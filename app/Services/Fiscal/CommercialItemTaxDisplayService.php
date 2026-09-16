<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
final class CommercialItemTaxDisplayService
{
 public function __construct(private mixed$db=null){$this->db??=db_connect();}
 public function invoice(int$id):array{$row=$this->db->table('invoice_items')->where(['id'=>$id,'deleted'=>0])->get(1)->getRow();if(!$row)return$this->empty();$c=(new CommercialTaxBreakdownService($this->db))->lineForSale($id);return$this->present($c,(string)$row->quantity,(string)$row->rate);}
 public function document(string$table,string$parentTable,string$fk,int$id):array{$row=$this->db->table($table)->where(['id'=>$id,'deleted'=>0])->get(1)->getRow();if(!$row)return$this->empty();$c=(new CommercialTaxBreakdownService($this->db))->lineForDocument($parentTable,$table,$fk,$id);return$this->present($c,(string)$row->quantity,(string)$row->rate);}
 private function present(array$c,string$qty,string$unitPrice):array{if(empty($c['ready']))return['ready'=>false,'unit_base'=>$unitPrice,'base'=>FiscalDecimal::multiply($unitPrice,$qty),'taxes'=>'Configuración pendiente','total'=>FiscalDecimal::multiply($unitPrice,$qty)];$names=['001'=>'ISR','002'=>'IVA','003'=>'IEPS'];$parts=[];foreach((array)$c['calculated_taxes']as$t){$name=$names[$t['tax_code']]??$t['tax_code'];$prefix=$t['tax_type']==='withholding'?'Retención ':'';$rate=$t['factor_type']==='Tasa'?(' '.rtrim(rtrim(number_format((float)$t['rate_or_quota']*100,6,'.',''),'0'),'.').'%'):(' '.$t['factor_type']);$sign=$t['tax_type']==='withholding'?'-':'';$parts[]=$prefix.$name.$rate.' · '.$sign.number_format((float)$t['tax_amount'],2,'.',',');}if(!$parts)$parts[]='Sin impuesto';return['ready'=>true,'unit_base'=>FiscalDecimal::divide($c['base'],$qty),'base'=>$c['base'],'taxes'=>implode('<br>',$parts),'total'=>$c['total']];}
 private function empty():array{return['ready'=>false,'unit_base'=>'0','base'=>'0','taxes'=>'Configuración pendiente','total'=>'0'];}
}
