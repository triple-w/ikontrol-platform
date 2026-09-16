<?php
declare(strict_types=1);

namespace App\Controllers\Fiscal;

use App\Controllers\Security_Controller;
use App\Services\Fiscal\FiscalArtifactStorageService;
use App\Services\Fiscal\Pac\FiscalDocumentStatusPresenter;
use App\Services\Fiscal\Pac\FiscalPacAdapterFactory;
use App\Services\Fiscal\Pac\FiscalStampingService;
use App\Services\Fiscal\Pac\FiscalStampReconciliationService;
use App\Services\Fiscal\Pac\PacPdfArtifactService;
use App\Services\Fiscal\Pdf\FiscalPacPdfGenerationService;
use CodeIgniter\Exceptions\PageNotFoundException;
use Throwable;

final class Stamping extends Security_Controller
{
    public function pacStatus()
    {
        $this->guard('fiscal_pac_status_view');
        $fiscal = config('Fiscal');
        $provider = config('TimbradorXpress');
        $available = false;
        $error = null;
        try {
            (new FiscalPacAdapterFactory($fiscal, $provider))->create();
            $available = true;
        } catch (Throwable $e) {
            $error = 'La configuración fiscal no permite utilizar el adaptador seleccionado.';
        }

        return $this->template->view('fiscal/pac/status', [
            'provider' => $fiscal->pacAdapter === 'fake' ? 'PAC falso' : 'TimbradorXpress',
            'environment' => $fiscal->environment,
            'configured' => $available,
            'production_enabled' => false,
            'pdf_template' => $provider->pdfTemplate,
            'configuration_error' => $error,
        ]);
    }

