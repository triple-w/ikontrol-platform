<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
helper(['general', 'date_time', 'plugin']);
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';

use App\Controllers\Estimate;
use App\Services\EstimateAcceptanceService;
use App\Services\EstimateToInvoiceService;
use App\Services\Fiscal\FiscalItemOverrideContract;
use App\Services\Fiscal\FiscalDecimal;
use App\Services\InvoiceCreationService;
use Config\Database;
use Config\Services;

$passed=0;$failed=0;
$ok=static function(bool $condition,string $message)use(&$passed,&$failed):void{echo($condition?'PASS ':'FAIL ').$message.PHP_EOL;$condition?$passed++:$failed++;};
$config=config('Database');$source=Database::connect($config->default,false);
$sourceName=(string)$source->query('SELECT DATABASE() database_name')->getRow()->database_name;
$testName=preg_replace('/[^a-zA-Z0-9_]/','_',$sourceName).'_c231_'.bin2hex(random_bytes(4));
$quoted='`'.$testName.'`';$sourceQuoted='`'.str_replace('`','``',$sourceName).'`';$testDb=null;$stage='init';

try{
    if($sourceName!== 'ikontrol20_dold_preview')throw new RuntimeException('C2.3.1 sólo admite clonar la base preview canónica.');
    $stage='clone';$source->query("CREATE DATABASE $quoted CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    foreach($source->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->getResultArray()as$row){$table=(string)reset($row);$t='`'.str_replace('`','``',$table).'`';$source->query("CREATE TABLE $quoted.$t LIKE $sourceQuoted.$t");$source->query("INSERT INTO $quoted.$t SELECT * FROM $sourceQuoted.$t");}
    $config->default['database']=$testName;$config->tests=$config->default;$config->defaultGroup='default';$testDb=db_connect('default');
    $testDb->table('settings')->where('setting_name','add_signature_option_on_accepting_estimate')->update(['setting_value'=>'0']);
    $settings=[];foreach($testDb->table('settings')->where('deleted',0)->get()->getResult()as$s)$settings[$s->setting_name]=$s->setting_value;$settings['timezone']=$settings['timezone']??'UTC';$settings['add_signature_option_on_accepting_estimate']='0';config('Rise')->app_settings_array=$settings;
    $attemptsBefore=$testDb->table('fiscal_stamp_attempts')->countAllResults();$movesBefore=$testDb->table('fiscal_stamp_movements')->countAllResults();
    $sourceEstimate=$testDb->table('estimates')->where('deleted',0)->orderBy('id','DESC')->get(1)->getRowArray();
    $actor=$testDb->table('users')->where(['deleted'=>0,'user_type'=>'staff'])->orderBy('is_admin','DESC')->get(1)->getRow();
    $product=$testDb->table('items')->where('deleted',0)->orderBy('id')->get(1)->getRow();
    if(!$sourceEstimate||!$actor||!$product)throw new RuntimeException('La copia temporal no contiene fixtures comerciales mínimos.');
    $createEstimate=static function(string$suffix)use($testDb,$sourceEstimate,$actor):int{$row=$sourceEstimate;unset($row['id']);$row['status']='sent';$row['converted_sale_id']=$row['converted_at']=$row['converted_by']=null;$row['accepted_by']=0;$row['created_by']=(int)$actor->id;$row['project_id']=0;$row['public_key']='QA_C231_'.$suffix.'_'.bin2hex(random_bytes(4));$row['note']='QA_C231_ESTIMATE_ACCEPTANCE '.$suffix;$row['deleted']=0;$testDb->table('estimates')->insert($row);return(int)$testDb->insertID();};
    $contract=new FiscalItemOverrideContract();$complete=$contract->encode($contract->fromInput(['fiscal_override_enabled'=>1,'product_service_code'=>'01010101','unit_code'=>'H87','fiscal_commercial_unit'=>'Pieza','tax_object_code'=>'02','fiscal_description'=>'Línea libre completa','pricing_mode'=>'tax_exclusive','fiscal_taxes'=>[['tax_code'=>'002','tax_type'=>'transfer','factor_type'=>'Tasa','rate_or_quota'=>'0.160000']]],0));$pending=$contract->encode($contract->fromInput(['fiscal_override_enabled'=>1,'fiscal_description'=>'Línea libre pendiente'],0));
    $addItems=static function(int$id)use($testDb,$product,$complete,$pending):void{foreach([
        ['item_id'=>(int)$product->id,'title'=>'Producto maestro','fiscal_override_json'=>null,'rate'=>'58.000000','quantity'=>'2.000000','cost'=>'40.000000','profit_percentage'=>null],
        ['item_id'=>0,'title'=>'Libre completa','fiscal_override_json'=>$complete,'rate'=>'100.000000','quantity'=>'1.000000','cost'=>null,'profit_percentage'=>null],
        ['item_id'=>0,'title'=>'Libre pendiente','fiscal_override_json'=>$pending,'rate'=>'25.000000','quantity'=>'1.000000','cost'=>null,'profit_percentage'=>null],
    ]as$sort=>$item){$testDb->table('estimate_items')->insert($item+['estimate_id'=>$id,'description'=>$item['title'],'unit_type'=>'Pieza','total'=>FiscalDecimal::multiply($item['rate'],$item['quantity']),'sort'=>$sort,'deleted'=>0]);}};

    $stage='service';$estimateId=$createEstimate('SERVICE');$addItems($estimateId);
    $result=(new EstimateAcceptanceService(null,$testDb))->acceptAndFulfill($estimateId,['accepted_by'=>(int)$actor->id],true,(int)$actor->id);
    $estimate=$testDb->table('estimates')->where('id',$estimateId)->get()->getRow();$invoice=$testDb->table('invoices')->where('id',$result['invoice_id'])->get()->getRow();$items=$testDb->table('invoice_items')->where(['invoice_id'=>$invoice->id,'deleted'=>0])->orderBy('sort')->get()->getResult();
    $ok($result['invoice_action']==='created'&&$estimate->status==='accepted','aceptación atómica deja estado Aceptada');
    $ok((int)$estimate->converted_sale_id===(int)$invoice->id&&(int)$invoice->estimate_id===$estimateId,'relación formal bidireccional');
    $ok(count($items)===3&&(int)$items[1]->item_id===0&&(int)$items[2]->item_id===0,'producto y líneas libres conservados');
    $completeSaved=json_decode((string)$items[1]->fiscal_override_json,true);$pendingSaved=json_decode((string)$items[2]->fiscal_override_json,true);
    $ok(($completeSaved['ready']??false)===true&&($pendingSaved['ready']??true)===false,'override completo y pendiente conservan su estado fiscal');
    $again=(new EstimateAcceptanceService(null,$testDb))->acceptAndFulfill($estimateId,[],true,(int)$actor->id);
    $ok($again['invoice_action']==='existing'&&(int)$again['invoice_id']===(int)$invoice->id&&$testDb->table('invoices')->where(['estimate_id'=>$estimateId,'deleted'=>0])->countAllResults()===1,'reintento idempotente devuelve una sola venta');

    $stage='rollback';$failedId=$createEstimate('ROLLBACK');$addItems($failedId);
    $failingCreator=new class($testDb)extends InvoiceCreationService{public function create(array$header,array$items,bool$manageTransaction=true):int{throw new RuntimeException('Fallo QA inyectado al crear partida.');}};
    $converter=new EstimateToInvoiceService($failingCreator,$testDb);try{(new EstimateAcceptanceService($converter,$testDb))->acceptAndFulfill($failedId,[],true,(int)$actor->id);$rolled=false;}catch(RuntimeException$e){$rolled=str_contains($e->getMessage(),'Fallo QA');}
    $failedEstimate=$testDb->table('estimates')->where('id',$failedId)->get()->getRow();
    $ok($rolled&&$failedEstimate->status==='sent'&&!$failedEstimate->converted_sale_id&&$testDb->table('invoices')->where('estimate_id',$failedId)->countAllResults()===0,'error forzado revierte aceptación y venta');

    $stage='endpoint';$httpId=$createEstimate('HTTP');$addItems($httpId);$publicKey=$testDb->table('estimates')->select('public_key')->where('id',$httpId)->get()->getRow()->public_key;
    $post=['id'=>$httpId,'public_key'=>$publicKey,'name'=>'QA C231','email'=>'qa-c231@example.test'];$request=Services::incomingrequest(null,false);$request->setMethod('POST');$request->setGlobal('post',$post);$request->setGlobal('request',$post);service('validation')->reset();$controller=new Estimate();$controller->initController($request,service('response'),service('logger'));ob_start();$controller->accept_estimate();$json=json_decode((string)ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
    $ok(($json['success']??false)&&($json['invoice_action']??'')==='created'&&!empty($json['invoice_id']),'endpoint AJAX devuelve éxito, acción e invoice_id');
    $httpAgainRequest=Services::incomingrequest(null,false);$httpAgainRequest->setMethod('POST');$httpAgainRequest->setGlobal('post',$post);$httpAgainRequest->setGlobal('request',$post);service('validation')->reset();$controller=new Estimate();$controller->initController($httpAgainRequest,service('response'),service('logger'));ob_start();$controller->accept_estimate();$againJson=json_decode((string)ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
    $ok(($againJson['invoice_action']??'')==='existing'&&$testDb->table('invoices')->where(['estimate_id'=>$httpId,'deleted'=>0])->countAllResults()===1,'endpoint repetido es idempotente');
    $routesOutput=(string)shell_exec('php spark routes 2>&1');
    $previewView=(string)file_get_contents(APPPATH.'Views/estimates/estimate_preview.php');
    $publicPreviewView=(string)file_get_contents(APPPATH.'Views/estimates/estimate_public_preview.php');
    $acceptView=(string)file_get_contents(APPPATH.'Views/estimates/accept_estimate_modal_form.php');
    $ok(str_contains($routesOutput,'estimate/accept_estimate_modal_form/([0-9]+)')&&str_contains($routesOutput,'Estimate::accept_estimate_modal_form/$1'),'router registra el GET exacto del modal de aceptacion');
    $ok(str_contains($routesOutput,'estimate/accept_estimate')&&str_contains($routesOutput,'Estimate::accept_estimate'),'router registra el POST exacto de confirmacion');
    $ok(str_contains($previewView,'data-action-method')&&str_contains($publicPreviewView,'data-action-method')&&str_contains($acceptView,'estimate/accept_estimate'),'enlaces ajaxModal usan GET y el formulario confirma por endpoint canonico');
    $ok(str_contains($acceptView,'csrf_field()')&&!str_contains($acceptView,'name="rise_csrf_token"'),'formulario publico genera CSRF con helper oficial, sin nombre ni hash hardcodeados');
    $ok(EstimateAcceptanceService::acceptsStatus('draft')&&EstimateAcceptanceService::acceptsStatus('sent')&&EstimateAcceptanceService::acceptsStatus('accepted'),'modal y coordinador comparten estados aceptables');
    $ok(!EstimateAcceptanceService::acceptsStatus('rejected')&&!EstimateAcceptanceService::acceptsStatus('cancelled'),'rechazada y cancelada no pueden aceptarse');
    $internal=file_get_contents(APPPATH.'Controllers/Estimates.php');$public=file_get_contents(APPPATH.'Controllers/Estimate.php');
    $ok(str_contains($internal,'validate_estimate_access($estimate_id, true)')&&str_contains($public,'public_key'),'permisos internos y clave pública se validan antes de aceptar');
    $ok($testDb->table('fiscal_stamp_attempts')->countAllResults()===$attemptsBefore&&$testDb->table('fiscal_stamp_movements')->countAllResults()===$movesBefore,'cero PAC y cero movimientos de timbres');
}catch(Throwable$e){echo'FAIL stage='.$stage.' '.get_class($e).': '.$e->getMessage().PHP_EOL;$failed++;}
finally{try{$source->query("DROP DATABASE IF EXISTS $quoted");}catch(Throwable$e){echo'FAIL cleanup: '.$e->getMessage().PHP_EOL;$failed++;}}
echo"RESULT passed=$passed failed=$failed".PHP_EOL;exit($failed?1:0);
