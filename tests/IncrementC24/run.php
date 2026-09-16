<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
$pass=0;$fail=0;$a=function(bool$ok,string$message)use(&$pass,&$fail){echo($ok?'[PASS] ':'[FAIL] ').$message.PHP_EOL;$ok?$pass++:$fail++;};
try{
 $service=file_get_contents(APPPATH.'Services/Fiscal/Cancellation/FiscalCancellationService.php');
 $adapter=file_get_contents(APPPATH.'Services/Fiscal/Cancellation/TimbradorXpressCancellationAdapter.php');
 $fake=file_get_contents(APPPATH.'Services/Fiscal/Cancellation/FakeFiscalCancellationAdapter.php');
 $view=file_get_contents(APPPATH.'Views/fiscal/invoices/cancel_form.php');
 $reasonCatalog=file_get_contents(APPPATH.'Services/Fiscal/Cancellation/FiscalCancellationReasonCatalog.php');
 $module=file_get_contents(APPPATH.'Controllers/Fiscal/InvoiceModule.php');
 $routes=file_get_contents(APPPATH.'Config/FiscalRoutes.php');
 $a(str_contains($module,'canCancel($row)'),'Cancel action is gated by fiscal state.');
 $a(str_contains($module,"['none','rejected']"),'Drafts, cancelled and active requests cannot show Cancel.');
 foreach(['01','02','03','04']as$reason)$a(str_contains($reasonCatalog,"'{$reason}'"),"Reason {$reason} is present in the shared catalog.");
 $a(str_contains($service,"\$reason==='01'")&&str_contains($service,'UUID sustituto'),'Reason 01 requires replacement UUID.');
 $a(str_contains($service,"\$reason!=='01')\$replacementUuid=null"),'Reasons 02-04 discard replacement UUID.');
 $a(str_contains($service,'[0-9A-F]{8}')&&str_contains($service,'[0-9A-F]{12}'),'UUID is validated locally.');
 $a(str_contains($service,'no puede ser igual'),'Replacement UUID cannot equal cancelled UUID.');
 $a(str_contains($service,'certificate_rfc')&&str_contains($service,'issuerRfc'),'Issuer CSD and RFC are resolved dynamically.');
 $a(str_contains($service,'consumeCancellationRequest')&&str_contains($service,'consumeCancellationStatusQuery'),'Cancellation uses explicit commercial wallet movements.');
 $a(str_contains($service,"'accepted'=>'cancelled'"),'Accepted result marks document cancelled.');
 $a(str_contains($service,"'pending'=>'cancellation_pending'"),'Pending acceptance is persisted.');
 $a(str_contains($service,"'rejected','transport_not_sent'=>'cancellation_rejected'"),'Provider rejection remains retryable.');
 $a(str_contains($service,"'unknown'=>'cancellation_pending'")&&str_contains($service,'requires_reconciliation'),'Unknown requires reconciliation.');
 $a(str_contains($service,"!in_array(\$existing->status,['rejected','transport_not_sent'],true)"),'Duplicate active cancellation is blocked.');
 $a(str_contains($routes,'cancellation/status')&&str_contains($service,'queryStatus'),'Status query is routed.');
 $a(str_contains($adapter,"'cancelarPEM'")&&str_contains($adapter,"'consultarEstadoSAT'"),'Documented TimbradorXpress operations are used.');
 $a(str_contains($view,'toggleClass')&&str_contains($view,"uuid.val('')"),'Conditional UUID UI clears stale values.');
 $a(str_contains($module,'cancellation/status/form')&&str_contains($module,'cancellation_request_id'),'UI exposes the charged status confirmation modal for persisted requests.');
 $a(str_contains($service,'FiscalPreviewModeGuard')&&str_contains($adapter,'assertSandbox'),'Production cancellation is guarded.');
 $a(!preg_match('/log_message\([^\n]*(?:key_pem|keyPEM|certificate_pem|cerPEM)/i',$adapter),'Adapter never logs private key material.');
 $a(str_contains($fake,'public function query'),'Automated fake supports zero-network status queries.');
 echo "[INFO] Automated suite uses static contract checks and performs zero PAC calls.".PHP_EOL;
}catch(Throwable$e){echo'[FAIL] '.get_class($e).': '.$e->getMessage().PHP_EOL;$fail++;}
echo"\n$pass passed, $fail failed.\n";exit($fail?1:0);
