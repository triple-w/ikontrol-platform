<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice_items_model;
use App\Models\Invoices_model;
use CodeIgniter\Database\BaseConnection;
use App\Services\Fiscal\FiscalDecimal;
use RuntimeException;

/** Creates a complete RISE invoice header, items and official totals atomically. */
class InvoiceCreationService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect('default');
    }

    public function create(array $header, array $items, bool $manageTransaction = true): int
    {
        if (! $items) {
            throw new RuntimeException('La venta requiere al menos una partida.');
        }

        $db = $this->db;
        if ($manageTransaction) {
            $db->transBegin();
        }

        try {
            $invoices = new Invoices_model($db);
            $invoiceItems = new Invoice_items_model($db);

            $header += [
                'type' => 'invoice',
                'project_id' => 0,
                'estimate_id' => 0,
                'status' => 'draft',
                'labels' => '',
                'recurring' => 0,
                // These hidden form defaults are posted by the ordinary RISE invoice modal even when recurring=0.
                'repeat_every' => 1,
                'repeat_type' => 'months',
                'no_of_cycles' => 0,
                'tax_id' => 0,
                'tax_id2' => 0,
                'tax_id3' => 0,
                'discount_amount' => 0,
                'discount_amount_type' => 'percentage',
                'discount_type' => 'before_tax',
                'files' => serialize([]),
            ];

            foreach (['client_id', 'bill_date', 'due_date', 'company_id', 'created_by'] as $required) {
                if (! isset($header[$required]) || $header[$required] === '') {
                    throw new RuntimeException("Falta el campo requerido de venta: {$required}.");
                }
            }

            if (empty($header['display_id'])) {
                $header = array_merge($header, prepare_invoice_display_id_data($header['due_date'], $header['bill_date']));
            }

            $invoiceId = (int) $invoices->save_invoice_and_update_total($header);
            if (! $invoiceId) {
                throw new RuntimeException('No fue posible guardar el encabezado de la venta.');
            }

            foreach ($items as $position => $item) {
                $item = (array) $item;
                $quantity = $item['quantity'] ?? 0;
                $rate = $item['rate'] ?? 0;
                $row = [
                    'invoice_id' => $invoiceId,
                    'item_id' => $item['item_id'] ?? 0,
                    'title' => $item['title'] ?? '',
                    'description' => $item['description'] ?? '',
                    'quantity' => $quantity,
                    'unit_type' => $item['unit_type'] ?? '',
                    'cost' => $item['cost'] ?? null,
                    'profit_percentage' => $item['profit_percentage'] ?? null,
                    'price_origin' => ($item['price_origin'] ?? null) === 'cost_margin' ? 'cost_margin' : 'manual',
                    'supplier_id' => $item['supplier_id'] ?? null,
                    'rate' => $rate,
                    'total' => $item['total'] ?? FiscalDecimal::multiply((string) $quantity, (string) $rate),
                    'taxable' => array_key_exists('taxable', $item) ? (int) $item['taxable'] : 1,
                    'fiscal_override_json' => $item['fiscal_override_json'] ?? null,
                    'sort' => $item['sort'] ?? $position,
                    'deleted' => 0,
                ];
                if (! $invoiceItems->ci_save($row)) {
                    throw new RuntimeException('No fue posible guardar una partida de la venta.');
                }
            }

            if (! $invoices->update_invoice_total_meta($invoiceId)) {
                throw new RuntimeException('No fue posible recalcular los totales oficiales de la venta.');
            }
            (new \App\Services\Fiscal\CommercialSaleTotalConsistencyService($db))->synchronizeIfCanonical($invoiceId);

            $savedCount = $invoiceItems->get_all_where(['invoice_id' => $invoiceId, 'deleted' => 0])->resultID->num_rows;
            if ($savedCount !== count($items)) {
                throw new RuntimeException('La venta quedó incompleta durante la validación de partidas.');
            }

            if (! $db->transStatus()) {
                throw new RuntimeException('La transacción de creación de venta no pudo completarse.');
            }
            if ($manageTransaction) {
                $db->transCommit();
            }
            return $invoiceId;
        } catch (\Throwable $e) {
            if ($manageTransaction) {
                $db->transRollback();
                $db->resetTransStatus();
            }
            log_message('error', 'Complete invoice creation failed: {exception}', ['exception' => $e]);
            throw $e;
        }
    }
}
