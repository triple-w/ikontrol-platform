<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\Fiscal\Fiscal_issuer_certificates_model;
use App\Services\Fiscal\Signing\CsdCertificateSecretService;
use RuntimeException;
use Throwable;

final class CsdCertificateService
{
    private const MAX_CERTIFICATE_BYTES = 65536;
    private const MAX_PRIVATE_KEY_BYTES = 65536;
    private $db;
    private string $root;

    public function __construct(
        $db = null,
        ?string $root = null,
        private readonly ?CsdCertificateSecretService $secretService = null
    )
    {
        $this->db = $db ?: db_connect();
        $this->root = rtrim($root ?: WRITEPATH . 'fiscal/certificates', '/\\');
    }

    public function import(
        int $issuerProfileId,
        string $certificateBytes,
        string $certificateName,
        string $privateKeyBytes,
        string $privateKeyName,
        string $password,
        bool $makeDefault,
        int $userId,
        bool $authorized
    ): array {
        if (!$authorized) {
            throw new RuntimeException('No tiene permiso para administrar certificados.');
        }
        $this->assertUpload($certificateBytes, $certificateName, ['cer', 'pem'], self::MAX_CERTIFICATE_BYTES, 'certificado');
        $this->assertUpload($privateKeyBytes, $privateKeyName, ['key', 'pem'], self::MAX_PRIVATE_KEY_BYTES, 'llave privada');
        if ($password === '') {
            throw new RuntimeException('La contraseña de la llave privada es obligatoria y no se almacenará.');
        }
        $issuer = $this->db->table('fiscal_profiles')->where([
            'id' => $issuerProfileId, 'profile_type' => 'issuer',
        ])->whereIn('status', ['ready', 'incomplete'])->get(1)->getRow();
        if (!$issuer) {
            throw new RuntimeException('El perfil emisor no existe.');
        }

        $certificate = $this->parseCertificate($certificateBytes);
        $privateKeyPem = $this->privateKeyPem($privateKeyBytes);
        $privateKey = @openssl_pkey_get_private($privateKeyPem, $password);
        if (!$privateKey) {
            $this->clearOpenSslErrors();
            throw new RuntimeException('No fue posible abrir la llave privada. Verifique el archivo y su contraseña.');
        }
        if (@openssl_pkey_get_private($privateKeyPem, '') !== false) {
            throw new RuntimeException('La llave privada no está protegida; cargue el archivo .key cifrado del CSD.');
        }
        $certificatePublic = openssl_pkey_get_public($certificate['pem']);
        if (!$certificatePublic || !$this->keysMatch($certificatePublic, $privateKey)) {
            throw new RuntimeException('El certificado y la llave privada no forman una pareja criptográfica.');
        }
        $certificateRfc = $this->extractRfc($certificate['parsed']);
        $issuerRfc = $this->normalizeRfc((string) $issuer->rfc);
        if ($certificateRfc === '' || $certificateRfc !== $issuerRfc) {
            throw new RuntimeException("El certificado pertenece al RFC {$certificateRfc}, pero el perfil emisor utiliza el RFC {$issuerRfc}.");
        }
        $now = time();
        $status = $now < $certificate['parsed']['validFrom_time_t']
            ? 'pending_validation'
            : ($now > $certificate['parsed']['validTo_time_t'] ? 'expired' : 'valid');

        $issuerDirectory = $this->root . DIRECTORY_SEPARATOR . $issuerProfileId;
        $this->ensurePrivateDirectory($issuerDirectory);
        $certName = bin2hex(random_bytes(24)) . '.cer';
        $keyName = bin2hex(random_bytes(24)) . '.key';
        $certRelative = 'fiscal/certificates/' . $issuerProfileId . '/' . $certName;
        $keyRelative = 'fiscal/certificates/' . $issuerProfileId . '/' . $keyName;
        $certTarget = $issuerDirectory . DIRECTORY_SEPARATOR . $certName;
        $keyTarget = $issuerDirectory . DIRECTORY_SEPARATOR . $keyName;
        $this->writeAtomic($certTarget, $certificate['der']);
        try {
            $this->writeAtomic($keyTarget, $privateKeyBytes);
            $this->db->transBegin();
            $existing = $this->db->table('fiscal_issuer_certificates')->where([
                'issuer_profile_id' => $issuerProfileId,
                'certificate_sha256' => hash('sha256', $certificate['der']),
            ])->get(1)->getRow();
            if ($existing) {
                throw new RuntimeException('Este certificado ya fue registrado para el emisor.');
            }
            if ($makeDefault && $status === 'valid') {
                $this->db->table('fiscal_issuer_certificates')->where([
                    'issuer_profile_id' => $issuerProfileId, 'deleted' => 0,
                ])->update(['is_default' => 0, 'updated_at' => get_current_utc_time()]);
            }
            $data = [
                'issuer_profile_id' => $issuerProfileId,
                'certificate_number' => $certificate['number'],
                'certificate_serial_hex' => $certificate['parsed']['serialNumberHex'] ?? null,
                'certificate_subject' => $this->subjectText($certificate['parsed']['subject'] ?? []),
                'certificate_rfc' => $certificateRfc,
                'valid_from' => gmdate('Y-m-d H:i:s', $certificate['parsed']['validFrom_time_t']),
                'valid_to' => gmdate('Y-m-d H:i:s', $certificate['parsed']['validTo_time_t']),
                'certificate_sha256' => hash('sha256', $certificate['der']),
                'public_certificate_path' => $certRelative,
                'encrypted_private_key_path' => $keyRelative,
                'private_key_sha256' => hash('sha256', $privateKeyBytes),
                'encryption_key_version' => 'password-v1',
                'status' => $status,
                'is_default' => $makeDefault && $status === 'valid' ? 1 : 0,
                'created_by' => $userId,
                'created_at' => get_current_utc_time(),
                'updated_at' => get_current_utc_time(),
                'deleted' => 0,
            ];
            $id = (new Fiscal_issuer_certificates_model())->ci_save($data);
            if (!$id || !$this->db->transStatus()) {
                throw new RuntimeException('No fue posible registrar el certificado.');
            }
            ($this->secretService ?? new CsdCertificateSecretService($this->db, null, $this->root))
                ->configure((int) $id, $password, $userId, true, false);
            if (!$this->db->transStatus()) {
                throw new RuntimeException('No fue posible proteger la contraseña del certificado.');
            }
            $this->db->transCommit();
            $data['id'] = (int) $id;
            return ['certificate' => (object) $data, 'status' => $status, 'validity_checked_locally' => true];
        } catch (Throwable $e) {
            $this->db->transRollback();
            @unlink($certTarget);
            @unlink($keyTarget);
            throw $e;
        } finally {
            unset($password, $privateKeyPem, $privateKey);
        }
    }

