<?php

namespace App\Jobs;

use App\Imports\OperacoesImport;
use App\Models\ImportacaoLinhaLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ImportOperacaoLoteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    /**
     * @param array<int, array{row_data: array<string, mixed>, line: int, log_id: int}> $rows
     */
    public function __construct(
        public array $rows,
        public ?int $userId,
        public bool $isAdmin,
        public ?string $filePath = null,
    ) {
        $this->onConnection('database');
    }

    public function handle(): void
    {
        foreach ($this->rows as $entry) {
            $line = (int) ($entry['line'] ?? 0);
            $rowData = (array) ($entry['row_data'] ?? []);
            $logId = (int) ($entry['log_id'] ?? 0);

            if ($logId <= 0 || $line <= 0) {
                continue;
            }

            $logRow = ImportacaoLinhaLog::find($logId);

            if (! $logRow) {
                continue;
            }

            if ($this->isCancelled()) {
                $logRow->update([
                    'status' => 'error',
                    'mensagem' => 'Importação cancelada pelo usuário.',
                    'processed_at' => now(),
                ]);

                continue;
            }

            $logRow->update([
                'status' => 'processing',
                'mensagem' => 'Processando linha.',
                'started_at' => now(),
            ]);

            $import = new OperacoesImport($this->userId, $this->isAdmin);
            $import->processArrayRow($rowData, $line);

            if ($import->errors > 0) {
                $error = $import->errorMessages[0] ?? 'Erro desconhecido';

                $logRow->update([
                    'status' => 'error',
                    'mensagem' => $error,
                    'processed_at' => now(),
                ]);

                continue;
            }

            $logRow->update([
                'status' => 'success',
                'mensagem' => 'Linha processada com sucesso.',
                'processed_at' => now(),
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        $logIds = collect($this->rows)
            ->pluck('log_id')
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($logIds->isEmpty()) {
            return;
        }

        ImportacaoLinhaLog::query()
            ->whereIn('id', $logIds)
            ->whereIn('status', ['queued', 'processing'])
            ->update([
                'status' => 'error',
                'mensagem' => $exception->getMessage(),
                'processed_at' => now(),
            ]);
    }

    private function isCancelled(): bool
    {
        if (! $this->filePath) {
            return false;
        }

        $cacheKey = 'importacao:cancelada:'.md5($this->filePath);

        return (bool) Cache::get($cacheKey, false);
    }
}
