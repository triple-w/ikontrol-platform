<?php
declare(strict_types=1);

foreach(['fiscal.enabled'=>'false','fiscal.stampingEnabled'=>'false','fiscal.allowRealPac'=>'false','fiscal.runtimeMode'=>'automated_test','fiscal.pacAdapter'=>'fake'] as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$_SERVER[$key]=$value;}
require dirname(__DIR__).'/bootstrap.php';

use App\Services\Upgrade\FiscalDraftDocumentSchemaUpgradeService;
use Config\Database;

$pass=$fail=0;
$ok=static function(bool $value,string $message)use(&$pass,&$fail):void{echo($value?'[PASS] ':'[FAIL] ').$message.PHP_EOL;$value?$pass++:$fail++;};
$configured=config(Database::class)->default;
$local=$configured;
if(trim((string)($local['hostname']??''))===''){
    $local=array_replace($local,['hostname'=>'127.0.0.1','username'=>(string)(getenv('IKONTROL_TEST_MYSQL_USER')?:'root'),'password'=>(string)(getenv('IKONTROL_TEST_MYSQL_PASSWORD')?:''),'port'=>(int)(getenv('IKONTROL_TEST_MYSQL_PORT')?:3306),'DBDriver'=>'MySQLi']);
}
if(!in_array((string)$local['hostname'],['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Unsafe test target: local MySQL/MariaDB is required.');
$name='ikontrol_test_draft_docs_'.bin2hex(random_bytes(5));
if(!str_starts_with($name,'ikontrol_test_draft_docs_'))throw new RuntimeException('Unsafe fixture schema name.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$admin=new mysqli($local['hostname'],$local['username'],$local['password'],'',(int)$local['port']);
$admin->query('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
register_shutdown_function(static function()use($admin,$name):void{$admin->query('DROP DATABASE IF EXISTS `'.$name.'`');$admin->close();});
$base=is_array($local)?$local:get_object_vars($local);
$db=Database::connect(array_replace($base,['DSN'=>'','database'=>$name,'DBPrefix'=>'fd_','pConnect'=>false,'DBDebug'=>true,'failover'=>[]]),false);
$p='fd_';
$db->query("CREATE TABLE {$p}fiscal_drafts(id BIGINT UNSIGNED PRIMARY KEY,fiscal_document_id BIGINT UNSIGNED NULL,status VARCHAR(30),updated_by BIGINT UNSIGNED NULL,updated_at DATETIME NULL)");
$db->query("CREATE TABLE {$p}fiscal_documents(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source_draft_id BIGINT UNSIGNED NULL,status VARCHAR(40) NOT NULL,deleted TINYINT NOT NULL DEFAULT 0,marker VARCHAR(40),UNIQUE KEY uq_fiscal_document_source_draft(source_draft_id)) ENGINE=InnoDB");
$db->query("CREATE TABLE {$p}fiscal_stamp_attempts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,fiscal_document_id BIGINT UNSIGNED,status VARCHAR(40),requires_reconciliation TINYINT DEFAULT 0,uuid CHAR(36) NULL)");
$db->query("CREATE TABLE {$p}fiscal_document_stamps(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,fiscal_document_id BIGINT UNSIGNED,uuid CHAR(36))");
foreach([2,3,4]as$id)$db->table('fiscal_drafts')->insert(['id'=>$id,'fiscal_document_id'=>$id,'status'=>'ready']);
$db->table('fiscal_documents')->insert(['id'=>2,'source_draft_id'=>2,'status'=>'stamping_error','marker'=>'uncertain','deleted'=>0]);
$db->table('fiscal_stamp_attempts')->insert(['fiscal_document_id'=>2,'status'=>'transport_unknown','requires_reconciliation'=>1]);
$db->table('fiscal_documents')->insert(['id'=>3,'source_draft_id'=>3,'status'=>'stamped','marker'=>'stamped','deleted'=>0]);
$db->table('fiscal_document_stamps')->insert(['fiscal_document_id'=>3,'uuid'=>'123E4567-E89B-42D3-A456-426614174003']);
$db->table('fiscal_documents')->insert(['id'=>4,'source_draft_id'=>4,'status'=>'superseded','marker'=>'historical','deleted'=>0]);
$before=$db->table('fiscal_documents')->select('id,source_draft_id,status,marker,deleted')->orderBy('id')->get()->getResultArray();
$duplicateBlocked=false;try{$db->table('fiscal_documents')->insert(['source_draft_id'=>4,'status'=>'locked','marker'=>'would-fail','deleted'=>0]);}catch(Throwable){$duplicateBlocked=true;}
$ok($duplicateBlocked,'El índice legado reproduce Duplicate entry para un reemplazo del mismo borrador.');
$service=new FiscalDraftDocumentSchemaUpgradeService($db);
$ok(!$service->inspect()['compatible'],'El diagnóstico previo detecta el esquema legado antes de facturar.');
$result=$service->apply();
$ok($result['changed']&&$result['after']['compatible'],'El upgrade dirigido reemplaza y verifica los índices.');
$after=$db->table('fiscal_documents')->select('id,source_draft_id,status,marker,deleted')->orderBy('id')->get()->getResultArray();
$ok($before===$after,'El upgrade no altera IDs, estados, source_draft_id ni evidencia histórica.');
$active=array_column($db->table('fiscal_documents')->select('id,active_source_draft_id')->orderBy('id')->get()->getResultArray(),'active_source_draft_id','id');
$ok((int)$active[2]===2&&(int)$active[3]===3&&$active[4]===null,'Timbrado e incierto permanecen protegidos; superseded queda histórico.');
$db->table('fiscal_documents')->insert(['source_draft_id'=>4,'active_source_draft_id'=>4,'status'=>'locked','marker'=>'replacement','deleted'=>0]);
$replacement=(int)$db->insertID();
$ok($replacement>4&&$db->table('fiscal_documents')->where('source_draft_id',4)->countAllResults()===2,'El borrador admite una preparación nueva sin reutilizar el documento superseded.');
$secondActiveBlocked=false;try{$db->table('fiscal_documents')->insert(['source_draft_id'=>4,'active_source_draft_id'=>4,'status'=>'locked','marker'=>'double-click','deleted'=>0]);}catch(Throwable){$secondActiveBlocked=true;}
$ok($secondActiveBlocked,'El índice activo impide dos principales aun ante una segunda confirmación concurrente.');
$again=$service->apply();
$ok(!$again['changed']&&$again['after']['compatible'],'La aplicación repetida es idempotente.');
$ok($db->table('fiscal_stamp_attempts')->where(['fiscal_document_id'=>2,'requires_reconciliation'=>1])->countAllResults()===1,'El intento incierto no se libera ni modifica.');
$ok($db->table('fiscal_document_stamps')->where('fiscal_document_id',3)->countAllResults()===1,'El UUID timbrado conserva su relación histórica.');
echo "TOTAL PASS={$pass} FAIL={$fail}; random local MySQL fixture dropped at shutdown; no PAC calls.".PHP_EOL;
exit($fail?1:0);
