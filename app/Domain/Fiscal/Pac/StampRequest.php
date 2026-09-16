<?php
declare(strict_types=1);

namespace App\Domain\Fiscal\Pac;

final class StampRequest
{
    public function __construct(
        public readonly int $fiscalDocumentId,
        public readonly string $signedXml,
        public readonly string $signedXmlSha256,
        public readonly string $provider,
        public readonly string $environment,
        public readonly string $idempotencyKey,
        public readonly ?string $keyPem = null
    ) {
    }
}
