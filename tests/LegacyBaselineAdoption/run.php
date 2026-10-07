<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\Instance\InstanceVersionService;
use App\Services\Upgrade\InstanceUpgradeService;
use App\Services\Upgrade\LegacyBaselineAdoptionService;
use Config\Database;

$local = config(Database::class)->default;
if (! in_array((string)$local['hostname'], ['localhost','127.0.0.1','::1'], true)) {
    throw new RuntimeException('Local fixture server required.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli($local['hostname'],$local['username'],$local['password'],'',(int)$local['port']);
$owned = 'ikontrol_test_legacy_adoption_' . bin2hex(random_bytes(5));
$admin->query('CREATE DATABASE `' . $owned . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$base = is_array($local) ? $local : get_object_vars($local);
$connect = static fn(string $prefix) => Database::connect(array_replace($base,[
    'DSN'=>'','database'=>$owned,'DBPrefix'=>$prefix,'pConnect'=>false,'DBDebug'=>true,'failover'=>[],
]),false);
register_shutdown_function(static function()use($admin,$owned):void{$admin->query('DROP DATABASE IF EXISTS `'.str_replace('`','``',$owned).'`');$admin->close();});

$pass=0;
$ok=static function(bool$value,string$message)use(&$pass):void{if(!$value)throw new RuntimeException('[FAIL] '.$message);$pass++;echo'[PASS] '.$message.PHP_EOL;};
$core=['settings','users','roles','clients','items','estimates','estimate_items','invoices','invoice_items','invoice_payments','payment_methods','taxes','company'];
$fixture=static function($db,array$options=[])use($core):void{
    $omit=$options['omit']??null;
    foreach($core as$table){
        if($table===$omit)continue;
        $physical=$db->protectIdentifiers($db->prefixTable($table));
        if($table==='users'){
            $db->query("CREATE TABLE {$physical} (id INT PRIMARY KEY,user_type VARCHAR(20),is_admin TINYINT,status VARCHAR(20),disable_login TINYINT,deleted TINYINT)");
        }else{
            $db->query("CREATE TABLE {$physical} (id INT PRIMARY KEY)");
        }
    }
    $versions=$db->protectIdentifiers($db->prefixTable('app_schema_versions'));
    $db->query("CREATE TABLE {$versions} (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,version VARCHAR(100) NOT NULL UNIQUE,description VARCHAR(255) NOT NULL,applied_at DATETIME NOT NULL)");
    if(($options['baseline']??true)===true)$db->table('app_schema_versions')->insert(['version'=>'rise-administrative-baseline-1','description'=>'fixture','applied_at'=>'2026-10-07 00:00:00']);
    if(isset($options['version']))$db->table('app_schema_versions')->insert(['version'=>'ikontrol-'.$options['version'],'description'=>'fixture','applied_at'=>'2026-10-07 00:00:00']);
    if(($options['admin']??true)===true&&$omit!=='users')$db->table('users')->insert(['id'=>1,'user_type'=>'staff','is_admin'=>1,'status'=>'active','disable_login'=>0,'deleted'=>0]);
};

$valid=$connect('valid_');$fixture($valid);
$service=new LegacyBaselineAdoptionService($valid);
$dry=$service->inspect();
$ok($dry['compatible']&&$dry['status']==='READY_TO_ADOPT'&&!$dry['already_adopted'],'legacy válida pasa dry-run');
$ok((new InstanceVersionService($valid))->current()===null,'dry-run no registra versión ni modifica la instancia');
$executed=$service->adopt();
$ok($executed['written']&&$executed['already_adopted']&&(new InstanceVersionService($valid))->current()==='1.0.0','execute registra exclusivamente iKontrol 1.0.0');
$countBefore=$valid->table('app_schema_versions')->countAllResults();$again=$service->adopt();
$ok(!$again['written']&&$again['status']==='ALREADY_ADOPTED'&&$valid->table('app_schema_versions')->countAllResults()===$countBefore,'segunda ejecución es idempotente');
$plan=(new InstanceUpgradeService($valid))->plan('1.1.4');
$paths=array_map(static fn(array$p):string=>$p['from'].'->'.$p['to'],$plan['packages']);
$ok($plan['compatible']&&$paths===['1.0.0->1.1.0','1.1.0->1.1.1','1.1.1->1.1.2','1.1.2->1.1.3','1.1.3->1.1.4'],'adopción habilita el plan completo 1.0.0 a 1.1.4');
$ok(!$valid->tableExists('financial_accounts')&&!$valid->tableExists('sat_catalog_installations'),'adopción no exige ni crea cuentas financieras o catálogos SAT');

$incomplete=$connect('incomplete_');$fixture($incomplete,['omit'=>'invoice_items']);$result=(new LegacyBaselineAdoptionService($incomplete))->inspect();
$ok(!$result['compatible']&&in_array('invoice_items',$result['checks']['missing_core_tables'],true),'core administrativo incompleto bloquea adopción');
$noAdmin=$connect('noadmin_');$fixture($noAdmin,['admin'=>false]);$result=(new LegacyBaselineAdoptionService($noAdmin))->inspect();
$ok(!$result['compatible']&&$result['checks']['active_staff_admins']===0,'ausencia de administrador staff activo bloquea adopción');
$versioned=$connect('versioned_');$fixture($versioned,['version'=>'1.1.2']);$result=(new LegacyBaselineAdoptionService($versioned))->inspect();
$codes=array_column($result['blockers'],'code');
$ok(!$result['compatible']&&in_array('INSTANCE_ALREADY_VERSIONED',$codes,true),'instancia ya versionada no puede readoptarse como 1.0.0');
$noBaseline=$connect('nobaseline_');$fixture($noBaseline,['baseline'=>false]);$result=(new LegacyBaselineAdoptionService($noBaseline))->inspect();
$ok(!$result['compatible']&&$result['checks']['rise_baseline']==='MISSING','baseline administrativo RISE ausente bloquea adopción');

$unknownVersion=$connect('unknown_');$fixture($unknownVersion);$unknownVersion->table('app_schema_versions')->insert(['version'=>'ikontrol-release-candidate','description'=>'fixture','applied_at'=>'2026-10-07 00:00:00']);$result=(new LegacyBaselineAdoptionService($unknownVersion))->inspect();
$ok(!$result['compatible']&&in_array('UNKNOWN_IKONTROL_VERSION_EVIDENCE',array_column($result['blockers'],'code'),true),'evidencia iKontrol no reconocida bloquea una adopción ambigua');

$evidence=$connect('evidence_');$fixture($evidence);$evidence->query('CREATE TABLE evidence_instance_upgrade_runs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,from_version VARCHAR(40),to_version VARCHAR(40),release_id VARCHAR(150),status VARCHAR(20))');$evidence->table('instance_upgrade_runs')->insert(['from_version'=>'1.0.0','to_version'=>'1.1.0','release_id'=>'fixture','status'=>'completed']);$result=(new LegacyBaselineAdoptionService($evidence))->inspect();
$ok(!$result['compatible']&&$result['checks']['directed_upgrade_evidence']!==[],'evidencia de upgrade posterior bloquea adopción aunque falte el registro de versión');

$command=file_get_contents(dirname(__DIR__,2).'/app/Commands/IkontrolAdoptBaseline.php');
$ok(str_contains($command,"protected \$name = 'ikontrol:adopt-baseline'")&&str_contains($command,"'--execute'")&&str_contains($command,"'--yes'"),'comando expone dry-run y ejecución confirmada');

echo "passed={$pass}\n";
