<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
use InvalidArgumentException;
final class InvoiceItemFiscalOverrideService
{
 public function __construct(private mixed$db=null){$this->db??=db_connect();}
 public function effective(int$itemId):array{$row=$this->db->table('invoice_items')->where(['id'=>$itemId,'deleted'=>0])->get(1)->getRow();if(!$row)return[];$stored=json_decode((string)($row->fiscal_override_json??''),true);$override=(new FiscalItemOverrideContract())->normalizeStored(is_array($stored)?$stored:null,(int)$row->item_id);if($override&&$override['ready'])return['source'=>'invoice_item_override']+$override;$master=(new ProductFiscalConfigurationResolver($this->db))->resolve((int)$row->item_id);if($override&&!$master['ready'])return['source'=>'invoice_item_override']+$override;return['source'=>$master['source'],'ready'=>$master['ready'],'missing'=>$master['missing'],'setting'=>$master['setting'],'taxes'=>$master['taxes']];}
 public function fromInput(array$input,int$productId):?string{return$this->fromValidatedInput($input,$productId);}
 public function fromValidatedInput(array$input,int$productId):?string{$contract=new FiscalItemOverrideContract();$data=$contract->fromInput($input,$productId);return$data?$contract->encode($data):null;}
 private function complete(array$d):bool{if(empty($d['ready'])||empty($d['product_service_code'])||empty($d['unit_code'])||empty($d['commercial_unit'])||empty($d['tax_object_code'])||($d['tax_object_code']!=='01'&&empty($d['taxes'])))return false;foreach((array)($d['taxes']??[])as$tax)if(str_pad((string)($tax['tax_code']??''),3,'0',STR_PAD_LEFT)==='001'&&($tax['tax_type']??'')!=='withholding')return false;return true;}
}
