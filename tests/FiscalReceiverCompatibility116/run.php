<?php
declare(strict_types=1);

define('ROOTPATH', dirname(__DIR__, 2).DIRECTORY_SEPARATOR);
define('FCPATH', ROOTPATH);
require ROOTPATH.'app/Config/Paths.php';
$paths = new Config\Paths();
define('APPPATH', realpath($paths->appDirectory).DIRECTORY_SEPARATOR);
define('SYSTEMPATH', realpath($paths->systemDirectory).DIRECTORY_SEPARATOR);
define('WRITEPATH', realpath($paths->writableDirectory).DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'testing');
require $paths->systemDirectory.'/Boot.php';
CodeIgniter\Boot::bootTest($paths);

use App\Services\Fiscal\FiscalInvoiceFlowService;
use App\Services\Fiscal\FiscalReceiverRuleService;
use App\Services\Fiscal\FiscalRejectedDraftReplacementService;
use Config\Database;

$db = Database::connect(['DBDriver'=>'SQLite3', 'database'=>':memory:', 'DBPrefix'=>''], false);
$db->query('CREATE TABLE sat_tax_regimes (id INTEGER PRIMARY KEY, code TEXT, description TEXT, applies_to_individual INTEGER, applies_to_company INTEGER, valid_from TEXT, valid_to TEXT, is_active INTEGER)');
$db->query('CREATE TABLE sat_cfdi_uses (id INTEGER PRIMARY KEY, code TEXT, description TEXT, applies_to_individual INTEGER, applies_to_company INTEGER, valid_from TEXT, valid_to TEXT, is_active INTEGER)');
$db->table('sat_tax_regimes')->insertBatch([
    ['id'=>1,'code'=>'616','description'=>'Sin obligaciones fiscales','applies_to_individual'=>1,'applies_to_company'=>1,'valid_from'=>'2022-01-01','valid_to'=>null,'is_active'=>1],
    ['id'=>2,'code'=>'601','description'=>'General de Ley Personas Morales','applies_to_individual'=>0,'applies_to_company'=>1,'valid_from'=>'2022-01-01','valid_to'=>null,'is_active'=>1],
]);
$db->table('sat_cfdi_uses')->insertBatch([
    ['id'=>1,'code'=>'S01','description'=>'Sin efectos fiscales','applies_to_individual'=>1,'applies_to_company'=>1,'valid_from'=>'2022-01-01','valid_to'=>null,'is_active'=>1],
    ['id'=>2,'code'=>'G03','description'=>'Gastos en general','applies_to_individual'=>1,'applies_to_company'=>1,'valid_from'=>'2022-01-01','valid_to'=>null,'is_active'=>1],
    ['id'=>3,'code'=>'OLD','description'=>'Vencido','applies_to_individual'=>1,'applies_to_company'=>1,'valid_from'=>'2022-01-01','valid_to'=>'2023-12-31','is_active'=>1],
]);

$passed = $failed = 0;
$ok = static function(bool $condition, string $message) use (&$passed, &$failed): void {
    echo ($condition ? 'PASS ' : 'FAIL ').$message.PHP_EOL;
    $condition ? $passed++ : $failed++;
};
$throws = static function(callable $callback, string $contains, string $message) use ($ok): void {
    try { $callback(); $ok(false, $message); }
    catch (Throwable $e) { $ok(str_contains($e->getMessage(), $contains), $message); }
};
$rules = new FiscalReceiverRuleService($db);
$public = ['rfc'=>'XAXX010101000','legal_name'=>'PUBLICO EN GENERAL','tax_regime_code'=>'616','fiscal_postal_code'=>'06000'];
$foreign = ['rfc'=>'XEXX010101000','legal_name'=>'CLIENTE EXTRANJERO','tax_regime_code'=>'616','fiscal_postal_code'=>'06000'];

