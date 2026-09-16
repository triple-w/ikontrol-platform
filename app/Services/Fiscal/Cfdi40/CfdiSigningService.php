<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Cfdi40;

use App\Services\Fiscal\CsdCertificateService;
use App\Services\Fiscal\FiscalArtifactStorageService;
use App\Services\Fiscal\Signing\SignedXmlVerifier;
use App\Services\Fiscal\Signing\CsdCertificateSecretService;
use App\Domain\Fiscal\Signing\CsdSecretException;
use DOMDocument;
use DOMXPath;
use RuntimeException;
use Throwable;

final class CfdiSigningService
{
    public const VERSION = 'ikontrol-local-signing-v1';
    private $db;
    private ?string $certificateRoot;
    private ?string $artifactRoot;
    private ?string $preXmlRoot;
    private ?string $xsltMain;
    private ?CsdCertificateSecretService $secretService;

    public function __construct($db = null, ?string $certificateRoot = null, ?string $artifactRoot = null, ?string $preXmlRoot = null, ?string $xsltMain = null, ?CsdCertificateSecretService $secretService = null)
    {
        $this->db = $db ?: db_connect();
        $this->certificateRoot = $certificateRoot;
        $this->artifactRoot = $artifactRoot;
        $this->preXmlRoot = $preXmlRoot;
        $this->xsltMain = $xsltMain;
        $this->secretService = $secretService;
    }

