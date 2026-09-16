<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
helper(['plugin']);
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';
$db = require __DIR__ . '/isolated_database.php';
session()->set('user_id',1);

$run = static function (string $method, array $post, array $args=[]): array {
    service('request')->setGlobal('post',$post);
    $controller=new App\Controllers\Invoices();
    $controller->initController(service('request'),service('response'),service('logger'));
    ob_start(); $controller->$method(...$args); $json=ob_get_clean();
    $decoded=json_decode($json,true,512,JSON_THROW_ON_ERROR);
    return $decoded['data'] ?? [];
};
$ids=static fn(array $rows):array=>array_map(static fn(array $row):int=>(int)$row[0],$rows);
$invoice=$db->table('invoices')->where(['deleted'=>0,'type'=>'invoice'])->orderBy('id','DESC')->get(1)->getRow();
if(!$invoice){echo "[SKIP] no invoice fixture exists\n";exit(0);}
$general=$run('list_data',[]);
$client=$run('invoice_list_data_of_client',[],[(int)$invoice->client_id]);
$month=$run('list_data',['start_date'=>date('Y-m-01'),'end_date'=>date('Y-m-t')]);
$fail=0; $assert=static function(bool $ok,string $text)use(&$fail){echo($ok?'[PASS] ':'[FAIL] ').$text.PHP_EOL;if(!$ok)$fail++;};
$assert(in_array((int)$invoice->id,$ids($general),true),'general list endpoint includes the sale without filters');
$assert(in_array((int)$invoice->id,$ids($client),true),'client list endpoint includes the same sale');
$expectedInMonth=$invoice->bill_date>=date('Y-m-01')&&$invoice->bill_date<=date('Y-m-t');
$assert(in_array((int)$invoice->id,$ids($month),true)===$expectedInMonth,'general monthly range follows sale/bill date policy');
$assert((int)$invoice->project_id===0,'sale without project is not excluded');
exit($fail?1:0);