$rules->validate($public, 'S01', 'I', '06000', '2026-11-09');
$ok(true, 'PUBLICO EN GENERAL 616/S01 y domicilio de expedicion es valido.');
$throws(fn()=>$rules->validate($public, 'G03', 'I', '06000', '2026-11-09'), 'S01', 'XAXX con G03 se bloquea localmente antes del PAC.');
$throws(fn()=>$rules->validate(array_merge($public,['tax_regime_code'=>'601']), 'S01', 'I', '06000', '2026-11-09'), '616', 'RFC generico con regimen distinto de 616 se bloquea.');
$throws(fn()=>$rules->validate(array_merge($public,['legal_name'=>'CLIENTE GENERICO']), 'S01', 'I', '06000', '2026-11-09'), 'PUBLICO EN GENERAL', 'XAXX de ingreso exige nombre PUBLICO EN GENERAL.');
$throws(fn()=>$rules->validate($public, 'S01', 'I', '64000', '2026-11-09'), 'lugar de expedicion', 'RFC generico exige domicilio fiscal igual al lugar de expedicion.');
$rules->validate($foreign, 'S01', 'I', '06000', '2026-11-09');
$ok(true, 'XEXX conserva nombre del residente y exige 616/S01.');
$throws(fn()=>$rules->validate($foreign, 'G03', 'I', '06000', '2026-11-09'), 'S01', 'XEXX con UsoCFDI distinto de S01 se bloquea.');
$rules->validate(['rfc'=>'AAA010101AAA','legal_name'=>'EMPRESA','tax_regime_code'=>'601','fiscal_postal_code'=>'06000'], 'G03', 'I', '06000', '2026-11-09');
$ok(true, 'Receptor moral ordinario usa aplicabilidad vigente de catalogos SAT.');
$throws(fn()=>$rules->validate(['rfc'=>'AAA010101AAA','legal_name'=>'EMPRESA','tax_regime_code'=>'601','fiscal_postal_code'=>'06000'], 'OLD', 'I', '06000', '2026-11-09'), 'ya no estaba vigente', 'UsoCFDI vencido se rechaza segun vigencia del catalogo.');

$message = new ReflectionMethod(FiscalInvoiceFlowService::class, 'providerRejectionMessage');
$message->setAccessible(true);
$visible = $message->invoke(null, 'CFDI40161', 'UsoCFDI incompatible con el regimen fiscal receptor');
$ok(str_contains($visible, 'CFDI40161') && str_contains($visible, 'UsoCFDI incompatible'), 'La interfaz conserva codigo y mensaje PAC sanitizados.');

$replacement = new ReflectionMethod(FiscalRejectedDraftReplacementService::class, 'prepare');
$source = file_get_contents(APPPATH.'Services/Fiscal/FiscalRejectedDraftReplacementService.php');
$ok($replacement->getNumberOfParameters()===3 && str_contains($source, "'receiver_profile_id','cfdi_use_code','global_periodicity','global_months','global_year'") && str_contains($source, 'rejected_preparation_replaced'), 'El reemplazo acepta correcciones, conserva trazabilidad y materializa un documento nuevo.');
$ok(str_contains($source, 'CfdiPreXmlArtifactService') && !str_contains($source, 'signed_xml_artifact_id'), 'El reemplazo regenera Pre-XML y no reutiliza XML firmado rechazado.');

foreach (['FiscalDraftWorkflowService.php','FiscalDraftValidationService.php','FiscalDraftStampingPreflightService.php'] as $file) {
    $ok(str_contains((string) file_get_contents(APPPATH.'Services/Fiscal/'.$file), 'FiscalReceiverRuleService'), $file.' usa la misma regla de receptor antes del transporte.');
}
$reviewSource = file_get_contents(APPPATH.'Services/Fiscal/FiscalReviewPreparation.php');
$ok(str_contains($reviewSource, "'expedition_postal_code'=>(string)(\$issuer->expedition_postal_code??'')"), 'La revision fiscal valida el domicilio generico contra el lugar de expedicion real.');
$lifecycleSource = file_get_contents(APPPATH.'Services/Fiscal/FiscalDraftWorkflowService.php');
$ok(str_contains($lifecycleSource, 'invalidateIfSnapshotChanged') && str_contains($lifecycleSource, "\$input['cfdi_use_code']"), 'Cambiar receptor o UsoCFDI invalida la preparacion rechazada y genera un snapshot nuevo.');
$ok(strpos($lifecycleSource, 'FiscalReceiverRuleService') < strpos($lifecycleSource, 'reserveDraftAllocations'), 'La combinacion fiscal se bloquea antes de reservar ventas o iniciar transporte PAC.');

echo "passed={$passed} failed={$failed}\n";
exit($failed ? 1 : 0);
