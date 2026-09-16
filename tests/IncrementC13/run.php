<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$controller=file_get_contents($root.'/app/Controllers/Fiscal/InvoiceModule.php');
$query=file_get_contents($root.'/app/Services/Fiscal/FiscalInvoiceCenterQueryService.php');
$view=file_get_contents($root.'/app/Views/fiscal/invoices/module_index.php');
$detail=file_get_contents($root.'/app/Views/fiscal/invoices/show.php');
$routes=file_get_contents($root.'/app/Config/FiscalRoutes.php');
$menu=file_get_contents($root.'/app/Libraries/Left_menu.php');
$roles=file_get_contents($root.'/app/Controllers/Roles.php');
$factory=file_get_contents($root.'/app/Services/Fiscal/Pdf/FiscalPdfGenerationAdapterFactory.php');
$stamping=file_get_contents($root.'/app/Controllers/Fiscal/Stamping.php');
$spanish=file_get_contents($root.'/app/Language/spanish/default_lang.php');
$english=file_get_contents($root.'/app/Language/english/default_lang.php');
$pass=0;$fail=0;$a=function(bool$ok,string$m)use(&$pass,&$fail){echo($ok?'[PASS] ':'[FAIL] ').$m.PHP_EOL;$ok?$pass++:$fail++;};
$a(str_contains($routes,"Fiscal\\InvoiceModule::index"),'Formal invoice list route exists.');
$a(str_contains($routes,"Fiscal\\InvoiceModule::show/$1"),'Formal invoice detail route exists.');
$a(str_contains($routes,"Fiscal\\InvoiceModule::listData"),'List data route exists.');
$a(str_contains($controller,"guardAny(['fiscal.invoices.view','fiscal_invoices_view'])")&&str_contains($controller,"guardAny(['fiscal.invoices.view','fiscal_invoice_view'])"),'List and detail enforce permissions.');
$a(!preg_match('/content_base64|select\\([^)]*stamped_xml/i',$query),'List projection never selects XML or PDF Base64.');
foreach(['series','folio','uuid','client','rfc','date_from','date_to','type','status','pdf_status','cancellation_status']as$f)$a(str_contains($query,"'$f'"),"Filter $f is implemented.");
$a(str_contains($query,'limit(max(1, min(500, $limit))'),'List query has bounded pagination.');
$a(str_contains($view,'filterParams')&&str_contains($view,'appTable'),'Responsive table preserves filters while paging.');
$a(str_contains($controller,"\$actionLabel = \$row->pdf_available ? 'Regenerar PDF' : 'Generar PDF'"),'Every row exposes generate or regenerate according to active PDF availability.');
$a(str_contains($controller,"allowed('fiscal_pdf_generate')"),'PDF generation action is permission protected.');
$a(str_contains($view,'Generando PDF…')&&str_contains($view,"data('busy')"),'Double click is blocked and progress is visible.');
$a(str_contains($view,'result.csrf'),'Renewed CSRF is applied.');
$a(str_contains($stamping,'FiscalPacPdfGenerationService'),'PDF endpoint uses the dedicated PDF service.');
$a(str_contains($factory,'FISCAL_PDF_FAKE_NOT_ALLOWED')&&str_contains($factory,"runtimeMode==='automated_test'"),'Operational flow has no automatic fake fallback.');
$a(str_contains($controller,'Disponible en el incremento de cancelación fiscal'),'Cancellation is visually disabled.');
$a(str_contains($controller,'Disponible en un incremento posterior'),'Status query is visually disabled.');
$a(!str_contains($controller,'FiscalCancellationService'),'Formal module cannot invoke cancellation.');
$a(str_contains($detail,'Intentos de timbrado')&&str_contains($detail,'Intentos PDF')&&str_contains($detail,'Artefactos disponibles'),'Detail exposes safe histories.');
$a(!str_contains($detail,'content_base64'),'Detail never renders Base64.');
$a(str_contains($menu,'fiscal_billing')&&str_contains($menu,'fiscal/pdf-templates'),'Billing menu contains both entries.');
foreach(['fiscal_invoices_view','fiscal_invoice_view','fiscal_xml_download','fiscal_pdf_generate','fiscal_pdf_view','fiscal_pdf_download','fiscal_cancel_request','fiscal_cancellation_receipt_view','fiscal_status_query']as$p)$a(str_contains($roles,$p),"Permission $p is persisted.");
$a(str_contains($routes,"pdf/generate', 'Fiscal\\Stamping::generatePdf")&&str_contains($routes,"['filter' => 'csrf']"),'PDF generation is POST-only and CSRF protected.');
$a(str_contains($routes,'pdf/preview')&&str_contains($routes,'pdf/download'),'Preview and download routes exist.');
$a(!preg_match('/SoapClient|facturaloplus|curl_/i',$controller.$query.$view.$detail),'Formal module makes zero external calls.');
$a(str_contains($query,"->join('fiscal_document_receivers r', 'r.fiscal_document_id=d.id', 'left')")
    && str_contains($query,"->join('fiscal_document_stamps s', 's.fiscal_document_id=d.id', 'left')"),
    'Optional receiver and stamp relations use LEFT JOIN.');
