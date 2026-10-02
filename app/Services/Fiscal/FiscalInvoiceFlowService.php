<?php
declare(strict_types=1);
namespace App\Services\Fiscal;

use App\Services\Fiscal\Stamps\FiscalStampAccountService;
use App\Services\Sales\SaleLifecycleService;
use RuntimeException;
use Throwable;

/** Orchestrates the normal one-click invoice flow without exposing draft/PAC internals. */
final class FiscalInvoiceFlowService
{
    public function __construct(private mixed $db=null,private ?FiscalDraftWorkflowService $workflow=null,private ?FiscalDraftStampingPreflightService $preflight=null,private ?FiscalDraftStampingService $stamping=null,private ?FiscalStampAccountService $wallet=null,private ?FiscalSaleAttemptResolver $attempts=null)
    {$this->db??=db_connect();$this->workflow??=new FiscalDraftWorkflowService($this->db);$this->preflight??=new FiscalDraftStampingPreflightService($this->db);$this->stamping??=new FiscalDraftStampingService($this->db);$this->wallet??=new FiscalStampAccountService($this->db);$this->attempts??=new FiscalSaleAttemptResolver($this->db);}

    public function inspect(int $draftId,bool$allowOpenSale=false):array
    {
        try{$snapshot=(new FiscalDraftSnapshotService($this->db))->getCompleteFiscalSnapshot($draftId);}catch(Throwable){return$this->blocked('La información fiscal debe actualizarse antes de facturar.');}
        $draft=$snapshot['draft'];$concepts=array_map(static fn(array$item):array=>['quantity'=>$item['quantity'],'total'=>$item['total'],'snapshot'=>$item['snapshot']],$snapshot['items']);
        $validation=(new FiscalDraftValidationService($this->db))->validate($draft,$snapshot['allocations'],$concepts);$errors=$validation['errors'];
        foreach($snapshot['allocations']as$allocation){$sale=$this->db->table('invoices')->select('commercial_status,status,deleted')->where('id',(int)$allocation['sale_id'])->get(1)->getRow();$allowedStatus=$allowOpenSale?['draft','open','closed']:['closed'];if(!$sale||(int)$sale->deleted===1||!in_array((string)$sale->commercial_status,$allowedStatus,true)||$sale->status==='cancelled'){$errors[]=['field'=>'sale','section'=>'sales','code'=>'SALE_NOT_CLOSED','message'=>'La venta debe estar disponible para facturación.'];break;}}
        $documentId=(int)($draft['fiscal_document_id']??0);if($documentId&&$this->hasActiveAttempt($documentId))return$this->unknown($draftId,$documentId,'Estamos verificando el resultado de la factura. No vuelva a facturar este documento.');
        if($documentId&&$this->hasRejectedAttempt($documentId))$errors[]=['field'=>'document','section'=>'document','code'=>'PREPARED_DOCUMENT_REBUILD_REQUIRED','message'=>'La preparacion fiscal rechazada debe regenerarse antes de volver a intentar.'];
        $environment=FiscalRuntimeContext::fiscalEnvironment(config('Fiscal'));$issuerId=(int)($draft['issuer_id']??0);$balance=$issuerId?$this->wallet->getBalance($issuerId,$environment):['available'=>0,'reserved'=>0];if((int)$balance['available']<1)$errors[]=['field'=>'wallet','section'=>'issuer','code'=>'STAMP_BALANCE_EMPTY','message'=>'No hay timbres disponibles para generar esta factura.'];
        return['ready'=>!$errors,'status'=>$errors?'review_needed':'ready','blockers'=>array_map([$this,'actionable'],$errors),'summary'=>$this->summary($snapshot,$balance),'draft_id'=>$draftId,'document_id'=>$documentId?:null];
    }

    public function execute(int $draftId, int $userId, bool $authorized): array
    {
        return $this->executeInternal($draftId, $userId, $authorized, false);
    }

