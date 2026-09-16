<?php
namespace App\Controllers\Fiscal;

use App\Controllers\Security_Controller;
use App\Models\Fiscal\Fiscal_profiles_model;
use App\Models\Fiscal\Fiscal_series_model;
use App\Services\Fiscal\FiscalFolioService;

class Series extends Security_Controller
{
    private Fiscal_series_model $series;
    public function __construct(){parent::__construct();$this->series=new Fiscal_series_model();}
    private function allowed(bool$manage=false):bool{if($this->login_user->is_admin)return true;$p=$this->login_user->permissions;if(!is_array($p))$p=@unserialize((string)$p)?:[];return(bool)get_array_value($p,$manage?'fiscal_series_manage':'fiscal_series_view');}
    private function guard(bool$manage=false):void{if(!$this->allowed($manage))app_redirect('forbidden');}
    public function index(){$this->guard();return$this->template->rander('fiscal/series/index',['can_manage'=>$this->allowed(true)]);}
    public function list_data():void{$this->guard();$profiles=[];foreach((new Fiscal_profiles_model())->issuers()->getResult()as$p)$profiles[$p->id]=$p->legal_name;$rows=[];foreach($this->series->get_all_where(['deleted'=>0])->getResult()as$s){$preview=$s->is_active?(new FiscalFolioService())->previewNextFolio((int)$s->id):null;$rows[]=[$profiles[$s->issuer_profile_id]??'-',app_lang('fiscal_document_type_'.$s->document_type),$s->series===''?app_lang('without_series'):$s->series,$preview?$preview['folio']:'-', $s->is_default?app_lang('yes'):app_lang('no'),$s->is_active?app_lang('active'):app_lang('inactive'),$this->allowed(true)?modal_anchor(get_uri('fiscal/series/form'),'<i data-feather="edit" class="icon-16"></i>',['data-post-id'=>$s->id,'title'=>app_lang('edit')]):''];}echo json_encode(['data'=>$rows]);}
    public function form(){$this->guard(true);$id=(int)$this->request->getPost('id');$issuers=[''=>'-'];foreach((new Fiscal_profiles_model())->activeIssuers()->getResult()as$p)$issuers[$p->id]=$p->legal_name.' · '.$p->rfc;return$this->template->view('fiscal/series/modal_form',['model_info'=>$this->series->get_one($id),'issuers'=>$issuers]);}
    public function save():void{$this->guard(true);$id=(int)$this->request->getPost('id');$issuer=(int)$this->request->getPost('issuer_profile_id');$type=(string)$this->request->getPost('document_type');if(!in_array($type,['ingreso','egreso','pago'],true)||$issuer<1){echo json_encode(['success'=>false,'message'=>app_lang('error_occurred')]);return;}$initial=max(1,(int)$this->request->getPost('initial_folio'));$active=$this->request->getPost('is_active')?1:0;$data=['issuer_profile_id'=>$issuer,'document_type'=>$type,'series'=>strtoupper(trim((string)$this->request->getPost('series'))),'initial_folio'=>$initial,'is_default'=>$active&&$this->request->getPost('is_default')?1:0,'is_active'=>$active,'updated_at'=>get_current_utc_time()];if(!$id){$data['current_folio']=$initial-1;$data['created_by']=$this->login_user->id;$data['created_at']=get_current_utc_time();}else{$old=$this->series->get_one($id);if(!$old->id||$initial<=(int)$old->current_folio)$data['initial_folio']=$old->initial_folio;}$saved=false;try{$saved=$this->series->ci_save($data,$id);}catch(\Throwable$e){log_message('error','Fiscal series save failed: {message}',['message'=>$e->getMessage()]);}$seriesId=$id?:($saved?(int)$saved:0);if($seriesId&&$data['is_default'])$this->series->setDefault($issuer,$type,$seriesId);echo json_encode(['success'=>(bool)$seriesId,'id'=>$seriesId,'message'=>$seriesId?app_lang('record_saved'):app_lang('fiscal_series_duplicate')]);}
    public function deactivate():void{$this->guard(true);$id=(int)$this->request->getPost('id');echo json_encode(['success'=>(bool)$this->series->ci_save(['is_active'=>0,'is_default'=>0,'updated_at'=>get_current_utc_time()],$id)]);}
}
