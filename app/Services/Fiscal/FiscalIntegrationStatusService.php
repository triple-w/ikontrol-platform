<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
use App\Services\Fiscal\Signing\CsdCertificateSecretService;
use Throwable;

final class FiscalIntegrationStatusService
{
    public function __construct(private mixed$db=null){$this->db??=db_connect();}
    public function inspect(int $draftId=3):array
    {
        $f=config('Fiscal');$pac=config('TimbradorXpress');$pdf=config('FiscalPdfProvider');
        $issuer=(new FiscalIssuerResolver($this->db))->resolve(null,'development');
        $series=$issuer?$this->db->table('fiscal_series')->where(['issuer_profile_id'=>$issuer->id,'environment'=>'development','is_active'=>1,'deleted'=>0])->whereIn('document_type',['ingreso','I'])->orderBy('is_default','DESC')->get(1)->getRow():null;
        $certificate=$issuer?$this->db->table('fiscal_issuer_certificates')->where(['issuer_profile_id'=>$issuer->id,'status'=>'valid','deleted'=>0])->orderBy('valid_to','DESC')->get(1)->getRow():null;
        $csdValid=false;$rfcMatch=false;
        $keyExportable=false;
        try{if($certificate){$password=(new CsdCertificateSecretService($this->db))->passwordForSigning((int)$certificate->id,0);$csd=new CsdCertificateService($this->db);$material=$csd->certificateMaterial($certificate);$key=$csd->openPrivateKey($material['private_key_bytes'],$password);$pem=$csd->exportPrivateKeyPem($key);$keyExportable=$pem!=='';unset($password,$material,$key,$pem);$now=date('Y-m-d H:i:s');$csdValid=$certificate->valid_from<=$now&&$certificate->valid_to>=$now;$rfcMatch=strtoupper((string)$certificate->certificate_rfc)===strtoupper((string)$issuer->rfc);}}catch(Throwable){}
        $template=null;try{$template=(new Pdf\FiscalPdfTemplateResolver($this->db))->resolve((int)($issuer->id??0),'timbradorxpress-tools','I')->templateCode;}catch(Throwable){}
        $draft=$this->db->table('fiscal_drafts')->select('id,fiscal_document_id')->where('id',$draftId)->get(1)->getRow();
        $documentId=(int)($draft->fiscal_document_id??0);
        $unknownStatuses=['unknown','timeout_unknown','transport_unknown','reconciliation_required'];
        $unknownGlobal=$this->db->table('fiscal_stamp_attempts')->groupStart()
            ->where('requires_reconciliation',1)->orWhereIn('status',$unknownStatuses)->groupEnd()->countAllResults();
        $unknownForDocument=$documentId>0?$this->db->table('fiscal_stamp_attempts')->where('fiscal_document_id',$documentId)
            ->groupStart()->where('requires_reconciliation',1)->orWhereIn('status',$unknownStatuses)->groupEnd()->countAllResults():0;
        $unknownForDraft=$draftId>0?$this->db->table('fiscal_stamp_attempts a')
            ->join($this->db->prefixTable('fiscal_documents').' d','d.id=a.fiscal_document_id')->where('d.source_draft_id',$draftId)
            ->groupStart()->where('a.requires_reconciliation',1)->orWhereIn('a.status',$unknownStatuses)->groupEnd()->countAllResults():0;
        $inflightGlobal=$this->db->table('fiscal_stamp_attempts')->whereIn('status',['pending','sending'])->countAllResults();
        $draftPreflight=false;
        if($draft){try{$draftPreflight=(new FiscalDraftStampingPreflightService($this->db))->inspect($draftId,$documentId>0)['allowed'];}catch(Throwable){}}
        $checks=[
            'runtime_mode'=>$f->runtimeMode,'fiscal_environment'=>$f->environment,
            'pac_adapter'=>$f->pacAdapter,'allow_real_pac'=>$f->allowRealPac,
            'pac_environment'=>$pac->environment,'pac_endpoint'=>$pac->baseUrl.'timbrarConSello',
            'pac_api_key_development_configured'=>$pac->environment==='sandbox'&&$pac->isConfigured(),
            'pac_api_key_fingerprint'=>$pac->isConfigured()?substr(hash('sha256',$pac->apiKey),0,12):null,
            'credits_known'=>'not_queried','issuer_configured'=>(bool)$issuer,
            'issuer_id'=>$issuer?->id,'issuer_rfc_masked'=>$issuer?$this->mask((string)$issuer->rfc):null,
            'series_configured'=>(bool)$series,'series_test_configured'=>(bool)$series,'series_id'=>$series?->id,'series_name'=>$series?->series,
            'csd_active'=>(bool)$certificate,'csd_valid'=>$csdValid,'csd_rfc_matches'=>$rfcMatch,'csd_transport_key_exportable'=>$keyExportable,
            'soap_client'=>class_exists(\SoapClient::class),'curl'=>extension_loaded('curl'),
            'pdf_provider'=>$pdf->provider,'pdf_enabled'=>$pdf->enabled,
            'pdf_wsdl_configured'=>$pdf->wsdl!=='','pdf_user_configured'=>$pdf->username!=='',
            'pdf_password_configured'=>$pdf->password!=='','income_template'=>$template,
            'complete_clients'=>$this->db->table('fiscal_profiles')->where(['profile_type'=>'receiver','environment'=>'development'])->whereIn('status',['active','ready'])->countAllResults(),
            'configured_products'=>$this->db->table('item_fiscal_settings')->whereIn('status',['active','ready'])->where('deleted',0)->countAllResults(),
            'active_unknown_attempts_global'=>$unknownGlobal,
            'global_unknown_is_diagnostic_only'=>true,
            'active_unknown_attempts_for_draft'=>$unknownForDraft,
            'active_unknown_attempts_for_document'=>$unknownForDocument,
            'active_inflight_attempts_global'=>$inflightGlobal,
            'status_draft_id'=>$draftId,'status_document_id'=>$documentId?:null,
        ];
        $checks['ready']=$f->runtimeMode==='integration'&&$f->environment==='development'&&$f->pacAdapter==='timbradorxpress'&&$f->allowRealPac&&$pac->environment==='sandbox'&&$pac->baseUrl===\Config\TimbradorXpress::SANDBOX_URL&&$checks['pac_api_key_development_configured']&&$issuer&&$series&&$csdValid&&$rfcMatch&&$keyExportable&&$checks['soap_client']&&$checks['curl']&&$pdf->provider==='timbradorxpress-tools'&&$pdf->enabled&&$checks['pdf_wsdl_configured']&&$checks['pdf_user_configured']&&$checks['pdf_password_configured']&&$template==='1';
        $checks['ready_for_stamp_draft_'.$draftId]=$checks['ready']&&$draftPreflight&&$unknownForDraft===0&&$unknownForDocument===0;
        return$checks;
    }
    private function mask(string$rfc):string{return strlen($rfc)<7?'***':substr($rfc,0,3).'***'.substr($rfc,-3);}
}
