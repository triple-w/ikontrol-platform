<?php
declare(strict_types=1);

if(!extension_loaded('sqlite3')){fwrite(STDERR,"BLOCKED: sqlite3 extension is required.\n");exit(2);}

foreach(['IKONTROL_RC1_LAB'=>'false','IKONTROL_RC1_SANDBOX_TRANSPORT'=>'false','fiscal.enabled'=>'false','fiscal.stampingEnabled'=>'false','fiscal.allowRealPac'=>'false','fiscal.runtimeMode'=>'automated_test','fiscal.pacAdapter'=>'fake','fiscal.environment'=>'development']as$key=>$value){putenv($key.'='.$value);$_ENV[$key]=$_SERVER[$key]=$value;}
require dirname(__DIR__).'/bootstrap.php';

use App\Services\Fiscal\CfdiPaymentRuleService;
use App\Services\Fiscal\FiscalDraftValidationService;
use App\Services\Fiscal\FiscalFolioService;
use App\Services\Fiscal\FiscalPaymentMethodReadinessService;
use App\Services\Fiscal\FiscalSeriesEnvironmentService;
use App\Services\Fiscal\FiscalUnifiedReadinessActionService;
use App\Services\Fiscal\ItemFiscalReadinessService;
use App\Services\Fiscal\ProductFiscalConfigurationResolver;
use Config\Database;

$cfg=['DSN'=>'','hostname'=>'','username'=>'','password'=>'','database'=>':memory:','DBDriver'=>'SQLite3','DBPrefix'=>'','pConnect'=>false,'DBDebug'=>true,'foreignKeys'=>true];
$dbConfig=config(Database::class);$dbConfig->default=$cfg;$db=Database::connect($cfg,true);$db->initialize();
if($db->DBDriver!=='SQLite3'||$db->getDatabase()!==':memory:')throw new RuntimeException('Unsafe test target: SQLite memory database required.');
$fiscal=config('Fiscal');$fiscal->environment='development';$fiscal->enabled=false;$fiscal->stampingEnabled=false;$fiscal->allowRealPac=false;$fiscal->pacAdapter='fake';$fiscal->runtimeMode='automated_test';

