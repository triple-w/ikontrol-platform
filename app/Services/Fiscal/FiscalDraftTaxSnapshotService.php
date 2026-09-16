<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
use RuntimeException;
final class FiscalDraftTaxSnapshotService
{
 public function __construct(private mixed$db=null){$this->db??=db_connect();}
 public function calculate(array$concept,string$mode='tax_inclusive'):array{
  $object=(string)($concept['snapshot']['tax_object_code']??'');$overrides=(array)($concept['snapshot']['taxes']??[]);$rows=[];
  if(!$overrides&&$object!=='01'){$resolved=(new InvoiceItemTaxResolver($this->db))->resolve((int)($concept['sale_item_id']??0));if(!$resolved['ready'])throw new RuntimeException('Falta la configuración fiscal del concepto.');foreach($resolved['taxes']as$i=>$tax)$rows[]=['fiscal_tax_type'=>$tax['tax_type'],'xml_rate'=>$tax['factor_type']==='Tasa'?$tax['rate_or_quota']:null,'xml_quota'=>$tax['factor_type']==='Cuota'?$tax['rate_or_quota']:null,'is_fiscal_ready'=>1,'use_for_fiscal'=>1,'tax_code'=>$tax['tax_code'],'factor_type'=>$tax['factor_type'],'sort_order'=>$i];}
  else{foreach($overrides as$i=>$tax){$code=str_pad(trim((string)($tax['tax_code']??'')),3,'0',STR_PAD_LEFT);$factor=trim((string)($tax['factor_type']??''));$type=trim((string)($tax['tax_type']??''));$rate=trim((string)($tax['rate_or_quota']??''));if(!in_array($type,['transfer','withholding'],true)||!in_array($factor,['Tasa','Cuota','Exento'],true)||!$this->db->table('sat_tax_codes')->where(['code'=>$code,'is_active'=>1])->countAllResults())throw new RuntimeException('El impuesto fiscal capturado no es válido.');if($factor!=='Exento'&&!preg_match('/^\d+(?:\.\d{1,6})?$/',$rate))throw new RuntimeException('La tasa o cuota fiscal no es válida.');$rows[]=['fiscal_tax_type'=>$type,'xml_rate'=>$factor==='Tasa'?$rate:null,'xml_quota'=>$factor==='Cuota'?$rate:null,'is_fiscal_ready'=>1,'use_for_fiscal'=>1,'tax_code'=>$code,'factor_type'=>$factor,'sort_order'=>$i];}}
  if($object!=='01'&&!$rows)throw new RuntimeException('Falta el desglose de impuestos del concepto.');
  $net=FiscalDecimal::subtract((string)$concept['subtotal'],(string)$concept['discount']);$signedRates='0.000000';$signedQuotas='0.000000';
  foreach($rows as$r){$sign=$r['fiscal_tax_type']==='withholding'?'-':'';if($r['factor_type']==='Tasa')$signedRates=FiscalDecimal::add($signedRates,$sign.ltrim((string)$r['xml_rate'],'-'));if($r['factor_type']==='Cuota')$signedQuotas=FiscalDecimal::add($signedQuotas,$sign.ltrim(FiscalDecimal::multiply((string)$concept['quantity'],(string)$r['xml_quota']),'-'));}
  $includedBase=FiscalDecimal::subtract($net,$signedQuotas);
  $base=$mode==='tax_exclusive'?$net:($rows?FiscalDecimal::prorate($includedBase,'1.000000',FiscalDecimal::add('1.000000',$signedRates)):$net);
  $taxes=[];$trans=$with='0.000000';foreach($rows as$i=>$r){if(!(int)$r['is_fiscal_ready']||!(int)$r['use_for_fiscal']||!in_array($r['fiscal_tax_type'],['transfer','withholding'],true)||!in_array($r['factor_type'],['Tasa','Cuota','Exento'],true)||!$r['tax_code'])throw new RuntimeException('La configuración fiscal del impuesto está incompleta.');$factor=$r['factor_type'];$rate=$factor==='Tasa'?(string)$r['xml_rate']:($factor==='Cuota'?(string)$r['xml_quota']:null);$amount=$factor==='Tasa'?FiscalDecimal::multiply($base,(string)$rate):($factor==='Cuota'?FiscalDecimal::multiply((string)$concept['quantity'],(string)$rate):'0.000000');if($r['fiscal_tax_type']==='withholding')$with=FiscalDecimal::add($with,$amount);else$trans=FiscalDecimal::add($trans,$amount);$taxes[]=['tax_type'=>$r['fiscal_tax_type'],'tax_code'=>$r['tax_code'],'factor_type'=>$factor,'rate_or_quota'=>$rate,'tax_base'=>$base,'tax_amount'=>$amount,'is_exempt'=>$factor==='Exento'?1:0,'calculation_order'=>(int)$r['sort_order']];}
  $total=FiscalDecimal::subtract(FiscalDecimal::add($base,$trans),$with);return['base'=>$base,'transferred'=>$trans,'withheld'=>$with,'total'=>$total,'taxes'=>$taxes];
 }
}
