<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
use RuntimeException;
use Throwable;
final class FiscalSaleCancellationPolicy
{
    public function __construct(private mixed $db=null,private ?FiscalSaleAllocationService$allocations=null){$this->db??=db_connect();$this->allocations??=new FiscalSaleAllocationService($this->db);}
    public function assertCanCancel(int$saleId):void{$summary=$this->allocations->getSaleFiscalSummary($saleId);if($summary['active_documents'])throw new RuntimeException('FISCAL_SALE_HAS_ACTIVE_DOCUMENTS');if($summary['active_drafts'])throw new RuntimeException('FISCAL_SALE_HAS_ACTIVE_DRAFTS');}
    public function cancel(int$saleId,int$userId,string$reason):void{if(trim($reason)==='')throw new RuntimeException('FISCAL_SALE_CANCELLATION_REASON_REQUIRED');$this->db->transBegin();try{$this->db->query('SELECT id FROM '.$this->db->prefixTable('invoices').' WHERE id=? FOR UPDATE',[$saleId]);$this->assertCanCancel($saleId);$this->db->table('invoices')->where(['id'=>$saleId,'deleted'=>0])->update(['status'=>'cancelled','cancelled_at'=>get_current_utc_time(),'cancelled_by'=>$userId,'cancellation_reason'=>mb_substr(trim($reason),0,500),'deleted'=>0]);$this->db->transCommit();}catch(Throwable$e){$this->db->transRollback();throw$e;}}
}
