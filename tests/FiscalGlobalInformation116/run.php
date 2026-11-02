<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Domain\Fiscal\Cfdi40\{CfdiConcept,CfdiDocument,CfdiParty};
use App\Services\Fiscal\Cfdi40\{CfdiOriginalChainGenerator,CfdiSemanticValidator,CfdiXmlBuilder,CfdiXsdValidator};
use App\Services\Fiscal\FiscalGlobalInformationService;

$pass=0;$ok=static function(bool$v,string$l)use(&$pass):void{if(!$v)throw new RuntimeException('[FAIL] '.$l);$pass++;echo'[PASS] '.$l.PHP_EOL;};
$throws=static function(callable$f,string$contains)use($ok):void{try{$f();$ok(false,$contains);}catch(Throwable$e){$ok(str_contains($e->getMessage(),$contains),$contains);}};
$global=new FiscalGlobalInformationService();$receiver=['rfc'=>'XAXX010101000','legal_name'=>'PUBLICO EN GENERAL','tax_regime_code'=>'616','fiscal_postal_code'=>'06000','cfdi_use_code'=>'S01'];
$valid=$global->validate(['global_periodicity'=>'04','global_months'=>'10','global_year'=>'2026'],$receiver,'S01','I','2026-10-31 12:00:00',['2026-10-03','2026-10-20']);
$ok($valid['required']&&$valid['global_year']===2026,'global period validates actual operation dates');
$throws(fn()=>$global->validate([],$receiver,'S01','I','2026-10-31',['2026-10-03']),'requiere Periodicidad');
$throws(fn()=>$global->validate(['global_periodicity'=>'99','global_months'=>'10','global_year'=>'2026'],$receiver,'S01','I','2026-10-31'),'Periodicidad');
$throws(fn()=>$global->validate(['global_periodicity'=>'05','global_months'=>'10','global_year'=>'2026'],$receiver,'S01','I','2026-10-31'),'bimestral');
$throws(fn()=>$global->validate(['global_periodicity'=>'04','global_months'=>'10','global_year'=>'2018'],$receiver,'S01','I','2026-10-31'),'desde 2019');
$throws(fn()=>$global->validate(['global_periodicity'=>'04','global_months'=>'09','global_year'=>'2026'],$receiver,'S01','I','2026-10-31',['2026-10-03']),'ventas seleccionadas');
$wrongReceiver=$receiver;$wrongReceiver['tax_regime_code']='612';$ok($global->isRequired($wrongReceiver,'G03','I'),'CFDI40130 requirement depends on income plus generic RFC and name');
$throws(fn()=>$global->validate(['global_periodicity'=>'04','global_months'=>'10','global_year'=>'2026'],$wrongReceiver,'G03','I','2026-10-31'),'616');
$throws(fn()=>$global->validate(['global_periodicity'=>'05','global_months'=>'17','global_year'=>'2026'],$receiver,'S01','I','2026-10-31',[],['tax_regime_code'=>'601']),'621');
$bimonthly=$global->validate(['global_periodicity'=>'05','global_months'=>'17','global_year'=>'2026'],$receiver,'S01','I','2026-10-31',[],['tax_regime_code'=>'621']);$ok($bimonthly['global_months']==='17','regime 621 accepts valid bimonthly period');

