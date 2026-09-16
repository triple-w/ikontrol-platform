<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class FiscalIssueDateNormalizer
{
    public const INPUT_MINUTES = 'Y-m-d\TH:i';
    public const INPUT_SECONDS = 'Y-m-d\TH:i:s';
    public const PERSISTENCE = 'Y-m-d H:i:s';

    public function timezone(): DateTimeZone
    {
        return new DateTimeZone((string) config('App')->appTimezone);
    }

    public function normalizeTransport(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new RuntimeException('FISCAL_ISSUE_DATE_REQUIRED');
        }

        foreach ([self::INPUT_MINUTES, self::INPUT_SECONDS] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value, $this->timezone());
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && (!is_array($errors) || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $date->format($format) === $value) {
                return $date->format(self::PERSISTENCE);
            }
        }

        throw new RuntimeException('FISCAL_ISSUE_DATE_INVALID');
    }

    public function parseCanonical(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!'.self::PERSISTENCE, trim($value), $this->timezone());
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format(self::PERSISTENCE) !== trim($value)) {
            throw new RuntimeException('FISCAL_ISSUE_DATE_INVALID');
        }
        return $date;
    }

    public function formatForInput(string $canonical): string
    {
        return $this->parseCanonical($canonical)->format(self::INPUT_MINUTES);
    }
}
