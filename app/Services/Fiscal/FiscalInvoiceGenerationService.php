<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Domain\Fiscal\FiscalInvoiceGenerationResult;
use App\Services\Fiscal\Cfdi40\CfdiPreXmlArtifactService;
use App\Services\Fiscal\Cfdi40\CfdiSigningService;
use App\Services\Fiscal\Pac\FiscalDocumentStatusPresenter;
use App\Services\Fiscal\Pac\FiscalPacAdapterFactory;
use App\Services\Fiscal\Pac\FiscalStampingService;
use App\Services\Fiscal\Pdf\FiscalPacPdfGenerationService;
use App\Services\Fiscal\Signing\CsdOperationalStatusService;
use RuntimeException;
use Throwable;

final class FiscalInvoiceGenerationService
{
    private $db;
    private string $stage = 'configuration';

    public function __construct(
        $db = null,
        private readonly ?FiscalDraftCreationService $drafts = null,
        private readonly ?CfdiPreXmlArtifactService $preXml = null,
        private readonly ?CfdiSigningService $signing = null,
        private readonly ?FiscalStampingService $stamping = null,
        private readonly ?FiscalDocumentStatusPresenter $statuses = null,
        private readonly ?FiscalPacAdapterFactory $adapterFactory = null,
        private readonly ?CsdOperationalStatusService $csdStatuses = null,
        private readonly ?FiscalInvoiceGenerationErrorPresenter $errors = null,
        private readonly ?FiscalPacPdfGenerationService $pdfGeneration = null
    ) {
        $this->db = $db ?: db_connect();
    }

