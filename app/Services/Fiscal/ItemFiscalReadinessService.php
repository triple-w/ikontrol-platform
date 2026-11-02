<?php
declare(strict_types=1);
namespace App\Services\Fiscal;

/** UI projection of the same effective product contract used by preflight. */
final class ItemFiscalReadinessService
{
    public function __construct(private mixed $db = null)
    {
        $this->db ??= db_connect();
    }

    public function evaluate(int $itemId, ?int $configurationId = null): array
    {
        $result = [
            'item_id'=>$itemId,'configuration_id'=>$configurationId,'status'=>'not_configured',
            'is_ready'=>false,'errors'=>[],'warnings'=>[],'missing_fields'=>[],
            'issues'=>[],'information'=>[],'tax_object_code'=>null,'tax_object_description'=>null,
        ];
        $item=$this->db->table('items')->where(['id'=>$itemId,'deleted'=>0])->get(1)->getRow();
        if(!$item){$result['errors'][]='El producto o servicio no existe.';return$result;}

        $resolved=(new ProductFiscalConfigurationResolver($this->db))->resolve($itemId,$configurationId);
        $setting=$resolved['setting']??null;
        if(!$setting)return$result;
        $result['configuration_id']=(int)$setting['id'];
        if((string)($setting['status']??'')==='inactive'){
            $result['status']='inactive';$result['errors'][]='La configuración está inactiva.';
            $result['issues'][]=['field'=>'status','code'=>'FISCAL_CONFIGURATION_INACTIVE','message'=>'La configuración está inactiva.'];
            return$result;
        }

        $result['issues']=$resolved['issues']??[];
        $result['missing_fields']=array_values(array_unique(array_map(static fn(array$i):string=>(string)$i['field'],$result['issues'])));
        $result['errors']=array_values(array_unique(array_map(static fn(array$i):string=>(string)$i['message'],$result['issues'])));
        $result['tax_object_code']=$setting['tax_object_code']??null;
        if($result['tax_object_code']){
            $catalog=$this->db->table('sat_tax_object_codes')->select('description')->where(['code'=>$result['tax_object_code'],'is_active'=>1])->get(1)->getRow();
            $result['tax_object_description']=$catalog->description??null;
        }
        $result['is_ready']=(bool)$resolved['ready'];
        $result['status']=$result['is_ready']?'ready':'incomplete';
        return$result;
    }
}
