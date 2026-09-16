<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Cfdi40;

use App\Services\Fiscal\FiscalDecimalCalculator;

/**
 * Canonical two-decimal currency equation for CFDI header totals.
 * Quantity, unit value and rates retain their own six-decimal representation.
 */
final class CfdiCurrencyTotalsCalculator
{
    private FiscalDecimalCalculator $decimal;

    public function __construct()
    {
        $this->decimal = new FiscalDecimalCalculator();
    }

    public function fromLines(array $lines): array
    {
        // The XML serializer emits concept amounts in currency precision. Make
        // those final serialized operands canonical before aggregating headers.
        $subtotal = $discount = $transferred = $withheld = '0.00';
        foreach ($lines as $line) {
            $subtotal = $this->decimal->add($subtotal, $this->decimal->money((string) ($line['subtotal'] ?? '0')));
            $discount = $this->decimal->add($discount, $this->decimal->money((string) ($line['discount'] ?? '0')));
            $transferred = $this->decimal->add($transferred, $this->decimal->money((string) ($line['transferred'] ?? '0')));
            $withheld = $this->decimal->add($withheld, $this->decimal->money((string) ($line['withheld'] ?? '0')));
        }
        return $this->fromAggregates($subtotal, $discount, $transferred, $withheld);
    }

    public function fromAggregates(
        string $subtotal,
        string $discount,
        string $transferred,
        string $withheld
    ): array {
        $subtotal = $this->decimal->money($subtotal);
        $discount = $this->decimal->money($discount);
        $transferred = $this->decimal->money($transferred);
        $withheld = $this->decimal->money($withheld);
        $total = $this->decimal->money(
            $this->decimal->sub(
                $this->decimal->add($this->decimal->sub($subtotal, $discount), $transferred),
                $withheld
            )
        );
        return compact('subtotal', 'discount', 'transferred', 'withheld', 'total');
    }
}
