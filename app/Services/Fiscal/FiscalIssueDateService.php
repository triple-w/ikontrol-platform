<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;

final class FiscalIssueDateService
{
    public const TIMEZONE = 'America/Mexico_City';
    public const XML_FORMAT = 'Y-m-d\TH:i:s';
    public const SNAPSHOT_FORMAT = 'Y-m-d H:i:s';

    /** @var callable|null */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock;
    }

    public function nowForSnapshot(): string
    {
        return $this->now()->format(self::SNAPSHOT_FORMAT);
    }

    public function formatForXml(string $snapshotDate): string
    {
        $date = DateTimeImmutable::createFromFormat(
            '!' . self::SNAPSHOT_FORMAT,
            trim($snapshotDate),
            (new FiscalIssueDateNormalizer())->timezone()
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new RuntimeException('La fecha fiscal congelada no tiene el formato esperado.');
        }

        return $date->format(self::XML_FORMAT);
    }

    private function now(): DateTimeImmutable
    {
        $value = $this->clock
            ? ($this->clock)()
            : new DateTimeImmutable('now', (new FiscalIssueDateNormalizer())->timezone());
        if (!$value instanceof DateTimeInterface) {
            throw new RuntimeException('El reloj fiscal devolvió un valor inválido.');
        }

        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone((new FiscalIssueDateNormalizer())->timezone());
    }
}
