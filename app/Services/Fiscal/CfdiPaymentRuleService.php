<?php
declare(strict_types=1);
namespace App\Services\Fiscal;

use RuntimeException;

final class CfdiPaymentRuleService
{
    private mixed $db;
    private FiscalDecimalCalculator $decimal;

    public function __construct($db=null){$this->db=$db;$this->decimal=new FiscalDecimalCalculator();}

    public function validate(string$paymentMethodCode,string$paymentFormCode):void
    {
        $method=strtoupper(trim($paymentMethodCode));$form=trim($paymentFormCode);
        if(!in_array($method,['PUE','PPD'],true))throw new RuntimeException('El método de pago CFDI debe ser PUE o PPD.');
        if($method==='PPD'&&$form!=='99')throw new RuntimeException('Cuando el método de pago es PPD, la forma de pago debe ser 99 - Por definir.');
        if($method==='PUE'&&($form===''||$form==='99'))throw new RuntimeException('Cuando el método de pago es PUE, selecciona la forma en que se recibió el pago.');
    }

    public function validateForInvoice(int$invoiceId,string$paymentMethodCode,string$paymentFormCode):array
    {
        $this->db=$this->db?:db_connect();$this->validate($paymentMethodCode,$paymentFormCode);
        $method=strtoupper(trim($paymentMethodCode));$form=trim($paymentFormCode);
        $catalog=$this->db->table('sat_payment_forms')->where(['code'=>$form,'is_active'=>1])->get(1)->getRow();
        if(!$catalog)throw new RuntimeException('La forma de pago SAT seleccionada no existe o está inactiva.');
        if($method==='PPD')return['ready'=>true,'requires_administrative_mapping'=>false,'payment_method_code'=>'PPD','payment_form_code'=>'99'];
        if($method!=='PUE')throw new RuntimeException('El método de pago CFDI no es válido para una factura de ingreso.');
        $invoice=$this->db->table('invoices')->where(['id'=>$invoiceId,'deleted'=>0])->get(1)->getRow();if(!$invoice)throw new RuntimeException('La venta no existe.');
        $payments=$this->db->table('invoice_payments')->where(['invoice_id'=>$invoiceId,'deleted'=>0,'status'=>'active'])->orderBy('payment_date')->orderBy('id')->get()->getResult();
        $paid='0';
        $paymentIds=[];
        foreach($payments as$payment){
            $paid=$this->decimal->add($paid,(string)$payment->amount);
            $mapping=(new FiscalPaymentMethodReadinessService($this->db))->forMethod((int)$payment->payment_method_id);
            if(!$mapping['ready'])throw new RuntimeException((string)$mapping['message']);
            if((string)$mapping['sat_payment_form_code']!==$form)throw new RuntimeException('La forma SAT seleccionada no coincide con todos los métodos administrativos de los pagos efectivos.');
            $paymentIds[]=(int)$payment->id;
        }
        if(!$paymentIds)throw new RuntimeException('PUE requiere un pago administrativo activo asociado a la factura.');
        if($this->decimal->compare($paid,(string)$invoice->invoice_total)<0)throw new RuntimeException('PUE requiere que el pago administrativo activo cubra el total de la factura.');
        return['ready'=>true,'requires_administrative_mapping'=>true,'payment_method_code'=>'PUE','payment_form_code'=>$form,'payment_method_id'=>$paymentIds[count($paymentIds)-1],'payment_ids'=>$paymentIds];
    }

