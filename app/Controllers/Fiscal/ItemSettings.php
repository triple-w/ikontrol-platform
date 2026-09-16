<?php
namespace App\Controllers\Fiscal;

use App\Controllers\Security_Controller;
use App\Models\Fiscal\Item_fiscal_settings_model;
use App\Models\Fiscal\Item_fiscal_taxes_model;
use App\Models\Fiscal\Sat_product_service_keys_model;
use App\Models\Fiscal\Sat_tax_object_codes_model;
use App\Models\Fiscal\Sat_unit_keys_model;
use App\Services\Fiscal\ItemFiscalReadinessService;
use RuntimeException;
use Throwable;

class ItemSettings extends Security_Controller
{
    private function allowed(bool $manage=false):bool{
        if($this->login_user->is_admin)return true;
        $permissions=$this->login_user->permissions;if(!is_array($permissions))$permissions=@unserialize((string)$permissions)?:[];
        return(bool)get_array_value($permissions,$manage?'fiscal_items_manage':'fiscal_items_view');
    }
    private function guard(bool $manage=false):void{if(!$this->allowed($manage))app_redirect('forbidden');}

    public function form(){
        $this->guard();$itemId=(int)$this->request->getPost('item_id');
        try{
            $item=db_connect()->table('items')->where(['id'=>$itemId,'deleted'=>0])->get()->getRow();
            if(!$item){log_message('warning','Fiscal item form rejected missing item id={item_id}',['item_id'=>$itemId]);return$this->response->setStatusCode(404)->setBody('<div class="alert alert-warning">'.app_lang('fiscal_item_not_found').'</div>');}
            $settings=new Item_fiscal_settings_model();$model=$settings->activeForItem($itemId)??(object)['id'=>0,'item_id'=>$itemId];$configurationId=(int)($model->id??0);
            $taxIds=$configurationId?array_map(static fn($tax)=>(int)$tax->tax_id,(new Item_fiscal_taxes_model())->forSetting($configurationId)):[];
            $productLabel=$this->catalogLabel(new Sat_product_service_keys_model(),(int)($model->sat_product_service_key_id??0),'description');
            $unitLabel=$this->catalogLabel(new Sat_unit_keys_model(),(int)($model->sat_unit_key_id??0),'name');
            $taxObject=$this->resolveTaxObject($model,$taxIds);
            $readiness=(new ItemFiscalReadinessService())->evaluate($itemId,$configurationId?:null);
            return$this->template->view('fiscal/items/modal_form',[
                'item_id'=>$itemId,'item_info'=>$item,'model_info'=>$model,'tax_ids'=>$taxIds,'taxes'=>$this->fiscalTaxesDropdown(),
                'product_label'=>$productLabel,'unit_label'=>$unitLabel,'tax_object'=>$taxObject,'readiness'=>$readiness,'can_manage'=>$this->allowed(true)
            ]);
        }catch(Throwable$exception){
            log_message('error','Fiscal item form failed item={item_id}: {type} {message} {file}:{line}',['item_id'=>$itemId,'type'=>$exception::class,'message'=>$exception->getMessage(),'file'=>$exception->getFile(),'line'=>$exception->getLine()]);
            return$this->response->setStatusCode(500)->setBody('<div class="alert alert-danger">'.app_lang('fiscal_item_form_error').'</div>');
        }
    }

    public function save():void{
        $this->guard(true);$db=db_connect();$itemId=(int)$this->request->getPost('item_id');$id=(int)$this->request->getPost('id');
        try{
            $item=$db->table('items')->where(['id'=>$itemId,'deleted'=>0])->get()->getRow();if(!$item)throw new RuntimeException(app_lang('fiscal_item_not_found'));
            $itemType=(string)$this->request->getPost('item_type');if(!in_array($itemType,['product','service'],true))throw new RuntimeException(app_lang('fiscal_item_type_required'));
            $settings=new Item_fiscal_settings_model();$existing=$id?$settings->get_one($id):null;
            if($id&&(!$existing->id||(int)$existing->item_id!==$itemId))throw new RuntimeException(app_lang('error_occurred'));
            $productKeyId=$this->validatedCatalogId('sat_product_service_keys',$this->request->getPost('sat_product_service_key_id'));
            $unitKeyId=$this->validatedCatalogId('sat_unit_keys',$this->request->getPost('sat_unit_key_id'));
            $rawTaxIds=array_values(array_filter(array_map('intval',(array)$this->request->getPost('tax_ids'))));
            if(count($rawTaxIds)!==count(array_unique($rawTaxIds)))throw new RuntimeException(app_lang('fiscal_duplicate_taxes'));
            $taxIds=$this->validatedTaxIds($rawTaxIds);
            $taxObject=$this->resolveTaxObject($existing??(object)[],$taxIds);
            if(!$taxObject||!$taxObject->id)throw new RuntimeException(app_lang('error_occurred'));
            $inactive=$existing&&($existing->status??'')==='inactive';
            $data=['item_id'=>$itemId,'item_type'=>$itemType,'sat_product_service_key_id'=>$productKeyId,'sat_unit_key_id'=>$unitKeyId,'tax_object_code_id'=>$taxObject->id,'is_default'=>1,'status'=>$inactive?'inactive':'incomplete','updated_at'=>get_current_utc_time(),'deleted'=>0];
            if(!$id){$data['created_by']=$this->login_user->id;$data['created_at']=get_current_utc_time();}
            $db->transBegin();$saved=$settings->ci_save($data,$id);if(!$saved)throw new RuntimeException(app_lang('error_occurred'));
            $settings->setDefault($itemId,(int)$saved);(new Item_fiscal_taxes_model())->replaceForSetting((int)$saved,$taxIds);
            $readiness=(new ItemFiscalReadinessService())->evaluate($itemId,(int)$saved);$finalStatus=$inactive?'inactive':$readiness['status'];$statusData=['status'=>$finalStatus];$settings->ci_save($statusData,(int)$saved);
            if($db->transStatus()===false)throw new RuntimeException(app_lang('error_occurred'));$db->transCommit();
            echo json_encode(['success'=>true,'id'=>$saved,'status'=>$finalStatus,'tax_object_code'=>$taxObject->code,'message'=>app_lang('record_saved')]);
        }catch(Throwable$exception){
            if($db->transStatus()!==null)$db->transRollback();log_message('error','Fiscal item save failed item={item_id}: {type} {message} {file}:{line}',['item_id'=>$itemId,'type'=>$exception::class,'message'=>$exception->getMessage(),'file'=>$exception->getFile(),'line'=>$exception->getLine()]);
            echo json_encode(['success'=>false,'message'=>$exception instanceof RuntimeException?$exception->getMessage():app_lang('error_occurred')]);
        }
    }

