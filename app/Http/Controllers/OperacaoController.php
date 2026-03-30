<?php

namespace App\Http\Controllers;

use App\Exports\OperacoesRelatorioExport;
use App\Jobs\ImportOperacoesJob;
use App\Models\ImportacaoLinhaLog;
use App\Models\Operacao;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;

class OperacaoController extends Controller
{
    /**
     * @var array<string, array<int, string>>
     */
    private const STATUS_TRANSITIONS = [
        'DIGITANDO' => ['PRÉ-ANÁLISE', 'EM ANÁLISE', 'PARA ASSINATURA', 'ASSINATURA CONCLUÍDA', 'APROVADA', 'CANCELADA'],
        'PRÉ-ANÁLISE' => ['DIGITANDO', 'EM ANÁLISE', 'PARA ASSINATURA', 'ASSINATURA CONCLUÍDA', 'APROVADA', 'CANCELADA'],
        'EM ANÁLISE' => ['DIGITANDO', 'PRÉ-ANÁLISE', 'PARA ASSINATURA', 'ASSINATURA CONCLUÍDA', 'APROVADA', 'CANCELADA'],
        'PARA ASSINATURA' => ['DIGITANDO', 'PRÉ-ANÁLISE', 'EM ANÁLISE', 'ASSINATURA CONCLUÍDA', 'APROVADA', 'CANCELADA'],
        'ASSINATURA CONCLUÍDA' => ['DIGITANDO', 'PRÉ-ANÁLISE', 'EM ANÁLISE', 'PARA ASSINATURA', 'APROVADA', 'CANCELADA'],
        'APROVADA' => ['DIGITANDO', 'PRÉ-ANÁLISE', 'EM ANÁLISE', 'PARA ASSINATURA', 'ASSINATURA CONCLUÍDA', 'CANCELADA', 'PAGO AO CLIENTE'],
        'CANCELADA' => [],
        'PAGO AO CLIENTE' => [],
    ];

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $this->buildOperacoesListQuery($request);

        $operacoes = $query->paginate(15)->withQueryString();
        $statuses = collect(array_keys(self::STATUS_TRANSITIONS));
        $importStats = $this->buildImportStats();

