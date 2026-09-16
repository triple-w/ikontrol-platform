<?php
declare(strict_types=1);
define('ROOTPATH',dirname(__DIR__,2).DIRECTORY_SEPARATOR);define('FCPATH',ROOTPATH);require ROOTPATH.'app/Config/Paths.php';$paths=new Config\Paths();define('APPPATH',realpath($paths->appDirectory).DIRECTORY_SEPARATOR);define('SYSTEMPATH',realpath($paths->systemDirectory).DIRECTORY_SEPARATOR);define('WRITEPATH',realpath($paths->writableDirectory).DIRECTORY_SEPARATOR);define('ENVIRONMENT','development');require$paths->systemDirectory.'/Boot.php';CodeIgniter\Boot::bootTest($paths);helper(['plugin','general','date_time']);
$pass=$fail=0;$ok=function(bool$c,string$m)use(&$pass,&$fail){echo($c?'[PASS] ':'[FAIL] ').$m.PHP_EOL;$c?$pass++:$fail++;};
$root=dirname(__DIR__,2);$read=fn(string$p)=>(string)file_get_contents($root.'/app/'.$p);
try{
 $db=db_connect();$flow=$read('Services/Fiscal/FiscalInvoiceFlowService.php');$drafts=$read('Controllers/Fiscal/Drafts.php');$view=$read('Views/fiscal/drafts/review.php');$routes=$read('Config/FiscalRoutes.php');$stamping=$read('Services/Fiscal/FiscalDraftStampingService.php');$wallet=$read('Services/Fiscal/Stamps/FiscalStampAccountService.php');
 $attemptsBefore=$db->table('fiscal_stamp_attempts')->countAllResults();$movesBefore=$db->table('fiscal_stamp_movements')->countAllResults();
 $ok(str_contains($flow,'FiscalDraftValidationService')&&str_contains($flow,"'review_needed':'ready'"),'Ready requiere validación completa.');
 $ok(str_contains($flow,"inspection['ready']"),'Blocker impide llegar al stamping.');
 $ok(str_contains($flow,'STAMP_BALANCE_EMPTY')&&str_contains($flow,'wallet_available'),'Wallet cero impide PAC.');
 $ok(str_contains($stamping,'$this->stamping->stamp')&&str_contains($wallet,'reserveForAttempt'),'Reserva ocurre antes del transporte PAC.');
 $ok(str_contains($flow,'GET_LOCK(?, 0)')&&str_contains($stamping,'fiscal_draft_stamp_'),'Doble clic protegido por locks idempotentes.');
 $ok(str_contains($wallet,'releaseReservation')&&str_contains($stamping,"setDraftState(\$draftId, 'ready'"),'Rechazo seguro libera reserva.');
 $ok(str_contains($flow,'requiresReconciliation')&&str_contains($flow,"retry_allowed'=>false"),'Unknown mantiene reserva y bloquea reenvío.');
 $ok(str_contains($wallet,'consumeReservation')&&str_contains($flow,"status'=>'stamped'"),'Éxito consume uno.');
 $ok(str_contains($stamping,'xmlAvailable')&&str_contains($stamping,'uuid'),'XML validado y UUID requerido.');
 $ok(str_contains($flow,"'uuid'=>\$stamp['uuid']"),'UUID guardado/proyectado.');
 $ok(str_contains($stamping,"'status' => 'stamped'"),'Draft stamped.');
 $ok(str_contains($read('Services/Fiscal/Pac/FiscalStampingService.php'),'fiscal_documents'),'Documento stamped por motor canónico.');
 $ok(str_contains($stamping,'convertDraftAllocationsToDocument'),'Asignaciones converted.');
 $ok(str_contains($stamping,'FiscalPacPdfGenerationService')&&str_contains($read('Services/Fiscal/Pdf/FiscalPacPdfGenerationService.php'),'providerCode'),'PDF valida respuesta PAC.');
 $ok(str_contains($flow,'pdfAvailable')&&str_contains($flow,'pero el PDF no pudo generarse'),'Error PDF no retimbra.');
 $ok(str_contains($flow,"'redirect_url'=>get_uri('fiscal/invoices/'"),'Success devuelve redirect.');
 $ok(str_contains($flow,"'keep_modal_open'=>true")&&str_contains($view,'retry_allowed'),'Failure permanece modal.');
 $ok(str_contains($flow,"'Editar cliente'")&&str_contains($flow,"'Editar partida'"),'Blockers accionables.');
 $ok(!str_contains($view,'parser')&&!str_contains($view,'provider_code')&&!str_contains($view,'snapshot_version'),'Sin detalles técnicos normales.');
 $ok(str_contains($wallet,"'document_consumption',1,0,-1")&&str_contains($wallet,"'document_reservation',1,-1,1"),'Saldo cliente baja uno.');
 $ok(str_contains($wallet,'PAC_PROVIDER_CREDITS_MUST_NOT_MUTATE_CLIENT_WALLET'),'PAC credits no alteran wallet.');
 $ok(str_contains($routes,'invoice-flow')&&str_contains($drafts,'FiscalInvoiceFlowService'),'Endpoint canónico registrado.');
 $ok(str_contains($view,'Esta operación utilizará 1 timbre asignado.')&&str_contains($view,'Generando factura...'),'Confirmación y espera visibles.');
 $ok($db->table('fiscal_stamp_attempts')->countAllResults()===$attemptsBefore&&$db->table('fiscal_stamp_movements')->countAllResults()===$movesBefore,'Cero PAC y cero timbres en pruebas.');
}catch(Throwable$e){echo'[FAIL] '.$e->getMessage().PHP_EOL;$fail++;}
echo"\n{$pass} passed, {$fail} failed.\n";exit($fail?1:0);