    public function certificateMaterial(object $record): array
    {
        $certificateDer = $this->readPrivate($record->public_certificate_path, (int) $record->issuer_profile_id, 'cer');
        $privateKeyBytes = $this->readPrivate($record->encrypted_private_key_path, (int) $record->issuer_profile_id, 'key');
        if (!hash_equals((string) $record->certificate_sha256, hash('sha256', $certificateDer))
            || !hash_equals((string) $record->private_key_sha256, hash('sha256', $privateKeyBytes))) {
            throw new RuntimeException('Los archivos del certificado no superan la verificación de integridad.');
        }
        return ['certificate_der' => $certificateDer, 'private_key_bytes' => $privateKeyBytes];
    }

    public function openPrivateKey(string $privateKeyBytes, string $password)
    {
        if ($password === '') {
            throw new RuntimeException('La contraseña de la llave privada es obligatoria.');
        }
        $key = @openssl_pkey_get_private($this->privateKeyPem($privateKeyBytes), $password);
        if (!$key) {
            $this->clearOpenSslErrors();
            throw new RuntimeException('No fue posible abrir la llave privada con la contraseña proporcionada.');
        }
        return $key;
    }

    public function exportPrivateKeyPem(mixed $key): string
    {
        $pem='';
        $options=[];
        $config=PHP_OS_FAMILY==='Windows'?'C:\\xampp\\php\\extras\\openssl\\openssl.cnf':'';
        if($config!==''&&is_file($config))$options['config']=$config;
        if(!@openssl_pkey_export($key,$pem,null,$options)){
            $this->clearOpenSslErrors();
            throw new RuntimeException('No fue posible exportar temporalmente la llave privada.');
        }
        if(!str_contains($pem,'BEGIN PRIVATE KEY')&&!str_contains($pem,'BEGIN RSA PRIVATE KEY')){
            throw new RuntimeException('La llave privada exportada no tiene formato PEM.');
        }
        return $pem;
    }

