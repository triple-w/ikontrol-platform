<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';
require_once APPPATH.'ThirdParty/PHP-Hooks/php-hooks.php';

use App\Models\Invoices_model;
use App\Services\Fiscal\Cancellation\FakeFiscalCancellationAdapter;
use App\Services\Fiscal\Cancellation\FiscalCancellationService;
use App\Services\Sales\SaleLifecycleService;
use Config\Database;

foreach(['fiscal.enabled'=>'false','fiscal.stampingEnabled'=>'false','fiscal.allowRealPac'=>'false','fiscal.runtimeMode'=>'automated_test','fiscal.pacAdapter'=>'fake']as$key=>$value){putenv($key.'='.$value);$_ENV[$key]=$_SERVER[$key]=$value;}
helper(['general','date_time','plugin','currency']);

$local=config(Database::class)->default;
if(!in_array((string)$local['hostname'],['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Local fixture server required.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$admin=new mysqli($local['hostname'],$local['username'],$local['password'],'',(int)$local['port']);
$owned='ikontrol_test_fiscal_116_fixes_'.bin2hex(random_bytes(5));
if(!preg_match('/^ikontrol_test_fiscal_116_fixes_[a-f0-9]{10}$/D',$owned))throw new RuntimeException('Unsafe fixture database name.');
$admin->query('CREATE DATABASE `'.$owned.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$fixture=array_replace($local,['DSN'=>'','database'=>$owned,'DBPrefix'=>'','pConnect'=>false,'DBDebug'=>true,'failover'=>[]]);
$db=Database::connect($fixture,false);
register_shutdown_function(static function()use($admin,$owned):void{$admin->query('DROP DATABASE IF EXISTS `'.str_replace('`','``',$owned).'`');$admin->close();});

$pass=0;$fail=0;
$assert=static function(bool$ok,string$message)use(&$pass,&$fail):void{echo($ok?'[PASS] ':'[FAIL] ').$message.PHP_EOL;$ok?$pass++:$fail++;};
$throws=static function(callable$call,string$contains=''):bool{try{$call();return false;}catch(Throwable$e){return$contains===''||str_contains($e->getMessage(),$contains);}};

foreach([
    "CREATE TABLE invoices (id INT PRIMARY KEY AUTO_INCREMENT,type VARCHAR(20),client_id INT,project_id INT NULL,subscription_id INT NULL,proposal_id INT NULL,order_id INT NULL,recurring_invoice_id INT DEFAULT 0,main_invoice_id INT DEFAULT 0,display_id VARCHAR(30),status VARCHAR(30),commercial_status VARCHAR(30),invoice_total DECIMAL(18,6),subtotal DECIMAL(18,6),discount_total DECIMAL(18,6),tax DECIMAL(18,6),tax2 DECIMAL(18,6),tax3 DECIMAL(18,6),tax_id INT NULL,tax_id2 INT NULL,tax_id3 INT NULL,bill_date DATE,due_date DATE,next_recurring_date DATE NULL,recurring TINYINT DEFAULT 0,due_reminder_date DATE NULL,recurring_reminder_date DATE NULL,created_by INT,cancelled_by INT NULL,cancelled_at DATETIME NULL,cancellation_reason VARCHAR(500) NULL,labels VARCHAR(255) NULL,closed_at DATETIME NULL,closed_by INT NULL,closure_reason VARCHAR(500) NULL,deleted TINYINT DEFAULT 0)",
    "CREATE TABLE clients (id INT PRIMARY KEY,company_name VARCHAR(100),vat_number VARCHAR(30),gst_number VARCHAR(30),currency VARCHAR(10),currency_symbol VARCHAR(10),owner_id INT,deleted TINYINT)",
    "CREATE TABLE projects (id INT PRIMARY KEY,title VARCHAR(100))",
    "CREATE TABLE users (id INT PRIMARY KEY,first_name VARCHAR(50),last_name VARCHAR(50))",
    "CREATE TABLE taxes (id INT PRIMARY KEY,percentage DECIMAL(10,6))",
    "CREATE TABLE labels (id INT PRIMARY KEY,title VARCHAR(100),color VARCHAR(20))",
    "CREATE TABLE invoice_payments (id INT PRIMARY KEY AUTO_INCREMENT,client_id INT,amount DECIMAL(18,6),payment_date DATE,status VARCHAR(20),deleted TINYINT)",
    "CREATE TABLE payment_allocations (id INT PRIMARY KEY AUTO_INCREMENT,invoice_payment_id INT,invoice_id INT,amount_applied DECIMAL(18,6),status VARCHAR(20),deleted TINYINT)",
    "CREATE TABLE fiscal_documents (id INT PRIMARY KEY AUTO_INCREMENT,invoice_id INT NULL,status VARCHAR(40),series VARCHAR(20) NULL,folio VARCHAR(20) NULL,total DECIMAL(18,6) DEFAULT 0,issuer_profile_id INT DEFAULT 1,environment VARCHAR(20) DEFAULT 'development',cancelled_at DATETIME NULL,updated_at DATETIME NULL,deleted TINYINT DEFAULT 0)",
    "CREATE TABLE fiscal_document_sales (id INT PRIMARY KEY AUTO_INCREMENT,fiscal_document_id INT,sale_id INT,allocated_total DECIMAL(18,6),allocation_status VARCHAR(20))",
    "CREATE TABLE fiscal_drafts (id INT PRIMARY KEY AUTO_INCREMENT,status VARCHAR(30),provisional_series VARCHAR(20) NULL,total DECIMAL(18,6) DEFAULT 0)",
    "CREATE TABLE fiscal_draft_sales (id INT PRIMARY KEY AUTO_INCREMENT,fiscal_draft_id INT,sale_id INT,allocated_total DECIMAL(18,6),allocation_status VARCHAR(20))",
    "CREATE TABLE fiscal_cancellation_requests (id INT PRIMARY KEY AUTO_INCREMENT,fiscal_document_id INT,fiscal_document_stamp_id INT NULL,uuid VARCHAR(40),issuer_rfc VARCHAR(20),receiver_rfc VARCHAR(20),total DECIMAL(18,6),cancellation_reason CHAR(2),replacement_uuid VARCHAR(40) NULL,provider VARCHAR(30),environment VARCHAR(20),status VARCHAR(40),provider_code VARCHAR(80) NULL,provider_message TEXT NULL,confirmed_at DATETIME NULL,cancelled_at DATETIME NULL,requires_reconciliation TINYINT DEFAULT 0,requested_at DATETIME NULL,user_id INT,idempotency_key VARCHAR(64),created_at DATETIME,updated_at DATETIME)",
    "CREATE TABLE fiscal_cancellation_attempts (id INT PRIMARY KEY AUTO_INCREMENT,fiscal_cancellation_request_id INT,status VARCHAR(40),provider_code VARCHAR(80) NULL,provider_message TEXT NULL,response_hash VARCHAR(64) NULL,requires_reconciliation TINYINT DEFAULT 0,started_at DATETIME NULL,responded_at DATETIME NULL,created_at DATETIME,updated_at DATETIME)",
    "CREATE TABLE fiscal_cancellation_artifacts (id INT PRIMARY KEY AUTO_INCREMENT,fiscal_cancellation_request_id INT,fiscal_cancellation_attempt_id INT,artifact_type VARCHAR(40),content_encoding VARCHAR(20),content_base64 LONGTEXT,decoded_mime_type VARCHAR(100),decoded_size_bytes INT,decoded_sha256 VARCHAR(64),created_by INT,created_at DATETIME)",
    "CREATE TABLE fiscal_document_stamps (id INT PRIMARY KEY AUTO_INCREMENT,fiscal_document_id INT,uuid VARCHAR(40))",
    "CREATE TABLE fiscal_document_relations (id INT PRIMARY KEY AUTO_INCREMENT,source_document_id INT,related_document_id INT,relation_type VARCHAR(30),created_by INT,created_at DATETIME)",
    "CREATE TABLE commercial_lifecycle_audit (id INT PRIMARY KEY AUTO_INCREMENT,entity_type VARCHAR(30),entity_id INT,event VARCHAR(60),old_status VARCHAR(30) NULL,new_status VARCHAR(30) NULL,reason VARCHAR(500) NULL,user_id INT,created_at DATETIME)",
    "CREATE TABLE settings (id INT PRIMARY KEY AUTO_INCREMENT,setting_name VARCHAR(100),setting_value TEXT)",
    "CREATE TABLE activity_logs (id INT PRIMARY KEY AUTO_INCREMENT,created_at DATETIME NULL)",
]as$sql)$db->query($sql);

$db->table('clients')->insert(['id'=>1,'company_name'=>'Cliente sintético','vat_number'=>'XAXX010101000','gst_number'=>'','currency'=>'MXN','currency_symbol'=>'$','owner_id'=>1,'deleted'=>0]);
$db->table('users')->insert(['id'=>9,'first_name'=>'Usuario','last_name'=>'Prueba']);
$db->table('projects')->insert(['id'=>1,'title'=>'Proyecto prueba']);
$db->table('taxes')->insert(['id'=>1,'percentage'=>'16.000000']);

$newSale=static function(string$status='not_paid',string$commercial='open',string$total='100.000000')use($db):int{$db->table('invoices')->insert(['type'=>'invoice','client_id'=>1,'project_id'=>1,'display_id'=>'F-TEST','status'=>$status,'commercial_status'=>$commercial,'invoice_total'=>$total,'subtotal'=>$total,'discount_total'=>'0','tax'=>'0','tax2'=>'0','tax3'=>'0','bill_date'=>'2026-10-15','due_date'=>'2026-10-20','created_by'=>9,'deleted'=>0]);return(int)$db->insertID();};
$life=new SaleLifecycleService($db);

// F-01: one transaction, rollback, idempotency and row serialization.
$rollbackSale=$newSale();
$db->query("CREATE TRIGGER fail_sale_audit BEFORE INSERT ON commercial_lifecycle_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='intentional audit failure'");
$failureDb=Database::connect($fixture,false);$failureLife=new SaleLifecycleService($failureDb);
$assert($throws(fn()=>$failureLife->cancel($rollbackSale,9,'Fallo intencional')),'F-01 injected audit failure reaches the transaction boundary');
$failureDb->close();
$rolled=$db->table('invoices')->where('id',$rollbackSale)->get()->getRow();
$assert($rolled->status==='not_paid'&&$rolled->commercial_status==='open'&&$db->table('commercial_lifecycle_audit')->where('entity_id',$rollbackSale)->countAllResults()===0,'F-01 rollback restores administrative, commercial and audit state');
$db->query('DROP TRIGGER fail_sale_audit');
$life->cancel($rollbackSale,9,'Cancelación válida');
$life->cancel($rollbackSale,9,'Repetición idempotente');
$cancelled=$db->table('invoices')->where('id',$rollbackSale)->get()->getRow();
$assert($cancelled->status==='cancelled'&&$cancelled->commercial_status==='cancelled','F-01 one atomic cancellation updates both states');
$assert($db->table('commercial_lifecycle_audit')->where(['entity_id'=>$rollbackSale,'event'=>'sale_cancelled'])->countAllResults()===1,'F-01 repeated cancellation is idempotent');

$concurrentSale=$newSale();
$second=Database::connect($fixture,false);$second->query('SET SESSION innodb_lock_wait_timeout=1');
$db->transBegin();$db->query('SELECT id FROM invoices WHERE id=? FOR UPDATE',[$concurrentSale]);
$blocked=$throws(fn()=>(new SaleLifecycleService($second))->cancel($concurrentSale,9,'Concurrente'));
$db->transRollback();$second->close();
$assert($blocked&&$db->table('invoices')->where('id',$concurrentSale)->get()->getRow()->status==='not_paid','F-01 concurrent cancellation cannot bypass the invoice row lock');
$life->cancel($concurrentSale,9,'Después del lock');

// F-02: only effective active applications block cancellation.
$activeSale=$newSale();$db->table('invoice_payments')->insert(['client_id'=>1,'amount'=>'40','payment_date'=>'2026-10-15','status'=>'active','deleted'=>0]);$activePayment=(int)$db->insertID();$db->table('payment_allocations')->insert(['invoice_payment_id'=>$activePayment,'invoice_id'=>$activeSale,'amount_applied'=>'40','status'=>'active','deleted'=>0]);
$decision=$life->canCancel($activeSale,9);
$assert(!$decision['allowed']&&$decision['code']==='SALE_HAS_ACTIVE_PAYMENT_ALLOCATIONS'&&str_contains($decision['message'],'pagos aplicados'),'F-02 active partial payment blocks with an actionable message');
$assert($throws(fn()=>$life->cancel($activeSale,9,'No permitida'),'SALE_HAS_ACTIVE_PAYMENT_ALLOCATIONS')&&$db->table('invoices')->where('id',$activeSale)->get()->getRow()->status==='not_paid','F-02 block happens before state changes');
$db->table('payment_allocations')->where('invoice_id',$activeSale)->update(['status'=>'inactive','deleted'=>1]);$life->cancel($activeSale,9,'Aplicación retirada');
$assert($db->table('invoices')->where('id',$activeSale)->get()->getRow()->status==='cancelled','F-02 inactive/deleted allocation does not block');

$fullyPaidSale=$newSale();$db->table('invoice_payments')->insert(['client_id'=>1,'amount'=>'100','payment_date'=>'2026-10-15','status'=>'active','deleted'=>0]);$fullPayment=(int)$db->insertID();$db->table('payment_allocations')->insert(['invoice_payment_id'=>$fullPayment,'invoice_id'=>$fullyPaidSale,'amount_applied'=>'100','status'=>'active','deleted'=>0]);
$assert($throws(fn()=>$life->cancel($fullyPaidSale,9,'Pago total aplicado'),'SALE_HAS_ACTIVE_PAYMENT_ALLOCATIONS'),'F-02 active total payment also blocks cancellation');
$db->table('payment_allocations')->where('invoice_id',$fullyPaidSale)->update(['status'=>'inactive','deleted'=>1]);$life->cancel($fullyPaidSale,9,'Aplicación total retirada');

$cancelledPaymentSale=$newSale();$db->table('invoice_payments')->insert(['client_id'=>1,'amount'=>'100','payment_date'=>'2026-10-15','status'=>'cancelled','deleted'=>0]);$cancelledPayment=(int)$db->insertID();$db->table('payment_allocations')->insert(['invoice_payment_id'=>$cancelledPayment,'invoice_id'=>$cancelledPaymentSale,'amount_applied'=>'100','status'=>'active','deleted'=>0]);$life->cancel($cancelledPaymentSale,9,'Pago cancelado');
$assert($db->table('invoices')->where('id',$cancelledPaymentSale)->get()->getRow()->status==='cancelled','F-02 allocation linked to cancelled payment is not effective');
$saleCancellationSource=file_get_contents(APPPATH.'Services/Sales/SaleLifecycleService.php').file_get_contents(APPPATH.'Services/Fiscal/FiscalSaleCancellationPolicy.php');
$assert(!str_contains($saleCancellationSource,'warehouse_movements')&&!str_contains($saleCancellationSource,'WarehouseMovementService'),'Inventory is not reversed: the existing sale cancellation flow has no sale-to-warehouse contract');

// F-03: invoke the durable result boundary with PAC-fake-shaped responses.
$persist=new ReflectionMethod(FiscalCancellationService::class,'persistResult');$persist->setAccessible(true);$cancellation=new FiscalCancellationService($db);
$resultCase=static function(string$initial,string$providerStatus,int$id)use($db,$persist,$cancellation):array{$now='2026-10-30 12:00:00';$db->table('fiscal_documents')->insert(['id'=>$id,'invoice_id'=>null,'status'=>$initial,'updated_at'=>$now,'deleted'=>0]);$uuid=sprintf('00000000-0000-4000-8000-%012d',$id);$db->table('fiscal_cancellation_requests')->insert(['id'=>$id,'fiscal_document_id'=>$id,'uuid'=>$uuid,'issuer_rfc'=>'AAA010101AAA','receiver_rfc'=>'XAXX010101000','total'=>'100','cancellation_reason'=>'02','provider'=>'fake','environment'=>'development','status'=>'sending','requested_at'=>$now,'user_id'=>9,'idempotency_key'=>hash('sha256',(string)$id),'created_at'=>$now,'updated_at'=>$now]);$db->table('fiscal_cancellation_attempts')->insert(['id'=>$id,'fiscal_cancellation_request_id'=>$id,'status'=>'sending','started_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);$scenario=$providerStatus==='unknown'?'timeout_unknown':$providerStatus;$result=(new FakeFiscalCancellationAdapter($scenario))->cancel(['uuid'=>$uuid]);$payload=json_encode(['fake'=>true,'status'=>$result['status']]);$result['operation']='consultarEstadoSAT';$result['provider_payload_base64']=base64_encode($payload);$response=$persist->invoke($cancellation,$id,$id,$id,$result,9);return[$response,$db->table('fiscal_documents')->where('id',$id)->get()->getRow(),$db->table('fiscal_cancellation_requests')->where('id',$id)->get()->getRow(),$payload];};
foreach([['accepted','cancelled'],['rejected','cancellation_rejected'],['pending','cancellation_pending'],['pending_acceptance','cancellation_pending']]as$index=>[$provider,$expected]){[$response,$document]=$resultCase('stamped',$provider,100+$index);$assert($document->status===$expected&&!$response['requires_reconciliation'],"F-03 {$provider} maps only to its confirmed state");}
[$unknownResponse,$unknownDocument,$unknownRequest,$unknownPayload]=$resultCase('stamped','unknown',110);
$artifact=$db->table('fiscal_cancellation_artifacts')->where(['fiscal_cancellation_request_id'=>110,'artifact_type'=>'provider_status'])->get()->getRow();
$assert($unknownDocument->status==='stamped'&&(int)$unknownRequest->requires_reconciliation===1&&$unknownResponse['cancellation_status']==='verifying','F-03 unknown preserves authoritative document state and requires reconciliation');
$assert($artifact&&hash_equals((string)$artifact->decoded_sha256,hash('sha256',$unknownPayload)),'F-03 ambiguous provider evidence is durable and hash-protected');
[$timeoutResponse,$timeoutDocument,$timeoutRequest]=$resultCase('cancellation_pending','unknown',111);
$assert($timeoutDocument->status==='cancellation_pending'&&(int)$timeoutRequest->requires_reconciliation===1,'F-03 timeout-shaped unknown preserves the previous confirmed pending state');
// A future provider value bypasses the fake's closed scenario list and exercises fail-closed normalization directly.
$now='2026-10-30 12:00:00';$db->table('fiscal_documents')->insert(['id'=>112,'status'=>'stamped','updated_at'=>$now,'deleted'=>0]);$db->table('fiscal_cancellation_requests')->insert(['id'=>112,'fiscal_document_id'=>112,'uuid'=>'00000000-0000-4000-8000-000000000112','issuer_rfc'=>'AAA010101AAA','receiver_rfc'=>'XAXX010101000','total'=>'100','cancellation_reason'=>'02','provider'=>'fake','environment'=>'development','status'=>'sending','requested_at'=>$now,'user_id'=>9,'idempotency_key'=>hash('sha256','112'),'created_at'=>$now,'updated_at'=>$now]);$db->table('fiscal_cancellation_attempts')->insert(['id'=>112,'fiscal_cancellation_request_id'=>112,'status'=>'sending','started_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);$unrecognized=$persist->invoke($cancellation,112,112,112,['status'=>'provider_new_state','code'=>'FAKE_NEW','message'=>'Estado futuro'],9);$unrecognizedDocument=$db->table('fiscal_documents')->where('id',112)->get()->getRow();$unrecognizedRequest=$db->table('fiscal_cancellation_requests')->where('id',112)->get()->getRow();
$assert($unrecognizedDocument->status==='stamped'&&$unrecognizedRequest->status==='unknown'&&$unrecognized['status']==='unknown','F-03 unrecognized status is normalized to unknown without changing the document');

// F-04: canonical report states reconcile with individual records.
$reportRows=[
    ['not_paid','100','10','14.4','104.4','104.4','0'],
    ['partially_paid','200','0','32','232','232','100'],
    ['paid','50','0','8','58','58','58'],
    ['cancelled','100','0','16','116','116','0'],
    ['credited','100','0','16','116','116','0'],
    ['draft','100','0','16','116','116','0'],
];
$reportIds=[];
foreach($reportRows as$i=>[$status,$subtotal,$discount,$tax,$total,$invoiceTotal,$paid]){$id=$newSale($status,$status==='draft'?'open':'closed',$invoiceTotal);$reportIds[$status]=$id;$db->table('invoices')->where('id',$id)->update(['display_id'=>'R-'.($i+1),'subtotal'=>$subtotal,'discount_total'=>$discount,'tax'=>$tax,'invoice_total'=>$invoiceTotal,'tax_id'=>1,'bill_date'=>'2026-10-15','due_date'=>'2026-10-20']);if((float)$paid>0){$db->table('invoice_payments')->insert(['client_id'=>1,'amount'=>$paid,'payment_date'=>'2026-10-16','status'=>'active','deleted'=>0]);$paymentId=(int)$db->insertID();$db->table('payment_allocations')->insert(['invoice_payment_id'=>$paymentId,'invoice_id'=>$id,'amount_applied'=>$paid,'status'=>'active','deleted'=>0]);}}

$databaseConfig=config(Database::class);$originalDefault=$databaseConfig->default;$databaseConfig->default=$fixture;
config('Rise')->app_settings_array=['default_currency'=>'MXN','currency_symbol'=>'$','generate_reports_based_on'=>'bill_date','paid_status_tolarance'=>'0.02','timezone'=>'UTC'];
$model=new Invoices_model($db);
$partial=$model->get_details(['status'=>'partially_paid'])->getResult();$full=$model->get_details(['status'=>'fully_paid'])->getResult();$unpaid=$model->get_details(['status'=>'not_paid'])->getResult();
$assert(count(array_filter($partial,fn($r)=>(int)$r->id===$reportIds['partially_paid']))===1&&count(array_filter($full,fn($r)=>(int)$r->id===$reportIds['paid']))===1&&count(array_filter($unpaid,fn($r)=>(int)$r->id===$reportIds['not_paid']))===1,'F-04 get_details returns canonical unpaid, partial and paid states');
$totals=$model->get_invoices_total_and_paymnts(['client_id'=>1,'currency'=>'MXN']);
$expectedTotal='394.400000';$expectedPaid='158.000000';$expectedDue='236.400000';
$assert((int)$totals->invoices_count===3&&bccomp((string)$totals->invoices_total,$expectedTotal,6)===0,'F-04 summary count and total include exactly three canonical issued invoices');
$assert((int)$totals->not_paid_count===1&&(int)$totals->partially_paid_count===1&&(int)$totals->fully_paid_count===1,'F-04 state counters reconcile to individual invoices');
$assert(bccomp((string)$totals->payments_total,$expectedPaid,6)===0&&bccomp((string)$totals->due,$expectedDue,6)===0,'F-04 applied payments and outstanding balance reconcile');
$summary=$model->get_invoices_summary(['currency'=>'MXN'])->getRow();
$assert((int)$summary->invoice_count===3&&bccomp((string)$summary->invoice_total,$expectedTotal,6)===0&&bccomp((string)$summary->payment_received,$expectedPaid,6)===0,'F-04 client report excludes draft, cancelled and credited documents');
$assert(bccomp((string)$summary->discount_total,'10.000000',6)===0&&bccomp((string)$summary->tax_total,'54.400000',6)===0,'F-04 discounts and taxes reconcile in client report');
$statistics=$model->invoice_statistics(['client_id'=>1,'start_date'=>'2026-10-01','end_date'=>'2026-10-31']);
$assert(count($statistics->invoices)===1&&bccomp((string)$statistics->invoices[0]->total,$expectedTotal,6)===0,'F-04 monthly invoice statistics use canonical states');
$databaseConfig->default=$originalDefault;

echo"TOTAL PASS={$pass} FAIL={$fail}. Owned synthetic MySQL schema removed at shutdown; no PAC/SAT or source database accessed.".PHP_EOL;
exit($fail?1:0);
