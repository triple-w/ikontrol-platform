<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
final class CommercialItemFiscalOverrideService
{
 public function __construct(private mixed$db=null){$this->db??=db_connect();}
 public function effective(string$table,int$id):array{$allowed=['proposal_items','estimate_items'];if(!in_array($table,$allowed,true))return[];$row=$this->db->table($table)->where(['id'=>$id,'deleted'=>0])->get(1)->getRow();if(!$row)return[];$stored=json_decode((string)($row->fiscal_override_json??''),true);$override=(new FiscalItemOverrideContract())->normalizeStored(is_array($stored)?$stored:null,(int)$row->item_id);if($override&&$override['ready'])return['source'=>'item_override']+$override;$master=(new ProductFiscalConfigurationResolver($this->db))->resolve((int)$row->item_id);if($override&&!$master['ready'])return['source'=>'item_override']+$override;return['source'=>$master['source'],'ready'=>$master['ready'],'missing'=>$master['missing'],'setting'=>$master['setting'],'taxes'=>$master['taxes']];}
 public function validateInput(array$input,int$productId):?string{return(new InvoiceItemFiscalOverrideService($this->db))->fromValidatedInput($input,$productId);}
}