$sql=[
 'CREATE TABLE items(id INTEGER PRIMARY KEY,title TEXT,description TEXT,unit_type TEXT,deleted INTEGER)',
 'CREATE TABLE item_fiscal_settings(id INTEGER PRIMARY KEY,item_id INTEGER,item_type TEXT,sat_product_service_key_id INTEGER,sat_unit_key_id INTEGER,commercial_unit TEXT,tax_object_code_id INTEGER,fiscal_description TEXT,is_default INTEGER,status TEXT,deleted INTEGER)',
 'CREATE TABLE sat_product_service_keys(id INTEGER PRIMARY KEY,code TEXT,is_active INTEGER)',
 'CREATE TABLE sat_unit_keys(id INTEGER PRIMARY KEY,code TEXT,is_active INTEGER)',
 'CREATE TABLE sat_tax_object_codes(id INTEGER PRIMARY KEY,code TEXT,description TEXT,is_active INTEGER)',
 'CREATE TABLE sat_tax_codes(id INTEGER PRIMARY KEY,code TEXT,is_active INTEGER)',
 'CREATE TABLE sat_tax_factor_types(id INTEGER PRIMARY KEY,name TEXT,is_active INTEGER)',
 'CREATE TABLE taxes(id INTEGER PRIMARY KEY,title TEXT,fiscal_tax_type TEXT,sat_tax_code_id INTEGER,factor_type_id INTEGER,xml_rate TEXT,xml_quota TEXT,deleted INTEGER,use_for_fiscal INTEGER,is_fiscal_ready INTEGER)',
 'CREATE TABLE item_fiscal_taxes(id INTEGER PRIMARY KEY,item_fiscal_setting_id INTEGER,tax_id INTEGER,sort_order INTEGER,is_active INTEGER)',
 'CREATE TABLE payment_methods(id INTEGER PRIMARY KEY,title TEXT,deleted INTEGER,available_on_invoice INTEGER)',
 'CREATE TABLE fiscal_payment_method_mappings(id INTEGER PRIMARY KEY,payment_method_id INTEGER,sat_payment_form_code TEXT,is_active INTEGER)',
 'CREATE TABLE sat_payment_forms(code TEXT PRIMARY KEY,name TEXT,is_active INTEGER)',
 'CREATE TABLE sat_payment_methods(code TEXT PRIMARY KEY,name TEXT,is_active INTEGER)',
 'CREATE TABLE invoices(id INTEGER PRIMARY KEY,invoice_total TEXT,deleted INTEGER)',
 'CREATE TABLE invoice_payments(id INTEGER PRIMARY KEY,invoice_id INTEGER,payment_method_id INTEGER,amount TEXT,payment_date TEXT,status TEXT,deleted INTEGER)',
 'CREATE TABLE fiscal_profiles(id INTEGER PRIMARY KEY,profile_type TEXT,status TEXT)',
 'CREATE TABLE fiscal_series(id INTEGER PRIMARY KEY,issuer_profile_id INTEGER,document_type TEXT,series TEXT,environment TEXT,initial_folio INTEGER,current_folio INTEGER,is_default INTEGER,is_active INTEGER,deleted INTEGER,updated_at TEXT)',
 'CREATE TABLE fiscal_documents(id INTEGER PRIMARY KEY,status TEXT)'
];foreach($sql as$q)$db->query($q);
$db->table('sat_payment_forms')->insert(['code'=>'99','name'=>'Por definir','is_active'=>1]);
$db->table('sat_payment_methods')->insert(['code'=>'PPD','name'=>'Pago en parcialidades o diferido','is_active'=>1]);
$db->table('sat_product_service_keys')->insert(['id'=>1,'code'=>'01010101','is_active'=>1]);$db->table('sat_unit_keys')->insert(['id'=>1,'code'=>'H87','is_active'=>1]);
foreach([['id'=>1,'code'=>'01','description'=>'No objeto','is_active'=>1],['id'=>2,'code'=>'02','description'=>'Sí objeto','is_active'=>1]]as$row)$db->table('sat_tax_object_codes')->insert($row);
$db->table('sat_tax_codes')->insert(['id'=>1,'code'=>'002','is_active'=>1]);$db->table('sat_tax_codes')->insert(['id'=>2,'code'=>'001','is_active'=>1]);$db->table('sat_tax_factor_types')->insert(['id'=>1,'name'=>'Tasa','is_active'=>1]);
$db->table('taxes')->insert(['id'=>1,'title'=>'IVA','fiscal_tax_type'=>'transfer','sat_tax_code_id'=>1,'factor_type_id'=>1,'xml_rate'=>'0.160000','xml_quota'=>null,'deleted'=>0,'use_for_fiscal'=>1,'is_fiscal_ready'=>1]);
$db->table('taxes')->insert(['id'=>2,'title'=>'ISR','fiscal_tax_type'=>'withholding','sat_tax_code_id'=>2,'factor_type_id'=>1,'xml_rate'=>'0.100000','xml_quota'=>null,'deleted'=>0,'use_for_fiscal'=>1,'is_fiscal_ready'=>1]);
$db->table('items')->insert(['id'=>1,'title'=>'Producto','description'=>'Descripción administrativa','unit_type'=>'Pieza','deleted'=>0]);
$db->table('item_fiscal_settings')->insert(['id'=>1,'item_id'=>1,'item_type'=>'product','sat_product_service_key_id'=>1,'sat_unit_key_id'=>1,'commercial_unit'=>'Pieza','tax_object_code_id'=>2,'fiscal_description'=>'Producto fiscal','is_default'=>1,'status'=>'ready','deleted'=>0]);
$db->table('item_fiscal_taxes')->insert(['id'=>1,'item_fiscal_setting_id'=>1,'tax_id'=>1,'sort_order'=>1,'is_active'=>1]);$db->table('item_fiscal_taxes')->insert(['id'=>2,'item_fiscal_setting_id'=>1,'tax_id'=>2,'sort_order'=>2,'is_active'=>1]);

