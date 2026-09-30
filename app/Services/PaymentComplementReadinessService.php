<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\Fiscal\FiscalDecimal;
use App\Services\Fiscal\FiscalIssueDatePolicy;

final class PaymentComplementReadinessService
{
    public function __construct(private mixed $db)
    {
    }

    public function check(int $id): array
    {
        $checks=[];$blockers=[];$warnings=[];
        $add=static function(string$section,string$label,bool$ok,string$detail,string$resolution='',bool$warning=false)use(&$checks,&$blockers,&$warnings):void{$row=['section'=>$section,'label'=>$label,'severity'=>$ok?'correct':($warning?'warning':'error'),'detail'=>$detail,'resolution'=>$resolution];$checks[]=$row;if(!$ok){if($warning)$warnings[]=$detail;else$blockers[]=$detail;}};
        $complement=$this->db->table('payment_complements c')->select('c.*,cl.company_name,cl.vat_number')->join('clients cl','cl.id=c.client_id','left')->where(['c.id'=>$id,'c.deleted'=>0])->get(1)->getRow();
        $add('general','Cliente / receptor',(bool)($complement&&$complement->client_id&&!empty($complement->company_name)),$complement?'Cliente: '.($complement->company_name?:'#'.$complement->client_id):'Borrador inexistente.','Seleccione un cliente valido.');
        $add('general','RFC receptor',(bool)($complement&&trim((string)$complement->vat_number)!==''),$complement&&$complement->vat_number?'RFC: '.$complement->vat_number:'El cliente no tiene RFC.','Complete el RFC fiscal.',true);
        $validDate=false;$dateError='Fecha fiscal invalida.';if($complement)try{(new FiscalIssueDatePolicy())->validate((string)$complement->issue_date);$validDate=true;}catch(\Throwable$e){$dateError=$e->getMessage();}
        $add('general','Fecha del complemento',$validDate,$validDate?'Fecha fiscal dentro de la ventana permitida.':$dateError,'Ajuste la fecha del complemento.');

        $payment=$this->db->table('payment_complement_payments p')->select('p.*,ip.status source_status,ip.deleted source_deleted,ip.amount source_amount,ip.payment_method_id,fm.sat_payment_form_code mapped_payment_form')
            ->join('invoice_payments ip','ip.id=p.source_invoice_payment_id','left')->join('fiscal_payment_method_mappings fm','fm.payment_method_id=ip.payment_method_id AND fm.is_active=1','left')
            ->where(['p.payment_complement_id'=>$id,'p.deleted'=>0])->get(1)->getRow();
        $form=$payment?($payment->payment_form_code?:$payment->mapped_payment_form):null;
        $catalog=$form?$this->db->table('sat_payment_forms')->where(['code'=>$form,'is_active'=>1])->get(1)->getRow():null;
        $add('general','Pago administrativo',(bool)($payment&&$payment->source_invoice_payment_id&&$payment->source_status==='active'&&!(int)$payment->source_deleted),$payment?'Pago #'.$payment->source_invoice_payment_id:'No existe pago origen.','Seleccione un pago administrativo activo.');
        $add('general','FormaDePagoP',(bool)$catalog,$catalog?$form.' activo':'FormaDePagoP inexistente o inactiva.','Seleccione una clave SAT activa.');
        if($payment)$add('general','Monto del pago',FiscalDecimal::micros((string)$payment->amount)>0&&FiscalDecimal::micros((string)$payment->amount)<=FiscalDecimal::micros((string)$payment->source_amount),'Monto fiscal '.$payment->amount,'Los documentos no pueden exceder el pago administrativo.');

        $documents=[];
        if($payment)try{$documents=(new PaymentComplementRelatedDocumentNormalizer($this->db))->forPayment($id,(int)$payment->id);}catch(\Throwable$e){$blockers[]=$e->getMessage();$checks[]=['section'=>'documents','label'=>'Normalizacion','severity'=>'error','detail'=>$e->getMessage(),'resolution'=>'Revise documentos e impuestos DR.'];}
        $internal=count(array_filter($documents,static fn(array$d):bool=>$d['source_type']==='internal'));
        $external=count($documents)-$internal;
        $add('documents','Documentos relacionados',(bool)$documents,$documents?count($documents).' CFDI: '.$internal.' interno(s), '.$external.' externo(s).':'No hay facturas agregadas.','Agregue al menos un CFDI interno o externo.');
        foreach($documents as$document){$numbers=$document['NumParcialidad']>=1&&FiscalDecimal::micros($document['ImpSaldoAnt'])>=0&&FiscalDecimal::micros($document['ImpPagado'])>0&&FiscalDecimal::micros($document['ImpSaldoInsoluto'])>=0&&FiscalDecimal::subtract($document['ImpSaldoAnt'],$document['ImpPagado'])===FiscalDecimal::format(FiscalDecimal::micros($document['ImpSaldoInsoluto']));$add('documents',$document['source_type'].' '.$document['IdDocumento'],$numbers,$numbers?'Parcialidad y saldos consistentes.':'Parcialidad o saldos inconsistentes.','Corrija el documento antes del snapshot.');$taxCount=count($document['ImpuestosDR']['TrasladosDR'])+count($document['ImpuestosDR']['RetencionesDR']);$taxOk=$document['ObjetoImpDR']==='02'?$taxCount>0:$taxCount===0;$add('taxes','ObjetoImpDR '.$document['IdDocumento'],$taxOk,$taxOk?'Impuestos DR persistidos y coherentes.':'ObjetoImpDR e impuestos DR no coinciden.','Corrija impuestos DR sin inferirlos de totales.');}

        $status=$blockers===[]&&$documents?'complete_draft':(string)($complement->status??'draft');
        return['ready'=>$blockers===[],'status'=>$status,'checks'=>$checks,'blockers'=>array_values(array_unique($blockers)),'warnings'=>array_values(array_unique($warnings))];
    }
}
