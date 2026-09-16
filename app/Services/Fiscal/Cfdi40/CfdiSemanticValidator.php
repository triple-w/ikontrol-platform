<?php
declare(strict_types=1);
namespace App\Services\Fiscal\Cfdi40;
use App\Domain\Fiscal\Cfdi40\CfdiDocument;
use App\Services\Fiscal\CfdiPaymentRuleService;
use App\Services\Fiscal\FiscalDecimalCalculator;
final class CfdiSemanticValidator{
 private FiscalDecimalCalculator$d;public function __construct(){$this->d=new FiscalDecimalCalculator();}
 public function validate(CfdiDocument$c):array{$errors=[];$warnings=['La validación completa permanece pendiente de CSD, certificado y sello.'];$checks=[];$need=function(bool$ok,string$group,string$message)use(&$errors,&$checks){$checks[]=['group'=>$group,'message'=>$message,'passed'=>$ok];if(!$ok)$errors[$group][]=$message;};
  foreach(['series','folio','issue_date','currency_code','payment_form_code','payment_method_code','export_code','expedition_postal_code','subtotal','total']as$f)$need(trim((string)$c->get($f))!=='','Comprobante',"Falta $f.");
  $need($c->get('status')==='locked','Comprobante','El documento debe estar locked.');$need($c->get('document_type')==='income','Comprobante','El documento debe ser de ingreso.');
  try{(new CfdiPaymentRuleService())->validate((string)$c->get('payment_method_code'),(string)$c->get('payment_form_code'));$need(true,'Pago','FormaPago y MetodoPago son compatibles.');}catch(\Throwable$e){$need(false,'Pago',$e->getMessage());}
  if($c->get('currency_code')!=='MXN')$need($c->get('exchange_rate')!==null&&$this->d->compare((string)$c->get('exchange_rate'),'0')>0,'Moneda','La moneda extranjera requiere tipo de cambio positivo.');
  foreach(['rfc','legal_name','tax_regime_code']as$f)$need(trim((string)$c->issuer->get($f))!=='','Emisor',"Falta $f del emisor.");
  foreach(['rfc','legal_name','tax_regime_code','fiscal_postal_code','cfdi_use_code']as$f)$need(trim((string)$c->receiver->get($f))!=='','Receptor',"Falta $f del receptor.");
  $need(count($c->concepts)>0,'Conceptos','Debe existir al menos un concepto.');$subtotal='0';$discount='0';$trans='0';$withheld='0';$summary=[];
  foreach($c->concepts as$n=>$concept){foreach(['product_service_code','quantity','unit_code','description','unit_value','gross_amount','tax_object_code']as$f)$need(trim((string)$concept->get($f))!=='','Conceptos',"Concepto ".($n+1).": falta $f.");$need($this->d->compare((string)$concept->get('quantity'),'0')>0,'Conceptos',"Concepto ".($n+1).': cantidad no positiva.');$subtotal=$this->d->add($subtotal,$this->d->money((string)$concept->get('gross_amount')));$discount=$this->d->add($discount,$this->d->money((string)$concept->get('discount')));
   foreach($concept->taxes as$t){$need($this->d->compare($t->base,'0')>=0,'Impuestos','La base no puede ser negativa.');$need(in_array($t->factorType,['Tasa','Cuota','Exento'],true),'Impuestos','TipoFactor inválido.');if($t->factorType!=='Exento')$need($t->rateOrQuota!==null,'Impuestos','Falta TasaOCuota.');if($t->taxType==='withheld')$withheld=$this->d->add($withheld,$this->d->money($t->amount));else$trans=$this->d->add($trans,$this->d->money($t->amount));$key=implode('|',[$t->taxCode,$t->taxType,$t->factorType,$t->rateOrQuota??'']);$summary[$key]['base']=$this->d->add($summary[$key]['base']??'0',$this->d->money($t->base));$summary[$key]['amount']=$this->d->add($summary[$key]['amount']??'0',$this->d->money($t->amount));}
  }
  $canonical=(new CfdiCurrencyTotalsCalculator())->fromAggregates($subtotal,$discount,$trans,$withheld);
  $need($canonical['subtotal']===$this->d->money((string)$c->get('subtotal')),'Totales','La suma de conceptos no coincide con SubTotal.');$need($canonical['discount']===$this->d->money((string)$c->get('discount')),'Totales','La suma de descuentos no coincide.');$need($canonical['transferred']===$this->d->money((string)$c->get('transferred_tax_total')),'Impuestos','Los traslados por concepto no coinciden.');$need($canonical['withheld']===$this->d->money((string)$c->get('withheld_tax_total')),'Impuestos','Las retenciones por concepto no coinciden.');
  foreach($c->taxTotals as$t){$key=implode('|',[$t->taxCode,$t->taxType,$t->factorType,$t->rateOrQuota??'']);$need(isset($summary[$key])&&$this->d->money($summary[$key]['base'])===$this->d->money($t->base)&&$this->d->money($summary[$key]['amount'])===$this->d->money($t->amount),'Impuestos','El resumen global no coincide con los conceptos.');}
  $need($canonical['total']===$this->d->money((string)$c->get('total')),'Totales','SubTotal - Descuento + Traslados - Retenciones no coincide con Total.');
  return['is_valid'=>$errors===[],'errors'=>$errors,'warnings'=>$warnings,'checks'=>$checks,'validation_level'=>'pre_sign_validation','full_cfdi_validation'=>['is_valid'=>false,'reason'=>'Pendiente de CSD, Certificado, NoCertificado y Sello.']];
 }
}
