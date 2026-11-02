<?php
declare(strict_types=1);

if(!extension_loaded('sqlite3')){fwrite(STDERR,"BLOCKED: sqlite3 extension is required.\n");exit(2);}

foreach(['fiscal.enabled'=>'false','fiscal.stampingEnabled'=>'false','fiscal.allowRealPac'=>'false','fiscal.runtimeMode'=>'automated_test','fiscal.pacAdapter'=>'fake','fiscal.environment'=>'development'] as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$_SERVER[$key]=$value;}
require dirname(__DIR__).'/bootstrap.php';
if(!function_exists('get_current_utc_time')){function get_current_utc_time():string{return gmdate('Y-m-d H:i:s');}}

use App\Services\Fiscal\FiscalEnvironmentSelectorService;
use App\Services\Fiscal\FiscalRuntimeDiagnosticService;
use App\Services\Fiscal\Pac\FiscalPacCreditService;
use App\Services\Fiscal\Stamps\FiscalStampBalancePresentationService;
use App\Services\Fiscal\Stamps\FiscalStampWalletResolutionService;
use Config\Database;
use Config\TimbradorXpress;

$cfg=['DSN'=>'','hostname'=>'','username'=>'','password'=>'','database'=>':memory:','DBDriver'=>'SQLite3','DBPrefix'=>'','pConnect'=>false,'DBDebug'=>true,'foreignKeys'=>false];
$dbConfig=config(Database::class);$dbConfig->default=$cfg;$db=Database::connect($cfg,true);$db->initialize();
if($db->DBDriver!=='SQLite3'||$db->getDatabase()!==':memory:')throw new RuntimeException('Unsafe test target: SQLite memory database required.');

$sql=[
 'CREATE TABLE fiscal_profiles(id INTEGER PRIMARY KEY,profile_type TEXT,company_id INTEGER,environment TEXT,status TEXT,is_default INTEGER,valid_from TEXT,valid_to TEXT,rfc TEXT,deleted INTEGER)',
 'CREATE TABLE fiscal_series(id INTEGER PRIMARY KEY,issuer_profile_id INTEGER,environment TEXT,document_type TEXT,is_active INTEGER,deleted INTEGER)',
 'CREATE TABLE fiscal_stamp_accounts(id INTEGER PRIMARY KEY,issuer_profile_id INTEGER,environment TEXT,available_balance INTEGER,reserved_balance INTEGER,status TEXT)',
 'CREATE TABLE fiscal_stamp_movements(id INTEGER PRIMARY KEY,stamp_account_id INTEGER,movement_type TEXT,quantity INTEGER,fiscal_document_id INTEGER,created_at TEXT)',
 'CREATE TABLE fiscal_pac_credit_consultations(id INTEGER PRIMARY KEY,issuer_profile_id INTEGER,provider TEXT,environment TEXT,available_credits INTEGER,provider_code TEXT,provider_message TEXT,http_status INTEGER,response_sha256 TEXT,consulted_at TEXT,created_by INTEGER)',
 'CREATE TABLE fiscal_stamp_attempts(id INTEGER PRIMARY KEY,status TEXT,requires_reconciliation INTEGER)',
 'CREATE TABLE fiscal_cancellation_requests(id INTEGER PRIMARY KEY,status TEXT,requires_reconciliation INTEGER)',
 'CREATE TABLE fiscal_pdf_generation_attempts(id INTEGER PRIMARY KEY,status TEXT,requires_reconciliation INTEGER)',
 'CREATE TABLE fiscal_environment_change_requests(id INTEGER PRIMARY KEY AUTOINCREMENT,requested_environment TEXT,current_environment TEXT,status TEXT,reason TEXT,before_context_id TEXT,after_context_id TEXT,preflight_json TEXT,requested_by INTEGER,requested_at TEXT,verified_at TEXT)'
];foreach($sql as$query)$db->query($query);

