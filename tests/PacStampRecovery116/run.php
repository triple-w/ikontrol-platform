<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use App\Domain\Fiscal\Pac\StampRequest;
use App\Services\Fiscal\FiscalStampAttemptDiagnosticService;
use App\Services\Fiscal\Pac\PacContingencyStorageService;
use App\Services\Fiscal\Pac\PacSecretVault;
use App\Services\Fiscal\Pac\TimbradorXpressRestAdapter;
use CodeIgniter\Database\Config as DbConfig;
use Config\Fiscal;
use Config\TimbradorXpress;

$n=0;$ok=static function(bool$v,string$m)use(&$n):void{if(!$v)throw new RuntimeException('FAIL '.$m);$n++;echo"PASS {$m}\n";};
$pac=(new ReflectionClass(TimbradorXpress::class))->newInstanceWithoutConstructor();
$pac->environment='sandbox';$pac->apiKey='synthetic-key';$pac->baseUrl=TimbradorXpress::SANDBOX_URL;$pac->soapWsdl=TimbradorXpress::SANDBOX_SOAP_WSDL;$pac->connectTimeout=2;$pac->requestTimeout=5;$pac->productionEnabled=false;$pac->maxSignedXmlBytes=2097152;$pac->maxResponseBytes=8388608;$pac->maxPdfBytes=10485760;$pac->pdfTemplate='Principal';
$fiscal=(new ReflectionClass(Fiscal::class))->newInstanceWithoutConstructor();
$fiscal->runtimeMode='integration';$fiscal->enabled=true;$fiscal->stampingEnabled=true;$fiscal->environment='development';$fiscal->pacAdapter='timbradorxpress';$fiscal->allowRealPac=true;$fiscal->labMode=false;
$request=new StampRequest(7,'<signed/>',hash('sha256','<signed/>'),'timbradorxpress','sandbox',str_repeat('a',64),"-----BEGIN PRIVATE KEY-----\nsynthetic\n-----END PRIVATE KEY-----");
$response=static fn(string$body,int$status=200)=>new class($body,$status){public function __construct(private string$b,private int$s){}public function getBody():string{return$this->b;}public function getStatusCode():int{return$this->s;}public function getHeaderLine(string$n):string{return'application/json';}};
$client=static fn(mixed$result)=>new class($result){public function __construct(private mixed$r){}public function post():object{if($this->r instanceof Throwable)throw$this->r;return$this->r;}};
$body=json_encode(['code'=>'200','message'=>'OK','data'=>'provider-data'],JSON_THROW_ON_ERROR);
$stored=(new TimbradorXpressRestAdapter($pac,$client($response($body)),$fiscal,static fn()=>['path'=>'fiscal/pac-contingency/'.str_repeat('a',48).'.enc','hash'=>str_repeat('b',64)]))->stamp($request);
$ok(!$stored->transportError&&$stored->code==='200'&&$stored->metadata['evidence_storage']==='stored','valid PAC response remains valid when evidence is stored');
$failed=(new TimbradorXpressRestAdapter($pac,$client($response($body)),$fiscal,static function(){throw new RuntimeException('synthetic storage failure');}))->stamp($request);
$ok(!$failed->transportError&&$failed->code==='200'&&$failed->metadata['evidence_storage']==='failed','local evidence failure is not classified as transport failure');
$timeout=(new TimbradorXpressRestAdapter($pac,$client(new RuntimeException('operation timed out')),$fiscal,static fn()=>[]))->stamp($request);
$ok($timeout->transportError&&$timeout->timeout&&$timeout->metadata['request_sent']===null,'timeout remains uncertain and never claims request was not sent');
$connection=(new TimbradorXpressRestAdapter($pac,$client(new RuntimeException('connection refused')),$fiscal,static fn()=>[]))->stamp($request);
$ok($connection->transportError&&!$connection->timeout&&$connection->metadata['request_sent']===null,'connection exception remains conservatively unknown');
$invalid=(new TimbradorXpressRestAdapter($pac,$client($response('<html>bad gateway</html>',502)),$fiscal,static fn()=>['path'=>'fiscal/pac-contingency/'.str_repeat('c',48).'.enc']))->stamp($request);
$ok(!$invalid->transportError&&$invalid->metadata['parsing_phase']==='outer_response_invalid'&&$invalid->httpStatus===502,'received invalid response is distinct from transport failure');

