<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Config\Database;

$database = $argv[1] ?? null;
if (! $database) throw new RuntimeException('Provide the isolated bridged database.');
$base = config('Database')->default;
$config = is_array($base) ? $base : get_object_vars($base);
$db = Database::connect(array_replace($config, ['database' => $database, 'DBPrefix' => 'sf_']), false);
$pass = 0;
$assert = static function (bool $condition, string $message) use (&$pass): void {
    if (! $condition) throw new RuntimeException('[FAIL] ' . $message);
    $pass++;
    echo '[PASS] ' . $message . PHP_EOL;
};
$service = file_get_contents(APPPATH . 'Services/Upgrade/Legacy/SmartfreePrefiscalBridgeService.php');
$command = file_get_contents(APPPATH . 'Commands/IkontrolLegacyUpgradeSmartfree.php');
$schema = file_get_contents(APPPATH . 'Services/Upgrade/Legacy/schema/ikontrol-1.0.0-fiscal.sql');
$assert(! str_contains($service, '$this->template') && ! str_contains($command, 'template-database'), 'bridge has no runtime template database dependency');
$assert(str_contains($schema, 'sale_fiscal_pricing_preparations') && str_contains($schema, 'sat_catalog_installations'), 'embedded schema contains runtime pricing and SAT metadata tables');
$assert(! str_contains($schema, 'GENERATED ALWAYS') && str_contains($schema, 'active_uuid` char(36) DEFAULT NULL'), 'MariaDB-compatible active_uuid is a nullable physical column');
$assert($db->tableExists('sale_fiscal_pricing_preparations') && $db->tableExists('sat_catalog_installations'), 'self-contained bridge created missing canonical tables');
foreach (['sat_product_service_keys', 'sat_unit_keys'] as $table) $assert($db->fieldExists('normalized_description', $table), $table . ' has normalized_description');
$active = $db->query("SELECT EXTRA FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sf_payment_complement_external_documents' AND column_name='active_uuid'")->getRow();
$assert($active && trim((string) $active->EXTRA) === '', 'active_uuid is not generated in MariaDB');
$assert((int) $db->table('clients')->countAllResults() === 315 && (int) $db->table('invoice_payments')->countAllResults() === 1418, 'legacy entity counts remain preserved');
$assert((int) $db->table('financial_account_movements')->countAllResults() === 1391 && (int) $db->table('payment_allocations')->countAllResults() === 1371, 'financial reconciliation remains certified and idempotent');
echo "passed={$pass}\n";