    public function deactivate():void{$this->changeActiveState(true);}
    public function activate():void{$this->changeActiveState(false);}
    private function changeActiveState(bool$inactive):void{
        $this->guard(true);$itemId=(int)$this->request->getPost('item_id');$configuration=(new Item_fiscal_settings_model())->activeForItem($itemId);
        if(!$configuration||!$configuration->id){echo json_encode(['success'=>false,'message'=>app_lang('fiscal_item_not_found')]);return;}
        $data=['status'=>$inactive?'inactive':'incomplete','updated_at'=>get_current_utc_time()];$saved=(new Item_fiscal_settings_model())->ci_save($data,(int)$configuration->id);
        if($saved&&!$inactive){$check=(new ItemFiscalReadinessService())->evaluate($itemId,(int)$configuration->id);$statusData=['status'=>$check['status']];(new Item_fiscal_settings_model())->ci_save($statusData,(int)$configuration->id);}
        echo json_encode(['success'=>(bool)$saved,'message'=>$saved?app_lang('record_saved'):app_lang('error_occurred')]);
    }

    public function readiness($itemId=0):void{$this->guard();validate_numeric_value($itemId);echo json_encode((new ItemFiscalReadinessService())->evaluate((int)$itemId));}
    public function search_products():void{$this->guard();echo json_encode((new Sat_product_service_keys_model())->search(trim((string)$this->request->getPost('term')),(int)$this->request->getPost('page'),(int)($this->request->getPost('limit')?:20)));}
    public function search_units():void{$this->guard();echo json_encode((new Sat_unit_keys_model())->search(trim((string)$this->request->getPost('term')),(int)$this->request->getPost('page'),(int)($this->request->getPost('limit')?:20)));}

    private function catalogLabel(object$model,int$id,string$descriptionField):string{if(!$id)return'';$row=$model->get_one($id);return$row->id?$row->code.' - '.$row->$descriptionField:'';}
    private function validatedCatalogId(string$table,$raw):?int{$id=(int)$raw;if(!$id)return null;$row=db_connect()->table($table)->select('id')->where(['id'=>$id,'is_active'=>1])->get()->getRow();if(!$row)throw new RuntimeException(app_lang('fiscal_catalog_key_inactive'));return$id;}
    private function validatedTaxIds(array$ids):array{if(!$ids)return[];$rows=db_connect()->table('taxes')->select('id')->whereIn('id',$ids)->where(['deleted'=>0,'use_for_fiscal'=>1,'is_fiscal_ready'=>1])->get()->getResultArray();$valid=array_map('intval',array_column($rows,'id'));if(count($valid)!==count($ids))throw new RuntimeException(app_lang('fiscal_tax_not_ready'));return$ids;}
    private function resolveTaxObject(object$current,array$taxIds):?object{
        $catalog=new Sat_tax_object_codes_model();$stored=!empty($current->tax_object_code_id)?$catalog->get_one($current->tax_object_code_id):null;
        if($stored&&in_array((string)$stored->code,['03','04'],true))return$stored;
        return$catalog->get_one_where(['code'=>$taxIds?'02':'01']);
    }
    private function fiscalTaxesDropdown():array{
        $rows=db_connect()->table('taxes t')->select('t.id,t.title,t.fiscal_tax_type,t.xml_rate,t.xml_quota,c.code AS sat_code,f.code AS factor_code')->join('sat_tax_codes c','c.id=t.sat_tax_code_id','left')->join('sat_tax_factor_types f','f.id=t.factor_type_id','left')->where(['t.deleted'=>0,'t.use_for_fiscal'=>1,'t.is_fiscal_ready'=>1])->orderBy('t.title')->get()->getResult();$options=[];
        foreach($rows as$row){$type=$row->fiscal_tax_type==='withholding'?app_lang('withholding'):app_lang('transfer');$value=$row->xml_quota?:$row->xml_rate;$options[$row->id]=trim($row->title.' | '.$row->sat_code.' | '.$type.' | '.$row->factor_code.($value!==null?' | '.$value:''));}return$options;
    }
}
