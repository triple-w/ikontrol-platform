<?php
declare(strict_types=1);

namespace App\Domain\Fiscal\Pac;

final class StampResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerCode,
        public readonly string $providerMessage,
        public readonly ?string $stampedXml = null,
        public readonly ?string $uuid = null,
        public readonly ?string $stampDate = null,
        public readonly ?string $pacRfc = null,
        public readonly ?string $satCertificateNumber = null,
        public readonly ?string $cfdSeal = null,
        public readonly ?string $satSeal = null,
        public readonly ?string $tfdVersion = null,
        public readonly array $rawResponseMetadata = [],
        public readonly ?string $errorCategory = null,
        public readonly bool $retryable = false,
        public readonly bool $statusUnknown = false
    ) {
    }
}
