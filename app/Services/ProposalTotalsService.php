<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Fiscal\FiscalDecimal;
use InvalidArgumentException;

/**
 * Pure, six-decimal total contract for proposals.
 *
 * It deliberately does not read rows, settings, or fiscal catalogues. The
 * legacy model remains responsible for loading proposal data and currency.
 */
final class ProposalTotalsService
{
    /**
     * @return array{subtotal:string,discount:string,total_after_discount:string,tax:string,tax2:string,grand_total:string,discount_type:string}
     */
    public function calculate(
        mixed $subtotal,
        mixed $discountAmount,
        mixed $discountAmountType,
        mixed $discountType,
        mixed $taxPercentage,
        mixed $taxPercentage2
    ): array {
        $subtotal = $this->amount($subtotal, 'Subtotal');
        $discountType = $discountType === 'after_tax' ? 'after_tax' : 'before_tax';
        $discountAmountType = $discountAmountType === 'percentage' ? 'percentage' : 'fixed_amount';
        $discountAmount = $this->amount($discountAmount, 'Descuento');
        $taxPercentage = $this->amount($taxPercentage, 'Impuesto');
        $taxPercentage2 = $this->amount($taxPercentage2, 'Segundo impuesto');

        $discount = $discountAmountType === 'percentage'
            ? FiscalDecimal::multiply($subtotal, FiscalDecimal::divide($discountAmount, '100.000000'))
            : $discountAmount;
        if ($discountType === 'before_tax') {
            if (FiscalDecimal::micros($discount) > FiscalDecimal::micros($subtotal)) {
                throw new InvalidArgumentException('El descuento no puede exceder el subtotal de la propuesta.');
            }
            $totalAfterDiscount = FiscalDecimal::subtract($subtotal, $discount);
            $tax = $this->tax($totalAfterDiscount, $taxPercentage);
            $tax2 = $this->tax($totalAfterDiscount, $taxPercentage2);
            $grandTotal = FiscalDecimal::add($totalAfterDiscount, $tax, $tax2);
        } else {
            $tax = $this->tax($subtotal, $taxPercentage);
            $tax2 = $this->tax($subtotal, $taxPercentage2);
            $grandBeforeDiscount = FiscalDecimal::add($subtotal, $tax, $tax2);
            $discount = $discountAmountType === 'percentage'
                ? FiscalDecimal::multiply($grandBeforeDiscount, FiscalDecimal::divide($discountAmount, '100.000000'))
                : $discountAmount;
            if (FiscalDecimal::micros($discount) > FiscalDecimal::micros($grandBeforeDiscount)) {
                throw new InvalidArgumentException('El descuento no puede exceder el total de la propuesta.');
            }
            $totalAfterDiscount = FiscalDecimal::subtract($grandBeforeDiscount, $discount);
            $grandTotal = $totalAfterDiscount;
        }

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total_after_discount' => $totalAfterDiscount,
            'tax' => $tax,
            'tax2' => $tax2,
            'grand_total' => $grandTotal,
            'discount_type' => $discountType,
        ];
    }

    private function tax(string $base, string $percentage): string
    {
        return FiscalDecimal::micros($percentage) === 0
            ? '0.000000'
            : FiscalDecimal::multiply($base, FiscalDecimal::divide($percentage, '100.000000'));
    }

    private function amount(mixed $value, string $label): string
    {
        $value = trim((string) ($value ?? '0'));
        if (! preg_match('/^\d+(?:\.\d{1,6})?$/D', $value)) {
            throw new InvalidArgumentException("{$label} debe ser un decimal no negativo de hasta seis decimales.");
        }
        return FiscalDecimal::format(FiscalDecimal::micros($value));
    }
}
