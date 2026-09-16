<?php
declare(strict_types=1);
namespace App\Services\Fiscal\Pac;
use RuntimeException;
use App\Services\Fiscal\Stamps\FiscalStampAccountService;
use App\Services\Fiscal\FiscalArtifactStorageService;
use App\Services\Fiscal\FiscalSaleAllocationService;
use App\Domain\Fiscal\Pac\PacResponse;

final class FiscalStampReconciliationService
{
    private FiscalStampAccountService $stampAccounts;
    public function __construct(private $db=null,private ?PacSecretVault $vault=null,private ?string $contingencyRoot=null,?FiscalStampAccountService $stampAccounts=null){$this->db=$db?:db_connect();$this->stampAccounts=$stampAccounts??new FiscalStampAccountService($this->db);}
    public function recoverFromContingency(int $attemptId,int $userId,bool $authorized):array
    {
        if(!$authorized)throw new RuntimeException('No tiene permiso para conciliar timbrados.');
        $attempt=$this->db->table('fiscal_stamp_attempts')->where('id',$attemptId)->get(1)->getRow();
        if(!$attempt||!in_array($attempt->status,['reconciliation_required','duplicate_reported','timeout_unknown','response_invalid'],true))throw new RuntimeException('El intento no admite conciliación.');
        if(!$attempt->contingency_path)throw new RuntimeException('No existe XML de contingencia; no se reenviará automáticamente.');
        $vault=$this->vault??new PacSecretVault();
        $xml=(new PacContingencyStorageService($vault,$this->contingencyRoot))->read($attempt->contingency_path);
        return ['attempt'=>$attempt,'xml'=>$xml,'sha256'=>hash('sha256',$xml),'requires_validation'=>true,'automatic_resend'=>false];
    }

