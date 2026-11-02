<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
final class ProductFiscalReadinessService
{
 public function evaluate(?array$setting,array$taxes):array
 {
  if(!$setting)return['ready'=>false,'missing'=>['configuración fiscal maestra'],'issues'=>[$this->issue('configuration','PRODUCT_FISCAL_CONFIGURATION_MISSING','Falta la configuración fiscal maestra.')],'normalized'=>null];
  $stored=['product_id'=>(int)($setting['item_id']??0),'product_service_code'=>(string)($setting['product_service_code']??''),'unit_code'=>(string)($setting['unit_code']??''),'commercial_unit'=>(string)($setting['commercial_unit']??''),'tax_object_code'=>(string)($setting['tax_object_code']??''),'fiscal_description'=>(string)($setting['fiscal_description']??''),'pricing_mode'=>(string)($setting['pricing_mode']??$setting['tax_pricing_mode']??'tax_inclusive'),'taxes'=>$taxes];
  $normalized=(new FiscalItemOverrideContract())->normalizeStored($stored,(int)($setting['item_id']??0));$missing=(array)($normalized['missing']??[]);
  $taxIds=[];foreach($taxes as$tax){$taxId=(int)($tax['tax_id']??0);if($taxId>0&&isset($taxIds[$taxId]))$missing[]='impuesto duplicado';$taxIds[$taxId]=true;if(isset($tax['tax_code_active'])&&!(int)$tax['tax_code_active'])$missing[]='catálogo de impuesto activo';if(isset($tax['factor_active'])&&!(int)$tax['factor_active'])$missing[]='factor de impuesto activo';}
  if(!in_array((string)($setting['item_type']??''),['product','service'],true))$missing[]='tipo producto/servicio';
  if(isset($setting['product_key_active'])&&!(int)$setting['product_key_active'])$missing[]='ClaveProdServ activa';
  if(isset($setting['unit_key_active'])&&!(int)$setting['unit_key_active'])$missing[]='ClaveUnidad activa';
  if(isset($setting['tax_object_active'])&&!(int)$setting['tax_object_active'])$missing[]='ObjetoImp activo';
  if((string)($setting['status']??'')==='inactive')$missing[]='configuración fiscal inactiva';
  $missing=array_values(array_unique($missing));$issues=[];
  foreach($missing as$label)$issues[]=$this->issueFor((string)$label);
  return['ready'=>!$missing,'missing'=>$missing,'issues'=>$issues,'normalized'=>$normalized];
 }

 private function issueFor(string$label):array
 {
  return match($label){
   'ClaveProdServ'=>$this->issue('product_service_code','PRODUCT_SERVICE_CODE_REQUIRED','Falta la ClaveProdServ SAT.'),
   'ClaveUnidad'=>$this->issue('unit_code','UNIT_CODE_REQUIRED','Falta la ClaveUnidad SAT.'),
   'unidad'=>$this->issue('commercial_unit','COMMERCIAL_UNIT_REQUIRED','Falta la unidad fiscal del concepto.'),
   'ObjetoImp','ObjetoImp válido'=>$this->issue('tax_object_code','TAX_OBJECT_REQUIRED','Falta un ObjetoImp SAT válido.'),
   'descripción fiscal'=>$this->issue('fiscal_description','FISCAL_DESCRIPTION_REQUIRED','Falta la descripción fiscal del concepto.'),
   'impuestos'=>$this->issue('taxes','TAX_BREAKDOWN_REQUIRED','Falta el desglose de impuestos requerido por ObjetoImp.'),
   'configuración fiscal inactiva'=>$this->issue('status','FISCAL_CONFIGURATION_INACTIVE','La configuración fiscal está inactiva.'),
   'tipo producto/servicio'=>$this->issue('item_type','ITEM_TYPE_REQUIRED','Falta el tipo fiscal producto/servicio.'),
   'ClaveProdServ activa'=>$this->issue('product_service_code','PRODUCT_SERVICE_CODE_INACTIVE','La ClaveProdServ SAT seleccionada está inactiva.'),
   'ClaveUnidad activa'=>$this->issue('unit_code','UNIT_CODE_INACTIVE','La ClaveUnidad SAT seleccionada está inactiva.'),
   'ObjetoImp activo'=>$this->issue('tax_object_code','TAX_OBJECT_INACTIVE','El ObjetoImp SAT seleccionado está inactivo.'),
   'impuesto duplicado'=>$this->issue('taxes','TAX_DUPLICATE','Existe un impuesto fiscal duplicado.'),
   'catálogo de impuesto activo'=>$this->issue('taxes','TAX_CODE_INACTIVE','El código SAT de un impuesto está inactivo.'),
   'factor de impuesto activo'=>$this->issue('taxes','TAX_FACTOR_INACTIVE','El factor SAT de un impuesto está inactivo.'),
   default=>$this->issue('taxes','TAX_CONFIGURATION_INVALID','La configuración fiscal contiene un dato inválido: '.$label.'.'),
  };
 }

 private function issue(string$field,string$code,string$message):array{return compact('field','code','message');}
}
