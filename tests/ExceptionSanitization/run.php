<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use CodeIgniter\Debug\BaseExceptionHandler;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Exceptions;

final class InspectableExceptionHandler extends BaseExceptionHandler
{
    public function handle(Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void {}

    public function inspect(Throwable $exception): array
    {
        return $this->collectVars($exception, 500);
    }
}

function throwWithSensitiveArguments(array $options, object $config): void
{
    throw new RuntimeException('Controlled exception without secret content.');
}

$sentinels = [
    'password' => 'SENTINEL_PASSWORD_VALUE',
    'api_key' => 'SENTINEL_API_KEY_VALUE',
    'token' => 'SENTINEL_TOKEN_VALUE',
    'privateKey' => 'SENTINEL_PRIVATE_KEY_VALUE',
    'username' => 'SENTINEL_USERNAME_VALUE',
];
$object = (object) ['database' => ['password' => $sentinels['password'], 'username' => $sentinels['username']]];

try {
    throwWithSensitiveArguments($sentinels, $object);
    throw new RuntimeException('Controlled exception was not thrown.');
} catch (RuntimeException $exception) {
    $output = json_encode((new InspectableExceptionHandler(config(Exceptions::class)))->inspect($exception), JSON_THROW_ON_ERROR);
}

$passed = 0;
foreach ($sentinels as $value) {
    if (str_contains($output, $value)) {
        throw new RuntimeException('Sensitive sentinel remained in exception output.');
    }
    $passed++;
}
if (! str_contains($output, '******************')) {
    throw new RuntimeException('Exception output did not contain masking evidence.');
}
$passed++;
$productionBoot = file_get_contents(APPPATH . 'Config/Boot/production.php');
if (! str_contains((string) $productionBoot, "ini_set('display_errors', '0')")) {
    throw new RuntimeException('Production boot does not disable detailed error display.');
}
$passed++;

echo "passed={$passed}\n";
