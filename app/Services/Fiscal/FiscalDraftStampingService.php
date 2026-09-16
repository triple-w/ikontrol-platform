<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Services\Fiscal\Cfdi40\CfdiPreXmlArtifactService;
use App\Services\Fiscal\Cfdi40\CfdiSigningService;
use App\Services\Fiscal\Pac\FiscalStampingService as PacStampingService;
use App\Services\Fiscal\Pdf\FiscalPacPdfGenerationService;
use App\Services\Sales\SaleLifecycleService;
use RuntimeException;
use Throwable;

final class FiscalDraftStampingService
{
    public function __construct(
        private mixed $db = null,
        private ?FiscalDraftStampingPreflightService $preflight = null,
        private ?FiscalDocumentFromDraftSnapshotService $materializer = null,
        private ?CfdiPreXmlArtifactService $preXml = null,
        private ?CfdiSigningService $signer = null,
        private ?PacStampingService $stamping = null,
        private ?FiscalSaleAllocationService $allocations = null,
        private ?FiscalPacPdfGenerationService $pdfGeneration = null
    ) {
        $this->db ??= db_connect();
        $this->preflight ??= new FiscalDraftStampingPreflightService($this->db);
        $this->materializer ??= new FiscalDocumentFromDraftSnapshotService($this->db, $this->preflight);
        $this->preXml ??= new CfdiPreXmlArtifactService($this->db);
        $this->signer ??= new CfdiSigningService($this->db);
        $this->stamping ??= new PacStampingService($this->db);
        $this->allocations ??= new FiscalSaleAllocationService($this->db);
    }

    public function preflight(int $draftId): array
    {
        $draft=$this->db->table('fiscal_drafts')->select('fiscal_document_id')->where('id',$draftId)->get(1)->getRow();
        return $this->preflight->inspect($draftId, !empty($draft->fiscal_document_id));
    }

    public function stamp(int $draftId, int $userId, bool $authorized): array
    {
        return $this->stampInternal($draftId, $userId, $authorized, false);
    }

    public function stampSaleFlow(int $draftId, int $userId, bool $authorized): array
    {
        return $this->stampInternal($draftId, $userId, $authorized, true);
    }

