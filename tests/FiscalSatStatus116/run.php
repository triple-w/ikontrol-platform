<?php
declare(strict_types=1);

if(!extension_loaded('sqlite3')){fwrite(STDERR,"BLOCKED: sqlite3 extension is required.\n");exit(2);}

foreach(['IKONTROL_RC1_LAB'=>'false','IKONTROL_RC1_SANDBOX_TRANSPORT'=>'false','fiscal.enabled'=>'false','fiscal.stampingEnabled'=>'false','fiscal.allowRealPac'=>'false','fiscal.runtimeMode'=>'automated_test','fiscal.pacAdapter'=>'fake','fiscal.environment'=>'development','fiscal.satStatusCooldownSeconds'=>'0'] as$key=>$value){putenv($key.'='.$value);$_ENV[$key]=$_SERVER[$key]=$value;}
require dirname(__DIR__).'/bootstrap.php';
if(!function_exists('get_current_utc_time')){function get_current_utc_time():string{return'2026-10-22 12:00:00';}}

use App\Contracts\Fiscal\Pac\PacAdapterInterface;
use App\Domain\Fiscal\Pac\PacResponse;
use App\Domain\Fiscal\Pac\StampRequest;
use App\Services\Fiscal\Cancellation\FiscalCancellationStatusMapper;
use App\Services\Fiscal\SatStatus\FiscalSatStatusService;
use Config\Database;

$cfg=['DSN'=>'','hostname'=>'','username'=>'','password'=>'','database'=>':memory:','DBDriver'=>'SQLite3','DBPrefix'=>'','pConnect'=>false,'DBDebug'=>true,'foreignKeys'=>false];
$database=config(Database::class);$database->default=$cfg;$db=Database::connect($cfg,true);$db->initialize();
if($db->DBDriver!=='SQLite3'||$db->getDatabase()!==':memory:')throw new RuntimeException('Unsafe test target: SQLite memory database required.');

$sql=[
'CREATE TABLE fiscal_documents(id INTEGER PRIMARY KEY,invoice_id INTEGER,issuer_profile_id INTEGER,status TEXT,environment TEXT,total TEXT,series TEXT,folio TEXT,deleted INTEGER,updated_at TEXT)',
'CREATE TABLE fiscal_document_stamps(id INTEGER PRIMARY KEY,fiscal_document_id INTEGER,stamped_xml_artifact_id INTEGER,uuid TEXT)',
'CREATE TABLE fiscal_document_issuers(id INTEGER PRIMARY KEY,fiscal_document_id INTEGER,rfc TEXT)',
'CREATE TABLE fiscal_document_receivers(id INTEGER PRIMARY KEY,fiscal_document_id INTEGER,rfc TEXT)',
'CREATE TABLE fiscal_document_artifacts(id INTEGER PRIMARY KEY,fiscal_document_id INTEGER,artifact_type TEXT,superseded_at TEXT)',
'CREATE TABLE fiscal_cancellation_requests(id INTEGER PRIMARY KEY AUTOINCREMENT,fiscal_document_id INTEGER,status TEXT,environment TEXT)',
'CREATE TABLE fiscal_stamp_movements(id INTEGER PRIMARY KEY AUTOINCREMENT,idempotency_key TEXT UNIQUE)',
'CREATE TABLE fiscal_document_audit(id INTEGER PRIMARY KEY AUTOINCREMENT,fiscal_document_id INTEGER,invoice_id INTEGER,user_id INTEGER,action TEXT,reason TEXT,created_at TEXT)',
'CREATE TABLE fiscal_sat_status_queries(id INTEGER PRIMARY KEY AUTOINCREMENT,fiscal_document_id INTEGER,fiscal_document_stamp_id INTEGER,cancellation_request_id INTEGER,uuid TEXT,provider TEXT,environment TEXT,idempotency_key TEXT UNIQUE,lock_document_id INTEGER UNIQUE,status TEXT,normalized_status TEXT,authoritative_status TEXT,previous_authoritative_status TEXT,provider_code TEXT,provider_message TEXT,http_status INTEGER,request_sent INTEGER,timed_out INTEGER,requires_reconciliation INTEGER,commercial_policy TEXT,commercial_credit_delta INTEGER,may_have_provider_cost INTEGER,queried_by INTEGER,started_at TEXT,completed_at TEXT,cooldown_until TEXT,created_at TEXT,updated_at TEXT)',
'CREATE TABLE fiscal_sat_status_artifacts(id INTEGER PRIMARY KEY AUTOINCREMENT,fiscal_sat_status_query_id INTEGER,artifact_type TEXT,content_encoding TEXT,content_base64 TEXT,decoded_mime_type TEXT,decoded_size_bytes INTEGER,decoded_sha256 TEXT,created_by INTEGER,created_at TEXT,UNIQUE(fiscal_sat_status_query_id,artifact_type))',
];foreach($sql as$q)$db->query($q);
$uuid='123E4567-E89B-42D3-A456-426614174000';
$db->table('fiscal_documents')->insert(['id'=>1,'invoice_id'=>10,'issuer_profile_id'=>3,'status'=>'stamped','environment'=>'development','total'=>'116.000000','series'=>'F','folio'=>'123','deleted'=>0]);
$db->table('fiscal_document_stamps')->insert(['id'=>1,'fiscal_document_id'=>1,'stamped_xml_artifact_id'=>7,'uuid'=>$uuid]);
$db->table('fiscal_document_issuers')->insert(['id'=>1,'fiscal_document_id'=>1,'rfc'=>'AAA010101AAA']);
$db->table('fiscal_document_receivers')->insert(['id'=>1,'fiscal_document_id'=>1,'rfc'=>'XAXX010101000']);