$db->table('fiscal_profiles')->insertBatch([
 ['id'=>1,'profile_type'=>'issuer','company_id'=>7,'environment'=>'development','status'=>'ready','is_default'=>1,'valid_from'=>null,'valid_to'=>null,'rfc'=>'AAA010101AAA','deleted'=>0],
 ['id'=>2,'profile_type'=>'issuer','company_id'=>7,'environment'=>'production','status'=>'ready','is_default'=>1,'valid_from'=>null,'valid_to'=>null,'rfc'=>'AAA010101AAA','deleted'=>0],
 ['id'=>3,'profile_type'=>'issuer','company_id'=>8,'environment'=>'development','status'=>'ready','is_default'=>1,'valid_from'=>null,'valid_to'=>null,'rfc'=>'BBB010101BBB','deleted'=>0],
]);
$db->table('fiscal_series')->insertBatch([
 ['id'=>1,'issuer_profile_id'=>1,'environment'=>'development','document_type'=>'ingreso','is_active'=>1,'deleted'=>0],
 ['id'=>2,'issuer_profile_id'=>2,'environment'=>'production','document_type'=>'ingreso','is_active'=>1,'deleted'=>0],
]);
$db->table('fiscal_stamp_accounts')->insertBatch([
 ['id'=>1,'issuer_profile_id'=>1,'environment'=>'development','available_balance'=>18,'reserved_balance'=>2,'status'=>'active'],
 ['id'=>2,'issuer_profile_id'=>2,'environment'=>'production','available_balance'=>90,'reserved_balance'=>0,'status'=>'active'],
 ['id'=>3,'issuer_profile_id'=>3,'environment'=>'development','available_balance'=>3,'reserved_balance'=>0,'status'=>'active'],
 ['id'=>4,'issuer_profile_id'=>3,'environment'=>'development','available_balance'=>4,'reserved_balance'=>0,'status'=>'active'],
]);
$db->table('fiscal_stamp_movements')->insert(['id'=>1,'stamp_account_id'=>1,'movement_type'=>'document_consumption','quantity'=>5,'fiscal_document_id'=>10,'created_at'=>'2026-10-22 10:00:00']);
$db->table('fiscal_pac_credit_consultations')->insertBatch([
 ['id'=>1,'issuer_profile_id'=>1,'provider'=>'timbradorxpress','environment'=>'development','available_credits'=>777,'provider_code'=>'200','consulted_at'=>'2026-10-22 10:00:00'],
 ['id'=>2,'issuer_profile_id'=>2,'provider'=>'timbradorxpress','environment'=>'production','available_credits'=>333,'provider_code'=>'200','consulted_at'=>'2026-10-22 11:00:00'],
]);

