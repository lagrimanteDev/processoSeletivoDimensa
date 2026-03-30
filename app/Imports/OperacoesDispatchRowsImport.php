<?php

namespace App\Imports;

use App\Jobs\ImportOperacaoLinhaJob;
use App\Models\ImportacaoLinhaLog;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;

class OperacoesDispatchRowsImport implements OnEachRow, WithHeadingRow, WithChunkReading, SkipsEmptyRows
{
    public function __construct(
        private readonly ?int $userId = null,
        private readonly bool $isAdmin = false,
        private readonly ?string $filePath = null,
    ) {
    }

    public int $dispatched = 0;

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

        ImportOperacaoLinhaJob::dispatch(
            $rowData,
            $row->getIndex(),
            $this->userId,
            $this->isAdmin,
            $log->id,
        )->onConnection('database');

        $this->dispatched++;
    }

    public function chunkSize(): int
    {
        return 500;
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
