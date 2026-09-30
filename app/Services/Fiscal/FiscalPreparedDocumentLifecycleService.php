<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Services\Fiscal\Stamps\FiscalStampAccountService;
use RuntimeException;
use Throwable;

final class FiscalPreparedDocumentLifecycleService
{
    private const UNCERTAIN = ['pending','sending','unknown','timeout_unknown','transport_unknown','duplicate_reported','response_invalid','stamp_data_invalid','reconciliation_required'];

    public function __construct(private mixed $db=null,private ?FiscalDraftSnapshotHashService $hasher=null)
    {
        $this->db??=db_connect();
        $this->hasher??=new FiscalDraftSnapshotHashService();
    }

    public function assertDraftEditable(int $draftId):void
    {
        $draft=$this->db->table('fiscal_drafts')->select('fiscal_document_id')->where('id',$draftId)->get(1)->getRow();
        $documentId=(int)($draft->fiscal_document_id??0);
        if(!$documentId)return;
        $document=$this->db->table('fiscal_documents')->select('id,status')->where('id',$documentId)->get(1)->getRow();
        if($document&&$this->isProtected($documentId,(string)$document->status))throw new RuntimeException('La preparacion fiscal esta protegida por un timbre o un resultado PAC pendiente de conciliacion.');
    }

