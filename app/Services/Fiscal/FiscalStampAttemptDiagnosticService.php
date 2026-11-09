<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Services\Fiscal\Pac\PacContingencyStorageService;
use App\Services\Fiscal\Pac\PacSecretVault;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/** Read-only, non-secret diagnosis for one PAC stamping attempt. */
final class FiscalStampAttemptDiagnosticService
{
    public function __construct(private BaseConnection $db,private ?PacSecretVault $vault=null,private ?string $root=null){}

    public function inspect(int $attemptId):array
    {
        $attempt=$this->db->table('fiscal_stamp_attempts')->where('id',$attemptId)->get(1)->getRowArray();
        if(!$attempt)return['status'=>'NOT_FOUND','attempt_id'=>$attemptId];
        $document=$this->db->table('fiscal_documents')->select('id,status,environment')->where('id',$attempt['fiscal_document_id'])->get(1)->getRowArray();
        $stamp=$this->db->table('fiscal_document_stamps')->select('id,uuid,stamped_xml_artifact_id,stamped_xml_sha256')->where('fiscal_document_id',$attempt['fiscal_document_id'])->get(1)->getRowArray();
        $artifact=$stamp&&$this->db->tableExists('fiscal_document_artifacts')
            ?$this->db->table('fiscal_document_artifacts')->select('id,artifact_type,validation_status,sha256')->where('id',$stamp['stamped_xml_artifact_id'])->get(1)->getRowArray():null;
        $evidence=$this->evidence((string)($attempt['contingency_path']??''));
        $movements=$this->db->tableExists('fiscal_stamp_movements')
            ?$this->db->table('fiscal_stamp_movements')->select('movement_type,quantity,reserved_before,reserved_after,available_before,available_after')->where('pac_attempt_id',$attemptId)->orderBy('id','ASC')->get()->getResultArray():[];
        $types=array_column($movements,'movement_type');
        $uuid=trim((string)($stamp['uuid']??$attempt['uuid']??''));
        $method=$stamp?'local_stamp':($evidence['valid']?'stored_evidence':($uuid!==''?'uuid_status_query':'manual_provider_review'));
        return[
            'status'=>'OK','attempt_id'=>$attemptId,'document_id'=>(int)$attempt['fiscal_document_id'],
            'attempt_status'=>(string)$attempt['status'],'document_status'=>(string)($document['status']??''),
            'environment'=>(string)($attempt['environment']??$document['environment']??''),
            'error_category'=>$attempt['error_category']??null,'parsing_phase'=>$attempt['parsing_phase']??null,
            'http_status'=>isset($attempt['http_status'])?(int)$attempt['http_status']:null,
            'provider_code'=>$attempt['provider_code']??null,'request_sent'=>$this->nullableBool($attempt['request_sent']??null),
            'response_received'=>!empty($attempt['response_received_at']),
            'requires_reconciliation'=>(bool)($attempt['requires_reconciliation']??false),'retryable'=>(bool)($attempt['retryable']??false),
            'uuid_present'=>$uuid!=='','stamped_xml_recorded'=>(bool)$stamp,
            'stamped_artifact_valid'=>(bool)$artifact&&($artifact['validation_status']??'')==='valid',
            'evidence'=>$evidence,
            'wallet'=>['reservation_recorded'=>in_array('document_reservation',$types,true),
                'consumption_recorded'=>in_array('document_consumption',$types,true)||in_array('reconciliation_consumption',$types,true),
                'release_recorded'=>in_array('document_release',$types,true)||in_array('reconciliation_release',$types,true),
                'movement_count'=>count($movements)],
            'reconciliation'=>['possible_without_resend'=>$method!=='manual_provider_review','method'=>$method,
                'automatic_resend_allowed'=>false],
        ];
    }

    private function evidence(string $path):array
    {
        if($path==='')return['present'=>false,'valid'=>false,'kind'=>null,'error'=>null];
        try{
            $payload=(new PacContingencyStorageService($this->vault??new PacSecretVault(),$this->root))->read($path);
            $trim=ltrim($payload);json_decode($payload,true);$jsonValid=json_last_error()===JSON_ERROR_NONE;
            $kind=(str_starts_with($trim,'<?xml')||str_starts_with($trim,'<cfdi:Comprobante'))?'stamped_xml':($jsonValid?'provider_json':'unknown');
            return['present'=>true,'valid'=>$kind!=='unknown','kind'=>$kind,'sha256'=>hash('sha256',$payload),'error'=>null];
        }catch(Throwable$e){return['present'=>true,'valid'=>false,'kind'=>null,'error'=>'EVIDENCE_UNREADABLE','error_class'=>get_class($e)];}
    }
    private function nullableBool(mixed$value):?bool{return$value===null?null:(bool)$value;}
}