    private function parseCertificate(string $bytes): array
    {
        $der = $bytes;
        if (str_contains($bytes, 'BEGIN CERTIFICATE')) {
            $body = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $bytes);
            $decoded = base64_decode((string) $body, true);
            if ($decoded === false) {
                throw new RuntimeException('El certificado PEM no contiene Base64 válido.');
            }
            $der = $decoded;
        }
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
        $x509 = @openssl_x509_read($pem);
        $parsed = $x509 ? openssl_x509_parse($x509, false) : false;
        if (!$parsed || !isset($parsed['validFrom_time_t'], $parsed['validTo_time_t'])) {
            $this->clearOpenSslErrors();
            throw new RuntimeException('El archivo no contiene un certificado X.509 válido.');
        }
        $hex = strtoupper((string) ($parsed['serialNumberHex'] ?? ''));
        $ascii = $hex !== '' && strlen($hex) % 2 === 0 ? @hex2bin($hex) : false;
        $number = is_string($ascii) && preg_match('/^\d{20}$/', $ascii)
            ? $ascii
            : (string) ($parsed['serialNumber'] ?? '');
        if (!preg_match('/^\d{20}$/', $number)) {
            throw new RuntimeException('El certificado no contiene un NoCertificado SAT de 20 dígitos.');
        }
        return ['der' => $der, 'pem' => $pem, 'parsed' => $parsed, 'number' => $number];
    }

    private function privateKeyPem(string $bytes): string
    {
        if (str_contains($bytes, 'BEGIN')) {
            return $bytes;
        }
        return "-----BEGIN ENCRYPTED PRIVATE KEY-----\n"
            . chunk_split(base64_encode($bytes), 64, "\n")
            . "-----END ENCRYPTED PRIVATE KEY-----\n";
    }

    private function keysMatch($publicKey, $privateKey): bool
    {
        $public = openssl_pkey_get_details($publicKey);
        $private = openssl_pkey_get_details($privateKey);
        return is_array($public) && is_array($private)
            && isset($public['key'], $private['key'])
            && hash_equals(hash('sha256', $public['key']), hash('sha256', $private['key']));
    }

    private function extractRfc(array $parsed): string
    {
        $values = [];
        $walk = static function ($value) use (&$walk, &$values): void {
            if (is_array($value)) {
                foreach ($value as $entry) {
                    $walk($entry);
                }
            } elseif (is_scalar($value)) {
                $values[] = (string) $value;
            }
        };
        $walk($parsed['subject'] ?? []);
        $walk($parsed['extensions']['subjectAltName'] ?? '');
        foreach ($values as $value) {
            if (preg_match('/(?:^|[^A-Z0-9Ñ&])([A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3})(?:$|[^A-Z0-9])/u', strtoupper($value), $match)) {
                return $this->normalizeRfc($match[1]);
            }
        }
        return '';
    }

    private function normalizeRfc(string $rfc): string
    {
        return preg_replace('/[^A-Z0-9Ñ&]/u', '', mb_strtoupper(trim($rfc), 'UTF-8'));
    }

    private function subjectText(array $subject): string
    {
        $parts = [];
        foreach ($subject as $key => $value) {
            $parts[] = $key . '=' . (is_array($value) ? implode(',', $value) : $value);
        }
        return mb_substr(implode(', ', $parts), 0, 500);
    }

    private function assertUpload(string $bytes, string $name, array $extensions, int $max, string $label): void
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, $extensions, true)) {
            throw new RuntimeException("La extensión del {$label} no está permitida.");
        }
        $size = strlen($bytes);
        if ($size === 0 || $size > $max) {
            throw new RuntimeException("El tamaño del {$label} no es válido.");
        }
    }

    private function ensurePrivateDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible crear el almacenamiento privado de certificados.');
        }
        @chmod($directory, 0700);
    }

    private function writeAtomic(string $target, string $contents): void
    {
        if (file_exists($target)) {
            throw new RuntimeException('El archivo privado ya existe.');
        }
        $temporary = $target . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('No fue posible escribir el archivo privado de forma atómica.');
        }
        @chmod($target, 0600);
    }

    private function readPrivate(string $relative, int $issuerId, string $extension): string
    {
        $pattern = '#^fiscal/certificates/' . $issuerId . '/([a-f0-9]{48}\.' . $extension . ')$#';
        if (!preg_match($pattern, $relative, $match)) {
            throw new RuntimeException('Ruta privada de certificado inválida.');
        }
        $directory = realpath($this->root . DIRECTORY_SEPARATOR . $issuerId);
        $path = realpath($this->root . DIRECTORY_SEPARATOR . $issuerId . DIRECTORY_SEPARATOR . $match[1]);
        if (!$directory || !$path || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Acceso al certificado denegado.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('No fue posible leer el certificado privado.');
        }
        return $contents;
    }

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
        }
    }
}
