<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$pass=0;$fail=0;$a=function(bool$ok,string$m)use(&$pass,&$fail){echo($ok?'[PASS] ':'[FAIL] ').$m.PHP_EOL;$ok?$pass++:$fail++;};
$migrations=glob($root.'/app/Database/Migrations/2026-07-26-*.php');sort($migrations);$a(count($migrations)===8,'Increment 06 defines eight ordered migrations.');
$all=implode("\n",array_map('file_get_contents',$migrations));$a(!preg_match('/\b(FLOAT|DOUBLE)\b/i',$all),'No Increment 06 fiscal amount uses FLOAT or DOUBLE.');
foreach(['fiscal_documents','fiscal_document_issuers','fiscal_document_receivers','fiscal_document_items','fiscal_document_item_taxes','fiscal_document_tax_totals','fiscal_document_metadata','fiscal_document_audit']as$table)$a(str_contains($all,$table),"Migration defines $table.");
$service=file_get_contents($root.'/app/Services/Fiscal/FiscalDraftCreationService.php');
$a(str_contains($service,'FOR UPDATE'),'Draft creation locks invoice and fiscal series rows.');
$a(str_contains($service,"hash('sha256'"),'Draft source snapshot uses SHA-256.');
$a(str_contains($service,'transRollback'),'Draft creation rolls back atomically.');
$a(!str_contains($service,'MAX(')&&!str_contains($service,'max(folio'),'Draft folio reservation never uses SQL MAX + 1.');
$a(!preg_match('/\b(float|double)\b/i',$service),'Draft service performs no float or double conversion.');
$routes=file_get_contents($root.'/app/Config/FiscalRoutes.php');$a(!str_contains($service,'DOMDocument')&&!str_contains($service,'SimpleXMLElement')&&!preg_match('/PacAdapter|StampingService|TimbradorXpress|TimbreFiscalDigital/i',$service),'Increment 06 draft creation service performs no XML, PAC, or stamping operation.');
foreach(['fiscal/invoices/drafts/create','fiscal/invoices/drafts/(:num)','fiscal/invoices/drafts/action']as$route)$a(str_contains($routes,$route),"Explicit protected route exists: $route.");
$roles=file_get_contents($root.'/app/Controllers/Roles.php');foreach(['fiscal_drafts_view','fiscal_drafts_create','fiscal_drafts_lock','fiscal_drafts_supersede','fiscal_drafts_cancel']as$p)$a(str_contains($roles,$p),"Role persistence supports $p.");
$view=file_get_contents($root.'/app/Views/fiscal/invoices/review.php');$a(str_contains($view,'fiscal/invoices/drafts/create')&&!str_contains($view,'fiscal/stamping/stamp'),'Review exposes draft creation without calling the stamping endpoint directly.');
echo"\n$pass passed, $fail failed.\n";exit($fail?1:0);
