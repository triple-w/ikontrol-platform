<?php

declare(strict_types=1);

if(!extension_loaded('sqlite3')){fwrite(STDERR,"BLOCKED: sqlite3 extension is required.\n");exit(2);}
require dirname(__DIR__) . '/bootstrap.php';

use App\Services\Fiscal\FiscalInstallationInitializerService;
use App\Services\Projects\ProjectStatusInitializerService;
use CodeIgniter\Database\Config as DbConfig;

$assertions=0;$assert=static function(bool$ok,string$msg)use(&$assertions):void{if(!$ok)throw new RuntimeException('FAIL '.$msg);$assertions++;echo "PASS {$msg}\n";};
$official=dirname(__DIR__,2).'/resources/fiscal/catalogs/sat';
$manifest=json_decode((string)file_get_contents($official.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
$assert(count($manifest['catalogs'])===10,'official manifest declares all ten runtime catalogs');
$counts=[];foreach($manifest['catalogs'] as$entry){$path=$official.'/'.$entry['file'];$assert(is_file($path)&&hash_equals(substr($entry['checksum'],7),hash_file('sha256',$path)),'checksum '.$entry['catalog_name']);$lines=0;$h=fopen($path,'rb');while(fgetcsv($h)!==false)$lines++;fclose($h);$assert($lines-1===$entry['row_count'],'row count '.$entry['catalog_name']);$counts[$entry['catalog_name']]=$entry['row_count'];}
$assert($counts['product-service']>52000&&$counts['units']>2400,'large official catalogs are complete-scale, not bootstrap fixtures');

$root=sys_get_temp_dir().'/fiscal-init-'.bin2hex(random_bytes(4));mkdir($root,0700,true);
$fixtures=[
'product-service'=>['product_service.csv',['code','description','valid_from','valid_to'],[['01010101','No existe en el catálogo','','']]],
'units'=>['unit_keys.csv',['code','name','description','symbol','valid_from','valid_to'],[['H87','Pieza','Pieza','','','']]],
'tax-codes'=>['tax_codes.csv',['code','name','description'],[['001','ISR','ISR'],['002','IVA','IVA'],['003','IEPS','IEPS']]],
'tax-factor-types'=>['tax_factor_types.csv',['code','name'],[['Tasa','Tasa'],['Cuota','Cuota'],['Exento','Exento']]],
'cfdi-uses'=>['cfdi_uses.csv',['code','description','applies_to_individual','applies_to_company','valid_from','valid_to'],[['S01','Sin efectos fiscales','1','1','','']]],
'tax-regimes'=>['tax_regimes.csv',['code','description','applies_to_individual','applies_to_company','valid_from','valid_to'],[['616','Sin obligaciones fiscales','1','1','','']]],
'tax-object-codes'=>['tax_object_codes.csv',['code','description','valid_from','valid_to'],[['01','No objeto','',''],['02','Sí objeto','','']]],
'payment-forms'=>['payment_forms.csv',['code','name'],[['01','Efectivo'],['99','Por definir']]],
'payment-methods'=>['payment_methods.csv',['code','name'],[['PUE','Pago en una sola exhibición'],['PPD','Pago en parcialidades o diferido']]],
'currencies'=>['currencies.csv',['code','name','requires_exchange_rate'],[['MXN','Peso Mexicano','0']]],
];
$entries=[];foreach($fixtures as$name=>[$file,$header,$rows]){$h=fopen($root.'/'.$file,'wb');fputcsv($h,$header);foreach($rows as$row)fputcsv($h,$row);fclose($h);$entries[]=['catalog_name'=>$name,'source'=>'synthetic isolated test','source_version'=>'fixture','generated_at'=>'2026-10-31T00:00:00-06:00','file'=>$file,'checksum'=>'sha256:'.hash_file('sha256',$root.'/'.$file),'row_count'=>count($rows),'complete_authoritative'=>true];}file_put_contents($root.'/manifest.json',json_encode(['schema_version'=>1,'generated_at'=>'2026-10-31T00:00:00-06:00','catalogs'=>$entries]));
$db=DbConfig::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
foreach(['sat_product_service_keys'=>'description TEXT, normalized_description TEXT, valid_from TEXT, valid_to TEXT','sat_unit_keys'=>'name TEXT, description TEXT, symbol TEXT, normalized_description TEXT, valid_from TEXT, valid_to TEXT','sat_tax_codes'=>'name TEXT, description TEXT','sat_tax_factor_types'=>'name TEXT','sat_cfdi_uses'=>'description TEXT, applies_to_individual INTEGER, applies_to_company INTEGER, valid_from TEXT, valid_to TEXT','sat_tax_regimes'=>'description TEXT, applies_to_individual INTEGER, applies_to_company INTEGER, valid_from TEXT, valid_to TEXT','sat_tax_object_codes'=>'description TEXT, valid_from TEXT, valid_to TEXT','sat_payment_forms'=>'name TEXT','sat_payment_methods'=>'name TEXT','sat_currencies'=>'name TEXT, requires_exchange_rate INTEGER'] as$table=>$extra)$db->query("CREATE TABLE {$table} (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, {$extra}, is_active INTEGER, created_at TEXT, updated_at TEXT)");
$db->query('CREATE TABLE sat_catalog_installations (id INTEGER PRIMARY KEY AUTOINCREMENT,catalog_name TEXT UNIQUE,source TEXT,source_version TEXT,source_checksum TEXT,source_generated_at TEXT,installed_at TEXT,row_count INTEGER,active_row_count INTEGER,metadata_json TEXT,created_at TEXT,updated_at TEXT)');
$db->query('CREATE TABLE taxes (id INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT,percentage REAL,deleted INTEGER,stripe_tax_id TEXT,sat_tax_code_id INTEGER,fiscal_tax_type TEXT,factor_type_id INTEGER,xml_rate TEXT,xml_quota TEXT,is_fiscal_ready INTEGER,use_for_administrative INTEGER,use_for_fiscal INTEGER,fiscal_notes TEXT UNIQUE,updated_at TEXT)');
$db->query('CREATE TABLE clients (id INTEGER PRIMARY KEY AUTOINCREMENT,company_name TEXT,type TEXT,created_date TEXT,starred_by TEXT,group_ids TEXT,deleted INTEGER,is_lead INTEGER,lead_status_id INTEGER,owner_id INTEGER,created_by INTEGER,sort INTEGER,lead_source_id INTEGER,last_lead_status TEXT,stripe_customer_id TEXT,stripe_card_ending_digit INTEGER,currency TEXT,disable_online_payment INTEGER,managers TEXT)');
$db->query('CREATE TABLE fiscal_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT,profile_type TEXT,client_id INTEGER,rfc TEXT,legal_name TEXT,tax_regime_id INTEGER,fiscal_postal_code TEXT,default_cfdi_use_id INTEGER,is_default INTEGER,status TEXT,created_at TEXT,updated_at TEXT)');
$db->query('CREATE TABLE project_status (id INTEGER PRIMARY KEY,title TEXT,title_language_key TEXT,key_name TEXT,icon TEXT,deleted INTEGER)');
$db->query("CREATE TABLE fiscal_documents (id INTEGER PRIMARY KEY,status TEXT); INSERT INTO fiscal_documents(id,status) VALUES(9,'stamped')");
$service=new FiscalInstallationInitializerService($db,$root);$first=$service->run(':memory:',false);
$assert(count($first['catalogs'])===10,'empty installation imports every supported catalog');
$assert($db->table('taxes')->countAllResults()===3,'safe IVA defaults inserted');
$assert($db->table('fiscal_profiles')->countAllResults()===2,'generic receiver drafts inserted');
$assert($db->table('fiscal_profiles')->where('status','draft')->countAllResults()===2,'generic receivers remain pending issuer postal data');
$assert($db->table('project_status')->countAllResults()===4,'canonical project status catalog inserted');
$second=$service->run(':memory:',false);
$assert($db->table('taxes')->countAllResults()===3&&$db->table('fiscal_profiles')->countAllResults()===2,'second execution creates no duplicates');
$assert($db->table('project_status')->countAllResults()===4,'project status initialization is idempotent');
$db->table('project_status')->where('id',4)->update(['title'=>'Personalizado','title_language_key'=>'custom','key_name'=>'custom']);
try{(new ProjectStatusInitializerService($db))->initialize(false);throw new RuntimeException('FAIL custom project status overwritten');}catch(RuntimeException$e){$assert(str_contains($e->getMessage(),'custom/incompatible'),'custom project status collision blocks without overwrite');}
$assert($db->table('project_status')->where(['id'=>4,'title'=>'Personalizado'])->countAllResults()===1,'custom project status remains unchanged');
$assert($db->table('fiscal_documents')->where(['id'=>9,'status'=>'stamped'])->countAllResults()===1,'historical fiscal document remains unchanged');
file_put_contents($root.'/tax_codes.csv',"code,name,description\n002,IVA,IVA\n");
try{$service->run(':memory:',false);throw new RuntimeException('FAIL incomplete catalog accepted');}catch(RuntimeException$e){$assert(str_contains($e->getMessage(),'Checksum'),'incomplete or altered catalog fails before baseline writes');}
foreach(glob($root.'/*')?:[]as$file)unlink($file);rmdir($root);echo "passed={$assertions}\n";
