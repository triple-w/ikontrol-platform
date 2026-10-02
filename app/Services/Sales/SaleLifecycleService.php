<?php
declare(strict_types=1);

namespace App\Services\Sales;

use App\Services\Fiscal\FiscalDecimal;
use App\Services\Fiscal\FiscalSaleCancellationPolicy;
use RuntimeException;
use Throwable;

final class SaleLifecycleService
{
    public function __construct(private mixed $db = null) { $this->db ??= db_connect(); }

    public function canEdit(int $id, int $userId, bool $structural = true): array
    {
        $sale = $this->sale($id);
        if ((string)$sale->status === 'cancelled' || (string)$sale->commercial_status === 'cancelled') return $this->decision(false,'SALE_CANCELLED','La venta cancelada no puede modificarse.');
        if ($this->hasStampedCfdi($id)) return $this->decision(false,'SALE_HAS_STAMPED_CFDI','Esta venta ya tiene un CFDI timbrado y no puede modificarse.');
        // A legacy RISE status named "closed" is not closure evidence by itself.
        $explicitlyClosed = (string)$sale->commercial_status === 'closed'
            && (trim((string)($sale->closed_at ?? '')) !== '' || (int)($sale->closed_by ?? 0) > 0 || trim((string)($sale->closure_reason ?? '')) !== '');
        if ($explicitlyClosed) return $this->decision(false,'SALE_CLOSED','La venta ya no admite cambios comerciales.');
        return $this->decision(true,'OK','Edición permitida.');
    }

    public function canClose(int $id, int $userId): array
    {
        $sale=$this->sale($id);$blockers=[];
        if (!in_array((string)$sale->commercial_status,['draft','open'],true)) $blockers[]='La venta no está abierta.';
        if (!$this->db->table('invoice_items')->where(['invoice_id'=>$id,'deleted'=>0])->countAllResults()) $blockers[]='La venta no contiene partidas.';
        if (($sale->invoice_total===null?0:FiscalDecimal::micros((string)$sale->invoice_total))<=0) $blockers[]='El total debe ser mayor que cero.';
        if (!(int)$sale->client_id) $blockers[]='La venta no tiene cliente válido.';
        return $this->decision(!$blockers,$blockers?'SALE_CLOSE_BLOCKED':'OK',$blockers?'No se puede cerrar la venta.':'La venta puede cerrarse.',$blockers);
    }

    public function close(int $id,int $userId,?string $reason): void
    {
        $decision=$this->canClose($id,$userId);if(!$decision['allowed'])throw new RuntimeException($decision['code'].': '.implode(' ',$decision['blockers']));
        $this->db->transBegin();try{$sale=$this->db->query('SELECT commercial_status FROM '.$this->db->prefixTable('invoices').' WHERE id=? FOR UPDATE',[$id])->getRow();$this->db->table('invoices')->where('id',$id)->update(['commercial_status'=>'closed','closed_at'=>get_current_utc_time(),'closed_by'=>$userId,'closure_reason'=>mb_substr(trim((string)$reason),0,500)]);$this->audit($id,$userId,'sale_closed',$sale->commercial_status,'closed',$reason);$this->db->transCommit();}catch(Throwable$e){$this->db->transRollback();throw$e;}
    }

    /** Idempotent local transition after persisted CFDI evidence exists. */
    public function finalizeFiscalIssuance(int $id,int $userId): array
    {
        if(!$this->hasStampedCfdi($id))throw new RuntimeException('SALE_HAS_NO_STAMPED_CFDI');
        $this->db->transBegin();try{$sale=$this->db->DBDriver==='SQLite3'?$this->db->table('invoices')->where('id',$id)->get(1)->getRow():$this->db->query('SELECT * FROM '.$this->db->prefixTable('invoices').' WHERE id=? FOR UPDATE',[$id])->getRow();if(!$sale||(int)$sale->deleted)throw new RuntimeException('SALE_NOT_FOUND');if((string)$sale->status==='cancelled'||(string)$sale->commercial_status==='cancelled')throw new RuntimeException('SALE_CANCELLED');$alreadyCanonical=(string)$sale->commercial_status==='closed'&&trim((string)($sale->closed_at??''))!=='';if(!$alreadyCanonical){$this->db->table('invoices')->where('id',$id)->update(['commercial_status'=>'closed','closed_at'=>get_current_utc_time(),'closed_by'=>$userId,'closure_reason'=>'Cierre automático posterior a timbrado CFDI']);$this->audit($id,$userId,'sale_closed_after_stamp',(string)$sale->commercial_status,'closed','CFDI timbrado');}$payment=(new SalePaymentStatusService($this->db))->synchronize($id);$this->db->transCommit();return['closed'=>true,'payment_status'=>$payment['status'],'balance'=>$payment['balance']];}catch(Throwable$e){$this->db->transRollback();throw$e;}
    }

    public function canCancel(int$id,int$userId):array{try{$sale=$this->sale($id);if($sale->commercial_status==='cancelled')return$this->decision(false,'SALE_ALREADY_CANCELLED','La venta ya está cancelada.');(new FiscalSaleCancellationPolicy($this->db))->assertCanCancel($id);return$this->decision(true,'OK','La venta puede cancelarse.');}catch(Throwable$e){return$this->decision(false,$e->getMessage(),'La venta tiene operaciones fiscales que impiden cancelarla.',[$e->getMessage()]);}}
    public function cancel(int$id,int$userId,string$reason):void{if(trim($reason)==='')throw new RuntimeException('SALE_CANCELLATION_REASON_REQUIRED');$decision=$this->canCancel($id,$userId);if(!$decision['allowed'])throw new RuntimeException($decision['code']);(new FiscalSaleCancellationPolicy($this->db))->cancel($id,$userId,$reason);$this->db->table('invoices')->where('id',$id)->update(['commercial_status'=>'cancelled']);$this->audit($id,$userId,'sale_cancelled',null,'cancelled',$reason);}
    private function hasStampedCfdi(int$id):bool{if($this->db->table('fiscal_documents d')->join('fiscal_document_stamps s','s.fiscal_document_id=d.id')->where(['d.invoice_id'=>$id,'d.deleted'=>0])->where('s.uuid !=','')->countAllResults())return true;if($this->db->table('fiscal_document_sales a')->join('fiscal_document_stamps s','s.fiscal_document_id=a.fiscal_document_id')->where('a.sale_id',$id)->where('s.uuid !=','')->countAllResults())return true;return(bool)$this->db->table('fiscal_draft_sales a')->join('fiscal_drafts d','d.id=a.fiscal_draft_id')->join('fiscal_document_stamps s','s.fiscal_document_id=d.fiscal_document_id')->where('a.sale_id',$id)->where('s.uuid !=','')->countAllResults();}
    private function sale(int$id):object{$sale=$this->db->table('invoices')->where(['id'=>$id,'deleted'=>0])->get(1)->getRow();if(!$sale)throw new RuntimeException('SALE_NOT_FOUND');return$sale;}
    private function audit(int$id,int$userId,string$event,?string$old,?string$new,?string$reason):void{$this->db->table('commercial_lifecycle_audit')->insert(['entity_type'=>'sale','entity_id'=>$id,'event'=>$event,'old_status'=>$old,'new_status'=>$new,'reason'=>mb_substr(trim((string)$reason),0,500),'user_id'=>$userId,'created_at'=>get_current_utc_time()]);}
    private function decision(bool$allowed,string$code,string$message,array$blockers=[]):array{return['allowed'=>$allowed,'code'=>$code,'message'=>$message,'blockers'=>$blockers];}
}
