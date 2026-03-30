<?php

namespace App\Exports;

use App\Models\Operacao;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class OperacoesRelatorioExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    /**
     * @param Collection<int, Operacao> $operacoes
     */
    public function __construct(
        private readonly Collection $operacoes,
        private readonly CarbonInterface $exportDate,
    ) {
    }

    public function collection(): Collection
    {
        return $this->operacoes;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Código da operação',
            'Nome do cliente',
            'CPF',
            'Valor da operação',
            'Status',
            'Produto',
            'Conveniada',
            'Valor Presente',
        ];
    }

    /**
     * @param Operacao $operacao
     * @return array<int, string|float>
     */
    public function map($operacao): array
    {
        $valorOperacao = $this->resolveValorOperacao($operacao);

        return [
            (string) $operacao->codigo,
            (string) ($operacao->cliente?->nome ?? ''),
            (string) ($operacao->cliente?->cpf ?? ''),
            round($valorOperacao, 2),
            (string) $operacao->status,
            (string) $operacao->produto,
            (string) ($operacao->conveniada?->nome ?? ''),
            round($this->calculatePresentValue($operacao, $valorOperacao), 2),
        ];
    }

    private function resolveValorOperacao(Operacao $operacao): float
    {
        return (float) ($operacao->valor_desembolso ?: $operacao->valor_requerido ?: 0);
    }

    private function calculatePresentValue(Operacao $operacao, float $valorOperacao): float
    {
        if ($valorOperacao <= 0) {
            return 0.0;
        }

        $exportDate = CarbonImmutable::parse($this->exportDate)->startOfDay();

        $taxaMulta = max((float) ($operacao->taxa_multa ?? 0), 0) / 100;
        $taxaMora = max((float) ($operacao->taxa_mora ?? 0), 0) / 100;
        $taxaOperacao = max((float) ($operacao->taxa_juros ?? 0), 0) / 100;

        $parcelas = $operacao->parcelas;

        if ($parcelas->isEmpty()) {
            return $valorOperacao;
        }

        $valorPresenteTotal = 0.0;

        foreach ($parcelas as $parcela) {
            $valorParcela = (float) ($parcela->valor ?? 0);

            if ($valorParcela <= 0 || ! $parcela->data_vencimento) {
                $valorPresenteTotal += $valorParcela;

                continue;
            }

            $vencimento = CarbonImmutable::parse($parcela->data_vencimento)->startOfDay();

            if ($exportDate->greaterThan($vencimento)) {
                $diasAtraso = $vencimento->diffInDays($exportDate);

                $valorPresenteParcela = $valorParcela
                    + ($valorParcela * $taxaMulta)
                    + ($valorParcela * ($taxaMora / 30) * $diasAtraso);
            } elseif ($exportDate->lessThan($vencimento)) {
                $diasAdiantamento = $exportDate->diffInDays($vencimento);

                $valorPresenteParcela = $valorParcela
                    - ($valorParcela * ($taxaOperacao / 30) * $diasAdiantamento);
            } else {
                $valorPresenteParcela = $valorParcela;
            }

            $valorPresenteTotal += max($valorPresenteParcela, 0);
        }

        return $valorPresenteTotal;
    }
}
