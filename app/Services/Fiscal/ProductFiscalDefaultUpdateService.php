<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
use InvalidArgumentException;
final class ProductFiscalDefaultUpdateService
{
 public function __construct(private mixed$db=null){$this->db??=db_connect();}
 public function apply(int$productId,array$data,int$actorId):void
 {
  if($productId<1)throw new InvalidArgumentException('Una linea libre no puede actualizar un producto maestro.');
  $validated=(new FiscalItemOverrideContract())->normalizeStored($data,$productId);
  if(!$validated||!$validated['ready'])throw new InvalidArgumentException('La configuracion fiscal no cumple el contrato: '.implode(', ',(array)($validated['missing']??[])).'.');
  $data=$validated;
  if(!$this->db->table('items')->where(['id'=>$productId,'deleted'=>0])->countAllResults())throw new InvalidArgumentException('El producto maestro no existe.');
  $pk=$this->catalog('sat_product_service_keys',(string)$data['product_service_code']);$uk=$this->catalog('sat_unit_keys',(string)$data['unit_code']);$obj=$this->catalog('sat_tax_object_codes',(string)$data['tax_object_code']);
  $setting=$this->db->table('item_fiscal_settings')->where(['item_id'=>$productId,'is_default'=>1,'deleted'=>0])->get(1)->getRow();$now=get_current_utc_time();
  $row=['item_id'=>$productId,'item_type'=>'product','sat_product_service_key_id'=>$pk,'sat_unit_key_id'=>$uk,'commercial_unit'=>$data['commercial_unit'],'tax_object_code_id'=>$obj,'fiscal_description'=>$data['fiscal_description'],'is_default'=>1,'status'=>'ready','updated_at'=>$now,'deleted'=>0];
  if($setting){$this->db->table('item_fiscal_settings')->where('id',$setting->id)->update($row);$settingId=(int)$setting->id;$this->db->table('item_fiscal_taxes')->where('item_fiscal_setting_id',$settingId)->delete();}
  else{$row+=['created_by'=>$actorId,'created_at'=>$now];$this->db->table('item_fiscal_settings')->insert($row);$settingId=(int)$this->db->insertID();}
  foreach($data['taxes']as$i=>$tax)$this->db->table('item_fiscal_taxes')->insert(['item_fiscal_setting_id'=>$settingId,'tax_id'=>$this->taxId($tax),'sort_order'=>$i,'is_active'=>1,'created_at'=>$now,'updated_at'=>$now]);
 }
 private function catalog(string$table,string$code):int{$row=$this->db->table($table)->select('id')->where(['code'=>$code,'is_active'=>1])->get(1)->getRow();if(!$row)throw new InvalidArgumentException('Codigo fiscal '.$code.' no disponible.');return(int)$row->id;}
 private function taxId(array$tax):int{$q=$this->db->table('taxes t')->select('t.id')->join('sat_tax_codes c','c.id=t.sat_tax_code_id')->join('sat_tax_factor_types f','f.id=t.factor_type_id')->where(['c.code'=>$tax['tax_code'],'t.fiscal_tax_type'=>$tax['tax_type'],'f.name'=>$tax['factor_type'],'t.deleted'=>0,'t.use_for_fiscal'=>1,'t.is_fiscal_ready'=>1]);if($tax['factor_type']==='Tasa')$q->where('t.xml_rate',$tax['rate_or_quota']);elseif($tax['factor_type']==='Cuota')$q->where('t.xml_quota',$tax['rate_or_quota']);$row=$q->get(1)->getRow();if(!$row)throw new InvalidArgumentException('El impuesto seleccionado no existe en la configuracion fiscal autorizada.');return(int)$row->id;}
}
