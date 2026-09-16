<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
final class InvoiceItemTaxResolver
{
 public function __construct(private mixed$db=null){$this->db??=db_connect();}
 public function resolve(int$invoiceItemId,?int$issuerId=null):array{
  $item=$this->db->table('invoice_items ii')->select('ii.*,i.company_id')->join('invoices i','i.id=ii.invoice_id')->where(['ii.id'=>$invoiceItemId,'ii.deleted'=>0])->get(1)->getRow();if(!$item)return['source'=>'none','ready'=>false,'prices_include_tax'=>true,'pricing_mode'=>'tax_inclusive','tax_object_code'=>'','taxes'=>[],'missing'=>['Partida inexistente']];
  if(!$issuerId)$issuer=(new FiscalIssuerResolver($this->db))->resolve((int)$item->company_id,config('Fiscal')->environment);else$issuer=$this->db->table('fiscal_profiles')->where('id',$issuerId)->get(1)->getRow();$mode=(string)($issuer->tax_pricing_mode??'tax_inclusive');$effective=(new InvoiceItemFiscalOverrideService($this->db))->effective($invoiceItemId);$taxes=array_values((array)($effective['taxes']??[]));$missing=array_values((array)($effective['missing']??[]));
  foreach($taxes as$tax){$code=str_pad((string)($tax['tax_code']??''),3,'0',STR_PAD_LEFT);$type=(string)($tax['tax_type']??'');if($code==='001'&&$type!=='withholding')$missing[]='ISR 001 debe configurarse como retención, no como traslado';}
  $origin=(string)($item->price_origin??'manual');$effectiveMode=$origin==='cost_margin'?'tax_exclusive':(in_array((string)($effective['pricing_mode']??''),['tax_inclusive','tax_exclusive'],true)?(string)$effective['pricing_mode']:$mode);return array_merge($effective,['source'=>(string)($effective['source']??'none'),'ready'=>!empty($effective['ready'])&&!$missing,'prices_include_tax'=>$effectiveMode!=='tax_exclusive','pricing_mode'=>$effectiveMode,'price_origin'=>$origin==='cost_margin'?'cost_margin':'manual','tax_object_code'=>(string)($effective['tax_object_code']??$effective['setting']['tax_object_code']??''),'taxes'=>$taxes,'missing'=>array_values(array_unique($missing)),'invoice_item_id'=>$invoiceItemId,'product_id'=>(int)$item->item_id]);
 }
 public function resolveProduct(int$productId,int$companyId,?int$issuerId=null):array{
  if(!$issuerId)$issuer=(new FiscalIssuerResolver($this->db))->resolve($companyId,config('Fiscal')->environment);else$issuer=$this->db->table('fiscal_profiles')->where('id',$issuerId)->get(1)->getRow();$mode=(string)($issuer->tax_pricing_mode??'tax_inclusive');$master=(new ProductFiscalConfigurationResolver($this->db))->resolve($productId);$masterMode=(string)($master['setting']['pricing_mode']??$master['setting']['tax_pricing_mode']??$mode);if(!in_array($masterMode,['tax_inclusive','tax_exclusive'],true))$masterMode=$mode;return array_merge($master,['prices_include_tax'=>$masterMode!=='tax_exclusive','pricing_mode'=>$masterMode,'tax_object_code'=>(string)($master['setting']['tax_object_code']??''),'product_id'=>$productId]);
 }
}
