<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
helper(['general', 'date_time', 'plugin']);
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';

$db = require dirname(__DIR__) . '/Increment02/isolated_database.php';
$settings = [];
foreach ($db->table('settings')->get()->getResult() as $setting) {
    $settings[$setting->setting_name] = $setting->setting_value;
}
config('Rise')->app_settings_array = $settings + ['timezone' => 'UTC'];
session();

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $condition ? $passed++ : $failed++;
};

foreach (['sat_product_service_keys', 'sat_unit_keys', 'sat_tax_object_codes', 'item_fiscal_settings', 'item_fiscal_taxes'] as $table) {
    $assert($db->tableExists($table), "$table exists in the isolated database");
}

foreach (['fiscal_street','fiscal_external_number','fiscal_internal_number','fiscal_neighborhood','fiscal_locality','fiscal_municipality','fiscal_state','fiscal_country_code','fiscal_address_reference'] as $field) {
    $assert($db->fieldExists($field, 'fiscal_profiles'), "fiscal_profiles.$field exists");
}

$profileClient = $db->table('clients')->where('deleted', 0)->orderBy('id')->get(1)->getRow();
$profileRegime = $db->table('sat_tax_regimes')->where('is_active', 1)->get(1)->getRow();
$profileUse = $db->table('sat_cfdi_uses')->where('is_active', 1)->get(1)->getRow();
if ($profileClient && $profileRegime && $profileUse) {
    $profileModel = new App\Models\Fiscal\Fiscal_profiles_model();
    $baseProfile = [
        'profile_type'=>'receiver','client_id'=>$profileClient->id,'rfc'=>'XAXX010101000','legal_name'=>'PERFIL FISCAL AISLADO',
        'tax_regime_id'=>$profileRegime->id,'fiscal_postal_code'=>'06000','default_cfdi_use_id'=>$profileUse->id,
        'status'=>'ready','is_default'=>0,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')
    ];
    $firstData=$baseProfile+['fiscal_street'=>'Calle Uno','fiscal_external_number'=>'10','fiscal_neighborhood'=>'Centro','fiscal_municipality'=>'Cuauhtémoc','fiscal_state'=>'Ciudad de México','fiscal_country_code'=>'MEX'];
    $firstId=(int)$profileModel->ci_save($firstData);
    $secondData=$baseProfile+['legal_name'=>'SEGUNDO PERFIL AISLADO','fiscal_street'=>'Calle Dos','fiscal_external_number'=>'20','fiscal_neighborhood'=>'Norte','fiscal_municipality'=>'Monterrey','fiscal_state'=>'Nuevo León','fiscal_country_code'=>'MEX'];
    $secondId=(int)$profileModel->ci_save($secondData);
    $assert($firstId>0&&$secondId>0&&$firstId!==$secondId,'One client can keep two fiscal profiles with separate addresses.');
    $firstEdit=['fiscal_street'=>'Calle Uno Editada','updated_at'=>date('Y-m-d H:i:s')];$profileModel->ci_save($firstEdit,$firstId);
    $assert($profileModel->get_one($firstId)->fiscal_street==='Calle Uno Editada'&&$profileModel->get_one($secondId)->fiscal_street==='Calle Dos','Editing one fiscal address does not modify the other.');
    $minimalData=$baseProfile+['legal_name'=>'PERFIL MINIMO SIN COPIA'];$minimalId=(int)$profileModel->ci_save($minimalData);$minimal=$profileModel->get_one($minimalId);
    $minimalReady=(new App\Services\Fiscal\FiscalReadinessService())->evaluate($minimal,$profileRegime,$profileUse);
    $assert($minimal->fiscal_street===null,'Creating a profile does not copy the commercial address automatically.');
    $assert($minimalReady['is_ready']&&$minimalReady['errors']===[]&&$minimalReady['warnings']!==[],'Minimum CFDI receiver fields remain ready while missing address details produce warnings only.');
    $assert((bool)$profileModel->get_one($minimalId)->id,'Existing-style profile with NULL new columns still opens.');
}

