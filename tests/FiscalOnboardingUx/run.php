<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\Fiscal\FiscalInstanceModeService;
use App\Services\Fiscal\FiscalReadinessActionService;
use App\Services\Fiscal\FiscalSeriesIssuerValidator;
use CodeIgniter\Database\Config as DbConfig;

$db = DbConfig::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
$db->query('CREATE TABLE fiscal_profiles (id INTEGER PRIMARY KEY, profile_type TEXT, company_id INTEGER NULL, environment TEXT, status TEXT)');
$db->query("INSERT INTO fiscal_profiles VALUES (1,'issuer',1,'development','incomplete')");
$db->query("INSERT INTO fiscal_profiles VALUES (2,'issuer',1,'development','ready')");
$db->query("INSERT INTO fiscal_profiles VALUES (3,'issuer',1,'production','ready')");
$fiscal = (object) ['environment' => 'development'];
$evaluator = static fn (int $id): array => ['is_ready' => $id === 2, 'errors' => $id === 2 ? [] : ['Falta RFC del emisor.']];
$validator = new FiscalSeriesIssuerValidator($db, $evaluator, $fiscal);
$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (! $condition) throw new RuntimeException('[FAIL] ' . $message);
    $passed++;
    echo '[PASS] ' . $message . PHP_EOL;
};

$blocked = $validator->validate(1);
$assert(! $blocked['valid'] && str_contains($blocked['message'], 'emisor') && $blocked['configuration_path'] === 'fiscal/issuers', 'serie queda bloqueada con validación accionable cuando el emisor está incompleto');
$assert($validator->validate(2)['valid'], 'serie puede guardarse cuando el emisor está completo y activo');
$assert(! $validator->validate(3)['valid'], 'serie rechaza un emisor de otro ambiente fiscal');

$readiness = [
    'state' => 'ONBOARDING', 'ready' => false, 'blockers' => ['Configuración pendiente.'],
    'details' => [
        'issuer' => ['status' => 'INCOMPLETE', 'id' => 1], 'csd' => ['status' => 'MISSING'],
        'series' => ['status' => 'MISSING'], 'pac' => ['status' => 'MISSING'],
        'catalogs' => ['product-service' => ['status' => 'PARTIAL']],
        'products' => ['incomplete' => 2], 'clients' => ['incomplete' => 1],
        'payment_methods' => ['available' => 2, 'mapped' => 1], 'stamping' => ['status' => 'BLOCKED'],
    ],
];
$actions = new FiscalReadinessActionService();
$checklist = $actions->onboardingChecklist($readiness);
$keys = array_column($checklist, 'key');
$assert($keys === ['catalogs', 'issuer', 'csd', 'series', 'pac', 'products', 'clients', 'payment_methods', 'stamping'], 'dashboard conserva todas las categorías de readiness en orden operativo');
$series = array_values(array_filter($checklist, static fn (array $row): bool => $row['key'] === 'series'))[0];
$assert($series['action']['path'] === 'fiscal/series' && $series['status'] === 'PENDIENTE', 'dashboard ofrece CTA directo para serie pendiente');

$saleReview = ['errors' => ['issuer' => ['Falta RFC.'], 'series' => ['Falta serie.'], 'receiver' => ['Falta régimen.'], 'items' => ['Producto incompleto.'], 'sale' => []]];
$groups = $actions->saleBlockerGroups($saleReview, $readiness, 55);
$assert($groups['issuer']['items'][0]['path'] === 'fiscal/issuers' && $groups['receiver']['items'][0]['path'] === 'clients/view/55', 'venta agrupa blockers y enlaza emisor y cliente');
$assert(isset($groups['csd'], $groups['pac'], $groups['payment_methods'], $groups['catalogs']), 'venta incorpora blockers globales desde readiness sin duplicar reglas');

$fiscalMode = (object) ['runtimeMode' => 'integration', 'enabled' => true, 'environment' => 'development', 'pacAdapter' => 'timbradorxpress', 'allowRealPac' => false, 'stampingEnabled' => false, 'csdEncryptionKey' => '', 'pacEncryptionKey' => ''];
$mode = (new FiscalInstanceModeService($db, $fiscalMode, (object) [], static fn (): array => $readiness))->inspect();
$assert($mode['mode'] === 'ONBOARDING' && ! $mode['ready'] && ! $mode['real_pac_allowed'], 'ONBOARDING mantiene PAC real y timbrado bloqueados');
$ready = ['state' => 'READY', 'ready' => true, 'blockers' => [], 'details' => ['catalogs' => [], 'issuer' => ['status' => 'READY']]];
$production = (object) ['runtimeMode' => 'production', 'enabled' => true, 'environment' => 'production', 'pacAdapter' => 'timbradorxpress', 'allowRealPac' => true, 'stampingEnabled' => true, 'csdEncryptionKey' => str_repeat('a', 64), 'pacEncryptionKey' => str_repeat('b', 32)];
$productionMode = (new FiscalInstanceModeService($db, $production, (object) [], static fn (): array => $ready))->inspect();
$assert($productionMode['mode'] === 'PRODUCTION' && $productionMode['ready'], 'instancia fiscalmente lista conserva el flujo PRODUCTION existente');

$controller = file_get_contents(APPPATH . 'Controllers/Fiscal/Series.php');
$validationPosition = strpos($controller, '$issuerValidation = (new FiscalSeriesIssuerValidator())->validate($issuer)');
$savePosition = strpos($controller, '$this->series->ci_save($data, $id)');
$assert($validationPosition !== false && $savePosition !== false && $validationPosition < $savePosition, 'controller valida emisor antes de persistir la serie');
$invoiceView = file_get_contents(APPPATH . 'Views/fiscal/invoices/review.php');
$draftView = file_get_contents(APPPATH . 'Views/fiscal/drafts/review.php');
$assert(str_contains($invoiceView, "blocker_groups") && str_contains($draftView, "blocker_groups"), 'ambos modales de revisión consumen blockers agrupados y accionables');

echo "passed={$passed}\n";
