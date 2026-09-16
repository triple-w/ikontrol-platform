<?php
declare(strict_types=1);

namespace App\Domain\Fiscal\Pac;

final class FiscalStampingResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly int $documentId,
        public readonly ?int $attemptId,
        public readonly string $stage,
        public readonly ?string $providerCode = null,
        public readonly ?string $providerMessage = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $uuid = null,
        public readonly bool $retryable = false,
        public readonly bool $requiresReconciliation = false,
        public readonly ?string $recommendedAction = null,
        public readonly bool $xmlAvailable = false,
        public readonly bool $pdfAvailable = false,
        public readonly bool $requiresPdfRecovery = false
    ) {
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
