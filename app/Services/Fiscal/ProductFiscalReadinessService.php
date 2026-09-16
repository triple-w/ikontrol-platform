<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
final class ProductFiscalReadinessService
{
 public function evaluate(?array$setting,array$taxes):array
 {
  if(!$setting)return['ready'=>false,'missing'=>['configuracion fiscal maestra'],'normalized'=>null];
  $stored=['product_id'=>(int)($setting['item_id']??0),'product_service_code'=>(string)($setting['product_service_code']??''),'unit_code'=>(string)($setting['unit_code']??''),'commercial_unit'=>(string)($setting['commercial_unit']??''),'tax_object_code'=>(string)($setting['tax_object_code']??''),'fiscal_description'=>(string)($setting['fiscal_description']??''),'pricing_mode'=>(string)($setting['pricing_mode']??$setting['tax_pricing_mode']??'tax_inclusive'),'taxes'=>$taxes];
  $normalized=(new FiscalItemOverrideContract())->normalizeStored($stored,(int)($setting['item_id']??0));$missing=(array)($normalized['missing']??[]);
  if(!in_array((string)($setting['status']??''),['active','ready'],true))$missing[]='estado fiscal listo';
  return['ready'=>!$missing,'missing'=>array_values(array_unique($missing)),'normalized'=>$normalized];
 }
}
