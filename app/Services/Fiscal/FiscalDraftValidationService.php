<?php
declare(strict_types=1);
namespace App\Services\Fiscal;

use Throwable;

final class FiscalDraftValidationService
{
    public function __construct(private mixed$db=null,private?FiscalIssueDatePolicy$dates=null){$this->db??=db_connect();$this->dates??=new FiscalIssueDatePolicy();}

    public function validate(array$draft,array$allocations,array$concepts,bool$forStamping=false):array
    {
        $errors=[];$warnings=[];
        $add=static function(array&$target,string$field,string$code,string$message,string$section):void{$target[]=compact('field','code','message','section');};
        $environment=FiscalRuntimeContext::fiscalEnvironment(config('Fiscal'));$issuerId=(int)($draft['issuer_id']??0);
        $issuer=$issuerId>0?(new FiscalIssuerResolver($this->db))->resolveById($issuerId,null,$environment):null;
        if(!$issuer)$add($errors,'issuer_id','ISSUER_REQUIRED','Selecciona un emisor fiscal activo.','issuer');
        if($issuer){$readiness=(new IssuerFiscalReadinessService($this->db))->evaluate((int)$issuer->id,isset($issuer->company_id)?(int)$issuer->company_id:null);foreach($readiness['errors']as$message)$add($errors,'issuer_id','ISSUER_INCOMPLETE',(string)$message,'issuer');$csd=(new FiscalCsdReadinessService($this->db))->inspect($issuer);if(!$csd['ready'])$add($errors,'certificate',(string)$csd['code'],(string)$csd['label'],'csd');}
        $receiver=$this->db->table('fiscal_profiles')->where(['id'=>(int)($draft['receiver_profile_id']??0),'profile_type'=>'receiver'])->whereIn('status',['active','ready'])->get(1)->getRow();
        if(!$receiver)$add($errors,'receiver_profile_id','RECEIVER_REQUIRED','Faltan los datos fiscales del receptor.','receiver');
        else foreach(['rfc'=>'RFC del receptor','legal_name'=>'razón social del receptor','tax_regime_id'=>'régimen fiscal del receptor','fiscal_postal_code'=>'código postal fiscal']as$field=>$label)if(trim((string)($receiver->{$field}??''))===''||(str_ends_with($field,'_id')&&(int)$receiver->{$field}<=0))$add($errors,$field,'RECEIVER_FIELD_REQUIRED','Falta '.$label.'.','receiver');
        foreach(['payment_form_code'=>'forma de pago','payment_method_code'=>'método de pago','currency_code'=>'moneda']as$field=>$label)if(trim((string)($draft[$field]??''))==='')$add($errors,$field,'COMPROBANTE_FIELD_REQUIRED','Falta '.$label.'.','document');
        $paymentForm=trim((string)($draft['payment_form_code']??''));if($paymentForm!==''&&!$this->db->table('sat_payment_forms')->where(['code'=>$paymentForm,'is_active'=>1])->countAllResults())$add($errors,'payment_form_code','SAT_PAYMENT_FORM_INVALID','La forma de pago SAT no existe o está inactiva.','document');
        $paymentMethod=trim((string)($draft['payment_method_code']??''));if($paymentMethod!==''&&!$this->db->table('sat_payment_methods')->where(['code'=>$paymentMethod,'is_active'=>1])->countAllResults())$add($errors,'payment_method_code','SAT_PAYMENT_METHOD_INVALID','El método de pago CFDI no existe o está inactivo.','document');
        if(trim((string)($draft['cfdi_use_code']??''))==='')$add($errors,'cfdi_use_code','CFDI_USE_REQUIRED','Falta Uso CFDI.','document');
        if(trim((string)($draft['payment_form_code']??''))!==''&&trim((string)($draft['payment_method_code']??''))!=='')try{(new CfdiPaymentRuleService($this->db))->validate((string)$draft['payment_method_code'],(string)$draft['payment_form_code']);}catch(Throwable$e){$add($errors,'payment','CFDI_PAYMENT_COMBINATION_INVALID',$e->getMessage(),'document');}
        if($forStamping)try{(new CfdiPaymentRuleService($this->db))->validateForAllocations($allocations,(string)($draft['payment_method_code']??''),(string)($draft['payment_form_code']??''));}catch(Throwable$e){$add($errors,'payment','CFDI_PAYMENT_OPERATION_INVALID',$e->getMessage(),'document');}
        if(($draft['currency_code']??'MXN')!=='MXN')try{if(FiscalDecimal::micros((string)($draft['exchange_rate']??'0'))<=0)throw new \RuntimeException();}catch(Throwable){$add($errors,'exchange_rate','EXCHANGE_RATE_REQUIRED','Captura un tipo de cambio válido.','document');}
        try{$this->dates->validate((string)($draft['issue_date']??''));}catch(Throwable$e){$message=match($e->getMessage()){'FISCAL_ISSUE_DATE_REQUIRED'=>'La fecha de expedición es obligatoria.','FISCAL_ISSUE_DATE_FUTURE'=>'La fecha de expedición no puede estar en el futuro.','FISCAL_ISSUE_DATE_TOO_OLD'=>'La fecha de expedición excede la antigüedad permitida.',default=>'La fecha de expedición no tiene un formato válido.'};$add($errors,'issue_date',$e->getMessage(),$message,'document');}
        if(!$allocations)$add($errors,'sales','SALES_REQUIRED','Selecciona al menos una venta con saldo disponible.','sales');
        if(!$concepts)$add($errors,'concepts','CONCEPTS_REQUIRED','Selecciona al menos un concepto.','concepts');

        foreach($concepts as$concept){
            $snapshot=$concept['snapshot']??[];$name=trim((string)($snapshot['fiscal_description']??$snapshot['title']??''));$label=$name!==''?'Concepto “'.$name.'”':'Concepto sin descripción';
            try{if(FiscalDecimal::micros((string)($concept['quantity']??'0'))<=0||FiscalDecimal::micros((string)($concept['total']??'0'))<=0)throw new \RuntimeException();}catch(Throwable){$add($errors,'concepts','CONCEPT_INVALID',$label.': cantidad e importe deben ser mayores que cero.','concepts');}
            if(trim((string)($snapshot['fiscal_description']??''))==='')$add($errors,'fiscal_description','CONCEPT_FISCAL_DESCRIPTION_REQUIRED',$label.': falta descripción fiscal.','concepts');
            foreach(['product_service_code'=>'ClaveProdServ SAT','unit_code'=>'ClaveUnidad SAT','commercial_unit'=>'unidad fiscal','tax_object_code'=>'ObjetoImp SAT']as$field=>$fieldLabel)if(trim((string)($snapshot[$field]??''))==='')$add($errors,$field,'CONCEPT_FISCAL_DATA_REQUIRED',$label.': falta '.$fieldLabel.'.','concepts');
            if((int)($snapshot['snapshot_version']??0)<2){$add($errors,'snapshot_version','FISCAL_DRAFT_SNAPSHOT_INCOMPLETE',$label.': el borrador debe editarse y guardarse nuevamente antes de facturarse.','concepts');continue;}
            $object=(string)($snapshot['object_tax']??$snapshot['tax_object_code']??'');$taxes=(array)($snapshot['taxes']??[]);
            if($object!=='01'&&!$taxes)$add($errors,'taxes','TAX_BREAKDOWN_REQUIRED',$label.': falta el desglose de impuestos requerido por ObjetoImp '.$object.'.','concepts');
            $transfer=$withheld='0.000000';
            foreach($taxes as$tax){
                if(!in_array($tax['tax_type']??'',['transfer','withholding'],true)||!preg_match('/^\d{3}$/',(string)($tax['tax_code']??''))||!in_array($tax['factor_type']??'',['Tasa','Cuota','Exento'],true)){$add($errors,'taxes','TAX_CONFIGURATION_INVALID',$label.': código, tipo o factor de impuesto inválido.','concepts');continue;}
                try{$base=(string)($tax['tax_base']??'');if(FiscalDecimal::micros($base)<0)throw new \RuntimeException();$factor=$tax['factor_type'];$amount=(string)($tax['tax_amount']??'0');$expected=$factor==='Tasa'?FiscalDecimal::multiply($base,(string)($tax['rate_or_quota']??'')):($factor==='Cuota'?FiscalDecimal::multiply((string)$concept['quantity'],(string)($tax['rate_or_quota']??'')):'0.000000');if(FiscalDecimal::micros($expected)!==FiscalDecimal::micros($amount)){$add($errors,'tax_amount','TAX_AMOUNT_MISMATCH',$label.': importe de impuesto '.$amount.'; esperado '.$expected.' según base y tasa.','concepts');continue;}if(($tax['tax_type']??'')==='withholding')$withheld=FiscalDecimal::add($withheld,$amount);else$transfer=FiscalDecimal::add($transfer,$amount);}catch(Throwable){$add($errors,'tax_amount','TAX_AMOUNT_INVALID',$label.': no fue posible validar base, tasa e importe del impuesto.','concepts');}
            }
            try{$expected=FiscalDecimal::subtract(FiscalDecimal::add((string)$snapshot['taxable_base'],$transfer),$withheld);$actual=(string)$concept['total'];if(FiscalDecimal::micros($expected)!==FiscalDecimal::micros($actual)){$rounding=$this->cents($expected)===$this->cents($actual);$add($errors,'total',$rounding?'CONCEPT_TOTAL_ROUNDING_MISMATCH':'CONCEPT_TOTAL_MISMATCH',$label.': total '.$actual.'; esperado '.$expected.($rounding?' (diferencia de precisión/redondeo).':'.'),'concepts');}}catch(Throwable){$add($errors,'total','CONCEPT_TOTAL_INVALID',$label.': el total no es válido.','concepts');}
        }
        return['valid'=>!$errors,'errors'=>$errors,'warnings'=>$warnings];
    }
    private function cents(string$value):string{return number_format((float)$value,2,'.','');}
}