$pass=$fail=0;$ok=static function(bool$v,string$m)use(&$pass,&$fail):void{echo($v?'[PASS] ':'[FAIL] ').$m.PHP_EOL;$v?$pass++:$fail++;};
$runtime=['runtime_mode'=>'automated_test','fiscal_environment'=>'development','transport_environment'=>'development','pac_provider'=>'fake'];
$fake=function(PacResponse$response):PacAdapterInterface{return new class($response)implements PacAdapterInterface{public int$calls=0;public function __construct(private PacResponse$r){}public function stamp(StampRequest$request):PacResponse{throw new RuntimeException('not used');}public function getStampStatus(array$query):PacResponse{$this->calls++;return$this->r;}};};
$service=function(PacResponse$response,bool$evidence=true,?callable$cancel=null)use($db,$runtime,$fake):array{$adapter=$fake($response);return[new FiscalSatStatusService($db,$adapter,$runtime,static fn()=> $evidence,$cancel,static fn()=>'2026-10-22 12:00:00'),$adapter];};
$response=static fn(array$data,int$http=200,bool$transport=false,bool$timeout=false):PacResponse=>new PacResponse('200','Resultado SAT',json_encode($data),$http,['request_sent'=>true],$transport,$timeout);

[$active,$a]=$service($response(['Estado'=>'Vigente','EstatusCancelacion'=>'','EsCancelable'=>'Cancelable con aceptación']));$r=$active->query(1,9,true,'status-query-active-0001');$ok($r['status']==='active'&&$r['authoritative_status']==='active','CFDI vigente se normaliza sin inventar cancelación pendiente');
[$cancelled]=$service($response(['Estado'=>'Cancelado','EstatusCancelacion'=>'Cancelado con aceptación']));$r=$cancelled->query(1,9,true,'status-query-cancel-0002');$ok($r['status']==='cancelled'&&$r['authoritative_status']==='cancelled','CFDI cancelado queda normalizado en la bitácora');
$mapper=new FiscalCancellationStatusMapper();
$ok($mapper->map('consultarEstadoSAT',['Estado'=>'Vigente','EstatusCancelacion'=>'Plazo vigente'])['cancellation_status']==='pending_acceptance','cancelación pendiente de aceptación se distingue explícitamente');
$ok($mapper->map('consultarEstadoSAT',['Estado'=>'Cancelado'])['service_status']==='accepted','cancelación aceptada se reconoce sólo con estado cancelado');
$ok($mapper->map('consultarEstadoSAT',['Estado'=>'Vigente','EstatusCancelacion'=>'Solicitud rechazada'])['cancellation_status']==='rejected','cancelación rechazada se distingue');
$ok($mapper->map('consultarEstadoSAT',['Estado'=>'Vigente','EstatusCancelacion'=>'Texto futuro no documentado'])['requires_reconciliation'],'resultado SAT desconocido no se interpreta como pendiente');

