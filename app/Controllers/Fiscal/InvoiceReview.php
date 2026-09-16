<?php
namespace App\Controllers\Fiscal;
use App\Controllers\Security_Controller;
use App\Models\Fiscal\Fiscal_profiles_model;
use App\Models\Fiscal\Fiscal_series_model;
use App\Models\Fiscal\Fiscal_documents_model;
use App\Services\Fiscal\SaleFiscalReadinessService;
use App\Services\Fiscal\SaleTaxAdjustmentService;
use App\Services\Fiscal\SaleTaxPricingSimulationService;
use App\Services\Fiscal\FiscalDraftCreationService;
use App\Services\Fiscal\FiscalDecimalCalculator;
use App\Services\Fiscal\CfdiPaymentRuleService;
use App\Services\Fiscal\Cfdi40\CfdiPreXmlArtifactService;
use App\Services\Fiscal\Cfdi40\CfdiXsdValidator;
use App\Services\Fiscal\Cfdi40\CfdiSigningService;
use App\Services\Fiscal\FiscalArtifactStorageService;
use App\Models\Fiscal\Fiscal_document_artifacts_model;
use App\Services\Fiscal\Pac\FiscalDocumentStatusPresenter;
use App\Services\Fiscal\Pac\FiscalPacAdapterFactory;
use App\Domain\Fiscal\Signing\CsdSecretException;
use App\Services\Fiscal\Signing\CsdOperationalStatusService;
use App\Services\Fiscal\FiscalInvoiceGenerationService;

