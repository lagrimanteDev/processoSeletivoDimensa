<?php

namespace App\Imports;

use App\Models\Cliente;
use App\Models\Conveniada;
use App\Models\Operacao;
use App\Models\Parcela;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class OperacoesImport implements OnEachRow, WithHeadingRow, WithChunkReading, SkipsEmptyRows
{
    public function __construct(
        private readonly ?int $userId = null,
        private readonly bool $isAdmin = false,
    ) {
    }

    public int $created = 0;

    public int $updated = 0;

    public int $parcelas = 0;

    public int $skipped = 0;

    public int $errors = 0;

    /**
     * @var array<int, string>
     */
    public array $errorMessages = [];

    /**
     * @var array<int, string>
     */
    public array $detectedHeaders = [];

    public function onRow(Row $row): void
    {
        $rowData = collect($row->toArray());

        if (empty($this->detectedHeaders)) {
            $this->detectedHeaders = array_map('strval', array_keys($rowData->toArray()));
        }

        $this->processArrayRow($rowData->toArray(), $row->getIndex());
    }

    /**
     * @param array<string, mixed> $rowData
     */
    public function processArrayRow(array $rowData, int $line): void
    {
        $row = collect($rowData);

        try {
            $this->importRow($row, $line);
        } catch (Throwable $e) {
            $this->errors++;

            if (count($this->errorMessages) < 20) {
                $this->errorMessages[] = 'Linha '.$line.': '.$e->getMessage();
            }
        }
    }

    public function chunkSize(): int
    {
        return 200;
    }

    private function importRow($row, int $line): void
    {
        $cpfRaw = $this->value($row, ['cpf', 'cliente_cpf', 'cpf_cliente', 'cpf_do_cliente', 'documento', 'documento_cliente', 'numero_cpf', 'nr_cpf'])
            ?: $this->valueByKeywords($row, ['cpf']);
        $cpf = $this->normalizeCpf($cpfRaw);
        $conveniadaRef = trim((string) ($this->value($row, ['conveniada_codigo', 'codigo_conveniada', 'conveniada_id']) ?: ''));
        $conveniadaNome = trim((string) ($this->value($row, ['conveniada_nome', 'nome_conveniada']) ?: ''));

        if ($cpf === '') {
            $this->skipped++;
            throw new \RuntimeException('CPF não informado ou coluna de CPF não reconhecida no arquivo.');
        }

        DB::transaction(function () use ($row, $cpf, $conveniadaRef, $conveniadaNome, $line): void {
            $clientePayload = [
                'nome' => (string) $this->value($row, ['nome_cliente', 'cliente_nome', 'nome']),
                'data_nascimento' => $this->parseDate($this->value($row, ['data_nascimento', 'cliente_data_nascimento', 'dt_nasc']), $line) ?? now()->toDateString(),
                'sexo' => (string) ($this->value($row, ['sexo', 'cliente_sexo']) ?: 'NAO_INFORMADO'),
                'email' => (string) ($this->value($row, ['cliente_email', 'email']) ?: "{$cpf}@sem-email.local"),
            ];

            $cliente = $this->resolveClienteByCpf($cpf, $clientePayload);

            $conveniada = null;

            if ($conveniadaRef !== '') {
                if (is_numeric($conveniadaRef)) {
                    $conveniada = Conveniada::query()
                        ->where('id', (int) $conveniadaRef)
                        ->orWhere('codigo', (string) ((int) $conveniadaRef))
                        ->first();
                } else {
                    $conveniada = Conveniada::query()->where('codigo', $conveniadaRef)->first();
                }
            }

            if (! $conveniada && $conveniadaNome !== '') {
                $conveniada = Conveniada::query()->where('nome', $conveniadaNome)->first();
            }

            if (! $conveniada) {
                throw new \RuntimeException('Conveniada inválida ou não cadastrada. Cadastre as conveniadas fixas (1 a 10) antes da importação.');
            }

            $status = $this->normalizeStatus($this->value($row, ['status', 'status_id']));
            $ownerUserId = User::query()->where('cpf', $cliente->cpf)->value('id');
            $assignedUserId = $ownerUserId ?? $this->userId;

            $payload = [
                'cliente_id' => $cliente->id,
                'conveniada_id' => $conveniada->id,
                'user_id' => $assignedUserId,
                'valor_requerido' => $this->parseDecimal($this->value($row, ['valor_requerido'])),
                'valor_desembolso' => $this->parseDecimal($this->value($row, ['valor_desembolso'])),
                'total_juros' => $this->parseDecimal($this->value($row, ['total_juros'])),
                'taxa_juros' => $this->parseDecimal($this->value($row, ['taxa_juros', 'taxa_juros_perc', 'taxa_juros_%'])),
                'taxa_multa' => $this->parseDecimal($this->value($row, ['taxa_multa'])),
                'taxa_mora' => $this->parseDecimal($this->value($row, ['taxa_mora'])),
                'status' => $status,
                'produto' => (string) ($this->value($row, ['produto']) ?: 'NAO_INFORMADO'),
                'data_criacao' => $this->parseDate($this->value($row, ['data_criacao']), $line) ?? now()->toDateString(),
                'data_pagamento' => $this->parseDate($this->value($row, ['data_pagamento']), $line),
            ];

            $payload['codigo'] = $this->nextCodigoIncremental();

            $operacao = Operacao::create($payload);
            $this->created++;

            $parcelaNumero = $this->value($row, ['parcela_numero', 'numero_parcela']);
            $parcelaValor = $this->value($row, ['parcela_valor', 'valor_parcela']);
            $parcelaVencimento = $this->value($row, ['parcela_data_vencimento', 'data_vencimento', 'data_primeiro_vencimento']);

            if ($parcelaNumero !== null && $parcelaValor !== null && $parcelaVencimento !== null) {
                Parcela::updateOrCreate(
                    [
                        'operacao_id' => $operacao->id,
                        'numero' => (int) $parcelaNumero,
                    ],
                    [
                        'data_vencimento' => $this->parseDate($parcelaVencimento, $line) ?? now()->toDateString(),
                        'valor' => $this->parseDecimal($parcelaValor),
                        'status' => mb_strtolower((string) ($this->value($row, ['parcela_status', 'status_parcela']) ?: 'pendente')),
                    ]
                );

                $this->parcelas++;

                return;
            }

            $qtdParcelas = (int) ($this->value($row, ['quantidade_parcelas']) ?? 0);
            $qtdPagas = (int) ($this->value($row, ['quantidade_parcelas_pagas']) ?? 0);
            $valorParcela = $this->value($row, ['valor_parcela']);
            $primeiroVencimento = $this->value($row, ['data_primeiro_vencimento']);

            if ($qtdParcelas > 0 && $valorParcela !== null && $primeiroVencimento !== null) {
                $baseDate = Carbon::parse($this->parseDate($primeiroVencimento, $line));

                for ($i = 1; $i <= $qtdParcelas; $i++) {
                    Parcela::updateOrCreate(
                        [
                            'operacao_id' => $operacao->id,
                            'numero' => $i,
                        ],
                        [
                            'data_vencimento' => $baseDate->copy()->addMonthsNoOverflow($i - 1)->format('Y-m-d'),
                            'valor' => $this->parseDecimal($valorParcela),
                            'status' => $i <= $qtdPagas ? 'paga' : 'pendente',
                        ]
                    );

                    $this->parcelas++;
                }
            }
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveClienteByCpf(string $cpf, array $payload): Cliente
    {
        try {
            return Cliente::updateOrCreate(['cpf' => $cpf], $payload);
        } catch (QueryException $e) {
            if (! $this->isDuplicateCpfException($e)) {
                throw $e;
            }

            $cliente = Cliente::query()->where('cpf', $cpf)->first();

            if (! $cliente) {
                throw $e;
            }

            $cliente->fill($payload);
            $cliente->save();

            return $cliente;
        }
    }

    private function isDuplicateCpfException(QueryException $e): bool
    {
        $message = mb_strtolower($e->getMessage());

        return str_contains($message, 'duplicate entry')
            && str_contains($message, 'clientes_cpf_unique');
    }

    private function normalizeCpf(mixed $value): string
    {
        $cpf = trim((string) ($value ?? ''));

        if ($cpf === '') {
            return '';
        }

        $onlyDigits = preg_replace('/\D+/', '', $cpf) ?: '';

        if ($onlyDigits !== '') {
            return $onlyDigits;
        }

        return $cpf;
    }

    private function nextCodigoIncremental(): string
    {
        return DB::transaction(function (): string {
            $sequence = DB::table('import_sequences')
                ->where('chave', 'operacao_codigo')
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                $maxCodigo = Operacao::query()
                    ->pluck('codigo')
                    ->map(fn ($codigo) => trim((string) $codigo))
                    ->filter(fn ($codigo) => ctype_digit($codigo))
                    ->map(fn ($codigo) => (int) $codigo)
                    ->max() ?? 0;

                DB::table('import_sequences')->insert([
                    'chave' => 'operacao_codigo',
                    'valor' => $maxCodigo,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $atual = $maxCodigo;
            } else {
                $atual = (int) $sequence->valor;
            }

            $proximo = $atual + 1;

            DB::table('import_sequences')
                ->where('chave', 'operacao_codigo')
                ->update([
                    'valor' => $proximo,
                    'updated_at' => now(),
                ]);

            return (string) $proximo;
        });
    }

    /**
     * @param array<int, string> $aliases
     */
    private function value($row, array $aliases): mixed
    {
        foreach ($aliases as $alias) {
            $key = Str::slug($alias, '_');
            $value = $row->get($key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $keywords
     */
    private function valueByKeywords($row, array $keywords): mixed
    {
        foreach ($row->toArray() as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $normalizedKey = Str::slug((string) $key, '_');

            foreach ($keywords as $keyword) {
                if (str_contains($normalizedKey, Str::slug($keyword, '_'))) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function parseDecimal(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = trim((string) $value);
        $normalized = preg_replace('/[^\d,.-]/', '', $normalized) ?: '';

        if ($normalized === '' || $normalized === '-' || $normalized === ',') {
            return 0.0;
        }

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return (float) $normalized;
    }

    private function parseDate(mixed $value, ?int $line = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        $formats = ['d/m/Y', 'Y-m-d', 'd-m-Y'];

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, (string) $value)->format('Y-m-d');
            } catch (Throwable) {
                // tenta no próximo formato
            }
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeStatus(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'DIGITANDO';
        }

        if (is_numeric($value)) {
            return match ((int) $value) {
                1 => 'DIGITANDO',
                2 => 'PRÉ-ANÁLISE',
                3 => 'EM ANÁLISE',
                4 => 'PARA ASSINATURA',
                5 => 'ASSINATURA CONCLUÍDA',
                6 => 'APROVADA',
                7 => 'CANCELADA',
                8 => 'PAGO AO CLIENTE',
                default => 'DIGITANDO',
            };
        }

        $status = mb_strtoupper(trim((string) $value));

        return match ($status) {
            'DIGITANDO' => 'DIGITANDO',
            'PRÉ-ANÁLISE', 'PRE-ANALISE', 'PRE ANALISE', 'PENDENTE' => 'PRÉ-ANÁLISE',
            'EM ANÁLISE', 'EM ANALISE' => 'EM ANÁLISE',
            'PARA ASSINATURA' => 'PARA ASSINATURA',
            'ASSINATURA CONCLUÍDA', 'ASSINATURA CONCLUIDA' => 'ASSINATURA CONCLUÍDA',
            'APROVADA' => 'APROVADA',
            'CANCELADA' => 'CANCELADA',
            'PAGO AO CLIENTE', 'PAGA' => 'PAGO AO CLIENTE',
            default => 'DIGITANDO',
        };
    }
}
