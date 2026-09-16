<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use App\Services\Database\ExplicitConnectionSeederOrchestrator;
use Config\Database;

$passed=0;$assert=static function(bool $ok,string $message)use(&$passed):void{if(!$ok)throw new RuntimeException('[FAIL] '.$message);$passed++;echo '[PASS] '.$message.PHP_EOL;};
$admin=Database::connect('default',false);$sourceClean=Database::connect('clean_build',false);
if((string)$admin->query('SELECT DATABASE() name')->getRow()->name!=='ikontrol_new'||(string)$sourceClean->query('SELECT DATABASE() name')->getRow()->name!=='ikontrol20_clean')throw new RuntimeException('Verified source connections are required.');
$suffix=bin2hex(random_bytes(4));$defaultName='ikontrol_seed_default_'.$suffix;$cleanName='ikontrol_seed_clean_'.$suffix;
$safe=static fn(string$n):bool=>(bool)preg_match('/^ikontrol_seed_(default|clean)_[a-f0-9]{8}$/',$n);if(!$safe($defaultName)||!$safe($cleanName))throw new RuntimeException('Unsafe database name.');$quote=static fn(string$n):string=>'`'.$n.'`';
$seeded=['sat_tax_codes','sat_tax_factor_types','sat_tax_regimes','sat_cfdi_uses','sat_product_service_keys','sat_unit_keys','sat_tax_object_codes'];
$migrationCatalogs=['sat_payment_forms','sat_payment_methods','sat_currencies'];$all=[...$seeded,...$migrationCatalogs];
$digest=static function($db,array$tables):string{$state=[];foreach($tables as$t)$state[$t]=$db->table($t)->orderBy('code')->get()->getResultArray();return hash('sha256',json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));};
try{
 foreach([$defaultName,$cleanName]as$n)$admin->query('CREATE DATABASE '.$quote($n).' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
 $config=config('Database');$dc=$config->default;$dc['database']=$defaultName;$dc['DBPrefix']='ikontrol_';$cc=$config->default;$cc['database']=$cleanName;$cc['DBPrefix']='ikontrol_';$default=Database::connect($dc,false);$clean=Database::connect($cc,false);
 foreach($all as$t){$physical=$sourceClean->getPrefix().$t;$create=$sourceClean->query('SHOW CREATE TABLE '.$sourceClean->protectIdentifiers($physical))->getRowArray();$sql=(string)array_values($create)[1];$default->query($sql);$clean->query($sql);}
 foreach($seeded as$t){$rows=$admin->table($t)->get()->getResultArray();if($rows!==[])$default->table($t)->insertBatch($rows);}
 foreach($migrationCatalogs as$t){$rows=$sourceClean->table($t)->get()->getResultArray();if($rows!==[]){$default->table($t)->insertBatch($rows);$clean->table($t)->insertBatch($rows);}}
 $before=$digest($default,$all);$timestamps=[];foreach($seeded as$t)$timestamps[$t]=$default->table($t)->select('code, updated_at')->orderBy('code')->get()->getResultArray();
 $orchestrator=new ExplicitConnectionSeederOrchestrator();$executions=$orchestrator->runSatCatalogs($clean,$cleanName);$assert(count($executions)===7,'All seven leaf SAT seeders executed.');$ids=array_unique(array_column($executions,'connection_id'));$assert(count($ids)===1&&reset($ids)===spl_object_id($clean),'Every child retained the exact clean connection object.');
 foreach($executions as$execution)$assert($execution['database']===$cleanName,'Child SELECT DATABASE() remained on clean: '.$execution['seeder']);
 foreach($all as$t)$assert($clean->table($t)->countAllResults()>0,"Clean catalog {$t} is populated.");
 $assert($digest($default,$all)===$before,'Complete default SAT digest remained identical.');foreach($seeded as$t)$assert($default->table($t)->select('code, updated_at')->orderBy('code')->get()->getResultArray()===$timestamps[$t],"Default timestamps remained identical for {$t}.");
 $assert(!$default->tableExists('migrations')&&!$clean->tableExists('migrations'),'No migration repository was created in either fixture database.');$negative=$digest($default,$all);
 try{$orchestrator->runSatCatalogs($default,$cleanName);throw new RuntimeException('Negative case unexpectedly succeeded.');}catch(RuntimeException$e){$assert(str_contains($e->getMessage(),'mismatch'),'Negative default-target attempt failed before writes.');}
 $assert($digest($default,$all)===$negative,'Negative attempt left default unchanged.');echo "passed={$passed}\n";
}finally{foreach([$defaultName,$cleanName]as$n)if($safe($n))$admin->query('DROP DATABASE IF EXISTS '.$quote($n));}