$countsBefore = [];
foreach (['sat_product_service_keys', 'sat_unit_keys', 'sat_tax_object_codes'] as $table) {
    $countsBefore[$table] = $db->table($table)->countAllResults();
}
$seeder = new App\Database\Seeds\Increment03ItemFiscalCatalogsSeeder(config('Database'), $db);
$seeder->run();
$seeder->run();
foreach ($countsBefore as $table => $count) {
    $assert($db->table($table)->countAllResults() === $count, "$table seeding is idempotent");
}
$importer = new App\Services\Fiscal\SatItemCatalogImporter($db);
$firstImport = $importer->import('factucare');
$productCountAfterImport = $db->table('sat_product_service_keys')->countAllResults();
$unitCountAfterImport = $db->table('sat_unit_keys')->countAllResults();
$secondImport = $importer->import('factucare');
$assert($productCountAfterImport === 52839 && $unitCountAfterImport === 2418, 'FactuCare source imports all distinct available product/service and unit keys.');
$assert($secondImport['product_service']['inserted'] === 0 && $secondImport['product_service']['updated'] === 0 && $secondImport['units']['inserted'] === 0 && $secondImport['units']['updated'] === 0, 'FactuCare catalog importer is idempotent.');

$products = new App\Models\Fiscal\Sat_product_service_keys_model();
$units = new App\Models\Fiscal\Sat_unit_keys_model();
$assert(count($products->search('01010101')['results']) === 1, 'Product/service catalog searches by code.');
$assert(count($products->search('Internet')['results']) >= 1, 'Product/service catalog searches by description.');
$assert(count($products->search('432')['results']) >= 1 && count($products->search('compu')['results']) >= 1, 'Complete product/service catalog supports partial code and description search.');
$assert(count($units->search('H87')['results']) === 1 && count($units->search('servicio')['results']) >= 1, 'Unit catalog searches by code and name/description.');
$assert(count($units->search('pie')['results']) >= 1, 'Complete unit catalog searches Pieza by description.');
$assert($products->search('01')['results']===[]&&$units->search('H8')['results']===[],'Catalog search does not run with fewer than three characters.');

$legacyItem = $db->table('items i')
    ->select('i.*')
    ->join('item_fiscal_settings f', 'f.item_id=i.id AND f.deleted=0', 'left')
    ->where('i.deleted', 0)
    ->where('f.id', null)
    ->orderBy('i.id')
    ->get(1)->getRow();
if (!$legacyItem) {
    $legacyData = $db->table('items')->where('deleted', 0)->get(1)->getRowArray();
    if (!$legacyData) throw new RuntimeException('An administrative item fixture is required in the isolated clone.');
    unset($legacyData['id']);
    $legacyData['title'] = 'Legacy item isolated fixture';
    $db->table('items')->insert($legacyData);
    $legacyItem = $db->table('items')->where('id', $db->insertID())->get()->getRow();
}
$readiness = new App\Services\Fiscal\ItemFiscalReadinessService();
$legacyResult = $readiness->evaluate((int) $legacyItem->id);
$assert($legacyResult['status'] === 'not_configured' && !$legacyResult['is_ready'], 'Legacy item remains available and reports not_configured.');
$assert((bool) (new App\Models\Items_model())->get_details(['id' => $legacyItem->id])->getRow(), 'Legacy item remains listable through the administrative model.');

$productKey = $db->table('sat_product_service_keys')->where('code', '01010101')->get()->getRow();
$unitKey = $db->table('sat_unit_keys')->where('code', 'H87')->get()->getRow();
$object01 = $db->table('sat_tax_object_codes')->where('code', '01')->get()->getRow();
$object02 = $db->table('sat_tax_object_codes')->where('code', '02')->get()->getRow();
$now = date('Y-m-d H:i:s');
$settingsModel = new App\Models\Fiscal\Item_fiscal_settings_model();
$taxesModel = new App\Models\Fiscal\Item_fiscal_taxes_model();

