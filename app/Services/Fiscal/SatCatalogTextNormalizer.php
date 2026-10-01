<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

final class SatCatalogTextNormalizer
{
    public static function description(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $ascii === false ? $value : $ascii;
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
    }
}
