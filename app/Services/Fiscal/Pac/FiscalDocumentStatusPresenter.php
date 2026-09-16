<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pac;

use App\Domain\Fiscal\Pac\FiscalDocumentStatusView;
use Config\Fiscal;
use RuntimeException;

/**
 * Read-only projection. It never repairs or changes persisted fiscal states.
 */
final class FiscalDocumentStatusPresenter
{
    private $db;

    public function __construct($db = null, private readonly ?Fiscal $configuration = null)
    {
        $this->db = $db ?? db_connect();
    }

    public function forDocument(int $documentId): FiscalDocumentStatusView
    {
        $document = $this->db->table('fiscal_documents')
            ->where(['id' => $documentId, 'deleted' => 0])
            ->get(1)->getRow();
        if (!$document) {
            throw new RuntimeException('El documento fiscal no existe.');
        }

        $signature = $this->db->table('fiscal_document_signatures')
            ->where('fiscal_document_id', $documentId)->orderBy('id', 'DESC')->get(1)->getRow();
        $attempt = $this->db->table('fiscal_stamp_attempts')
            ->where('fiscal_document_id', $documentId)->orderBy('id', 'DESC')->get(1)->getRow();
        $stamp = $this->db->table('fiscal_document_stamps')
            ->where('fiscal_document_id', $documentId)->get(1)->getRow();
        $stampedXml = $this->db->table('fiscal_document_artifacts')
            ->where([
                'fiscal_document_id' => $documentId,
                'artifact_type' => 'stamped_xml',
                'superseded_at' => null,
            ])->get(1)->getRow();

        $pdf = null;
        if ($this->db->tableExists('fiscal_document_binary_artifacts')
            && $stamp
            && (int) ($stamp->pac_pdf_artifact_id ?? 0) > 0) {
            $pdf = $this->db->table('fiscal_document_binary_artifacts')
                ->where([
                    'id' => (int) $stamp->pac_pdf_artifact_id,
                    'fiscal_document_id' => $documentId,
                    'artifact_type' => 'pac_pdf',
                    'validation_status' => 'valid',
                ])->get(1)->getRow();
        }
        $cancellation = $this->db->tableExists('fiscal_cancellation_requests')
            ? $this->db->table('fiscal_cancellation_requests')->where('fiscal_document_id', $documentId)->orderBy('id', 'DESC')->get(1)->getRow()
            : null;

        [$status, $message, $reconciliation, $canStamp, $action] =
            $this->project($document, $signature, $attempt, $stamp, $stampedXml, $pdf, $cancellation);

        return new FiscalDocumentStatusView(
            $document,
            $signature,
            $attempt,
            $stamp,
            $stampedXml,
            $pdf,
            $status,
            $message,
            $reconciliation,
            $canStamp,
            $stampedXml !== null,
            $pdf !== null,
            $action
        );
    }

    private function project(
        object $document,
        ?object $signature,
        ?object $attempt,
        ?object $stamp,
        ?object $stampedXml,
        ?object $pdf,
        ?object $cancellation
    ): array {
        if ($cancellation) {
            if ($cancellation->status === 'accepted') return ['cancelled', 'El CFDI está cancelado fiscalmente.', false, false, null];
            if (in_array($cancellation->status, ['requested', 'sending', 'pending'], true)) return ['cancellation_pending', 'La cancelación fiscal está pendiente.', false, false, 'Esperar o consultar el resultado.'];
            if ($cancellation->status === 'rejected') return ['cancellation_rejected', 'La cancelación fiscal fue rechazada.', false, false, 'Revisar el motivo del rechazo.'];
            if ($cancellation->status === 'unknown') return ['stamped', 'El CFDI continúa timbrado mientras se verifica la cancelación.', true, false, 'Consultar el estado sin reenviar la cancelación.'];
        }
        if ($stamp && trim((string) ($stamp->uuid ?? '')) !== '' && $stampedXml) {
            if (($stamp->pdf_status ?? '') === 'processing'
                || $document->status === 'stamped_pdf_processing') {
                return ['stamped_pdf_processing', 'El CFDI está timbrado; la generación del PDF está en proceso.', false, false, 'Esperar el resultado del intento.'];
            }
            if (($stamp->pdf_status ?? '') === 'unknown'
                || $document->status === 'stamped_pdf_unknown') {
                return ['stamped_pdf_unknown', 'El CFDI está timbrado, pero el resultado del PDF es desconocido.', true, false, 'Conciliar el intento de PDF sin retimbrar.'];
            }
            if (!$pdf || ($stamp->pdf_status ?? 'pending') === 'pending') return ['stamped_pdf_pending', 'El CFDI está timbrado; el PDF está pendiente.', false, false, 'Recuperar PDF'];
            if (($stamp->pdf_status ?? '') === 'error') return ['stamped_pdf_error', 'El CFDI está timbrado; el PDF recibido no fue válido.', false, false, 'Recuperar PDF'];
            return ['stamped', 'El CFDI está timbrado.', false, false, null];
        }

        if ($document->status === 'cancelled_internal') return ['cancelled_internal', 'La preparación fue cancelada internamente.', false, false, null];
        if ($document->status === 'superseded') return ['superseded', 'La preparación fue reemplazada.', false, false, null];

        if ($document->status === 'stamping') {
            if (!$attempt || $this->isStaleSending($attempt)) {
                return [
                    'unknown',
                    'No fue posible confirmar el resultado del intento de timbrado. No reenvíe este CFDI hasta completar una conciliación.',
                    true,
                    false,
                    'Revisar intento y conciliar sin reenviar.',
                ];
            }
            return ['processing', 'El intento de timbrado está en proceso.', false, false, 'Esperar el resultado del intento.'];
        }

        if ($document->status === 'stamp_status_unknown'
            || ($attempt && ((int) ($attempt->requires_reconciliation ?? 0) === 1
                || in_array($attempt->status, ['timeout_unknown', 'reconciliation_required', 'response_invalid', 'duplicate_reported'], true)))) {
            return [
                'unknown',
                'No fue posible confirmar el resultado del intento de timbrado. No reenvíe este CFDI hasta completar una conciliación.',
                true,
                false,
                'Revisar intento y conciliar sin reenviar.',
            ];
        }

        if ($document->status === 'stamping_error'
            || ($attempt && in_array($attempt->status, ['rejected', 'transport_not_sent'], true))) {
            return [
                'correctable_error',
                'El CFDI no fue timbrado. Revise el detalle antes de preparar una nueva versión.',
                false,
                false,
                (string) ($attempt->recommended_action ?? 'Corregir los datos o la configuración indicada.'),
            ];
        }

        if ($signature && in_array($document->status, ['locked', 'ready_to_stamp'], true)) {
            return ['signed', 'El documento firmado está listo para continuar mediante el orquestador.', false, true, null];
        }

        return ['draft', 'El documento fiscal continúa en preparación.', false, false, null];
    }

    private function isStaleSending(object $attempt): bool
    {
        if (!in_array($attempt->status, ['pending', 'sending_prepared', 'sending'], true)) {
            return false;
        }
        $reference = (string) ($attempt->sent_at ?: $attempt->started_at ?: $attempt->created_at);
        $timestamp = strtotime($reference);
        if ($timestamp === false) {
            return true;
        }
        $minutes = ($this->configuration ?? config('Fiscal'))->stampingSendingStaleMinutes;

        return $timestamp <= time() - ($minutes * 60);
    }
}
