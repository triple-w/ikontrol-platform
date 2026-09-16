<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
$root=dirname(__DIR__,2);$p=0;$f=0;$a=static function(bool$ok,string$m)use(&$p,&$f){echo($ok?'[PASS] ':'[FAIL] ').$m.PHP_EOL;$ok?$p++:$f++;};
$m=glob($root.'/app/Database/Migrations/2026-07-25-*.php')?:[];$a(count($m)===2,'Increment 05 defines two ordered migrations.');$t=implode("\n",array_map('file_get_contents',$m));$a(!preg_match("/'type'\s*=>\s*'(?:FLOAT|DOUBLE)'/i",$t)&&str_contains($t,"'type'=>'DECIMAL'")&&substr_count($t, '=>$money')>=10,'All new monetary fields use DECIMAL.');$a(!preg_match('/alterColumn\([\'\"](?:invoices|invoice_items|invoice_payments|taxes|items)/',$t),'Migrations do not alter administrative sales, payments, taxes, or items.');
$calc=file_get_contents($root.'/app/Services/Fiscal/FiscalDecimalCalculator.php');$a(str_contains($calc,'bcadd')&&str_contains($calc,'money('),'Fiscal arithmetic and rounding are centralized.');
$sim=file_get_contents($root.'/app/Services/Fiscal/SaleTaxPricingSimulationService.php');foreach(['tax_inclusive','tax_exclusive','preserve_total']as$mode)$a(str_contains($sim,$mode),"Simulation supports $mode.");$a(!str_contains($sim,'reserveNextFolio')&&!preg_match('/fiscal_documents|PacClient|createXml|stampDocument/i',$sim),'Simulation consumes no folio and creates no fiscal document.');
$adjust=file_get_contents($root.'/app/Services/Fiscal/SaleTaxAdjustmentService.php');$a(str_contains($adjust,'FOR UPDATE')&&str_contains($adjust,'transRollback')&&str_contains($adjust,'payment_total_snapshot'),'Adjustment uses locking, snapshots, and rollback.');
$roles=file_get_contents($root.'/app/Controllers/Roles.php');foreach(['fiscal_sales_pricing_review','fiscal_sales_pricing_apply','fiscal_sales_pricing_override']as$key)$a(str_contains($roles,$key),"Permission $key is persisted explicitly.");
$invoices=file_get_contents($root.'/app/Controllers/Invoices.php');$a(str_contains($invoices,"fiscal/invoices/review/")&&str_contains($invoices,'sale_fiscal_pricing_preparations'),'General sale list reuses fiscal review and displays preparation state.');
echo"\n$p passed, $f failed.\n";exit($f?1:0);
