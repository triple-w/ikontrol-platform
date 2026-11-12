<?php
declare(strict_types=1);

define('ROOTPATH',dirname(__DIR__,2).DIRECTORY_SEPARATOR);
define('FCPATH',ROOTPATH);
require ROOTPATH.'app/Config/Paths.php';
$paths=new Config\Paths();
define('APPPATH',realpath($paths->appDirectory).DIRECTORY_SEPARATOR);
define('SYSTEMPATH',realpath($paths->systemDirectory).DIRECTORY_SEPARATOR);
define('WRITEPATH',realpath($paths->writableDirectory).DIRECTORY_SEPARATOR);
define('ENVIRONMENT','testing');
require $paths->systemDirectory.'/Boot.php';
CodeIgniter\Boot::bootTest($paths);

use App\Services\Fiscal\FiscalPreparedDocumentLifecycleService;

$pass=$fail=0;
$ok=static function(bool$value,string$message)use(&$pass,&$fail):void{echo($value?'[PASS] ':'[FAIL] ').$message.PHP_EOL;$value?$pass++:$fail++;};

$same='same-snapshot';
$ok(FiscalPreparedDocumentLifecycleService::decision($same,$same,false,[],'locked')==='reuse','Una preparación local idéntica se reutiliza y no duplica folio.');
$ok(FiscalPreparedDocumentLifecycleService::decision($same,$same,false,[],'superseded')==='invalidate','Una preparación superseded queda histórica y no bloquea el borrador.');
$ok(FiscalPreparedDocumentLifecycleService::decision($same,$same,false,[['status'=>'provider_rejected','requires_reconciliation'=>0]],'stamping_error')==='invalidate','Un rechazo definitivo habilita una preparación nueva.');
$ok(FiscalPreparedDocumentLifecycleService::decision($same,'changed',false,[['status'=>'transport_unknown','requires_reconciliation'=>1]],'stamping_error')==='protected','Un resultado incierto protege documento, folio e idempotencia.');
$ok(FiscalPreparedDocumentLifecycleService::decision($same,'changed',true,[],'stamped')==='protected','Un UUID persistido nunca se reemplaza.');

$workflow=(string)file_get_contents(APPPATH.'Services/Fiscal/FiscalDraftWorkflowService.php');
$materializer=(string)file_get_contents(APPPATH.'Services/Fiscal/FiscalDocumentFromDraftSnapshotService.php');
$preflight=(string)file_get_contents(APPPATH.'Services/Fiscal/FiscalDraftStampingPreflightService.php');
$flow=(string)file_get_contents(APPPATH.'Services/Fiscal/FiscalInvoiceFlowService.php');
$allocations=(string)file_get_contents(APPPATH.'Services/Fiscal/FiscalSaleAllocationService.php');
$controller=(string)file_get_contents(APPPATH.'Controllers/Fiscal/Drafts.php');
$view=(string)file_get_contents(APPPATH.'Views/fiscal/drafts/review.php');

$ok(!str_contains($workflow,"'product_service_keys'=>\$catalog")&&!str_contains($workflow,"'unit_keys'=>\$catalog"),'La revisión no carga completos los dos catálogos SAT masivos.');
$ok(str_contains($workflow,'bool $includeCompatibleSales = true')&&str_contains($workflow,'? $this->compatibleSales')&&str_contains((string)file_get_contents(APPPATH.'Controllers/Fiscal/Drafts.php'),'formData($draftId,[],false)'),'La revisión normal evita el barrido N+1 de todas las ventas del cliente.');
$ok(str_contains($controller,'FISCAL_REVIEW_TIMING')&&str_contains($controller,"'form_data'")&&str_contains($controller,"'readiness'")&&str_contains($controller,"'render'"),'La ruta registra tiempos por etapa sin datos fiscales ni secretos.');
$ok(str_contains($preflight,'principalDocumentId')&&str_contains($flow,'principalDocumentId'),'Preflight y ejecución comparten la resolución del documento principal.');
$ok(str_contains($preflight,'hasBlockingOperation')&&str_contains($allocations,"join('fiscal_documents d','d.source_draft_id=a.fiscal_draft_id')"),'Un intento incierto histórico de la venta bloquea otra preparación aunque aún no exista UUID.');
$ok(str_contains($materializer,'FOR UPDATE')&&str_contains($materializer,"'current_folio' => \$folio")&&str_contains($materializer,"'status' => 'stamping'"),'Folio se asigna bajo lock sólo al materializar para timbrado.');
$ok(str_contains($flow,'refreshExpiredIssueDate')&&str_contains($flow,'issue_date_refreshed_for_issuance'),'Un borrador antiguo recibe fecha de emisión actual auditada antes de materializarse.');
$ok(str_contains($view,'r.blockers&&r.blockers.length')&&str_contains($view,"Acción: ")&&str_contains($view,'r.retry_allowed'),'La interfaz presenta blockers concretos y respeta el bloqueo de reintento.');
$ok(substr_count($flow,'->stampSaleFlow(')===1,'El flujo contiene una sola invocación de timbrado por confirmación.');

echo "Resultado: {$pass} PASS, {$fail} FAIL".PHP_EOL;
exit($fail?1:0);