    private function stampInternal(int $draftId, int $userId, bool $authorized, bool $saleFlow): array
    {
        if (!$authorized) throw new RuntimeException('No tiene permiso para facturar el borrador.');
        $lockName = 'fiscal_draft_stamp_' . $draftId;
        $acquired = (int)($this->db->query('SELECT GET_LOCK(?, 0) acquired', [$lockName])->getRow()->acquired ?? 0);
        if ($acquired !== 1) throw new RuntimeException('El borrador ya está siendo procesado.');
        $documentId = null;
        try {
            (new FiscalPreparedDocumentLifecycleService($this->db))->invalidateIfSnapshotChanged($draftId, $userId);
            $prepared=$this->db->table('fiscal_drafts')->select('fiscal_document_id')->where('id',$draftId)->get(1)->getRow();
            $documentId=(int)($prepared->fiscal_document_id??0);
            if ($documentId>0) {
                $existingStamp=$this->db->table('fiscal_document_stamps')->where('fiscal_document_id',$documentId)->get(1)->getRow();
                if ($existingStamp && trim((string)$existingStamp->uuid)!=='') return $this->completeStampedLocally($draftId,$documentId,$userId,$existingStamp);
            }
            $saleFlow ? $this->preflight->requireReadyForSaleFlow($draftId, $documentId>0) : $this->preflight->requireReady($draftId, $documentId>0);
            if($documentId===0)$documentId = $this->materializer->materialize($draftId, $userId, $saleFlow);
            else if(!$this->db->table('fiscal_stamp_attempts')->where('fiscal_document_id',$documentId)->countAllResults())$this->materializer->reconcileLocalDocumentCurrencyTotals($documentId);
            // Exact final gate before signing, stamp reservation, and PAC transport.
            $this->allocations->validateDraftDocumentConsistency($draftId, $documentId);
            $signature=$this->db->table('fiscal_document_signatures')->where([
                'fiscal_document_id'=>$documentId,'signature_verified'=>1,'xsd_status'=>'valid',
            ])->orderBy('id','DESC')->get(1)->getRow();
            if(!$signature){
                $preXmlArtifact=$this->db->table('fiscal_document_artifacts')->where([
                    'fiscal_document_id'=>$documentId,'artifact_type'=>'pre_xml',
                    'validation_status'=>'valid','superseded_at'=>null,
                ])->orderBy('id','DESC')->get(1)->getRow();
                if(!$preXmlArtifact){
                    $preXml=$this->preXml->generate($documentId,$userId,true);
                    $preXmlArtifact=$preXml['artifact'];
                }
                $certificate=$this->activeCertificate($documentId);
                $this->signer->sign($documentId,(int)$preXmlArtifact->id,(int)$certificate->id,$userId,true);
            }
            $result = $this->stamping->stamp($documentId, $userId, true);
            if ($result->xmlAvailable && $result->uuid) {
                $stamp=$this->db->table('fiscal_document_stamps')->where('fiscal_document_id',$documentId)->get(1)->getRow();
                if(!$stamp||trim((string)$stamp->uuid)==='')throw new RuntimeException('PAC_SUCCESS_WAS_NOT_PERSISTED');
                return $this->completeStampedLocally($draftId,$documentId,$userId,$stamp,$result->toArray());
            }
            if ($result->requiresReconciliation) $this->setDraftState($draftId,'blocked',$userId);
            else $this->setDraftState($draftId,'ready',$userId);
            return ['document_id'=>$documentId,'result'=>$result->toArray()];
        } catch (Throwable $e) {
            $this->audit($draftId,$userId,'draft_stamp_failed',[
                'document_id'=>$documentId,'error_class'=>get_class($e),
                'message'=>mb_substr(preg_replace('/[\r\n\t]+/',' ',(string)$e->getMessage()),0,300),
                'pac_attempt_exists'=>$documentId!==null&&(bool)$this->db->table('fiscal_stamp_attempts')->where('fiscal_document_id',$documentId)->countAllResults(),
            ]);
            if ($documentId !== null) {
                $attempt = $this->db->table('fiscal_stamp_attempts')->where('fiscal_document_id', $documentId)
                    ->orderBy('id', 'DESC')->get(1)->getRow();
                if (!$attempt || !in_array((string)$attempt->status, ['sending', 'unknown', 'timeout_unknown', 'transport_unknown', 'reconciliation_required'], true)) {
                    $this->setDraftState($draftId, 'ready', $userId);
                } else {
                    $this->setDraftState($draftId, 'blocked', $userId);
                }
            }
            throw $e;
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    private function completeStampedLocally(int $draftId,int $documentId,int $userId,object $stamp,array $providerResult=[]):array
    {
        $pending=[];
        $attemptId=(int)($stamp->stamp_attempt_id??0);
        if($attemptId>0){$attempt=$this->db->table('fiscal_stamp_attempts')->select('status, requires_reconciliation')->where('id',$attemptId)->get(1)->getRow();if($attempt&&(int)($attempt->requires_reconciliation??0)===1&&(string)($attempt->status??'')==='success_local_reconciliation_pending')$pending[]='stamp_consumption';}
        try{
            $reserved=$this->db->table('fiscal_draft_sales')->where(['fiscal_draft_id'=>$draftId,'allocation_status'=>'reserved'])->countAllResults();
            $active=$this->db->table('fiscal_document_sales')->where(['fiscal_document_id'=>$documentId,'allocation_status'=>'active'])->countAllResults();
            if($reserved)$this->allocations->convertDraftAllocationsToDocument($draftId,$documentId,$userId);
            elseif(!$active)throw new RuntimeException('FISCAL_LOCAL_ALLOCATION_RECONCILIATION_REQUIRED');
        }catch(Throwable $allocationError){$pending[]='allocations';$this->postStampWarning($draftId,$documentId,$userId,'allocation_conversion',$allocationError);}
        $saleIds=array_map('intval',array_column($this->db->table('fiscal_draft_sales')->select('sale_id')->where('fiscal_draft_id',$draftId)->get()->getResultArray(),'sale_id'));
        $document=$this->db->table('fiscal_documents')->select('invoice_id')->where('id',$documentId)->get(1)->getRow();
        if(!empty($document->invoice_id))$saleIds[]=(int)$document->invoice_id;
        foreach(array_values(array_unique(array_filter($saleIds)))as$saleId){
            try{$sale=$this->db->table('invoices')->select('commercial_status')->where(['id'=>$saleId,'deleted'=>0])->get(1)->getRow();if($sale&&in_array((string)$sale->commercial_status,['draft','open'],true))(new SaleLifecycleService($this->db))->close($saleId,$userId,'Cierre automatico posterior a timbrado CFDI');}
            catch(Throwable $closeError){$pending[]='sale_close';$this->postStampWarning($draftId,$documentId,$userId,'sale_close',$closeError);}
        }
        $pdfAvailable=(string)($stamp->pdf_status??'')==='valid'&&(int)($stamp->pac_pdf_artifact_id??0)>0;
        if(!$pdfAvailable&&config('FiscalPdfProvider')->enabled){try{$pdf=($this->pdfGeneration??new FiscalPacPdfGenerationService($this->db))->generate($documentId,$userId);$pdfAvailable=$pdf->pdfAvailable;}catch(Throwable $pdfError){$this->postStampWarning($draftId,$documentId,$userId,'pdf_generation',$pdfError);}}
        $localPending=(bool)$pending;
        $status=$localPending?'stamped_local_reconciliation_pending':($pdfAvailable?'stamped':'stamped_pdf_pending');
        $this->db->table('fiscal_drafts')->where('id',$draftId)->update(['fiscal_document_id'=>$documentId,'status'=>$localPending?'error':'stamped','updated_by'=>$userId,'updated_at'=>get_current_utc_time()]);
        $this->db->table('fiscal_documents')->where('id',$documentId)->update(['status'=>$status,'stamp_updated_at'=>get_current_utc_time()]);
        $message=$localPending?'Factura timbrada correctamente. Falta completar una actualizacion administrativa local.':($pdfAvailable?'Factura timbrada correctamente.':'Factura timbrada correctamente. El PDF esta pendiente de generacion.');
        $this->audit($draftId,$userId,$localPending?'draft_stamped_local_reconciliation_pending':'draft_stamped',['document_id'=>$documentId,'attempt_id'=>(int)($stamp->stamp_attempt_id??0),'uuid'=>(string)$stamp->uuid,'pending'=>$pending,'pdf_available'=>$pdfAvailable]);
        return['document_id'=>$documentId,'result'=>array_replace($providerResult,['success'=>true,'status'=>$status,'documentId'=>$documentId,'attemptId'=>(int)($stamp->stamp_attempt_id??0),'providerMessage'=>$message,'uuid'=>(string)$stamp->uuid,'retryable'=>false,'requiresReconciliation'=>false,'xmlAvailable'=>true,'pdfAvailable'=>$pdfAvailable,'requiresPdfRecovery'=>!$pdfAvailable])];
    }

    private function postStampWarning(int $draftId,int $documentId,int $userId,string $stage,Throwable $error):void
    {
        log_message('error','CFDI_POST_STAMP_LOCAL_FAILURE {detail}',['detail'=>json_encode(['draft_id'=>$draftId,'fiscal_document_id'=>$documentId,'stage'=>$stage,'exception_class'=>get_class($error),'exception_message'=>$error->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $this->audit($draftId,$userId,'post_stamp_local_failure',['document_id'=>$documentId,'stage'=>$stage,'exception_class'=>get_class($error)]);
    }
    private function activeCertificate(int $documentId): object
    {
        $document = $this->db->table('fiscal_documents')->select('issuer_profile_id')
            ->where('id', $documentId)->get(1)->getRow();
        $now = get_current_utc_time();
        $certificate = $this->db->table('fiscal_issuer_certificates')
            ->where(['issuer_profile_id' => $document->issuer_profile_id, 'status' => 'valid', 'deleted' => 0])
            ->where('valid_from <=', $now)->where('valid_to >=', $now)
            ->orderBy('valid_to', 'DESC')->get(1)->getRow();
        if (!$certificate) throw new RuntimeException('El emisor no tiene un CSD utilizable.');
        return $certificate;
    }

    private function setDraftState(int $draftId, string $status, int $userId): void
    {
        $this->db->table('fiscal_drafts')->where('id', $draftId)->update([
            'status' => $status, 'updated_by' => $userId, 'updated_at' => get_current_utc_time(),
        ]);
    }

    private function audit(int $draftId, int $userId, string $event, array $summary): void
    {
        $this->db->table('fiscal_draft_audit')->insert([
            'fiscal_draft_id' => $draftId, 'user_id' => $userId, 'event' => $event,
            'summary_json' => json_encode($summary, JSON_UNESCAPED_SLASHES), 'created_at' => get_current_utc_time(),
        ]);
    }
}