    public function validateForAllocations(array$allocations,string$paymentMethodCode,string$paymentFormCode):array
    {
        $this->db=$this->db?:db_connect();
        $this->validate($paymentMethodCode,$paymentFormCode);
        $method=strtoupper(trim($paymentMethodCode));$form=trim($paymentFormCode);
        $catalog=$this->db->table('sat_payment_forms')->where(['code'=>$form,'is_active'=>1])->get(1)->getRow();
        if(!$catalog)throw new RuntimeException('La forma de pago SAT seleccionada no existe o está inactiva.');
        if($method==='PPD')return['ready'=>true,'requires_administrative_mapping'=>false,'payment_method_code'=>'PPD','payment_form_code'=>'99'];
        if(!$allocations)throw new RuntimeException('PUE requiere ventas y pagos administrativos asociados.');

        $requiredBySale=[];
        foreach($allocations as$allocation){
            $saleId=(int)($allocation['sale_id']??0);$amount=(string)($allocation['allocated_total']??'0');
            if($saleId<1||$this->decimal->compare($amount,'0')<=0)throw new RuntimeException('La asignación fiscal de la venta no es válida.');
            $requiredBySale[$saleId]=isset($requiredBySale[$saleId])?$this->decimal->add($requiredBySale[$saleId],$amount):$amount;
        }
        $rows=$this->db->table('payment_allocations pa')
            ->select('pa.invoice_id,pa.amount_applied,ip.id payment_id,ip.payment_method_id')
            ->join('invoice_payments ip','ip.id=pa.invoice_payment_id')
            ->whereIn('pa.invoice_id',array_keys($requiredBySale))
            ->where(['pa.status'=>'active','pa.deleted'=>0,'ip.status'=>'active','ip.deleted'=>0])
            ->get()->getResult();
        $paidBySale=[];$paymentIds=[];
        foreach($rows as$row){
            $saleId=(int)$row->invoice_id;
            $paidBySale[$saleId]=isset($paidBySale[$saleId])?$this->decimal->add($paidBySale[$saleId],(string)$row->amount_applied):(string)$row->amount_applied;
            $mapping=(new FiscalPaymentMethodReadinessService($this->db))->forMethod((int)$row->payment_method_id);
            if(!$mapping['ready'])throw new RuntimeException((string)$mapping['message']);
            if((string)$mapping['sat_payment_form_code']!==$form)throw new RuntimeException('La forma SAT seleccionada no coincide con todos los métodos administrativos de los pagos aplicados.');
            $paymentIds[(int)$row->payment_id]=(int)$row->payment_id;
        }
        foreach($requiredBySale as$saleId=>$required){
            if(!isset($paidBySale[$saleId])||$this->decimal->compare($paidBySale[$saleId],$required)<0)throw new RuntimeException('PUE requiere que los pagos administrativos aplicados cubran el importe fiscal de cada venta.');
        }
        if(!$paymentIds)throw new RuntimeException('PUE requiere pagos administrativos aplicados.');
        return['ready'=>true,'requires_administrative_mapping'=>true,'payment_method_code'=>'PUE','payment_form_code'=>$form,'payment_ids'=>array_values($paymentIds)];
    }

    public function suggest(int$invoiceId):array
    {
        $this->db=$this->db?:db_connect();
        $invoice=$this->db->table('invoices')->where(['id'=>$invoiceId,'deleted'=>0])->get(1)->getRow();
        if(!$invoice)throw new RuntimeException('La venta no existe.');
        $paid=$this->db->table('invoice_payments')->selectSum('amount','total')->where(['invoice_id'=>$invoiceId,'deleted'=>0,'status'=>'active'])->get()->getRow();
        $total=$this->decimal->money((string)$invoice->invoice_total);$paymentTotal=$this->decimal->money((string)($paid->total??'0'));
        if($this->decimal->compare($paymentTotal,$total)<0)return['payment_method_code'=>'PPD','payment_form_code'=>'99','source'=>'unpaid_or_partial','warning'=>null];

        $payments=$this->db->table('invoice_payments')->where(['invoice_id'=>$invoiceId,'deleted'=>0,'status'=>'active'])->orderBy('payment_date')->orderBy('id')->get()->getResult();
        $forms=[];$warnings=[];
        foreach($payments as$payment){
            $mapping=(new FiscalPaymentMethodReadinessService($this->db))->forMethod((int)$payment->payment_method_id);
            if(!$mapping['ready']){$warnings[]=$mapping['message'];continue;}
            $forms[(string)$mapping['sat_payment_form_code']]=true;
        }
        $mapped=count($forms)===1&&$warnings===[];
        $form=$mapped?(string)array_key_first($forms):null;
        return[
            'payment_method_code'=>'PUE',
            'payment_form_code'=>$mapped?$form:null,
            'source'=>$mapped?'explicit_mapping':'paid_without_mapping',
            'warning'=>$mapped?null:($warnings[0]??'Los pagos administrativos no comparten una única forma SAT válida.'),
        ];
    }
}
