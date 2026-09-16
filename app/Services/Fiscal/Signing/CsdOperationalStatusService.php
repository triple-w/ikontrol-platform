<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Signing;

use App\Domain\Fiscal\Signing\CsdSecretException;
use App\Services\Fiscal\CsdCertificateService;
use Throwable;

final class CsdOperationalStatusService
{
    private $db;

    public function __construct(
        $db = null,
        private readonly ?CsdCertificateSecretService $secrets = null,
        private readonly ?string $certificateRoot = null
    ) {
        $this->db = $db ?: db_connect();
    }

    public function forCertificate(object $certificate): array
    {
        if ((string) $certificate->status !== 'valid') {
            return $this->result(false, 'certificate_not_ready', 'Requiere reconfiguración.');
        }
        $now = gmdate('Y-m-d H:i:s');
        if ((string) $certificate->valid_from > $now || (string) $certificate->valid_to < $now) {
            return $this->result(false, 'certificate_expired', 'Certificado vencido.');
        }
        try {
            (new CsdCertificateService($this->db, $this->certificateRoot))
                ->certificateMaterial($certificate);
        } catch (Throwable) {
            return $this->result(false, 'private_files_unavailable', 'Archivos privados no disponibles.');
        }
        $secret = $this->db->table('fiscal_issuer_certificate_secrets')->where([
            'fiscal_issuer_certificate_id' => $certificate->id,
            'secret_type' => 'private_key_password',
            'status' => 'active',
        ])->get(1)->getRow();
        if (!$secret) {
            return $this->result(false, 'password_pending', 'Contraseña pendiente de configuración.');
        }
        try {
            ($this->secrets ?? new CsdCertificateSecretService($this->db, null, $this->certificateRoot))
                ->passwordForSigning((int) $certificate->id, 0);
            return $this->result(true, 'ready', 'Configurado y listo.');
        } catch (CsdSecretException $e) {
            return match ($e->errorCode) {
                'CSD_ENCRYPTION_KEY_MISSING', 'CSD_ENCRYPTION_KEY_INVALID',
                'CSD_ENCRYPTION_KEYS_REUSED' => $this->result(
                    false,
                    'encryption_configuration_missing',
                    'Configuración de cifrado ausente.'
                ),
                'CSD_SECRET_DECRYPT_FAILED', 'CSD_SECRET_CORRUPTED' => $this->result(
                    false,
                    'password_invalid',
                    'Contraseña inválida.'
                ),
                default => $this->result(false, 'requires_reconfiguration', 'Requiere reconfiguración.'),
            };
        }
    }

    private function result(bool $ready, string $code, string $label): array
    {
        return ['ready' => $ready, 'code' => $code, 'label' => $label];
    }
}