class InvoiceReview extends Security_Controller
{
 private function permissions():array{$p=$this->login_user->permissions;if(!is_array($p))$p=@unserialize((string)$p)?:[];return$p;}
 private function allowed(string$key):bool{return$this->login_user->is_admin||(bool)get_array_value($this->permissions(),$key);}
 private function guard(int$invoiceId,string$key='fiscal_sales_review'):void{$granted=$key==='fiscal_sales_review'?($this->allowed('fiscal_sales_review')||$this->allowed('fiscal_sales_pricing_review')||$this->allowed('fiscal_drafts_view')):$this->allowed($key);if(!$granted||!$this->can_view_invoices($invoiceId))app_redirect('forbidden');}
 public function show($invoiceId=0){
  validate_numeric_value($invoiceId);$this->guard((int)$invoiceId);$db=db_connect();
  $issuerId=(int)($this->request->getPost('issuer_profile_id')?:$this->request->getGet('issuer_profile_id'));
  $seriesId=(int)($this->request->getPost('series_id')?:$this->request->getGet('series_id'));
  $receiverId=(int)($this->request->getPost('receiver_profile_id')?:$this->request->getGet('receiver_profile_id'));
  $review=(new SaleFiscalReadinessService())->review((int)$invoiceId,$issuerId?:null,$seriesId?:null,$receiverId?:null);
  $profiles=new Fiscal_profiles_model();$issuers=[];foreach($profiles->activeIssuers()->getResult()as$p)$issuers[$p->id]=$p->legal_name.' · '.$p->rfc;
  $invoice=$db->table('invoices')->where('id',$invoiceId)->get(1)->getRow();$receivers=[];foreach($profiles->forClient((int)$invoice->client_id)->getResult()as$p)if($p->status!=='inactive')$receivers[$p->id]=$p->legal_name.' · '.$p->rfc;
  $series=[];if($review['issuer']['issuer_profile_id'])foreach((new Fiscal_series_model())->activeForIssuer((int)$review['issuer']['issuer_profile_id'],'ingreso')->getResult()as$s)$series[$s->id]=($s->series?:app_lang('without_series')).' · '.app_lang('next_folio').' '.max((int)$s->initial_folio,(int)$s->current_folio+1);
  $simulation=null;$simulationError=null;$issuer=$review['issuer']['profile']??null;$override=(string)$this->request->getPost('pricing_mode_override');$canOverride=$this->allowed('fiscal_sales_pricing_override')&&$issuer&&$issuer->allow_sale_tax_pricing_override;if(!$canOverride)$override='';
  if($issuer&&$review['issuer']['is_ready'])try{$simulation=(new SaleTaxPricingSimulationService())->simulate((int)$invoiceId,(int)$issuer->id,$review['receiver']['profile_id']?:null,$review['series']['series_id']?:null,$override?:null,(int)$this->login_user->id,true);}catch(\Throwable$e){$simulationError=$e->getMessage();}
  $dropdown=function(string$table)use($db):array{$out=[];foreach($db->table($table)->where('is_active',1)->orderBy('code')->get()->getResult()as$r)$out[$r->code]=$r->code.' · '.$r->name;return$out;};
  $paymentSuggestion=(new CfdiPaymentRuleService($db))->suggest((int)$invoiceId);
  $csdSummary=['ready'=>false,'label'=>app_lang('csd_certificate_not_ready')];
  if($issuer){$certificate=$db->table('fiscal_issuer_certificates')->where(['issuer_profile_id'=>$issuer->id,'status'=>'valid','deleted'=>0])->orderBy('is_default','DESC')->get(1)->getRow();if($certificate)$csdSummary=(new CsdOperationalStatusService($db))->forCertificate($certificate);}
  return$this->template->view('fiscal/invoices/review',['review'=>$review,'issuers'=>$issuers,'receivers'=>$receivers,'series_options'=>$series,'simulation'=>$simulation,'simulation_error'=>$simulationError,'can_override'=>$canOverride,'can_apply'=>$this->allowed('fiscal_sales_pricing_apply'),'can_create_draft'=>$this->allowed('fiscal_drafts_create'),'can_generate'=>$this->allowed('fiscal_stamp_sandbox'),'can_view_drafts'=>$this->allowed('fiscal_drafts_view'),'payment_forms'=>$dropdown('sat_payment_forms'),'payment_methods'=>$dropdown('sat_payment_methods'),'payment_suggestion'=>$paymentSuggestion,'currencies'=>$dropdown('sat_currencies'),'drafts'=>(new Fiscal_documents_model())->forInvoice((int)$invoiceId)->getResult(),'csd_summary'=>$csdSummary,'pac_status'=>$this->pacStatusForViewer(),'can_advanced'=>$this->login_user->is_admin]);
 }
 public function apply():void{$invoiceId=(int)$this->request->getPost('invoice_id');$this->guard($invoiceId,'fiscal_sales_pricing_apply');try{$result=(new SaleTaxAdjustmentService())->confirmAndApply((int)$this->request->getPost('preparation_id'),(int)$this->login_user->id,(bool)$this->request->getPost('confirm_adjustment'));echo json_encode(['success'=>true,'message'=>app_lang('fiscal_sale_adjustment_applied'),'data'=>$result]);}catch(\Throwable$e){log_message('warning','Fiscal sale adjustment rejected: {message}',['message'=>$e->getMessage()]);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}}
 public function create_draft():void{
  $invoiceId=(int)$this->request->getPost('invoice_id');$this->guard($invoiceId,'fiscal_drafts_create');if($this->request->getPost('confirm_supersede'))$this->guard($invoiceId,'fiscal_drafts_supersede');
  try{$result=(new FiscalDraftCreationService())->create($invoiceId,(int)$this->request->getPost('issuer_profile_id'),(int)$this->request->getPost('receiver_profile_id'),(int)$this->request->getPost('series_id'),(int)$this->request->getPost('preparation_id'),['payment_form_code'=>$this->request->getPost('payment_form_code'),'payment_method_code'=>$this->request->getPost('payment_method_code'),'currency_code'=>$this->request->getPost('currency_code'),'exchange_rate'=>$this->request->getPost('exchange_rate'),'export_code'=>'01'],(int)$this->login_user->id,true,(bool)$this->request->getPost('confirm_supersede'));echo json_encode(['success'=>true,'message'=>$result['action']==='existing'?app_lang('fiscal_draft_already_exists'):app_lang('fiscal_draft_created'),'data'=>$result]);}catch(\Throwable$e){log_message('warning','Fiscal draft creation rejected: {message}',['message'=>$e->getMessage()]);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
 }
public function generate($routeInvoiceId=0):void{
 $invoiceId=(int)$routeInvoiceId;$postedInvoiceId=(int)$this->request->getPost('invoice_id');if($invoiceId<1||$postedInvoiceId!==$invoiceId){$this->response->setStatusCode(400);echo json_encode(['success'=>false,'message'=>app_lang('invalid_request'),'csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()]]);return;}$this->guard($invoiceId,'fiscal_stamp_sandbox');
  $result=(new FiscalInvoiceGenerationService())->generate($invoiceId,[
   'issuer_profile_id'=>$this->request->getPost('issuer_profile_id'),
   'receiver_profile_id'=>$this->request->getPost('receiver_profile_id'),
   'series_id'=>$this->request->getPost('series_id'),
   'preparation_id'=>$this->request->getPost('preparation_id'),
   'payment_form_code'=>$this->request->getPost('payment_form_code'),
   'payment_method_code'=>$this->request->getPost('payment_method_code'),
   'currency_code'=>$this->request->getPost('currency_code'),
   'exchange_rate'=>$this->request->getPost('exchange_rate'),
   'confirm_new_version'=>$this->request->getPost('confirm_new_version'),
  ],(int)$this->login_user->id,true);
  $payload=$result->toArray();$payload['csrf']=['name'=>csrf_token(),'hash'=>csrf_hash()];
  echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 }
 public function draft($id=0){
  $documentId=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if(!$documentId)return$this->draftError(app_lang('fiscal_draft_not_found'),400,'Invalid fiscal draft identifier.');
  try{
   $data=(new Fiscal_documents_model())->complete((int)$documentId);
   if(!$data){
    log_message('warning','Fiscal draft viewer requested a missing document id: {id}.',['id'=>$documentId]);
    return$this->draftError(app_lang('fiscal_draft_not_found'),404,'Fiscal draft not found.');
   }
   if(!$this->allowed('fiscal_drafts_view')||!$this->can_view_invoices((int)$data['document']->invoice_id))return$this->draftError(app_lang('fiscal_access_denied'),403,'Fiscal draft access denied.');
   $db=db_connect();
   db_connect()->table('fiscal_document_audit')->insert(['fiscal_document_id'=>$documentId,'invoice_id'=>$data['document']->invoice_id,'user_id'=>$this->login_user->id,'action'=>'viewed','created_at'=>get_current_utc_time()]);
   $currentInvoice=$db->table('invoices')->where('id',$data['document']->invoice_id)->get(1)->getRow();
   $payment=$db->table('invoice_payments')->selectSum('amount','total')->where(['invoice_id'=>$data['document']->invoice_id,'deleted'=>0])->get()->getRow();
   $decimal=new FiscalDecimalCalculator();
   $sourceChanged=$decimal->money((string)($currentInvoice->invoice_total??0))!==$decimal->money((string)$data['document']->administrative_total_reference)||$decimal->money((string)($payment->total??0))!==$decimal->money((string)($data['metadata']->payment_total_snapshot??0));
   $artifact=(new Fiscal_document_artifacts_model())->active((int)$documentId);
   $certificates=[];$csdStatusService=new CsdOperationalStatusService($db);foreach($db->table('fiscal_issuer_certificates')->where(['issuer_profile_id'=>$data['document']->issuer_profile_id,'status'=>'valid','deleted'=>0])->orderBy('is_default','DESC')->get()->getResult()as$c){$csdStatus=$csdStatusService->forCertificate($c);$certificates[$c->id]=$c->certificate_number.' · '.$c->valid_to.' · '.$csdStatus['label'];}
   $signature=$db->table('fiscal_document_signatures')->where('fiscal_document_id',$documentId)->orderBy('id','DESC')->get(1)->getRow();
   $signedCertificate=$signature?$db->table('fiscal_issuer_certificates')->where(['id'=>$signature->certificate_id,'deleted'=>0])->get(1)->getRow():null;
   $pac=$this->pacStatusForViewer();
   $stamp=$db->table('fiscal_document_stamps')->where('fiscal_document_id',$documentId)->get(1)->getRow();
   $attempt=$db->table('fiscal_stamp_attempts')->where('fiscal_document_id',$documentId)->orderBy('id','DESC')->get(1)->getRow();
   $statusView=(new FiscalDocumentStatusPresenter($db))->forDocument((int)$documentId);
   return$this->template->view('fiscal/invoices/draft',$data+['can_lock'=>$this->allowed('fiscal_drafts_lock'),'can_cancel'=>$this->allowed('fiscal_drafts_cancel'),'source_changed'=>$sourceChanged,'artifact'=>$artifact,'certificates'=>$certificates,'signature'=>$signature,'signed_certificate'=>$signedCertificate,'can_sign'=>$this->allowed('fiscal_xml_sign'),'can_signed_view'=>$this->allowed('fiscal_signed_xml_view'),'can_xml_generate'=>$this->allowed('fiscal_xml_preview_generate'),'can_xml_view'=>$this->allowed('fiscal_xml_preview_view'),'can_xml_download'=>$this->allowed('fiscal_xml_preview_download'),'can_xml_validate'=>$this->allowed('fiscal_xml_preview_validate'),'pac_status'=>$pac,'stamp'=>$stamp,'stamp_attempt'=>$attempt,'fiscal_status_view'=>$statusView,'can_stamp_sandbox'=>$this->allowed('fiscal_stamp_sandbox'),'can_stamp_status'=>$this->allowed('fiscal_stamp_status'),'can_advanced'=>$this->login_user->is_admin]);
  }catch(\Throwable$e){
   log_message('error','Fiscal draft viewer failed for document {id}: {message}',['id'=>$documentId,'message'=>$e->getMessage()]);
   return$this->draftError(app_lang('something_went_wrong'),500,'Fiscal draft viewer failed.');
  }
 }
 private function draftError(string$message,int$status,string$logMessage){
  log_message('warning',$logMessage);
  return$this->response->setStatusCode($status)->setBody('<div class="modal-body"><div class="alert alert-danger">'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</div></div><div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">'.htmlspecialchars(app_lang('close'),ENT_QUOTES,'UTF-8').'</button></div>');
 }
 private function pacStatusForViewer():object{
  try{$fiscal=config('Fiscal');$provider=config('TimbradorXpress');(new FiscalPacAdapterFactory($fiscal,$provider))->create();return(object)['provider'=>$fiscal->pacAdapter,'environment'=>$fiscal->environment,'configured'=>true,'production_enabled'=>false,'configuration_error'=>null];}
  catch(\Throwable$e){log_message('warning','PAC status unavailable while rendering fiscal document: {type}',['type'=>get_class($e)]);return(object)['provider'=>'unavailable','environment'=>'local','configured'=>false,'production_enabled'=>false,'configuration_error'=>app_lang('pac_not_configured')];}
 }
 public function draft_action():void{
  $id=(int)$this->request->getPost('document_id');$row=db_connect()->table('fiscal_documents')->where('id',$id)->get(1)->getRow();if(!$row){echo json_encode(['success'=>false,'message'=>app_lang('fiscal_draft_not_found')]);return;}
  $action=(string)$this->request->getPost('action');$permission=$action==='lock'?'fiscal_drafts_lock':'fiscal_drafts_cancel';$this->guard((int)$row->invoice_id,$permission);
  try{$result=(new FiscalDraftCreationService())->changeStatus($id,$action,(int)$this->login_user->id,true,(string)$this->request->getPost('reason'));echo json_encode(['success'=>true,'message'=>app_lang('fiscal_draft_action_completed'),'data'=>$result]);}catch(\Throwable$e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
 }
 public function generate_prexml():void{$id=(int)$this->request->getPost('document_id');$this->guardDocument($id,'fiscal_xml_preview_generate');try{$r=(new CfdiPreXmlArtifactService())->generate($id,(int)$this->login_user->id,true);echo json_encode(['success'=>true,'message'=>$r['action']==='existing'?app_lang('prexml_already_exists'):app_lang('prexml_generated'),'data'=>['artifact_id'=>$r['artifact']->id,'sha256'=>$r['artifact']->sha256]]);}catch(\Throwable$e){log_message('warning','Pre-XML generation rejected: {message}',['message'=>$e->getMessage()]);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}}
 public function view_prexml($artifactId=0){validate_numeric_value($artifactId);$a=db_connect()->table('fiscal_document_artifacts')->where('id',$artifactId)->get(1)->getRow();if(!$a)app_redirect('not_found');$this->guardDocument((int)$a->fiscal_document_id,'fiscal_xml_preview_view');$r=(new CfdiPreXmlArtifactService())->read((int)$artifactId,true);$this->auditArtifact($a,'pre_xml_viewed');return$this->template->view('fiscal/invoices/prexml',['artifact'=>$a,'xml'=>$r['xml'],'validation'=>$r['validation'],'can_download'=>$this->allowed('fiscal_xml_preview_download'),'can_validate'=>$this->allowed('fiscal_xml_preview_validate')]);}
 public function download_prexml($artifactId=0){validate_numeric_value($artifactId);$a=db_connect()->table('fiscal_document_artifacts')->where('id',$artifactId)->get(1)->getRow();if(!$a)app_redirect('not_found');$this->guardDocument((int)$a->fiscal_document_id,'fiscal_xml_preview_download');$r=(new CfdiPreXmlArtifactService())->read((int)$artifactId,true);$this->auditArtifact($a,'pre_xml_downloaded');return$this->response->download('prexml-'.$a->fiscal_document_id.'.xml',$r['xml'])->setContentType('application/xml');}
 public function validate_prexml():void{$artifactId=(int)$this->request->getPost('artifact_id');$a=db_connect()->table('fiscal_document_artifacts')->where('id',$artifactId)->get(1)->getRow();if(!$a){echo json_encode(['success'=>false,'message'=>app_lang('prexml_not_found')]);return;}$this->guardDocument((int)$a->fiscal_document_id,'fiscal_xml_preview_validate');try{$r=(new CfdiPreXmlArtifactService())->read($artifactId,true);$xsd=(new CfdiXsdValidator())->validate($r['xml']);$payload=$r['validation'];$payload['xsd']=$xsd;db_connect()->table('fiscal_document_artifacts')->where('id',$artifactId)->update(['validation_status'=>$xsd['status'],'validation_payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$this->auditArtifact($a,'pre_xml_validated');echo json_encode(['success'=>true,'message'=>app_lang('prexml_validated'),'data'=>$xsd]);}catch(\Throwable$e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}}
 public function sign_xml():void{
  $documentId=(int)$this->request->getPost('document_id');$this->guardDocument($documentId,'fiscal_xml_sign');
  try{
   $r=(new CfdiSigningService())->sign($documentId,(int)$this->request->getPost('pre_xml_artifact_id'),(int)$this->request->getPost('certificate_id'),(int)$this->login_user->id,true);
   $artifactId=(int)$r['signature']->signed_xml_artifact_id;
   $data=['fiscal_document_id'=>$documentId,'state'=>'ready_to_stamp','signature_id'=>(int)$r['signature']->id,'signed_xml_artifact_id'=>$artifactId,'view_url'=>url_to('fiscal_invoice_draft_view',$documentId),'signed_xml_url'=>url_to('fiscal_signed_xml_view',$documentId),'signed_xml_download_url'=>url_to('fiscal_signed_xml_download',$documentId),'stamp_url'=>get_uri('fiscal/stamping/stamp')];
   echo json_encode(['success'=>true,'message'=>$r['action']==='existing'?app_lang('signed_xml_already_exists'):app_lang('local_signature_correct'),'data'=>$data]+$data);
  }catch(CsdSecretException$e){
   log_message('warning','Automatic CSD signing blocked for document {id}: {code}',['id'=>$documentId,'code'=>$e->errorCode]);
   echo json_encode(['success'=>false,'stage'=>'csd','code'=>$e->errorCode,'message'=>$e->getMessage(),'action'=>'Configurar certificado','configuration_url'=>get_uri('fiscal/issuers')]);
  }catch(\Throwable$e){
   log_message('warning','Local XML signing rejected for document {id}: {type}',['id'=>$documentId,'type'=>get_class($e)]);
   echo json_encode(['success'=>false,'stage'=>'signing','code'=>'SIGNING_FAILED','message'=>'No fue posible sellar el XML. Revise la configuración del CSD.']);
  }
 }
 public function view_signed_xml($documentId=0){validate_numeric_value($documentId);$this->guardDocument((int)$documentId,'fiscal_signed_xml_view');$a=$this->signedArtifactForDocument((int)$documentId);if(!$a)app_redirect('not_found');$xml=(new FiscalArtifactStorageService())->read($a);return$this->template->view('fiscal/invoices/signed_xml',['artifact'=>$a,'xml'=>$xml]);}
 public function download_signed_xml($documentId=0){validate_numeric_value($documentId);$this->guardDocument((int)$documentId,'fiscal_signed_xml_view');$a=$this->signedArtifactForDocument((int)$documentId);if(!$a)app_redirect('not_found');$xml=(new FiscalArtifactStorageService())->read($a);return$this->response->download('cfdi-sellado-local-'.$documentId.'.xml',$xml)->setContentType('application/xml');}
 private function signedArtifactForDocument(int$documentId):?object{$signature=db_connect()->table('fiscal_document_signatures')->where('fiscal_document_id',$documentId)->orderBy('id','DESC')->get(1)->getRow();if(!$signature)return null;return db_connect()->table('fiscal_document_artifacts')->where(['id'=>$signature->signed_xml_artifact_id,'fiscal_document_id'=>$documentId,'artifact_type'=>'signed_xml'])->get(1)->getRow();}
 private function guardDocument(int$id,string$permission):void{$d=db_connect()->table('fiscal_documents')->where(['id'=>$id,'deleted'=>0])->get(1)->getRow();if(!$d)app_redirect('not_found');$this->guard((int)$d->invoice_id,$permission);}
 private function auditArtifact(object$a,string$action):void{$d=db_connect()->table('fiscal_documents')->where('id',$a->fiscal_document_id)->get(1)->getRow();db_connect()->table('fiscal_document_audit')->insert(['fiscal_document_id'=>$a->fiscal_document_id,'invoice_id'=>$d->invoice_id,'user_id'=>$this->login_user->id,'action'=>$action,'reason'=>json_encode(['artifact_id'=>$a->id,'sha256'=>$a->sha256,'builder_version'=>$a->builder_version]),'new_hash'=>$a->sha256,'created_at'=>get_current_utc_time()]);}
}
