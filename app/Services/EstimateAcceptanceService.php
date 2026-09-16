<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Estimate_items_model;
use App\Models\Estimates_model;
use App\Models\Invoice_items_model;
use App\Models\Invoices_model;
use RuntimeException;
use CodeIgniter\Database\BaseConnection;

class EstimateAcceptanceService
{
    private const AUTOMATIC_SALE_SETTING = 'create_new_invoices_automatically_when_estimates_gets_accepted';

    public function __construct(private ?EstimateToInvoiceService $converter = null, private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect('default');
        $this->converter ??= new EstimateToInvoiceService(null, $this->db);
    }

    public static function acceptsStatus(string $status): bool
    {
        return EstimateAcceptanceCoordinator::acceptsStatus($status);
    }

    public function acceptAndFulfill(int $estimateId, array $acceptanceData, bool $createInvoice, int $userId): array
    {
        return (new EstimateAcceptanceCoordinator($this->converter, $this->db))->accept($estimateId, $acceptanceData, $createInvoice, $userId);
        /* Legacy implementation retained below temporarily for source compatibility; unreachable. */
        $db = db_connect('default');
        $db->transBegin();

        try {
            $acceptanceData['status'] = 'accepted';
            if (! (new Estimates_model())->ci_save($acceptanceData, $estimateId)) {
                throw new RuntimeException('No fue posible marcar la cotización como aceptada.');
            }

            $result = $createInvoice
                ? $this->fulfillInsideTransaction($estimateId, $userId)
                : $this->disabledResult();
            if ($createInvoice && !empty($result['invoice_id'])) {
                $this->markConverted($estimateId, (int)$result['invoice_id'], $userId);
            }

            $result['accepted'] = true;

            if (! $db->transStatus()) {
                throw new RuntimeException('La transacción de aceptación no pudo completarse.');
            }

            $db->transCommit();
            return $result;
        } catch (\Throwable $e) {
            $db->transRollback();
            $db->resetTransStatus();
            log_message('error', 'Atomic estimate acceptance failed estimate={estimate_id}: {exception}', [
                'estimate_id' => $estimateId,
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    public function fulfill(int $estimateId, bool $createInvoice, int $userId): array
    {
        return (new EstimateAcceptanceCoordinator($this->converter, $this->db))->accept($estimateId, [], $createInvoice, $userId);
        /* Legacy implementation retained below temporarily for source compatibility; unreachable. */
        if (! $createInvoice) {
            return $this->disabledResult();
        }

        $db = db_connect('default');
        $db->transBegin();

        try {
            $result = $this->fulfillInsideTransaction($estimateId, $userId);
            if (!empty($result['invoice_id'])) {
                $this->markConverted($estimateId, (int)$result['invoice_id'], $userId);
            }
            if (! $db->transStatus()) {
                throw new RuntimeException('La transacción de venta no pudo completarse.');
            }
            $db->transCommit();
            return $result;
        } catch (\Throwable $e) {
            $db->transRollback();
            $db->resetTransStatus();
            log_message('error', 'Estimate sale creation failed estimate={estimate_id}: {exception}', [
                'estimate_id' => $estimateId,
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    public function shouldCreateInvoiceOnAcceptance(): bool
    {
        return true;
    }

    public function resultMessageKey(array $result): string
    {
        return match ($result['invoice_action'] ?? null) {
            'created' => 'estimate_accepted_invoice_created',
            'existing' => 'estimate_accepted_invoice_existing',
            'disabled' => 'estimate_accepted_invoice_disabled',
            default => 'estimate_accepted',
        };
    }

    public static function userMessage(\Throwable $error): string
    {
        return $error instanceof RuntimeException
            ? $error->getMessage()
            : 'No fue posible aceptar la cotización y crear la venta. Inténtalo nuevamente o contacta a soporte.';
    }

    private function fulfillInsideTransaction(int $estimateId, int $userId): array
    {
        $estimates = new Estimates_model();
        $invoices = new Invoices_model();
        $invoiceItems = new Invoice_items_model();
        $estimate = $estimates->get_one($estimateId);

        if (! $estimate->id || $estimate->deleted) {
            throw new RuntimeException('La cotización no existe o fue eliminada.');
        }
        if ($estimate->status !== 'accepted') {
            throw new RuntimeException('La cotización debe estar aceptada antes de crear la venta.');
        }

        $matches = $invoices->get_all_where(['estimate_id' => $estimateId, 'deleted' => 0], 2)->getResult();
        if (count($matches) > 1) {
            throw new RuntimeException('Existe más de una venta para esta cotización. Resuelve el conflicto antes de continuar.');
        }

        $invoice = $matches[0] ?? null;
        if ($invoice && $invoice->id) {
            $items = $invoiceItems->get_all_where(['invoice_id' => $invoice->id, 'deleted' => 0], 1000000, 0, 'sort')->getResult();

            if (! $items) {
                if (! $this->matchesHeader($estimate, $invoice)) {
                    throw new RuntimeException('Existe una venta incompleta modificada para esta cotización. Revísala manualmente; no se creó un duplicado.');
                }
                $this->converter->copyItems($estimateId, (int) $invoice->id);
                $statusData = ['status' => 'not_paid'];
                $invoices->ci_save($statusData, $invoice->id);
                log_message('warning', 'Repaired empty automatic invoice={invoice_id} estimate={estimate_id}.', [
                    'invoice_id' => $invoice->id,
                    'estimate_id' => $estimateId,
                ]);
                return ['invoice_action' => 'existing', 'invoice_id' => (int) $invoice->id, 'created_invoice' => false, 'repaired_invoice' => true];
            }

            if ($invoice->status === 'draft' && $this->matchesHeader($estimate, $invoice) && $this->itemsMatch($estimate, $items)) {
                $statusData = ['status' => 'not_paid'];
                $invoices->ci_save($statusData, $invoice->id);
                return ['invoice_action' => 'existing', 'invoice_id' => (int) $invoice->id, 'created_invoice' => false, 'repaired_invoice' => true];
            }

            log_message('info', 'Reused existing invoice={invoice_id} estimate={estimate_id}; no duplicate.', [
                'invoice_id' => $invoice->id,
                'estimate_id' => $estimateId,
            ]);
            return ['invoice_action' => 'existing', 'invoice_id' => (int) $invoice->id, 'created_invoice' => false, 'repaired_invoice' => false];
        }

        $invoiceId = $this->converter->createFromEstimate($estimate, $userId, 'not_paid');
        return ['invoice_action' => 'created', 'invoice_id' => $invoiceId, 'created_invoice' => true, 'repaired_invoice' => false];
    }

    private function disabledResult(): array
    {
        return ['invoice_action' => 'disabled', 'invoice_id' => null, 'created_invoice' => false, 'repaired_invoice' => false];
    }

    private function markConverted(int $estimateId, int $invoiceId, int $userId): void
    {
        $conversionData = [
            'status' => 'converted',
            'converted_sale_id' => $invoiceId,
            'converted_at' => get_current_utc_time(),
            'converted_by' => $userId,
        ];
        (new Estimates_model())->ci_save($conversionData, $estimateId);
        db_connect()->table('commercial_lifecycle_audit')->insert([
            'entity_type'=>'quotation','entity_id'=>$estimateId,'event'=>'quotation_converted',
            'old_status'=>'accepted','new_status'=>'converted','reason'=>null,
            'user_id'=>$userId,'created_at'=>get_current_utc_time(),
        ]);
    }

    private function matchesHeader(object $estimate, object $invoice): bool
    {
        foreach (['client_id', 'company_id', 'tax_id', 'tax_id2', 'discount_amount', 'discount_amount_type', 'discount_type', 'note'] as $field) {
            if ((string) $estimate->$field !== (string) $invoice->$field) {
                return false;
            }
        }
        return (int) $invoice->project_id === 0 && (int) $invoice->tax_id3 === 0;
    }

    private function itemsMatch(object $estimate, array $target): bool
    {
        $source = (new Estimate_items_model())->get_details(['estimate_id' => $estimate->id])->getResult();
        if (count($source) !== count($target) || ! $source) {
            return false;
        }
        foreach ($source as $index => $item) {
            foreach (['title', 'description', 'quantity', 'unit_type', 'rate', 'total', 'item_id'] as $field) {
                if ((string) $item->$field !== (string) $target[$index]->$field) {
                    return false;
                }
            }
        }
        return true;
    }
}
