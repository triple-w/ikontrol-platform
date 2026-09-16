<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
helper(['date_time','general','currency','form']);
use App\Services\Fiscal\{CreditNoteService,FiscalIssueDateNormalizer,FiscalIssueDatePolicy};
$passed=$failed=0;$assert=static function(bool$c,string$m)use(&$passed,&$failed):void{echo($c?'[PASS] ':'[FAIL] ').$m.PHP_EOL;$c?$passed++:$failed++;};$throws=static function(callable$f,string$e):bool{try{$f();return false;}catch(Throwable$t){return str_contains($t->getMessage(),$e);}};
try{
 $normalizer=new FiscalIssueDateNormalizer();$policy=new FiscalIssueDatePolicy();$zone=$normalizer->timezone();$now=new DateTimeImmutable('2026-08-28 19:01:30',$zone);
 $assert($zone->getName()===(string)config('App')->appTimezone,'Frontend y backend toman la zona fiscal de Config\\App.');
 $assert($normalizer->normalizeTransport('2026-08-28T19:01')==='2026-08-28 19:01:00','datetime-local con minutos se normaliza al formato canónico.');
 $assert($normalizer->normalizeTransport('2026-08-28T19:01:15')==='2026-08-28 19:01:15','datetime-local conserva segundos.');
 $policy->validate('2026-08-28 19:01:30',$now);$assert(true,'La hora actual es aceptada.');
 $policy->validate('2026-08-28 18:56:30',$now);$assert(true,'Unos minutos en el pasado son aceptados.');
 $policy->validate($now->modify('-'.((int)config('Fiscal')->maxIssueAgeHours-1).' hours')->format('Y-m-d H:i:s'),$now);$assert(true,'Una fecha dentro de la ventana fiscal es aceptada.');
 $assert($throws(fn()=>$policy->validate('2026-08-28 19:02:00',$now),'FISCAL_ISSUE_DATE_FUTURE'),'Una fecha futura es bloqueada específicamente.');
 $assert($throws(fn()=>$policy->validate($now->modify('-'.((int)config('Fiscal')->maxIssueAgeHours+1).' hours')->format('Y-m-d H:i:s'),$now),'FISCAL_ISSUE_DATE_TOO_OLD'),'Una fecha fuera de ventana es bloqueada específicamente.');
 $policy->validate('2026-08-28 19:01:30',$now->setTimezone(new DateTimeZone('UTC')));$assert(true,'El mismo instante UTC/local no se interpreta como futuro.');
 $db=require dirname(__DIR__).'/Increment02/isolated_database.php';$service=new CreditNoteService($db);$user=(int)$db->table('users')->where(['is_admin'=>1,'deleted'=>0])->get(1)->getRow()->id;$document=$service->eligibleDocuments()[0]??null;if(!$document)throw new RuntimeException('No hay factura fiscal elegible en la base aislada.');$id=$service->create((int)$document->id,$user);$context=$service->context($id);$quantities=[];foreach($context['items']as$item)$quantities[(int)$item->id]='0.000001';$input=(new DateTimeImmutable('now',$zone))->modify('-1 minute')->format('Y-m-d\\TH:i:s');$service->update($id,$quantities,$input,$user);$saved=(string)$service->context($id)['note']->issue_date;
 $assert($saved===$normalizer->normalizeTransport($input),'Guardar y recargar conserva exactamente fecha, hora y segundos.');
 $serviceSource=file_get_contents(APPPATH.'Services/Fiscal/CreditNoteService.php');$invoiceSource=file_get_contents(APPPATH.'Services/Fiscal/FiscalDraftWorkflowService.php');$view=file_get_contents(APPPATH.'Views/credit_notes/edit.php');$review=file_get_contents(APPPATH.'Views/credit_notes/review.php');
 $assert(str_contains($serviceSource,'FiscalIssueDateNormalizer')&&str_contains($serviceSource,'FiscalIssueDatePolicy')&&str_contains($invoiceSource,'FiscalIssueDateNormalizer'),'Facturas y Notas comparten normalizador y política fiscal.');
 $assert(str_contains($serviceSource,'La fecha de emisión no puede estar en el futuro.')&&!str_contains($review,'FISCAL_ISSUE_DATE_INVALID'),'La UI recibe mensajes legibles, no códigos internos.');
 $assert(str_contains($view,'type="datetime-local" step="1"')&&str_contains($view,'max="')&&str_contains($view,'Zona fiscal:'),'Selector conserva segundos, limita futuro y muestra zona fiscal.');
 $assert(str_contains($review,'Fecha de emisión')&&str_contains($review,'Bloqueante')&&str_contains($review,'Correcto'),'Revisión fiscal muestra fecha y estado concreto.');
}catch(Throwable$e){echo'[FAIL] '.get_class($e).': '.$e->getMessage().' '.$e->getFile().':'.$e->getLine().PHP_EOL;$failed++;}
echo"TOTAL PASS=$passed FAIL=$failed".PHP_EOL;exit($failed?1:0);
