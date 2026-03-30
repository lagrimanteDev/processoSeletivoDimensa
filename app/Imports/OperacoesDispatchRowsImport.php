<?php

namespace App\Imports;

use App\Jobs\ImportOperacaoLoteJob;
use App\Models\ImportacaoLinhaLog;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;
use Maatwebsite\Excel\Events\AfterImport;

class OperacoesDispatchRowsImport implements OnEachRow, WithHeadingRow, WithChunkReading, SkipsEmptyRows, WithEvents
{
    public function __construct(
        private readonly ?int $userId = null,
        private readonly bool $isAdmin = false,
        private readonly ?string $filePath = null,
    ) {
    }

    public int $dispatched = 0;

    /**
     * @var array<int, array{row_data: array<string, mixed>, line: int, log_id: int}>
     */
    private array $pendingRows = [];

    private int $batchDispatchSize = 500;

    public function onRow(Row $row): void
    {
        if ($this->isImportCancelled()) {
            ImportacaoLinhaLog::create([
                'arquivo' => $this->filePath,
                'linha' => $row->getIndex(),
                'user_id' => $this->userId,
                'status' => 'error',
                'mensagem' => 'Importação cancelada pelo usuário.',
                'processed_at' => now(),
            ]);

            return;
        }

        $rowData = $row->toArray();

        $log = ImportacaoLinhaLog::create([
            'arquivo' => $this->filePath,
            'linha' => $row->getIndex(),
            'user_id' => $this->userId,
            'status' => 'queued',
            'mensagem' => 'Linha enfileirada para processamento.',
        ]);

        $this->pendingRows[] = [
            'row_data' => $rowData,
            'line' => $row->getIndex(),
            'log_id' => (int) $log->id,
        ];

        if (count($this->pendingRows) >= $this->batchDispatchSize) {
            $this->dispatchPendingBatch();
        }
    }

    public function chunkSize(): int
    {
        return 500;
    }

    /**
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterImport::class => function (): void {
                $this->dispatchPendingBatch();
            },
        ];
    }

    private function dispatchPendingBatch(): void
    {
        if ($this->pendingRows === []) {
            return;
        }

        ImportOperacaoLoteJob::dispatch(
            $this->pendingRows,
            $this->userId,
            $this->isAdmin,
            $this->filePath,
        )->onConnection('database');

        $this->dispatched += count($this->pendingRows);
        $this->pendingRows = [];
    }

    private function isImportCancelled(): bool
    {
        if (! $this->filePath) {
            return false;
        }

        $cacheKey = 'importacao:cancelada:'.md5($this->filePath);

        return (bool) Cache::get($cacheKey, false);
    }
}
