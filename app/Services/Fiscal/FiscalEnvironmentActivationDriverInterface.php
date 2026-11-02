<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/** Installation-specific activation contract. No mutable driver ships by default. */
interface FiscalEnvironmentActivationDriverInterface
{
    public function activate(string $environment, string $reason, int $actorId): void;
    public function readBack(): array;
}
