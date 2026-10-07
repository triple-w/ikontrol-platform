<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Cancellation;

use App\Contracts\Fiscal\Cancellation\FiscalCancellationAdapterInterface;
use RuntimeException;
use Throwable;
use App\Services\Fiscal\FiscalPreviewModeGuard;
use App\Services\Fiscal\CsdCertificateService;
use App\Services\Fiscal\Signing\CsdCertificateSecretService;
use App\Services\Fiscal\Stamps\FiscalStampAccountService;
use App\Services\Fiscal\Stamps\FiscalStampBalanceService;
use App\Services\Fiscal\Stamps\FiscalStampBalanceException;
use App\Services\Fiscal\FiscalArtifactStorageService;
use App\Services\Fiscal\FiscalRuntimeContext;
use DOMDocument;

final class FiscalCancellationService
{
    private $db;
    public function __construct($db=null, private readonly ?FiscalCancellationAdapterInterface $adapter=null, $unusedStampAccounts=null)
    {
        $this->db=$db?:db_connect();
    }

    public function cancel(int $documentId,string $reason,?string $replacementUuid,int $userId,bool $authorized):array
    {
        (new FiscalPreviewModeGuard($this->db))->assertCancellationAllowed();
        if(!$authorized)throw new RuntimeException('No tiene permiso para cancelar facturas.');
        if(!in_array($reason,['01','02','03','04'],true))throw new RuntimeException('El motivo de cancelación no es válido.');
        $replacementUuid=$replacementUuid?strtoupper(trim($replacementUuid)):null;
        if($reason==='01'&&!preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/',$replacementUuid??''))throw new RuntimeException('El motivo 01 requiere UUID sustituto válido.');
        if($reason!=='01')$replacementUuid=null;
        $lock='ikontrol:fiscal:cancel:'.$documentId;
        $row=$this->db->query('SELECT GET_LOCK(?,0) acquired',[$lock])->getRow();
        if((int)($row->acquired??0)!==1)return['success'=>false,'status'=>'processing','message'=>'La cancelación ya está siendo procesada.'];
        try{
            $this->db->transBegin();
            $table=$this->db->prefixTable('fiscal_documents');
            $document=$this->db->query("SELECT * FROM {$table} WHERE id=? AND deleted=0 FOR UPDATE",[$documentId])->getRow();
            $stamp=$this->db->table('fiscal_document_stamps')->where('fiscal_document_id',$documentId)->get(1)->getRow();
            $issuer=$this->db->table('fiscal_document_issuers')->where('fiscal_document_id',$documentId)->get(1)->getRow();
            $receiver=$this->db->table('fiscal_document_receivers')->where('fiscal_document_id',$documentId)->get(1)->getRow();
            $xml=$this->db->table('fiscal_document_artifacts')->where(['fiscal_document_id'=>$documentId,'artifact_type'=>'stamped_xml','superseded_at'=>null])->get(1)->getRow();
            if(!$document||!$stamp||!$xml||!trim((string)$stamp->uuid))throw new RuntimeException('Sólo un CFDI timbrado puede cancelarse fiscalmente.');
            $identity=$this->identityFromStampedXml($xml,$document,$issuer,$receiver);
            if($replacementUuid!==null&&strtoupper((string)$stamp->uuid)===$replacementUuid)throw new RuntimeException('El UUID sustituto no puede ser igual al CFDI que se cancela.');
            if(in_array($document->status,['stamping','stamp_status_unknown'],true))throw new RuntimeException('No puede cancelarse un documento con estado fiscal desconocido.');
            $existing=$this->db->table('fiscal_cancellation_requests')->where('fiscal_document_id',$documentId)->orderBy('id','DESC')->get(1)->getRow();
            if($existing&&!in_array($existing->status,['rejected','transport_not_sent'],true)){
                $this->db->transCommit();return['success'=>$existing->status==='accepted','status'=>$existing->status,'request_id'=>(int)$existing->id,'message'=>'Ya existe una solicitud de cancelación para este CFDI.'];
            }
            if($document->status==='cancelled')throw new RuntimeException('El CFDI ya está cancelado.');
            $isFake=$this->adapter instanceof FakeFiscalCancellationAdapter;$fiscal=config('Fiscal');$config=config('TimbradorXpress');$provider=$isFake?'fake':'timbradorxpress';$environment=FiscalRuntimeContext::fiscalEnvironment($fiscal);if(!$isFake){$context=FiscalRuntimeContext::assertPacOperational($fiscal,$config);$environment=$context['fiscal_environment'];if((string)$document->environment!==$environment)throw new RuntimeException('El CFDI pertenece a otro ambiente fiscal.');$balance=(new FiscalStampBalanceService($this->db))->forDocument($document);if($balance['usable']<1)throw new FiscalStampBalanceException('No cuenta con timbres suficientes para solicitar la cancelación.');$csdPayload=$this->csdPayload((int)$document->issuer_profile_id,$identity['issuer_rfc'],$userId);}
            $key=hash('sha256',implode('|',[$documentId,$stamp->uuid,$reason,$replacementUuid??'',$provider,$environment,get_current_utc_time()]));
            $now=get_current_utc_time();
            $data=['fiscal_document_id'=>$documentId,'fiscal_document_stamp_id'=>$stamp->id,'uuid'=>$stamp->uuid,'issuer_rfc'=>$identity['issuer_rfc'],'receiver_rfc'=>$identity['receiver_rfc'],'total'=>$identity['total'],'cancellation_reason'=>$reason,'replacement_uuid'=>$replacementUuid,'provider'=>$provider,'environment'=>$environment,'status'=>'sending','requested_at'=>$now,'user_id'=>$userId,'idempotency_key'=>$key,'requires_reconciliation'=>0,'created_at'=>$now,'updated_at'=>$now];
            $this->db->table('fiscal_cancellation_requests')->insert($data);$requestId=(int)$this->db->insertID();
            $this->db->table('fiscal_cancellation_attempts')->insert(['fiscal_cancellation_request_id'=>$requestId,'status'=>'sending','started_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);$attemptId=(int)$this->db->insertID();
            $this->db->table('fiscal_documents')->where('id',$documentId)->update(['status'=>'cancellation_pending','updated_at'=>$now]);
            if(!$this->db->transStatus())throw new RuntimeException('No fue posible preparar la cancelación durable.');
            $this->db->transCommit();

            $payload=['uuid'=>$stamp->uuid,'reason'=>$reason,'replacement_uuid'=>$replacementUuid,'issuer_rfc'=>$identity['issuer_rfc'],'receiver_rfc'=>$identity['receiver_rfc'],'total'=>$identity['total']];if(!$isFake)$payload+=$csdPayload;
            $result=($this->adapter??new TimbradorXpressCancellationAdapter($config,null,$fiscal))->cancel($payload);unset($payload);
            if(!$isFake&&!empty($result['request_sent']))(new FiscalStampAccountService($this->db))->consumeCancellationRequest($requestId,$userId);
            if(!empty($result['force_persistence_error']))throw new RuntimeException('Fallo de persistencia posterior simulado.');
            return $this->persistResult($documentId,$requestId,$attemptId,$result,$userId);
        }catch(Throwable $e){
            $this->db->transRollback();
            if(isset($requestId)){
                $now=get_current_utc_time();
                $this->db->table('fiscal_cancellation_requests')->where('id',$requestId)->update(['status'=>'unknown','requires_reconciliation'=>1,'updated_at'=>$now]);
                $this->db->table('fiscal_cancellation_attempts')->where('id',$attemptId)->update(['status'=>'reconciliation_required','requires_reconciliation'=>1,'updated_at'=>$now]);
                $this->db->table('fiscal_documents')->where('id',$documentId)->update(['status'=>'cancellation_pending','updated_at'=>$now]);
                return['success'=>false,'status'=>'unknown','request_id'=>$requestId,'requires_reconciliation'=>true,'message'=>'La cancelación requiere conciliación.'];
            }
            throw$e;
        }finally{try{$this->db->query('SELECT RELEASE_LOCK(?)',[$lock]);}catch(Throwable){}}
    }

    public function queryStatus(int$documentId,int$userId,bool$authorized,string$queryKey):array
    {
        if(!$authorized)throw new RuntimeException('No tiene permiso para consultar el estatus fiscal.');
        $request=$this->db->table('fiscal_cancellation_requests')->where('fiscal_document_id',$documentId)->orderBy('id','DESC')->get(1)->getRow();
        if(!$request)throw new RuntimeException('No existe solicitud de cancelación para esta factura.');
        if($request->status==='accepted')return$this->response($documentId,$request,(int)$request->id,null,'accepted','Factura cancelada correctamente.');if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/',$queryKey))throw new RuntimeException('La clave idempotente de consulta no es válida.');
        $movementKey='cancellation-status-query:'.$request->id.':'.$queryKey;$existingMovement=$this->db->table('fiscal_stamp_movements')->where('idempotency_key',$movementKey)->get(1)->getRow();if($existingMovement){$response=$this->response($documentId,$request,(int)$request->id,null,(string)$request->status,'La consulta ya fue procesada con esta referencia.');$response['success']=true;return$response;}$document=$this->db->table('fiscal_documents')->where('id',$documentId)->get(1)->getRow();$environment=(string)($request->environment?:$document->environment);if(!($this->adapter instanceof FakeFiscalCancellationAdapter)&&FiscalRuntimeContext::fiscalEnvironment(config('Fiscal'))!==$environment)throw new RuntimeException('La solicitud pertenece a otro ambiente fiscal.');$balance=(new FiscalStampBalanceService($this->db))->forIssuer((int)$document->issuer_profile_id,$environment);if($balance['usable']<1)throw new FiscalStampBalanceException('No cuenta con timbres suficientes para realizar la consulta.');
        $attempt=$this->db->table('fiscal_cancellation_attempts')->where('fiscal_cancellation_request_id',$request->id)->get(1)->getRow();if(!$attempt)throw new RuntimeException('La solicitud no tiene un intento durable para conciliación.');$attemptId=(int)$attempt->id;$now=get_current_utc_time();$this->db->table('fiscal_cancellation_attempts')->where('id',$attemptId)->update(['status'=>'querying','updated_at'=>$now]);
        $result=($this->adapter??new TimbradorXpressCancellationAdapter())->query(['uuid'=>$request->uuid,'issuer_rfc'=>$request->issuer_rfc,'receiver_rfc'=>$request->receiver_rfc,'total'=>(string)$request->total]);
        if(!empty($result['request_sent']))(new FiscalStampAccountService($this->db))->consumeCancellationStatusQuery((int)$request->id,$queryKey,$userId);$response=$this->persistResult($documentId,(int)$request->id,$attemptId,$result,$userId);$response['success']=true;return$response;
    }

    public function reconcileStoredStatusEvidence(int$documentId,int$userId,bool$authorized):array
    {
        if(!$authorized)throw new RuntimeException('No tiene permiso para conciliar el estatus fiscal.');$request=$this->db->table('fiscal_cancellation_requests')->where('fiscal_document_id',$documentId)->orderBy('id','DESC')->get(1)->getRow();if(!$request)throw new RuntimeException('No existe solicitud de cancelación.');$attempt=$this->db->table('fiscal_cancellation_attempts')->where('fiscal_cancellation_request_id',$request->id)->get(1)->getRow();$artifact=$this->db->table('fiscal_cancellation_artifacts')->where(['fiscal_cancellation_request_id'=>$request->id,'artifact_type'=>'provider_status'])->get(1)->getRow();if(!$attempt||!$artifact)throw new RuntimeException('No existe evidencia durable para conciliar.');$bytes=base64_decode((string)$artifact->content_base64,true);if($bytes===false||!hash_equals((string)$artifact->decoded_sha256,hash('sha256',$bytes)))throw new RuntimeException('La evidencia de consulta no supera integridad.');$result=(new TimbradorXpressCancellationAdapter())->interpret($bytes,200,'consultarEstadoSAT');return$this->persistResult($documentId,(int)$request->id,(int)$attempt->id,$result,$userId);
    }

    private function persistResult(int$documentId,int$requestId,int$attemptId,array$result,int$userId):array
    {
        $status=(string)$result['status'];$now=get_current_utc_time();$requestStatus=$status;$documentStatus=match($status){'accepted'=>'cancelled','pending'=>'cancellation_pending','rejected','transport_not_sent'=>'cancellation_rejected','unknown'=>'cancellation_pending',default=>'cancellation_pending'};
        $requires=$status==='unknown'?1:0;$ackId=null;
        $this->db->transBegin();
        try{
            if(!empty($result['provider_payload_base64']))$this->storeArtifact($requestId,$attemptId,'provider_'.(($result['operation']??'cancelarPEM')==='consultarEstadoSAT'?'status':'response'),(string)$result['provider_payload_base64'],'application/json',$userId,$now);
            if(!empty($result['ack_base64'])){
                $clean=trim((string)($result['ack_base64']??''));$bytes=base64_decode($clean,true);
                if($bytes!==false&&str_starts_with(ltrim($bytes),'<?xml')&&strlen($bytes)>=40){$this->storeArtifact($requestId,$attemptId,'cancellation_ack',$clean,'application/xml',$userId,$now);$ack=$this->db->table('fiscal_cancellation_artifacts')->where(['fiscal_cancellation_request_id'=>$requestId,'artifact_type'=>'cancellation_ack'])->get(1)->getRow();$ackId=$ack?(int)$ack->id:null;}
            }
            $this->db->table('fiscal_cancellation_requests')->where('id',$requestId)->update(['status'=>$requestStatus,'provider_code'=>$result['code'],'provider_message'=>$result['message'],'confirmed_at'=>in_array($status,['accepted','rejected'],true)?$now:null,'cancelled_at'=>$status==='accepted'?$now:null,'requires_reconciliation'=>$requires,'updated_at'=>$now]);
            $this->db->table('fiscal_cancellation_attempts')->where('id',$attemptId)->update(['status'=>$requestStatus,'provider_code'=>$result['code'],'provider_message'=>$result['message'],'response_hash'=>hash('sha256',json_encode([$status,$result['code'],$result['message']])), 'requires_reconciliation'=>$requires,'responded_at'=>$now,'updated_at'=>$now]);
            $this->db->table('fiscal_documents')->where('id',$documentId)->update(['status'=>$documentStatus,'cancelled_at'=>$status==='accepted'?$now:null,'updated_at'=>$now]);
            if($status==='accepted'){$request=$this->db->table('fiscal_cancellation_requests')->where('id',$requestId)->get(1)->getRow();if($request&&$request->replacement_uuid){$related=$this->db->table('fiscal_document_stamps')->where('uuid',$request->replacement_uuid)->get(1)->getRow();if($related&&!$this->db->table('fiscal_document_relations')->where(['source_document_id'=>$documentId,'related_document_id'=>$related->fiscal_document_id,'relation_type'=>'substitution'])->countAllResults())$this->db->table('fiscal_document_relations')->insert(['source_document_id'=>$documentId,'related_document_id'=>$related->fiscal_document_id,'relation_type'=>'substitution','created_by'=>$userId,'created_at'=>$now]);}}
            if(!$this->db->transStatus())throw new RuntimeException('No fue posible persistir el resultado de cancelación.');
            $this->db->transCommit();
        }catch(Throwable$e){$this->db->transRollback();throw$e;}
        $request=$this->db->table('fiscal_cancellation_requests')->where('id',$requestId)->get(1)->getRow();$message=match($status){'accepted'=>'Factura cancelada correctamente.','pending'=>'Solicitud de cancelación enviada. El resultado está pendiente.','rejected','transport_not_sent'=>'No fue posible cancelar la factura.','unknown'=>'Estamos verificando el resultado de la cancelación.',default=>(string)$result['message']};return$this->response($documentId,$request,$requestId,$attemptId,$status,$message,$result,$ackId);
    }
    private function storeArtifact(int$requestId,int$attemptId,string$type,string$base64,string$mime,int$userId,string$now):void{$bytes=base64_decode($base64,true);if($bytes===false)return;$data=['fiscal_cancellation_attempt_id'=>$attemptId,'content_encoding'=>'base64','content_base64'=>$base64,'decoded_mime_type'=>$mime,'decoded_size_bytes'=>strlen($bytes),'decoded_sha256'=>hash('sha256',$bytes),'created_by'=>$userId,'created_at'=>$now];$existing=$this->db->table('fiscal_cancellation_artifacts')->where(['fiscal_cancellation_request_id'=>$requestId,'artifact_type'=>$type])->get(1)->getRow();if($existing)$this->db->table('fiscal_cancellation_artifacts')->where('id',$existing->id)->update($data);else$this->db->table('fiscal_cancellation_artifacts')->insert($data+['fiscal_cancellation_request_id'=>$requestId,'artifact_type'=>$type]);}
    private function response(int$documentId,object$request,int$requestId,?int$attemptId,string$status,string$message,array$result=[],?int$ackId=null):array{$ack=$ackId!==null||$this->db->table('fiscal_cancellation_artifacts')->where(['fiscal_cancellation_request_id'=>$requestId,'artifact_type'=>'cancellation_ack'])->countAllResults()>0;$fiscal=$status==='accepted'?'cancelled':'stamped';$cancel=match($status){'accepted'=>'cancelled','pending','requested','sending'=>'pending','rejected','transport_not_sent'=>'rejected',default=>'verifying'};return['success'=>in_array($status,['accepted','pending'],true),'document_id'=>$documentId,'request_id'=>$requestId,'attempt_id'=>$attemptId,'status'=>$status,'fiscal_status'=>$fiscal,'cancellation_status'=>$cancel,'requires_reconciliation'=>$status==='unknown','provider_code'=>$result['code']??$request->provider_code,'provider_message'=>$result['message']??$request->provider_message,'http_status'=>$result['http_status']??null,'ack_available'=>$ack,'ack_url'=>$ack?get_uri('fiscal/invoices/cancellation/ack/'.$requestId):null,'message'=>$message];}
    private function identityFromStampedXml(object $artifact,object $document,?object $storedIssuer,?object $storedReceiver):array
    {
        try{$xml=(new FiscalArtifactStorageService($this->db))->read($artifact);$dom=new DOMDocument();if(!@$dom->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS)||!$dom->documentElement)throw new RuntimeException('XML_INVALID');$root=$dom->documentElement;$issuer=$root->getElementsByTagNameNS('http://www.sat.gob.mx/cfd/4','Emisor')->item(0);$receiver=$root->getElementsByTagNameNS('http://www.sat.gob.mx/cfd/4','Receptor')->item(0);$total=trim($root->getAttribute('Total'));$issuerRfc=$issuer?trim($issuer->getAttribute('Rfc')):'';$receiverRfc=$receiver?trim($receiver->getAttribute('Rfc')):'';if($total!==''&&$issuerRfc!==''&&$receiverRfc!=='')return['issuer_rfc'=>$issuerRfc,'receiver_rfc'=>$receiverRfc,'total'=>$total];}catch(Throwable $e){log_message('warning','Cancellation uses immutable fiscal model because stamped XML artifact is unavailable: {type}',['type'=>get_class($e)]);}
        $issuerRfc=trim((string)($storedIssuer->rfc??''));$receiverRfc=trim((string)($storedReceiver->rfc??''));$total=trim((string)($document->total??''));if($total===''||$issuerRfc===''||$receiverRfc==='')throw new RuntimeException('No existe identidad fiscal autoritativa para cancelar.');return['issuer_rfc'=>$issuerRfc,'receiver_rfc'=>$receiverRfc,'total'=>$total];
    }    private function csdPayload(int$issuerProfileId,string$issuerRfc,int$userId):array{$now=get_current_utc_time();$certificate=$this->db->table('fiscal_issuer_certificates')->where(['issuer_profile_id'=>$issuerProfileId,'status'=>'valid','deleted'=>0])->where('valid_from <=',$now)->where('valid_to >=',$now)->orderBy('is_default','DESC')->get(1)->getRow();if(!$certificate)throw new RuntimeException('El emisor no tiene un CSD activo para cancelar.');$normalize=static fn(string$v):string=>preg_replace('/[^A-Z0-9Ñ&]/u','',mb_strtoupper(trim($v),'UTF-8'));if($normalize((string)$certificate->certificate_rfc)!==$normalize($issuerRfc))throw new RuntimeException('El RFC del CSD activo no coincide con el emisor del CFDI.');$password=(new CsdCertificateSecretService($this->db))->passwordForSigning((int)$certificate->id,$userId);$csd=new CsdCertificateService($this->db);$material=$csd->certificateMaterial($certificate);$key=$csd->openPrivateKey($material['private_key_bytes'],$password);$keyPem=$csd->exportPrivateKeyPem($key);$cerPem="-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($material['certificate_der']),64,"\n")."-----END CERTIFICATE-----\n";unset($password,$material,$key);return['key_pem'=>$keyPem,'certificate_pem'=>$cerPem];}
}
