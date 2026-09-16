<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

final class CreditNoteFiscalCalculator
{
    public function round(string $value, int $scale = 2): string
    {
        $increment = '0.'.str_repeat('0', max(0, $scale)).'5';
        $adjusted = bccomp($value, '0', $scale + 6) < 0
            ? bcsub($value, $increment, $scale + 6)
            : bcadd($value, $increment, $scale + 6);
        return bcadd($adjusted, '0', $scale);
    }

    public function line(object $source, string $quantity, array $sourceTaxes): array
    {
        $quantity = bcadd($quantity, '0', 6);
        $ratio = bcdiv($quantity, (string) $source->quantity, 18);
        $subtotal = $this->round(bcmul((string) $source->gross_amount, $ratio, 18));
        $discount = $this->round(bcmul((string) $source->discount, $ratio, 18));
        $taxes = [];
        $transferred = $withheld = '0.00';
        foreach ($sourceTaxes as $tax) {
            $base = $this->round(bcmul((string) $tax->taxable_base, $ratio, 18));
            $amount = $tax->factor_type === 'Exento' ? '0.00' : $this->round(bcmul((string) $tax->amount, $ratio, 18));
            $taxes[] = ['source' => $tax, 'base' => $base, 'amount' => $amount];
            if ($tax->tax_type === 'transferred') {
                $transferred = bcadd($transferred, $amount, 2);
            } else {
                $withheld = bcadd($withheld, $amount, 2);
            }
        }
        $total = bcsub(bcadd(bcsub($subtotal, $discount, 2), $transferred, 2), $withheld, 2);
        return compact('quantity', 'subtotal', 'discount', 'transferred', 'withheld', 'total', 'taxes');
    }

    public function totals(array $lines): array
    {
        $totals = ['subtotal'=>'0.00','discount'=>'0.00','transferred'=>'0.00','withheld'=>'0.00','total'=>'0.00'];
        foreach ($lines as $line) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] = bcadd($totals[$key], (string) $line[$key], 2);
            }
        }
        $totals['calculated'] = bcsub(bcadd(bcsub($totals['subtotal'], $totals['discount'], 2), $totals['transferred'], 2), $totals['withheld'], 2);
        $totals['difference'] = bcsub($totals['total'], $totals['calculated'], 2);
        return $totals;
    }
}