    private function executeInternal(int $draftId, int $userId, bool $authorized, bool $saleFlow): array
    {
        if(!$authorized)return$this->blocked('No tiene permiso para generar facturas.')+['success'=>false];$lock='fiscal_invoice_flow_'.$draftId;$acquired=(int)($this->db->query('SELECT GET_LOCK(?, 0) acquired',[$lock])->getRow()->acquired??0);if($acquired!==1)return['success'=>false,'status'=>'processing','retry_allowed'=>false,'message'=>'La factura ya está siendo procesada.'];
        $documentId=null;
        try{(new FiscalPreparedDocumentLifecycleService($this->db))->invalidateIfSnapshotChanged($draftId,$userId);$inspection=$this->inspect($draftId,$saleFlow);if(!$inspection['ready'])return$inspection+['success'=>false,'keep_modal_open'=>true];$draft=$this->db->table('fiscal_drafts')->where('id',$draftId)->get(1)->getRow();if(!$draft)throw new RuntimeException('La revisión fiscal no existe.');if($draft->status!=='ready')$this->workflow->markReady($draftId,$userId);$prepared=(int)($draft->fiscal_document_id??0)>0;$preflight=$saleFlow?$this->preflight->inspectForSaleFlow($draftId,$prepared):$this->preflight->inspect($draftId,$prepared);if(!$preflight['allowed'])return$this->blockers($preflight['errors']);
            $result=$saleFlow?$this->stamping->stampSaleFlow($draftId,$userId,true):$this->stamping->stamp($draftId,$userId,true);$stamp=$result['result'];$documentId=(int)$result['document_id'];if(!empty($stamp['requiresReconciliation']))return$this->unknown($draftId,$documentId,'Estamos verificando el resultado de la factura. No vuelva a facturar este documento.')+['success'=>false,'attempt_id'=>$stamp['attemptId']??null,'http_status'=>$stamp['httpStatus']??null,'provider_code'=>$stamp['providerCode']??null];
            if(empty($stamp['xmlAvailable'])||empty($stamp['uuid'])){$providerMessage=$this->sanitizeProviderMessage((string)($stamp['providerMessage']??''));$providerCode=$this->sanitize((string)($stamp['providerCode']??''));$rejected=($stamp['status']??'')==='rejected';return$this->withXmlInspection(['success'=>false,'status'=>'correctable_error','category'=>$rejected?'provider_rejected':'validation','keep_modal_open'=>true,'retry_allowed'=>$rejected,'message'=>$rejected?'No fue posible timbrar la factura.':($providerMessage?:'No fue posible generar la factura.'),'provider_code'=>$providerCode,'provider_message'=>$providerMessage,'blockers'=>$rejected?array_values(array_filter([['message'=>$providerCode!==''?'Código: '.$providerCode:'','action'=>'Corregir revisión'],['message'=>$providerMessage!==''?'Mensaje: '.$providerMessage:'','action'=>'Corregir revisión']],fn($b)=>$b['message']!=='')):[],'actions'=>[['type'=>'review','label'=>'Revisar datos fiscales']],'technical_reference'=>['document_id'=>$documentId,'attempt_id'=>$stamp['attemptId']??null],'document_id'=>$documentId,'attempt_id'=>$stamp['attemptId']??null],$draftId,$documentId);}
            $persisted=$this->db->table('fiscal_document_stamps')->where('fiscal_document_id',$documentId)->get(1)->getRow();$pdfAvailable=(int)($persisted->pac_pdf_artifact_id??0)>0&&($persisted->pdf_status??'')==='valid';$finalStatus=(string)($stamp['status']??($pdfAvailable?'stamped':'stamped_pdf_pending'));$finalMessage=(string)($stamp['providerMessage']??($pdfAvailable?'Factura timbrada correctamente.':'Factura timbrada correctamente. El PDF esta pendiente de generacion.'));return['success'=>true,'status'=>$finalStatus,'message'=>$finalMessage,'document_id'=>$documentId,'attempt_id'=>$stamp['attemptId']??null,'uuid'=>$stamp['uuid'],'xml_available'=>true,'pdf_available'=>$pdfAvailable,'pdf_status'=>$persisted->pdf_status??'pending','http_status'=>$stamp['httpStatus']??null,'provider_code'=>$stamp['providerCode']??null,'provider_message'=>$this->sanitize((string)($stamp['providerMessage']??'')),'redirect_url'=>get_uri('fiscal/invoices/'.$documentId),'keep_modal_open'=>false];
        }catch(Throwable$e){if($documentId&&($confirmed=$this->db->table('fiscal_document_stamps')->where('fiscal_document_id',$documentId)->get(1)->getRow())&&trim((string)$confirmed->uuid)!==''){log_message('error','CFDI_POST_STAMP_FLOW_FAILURE for document {document}: {type}',['document'=>$documentId,'type'=>get_class($e)]);return$this->withXmlInspection(['success'=>true,'status'=>'stamped_local_reconciliation_pending','message'=>'Factura timbrada correctamente. Falta completar una actualizacion administrativa local.','document_id'=>$documentId,'attempt_id'=>(int)($confirmed->stamp_attempt_id??0),'uuid'=>(string)$confirmed->uuid,'xml_available'=>true,'pdf_available'=>(string)($confirmed->pdf_status??'')==='valid','keep_modal_open'=>false],$draftId,$documentId);}log_message('error','UX2 invoice flow failed for draft {draft}: {type}',['draft'=>$draftId,'type'=>get_class($e)]);$inspection=$this->inspect($draftId);if(($inspection['status']??'')==='verification_pending')return$inspection+['success'=>false];return$this->withXmlInspection($this->blocked($this->safeError($e,'invoice_flow.execute',$draftId))+['success'=>false,'keep_modal_open'=>true],$draftId);}finally{$this->db->query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }

    public function executeSale(int$saleId,array$input,int$userId,bool$authorized):array
    {
        if(!$authorized)return$this->blocked('No tiene permiso para generar facturas.')+['success'=>false,'category'=>'validation'];$lock='fiscal_invoice_sale_'.$saleId;$acquired=(int)($this->db->query('SELECT GET_LOCK(?, 0) acquired',[$lock])->getRow()->acquired??0);if($acquired!==1)return['success'=>false,'category'=>'validation','status'=>'processing','retry_allowed'=>false,'message'=>'La venta ya está siendo procesada.'];
        $result=[];
        try{$sale=$this->db->table('invoices')->where(['id'=>$saleId,'deleted'=>0])->get(1)->getRow();if(!$sale)return$this->blocked('La venta no existe.')+['success'=>false,'category'=>'validation'];(new CommercialSaleTotalConsistencyService($this->db))->synchronizeIfCanonical($saleId);$attempt=$this->attempts->find($saleId);$draftId=$attempt?(int)$attempt->id:null;
            if($attempt&&!empty($attempt->fiscal_document_id)){$documentId=(int)$attempt->fiscal_document_id;$stamp=$this->db->table('fiscal_document_stamps')->where('fiscal_document_id',$documentId)->get(1)->getRow();if($stamp&&trim((string)$stamp->uuid)!=='')return$this->existingStamped($saleId,(int)$attempt->id,$documentId,$stamp,$userId);if($this->hasActiveAttempt($documentId)||in_array((string)$attempt->status,['blocked','stamping'],true))return$this->unknown((int)$attempt->id,$documentId,'Estamos verificando el resultado de la factura. No vuelva a facturar este documento.')+['success'=>false,'sale_id'=>$saleId];}
            $input['sale_ids']=[$saleId];$input['ux_mode']='normal';$input['save_as_draft']=0;
            if($draftId){$saved=$this->workflow->save($input,$userId,$draftId);$draftId=(int)$saved['id'];$inspection=$this->inspect($draftId,true);if(!$inspection['ready'])return$inspection+['success'=>false,'category'=>'validation','keep_modal_open'=>true];}
            else{$data=$this->workflow->formData(null,[$saleId]);$preparation=(new FiscalReviewPreparation($this->db))->prepare($data,$input);if(!$preparation['validation']['valid'])return['success'=>false,'category'=>'validation','status'=>'review_needed','retry_allowed'=>true,'keep_modal_open'=>true,'message'=>'No es posible facturar todavía.','blockers'=>array_map([$this,'actionable'],$preparation['validation']['errors'])];$saved=$this->workflow->save($input,$userId);$draftId=(int)$saved['id'];}
            $result=$this->executeInternal($draftId,$userId,true,true);
            $saleClosed=false;
            if(!empty($result['success'])&&!empty($result['uuid'])){
                $transition=(new SaleLifecycleService($this->db))->finalizeFiscalIssuance($saleId,$userId);
                $saleClosed=(bool)$transition['closed'];$result['payment_status']=$transition['payment_status'];$result['balance']=$transition['balance'];
            }
            return$result+['sale_id'=>$saleId,'sale_closed'=>$saleClosed];
        }catch(Throwable$e){if(!empty($result['uuid'])){log_message('error','CFDI_POST_STAMP_SALE_CLOSE_FAILURE for sale {sale}: {type}',['sale'=>$saleId,'type'=>get_class($e)]);return$result+['success'=>true,'status'=>'stamped_local_reconciliation_pending','message'=>'Factura timbrada correctamente. Falta completar una actualizacion administrativa local.','sale_id'=>$saleId,'sale_closed'=>false];}return$this->blocked($this->safeError($e,'invoice_flow.execute_sale',null,$saleId))+['success'=>false,'category'=>'validation','keep_modal_open'=>true];}finally{$this->db->query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }

    private function withXmlInspection(array $result, int $draftId, ?int $documentId = null): array
    {
        if (!$documentId) {
            $draft = $this->db->table('fiscal_drafts')->select('fiscal_document_id')->where('id', $draftId)->get(1)->getRow();
            $documentId = (int) ($draft->fiscal_document_id ?? 0);
        }
        if (!$documentId) return $result;
        $signature = $this->db->table('fiscal_document_signatures')->select('signed_xml_artifact_id')
            ->where('fiscal_document_id', $documentId)->orderBy('id', 'DESC')->get(1)->getRow();
        if (!empty($signature->signed_xml_artifact_id)) {
            $result['xml_inspection'] = ['label'=>'Ver XML enviado al PAC','url'=>url_to('fiscal_signed_xml_view', $documentId),'kind'=>'signed'];
            return $result;
        }
        $artifact = $this->db->table('fiscal_document_artifacts')->select('id')
            ->where(['fiscal_document_id'=>$documentId,'artifact_type'=>'pre_xml','superseded_at'=>null])
            ->orderBy('id','DESC')->get(1)->getRow();
        if ($artifact) {
            $result['xml_inspection'] = ['label'=>'Ver XML preliminar','url'=>get_uri('fiscal/invoices/prexml/view/'.$artifact->id),'kind'=>'pre_xml'];
        }
        return $result;
    }
    private function hasActiveAttempt(int$id):bool{return(bool)$this->db->table('fiscal_stamp_attempts')->where('fiscal_document_id',$id)->groupStart()->whereIn('status',['pending','sending','unknown','timeout_unknown','transport_unknown','reconciliation_required'])->orWhere('requires_reconciliation',1)->groupEnd()->countAllResults();}
    private function hasRejectedAttempt(int$id):bool{return(bool)$this->db->table('fiscal_stamp_attempts')->where('fiscal_document_id',$id)->whereIn('status',['rejected','provider_rejected'])->countAllResults();}
    private function summary(array$s,array$balance):array{$d=$s['draft'];return['issuer_id'=>(int)$d['issuer_id'],'receiver_name'=>(string)($s['receiver_snapshot']['legal_name']??''),'receiver_rfc'=>(string)($s['receiver_snapshot']['rfc']??''),'series'=>(string)($s['series_snapshot']['series']??$d['provisional_series']??''),'issue_date'=>(string)$d['issue_date'],'cfdi_use'=>(string)$d['cfdi_use_code'],'payment_method'=>(string)$d['payment_method_code'],'payment_form'=>(string)$d['payment_form_code'],'subtotal'=>(string)$s['totals']['subtotal'],'transferred'=>(string)$s['totals']['transferred'],'withheld'=>(string)$s['totals']['withheld'],'total'=>(string)$s['totals']['total'],'wallet_available'=>(int)$balance['available'],'wallet_reserved'=>(int)$balance['reserved']];}
    private function actionable(array$e):array{$section=(string)($e['section']??'document');return['code'=>(string)($e['code']??'VALIDATION_ERROR'),'message'=>(string)($e['message']??'Revisa la información fiscal.'),'action'=>match($section){'receiver'=>'Editar cliente','concepts'=>'Editar partida',default=>'Corregir revisión'}];}
    private function blockers(array$messages):array{return['success'=>false,'ready'=>false,'category'=>'validation','status'=>'review_needed','keep_modal_open'=>true,'retry_allowed'=>true,'message'=>'No es posible facturar todavía.','blockers'=>array_map(fn($m)=>['message'=>$m,'action'=>'Corregir revisión'],array_values(array_unique($messages)))];}
    private function blocked(string$message):array{return['ready'=>false,'status'=>'review_needed','blockers'=>[],'message'=>$message,'retry_allowed'=>true];}
    private function unknown(int$draftId,int$documentId,string$message):array{return['ready'=>false,'category'=>'transport_unknown','status'=>'verification_pending','draft_id'=>$draftId,'document_id'=>$documentId,'technical_reference'=>['document_id'=>$documentId],'message'=>$message,'actions'=>[['type'=>'status_query','label'=>'Consultar estado']],'retry_allowed'=>false,'requires_reconciliation'=>true,'keep_modal_open'=>true];}
    private function sanitize(string$message):string{return mb_substr(trim(preg_replace('/[\r\n\t]+/',' ',$message)),0,300);}
    private function sanitizeProviderMessage(string$message):string{$message=$this->sanitize($message);$message=preg_replace('/\b(api(?:key)?|keypem|password|contraseña)\s*[:=]\s*\S+/iu','$1=[PROTEGIDO]',$message);return mb_substr((string)$message,0,300);}
    private function activeDraftForSale(int$saleId):?int{return$this->attempts->draftId($saleId);}
    private function existingStamped(int$saleId,int$draftId,int$documentId,object$stamp,int$userId):array{$transition=(new SaleLifecycleService($this->db))->finalizeFiscalIssuance($saleId,$userId);$pdf=(string)($stamp->pdf_status??'')==='valid'&&(int)($stamp->pac_pdf_artifact_id??0)>0;return['success'=>true,'ready'=>true,'status'=>$pdf?'stamped':'stamped_pdf_pending','message'=>$pdf?'La factura ya estaba timbrada.':'La factura ya estaba timbrada; el PDF sigue pendiente.','sale_id'=>$saleId,'sale_closed'=>(bool)$transition['closed'],'payment_status'=>$transition['payment_status'],'balance'=>$transition['balance'],'draft_id'=>$draftId,'document_id'=>$documentId,'attempt_id'=>(int)($stamp->stamp_attempt_id??0),'uuid'=>(string)$stamp->uuid,'xml_available'=>true,'pdf_available'=>$pdf,'retry_allowed'=>false,'keep_modal_open'=>false,'redirect_url'=>get_uri('fiscal/invoices/'.$documentId)];}
    private function safeError(Throwable$e,string$stage='unknown',?int$draftId=null,?int$saleId=null):string{$m=$e->getMessage();if($m===CommercialSaleTotalConsistencyService::MISMATCH)return'La venta tiene importes fiscales inconsistentes con sus partidas. Revise la configuración fiscal de los productos.';foreach(['No hay timbres disponibles','El borrador ya está siendo procesado','La fecha de expedición','El emisor no tiene un CSD','La venta debe estar cerrada','Falta la configuración fiscal']as$known)if(str_contains($m,$known))return$m;$reference='FI-'.strtoupper(substr(hash('sha256',get_class($e).'|'.$m.'|'.$e->getFile().'|'.$e->getLine().'|'.microtime(true)),0,10));$detail=['reference'=>$reference,'error_code'=>$e->getCode(),'exception_class'=>get_class($e),'exception_message'=>$m,'file'=>$e->getFile(),'line'=>$e->getLine(),'service'=>self::class,'method'=>__FUNCTION__,'stage'=>$stage,'sale_id'=>$saleId,'draft_id'=>$draftId];log_message('error','Fiscal invoice preparation failure {detail}',['detail'=>json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);if($draftId)$this->db->table('fiscal_draft_audit')->insert(['fiscal_draft_id'=>$draftId,'sale_id'=>$saleId,'user_id'=>null,'event'=>'invoice_flow_technical_error','summary_json'=>json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>get_current_utc_time()]);return'No fue posible preparar la factura. Referencia técnica: '.$reference;}
}
