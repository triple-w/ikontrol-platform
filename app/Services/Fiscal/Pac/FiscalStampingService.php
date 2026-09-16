<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pac;

use App\Domain\Fiscal\Pac\FiscalStampingResult;
use App\Domain\Fiscal\Pac\StampRequest;
use App\Domain\Fiscal\Signing\SignedXmlVerificationResult;
use App\Services\Fiscal\FiscalArtifactStorageService;
use App\Services\Fiscal\Signing\SignedXmlVerifier;
use App\Services\Fiscal\Signing\CsdCertificateSecretService;
use App\Services\Fiscal\CsdCertificateService;
use App\Services\Fiscal\FiscalPreviewModeGuard;
use App\Services\Fiscal\Stamps\FiscalStampAccountService;
use App\Services\Fiscal\Stamps\FiscalStampBalanceException;
use Config\TimbradorXpress;
use RuntimeException;
use Throwable;

final class FiscalStampingService
{
    private $db;

    public function __construct(
        $db = null,
        private ?FiscalPacAdapterFactory $adapterFactory = null,
        private ?TimbradorXpress $configuration = null,
        private ?PacSecretVault $vault = null,
        private ?string $artifactRoot = null,
        private ?string $contingencyRoot = null,
        private $signedXmlVerifier = null,
        private ?FiscalStampAccountService $stampAccounts = null
    ) {
        $this->db = $db ?: db_connect();
    }