        return view('operacoes.index', compact('operacoes', 'statuses', 'importStats'));
    }

    public function report(Request $request)
    {
        $exportDate = CarbonImmutable::now();

        $operacoes = $this->buildOperacoesListQuery($request)
            ->with([
                'parcelas:id,operacao_id,data_vencimento,valor',
            ])
            ->orderBy('id')
            ->get();

        $fileName = 'relatorio-operacoes-'.$exportDate->format('Ymd-His').'.xlsx';

        return Excel::download(new OperacoesRelatorioExport($operacoes, $exportDate), $fileName);
    }

    public function importStats(): JsonResponse
    {
        $stats = $this->buildImportStats();

        return response()->json([
            'latest_file' => $stats['latest_file'],
            'total' => $stats['total'],
            'queued' => $stats['queued'],
            'processing' => $stats['processing'],
            'success' => $stats['success'],
            'error' => $stats['error'],
            'processed' => $stats['processed'],
            'progress' => $stats['progress'],
            'jobs_pending' => $stats['jobs_pending'],
            'is_running' => $stats['is_running'],
            'is_completed' => $stats['is_completed'],
            'recent_errors' => $stats['recent_errors']->map(fn ($item) => [
                'linha' => $item->linha,
                'mensagem' => $item->mensagem,
            ])->values(),
        ]);
    }

    public function show(Operacao $operacao)
    {
        $this->authorizeOperacaoAccess($operacao);

        $operacao->load([
            'cliente',
            'conveniada',
            'parcelas',
            'historicoStatus' => fn ($query) => $query->latest(),
            'historicoStatus.usuario',
        ]);

        $nextStatuses = self::STATUS_TRANSITIONS[$operacao->status] ?? [];

        return view('operacoes.show', compact('operacao', 'nextStatuses'));
    }

    public function import(Request $request)
    {
        $authUser = Auth::user();
        $isAdmin = $authUser instanceof User && $authUser->isAdmin();
        $userId = $authUser instanceof User ? $authUser->id : null;

        $request->validate([
            'arquivo' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        $filePath = $request->file('arquivo')->store('imports', 'local');

        $queueName = (string) config('queue.connections.database.queue', 'default');

        ImportOperacoesJob::dispatch($filePath, $userId, $isAdmin)->onConnection('database');
        $this->startQueueWorkerInBackground($queueName);

        return redirect()
            ->route('operacoes.index')
            ->with('status', 'Importação recebida. O processamento foi iniciado em segundo plano.');
    }

    private function startQueueWorkerInBackground(string $queueName): void
    {
        $phpBinary = PHP_BINARY;
        $artisanPath = base_path('artisan');

        if (DIRECTORY_SEPARATOR === '\\') {
            $command = sprintf(
                'cmd /c start "" /B "%s" "%s" queue:work --queue=%s --timeout=0 --tries=1 --stop-when-empty',
                $phpBinary,
                $artisanPath,
                $queueName,
            );

            @pclose(@popen($command, 'r'));

            return;
        }

        $command = sprintf(
            '%s %s queue:work --queue=%s --timeout=0 --tries=1 --stop-when-empty > /dev/null 2>&1 &',
            escapeshellarg($phpBinary),
            escapeshellarg($artisanPath),
            escapeshellarg($queueName),
        );

        @exec($command);
    }

    public function updateStatus(Request $request, Operacao $operacao)
    {
        $this->authorizeOperacaoAccess($operacao);

        $allowedStatuses = collect(self::STATUS_TRANSITIONS)
            ->keys()
            ->flatMap(fn ($status) => [$status, ...(self::STATUS_TRANSITIONS[$status] ?? [])])
            ->unique()
            ->values()
            ->all();

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', $allowedStatuses)],
        ]);

        $novoStatus = mb_strtoupper($validated['status']);
        $statusAtual = $operacao->status;

        if ($novoStatus === $statusAtual) {
            return redirect()
                ->route('operacoes.show', $operacao)
                ->with('status', 'A operação já está nesse status.');
        }

        $allowedNext = self::STATUS_TRANSITIONS[$statusAtual] ?? [];

        if (! in_array($novoStatus, $allowedNext, true)) {
            return redirect()
                ->route('operacoes.show', $operacao)
                ->withErrors(['status' => "Transição inválida: {$statusAtual} -> {$novoStatus}."]);
        }

        if ($novoStatus === 'PAGO AO CLIENTE') {
            if ($statusAtual !== 'APROVADA') {
                return redirect()
                    ->route('operacoes.show', $operacao)
                    ->withErrors(['status' => 'Uma operação só pode ser PAGO AO CLIENTE quando estiver em APROVADA.']);
            }

            $hasPassedAssinaturaConcluida = $operacao->historicoStatus()
                ->where(function ($query): void {
                    $query->where('status_anterior', 'ASSINATURA CONCLUÍDA')
                        ->orWhere('status_novo', 'ASSINATURA CONCLUÍDA');
                })
                ->exists();

            if (! $hasPassedAssinaturaConcluida) {
                return redirect()
                    ->route('operacoes.show', $operacao)
                    ->withErrors(['status' => 'Para marcar como PAGO AO CLIENTE, a operação deve já ter passado por ASSINATURA CONCLUÍDA.']);
            }
        }

        $dataPagamentoAtualizada = $novoStatus === 'PAGO AO CLIENTE'
            ? now()->toDateString()
            : $operacao->data_pagamento;

        $operacao->update([
            'status' => $novoStatus,
            'data_pagamento' => $dataPagamentoAtualizada,
        ]);

        $operacao->historicoStatus()->create([
            'status_anterior' => $statusAtual,
            'status_novo' => $novoStatus,
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('operacoes.show', $operacao)
            ->with('status', "Status atualizado para {$novoStatus}.");
    }

    private function authorizeOperacaoAccess(Operacao $operacao): void
    {
        $authUser = Auth::user();
        $isAdmin = $authUser instanceof User && $authUser->isAdmin();
        $userId = $authUser instanceof User ? $authUser->id : null;
        $clienteId = $authUser instanceof User ? $authUser->cliente_id : null;

        if ($isAdmin) {
            return;
        }

        $hasAccessByOwner = $operacao->user_id === $userId;
        $hasAccessByCliente = $clienteId !== null && $operacao->cliente_id === $clienteId;

        abort_unless($hasAccessByOwner || $hasAccessByCliente, 403);
    }

    private function buildOperacoesListQuery(Request $request): Builder
    {
        $authUser = Auth::user();
        $isAdmin = $authUser instanceof User && $authUser->isAdmin();
        $userId = $authUser instanceof User ? $authUser->id : null;
        $clienteId = $authUser instanceof User ? $authUser->cliente_id : null;

        $query = Operacao::query()
            ->select([
                'id',
                'codigo',
                'user_id',
                'cliente_id',
                'conveniada_id',
                'valor_requerido',
                'valor_desembolso',
                'taxa_juros',
                'taxa_multa',
                'taxa_mora',
                'data_pagamento',
                'status',
                'produto',
            ])
            ->with([
                'cliente:id,nome,cpf',
                'conveniada:id,nome',
            ])
            ->latest('id');

        if (! $isAdmin) {
            $query->where(function ($q) use ($userId, $clienteId): void {
                $q->where('user_id', $userId);

                if ($clienteId !== null) {
                    $q->orWhere('cliente_id', $clienteId);
                }
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('codigo')) {
            $query->where('codigo', 'like', $request->string('codigo').'%');
        }

        if ($request->filled('cliente') || $request->filled('cpf')) {
            $cliente = trim((string) ($request->filled('cpf') ? $request->string('cpf') : $request->string('cliente')));

            $cpfAlphaNum = preg_replace('/[^A-Za-z0-9]+/', '', $cliente) ?: '';

            $query->whereHas('cliente', function ($q) use ($cliente, $cpfAlphaNum): void {
                $q->where('nome', 'like', $cliente.'%')
                    ->orWhere('cpf', 'like', $cliente.'%');

                if ($cpfAlphaNum !== '' && $cpfAlphaNum !== $cliente) {
                    $q->orWhere('cpf', 'like', $cpfAlphaNum.'%');
                }
            });
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildImportStats(): array
    {
        $authUser = Auth::user();
        $isAdmin = $authUser instanceof User && $authUser->isAdmin();
        $userId = $authUser instanceof User ? $authUser->id : null;

        $baseQuery = ImportacaoLinhaLog::query();

        if (! $isAdmin) {
            $baseQuery->where(function ($query) use ($userId): void {
                if ($userId !== null) {
                    $query->where('user_id', $userId)
                        ->orWhereNull('user_id');

                    return;
                }

                $query->whereNull('user_id');
            });
        }

        $latestFile = (clone $baseQuery)
            ->latest('id')
            ->value('arquivo');

        $scopeQuery = clone $baseQuery;

        if ($latestFile) {
            $scopeQuery->where('arquivo', $latestFile);
        }

        $jobsPending = DB::table('jobs')->count();

        if ($latestFile && $jobsPending === 0) {
            ImportacaoLinhaLog::query()
                ->where('arquivo', $latestFile)
                ->whereIn('status', ['queued', 'processing'])
                ->where('created_at', '<', now()->subMinutes(1))
                ->update([
                    'status' => 'error',
                    'mensagem' => 'Processamento interrompido. Reenvie a importação.',
                    'processed_at' => now(),
                ]);
        }

        $total = (clone $scopeQuery)->count();

        $statusCounts = (clone $scopeQuery)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $queued = (int) ($statusCounts['queued'] ?? 0);
        $processing = (int) ($statusCounts['processing'] ?? 0);
        $success = (int) ($statusCounts['success'] ?? 0);
        $error = (int) ($statusCounts['error'] ?? 0);
        $processed = $success + $error;
        $progress = $total > 0 ? (int) round(($processed / $total) * 100) : 0;

        $recentErrors = (clone $scopeQuery)
            ->where('status', 'error')
            ->select(['linha', 'mensagem'])
            ->latest('id')
            ->limit(5)
            ->get();

        $isRunning = $jobsPending > 0 || $queued > 0 || $processing > 0;
        $isCompleted = $total > 0 && ! $isRunning;

        return [
            'latest_file' => $latestFile,
            'total' => $total,
            'queued' => $queued,
            'processing' => $processing,
            'success' => $success,
            'error' => $error,
            'processed' => $processed,
            'progress' => $progress,
            'recent_errors' => $recentErrors,
            'jobs_pending' => $jobsPending,
            'is_running' => $isRunning,
            'is_completed' => $isCompleted,
        ];
    }
}