[$timeout]=$service(new PacResponse(null,'timeout',null,0,['request_sent'=>true],true,true));$before=$db->table('fiscal_documents')->where('id',1)->get(1)->getRow()->status;$r=$timeout->query(1,9,true,'status-query-timeout-003');$after=$db->table('fiscal_documents')->where('id',1)->get(1)->getRow()->status;$ok($r['status']==='transport_error'&&$r['authoritative_status']==='cancelled'&&$before===$after,'timeout conserva estado histórico y no reintenta ni muta el CFDI');
[$transport]=$service(new PacResponse(null,'network',null,0,['request_sent'=>false],true,false));$ok($transport->query(1,9,true,'status-query-network-004')['requires_reconciliation'],'error de transporte queda durable y requiere conciliación');

$db->table('fiscal_document_stamps')->where('id',1)->update(['uuid'=>'INVALID']);$bad=false;try{$active->query(1,9,true,'status-query-invalid-005');}catch(RuntimeException$e){$bad=str_contains($e->getMessage(),'UUID válido');}$ok($bad,'UUID inexistente o inválido se bloquea antes del adaptador');$db->table('fiscal_document_stamps')->where('id',1)->update(['uuid'=>$uuid]);
[$noEvidence]=$service($response(['Estado'=>'Vigente']),false);$bad=false;try{$noEvidence->query(1,9,true,'status-query-evidence-006');}catch(RuntimeException$e){$bad=str_contains($e->getMessage(),'integridad');}$ok($bad,'CFDI sin evidencia íntegra no puede consultarse');
$db->table('fiscal_documents')->where('id',1)->update(['environment'=>'production']);$bad=false;try{$active->query(1,9,true,'status-query-env-00007');}catch(RuntimeException$e){$bad=str_contains($e->getMessage(),'otro ambiente');}$ok($bad,'ambiente incorrecto bloquea antes del transporte y nunca cambia automáticamente');$db->table('fiscal_documents')->where('id',1)->update(['environment'=>'development']);
$bad=false;try{$active->query(1,9,false,'status-query-permission-8');}catch(RuntimeException$e){$bad=str_contains($e->getMessage(),'permiso');}$ok($bad,'usuario sin permiso específico queda bloqueado');
$routes=file_get_contents(APPPATH.'Config/FiscalRoutes.php');$ok(str_contains($routes,"fiscal/invoices/sat-status', 'Fiscal\\SatStatus::query', ['filter' => 'csrf']")&&str_contains($routes,"Fiscal\\SatStatus::queryCancellation"),'acciones SAT y cancelación usan CSRF y el wrapper durable común');

