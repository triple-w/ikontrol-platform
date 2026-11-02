<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$pass=$fail=0;
$ok=static function(bool$value,string$label)use(&$pass,&$fail):void{echo($value?'[PASS] ':'[FAIL] ').$label.PHP_EOL;$value?$pass++:$fail++;};
$json=static function(string$path)use($root):array{$data=json_decode((string)file_get_contents($root.DIRECTORY_SEPARATOR.$path),true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Invalid JSON: '.$path);return$data;};

$version=(string)file_get_contents($root.'/app/Config/Version.php');
$manifest=$json('updates/1.1.6/manifest.json');
$spec=$json('tools/release/releases/1.1.6.json');
$upgrade=(string)file_get_contents($root.'/app/Services/Upgrade/InstanceUpgradeService.php');
$builder=(string)file_get_contents($root.'/app/Services/Release/ReleaseArtifactBuilder.php');

$ok(str_contains($version,"VERSION = '1.1.6'")&&str_contains($version,"RELEASE_REF = 'v1.1.6'"),'Canonical version and release ref are 1.1.6.');
$ok(($manifest['version']??null)==='1.1.6'&&($manifest['from_versions']??[])===['1.1.5']&&($manifest['requires_backup']??false)===true,'Logical manifest supports only 1.1.5 and requires backup.');
$ids=array_column($manifest['migrations']??[],'id');
$expected=['2026-10-22-120000_CreateFiscalEnvironmentChangeRequests','2026-10-22-130000_CreateFiscalSatStatusQueries','2026-10-22-140000_AddFiscalGlobalInformation'];
$ok($ids===$expected&&!in_array(false,array_column($manifest['migrations'],'required'),true),'All three additive migrations are required and ordered.');
$allExist=true;foreach($ids as$id)$allExist=$allExist&&is_file($root.'/app/Database/Migrations/'.$id.'.php');
$ok($allExist,'Every manifest migration has a migration source file.');
$ok(($manifest['health_checks']??[])===['ikontrol:database-check','ikontrol:baseline-check','ikontrol:fiscal-116-readiness'],'Post-install health checks include fiscal 1.1.6 readiness.');
$ok(($spec['release_id']??null)==='ikontrol-1.1.6-fiscal-unification'&&($spec['release_tag']??null)==='v1.1.6','Builder spec identifies the intended release and tag.');
$source=$spec['source_refs']['1.1.5']??'';
$ok($source==='3c4a65d00d833743378ce526da7e5891da7509f9','Builder source ref is the validated 1.1.5 commit.');
$ok(str_contains($upgrade,"'1.1.5' =>")&&str_contains($upgrade,"'steps' => ['fiscal_116_schema', 'fiscal_116_verify', 'record_version']"),'Directed upgrade verifies schema before recording 1.1.6.');
$ok(str_contains($upgrade,'FISCAL_116_PREREQUISITE_INCOMPATIBLE')&&!str_contains($upgrade,'spark migrate'),'Directed upgrade blocks incompatible prerequisites and never invokes global migrate.');
$ok(str_contains($builder,'RELEASE_REQUIRED_MIGRATION_MISSING'),'Builder rejects a manifest whose required migration is absent from the tagged tree.');
$ok(is_file($root.'/app/Commands/IkontrolFiscal116Readiness.php')&&is_file($root.'/app/Services/Upgrade/Fiscal116CompatibilityService.php'),'Read-only per-instance readiness command and service are present.');

echo "TOTAL PASS={$pass} FAIL={$fail}. Static files only; no database or network.\n";
exit($fail?1:0);
