<?php

namespace App\Jobs;

use App\Imports\OperacoesImport;
use App\Models\ImportacaoLinhaLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportOperacaoLinhaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param array<string, mixed> $rowData
     */
    public function __construct(
        public array $rowData,
        public int $line,
        public ?int $userId,
        public bool $isAdmin,
        public ?int $logId = null,
    ) {
        $this->onConnection('database');
    }

    public function handle(): void
    {
        $logRow = $this->logId ? ImportacaoLinhaLog::find($this->logId) : null;

        if ($logRow) {
            $logRow->update([
                'status' => 'processing',
                'mensagem' => 'Processando linha.',
                'started_at' => now(),
            ]);
        }

        $import = new OperacoesImport($this->userId, $this->isAdmin);
        $import->processArrayRow($this->rowData, $this->line);

        if ($import->errors > 0) {
            $error = $import->errorMessages[0] ?? 'Erro desconhecido';

            if ($logRow) {
                $logRow->update([
                    'status' => 'error',
                    'mensagem' => $error,
                    'processed_at' => now(),
                ]);
            }

            Log::warning('Linha ignorada na importação de operações', [
                'linha' => $this->line,
                'erro' => $error,
            ]);

            return;
        }

        if ($logRow) {
            $logRow->update([
                'status' => 'success',
                'mensagem' => 'Linha processada com sucesso.',
                'processed_at' => now(),
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        if ($this->logId) {
            ImportacaoLinhaLog::query()
                ->where('id', $this->logId)
                ->update([
                    'status' => 'error',
                    'mensagem' => $exception->getMessage(),
                    'processed_at' => now(),
                ]);
        }
    }
}
