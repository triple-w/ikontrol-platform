<?php
declare(strict_types=1);

foreach(['fiscal.enabled'=>'false','fiscal.stampingEnabled'=>'false','fiscal.allowRealPac'=>'false','fiscal.runtimeMode'=>'automated_test','fiscal.pacAdapter'=>'fake','fiscal.environment'=>'development']as$key=>$value){putenv($key.'='.$value);$_ENV[$key]=$_SERVER[$key]=$value;}
require dirname(__DIR__).'/bootstrap.php';

set_exception_handler(static function(Throwable$e):void{fwrite(STDERR,'[ERROR] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine().PHP_EOL);exit(1);});

use App\Services\Instance\InstanceVersionService;
use App\Services\Upgrade\Fiscal116CompatibilityService;
use App\Services\Upgrade\InstanceUpgradeService;
use App\Services\Upgrade\Legacy\PhysicalSchemaInspector;
use Config\Database;
use Config\Version;

$local=config(Database::class)->default;if(!in_array((string)$local['hostname'],['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Unsafe test target: local MySQL fixture server required.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$admin=new mysqli($local['hostname'],$local['username'],$local['password'],'',(int)$local['port']);$names=['ikontrol_test_116_'.bin2hex(random_bytes(5)),'ikontrol_test_116_fail_'.bin2hex(random_bytes(5))];foreach($names as$name)$admin->query('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');register_shutdown_function(static function()use($admin,$names):void{foreach($names as$name)$admin->query('DROP DATABASE IF EXISTS `'.$name.'`');$admin->close();});
$connect=static function(string$name,string$prefix)use($local){$base=is_array($local)?$local:get_object_vars($local);return Database::connect(array_replace($base,['DSN'=>'','database'=>$name,'DBPrefix'=>$prefix,'pConnect'=>false,'DBDebug'=>true,'failover'=>[]]),false);};
$fixture=static function($db,string$p):void{
 $db->query("CREATE TABLE {$p}app_schema_versions(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,version VARCHAR(100) UNIQUE,description VARCHAR(255),applied_at DATETIME)");
 $db->query("CREATE TABLE {$p}fiscal_drafts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY)");
 $db->query("CREATE TABLE {$p}fiscal_documents(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,issuer_profile_id BIGINT UNSIGNED,status VARCHAR(30),environment VARCHAR(20),deleted TINYINT DEFAULT 0,uuid_marker VARCHAR(40))");
 $db->query("CREATE TABLE {$p}fiscal_document_stamps(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,fiscal_document_id BIGINT UNSIGNED,uuid CHAR(36),stamped_xml_artifact_id BIGINT UNSIGNED NULL)");
 $db->query("CREATE TABLE {$p}fiscal_document_artifacts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,fiscal_document_id BIGINT UNSIGNED,artifact_type VARCHAR(30),storage_path VARCHAR(255),sha256 CHAR(64),superseded_at DATETIME NULL)");
 $db->query("CREATE TABLE {$p}fiscal_document_issuers(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,fiscal_document_id BIGINT UNSIGNED,rfc VARCHAR(20))");
 $db->query("CREATE TABLE {$p}fiscal_document_receivers(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,fiscal_document_id BIGINT UNSIGNED,rfc VARCHAR(20))");
 $db->query("CREATE TABLE {$p}fiscal_cancellation_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,fiscal_document_id BIGINT UNSIGNED,environment VARCHAR(20),status VARCHAR(30))");
 $db->query("CREATE TABLE {$p}fiscal_stamp_movements(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,idempotency_key VARCHAR(191))");
 $db->query("CREATE TABLE {$p}fiscal_profiles(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED,profile_type VARCHAR(20),rfc VARCHAR(20),environment VARCHAR(20),status VARCHAR(20),deleted TINYINT DEFAULT 0)");
 $db->query("CREATE TABLE {$p}fiscal_series(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,issuer_profile_id BIGINT UNSIGNED,document_type VARCHAR(20),series VARCHAR(25),environment VARCHAR(20),initial_folio BIGINT UNSIGNED,current_folio BIGINT UNSIGNED,is_default TINYINT,is_active TINYINT,deleted TINYINT,updated_at DATETIME NULL)");
 $db->query("CREATE TABLE {$p}fiscal_stamp_accounts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,issuer_profile_id BIGINT UNSIGNED,environment VARCHAR(20),available_balance INT,reserved_balance INT,status VARCHAR(20))");
 $db->query("CREATE TABLE {$p}payment_methods(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,title VARCHAR(100),deleted TINYINT,available_on_invoice TINYINT)");
 $db->query("CREATE TABLE {$p}fiscal_payment_method_mappings(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,payment_method_id BIGINT UNSIGNED,sat_payment_form_code VARCHAR(3),is_active TINYINT)");
 $db->query("CREATE TABLE {$p}sat_payment_forms(code VARCHAR(3) PRIMARY KEY,name VARCHAR(100),is_active TINYINT)");
 $db->query("CREATE TABLE {$p}item_fiscal_settings(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id BIGINT UNSIGNED,commercial_unit VARCHAR(120),fiscal_description TEXT,sat_product_service_key_id BIGINT UNSIGNED,sat_unit_key_id BIGINT UNSIGNED,tax_object_code_id BIGINT UNSIGNED,status VARCHAR(20),deleted TINYINT,is_default TINYINT)");
 $db->query("CREATE TABLE {$p}item_fiscal_taxes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_fiscal_setting_id BIGINT UNSIGNED,tax_id BIGINT UNSIGNED,is_active TINYINT)");
 $db->query("ALTER TABLE {$p}fiscal_document_stamps ADD UNIQUE KEY uq_stamp_document(fiscal_document_id),ADD UNIQUE KEY uq_stamp_uuid(uuid)");
 $db->query("ALTER TABLE {$p}fiscal_document_artifacts ADD KEY idx_fiscal_artifact_active(fiscal_document_id,artifact_type,superseded_at)");
 $db->query("ALTER TABLE {$p}fiscal_series ADD UNIQUE KEY uq_fiscal_series_issuer_type_series(issuer_profile_id,document_type,series)");
 $db->query("ALTER TABLE {$p}fiscal_stamp_accounts ADD UNIQUE KEY uq_stamp_account_issuer_environment(issuer_profile_id,environment)");
 $db->query("ALTER TABLE {$p}fiscal_stamp_movements ADD UNIQUE KEY uq_stamp_movement_idempotency(idempotency_key)");
 $db->query("ALTER TABLE {$p}fiscal_payment_method_mappings ADD UNIQUE KEY uq_fiscal_payment_method_mapping(payment_method_id)");
 $db->table('app_schema_versions')->insert(['version'=>'ikontrol-1.1.5','description'=>'fixture','applied_at'=>'2026-10-08 00:00:00']);
 $db->table('fiscal_documents')->insert(['issuer_profile_id'=>1,'status'=>'stamped','environment'=>'production','deleted'=>0,'uuid_marker'=>'immutable-document']);$db->table('fiscal_document_stamps')->insert(['fiscal_document_id'=>1,'uuid'=>'123E4567-E89B-42D3-A456-426614174000','stamped_xml_artifact_id'=>null]);
 $db->table('fiscal_series')->insert(['issuer_profile_id'=>1,'document_type'=>'ingreso','series'=>'F','environment'=>'production','initial_folio'=>1,'current_folio'=>0,'is_default'=>1,'is_active'=>1,'deleted'=>0]);$db->table('fiscal_stamp_accounts')->insert(['issuer_profile_id'=>1,'environment'=>'production','available_balance'=>73,'reserved_balance'=>2,'status'=>'active']);$db->table('fiscal_payment_method_mappings')->insert(['payment_method_id'=>1,'sat_payment_form_code'=>'03','is_active'=>1]);
};
$pass=$fail=0;$ok=static function(bool$v,string$m)use(&$pass,&$fail):void{echo($v?'[PASS] ':'[FAIL] ').$m.PHP_EOL;$v?$pass++:$fail++;};
$db=$connect($names[0],'u116_');$fixture($db,'u116_');$before=['document'=>$db->table('fiscal_documents')->select('id,issuer_profile_id,status,environment,deleted,uuid_marker')->get()->getResultArray(),'stamp'=>$db->table('fiscal_document_stamps')->get()->getResultArray(),'wallet'=>$db->table('fiscal_stamp_accounts')->get()->getResultArray()];$upgrade=new InstanceUpgradeService($db);$plan=$upgrade->plan('1.1.6');
$preUpgrade=(new Fiscal116CompatibilityService($db,dirname(__DIR__,2)))->inspect();
$ok(Version::VERSION==='1.1.6'&&$plan['compatible']&&count($plan['packages'])===1,'versión canónica y ruta dirigida 1.1.5 → 1.1.6');$ok($plan['packages'][0]['steps']===['fiscal_116_schema','fiscal_116_verify','record_version'],'record_version ocurre sólo después de esquema y verificación');
$ok($preUpgrade['status']==='UPGRADE_REQUIRED','preflight distingue una 1.1.5 compatible que sólo requiere el esquema aditivo');
$result=$upgrade->execute('1.1.6');$ok($result['current_version']==='1.1.6'&&(new InstanceVersionService($db))->current()==='1.1.6','upgrade registra 1.1.6 únicamente tras verificar');
$schema=new PhysicalSchemaInspector($db,(string)$db->DBPrefix);foreach(['fiscal_environment_change_requests','fiscal_sat_status_queries','fiscal_sat_status_artifacts']as$table)$ok($schema->tableExists($table)&&$db->table($table)->countAllResults()===0,"{$table} existe con prefijo y sin filas inventadas");
foreach(['fiscal_drafts','fiscal_documents']as$table)foreach(['global_periodicity','global_months','global_year']as$column)$ok($schema->columnExists($table,$column),"{$table}.{$column} existe tras el upgrade dirigido");
$steps=$db->table('instance_upgrade_steps')->orderBy('id')->get()->getResultArray();$ok(array_column($steps,'step')===['fiscal_116_schema','fiscal_116_verify','record_version']&&count(array_filter($steps,fn($r)=>$r['status']==='completed'))===3,'evidencia técnica conserva orden y resultado de steps');
$after=['document'=>$db->table('fiscal_documents')->select('id,issuer_profile_id,status,environment,deleted,uuid_marker')->get()->getResultArray(),'stamp'=>$db->table('fiscal_document_stamps')->get()->getResultArray(),'wallet'=>$db->table('fiscal_stamp_accounts')->get()->getResultArray()];$ok($before===$after,'upgrade no modifica documentos, UUID ni wallet existente');
$again=$upgrade->execute('1.1.6');$ok($again['completed']===[]&&$db->table('app_schema_versions')->where('version','ikontrol-1.1.6')->countAllResults()===1,'reejecución controlada es idempotente');
$readiness=(new Fiscal116CompatibilityService($db,dirname(__DIR__,2)))->inspect();$ok($readiness['status']==='READY'&&preg_match('/^[a-f0-9]{64}$/',$readiness['schema_fingerprint']['sha256'])===1,'diagnóstico read-only entrega fingerprint y estado READY post-upgrade');

$bad=$connect($names[1],'bad116_');$fixture($bad,'bad116_');$bad->query('CREATE TABLE bad116_fiscal_sat_status_queries(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY)');$failed=false;try{(new InstanceUpgradeService($bad))->execute('1.1.6');}catch(Throwable$e){$failed=str_contains($e->getMessage(),'FISCAL_116_SCHEMA_INCOMPLETE');}$ok($failed,'tabla parcial o personalizada bloquea el upgrade');$ok($bad->table('app_schema_versions')->where('version','ikontrol-1.1.6')->countAllResults()===0,'fallo de esquema nunca marca 1.1.6 como instalada');$run=$bad->table('instance_upgrade_runs')->orderBy('id','DESC')->get(1)->getRowArray();$step=$bad->table('instance_upgrade_steps')->where('step','fiscal_116_schema')->get(1)->getRowArray();$ok($run['status']==='failed'&&$step['status']==='failed'&&str_contains((string)$step['result_json'],'FISCAL_116_SCHEMA_INCOMPLETE'),'fallo conserva evidencia reanudable y diagnosticable');
$source=file_get_contents(APPPATH.'Services/Upgrade/InstanceUpgradeService.php');$ok(!str_contains($source,'spark migrate')&&!str_contains($source,'migrate('),'upgrade dirigido no invoca runner global');
echo"TOTAL PASS={$pass} FAIL={$fail}. Two random local MySQL fixture schemas dropped at shutdown; no PAC adapter.\n";exit($fail?1:0);