$a(str_contains($query, 'related_sales') && !str_contains($query, 'd.invoice_id IS NOT NULL'),
    'Documents without an operational sale remain in the fiscal listing.');
$a(str_contains($query, 'imported_test_fixture') && str_contains($controller, 'Prueba importada'),
    'Imported fixtures are projected and visibly identified.');
$a(str_contains($view, "'I'=>'Ingreso'") && str_contains($view, "'P'=>'Pago'")
    && str_contains($view, "form_dropdown('type',[''=>'Todos'"),
    'CFDI type is a normalized select whose initial value is Todos.');
$a(str_contains($query, "'I' => ['I','income','ingreso']")
    && str_contains($query, "'P' => ['P','payment','pago']"),
    'CFDI I and P filters use internal codes with legacy normalization.');
$a(str_contains($controller, "date('d/m/Y'") && !str_contains($view, 'mm/dd/yyyy'),
    'Invoice dates are presented in Spanish format.');
$a(str_contains($view, 'searching:false') && substr_count($view, "id=\"fi-search\"") === 0
    && substr_count($view, "'search'=>'Buscar'") === 1,
    'DataTables search is disabled and only the labeled server filter remains.');
$a(str_contains($view, 'No se encontraron facturas con los filtros seleccionados.')
    && str_contains($view, 'Limpiar filtros'), 'Empty state explains filters and offers reset.');
$a(strpos($spanish, '$lang["fiscal_billing"]') < strrpos($spanish, 'return $lang;')
    && strpos($english, '$lang["fiscal_billing"]') < strrpos($english, 'return $lang;'),
    'Fiscal menu translations are declared before the language file return.');
$a(str_contains($spanish, '$lang["fiscal_billing"] = "Facturación";')
    && str_contains($spanish, '$lang["fiscal_invoices"] = "Facturas";')
    && str_contains($spanish, '$lang["fiscal_pdf_templates"] = "Plantillas PDF";'),
    'Spanish menu labels are resolved from the correct language file.');
$a(str_contains($controller, 'data-document-id') && str_contains($view, '/pdf/generate')
    && str_contains($controller, "'Regenerar PDF' : 'Generar PDF'"), 'Document 21-compatible PDF action opens the configured-provider flow.');
$a(str_contains($controller, 'WSTools33 / PAC') && str_contains($controller, 'PDF generado por PAC')
    && str_contains($controller, 'PDF de prueba local'), 'Fake and real PDF providers have unambiguous labels.');
$a(str_contains($view, 'Proveedor efectivo') && str_contains($view, "pendingButton.data('provider-label')")
    && str_contains($view, "pendingButton.data('series')+' '+pendingButton.data('folio')"),
    'Confirmation modal shows the effective provider and exact document.');
$a(str_contains($detail, "'WSTools33 / PAC' : 'Prueba local'")
    && str_contains($detail, "'PDF del PAC' : 'PDF de prueba'"),
    'PDF history and artifacts distinguish local tests from PAC results.');
$a(str_contains($view, 'filterParams:currentFilters()')
    && str_contains($view, "values[name]=$(selector).val()||''"),
    'The listing sends filter values, never CSS selector strings.');
$a(str_contains($view, "InstanceCollection['fiscal-invoices-table'].filterParams=currentFilters()"),
    'Every reload refreshes the effective filter bindings.');
$a(!str_contains($view, 'filterParams:filterSelectors'),
    'CSS selectors cannot be applied as SQL filter values.');
echo PHP_EOL."$pass passed, $fail failed.".PHP_EOL;exit($fail?1:0);
