<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
use RuntimeException;
use Throwable;
final class FiscalSaleCancellationPolicy
{
    public function __construct(private mixed $db=null,private ?FiscalSaleAllocationService$allocations=null){$this->db??=db_connect();$this->allocations??=new FiscalSaleAllocationService($this->db);}
    public function assertCanCancel(int$saleId):void
    {
        $summary=$this->allocations->getSaleFiscalSummary($saleId);
        if($summary['active_documents'])throw new RuntimeException('FISCAL_SALE_HAS_ACTIVE_DOCUMENTS');
        if($summary['active_drafts'])throw new RuntimeException('FISCAL_SALE_HAS_ACTIVE_DRAFTS');
        $activePayments=$this->db->table('payment_allocations a')
            ->join('invoice_payments p','p.id=a.invoice_payment_id')
            ->where([
                'a.invoice_id'=>$saleId,
                'a.status'=>'active',
                'a.deleted'=>0,
                'p.status'=>'active',
                'p.deleted'=>0,
            ])->countAllResults();
        if($activePayments>0)throw new RuntimeException('SALE_HAS_ACTIVE_PAYMENT_ALLOCATIONS');
    }

    /** The caller owns the transaction and must hold the invoice row lock. */
    public function cancelWithinTransaction(int$saleId,int$userId,string$reason):void
    {
        if(trim($reason)==='')throw new RuntimeException('FISCAL_SALE_CANCELLATION_REASON_REQUIRED');
        $this->assertCanCancel($saleId);
        $updated=$this->db->table('invoices')->where(['id'=>$saleId,'deleted'=>0])->update([
            'status'=>'cancelled','cancelled_at'=>get_current_utc_time(),'cancelled_by'=>$userId,
            'cancellation_reason'=>mb_substr(trim($reason),0,500),'deleted'=>0,
        ]);
        if(!$updated)throw new RuntimeException('FISCAL_SALE_NOT_FOUND');
    }

    /** Backwards-compatible standalone boundary for callers that do not own a transaction. */
    public function cancel(int$saleId,int$userId,string$reason):void
    {
        $this->db->transBegin();
        try{
            $sale=$this->db->query('SELECT status FROM '.$this->db->prefixTable('invoices').' WHERE id=? AND deleted=0 FOR UPDATE',[$saleId])->getRow();
            if(!$sale)throw new RuntimeException('FISCAL_SALE_NOT_FOUND');
            if((string)$sale->status==='cancelled'){$this->db->transCommit();return;}
            $this->cancelWithinTransaction($saleId,$userId,$reason);
            if(!$this->db->transStatus())throw new RuntimeException('FISCAL_SALE_CANCELLATION_FAILED');
            $this->db->transCommit();
        }catch(Throwable$e){$this->db->transRollback();throw$e;}
    }
}
