<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final class EstimateItemPricingService
{
    public function optionalNonNegativeDecimal(mixed $value, string $label): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $normalized = $this->normalizeDecimal((string) $value);
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $normalized)) {
            throw new InvalidArgumentException("{$label} debe ser un número no negativo válido.");
        }
        $number = (float) $normalized;
        if (! is_finite($number) || $number < 0) {
            throw new InvalidArgumentException("{$label} debe ser un número no negativo válido.");
        }

        return $normalized;
    }

    public function positiveDecimal(mixed $value, string $label): string
    {
        $normalized = $this->optionalNonNegativeDecimal($value, $label);
        if ($normalized === null || (float) $normalized <= 0) {
            throw new InvalidArgumentException("{$label} debe ser mayor que cero.");
        }
        return $normalized;
    }

    public function requiredNonNegativeDecimal(mixed $value, string $label): string
    {
        $normalized = $this->optionalNonNegativeDecimal($value, $label);
        if ($normalized === null) {
            throw new InvalidArgumentException("{$label} es obligatorio.");
        }

        return $normalized;
    }

    public function suggestedRate(?string $cost, ?string $profitPercentage): ?string
    {
        if ($cost === null || $profitPercentage === null) {
            return null;
        }
        return (new CommercialMarginService())->priceFromMargin($cost, $profitPercentage);
    }

    public function marginFromRate(string $cost,string $rate):string{return(new CommercialMarginService())->marginFromPrice($cost,$rate);}

    private function normalizeDecimal(string $value): string
    {
        $value = trim($value);
        if (str_contains($value, ',') && str_contains($value, '.')) {
            if (strrpos($value, ',') > strrpos($value, '.')) {
                return str_replace(',', '.', str_replace('.', '', $value));
            }
            return str_replace(',', '', $value);
        }
        if (str_contains($value, ',')) {
            return str_replace(',', '.', $value);
        }
        return $value;
    }
}
