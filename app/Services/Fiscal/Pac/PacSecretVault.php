<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pac;

use Config\Encryption;
use Config\Fiscal;
use Config\Services;
use RuntimeException;

final class PacSecretVault
{
    private $encrypter;

    public function __construct(?string $masterKey = null, ?Fiscal $fiscalConfig = null)
    {
        $config = $fiscalConfig ?? config('Fiscal');
        $key = $masterKey ?? $config->pacEncryptionKey;
        if (strlen($key) < 32) {
            throw new RuntimeException('Configure fiscal.pacEncryptionKey fuera del repositorio con al menos 32 caracteres.');
        }
        $config = new Encryption();
        $config->key = hash('sha256', $key, true);
        $this->encrypter = Services::encrypter($config, false);
    }

    public function encrypt(string $secret): string
    {
        if (trim($secret) === '') throw new RuntimeException('El contenido sensible a cifrar está vacío.');
        return base64_encode($this->encrypter->encrypt($secret));
    }

    public function decrypt(string $ciphertext): string
    {
        $raw = base64_decode($ciphertext, true);
        if ($raw === false) throw new RuntimeException('La evidencia fiscal cifrada no es válida.');
        return $this->encrypter->decrypt($raw);
    }
}
