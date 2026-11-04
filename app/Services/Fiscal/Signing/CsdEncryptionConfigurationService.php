<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Signing;

use App\Domain\Fiscal\Signing\CsdSecretException;
use CodeIgniter\Database\BaseConnection;
use Config\Fiscal;

/** Non-secret, read-only preflight for the installation-local CSD master key. */
final class CsdEncryptionConfigurationService
{
    public function __construct(private ?BaseConnection $db = null, private ?Fiscal $config = null) {}

    public function inspect(): array
    {
        $config = $this->config ?? config('Fiscal');
        try {
            $config->assertValidCsdEncryptionKey();
            if (!extension_loaded('openssl') || !in_array(CsdSecretVault::ALGORITHM, openssl_get_cipher_methods(), true)) {
                throw new CsdSecretException('CSD_CIPHER_UNAVAILABLE', 'AES-256-GCM no está disponible en esta instalación de PHP.');
            }
            $vault = new CsdSecretVault($config);
            $verified = 0;
            $db = $this->db;
            if ($db && $db->tableExists('fiscal_issuer_certificate_secrets')) {
                $rows = $db->table('fiscal_issuer_certificate_secrets')
                    ->select('encrypted_payload')->where('status', 'active')->get()->getResultArray();
                foreach ($rows as $row) {
                    $plaintext = $vault->decrypt((string)$row['encrypted_payload']);
                    $verified++;
                    unset($plaintext);
                }
            }
            return ['ready'=>true, 'code'=>'READY', 'active_secrets_verified'=>$verified,
                'key_configured'=>true, 'cipher'=>CsdSecretVault::ALGORITHM];
        } catch (CsdSecretException $e) {
            return ['ready'=>false, 'code'=>$e->errorCode, 'message'=>$e->getMessage(),
                'key_configured'=>trim($config->csdEncryptionKey) !== '', 'active_secrets_verified'=>0,
                'cipher'=>CsdSecretVault::ALGORITHM];
        }
    }

    public function assertReady(): void
    {
        $result = $this->inspect();
        if (!$result['ready']) {
            throw new CsdSecretException((string)$result['code'], (string)$result['message']);
        }
    }
}
