<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

$root=dirname(__DIR__,2); $failures=[]; $assert=function(bool $ok,string $message) use (&$failures){ echo ($ok?'PASS':'FAIL')." $message\n"; if(!$ok)$failures[]=$message; };
$service=new App\Services\Fiscal\FiscalReadinessService();
$empty=(object)['id'=>7,'rfc'=>'','legal_name'=>'','tax_regime_id'=>null,'fiscal_postal_code'=>'','default_cfdi_use_id'=>null,'status'=>'draft'];
$r=$service->evaluate($empty); $assert(!$r['is_ready'] && count($r['missing_fields'])===5,'Incomplete receiver profile is not ready and reports five required fields.');
$ready=(object)['id'=>8,'rfc'=>'XAXX010101000','legal_name'=>'PUBLICO EN GENERAL','tax_regime_id'=>1,'fiscal_postal_code'=>'06000','default_cfdi_use_id'=>1,'status'=>'ready'];
$r=$service->evaluate($ready,(object)['is_active'=>1],(object)['is_active'=>1]); $assert($r['is_ready'],'Complete profile with active catalogs is ready.');
$r=$service->evaluate($ready,(object)['is_active'=>0],(object)['is_active'=>1]); $assert(!$r['is_ready'],'Inactive regime prevents readiness.');
$migration=file_get_contents($root.'/app/Database/Migrations/2026-07-21-010100_ExtendAdministrativeTaxesForFiscalPreparation.php');
$assert(str_contains($migration,"'type'=>'DECIMAL'") && !preg_match("/'type'\s*=>\s*'(?:FLOAT|DOUBLE)'/i",$migration),'New fiscal rates use DECIMAL, not FLOAT/DOUBLE.');
$assert(str_contains($migration,"'use_for_fiscal' => ['type'=>'TINYINT'") && str_contains($migration,"'default'=>0"),'Existing taxes remain non-fiscal by default.');
$flow=file_get_contents($root.'/app/Services/EstimateAcceptanceCoordinator.php');
$assert(str_contains($flow,'FOR UPDATE') && str_contains($flow,"'estimate_id' => \$estimate->id") && str_contains($flow,'transRollback'),'Acceptance service locks the estimate, checks existing relations and rolls back failures.');
$assert(str_contains($flow,"'converted_sale_id' => \$invoiceId") && str_contains($flow,"'status' => self::STATUS_ACCEPTED"),'Estimate conversion persists the formal backlink and accepted state.');
$converter=file_get_contents($root.'/app/Services/EstimateToInvoiceService.php');
$assert((bool) preg_match("/'estimate_id'\s*=>\s*(?:\(int\)\s*)?\\\$estimate->id/",$converter) && str_contains($converter,"'project_id' => (int) (\$estimate->project_id ?? 0)") && (bool) preg_match("/'status'\s*=>\s*\\\$status/",$converter) && str_contains($flow,"'not_paid'"),'Generated sale retains estimate/project and is promoted to unpaid.');
$assert(!str_contains($flow, 'Projects_model'), 'Acceptance service has no project dependency.');
$taxService=new App\Services\Fiscal\TaxFiscalConfigurationService();
$admin=$taxService->prepare(['use_for_administrative'=>1,'use_for_fiscal'=>0,'xml_rate'=>''],null,null);
$assert($admin['errors']===[] && $admin['data']['xml_rate']===null,'Administrative tax accepts NULL fiscal values.');
$factor=(object)['id'=>1,'code'=>'Tasa','is_active'=>1]; $code=(object)['id'=>1,'is_active'=>1];
$iva=$taxService->prepare(['use_for_administrative'=>1,'use_for_fiscal'=>1,'sat_tax_code_id'=>1,'fiscal_tax_type'=>'transfer','factor_type_id'=>1,'xml_rate'=>'0.160000'],$code,$factor);
$assert($iva['errors']===[] && $iva['data']['xml_rate']==='0.160000','Fiscal rate remains an exact decimal string.');
$routes=file_get_contents($root.'/app/Config/FiscalRoutes.php'); $assert(!str_contains(strtolower($routes),'timbr'), 'No stamping route exists.');
exit($failures?1:0);