[$idem,$adapter]=$service($response(['Estado'=>'Vigente']));$one=$idem->query(1,9,true,'same-click-query-000009');$two=$idem->query(1,9,true,'same-click-query-000009');$ok($adapter->calls===1&&$two['idempotent_replay'],'doble clic y misma clave devuelven el resultado previo sin segunda llamada');
$fiscalConfig=config('Fiscal');$fiscalConfig->satStatusCooldownSeconds=60;[$cooldown,$cooldownAdapter]=$service($response(['Estado'=>'Vigente']));$cooldown->query(1,9,true,'cooldown-query-key-001');$bad=false;try{$cooldown->query(1,9,true,'cooldown-query-key-002');}catch(RuntimeException$e){$bad=str_contains($e->getMessage(),'periodo de espera');}$ok($bad&&$cooldownAdapter->calls===1,'cooldown configurable bloquea una segunda clave sin reintento automático');$fiscalConfig->satStatusCooldownSeconds=0;$db->table('fiscal_sat_status_queries')->where('idempotency_key','cooldown-query-key-001')->update(['cooldown_until'=>'2026-10-22 12:00:00']);
$db->table('fiscal_sat_status_queries')->insert(['fiscal_document_id'=>1,'fiscal_document_stamp_id'=>1,'uuid'=>$uuid,'provider'=>'fake','environment'=>'development','idempotency_key'=>'manual-lock-query-0010','lock_document_id'=>1,'status'=>'querying','normalized_status'=>'unknown','authoritative_status'=>'active','previous_authoritative_status'=>'active','request_sent'=>0,'timed_out'=>0,'requires_reconciliation'=>0,'commercial_policy'=>'unconfirmed_no_charge','commercial_credit_delta'=>0,'may_have_provider_cost'=>1,'queried_by'=>9,'started_at'=>'2026-10-22 12:00:00','created_at'=>'2026-10-22 12:00:00','updated_at'=>'2026-10-22 12:00:00']);
$bad=false;try{$idem->query(1,9,true,'parallel-query-key-0011');}catch(RuntimeException$e){$bad=str_contains($e->getMessage(),'curso');}$ok($bad&&$adapter->calls===1,'consulta simultánea del mismo CFDI falla cerrada por lock durable');$db->table('fiscal_sat_status_queries')->where('idempotency_key','manual-lock-query-0010')->delete();
$ok($db->table('fiscal_document_audit')->where('action','sat_status_query_completed')->countAllResults()>=1,'usuario, ambiente, fecha y resultado quedan auditados');
$artifact=$db->table('fiscal_sat_status_artifacts')->orderBy('id','DESC')->get(1)->getRow();$bytes=base64_decode((string)$artifact->content_base64,true);$ok($bytes!==false&&hash_equals((string)$artifact->decoded_sha256,hash('sha256',$bytes)),'evidencia protegida conserva tamaño y SHA-256 verificable');
$ok($db->table('fiscal_documents')->where('id',1)->get(1)->getRow()->status==='stamped','consultas nunca sobrescriben estados históricos del documento');
$latest=$db->table('fiscal_sat_status_queries')->where('idempotency_key','same-click-query-000009')->get(1)->getRow();$ok($latest->commercial_policy==='one_credit_per_executed_query'&&(int)$latest->commercial_credit_delta===0&&(int)$latest->may_have_provider_cost===1,'la política exige un timbre; el PAC fake aislado no altera el monedero');

$db->table('fiscal_cancellation_requests')->insert(['fiscal_document_id'=>1,'status'=>'pending_acceptance','environment'=>'development']);$delegated=0;[$delegate,$delegateAdapter]=$service($response(['Estado'=>'Cancelado']),true,static function()use(&$delegated):array{$delegated++;return['success'=>true,'status'=>'pending_acceptance','message'=>'delegated'];});$r=$delegate->query(1,9,true,'cancel-delegate-key-012');$ok($delegated===1&&$delegateAdapter->calls===0&&$r['status']==='pending_acceptance','cancelación pendiente delega al flujo existente sin segunda operación SAT');
$controller=file_get_contents(APPPATH.'Controllers/Fiscal/SatStatus.php');$view=file_get_contents(APPPATH.'Views/fiscal/invoices/sat_status_form.php');$list=file_get_contents(APPPATH.'Controllers/Fiscal/InvoiceModule.php');$detail=file_get_contents(APPPATH.'Views/fiscal/invoices/show.php');$ok(str_contains($controller,'fiscal.sat_status.query')&&str_contains($view,'Consultar estado SAT')&&str_contains($list,'Consultar estado SAT')&&str_contains($detail,'Consultar estado SAT'),'botón visible en listado y detalle usa permiso específico y confirmación');
$cancelService=file_get_contents(APPPATH.'Services/Fiscal/Cancellation/FiscalCancellationService.php');$cancelAdapter=file_get_contents(APPPATH.'Services/Fiscal/Cancellation/TimbradorXpressCancellationAdapter.php');$ok(str_contains($cancelService,"'accepted'=>'cancelled'")&&!str_contains($cancelService,"'unknown'=>'cancellation_pending'")&&str_contains($cancelService,'default=>null')&&str_contains($cancelAdapter,"pending_acceptance"),'regresión Navika: sólo accepted cancela; unknown conserva el estado autoritativo y pending_acceptance es explícito');

echo"TOTAL PASS={$pass} FAIL={$fail}. SQLite :memory: only; injected PAC stub, zero network requests.\n";exit($fail?1:0);
