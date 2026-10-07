<?php
declare(strict_types=1);

namespace App\Services\Sales;

use App\Services\FinancialMoney;
use App\Services\Fiscal\CreditNoteBalanceService;
use App\Services\PaymentAllocationService;
use RuntimeException;

/** Keeps the legacy invoice payment label aligned with canonical allocations. */
final class SalePaymentStatusService
{
    public function __construct(private mixed $db = null)
    {
        $this->db ??= db_connect();
    }

    /** @return array{status:string,total:string,paid:string,credited:string,balance:string} */
    public function calculate(int $saleId): array
    {
        $sale = $this->db->table('invoices')->where(['id' => $saleId, 'deleted' => 0])->get(1)->getRow();
        if (! $sale) throw new RuntimeException('SALE_NOT_FOUND');
        if (in_array((string) $sale->status, ['cancelled', 'credited'], true)) {
            return ['status'=>(string)$sale->status,'total'=>(string)$sale->invoice_total,'paid'=>'0.000000','credited'=>'0.000000','balance'=>(string)$sale->invoice_total];
        }
        $allocations = new PaymentAllocationService($this->db);
        $total = FinancialMoney::fromDatabase($sale->invoice_total);
        $paid = $allocations->salePaid($saleId);
        $credited = (new CreditNoteBalanceService($this->db))->creditedSaleAmount($saleId);
        $settled = FinancialMoney::add($paid, $credited);
        $balance = FinancialMoney::subtract($total, $settled);
        if (bccomp($balance, '0.000000', 6) <= 0) {
            $status = 'paid';
            $balance = '0.000000';
        } elseif (bccomp($settled, '0.000000', 6) > 0) {
            $status = 'partially_paid';
        } elseif (! in_array((string)($sale->commercial_status ?? ''), ['closed'], true)) {
            $status = 'draft';
        } else {
            $status = 'not_paid';
        }
        return ['status'=>$status,'total'=>$total,'paid'=>$paid,'credited'=>$credited,'balance'=>$balance];
    }

    /** @return array{status:string,total:string,paid:string,credited:string,balance:string} */
    public function synchronize(int $saleId): array
    {
        $result = $this->calculate($saleId);
        $sale = $this->db->table('invoices')->select('status')->where(['id'=>$saleId,'deleted'=>0])->get(1)->getRow();
        if (! $sale) throw new RuntimeException('SALE_NOT_FOUND');
        $status = $result['status'];
        if ((string) $sale->status !== $status) {
            $this->db->table('invoices')->where('id', $saleId)->update(['status' => $status]);
        }
        return $result;
    }
}
