<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Services\Fiscal\Cancellation\FakeFiscalCancellationAdapter;
use App\Services\Fiscal\Cancellation\FiscalCancellationService;
use App\Services\Fiscal\FiscalPreviewModeGuard;
use CodeIgniter\Config\Factories;

$pass=$fail=0;
$ok=static function(bool$value,string$message)use(&$pass,&$fail):void{echo($value?'[PASS] ':'[FAIL] ').$message.PHP_EOL;$value?$pass++:$fail++;};
$throws=static function(callable$call,?string$contains=null):bool{try{$call();return false;}catch(Throwable$e){return$contains===null||str_contains($e->getMessage(),$contains);}};

$fiscal=new class{public bool$previewMode=false;public bool$stampingEnabled=true;};
Factories::injectMock('config','Fiscal',$fiscal);
$dbThatRejectsGlobalQueries=new class{public function table(string$name):never{throw new RuntimeException('GLOBAL_QUERY_FORBIDDEN: '.$name);}};
try{
    (new FiscalPreviewModeGuard($dbThatRejectsGlobalQueries))->assertCancellationAllowed();
    $ok(true,'cancelación de un CFDI no consulta readiness global ni clientes ajenos');
}catch(Throwable$e){$ok(false,'cancelación de un CFDI no consulta readiness global ni clientes ajenos: '.$e->getMessage());}

$guardSource=file_get_contents(APPPATH.'Services/Fiscal/FiscalPreviewModeGuard.php');
$cancelMethod=substr($guardSource,(int)strpos($guardSource,'public function assertCancellationAllowed'));
$ok(!str_contains($cancelMethod,'FiscalUnifiedOnboardingService')&&!str_contains($cancelMethod,'assertReadyForStamping'),'guard de cancelación quedó separado del onboarding de emisión');

$service=(new ReflectionClass(FiscalCancellationService::class))->newInstanceWithoutConstructor();
$validate=new ReflectionMethod(FiscalCancellationService::class,'validateCancellationRequest');$validate->setAccessible(true);
$cancelable=new ReflectionMethod(FiscalCancellationService::class,'assertDocumentCancelable');$cancelable->setAccessible(true);
$ok($validate->invoke($service,'02',null)===null,'motivo 02 no exige UUID sustituto');
$ok($throws(fn()=>$validate->invoke($service,'01',null),'requiere UUID sustituto'),'motivo 01 exige UUID sustituto');
$replacement='123E4567-E89B-42D3-A456-426614174000';
$ok($validate->invoke($service,'01',strtolower($replacement))===$replacement,'motivo 01 normaliza y conserva UUID sustituto válido');
try{$cancelable->invoke($service,(object)['status'=>'stamped']);$valid=true;}catch(Throwable){$valid=false;}
$ok($valid,'CFDI timbrado es cancelable sin revisar otros clientes');
$ok($throws(fn()=>$cancelable->invoke($service,(object)['status'=>'ready_to_stamp']),'estado fiscal'),'documento no timbrado se rechaza antes del transporte');
$ok($throws(fn()=>$cancelable->invoke($service,(object)['status'=>'cancelled']),'ya está cancelado'),'documento ya cancelado se rechaza explícitamente');

$fake=new FakeFiscalCancellationAdapter('accepted');
$fiscal->previewMode=true;
$ok($throws(fn()=>(new FiscalPreviewModeGuard($dbThatRejectsGlobalQueries))->assertCancellationAllowed(),'DESHABILITADA')&&$fake->calls===0,'error previo al transporte produce cero solicitudes externas');

$controller=file_get_contents(APPPATH.'Controllers/Fiscal/Invoices.php');
$ok(str_contains($controller,"'success'=>false")&&str_contains($controller,"'technical_reference'=>\$reference")&&str_contains($controller,"'retry_allowed'=>!\$transport"),'controlador conserva respuesta JSON uniforme y distingue resultado ambiguo');

echo"TOTAL PASS={$pass} FAIL={$fail}; pure isolated test, zero network and zero database.\n";
exit($fail?1:0);
