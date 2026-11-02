<?php
declare(strict_types=1);

if (! extension_loaded('sqlite3')) { fwrite(STDERR, "BLOCKED: sqlite3 extension required.\n"); exit(2); }
foreach (['fiscal.environment'=>'development','fiscal.runtimeMode'=>'automated_test','fiscal.pacAdapter'=>'fake','fiscal.allowRealPac'=>'false'] as $key=>$value) { putenv("{$key}={$value}"); $_ENV[$key]=$_SERVER[$key]=$value; }
require dirname(__DIR__).'/bootstrap.php';

use App\Services\Fiscal\FiscalSeriesEnvironmentService;
use App\Services\Fiscal\FiscalSeriesIssuerValidator;
use Config\Database;

$cfg=['DSN'=>'','hostname'=>'','username'=>'','password'=>'','database'=>':memory:','DBDriver'=>'SQLite3','DBPrefix'=>'','pConnect'=>false,'DBDebug'=>true,'foreignKeys'=>false];
$database=config(Database::class);$database->default=$cfg;$db=Database::connect($cfg,true);$db->initialize();
if ($db->DBDriver!=='SQLite3'||$db->getDatabase()!==':memory:') throw new RuntimeException('Unsafe test target.');
$db->query('CREATE TABLE company(id INTEGER PRIMARY KEY,deleted INTEGER,is_default INTEGER)');
$db->query('CREATE TABLE fiscal_profiles(id INTEGER PRIMARY KEY,profile_type TEXT,company_id INTEGER,environment TEXT,status TEXT,is_default INTEGER,valid_from TEXT,valid_to TEXT)');
$db->query('CREATE TABLE fiscal_series(id INTEGER PRIMARY KEY AUTOINCREMENT,issuer_profile_id INTEGER,environment TEXT,document_type TEXT,series TEXT,initial_folio INTEGER,current_folio INTEGER,is_default INTEGER,is_active INTEGER,deleted INTEGER)');
$db->table('company')->insertBatch([['id'=>7,'deleted'=>0,'is_default'=>1],['id'=>8,'deleted'=>0,'is_default'=>0]]);
$db->table('fiscal_profiles')->insertBatch([
 ['id'=>1,'profile_type'=>'issuer','company_id'=>7,'environment'=>'development','status'=>'ready','is_default'=>1,'valid_from'=>null,'valid_to'=>null],
 ['id'=>2,'profile_type'=>'issuer','company_id'=>7,'environment'=>'production','status'=>'ready','is_default'=>0,'valid_from'=>null,'valid_to'=>null],
 ['id'=>3,'profile_type'=>'issuer','company_id'=>8,'environment'=>'development','status'=>'ready','is_default'=>0,'valid_from'=>null,'valid_to'=>null],
 ['id'=>4,'profile_type'=>'issuer','company_id'=>7,'environment'=>'development','status'=>'incomplete','is_default'=>0,'valid_from'=>null,'valid_to'=>null],
]);
$evaluate=static fn(int$id):array=>['is_ready'=>$id!==4,'errors'=>$id===4?['Falta código postal fiscal.']:[]];
$validator=new FiscalSeriesIssuerValidator($db,$evaluate,(object)['environment'=>'development']);
$pass=0;$fail=0;$ok=static function(bool$condition,string$message)use(&$pass,&$fail):void{if($condition){$pass++;echo"PASS {$message}\n";}else{$fail++;echo"FAIL {$message}\n";}};
$ok($validator->validate(1,7,'development')['valid'],'emisor elegible del ambiente actual');
$otherEnvironment=$validator->validate(2,7,'development');$ok(!$otherEnvironment['valid']&&str_contains(implode(' ',$otherEnvironment['errors']),'production'),'emisor de otro ambiente informa incompatibilidad');
$otherCompany=$validator->validate(3,7,'development');$ok(!$otherCompany['valid']&&str_contains(implode(' ',$otherCompany['errors']),'otra compañía'),'emisor de otra compañía queda rechazado');
$incomplete=$validator->validate(4,7,'development');$ok(!$incomplete['valid']&&str_contains(implode(' ',$incomplete['errors']),'código postal'),'emisor incompleto conserva diagnóstico de readiness');
$ok($validator->validate(1,7,'development')['valid']&&(int)$db->table('fiscal_profiles')->where('id',1)->get()->getRow()->is_default===1,'emisor predeterminado válido permanece elegible');
$db->table('fiscal_series')->insert(['issuer_profile_id'=>1,'environment'=>'development','document_type'=>'ingreso','series'=>'LAB','initial_folio'=>1,'current_folio'=>0,'is_default'=>1,'is_active'=>1,'deleted'=>0]);$seriesId=(int)$db->insertID();
$db->table('fiscal_series')->where('id',$seriesId)->update(['series'=>'LABEDIT']);$saved=(new FiscalSeriesEnvironmentService($db))->activeForIssuer(1,'ingreso','development')->getRow();
$ok($saved&&$saved->series==='LABEDIT'&&(int)$saved->issuer_profile_id===1,'alta y edición de serie preservan emisor y ambiente');
$before=$db->table('fiscal_series')->countAllResults();if($validator->validate(2,7,'development')['valid'])$db->table('fiscal_series')->insert(['issuer_profile_id'=>2,'environment'=>'development']);
$ok($db->table('fiscal_series')->countAllResults()===$before,'asociación incompatible se rechaza antes de persistir');
$issuerController=(string)file_get_contents(APPPATH.'Controllers/Fiscal/Issuers.php');
$ok(str_contains($issuerController,"['environment']=FiscalRuntimeContext::fiscalEnvironment"),'alta de emisor congela el ambiente runtime');
echo "TOTAL PASS={$pass} FAIL={$fail}; SQLite memory only, no network.\n";exit($fail===0?0:1);
