<?php
declare(strict_types=1);

namespace App\Services\Fiscal\SatStatus;

use App\Contracts\Fiscal\Pac\PacAdapterInterface;
use App\Domain\Fiscal\Pac\PacResponse;
use App\Services\Fiscal\Cancellation\TimbradorXpressCancellationAdapter;
use App\Services\Fiscal\Cancellation\FiscalCancellationStatusMapper;
use App\Services\Fiscal\FiscalArtifactStorageService;
use App\Services\Fiscal\Pac\FiscalPacAdapterFactory;
use App\Services\Fiscal\Stamps\FiscalStampAccountService;
use RuntimeException;
use Throwable;

final class FiscalSatStatusService
{
    private $db;
    private $evidenceVerifier;
    private $cancellationQuery;
    private $clock;

    public function __construct(
        $db = null,
        private readonly ?PacAdapterInterface $adapter = null,
        private readonly ?array $runtimeContext = null,
        ?callable $evidenceVerifier = null,
        ?callable $cancellationQuery = null,
        ?callable $clock = null
    ) {
        $this->db = $db ?: db_connect();
        $this->evidenceVerifier = $evidenceVerifier;
        $this->cancellationQuery = $cancellationQuery;
        $this->clock = $clock;
    }

    public function context(int $documentId): array
    {
        [$document,$stamp] = $this->document($documentId);
        $latest = $this->db->table('fiscal_sat_status_queries')->where('fiscal_document_id',$documentId)->orderBy('id','DESC')->get(1)->getRow();
        $cancellation = $this->activeCancellation($documentId);
        $reason = null;
        try { $this->assertEvidence($document,$stamp); } catch (Throwable $e) { $reason=$e->getMessage(); }
        return [
            'document'=>$document,'stamp'=>$stamp,'latest'=>$latest,'cancellation'=>$cancellation,
            'eligible'=>$reason===null,'blocked_reason'=>$reason,
            'environment'=>(string)$document->environment,
            'delegates_to_cancellation'=>$cancellation!==null,
            'commercial_policy'=>'one_credit_per_executed_query',
            'may_have_provider_cost'=>true,
        ];
    }