if(!extension_loaded('sqlite3')){fwrite(STDERR,"BLOCKED diagnostic fixture: sqlite3 extension is required.\n");exit(2);}
$db=DbConfig::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
$db->query('CREATE TABLE fiscal_stamp_attempts(id INTEGER PRIMARY KEY,fiscal_document_id INTEGER,status TEXT,environment TEXT,error_category TEXT,parsing_phase TEXT,http_status INTEGER,provider_code TEXT,request_sent INTEGER,response_received_at TEXT,requires_reconciliation INTEGER,retryable INTEGER,uuid TEXT,contingency_path TEXT)');
$db->query('CREATE TABLE fiscal_documents(id INTEGER PRIMARY KEY,status TEXT,environment TEXT)');
$db->query('CREATE TABLE fiscal_document_stamps(id INTEGER PRIMARY KEY,fiscal_document_id INTEGER,uuid TEXT,stamped_xml_artifact_id INTEGER,stamped_xml_sha256 TEXT)');
$db->query('CREATE TABLE fiscal_document_artifacts(id INTEGER PRIMARY KEY,artifact_type TEXT,validation_status TEXT,sha256 TEXT)');
$db->query('CREATE TABLE fiscal_stamp_movements(id INTEGER PRIMARY KEY,pac_attempt_id INTEGER,movement_type TEXT,quantity INTEGER,reserved_before INTEGER,reserved_after INTEGER,available_before INTEGER,available_after INTEGER)');
$db->table('fiscal_documents')->insert(['id'=>10,'status'=>'stamp_status_unknown','environment'=>'development']);
$db->table('fiscal_stamp_attempts')->insert(['id'=>3,'fiscal_document_id'=>10,'status'=>'transport_unknown','environment'=>'development','requires_reconciliation'=>1,'retryable'=>0]);
$db->table('fiscal_stamp_movements')->insert(['id'=>1,'pac_attempt_id'=>3,'movement_type'=>'document_reservation','quantity'=>1,'reserved_before'=>0,'reserved_after'=>1,'available_before'=>10,'available_after'=>9]);
$diagnostic=new FiscalStampAttemptDiagnosticService($db,new PacSecretVault(str_repeat('k',32),$fiscal),sys_get_temp_dir().'/missing-pac-evidence');
$noUuid=$diagnostic->inspect(3);
$ok($noUuid['reconciliation']['method']==='manual_provider_review'&&!$noUuid['reconciliation']['automatic_resend_allowed'],'attempt without UUID/evidence stays blocked without resend');
$db->table('fiscal_stamp_attempts')->where('id',3)->update(['uuid'=>'11111111-1111-1111-1111-111111111111']);
$withUuid=$diagnostic->inspect(3);
$ok($withUuid['reconciliation']['method']==='uuid_status_query'&&$withUuid['wallet']['reservation_recorded'],'UUID enables status query while reservation remains traceable');
$evidenceRoot=sys_get_temp_dir().'/pac-recovery-'.bin2hex(random_bytes(4));
$vault=new PacSecretVault(str_repeat('k',32),$fiscal);$storedEvidence=(new PacContingencyStorageService($vault,$evidenceRoot))->storePayload(3,$body);
$db->table('fiscal_stamp_attempts')->where('id',3)->update(['uuid'=>null,'contingency_path'=>$storedEvidence['path']]);
$withEvidence=(new FiscalStampAttemptDiagnosticService($db,$vault,$evidenceRoot))->inspect(3);
$ok($withEvidence['evidence']['valid']&&$withEvidence['reconciliation']['method']==='stored_evidence','encrypted provider evidence is validated without exposing its payload');
foreach(glob($evidenceRoot.'/*')?:[]as$file)unlink($file);rmdir($evidenceRoot);
$source=file_get_contents(APPPATH.'Services/Fiscal/Pac/FiscalStampReconciliationService.php');
$stamping=file_get_contents(APPPATH.'Services/Fiscal/Pac/FiscalStampingService.php');
$ok(str_contains($source,"outcome'=>'manual_provider_review_required'")&&str_contains($source,"'external_query_executed'=>false"),'reconciliation without UUID performs no external status query');
$ok(str_contains($stamping,"'status'=>'response_validated_local'")&&str_contains($stamping,"'requires_reconciliation' => 0"),'validated UUID is frozen before optional evidence and cleared only after successful persistence');
$ok(str_contains($stamping,'reserveForAttempt')&&str_contains($stamping,'consumeReservation')&&str_contains($stamping,"'existing'"),'existing idempotency and single reservation/consumption guards remain active');
echo"passed={$n}\n";
