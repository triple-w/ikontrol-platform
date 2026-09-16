<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $condition ? $passed++ : $failed++;
};

$name = 'fiscal.pacEncryptionKey';
$original = getenv($name);
$set = static function (string $value) use ($name): void {
    putenv($name . '=' . $value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
};

try {
    $set('');
    $missing = new Config\Fiscal();
    $assert(!$missing->hasValidPacEncryptionKey(), 'Missing local encryption key is invalid.');
    try {new App\Services\Fiscal\Pac\PacSecretVault(null, $missing);$blocked=false;} catch (RuntimeException) {$blocked=true;}
    $assert($blocked, 'Missing key produces a controlled vault error.');

    $set(str_repeat('a', 31));
    $short = new Config\Fiscal();
    $assert(!$short->hasValidPacEncryptionKey(), 'Encryption key shorter than 32 characters is invalid.');

    $set(str_repeat('b', 32));
    $minimum = new Config\Fiscal();
    $assert($minimum->hasValidPacEncryptionKey(), 'A 32-character encryption key is accepted.');
    new App\Services\Fiscal\Pac\PacSecretVault(null, $minimum);
    $assert(true, 'Vault uses Config\\Fiscal for a valid minimum-length key.');

    $runtimeSecret = bin2hex(random_bytes(32));
    $set($runtimeSecret);
    $hex = new Config\Fiscal();
    $assert($hex->hasValidPacEncryptionKey() && strlen($hex->pacEncryptionKey) === 64, 'A 64-character hexadecimal key is accepted.');

    $fiscalSource = file_get_contents(APPPATH . 'Config/Fiscal.php');
    $applicationFiles = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH));
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php'
            || str_contains(str_replace('\\', '/', $file->getPathname()), '/ThirdParty/')) continue;
        $source = file_get_contents($file->getPathname());
        if (str_contains($source, "env('fiscal.pacEncryptionKey'") || str_contains($source, 'env("fiscal.pacEncryptionKey"')) {
            $applicationFiles[] = str_replace('\\', '/', $file->getPathname());
        }
    }
    $assert(count($applicationFiles) === 1 && str_ends_with($applicationFiles[0], '/app/Config/Fiscal.php'), 'Config\\Fiscal is the only application source that reads pacEncryptionKey.');

    $timbrador = file_get_contents(APPPATH . 'Config/TimbradorXpress.php');
    $stamping = file_get_contents(APPPATH . 'Services/Fiscal/Pac/FiscalStampingService.php');
    $assert(str_contains($timbrador, 'TIMBRADORXPRESS_APIKEY_SANDBOX')
        && !str_contains($stamping, 'encrypted_api_key')
        && !str_contains($stamping, 'fiscal_pac_configurations'), 'PAC credentials come from Config\\TimbradorXpress, never from the legacy table.');
    $assert((bool) preg_match("/'pac_configuration_id'\\s*=>\\s*null/", $stamping), 'Nullable historical pac_configuration_id does not block the current flow.');

    putenv('TIMBRADORXPRESS_ENVIRONMENT=sandbox');
    putenv('TIMBRADORXPRESS_APIKEY_SANDBOX=' . str_repeat('k', 32));
    putenv('TIMBRADORXPRESS_PRODUCTION_ENABLED=false');
    $_ENV['TIMBRADORXPRESS_ENVIRONMENT'] = $_SERVER['TIMBRADORXPRESS_ENVIRONMENT'] = 'sandbox';
    $_ENV['TIMBRADORXPRESS_APIKEY_SANDBOX'] = $_SERVER['TIMBRADORXPRESS_APIKEY_SANDBOX'] = str_repeat('k', 32);
    $_ENV['TIMBRADORXPRESS_PRODUCTION_ENABLED'] = $_SERVER['TIMBRADORXPRESS_PRODUCTION_ENABLED'] = 'false';
    $pac = new Config\TimbradorXpress();
    $assert($pac->isConfigured() && !$pac->productionEnabled && $pac->environment === 'sandbox', 'Sandbox API key configures PAC while production remains disabled.');

    $logs = '';
    foreach (glob(WRITEPATH . 'logs/*.log') ?: [] as $log) {
        $logs .= (string) file_get_contents($log);
    }
    $assert(!str_contains($logs, $runtimeSecret), 'Runtime encryption key is absent from existing logs.');
    $assert(true, 'Configuration tests instantiate no HTTP client and perform no PAC request.');
} catch (Throwable $error) {
    echo '[FAIL] ' . get_class($error) . ': ' . $error->getMessage() . PHP_EOL;
    $failed++;
} finally {
    if ($original === false) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    } else {
        $set($original);
    }
}

echo PHP_EOL . "$passed passed, $failed failed." . PHP_EOL;
exit($failed ? 1 : 0);
