<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$f=[
'routes'=>file_get_contents($root.'/app/Config/FiscalRoutes.php'),'controller'=>file_get_contents($root.'/app/Controllers/Fiscal/Stamping.php'),'service'=>file_get_contents($root.'/app/Services/Fiscal/Pdf/FiscalPacPdfGenerationService.php'),'resolver'=>file_get_contents($root.'/app/Services/Fiscal/Pdf/FiscalPdfTemplateResolver.php'),'adapter'=>file_get_contents($root.'/app/Services/Fiscal/Pdf/TimbradorXpressToolsPdfAdapter.php'),'invoice'=>file_get_contents($root.'/app/Controllers/Fiscal/InvoiceModule.php').file_get_contents($root.'/app/Views/fiscal/invoices/show.php'),'credit'=>file_get_contents($root.'/app/Controllers/Credit_notes.php').file_get_contents($root.'/app/Views/credit_notes/edit.php'),'payment'=>file_get_contents($root.'/app/Controllers/Payment_complements.php').file_get_contents($root.'/app/Views/payment_complements/edit.php'),'modal'=>file_get_contents($root.'/app/Views/fiscal/pdf_regeneration_modal.php')];
$pass=0;$fail=0;$a=static function(bool$ok,string$m)use(&$pass,&$fail):void{echo($ok?'[PASS] ':'[FAIL] ').$m.PHP_EOL;$ok?$pass++:$fail++;};
$a(str_contains($f['routes'],"pdf/regenerate', 'Fiscal\\Stamping::regeneratePdf/$1'")&&str_contains($f['routes'],"['filter' => 'csrf']"),'Common route is POST and CSRF protected.');
$a(str_contains($f['controller'],'function regeneratePdf')&&str_contains($f['controller'],'generate($id, (int) $this->login_user->id, null, true)'),'Controller uses common service in regeneration mode.');
$a(str_contains($f['service'],"'income','i'=>'I'")&&str_contains($f['service'],"'expense','e'=>'E'")&&str_contains($f['service'],"'payment','p'=>'P'"),'Service resolves I/E/P.');
$a(str_contains($f['resolver'],"['I','E','P','T','N']")&&str_contains($f['resolver'],'defaultFor($type)'),'Templates resolve by issuer/type with configured fallback.');
$a(str_contains($f['adapter'],'generarPDF')&&!preg_match('/timbrar|timbrarConSello/i',$f['adapter']),'Adapter renders without stamping.');
$a(str_contains($f['service'],"artifact_type'=>'stamped_xml'")&&str_contains($f['service'],'uuidFromStampedXml($xml)'),'Stamped XML is authoritative.');
$a(str_contains($f['service'],"'artifact_type'=>'pac_pdf_superseded'")&&str_contains($f['service'],"['artifact_status'=>'active']"),'Artifact is versioned and replacement activated.');
$a(str_contains($f['service'],"'fiscal_pdf_generation_attempts'")&&!str_contains($f['service'],"'fiscal_stamp_attempts')->insert"),'No new stamp attempt is created.');
$a(str_contains($f['modal'],'El UUID y el XML no cambiar')&&str_contains($f['modal'],'/pdf/regenerate'),'Shared confirmation preserves UUID/XML.');
$a(str_contains($f['invoice'],'fiscal-regenerate-pdf'),'Income list/detail expose regeneration.');
$a(str_contains($f['credit'],'fiscal-regenerate-pdf')&&str_contains($f['credit'],"'E'"),'Expense list/detail use E template.');
$a(str_contains($f['payment'],'fiscal-regenerate-pdf')&&str_contains($f['payment'],"'P'"),'Payment list/detail use P template.');
$a(str_contains($f['controller'],'No existe XML timbrado')&&str_contains($f['controller'],'No existe una plantilla PDF configurada'),'Missing XML/template errors are explicit.');
$a(!preg_match('/invoice_payments|payment_allocations|financial_account_movements|ledger/i',$f['service'].$f['controller']),'No financial side effects exist.');
$a(str_contains($f['service'],"if(\$previousDocumentStatus==='cancelled')\$documentStatus='cancelled'"),'Cancelled documents retain their fiscal status.');echo PHP_EOL."$pass passed, $fail failed.".PHP_EOL;exit($fail?1:0);