<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $message) use (&$pass, &$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$config = static function (string $csd, string $pac = ''): Config\Fiscal {
    $value = (new ReflectionClass(Config\Fiscal::class))->newInstanceWithoutConstructor();
    $value->csdEncryptionKey = $csd;
    $value->pacEncryptionKey = $pac;
    $value->csdEncryptionVersion = 'csd-secret-v1';
    return $value;
};
$password = bin2hex(random_bytes(24));

$vault = new App\Services\Fiscal\Signing\CsdSecretVault($config(str_repeat('a', 64), str_repeat('b', 64)));
$first = $vault->encrypt($password);
$second = $vault->encrypt($password);
$assert($vault->decrypt($first->toJson()) === $password, 'AES-256-GCM encrypt/decrypt returns the original password.');
$assert($first->ciphertext !== $second->ciphertext || $first->nonce !== $second->nonce, 'The same plaintext produces a different payload through a random nonce.');
$assert($first->version === 1 && $first->algorithm === 'aes-256-gcm', 'Payload format and algorithm are explicitly versioned.');

$mutations = [
    'tag' => array_replace($first->toArray(), ['tag' => base64_encode(str_repeat('x', 16))]),
    'nonce' => array_replace($first->toArray(), ['nonce' => base64_encode(str_repeat('x', 12))]),
    'ciphertext' => array_replace($first->toArray(), ['ciphertext' => base64_encode('tampered')]),
    'incomplete' => ['version' => 1, 'algorithm' => 'aes-256-gcm'],
    'version' => array_replace($first->toArray(), ['version' => 99]),
];
foreach ($mutations as $label => $payload) {
    try {
        $vault->decrypt($payload);
        $blocked = false;
    } catch (Throwable $e) {
        $blocked = !str_contains($e->getMessage(), $password);
    }
    $assert($blocked, "Tampered or unsupported {$label} payload is rejected without leaking plaintext.");
}
try {
    (new App\Services\Fiscal\Signing\CsdSecretVault($config(str_repeat('c', 64))))->decrypt($first->toJson());
    $wrongKey = false;
} catch (Throwable $e) {
    $wrongKey = !str_contains($e->getMessage(), $password);
}
$assert($wrongKey, 'A different master key cannot decrypt the payload.');

foreach ([
    ['', '', 'A missing key is rejected.'],
    ['abcd', '', 'A short key is rejected.'],
    [str_repeat('g', 64), '', 'A non-hexadecimal key is rejected.'],
    [str_repeat('d', 64), str_repeat('d', 64), 'Reusing the PAC key is rejected.'],
] as [$csd, $pac, $message]) {
    try {
        new App\Services\Fiscal\Signing\CsdSecretVault($config($csd, $pac));
        $blocked = false;
    } catch (Throwable $e) {
        $blocked = !str_contains($e->getMessage(), $password);
    }
    $assert($blocked, $message);
}
$serialized = $first->toJson();
$assert(!str_contains($serialized, $password), 'Serialized payload contains no plaintext password.');

echo PHP_EOL . "{$pass} passed, {$fail} failed." . PHP_EOL;
exit($fail ? 1 : 0);
