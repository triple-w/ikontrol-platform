<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\AdministrativePaymentService;
use App\Services\Fiscal\FiscalStampAdminService;
use App\Services\Fiscal\Stamps\FiscalStampBalanceService;
use App\Services\PaymentAllocationService;
use App\Services\Sales\SaleLifecycleService;
use App\Services\Sales\SalePaymentEligibilityService;
use App\Services\Sales\SalePaymentStatusService;
use App\Services\Sales\SaleStatusNormalizer;
use App\Services\Upgrade\InstanceUpgradeService;
use Config\Database;

helper(['date_time']);
$local = config(Database::class)->default;
if (! in_array((string)$local['hostname'], ['localhost','127.0.0.1','::1'], true)) throw new RuntimeException('Local fixture server required.');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli($local['hostname'],$local['username'],$local['password'],'',(int)$local['port']);
$owned = 'ikontrol_test_stamps_sales_' . bin2hex(random_bytes(5));
$admin->query('CREATE DATABASE `' . $owned . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$base = is_array($local) ? $local : get_object_vars($local);
$db = Database::connect(array_replace($base,['DSN'=>'','database'=>$owned,'DBPrefix'=>'canon_','pConnect'=>false,'DBDebug'=>true,'failover'=>[]]),false);
register_shutdown_function(static function() use($admin,$owned):void{$admin->query('DROP DATABASE IF EXISTS `'.str_replace('`','``',$owned).'`');$admin->close();});
$pass=0;
$ok=static function(bool$value,string$message)use(&$pass):void{if(!$value)throw new RuntimeException('[FAIL] '.$message);$pass++;echo'[PASS] '.$message.PHP_EOL;};
$p='canon_';
$db->query("CREATE TABLE {$p}app_schema_versions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,version VARCHAR(100) UNIQUE,description VARCHAR(255),applied_at DATETIME)");

// Canonical stamp fixtures: same issuer, isolated environments, plus another issuer.
$db->query("CREATE TABLE {$p}fiscal_profiles (id INT PRIMARY KEY,profile_type VARCHAR(20),rfc VARCHAR(20),legal_name VARCHAR(150),environment VARCHAR(20),status VARCHAR(20),deleted TINYINT DEFAULT 0)");
$db->query("CREATE TABLE {$p}fiscal_stamp_accounts (id INT AUTO_INCREMENT PRIMARY KEY,issuer_profile_id INT,environment VARCHAR(20),available_balance INT,reserved_balance INT,status VARCHAR(20),created_at DATETIME NULL,updated_at DATETIME NULL,UNIQUE KEY uq_issuer_env(issuer_profile_id,environment))");
$db->query("CREATE TABLE {$p}fiscal_stamp_movements (id INT AUTO_INCREMENT PRIMARY KEY,stamp_account_id INT,movement_type VARCHAR(40),quantity INT,available_before INT,available_after INT,reserved_before INT,reserved_after INT,idempotency_key VARCHAR(191),reason VARCHAR(1000),created_at DATETIME NULL)");
$db->query("CREATE TABLE {$p}fiscal_documents (id INT PRIMARY KEY,invoice_id INT NULL,issuer_profile_id INT,environment VARCHAR(20),deleted TINYINT DEFAULT 0,status VARCHAR(40))");
$db->query("CREATE TABLE {$p}fiscal_document_stamps (id INT AUTO_INCREMENT PRIMARY KEY,fiscal_document_id INT,uuid VARCHAR(36),stamp_attempt_id INT NULL)");
$db->table('fiscal_profiles')->insertBatch([
 ['id'=>1,'profile_type'=>'issuer','rfc'=>'DOLD860620EW7','legal_name'=>'DOLD','environment'=>'production','status'=>'ready','deleted'=>0],
 ['id'=>2,'profile_type'=>'issuer','rfc'=>'OTRO010101AAA','legal_name'=>'Otro','environment'=>'production','status'=>'ready','deleted'=>0],
]);
$db->table('fiscal_stamp_accounts')->insertBatch([
 ['issuer_profile_id'=>1,'environment'=>'production','available_balance'=>473,'reserved_balance'=>0,'status'=>'active'],
 ['issuer_profile_id'=>1,'environment'=>'development','available_balance'=>7,'reserved_balance'=>2,'status'=>'active'],
 ['issuer_profile_id'=>2,'environment'=>'production','available_balance'=>91,'reserved_balance'=>0,'status'=>'active'],
]);
$db->table('fiscal_documents')->insert(['id'=>100,'invoice_id'=>2,'issuer_profile_id'=>1,'environment'=>'production','status'=>'stamped','deleted'=>0]);
$balances=new FiscalStampBalanceService($db);
$production=$balances->forIssuer(1,'production');
$development=$balances->forIssuer(1,'development');
$documentBalance=$balances->forDocument(100);
$ok($production['available']===473&&$production['reserved']===0&&$production['usable']===473,'production resuelve 473 disponibles y utilizables');
$ok($development['available']===0&&$development['status']==='environment_mismatch','perfil production no puede consumir su cuenta development accidental');
$ok($documentBalance['usable']===473&&$documentBalance['environment']==='production','documento production y preflight usan el mismo saldo 473');
$ok($balances->forIssuer(2,'production')['usable']===91&&$balances->forIssuer(1,'production')['usable']===473,'otro emisor no contamina ni consume el saldo DOLD');
$adminRows=(new FiscalStampAdminService($db))->getAccounts();
$doldAdmin=array_values(array_filter($adminRows,static fn(array$row):bool=>$row['issuer_profile_id']===1))[0]??null;
$ok($doldAdmin&&$doldAdmin['available_balance']===473&&$doldAdmin['usable_balance']===473,'administración consume el mismo resolver canónico');

// Sales/payment schema used by lifecycle and normalization.
$db->query("CREATE TABLE {$p}clients (id INT PRIMARY KEY,company_name VARCHAR(100),deleted TINYINT DEFAULT 0)");
$db->query("CREATE TABLE {$p}financial_accounts (id INT PRIMARY KEY,name VARCHAR(100),currency CHAR(3),is_active TINYINT,deleted TINYINT)");
$db->query("CREATE TABLE {$p}payment_methods (id INT PRIMARY KEY,title VARCHAR(100),default_financial_account_id INT NULL,deleted TINYINT)");
$db->query("CREATE TABLE {$p}invoices (id INT PRIMARY KEY,client_id INT,type VARCHAR(20),invoice_total DECIMAL(18,6),status VARCHAR(30),commercial_status VARCHAR(30) NULL,closed_at DATETIME NULL,closed_by INT NULL,closure_reason VARCHAR(500) NULL,deleted TINYINT DEFAULT 0)");
$db->query("CREATE TABLE {$p}invoice_payments (id INT AUTO_INCREMENT PRIMARY KEY,client_id INT,invoice_id INT NULL,amount DECIMAL(18,6),payment_date DATE,payment_method_id INT,destination_financial_account_id INT,note TEXT NULL,reference VARCHAR(150) NULL,status VARCHAR(20),cancelled_at DATETIME NULL,cancelled_by INT NULL,cancellation_reason VARCHAR(500) NULL,created_by INT NULL,created_at DATETIME NULL,deleted TINYINT)");
$db->query("CREATE TABLE {$p}payment_allocations (id INT AUTO_INCREMENT PRIMARY KEY,invoice_payment_id INT,invoice_id INT,amount_applied DECIMAL(18,6),allocation_date DATE,status VARCHAR(20),created_by INT NULL,created_at DATETIME NULL,updated_at DATETIME NULL,deleted TINYINT,deactivated_at DATETIME NULL,deactivated_by INT NULL,deactivation_reason VARCHAR(500) NULL,UNIQUE KEY uq_payment_sale(invoice_payment_id,invoice_id))");
$db->query("CREATE TABLE {$p}financial_account_movements (id INT AUTO_INCREMENT PRIMARY KEY,financial_account_id INT,direction VARCHAR(3),amount DECIMAL(18,6),movement_date DATE,reference_type VARCHAR(50),reference_id INT,movement_role VARCHAR(20),reversal_of_movement_id INT NULL,reversed_movement_id INT NULL,reversal_reason VARCHAR(500) NULL,description TEXT NULL,is_active TINYINT,created_by INT NULL,created_at DATETIME NULL,updated_at DATETIME NULL,UNIQUE KEY uq_source_role(reference_type,reference_id,movement_role))");
$db->query("CREATE TABLE {$p}commercial_lifecycle_audit (id INT AUTO_INCREMENT PRIMARY KEY,entity_type VARCHAR(20),entity_id INT,event VARCHAR(80),old_status VARCHAR(30) NULL,new_status VARCHAR(30) NULL,reason VARCHAR(500) NULL,user_id INT NULL,created_at DATETIME)");
$db->query("CREATE TABLE {$p}fiscal_document_sales (id INT AUTO_INCREMENT PRIMARY KEY,fiscal_document_id INT,sale_id INT)");
$db->query("CREATE TABLE {$p}fiscal_draft_sales (id INT AUTO_INCREMENT PRIMARY KEY,fiscal_draft_id INT,sale_id INT)");
$db->query("CREATE TABLE {$p}fiscal_drafts (id INT PRIMARY KEY,fiscal_document_id INT NULL)");
$db->table('clients')->insert(['id'=>1,'company_name'=>'Cliente','deleted'=>0]);
$db->table('financial_accounts')->insert(['id'=>1,'name'=>'Caja','currency'=>'MXN','is_active'=>1,'deleted'=>0]);
$db->table('payment_methods')->insert(['id'=>1,'title'=>'Efectivo','default_financial_account_id'=>1,'deleted'=>0]);
$sale=static function(int$id,string$commercial,string$status='draft')use($db):void{$db->table('invoices')->insert(['id'=>$id,'client_id'=>1,'type'=>'invoice','invoice_total'=>'100.000000','status'=>$status,'commercial_status'=>$commercial,'deleted'=>0]);};
$allocation=static function(int$id,int$saleId,string$amount)use($db):void{$db->table('payment_allocations')->insert(['id'=>$id,'invoice_payment_id'=>$id,'invoice_id'=>$saleId,'amount_applied'=>$amount,'allocation_date'=>'2026-10-07','status'=>'active','deleted'=>0]);};
$stamp=static function(int$saleId,int$documentId)use($db):void{$db->table('fiscal_documents')->insert(['id'=>$documentId,'invoice_id'=>$saleId,'issuer_profile_id'=>1,'environment'=>'production','status'=>'stamped','deleted'=>0]);$db->table('fiscal_document_stamps')->insert(['fiscal_document_id'=>$documentId,'uuid'=>sprintf('00000000-0000-0000-0000-%012d',$documentId)]);};

$statusService=new SalePaymentStatusService($db);
$sale(1,'open');
$ok($statusService->synchronize(1)['status']==='draft'&&$db->table('invoices')->where('id',1)->get(1)->getRow()->commercial_status==='open','open sin pagos permanece draft');

$sale(2,'open');$stamp(2,102);$zero=(new SaleLifecycleService($db))->finalizeFiscalIssuance(2,1);
$ok($zero['payment_status']==='not_paid'&&$db->table('invoices')->where('id',2)->get(1)->getRow()->commercial_status==='closed','timbrado sin pagos produce closed + not_paid');
$sale(3,'open');$allocation(301,3,'40.000000');$stamp(3,103);$partial=(new SaleLifecycleService($db))->finalizeFiscalIssuance(3,1);
$ok($partial['payment_status']==='partially_paid'&&$partial['balance']==='60.000000','timbrado con pago parcial produce closed + partially_paid');
$sale(4,'open');$allocation(401,4,'100.000000');$stamp(4,104);$paid=(new SaleLifecycleService($db))->finalizeFiscalIssuance(4,1);
$ok($paid['payment_status']==='paid'&&$paid['balance']==='0.000000','timbrado con pago total produce closed + paid');

$sale(5,'open');
$paymentData=['invoice_id'=>5,'client_id'=>1,'amount'=>'40.000000','payment_date'=>'2026-10-07','payment_method_id'=>1,'destination_financial_account_id'=>1,'reference'=>'primer pago','created_by'=>1,'created_at'=>'2026-10-07 00:00:00'];
$paymentId=(new AdministrativePaymentService($db))->save($paymentData);
$afterFirst=$db->table('invoices')->where('id',5)->get(1)->getRow();
$ok($afterFirst->commercial_status==='closed'&&$afterFirst->status==='partially_paid','primer pago cierra venta open y sincroniza partially_paid');
$ok((new SalePaymentEligibilityService($db))->evaluate(5)['allowed'],'closed con saldo pendiente sigue admitiendo pagos');
$movementCount=$db->table('financial_account_movements')->where(['reference_type'=>'invoice_payment','reference_id'=>$paymentId,'movement_role'=>'original'])->countAllResults();
$allocationRow=$db->table('payment_allocations')->where(['invoice_payment_id'=>$paymentId,'invoice_id'=>5])->get(1)->getRow();
(new PaymentAllocationService($db))->deactivate((int)$allocationRow->id,1,'Retiro fixture');
$afterRemoval=$db->table('invoices')->where('id',5)->get(1)->getRow();
$ok($afterRemoval->commercial_status==='closed'&&$afterRemoval->status==='not_paid','retirar aplicación recalcula closed + not_paid');
$ok($movementCount===1&&$db->table('financial_account_movements')->where(['reference_type'=>'invoice_payment','reference_id'=>$paymentId,'movement_role'=>'original'])->countAllResults()===1,'recalcular cobranza no duplica movimiento financiero');

// Historical closed + draft normalization for no/partial/full settlement.
$sale(6,'closed');$sale(7,'closed');$sale(8,'closed');$allocation(701,7,'25.000000');$allocation(801,8,'100.000000');
$normalizer=new SaleStatusNormalizer($db);
$dry=$normalizer->normalize(true);$targets=array_column($dry['rows'],'to','invoice_id');
$ok($dry['updated']===0&&$targets[6]==='not_paid'&&$targets[7]==='partially_paid'&&$targets[8]==='paid','dry-run propone estados por saldo real sin escribir');
$executed=$normalizer->normalize(false);
$ok($executed['updated']===3&&$db->table('invoices')->where('id',6)->get(1)->getRow()->status==='not_paid'&&$db->table('invoices')->where('id',7)->get(1)->getRow()->status==='partially_paid'&&$db->table('invoices')->where('id',8)->get(1)->getRow()->status==='paid','normalizador aplica not_paid, partially_paid y paid');
$ok($normalizer->normalize(false)['updated']===0,'normalizador es idempotente');

// Code-only directed release records 1.1.4 without schema steps.
$db->table('app_schema_versions')->insert(['version'=>'ikontrol-1.1.3','description'=>'fixture','applied_at'=>'2026-10-06 00:00:00']);
$plan=(new InstanceUpgradeService($db))->plan('1.1.4');
$ok($plan['compatible']&&($plan['packages'][0]['steps']??null)===['record_version'],'release 1.1.4 es code-only y no inventa migración');

$root=dirname(__DIR__,2);
$invoiceModule=file_get_contents($root.'/app/Controllers/Fiscal/InvoiceModule.php');
$invoiceCancellation=file_get_contents($root.'/app/Controllers/Fiscal/Invoices.php');
$cancellationService=file_get_contents($root.'/app/Services/Fiscal/Cancellation/FiscalCancellationService.php');
$paymentController=file_get_contents($root.'/app/Controllers/Invoice_payments.php');
$ok(str_contains($invoiceModule,'FiscalStampBalanceService')&&str_contains($invoiceCancellation,'forDocument($doc)')&&str_contains($cancellationService,'FiscalStampBalanceService'),'facturas, cancelación y ejecución consumen el resolver canónico');
$ok(!str_contains($invoiceCancellation,"getBalance((int)\$doc->issuer_profile_id,'development'")&&!str_contains($paymentController,'update_invoice_status($invoice_id);'),'no quedan los hardcodes que causaban saldo cero ni el overwrite not_paid');

echo "passed={$pass}\n";
