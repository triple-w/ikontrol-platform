<?php
declare(strict_types=1);
namespace App\Controllers\Fiscal;

use App\Controllers\Security_Controller;
use App\Models\Fiscal\Fiscal_profiles_model;
use App\Models\Fiscal\Fiscal_series_model;
use App\Services\Fiscal\FiscalFolioService;
use App\Services\Fiscal\FiscalRuntimeContext;
use App\Services\Fiscal\FiscalSeriesIssuerValidator;
use Throwable;

/** Environment-safe series administration; legacy rows remain read-only. */
final class SeriesEnvironment extends Security_Controller
{
    private Fiscal_series_model $series;
    public function __construct(){parent::__construct();$this->series=new Fiscal_series_model();}
    public function index(){ $this->guard();return$this->template->rander('fiscal/series_environment/index',['can_manage'=>$this->allowed(true)]); }
    public function list_data():void{
        $this->guard();$profiles=[];foreach((new Fiscal_profiles_model())->issuers()->getResult()as$p)$profiles[$p->id]=$p->legal_name;
        $active=FiscalRuntimeContext::fiscalEnvironment(config('Fiscal'));$rows=[];
        foreach($this->series->get_all_where(['deleted'=>0])->getResult()as$s){$usable=(string)$s->environment===$active;$preview=$usable&&$s->is_active?(new FiscalFolioService())->previewNextFolio((int)$s->id):null;
            $rows[]=[
                $profiles[$s->issuer_profile_id]??'-',app_lang('fiscal_document_type_'.$s->document_type),$s->series===''?app_lang('without_series'):$s->series,
                $usable?'<span class="badge bg-success">'.esc($s->environment).'</span>':'<span class="badge bg-warning">'.esc((string)$s->environment).' · requiere revisión</span>',
                $preview?$preview['folio']:'-', $s->is_default?app_lang('yes'):app_lang('no'),$s->is_active?app_lang('active'):app_lang('inactive'),
                $this->allowed(true)?modal_anchor(get_uri('fiscal/series/form'),'<i data-feather="edit" class="icon-16"></i>',['data-post-id'=>$s->id,'title'=>app_lang('edit')]):'',
            ];
        }echo json_encode(['data'=>$rows]);
    }
    public function form(){
        $this->guard(true);$id=(int)$this->request->getPost('id');$issuers=[''=>'-'];$rejections=[];$validator=new FiscalSeriesIssuerValidator();$companyId=$this->activeCompanyId();$environment=FiscalRuntimeContext::fiscalEnvironment(config('Fiscal'));
        foreach((new Fiscal_profiles_model())->issuers()->getResult()as$p){$result=$validator->validate((int)$p->id,$companyId,$environment);if($result['valid'])$issuers[$p->id]=$p->legal_name.' · '.$p->rfc;else$rejections[]=($p->legal_name?:'Emisor #'.$p->id).': '.implode(' ',$result['errors']);}
        return$this->template->view('fiscal/series_environment/form',['model_info'=>$this->series->get_one($id),'issuers'=>$issuers,'issuer_required'=>count($issuers)===1,'issuer_rejections'=>$rejections,'active_environment'=>$environment]);
    }
    public function save():void{
        $this->guard(true);$id=(int)$this->request->getPost('id');$issuer=(int)$this->request->getPost('issuer_profile_id');$type=(string)$this->request->getPost('document_type');$environment=FiscalRuntimeContext::fiscalEnvironment(config('Fiscal'));
        $issuerValidation=(new FiscalSeriesIssuerValidator())->validate($issuer,$this->activeCompanyId(),$environment);if(!$issuerValidation['valid']){echo json_encode(['success'=>false,'code'=>'FISCAL_ISSUER_REQUIRED','message'=>$issuerValidation['message'],'errors'=>$issuerValidation['errors'],'action'=>'Configurar emisor','configuration_url'=>get_uri($issuerValidation['configuration_path'])]);return;}
        if(!in_array($type,['ingreso','egreso','pago'],true)){echo json_encode(['success'=>false,'code'=>'FISCAL_SERIES_TYPE_INVALID','message'=>'Selecciona un tipo de documento fiscal válido.']);return;}
        $initial=max(1,(int)$this->request->getPost('initial_folio'));$active=$this->request->getPost('is_active')?1:0;
        $data=['issuer_profile_id'=>$issuer,'document_type'=>$type,'series'=>strtoupper(trim((string)$this->request->getPost('series'))),'initial_folio'=>$initial,'is_default'=>$active&&$this->request->getPost('is_default')?1:0,'is_active'=>$active,'updated_at'=>get_current_utc_time()];
        if(!$id)$data+=['environment'=>$environment,'current_folio'=>$initial-1,'created_by'=>$this->login_user->id,'created_at'=>get_current_utc_time()];
        else{$old=$this->series->get_one($id);if(!$old->id||(string)$old->environment!==$environment){echo json_encode(['success'=>false,'code'=>'FISCAL_SERIES_ENVIRONMENT_REVIEW','message'=>'La serie pertenece a '.((string)($old->environment??'legacy')).' y requiere revisión dirigida; no se modificó.']);return;}if($initial<=(int)$old->current_folio)$data['initial_folio']=$old->initial_folio;}
        try{$saved=$this->series->ci_save($data,$id);}catch(Throwable$e){log_message('error','Fiscal series save failed: {message}',['message'=>$e->getMessage()]);$saved=false;}
        $seriesId=$id?:($saved?(int)$saved:0);if($seriesId&&$data['is_default'])$this->series->setDefault($issuer,$type,$seriesId,$environment);
        echo json_encode(['success'=>(bool)$seriesId,'id'=>$seriesId,'message'=>$seriesId?app_lang('record_saved'):app_lang('fiscal_series_duplicate')]);
    }
    public function deactivate():void{$this->guard(true);$id=(int)$this->request->getPost('id');$row=$this->series->get_one($id);$environment=FiscalRuntimeContext::fiscalEnvironment(config('Fiscal'));if(!$row->id||(string)$row->environment!==$environment){echo json_encode(['success'=>false,'message'=>'La serie requiere revisión dirigida y no se modificó.']);return;}echo json_encode(['success'=>(bool)$this->series->ci_save(['is_active'=>0,'is_default'=>0,'updated_at'=>get_current_utc_time()],$id)]);}
    private function allowed(bool$manage=false):bool{if($this->login_user->is_admin)return true;$p=is_array($this->login_user->permissions)?$this->login_user->permissions:(@unserialize((string)$this->login_user->permissions)?:[]);return(bool)get_array_value($p,$manage?'fiscal_series_manage':'fiscal_series_view');}
    private function guard(bool$manage=false):void{if(!$this->allowed($manage))app_redirect('forbidden');}
    private function activeCompanyId():?int{try{$id=(int)get_default_company_id();return$id>0?$id:null;}catch(Throwable){return null;}}
}
