<?php
declare(strict_types=1);

use Ikontrol\Compatibility\InstanceCompatibilityInspector;

$options=getopt('',['root:','invoice-id::','pretty']);
$root=realpath((string)($options['root']??''));
$invoice=isset($options['invoice-id'])?(int)$options['invoice-id']:null;
$emit=static function(array$data,bool$pretty=false):void{echo "IKONTROL_COMPATIBILITY_JSON_BEGIN\n".json_encode($data,($pretty?JSON_PRETTY_PRINT:0)|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\nIKONTROL_COMPATIBILITY_JSON_END\n";};

try{
    if($root===false||!is_dir($root)||is_link((string)($options['root']??''))||!is_file($root.'/app/Config/Paths.php')||!is_file($root.'/system/Boot.php'))throw new RuntimeException('INSTANCE_ROOT_UNSAFE');
    $contractPath=__DIR__.'/contract-1.1.6.json';$contract=json_decode((string)file_get_contents($contractPath),true,64,JSON_THROW_ON_ERROR);
    require_once __DIR__.'/InstanceCompatibilityInspector.php';
    chdir($root);define('ROOTPATH',rtrim($root,'/\\').DIRECTORY_SEPARATOR);define('FCPATH',ROOTPATH);require ROOTPATH.'app/Config/Paths.php';$paths=new Config\Paths();
    define('APPPATH',realpath($paths->appDirectory).DIRECTORY_SEPARATOR);define('SYSTEMPATH',realpath($paths->systemDirectory).DIRECTORY_SEPARATOR);define('WRITEPATH',realpath($paths->writableDirectory).DIRECTORY_SEPARATOR);define('TESTPATH',ROOTPATH.'tests'.DIRECTORY_SEPARATOR);define('ENVIRONMENT','production');
    putenv('logger.threshold=0');$_ENV['logger.threshold']=$_SERVER['logger.threshold']='0';require SYSTEMPATH.'Boot.php';CodeIgniter\Boot::bootTest($paths);
    $fiscalEnvironment=strtolower(trim((string)env('fiscal.environment','development')));if(in_array($fiscalEnvironment,['local','sandbox'],true))$fiscalEnvironment='development';
    $runtimeMode=strtolower(trim((string)env('fiscal.runtimeMode','integration')));$adapter=strtolower(trim((string)env('fiscal.pacAdapter','')));$transport=strtolower(trim((string)env('TIMBRADORXPRESS_ENVIRONMENT',$fiscalEnvironment==='production'?'production':'sandbox')));$expectedTransport=$fiscalEnvironment==='production'?'production':'sandbox';
    $sandbox=(string)env('TIMBRADORXPRESS_BASE_URL_SANDBOX','https://dev.timbradorxpress.mx/api/rest/servicio/');$production=(string)env('TIMBRADORXPRESS_BASE_URL_PRODUCTION','https://app.timbradorxpress.mx/api/rest/servicio/');$activeEndpoint=$expectedTransport==='production'?$production:$sandbox;$allowedEndpoint=$expectedTransport==='production'?'https://app.timbradorxpress.mx/api/rest/servicio/':'https://dev.timbradorxpress.mx/api/rest/servicio/';$endpointAllowed=hash_equals($allowedEndpoint,$activeEndpoint);
    $credentialKey=$expectedTransport==='production'?'TIMBRADORXPRESS_APIKEY_PRODUCTION':'TIMBRADORXPRESS_APIKEY_SANDBOX';$credentialPresent=trim((string)env($credentialKey,''))!=='';$fiscalEnabled=filter_var(env('fiscal.enabled',false),FILTER_VALIDATE_BOOL);$stampingEnabled=filter_var(env('fiscal.stampingEnabled',false),FILTER_VALIDATE_BOOL);$realPacOptIn=filter_var(env('fiscal.allowRealPac',false),FILTER_VALIDATE_BOOL);$productionOptIn=filter_var(env('TIMBRADORXPRESS_PRODUCTION_ENABLED',false),FILTER_VALIDATE_BOOL);
    $coherent=in_array($fiscalEnvironment,['development','production'],true)&&in_array($runtimeMode,['integration','production'],true)&&$adapter==='timbradorxpress'&&$transport===$expectedTransport&&$endpointAllowed;$productionGuard=$fiscalEnvironment!=='production'||$productionOptIn;$operational=$coherent&&$credentialPresent&&$productionGuard&&$fiscalEnabled&&$stampingEnabled&&$realPacOptIn;$runtimeStatus=!$coherent?'BLOCKED':($operational?'VERIFIED':'REVIEW_REQUIRED');
    $runtime=['status'=>$runtimeStatus,'runtime_mode'=>$runtimeMode,'logical_environment'=>$fiscalEnvironment,'transport_environment'=>$transport,'adapter'=>$adapter,'fiscal_enabled'=>$fiscalEnabled,'stamping_enabled'=>$stampingEnabled,'real_pac_opt_in'=>$realPacOptIn,'production_transport_opt_in'=>$productionOptIn,'credential_present'=>$credentialPresent,'endpoint_allowed'=>$endpointAllowed,'production_guard_satisfied'=>$productionGuard,'coherent'=>$coherent,'operational'=>$operational];
    $db=Config\Database::connect(null,false);$result=(new InstanceCompatibilityInspector($db,$root,$contract,$runtime))->inspect($invoice);$db->close();$emit($result,isset($options['pretty']));exit(in_array($result['status'],['COMPATIBLE','REVIEW_REQUIRED'],true)?0:2);
}catch(Throwable$e){$emit(['schema_version'=>1,'status'=>'NOT_VERIFIED','compatible'=>false,'read_only'=>true,'error_code'=>'INSPECTION_FAILED','error_type'=>get_class($e),'privacy'=>['contains_secrets'=>false]],isset($options['pretty']));exit(2);}