$passed=0;$failed=0;$ok=function(bool$condition,string$message)use(&$passed,&$failed):void{if($condition){$passed++;echo"[PASS] {$message}\n";}else{$failed++;echo"[FAIL] {$message}\n";}};
$fiscal=static fn(string$environment='development',string$adapter='timbradorxpress',bool$allow=true,bool$flag=true):object=>(object)[
 'runtimeMode'=>$environment==='production'?'production':'integration','environment'=>$environment,'pacAdapter'=>$adapter,
 'enabled'=>true,'stampingEnabled'=>true,'allowRealPac'=>$allow,'environmentSelectorEnabled'=>$flag,'environmentSelectorPreviewOnly'=>true,
];
$pac=static fn(string$transport,string$url,string$key='key',bool$productionEnabled=true):object=>(object)[
 'environment'=>$transport,'baseUrl'=>$url,'apiKey'=>$key,'productionEnabled'=>$productionEnabled,
];
$diagnostic=new FiscalRuntimeDiagnosticService();
$dev=$diagnostic->inspect($fiscal(),$pac('sandbox',TimbradorXpress::SANDBOX_URL));
$devSafe=$diagnostic->inspect($fiscal(),$pac('sandbox',TimbradorXpress::SANDBOX_URL,'key',false));
$ok($devSafe['provider_host']==='dev.timbradorxpress.mx'&&$devSafe['stamping_operation']==='timbrarConSello','diagnostic exposes sandbox host and stamping operation without transport');
$ok($devSafe['production_disabled']===true&&$devSafe['provider_cost_policy']==='NOT_VERIFIED','diagnostic keeps production disabled and provider cost unverified');
$ok($dev['fiscal_environment']==='development'&&$dev['transport_environment']==='sandbox'&&$dev['endpoint_allowed'],'development resuelve ambiente lógico, transporte y endpoint permitidos');
$prod=$diagnostic->inspect($fiscal('production'),$pac('production',TimbradorXpress::PRODUCTION_URL));
$ok($prod['fiscal_environment']==='production'&&$prod['pac_operational']&&$prod['stamping_operational'],'production requiere y reconoce opt-in, credencial y timbrado');
$mismatch=$diagnostic->inspect($fiscal(),$pac('production',TimbradorXpress::SANDBOX_URL));
$ok(!$mismatch['coherent']&&!$mismatch['checks']['transport_matches_environment'],'incompatibilidad lógico/transporte bloquea el contexto');
$missing=$diagnostic->inspect($fiscal(),$pac('sandbox',TimbradorXpress::SANDBOX_URL,''));
$ok(!$missing['credential_present']&&!$missing['pac_operational'],'credencial ausente no se presenta como PAC operativo');
$badEndpoint=$diagnostic->inspect($fiscal(),$pac('sandbox','https://invalid.example/pac/'));
$ok(!$badEndpoint['endpoint_allowed']&&!$badEndpoint['pac_operational'],'endpoint fuera de allowlist queda rechazado');
$noOptIn=$diagnostic->inspect($fiscal('production','timbradorxpress',false),$pac('production',TimbradorXpress::PRODUCTION_URL));
$ok(!$noOptIn['pac_operational']&&in_array('El PAC real requiere habilitación explícita.',$noOptIn['blocking_reasons'],true),'PAC real sin opt-in queda bloqueado con motivo concreto');
$ok((new FiscalStampWalletResolutionService($db))->resolve(2,'development')['status']==='environment_mismatch','wallet de emisor de otro ambiente queda rechazada');
$ambiguous=(new FiscalStampWalletResolutionService($db))->resolve(3,'development');
$ok($ambiguous['status']==='account_ambiguous'&&$ambiguous['account']===null,'wallet duplicada o ambigua falla de manera segura');
$projection=(new FiscalStampBalancePresentationService($db))->forIssuer(1,'development');
$ok($projection['commercial']['available']===18&&$projection['commercial']['consumed']===5&&$projection['pac']['available_credits']===777,'saldo comercial y créditos PAC se presentan separados para emisor y ambiente');
$ok((new FiscalStampBalancePresentationService($db))->forIssuer(2,'production')['pac']['available_credits']===333,'créditos development no se muestran como production');
$creditFiscal=(new ReflectionClass(Config\Fiscal::class))->newInstanceWithoutConstructor();foreach((array)$fiscal() as$key=>$value)$creditFiscal->{$key}=$value;
$creditPac=(new ReflectionClass(Config\TimbradorXpress::class))->newInstanceWithoutConstructor();foreach(['environment'=>'sandbox','baseUrl'=>TimbradorXpress::SANDBOX_URL,'apiKey'=>'fake-key-not-sent','productionEnabled'=>false,'connectTimeout'=>2,'requestTimeout'=>5]as$key=>$value)$creditPac->{$key}=$value;
$fakeClient=new class{public int$calls=0;public function post(string$url,array$options):object{$this->calls++;return new class{public function getBody():string{return'{"code":"200","message":"ok","data":{"creditos":55}}';}public function getStatusCode():int{return 200;}};}};
$credit=(new FiscalPacCreditService($db,$creditPac,$fakeClient,$creditFiscal))->consult(1,9);
$ok($credit['available_credits']===55&&$credit['environment']==='development'&&$fakeClient->calls===1,'consulta de créditos usa contexto, emisor y ambiente correctos con adaptador stub');
$wrongIssuerBlocked=false;try{(new FiscalPacCreditService($db,$creditPac,$fakeClient,$creditFiscal))->consult(2,9);}catch(RuntimeException$e){$wrongIssuerBlocked=str_contains($e->getMessage(),'ambiente PAC activo');}
$ok($wrongIssuerBlocked&&$fakeClient->calls===1,'consulta PAC bloquea emisor de otro ambiente antes del transporte');

