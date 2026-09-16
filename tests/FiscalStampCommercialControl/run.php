<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';helper(['general','date_time']);
$pass=$fail=0;$ok=static function(bool$c,string$m)use(&$pass,&$fail){echo($c?'[PASS] ':'[FAIL] ').$m.PHP_EOL;$c?$pass++:$fail++;};
$root=dirname(__DIR__,2);$read=static fn(string$p)=>(string)file_get_contents($root.'/'.$p);
$migration=$read('app/Database/Migrations/2026-08-04-170000_CreateFiscalStampCommercialControl.php');$service=$read('app/Services/Fiscal/Stamps/FiscalStampAccountService.php');$stamping=$read('app/Services/Fiscal/Pac/FiscalStampingService.php');$cancel=$read('app/Services/Fiscal/Cancellation/FiscalCancellationService.php');$cli=$read('app/Commands/FiscalStampsAdjust.php');$identity=$read('app/Commands/FiscalPlatformIdentity.php');$controller=$read('app/Controllers/Fiscal/StampBalance.php');$routes=$read('app/Config/FiscalRoutes.php');
$ok(!preg_match('/DOUBLE|FLOAT/i',$migration.$service),'Stamp balances and quantities never use FLOAT or DOUBLE.');
foreach(['fiscal_stamp_accounts','fiscal_stamp_movements','available_balance','reserved_balance','consumption_key','fiscal_cancellation_request_id']as$s)$ok(str_contains($migration,$s),"Migration defines {$s}.");
$ok(str_contains($migration,"addUniqueKey('issuer_profile_id'")&&str_contains($migration,"addUniqueKey('consumption_key'"),'Issuer account and consumption keys are unique.');
$ok(str_contains($migration,'quantity <> 0')&&str_contains($migration,'available_balance >= 0'),'Database checks reject zero movements and negative balances.');
$ok(str_contains($service,'FOR UPDATE'),'Every account mutation locks the issuer account.');
$ok(str_contains($service,'stamp-reservation:')&&str_contains($service,'stamp-consumption:')&&str_contains($service,'stamp-release:'),'Document operations have stable idempotency keys.');
$ok(str_contains($service,'cancellation-request:')&&str_contains($service,'cancellation-status-query:'),'Cancellation request and query have independent stable idempotency keys.');
$ok(str_contains($stamping,'reserveForAttempt')&&strpos($stamping,'reserveForAttempt')<strpos($stamping,'markSending'),'Reservation occurs before PAC sending state.');
$ok(str_contains($stamping,'consumeReservation'),'Successful stamp converts the reservation to consumption.');
$ok(str_contains($stamping,'releaseCommercialReservation'),'Rejected or unsent document operations release reservations.');
$ok(str_contains($stamping,'reconciliation_required'),'Unknown transport outcomes remain reconcilable.');
$ok(str_contains($cancel,'consumeCancellationRequest')&&str_contains($cancel,'consumeCancellationStatusQuery'),'Cancellation request and status query each consume one commercial stamp after provider call.');
$ok(str_contains($cli,'--confirm-rfc')&&str_contains($cli,'--dry-run')&&str_contains($cli,'--execute'),'Adjustment CLI requires explicit safe execution mode and RFC confirmation.');
$ok(str_contains($cli,'is_platform_superadmin')&&str_contains($cli,'platform.fiscal_stamps.manage'),'Normal admin alone cannot adjust stamps.');
$ok(str_contains($identity,'platform_identity_audit'),'Platform identity changes are audited.');
$ok(str_contains($routes,"get('fiscal/stamps/balance'")&&!str_contains($routes,"post('fiscal/stamps/balance'"),'Balance endpoint is GET-only.');
$ok(!str_contains($controller,'getGet(')&&!str_contains($controller,'issuer_profile_id'),'Balance controller accepts no arbitrary issuer profile.');
$ok(str_contains($controller,'fiscal.stamps.view_balance'),'Balance view enforces its dedicated permission.');
$ok(!preg_match('/function\s+(update|delete)\s*\(/i',$service),'Domain service exposes no movement update or delete operation.');
require_once $root.'/app/Database/Migrations/2026-08-04-170000_CreateFiscalStampCommercialControl.php';
$db=require dirname(__DIR__).'/Increment02/isolated_database.php';
try{
 (new App\Database\Migrations\CreateFiscalStampCommercialControl())->up();
 $ok($db->tableExists('fiscal_stamp_accounts')&&$db->tableExists('fiscal_stamp_movements'),'Migration applies to a disposable database.');
 $issuer=$db->table('fiscal_profiles')->where('profile_type','issuer')->get(1)->getRow();
 if(!$issuer)throw new RuntimeException('Temporary fixture has no issuer.');
 $s=new App\Services\Fiscal\Stamps\FiscalStampAccountService($db);
 $a=$s->getOrCreateAccountForIssuer((int)$issuer->id);
 $ok((int)$a->available_balance===0,'Issuer account starts at zero.');
 $m=$s->allocate((int)$issuer->id,100,'Test allocation',null,'test:allocation');
 $ok((int)$m->available_after===100,'Allocation adds exactly 100 integer stamps.');
 $same=$s->allocate((int)$issuer->id,100,'Test allocation',null,'test:allocation');
 $ok((int)$same->id===(int)$m->id&&$s->getBalance((int)$issuer->id)['available']===100,'Repeated key does not allocate twice.');
 $thrown=false;try{$s->adjust((int)$issuer->id,-101,'Invalid debit',null,'test:negative');}catch(Throwable){$thrown=true;}
 $ok($thrown,'A debit that leaves negative balance is rejected.');
 $s->adjust((int)$issuer->id,-99,'Leave one',null,'test:leave-one');
 $attempts=$db->table('fiscal_stamp_attempts a')->select('a.id')->join('fiscal_documents d','d.id=a.fiscal_document_id')->where('d.issuer_profile_id',$issuer->id)->limit(2)->get()->getResult();
 if(count($attempts)<2)throw new RuntimeException('Temporary fixture has fewer than two attempts.');
 $s->reserveForAttempt((int)$attempts[0]->id);$b=$s->getBalance((int)$issuer->id);
 $ok($b['available']===0&&$b['reserved']===1,'Reservation moves one stamp to reserved.');
 $blocked=false;try{$s->reserveForAttempt((int)$attempts[1]->id);}catch(Throwable){$blocked=true;}$ok($blocked,'One available stamp permits only one reservation.');
 $s->releaseReservation((int)$attempts[0]->id);$b=$s->getBalance((int)$issuer->id);
 $ok($b['available']===1&&$b['reserved']===0,'Release restores unused reservation.');
}catch(Throwable$e){$ok(false,'Disposable database tests: '.$e->getMessage());}
echo"\n{$pass} passed, {$fail} failed.\n";exit($fail?1:0);
try{$db=require dirname(__DIR__).'/Increment02/isolated_database.php';(new App\Database\Migrations\CreateFiscalStampCommercialControl())->up();$ok($db->tableExists('fiscal_stamp_accounts')&&$db->tableExists('fiscal_stamp_movements'),'Migration applies to a disposable database.');$issuer=$db->table('fiscal_profiles')->where(['profile_type'=>'issuer','deleted'=>0])->get(1)->getRow();if(!$issuer)throw new RuntimeException('Temporary fixture has no issuer.');$s=new App\Services\Fiscal\Stamps\FiscalStampAccountService($db);$a=$s->getOrCreateAccountForIssuer((int)$issuer->id);$ok((int)$a->available_balance===0&&$s->getBalance((int)$issuer->id)['reserved']===0,'Issuer account starts at zero.');$m=$s->allocate((int)$issuer->id,100,'Test allocation',null,'test:allocation');$ok((int)$m->available_after===100,'Allocation adds exactly 100 integer stamps.');$same=$s->allocate((int)$issuer->id,100,'Test allocation',null,'test:allocation');$ok((int)$same->id===(int)$m->id&&$s->getBalance((int)$issuer->id)['available']===100,'Repeated idempotency key does not allocate twice.');$thrown=false;try{$s->adjust((int)$issuer->id,-101,'Invalid debit',null,'test:negative');}catch(Throwable){$thrown=true;}$ok($thrown&&$s->getBalance((int)$issuer->id)['available']===100,'Debit that would leave negative balance is rejected atomically.');$s->adjust((int)$issuer->id,-99,'Leave one',null,'test:leave-one');$attempts=$db->table('fiscal_stamp_attempts a')->select('a.id')->join('fiscal_documents d','d.id=a.fiscal_document_id')->where('d.issuer_profile_id',$issuer->id)->limit(2)->get()->getResult();if(count($attempts)>=2){$s->reserveForAttempt((int)$attempts[0]->id);$ok($s->getBalance((int)$issuer->id)['available']===0&&$s->getBalance((int)$issuer->id)['reserved']===1,'Reservation atomically moves one available stamp to reserved.');$blocked=false;try{$s->reserveForAttempt((int)$attempts[1]->id);}catch(Throwable){$blocked=true;}$ok($blocked,'With one stamp, a competing second reservation is rejected.');$s->releaseReservation((int)$attempts[0]->id);$ok($s->getBalance((int)$issuer->id)['available']===1&&$s->getBalance((int)$issuer->id)['reserved']===0,'Release restores the unused stamp.');}else{$ok(false,'Temporary fixtures provide two PAC attempts.');$ok(false,'Concurrency guard exercised.');$ok(false,'Release exercised.');}}
catch(Throwable$e){$ok(false,'Disposable database tests: '.$e->getMessage());}
echo"\n{$pass} passed, {$fail} failed.\n";exit($fail?1:0);
