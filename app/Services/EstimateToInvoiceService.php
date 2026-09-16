<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Estimate_items_model;
use App\Models\Invoice_items_model;
use App\Models\Invoices_model;
use App\Services\Fiscal\FiscalDecimal;
use App\Services\Fiscal\FiscalItemOverrideContract;
use RuntimeException;

class EstimateToInvoiceService
{
    public function __construct(private ?InvoiceCreationService $creator = null, private mixed $db = null)
    {
        $this->db ??= db_connect('default');
        $this->creator ??= new InvoiceCreationService($this->db);
    }

    public function prepareHeader(object $estimate, int $userId, string $status, ?string $billDate = null, ?string $dueDate = null): array
    {
        $billDate ??= get_my_local_time('Y-m-d');
        $dueDate ??= date('Y-m-d', strtotime('+' . (int) get_setting('default_due_date_after_billing_date') . ' days', strtotime($billDate)));
        return [
            'client_id' => $estimate->client_id,
            'project_id' => (int) ($estimate->project_id ?? 0),
            'bill_date' => $billDate,
            'due_date' => $dueDate,
            'status' => $status,
            'commercial_status' => 'open',
            'tax_id' => 0,
            'tax_id2' => 0,
            'tax_id3' => 0,
            'company_id' => $estimate->company_id,
            'note' => $estimate->note,
            'labels' => '',
            'estimate_id' => $estimate->id,
            'discount_amount' => $estimate->discount_amount,
            'discount_amount_type' => $estimate->discount_amount_type,
            'discount_type' => $estimate->discount_type,
            'created_by' => $userId,
            'files' => serialize([]),
            'recurring' => 0,
            'repeat_every' => 1,
            'repeat_type' => 'months',
            'no_of_cycles' => 0,
        ];
    }

    public function getItems(int $estimateId): array
    {
        $items = (new Estimate_items_model())->get_details(['estimate_id' => $estimateId])->getResult();
        if (! $items) {
            throw new RuntimeException('La cotización no contiene partidas; no se puede crear una venta vacía.');
        }
        return array_map(function ($item) {
            $productId = (int) ($item->item_id ?? 0);
            if ($productId > 0 && ! $this->db->table('items')->where(['id' => $productId, 'deleted' => 0])->countAllResults()) {
                throw new RuntimeException('Una partida de la cotización referencia un producto inexistente o eliminado.');
            }
            $quantity=trim((string)$item->quantity);$rate=trim((string)$item->rate);if(FiscalDecimal::micros($quantity)<=0||FiscalDecimal::micros($rate)<0)throw new RuntimeException('La cotización contiene cantidades o precios no válidos.');$stored=json_decode((string)($item->fiscal_override_json??''),true);$contract=new FiscalItemOverrideContract();$normalized=$contract->normalizeStored(is_array($stored)?$stored:null,$productId);$overrideJson=$normalized?$contract->encode($normalized):null;return [
            'item_id' => $productId,
            'title' => $item->title,
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_type' => $item->unit_type,
            'cost' => $item->cost ?? null,
            'profit_percentage' => $item->profit_percentage ?? null,
            'price_origin' => ($item->price_origin ?? null) === 'cost_margin' ? 'cost_margin' : 'manual',
            'supplier_id' => $item->supplier_id ?: null,
            'rate' => $item->rate,
            'total' => FiscalDecimal::multiply($quantity,$rate),
            // RISE estimate_items has no taxable column; the original conversion marks copied rows taxable.
            'taxable' => 0,
            'fiscal_override_json' => $overrideJson,
            'sort' => $item->sort,
        ];}, $items);
    }

    public function createFromEstimate(object $estimate, int $userId, string $status, array $overrides = []): int
    {
        $this->validateRelations($estimate);
        $header = array_replace($this->prepareHeader(
            $estimate,
            $userId,
            $status,
            $overrides['bill_date'] ?? null,
            $overrides['due_date'] ?? null
        ), $overrides);
        return $this->creator->create($header, $this->getItems((int) $estimate->id), false);
    }

    private function validateRelations(object $estimate): void
    {
        $client = $this->db->table('clients')->where(['id' => $estimate->client_id, 'deleted' => 0])->get(1)->getRow();
        if (! $client || (int) $client->is_lead === 1) {
            throw new RuntimeException('El cliente de la cotización no es válido para crear una venta.');
        }
        if (! $this->db->table('company')->where(['id' => $estimate->company_id, 'deleted' => 0])->countAllResults()) {
            throw new RuntimeException('La empresa de la cotización no es válida.');
        }
        if (! empty($estimate->project_id)) {
            $project = $this->db->table('projects')->where(['id' => $estimate->project_id, 'deleted' => 0])->get(1)->getRow();
            if (! $project || (int) $project->client_id !== (int) $estimate->client_id) {
                throw new RuntimeException('El proyecto de la cotización no corresponde al cliente.');
            }
        }
    }

    /** Compatibility path for non-atomic legacy callers with an existing header. */
    public function copyItems(int $estimateId, int $invoiceId): int
    {
        $source = $this->getItems($estimateId);
        $items = new Invoice_items_model();
        foreach ($source as $row) {
            $row['invoice_id'] = $invoiceId;
            if (! $items->ci_save($row)) {
                throw new RuntimeException('No fue posible copiar una partida de la cotización.');
            }
        }
        (new Invoices_model())->update_invoice_total_meta($invoiceId);
        return count($source);
    }
}
