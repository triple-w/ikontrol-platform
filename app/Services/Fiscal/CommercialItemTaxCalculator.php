<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

/** Pure six-decimal calculator used by commercial rows and fiscal preparation. */
final class CommercialItemTaxCalculator
{
    public function calculate(string $quantity, string $unitPrice, string $discount, string $pricingMode, string $taxObjectCode, array $taxes): array
    {
        $gross = FiscalDecimal::multiply($quantity, $unitPrice);
        if (FiscalDecimal::micros($discount) < 0 || FiscalDecimal::micros($discount) > FiscalDecimal::micros($gross)) {
            throw new RuntimeException('El descuento de la partida no es válido.');
        }
        $net = FiscalDecimal::subtract($gross, $discount);
        if ($taxObjectCode === '01') $taxes = [];
        if ($taxObjectCode !== '01' && !$taxes) throw new RuntimeException('Falta el desglose de impuestos del concepto.');

        $signedRates = '0.000000';
        $signedQuotas = '0.000000';
        foreach ($taxes as $tax) {
            $sign = ($tax['tax_type'] ?? '') === 'withholding' ? '-' : '';
            if (($tax['factor_type'] ?? '') === 'Tasa') $signedRates = FiscalDecimal::add($signedRates, $sign . ltrim((string) $tax['rate_or_quota'], '-'));
            if (($tax['factor_type'] ?? '') === 'Cuota') $signedQuotas = FiscalDecimal::add($signedQuotas, $sign . ltrim(FiscalDecimal::multiply($quantity, (string) $tax['rate_or_quota']), '-'));
        }
        $includedBase = FiscalDecimal::subtract($net, $signedQuotas);
        $base = $pricingMode === 'tax_exclusive' ? $net : ($taxes
            ? FiscalDecimal::prorate($includedBase, '1.000000', FiscalDecimal::add('1.000000', $signedRates))
            : $net);
        $transfers = $withholdings = '0.000000';
        $calculated = [];
        foreach ($taxes as $index => $tax) {
            $factor = (string) $tax['factor_type'];
            $rate = $factor === 'Exento' ? null : (string) $tax['rate_or_quota'];
            $amount = $factor === 'Tasa' ? FiscalDecimal::multiply($base, (string) $rate)
                : ($factor === 'Cuota' ? FiscalDecimal::multiply($quantity, (string) $rate) : '0.000000');
            if ($tax['tax_type'] === 'withholding') $withholdings = FiscalDecimal::add($withholdings, $amount);
            else $transfers = FiscalDecimal::add($transfers, $amount);
            $calculated[] = [
                'tax_type' => $tax['tax_type'], 'tax_code' => $tax['tax_code'],
                'factor_type' => $factor, 'rate_or_quota' => $rate,
                'tax_base' => $base, 'tax_amount' => $amount,
                'is_exempt' => $factor === 'Exento' ? 1 : 0, 'calculation_order' => $index,
            ];
        }
        return [
            'gross_price' => $gross, 'base' => $base, 'transfers' => $transfers,
            'withholdings' => $withholdings, 'discount' => $discount,
            'total' => FiscalDecimal::subtract(FiscalDecimal::add($base, $transfers), $withholdings),
            'calculated_taxes' => $calculated,
        ];
    }
}
