<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$pass = 0;
$fail = 0;
$assert = static function (bool $condition, string $message) use (&$pass, &$fail): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $condition ? $pass++ : $fail++;
};
$config = static function (
    bool $enabled,
    string $adapter,
    bool $allowReal = false,
    string $environment = 'local'
): Config\Fiscal {
    $value = (new ReflectionClass(Config\Fiscal::class))->newInstanceWithoutConstructor();
    $value->enabled = $enabled;
    $value->pacAdapter = $adapter;
    $value->allowRealPac = $allowReal;
    $value->environment = $environment;
    $value->runtimeMode = $adapter === 'fake' ? 'automated_test' : 'integration';
    $value->pacEncryptionKey = '';
    $value->stampingSendingStaleMinutes = 5;
    return $value;
};
$provider = (new ReflectionClass(Config\TimbradorXpress::class))->newInstanceWithoutConstructor();
$provider->environment = 'sandbox';
$provider->apiKey = str_repeat('x', 32);
$provider->baseUrl = Config\TimbradorXpress::SANDBOX_URL;
$provider->productionEnabled = false;
$provider->connectTimeout = 10;
$provider->requestTimeout = 60;
$provider->maxSignedXmlBytes = 2097152;
$provider->maxResponseBytes = 8388608;
$provider->maxPdfBytes = 10485760;
$provider->pdfTemplate = 'Principal';

try {
    (new App\Services\Fiscal\Pac\FiscalPacAdapterFactory($config(false, 'timbradorxpress'), $provider))->create();
    $blocked = false;
} catch (Throwable) {
    $blocked = true;
}
$assert($blocked, 'fiscal.enabled=false blocks the real adapter.');

try {
    (new App\Services\Fiscal\Pac\FiscalPacAdapterFactory(
        $config(true, 'timbradorxpress', false, 'sandbox'),
        $provider
    ))->create();
    $blocked = false;
} catch (Throwable) {
    $blocked = true;
}
$assert($blocked, 'fiscal.allowRealPac=false blocks the real adapter.');

$fake = new App\Services\Fiscal\Pac\FakePacAdapter('transport_not_sent');
$factory = new App\Services\Fiscal\Pac\FiscalPacAdapterFactory($config(true, 'fake'), $provider, $fake);
$assert($factory->create() === $fake, 'pacAdapter=fake selects the injected network-free adapter.');
$assert($fake->stampCalls === 0, 'Selecting fake performs no call.');
$request = new App\Domain\Fiscal\Pac\StampRequest(1, '<xml/>', hash('sha256', '<xml/>'), 'fake', 'local', str_repeat('a', 64));
$response = $factory->create()->stamp($request);
$assert(
    $response->transportError
        && ($response->metadata['request_sent'] ?? null) === false
        && $fake->stampCalls === 1,
    'Fake transport_not_sent confirms that no external request left the process.'
);

$production = clone $provider;
$production->environment = 'production';
$production->baseUrl = Config\TimbradorXpress::PRODUCTION_URL;
$production->productionEnabled = true;
try {
    (new App\Services\Fiscal\Pac\FiscalPacAdapterFactory(
        $config(true, 'timbradorxpress', true, 'production'),
        $production
    ))->create();
    $blocked = false;
} catch (Throwable) {
    $blocked = true;
}
$assert($blocked, 'Production remains blocked by the master factory.');

$service = (string) file_get_contents(APPPATH . 'Services/Fiscal/Pac/FiscalStampingService.php');
$controller = (string) file_get_contents(APPPATH . 'Controllers/Fiscal/Stamping.php');
$assert(
    !str_contains($service, 'new TimbradorXpressRestAdapter')
        && !str_contains($controller, 'new TimbradorXpressRestAdapter'),
    'Runtime service and controller do not construct the REST adapter directly.'
);
$assert(
    !preg_match('/apiKey|xmlCFDI|content_base64|private_key_password/', $controller),
    'Fiscal endpoints do not expose PAC, XML, PDF or CSD secrets.'
);

echo PHP_EOL . "{$pass} passed, {$fail} failed." . PHP_EOL;
exit($fail ? 1 : 0);
