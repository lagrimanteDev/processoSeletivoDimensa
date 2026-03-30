<?php

namespace App\Jobs;

use App\Imports\OperacoesDispatchRowsImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ImportOperacoesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public string $filePath,
        public ?int $userId,
        public bool $isAdmin,
    )
    {
        $this->onConnection('database');
    }

    public function handle(): void
    {
        ini_set('memory_limit', '1024M');
        set_time_limit($this->timeout);

        $import = new OperacoesDispatchRowsImport($this->userId, $this->isAdmin, $this->filePath);

        Excel::import($import, $this->filePath, 'local');

        Log::info('Importação de operações processada por linha', [
            'arquivo' => $this->filePath,
            'linhas_processadas' => $import->dispatched,
        ]);

        Storage::disk('local')->delete($this->filePath);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Falha na importação de operações', [
            'arquivo' => $this->filePath,
            'user_id' => $this->userId,
            'is_admin' => $this->isAdmin,
            'erro' => $exception->getMessage(),
        ]);
    }
}
