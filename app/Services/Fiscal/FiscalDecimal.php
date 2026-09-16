<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

final class FiscalDecimal
{
    public static function micros(string $value): int
    {
        $value = trim($value);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,6}))?$/', $value, $match)) {
            throw new RuntimeException('FISCAL_ALLOCATION_DECIMAL_INVALID');
        }
        $micros = ((int) $match[2] * 1000000) + (int) str_pad($match[3] ?? '', 6, '0');
        return $match[1] === '-' ? -$micros : $micros;
    }

    public static function format(int $micros): string
    {
        $sign = $micros < 0 ? '-' : '';
        $micros = abs($micros);
        return $sign . intdiv($micros, 1000000) . '.'
            . str_pad((string) ($micros % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function add(string ...$values): string
    {
        return self::format(array_sum(array_map([self::class, 'micros'], $values)));
    }

    public static function subtract(string $left, string ...$right): string
    {
        return self::format(self::micros($left) - array_sum(array_map([self::class, 'micros'], $right)));
    }

    public static function multiply(string $left, string $right): string
    {
        if (function_exists('bcmul')) {
            return self::normalize(bcmul($left, $right, 12));
        }
        return self::format(intdiv(self::micros($left) * self::micros($right), 1000000));
    }

    public static function divide(string $dividend, string $divisor): string
    {
        if (self::micros($divisor) === 0) throw new RuntimeException('FISCAL_DECIMAL_DIVISION_BY_ZERO');
        if (function_exists('bcdiv')) return self::normalize(bcdiv($dividend, $divisor, 12));
        return self::format(intdiv(self::micros($dividend) * 1000000, self::micros($divisor)));
    }

    public static function prorate(string $amount, string $part, string $whole): string
    {
        if (self::micros($whole) <= 0) throw new RuntimeException('FISCAL_DECIMAL_DIVISION_BY_ZERO');
        if (function_exists('bcdiv') && function_exists('bcmul')) {
            return self::normalize(bcdiv(bcmul($amount, $part, 12), $whole, 12));
        }
        return self::format(intdiv(self::micros($amount) * self::micros($part), self::micros($whole)));
    }

    private static function normalize(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad($fraction, 7, '0');
        $micros = ((int) $integer * 1000000) + (int) substr($fraction, 0, 6);
        if ((int) $fraction[6] >= 5) $micros++;
        return self::format($negative ? -$micros : $micros);
    }
}
