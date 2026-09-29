<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/tests/bootstrap.php';

use App\Services\Fiscal\FiscalRuntimeContext;
use App\Services\Fiscal\Pac\FiscalPacAdapterFactory;
use App\Services\Fiscal\Pac\TimbradorXpressRestAdapter;
use Config\Fiscal;
use Config\TimbradorXpress;

$passed = $failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $condition ? $passed++ : $failed++;
};

$fiscal = static function (string $runtime, string $environment, bool $allowReal = true): Fiscal {
    $config = (new ReflectionClass(Fiscal::class))->newInstanceWithoutConstructor();
    $config->runtimeMode = $runtime;
    $config->enabled = true;
    $config->environment = $environment;
    $config->pacAdapter = 'timbradorxpress';
    $config->allowRealPac = $allowReal;
    return $config;
};
$pac = static function (string $environment, string $key = 'test-key', bool $productionEnabled = false): TimbradorXpress {
    $config = (new ReflectionClass(TimbradorXpress::class))->newInstanceWithoutConstructor();
    $config->environment = $environment;
    $config->baseUrl = $environment === 'production' ? TimbradorXpress::PRODUCTION_URL : TimbradorXpress::SANDBOX_URL;
    $config->apiKey = $key;
    $config->productionEnabled = $productionEnabled;
    $config->connectTimeout = 2;
    $config->requestTimeout = 5;
    return $config;
};

$appProductionSandbox = FiscalRuntimeContext::from($fiscal('production', 'development'), $pac('sandbox'));
$assert($appProductionSandbox['operational'] && $appProductionSandbox['fiscal_mode'] === 'sandbox', 'App production puede operar fiscal sandbox sin usar el runtime como selector.');
$assert($appProductionSandbox['pac_endpoint'] === TimbradorXpress::SANDBOX_URL, 'Sandbox resuelve únicamente el endpoint dev permitido.');

$testingSandbox = FiscalRuntimeContext::from($fiscal('automated_test', 'development'), $pac('sandbox'));
$assert($testingSandbox['operational'] && $testingSandbox['transport_environment'] === 'sandbox', 'App testing conserva fiscal sandbox con el mismo contrato.');

$productionFiscal = $fiscal('production', 'production');
$productionPac = $pac('production', 'production-key', true);
$production = FiscalRuntimeContext::from($productionFiscal, $productionPac);
$assert($production['operational'] && $production['pac_endpoint'] === TimbradorXpress::PRODUCTION_URL, 'Fiscal production habilitado resuelve el endpoint productivo permitido.');
$assert((new FiscalPacAdapterFactory($productionFiscal, $productionPac))->create() instanceof TimbradorXpressRestAdapter, 'Factory usa el mismo adaptador y pipeline en production.');
$assert((new FiscalPacAdapterFactory($fiscal('production', 'development'), $pac('sandbox')))->create() instanceof TimbradorXpressRestAdapter, 'Factory usa el mismo adaptador y pipeline en sandbox.');

$missing = FiscalRuntimeContext::from($productionFiscal, $pac('production', '', true));
$assert(!$missing['operational'] && !$missing['pac_configured'], 'Production sin credencial queda bloqueado en preflight canónico.');
try {
    (new FiscalPacAdapterFactory($productionFiscal, $pac('production', '', true)))->create();
    $blocked = false;
} catch (Throwable) {
    $blocked = true;
}
$assert($blocked, 'Factory rechaza production incompleto antes de construir transporte.');

$disabledGuard = FiscalRuntimeContext::from($productionFiscal, $pac('production', 'production-key', false));
$assert(!$disabledGuard['operational'] && !$disabledGuard['production_guard_satisfied'], 'Production requiere habilitación explícita del servidor.');

$http = new class {
    public array $calls = [];
    public function post(string $url, array $options): object
    {
        $this->calls[] = [$url, $options];
        return new class {
            public function getBody(): string { return '{"code":"200","message":"ok","data":{}}'; }
            public function getStatusCode(): int { return 200; }
        };
    }
};
$adapter = new TimbradorXpressRestAdapter($productionPac, $http, $productionFiscal);
$adapter->getStampStatus([
    'environment'=>'production','uuid'=>'123E4567-E89B-42D3-A456-426614174000',
    'rfcEmisor'=>'AAA010101AAA','rfcReceptor'=>'XAXX010101000','total'=>'116.00',
]);
$assert(count($http->calls) === 1 && $http->calls[0][0] === TimbradorXpress::PRODUCTION_URL . 'consultarEstadoSAT', 'Production MOCK dirige la operación al endpoint productivo sin HTTP real.');
$assert($http->calls[0][1]['form_params']['apikey'] === 'production-key', 'Production MOCK usa la credencial del ambiente correspondiente.');

$sandboxHttp = clone $http;
$sandboxHttp->calls = [];
$sandboxFiscal = $fiscal('production', 'development');
$sandboxPac = $pac('sandbox', 'sandbox-key');
(new TimbradorXpressRestAdapter($sandboxPac, $sandboxHttp, $sandboxFiscal))->getStampStatus([
    'environment'=>'sandbox','uuid'=>'123E4567-E89B-42D3-A456-426614174000',
    'rfcEmisor'=>'AAA010101AAA','rfcReceptor'=>'XAXX010101000','total'=>'116.00',
]);
$assert($sandboxHttp->calls[0][0] === TimbradorXpress::SANDBOX_URL . 'consultarEstadoSAT', 'Sandbox MOCK dirige la operación al endpoint dev permitido.');
$assert($sandboxHttp->calls[0][1]['form_params']['apikey'] === 'sandbox-key', 'Sandbox MOCK usa la credencial del ambiente correspondiente.');

$criticalFiles = [
    'app/Services/Fiscal/FiscalDraftStampingPreflightService.php',
    'app/Services/Fiscal/FiscalInvoiceFlowService.php',
    'app/Services/Fiscal/FiscalIntegrationStatusService.php',
    'app/Services/Fiscal/Pac/FiscalPacAdapterFactory.php',
    'app/Services/Fiscal/Pac/FiscalPacCreditService.php',
    'app/Services/Fiscal/Cancellation/FiscalCancellationService.php',
    'app/Controllers/Fiscal/StampBalance.php',
    'app/Controllers/Payment_complement_cancellations.php',
];
$sources = implode("\n", array_map(static fn(string $file): string => file_get_contents($root . '/' . $file), $criticalFiles));
$assert(!str_contains($sources, 'CI_ENVIRONMENT'), 'Consumidores críticos no deciden el ambiente fiscal mediante CI_ENVIRONMENT.');
$assert(str_contains($sources, 'FiscalRuntimeContext'), 'Preflight, diagnóstico, saldo y consumidores PAC usan el contexto canónico.');
$assert(!str_contains(file_get_contents($root . '/app/Services/Fiscal/Pac/FiscalPacCreditService.php'), "'environment' => 'development'"), 'Consulta y snapshots de saldo PAC conservan el ambiente efectivo.');

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed ? 1 : 0);
