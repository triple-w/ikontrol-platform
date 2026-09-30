<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\Fiscal\FiscalDecimal;
use RuntimeException;

/** Converts Pago 2.0 document amounts without reducing EquivalenciaDR's ten-decimal scale. */
final class PaymentComplementAmountConverter
{
    public static function toPaymentCurrency(
        string $amount,
        string $documentCurrency,
        string $equivalenceDr,
        string $paymentCurrency
    ): string {
        if (strtoupper($documentCurrency) === strtoupper($paymentCurrency)) {
            return FiscalDecimal::format(FiscalDecimal::micros($amount));
        }

        if (function_exists('bcdiv')) {
            $value = bcdiv($amount, $equivalenceDr, 7);
            [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
            $fraction = str_pad($fraction, 7, '0');
            $micros = FiscalDecimal::micros($integer . '.' . substr($fraction, 0, 6));
            if ((int) $fraction[6] >= 5) $micros++;
            return FiscalDecimal::format($micros);
        }

        $compatible = rtrim(rtrim($equivalenceDr, '0'), '.');
        if (!preg_match('/^\d+(?:\.\d{1,6})?$/D', $compatible)) {
            throw new RuntimeException('BCMath es obligatorio para EquivalenciaDR con más de seis decimales significativos.');
        }
        return FiscalDecimal::divide($amount, $compatible);
    }
}
