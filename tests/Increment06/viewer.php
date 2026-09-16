<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $message) use (&$pass, &$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$routes = file_get_contents($root . '/app/Config/FiscalRoutes.php');
$review = file_get_contents($root . '/app/Views/fiscal/invoices/review.php');
$draft = file_get_contents($root . '/app/Views/fiscal/invoices/draft.php');
$controller = file_get_contents($root . '/app/Controllers/Fiscal/InvoiceReview.php');
$model = file_get_contents($root . '/app/Models/Fiscal/Fiscal_documents_model.php');
$spanish = file_get_contents($root . '/app/Language/spanish/default_lang.php');
$english = file_get_contents($root . '/app/Language/english/default_lang.php');

$assert(str_contains($routes, "\$routes->get('fiscal/invoices/drafts/(:num)/view'") && str_contains($routes, "'as'=>'fiscal_invoice_draft_view'"), 'Viewer has one named canonical GET route.');
$assert(!str_contains($routes, "\$routes->post('fiscal/invoices/drafts/(:num)/view'"), 'Draft viewer is never exposed by POST.');
$assert(str_contains($review, "url_to('fiscal_invoice_draft_view',\$d->id)") && str_contains($review, "'data-fiscal-document-id'=>(int)\$d->id"), 'View button sends fiscal_documents.id through the named route.');
$assert(str_contains($review, 'fiscal-draft-view') && !str_contains($review, "modal_anchor(get_uri('fiscal/invoices/drafts/"), 'Viewer replaces the current modal instead of opening a second ajax modal.');
$assert(str_contains($review, "type:'GET'"), 'Viewer JavaScript uses GET.');
$assert(str_contains($review, "button.trigger('blur')") && str_contains($review, "trigger('focus')"), 'Modal transition releases and restores focus.');
$assert(str_contains($controller, "complete((int)\$documentId)") && str_contains($model, "'tax_totals'") && str_contains($model, "'metadata'"), 'Controller loads the complete immutable snapshot.');
$assert(str_contains($controller, "'fiscal_drafts_view'"), 'Viewer enforces fiscal_drafts_view.');
$assert(str_contains($controller, "draftError(app_lang('fiscal_access_denied'),403"), 'Denied fiscal document access returns HTTP 403.');
$assert(str_contains($controller, 'draftError(') && str_contains($controller, 'Fiscal draft viewer requested a missing document'), 'Invalid and missing IDs have controlled responses and logging.');
$assert(!preg_match('/Fiscal_profiles_model|Items_model|Invoices_model/', substr($controller, strpos($controller, 'public function draft('), strpos($controller, 'public function draft_action(') - strpos($controller, 'public function draft('))), 'Viewer does not use live fiscal profiles, items, or invoice models as snapshot fallback.');

$required = [
    'create_fiscal_draft', 'sat_payment_form', 'sat_payment_method', 'exchange_rate',
    'exchange_rate_help', 'confirm_replace_fiscal_draft', 'fiscal_preparations',
    'series', 'folio', 'fiscal_draft_status_draft', 'fiscal_draft_status_ready',
    'fiscal_draft_status_locked', 'fiscal_draft_status_superseded',
    'fiscal_draft_status_cancelled_internal', 'ready', 'subtotal',
    'view_fiscal_draft', 'close_fiscal_preparation',
];
foreach ($required as $key) {
    $assert(str_contains($spanish, "\$lang[\"$key\"]"), "Spanish contains $key.");
    $assert(str_contains($english, "\$lang[\"$key\"]"), "English contains $key.");
}
$fiscalFiles = [];
foreach (['app/Views/fiscal', 'app/Controllers/Fiscal', 'app/Services/Fiscal'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $fiscalFiles[] = $file->getPathname();
        }
    }
}
$missing = [];
foreach ($fiscalFiles as $file) {
    preg_match_all('/app_lang\(\s*[\'"]([^\'"]+)[\'"]/', file_get_contents($file), $matches);
    foreach ($matches[1] as $key) {
        if (str_ends_with($key, '_')) {
            continue;
        }
        if (!str_contains($spanish, "\$lang[\"$key\"]") || !str_contains($english, "\$lang[\"$key\"]")) {
            $missing[$key] = true;
        }
    }
}
$assert($missing === [], 'All static fiscal language keys exist in Spanish and English: ' . implode(', ', array_keys($missing)));
$assert(strrpos($spanish, 'return $lang;') > strrpos($spanish, '$lang['), 'Spanish returns the language array after Increment 6/7 keys.');
$assert(strrpos($english, 'return $lang;') > strrpos($english, '$lang['), 'English returns the language array after Increment 6/7 keys.');
$assert(str_contains($draft,'$signature&&in_array($document->status')&&str_contains($draft,'$can_stamp_sandbox'),'Later stamping action is gated by signed XML, controlled state, configuration, and explicit permission.');
$assert(str_contains($draft,"in_array(\$document->status,['locked','ready_to_stamp','stamped'],true)")&&str_contains($draft,"\$document->status==='locked'&&\$can_sign"),'Signed and stamped documents remain viewable while only locked unsigned documents show the signing form.');
$assert(str_contains($routes, "\$routes->post('fiscal/invoices/sign'") && str_contains($routes, "\$routes->post('fiscal/stamping/stamp'"), 'Signing and stamping remain separate POST mutations.');
$assert(str_contains($routes, "\$routes->get('fiscal/invoices/signed/view/(:num)'"), 'Signed XML viewer is a read-only GET route.');
$assert(str_contains($draft, '$issuer->') && str_contains($draft, '$receiver->') && str_contains($draft, '$items') && str_contains($draft, '$tax_totals'), 'Viewer renders issuer, receiver, concepts, item taxes, and tax summary snapshots.');
$assert(substr_count($review . $draft, 'id="ajaxModal"') === 0, 'Fiscal views never create another #ajaxModal.');

echo PHP_EOL . "$pass passed, $fail failed." . PHP_EOL;
exit($fail ? 1 : 0);
