<?php
declare(strict_types=1);

namespace App\Domain\Fiscal;

final class FiscalInvoiceGenerationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $stage,
        public readonly string $status,
        public readonly ?int $documentId = null,
        public readonly ?int $attemptId = null,
        public readonly ?string $code = null,
        public readonly string $message = '',
        public readonly ?string $providerCode = null,
        public readonly ?string $providerMessage = null,
        public readonly ?string $uuid = null,
        public readonly bool $retryable = false,
        public readonly bool $requiresReconciliation = false,
        public readonly bool $correctable = false,
        public readonly ?string $recommendedAction = null,
        public readonly ?string $viewUrl = null,
        public readonly bool $xmlAvailable = false,
        public readonly bool $pdfAvailable = false,
        public readonly bool $requiresPdfRecovery = false,
        public readonly bool $retryableStamping = false
    ) {
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'stage' => $this->stage,
            'status' => $this->status,
            'document_id' => $this->documentId,
            'attempt_id' => $this->attemptId,
            'code' => $this->code,
            'message' => $this->message,
            'provider_code' => $this->providerCode,
            'provider_message' => $this->providerMessage,
            'uuid' => $this->uuid,
            'retryable' => $this->retryable,
            'requires_reconciliation' => $this->requiresReconciliation,
            'correctable' => $this->correctable,
            'action' => $this->recommendedAction,
            'view_url' => $this->viewUrl,
            'xml_available' => $this->xmlAvailable,
            'pdf_available' => $this->pdfAvailable,
            'requires_pdf_recovery' => $this->requiresPdfRecovery,
            'retryable_stamping' => $this->retryableStamping,
        ];
    }
}