    public function sign(int $documentId, int $preXmlArtifactId, int $certificateId, int $userId, bool $authorized): array
    {
        if (!$authorized) {
            throw new RuntimeException('No tiene permiso para sellar XML localmente.');
        }
        $document = $this->db->table('fiscal_documents')->where(['id' => $documentId, 'deleted' => 0])->whereIn('status', ['locked', 'ready_to_stamp'])->get(1)->getRow();
        if (!$document) {
            throw new RuntimeException('El documento fiscal debe estar cerrado antes del sellado local.');
        }
        $preXmlArtifact = $this->db->table('fiscal_document_artifacts')->where([
            'id' => $preXmlArtifactId, 'fiscal_document_id' => $documentId,
            'artifact_type' => 'pre_xml', 'superseded_at' => null,
        ])->get(1)->getRow();
        if (!$preXmlArtifact) {
            throw new RuntimeException('El Pre-XML vigente no existe.');
        }
        $existing = $this->db->table('fiscal_document_signatures')->where([
            'fiscal_document_id' => $documentId,
            'pre_xml_sha256' => $preXmlArtifact->sha256,
            'certificate_id' => $certificateId,
        ])->get(1)->getRow();
        if ($existing) {
            return ['action' => 'existing', 'signature' => $existing];
        }
        $certificate = $this->db->table('fiscal_issuer_certificates')->where([
            'id' => $certificateId, 'issuer_profile_id' => $document->issuer_profile_id,
            'status' => 'valid', 'deleted' => 0,
        ])->get(1)->getRow();
        if (!$certificate) {
            throw new CsdSecretException(
                'CSD_CERTIFICATE_NOT_READY',
                'El CSD seleccionado no está vigente o no corresponde al emisor.'
            );
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($certificate->valid_from > $now || $certificate->valid_to < $now) {
            throw new CsdSecretException(
                'CSD_CERTIFICATE_NOT_READY',
                'El CSD seleccionado no se encuentra dentro de su vigencia local.'
            );
        }
        $issuerSnapshot = $this->db->table('fiscal_document_issuers')->where('fiscal_document_id', $documentId)->get(1)->getRow();
        if (!$issuerSnapshot || $this->normalizeRfc($issuerSnapshot->rfc) !== $this->normalizeRfc($certificate->certificate_rfc)) {
            throw new CsdSecretException(
                'CSD_CERTIFICATE_NOT_READY',
                'El CSD no corresponde al RFC congelado del emisor.'
            );
        }

        $fiscalMetadata=$this->db->table('fiscal_document_metadata')->where('fiscal_document_id',$documentId)->get(1)->getRow();
        $fiscalMetadataPayload=$fiscalMetadata?json_decode((string)$fiscalMetadata->metadata_json,true):[];
        if ((string)$document->document_type === 'payment') {
            $metadata=$this->db->table('fiscal_document_metadata')->where('fiscal_document_id',$documentId)->get(1)->getRow();
            $meta=$metadata?json_decode((string)$metadata->metadata_json,true):[];
            $complementId=(int)($meta['payment_complement_id']??0);
            if(!$complementId)throw new RuntimeException('El documento Pago no conserva el vínculo con su Complemento.');
            $paymentPreflight=(new \App\FiscalServices\PaymentComplementPreflightService($this->db))->requireReady($complementId);
            if(!hash_equals((string)$document->source_snapshot_hash,(string)$paymentPreflight['snapshot']['sha256']))throw new RuntimeException('El borrador cambió después de materializar el documento Pago.');
            $semantic=['is_valid'=>true,'validation_level'=>'payment_complement_preflight','errors'=>[],'warnings'=>$paymentPreflight['warnings']];
        } elseif ((string)$document->document_type === 'expense' && !empty($fiscalMetadataPayload['credit_note_id'])) {
            $creditNoteId=(int)$fiscalMetadataPayload['credit_note_id'];$credit=new \App\Services\Fiscal\CreditNoteService($this->db);$review=$credit->review($creditNoteId);
            if(!$review['ready'])throw new RuntimeException('La Nota de Crédito no supera la revisión fiscal.');
            if(!hash_equals((string)$document->source_snapshot_hash,hash('sha256',$credit->buildXml($creditNoteId,(string)$document->series,(int)$document->folio))))throw new RuntimeException('La Nota de Crédito cambió después de materializar su CFDI E.');
            $semantic=['is_valid'=>true,'validation_level'=>'credit_note_preflight','errors'=>[],'warnings'=>[]];
        } else {
            $mapped = (new CfdiDraftMapper())->map($documentId);
            $semantic = (new CfdiSemanticValidator())->validate($mapped);
            if (!$semantic['is_valid']) {
                throw new RuntimeException('El documento no supera la validación semántica previa al sellado.');
            }
        }
        $preXmlResult = (new CfdiPreXmlArtifactService($this->db, $this->preXmlRoot))->read($preXmlArtifactId, true);
        $preXml = $preXmlResult['xml'];
        if (!hash_equals((string) $preXmlArtifact->sha256, hash('sha256', $preXml))) {
            throw new RuntimeException('El Pre-XML cambió y no puede firmarse.');
        }
        $csdService = new CsdCertificateService($this->db, $this->certificateRoot);
        $material = $csdService->certificateMaterial($certificate);
        $secrets = $this->secretService
            ?? new CsdCertificateSecretService($this->db, null, $this->certificateRoot);
        $password = $secrets->passwordForSigning($certificateId, $userId);
        $privateKey = $csdService->openPrivateKey($material['private_key_bytes'], $password);
        try {
            $certificateBase64 = base64_encode($material['certificate_der']);
            $unsigned = $this->withCertificate($preXml, $certificateBase64, (string) $certificate->certificate_number);
            $generator = new CfdiOriginalChainGenerator($this->xsltMain);
            $chain = $generator->generate($unsigned);
            $signature = '';
            if (!openssl_sign($chain['chain'], $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('OpenSSL no pudo generar el sello local.');
            }
            $seal = base64_encode($signature);
            $publicKey = openssl_pkey_get_public($this->certificatePem($material['certificate_der']));
            if (!$publicKey || openssl_verify($chain['chain'], $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
                throw new RuntimeException('La verificación criptográfica del sello local falló.');
            }
            $signedXml = $this->withSeal($unsigned, $seal);
            $recomputed = $generator->generate($signedXml);
            if (!hash_equals($chain['sha256'], $recomputed['sha256'])) {
                throw new RuntimeException('El XML cambió después de generar la cadena original.');
            }
            $this->assertNoTimbre($signedXml);
            $xsd = (new CfdiXsdValidator())->validate($signedXml);
            if ($xsd['status'] !== 'valid') {
                throw new RuntimeException('El XML sellado no supera la validación XSD completa.');
            }
            $independent=(new SignedXmlVerifier($generator,new CfdiXsdValidator()))->verify($signedXml,(string)$issuerSnapshot->rfc);
            if(!$independent->valid)throw new RuntimeException('El XML sellado no supera la verificación criptográfica independiente.');
            $storage = new FiscalArtifactStorageService($this->db, $this->artifactRoot);
            $chainArtifact = $storage->store(
                $documentId, 'original_chain', $chain['chain'], $chain['version'],
                'CFDI 4.0 cadena original', $chain['xslt_sha256'], 'valid',
                ['sha256' => $chain['sha256'], 'xslt_sha256' => $chain['xslt_sha256']], $userId
            );
            $signedArtifact = $storage->store(
                $documentId, 'signed_xml', $signedXml, self::VERSION,
                CfdiXmlBuilder::SCHEMA_VERSION, $xsd['schema_sha256'], 'valid',
                ['semantic' => $semantic, 'xsd' => $xsd, 'signature_verified' => true, 'independent_verification'=>$independent->toArray(), 'stamped' => false], $userId
            );
            $data = [
                'fiscal_document_id' => $documentId,
                'pre_xml_artifact_id' => $preXmlArtifactId,
                'certificate_id' => $certificateId,
                'original_chain_artifact_id' => $chainArtifact->id,
                'signed_xml_artifact_id' => $signedArtifact->id,
                'pre_xml_sha256' => $preXmlArtifact->sha256,
                'original_chain_sha256' => $chain['sha256'],
                'signed_xml_sha256' => hash('sha256', $signedXml),
                'signature_verified' => 1,
                'xsd_status' => 'valid',
                'created_by' => $userId,
                'created_at' => get_current_utc_time(),
            ];
            $this->db->table('fiscal_document_signatures')->insert($data);
            $data['id'] = (int) $this->db->insertID();
            $this->db->table('fiscal_documents')->where(['id' => $documentId, 'status' => 'locked'])->update([
                'status' => 'ready_to_stamp',
                'stamp_updated_at' => get_current_utc_time(),
            ]);
            $this->audit($documentId, (int) $document->invoice_id, $userId, 'locally_signed', [
                'certificate_id' => $certificateId,
                'pre_xml_sha256' => $preXmlArtifact->sha256,
                'original_chain_sha256' => $chain['sha256'],
                'signed_xml_sha256' => $data['signed_xml_sha256'],
                'signature_verified' => true,
                'xsd_status' => 'valid',
            ]);
            $secrets->auditAutomaticSigning($certificateId, $userId);
            return [
                'action' => 'created',
                'signature' => (object) $data,
                'signed_xml' => $signedXml,
                'seal_verified' => true,
                'xsd' => $xsd,
                'stamped' => false,
            ];
        } catch (Throwable $e) {
            $this->audit($documentId, (int) $document->invoice_id, $userId, 'local_signing_error', [
                'certificate_id' => $certificateId,
                'error_type' => get_class($e),
            ]);
            throw $e;
        } finally {
            unset($password, $privateKey, $material);
        }
    }

    private function withCertificate(string $xml, string $certificate, string $number): string
    {
        $dom = $this->loadXml($xml);
        $root = $dom->documentElement;
        $root->setAttribute('NoCertificado', $number);
        $root->setAttribute('Certificado', $certificate);
        $root->removeAttribute('Sello');
        return $dom->saveXML();
    }

    private function withSeal(string $xml, string $seal): string
    {
        $dom = $this->loadXml($xml);
        $dom->documentElement->setAttribute('Sello', $seal);
        return $dom->saveXML();
    }

    private function loadXml(string $xml): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new RuntimeException('El XML técnico no está bien formado.');
        }
        return $dom;
    }

    private function assertNoTimbre(string $xml): void
    {
        $dom = $this->loadXml($xml);
        $xpath = new DOMXPath($dom);
        if ($xpath->query('//*[local-name()="TimbreFiscalDigital"]')->length > 0
            || str_contains($xml, 'http://www.sat.gob.mx/TimbreFiscalDigital')) {
            throw new RuntimeException('El sellado local no admite TimbreFiscalDigital.');
        }
    }

    private function certificatePem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
    }

    private function normalizeRfc(string $rfc): string
    {
        return preg_replace('/[^A-Z0-9Ñ&]/u', '', mb_strtoupper(trim($rfc), 'UTF-8'));
    }

    private function audit(int $documentId, int $invoiceId, int $userId, string $action, array $details): void
    {
        $this->db->table('fiscal_document_audit')->insert([
            'fiscal_document_id' => $documentId,
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
            'action' => $action,
            'reason' => mb_substr(json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 500),
            'created_at' => get_current_utc_time(),
        ]);
    }
}