    public function generate(int $invoiceId, array $input, int $userId, bool $authorized): FiscalInvoiceGenerationResult
    {
        if (!$authorized) {
            return new FiscalInvoiceGenerationResult(
                false, 'authorization', 'forbidden', null, null,
                'FISCAL_GENERATION_FORBIDDEN', 'No tiene permiso para generar la factura.'
            );
        }
        if ($invoiceId <= 0) {
            return new FiscalInvoiceGenerationResult(
                false, 'validation', 'correctable_error', null, null,
                'FISCAL_INVOICE_INVALID', 'La venta indicada no es válida.',
                correctable: true, recommendedAction: 'Corregir datos fiscales'
            );
        }

        $lock = 'ikontrol:fiscal:invoice:' . $invoiceId;
        if (!$this->acquireLock($lock)) {
            return new FiscalInvoiceGenerationResult(
                false, 'concurrency', 'processing', null, null,
                'FISCAL_GENERATION_IN_PROGRESS',
                'La factura ya está siendo procesada en otra solicitud.',
                recommendedAction: 'Esperar el resultado en curso'
            );
        }

        $documentId = null;
        try {
            $document = $this->latestDocument($invoiceId);
            if ($document) {
                $documentId = (int) $document->id;
                $projected = ($this->statuses ?? new FiscalDocumentStatusPresenter($this->db))
                    ->forDocument($documentId);
                if ($projected->visibleStatus === 'stamped') {
                    return $this->projectedResult($projected, true);
                }
                if (in_array($projected->visibleStatus, ['stamped_pdf_pending', 'stamped_pdf_error'], true)) {
                    return $this->projectedResult($projected, false);
                }
                if (in_array($projected->visibleStatus, ['processing', 'unknown'], true)) {
                    return $this->projectedResult($projected, false);
                }
                if ($projected->visibleStatus === 'correctable_error') {
                    if (empty($input['confirm_new_version'])) {
                        return $this->projectedResult($projected, false);
                    }
                    $document = null;
                    $documentId = null;
                }
            }

            $this->stage = 'configuration';
            ($this->adapterFactory ?? new FiscalPacAdapterFactory())->create();

            if (!$document) {
                $this->stage = 'readiness';
                $issuerId = $this->positive($input, 'issuer_profile_id');
                $receiverId = $this->positive($input, 'receiver_profile_id');
                $seriesId = $this->positive($input, 'series_id');
                $preparationId = $this->positive($input, 'preparation_id');
                $review = (new SaleFiscalReadinessService())->review(
                    $invoiceId, $issuerId, $seriesId, $receiverId
                );
                if (!$review['is_ready']) {
                    throw new RuntimeException('La venta todavía no reúne los datos fiscales necesarios.');
                }

                $this->stage = 'pricing';
                $preparation = $this->db->table('sale_fiscal_pricing_preparations')
                    ->where(['id' => $preparationId, 'invoice_id' => $invoiceId])
                    ->get(1)->getRow();
                if (!$preparation || in_array($preparation->status, ['superseded', 'stale', 'confirmation_required'], true)) {
                    throw new RuntimeException('La preparación de precios debe estar confirmada y vigente.');
                }

                $this->stage = 'csd';
                $certificate = $this->readyCertificate($issuerId, $input);

                $this->stage = 'snapshot';
                $created = ($this->drafts ?? new FiscalDraftCreationService($this->db))->create(
                    $invoiceId,
                    $issuerId,
                    $receiverId,
                    $seriesId,
                    $preparationId,
                    [
                        'payment_form_code' => (string) ($input['payment_form_code'] ?? ''),
                        'payment_method_code' => (string) ($input['payment_method_code'] ?? ''),
                        'currency_code' => (string) ($input['currency_code'] ?? ''),
                        'exchange_rate' => $input['exchange_rate'] ?? null,
                        'export_code' => '01',
                    ],
                    $userId,
                    true,
                    !empty($input['confirm_new_version'])
                );
                $documentId = (int) $created['id'];
                $document = $this->document($documentId);
                $input['certificate_id'] = (int) $certificate->id;
            }

            if (in_array((string) $document->status, ['draft', 'ready'], true)) {
                $this->stage = 'snapshot';
                ($this->drafts ?? new FiscalDraftCreationService($this->db))
                    ->changeStatus($documentId, 'lock', $userId, true, 'Flujo normal Generar factura');
                $document = $this->document($documentId);
            }

            $preXmlArtifact = $this->activeArtifact($documentId, 'pre_xml');
            if (!$preXmlArtifact) {
                $this->stage = 'xml';
                $generated = ($this->preXml ?? new CfdiPreXmlArtifactService($this->db))
                    ->generate($documentId, $userId, true);
                if (!($generated['semantic']['is_valid'] ?? false)) {
                    throw new RuntimeException('El Pre-XML no supera la validación semántica.');
                }
                $preXmlArtifact = $generated['artifact'];
            }

            $signature = $this->db->table('fiscal_document_signatures')
                ->where('fiscal_document_id', $documentId)->orderBy('id', 'DESC')->get(1)->getRow();
            if (!$signature) {
                $this->stage = 'csd';
                $certificate = $this->readyCertificate((int) $document->issuer_profile_id, $input);
                $this->stage = 'signing';
                $signed = ($this->signing ?? new CfdiSigningService($this->db))->sign(
                    $documentId,
                    (int) $preXmlArtifact->id,
                    (int) $certificate->id,
                    $userId,
                    true
                );
                $signature = $signed['signature'];
            }

            $this->stage = 'verification';
            $stamping = $this->stamping ?? new FiscalStampingService(
                $this->db,
                $this->adapterFactory
            );
            $verification = $stamping->verifySignedDocument($documentId);
            if (!$verification->valid) {
                throw new RuntimeException('El XML firmado no supera la verificación independiente.');
            }

            $this->stage = 'stamping';
            $stampResult = $stamping->stamp($documentId, $userId, true);
            $pdfResult = null;
            $pdfConfiguration = config('FiscalPdfProvider');
            if ($stampResult->success && !$stampResult->pdfAvailable && $pdfConfiguration->enabled) {
                $this->stage = 'pdf';
                try {
                    $pdfResult = ($this->pdfGeneration ?? new FiscalPacPdfGenerationService($this->db))
                        ->generate($documentId, $userId);
                } catch (Throwable $pdfError) {
                    log_message('warning', 'Fiscal PDF generation failed after stamping document {document}: {type}', [
                        'document' => $documentId,
                        'type' => get_class($pdfError),
                    ]);
                    $projected = ($this->statuses ?? new FiscalDocumentStatusPresenter($this->db))
                        ->forDocument($documentId);
                    $pdfResult = [
                        'status' => $projected->visibleStatus,
                        'pdf_available' => $projected->pdfAvailable,
                    ];
                }
            }

            $finalStatus = $pdfResult['status'] ?? $stampResult->status;
            $pdfAvailable = (bool) ($pdfResult['pdf_available'] ?? $stampResult->pdfAvailable);

            return new FiscalInvoiceGenerationResult(
                $stampResult->success,
                $stampResult->stage,
                $finalStatus,
                $stampResult->documentId,
                $stampResult->attemptId,
                $stampResult->success ? null : 'FISCAL_STAMP_' . strtoupper($stampResult->status),
                $stampResult->success ? 'Factura generada correctamente.' : ($stampResult->providerMessage ?: 'No fue posible completar el timbrado.'),
                $stampResult->providerCode,
                $stampResult->providerMessage,
                $stampResult->uuid,
                $stampResult->retryable,
                $stampResult->requiresReconciliation,
                $stampResult->status === 'rejected',
                $stampResult->recommendedAction,
                get_uri('fiscal/invoices/drafts/' . $documentId . '/view'),
                $stampResult->xmlAvailable,
                $pdfAvailable,
                $stampResult->requiresPdfRecovery && !$pdfAvailable,
                false
            );
        } catch (Throwable $error) {
            log_message('warning', 'Fiscal invoice generation stopped at {stage} for invoice {invoice}: {type}', [
                'stage' => $this->stage,
                'invoice' => $invoiceId,
                'type' => get_class($error),
            ]);

            return ($this->errors ?? new FiscalInvoiceGenerationErrorPresenter())
                ->present($error, $this->stage, $documentId);
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function latestDocument(int $invoiceId): ?object
    {
        return $this->db->table('fiscal_documents')
            ->where(['invoice_id' => $invoiceId, 'deleted' => 0])
            ->whereNotIn('status', ['cancelled_internal', 'superseded'])
            ->orderBy('id', 'DESC')->get(1)->getRow();
    }

    private function document(int $id): object
    {
        $document = $this->db->table('fiscal_documents')->where(['id' => $id, 'deleted' => 0])->get(1)->getRow();
        if (!$document) {
            throw new RuntimeException('El documento fiscal no existe.');
        }
        return $document;
    }

    private function activeArtifact(int $documentId, string $type): ?object
    {
        return $this->db->table('fiscal_document_artifacts')->where([
            'fiscal_document_id' => $documentId,
            'artifact_type' => $type,
            'superseded_at' => null,
        ])->orderBy('id', 'DESC')->get(1)->getRow();
    }

    private function readyCertificate(int $issuerId, array $input): object
    {
        $builder = $this->db->table('fiscal_issuer_certificates')->where([
            'issuer_profile_id' => $issuerId,
            'status' => 'valid',
            'deleted' => 0,
        ]);
        if (!empty($input['certificate_id'])) {
            $builder->where('id', (int) $input['certificate_id']);
        }
        $certificate = $builder->orderBy('is_default', 'DESC')->orderBy('id', 'DESC')->get(1)->getRow();
        if (!$certificate) {
            throw new RuntimeException('No existe un CSD activo para el emisor.');
        }
        $status = ($this->csdStatuses ?? new CsdOperationalStatusService($this->db))
            ->forCertificate($certificate);
        if (!$status['ready']) {
            throw new RuntimeException('El CSD requiere configuración antes de generar la factura.');
        }
        return $certificate;
    }

    private function projectedResult(object $view, bool $success): FiscalInvoiceGenerationResult
    {
        return new FiscalInvoiceGenerationResult(
            $success,
            $view->visibleStatus === 'stamped' ? 'completed' : 'recovery',
            $view->visibleStatus,
            (int) $view->document->id,
            $view->attempt ? (int) $view->attempt->id : null,
            $view->visibleStatus === 'unknown' ? 'FISCAL_STAMP_RESULT_UNKNOWN' : null,
            $view->visibleMessage,
            $view->attempt->provider_code ?? null,
            $view->attempt->provider_message ?? null,
            $view->stamp->uuid ?? null,
            false,
            $view->requiresReconciliation,
            $view->visibleStatus === 'correctable_error',
            $view->visibleStatus === 'correctable_error' ? 'Corregir datos fiscales' : $view->recommendedAction,
            get_uri('fiscal/invoices/drafts/' . $view->document->id . '/view'),
            $view->xmlAvailable,
            $view->pdfAvailable,
            in_array($view->visibleStatus, ['stamped_pdf_pending', 'stamped_pdf_error'], true),
            false
        );
    }

    private function positive(array $input, string $key): int
    {
        $value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$value) {
            throw new RuntimeException('Falta un dato requerido para generar la factura.');
        }
        return (int) $value;
    }

    private function acquireLock(string $name): bool
    {
        $row = $this->db->query('SELECT GET_LOCK(?, 0) AS acquired', [$name])->getRow();
        return (int) ($row->acquired ?? 0) === 1;
    }

    private function releaseLock(string $name): void
    {
        try {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$name]);
        } catch (Throwable) {
            // Connection-scoped MySQL locks are also released when the request ends.
        }
    }
}
