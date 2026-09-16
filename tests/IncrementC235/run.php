<?php
declare(strict_types=1);
define('ROOTPATH',dirname(__DIR__,2).DIRECTORY_SEPARATOR);define('FCPATH',ROOTPATH);require ROOTPATH.'app/Config/Paths.php';$paths=new Config\Paths();define('APPPATH',realpath($paths->appDirectory).DIRECTORY_SEPARATOR);define('SYSTEMPATH',realpath($paths->systemDirectory).DIRECTORY_SEPARATOR);define('WRITEPATH',realpath($paths->writableDirectory).DIRECTORY_SEPARATOR);define('ENVIRONMENT','development');require $paths->systemDirectory.'/Boot.php';CodeIgniter\Boot::bootTest($paths);helper(['general','date_time']);
use App\Services\Fiscal\FiscalIssuerResolver;
$pass=$fail=0;$ok=static function(bool$v,string$m)use(&$pass,&$fail){echo($v?'[PASS] ':'[FAIL] ').$m.PHP_EOL;$v?$pass++:$fail++;};$read=static fn(string$p)=>(string)file_get_contents(APPPATH.$p);
try{
 $db=db_connect();$ok($db->getDatabase()==='ikontrol20_dold_preview','Base activa canónica.');
 $allocation=$read('Services/Fiscal/FiscalSaleAllocationService.php');$ok(str_contains($allocation,"where('a.sale_id',")&&str_contains($allocation,"orWhere('s.requires_reconciliation',1)"),'Unknown propio bloquea; externo no es bloqueo global.');
 $issuer=(new FiscalIssuerResolver($db))->resolve(1,'development');$ok($issuer&&$issuer->status==='ready','Emisor development dinámico.');
 $resolver=$read('Services/Fiscal/FiscalIssuerResolver.php');$ok(!str_contains($resolver,'LIMF651016ID9')&&!preg_match("/where\s*\(\s*['\"]rfc/i",$resolver),'Sin RFC hardcodeado.');
 $series=$db->table('fiscal_series')->where(['issuer_profile_id'=>(int)$issuer->id,'environment'=>'development','is_active'=>1,'deleted'=>0])->get()->getResult();$ok((bool)$series&&!array_filter($series,fn($s)=>(int)$s->issuer_profile_id!==(int)$issuer->id),'Series sólo del emisor.');
 $cert=$db->table('fiscal_issuer_certificates')->where('id',(int)$issuer->certificate_id)->get(1)->getRow();$ok($cert&&strtoupper($cert->certificate_rfc)===strtoupper($issuer->rfc),'CSD corresponde al RFC emisor.');
 $routes=$read('Config/FiscalRoutes.php');$summary=$read('Views/invoices/fiscal_summary.php');$actions=$read('Views/invoices/invoice_actions.php');$ok(str_contains($routes,'fiscal/drafts/create/(:num)')&&str_contains($summary,'fiscal/drafts/create/')&&str_contains($actions,'fiscal/drafts/create/'),'Botones Facturar canónicos.');
 $ok(!str_contains($summary,'fiscal/invoices/review/')&&!str_contains($actions,'fiscal/invoices/review/'),'Legacy no visible.');
 $view=$read('Views/fiscal/drafts/form.php');foreach(['appForm','Uso CFDI','Método de pago','Forma de pago','Fecha de expedición CFDI','Editar datos fiscales']as$n)$ok(str_contains($view,$n),"Modal contiene {$n}.");
 $ok(str_contains($view,'snapshot fiscal')&&!str_contains($view,'Editar venta'),'Edición fiscal separada.');
 $workflow=$read('Services/Fiscal/FiscalDraftWorkflowService.php');$ok(str_contains($workflow,'FiscalIssuerResolver')&&str_contains($workflow,"'issuer_profile_id'=>\$issuerId"),'Workflow dinámico por emisor/serie.');
 $ok(str_contains($workflow,'receiver_profile_id')&&str_contains($workflow,'client_id'),'Receptor dinámico.');$ok(str_contains($workflow,'FiscalIssueDatePolicy'),'Política de fecha canónica.');
 $ok(str_contains($view,'taxes][0][tax_code]')&&str_contains($view,'0.160000'),'Conceptos e impuestos editables.');
 $draft=$db->table('fiscal_drafts')->where('id',1)->get(1)->getRow();$sale=$db->table('invoices')->where('id',7)->get(1)->getRow();$ok($sale&&$sale->commercial_status==='closed','Venta closed inmutable.');
 $ok($draft&&(int)$draft->snapshot_version===2,'Borrador snapshot v2.');$tax=$db->table('fiscal_draft_item_taxes')->where('fiscal_draft_id',1)->get(1)->getRow();$ok($tax&&$tax->tax_code==='002'&&(string)$tax->rate_or_quota==='0.160000','IVA 16% persistido.');
 $ok($draft&&$draft->cfdi_use_code==='G03'&&$draft->payment_method_code==='PPD'&&$draft->payment_form_code==='99','Uso/método/forma persistidos.');
 $account=$db->table('fiscal_stamp_accounts')->where(['issuer_profile_id'=>(int)$issuer->id,'environment'=>'development'])->get(1)->getRow();$ok($account&&(int)$account->available_balance>0,'Saldo del emisor actual.');
 $stamping=$read('Services/Fiscal/Pac/FiscalStampingService.php');$ok(str_contains($stamping,'persistResponseForensics')&&str_contains($stamping,'response_body_sha256'),'Evidencia forense del parser.');
 $rawXml='<?xml version="1.0"?><cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0"><cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="1.1" UUID="00000000-0000-0000-0000-000000000000"/></cfdi:Complemento></cfdi:Comprobante>';$parsed=(new App\Services\Fiscal\Pac\TimbradorXpressStampDataParser())->parse(new App\Domain\Fiscal\Pac\PacResponse('200','ok',$rawXml,200));$ok($parsed['XML']===$rawXml,'Parser acepta estructura real: data es XML CFDI directo.');
 $pdf=$read('Services/Fiscal/Pdf/FiscalPdfGenerationAdapterFactory.php');$ok(!str_contains(strtolower($pdf),'fallback'),'PDF sin fallback.');
}catch(Throwable$e){echo'[FAIL] '.get_class($e).': '.$e->getMessage().PHP_EOL;$fail++;}
echo"\n{$pass} passed, {$fail} failed.\n";exit($fail?1:0);
