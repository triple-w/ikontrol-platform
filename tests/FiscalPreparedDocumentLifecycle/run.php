<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\Fiscal\FiscalDraftSnapshotHashService;
use App\Services\Fiscal\FiscalPreparedDocumentLifecycleService;

$passed=0;$failed=0;
$assert=static function(bool $ok,string $message)use(&$passed,&$failed):void{echo($ok?'[PASS] ':'[FAIL] ').$message.PHP_EOL;$ok?$passed++:$failed++;};
$hasher=new FiscalDraftSnapshotHashService();
$base=[
 'draft'=>['id'=>9,'fiscal_document_id'=>10,'status'=>'ready','issuer_id'=>2,'receiver_profile_id'=>3,'fiscal_series_id'=>1,'issue_date'=>'2026-09-02 10:00:00','currency_code'=>'MXN','exchange_rate'=>'1.000000','payment_form_code'=>'03','payment_method_code'=>'PPD','cfdi_use_code'=>'G03','subtotal'=>'171137.41','discount'=>'0.00','tax_total'=>'27381.98','total'=>'198519.39','updated_at'=>'2026-09-02 10:00:00'],
 'items'=>[['id'=>91,'fiscal_draft_id'=>9,'sale_id'=>15,'sale_item_id'=>150,'product_id'=>1,'quantity'=>'1.000000','unit_price'=>'171137.410000','subtotal'=>'171137.41','discount'=>'0.00','total'=>'198519.39','snapshot'=>['fiscal_description'=>'Producto','tax_object_code'=>'02'],'taxes'=>[['id'=>1,'fiscal_draft_item_id'=>91,'tax_code'=>'002','tax_type'=>'transfer','factor_type'=>'Tasa','rate_or_quota'=>'0.160000','tax_base'=>'171137.41','tax_amount'=>'27381.98']]]],
 'issuer_snapshot'=>['id'=>2,'rfc'=>'AAA010101AAA','legal_name'=>'EMISOR','updated_at'=>'2026-09-02'],
 'receiver_snapshot'=>['id'=>3,'rfc'=>'XAXX010101000','legal_name'=>'RECEPTOR'],
 'series_snapshot'=>['id'=>1,'series'=>'B'],
 'allocations'=>[['id'=>4,'fiscal_draft_id'=>9,'sale_id'=>15,'allocated_subtotal'=>'171137.41','allocated_tax'=>'27381.98','allocated_total'=>'198519.39','allocation_status'=>'reserved']],
];
$hash=$hasher->hash($base);
$lifecycleOnly=$base;$lifecycleOnly['draft']['fiscal_document_id']=99;$lifecycleOnly['draft']['status']='stamping';$lifecycleOnly['draft']['updated_at']='2026-09-03';$lifecycleOnly['items'][0]['id']=999;
$assert($hash===$hasher->hash($lifecycleOnly),'TEST B: el mismo snapshot fiscal reutiliza el documento aunque cambien campos de lifecycle.');
$assert(FiscalPreparedDocumentLifecycleService::decision($hash,$hash,false,[])==='reuse','TEST A/B: documento preparado vigente se reutiliza sin duplicado.');
$quantity=$base;$quantity['items'][0]['quantity']='2.000000';
$assert($hash!==$hasher->hash($quantity)&&FiscalPreparedDocumentLifecycleService::decision($hash,$hasher->hash($quantity),false,[])==='invalidate','TEST C: cambiar cantidad invalida la preparación local.');
$price=$base;$price['items'][0]['unit_price']='171137.420000';
$assert($hash!==$hasher->hash($price),'TEST D: cambiar precio modifica el hash fiscal.');
$tax=$base;$tax['items'][0]['taxes'][0]['rate_or_quota']='0.080000';
$assert($hash!==$hasher->hash($tax),'TEST E: cambiar override/impuesto modifica el hash fiscal.');
$assert(FiscalPreparedDocumentLifecycleService::decision($hash,'changed',true,[],'stamped')==='protected','TEST F: un documento stamped nunca se invalida.');
$assert(FiscalPreparedDocumentLifecycleService::decision($hash,'changed',false,[['status'=>'transport_unknown','requires_reconciliation'=>1]])==='protected','TEST G: resultado PAC incierto exige conciliación y bloquea reconstrucción.');
$assert(FiscalPreparedDocumentLifecycleService::decision($hash,'changed',false,[])==='invalidate','TEST H: un error semántico local sin intento PAC permite nuevo PreXML.');
$assert(FiscalPreparedDocumentLifecycleService::decision($hash,'changed',false,[['status'=>'provider_rejected','requires_reconciliation'=>0]])==='invalidate','Un rechazo definitivo del PAC no se confunde con estado incierto.');
$materializer=file_get_contents(APPPATH.'Services/Fiscal/FiscalDocumentFromDraftSnapshotService.php');$workflow=file_get_contents(APPPATH.'Services/Fiscal/FiscalDraftWorkflowService.php');$flow=file_get_contents(APPPATH.'Services/Fiscal/FiscalInvoiceFlowService.php');
$assert(str_contains($materializer,'FiscalDraftSnapshotHashService')&&str_contains($workflow,'invalidateIfSnapshotChanged')&&str_contains($flow,'invalidateIfSnapshotChanged'),'Materialización, edición y ejecución comparten hash/lifecycle canónico.');
$assert(str_contains($workflow,'assertDraftEditable'),'La edición se bloquea antes de alterar un documento PAC protegido.');
echo PHP_EOL."{$passed} passed, {$failed} failed.".PHP_EOL;exit($failed?1:0);