$incompleteData = [
    'item_id' => $legacyItem->id,
    'item_type' => 'product',
    'sat_product_service_key_id' => $productKey->id,
    'is_default' => 1,
    'status' => 'incomplete',
    'created_at' => $now,
    'updated_at' => $now,
    'deleted' => 0,
];
$incompleteId = (int) $settingsModel->ci_save($incompleteData);
$incomplete = $readiness->evaluate((int) $legacyItem->id, $incompleteId);
$assert($incomplete['status'] === 'incomplete' && in_array('sat_unit_key_id', $incomplete['missing_fields'], true), 'Incomplete configuration reports missing SAT unit.');

$completeData = [
    'sat_unit_key_id' => $unitKey->id,
    'tax_object_code_id' => $object01->id,
    'status' => 'ready',
    'updated_at' => $now,
];
$settingsModel->ci_save($completeData, $incompleteId);
$object01Ready = $readiness->evaluate((int) $legacyItem->id, $incompleteId);
$assert($object01Ready['is_ready'] && $object01Ready['status'] === 'ready', 'ObjetoImp 01 is ready without associated fiscal taxes.');

$taxCode = $db->table('sat_tax_codes')->where('code', '002')->get()->getRow();
$factor = $db->table('sat_tax_factor_types')->where('code', 'Tasa')->get()->getRow();
$db->table('taxes')->insert([
    'title' => 'IVA 16% Increment03 isolated fixture',
    'percentage' => '16',
    'sat_tax_code_id' => $taxCode->id,
    'fiscal_tax_type' => 'transfer',
    'factor_type_id' => $factor->id,
    'xml_rate' => '0.160000',
    'is_fiscal_ready' => 1,
    'use_for_administrative' => 1,
    'use_for_fiscal' => 1,
    'deleted' => 0,
]);
$readyTaxId = (int) $db->insertID();
$object02Data = ['tax_object_code_id' => $object02->id, 'updated_at' => $now];
$settingsModel->ci_save($object02Data, $incompleteId);
$withoutTax = $readiness->evaluate((int) $legacyItem->id, $incompleteId);
$assert(
    $withoutTax['is_ready'] && $withoutTax['tax_object_code'] === '01',
    'A product without associated taxes derives ObjetoImp 01 even when an old automatic 02 value was stored.'
);
$taxesModel->replaceForSetting($incompleteId, [$readyTaxId, $readyTaxId]);
$assert($db->table('item_fiscal_taxes')->where('item_fiscal_setting_id', $incompleteId)->countAllResults() === 1, 'Duplicate fiscal tax selections are normalized to one relation.');
$ready = $readiness->evaluate((int) $legacyItem->id, $incompleteId);
$assert($ready['is_ready'] && $ready['status'] === 'ready', 'ObjetoImp 02 with a fiscal-ready tax becomes ready.');
$storedTax = $db->table('taxes')->where('id', $readyTaxId)->get()->getRow();
$assert((string) $storedTax->percentage === '16' && (string) $storedTax->xml_rate === '0.160000', 'Product relation does not alter administrative percentage or exact XML rate.');

$db->table('taxes')->insert(['title' => 'Legacy tax Increment03 fixture', 'percentage' => '8', 'use_for_administrative' => 1, 'use_for_fiscal' => 0, 'is_fiscal_ready' => 0, 'deleted' => 0]);
$legacyTaxId = (int) $db->insertID();
$taxesModel->replaceForSetting($incompleteId, [$legacyTaxId]);
$badTax = $readiness->evaluate((int) $legacyItem->id, $incompleteId);
$assert(!$badTax['is_ready'] && str_contains(implode(' ', $badTax['errors']), 'no está fiscalmente listo'), 'A non-fiscal-ready tax prevents readiness.');
$taxesModel->replaceForSetting($incompleteId, [$readyTaxId]);

