<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Services\Fiscal\Stamps\FiscalStampAccountService;
use App\Services\Fiscal\Cancellation\FiscalCancellationService;
use App\Services\Fiscal\Cancellation\FiscalCancellationFailureException;
use Config\Database;
helper(['date_time']);
$local=config(Database::class)->default;
if(!in_array((string)$local['hostname'],['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Local fixture server required.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$admin=new mysqli($local['hostname'],$local['username'],$local['password'],'',(int)$local['port']);
$owned='ikontrol_test_cancel_wallet_'.bin2hex(random_bytes(5));if(!preg_match('/^ikontrol_test_cancel_wallet_[a-f0-9]{10}$/D',$owned))throw new RuntimeException('Unsafe fixture database.');
$admin->query('CREATE DATABASE '.chr(96).$owned.chr(96).' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$cfg=array_replace($local,['DSN'=>'','database'=>$owned,'DBPrefix'=>'','pConnect'=>false,'DBDebug'=>true,'failover'=>[]]);$db=Database::connect($cfg,false);
register_shutdown_function(static function()use($admin,$owned):void{$admin->query('DROP DATABASE IF EXISTS '.chr(96).$owned.chr(96));$admin->close();});
foreach([
"CREATE TABLE fiscal_profiles(id INT PRIMARY KEY,company_id INT,profile_type VARCHAR(20),rfc VARCHAR(20),legal_name VARCHAR(100),environment VARCHAR(20),status VARCHAR(20),deleted TINYINT)",
"CREATE TABLE fiscal_stamp_accounts(id INT AUTO_INCREMENT PRIMARY KEY,issuer_profile_id INT,environment VARCHAR(20),available_balance INT,reserved_balance INT,status VARCHAR(20),created_at DATETIME NULL,updated_at DATETIME NULL,UNIQUE KEY uq(issuer_profile_id,environment))",
"CREATE TABLE fiscal_documents(id INT PRIMARY KEY,invoice_id INT NULL,issuer_profile_id INT,environment VARCHAR(20),status VARCHAR(40),deleted TINYINT)",
"CREATE TABLE fiscal_document_audit(id INT AUTO_INCREMENT PRIMARY KEY,fiscal_document_id INT,invoice_id INT NULL,user_id INT NULL,action VARCHAR(40),reason VARCHAR(500),created_at DATETIME NULL)",
"CREATE TABLE fiscal_cancellation_requests(id INT PRIMARY KEY,fiscal_document_id INT,environment VARCHAR(20),status VARCHAR(30))",
"CREATE TABLE fiscal_stamp_movements(id INT AUTO_INCREMENT PRIMARY KEY,stamp_account_id INT,environment VARCHAR(20),movement_type VARCHAR(50),quantity INT,available_before INT,available_after INT,reserved_before INT,reserved_after INT,fiscal_document_id INT NULL,pac_attempt_id INT NULL,fiscal_document_stamp_id INT NULL,fiscal_cancellation_request_id INT NULL,idempotency_key VARCHAR(191) UNIQUE,consumption_key VARCHAR(191) NULL,reason TEXT,reference VARCHAR(191) NULL,created_by INT NULL,created_at DATETIME NULL)"
]as$sql)$db->query($sql);
$db->table('fiscal_profiles')->insert(['id'=>3,'company_id'=>1,'profile_type'=>'issuer','rfc'=>'AAA010101AAA','legal_name'=>'Emisor sintético','environment'=>'development','status'=>'ready','deleted'=>0]);
$db->table('fiscal_stamp_accounts')->insert(['issuer_profile_id'=>3,'environment'=>'development','available_balance'=>5,'reserved_balance'=>0,'status'=>'active']);
$db->table('fiscal_documents')->insert(['id'=>6,'issuer_profile_id'=>3,'environment'=>'development','status'=>'stamped','deleted'=>0]);
$db->table('fiscal_cancellation_requests')->insert(['id'=>9,'fiscal_document_id'=>6,'environment'=>'development','status'=>'sending']);
$service=new FiscalStampAccountService($db);$pass=$fail=0;$ok=static function(bool$v,string$m)use(&$pass,&$fail):void{echo($v?'[PASS] ':'[FAIL] ').$m.PHP_EOL;$v?$pass++:$fail++;};
$cancel=$service->consumeCancellationRequest(9,1);$cancelReplay=$service->consumeCancellationRequest(9,1);
$ok((int)$cancel->available_after===4&&(int)$cancelReplay->id===(int)$cancel->id,'solicitud enviada consume uno y replay no duplica cargo');
$query=$service->consumeSatStatusQuery(6,'sat-query-wallet-0001',1);$queryReplay=$service->consumeSatStatusQuery(6,'sat-query-wallet-0001',1);
$ok((int)$query->available_after===3&&(int)$queryReplay->id===(int)$query->id,'consulta enviada consume uno y doble clic es idempotente');
$query2=$service->consumeSatStatusQuery(6,'sat-query-wallet-0002',1);
$ok((int)$query2->available_after===2,'consulta posterior con clave nueva es una operación independiente');
$ok($db->table('fiscal_stamp_movements')->countAllResults()===3,'errores previos al transporte no crean movimientos porque el ledger sólo se invoca después de request_sent');
$failureMethod=(new ReflectionClass(FiscalCancellationService::class))->getMethod('failure');$failureMethod->setAccessible(true);$failureService=new FiscalCancellationService($db);
$db->transBegin();$db->table('fiscal_document_audit')->insert(['fiscal_document_id'=>6,'action'=>'rolled_back_marker']);$db->transRollback();
$failure=$failureMethod->invoke($failureService,6,1,'adapter_configuration',false,'not_sent',new RuntimeException('apikey=supersecret password=hidden'));
$audit=$db->table('fiscal_document_audit')->where('action','cancellation_failure')->get(1)->getRow();$detail=json_decode((string)($audit->reason??''),true);
$ok($failure instanceof FiscalCancellationFailureException&&$failure->transportStarted===false&&$failure->outcome==='not_sent','fallo previo a SOAP conserva frontera no enviada y referencia recuperable');
$ok($audit!==null&&($detail['stage']??'')==='adapter_configuration'&&($detail['transport_started']??true)===false,'diagnóstico durable sobrevive al rollback comercial');
$ok(!str_contains((string)$audit->reason,'supersecret')&&!str_contains((string)$audit->reason,'hidden')&&str_contains((string)$audit->reason,'DATO PROTEGIDO'),'diagnóstico persistido redacta API keys y contraseñas');
echo"TOTAL PASS={$pass} FAIL={$fail}; owned local MySQL fixture removed, zero network.\n";exit($fail?1:0);
