<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Signing;

use App\Domain\Fiscal\Signing\CsdSecretException;
use App\Services\Fiscal\CsdCertificateService;
use Throwable;

final class CsdCertificateSecretService
{
    private $db;

    public function __construct(
        $db = null,
        private readonly ?CsdSecretVault $vault = null,
        private readonly ?string $certificateRoot = null
    ) {
        $this->db = $db ?: db_connect();
    }

    public function configure(
        int $certificateId,
        string $password,
        int $userId,
        bool $authorized,
        bool $manageTransaction = true
    ): object {
        if (!$authorized) {
            throw new CsdSecretException(
                'CSD_SECRET_ACCESS_DENIED',
                'No tiene permiso para configurar secretos CSD.'
            );
        }
        $certificate = $this->certificate($certificateId);
        $vault = $this->vault ?? new CsdSecretVault();
        $csd = new CsdCertificateService($this->db, $this->certificateRoot, $this);
        $material = null;
        $key = null;
        $payload = null;
        if ($manageTransaction) {
            $this->db->transBegin();
        }
        try {
            $material = $csd->certificateMaterial($certificate);
            try {
                $key = $csd->openPrivateKey($material['private_key_bytes'], $password);
            } catch (Throwable) {
                throw new CsdSecretException(
                    'CSD_PRIVATE_KEY_PASSWORD_INVALID',
                    'La contraseña no permite abrir la llave privada del CSD.'
                );
            }
            $payload = $vault->encrypt($password);
            $now = get_current_utc_time();
            $existing = $this->db->table('fiscal_issuer_certificate_secrets')->where([
                'fiscal_issuer_certificate_id' => $certificateId,
                'secret_type' => 'private_key_password',
            ])->get(1)->getRow();
            $data = [
                'encrypted_payload' => $payload->toJson(),
                'encryption_version' => CsdSecretVault::FORMAT_VERSION,
                'status' => 'active',
                'validated_at' => $now,
                'updated_at' => $now,
            ];
            if ($existing) {
                $data['rotated_at'] = $now;
                $this->db->table('fiscal_issuer_certificate_secrets')
                    ->where('id', $existing->id)->update($data);
                $action = 'csd_secret_updated';
                $id = (int) $existing->id;
            } else {
                $data += [
                    'fiscal_issuer_certificate_id' => $certificateId,
                    'secret_type' => 'private_key_password',
                    'created_at' => $now,
                    'rotated_at' => null,
                ];
                $this->db->table('fiscal_issuer_certificate_secrets')->insert($data);
                $id = (int) $this->db->insertID();
                $action = 'csd_secret_configured';
            }
            if (!$id || !$this->db->transStatus()) {
                throw new CsdSecretException(
                    'CSD_SECRET_PERSIST_FAILED',
                    'No fue posible almacenar el secreto CSD.'
                );
            }
            $this->audit($certificateId, $userId, $action, 'success');
            if ($manageTransaction) {
                $this->db->transCommit();
            }
            return $this->db->table('fiscal_issuer_certificate_secrets')
                ->where('id', $id)->get(1)->getRow();
        } catch (Throwable $e) {
            if ($manageTransaction) {
                $this->db->transRollback();
                $this->audit(
                    $certificateId,
                    $userId,
                    'csd_secret_validation_failed',
                    'failed',
                    $e instanceof CsdSecretException ? $e->errorCode : 'CSD_SECRET_CONFIGURATION_FAILED'
                );
            }
            throw $e;
        } finally {
            unset($password, $material, $key, $payload);
        }
    }

    public function passwordForSigning(int $certificateId, int $userId): string
    {
        $certificate = $this->certificate($certificateId);
        $secret = $this->db->table('fiscal_issuer_certificate_secrets')->where([
            'fiscal_issuer_certificate_id' => $certificateId,
            'secret_type' => 'private_key_password',
            'status' => 'active',
        ])->get(1)->getRow();
        if (!$secret) {
            throw new CsdSecretException(
                'CSD_SECRET_NOT_CONFIGURED',
                'El certificado fiscal necesita configurar su contraseña.'
            );
        }
        try {
            $password = ($this->vault ?? new CsdSecretVault())
                ->decrypt((string) $secret->encrypted_payload);
        } catch (CsdSecretException $e) {
            $this->audit($certificateId, $userId, 'csd_secret_decryption_failed', 'failed', $e->errorCode);
            throw $e;
        }
        try {
            $csd = new CsdCertificateService($this->db, $this->certificateRoot, $this);
            $material = $csd->certificateMaterial($certificate);
        } catch (Throwable) {
            unset($password);
            $this->audit(
                $certificateId,
                $userId,
                'csd_secret_decryption_failed',
                'failed',
                'CSD_PRIVATE_KEY_FILE_MISSING'
            );
            throw new CsdSecretException(
                'CSD_PRIVATE_KEY_FILE_MISSING',
                'Los archivos privados del CSD no están disponibles o no superan integridad.'
            );
        }
        try {
            $key = $csd->openPrivateKey($material['private_key_bytes'], $password);
            unset($key, $material);
            return $password;
        } catch (Throwable) {
            unset($password, $material, $key);
            $this->audit(
                $certificateId,
                $userId,
                'csd_secret_decryption_failed',
                'failed',
                'CSD_PRIVATE_KEY_PASSWORD_INVALID'
            );
            throw new CsdSecretException(
                'CSD_PRIVATE_KEY_PASSWORD_INVALID',
                'El secreto almacenado no permite abrir la llave privada.'
            );
        }
    }

    public function auditAutomaticSigning(int $certificateId, int $userId): void
    {
        $this->audit($certificateId, $userId, 'csd_automatic_signing_used', 'success');
    }

    private function certificate(int $id): object
    {
        $certificate = $this->db->table('fiscal_issuer_certificates')->where([
            'id' => $id,
            'deleted' => 0,
        ])->get(1)->getRow();
        if (!$certificate) {
            throw new CsdSecretException(
                'CSD_CERTIFICATE_NOT_READY',
                'El certificado fiscal no está disponible.'
            );
        }
        return $certificate;
    }

    private function audit(
        int $certificateId,
        int $userId,
        string $action,
        string $result,
        ?string $errorCode = null
    ): void {
        if (!$this->db->tableExists('fiscal_issuer_certificate_secret_audit')) {
            return;
        }
        $this->db->table('fiscal_issuer_certificate_secret_audit')->insert([
            'fiscal_issuer_certificate_id' => $certificateId,
            'user_id' => $userId ?: null,
            'action' => $action,
            'result' => $result,
            'error_code' => $errorCode,
            'created_at' => get_current_utc_time(),
        ]);
    }
}