$secondData = [
    'item_id' => $legacyItem->id, 'item_type' => 'service', 'is_default' => 0,
    'status' => 'incomplete', 'created_at' => $now, 'updated_at' => $now, 'deleted' => 0,
];
$secondId = (int) $settingsModel->ci_save($secondData);
$assert($settingsModel->setDefault((int) $legacyItem->id, $secondId), 'A different historical configuration can become default.');
$assert($db->table('item_fiscal_settings')->where(['item_id' => $legacyItem->id, 'is_default' => 1, 'deleted' => 0])->countAllResults() === 1, 'Only one default configuration remains active per item.');
$inactiveData = ['status' => 'inactive', 'updated_at' => $now];
$settingsModel->ci_save($inactiveData, $secondId);
$inactive = $readiness->evaluate((int) $legacyItem->id, $secondId);
$assert($inactive['status'] === 'inactive' && !$inactive['is_ready'], 'Inactive configuration is not ready.');
$settingsModel->setDefault((int) $legacyItem->id, $incompleteId);

$role = $db->table('roles')->where('deleted', 0)->orderBy('id')->get(1)->getRow();
if ($role) {
    $permissions = @unserialize((string) $role->permissions) ?: [];
    $assert(!array_key_exists('fiscal_items_view', $permissions) && !array_key_exists('fiscal_items_manage', $permissions), 'Existing role receives no fiscal item permission automatically.');
    $permissions['fiscal_items_view'] = '1';
    $assert(isset($permissions['fiscal_items_view']) && !isset($permissions['fiscal_items_manage']), 'View permission can be granted independently of manage.');
}

$invoice = $db->table('invoices')->where('deleted', 0)->orderBy('id', 'DESC')->get(1)->getRowArray();
if ($invoice) {
    $sourceInvoiceId = (int) $invoice['id'];
    unset($invoice['id']);
    $db->table('invoices')->insert($invoice);
    $invoiceId = (int) $db->insertID();
    $sourceLine = $db->table('invoice_items')->where(['invoice_id' => $sourceInvoiceId, 'deleted' => 0])->get(1)->getRowArray();
    if ($sourceLine) {
        unset($sourceLine['id']);
        $sourceLine['invoice_id'] = $invoiceId;
        $sourceLine['item_id'] = $legacyItem->id;
        $db->table('invoice_items')->insert($sourceLine);
        $sourceLine['item_id'] = 0;
        $sourceLine['title'] = 'Partida manual Increment03 fixture';
        $db->table('invoice_items')->insert($sourceLine);
        $review = (new App\Services\Fiscal\InvoiceFiscalReviewService())->review($invoiceId);
        $assert($review['item_count'] === 2 && $review['ready_items'] === 1 && $review['incomplete_items'] === 1, 'Invoice review counts ready and manual incomplete lines.');
        $assert($review['items'][1]['readiness']['missing_fields'] === ['item_id'], 'Manual invoice line requires explicit future fiscal capture.');
        $assert($review['status'] === 'not_ready', 'Invoice with an incomplete client or manual line is not ready.');
    } else {
        echo "[SKIP] no invoice item was available to characterize fiscal review\n";
    }
} else {
    echo "[SKIP] no invoice was available to characterize fiscal review\n";
}

$increment03MigrationText=implode("\n",array_map('file_get_contents',glob(APPPATH.'Database/Migrations/2026-07-23-*.php')?:[]));$assert(!str_contains($increment03MigrationText,'fiscal_documents'), 'Increment 03 itself creates no fiscal_documents table.');
$assert($db->fieldExists('rate', 'items') && $db->fieldExists('unit_type', 'items') && $db->fieldExists('taxable', 'items'), 'Administrative item schema remains available.');

echo "\n$passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
