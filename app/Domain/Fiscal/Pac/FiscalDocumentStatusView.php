<?php
declare(strict_types=1);

namespace App\Domain\Fiscal\Pac;

final class FiscalDocumentStatusView
{
    public function __construct(
        public readonly object $document,
        public readonly ?object $signature,
        public readonly ?object $attempt,
        public readonly ?object $stamp,
        public readonly ?object $stampedXml,
        public readonly ?object $pdf,
        public readonly string $visibleStatus,
        public readonly string $visibleMessage,
        public readonly bool $requiresReconciliation,
        public readonly bool $canStamp,
        public readonly bool $xmlAvailable,
        public readonly bool $pdfAvailable,
        public readonly ?string $recommendedAction
    ) {
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