$pass=$fail=0;$ok=static function(bool$value,string$message)use(&$pass,&$fail):void{echo($value?'[PASS] ':'[FAIL] ').$message.PHP_EOL;$value?$pass++:$fail++;};
$ui=(new ItemFiscalReadinessService($db))->evaluate(1);$effective=(new ProductFiscalConfigurationResolver($db))->resolve(1);$ok($ui['is_ready']&&$effective['ready']&&$ui['is_ready']===$effective['ready'],'formulario/listado y resolvedor efectivo comparten readiness para producto completo');
$db->table('item_fiscal_settings')->where('id',1)->update(['status'=>'incomplete']);$ui=(new ItemFiscalReadinessService($db))->evaluate(1);$effective=(new ProductFiscalConfigurationResolver($db))->resolve(1);$ok($ui['is_ready']&&$effective['ready'],'configuración histórica completa no queda bloqueada por un status derivado obsoleto');$db->table('item_fiscal_settings')->where('id',1)->update(['status'=>'ready']);
$ok(count($effective['taxes'])===2&&$effective['taxes'][0]['tax_type']==='transfer'&&$effective['taxes'][1]['tax_type']==='withholding','contrato conserva traslados y retenciones fiscalmente listos');
$db->table('item_fiscal_settings')->where('id',1)->update(['fiscal_description'=>null]);$ui=(new ItemFiscalReadinessService($db))->evaluate(1);$effective=(new ProductFiscalConfigurationResolver($db))->resolve(1);$ok(!$ui['is_ready']&&!$effective['ready']&&in_array('fiscal_description',$ui['missing_fields'],true),'descripción fiscal faltante bloquea igual en UI y preflight');
$db->table('item_fiscal_settings')->where('id',1)->update(['fiscal_description'=>'Producto fiscal','commercial_unit'=>null]);$ui=(new ItemFiscalReadinessService($db))->evaluate(1);$ok(!$ui['is_ready']&&in_array('commercial_unit',$ui['missing_fields'],true),'unidad fiscal faltante se diagnostica por campo');
$db->table('item_fiscal_settings')->where('id',1)->update(['commercial_unit'=>'Pieza','tax_object_code_id'=>1]);$noTax=(new ProductFiscalConfigurationResolver($db))->resolve(1);$ok($noTax['ready']&&$noTax['taxes']===[],'ObjetoImp 01 excluye impuestos de la configuración efectiva sin borrar el maestro');
$db->table('item_fiscal_settings')->where('id',1)->update(['tax_object_code_id'=>2]);$db->table('item_fiscal_taxes')->where('item_fiscal_setting_id',1)->delete();$taxMissing=(new ProductFiscalConfigurationResolver($db))->resolve(1);$ok(!$taxMissing['ready']&&in_array('impuestos',$taxMissing['missing'],true),'ObjetoImp 02 exige desglose fiscal');

$draft=['issuer_id'=>0,'receiver_profile_id'=>0,'payment_form_code'=>'99','payment_method_code'=>'PPD','currency_code'=>'MXN','cfdi_use_code'=>'G03','issue_date'=>date('Y-m-d H:i:s')];
$concept=['quantity'=>'1','total'=>'116.000001','snapshot'=>['snapshot_version'=>2,'title'=>'Línea A','fiscal_description'=>'Línea A','product_service_code'=>'01010101','unit_code'=>'H87','commercial_unit'=>'Pieza','tax_object_code'=>'02','taxable_base'=>'100.000000','taxes'=>[['tax_type'=>'transfer','tax_code'=>'002','factor_type'=>'Tasa','rate_or_quota'=>'0.160000','tax_base'=>'100.000000','tax_amount'=>'16.000000']]]];
$validation=(new FiscalDraftValidationService($db))->validate($draft,[['sale_id'=>1]],[$concept]);$round=array_values(array_filter($validation['errors'],static fn(array$e):bool=>$e['code']==='CONCEPT_TOTAL_ROUNDING_MISMATCH'));$ok(count($round)===1&&str_contains($round[0]['message'],'Línea A')&&str_contains($round[0]['message'],'esperado'),'preflight distingue redondeo y reporta concepto, observado y esperado');
unset($concept['snapshot']['commercial_unit']);$validation=(new FiscalDraftValidationService($db))->validate($draft,[['sale_id'=>1]],[$concept]);$unitIssue=array_values(array_filter($validation['errors'],static fn(array$e):bool=>$e['field']==='commercial_unit'));$ok(count($unitIssue)===1&&str_contains($unitIssue[0]['message'],'unidad fiscal'),'preflight identifica unidad fiscal faltante por concepto');

