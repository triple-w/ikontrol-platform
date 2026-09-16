<?php
declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** Canonical transaction for accepting an Estimate and creating its Invoice sale. */
final class EstimateAcceptanceCoordinator
{
    public const STATUS_ACCEPTED = 'accepted';
    private const ACCEPTABLE_STATUSES = ['draft', 'sent', self::STATUS_ACCEPTED];

    public static function acceptsStatus(string $status): bool
    {
        return in_array($status, self::ACCEPTABLE_STATUSES, true);
    }

    public function __construct(
        private ?EstimateToInvoiceService $converter = null,
        private ?BaseConnection $db = null
    ) {
        $this->db ??= db_connect('default');
        $this->converter ??= new EstimateToInvoiceService(null, $this->db);
    }

    public function accept(int $estimateId, array $acceptanceData, bool $createInvoice, int $userId): array
    {
        if (! $createInvoice) throw new RuntimeException('La aceptación de la cotización requiere crear su venta relacionada.');
        $this->db->transBegin();
        try {
            $estimate = $this->lockedEstimate($estimateId);
            $existing = $this->existingSale($estimate);
            if ($existing) {
                $this->ensureBacklink($estimate, $existing, $acceptanceData, $userId);
                (new SupplierCostHistoryService($this->db))->snapshotEstimate($estimateId, self::STATUS_ACCEPTED, $userId);
                (new SupplierCostHistoryService($this->db))->snapshotInvoice((int)$existing->id, (string)($existing->status ?: 'not_paid'), $userId);
                return $this->commit($this->result($estimateId, (int) $existing->id, 'existing'));
            }
            if (! self::acceptsStatus((string) $estimate->status)) {
                throw new RuntimeException('El estado actual de la cotización no permite aceptarla.');
            }
            // Fiscal readiness is intentionally deferred to CFDI preparation.
            $invoiceId = $this->converter->createFromEstimate($estimate, $userId, 'not_paid');
            $this->persistAcceptance($estimate, $invoiceId, $acceptanceData, $userId);
            (new SupplierCostHistoryService($this->db))->snapshotEstimate($estimateId, self::STATUS_ACCEPTED, $userId);
            (new SupplierCostHistoryService($this->db))->snapshotInvoice($invoiceId, 'not_paid', $userId);
            return $this->commit($this->result($estimateId, $invoiceId, 'created'));
        } catch (\Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();
            log_message('error', 'Atomic estimate acceptance failed estimate={estimate_id} stage=accept_and_convert: {exception}', [
                'estimate_id' => $estimateId, 'exception' => $e,
            ]);
            throw $e;
        }
    }

    private function lockedEstimate(int $id): object
    {
        $table = $this->db->prefixTable('estimates');
        $row = $this->db->query("SELECT * FROM {$table} WHERE id=? AND deleted=0 FOR UPDATE", [$id])->getRow();
        if (! $row) throw new RuntimeException('La cotización no existe o fue eliminada.');
        return $row;
    }

    private function existingSale(object $estimate): ?object
    {
        $linked = $this->db->table('invoices')->where(['estimate_id' => $estimate->id, 'deleted' => 0])->get()->getResult();
        if (count($linked) > 1) throw new RuntimeException('Existe más de una venta vinculada a la cotización; se requiere revisión manual.');
        if ($estimate->converted_sale_id) {
            $backlink = $this->db->table('invoices')->where([
                'id' => $estimate->converted_sale_id, 'estimate_id' => $estimate->id, 'deleted' => 0,
            ])->get(1)->getRow();
            if (! $backlink) throw new RuntimeException('La relación Cotización–Venta es inconsistente; se requiere revisión manual.');
            if ($linked && (int) $linked[0]->id !== (int) $backlink->id) throw new RuntimeException('La cotización contiene vínculos de venta incompatibles.');
            return $backlink;
        }
        return $linked[0] ?? null;
    }

    private function ensureBacklink(object $estimate, object $invoice, array $data, int $userId): void
    {
        if ((int) ($estimate->converted_sale_id ?? 0) === (int) $invoice->id && (string) $estimate->status === self::STATUS_ACCEPTED) return;
        $this->persistAcceptance($estimate, (int) $invoice->id, $data, $userId, false);
    }

    private function persistAcceptance(object $estimate, int $invoiceId, array $input, int $userId, bool $audit = true): void
    {
        $now = get_current_utc_time();
        $allowed = array_intersect_key($input, array_flip(['accepted_by', 'meta_data', 'signature']));
        $data = $allowed + [
            'accepted_by' => $userId, 'status' => self::STATUS_ACCEPTED,
            'converted_sale_id' => $invoiceId, 'converted_at' => $now, 'converted_by' => $userId,
        ];
        if (! $this->db->table('estimates')->where(['id' => $estimate->id, 'deleted' => 0])->update($data)) {
            throw new RuntimeException('No fue posible registrar la aceptación de la cotización.');
        }
        if ($audit && $this->db->tableExists('commercial_lifecycle_audit') && ! $this->db->table('commercial_lifecycle_audit')->insert([
            'entity_type' => 'quotation', 'entity_id' => $estimate->id,
            'event' => 'quotation_accepted_and_converted', 'old_status' => $estimate->status,
            'new_status' => self::STATUS_ACCEPTED, 'reason' => null,
            'user_id' => $userId, 'created_at' => $now,
        ])) throw new RuntimeException('No fue posible registrar la auditoría de aceptación.');
    }

    private function commit(array $result): array
    {
        if (! $this->db->transStatus()) throw new RuntimeException('La transacción de aceptación no pudo completarse.');
        $this->db->transCommit();
        return $result;
    }

    private function result(int $estimateId, int $invoiceId, string $action): array
    {
        return [
            'accepted' => true, 'estimate_id' => $estimateId, 'invoice_id' => $invoiceId,
            'invoice_action' => $action, 'created_invoice' => $action === 'created',
            'invoice_url' => get_uri('invoices/view/' . $invoiceId),
        ];
    }
}
