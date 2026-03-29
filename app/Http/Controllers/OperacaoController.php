<?php

namespace App\Http\Controllers;

use App\Jobs\ImportOperacoesJob;
use App\Models\Operacao;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
        $authUser = Auth::user();
        $isAdmin = $authUser instanceof User && $authUser->isAdmin();
        $userId = $authUser instanceof User ? $authUser->id : null;
        $clienteId = $authUser instanceof User ? $authUser->cliente_id : null;

        $query = Operacao::query()
            ->select(['id', 'codigo', 'user_id', 'cliente_id', 'conveniada_id', 'valor_requerido', 'valor_desembolso', 'status', 'produto'])
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
            $clienteUpper = mb_strtoupper($cliente);
            $cpfAlphaNumUpper = mb_strtoupper($cpfAlphaNum);

            $query->whereHas('cliente', function ($q) use ($cliente, $cpfAlphaNum, $clienteUpper, $cpfAlphaNumUpper): void {
                $q->where('nome', 'like', $cliente.'%')
                    ->orWhere('cpf', 'like', $cliente.'%')
                    ->orWhereRaw('UPPER(cpf) like ?', [$clienteUpper.'%']);

                if ($cpfAlphaNum !== '' && $cpfAlphaNum !== $cliente) {
                    $q->orWhere('cpf', 'like', $cpfAlphaNum.'%')
                        ->orWhereRaw('UPPER(cpf) like ?', [$cpfAlphaNumUpper.'%']);
                }
            });
        }

        $operacoes = $query->paginate(15)->withQueryString();
        $statuses = collect(array_keys(self::STATUS_TRANSITIONS));

        return view('operacoes.index', compact('operacoes', 'statuses'));
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
        @set_time_limit(0);

        $authUser = Auth::user();
        $isAdmin = $authUser instanceof User && $authUser->isAdmin();
        $userId = $authUser instanceof User ? $authUser->id : null;

        $request->validate([
            'arquivo' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        $filePath = $request->file('arquivo')->store('imports', 'local');

        ImportOperacoesJob::dispatch($filePath, $userId, $isAdmin);

        return redirect()
            ->route('operacoes.index')
            ->with('status', 'Importação iniciada. O processamento ocorre em segundo plano e pode levar alguns minutos.');
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

        $operacao->update([
            'status' => $novoStatus,
            'data_pagamento' => $novoStatus === 'PAGO AO CLIENTE' ? ($operacao->data_pagamento ?? now()->toDateString()) : $operacao->data_pagamento,
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
}
