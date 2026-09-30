<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Domain\Fiscal\Pac\StampRequest;
use App\Services\Fiscal\FiscalPreparedDocumentLifecycleService;
use App\Services\Fiscal\Pac\FakePacAdapter;
use App\Services\Fiscal\Pac\FiscalStampReconciliationService;

$passed=0;$failed=0;
$assert=static function(bool$ok,string$message)use(&$passed,&$failed):void{echo($ok?'[PASS] ':'[FAIL] ').$message.PHP_EOL;$ok?$passed++:$failed++;};
$request=new StampRequest(1,'<?xml version="1.0"?><root/>',hash('sha256','<?xml version="1.0"?><root/>'),'fake','sandbox',str_repeat('a',64));

$success=(new FakePacAdapter('success'))->stamp($request);
$assert($success->code==='200'&&!$success->transportError,'1. Exito normal fake produce respuesta definitiva.');
$notSent=(new FakePacAdapter('transport_not_sent'))->stamp($request);
$assert($notSent->transportError&&($notSent->metadata['request_sent']??null)===false,'2/3. Error local y transport_not_sent conservan evidencia explicita de no-envio.');
try{(new FakePacAdapter('throw_after_transport_open'))->stamp($request);$thrown=false;}catch(Throwable){$thrown=true;}
$assert($thrown,'4. Excepcion despues de abrir transporte queda distinguible de no-envio.');
$rejected=(new FakePacAdapter('rejected'))->stamp($request);
$assert(!$rejected->transportError&&($rejected->metadata['request_sent']??null)===true,'5. Rechazo PAC definitivo conserva evidencia de envio.');
$timeout=(new FakePacAdapter('timeout_unknown'))->stamp($request);
$assert($timeout->timeout&&($timeout->metadata['request_sent']??null)===true,'6/7. Timeout posterior a posible envio queda incierto.');

$safe=['status'=>'transport_not_sent','request_sent'=>0,'requires_reconciliation'=>0,'uuid'=>null,'contingency_path'=>null,'response_hash'=>null,'response_body_sha256'=>null,'provider_code'=>null];
$assert(FiscalPreparedDocumentLifecycleService::safeToInvalidateAttempt($safe),'8. Solo evidencia completa transport_not_sent permite invalidar.');
foreach([
 ['request_sent'=>null,'label'=>'request_sent desconocido'],
 ['requires_reconciliation'=>1,'label'=>'conciliacion pendiente'],
 ['uuid'=>'ABC','label'=>'UUID existente'],
 ['contingency_path'=>'evidence','label'=>'evidencia de respuesta'],
 ['response_hash'=>str_repeat('a',64),'label'=>'hash de respuesta'],
 ['provider_code'=>'200','label'=>'codigo PAC'],
]as$case){$candidate=array_replace($safe,$case);unset($candidate['label']);$assert(!FiscalPreparedDocumentLifecycleService::safeToInvalidateAttempt($candidate),'Retry bloqueado: '.$case['label'].'.');}
$assert(FiscalPreparedDocumentLifecycleService::decision('same','same',false,[['status'=>'transport_unknown','requires_reconciliation'=>1]])==='protected','9. Unknown bloquea retry aun con el mismo snapshot.');
$assert(FiscalPreparedDocumentLifecycleService::decision('same','same',true,[],'stamped')==='protected','10/12. UUID/timbre existente bloquea doble submit.');
$assert(FiscalPreparedDocumentLifecycleService::decision('same','same',false,[])==='reuse','11. Prepared sin intento se reutiliza idempotentemente.');
$assert(FiscalPreparedDocumentLifecycleService::decision('old','new',false,[['status'=>'rejected','requires_reconciliation'=>0]])==='invalidate','12. Rechazo definitivo puede reemplazarse con nuevo prepared, nunca reenviarse.');

foreach([
 'reconcile_stamped'=>'stamped',
 'reconcile_not_found'=>'not_found_definitive',
 'reconcile_indeterminate'=>'indeterminate',
 'reconcile_error'=>'error',
]as$scenario=>$expected){$response=(new FakePacAdapter($scenario,'<xml/>'))->getStampStatus([]);$assert(FiscalStampReconciliationService::classifyResponse($response)===$expected,'Conciliacion fake clasifica '.$expected.'.');}

$stamping=file_get_contents(APPPATH.'Services/Fiscal/Pac/FiscalStampingService.php');
$lifecycle=file_get_contents(APPPATH.'Services/Fiscal/FiscalPreparedDocumentLifecycleService.php');
$reconciliation=file_get_contents(APPPATH.'Services/Fiscal/Pac/FiscalStampReconciliationService.php');
$wallet=file_get_contents(APPPATH.'Services/Fiscal/Stamps/FiscalStampAccountService.php');
$migration=file_get_contents(APPPATH.'Database/Migrations/2026-09-30-100000_AddCanonicalFiscalStampTransportEvidence.php');
$assert(!str_contains($stamping,'manual-retry')&&str_contains($stamping,"'transport_unknown'"),'13/14. Doble retry no genera otro intento y excepcion del adaptador queda unknown.');
$assert(str_contains($reconciliation,'GET_LOCK')&&str_contains($lifecycle,'GET_LOCK'),'15. Retry y conciliacion usan locks nominales por intento.');
$assert(str_contains($reconciliation,"table('fiscal_document_issuers')")&&str_contains($reconciliation,"table('fiscal_document_receivers')"),'15b. Conciliacion usa snapshots fiscales persistidos, no catalogos actuales.');
$assert(str_contains($wallet,'STAMP_RELEASE_NOT_SAFE')&&str_contains($wallet,'RECONCILIATION_RELEASE_NOT_DEFINITIVE'),'16/17/18. Wallet impide release incierto y exige cierre definitivo.');
$assert(str_contains($wallet,'stamp-consumption:{$stamp}')&&str_contains($wallet,'stamp-reconciliation-consumption:{$stamp}'),'19. Consumos normal y conciliado tienen claves idempotentes independientes.');
$assert(str_contains($migration,"'pac_endpoint'")&&str_contains($migration,"'request_sent'")&&str_contains($migration,"'transport_opened_at'"),'20. Schema preserva endpoint y evidencia del transporte sin backfill inventado.');

echo PHP_EOL."{$passed} passed, {$failed} failed.".PHP_EOL;
exit($failed?1:0);