    public function invalidateIfSnapshotChanged(int $draftId,int $userId):array
    {
        $draft=$this->db->table('fiscal_drafts')->select('fiscal_document_id')->where('id',$draftId)->get(1)->getRow();
        $documentId=(int)($draft->fiscal_document_id??0);
        if(!$documentId)return['prepared'=>false,'action'=>'none'];
        $document=$this->db->table('fiscal_documents')->select('id,status,source_snapshot_hash')->where(['id'=>$documentId,'deleted'=>0])->get(1)->getRow();
        if(!$document){$this->detachDraft($draftId,$documentId,$userId);return['prepared'=>false,'action'=>'missing_document_detached'];}
        $currentHash=$this->hasher->hash((new FiscalDraftSnapshotService($this->db))->getCompleteFiscalSnapshot($draftId));
        $oldHash=(string)$document->source_snapshot_hash;
        $hasStamp=(bool)$this->db->table('fiscal_document_stamps')->where('fiscal_document_id',$documentId)->countAllResults();
        $decision=self::decision($oldHash,$currentHash,$hasStamp,$this->attemptEvidence($documentId),(string)$document->status);
        if($decision==='reuse')return['prepared'=>true,'action'=>'reused','document_id'=>$documentId,'snapshot_hash'=>$currentHash];
        if($decision==='protected')throw new RuntimeException('El documento fiscal tiene un timbre o un resultado PAC pendiente de conciliacion.');
        $this->db->transStart();
        $this->db->table('fiscal_documents')->where('id',$documentId)->update(['status'=>'superseded','stamp_updated_at'=>get_current_utc_time()]);
        $this->detachDraft($draftId,$documentId,$userId);
        $this->db->table('fiscal_draft_audit')->insert(['fiscal_draft_id'=>$draftId,'sale_id'=>null,'user_id'=>$userId,'event'=>'draft_prepared_document_invalidated','summary_json'=>json_encode(['draft_id'=>$draftId,'old_document_id'=>$documentId,'reason'=>'snapshot_changed','old_source_snapshot_hash'=>$oldHash?:null,'current_snapshot_hash'=>$currentHash],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>get_current_utc_time()]);
        $this->db->transComplete();
        return['prepared'=>false,'action'=>'invalidated','document_id'=>$documentId,'snapshot_hash'=>$currentHash];
    }

    /** Invalidates the prepared document only after durable proof of no-send. */
    public function invalidateConfirmedNotSent(int $attemptId,int $userId):array
    {
        $lock='fiscal_retry_invalidate_'.$attemptId;
        if((int)($this->db->query('SELECT GET_LOCK(?,0) acquired',[$lock])->getRow('acquired')??0)!==1)throw new RuntimeException('Ya existe una invalidacion o retry en curso para este intento.');
        try{
            $this->db->transBegin();
            $table=$this->db->protectIdentifiers($this->db->prefixTable('fiscal_stamp_attempts'));
            $attempt=$this->db->query("SELECT * FROM {$table} WHERE id=? FOR UPDATE",[$attemptId])->getRow();
            if(!$attempt||!self::safeToInvalidateAttempt($attempt))throw new RuntimeException('El intento no tiene evidencia suficiente de transport_not_sent.');
            $documentId=(int)$attempt->fiscal_document_id;
            if($this->db->table('fiscal_document_stamps')->where('fiscal_document_id',$documentId)->countAllResults())throw new RuntimeException('El documento ya tiene timbre.');
            if($this->db->table('fiscal_document_artifacts')->where(['fiscal_document_id'=>$documentId,'artifact_type'=>'stamped_xml'])->countAllResults())throw new RuntimeException('El documento ya tiene XML timbrado.');
            $draft=$this->db->table('fiscal_drafts')->select('id')->where('fiscal_document_id',$documentId)->get(1)->getRow();
            $now=get_current_utc_time();
            $this->db->table('fiscal_stamp_attempts')->where('id',$attemptId)->update(['status'=>'transport_not_sent_invalidated','retryable'=>0,'reconciled_at'=>$now,'reconciled_by'=>$userId,'reconciliation_source'=>'confirmed_transport_not_sent','updated_at'=>$now]);
            $this->db->table('fiscal_documents')->where('id',$documentId)->update(['status'=>'superseded','stamp_updated_at'=>$now]);
            if($draft)$this->detachDraft((int)$draft->id,$documentId,$userId);
            if(!$this->db->transStatus())throw new RuntimeException('No fue posible invalidar el documento preparado.');
            $this->db->transCommit();
            (new FiscalStampAccountService($this->db))->releaseReservation($attemptId,'Confirmed transport_not_sent; prepared document invalidated.',$userId);
            return['attempt_id'=>$attemptId,'document_id'=>$documentId,'action'=>'invalidated_for_safe_retry'];
        }catch(Throwable $e){$this->db->transRollback();throw$e;}finally{$this->db->query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }

    public static function safeToInvalidateAttempt(array|object $attempt):bool
    {
        $v=static fn(string$key,mixed$default=null):mixed=>is_array($attempt)?($attempt[$key]??$default):($attempt->{$key}??$default);
        return(string)$v('status')==='transport_not_sent'&&(int)$v('request_sent',-1)===0&&(int)$v('requires_reconciliation',0)===0&&trim((string)$v('uuid',''))===''&&trim((string)$v('contingency_path',''))===''&&trim((string)$v('response_hash',''))===''&&trim((string)$v('response_body_sha256',''))===''&&trim((string)$v('provider_code',''))==='';
    }

    public static function decision(string$oldHash,string$currentHash,bool$hasStamp,array$attempts,string$documentStatus='locked'):string
    {
        if($hasStamp||$documentStatus==='stamped')return'protected';
        foreach($attempts as$attempt){$v=static fn(string$key,mixed$default=null):mixed=>is_array($attempt)?($attempt[$key]??$default):($attempt->{$key}??$default);if(trim((string)$v('uuid',''))!==''||(int)$v('requires_reconciliation',0)===1||in_array((string)$v('status',''),self::UNCERTAIN,true))return'protected';}
        if($oldHash!==''&&hash_equals($oldHash,$currentHash))return'reuse';
        return'invalidate';
    }

    private function isProtected(int$documentId,string$status):bool
    {
        $stamp=(bool)$this->db->table('fiscal_document_stamps')->where('fiscal_document_id',$documentId)->countAllResults();
        return self::decision('different','current',$stamp,$this->attemptEvidence($documentId),$status)==='protected';
    }
    private function attemptEvidence(int$documentId):array{return$this->db->table('fiscal_stamp_attempts')->select('status,requires_reconciliation,uuid,request_sent,contingency_path,response_hash,response_body_sha256,provider_code')->where('fiscal_document_id',$documentId)->get()->getResultArray();}
    private function detachDraft(int$draftId,int$documentId,int$userId):void{$this->db->table('fiscal_drafts')->where(['id'=>$draftId,'fiscal_document_id'=>$documentId])->update(['fiscal_document_id'=>null,'updated_by'=>$userId,'updated_at'=>get_current_utc_time()]);}
}