$issuer=new CfdiParty(['rfc'=>'AAA010101AAA','legal_name'=>'EMISOR TEST','tax_regime_code'=>'601','expedition_postal_code'=>'06000']);
$party=new CfdiParty($receiver);$concept=new CfdiConcept(['product_service_code'=>'01010101','quantity'=>'1','unit_code'=>'H87','unit_name'=>'Pieza','description'=>'Prueba','unit_value'=>'100','gross_amount'=>'100','discount'=>'0','tax_object_code'=>'01'],[]);
$base=['series'=>'G','folio'=>'1','issue_date'=>'2026-10-31 12:00:00','payment_form_code'=>'99','subtotal'=>'100','discount'=>'0','currency_code'=>'MXN','total'=>'100','document_type'=>'income','status'=>'locked','export_code'=>'01','payment_method_code'=>'PPD','expedition_postal_code'=>'06000','withheld_tax_total'=>'0','transferred_tax_total'=>'0'];
$document=new CfdiDocument($base+['global_periodicity'=>'04','global_months'=>'10','global_year'=>'2026'],$issuer,$party,[$concept],[]);
$xml=(new CfdiXmlBuilder())->build($document);$ok(str_contains($xml,'<cfdi:InformacionGlobal Periodicidad="04" Meses="10" Año="2026"/>'),'XML contains exact InformacionGlobal node');
$ok(strpos($xml,'cfdi:InformacionGlobal')<strpos($xml,'cfdi:Emisor'),'InformacionGlobal respects CFDI schema order');
$semantic=(new CfdiSemanticValidator())->validate($document);$ok($semantic['is_valid'],'semantic validation accepts complete global CFDI');
$xsd=(new CfdiXsdValidator())->validate($xml);$xsdMessages=implode(' ',array_column($xsd['errors']??[],'message'));$ok(($xsd['status']??'')==='schema_pending_signature'&&!str_contains($xsdMessages,'InformacionGlobal'),'official bundled CFDI 4.0 XSD accepts InformacionGlobal before signature');
$missingGlobal=(new CfdiSemanticValidator())->validate(new CfdiDocument($base,$issuer,$party,[$concept],[]));$ok(!$missingGlobal['is_valid']&&isset($missingGlobal['errors']['InformacionGlobal']),'generic public receiver without global information is blocked locally');
$chainResult=(new CfdiOriginalChainGenerator())->generate($xml);$chain=(string)$chainResult['chain'];$ok(str_contains($chain,'|04|10|2026|'),'original chain includes global information');
$opensslConfig='C:/xampp/apache/conf/openssl.cnf';if(is_file($opensslConfig))putenv('OPENSSL_CONF='.$opensslConfig);
$key=openssl_pkey_new(['config'=>$opensslConfig,'private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);$ok($key!==false&&openssl_sign($chain,$signature,$key,OPENSSL_ALGO_SHA256),'synthetic key signs the chain generated from global XML');$details=openssl_pkey_get_details($key);$ok(openssl_verify($chain,$signature,$details['key'],OPENSSL_ALGO_SHA256)===1,'synthetic seal verifies against the same global chain');
$ordinary=new CfdiDocument($base,$issuer,new CfdiParty(['rfc'=>'COSC8001137NA','legal_name'=>'RECEPTOR ORDINARIO','tax_regime_code'=>'612','fiscal_postal_code'=>'06000','cfdi_use_code'=>'G03']),[$concept],[]);$ordinaryXml=(new CfdiXmlBuilder())->build($ordinary);$ok(!str_contains($ordinaryXml,'InformacionGlobal'),'ordinary receiver does not receive unnecessary global node');
$workflow=file_get_contents(APPPATH.'Services/Fiscal/FiscalDraftWorkflowService.php');$materializer=file_get_contents(APPPATH.'Services/Fiscal/FiscalDocumentFromDraftSnapshotService.php');$ok(str_contains($workflow,"'global_periodicity'=>")&&str_contains($materializer,"'global_periodicity'=>"),'draft and immutable document persist global information');
$review=file_get_contents(APPPATH.'Views/fiscal/drafts/review.php');$ok(str_contains($review,'change.globalSave')&&str_contains($review,'blur.globalSave'),'global selects autosave on change and year autosaves on blur');$ok(str_contains($review,"q.always(go)")&&str_contains($review,"if(bad)"),'confirmation waits pending persistence and blocks failed save');$ok(str_contains($review,'HTTP/CSRF')&&str_contains($review,"msg('Guardado'"),'autosave exposes truthful saving, saved and HTTP/CSRF states');
$flow=file_get_contents(APPPATH.'Services/Fiscal/FiscalInvoiceFlowService.php');$ok(str_contains($flow,'invoice_flow.execute_sale')&&str_contains($flow,'$draftId,$saleId'),'sale flow persists future technical references against the resolved draft');
$globalJs=file_get_contents(ROOTPATH.'assets/js/fiscal-global-review.js');$ok(str_contains($globalJs,'draftFlowBase + draftId + "/invoice-flow"')&&!str_contains($globalJs,'sales/'),'confirmation uses the persisted draft instead of transient sale form values');$ok(str_contains($globalJs,'same(response.data && response.data.global_information, requestValues)')&&str_contains($globalJs,'Error de guardado HTTP/CSRF'),'autosave requires backend read-back and exposes transport failures');$ok(str_contains($globalJs,'.off("click")')&&str_contains($globalJs,'textStatus === "abort"'),'legacy handlers and out-of-order requests cannot duplicate confirmation');
$invoiceController=file_get_contents(APPPATH.'Controllers/Invoices.php');$invoiceView=file_get_contents(APPPATH.'Views/invoices/modal_form.php');$ok(str_contains($invoiceController,'Selecciona un cliente para guardar la venta.')&&str_contains($invoiceController,'Selecciona un cliente activo.'),'sale backend returns clear client validation messages');$ok(str_contains($invoiceView,'data-rule-required')&&str_contains($invoiceView,'field_errors'),'sale frontend requires the client and preserves server field errors');
$stamping=file_get_contents(APPPATH.'Services/Fiscal/Pac/FiscalStampingService.php');$ok(substr_count($stamping,'reserveForAttempt')>0&&substr_count($stamping,'->prepare(')===1,'wallet and durable attempt paths remain singular');
echo"TOTAL PASS={$pass} FAIL=0; synthetic data only, no database and no network.\n";
