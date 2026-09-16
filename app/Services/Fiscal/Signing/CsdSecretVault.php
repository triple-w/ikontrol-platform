<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Signing;

use App\Domain\Fiscal\Signing\CsdSecretException;
use App\Domain\Fiscal\Signing\CsdSecretPayload;
use Config\Fiscal;
use JsonException;

final class CsdSecretVault
{
    public const VERSION = 1;
    public const FORMAT_VERSION = 'csd-secret-v1';
    public const ALGORITHM = 'aes-256-gcm';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    private readonly string $binaryKey;

    public function __construct(?Fiscal $config = null)
    {
        $config ??= config('Fiscal');
        $config->assertValidCsdEncryptionKey();
        $decoded = hex2bin($config->csdEncryptionKey);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new CsdSecretException(
                'CSD_ENCRYPTION_KEY_INVALID',
                'La llave de cifrado CSD no tiene un formato válido.'
            );
        }
        $this->binaryKey = $decoded;
    }

    public function encrypt(string $plaintext): CsdSecretPayload
    {
        if ($plaintext === '') {
            throw new CsdSecretException(
                'CSD_PRIVATE_KEY_PASSWORD_INVALID',
                'La contraseña del CSD es obligatoria.'
            );
        }
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::ALGORITHM,
            $this->binaryKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_BYTES
        );
        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new CsdSecretException(
                'CSD_SECRET_ENCRYPT_FAILED',
                'No fue posible proteger la contraseña del CSD.'
            );
        }

        return new CsdSecretPayload(
            self::VERSION,
            self::ALGORITHM,
            base64_encode($nonce),
            base64_encode($tag),
            base64_encode($ciphertext)
        );
    }

    public function decrypt(string|array $payload): string
    {
        try {
            $data = is_array($payload)
                ? $payload
                : json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->corrupted();
        }
        if (!is_array($data)
            || ($data['version'] ?? null) !== self::VERSION
            || ($data['algorithm'] ?? null) !== self::ALGORITHM) {
            throw new CsdSecretException(
                isset($data['version']) && $data['version'] !== self::VERSION
                    ? 'CSD_SECRET_VERSION_UNSUPPORTED'
                    : 'CSD_SECRET_CORRUPTED',
                'El secreto CSD no tiene un formato compatible.'
            );
        }
        foreach (['nonce', 'tag', 'ciphertext'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
                throw $this->corrupted();
            }
        }
        $nonce = base64_decode($data['nonce'], true);
        $tag = base64_decode($data['tag'], true);
        $ciphertext = base64_decode($data['ciphertext'], true);
        if ($nonce === false || strlen($nonce) !== self::NONCE_BYTES
            || $tag === false || strlen($tag) !== self::TAG_BYTES
            || $ciphertext === false || $ciphertext === '') {
            throw $this->corrupted();
        }
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::ALGORITHM,
            $this->binaryKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );
        if ($plaintext === false) {
            throw new CsdSecretException(
                'CSD_SECRET_DECRYPT_FAILED',
                'No fue posible autenticar o descifrar el secreto CSD.'
            );
        }
        return $plaintext;
    }

    private function corrupted(): CsdSecretException
    {
        return new CsdSecretException(
            'CSD_SECRET_CORRUPTED',
            'El secreto CSD está incompleto o fue alterado.'
        );
    }
}
