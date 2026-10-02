<?php
declare(strict_types=1);

namespace App\Services\Sales;

use App\Services\FinancialMoney;
use App\Services\PaymentAllocationService;
use RuntimeException;

/** Keeps the legacy invoice payment label aligned with canonical allocations. */
final class SalePaymentStatusService
{
    public function __construct(private mixed $db = null)
    {
        $this->db ??= db_connect();
    }

    /** @return array{status:string,total:string,paid:string,balance:string} */
    public function synchronize(int $saleId): array
    {
        $sale = $this->db->table('invoices')->where(['id' => $saleId, 'deleted' => 0])->get(1)->getRow();
        if (! $sale) throw new RuntimeException('SALE_NOT_FOUND');
        if (in_array((string) $sale->status, ['cancelled', 'credited'], true)) {
            return ['status'=>(string)$sale->status,'total'=>(string)$sale->invoice_total,'paid'=>'0.000000','balance'=>(string)$sale->invoice_total];
        }
        $allocations = new PaymentAllocationService($this->db);
        $total = FinancialMoney::fromDatabase($sale->invoice_total);
        $paid = $allocations->salePaid($saleId);
        $balance = FinancialMoney::subtract($total, $paid);
        if (bccomp($balance, '0.000000', 6) <= 0) {
            $status = 'paid';
            $balance = '0.000000';
        } elseif (bccomp($paid, '0.000000', 6) > 0) {
            $status = 'partially_paid';
        } else {
            $status = 'not_paid';
        }
        if ((string) $sale->status !== $status) {
            $this->db->table('invoices')->where('id', $saleId)->update(['status' => $status]);
        }
        return ['status'=>$status,'total'=>$total,'paid'=>$paid,'balance'=>$balance];
    }
}
