<?php
declare(strict_types=1);

namespace App\Domain\Fiscal\Signing;

final class SignedXmlVerificationResult
{
    public function __construct(
        public readonly bool $valid,
        public readonly bool $xmlWellFormed,
        public readonly bool $xsdValid,
        public readonly bool $certificateValid,
        public readonly bool $certificateMatchesIssuer,
        public readonly bool $certificateNumberMatches,
        public readonly bool $signatureValid,
        public readonly bool $originalChainGenerated,
        public readonly array $errors,
        public readonly array $warnings,
        public readonly string $xmlSha256,
        public readonly ?string $certificateNumber = null,
        public readonly ?string $certificateRfc = null,
        public readonly ?string $validFrom = null,
        public readonly ?string $validTo = null
    ) {
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
