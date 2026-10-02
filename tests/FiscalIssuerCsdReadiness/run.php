<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\Fiscal\FiscalCsdReadinessService;
use App\Services\Fiscal\FiscalDraftValidationService;
use App\Services\Fiscal\FiscalIssuerResolver;
use App\Services\Fiscal\FiscalReadinessActionService;
use App\Services\Fiscal\IssuerFiscalReadinessService;
use App\Services\Fiscal\Signing\CsdOperationalStatusService;
use Config\Database;

$local = config(Database::class)->default;
if (! in_array((string) $local['hostname'], ['localhost','127.0.0.1','::1'], true)) throw new RuntimeException('Local fixture server required.');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli($local['hostname'], $local['username'], $local['password'], '', (int) $local['port']);
$owned = 'ikontrol_test_issuer_csd_' . bin2hex(random_bytes(5));
$admin->query('CREATE DATABASE `' . $owned . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$db = Database::connect(array_replace($local, ['DSN'=>'','database'=>$owned,'DBPrefix'=>'','pConnect'=>false,'DBDebug'=>true,'failover'=>[]]), false);
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ikontrol_csd_' . bin2hex(random_bytes(5));
mkdir($root, 0700, true);
register_shutdown_function(static function () use ($admin, $owned, $root): void {
    $admin->query('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $owned) . '`');
    $admin->close();
    $remove = static function (string $path) use (&$remove): void {
        if (! is_dir($path)) return;
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $target = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($target) ? $remove($target) : @unlink($target);
        }
        @rmdir($path);
    };
    $remove($root);
});

$db->query('CREATE TABLE sat_tax_regimes (id INT PRIMARY KEY, code VARCHAR(10), description VARCHAR(100), is_active TINYINT)');
$db->query('CREATE TABLE fiscal_profiles (id INT PRIMARY KEY, profile_type VARCHAR(20), company_id INT NULL, environment VARCHAR(20), status VARCHAR(20), is_default TINYINT, valid_from DATE NULL, valid_to DATE NULL, rfc VARCHAR(20), legal_name VARCHAR(200), tax_regime_id INT NULL, fiscal_postal_code VARCHAR(10), expedition_postal_code VARCHAR(10), fiscal_country_code VARCHAR(5), fiscal_street VARCHAR(100) NULL, fiscal_neighborhood VARCHAR(100) NULL, fiscal_municipality VARCHAR(100) NULL, fiscal_state VARCHAR(100) NULL, trade_name VARCHAR(100) NULL)');
$db->query('CREATE TABLE fiscal_issuer_certificates (id INT PRIMARY KEY, issuer_profile_id INT, certificate_number VARCHAR(30), certificate_rfc VARCHAR(20), valid_from DATETIME, valid_to DATETIME, certificate_sha256 CHAR(64), public_certificate_path VARCHAR(255), encrypted_private_key_path VARCHAR(255), private_key_sha256 CHAR(64), status VARCHAR(30), is_default TINYINT, deleted TINYINT)');
$db->query('CREATE TABLE fiscal_issuer_certificate_secrets (id INT PRIMARY KEY AUTO_INCREMENT, fiscal_issuer_certificate_id INT, secret_type VARCHAR(40), status VARCHAR(20), encrypted_payload TEXT NULL)');
$db->table('sat_tax_regimes')->insert(['id'=>1,'code'=>'601','description'=>'General','is_active'=>1]);
$profile = static fn (int $id, string $environment='development'): array => ['id'=>$id,'profile_type'=>'issuer','company_id'=>1,'environment'=>$environment,'status'=>'ready','is_default'=>0,'valid_from'=>null,'valid_to'=>null,'rfc'=>'AAA010101AAA','legal_name'=>'EMISOR '.$id,'tax_regime_id'=>1,'fiscal_postal_code'=>'06000','expedition_postal_code'=>'06000','fiscal_country_code'=>'MEX'];
foreach ([$profile(1), $profile(2, 'production'), $profile(3), $profile(4), $profile(5)] as $row) $db->table('fiscal_profiles')->insert($row);
$now = time();
$certificate = static fn (int $id, int $issuer, string $status='valid', ?string $to=null): array => ['id'=>$id,'issuer_profile_id'=>$issuer,'certificate_number'=>(string)$id,'certificate_rfc'=>'AAA010101AAA','valid_from'=>gmdate('Y-m-d H:i:s',$GLOBALS['now']-3600),'valid_to'=>$to ?? gmdate('Y-m-d H:i:s',$GLOBALS['now']+3600),'certificate_sha256'=>hash('sha256','cer'),'public_certificate_path'=>'fiscal/certificates/'.$issuer.'/'.str_repeat('a',48).'.cer','encrypted_private_key_path'=>'fiscal/certificates/'.$issuer.'/'.str_repeat('b',48).'.key','private_key_sha256'=>hash('sha256','key'),'status'=>$status,'is_default'=>0,'deleted'=>0];
$db->table('fiscal_issuer_certificates')->insert($certificate(1, 1));
$db->table('fiscal_issuer_certificates')->insert($certificate(3, 3));
$db->table('fiscal_issuer_certificates')->insert($certificate(4, 4, 'expired', gmdate('Y-m-d H:i:s', $now - 1)));
$db->table('fiscal_issuer_certificates')->insert($certificate(5, 5));

$pass = 0;
$assert = static function (bool $condition, string $message) use (&$pass): void {
    if (! $condition) throw new RuntimeException('[FAIL] ' . $message);
    $pass++; echo '[PASS] ' . $message . PHP_EOL;
};

$resolver = new FiscalIssuerResolver($db);
$issuer = $resolver->resolve(1, 'development');
$assert((int) $issuer->id === 1 && ! (int) $issuer->is_default, 'resolver canónico acepta emisor legacy válido sin is_default');
$assert((int) ($resolver->resolveById(1, 1, 'development')?->id ?? 0) === 1, 'resolveById valida el mismo emisor canónico');
$assert($resolver->resolveById(2, 1, 'development') === null, 'emisor de otro ambiente permanece bloqueado');
$assert((new IssuerFiscalReadinessService($db))->evaluate(1, 1)['is_ready'], 'emisor configurado queda READY sin depender del CSD');

$missing = (new CsdOperationalStatusService($db, null, $root))->forCertificate((object) $certificate(1, 1));
$assert(! $missing['ready'] && $missing['code'] === 'private_files_unavailable', 'registro vigente sin archivos físicos no produce falso READY');
$csdMissing = (new FiscalCsdReadinessService($db, static fn (): array => $missing))->inspect($issuer);
$assert($csdMissing['registered'] && $csdMissing['status'] === 'BLOCKED' && $csdMissing['code'] === 'private_files_unavailable', 'CSD registrado y CSD operacional quedan diferenciados');

$directory = $root . DIRECTORY_SEPARATOR . '3'; mkdir($directory, 0700, true);
file_put_contents($directory . DIRECTORY_SEPARATOR . str_repeat('a',48) . '.cer', 'cer');
file_put_contents($directory . DIRECTORY_SEPARATOR . str_repeat('b',48) . '.key', 'key');
$passwordPending = (new CsdOperationalStatusService($db, null, $root))->forCertificate((object) $certificate(3, 3));
$assert($passwordPending['code'] === 'password_pending', 'material presente sin secreto reporta password_pending');
$expired = (new CsdOperationalStatusService($db, null, $root))->forCertificate((object) $certificate(4, 4, 'expired', gmdate('Y-m-d H:i:s',$now-1)));
$assert($expired['code'] === 'certificate_expired', 'certificado vencido reporta certificate_expired antes de revisar archivos');
$ready = (new FiscalCsdReadinessService($db, static fn (): array => ['ready'=>true,'code'=>'ready','label'=>'Configurado y listo.']))->inspect((object)['id'=>5]);
$assert($ready['status'] === 'READY' && $ready['registered'], 'emisor con CSD operacional queda READY');

$validation = (new FiscalDraftValidationService($db))->validate(['issuer_id'=>1], [], []);
$sections = array_column($validation['errors'], 'section');
$codes = array_column($validation['errors'], 'code');
$assert(! in_array('issuer', $sections, true) && in_array('private_files_unavailable', $codes, true), 'revisión fiscal conserva emisor READY y bloquea exclusivamente el CSD ausente');

$onboarding = ['details'=>['issuer'=>['id'=>1], 'csd'=>['status'=>'BLOCKED','code'=>'private_files_unavailable','label'=>'Archivos privados no disponibles.'], 'catalogs'=>[], 'products'=>['incomplete'=>0], 'clients'=>['incomplete'=>0], 'payment_methods'=>['available'=>1,'mapped'=>1], 'series'=>['status'=>'READY'], 'pac'=>['status'=>'READY'], 'stamping'=>['status'=>'BLOCKED']]];
$checklist = (new FiscalReadinessActionService())->onboardingChecklist($onboarding);
$csdRow = array_values(array_filter($checklist, static fn (array $row): bool => $row['key'] === 'csd'))[0];
$assert($csdRow['action']['label'] === 'Recargar CSD' && $csdRow['action']['path'] === 'fiscal/issuers/1/certificates', 'private_files_unavailable ofrece CTA Recargar CSD');
$actionService = new FiscalReadinessActionService();
$csdAction = static function (string $code) use ($actionService, $onboarding): string {
    $onboarding['details']['csd']['code'] = $code;
    $rows = $actionService->onboardingChecklist($onboarding);
    return array_values(array_filter($rows, static fn (array $row): bool => $row['key'] === 'csd'))[0]['action']['label'];
};
$assert($csdAction('certificate_missing') === 'Cargar CSD' && $csdAction('password_pending') === 'Configurar contraseña CSD', 'CTA distingue certificado inexistente y contraseña pendiente');
$assert($csdAction('certificate_expired') === 'Cargar nuevo CSD' && $csdAction('encryption_configuration_missing') === 'Revisar configuración CSD', 'CTA distingue certificado vencido y error de cifrado');

$source = file_get_contents(APPPATH . 'Services/Fiscal/SaleFiscalReadinessService.php');
$onboardingSource = file_get_contents(APPPATH . 'Services/Fiscal/FiscalOnboardingReadinessService.php');
$assert(str_contains($source, 'FiscalIssuerResolver') && str_contains($onboardingSource, 'FiscalIssuerResolver') && ! str_contains($source, 'defaultIssuer('), 'onboarding y venta comparten el resolver canónico');

echo "passed={$pass}\n";
