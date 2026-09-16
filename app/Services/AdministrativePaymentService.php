<?php
namespace App\Services;

use RuntimeException;
use Throwable;

final class AdministrativePaymentService
{
    public function __construct(private $db=null){$this->db??=db_connect();}
    public function defaultAccountForMethod(int$id):int{$m=$this->db->table('payment_methods')->select('default_financial_account_id')->where(['id'=>$id,'deleted'=>0])->get(1)->getRow();return(int)($m->default_financial_account_id??0);}
    public function save(array$data,int$id=0):int
    {
        $accountId=(int)($data['destination_financial_account_id']??0);if(!$this->db->table('financial_accounts')->where(['id'=>$accountId,'deleted'=>0,'is_active'=>1,'currency'=>'MXN'])->countAllResults())throw new RuntimeException('Debe seleccionar una cuenta financiera MXN activa.');
        $invoiceId=(int)($data['invoice_id']??0);$clientId=(int)($data['client_id']??0);
        if($invoiceId){$invoice=$this->db->table('invoices')->select('client_id')->where(['id'=>$invoiceId,'deleted'=>0])->get(1)->getRow();if(!$invoice)throw new RuntimeException('La venta no existe.');if($clientId&&$clientId!==(int)$invoice->client_id)throw new RuntimeException('La venta pertenece a otro cliente.');$clientId=(int)$invoice->client_id;}
        if(!$clientId||!$this->db->table('clients')->where(['id'=>$clientId,'deleted'=>0])->countAllResults())throw new RuntimeException('Debe seleccionar un cliente válido.');
        $data['client_id']=$clientId;$data['invoice_id']=$invoiceId?:null;$data['amount']=FinancialMoney::positive($data['amount']??'0');$data['destination_financial_account_id']=$accountId;$data['status']='active';$data['deleted']=0;
        $this->db->transBegin();try{
            if($id){$current=$this->db->query('SELECT * FROM '.$this->db->prefixTable('invoice_payments').' WHERE id=? FOR UPDATE',[$id])->getRow();if(!$current||$current->status!=='active'||(int)$current->deleted)throw new RuntimeException('El pago no existe o está cancelado.');$applied=(new PaymentAllocationService($this->db))->paymentApplied($id);if(bccomp($data['amount'],$applied,6)<0)throw new RuntimeException('No puede reducir el pago por debajo del monto aplicado.');if((int)$current->client_id!==$clientId&&bccomp($applied,'0',6)>0)throw new RuntimeException('No puede cambiar el cliente de un pago con aplicaciones.');unset($data['created_at'],$data['created_by']);if(!$this->db->table('invoice_payments')->where('id',$id)->update($data))throw new RuntimeException('No fue posible actualizar el pago.');$paymentId=$id;}
            else{if(!$this->db->table('invoice_payments')->insert($data))throw new RuntimeException('No fue posible guardar el pago.');$paymentId=(int)$this->db->insertID();}
            (new FinancialAccountMovementService($this->db))->sync('invoice_payment',$paymentId,$accountId,'in',$data['amount'],substr((string)$data['payment_date'],0,10),isset($data['created_by'])?(int)$data['created_by']:null,(string)($data['reference']??$data['note']??''));
            if(!$id&&$invoiceId){$allocations=new PaymentAllocationService($this->db);$outstanding=$allocations->saleOutstanding($invoiceId);if(bccomp($outstanding,'0',6)<=0)throw new RuntimeException('La venta seleccionada no tiene saldo pendiente.');$automatic=bccomp($data['amount'],$outstanding,6)>0?$outstanding:$data['amount'];$allocations->createWithinTransaction($paymentId,$invoiceId,$automatic,isset($data['created_by'])?(int)$data['created_by']:null,substr((string)$data['payment_date'],0,10));}
            if(!$this->db->transStatus())throw new RuntimeException('No fue posible registrar el movimiento financiero del pago.');$this->db->transCommit();return$paymentId;
        }catch(Throwable$e){$this->db->transRollback();throw$e;}
    }
    public function cancel(int$id,int$actor,string$reason):void
    {
        $reason=trim($reason);if($reason==='')throw new RuntimeException('El motivo de cancelación es obligatorio.');$this->db->transBegin();try{$p=$this->db->query('SELECT * FROM '.$this->db->prefixTable('invoice_payments').' WHERE id=? FOR UPDATE',[$id])->getRow();if(!$p||$p->status!=='active'||(int)$p->deleted)throw new RuntimeException('El pago no existe o ya está cancelado.');if($this->db->table('payment_allocations')->where(['invoice_payment_id'=>$id,'deleted'=>0,'status'=>'active'])->countAllResults())throw new RuntimeException('El pago tiene aplicaciones activas. Retírelas antes de cancelarlo.');(new FinancialAccountMovementService($this->db))->reverseSource('invoice_payment',$id,$actor,$reason);$this->db->table('invoice_payments')->where('id',$id)->update(['status'=>'cancelled','deleted'=>1,'cancelled_at'=>get_current_utc_time(),'cancelled_by'=>$actor,'cancellation_reason'=>$reason]);if(!$this->db->transStatus())throw new RuntimeException('No fue posible cancelar el pago.');$this->db->transCommit();}catch(Throwable$e){$this->db->transRollback();throw$e;}
    }
}
