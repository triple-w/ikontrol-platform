<?php
declare(strict_types=1);
namespace App\Services\Quotes;
use App\Services\EstimateToInvoiceService;
use RuntimeException;
use Throwable;
final class QuotationLifecycleService
{
 private const FINAL=['rejected','expired','converted','cancelled'];
 public function __construct(private mixed$db=null){$this->db??=db_connect();}
 public function canEdit(int$id,int$userId,bool$sentOverride=false):array{$q=$this->quote($id);$a=$q->status==='draft'||($q->status==='sent'&&$sentOverride);return$this->d($a,$a?'OK':'QUOTATION_NOT_EDITABLE',$a?'Edición permitida.':'La cotización está cerrada para edición.');}
 public function markSent(int$id,int$u):void{$this->move($id,$u,['draft'],'sent','quotation_sent');}
 public function accept(int$id,int$u):void{$this->move($id,$u,['sent'],'accepted','quotation_accepted');}
 public function reject(int$id,int$u,string$r=''):void{$this->move($id,$u,['sent'],'rejected','quotation_rejected',$r);}
 public function expire(int$id,int$u):void{$this->move($id,$u,['draft','sent'],'expired','quotation_expired');}
 public function cancel(int$id,int$u,string$r):void{if(trim($r)==='')throw new RuntimeException('QUOTATION_CANCELLATION_REASON_REQUIRED');$this->move($id,$u,['draft','sent','accepted'],'cancelled','quotation_cancelled',$r,['cancelled_at'=>get_current_utc_time(),'cancelled_by'=>$u,'cancellation_reason'=>mb_substr(trim($r),0,500)]);}
 public function convertToSale(int$id,int$u):int{$this->db->transBegin();try{$q=$this->db->query('SELECT * FROM '.$this->db->prefixTable('estimates').' WHERE id=? AND deleted=0 FOR UPDATE',[$id])->getRow();if(!$q||$q->status!=='accepted'||$q->converted_sale_id)throw new RuntimeException('QUOTATION_NOT_CONVERTIBLE');if($this->db->table('invoices')->where(['estimate_id'=>$id,'deleted'=>0])->countAllResults())throw new RuntimeException('QUOTATION_ALREADY_CONVERTED');$bill=(new \DateTimeImmutable('now',new \DateTimeZone('America/Mexico_City')))->format('Y-m-d');$sale=(new EstimateToInvoiceService())->createFromEstimate($q,$u,'not_paid',['commercial_status'=>'open','bill_date'=>$bill,'due_date'=>$bill]);$now=get_current_utc_time();$this->db->table('estimates')->where('id',$id)->update(['status'=>'converted','converted_sale_id'=>$sale,'converted_at'=>$now,'converted_by'=>$u]);$this->audit($id,$u,'quotation_converted','accepted','converted',null);$this->db->transCommit();return$sale;}catch(Throwable$e){$this->db->transRollback();throw$e;}}
 private function move(int$id,int$u,array$from,string$to,string$event,string$r='',array$extra=[]):void{$q=$this->quote($id);if(!in_array($q->status,$from,true)||in_array($q->status,self::FINAL,true))throw new RuntimeException('QUOTATION_TRANSITION_NOT_ALLOWED');$this->db->table('estimates')->where('id',$id)->update(['status'=>$to]+$extra);$this->audit($id,$u,$event,$q->status,$to,$r);}
 private function quote(int$id):object{$q=$this->db->table('estimates')->where(['id'=>$id,'deleted'=>0])->get(1)->getRow();if(!$q)throw new RuntimeException('QUOTATION_NOT_FOUND');return$q;}
 private function audit(int$id,int$u,string$e,?string$o,?string$n,?string$r):void{$this->db->table('commercial_lifecycle_audit')->insert(['entity_type'=>'quotation','entity_id'=>$id,'event'=>$e,'old_status'=>$o,'new_status'=>$n,'reason'=>mb_substr(trim((string)$r),0,500),'user_id'=>$u,'created_at'=>get_current_utc_time()]);}
 private function d(bool$a,string$c,string$m,array$b=[]):array{return['allowed'=>$a,'code'=>$c,'message'=>$m,'blockers'=>$b];}
}
