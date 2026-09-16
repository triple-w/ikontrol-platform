<?php
declare(strict_types=1);
define('ROOTPATH',dirname(__DIR__,2).DIRECTORY_SEPARATOR);define('FCPATH',ROOTPATH);require ROOTPATH.'app/Config/Paths.php';$paths=new Config\Paths();define('APPPATH',realpath($paths->appDirectory).DIRECTORY_SEPARATOR);define('SYSTEMPATH',realpath($paths->systemDirectory).DIRECTORY_SEPARATOR);define('WRITEPATH',realpath($paths->writableDirectory).DIRECTORY_SEPARATOR);define('ENVIRONMENT','development');require $paths->systemDirectory.'/Boot.php';CodeIgniter\Boot::bootTest($paths);helper(['general','date_time']);
use App\Services\Fiscal\Pac\FiscalPacCreditService;
$pass=$fail=0;$ok=static function(bool$v,string$m)use(&$pass,&$fail){echo($v?'[PASS] ':'[FAIL] ').$m.PHP_EOL;$v?$pass++:$fail++;};$read=static fn(string$p)=>(string)file_get_contents(APPPATH.$p);
try{
 $db=db_connect();$ok($db->getDatabase()==='ikontrol20_dold_preview','DB correcta.');
 $actions=$read('Views/invoices/invoice_actions.php');$ok(str_contains($actions,'fiscal/drafts/create/')&&!str_contains($actions,'fiscal/invoices/review/'),'No enlace legacy visible.');
 $credits=new FiscalPacCreditService($db);$parsed=$credits->parse(json_encode(['code'=>200,'message'=>'ok','data'=>json_encode(['creditosDisponibles'=>49])]),200);$ok($parsed['available_credits']===49&&$parsed['provider_code']==='200','Consulta créditos parsea contrato estricto.');
 $account=$read('Services/Fiscal/Stamps/FiscalStampAccountService.php');$migration=$read('Database/Migrations/2026-08-13-190000_NormalizeDevelopmentPacOperations.php');
 $ok(str_contains($account,"['development','production']")&&str_contains($migration,'uq_stamp_account_issuer_environment'),'Ambientes separados.');
 $ok(str_contains($account,'PAC_PROVIDER_CREDITS_MUST_NOT_MUTATE_CLIENT_WALLET')&&str_contains($migration,'fiscal_pac_credit_consultations'),'Créditos PAC no sincronizan wallet comercial.');
 $ok(str_contains($account,'document_reservation')&&str_contains($account,'available_balance'),'Reserva controlada.');
 $ok(str_contains($account,'document_consumption')&&str_contains($account,'consumption_key'),'Consumo idempotente.');
 $stamping=$read('Services/Fiscal/Pac/FiscalStampingService.php');$ok(str_contains($stamping,"'existing'")&&str_contains($stamping,'requires_reconciliation'),'Unknown no reenvía.');
 $forensic=[];foreach(['response_content_type','response_body_length','response_body_sha256','parsing_phase','response_error_class','response_structure']as$field)$forensic[]=str_contains($migration,$field);$ok(!in_array(false,$forensic,true)&&str_contains($stamping,'persistResponseForensics'),'Metadata forense durable.');
 $ok(str_contains($stamping,'outer_response_invalid')&&str_contains($stamping,'stamp_data_invalid')&&str_contains($stamping,'stamped_xml_invalid')&&str_contains($stamping,'stamped_xml_semantic_invalid'),'Errores separados por fase.');
 $validator=$read('Services/Fiscal/Pac/StampedXmlValidator.php');$ok(str_contains($validator,'TimbreFiscalDigital')&&str_contains($validator,'UUID')&&str_contains($validator,'compareHeaders'),'Éxito XML exige CFDI/TFD y semántica.');
 $pdf=$read('Services/Fiscal/Pdf/TimbradorXpressToolsPdfAdapter.php');$ok(str_contains($pdf,"\$code !== '210'")&&str_contains($pdf,'PacPdfValidator'),'PDF exige code 210.');
 $factory=$read('Services/Fiscal/Pdf/FiscalPdfGenerationAdapterFactory.php');$ok(!str_contains($factory,'fallback'),'PDF sin fallback local.');
 $latest=$db->table('fiscal_pac_credit_consultations')->where('environment','development')->orderBy('id','DESC')->get(1)->getRow();$balance=$db->table('fiscal_stamp_accounts')->where(['issuer_profile_id'=>(int)($latest->issuer_profile_id??0),'environment'=>'development'])->get(1)->getRow();$ok($latest&&$balance&&(int)$latest->available_credits!==((int)$balance->available_balance+(int)$balance->reserved_balance),'Saldo local separado del crédito PAC.');
 $views=$read('Views/fiscal/drafts/form.php').$read('Views/fiscal/invoices/index.php');$ok(!str_contains($views,'FakePac')&&!str_contains($views,'fakePacScenario'),'Fake no visible en navegador.');
}catch(Throwable$e){echo'[FAIL] '.get_class($e).': '.$e->getMessage().PHP_EOL;$fail++;}
echo"\n{$pass} passed, {$fail} failed.\n";exit($fail?1:0);
