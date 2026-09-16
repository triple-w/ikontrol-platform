<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/tests/bootstrap.php';
require_once $root . '/app/Database/Migrations/2026-09-01-091000_EnsureGenericSupplierCostHistorySource.php';
$db = require $root . '/tests/Increment02/isolated_database.php';
$forge = \CodeIgniter\Database\Config::forge('default');
$migration = new \App\Database\Migrations\EnsureGenericSupplierCostHistorySource($forge);
$table = $db->prefixTable('product_supplier_cost_history');
$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $condition ? $passed++ : $failed++;
};

foreach (['uq_cost_history_source_economic', 'idx_cost_history_source'] as $index) {
    if (isset($db->getIndexData('product_supplier_cost_history')[$index])) {
        $db->query("ALTER TABLE {$table} DROP INDEX {$index}");
    }
}
foreach (['source_folio', 'source_item_id', 'source_id', 'source_type'] as $column) {
    $db->resetDataCache();
    if ($db->fieldExists($column, 'product_supplier_cost_history')) {
        $db->query("ALTER TABLE {$table} DROP COLUMN {$column}");
    }
}

$migration->up();
$db->resetDataCache();
$check(!array_diff(['source_type', 'source_id', 'source_item_id', 'source_folio'], $db->getFieldNames('product_supplier_cost_history')), 'crea todas las columnas cuando ninguna existe');
$indexes = $db->getIndexData('product_supplier_cost_history');
$check(isset($indexes['uq_cost_history_source_economic'], $indexes['idx_cost_history_source']), 'crea los índices genéricos faltantes');
$legacy = $db->table('product_supplier_cost_history')->where('proposal_id IS NOT NULL', null, false)->get(1)->getRow();
$check(!$legacy || ($legacy->source_type === 'proposal' && (int) $legacy->source_id === (int) $legacy->proposal_id && (int) $legacy->source_item_id === (int) $legacy->proposal_item_id), 'backfill legacy conserva origen Proposal');

$db->query("ALTER TABLE {$table} DROP COLUMN source_folio");
$migration->up();
$db->resetDataCache();
$check($db->fieldExists('source_folio', 'product_supplier_cost_history'), 'repara presencia parcial');
$migration->up();
$db->resetDataCache();
$check($db->fieldExists('source_type', 'product_supplier_cost_history'), 'es segura con esquema completo');
$indexes = $db->getIndexData('product_supplier_cost_history');
$check(isset($indexes['uq_cost_history_source_economic'], $indexes['idx_cost_history_source']), 'la reejecución no duplica índices');

echo "TOTAL PASS={$passed} FAIL={$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
