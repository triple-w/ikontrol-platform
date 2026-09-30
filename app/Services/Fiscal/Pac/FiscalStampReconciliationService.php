<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pac;

use App\Contracts\Fiscal\Pac\PacAdapterInterface;
use App\Domain\Fiscal\Pac\PacResponse;
use App\Services\Fiscal\FiscalArtifactStorageService;
use App\Services\Fiscal\FiscalSaleAllocationService;
use App\Services\Fiscal\Stamps\FiscalStampAccountService;
use RuntimeException;
use Throwable;

final class FiscalStampReconciliationService
{
    private FiscalStampAccountService $stampAccounts;

    public function __construct(private mixed $db=null,private ?PacSecretVault $vault=null,private ?string $contingencyRoot=null,?FiscalStampAccountService $stampAccounts=null,private ?PacAdapterInterface $adapter=null)
    {
        $this->db??=db_connect();
        $this->stampAccounts=$stampAccounts??new FiscalStampAccountService($this->db);
    }

    /** The only provider-facing path for attempts whose request may have left. */
    public function reconcile(int $attemptId,int $userId,bool $authorized):array
    {
        if(!$authorized)throw new RuntimeException('No tiene permiso para conciliar timbrados.');
        $lock='fiscal_stamp_reconcile_'.$attemptId;
        if((int)($this->db->query('SELECT GET_LOCK(?,0) acquired',[$lock])->getRow('acquired')??0)!==1)throw new RuntimeException('Ya existe una conciliacion en curso.');
        try{
            $attempt=$this->db->table('fiscal_stamp_attempts')->where('id',$attemptId)->get(1)->getRow();
            if($attempt&&$attempt->status==='success_local_reconciliation_pending'){
                $stamp=$this->db->table('fiscal_document_stamps')->where('stamp_attempt_id',$attemptId)->get(1)->getRow();
                if(!$stamp)throw new RuntimeException('El timbre conciliado pendiente no existe.');
                $this->stampAccounts->consumeFromReconciliation($attemptId,(int)$stamp->id,$userId);
                $this->db->table('fiscal_stamp_attempts')->where('id',$attemptId)->update(['status'=>'success_reconciled','updated_at'=>get_current_utc_time()]);
                return['attempt_id'=>$attemptId,'document_id'=>(int)$attempt->fiscal_document_id,'outcome'=>'stamped','wallet_recovered'=>true];
            }
            if($attempt&&$attempt->status==='reconciled_not_found_wallet_pending'){
                $this->stampAccounts->releaseFromReconciliation($attemptId,$userId);
                $this->db->table('fiscal_stamp_attempts')->where('id',$attemptId)->update(['status'=>'reconciled_not_found','updated_at'=>get_current_utc_time()]);
                return['attempt_id'=>$attemptId,'document_id'=>(int)$attempt->fiscal_document_id,'outcome'=>'not_found_definitive','wallet_recovered'=>true];
            }
            if(!$attempt||(int)$attempt->requires_reconciliation!==1)throw new RuntimeException('El intento no requiere conciliacion.');
            if($this->db->table('fiscal_document_stamps')->where('fiscal_document_id',$attempt->fiscal_document_id)->countAllResults())return['attempt_id'=>$attemptId,'outcome'=>'stamped','idempotent'=>true];

            $adapter=$this->adapter??(new FiscalPacAdapterFactory())->create();
            try{$response=$adapter->getStampStatus($this->statusQuery($attempt));}
            catch(Throwable $e){$response=new PacResponse(null,$e->getMessage(),null,0,['reconciliation_outcome'=>'error'],true,false);}
            $outcome=self::classifyResponse($response);
            if($outcome==='stamped'){
                if(!$response->data)throw new RuntimeException('El PAC reporto timbrado sin XML verificable.');
                $stored=(new PacContingencyStorageService($this->vault??new PacSecretVault(),$this->contingencyRoot))->storePayload($attemptId,json_encode(['code'=>$response->code,'message'=>$response->message,'data'=>$response->data],JSON_THROW_ON_ERROR));
                $this->db->table('fiscal_stamp_attempts')->where('id',$attemptId)->update(['contingency_path'=>$stored['path'],'response_hash'=>$stored['hash'],'updated_at'=>get_current_utc_time()]);
                return $this->reconcileStoredProviderResponse($attemptId,$userId,true);
            }
            if($outcome==='not_found_definitive')return $this->closeDefinitiveNotFound($attempt,$userId,$response);
            $this->keepPending($attemptId,$outcome,$response->message);
            return['attempt_id'=>$attemptId,'document_id'=>(int)$attempt->fiscal_document_id,'outcome'=>$outcome,'requires_reconciliation'=>true,'automatic_retry'=>false];
        }finally{$this->db->query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }

    public static function classifyResponse(PacResponse $response):string
    {
        $declared=(string)($response->metadata['reconciliation_outcome']??'');
        if(in_array($declared,['stamped','not_found_definitive','indeterminate','error'],true))return$declared;
        if($response->transportError)return'error';
        return'indeterminate';
    }

    public function recoverFromContingency(int$attemptId,int$userId,bool$authorized):array
    {
        if(!$authorized)throw new RuntimeException('No tiene permiso para conciliar timbrados.');
        $attempt=$this->db->table('fiscal_stamp_attempts')->where('id',$attemptId)->get(1)->getRow();
        if(!$attempt||(int)($attempt->requires_reconciliation??0)!==1)throw new RuntimeException('El intento no admite conciliacion.');
        if(!$attempt->contingency_path)throw new RuntimeException('No existe evidencia local; no se reenviara automaticamente.');
        $xml=(new PacContingencyStorageService($this->vault??new PacSecretVault(),$this->contingencyRoot))->read($attempt->contingency_path);
        return['attempt'=>$attempt,'xml'=>$xml,'sha256'=>hash('sha256',$xml),'requires_validation'=>true,'automatic_resend'=>false];
    }

    public function reconcileStoredProviderResponse(int$attemptId,int$userId,bool$authorized):array
    {
        if(!$authorized)throw new RuntimeException('No tiene permiso para conciliar timbrados.');
        $attempt=$this->db->table('fiscal_stamp_attempts')->where('id',$attemptId)->get(1)->getRow();
        if(!$attempt||(int)$attempt->requires_reconciliation!==1)throw new RuntimeException('El intento no admite conciliacion desde respuesta almacenada.');
        $outer=(new PacContingencyStorageService($this->vault??new PacSecretVault(),$this->contingencyRoot))->read((string)$attempt->contingency_path);
        $payload=json_decode($outer,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($payload)||!isset($payload['data'])||!is_string($payload['data']))throw new RuntimeException('La evidencia no contiene data PAC utilizable.');
        $data=(new TimbradorXpressStampDataParser())->parse(new PacResponse((string)($payload['code']??''),(string)($payload['message']??''),$payload['data'],(int)($attempt->http_status??200)));
        $xml=(string)$data['XML'];
        $signature=$this->db->table('fiscal_document_signatures')->where(['fiscal_document_id'=>$attempt->fiscal_document_id,'signature_verified'=>1,'xsd_status'=>'valid'])->orderBy('id','DESC')->get(1)->getRow();
        if(!$signature)throw new RuntimeException('No existe XML firmado validado.');
        $signedArtifact=$this->db->table('fiscal_document_artifacts')->where('id',$signature->signed_xml_artifact_id)->get(1)->getRow();
        if(!$signedArtifact)throw new RuntimeException('No existe el artefacto firmado.');
        $storage=new FiscalArtifactStorageService($this->db);
        $validated=(new StampedXmlValidator())->validate($xml,$storage->read($signedArtifact));
        $document=$this->db->table('fiscal_documents')->where('id',$attempt->fiscal_document_id)->get(1)->getRow();
        if(!$document)throw new RuntimeException('El documento fiscal no existe.');
        $artifact=$storage->store((int)$document->id,'stamped_xml',$xml,(string)$attempt->provider.'-reconciliation-v1','CFDI 4.0 + TFD 1.1',(string)$signedArtifact->schema_sha256,'valid',['attempt_id'=>$attemptId,'source'=>'provider_reconciliation','uuid'=>$validated['uuid']],$userId);
        $this->db->transBegin();
        try{
            if($this->db->table('fiscal_document_stamps')->where('fiscal_document_id',$document->id)->countAllResults())throw new RuntimeException('El documento ya tiene timbre registrado.');
            $this->db->table('fiscal_document_stamps')->insert(['fiscal_document_id'=>$document->id,'stamp_attempt_id'=>$attemptId,'stamped_xml_artifact_id'=>$artifact->id,'uuid'=>$validated['uuid'],'stamp_date'=>$this->sqlDate($validated['stamp_date']),'pac_rfc'=>$validated['pac_rfc'],'sat_certificate_number'=>$validated['sat_certificate_number'],'cfd_seal'=>$validated['cfd_seal'],'sat_seal'=>$validated['sat_seal'],'tfd_version'=>$validated['tfd_version'],'provider'=>$attempt->provider,'environment'=>$attempt->environment,'stamped_xml_sha256'=>$validated['sha256'],'provider_original_chain'=>$data['CadenaOriginal']??null,'sat_original_chain'=>$data['CadenaOriginalSAT']??null,'qr_data'=>$data['CodigoQR']??null,'pdf_status'=>'pending','pdf_template'=>(string)(config('TimbradorXpress')->pdfTemplate??'1'),'created_at'=>get_current_utc_time()]);
            $stampId=(int)$this->db->insertID();$now=get_current_utc_time();
            $this->db->table('fiscal_stamp_attempts')->where('id',$attemptId)->update(['status'=>'success_reconciled','requires_reconciliation'=>0,'retryable'=>0,'uuid'=>$validated['uuid'],'response_hash'=>$validated['sha256'],'reconciled_at'=>$now,'reconciled_by'=>$userId,'reconciliation_source'=>'provider_reconciliation','updated_at'=>$now]);
            $this->db->table('fiscal_documents')->where('id',$document->id)->update(['status'=>'stamped','stamp_updated_at'=>$now]);
            if(!$this->db->transStatus())throw new RuntimeException('No fue posible persistir la conciliacion.');
            $this->db->transCommit();
        }catch(Throwable$e){$this->db->transRollback();throw$e;}
        try{$this->stampAccounts->consumeFromReconciliation($attemptId,$stampId,$userId);}
        catch(Throwable $e){$this->db->table('fiscal_stamp_attempts')->where('id',$attemptId)->update(['status'=>'success_local_reconciliation_pending','error_category'=>'wallet_persistence','recommended_action'=>'Reintentar solo el consumo local de la reserva.','updated_at'=>get_current_utc_time()]);throw new RuntimeException('El timbre fue conciliado; el consumo local de wallet queda pendiente.',0,$e);}
        if((int)($document->source_draft_id??0)>0){(new FiscalSaleAllocationService($this->db))->convertDraftAllocationsToDocument((int)$document->source_draft_id,(int)$document->id,$userId);$this->db->table('fiscal_drafts')->where('id',$document->source_draft_id)->update(['status'=>'stamped','fiscal_document_id'=>$document->id,'updated_by'=>$userId,'updated_at'=>get_current_utc_time()]);}
        return['attempt_id'=>$attemptId,'document_id'=>(int)$document->id,'stamp_id'=>$stampId,'uuid'=>$validated['uuid'],'artifact_id'=>(int)$artifact->id,'outcome'=>'stamped'];
    }

    public function consumeConfirmedStamp(int$id,int$stamp,int$user,bool$authorized):array{if(!$authorized)throw new RuntimeException('No tiene permiso para conciliar timbrados.');return(array)$this->stampAccounts->consumeFromReconciliation($id,$stamp,$user);}
    public function releaseDefinitiveRejection(int$id,int$user,bool$authorized):array{if(!$authorized)throw new RuntimeException('No tiene permiso para conciliar timbrados.');return(array)$this->stampAccounts->releaseFromReconciliation($id,$user);}

    private function statusQuery(object$attempt):array
    {
        $document=$this->db->table('fiscal_documents')->where('id',$attempt->fiscal_document_id)->get(1)->getRow();
        $issuer=$document?$this->db->table('fiscal_document_issuers')->where('fiscal_document_id',$document->id)->get(1)->getRow():null;
        $receiver=$document?$this->db->table('fiscal_document_receivers')->where('fiscal_document_id',$document->id)->get(1)->getRow():null;
        return['attempt_id'=>(int)$attempt->id,'environment'=>(string)$attempt->environment,'endpoint'=>$attempt->pac_endpoint??null,'uuid'=>(string)($attempt->uuid??''),'rfcEmisor'=>(string)($issuer->rfc??''),'rfcReceptor'=>(string)($receiver->rfc??''),'total'=>(string)($document->total??'')];
    }
    private function closeDefinitiveNotFound(object$attempt,int$userId,PacResponse$response):array
    {
        $now=get_current_utc_time();
        $this->db->transStart();
        $this->db->table('fiscal_stamp_attempts')->where('id',$attempt->id)->update(['status'=>'reconciled_not_found','requires_reconciliation'=>0,'retryable'=>0,'provider_message'=>$response->message,'reconciled_at'=>$now,'reconciled_by'=>$userId,'reconciliation_source'=>'provider_definitive_not_found','updated_at'=>$now]);
        $this->db->table('fiscal_documents')->where('id',$attempt->fiscal_document_id)->update(['status'=>'stamping_error','stamp_updated_at'=>$now]);
        $this->db->transComplete();
        try{$this->stampAccounts->releaseFromReconciliation((int)$attempt->id,$userId);}
        catch(Throwable $e){$this->db->table('fiscal_stamp_attempts')->where('id',$attempt->id)->update(['status'=>'reconciled_not_found_wallet_pending','error_category'=>'wallet_persistence','recommended_action'=>'Reintentar solo la liberacion local de la reserva.','updated_at'=>get_current_utc_time()]);throw new RuntimeException('La conciliacion cerro sin timbre; la liberacion local queda pendiente.',0,$e);}
        return['attempt_id'=>(int)$attempt->id,'document_id'=>(int)$attempt->fiscal_document_id,'outcome'=>'not_found_definitive','requires_reconciliation'=>false,'automatic_retry'=>false];
    }
    private function keepPending(int$id,string$outcome,string$message):void{$this->db->table('fiscal_stamp_attempts')->where('id',$id)->update(['status'=>'reconciliation_required','requires_reconciliation'=>1,'retryable'=>0,'error_category'=>$outcome==='error'?'reconciliation_error':'status_unknown','provider_message'=>$message,'updated_at'=>get_current_utc_time()]);}
    private function sqlDate(string$value):string{$time=strtotime($value);if($time===false)throw new RuntimeException('Fecha de timbrado invalida.');return date('Y-m-d H:i:s',$time);}
}