$selector=new FiscalEnvironmentSelectorService($db,$fiscal(),$pac('sandbox',TimbradorXpress::SANDBOX_URL));
$preview=$selector->inspect('production');
$ok($preview['issuer']['id']===2&&$preview['series']['ready']&&$preview['credential_check']==='requires_server_readback','preflight destino usa emisor y serie production sin reutilizar la credencial development');
$db->table('fiscal_series')->where('id',2)->delete();$withoutTargetSeries=$selector->inspect('production');
$ok(!$withoutTargetSeries['series']['ready'],'serie de otro ambiente no satisface el preflight del destino');
$db->table('fiscal_series')->insert(['id'=>2,'issuer_profile_id'=>2,'environment'=>'production','document_type'=>'ingreso','is_active'=>1,'deleted'=>0]);
$db->table('fiscal_stamp_attempts')->insert(['id'=>1,'status'=>'sending','requires_reconciliation'=>0]);
$blocked=false;try{$selector->requestPreview('development','Cambio fiscal controlado','CAMBIAR AMBIENTE',9,true);}catch(RuntimeException$e){$blocked=$e->getMessage()==='FISCAL_ENVIRONMENT_OPERATIONS_IN_PROGRESS';}
$ok($blocked,'cambio se bloquea cuando existen operaciones fiscales en curso');
$db->table('fiscal_stamp_attempts')->where('id',1)->delete();
$unauthorized=false;try{$selector->requestPreview('development','Cambio fiscal controlado','CAMBIAR AMBIENTE',9,false);}catch(RuntimeException$e){$unauthorized=$e->getMessage()==='FISCAL_ENVIRONMENT_PERMISSION_REQUIRED';}
$ok($unauthorized,'usuario sin permiso específico queda bloqueado');
$invalid=false;try{$selector->requestPreview('production','Cambio fiscal controlado','CAMBIAR AMBIENTE',9,true);}catch(RuntimeException$e){$invalid=$e->getMessage()==='FISCAL_ENVIRONMENT_CONFIRMATION_INVALID';}
$ok($invalid,'production exige confirmación reforzada exacta');
$saved=$selector->requestPreview('development','Validación local reversible','CAMBIAR AMBIENTE',9,true);
$audit=$db->table('fiscal_environment_change_requests')->where('id',$saved['id'])->get(1)->getRow();
$ok($audit&&$audit->status==='preview_only'&&$audit->before_context_id===$audit->after_context_id,'solicitud queda auditada con contexto antes/después sin activar cambios');
$ok(!$saved['environment_changed']&&$saved['read_back']['context_id']===$saved['preview']['current']['context_id'],'selector persiste y verifica read-back del contexto inmutable');
$disabled=new FiscalEnvironmentSelectorService($db,$fiscal('development','timbradorxpress',true,false),$pac('sandbox',TimbradorXpress::SANDBOX_URL));
$flagBlocked=false;try{$disabled->requestPreview('development','Validación local reversible','CAMBIAR AMBIENTE',9,true);}catch(RuntimeException$e){$flagBlocked=$e->getMessage()==='FISCAL_ENVIRONMENT_SELECTOR_DISABLED';}
$ok($flagBlocked,'feature flag deshabilitada impide usar el selector');
$configSource=file_get_contents(APPPATH.'Config/Fiscal.php');$ok(str_contains($configSource,"ENVIRONMENT !== 'production'")&&str_contains($configSource,'environmentSelectorPreviewOnly = true'),'feature flag queda forzosamente apagada en runtime production y el driver distribuido es preview-only');
$routes=file_get_contents(APPPATH.'Config/FiscalRoutes.php');$controller=file_get_contents(APPPATH.'Controllers/Fiscal/EnvironmentSelector.php');$roles=file_get_contents(APPPATH.'Controllers/Roles.php');$permissionsView=file_get_contents(APPPATH.'Views/roles/permissions.php');
$ok(str_contains($routes,"['filter' => 'csrf']")&&str_contains($controller,'fiscal.environment.manage')&&str_contains($controller,'$this->login_user->is_admin')&&str_contains($roles,'fiscal.environment.manage')&&str_contains($permissionsView,'fiscal.environment.manage'),'selector exige CSRF, administrador y permiso específico registrable');
$payment=file_get_contents(APPPATH.'FiscalServices/PaymentComplementFiscalDocumentService.php');$credit=file_get_contents(APPPATH.'Services/Fiscal/CreditNoteService.php');
$ok(str_contains($payment,'issuer_profile_id=? AND environment=?')&&str_contains($credit,'issuer_profile_id=? AND environment=?'),'complementos y notas de crédito filtran series por emisor y ambiente');
$ok(str_contains($credit,'$source->environment')&&str_contains($credit,'otro ambiente fiscal'),'documentos históricos conservan su ambiente y no se reinterpretan');

echo "TOTAL PASS={$passed} FAIL={$failed}. SQLite memory only; no PAC/SAT adapter or network request created.\n";
exit($failed===0?0:1);
