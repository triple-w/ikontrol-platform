<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
use RuntimeException;
final class ProductFiscalConfigurationAuditService
{
 public function __construct(private mixed$db=null){$this->db??=db_connect();}
 public function auditAll():array{$rows=$this->db->table('items')->select('id,title,description,unit_type')->where('deleted',0)->orderBy('id')->get()->getResultArray();return array_map(fn(array$i)=>$this->auditProduct($i),$rows);}
 public function repair(array$f,int$actor):void{if(($f['classification']??'')!=='LEGACY_REPAIRABLE'||empty($f['repair_input']))throw new RuntimeException('El producto no tiene una reparacion fiscal inequivoca.');$this->db->transBegin();try{(new ProductFiscalDefaultUpdateService($this->db))->apply((int)$f['product_id'],$f['repair_input'],$actor);if(!(new ProductFiscalConfigurationResolver($this->db))->resolve((int)$f['product_id'])['ready'])throw new RuntimeException('La reparacion no satisface el contrato fiscal.');if(!$this->db->transStatus())throw new RuntimeException('No fue posible reparar la configuracion fiscal.');$this->db->transCommit();}catch(\Throwable$e){$this->db->transRollback();throw$e;}}
 private function auditProduct(array$item):array
 {
  $settings=$this->db->table('item_fiscal_settings s')->select('s.*,p.code product_service_code,u.code unit_code,o.code tax_object_code')->join('sat_product_service_keys p','p.id=s.sat_product_service_key_id','left')->join('sat_unit_keys u','u.id=s.sat_unit_key_id','left')->join('sat_tax_object_codes o','o.id=s.tax_object_code_id','left')->where(['s.item_id'=>$item['id'],'s.is_default'=>1,'s.deleted'=>0])->orderBy('s.id','DESC')->get()->getResultArray();
  if(!$settings)return$this->finding($item,'INCOMPLETE',['configuracion fiscal maestra']);if(count($settings)!==1)return$this->finding($item,'INCONSISTENT',['multiples configuraciones fiscales por defecto']);$s=$settings[0];
  $rows=$this->db->table('item_fiscal_taxes ft')->select('ft.is_active,t.deleted tax_deleted,t.use_for_fiscal,t.is_fiscal_ready,t.fiscal_tax_type tax_type,c.code tax_code,f.name factor_type,COALESCE(t.xml_rate,t.xml_quota) rate_or_quota')->join('taxes t','t.id=ft.tax_id','left')->join('sat_tax_codes c','c.id=t.sat_tax_code_id','left')->join('sat_tax_factor_types f','f.id=t.factor_type_id','left')->where('ft.item_fiscal_setting_id',$s['id'])->orderBy('ft.sort_order')->get()->getResultArray();
  foreach($rows as$t)if(!(int)$t['is_active']||(int)$t['tax_deleted']||!(int)$t['use_for_fiscal']||!(int)$t['is_fiscal_ready'])return$this->finding($item,'INCONSISTENT',['relacion de impuesto inactiva o no autorizada']);$taxes=array_map(fn($t)=>array_intersect_key($t,array_flip(['tax_type','tax_code','factor_type','rate_or_quota'])),$rows);$a=(new ProductFiscalReadinessService())->evaluate($s,$taxes);if($a['ready'])return$this->finding($item,'READY',[]);
  $candidate=$s;$candidate['commercial_unit']=trim((string)$candidate['commercial_unit'])?:trim((string)$item['unit_type']);$candidate['fiscal_description']=trim((string)$candidate['fiscal_description'])?:trim((string)($item['description']?:$item['title']));$candidate['status']='ready';
  if((new ProductFiscalReadinessService())->evaluate($candidate,$taxes)['ready'])return$this->finding($item,'LEGACY_REPAIRABLE',$a['missing'],['product_service_code'=>$candidate['product_service_code'],'unit_code'=>$candidate['unit_code'],'commercial_unit'=>$candidate['commercial_unit'],'tax_object_code'=>$candidate['tax_object_code'],'fiscal_description'=>$candidate['fiscal_description'],'taxes'=>$taxes]);
  return$this->finding($item,'INCOMPLETE',$a['missing']);
 }
 private function finding(array$i,string$c,array$issues,?array$repair=null):array{return['product_id'=>(int)$i['id'],'product'=>(string)$i['title'],'classification'=>$c,'issues'=>array_values(array_unique($issues)),'repair_input'=>$repair];}
}
