<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

final class CfdiPaymentRuleService
{
    private $db;
    private FiscalDecimalCalculator $decimal;

    public function __construct($db = null)
    {
        $this->db = $db;
        $this->decimal = new FiscalDecimalCalculator();
    }

    public function validate(string $paymentMethodCode, string $paymentFormCode): void
    {
        $method = strtoupper(trim($paymentMethodCode));
        $form = trim($paymentFormCode);
        if ($method === 'PPD' && $form !== '99') {
            throw new RuntimeException('Cuando el método de pago es PPD, la forma de pago debe ser 99 - Por definir.');
        }
        if ($method === 'PUE' && ($form === '' || $form === '99')) {
            throw new RuntimeException('Cuando el método de pago es PUE, selecciona la forma en que se recibió el pago.');
        }
    }

    public function suggest(int $invoiceId): array
    {
        $this->db = $this->db ?: db_connect();
        $invoice = $this->db->table('invoices')->where(['id' => $invoiceId, 'deleted' => 0])->get(1)->getRow();
        if (!$invoice) {
            throw new RuntimeException('La venta no existe.');
        }
        $paid = $this->db->table('invoice_payments')->selectSum('amount', 'total')
            ->where(['invoice_id' => $invoiceId, 'deleted' => 0])->get()->getRow();
        $total = $this->decimal->money((string) $invoice->invoice_total);
        $paymentTotal = $this->decimal->money((string) ($paid->total ?? '0'));
        if ($this->decimal->compare($paymentTotal, $total) < 0) {
            return [
                'payment_method_code' => 'PPD',
                'payment_form_code' => '99',
                'source' => 'unpaid_or_partial',
                'warning' => null,
            ];
        }
        $lastPayment = $this->db->table('invoice_payments')
            ->where(['invoice_id' => $invoiceId, 'deleted' => 0])
            ->orderBy('payment_date', 'DESC')->orderBy('id', 'DESC')->get(1)->getRow();
        $mapping = $lastPayment ? $this->db->table('fiscal_payment_method_mappings')
            ->where(['payment_method_id' => $lastPayment->payment_method_id, 'is_active' => 1])->get(1)->getRow() : null;
        return [
            'payment_method_code' => 'PUE',
            'payment_form_code' => $mapping->sat_payment_form_code ?? null,
            'source' => $mapping ? 'explicit_mapping' : 'paid_without_mapping',
            'warning' => $mapping ? null : 'La venta está pagada, pero su forma administrativa no tiene un mapeo SAT explícito.',
        ];
    }
}
