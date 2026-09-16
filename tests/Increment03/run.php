<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$root = dirname(__DIR__, 2);
$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $condition ? $passed++ : $failed++;
};

$migrationFiles = glob($root . '/app/Database/Migrations/2026-07-23-*.php') ?: [];
$assert(count($migrationFiles) === 6, 'Increment 03 defines six ordered, separate migrations including the corrective fiscal-address migration.');

$allMigrations = implode("\n", array_map('file_get_contents', $migrationFiles));
$assert(!preg_match("/'type'\s*=>\s*'(?:FLOAT|DOUBLE)'/i", $allMigrations), 'Increment 03 adds no FLOAT or DOUBLE fiscal fields.');
$assert(!str_contains($allMigrations, "alterColumn('items'") && !str_contains($allMigrations, "alterColumn('invoice_items'"), 'Migrations do not alter administrative item columns.');

$routes = file_get_contents($root . '/app/Config/FiscalRoutes.php');
foreach (['fiscal/items/form', 'fiscal/items/save', 'fiscal/catalogs/product-service/search', 'fiscal/catalogs/units/search', 'fiscal/items/deactivate', 'fiscal/items/activate', 'fiscal/invoices/review'] as $route) {
    $assert(str_contains($routes, $route), "Explicit protected fiscal route exists: $route.");
}
$itemRoutes=implode("\n",array_filter(explode("\n",$routes),fn($line)=>str_contains($line,'fiscal/items')||str_contains($line,'fiscal/catalogs')));
$assert(!preg_match('/timbr|stamp|pac|cfdi\/(?:issue|seal|sign)/i',$itemRoutes),'Increment 03 item routes contain no stamping, PAC, signing, or issuance operation.');

$itemsMigration = file_get_contents($root . '/app/Database/Migrations/2026-07-23-030300_CreateItemFiscalSettings.php');
$assert(str_contains($itemsMigration, "'item_type'") && str_contains($itemsMigration, "'sat_unit_key_id'") && str_contains($itemsMigration, "'tax_object_code_id'"), 'Fiscal item settings remain separate from items.');

$permissionsController = file_get_contents($root . '/app/Controllers/Roles.php');
$permissionsView = file_get_contents($root . '/app/Views/roles/permissions.php');
$assert(str_contains($permissionsController, 'fiscal_items_view') && str_contains($permissionsController, 'fiscal_items_manage'), 'Role persistence knows both item fiscal permissions.');
$assert(str_contains($permissionsView, 'fiscal_items_view') && str_contains($permissionsView, 'fiscal_items_manage'), 'Role UI exposes both item fiscal permissions.');

$review = file_get_contents($root . '/app/Services/Fiscal/InvoiceFiscalReviewService.php');
$assert(str_contains($review, 'item_id') && str_contains(strtolower($review), 'partida manual'), 'Invoice fiscal review detects manual lines without inventing catalog data.');
$assert(!str_contains($review, 'Fiscal_documents') && !str_contains($review, 'fiscal_documents'), 'Invoice review creates no fiscal document snapshot.');

$itemController = file_get_contents($root . '/app/Controllers/Fiscal/ItemSettings.php');
$assert(str_contains($itemController, "(object)['id'=>0") && str_contains($itemController, '(int)($model->id??0)'), 'Legacy item form handles a missing fiscal configuration without reading an undefined id.');
$assert(str_contains($itemController, "log_message('error'") && str_contains($itemController, 'setStatusCode(404)'), 'Fiscal item form logs unexpected failures and returns controlled missing-item responses.');
$assert(!preg_match("/->get\('fiscal\/items\/form/", $routes), 'Fiscal item modal intentionally has no GET route.');
$itemView = file_get_contents($root . '/app/Views/fiscal/items/modal_form.php');
foreach (['name="commercial_unit"','name="fiscal_description"','name="identification_number"','name="tax_object_code_id"','name="status"'] as $forbiddenInput) $assert(!str_contains($itemView,$forbiddenInput),"Normal fiscal modal omits editable $forbiddenInput.");
$assert(str_contains($itemView,'minimumInputLength:3')&&str_contains($itemView,"type:'POST'")&&str_contains($itemView,'quietMillis:350'),'Both SAT selectors use Select2 3.x debounced POST search from three characters.');
$assert(substr_count($itemView,'type="hidden"')>=4&&!str_contains($itemView,'processResults')&&!str_contains($itemView,'delay:350'),'Remote SAT catalogs use hidden inputs and no Select2 4.x options.');
$assert(!str_contains($itemController,"getPost('status')")&&!str_contains($itemController,"getPost('tax_object_code_id')")&&!str_contains($itemController,"getPost('commercial_unit')")&&!str_contains($itemController,"getPost('fiscal_description')"),'Save endpoint ignores manipulated derived and legacy fields.');

$profileMigration = file_get_contents($root . '/app/Database/Migrations/2026-07-23-030500_AddCompleteFiscalAddressToProfiles.php');
foreach (['fiscal_street','fiscal_external_number','fiscal_internal_number','fiscal_neighborhood','fiscal_locality','fiscal_municipality','fiscal_state','fiscal_country_code','fiscal_address_reference'] as $field) {
    $assert(str_contains($profileMigration, "'$field'"), "Corrective migration defines $field.");
}
$profileController = file_get_contents($root . '/app/Controllers/Fiscal/ClientProfiles.php');
$assert(!str_contains($profileController, "'fiscal_street' => \$client") && str_contains($profileController, "nullablePost('fiscal_street')"), 'Server never copies the commercial address implicitly.');
$profileView = file_get_contents($root . '/app/Views/fiscal/client_profiles/modal_form.php');
$assert(str_contains($profileView, 'id="copy-commercial-address"') && str_contains($profileView, "'fiscal_street' => \$client_info->address"), 'Commercial address copy exists only as an explicit client-side action.');
$assert(str_contains($profileView, "(\$model_info->id ?? 0) ?") && str_contains($profileView, ": 'MEX'"), 'MEX is a UI default only for a new profile, not an overwrite of an existing profile.');

$taxFiles = array_filter(array_merge(
    gitFiles($root, 'app/Controllers/Taxes.php'),
    gitFiles($root, 'app/Models/Taxes_model.php'),
    gitFiles($root, 'app/Views/taxes')
));
$assert($taxFiles === [], 'Increment 03 does not modify the taxes CRUD.');

echo "\n$passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);

function gitFiles(string $root, string $path): array
{
    $command = 'git -C ' . escapeshellarg($root) . ' diff --name-only -- ' . escapeshellarg($path);
    $output = shell_exec($command) ?: '';
    return array_values(array_filter(preg_split('/\R/', trim($output)) ?: []));
}
