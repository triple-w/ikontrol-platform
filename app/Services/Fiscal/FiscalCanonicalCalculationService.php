<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Services\Fiscal\Cfdi40\CfdiCurrencyTotalsCalculator;
use RuntimeException;

/**
 * Single monetary result for review, draft headers and sale allocations.
 * Resolved concepts retain six-decimal SAT operands; currency totals are
 * materialized once from their two-decimal concept components.
 */
final class FiscalCanonicalCalculationService
{
    public function calculate(array $resolvedLines): array
    {
        if (!$resolvedLines) {
            throw new RuntimeException('FISCAL_CALCULATION_LINES_REQUIRED');
        }
        $calculator = new CfdiCurrencyTotalsCalculator();
        $currencyLines = [];
        $currencyLinesBySale = [];
        foreach ($resolvedLines as $line) {
            $snapshot = (array) ($line['snapshot'] ?? []);
            $currencyLine = [
                'subtotal' => (string) ($line['subtotal'] ?? '0'),
                'discount' => (string) ($line['discount'] ?? '0'),
                'transferred' => (string) ($snapshot['transferred_total'] ?? '0'),
                'withheld' => (string) ($snapshot['withheld_total'] ?? '0'),
            ];
            $currencyLines[] = $currencyLine;
            $saleId = (int) ($line['sale_id'] ?? 0);
            if ($saleId <= 0) throw new RuntimeException('FISCAL_CALCULATION_SALE_REQUIRED');
            $currencyLinesBySale[$saleId][] = $currencyLine;
        }

        $totals = $calculator->fromLines($currencyLines);
        $allocations = [];
        foreach ($currencyLinesBySale as $saleId => $saleLines) {
            $saleTotals = $calculator->fromLines($saleLines);
            $allocations[] = [
                'sale_id' => $saleId,
                'allocated_subtotal' => FiscalDecimal::subtract($saleTotals['subtotal'], $saleTotals['discount']),
                'allocated_tax' => FiscalDecimal::subtract($saleTotals['transferred'], $saleTotals['withheld']),
                'allocated_total' => $saleTotals['total'],
                'allocation_status' => 'reserved',
            ];
        }
        $allocated = '0.000000';
        foreach ($allocations as $allocation) {
            $allocated = FiscalDecimal::add($allocated, $allocation['allocated_total']);
        }
        if (FiscalDecimal::micros($allocated) !== FiscalDecimal::micros($totals['total'])) {
            throw new RuntimeException('FISCAL_CANONICAL_ALLOCATION_TOTAL_MISMATCH');
        }
        return ['lines' => $resolvedLines, 'totals' => $totals, 'allocations' => $allocations];
    }
}