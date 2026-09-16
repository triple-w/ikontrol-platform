<?php
declare(strict_types=1);

namespace App\Domain\Fiscal\Pdf;

use ArrayAccess;
use JsonSerializable;
use LogicException;

/** @implements ArrayAccess<string, mixed> */
final class FiscalPacPdfGenerationResult implements ArrayAccess, JsonSerializable
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly ?int $attemptId,
        public readonly ?int $pdfArtifactId,
        public readonly bool $pdfAvailable,
        public readonly string $templateCode,
        public readonly bool $requiresReconciliation = false,
        public readonly ?string $providerCode = null,
        public readonly ?string $providerMessage = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (bool) ($data['success'] ?? false),
            (string) ($data['status'] ?? 'stamped_pdf_error'),
            isset($data['attempt_id']) ? (int) $data['attempt_id'] : null,
            isset($data['pdf_artifact_id']) ? (int) $data['pdf_artifact_id'] : null,
            (bool) ($data['pdf_available'] ?? false),
            (string) ($data['template_code'] ?? ''),
            (bool) ($data['requires_reconciliation'] ?? false),
            isset($data['provider_code']) ? (string) $data['provider_code'] : null,
            isset($data['provider_message']) ? (string) $data['provider_message'] : null
        );
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status,
            'attempt_id' => $this->attemptId,
            'pdf_artifact_id' => $this->pdfArtifactId,
            'pdf_available' => $this->pdfAvailable,
            'template_code' => $this->templateCode,
            'requires_reconciliation' => $this->requiresReconciliation,
            'provider_code' => $this->providerCode,
            'provider_message' => $this->providerMessage,
        ];
    }

    public function jsonSerialize(): array { return $this->toArray(); }
    public function offsetExists(mixed $offset): bool { return array_key_exists((string) $offset, $this->toArray()); }
    public function offsetGet(mixed $offset): mixed { return $this->toArray()[(string) $offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void { throw new LogicException('Fiscal PDF results are immutable.'); }
    public function offsetUnset(mixed $offset): void { throw new LogicException('Fiscal PDF results are immutable.'); }
}