    public function reconcileStoredProviderResponse(int$attemptId,int$userId,bool$authorized):array
    {
        if(!$authorized)throw new RuntimeException('No tiene permiso para conciliar timbrados.');
        $attempt=$this->db->table('fiscal_stamp_attempts')->where('id',$attemptId)->get(1)->getRow();
        if(!$attempt||(int)$attempt->requires_reconciliation!==1||!in_array($attempt->status,['stamp_data_invalid','response_invalid','reconciliation_required'],true))throw new RuntimeException('El intento no admite conciliación desde respuesta almacenada.');
        $outer=(new PacContingencyStorageService($this->vault??new PacSecretVault(),$this->contingencyRoot))->read((string)$attempt->contingency_path);
        $payload=json_decode($outer,true,16,JSON_THROW_ON_ERROR);if(!is_array($payload)||!isset($payload['data'])||!is_string($payload['data']))throw new RuntimeException('La evidencia no contiene data PAC utilizable.');
        $data=(new TimbradorXpressStampDataParser())->parse(new PacResponse((string)($payload['code']??''),(string)($payload['message']??''),$payload['data'],(int)$attempt->http_status));$xml=(string)$data['XML'];
        $signature=$this->db->table('fiscal_document_signatures')->where(['fiscal_document_id'=>$attempt->fiscal_document_id,'signature_verified'=>1,'xsd_status'=>'valid'])->orderBy('id','DESC')->get(1)->getRow();if(!$signature)throw new RuntimeException('No existe XML firmado validado.');
        $signedArtifact=$this->db->table('fiscal_document_artifacts')->where('id',$signature->signed_xml_artifact_id)->get(1)->getRow();$storage=new FiscalArtifactStorageService($this->db);$signed=$storage->read($signedArtifact);$validated=(new StampedXmlValidator())->validate($xml,$signed);
        $document=$this->db->table('fiscal_documents')->where('id',$attempt->fiscal_document_id)->get(1)->getRow();$issuer=$this->db->table('fiscal_profiles')->where('id',$document->issuer_profile_id)->get(1)->getRow();$receiver=$this->db->table('fiscal_profiles')->where('id',$document->receiver_profile_id)->get(1)->getRow();
        $dom=new \DOMDocument();$dom->loadXML($xml,LIBXML_NONET);$root=$dom->documentElement;$xp=new \DOMXPath($dom);$xp->registerNamespace('cfdi','http://www.sat.gob.mx/cfd/4');$emisor=$xp->query('/cfdi:Comprobante/cfdi:Emisor')->item(0);$receptor=$xp->query('/cfdi:Comprobante/cfdi:Receptor')->item(0);$transferred='0';foreach($xp->query('/cfdi:Comprobante/cfdi:Impuestos/cfdi:Traslados/cfdi:Traslado')as$t)$transferred=(string)((float)$transferred+(float)$t->getAttribute('Importe'));
        $must=[strtoupper($validated['uuid'])==='CEB0CA60-4680-4298-B68B-E0638C0EEAEE',$root->getAttribute('Version')==='4.0',$validated['tfd_version']==='1.1',$root->getAttribute('Serie')==='A',$root->getAttribute('Folio')==='1',strtoupper($emisor?->getAttribute('Rfc')??'')===strtoupper($issuer->rfc),strtoupper($receptor?->getAttribute('Rfc')??'')===strtoupper($receiver->rfc),abs((float)$root->getAttribute('SubTotal')-11976.00)<0.001,abs((float)$transferred-1916.16)<0.001,abs((float)$root->getAttribute('Total')-13892.16)<0.001,!empty($validated['sat_certificate_number']),!empty($validated['cfd_seal']),!empty($validated['sat_seal']),!empty($validated['stamp_date'])];if(in_array(false,$must,true))throw new RuntimeException('El XML A-1 no coincide con la evidencia fiscal esperada.');
        $artifact=$storage->store((int)$document->id,'stamped_xml',$xml,'timbradorxpress-reconciliation-v1','CFDI 4.0 + TFD 1.1',(string)$signedArtifact->schema_sha256,'valid',['attempt_id'=>$attemptId,'source'=>'stored_provider_response','uuid'=>$validated['uuid']],$userId);
        $this->db->transBegin();try{$this->db->table('fiscal_document_stamps')->insert(['fiscal_document_id'=>$document->id,'stamp_attempt_id'=>$attemptId,'stamped_xml_artifact_id'=>$artifact->id,'uuid'=>$validated['uuid'],'stamp_date'=>date('Y-m-d H:i:s',strtotime($validated['stamp_date'])),'pac_rfc'=>$validated['pac_rfc'],'sat_certificate_number'=>$validated['sat_certificate_number'],'cfd_seal'=>$validated['cfd_seal'],'sat_seal'=>$validated['sat_seal'],'tfd_version'=>$validated['tfd_version'],'provider'=>'timbradorxpress','environment'=>'sandbox','stamped_xml_sha256'=>$validated['sha256'],'pdf_status'=>'pending','pdf_template'=>'1','created_at'=>get_current_utc_time()]);$stampId=(int)$this->db->insertID();$now=get_current_utc_time();$this->db->table('fiscal_stamp_attempts')->where('id',$attemptId)->update(['status'=>'success_reconciled','requires_reconciliation'=>0,'uuid'=>$validated['uuid'],'response_hash'=>$validated['sha256'],'reconciled_at'=>$now,'reconciled_by'=>$userId,'reconciliation_source'=>'stored_provider_response','updated_at'=>$now]);$this->db->table('fiscal_documents')->where('id',$document->id)->update(['status'=>'stamped','stamp_updated_at'=>$now]);if(!$this->db->transStatus())throw new RuntimeException('No fue posible persistir la conciliación.');$this->db->transCommit();}catch(\Throwable$e){$this->db->transRollback();throw$e;}
        $this->stampAccounts->consumeFromReconciliation($attemptId,$stampId,$userId);(new FiscalSaleAllocationService($this->db))->convertDraftAllocationsToDocument((int)$document->source_draft_id,(int)$document->id,$userId);$this->db->table('fiscal_drafts')->where('id',$document->source_draft_id)->update(['status'=>'stamped','fiscal_document_id'=>$document->id,'updated_by'=>$userId,'updated_at'=>get_current_utc_time()]);
        return['attempt_id'=>$attemptId,'document_id'=>(int)$document->id,'stamp_id'=>$stampId,'uuid'=>$validated['uuid'],'artifact_id'=>(int)$artifact->id];
    }

    public function consumeConfirmedStamp(int $attemptId,int $stampId,int $userId,bool $authorized):array
    {
        if(!$authorized)throw new RuntimeException('No tiene permiso para conciliar timbrados.');
        return $this->stampAccounts->consumeFromReconciliation($attemptId,$stampId,$userId);
    }

    public function releaseDefinitiveRejection(int $attemptId,int $userId,bool $authorized):array
    {
        if(!$authorized)throw new RuntimeException('No tiene permiso para conciliar timbrados.');
        return $this->stampAccounts->releaseFromReconciliation($attemptId,$userId);
    }
}
