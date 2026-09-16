<?php
declare(strict_types=1);
namespace App\Services\Sales;
use App\Services\Fiscal\FiscalDecimal;
use App\Services\Fiscal\FiscalSaleCancellationPolicy;
use RuntimeException;
use Throwable;
final class SaleLifecycleService
{
 public function __construct(private mixed$db=null){$this->db??=db_connect();}
 public function canEdit(int$id,int$userId,bool$structural=true):array{$s=$this->sale($id);if($this->hasStampedCfdi($id))return$this->d(false,'SALE_HAS_STAMPED_CFDI','Esta venta ya tiene un CFDI timbrado y no puede modificarse.');$a=in_array((string)$s->commercial_status,['draft','open'],true);return$this->d($a,$a?'OK':'SALE_NOT_EDITABLE',$a?'Edicion permitida.':'La venta ya no admite cambios comerciales.');}
 public function canClose(int$id,int$userId):array{$s=$this->sale($id);$b=[];if(!in_array((string)$s->commercial_status,['draft','open'],true))$b[]='La venta no está abierta.';if(!$this->db->table('invoice_items')->where(['invoice_id'=>$id,'deleted'=>0])->countAllResults())$b[]='La venta no contiene partidas.';$total=$s->invoice_total===null?0:FiscalDecimal::micros((string)$s->invoice_total);if($total<=0)$b[]='El total debe ser mayor que cero.';if(!(int)$s->client_id)$b[]='La venta no tiene cliente válido.';return$this->d(!$b,$b?'SALE_CLOSE_BLOCKED':'OK',$b?'No se puede cerrar la venta.':'La venta puede cerrarse.',$b);}
 public function close(int$id,int$u,?string$r):void{$d=$this->canClose($id,$u);if(!$d['allowed'])throw new RuntimeException($d['code'].': '.implode(' ',$d['blockers']));$this->db->transBegin();try{$s=$this->db->query('SELECT commercial_status FROM '.$this->db->prefixTable('invoices').' WHERE id=? FOR UPDATE',[$id])->getRow();$this->db->table('invoices')->where('id',$id)->update(['commercial_status'=>'closed','closed_at'=>get_current_utc_time(),'closed_by'=>$u,'closure_reason'=>mb_substr(trim((string)$r),0,500)]);$this->audit($id,$u,'sale_closed',$s->commercial_status,'closed',$r);$this->db->transCommit();}catch(Throwable$e){$this->db->transRollback();throw$e;}}
 public function canCancel(int$id,int$u):array{try{$s=$this->sale($id);if($s->commercial_status==='cancelled')return$this->d(false,'SALE_ALREADY_CANCELLED','La venta ya está cancelada.');(new FiscalSaleCancellationPolicy($this->db))->assertCanCancel($id);return$this->d(true,'OK','La venta puede cancelarse.');}catch(Throwable$e){return$this->d(false,$e->getMessage(),'La venta tiene operaciones fiscales que impiden cancelarla.',[$e->getMessage()]);}}
 public function cancel(int$id,int$u,string$r):void{if(trim($r)==='')throw new RuntimeException('SALE_CANCELLATION_REASON_REQUIRED');$d=$this->canCancel($id,$u);if(!$d['allowed'])throw new RuntimeException($d['code']);(new FiscalSaleCancellationPolicy($this->db))->cancel($id,$u,$r);$this->db->table('invoices')->where('id',$id)->update(['commercial_status'=>'cancelled']);$this->audit($id,$u,'sale_cancelled',null,'cancelled',$r);}
 private function hasStampedCfdi(int$id):bool{
  if($this->db->table('fiscal_documents d')->join('fiscal_document_stamps s','s.fiscal_document_id=d.id')->where(['d.invoice_id'=>$id,'d.deleted'=>0])->where('s.uuid !=','')->countAllResults())return true;
  if($this->db->table('fiscal_document_sales a')->join('fiscal_document_stamps s','s.fiscal_document_id=a.fiscal_document_id')->where('a.sale_id',$id)->where('s.uuid !=','')->countAllResults())return true;
  return(bool)$this->db->table('fiscal_draft_sales a')->join('fiscal_drafts d','d.id=a.fiscal_draft_id')->join('fiscal_document_stamps s','s.fiscal_document_id=d.fiscal_document_id')->where('a.sale_id',$id)->where('s.uuid !=','')->countAllResults();
 } private function paid(int$id):int{$x=$this->db->table('invoice_payments')->selectSum('amount','paid')->where(['invoice_id'=>$id,'deleted'=>0])->get()->getRow();return FiscalDecimal::micros((string)($x->paid??'0'));}
 private function sale(int$id):object{$s=$this->db->table('invoices')->where(['id'=>$id,'deleted'=>0])->get(1)->getRow();if(!$s)throw new RuntimeException('SALE_NOT_FOUND');return$s;}
 private function audit(int$id,int$u,string$e,?string$o,?string$n,?string$r):void{$this->db->table('commercial_lifecycle_audit')->insert(['entity_type'=>'sale','entity_id'=>$id,'event'=>$e,'old_status'=>$o,'new_status'=>$n,'reason'=>mb_substr(trim((string)$r),0,500),'user_id'=>$u,'created_at'=>get_current_utc_time()]);}
 private function d(bool$a,string$c,string$m,array$b=[]):array{return['allowed'=>$a,'code'=>$c,'message'=>$m,'blockers'=>$b];}
}
