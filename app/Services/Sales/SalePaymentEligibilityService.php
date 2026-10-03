<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Services\FinancialMoney;
use App\Services\Fiscal\CreditNoteBalanceService;
use App\Services\PaymentAllocationService;

/** Canonical policy for deciding whether an administrative sale may receive money. */
final class SalePaymentEligibilityService
{
    public function __construct(private mixed $db = null)
    {
        $this->db ??= db_connect();
    }

    /** @return array{allowed:bool,code:string,message:string,total:string,paid:string,credited:string,balance:string} */
    public function evaluate(int $saleId): array
    {
        $sale = $this->db->table('invoices')->where('id', $saleId)->get(1)->getRow();
        if (! $sale || (int) ($sale->deleted ?? 0) !== 0) {
            return $this->decision(false, 'SALE_NOT_FOUND', 'La venta no existe o fue eliminada.');
        }
        if ((string) ($sale->status ?? '') === 'cancelled'
            || (string) ($sale->commercial_status ?? '') === 'cancelled') {
            return $this->decision(false, 'SALE_CANCELLED', 'La venta cancelada no admite pagos.');
        }
        if ((string) ($sale->status ?? '') === 'credited') {
            return $this->decision(false, 'SALE_CREDITED', 'La venta acreditada no admite pagos adicionales.');
        }

        $total = FinancialMoney::fromDatabase($sale->invoice_total ?? '0');
        $paid = (new PaymentAllocationService($this->db))->salePaid($saleId);
        $credited = (new CreditNoteBalanceService($this->db))->creditedSaleAmount($saleId);
        $balance = FinancialMoney::subtract(FinancialMoney::subtract($total, $paid), $credited);
        if (bccomp($balance, '0.000000', 6) <= 0) {
            return $this->decision(false, 'SALE_BALANCE_SETTLED', 'La venta no tiene saldo pendiente.', $total, $paid, $credited, '0.000000');
        }

        return $this->decision(true, 'SALE_PAYMENT_ELIGIBLE', '', $total, $paid, $credited, $balance);
    }

    private function decision(
        bool $allowed,
        string $code,
        string $message,
        string $total = '0.000000',
        string $paid = '0.000000',
        string $credited = '0.000000',
        string $balance = '0.000000'
    ): array {
        return compact('allowed', 'code', 'message', 'total', 'paid', 'credited', 'balance');
    }
}
