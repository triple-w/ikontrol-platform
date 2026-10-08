<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/tools/legacy-bootstrap/LegacyBootstrapInstaller.php';

use Ikontrol\LegacyBootstrap\LegacyBootstrapInstaller;

$root=dirname(__DIR__,2);
$manifest=$root.'/resources/upgrade/legacy-bootstrap/manifest.json';
$temp=rtrim(str_replace('\\','/',sys_get_temp_dir()),'/').'/ikontrol_legacy_bootstrap_test_'.bin2hex(random_bytes(6));
if(!mkdir($temp,0775,true)&&!is_dir($temp))throw new RuntimeException('Unable to create isolated fixture root.');
$remove=static function(string$path)use(&$remove,$temp):void{
    $resolved=realpath($path);$safeRoot=realpath($temp);
    if($resolved===false||$safeRoot===false||($resolved!==$safeRoot&&!str_starts_with(str_replace('\\','/',$resolved).'/',str_replace('\\','/',$safeRoot).'/')))return;
    if(is_dir($resolved)){foreach(array_diff(scandir($resolved)?:[],['.','..'])as$item)$remove($resolved.DIRECTORY_SEPARATOR.$item);@rmdir($resolved);}elseif(is_file($resolved)){@unlink($resolved);}
};
register_shutdown_function(static function()use($remove,$temp):void{$remove($temp);});
$target=static function(string$name)use($temp):string{
    $path=$temp.'/'.$name;mkdir($path.'/app/Config',0775,true);mkdir($path.'/files/system',0775,true);mkdir($path.'/files/timeline_files',0775,true);mkdir($path.'/.git',0775,true);
    file_put_contents($path.'/spark','legacy-spark');file_put_contents($path.'/app/Config/Logger.php','legacy-logger');file_put_contents($path.'/files/system/customer.dat','customer-system-file');file_put_contents($path.'/files/timeline_files/customer.dat','customer-timeline-file');file_put_contents($path.'/.git/config','legacy-remote-config');
    return$path;
};
$pass=0;$ok=static function(bool$value,string$message)use(&$pass):void{if(!$value)throw new RuntimeException('[FAIL] '.$message);$pass++;echo'[PASS] '.$message.PHP_EOL;};

$decoded=json_decode((string)file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR);
$paths=array_column($decoded['files'],'path');
$required=['app/Config/Version.php','app/Commands/IkontrolVersion.php','app/Commands/IkontrolDatabaseCheck.php','app/Commands/IkontrolBaselineCheck.php','app/Commands/IkontrolAdoptBaseline.php','app/Commands/IkontrolUpgradePlan.php','app/Commands/IkontrolUpgrade.php','app/Services/Upgrade/LegacyBaselineAdoptionService.php','app/Services/Upgrade/InstanceUpgradeService.php'];
$ok(array_diff($required,$paths)===[],'manifest contiene los seis comandos y servicios mínimos de adopción/versionado');
$hashes=true;foreach($decoded['files']as$entry){$file=$root.'/'.$entry['path'];$hashes=$hashes&&is_file($file)&&hash_equals($entry['sha256'],hash_file('sha256',$file));}
$ok($hashes,'checksums del payload coinciden con las fuentes canónicas');

$clean=$target('clean');$installer=new LegacyBootstrapInstaller($root,$clean,$manifest);$dry=$installer->inspect();
$ok($dry['status']==='READY_TO_INSTALL'&&$dry['writes']===0&&!file_exists($clean.'/app/Commands/IkontrolVersion.php'),'dry-run calcula archivos sin escribir');
$beforeProtected=[hash_file('sha256',$clean.'/app/Config/Logger.php'),hash_file('sha256',$clean.'/files/system/customer.dat'),hash_file('sha256',$clean.'/files/timeline_files/customer.dat'),hash_file('sha256',$clean.'/.git/config')];
$installed=$installer->install();
$ok($installed['status']==='INSTALLED'&&$installed['writes']===count($decoded['files']),'execute instala exclusivamente el payload aditivo completo');
$ok(is_file($installed['receipt'])&&count(json_decode((string)file_get_contents($installed['receipt']),true)['created_files'])===count($decoded['files']),'recibo registra exactamente los archivos creados');
$afterProtected=[hash_file('sha256',$clean.'/app/Config/Logger.php'),hash_file('sha256',$clean.'/files/system/customer.dat'),hash_file('sha256',$clean.'/files/timeline_files/customer.dat'),hash_file('sha256',$clean.'/.git/config')];
$ok($beforeProtected===$afterProtected,'Logger, archivos operativos y configuración Git permanecen byte a byte');
$again=$installer->install();
$ok($again['status']==='INSTALLED'&&$again['writes']===0,'segunda ejecución detecta bootstrap instalado y es idempotente');
$rollback=$installer->rollbackPlan();
$ok($rollback['safe']&&count($rollback['removable'])===count($decoded['files'])&&$rollback['modified']===[],'rollback-plan enumera sólo archivos creados que conservan su checksum');
file_put_contents($clean.'/app/Commands/IkontrolVersion.php',"<?php // changed after bootstrap\n");$rollback=$installer->rollbackPlan();
$ok(!$rollback['safe']&&$rollback['status']==='MANUAL_REVIEW_REQUIRED'&&count($rollback['modified'])===1,'rollback-plan bloquea eliminación de un archivo modificado después del bootstrap');

$conflict=$target('conflict');file_put_contents($conflict.'/app/Config/Version.php','legacy-version-conflict');$conflicting=new LegacyBootstrapInstaller($root,$conflict,$manifest);$inspection=$conflicting->inspect();
$ok($inspection['status']==='CONFLICT'&&count($inspection['conflicts'])===1,'archivo existente diferente se reporta como conflicto');
$blocked=false;try{$conflicting->install();}catch(RuntimeException){$blocked=true;}
$ok($blocked&&!file_exists($conflict.'/app/Commands/IkontrolVersion.php'),'un conflicto bloquea todas las escrituras antes de copiar');

$installerSource=file_get_contents($root.'/tools/legacy-bootstrap/LegacyBootstrapInstaller.php');
$ok(!str_contains($installerSource,'migrate')&&!str_contains($installerSource,'git remote')&&!str_contains($installerSource,'DELETE FROM')&&!str_contains($installerSource,'TRUNCATE'),'instalador no contiene migraciones, remotes ni DML destructivo');

echo"passed={$pass}\n";