    public function stamp(): void
    {
        $id = (int) $this->request->getPost('fiscal_document_id');
        $this->guardDocument($id, 'fiscal_stamp_sandbox');
        try {
            $result = (new FiscalStampingService())->stamp($id, (int) $this->login_user->id, true);
            echo json_encode([
                'success' => $result->success,
                'stage' => $result->stage,
                'status' => $result->status,
                'code' => $result->providerCode,
                'message' => $result->providerMessage
                    ?? ($result->success ? app_lang('cfdi_stamped_successfully') : 'Revise el resultado del intento.'),
                'retryable' => $result->retryable,
                'requires_reconciliation' => $result->requiresReconciliation,
                'action' => $result->recommendedAction,
                'data' => $result->toArray(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            log_message('error', 'Stamp request failed for document {document}: {type}', [
                'document' => $id,
                'type' => get_class($e),
            ]);
            echo json_encode([
                'success' => false,
                'stage' => 'stamping',
                'status' => 'configuration_or_validation_error',
                'code' => 'FISCAL_STAMP_BLOCKED',
                'message' => 'No fue posible iniciar el timbrado. Revise la configuración y el documento.',
                'retryable' => false,
                'requires_reconciliation' => false,
                'action' => 'Revisar detalle fiscal',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    public function result($documentId = 0)
    {
        $id = (int) $documentId;
        $this->guardDocument($id, 'fiscal_stamp_status');
        $projection = (new FiscalDocumentStatusPresenter())->forDocument($id);

        return $this->template->view('fiscal/pac/result', [
            'status_view' => $projection,
            'document' => $projection->document,
            'stamp' => $projection->stamp,
            'attempt' => $projection->attempt,
            'pdf' => $projection->pdf,
        ]);
    }

    public function verifySigned(): void
    {
        $id = (int) $this->request->getPost('fiscal_document_id');
        $this->guardDocument($id, 'fiscal_xml_sign');
        try {
            $result = (new FiscalStampingService())->verifySignedDocument($id);
            echo json_encode([
                'success' => $result->valid,
                'message' => $result->valid
                    ? app_lang('signed_xml_verification_valid')
                    : app_lang('signed_xml_verification_failed'),
                'data' => $result->toArray(),
            ]);
        } catch (Throwable $e) {
            log_message('warning', 'Independent signed XML verification failed for document {document}: {type}', [
                'document' => $id,
                'type' => get_class($e),
            ]);
            echo json_encode([
                'success' => false,
                'message' => app_lang('signed_xml_verification_failed'),
                'data' => ['errors' => ['No fue posible completar la verificación independiente.']],
            ]);
        }
    }

    public function viewXml($documentId = 0)
    {
        $this->guardDocumentAny((int) $documentId, ['fiscal_stamped_xml_view','fiscal_invoices_download_xml']);
        return $this->artifactResponse((int) $documentId, 'stamped_xml', false);
    }

    public function downloadXml($documentId = 0)
    {
        $this->guardDocumentAny((int) $documentId, ['fiscal_xml_download','fiscal_stamped_xml_download','fiscal_invoices_download_xml']);
        return $this->artifactResponse((int) $documentId, 'stamped_xml', true);
    }

    public function viewPdf($documentId = 0)
    {
        $this->guardDocumentAny((int) $documentId, ['fiscal_pdf_view','fiscal_invoices_download_pdf']);
        return $this->pdfResponse((int) $documentId, false);
    }

    public function downloadPdf($documentId = 0)
    {
        $this->guardDocumentAny((int) $documentId, ['fiscal_pdf_download','fiscal_invoices_download_pdf']);
        return $this->pdfResponse((int) $documentId, true);
    }

    public function generatePdf($documentId = 0):void
    {
        $id=(int)$documentId;
        $this->guard('fiscal_pdf_generate');
        if ((bool) $this->request->getPost('regenerate')) {
            $this->guard('fiscal.advanced.regenerate_pdf');
        }
        if(!db_connect()->table('fiscal_documents')->where(['id'=>$id,'deleted'=>0])->countAllResults())throw PageNotFoundException::forPageNotFound();
        try{$regenerate=(bool)$this->request->getPost('regenerate');$result=(new FiscalPacPdfGenerationService())->generate($id,(int)$this->login_user->id,null,$regenerate);$response=$result->toArray()+['message'=>$result->success?'PDF generado correctamente.':$this->pdfResultMessage($result->providerCode,$result->providerMessage,$result->status),'preview_url'=>$result->pdfAvailable?get_uri('fiscal/documents/'.$id.'/pdf/preview'):null,'download_url'=>$result->pdfAvailable?get_uri('fiscal/documents/'.$id.'/pdf/download'):null,'csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()]];echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
        catch(Throwable$e){log_message('warning','Fiscal PDF generation blocked for document {document}: {type}',['document'=>$id,'type'=>get_class($e)]);echo json_encode(['success'=>false,'status'=>'blocked','message'=>$this->pdfExceptionMessage($e),'csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()]],JSON_UNESCAPED_UNICODE);}
    }

    public function regeneratePdf($documentId = 0): void
    {
        $id = (int) $documentId;
        $this->guardDocumentAny($id, ['fiscal_pdf_generate']);
        $this->guard('fiscal.advanced.regenerate_pdf');
        try {
            $result = (new FiscalPacPdfGenerationService())->generate($id, (int) $this->login_user->id, null, true);
            $payload = $result->toArray() + [
                'message' => $result->success ? 'PDF regenerado correctamente.' : $this->pdfResultMessage($result->providerCode, $result->providerMessage, $result->status),
                'preview_url' => $result->pdfAvailable ? get_uri('fiscal/documents/'.$id.'/pdf/preview') : null,
                'download_url' => $result->pdfAvailable ? get_uri('fiscal/documents/'.$id.'/pdf/download') : null,
                'csrf' => ['name'=>csrf_token(),'hash'=>csrf_hash()],
            ];
            $this->response->setContentType('application/json')->setJSON($payload)->send();
        } catch (Throwable $error) {
            log_message('warning', 'Fiscal PDF regeneration blocked for document {document}: {type}', ['document'=>$id,'type'=>get_class($error)]);
            $payload = [
                'success'=>false,'status'=>'blocked','message'=>$this->pdfExceptionMessage($error),
                'configure_url'=>in_array($error->getMessage(), ['FISCAL_PDF_TEMPLATE_INVALID','FISCAL_PDF_TEMPLATE_MISSING'], true) ? get_uri('fiscal/pdf-templates') : null,
                'csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()],
            ];
            $this->response->setStatusCode(422)->setContentType('application/json')->setJSON($payload)->send();
        }
    }
    private function pdfExceptionMessage(Throwable $e):string
    {
        $message=$e->getMessage();
        if(str_starts_with($message,'FISCAL_PDF_XML_INVALID:'))return 'No se puede generar el PDF porque el XML timbrado está incompleto o dañado. Detalle: '.trim(substr($message,strlen('FISCAL_PDF_XML_INVALID:')));
        return match($message){
            'FISCAL_PDF_STAMPED_XML_MISSING','FISCAL_PDF_STAMP_REQUIRED'=>'No existe XML timbrado para regenerar el PDF.',
            'FISCAL_PDF_UUID_MISSING'=>'UUID no encontrado en el XML.',
            'FISCAL_PDF_TEMPLATE_INVALID','FISCAL_PDF_TEMPLATE_MISSING'=>'No existe una plantilla PDF configurada para este tipo de documento. Configure una plantilla antes de regenerar el PDF.',
            'FISCAL_PDF_DISABLED','FISCAL_PDF_EXTERNAL_DISABLED','FISCAL_PDF_PRODUCTION_BLOCKED'=>'Servicio PDF deshabilitado.',
            'FISCAL_PDF_CREDENTIALS_MISSING'=>'Credenciales PDF no configuradas.',
            'FISCAL_PDF_INSECURE_ENDPOINT','FISCAL_PDF_HOST_NOT_ALLOWED'=>'No fue posible conectar con el proveedor.',
            default=>'No fue posible generar el PDF.',
        };
    }

    private function pdfResultMessage(?string $code,?string $providerMessage,string $status):string
    {
        $suffix=$code?' Código PAC: '.$code.'.':'';
        if($status==='unknown')return 'No fue posible conectar con el proveedor.'.$suffix;
        if($code==='210'&&$providerMessage&&str_contains($providerMessage,'no devolvió'))return 'El proveedor respondió sin PDF.'.$suffix;
        if($code==='210')return 'El PDF recibido no es válido.'.$suffix;
        if(!$code)return 'El proveedor respondió sin PDF.';
        return 'El proveedor rechazó la generación.'.$suffix;
    }

    public function satStatus(): void
    {
        $this->guard('fiscal_stamp_status');
        $id = (int) $this->request->getPost('fiscal_document_id');
        $db = db_connect();
        try {
            $stamp = $db->table('fiscal_document_stamps')->where('fiscal_document_id', $id)->get(1)->getRow();
            $document = $db->table('fiscal_documents')->where('id', $id)->get(1)->getRow();
            $issuer = $db->table('fiscal_document_issuers')->where('fiscal_document_id', $id)->get(1)->getRow();
            $receiver = $db->table('fiscal_document_receivers')->where('fiscal_document_id', $id)->get(1)->getRow();
            if (!$stamp || !$document || !$issuer || !$receiver) {
                throw new \RuntimeException('Faltan datos para consultar el estado.');
            }
            $factory = new FiscalPacAdapterFactory();
            $adapter = $factory->create();
            $response = $adapter->getStampStatus([
                'environment' => $factory->environment(),
                'uuid' => $stamp->uuid,
                'rfcEmisor' => $issuer->rfc,
                'rfcReceptor' => $receiver->rfc,
                'total' => $document->total,
            ]);
            $this->audit($id, 'sat_status_queried', (string) $stamp->uuid);
            echo json_encode([
                'success' => !$response->transportError,
                'data' => ['code' => $response->code, 'message' => $response->message],
                'message' => $response->message,
            ]);
        } catch (Throwable $e) {
            log_message('warning', 'SAT status query blocked for document {document}: {type}', [
                'document' => $id,
                'type' => get_class($e),
            ]);
            echo json_encode([
                'success' => false,
                'stage' => 'status_query',
                'status' => 'blocked',
                'code' => 'FISCAL_STATUS_QUERY_BLOCKED',
                'message' => 'La consulta externa no está disponible con la configuración actual.',
                'retryable' => false,
                'requires_reconciliation' => false,
            ]);
        }
    }

    public function reconcile(): void
    {
        $this->guard('fiscal_stamp_reconcile');
        try {
            $result = (new FiscalStampReconciliationService())->recoverFromContingency(
                (int) $this->request->getPost('attempt_id'),
                (int) $this->login_user->id,
                true
            );
            echo json_encode([
                'success' => true,
                'data' => [
                    'attempt_id' => $result['attempt']->id,
                    'sha256' => $result['sha256'],
                    'automatic_resend' => false,
                ],
                'message' => app_lang('reconciliation'),
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Local fiscal reconciliation failed: {type}', ['type' => get_class($e)]);
            echo json_encode([
                'success' => false,
                'stage' => 'reconciliation',
                'status' => 'error',
                'code' => 'FISCAL_RECONCILIATION_FAILED',
                'message' => 'No fue posible completar la conciliación local.',
                'retryable' => false,
                'requires_reconciliation' => true,
            ]);
        }
    }

    private function artifactResponse(int $id, string $type, bool $download)
    {
        $db = db_connect();
        $artifact = $db->table('fiscal_document_artifacts')->where([
            'fiscal_document_id' => $id,
            'artifact_type' => $type,
            'superseded_at' => null,
        ])->get(1)->getRow();
        if (!$artifact) throw PageNotFoundException::forPageNotFound();
        $contents = (new FiscalArtifactStorageService())->read($artifact);
        $stamp = $db->table('fiscal_document_stamps')->where('fiscal_document_id', $id)->get(1)->getRow();
        if (!$stamp) throw PageNotFoundException::forPageNotFound();
        $this->audit($id, ($download ? 'downloaded_' : 'viewed_') . $type, (string) $stamp->uuid);

        return $this->response
            ->setHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->setHeader('Content-Disposition', ($download ? 'attachment' : 'inline') . '; filename="CFDI-' . $stamp->uuid . '.xml"')
            ->setHeader('Cache-Control', 'private, no-store, max-age=0')
            ->setBody($contents);
    }

    private function pdfResponse(int $id, bool $download)
    {
        $db = db_connect();
        $document = $db->table('fiscal_documents')->where(['id' => $id, 'deleted' => 0])->get(1)->getRow();
        $stamp = $db->table('fiscal_document_stamps')->where('fiscal_document_id', $id)->get(1)->getRow();
        if (!$document || !$stamp || !$db->tableExists('fiscal_document_binary_artifacts')) throw PageNotFoundException::forPageNotFound();
        $pdf = (new PacPdfArtifactService($db))->read($id);
        $safeUuid = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $stamp->uuid);
        $name = strtolower((string) $document->document_type) === 'payment'
            ? 'ComplementoPago_' . $safeUuid . '.pdf'
            : 'CFDI-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $document->series) . '-' . (int) $document->folio . '.pdf';
        $this->audit($id, $download ? 'downloaded_pac_pdf' : 'viewed_pac_pdf', (string) $stamp->uuid);
        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', ($download ? 'attachment' : 'inline') . '; filename="' . $name . '"')
            ->setHeader('Content-Length', (string) strlen($pdf['bytes']))
            ->setHeader('Cache-Control', 'private, no-store, max-age=0')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($pdf['bytes']);
    }
    private function audit(int $id, string $action, string $uuid): void
    {
        $db = db_connect();
        $document = $db->table('fiscal_documents')->where('id', $id)->get(1)->getRow();
        $db->table('fiscal_document_audit')->insert([
            'fiscal_document_id' => $id,
            'invoice_id' => $document->invoice_id,
            'user_id' => $this->login_user->id,
            'action' => $action,
            'reason' => json_encode(['uuid' => $uuid]),
            'created_at' => get_current_utc_time(),
        ]);
    }

    private function guard(string $permission): void
    {
        if ($this->login_user->is_admin) return;
        $permissions = is_array($this->login_user->permissions)
            ? $this->login_user->permissions
            : (@unserialize((string) $this->login_user->permissions) ?: []);
        if (!get_array_value($permissions, $permission)) app_redirect('forbidden');
    }

    private function guardDocument(int $id, string $permission): void
    {
        $this->guard($permission);
        $document = db_connect()->table('fiscal_documents')
            ->where(['id' => $id, 'deleted' => 0])->get(1)->getRow();
        if (!$document) throw PageNotFoundException::forPageNotFound();
        if (!$this->can_view_invoices((int) $document->invoice_id)) app_redirect('forbidden');
    }

    private function guardDocumentAny(int $id, array $permissions): void
    {
        if (!$this->login_user->is_admin) {
            $all = is_array($this->login_user->permissions)
                ? $this->login_user->permissions
                : (@unserialize((string) $this->login_user->permissions) ?: []);
            $granted = false;
            foreach ($permissions as $permission) {
                if (get_array_value($all, $permission)) {$granted = true;break;}
            }
            if (!$granted) app_redirect('forbidden');
        }
        $document = db_connect()->table('fiscal_documents')->where(['id'=>$id,'deleted'=>0])->get(1)->getRow();
        if (!$document) throw PageNotFoundException::forPageNotFound();
        if (!$this->can_view_invoices((int)$document->invoice_id)) app_redirect('forbidden');
    }
}
