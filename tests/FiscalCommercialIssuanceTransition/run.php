<?php
declare(strict_types=1);

if (!extension_loaded('sqlite3')) { fwrite(STDERR, "BLOCKED: sqlite3 extension is required.\n"); exit(2); }
foreach (['fiscal.enabled'=>'false','fiscal.stampingEnabled'=>'false','fiscal.allowRealPac'=>'false','fiscal.runtimeMode'=>'automated_test','fiscal.pacAdapter'=>'fake','fiscal.environment'=>'development'] as $key=>$value) { putenv($key.'='.$value); $_ENV[$key]=$_SERVER[$key]=$value; }
require dirname(__DIR__).'/bootstrap.php';
if (!function_exists('get_current_utc_time')) { function get_current_utc_time(): string { return gmdate('Y-m-d H:i:s'); } }

use App\Services\Sales\SaleLifecycleService;
use Config\Database;

$cfg=['DSN'=>'','hostname'=>'','username'=>'','password'=>'','database'=>':memory:','DBDriver'=>'SQLite3','DBPrefix'=>'','pConnect'=>false,'DBDebug'=>true,'foreignKeys'=>false];
$database=config(Database::class); $database->default=$cfg; $db=Database::connect($cfg,true); $db->initialize();
if ($db->DBDriver!=='SQLite3'||$db->getDatabase()!==':memory:') throw new RuntimeException('Unsafe test target.');
foreach ([
    'CREATE TABLE invoices(id INTEGER PRIMARY KEY,status TEXT,commercial_status TEXT,invoice_total NUMERIC,deleted INTEGER,closed_at TEXT NULL,closed_by INTEGER NULL,closure_reason TEXT NULL)',
    'CREATE TABLE payment_allocations(id INTEGER PRIMARY KEY,invoice_payment_id INTEGER,invoice_id INTEGER,amount_applied NUMERIC,status TEXT,deleted INTEGER)',
    'CREATE TABLE fiscal_documents(id INTEGER PRIMARY KEY,invoice_id INTEGER,status TEXT,deleted INTEGER)',
    'CREATE TABLE fiscal_document_stamps(id INTEGER PRIMARY KEY,fiscal_document_id INTEGER,uuid TEXT)',
    'CREATE TABLE fiscal_document_sales(id INTEGER PRIMARY KEY,fiscal_document_id INTEGER,sale_id INTEGER,allocation_status TEXT)',
    'CREATE TABLE commercial_lifecycle_audit(id INTEGER PRIMARY KEY AUTOINCREMENT,entity_type TEXT,entity_id INTEGER,event TEXT,old_status TEXT,new_status TEXT,reason TEXT,user_id INTEGER,created_at TEXT)'
] as $sql) $db->query($sql);

$pass=0; $ok=static function(bool $value,string $label)use(&$pass):void{if(!$value)throw new RuntimeException('[FAIL] '.$label);$pass++;echo'[PASS] '.$label.PHP_EOL;};
$db->table('invoices')->insert(['id'=>1,'status'=>'not_paid','commercial_status'=>'open','invoice_total'=>'1740.000000','deleted'=>0]);
$ok($db->table('invoices')->where('id',1)->get(1)->getRow()->commercial_status==='open','venta permanece open antes de confirmar UUID');
$db->table('fiscal_documents')->insert(['id'=>1,'invoice_id'=>1,'status'=>'stamped','deleted'=>0]);
$db->table('fiscal_document_stamps')->insert(['id'=>1,'fiscal_document_id'=>1,'uuid'=>'123E4567-E89B-42D3-A456-426614174000']);
$result=(new SaleLifecycleService($db))->finalizeFiscalIssuance(1,9);
$sale=$db->table('invoices')->where('id',1)->get(1)->getRow();
$ok($result['closed']&&$sale->commercial_status==='closed'&&$sale->status==='not_paid','UUID persistido cierra comercialmente sin inventar pago');
(new SaleLifecycleService($db))->finalizeFiscalIssuance(1,9);
$ok($db->table('commercial_lifecycle_audit')->where(['entity_id'=>1,'event'=>'sale_closed_after_stamp'])->countAllResults()===1,'finalización comercial es idempotente');

$flow=file_get_contents(APPPATH.'Services/Fiscal/FiscalInvoiceFlowService.php');
$stamping=file_get_contents(APPPATH.'Services/Fiscal/FiscalDraftStampingService.php');
$ok(str_contains($flow,'return $this->executeInternal($draftId, $userId, $authorized, true);'),'ruta de borrador admite venta open mediante sale-flow');
$ok(str_contains($stamping,'return $this->stampInternal($draftId, $userId, $authorized, true);'),'preparación y timbrado usan la misma política comercial');
$ok(strpos($stamping,'finalizeFiscalIssuance($saleId,$userId)')<strpos($stamping,"'xmlAvailable'=>true"),'cierre comercial forma parte de la terminación posterior al timbre');
$ok(!str_contains($flow,'TimbradorXpressRestAdapter'),'orquestador no construye transporte ni ejecuta red en la prueba');
echo "TOTAL PASS={$pass} FAIL=0; SQLite memory, no PAC/SAT.\n";