    public function query(int $documentId, int $userId, bool $authorized, string $idempotencyKey): array
    {
        if (!$authorized) throw new RuntimeException('No tiene permiso para consultar el estado SAT.');
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/',$idempotencyKey)) throw new RuntimeException('La clave idempotente de consulta no es válida.');

        $existing=$this->db->table('fiscal_sat_status_queries')->where('idempotency_key',$idempotencyKey)->get(1)->getRow();
        if($existing){
            if((int)$existing->fiscal_document_id!==$documentId)throw new RuntimeException('La referencia de consulta pertenece a otro documento.');
            $this->audit($documentId,$userId,'sat_status_query_replayed',['query_id'=>(int)$existing->id]);
            return$this->response($existing,true);
        }

        [$document,$stamp,$issuer,$receiver]=$this->document($documentId,true);
        $this->assertEvidence($document,$stamp);
        $context=$this->contextRuntime();
        if((string)$document->environment!==$context['fiscal_environment'])throw new RuntimeException('El CFDI pertenece a otro ambiente fiscal; no se cambiará el ambiente automáticamente.');

        $cancellation=$this->activeCancellation($documentId);
        if($cancellation){
            if(!$this->cancellationQuery)throw new RuntimeException('La consulta debe continuar en el flujo de cancelación existente.');
            return $this->delegateCancellation($document,$stamp,$cancellation,$context,$userId,$idempotencyKey);
        }

        $now=$this->now();$latest=$this->db->table('fiscal_sat_status_queries')->where('fiscal_document_id',$documentId)->orderBy('id','DESC')->get(1)->getRow();
        if($latest&&!empty($latest->cooldown_until)&&strtotime((string)$latest->cooldown_until)>strtotime($now))throw new RuntimeException('La consulta está en periodo de espera. Revise el último resultado antes de consultar nuevamente.');
        $previous=$latest?(string)$latest->authoritative_status:$this->authoritativeFromDocument((string)$document->status);
        $cooldown=max(0,min(3600,(int)(config('Fiscal')->satStatusCooldownSeconds??60)));
        $row=['fiscal_document_id'=>$documentId,'fiscal_document_stamp_id'=>(int)$stamp->id,'cancellation_request_id'=>null,'uuid'=>strtoupper((string)$stamp->uuid),
            'provider'=>$context['pac_provider'],'environment'=>$context['fiscal_environment'],'idempotency_key'=>$idempotencyKey,'lock_document_id'=>$documentId,
            'status'=>'querying','normalized_status'=>'unknown','authoritative_status'=>$previous,'previous_authoritative_status'=>$previous,
            'request_sent'=>0,'timed_out'=>0,'requires_reconciliation'=>0,'commercial_policy'=>'one_credit_per_executed_query','commercial_credit_delta'=>0,
            'may_have_provider_cost'=>1,'queried_by'=>$userId,'started_at'=>$now,'completed_at'=>null,'cooldown_until'=>date('Y-m-d H:i:s',strtotime($now)+$cooldown),'created_at'=>$now,'updated_at'=>$now];
        try{$this->db->table('fiscal_sat_status_queries')->insert($row);$queryId=(int)$this->db->insertID();}
        catch(Throwable$e){$race=$this->db->table('fiscal_sat_status_queries')->where('idempotency_key',$idempotencyKey)->get(1)->getRow();if($race)return$this->response($race,true);throw new RuntimeException('Ya existe una consulta en curso para este CFDI.',0,$e);}
        $this->audit($documentId,$userId,'sat_status_query_started',['query_id'=>$queryId,'environment'=>$context['fiscal_environment']]);

        try{
            $pac=$this->queryProvider(['environment'=>$context['transport_environment'],'uuid'=>$stamp->uuid,'rfcEmisor'=>$issuer->rfc,'rfcReceptor'=>$receiver->rfc,'total'=>$document->total]);
            $payload=['code'=>$pac->code,'message'=>$pac->message,'data'=>$this->decoded($pac->data)];
            $mapped=(new FiscalCancellationStatusMapper())->map('consultarEstadoSAT',$payload,$pac->httpStatus);
            $normalized=$pac->transportError?'transport_error':match($mapped['cancellation_status']){'cancelled'=>'cancelled','pending'=>'cancellation_pending','pending_acceptance'=>'pending_acceptance','rejected'=>'rejected','none'=>$mapped['fiscal_status']==='active'?'active':'unknown',default=>'unknown'};
            $definitive=in_array($normalized,['active','cancelled'],true);$authoritative=$definitive?$normalized:$previous;
            $requires=$pac->transportError||!$definitive;
            $evidence=json_encode(['code'=>$pac->code,'message'=>$this->sanitize($pac->message),'data'=>$this->redact($this->decoded($pac->data)),'http_status'=>$pac->httpStatus,'transport_error'=>$pac->transportError,'timeout'=>$pac->timeout],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $this->storeArtifact($queryId,$evidence,$userId,$now);
            $requestSent=(bool)($pac->metadata['request_sent']??true);$charged=false;
            if($requestSent&&$context['pac_provider']!=='fake'){(new FiscalStampAccountService($this->db))->consumeSatStatusQuery($documentId,$idempotencyKey,$userId);$charged=true;}
            $update=['lock_document_id'=>null,'status'=>$pac->transportError?'transport_error':'completed','normalized_status'=>$normalized,'authoritative_status'=>$authoritative,
                'provider_code'=>$pac->code,'provider_message'=>$this->sanitize($pac->message),'http_status'=>$pac->httpStatus,'request_sent'=>(int)($pac->metadata['request_sent']??true),
                'timed_out'=>$pac->timeout?1:0,'requires_reconciliation'=>$requires?1:0,'commercial_credit_delta'=>$charged?-1:0,'completed_at'=>$this->now(),'updated_at'=>$this->now()];
            $this->db->table('fiscal_sat_status_queries')->where('id',$queryId)->update($update);
            $this->audit($documentId,$userId,'sat_status_query_completed',['query_id'=>$queryId,'normalized_status'=>$normalized,'authoritative_status'=>$authoritative,'evidence_sha256'=>hash('sha256',$evidence)]);
        }catch(Throwable$e){
            $done=$this->now();$this->db->table('fiscal_sat_status_queries')->where('id',$queryId)->update(['lock_document_id'=>null,'status'=>'transport_error','normalized_status'=>'transport_error','authoritative_status'=>$previous,'provider_message'=>'No fue posible confirmar la respuesta del PAC.','request_sent'=>0,'requires_reconciliation'=>1,'completed_at'=>$done,'updated_at'=>$done]);
            $this->audit($documentId,$userId,'sat_status_query_failed',['query_id'=>$queryId,'error_type'=>get_class($e)]);
        }
        return$this->response($this->db->table('fiscal_sat_status_queries')->where('id',$queryId)->get(1)->getRow(),false);
    }

    private function document(int$id,bool$full=false):array
    {
        $d=$this->db->table('fiscal_documents')->where(['id'=>$id,'deleted'=>0])->get(1)->getRow();$s=$this->db->table('fiscal_document_stamps')->where('fiscal_document_id',$id)->get(1)->getRow();
        if(!$d||!$s)throw new RuntimeException('Sólo un CFDI timbrado puede consultarse.');
        if(!$full)return[$d,$s];
        $i=$this->db->table('fiscal_document_issuers')->where('fiscal_document_id',$id)->get(1)->getRow();$r=$this->db->table('fiscal_document_receivers')->where('fiscal_document_id',$id)->get(1)->getRow();
        if(!$i||!$r)throw new RuntimeException('Falta la identidad fiscal congelada del CFDI.');return[$d,$s,$i,$r];
    }
    private function delegateCancellation(object$document,object$stamp,object$cancellation,array$context,int$userId,string$key):array
    {
        $now=$this->now();$latest=$this->db->table('fiscal_sat_status_queries')->where('fiscal_document_id',$document->id)->orderBy('id','DESC')->get(1)->getRow();
        if($latest&&!empty($latest->cooldown_until)&&strtotime((string)$latest->cooldown_until)>strtotime($now))throw new RuntimeException('La consulta está en periodo de espera. Revise el último resultado antes de consultar nuevamente.');
        $previous=$latest?(string)$latest->authoritative_status:$this->authoritativeFromDocument((string)$document->status);$cooldown=max(0,min(3600,(int)(config('Fiscal')->satStatusCooldownSeconds??60)));
        $row=['fiscal_document_id'=>(int)$document->id,'fiscal_document_stamp_id'=>(int)$stamp->id,'cancellation_request_id'=>(int)$cancellation->id,'uuid'=>strtoupper((string)$stamp->uuid),
            'provider'=>$context['pac_provider'],'environment'=>$context['fiscal_environment'],'idempotency_key'=>$key,'lock_document_id'=>(int)$document->id,'status'=>'querying','normalized_status'=>'unknown',
            'authoritative_status'=>$previous,'previous_authoritative_status'=>$previous,'request_sent'=>0,'timed_out'=>0,'requires_reconciliation'=>0,
            'commercial_policy'=>'existing_cancellation_policy_one_credit','commercial_credit_delta'=>0,'may_have_provider_cost'=>1,'queried_by'=>$userId,'started_at'=>$now,
            'completed_at'=>null,'cooldown_until'=>date('Y-m-d H:i:s',strtotime($now)+$cooldown),'created_at'=>$now,'updated_at'=>$now];
        try{$this->db->table('fiscal_sat_status_queries')->insert($row);$queryId=(int)$this->db->insertID();}
        catch(Throwable$e){$race=$this->db->table('fiscal_sat_status_queries')->where('idempotency_key',$key)->get(1)->getRow();if($race)return$this->response($race,true);throw new RuntimeException('Ya existe una consulta en curso para este CFDI.',0,$e);}
        $this->audit((int)$document->id,$userId,'sat_status_query_delegated',['query_id'=>$queryId,'cancellation_request_id'=>(int)$cancellation->id]);
        try{
            $result=($this->cancellationQuery)((int)$document->id,$userId,$key);$status=(string)($result['status']??'unknown');
            $normalized=match($status){'accepted'=>'cancelled','pending'=>'cancellation_pending','pending_acceptance'=>'pending_acceptance','rejected','transport_not_sent'=>'rejected',default=>'unknown'};
            $authoritative=$normalized==='cancelled'?'cancelled':$previous;$requires=$normalized==='unknown';
            $movementKey='cancellation-status-query:'.$cancellation->id.':'.$key;$charged=$this->db->table('fiscal_stamp_movements')->where('idempotency_key',$movementKey)->countAllResults()>0;
            $evidence=json_encode(['delegated'=>true,'status'=>$status,'provider_code'=>$result['provider_code']??null,'provider_message'=>$this->sanitize((string)($result['provider_message']??$result['message']??''))],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $this->storeArtifact($queryId,$evidence,$userId,$now);$done=$this->now();
            $this->db->table('fiscal_sat_status_queries')->where('id',$queryId)->update(['lock_document_id'=>null,'status'=>'completed','normalized_status'=>$normalized,'authoritative_status'=>$authoritative,
                'provider_code'=>$result['provider_code']??null,'provider_message'=>$this->sanitize((string)($result['provider_message']??$result['message']??'')),'http_status'=>$result['http_status']??null,
                'request_sent'=>1,'requires_reconciliation'=>$requires?1:0,'commercial_credit_delta'=>$charged?-1:0,'completed_at'=>$done,'updated_at'=>$done]);
        }catch(Throwable$e){$done=$this->now();$this->db->table('fiscal_sat_status_queries')->where('id',$queryId)->update(['lock_document_id'=>null,'status'=>'transport_error','normalized_status'=>'transport_error','authoritative_status'=>$previous,'provider_message'=>'No fue posible confirmar la consulta de cancelación.','requires_reconciliation'=>1,'completed_at'=>$done,'updated_at'=>$done]);$this->audit((int)$document->id,$userId,'sat_status_query_failed',['query_id'=>$queryId,'delegated'=>true,'error_type'=>get_class($e)]);}
        return$this->response($this->db->table('fiscal_sat_status_queries')->where('id',$queryId)->get(1)->getRow(),false);
    }
    private function assertEvidence(object$d,object$s):void
    {
        if(!preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[1-5][0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i',(string)$s->uuid))throw new RuntimeException('El CFDI no tiene un UUID válido.');
        if(empty($s->stamped_xml_artifact_id))throw new RuntimeException('El CFDI no tiene evidencia XML timbrada íntegra.');
        if($this->evidenceVerifier){if(!(bool)($this->evidenceVerifier)($d,$s))throw new RuntimeException('La evidencia XML timbrada no supera integridad.');return;}
        $a=$this->db->table('fiscal_document_artifacts')->where(['id'=>(int)$s->stamped_xml_artifact_id,'fiscal_document_id'=>(int)$d->id,'artifact_type'=>'stamped_xml','superseded_at'=>null])->get(1)->getRow();
        if(!$a)throw new RuntimeException('El CFDI no tiene evidencia XML timbrada íntegra.');$xml=(new FiscalArtifactStorageService($this->db))->read($a);
        if(!str_contains(strtoupper($xml),strtoupper((string)$s->uuid)))throw new RuntimeException('El UUID no coincide con la evidencia XML timbrada.');
    }
    private function activeCancellation(int$id):?object{$r=$this->db->table('fiscal_cancellation_requests')->where('fiscal_document_id',$id)->orderBy('id','DESC')->get(1)->getRow();return$r&&in_array((string)$r->status,['requested','sending','pending','pending_acceptance','unknown'],true)?$r:null;}
    private function contextRuntime():array{return$this->runtimeContext??$this->factory()->context();}
    private function queryProvider(array$query):PacResponse
    {
        if($this->adapter!==null)return$this->adapter->getStampStatus($query);
        $result=(new TimbradorXpressCancellationAdapter())->query(['uuid'=>$query['uuid'],'issuer_rfc'=>$query['rfcEmisor'],'receiver_rfc'=>$query['rfcReceptor'],'total'=>$query['total']]);
        $http=(int)($result['http_status']??0);$sent=(bool)($result['request_sent']??false);
        return new PacResponse($result['code']??null,(string)($result['message']??'Respuesta de consulta SAT.'),
            json_encode($result['evidence']??$result['normalized']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            $http,['request_sent'=>$sent,'operation'=>'consultarEstadoSAT'],$sent&&$http===0,false);
    }
    private function factory():FiscalPacAdapterFactory{return new FiscalPacAdapterFactory();}
    private function decoded(?string$data):mixed{if($data===null)return null;$decoded=json_decode($data,true);return json_last_error()===JSON_ERROR_NONE?$decoded:$data;}
    private function redact(mixed$v,?string$key=null):mixed{if($key&&preg_match('/api.?key|token|password|private|certificate|xml/i',$key))return'[REDACTED]';if(is_array($v)){foreach($v as$k=>$e)$v[$k]=$this->redact($e,(string)$k);return$v;}return is_string($v)&&strlen($v)>10000?mb_substr($v,0,10000):$v;}
    private function sanitize(string$v):string{return mb_substr(trim(strip_tags((string)preg_replace('/[\x00-\x1F\x7F]+/u',' ',$v))),0,500);}
    private function storeArtifact(int$id,string$bytes,int$user,string$now):void{$this->db->table('fiscal_sat_status_artifacts')->insert(['fiscal_sat_status_query_id'=>$id,'artifact_type'=>'provider_response','content_encoding'=>'base64','content_base64'=>base64_encode($bytes),'decoded_mime_type'=>'application/json','decoded_size_bytes'=>strlen($bytes),'decoded_sha256'=>hash('sha256',$bytes),'created_by'=>$user,'created_at'=>$now]);}
    private function authoritativeFromDocument(string$status):string{return$status==='cancelled'?'cancelled':(in_array($status,['stamped','stamped_pdf_pending','stamped_pdf_error','cancellation_pending','cancellation_rejected'],true)?'active':'unknown');}
    private function now():string{return$this->clock?(string)($this->clock)():get_current_utc_time();}
    private function audit(int$id,int$user,string$action,array$reason):void{if(!$this->db->tableExists('fiscal_document_audit'))return;$d=$this->db->table('fiscal_documents')->select('invoice_id')->where('id',$id)->get(1)->getRow();$this->db->table('fiscal_document_audit')->insert(['fiscal_document_id'=>$id,'invoice_id'=>$d->invoice_id??null,'user_id'=>$user,'action'=>$action,'reason'=>json_encode($reason,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>$this->now()]);}
    private function response(object$r,bool$replayed):array{return['success'=>in_array((string)$r->normalized_status,['active','cancelled','cancellation_pending','pending_acceptance','rejected'],true),'query_id'=>(int)$r->id,'status'=>(string)$r->normalized_status,'authoritative_status'=>(string)$r->authoritative_status,'requires_reconciliation'=>(bool)$r->requires_reconciliation,'environment'=>(string)$r->environment,'provider_code'=>$r->provider_code,'provider_message'=>$r->provider_message,'last_queried_at'=>$r->completed_at?:$r->started_at,'idempotent_replay'=>$replayed,'commercial_policy'=>(string)$r->commercial_policy,'commercial_credit_delta'=>(int)$r->commercial_credit_delta,'may_have_provider_cost'=>(bool)$r->may_have_provider_cost,'message'=>$this->label((string)$r->normalized_status)];}
    private function label(string$s):string{return match($s){'active'=>'El CFDI continúa vigente.','cancelled'=>'El CFDI está cancelado.','cancellation_pending'=>'La cancelación continúa pendiente.','pending_acceptance'=>'La cancelación está pendiente de aceptación del receptor.','rejected'=>'La solicitud de cancelación fue rechazada.','transport_error'=>'No fue posible conectar con el PAC; se conservó el estado anterior.','unknown'=>'El SAT devolvió un resultado desconocido; se conservó el estado anterior.',default=>'La consulta requiere conciliación.'};}
}
