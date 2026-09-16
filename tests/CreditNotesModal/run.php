<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
helper(['date_time','general','currency','form']);
$db=require dirname(__DIR__).'/Increment02/isolated_database.php';
$p=$f=0;$ok=function($value,string$message)use(&$p,&$f){echo($value?'[PASS] ':'[FAIL] ').$message.PHP_EOL;$value?$p++:$f++;};
try{
 $service=new App\Services\Fiscal\CreditNoteService($db);$documents=$service->eligibleDocuments();if(!$documents)throw new RuntimeException('Sin factura fiscal acreditable para fixture.');$source=$documents[0];
 $clients=$db->table('clients')->select('id,company_name,vat_number')->where('deleted',0)->get()->getResult();$before=$db->table('fiscal_credit_notes')->countAllResults();
 $html=view('credit_notes/create',['clients'=>$clients,'documents'=>[],'selected'=>0,'selected_client'=>0]);
 $ok(str_contains($html,'creation_mode')&&str_contains($html,'Crear desde factura')&&str_contains($html,'Crear manualmente'),'Abrir modal sin datos renderiza ambas modalidades.');
 $ok(str_contains($html,'name="client_id"')&&str_contains($html,'name="source_document_id"'),'Modal solicita cliente y factura dependiente.');
 $ok($db->table('fiscal_credit_notes')->countAllResults()===$before,'Abrir/cerrar modal no crea borradores.');
 $routes=file_get_contents(APPPATH.'Config/Routes.php');$controller=file_get_contents(APPPATH.'Controllers/Credit_notes.php');
 $ok(str_contains($routes,"post('credit_notes/create/form'")&&str_contains($controller,'function create_form()'),'POST de apertura tiene endpoint separado.');
 $ok(str_contains($routes,"post('credit_notes/create'")&&str_contains($controller,"Seleccione un cliente."),'Submit final conserva CSRF y validación controlada de cliente.');
 $filtered=$service->eligibleDocuments((int)$source->client_id);$ok((bool)array_filter($filtered,fn($d)=>(int)$d->id===(int)$source->id),'Cliente válido carga únicamente sus facturas acreditables.');
 $user=(int)$db->table('users')->where(['is_admin'=>1,'deleted'=>0])->get(1)->getRow()->id;$manual=$service->create((int)$source->id,$user,false);$ctx=$service->context($manual);$ok($ctx['note']->status==='draft'&&count($ctx['items'])===0,'Crear manual abre exactamente un borrador vacío.');
 $invoice=$service->create((int)$source->id,$user,true);$ctx=$service->context($invoice);$ok(count($ctx['items'])>0&&$db->table('fiscal_credit_notes')->whereIn('id',[$manual,$invoice])->countAllResults()===2,'Cliente y factura válidos crean un único borrador por confirmación.');
}catch(Throwable$e){echo'[FAIL] '.get_class($e).': '.$e->getMessage().' '.$e->getFile().':'.$e->getLine().PHP_EOL;$f++;}
echo"TOTAL PASS=$p FAIL=$f".PHP_EOL;exit($f?1:0);