    public function stamp(int $documentId, int $userId, bool $authorized): FiscalStampingResult
    {
        (new FiscalPreviewModeGuard($this->db))->assertStampingAllowed();
        if (!$authorized) {
            throw new RuntimeException('No tiene permiso para timbrar CFDI.');
        }

        // All master configuration guards are evaluated before an attempt is created.
        $factory = $this->adapterFactory ?? new FiscalPacAdapterFactory();
        $adapter = $factory->create();
        $provider = $factory->provider();
        $environment = $factory->environment();
        $providerConfig = $this->configuration ?? config('TimbradorXpress');
        $operation = $provider === 'timbradorxpress' ? 'timbrarConSello' : 'timbrar3';

        $verification = $this->verifySignedDocument($documentId);
        if (!$verification->valid) {
            throw new RuntimeException('El XML sellado no supera la verificación independiente.');
        }

        [$document, $artifact, $attempt, $xml, $existing] =
            $this->prepare($documentId, $userId, $provider, $environment, $operation);

        if ($existing) {
            return new FiscalStampingResult(
                false,
                'existing',
                $documentId,
                (int) $attempt->id,
                'idempotency',
                $attempt->provider_code ?? null,
                $attempt->provider_message ?? 'Ya existe un intento para este XML.',
                isset($attempt->http_status) ? (int) $attempt->http_status : null,
                $attempt->uuid ?? null,
                false,
                (int) ($attempt->requires_reconciliation ?? 0) === 1,
                $attempt->recommended_action ?? 'Revisar el intento existente.'
            );
        }

        try {
            ($this->stampAccounts ??= new FiscalStampAccountService($this->db))
                ->reserveForAttempt((int)$attempt->id, $userId);
        } catch (FiscalStampBalanceException $balanceError) {
            $this->db->transStart();
            $this->db->table('fiscal_stamp_attempts')->where('id',$attempt->id)->update(['status'=>'insufficient_balance','retryable'=>1,'error_category'=>'commercial_balance','updated_at'=>get_current_utc_time()]);
            $this->db->table('fiscal_documents')->where('id',$documentId)->update(['status'=>'stamping_error','stamp_updated_at'=>get_current_utc_time()]);
            $this->audit($documentId,$userId,'stamp_balance_blocked',['attempt_id'=>$attempt->id]);
            $this->db->transComplete();
            throw $balanceError;
        }

        if (!hash_equals($verification->xmlSha256, hash('sha256', $xml))) {
            $this->markPreparationFailure((int) $attempt->id, $documentId);
            $this->releaseCommercialReservation((int)$attempt->id,$userId,'Signed XML changed before PAC transport.');
            throw new RuntimeException('El XML firmado cambió después de su verificación.');
        }

        try {
            [$transportXml,$keyPem] = $this->transportMaterial($documentId,$userId,$provider,$xml);
        } catch (\Throwable $preTransportError) {
            $this->finishNotSent(
                (int) $attempt->id,
                $documentId,
                'No fue posible preparar de forma segura el transporte al PAC.',
                0
            );
            $this->releaseCommercialReservation((int)$attempt->id,$userId,'Transport preparation failed before PAC request.');
            throw $preTransportError;
        }

        // This update must succeed immediately before the adapter is invoked.
        try{$this->markSending((int)$attempt->id,$documentId);}catch(Throwable$e){$this->releaseCommercialReservation((int)$attempt->id,$userId,'Local persistence failed before PAC request.');throw$e;}
        $started = microtime(true);
        try {
            $response = $adapter->stamp(new StampRequest(
                (int) $document->id,
                $transportXml,
                hash('sha256',$transportXml),
                $provider,
                $environment,
                (string) $attempt->idempotency_key,
                $keyPem
            ));
        } catch (\Throwable $preTransportError) {
            $this->finishNotSent(
                (int)$attempt->id,$documentId,
                'El adaptador rechazó la solicitud antes del transporte.',
                (int)round((microtime(true)-$started)*1000)
            );
            $this->releaseCommercialReservation((int)$attempt->id,$userId,'PAC adapter failed before request transport.');
            throw $preTransportError;
        }
        unset($keyPem);
        $duration = (int) round((microtime(true) - $started) * 1000);
        $this->persistResponseForensics((int)$attempt->id,$response);

        if ($response->transportError) {
            if (($response->metadata['request_sent'] ?? null) === false && !$response->timeout) {
                $this->finishNotSent((int) $attempt->id, $documentId, $response->message, $duration);
                $this->releaseCommercialReservation((int)$attempt->id,$userId,'PAC confirmed request was not sent.');

                return new FiscalStampingResult(
                    false, 'transport_not_sent', $documentId, (int) $attempt->id,
                    'transport', null, $response->message, null, null,
                    true, false, 'Revisar conectividad antes de preparar una nueva versión.'
                );
            }

            $this->finishUnknown(
                (int) $attempt->id,
                $documentId,
                $response->timeout ? 'timeout_unknown' : 'transport_unknown',
                $response->message,
                $duration
            );

            return new FiscalStampingResult(
                false, 'unknown', $documentId, (int) $attempt->id,
                'transport', null, 'No fue posible confirmar el resultado del timbrado.',
                null, null, false, true, 'Revisar intento y conciliar sin reenviar.'
            );
        }

        if (($response->metadata['parsing_phase'] ?? null) === 'outer_response_invalid') {
            $this->finishInvalidResponse((int)$attempt->id,$documentId,'outer_response_invalid',$response,$duration,
                (string)($response->metadata['response_error_class']??'RuntimeException'),
                (string)($response->metadata['response_error_message']??'Respuesta exterior inválida.'));
            return new FiscalStampingResult(false,'unknown',$documentId,(int)$attempt->id,'outer_response_invalid',null,
                'La respuesta exterior del PAC requiere conciliación.',$response->httpStatus,null,false,true,'Conciliar sin reenviar.');
        }

        $mapper = new TimbradorXpressErrorMapper();
        if ($mapper->isDuplicate($response->code)) {
            $this->finishUnknown(
                (int) $attempt->id,
                $documentId,
                'duplicate_reported',
                $response->message,
                $duration,
                $response->httpStatus,
                $response->code
            );

            return new FiscalStampingResult(
                false, 'unknown', $documentId, (int) $attempt->id,
                'provider', $response->code, $response->message,
                $response->httpStatus, null, false, true,
                'Conciliar el CFDI informado como duplicado.'
            );
        }

        if (!$mapper->isSuccess($response->code)) {
            return $this->reject($documentId, $userId, $attempt, $response, $duration, $mapper);
        }

        try {$data=(new TimbradorXpressStampDataParser())->parse($response);}
        catch(Throwable $e){$this->finishInvalidResponse((int)$attempt->id,$documentId,'stamp_data_invalid',$response,$duration,get_class($e),$e->getMessage());throw new RuntimeException('Los datos de timbrado requieren conciliación.');}
        $stampedXml=(string)$data['XML'];
        try {$validated=(new StampedXmlValidator())->validate($stampedXml,$transportXml);}
        catch(Throwable $e){$phase=$this->isWellFormedXml($stampedXml)?'stamped_xml_semantic_invalid':'stamped_xml_invalid';$this->finishInvalidResponse((int)$attempt->id,$documentId,$phase,$response,$duration,get_class($e),$e->getMessage());throw new RuntimeException('El XML timbrado requiere conciliación.');}
        $warnings=$this->compareAuxiliary($data,$validated);

        $contingency = (new PacContingencyStorageService(
            $this->vault ?? new PacSecretVault(),
            $this->contingencyRoot
        ))->store((int) $attempt->id, $stampedXml);
        $this->db->table('fiscal_stamp_attempts')->where('id', $attempt->id)->update([
            'contingency_path' => $contingency['path'],
            'response_hash' => $contingency['hash'],
            'updated_at' => get_current_utc_time(),
        ]);

        try {
            $stored = (new FiscalArtifactStorageService($this->db, $this->artifactRoot))->store(
                $documentId,
                'stamped_xml',
                $stampedXml,
                $provider . '-stamp-v1',
                'CFDI 4.0 + TFD 1.1',
                (string) $artifact->schema_sha256,
                'valid',
                [
                    'provider' => $provider,
                    'environment' => $environment,
                    'attempt_id' => $attempt->id,
                    'uuid' => $validated['uuid'],
                    'auxiliary_warnings' => $warnings,
                ],
                $userId
            );

            if (($response->metadata['force_persistence_error'] ?? false) === true) {
                throw new RuntimeException('Fallo de persistencia simulado.');
            }

            $this->db->transBegin();
            if ($this->db->table('fiscal_document_stamps')
                ->where('fiscal_document_id', $documentId)->countAllResults() > 0) {
                throw new RuntimeException('El documento ya tiene un timbre registrado.');
            }

            $this->db->table('fiscal_document_stamps')->insert([
                'fiscal_document_id' => $documentId,
                'stamp_attempt_id' => $attempt->id,
                'stamped_xml_artifact_id' => $stored->id,
                'uuid' => $validated['uuid'],
                'stamp_date' => $this->sqlDate($validated['stamp_date']),
                'pac_rfc' => $validated['pac_rfc'],
                'sat_certificate_number' => $validated['sat_certificate_number'],
                'cfd_seal' => $validated['cfd_seal'],
                'sat_seal' => $validated['sat_seal'],
                'tfd_version' => $validated['tfd_version'],
                'provider' => $provider,
                'environment' => $environment,
                'stamped_xml_sha256' => $validated['sha256'],
                'provider_original_chain' => $data['CadenaOriginal'],
                'sat_original_chain' => $data['CadenaOriginalSAT'],
                'qr_data' => $this->safeQrData($data['CodigoQR']),
                'auxiliary_warnings' => json_encode($warnings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'pdf_status' => 'pending',
                'pdf_template' => $providerConfig->pdfTemplate,
                'created_at' => get_current_utc_time(),
            ]);
            $stampId=(int)$this->db->insertID();
            $this->db->table('fiscal_stamp_attempts')->where('id', $attempt->id)->update([
                'status' => 'success',
                'responded_at' => get_current_utc_time(),
                'http_status' => $response->httpStatus,
                'provider_code' => $response->code,
                'provider_message' => $response->message,
                'uuid' => $validated['uuid'],
                'response_hash' => $validated['sha256'],
                'duration_ms' => $duration,
                'updated_at' => get_current_utc_time(),
            ]);
            $this->db->table('fiscal_documents')->where('id', $documentId)->update([
                'status' => 'stamped',
                'stamp_updated_at' => get_current_utc_time(),
            ]);
            $this->audit($documentId, $userId, 'stamped', [
                'attempt_id' => $attempt->id,
                'uuid' => $validated['uuid'],
                'hash' => $validated['sha256'],
                'environment' => $environment,
            ]);
            if (!$this->db->transStatus()) {
                throw new RuntimeException('No fue posible persistir el timbre.');
            }
            $this->db->transCommit();

            $commercialReconciliationPending=false;
            try {
                ($this->stampAccounts ??= new FiscalStampAccountService($this->db))
                    ->consumeReservation((int)$attempt->id,$stampId,$userId);
            } catch (Throwable $commercialError) {
                $commercialReconciliationPending=true;
                $this->db->table('fiscal_stamp_attempts')->where('id',$attempt->id)->update(['status'=>'success_local_reconciliation_pending','requires_reconciliation'=>1,'error_category'=>'commercial_consumption','updated_at'=>get_current_utc_time()]);
                $this->db->table('fiscal_documents')->where('id',$documentId)->update(['status'=>'stamped_local_reconciliation_pending','stamp_updated_at'=>get_current_utc_time()]);
                log_message('error','CFDI stamped; local stamp consumption pending for document {document}: {type}',['document'=>$documentId,'type'=>get_class($commercialError)]);
            }

            $pdfResult = $this->persistOptionalPacPdf(
                $documentId,
                (int) $attempt->id,
                $validated['uuid'],
                $data['PDF'] ?? null,
                $providerConfig,
                $userId
            );
            $operationalStatus = $commercialReconciliationPending ? 'stamped_local_reconciliation_pending' : match ($pdfResult['status']) {
                'valid' => 'stamped',
                'pending' => 'stamped_pdf_pending',
                default => 'stamped_pdf_error',
            };
            $this->db->table('fiscal_documents')->where('id', $documentId)->update([
                'status' => $operationalStatus,
                'stamp_updated_at' => get_current_utc_time(),
            ]);

            $resultStage = $commercialReconciliationPending ? 'local_reconciliation' : ($operationalStatus === 'stamped' ? 'completed' : 'pdf');
            $resultMessage = $commercialReconciliationPending
                ? 'CFDI timbrado; conciliacion administrativa local pendiente.'
                : ($operationalStatus === 'stamped' ? $response->message : ($pdfResult['status'] === 'pending' ? 'CFDI timbrado; PDF pendiente.' : 'CFDI timbrado; PDF invalido.'));
            return new FiscalStampingResult(
                true, $operationalStatus, $documentId, (int) $attempt->id,
                $resultStage, $response->code, $resultMessage,
                $response->httpStatus, $validated['uuid'], false, false,
                $commercialReconciliationPending ? 'Completar conciliacion local' : ($operationalStatus === 'stamped' ? null : 'Recuperar PDF'),
                true, $pdfResult['status'] === 'valid',
                !$commercialReconciliationPending && $operationalStatus !== 'stamped'
            );
        } catch (Throwable $e) {
            $this->db->transRollback();
            $persistedStamp = $this->db->table('fiscal_document_stamps')
                ->where('fiscal_document_id', $documentId)->get(1)->getRow();
            if ($persistedStamp && trim((string) ($persistedStamp->uuid ?? '')) !== '') {
                $this->db->table('fiscal_stamp_attempts')->where('id', $attempt->id)->update([
                    'status' => 'success_local_reconciliation_pending',
                    'error_category' => 'post_stamp_local_failure',
                    'requires_reconciliation' => 1,
                    'retryable' => 0,
                    'updated_at' => get_current_utc_time(),
                ]);
                $this->db->table('fiscal_documents')->where('id', $documentId)->update([
                    'status' => 'stamped_local_reconciliation_pending',
                    'stamp_updated_at' => get_current_utc_time(),
                ]);
                log_message('error', 'CFDI_POST_STAMP_PERSISTENCE_FAILURE document {document}: {type}', [
                    'document' => $documentId,
                    'type' => get_class($e),
                ]);
                return new FiscalStampingResult(
                    true, 'stamped_local_reconciliation_pending', $documentId, (int) $attempt->id,
                    'local_reconciliation', null,
                    'CFDI timbrado; existe una actualizacion local pendiente.',
                    null, (string) $persistedStamp->uuid, false, false,
                    'Completar conciliacion local', true,
                    (string) ($persistedStamp->pdf_status ?? '') === 'valid',
                    (string) ($persistedStamp->pdf_status ?? '') !== 'valid'
                );
            }
            $this->db->table('fiscal_stamp_attempts')->where('id', $attempt->id)->update([
                'status' => 'reconciliation_required',
                'error_category' => 'persistence_error',
                'requires_reconciliation' => 1,
                'retryable' => 0,
                'updated_at' => get_current_utc_time(),
            ]);
            $this->db->table('fiscal_documents')->where('id', $documentId)->update([
                'status' => 'stamp_status_unknown',
                'stamp_updated_at' => get_current_utc_time(),
            ]);
            throw new RuntimeException('La respuesta fiscal requiere conciliacion por un error de persistencia.');
        }
    }
    private function prepare(
        int $documentId,
        int $userId,
        string $provider,
        string $environment,
        string $operation
    ): array {
        $this->db->transBegin();
        try {
            $table = $this->db->prefixTable('fiscal_documents');
            $document = $this->db->query(
                "SELECT * FROM {$table} WHERE id=? AND deleted=0 FOR UPDATE",
                [$documentId]
            )->getRow();
            if (!$document) {
                throw new RuntimeException('El documento fiscal no existe.');
            }
            $signature = $this->db->table('fiscal_document_signatures')->where([
                'fiscal_document_id' => $documentId,
                'signature_verified' => 1,
                'xsd_status' => 'valid',
            ])->orderBy('id', 'DESC')->get(1)->getRow();
            if (!$signature) {
                throw new RuntimeException('No existe un XML firmado y validado.');
            }
            $certificate = $this->db->table('fiscal_issuer_certificates')->where([
                'id' => $signature->certificate_id,
                'issuer_profile_id' => $document->issuer_profile_id,
                'status' => 'valid',
                'deleted' => 0,
            ])->get(1)->getRow();
            $now = get_current_utc_time();
            if (!$certificate || $certificate->valid_from > $now || $certificate->valid_to < $now) {
                throw new RuntimeException('El certificado del XML firmado no está vigente.');
            }
            $artifact = $this->db->table('fiscal_document_artifacts')->where([
                'id' => $signature->signed_xml_artifact_id,
                'fiscal_document_id' => $documentId,
                'artifact_type' => 'signed_xml',
                'validation_status' => 'valid',
                'superseded_at' => null,
            ])->get(1)->getRow();
            if (!$artifact) {
                throw new RuntimeException('El artefacto signed_xml activo no existe.');
            }
            $xml = (new FiscalArtifactStorageService($this->db, $this->artifactRoot))->read($artifact);
            $key = hash('sha256', implode('|', [
                $documentId,
                $artifact->sha256,
                $provider,
                $environment,
                $operation,
            ]));
            $existing = $this->db->table('fiscal_stamp_attempts')
                ->where('idempotency_key', $key)->get(1)->getRow();
            if ($existing) {
                if ((int)($existing->retryable ?? 0) !== 1
                    || !in_array((string)$existing->status, ['transport_not_sent','provider_rejected','rejected','insufficient_balance'], true)) {
                    $this->db->transCommit();
                    return [$document, $artifact, $existing, $xml, true];
                }
                $attemptNumber=(int)$this->db->table('fiscal_stamp_attempts')
                    ->where('fiscal_document_id',$documentId)->selectMax('attempt_number','number')
                    ->get()->getRow('number')+1;
                $key=hash('sha256',$key.'|manual-retry|'.$attemptNumber);
            } else {
                $attemptNumber=1;
            }

            if ($document->status === 'stamped'
                || $this->db->table('fiscal_document_stamps')
                    ->where('fiscal_document_id', $documentId)->countAllResults()) {
                throw new RuntimeException('Este CFDI ya está timbrado.');
            }
            if (in_array($document->status, ['stamping', 'stamp_status_unknown'], true)) {
                throw new RuntimeException('Este CFDI requiere conciliación y no puede reenviarse.');
            }
            if (!in_array($document->status, ['locked', 'ready_to_stamp', 'stamping_error'], true)) {
                throw new RuntimeException('El documento no está listo para timbrado.');
            }

            $data = [
                'fiscal_document_id' => $documentId,
                'signed_xml_artifact_id' => $artifact->id,
                'pac_configuration_id' => null,
                'provider' => $provider,
                'environment' => $environment,
                'operation' => $operation,
                'request_hash' => $artifact->sha256,
                'idempotency_key' => $key,
                'attempt_number' => $attemptNumber,
                'status' => 'pending',
                'started_at' => $now,
                'sent_at' => null,
                'retryable' => 0,
                'requires_reconciliation' => 0,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (!$this->db->table('fiscal_stamp_attempts')->insert($data)) {
                throw new RuntimeException('No fue posible crear el intento durable.');
            }
            $data['id'] = (int) $this->db->insertID();
            if ($data['id'] <= 0) {
                throw new RuntimeException('El intento durable no obtuvo un identificador.');
            }
            $this->db->table('fiscal_documents')->where('id', $documentId)->update([
                'status' => 'stamping',
                'stamp_updated_at' => $now,
            ]);
            $this->audit($documentId, $userId, 'stamp_prepared', [
                'attempt_id' => $data['id'],
                'environment' => $environment,
                'request_hash' => $artifact->sha256,
                'operation' => $operation,
                'keyPEM' => $operation === 'timbrarConSello',
                'cerPEM' => false,
            ]);
            if (!$this->db->transStatus()) {
                throw new RuntimeException('No fue posible preparar el intento.');
            }
            $this->db->transCommit();

            return [$document, $artifact, (object) $data, $xml, false];
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    private function markSending(int $attemptId, int $documentId): void
    {
        $now = get_current_utc_time();
        $this->db->transBegin();
        try {
            $updated = $this->db->table('fiscal_stamp_attempts')
                ->where(['id' => $attemptId, 'fiscal_document_id' => $documentId, 'status' => 'pending'])
                ->update(['status' => 'sending', 'sent_at' => $now, 'updated_at' => $now]);
            if (!$updated || $this->db->affectedRows() !== 1 || !$this->db->transStatus()) {
                throw new RuntimeException('No fue posible confirmar el intento antes del envío.');
            }
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->markPreparationFailure($attemptId, $documentId);
            throw $e;
        }
    }

    private function markPreparationFailure(int $attemptId, int $documentId): void
    {
        $this->db->transStart();
        $this->db->table('fiscal_stamp_attempts')->where('id', $attemptId)->update([
            'status' => 'preparation_error',
            'error_category' => 'local_persistence',
            'retryable' => 0,
            'updated_at' => get_current_utc_time(),
        ]);
        $this->db->table('fiscal_documents')->where('id', $documentId)->update([
            'status' => 'stamping_error',
            'stamp_updated_at' => get_current_utc_time(),
        ]);
        $this->db->transComplete();
    }

    public function verifySignedDocument(int $documentId): SignedXmlVerificationResult
    {
        $document = $this->db->table('fiscal_documents')
            ->where(['id' => $documentId, 'deleted' => 0])->get(1)->getRow();
        $issuer = $this->db->table('fiscal_document_issuers')
            ->where('fiscal_document_id', $documentId)->get(1)->getRow();
        $signature = $this->db->table('fiscal_document_signatures')
            ->where('fiscal_document_id', $documentId)->orderBy('id', 'DESC')->get(1)->getRow();
        if (!$document || !$issuer || !$signature) {
            throw new RuntimeException('No existe un documento firmado completo para verificar.');
        }
        $artifact = $this->db->table('fiscal_document_artifacts')->where([
            'id' => $signature->signed_xml_artifact_id,
            'fiscal_document_id' => $documentId,
            'artifact_type' => 'signed_xml',
            'superseded_at' => null,
        ])->get(1)->getRow();
        if (!$artifact) {
            throw new RuntimeException('El artefacto signed_xml no existe.');
        }
        $xml = (new FiscalArtifactStorageService($this->db, $this->artifactRoot))->read($artifact);
        if (is_callable($this->signedXmlVerifier)) {
            return ($this->signedXmlVerifier)($xml, (string) $issuer->rfc, (string) $artifact->sha256);
        }

        return (new SignedXmlVerifier())->verify($xml, (string) $issuer->rfc, (string) $artifact->sha256);
    }

    private function persistOptionalPacPdf(
        int $documentId,
        int $attemptId,
        string $uuid,
        ?string $pdfBase64,
        TimbradorXpress $config,
        int $userId
    ): array {
        if ($pdfBase64 === null || trim($pdfBase64) === '') {
            $this->db->table('fiscal_document_stamps')->where('fiscal_document_id', $documentId)->update([
                'pdf_status' => 'pending',
                'pdf_template' => $config->pdfTemplate,
            ]);
            return ['status' => 'pending'];
        }
        try {
            $artifact = (new PacPdfArtifactService(
                $this->db,
                new PacPdfValidator($config->maxPdfBytes)
            ))->store($documentId, $attemptId, $uuid, $pdfBase64, $config->pdfTemplate, $userId);
            $this->db->table('fiscal_document_stamps')->where('fiscal_document_id', $documentId)->update([
                'pac_pdf_artifact_id' => $artifact->id,
                'pdf_status' => 'valid',
                'pdf_template' => $config->pdfTemplate,
            ]);
            $this->audit($documentId, $userId, 'pac_pdf_stored', [
                'artifact_id' => $artifact->id,
                'sha256' => $artifact->decoded_sha256,
                'size' => $artifact->decoded_size_bytes,
            ]);

            return ['status' => 'valid', 'artifact_id' => $artifact->id];
        } catch (Throwable $e) {
            $this->db->table('fiscal_document_stamps')->where('fiscal_document_id', $documentId)->update([
                'pdf_status' => 'error',
                'pdf_template' => $config->pdfTemplate,
            ]);
            $clean = trim((string) $pdfBase64);
            $bytes = base64_decode($clean, true);
            $this->audit($documentId, $userId, 'pac_pdf_invalid', [
                'attempt_id' => $attemptId,
                'code' => str_contains($e->getMessage(), 'PDF_STRUCTURE_INVALID')
                    ? 'PDF_STRUCTURE_INVALID'
                    : 'PDF_CONTENT_INVALID',
                'size' => $bytes === false ? null : strlen($bytes),
                'sha256' => $bytes === false ? null : hash('sha256', $bytes),
                'stage' => 'pac_pdf_validation',
            ]);
            log_message('warning', 'PAC PDF rejected for document {document}, attempt {attempt}: {code}', [
                'document' => $documentId,
                'attempt' => $attemptId,
                'code' => str_contains($e->getMessage(), 'PDF_STRUCTURE_INVALID')
                    ? 'PDF_STRUCTURE_INVALID'
                    : 'PDF_CONTENT_INVALID',
            ]);

            return ['status' => 'error'];
        }
    }

    private function reject(
        int $documentId,
        int $userId,
        object $attempt,
        object $response,
        int $duration,
        TimbradorXpressErrorMapper $mapper
    ): FiscalStampingResult {
        $error = $mapper->map($response->code, $response->message);
        $this->db->transStart();
        $this->db->table('fiscal_stamp_attempts')->where('id', $attempt->id)->update([
            'status' => 'rejected',
            'responded_at' => get_current_utc_time(),
            'http_status' => $response->httpStatus,
            'provider_code' => $response->code,
            'provider_message' => $response->message,
            'error_category' => $error->category,
            'parsing_phase' => 'provider_rejected',
            'recommended_action' => $error->recommendedAction,
            'requires_reconciliation' => $error->requiresReconciliation ? 1 : 0,
            'retryable' => $error->retryable ? 1 : 0,
            'duration_ms' => $duration,
            'updated_at' => get_current_utc_time(),
        ]);
        $this->db->table('fiscal_documents')->where('id', $documentId)->update([
            'status' => 'stamping_error',
            'stamp_updated_at' => get_current_utc_time(),
        ]);
        $this->audit($documentId, $userId, 'stamp_rejected', [
            'attempt_id' => $attempt->id,
            'provider_code' => $response->code,
            'category' => $error->category,
        ]);
        $this->db->transComplete();
        $this->releaseCommercialReservation((int)$attempt->id,$userId,'PAC definitively rejected the CFDI.');

        return new FiscalStampingResult(
            false, 'rejected', $documentId, (int) $attempt->id,
            'provider', $response->code, $response->message,
            $response->httpStatus, null, $error->retryable,
            $error->requiresReconciliation, $error->recommendedAction
        );
    }

    private function releaseCommercialReservation(int$attemptId,int$userId,string$reason):void
    {
        try{($this->stampAccounts??=new FiscalStampAccountService($this->db))->releaseReservation($attemptId,$reason,$userId);}
        catch(FiscalStampBalanceException$e){if($e->getMessage()!=='STAMP_RESERVATION_MISSING')throw$e;}
    }

    private function finishUnknown(
        int $attemptId,
        int $documentId,
        string $status,
        string $message,
        int $duration,
        int $httpStatus = 0,
        ?string $code = null
    ): void {
        $this->db->transStart();
        $this->db->table('fiscal_stamp_attempts')->where('id', $attemptId)->update([
            'status' => $status,
            'responded_at' => get_current_utc_time(),
            'http_status' => $httpStatus ?: null,
            'provider_code' => $code,
            'provider_message' => $message,
            'error_category' => 'status_unknown',
            'requires_reconciliation' => 1,
            'retryable' => 0,
            'duration_ms' => $duration,
            'updated_at' => get_current_utc_time(),
        ]);
        $this->db->table('fiscal_documents')->where('id', $documentId)->update([
            'status' => 'stamp_status_unknown',
            'stamp_updated_at' => get_current_utc_time(),
        ]);
        $this->db->transComplete();
    }

    private function finishNotSent(int $attemptId, int $documentId, string $message, int $duration): void
    {
        $this->db->transStart();
        $this->db->table('fiscal_stamp_attempts')->where('id', $attemptId)->update([
            'status' => 'transport_not_sent',
            'responded_at' => get_current_utc_time(),
            'provider_message' => $message,
            'error_category' => 'transport_not_sent',
            'requires_reconciliation' => 0,
            'retryable' => 1,
            'duration_ms' => $duration,
            'updated_at' => get_current_utc_time(),
        ]);
        $this->db->table('fiscal_documents')->where('id', $documentId)->update([
            'status' => 'stamping_error',
            'stamp_updated_at' => get_current_utc_time(),
        ]);
        $this->db->transComplete();
    }

    private function persistResponseForensics(int $attemptId, object $response): void
    {
        $metadata=$response->metadata??[];
        $row=[
            'response_content_type'=>$metadata['response_content_type']??null,
            'response_body_length'=>$metadata['response_body_length']??null,
            'response_body_sha256'=>$metadata['response_body_sha256']??null,
            'parsing_phase'=>$metadata['parsing_phase']??'transport_completed',
            'response_error_class'=>$metadata['response_error_class']??null,
            'response_error_message'=>$metadata['response_error_message']??null,
            'response_structure'=>isset($metadata['response_structure'])?json_encode($metadata['response_structure'],JSON_UNESCAPED_SLASHES):json_encode(['keys'=>$metadata['response_keys']??[],'has_data'=>$metadata['has_data']??false],JSON_UNESCAPED_SLASHES),
        ];
        if(!empty($metadata['forensic_path']))$row['contingency_path']=$metadata['forensic_path'];
        $this->db->table('fiscal_stamp_attempts')->where('id',$attemptId)->update($row);
    }

    private function finishInvalidResponse(int $attemptId,int $documentId,string $phase,object $response,int $duration,string $errorClass,string $errorMessage):void
    {
        $safe=mb_substr(trim(strip_tags((string)preg_replace('/[\x00-\x1F\x7F]+/u',' ',$errorMessage))),0,500);
        $this->db->transStart();
        $this->db->table('fiscal_stamp_attempts')->where('id',$attemptId)->update([
            'status'=>$phase,'responded_at'=>get_current_utc_time(),'http_status'=>$response->httpStatus?:null,
            'provider_code'=>$response->code,'provider_message'=>'Respuesta PAC no interpretable.',
            'error_category'=>$phase,'retryable'=>0,'requires_reconciliation'=>1,'duration_ms'=>$duration,
            'response_hash'=>$response->data?hash('sha256',$response->data):($response->metadata['response_body_sha256']??null),
            'parsing_phase'=>$phase,'response_error_class'=>mb_substr($errorClass,0,160),'response_error_message'=>$safe,'updated_at'=>get_current_utc_time(),
        ]);
        $this->db->table('fiscal_documents')->where('id',$documentId)->update(['status'=>'stamp_status_unknown','stamp_updated_at'=>get_current_utc_time()]);
        $this->db->transComplete();
    }

    private function isWellFormedXml(string $xml):bool
    {
        if($xml===''||stripos($xml,'<!DOCTYPE')!==false||stripos($xml,'<!ENTITY')!==false)return false;
        $dom=new \DOMDocument();$previous=libxml_use_internal_errors(true);
        try{return @$dom->loadXML($xml,LIBXML_NONET)!==false;}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    }

    private function compareAuxiliary(array $data, array $xml): array
    {
        $map = [
            'UUID' => 'uuid',
            'FechaTimbrado' => 'stamp_date',
            'NoCertificadoSAT' => 'sat_certificate_number',
            'Sello' => 'cfd_seal',
            'SelloSAT' => 'sat_seal',
        ];
        $warnings = [];
        foreach ($map as $auxiliary => $authoritative) {
            if ($data[$auxiliary] !== null
                && trim((string) $data[$auxiliary]) !== trim((string) $xml[$authoritative])) {
                $warnings[] = "Discrepancia {$auxiliary}: se conservó el valor del XML.";
            }
        }

        return $warnings;
    }

    private function transportMaterial(int $documentId, int $userId, string $provider, string $signedXml): array
    {
        if ($provider !== 'timbradorxpress') return [$signedXml, null];
        $signature=$this->db->table('fiscal_document_signatures')->where('fiscal_document_id',$documentId)->orderBy('id','DESC')->get(1)->getRow();
        $certificate=$signature?$this->db->table('fiscal_issuer_certificates')->where(['id'=>$signature->certificate_id,'deleted'=>0,'status'=>'valid'])->get(1)->getRow():null;
        if(!$certificate)throw new RuntimeException('El CSD activo no está disponible para timbrarConSello.');
        $secrets=new CsdCertificateSecretService($this->db);
        $password=$secrets->passwordForSigning((int)$certificate->id,$userId);
        $csd=new CsdCertificateService($this->db);
        $material=$csd->certificateMaterial($certificate);$key=$csd->openPrivateKey($material['private_key_bytes'],$password);$keyPem='';
        try{
            $keyPem=$csd->exportPrivateKeyPem($key);
            $dom=new \DOMDocument('1.0','UTF-8');
            if(!$dom->loadXML($signedXml,LIBXML_NONET|LIBXML_NOBLANKS)||!$dom->documentElement)throw new RuntimeException('El XML para timbrado no está bien formado.');
            $dom->documentElement->setAttribute('Sello','');
            return[(string)$dom->saveXML(),$keyPem];
        }finally{unset($password,$material,$key,$keyPem);}
    }

    private function safeQrData(?string $value): ?string
    {
        if ($value === null || $value === '') return null;
        $value = trim($value);
        if (strlen($value) > 4096 || preg_match('/<[^>]+>/', $value)) return null;
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return str_starts_with($value, 'https://verificacfdi.facturaelectronica.sat.gob.mx/')
                ? $value : null;
        }

        return preg_match('/^[A-Za-z0-9+\/=_?&%.:\-]+$/', $value) ? $value : null;
    }

    private function audit(int $documentId, int $userId, string $action, array $details): void
    {
        $document = $this->db->table('fiscal_documents')->where('id', $documentId)->get(1)->getRow();
        $this->db->table('fiscal_document_audit')->insert([
            'fiscal_document_id' => $documentId,
            'invoice_id' => $document->invoice_id ?? null,
            'user_id' => $userId,
            'action' => $action,
            'reason' => mb_substr(json_encode($details, JSON_UNESCAPED_SLASHES), 0, 500),
            'created_at' => get_current_utc_time(),
        ]);
    }

    private function sqlDate(string $date): string
    {
        return gmdate('Y-m-d H:i:s', strtotime($date));
    }
}
