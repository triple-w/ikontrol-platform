<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);$passed=0;
$ok=static function(bool$condition,string$message)use(&$passed):void{if(!$condition)throw new RuntimeException('[FAIL] '.$message);$passed++;echo'[PASS] '.$message.PHP_EOL;};
$fiscal=(string)file_get_contents($root.'/app/Config/Fiscal.php');
$factory=(string)file_get_contents($root.'/app/Services/Fiscal/Pac/FiscalPacAdapterFactory.php');
$context=(string)file_get_contents($root.'/app/Services/Fiscal/FiscalRuntimeContext.php');
$cancel=(string)file_get_contents($root.'/app/Services/Fiscal/Cancellation/FiscalCancellationService.php');
$install=(string)file_get_contents($root.'/app/Services/Database/CanonicalCleanInstallService.php');
$layout=(string)file_get_contents($root.'/app/Views/layout/index.php').(string)file_get_contents($root.'/app/Views/signin/index.php');
$ok(str_contains($fiscal,"env('IKONTROL_RC1_LAB', false)")&&str_contains($fiscal,"ENVIRONMENT !== 'development'"),'RC1 mode is explicit and development-only');
$ok(str_contains($fiscal,"env('IKONTROL_RC1_SANDBOX_TRANSPORT', false)")&&str_contains($fiscal,"\$this->pacAdapter === 'timbradorxpress'")&&str_contains($fiscal,"TIMBRADORXPRESS_PRODUCTION_ENABLED"),'RC1 sandbox requires explicit transport opt-in and keeps production disabled');
$ok(str_contains($fiscal,'RC1 functional acceptance must use the real TimbradorXpress sandbox')&&preg_match('/allowsLabFakePac\(\): bool\s*\{[^}]*return false;/s',$fiscal)===1,'RC1 browser and integration runtime cannot use fake PAC as acceptance evidence');
$ok(str_contains($factory,'allowsLabFakePac()')&&str_contains($context,'allowsLabFakePac'),'stamping, SAT status and runtime diagnostics share the lab guard');
$ok(str_contains($cancel,'effectiveAdapter()')&&str_contains($cancel,'FakeFiscalCancellationAdapter'),'cancellation uses the same guarded fake laboratory transport');
$ok(str_contains($install,'ExplicitConnectionSeederOrchestrator')&&str_contains($install,'FiscalInstallationInitializerService')&&!str_contains($install,'IKONTROL_RC1_LAB'),'clean installation uses the canonical catalog initializer and has no RC1 synthetic fallback');
$ok(substr_count($layout,'ENTORNO DE PRUEBAS: SIN VALIDEZ FISCAL')===2,'login and authenticated layouts carry the RC1 warning');
echo'TOTAL PASS='.$passed.' FAIL=0. Static source only; no database or network used.'.PHP_EOL;