foreach([['id'=>1,'title'=>'Transferencia','deleted'=>0,'available_on_invoice'=>1],['id'=>2,'title'=>'Efectivo','deleted'=>0,'available_on_invoice'=>1],['id'=>3,'title'=>'Tarjeta','deleted'=>0,'available_on_invoice'=>1],['id'=>4,'title'=>'Duplicado','deleted'=>0,'available_on_invoice'=>1]]as$row)$db->table('payment_methods')->insert($row);
$db->table('sat_payment_forms')->insert(['code'=>'03','name'=>'Transferencia electrónica','is_active'=>1]);$db->table('sat_payment_forms')->insert(['code'=>'04','name'=>'Tarjeta de crédito','is_active'=>0]);
foreach([['id'=>1,'payment_method_id'=>1,'sat_payment_form_code'=>'03','is_active'=>1],['id'=>2,'payment_method_id'=>3,'sat_payment_form_code'=>'04','is_active'=>1],['id'=>3,'payment_method_id'=>4,'sat_payment_form_code'=>'03','is_active'=>1],['id'=>4,'payment_method_id'=>4,'sat_payment_form_code'=>'03','is_active'=>1]]as$row)$db->table('fiscal_payment_method_mappings')->insert($row);
$payments=(new FiscalPaymentMethodReadinessService($db))->inspect();$statuses=array_column($payments['methods'],'status','payment_method_id');$ok($statuses[1]==='READY'&&$statuses[2]==='MAPPING_MISSING'&&$statuses[3]==='SAT_FORM_INACTIVE'&&$statuses[4]==='MAPPING_DUPLICATE','mappings se validan por ID: válido, ausente, SAT inactivo y duplicado');
$readiness=['state'=>'ONBOARDING','details'=>['issuer'=>['status'=>'READY'],'csd'=>['status'=>'READY'],'series'=>['status'=>'READY'],'pac'=>['status'=>'READY'],'catalogs'=>[],'products'=>['incomplete'=>0],'clients'=>['incomplete'=>0],'payment_methods'=>$payments,'stamping'=>['status'=>'BLOCKED']]];$checklist=(new FiscalUnifiedReadinessActionService())->onboardingChecklist($readiness);$paymentAction=array_values(array_filter($checklist,static fn(array$r):bool=>$r['key']==='payment_methods'))[0];$ok($paymentAction['status']==='ADVERTENCIA'&&$paymentAction['action']['path']==='fiscal/payment-methods'&&str_contains($paymentAction['detail'],'métodos administrativos mapeados'),'onboarding reporta cobertura y enlaza configuración sin convertirla en bloqueo global');
$db->table('invoices')->insert(['id'=>1,'invoice_total'=>'100.00','deleted'=>0]);$db->table('invoice_payments')->insert(['id'=>1,'invoice_id'=>1,'payment_method_id'=>1,'amount'=>'100.00','payment_date'=>'2026-10-22','status'=>'active','deleted'=>0]);$pue=(new CfdiPaymentRuleService($db))->suggest(1);$ok($pue['payment_method_code']==='PUE'&&$pue['payment_form_code']==='03','PUE usa la forma SAT activa del método administrativo efectivo');
$db->table('invoices')->insert(['id'=>2,'invoice_total'=>'100.00','deleted'=>0]);$db->table('invoice_payments')->insert(['id'=>2,'invoice_id'=>2,'payment_method_id'=>2,'amount'=>'20.00','payment_date'=>'2026-10-22','status'=>'active','deleted'=>0]);$ppd=(new CfdiPaymentRuleService($db))->suggest(2);$ok($ppd['payment_method_code']==='PPD'&&$ppd['payment_form_code']==='99','PPD parcial conserva forma 99 sin inventar mapping');

foreach([['id'=>1,'issuer_profile_id'=>10,'document_type'=>'ingreso','series'=>'DEV','environment'=>'development','initial_folio'=>1,'current_folio'=>0,'is_default'=>1,'is_active'=>1,'deleted'=>0],['id'=>2,'issuer_profile_id'=>10,'document_type'=>'ingreso','series'=>'PROD','environment'=>'production','initial_folio'=>1,'current_folio'=>0,'is_default'=>1,'is_active'=>1,'deleted'=>0],['id'=>3,'issuer_profile_id'=>10,'document_type'=>'ingreso','series'=>'OLD','environment'=>'legacy','initial_folio'=>1,'current_folio'=>0,'is_default'=>1,'is_active'=>1,'deleted'=>0]]as$row)$db->table('fiscal_series')->insert($row);
$series=(new FiscalSeriesEnvironmentService($db))->activeForIssuer(10,'ingreso','development')->getResult();$ok(count($series)===1&&$series[0]->series==='DEV','selector de series incluye sólo emisor y ambiente activo');
$folio=(new FiscalFolioService($db))->getDefaultSeries(10);$ok($folio&&$folio->series==='DEV','serie default de otro ambiente o legacy no se presenta como lista');
$seriesController=file_get_contents(APPPATH.'Controllers/Fiscal/SeriesEnvironment.php');$ok(str_contains($seriesController,"'environment'=>\$environment")&&str_contains($seriesController,'requiere revisión dirigida'),'nuevas series reciben ambiente y legacy queda explícitamente sin conversión');
$seriesView=file_get_contents(APPPATH.'Views/fiscal/series_environment/index.php');$ok(str_contains($seriesView,"title:'Ambiente'")&&str_contains($seriesView,'legacy'),'interfaz muestra ambiente y advertencia para series legacy');
$snapshotService=file_get_contents(APPPATH.'Services/Fiscal/FiscalDocumentFromDraftSnapshotService.php');$ok(str_contains($snapshotService,'environment=?')&&str_contains($snapshotService,"(string)\$draft['environment']"),'materialización respeta el ambiente congelado del draft');
$ok($db->table('fiscal_documents')->countAllResults()===0,'runner no crea ni recalcula documentos o snapshots históricos');

echo "TOTAL PASS={$pass} FAIL={$fail}. SQLite memory only; PAC disabled and no network adapter created.\n";exit($fail?1:0);
