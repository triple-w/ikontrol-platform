<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Config\Database;

$db = Database::connect('clean_build', false);
$expected = 'ikontrol20_clean';
$selected = (string) ($db->query('SELECT DATABASE() AS name')->getRow()->name ?? '');
if ($selected !== $expected || $db->getDatabase() !== $expected) {
    throw new RuntimeException('Clean-install smoke test requires only ikontrol20_clean.');
}
$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (! $condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    ++$passed;
    echo '[PASS] ' . $message . PHP_EOL;
};

foreach ([
    'settings', 'users', 'roles', 'clients', 'items', 'invoices', 'invoice_payments',
    'fiscal_series', 'fiscal_documents', 'fiscal_stamp_attempts', 'payment_complements',
    'payment_complement_external_documents', 'payment_complement_external_taxes',
    'product_supplier_cost_history', 'warehouse_products', 'warehouse_transfers',
] as $table) {
    $assert($db->tableExists($table), 'schema contains ' . $table);
}
$assert($db->table('migrations')->countAllResults() === 88, 'directed migration history contains the canonical 88 entries');
$assert($db->table('users')->where('is_admin', 1)->where('deleted', 0)->countAllResults() >= 1, 'initial administrator exists');
foreach (['sat_tax_codes', 'sat_tax_factor_types', 'sat_tax_regimes', 'sat_cfdi_uses', 'sat_product_service_keys', 'sat_unit_keys', 'sat_tax_object_codes'] as $table) {
    $assert($db->table($table)->countAllResults() > 0, 'seeded catalog ' . $table);
}
$routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
foreach (['payment_complements/(:num)/external-documents', 'suppliers/manual-cost/save', 'warehouses/transfers', 'offer/accept_proposal'] as $route) {
    $assert(str_contains($routes, $route), 'critical route is registered: ' . $route);
}
$example = (string) file_get_contents(ROOTPATH . '.env.example');
$assert(str_contains($example, 'fiscal.allowRealPac = false') && str_contains($example, 'fiscal.pacAdapter = timbradorxpress'), 'example configuration disables real PAC and remains fail-closed');
foreach (['ProposalCanonical', 'SupplierWorkflowCanonical', 'WarehouseCanonical', 'PaymentComplementsCanonical', 'FiscalSeriesPdfCanonical', 'P13HttpSurface'] as $runner) {
    $assert(is_file(ROOTPATH . 'tests/' . $runner . '/run.php'), 'reconciliation runner available: ' . $runner);
}
echo "passed={$passed}\n";
