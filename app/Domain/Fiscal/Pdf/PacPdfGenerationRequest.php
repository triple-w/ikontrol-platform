<?php
declare(strict_types=1);

namespace App\Domain\Fiscal\Pdf;

final class PacPdfGenerationRequest
{
    public function __construct(
        public readonly int $documentId,
        public readonly int $stampId,
        public readonly int $stampAttemptId,
        public readonly string $uuid,
        public readonly string $stampedXml,
        public readonly string $templateCode,
        public readonly array $printMetadata,
        public readonly ?string $logoBase64 = null,
        public readonly int $pdfAttemptId = 0,
        public readonly string $correlationId = ''
    ) {
    }
}
