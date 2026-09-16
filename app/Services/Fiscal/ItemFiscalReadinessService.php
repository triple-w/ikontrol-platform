<?php
declare(strict_types=1);
namespace App\Services\Fiscal;

use App\Models\Fiscal\Item_fiscal_settings_model;
use App\Models\Fiscal\Item_fiscal_taxes_model;
use App\Models\Fiscal\Sat_product_service_keys_model;
use App\Models\Fiscal\Sat_tax_object_codes_model;
use App\Models\Fiscal\Sat_unit_keys_model;
use App\Models\Items_model;

class ItemFiscalReadinessService
{
    public function evaluate(int$itemId,?int$configurationId=null):array
    {
        $result=['item_id'=>$itemId,'configuration_id'=>$configurationId,'status'=>'not_configured','is_ready'=>false,'errors'=>[],'warnings'=>[],'missing_fields'=>[],'information'=>[],'tax_object_code'=>null,'tax_object_description'=>null];
        $item=(new Items_model())->get_one($itemId);
        if(!$item->id||$item->deleted){$result['errors'][]='El producto o servicio no existe.';return$result;}

        $settings=new Item_fiscal_settings_model();
        $configuration=$configurationId?$settings->get_one($configurationId):$settings->activeForItem($itemId);
        if(!$configuration||!$configuration->id||$configuration->deleted)return$result;
        $result['configuration_id']=(int)$configuration->id;
        if($configuration->status==='inactive'){$result['status']='inactive';$result['errors'][]='La configuración está inactiva.';return$result;}

        if(!in_array($configuration->item_type,['product','service'],true))$result['missing_fields'][]='item_type';
        $productKey=$configuration->sat_product_service_key_id?(new Sat_product_service_keys_model())->get_one($configuration->sat_product_service_key_id):null;
        $unitKey=$configuration->sat_unit_key_id?(new Sat_unit_keys_model())->get_one($configuration->sat_unit_key_id):null;
        if(!$configuration->sat_product_service_key_id)$result['missing_fields'][]='sat_product_service_key_id';elseif(!$productKey||!$productKey->id||!(int)$productKey->is_active)$result['errors'][]='La Clave de producto o servicio SAT seleccionada está inactiva.';
        if(!$configuration->sat_unit_key_id)$result['missing_fields'][]='sat_unit_key_id';elseif(!$unitKey||!$unitKey->id||!(int)$unitKey->is_active)$result['errors'][]='La Clave de unidad SAT seleccionada está inactiva.';
        if(trim((string)$item->description)===''){$result['missing_fields'][]='administrative_description';}

        $taxes=(new Item_fiscal_taxes_model())->forSetting((int)$configuration->id);
        $taxIds=array_map(static fn($tax)=>(int)$tax->tax_id,$taxes);
        if(count($taxIds)!==count(array_unique($taxIds)))$result['errors'][]='Existe un impuesto duplicado.';
        foreach($taxes as$tax)if($tax->tax_deleted||!(int)$tax->use_for_fiscal||!(int)$tax->is_fiscal_ready)$result['errors'][]='El impuesto '.($tax->title?:('#'.$tax->tax_id)).' no está fiscalmente listo.';

        $catalog=new Sat_tax_object_codes_model();$stored=$configuration->tax_object_code_id?$catalog->get_one($configuration->tax_object_code_id):null;
        $advanced=$stored&&in_array((string)$stored->code,['03','04'],true);
        $taxObject=$advanced?$stored:$catalog->get_one_where(['code'=>$taxes?'02':'01']);
        if(!$taxObject||!$taxObject->id||!(int)$taxObject->is_active)$result['errors'][]='No fue posible determinar un Objeto de impuesto activo.';
        else{
            $result['tax_object_code']=$taxObject->code;$result['tax_object_description']=$taxObject->description;
            $result['information'][]='Objeto de impuesto determinado automáticamente: '.$taxObject->code.' - '.$taxObject->description.'.';
            if($advanced)$result['warnings'][]='La configuración conserva ObjetoImp '.$taxObject->code.' como override avanzado; no fue sobrescrito.';
        }

        $labels=['item_type'=>'tipo producto/servicio','sat_product_service_key_id'=>'Clave de producto o servicio SAT','sat_unit_key_id'=>'Clave de unidad SAT','administrative_description'=>'descripción administrativa del producto'];
        foreach($result['missing_fields']as$field)$result['errors'][]='Falta '.$labels[$field].'.';
        $result['is_ready']=$result['errors']===[];$result['status']=$result['is_ready']?'ready':'incomplete';
        return$result;
    }
}
