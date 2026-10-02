<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use App\Services\Fiscal\FiscalIssuerResolver;
use App\Services\Fiscal\FiscalSaleAttemptResolver;
use App\Services\Sales\SaleLifecycleService;
use Config\Database;

helper(['date_time']);
$local=config(Database::class)->default;
if(!in_array((string)$local['hostname'],['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Local fixture server required.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$admin=new mysqli($local['hostname'],$local['username'],$local['password'],'',(int)$local['port']);$owned='ikontrol_test_platform_defects_'.bin2hex(random_bytes(5));$admin->query('CREATE DATABASE `'.$owned.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$db=Database::connect(array_replace($local,['DSN'=>'','database'=>$owned,'DBPrefix'=>'','pConnect'=>false,'DBDebug'=>true,'failover'=>[]]),false);
register_shutdown_function(static function()use($admin,$owned):void{$dbName=str_replace('`','``',$owned);$admin->query('DROP DATABASE IF EXISTS `'.$dbName.'`');$admin->close();});
foreach([
    'CREATE TABLE invoices (id INTEGER PRIMARY KEY,status TEXT,commercial_status TEXT,invoice_total NUMERIC,client_id INTEGER,type TEXT,deleted INTEGER,closed_at TEXT NULL,closed_by INTEGER NULL,closure_reason TEXT NULL)',
    'CREATE TABLE invoice_items (id INTEGER PRIMARY KEY,invoice_id INTEGER,deleted INTEGER)',
    'CREATE TABLE payment_allocations (id INTEGER PRIMARY KEY,invoice_payment_id INTEGER,invoice_id INTEGER,amount_applied NUMERIC,status TEXT,deleted INTEGER)',
    'CREATE TABLE fiscal_documents (id INTEGER PRIMARY KEY,invoice_id INTEGER NULL,status TEXT,deleted INTEGER)',
    'CREATE TABLE fiscal_document_stamps (id INTEGER PRIMARY KEY,fiscal_document_id INTEGER,uuid TEXT,stamp_attempt_id INTEGER NULL,pdf_status TEXT NULL,pac_pdf_artifact_id INTEGER NULL)',
    'CREATE TABLE fiscal_document_sales (id INTEGER PRIMARY KEY,fiscal_document_id INTEGER,sale_id INTEGER,allocation_status TEXT)',
    'CREATE TABLE fiscal_drafts (id INTEGER PRIMARY KEY,status TEXT,fiscal_document_id INTEGER NULL,data_origin TEXT,provisional_series TEXT NULL,fiscal_series_id INTEGER NULL)',
    'CREATE TABLE fiscal_draft_sales (id INTEGER PRIMARY KEY,fiscal_draft_id INTEGER,sale_id INTEGER,allocation_status TEXT)',
    'CREATE TABLE commercial_lifecycle_audit (id INTEGER PRIMARY KEY AUTO_INCREMENT,entity_type TEXT,entity_id INTEGER,event TEXT,old_status TEXT NULL,new_status TEXT NULL,reason TEXT NULL,user_id INTEGER,created_at TEXT)',
    'CREATE TABLE sat_tax_regimes (id INTEGER PRIMARY KEY,code TEXT,description TEXT)',
    'CREATE TABLE fiscal_profiles (id INTEGER PRIMARY KEY,profile_type TEXT,company_id INTEGER NULL,environment TEXT,status TEXT,is_default INTEGER,valid_from TEXT NULL,valid_to TEXT NULL,tax_regime_id INTEGER NULL,rfc TEXT)',
    'CREATE TABLE fiscal_issuer_certificates (id INTEGER PRIMARY KEY,issuer_profile_id INTEGER,deleted INTEGER,status TEXT,valid_from TEXT,valid_to TEXT,certificate_rfc TEXT,is_default INTEGER,certificate_number TEXT)',
    'CREATE TABLE fiscal_issuer_certificate_secrets (id INTEGER PRIMARY KEY,fiscal_issuer_certificate_id INTEGER,status TEXT)'
] as$sql)$db->query($sql);

$pass=0;$assert=static function(bool$ok,string$message)use(&$pass):void{if(!$ok)throw new RuntimeException('[FAIL] '.$message);$pass++;echo'[PASS] '.$message.PHP_EOL;};
$sale=static function(int$id,string$status='not_paid',string$commercial='open',?string$closedAt=null)use($db):void{$db->table('invoices')->insert(['id'=>$id,'status'=>$status,'commercial_status'=>$commercial,'invoice_total'=>'100.000000','client_id'=>1,'type'=>'invoice','deleted'=>0,'closed_at'=>$closedAt,'closed_by'=>$closedAt?1:null,'closure_reason'=>$closedAt?'cierre':null]);$db->table('invoice_items')->insert(['id'=>$id,'invoice_id'=>$id,'deleted'=>0]);};

$sale(1,'paid','closed');$life=new SaleLifecycleService($db);
$assert($life->canEdit(1,1)['allowed'],'status legacy paid/closed sin evidencia irreversible permanece editable');
$sale(2,'not_paid','closed','2026-10-02 10:00:00');$assert(!$life->canEdit(2,1)['allowed'],'cierre comercial canónico bloquea partidas');
$sale(3,'cancelled','cancelled');$assert(!$life->canEdit(3,1)['allowed'],'venta cancelada bloquea partidas');

foreach([[10,'0.000000','not_paid'],[11,'40.000000','partially_paid'],[12,'100.000000','paid']]as[$id,$paid,$expected]){
    $sale($id);$db->table('fiscal_documents')->insert(['id'=>$id,'invoice_id'=>$id,'status'=>'stamped','deleted'=>0]);$db->table('fiscal_document_stamps')->insert(['id'=>$id,'fiscal_document_id'=>$id,'uuid'=>'UUID-'.$id]);
    if($paid!=='0.000000')$db->table('payment_allocations')->insert(['id'=>$id,'invoice_payment_id'=>$id,'invoice_id'=>$id,'amount_applied'=>$paid,'status'=>'active','deleted'=>0]);
    $state=$life->finalizeFiscalIssuance($id,9);$row=$db->table('invoices')->where('id',$id)->get(1)->getRow();
    $assert($row->commercial_status==='closed'&&$row->status===$expected,"timbrado cierra y conserva cobranza {$expected}");
    $life->finalizeFiscalIssuance($id,9);$assert($db->table('commercial_lifecycle_audit')->where(['entity_id'=>$id,'event'=>'sale_closed_after_stamp'])->countAllResults()===1,"transición {$expected} es idempotente");
}
$sale(20);$db->table('fiscal_documents')->insert(['id'=>20,'invoice_id'=>20,'status'=>'stamped','deleted'=>0]);$db->table('fiscal_document_stamps')->insert(['id'=>20,'fiscal_document_id'=>20,'uuid'=>'UUID-20']);$assert(!$life->canEdit(20,1)['allowed'],'CFDI timbrado bloquea partidas aunque la venta siga open');

$db->table('fiscal_drafts')->insert(['id'=>30,'status'=>'blocked','fiscal_document_id'=>30,'data_origin'=>'operational','provisional_series'=>'SF','fiscal_series_id'=>4]);$db->table('fiscal_draft_sales')->insert(['id'=>30,'fiscal_draft_id'=>30,'sale_id'=>30,'allocation_status'=>'reserved']);
$db->table('fiscal_drafts')->insert(['id'=>31,'status'=>'stamped','fiscal_document_id'=>31,'data_origin'=>'operational','provisional_series'=>'SF','fiscal_series_id'=>4]);$db->table('fiscal_draft_sales')->insert(['id'=>31,'fiscal_draft_id'=>31,'sale_id'=>31,'allocation_status'=>'converted']);
$attempts=new FiscalSaleAttemptResolver($db);$assert($attempts->draftId(30)===30&&$attempts->draftId(30)===30,'resultado incierto conserva el mismo draft en solicitudes repetidas');$assert($attempts->draftId(31)===31,'asignación convertida conserva el intento timbrado');$same=$db->table('fiscal_drafts')->where('id',30)->get(1)->getRow();$assert($db->table('fiscal_drafts')->where('id',30)->countAllResults()===1&&$same->provisional_series==='SF'&&(int)$same->fiscal_series_id===4,'reintento conserva serie/folio provisional y no crea otro intento');

$db->table('sat_tax_regimes')->insert(['id'=>1,'code'=>'601','description'=>'General']);
$db->table('fiscal_profiles')->insert(['id'=>50,'profile_type'=>'issuer','company_id'=>1,'environment'=>'development','status'=>'ready','is_default'=>0,'tax_regime_id'=>1,'rfc'=>'AAA010101AAA']);
$db->table('fiscal_issuer_certificates')->insert(['id'=>50,'issuer_profile_id'=>50,'deleted'=>0,'status'=>'valid','valid_from'=>'2020-01-01 00:00:00','valid_to'=>'2099-01-01 00:00:00','certificate_rfc'=>'AAA010101AAA','is_default'=>0,'certificate_number'=>'123']);
$db->table('fiscal_issuer_certificate_secrets')->insert(['id'=>50,'fiscal_issuer_certificate_id'=>50,'status'=>'active']);
$resolver=new FiscalIssuerResolver($db);$assert((int)$resolver->resolve(1,'development')->id===50,'emisor y CSD válidos se detectan aunque flags default legacy falten');$assert($resolver->resolve(1,'production')===null,'emisor de ambiente incorrecto sigue bloqueado');
$readiness=file_get_contents(APPPATH.'Services/Fiscal/FiscalOnboardingReadinessService.php');$assert(str_contains($readiness,'new FiscalIssuerResolver'),'readiness consume el resolver fiscal canónico');

$security=file_get_contents(APPPATH.'Controllers/Security_Controller.php');$invoices=file_get_contents(APPPATH.'Controllers/Invoices.php');$assert(str_contains($security,'SaleLifecycleService')&&substr_count($invoices,'SaleLifecycleService')>=3&&str_contains($invoices,'function save_item')&&str_contains($invoices,'function delete_item'),'alta, edición y borrado de partidas comparten la policy canónica en frontend y backend');
$app=file_get_contents(ROOTPATH.'assets/js/app.js');$shim=file_get_contents(ROOTPATH.'assets/js/bootstrap-popover-compat.js');$head=file_get_contents(APPPATH.'Views/includes/head.php');
$assert(str_contains($app,'window.bootstrap.Popover')&&str_contains($shim,'getOrCreateInstance')&&str_contains($head,'bootstrap-popover-compat.js'),'appTable dispone de popover Bootstrap 5 sin depender del plugin jQuery');

$emptyPreparation=(new App\Services\Fiscal\FiscalReviewPreparation($db))->prepare(['issuer'=>(object)['id'=>1],'receiver'=>(object)['id'=>1],'series'=>[],'sales'=>[]],['issue_date'=>'2026-10-02T12:00:00','currency_code'=>'MXN','exchange_rate'=>'1.000000','payment_method_code'=>'PUE','payment_form_code'=>'99','cfdi_use_code'=>'G01','fiscal_series_id'=>1]);
$assert($emptyPreparation['validation']['valid']===false && in_array('La venta no contiene partidas para facturar.', array_column($emptyPreparation['validation']['errors'],'message'), true),'preparación sin líneas devuelve blocker de negocio y no dispara cálculo vacío');
$assert($emptyPreparation['items']===[] && $emptyPreparation['allocations']===[] && $emptyPreparation['totals']['total']==='0.000000','preparación vacía queda en modo validación sin entrar al cálculo canónico');

echo"passed={$pass}\n";
