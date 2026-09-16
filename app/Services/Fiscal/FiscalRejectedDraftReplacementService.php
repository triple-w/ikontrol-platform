<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
use App\Services\Fiscal\Cfdi40\CfdiPreXmlArtifactService;
use DOMDocument; use DOMXPath; use RuntimeException;

final class FiscalRejectedDraftReplacementService
{
    public function __construct(private mixed $db=null){$this->db??=db_connect();}
    public function prepare(int$rejectedDraftId,int$userId):array
    {
        $old=$this->db->table('fiscal_drafts')->where('id',$rejectedDraftId)->get(1)->getRow();
        if(!$old||!(int)$old->fiscal_document_id)throw new RuntimeException('El borrador rechazado no existe.');
        $attempt=$this->db->table('fiscal_stamp_attempts')->where('fiscal_document_id',(int)$old->fiscal_document_id)->orderBy('id','DESC')->get(1)->getRow();
        $stamp=$this->db->table('fiscal_document_stamps')->where('fiscal_document_id',(int)$old->fiscal_document_id)->get(1)->getRow();
        if(!$attempt||!in_array((string)$attempt->status,['rejected','provider_rejected'],true)||(int)$attempt->requires_reconciliation===1||!empty($stamp?->uuid))throw new RuntimeException('El documento anterior no es un rechazo definitivo reemplazable.');
        $saleIds=array_map('intval',array_column($this->db->table('fiscal_draft_sales')->select('sale_id')->where('fiscal_draft_id',$rejectedDraftId)->get()->getResultArray(),'sale_id'));
        if(!$saleIds)throw new RuntimeException('El borrador anterior no tiene ventas relacionadas.');
        if((string)$old->status==='discarded'){
            $replacement=$this->db->table('fiscal_drafts d')->select('d.id,d.fiscal_document_id')->join('fiscal_draft_sales a','a.fiscal_draft_id=d.id')->where('a.sale_id',$saleIds[0])->where('a.allocation_status','reserved')->where('d.id >',$rejectedDraftId)->where('d.fiscal_document_id IS NOT NULL',null,false)->orderBy('d.id','DESC')->get(1)->getRow();
            if(!$replacement||$this->db->table('fiscal_stamp_attempts')->where('fiscal_document_id',(int)$replacement->fiscal_document_id)->countAllResults())throw new RuntimeException('No existe una preparación de reemplazo reanudable.');
            $preXml=(new CfdiPreXmlArtifactService($this->db))->generate((int)$replacement->fiscal_document_id,$userId,true);$inspection=$this->inspectXml((string)$preXml['xml']);if(!$inspection['valid'])throw new RuntimeException('El Pre-XML regenerado no cumple las ecuaciones fiscales requeridas.');
            $this->auditReplacement((int)$replacement->id,$userId,$rejectedDraftId,(int)$old->fiscal_document_id,(int)$attempt->id,(int)$replacement->fiscal_document_id);
            return['draft_id'=>(int)$replacement->id,'document_id'=>(int)$replacement->fiscal_document_id,'pre_xml_artifact_id'=>(int)$preXml['artifact']->id]+$inspection;
        }
        $quantities=[];foreach($this->db->table('fiscal_draft_items')->select('sale_item_id,quantity')->where('fiscal_draft_id',$rejectedDraftId)->get()->getResult()as$item)$quantities[(int)$item->sale_item_id]=(string)$item->quantity;
        $workflow=new FiscalDraftWorkflowService($this->db);$workflow->discard($rejectedDraftId,$userId,'Reemplazo seguro posterior a rechazo PAC definitivo; evidencia conservada.');
        $zone=new \DateTimeZone((new FiscalIssueDatePolicy())->constraints()['timezone']);
        $input=['ux_mode'=>'normal','save_as_draft'=>0,'sale_ids'=>$saleIds,'quantities'=>$quantities,'issuer_id'=>(int)$old->issuer_id,'receiver_profile_id'=>(int)$old->receiver_profile_id,'fiscal_series_id'=>(int)$old->fiscal_series_id,'issue_date'=>(new \DateTimeImmutable('now',$zone))->format('Y-m-d\TH:i'),'currency_code'=>(string)$old->currency_code,'exchange_rate'=>(string)$old->exchange_rate,'payment_method_code'=>(string)$old->payment_method_code,'payment_form_code'=>(string)$old->payment_form_code,'cfdi_use_code'=>(string)$old->cfdi_use_code,'conditions'=>(string)$old->conditions,'observations'=>(string)$old->observations];
        $saved=$workflow->save($input,$userId);$draftId=(int)$saved['id'];if(($saved['status']??'')!=='ready')throw new RuntimeException('La nueva preparación no quedó lista.');
        $documentId=(new FiscalDocumentFromDraftSnapshotService($this->db))->materialize($draftId,$userId);$preXml=(new CfdiPreXmlArtifactService($this->db))->generate($documentId,$userId,true);$inspection=$this->inspectXml((string)$preXml['xml']);
        if(!$inspection['valid'])throw new RuntimeException('El Pre-XML regenerado no cumple las ecuaciones fiscales requeridas.');
        $this->auditReplacement($draftId,$userId,$rejectedDraftId,(int)$old->fiscal_document_id,(int)$attempt->id,$documentId);
        return['draft_id'=>$draftId,'document_id'=>$documentId,'pre_xml_artifact_id'=>(int)$preXml['artifact']->id]+$inspection;
    }
    public function inspectPrepared(int$draftId):array
    {
        $draft=$this->db->table('fiscal_drafts')->where('id',$draftId)->get(1)->getRow();if(!$draft||!(int)$draft->fiscal_document_id)throw new RuntimeException('No existe una preparación materializada.');
        $artifact=$this->db->table('fiscal_document_artifacts')->where(['fiscal_document_id'=>(int)$draft->fiscal_document_id,'artifact_type'=>'pre_xml','superseded_at'=>null])->orderBy('id','DESC')->get(1)->getRow();if(!$artifact||!$artifact->storage_path)throw new RuntimeException('No existe Pre-XML vigente.');
        $path=WRITEPATH.ltrim(str_replace(['/','\\'],DIRECTORY_SEPARATOR,(string)$artifact->storage_path),DIRECTORY_SEPARATOR);$xml=is_file($path)?file_get_contents($path):false;
        if($xml===false||!hash_equals((string)$artifact->sha256,hash('sha256',$xml)))throw new RuntimeException('El Pre-XML no supera integridad SHA-256.');return['draft_id'=>$draftId,'document_id'=>(int)$draft->fiscal_document_id,'pre_xml_artifact_id'=>(int)$artifact->id]+$this->inspectXml($xml);
    }
    private function inspectXml(string$xml):array
    {
        $dom=new DOMDocument();if(!@$dom->loadXML($xml,LIBXML_NONET))throw new RuntimeException('El Pre-XML no es XML válido.');$xp=new DOMXPath($dom);$xp->registerNamespace('cfdi','http://www.sat.gob.mx/cfd/4');$root=$xp->query('/cfdi:Comprobante')->item(0);$concept=$xp->query('//cfdi:Concepto')->item(0);$tax=$xp->query('//cfdi:Concepto/cfdi:Impuestos/cfdi:Traslados/cfdi:Traslado')->item(0);if(!$root||!$concept||!$tax)throw new RuntimeException('El Pre-XML no contiene el concepto e impuesto esperados.');
        $quantity=$concept->getAttribute('Cantidad');$unit=$concept->getAttribute('ValorUnitario');$amount=$concept->getAttribute('Importe');$base=$tax->getAttribute('Base');$rate=$tax->getAttribute('TasaOCuota');$taxAmount=$tax->getAttribute('Importe');$subtotal=$root->getAttribute('SubTotal');$discount=$root->getAttribute('Descuento')?:'0';$total=$root->getAttribute('Total');
        $equations=['quantity_unit_amount'=>FiscalDecimal::micros(FiscalDecimal::multiply($quantity,$unit))===FiscalDecimal::micros($amount),'base_tax'=>FiscalDecimal::micros(FiscalDecimal::multiply($base,$rate))===FiscalDecimal::micros($taxAmount),'total'=>FiscalDecimal::micros(FiscalDecimal::add(FiscalDecimal::subtract($subtotal,$discount),$taxAmount))===FiscalDecimal::micros($total)];
        return['valid'=>!in_array(false,$equations,true),'series'=>$root->getAttribute('Serie'),'folio'=>$root->getAttribute('Folio'),'issue_date'=>$root->getAttribute('Fecha'),'subtotal'=>$subtotal,'discount'=>$discount,'total'=>$total,'quantity'=>$quantity,'unit_value'=>$unit,'amount'=>$amount,'tax_object'=>$concept->getAttribute('ObjetoImp'),'tax_code'=>$tax->getAttribute('Impuesto'),'factor'=>$tax->getAttribute('TipoFactor'),'rate'=>$rate,'base'=>$base,'tax_amount'=>$taxAmount,'equations'=>$equations];
    }
    private function auditReplacement(int$draftId,int$userId,int$oldDraftId,int$oldDocumentId,int$oldAttemptId,int$newDocumentId):void
    {
        if($this->db->table('fiscal_draft_audit')->where(['fiscal_draft_id'=>$draftId,'event'=>'rejected_preparation_replaced'])->countAllResults())return;
        $this->db->table('fiscal_draft_audit')->insert(['fiscal_draft_id'=>$draftId,'user_id'=>$userId,'event'=>'rejected_preparation_replaced','summary_json'=>json_encode(['replaced_draft_id'=>$oldDraftId,'replaced_document_id'=>$oldDocumentId,'replaced_attempt_id'=>$oldAttemptId,'new_document_id'=>$newDocumentId],JSON_UNESCAPED_SLASHES),'created_at'=>get_current_utc_time()]);
    }
}
