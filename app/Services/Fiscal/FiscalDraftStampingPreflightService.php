<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Services\Fiscal\Signing\CsdCertificateSecretService;
use App\Services\Fiscal\Pdf\FiscalPdfTemplateResolver;
use RuntimeException;
use Throwable;

final class FiscalDraftStampingPreflightService
{
    public function __construct(private mixed $db = null, private ?FiscalDraftSnapshotService $snapshots = null)
    {
        $this->db ??= db_connect();
        $this->snapshots ??= new FiscalDraftSnapshotService($this->db);
    }

    public function inspect(int $draftId, bool $allowPreparedDocument = false): array
    {
        return $this->inspectWithSalePolicy($draftId, $allowPreparedDocument, false);
    }

    public function inspectForSaleFlow(int $draftId, bool $allowPreparedDocument = false): array
    {
        return $this->inspectWithSalePolicy($draftId, $allowPreparedDocument, true);
    }

    private function inspectWithSalePolicy(int $draftId, bool $allowPreparedDocument, bool $allowOpenSale): array
    {
        $errors = [];
        try {
            $snapshot = $this->snapshots->getCompleteFiscalSnapshot($draftId);
        } catch (Throwable) {
            return $this->result(null, ['El borrador debe editarse y guardarse nuevamente antes de facturarse.']);
        }
        $draft = $snapshot['draft'];
        $fiscal=config('Fiscal');
        if($fiscal->runtimeMode!=='automated_test'){
            $pac=config('TimbradorXpress');$pdf=config('FiscalPdfProvider');
            $context=FiscalRuntimeContext::from($fiscal,$pac);$environment=$context['fiscal_environment'];
            if(!$context['operational'])$errors[]=$context['errors'][0]??'El ambiente fiscal no está configurado correctamente.';
            if(($draft['environment']??'')!==$environment)$errors[]='El borrador no pertenece al ambiente fiscal activo.';
            if(($snapshot['series_snapshot']['environment']??'')!==$environment||(int)($snapshot['series_snapshot']['issuer_profile_id']??0)!==(int)($draft['issuer_id']??0)||empty($snapshot['series_snapshot']['is_active']))$errors[]='Selecciona una serie activa del ambiente fiscal y del emisor.';
            if(!class_exists(\SoapClient::class))$errors[]='La extensión SOAP no está disponible.';
            if($pdf->provider!=='timbradorxpress-tools'||!$pdf->enabled||$pdf->username===''||$pdf->password===''||$pdf->wsdl==='')$errors[]='El servicio PDF WSTools33 no está configurado.';
            try{$template=(new FiscalPdfTemplateResolver($this->db))->resolve((int)$draft['issuer_id'],'timbradorxpress-tools','I');if($template->templateCode!=='1')$errors[]='La plantilla PDF de ingreso debe ser 1.';}catch(Throwable){$errors[]='La plantilla PDF de ingreso debe ser 1.';}
            try{
                $now=get_current_utc_time();$certificate=$this->db->table('fiscal_issuer_certificates')->where(['issuer_profile_id'=>$draft['issuer_id'],'status'=>'valid','deleted'=>0])->where('valid_from <=',$now)->where('valid_to >=',$now)->get(1)->getRow();
                if(!$certificate)throw new RuntimeException('missing');
                (new CsdCertificateSecretService($this->db))->passwordForSigning((int)$certificate->id,(int)($draft['updated_by']??$draft['created_by']??0));
                if(strtoupper((string)$certificate->certificate_rfc)!==strtoupper((string)$snapshot['issuer_snapshot']['rfc']))throw new RuntimeException('rfc');
            }catch(Throwable){$errors[]='El CSD activo, su contraseña o el RFC del emisor no son válidos.';}
        }
        $allowedDraftStatuses = $allowPreparedDocument ? ['ready', 'stamping'] : ['ready'];
        if (!in_array((string)($draft['status'] ?? ''), $allowedDraftStatuses, true)) $errors[] = 'El borrador no está listo para facturarse.';
        if ((int)($draft['snapshot_version'] ?? 0) < 2
            || (int)($draft['requires_snapshot_refresh'] ?? 1) !== 0
            || empty($draft['snapshot_completed_at'])) {
            $errors[] = 'El borrador debe editarse y guardarse nuevamente antes de facturarse.';
        }
        if (empty($snapshot['issuer_snapshot']['tax_regime_code'])
            || empty($snapshot['issuer_snapshot']['rfc'])
            || empty($snapshot['receiver_snapshot']['rfc'])) {
            $errors[] = 'El snapshot fiscal del emisor o receptor está incompleto.';
        }
        if (!$snapshot['items']) $errors[] = 'El borrador no contiene conceptos.';
        foreach ($snapshot['items'] as $item) {
            $object = (string)($item['snapshot']['object_tax'] ?? $item['snapshot']['tax_object_code'] ?? '');
            if ($object !== '01' && !$item['taxes']) {
                $errors[] = 'Un concepto gravable no tiene impuestos persistidos.';
                break;
            }
        }
        $expected = FiscalDecimal::subtract(
            FiscalDecimal::add(
                FiscalDecimal::subtract((string)$snapshot['totals']['subtotal'], (string)$snapshot['totals']['discount']),
                (string)$snapshot['totals']['transferred']
            ),
            (string)$snapshot['totals']['withheld']
        );
        if (FiscalDecimal::micros($expected) !== FiscalDecimal::micros((string)$snapshot['totals']['total'])) {
            $errors[] = 'Los totales del snapshot fiscal no son consistentes.';
        }
        $allocated = 0;
        $availability = new FiscalSaleAllocationService($this->db);
        foreach ($snapshot['allocations'] as $allocation) {
            if (($allocation['allocation_status'] ?? '') !== 'reserved') {
                $errors[] = 'Las asignaciones del borrador no están reservadas.';
                break;
            }
            $allocated += FiscalDecimal::micros((string)$allocation['allocated_total']);
            $sale = $this->db->table('invoices')->select('status,commercial_status,deleted')
                ->where('id', (int)$allocation['sale_id'])->get(1)->getRowArray();
            if (!$sale || (int)$sale['deleted'] === 1 || $sale['status'] === 'cancelled'
                || !in_array((string)($sale['commercial_status'] ?? 'open'), ($allowOpenSale || $fiscal->runtimeMode==='automated_test') ? ['draft','open','closed'] : ['closed'], true)) {
                $errors[] = 'Una venta relacionada no está disponible para facturación.';
                continue;
            }
            try {
                $availability->validateDraftAvailability(
                    (int) $allocation['sale_id'], $draftId, (string) $allocation['allocated_total']
                );
            } catch (Throwable $exception) {
                $detail = $availability->getDraftAvailability((int) $allocation['sale_id'], $draftId);
                log_message('error', 'FISCAL_PREFLIGHT_SALE_AVAILABILITY_FAILURE {detail}', [
                    'detail' => json_encode($detail + [
                        'draft_id' => $draftId,
                        'sale_id' => (int) $allocation['sale_id'],
                        'current_draft_allocated_total' => (string) $allocation['allocated_total'],
                        'exception_class' => get_class($exception),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                $errors[] = 'Una venta relacionada no está disponible para facturación.';
            }
        }
        if ($allocated !== FiscalDecimal::micros((string)$snapshot['totals']['total'])) {
            $errors[] = 'Las asignaciones no coinciden con el total del borrador.';
        }
        try {
            (new FiscalIssueDatePolicy())->validate((string)$draft['issue_date']);
        } catch (Throwable) {
            $errors[] = 'La fecha de expedición ya no es válida.';
        }
        $preparedDocumentId = (int)($draft['fiscal_document_id'] ?? 0);
        if (!$preparedDocumentId) {
            $preparedDocumentId = (int)($this->db->table('fiscal_documents')->select('id')
                ->where('source_draft_id', $draftId)->get(1)->getRow()?->id ?? 0);
        }
        if ($preparedDocumentId) {
            if (!$allowPreparedDocument) {
                $errors[] = 'El borrador ya tiene un documento fiscal principal.';
            } else {
                $document = $this->db->table('fiscal_documents')->select('status')
                    ->where(['id'=>$preparedDocumentId,'source_draft_id'=>$draftId,'deleted'=>0])->get(1)->getRow();
                $active = $this->db->table('fiscal_stamp_attempts')->where('fiscal_document_id',$preparedDocumentId)
                    ->groupStart()->whereIn('status',['pending','sending','unknown','timeout_unknown','transport_unknown','reconciliation_required'])->orWhere('requires_reconciliation',1)->groupEnd()
                    ->countAllResults();
                if (!$document || !in_array($document->status,['locked','ready_to_stamp','stamping_error'],true) || $active) {
                    $errors[] = 'El documento fiscal preparado requiere revisión antes de continuar.';
                }
            }
        }
        return $this->result($snapshot, array_values(array_unique($errors)));
    }

    public function requireReady(int $draftId, bool $allowPreparedDocument = false): array
    {
        $result = $this->inspect($draftId, $allowPreparedDocument);
        if (!$result['allowed']) throw new RuntimeException($result['errors'][0]);
        return $result['snapshot'];
    }

    public function requireReadyForSaleFlow(int $draftId, bool $allowPreparedDocument = false): array
    {
        $result = $this->inspectForSaleFlow($draftId, $allowPreparedDocument);
        if (!$result['allowed']) throw new RuntimeException($result['errors'][0]);
        return $result['snapshot'];
    }

    private function result(?array $snapshot, array $errors): array
    {
        return ['allowed' => !$errors, 'errors' => $errors, 'snapshot' => $snapshot];
    }
}
