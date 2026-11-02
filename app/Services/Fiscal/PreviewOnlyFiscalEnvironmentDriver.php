<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

final class PreviewOnlyFiscalEnvironmentDriver implements FiscalEnvironmentActivationDriverInterface
{
    public function __construct(private ?object $fiscal = null, private ?object $pac = null) {}

    public function activate(string $environment, string $reason, int $actorId): void
    {
        throw new RuntimeException('FISCAL_ENVIRONMENT_PREVIEW_ONLY');
    }

    public function readBack(): array
    {
        return (new FiscalRuntimeDiagnosticService())->inspect($this->fiscal, $this->pac);
    }
}
