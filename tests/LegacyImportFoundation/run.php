<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
helper(['general', 'date_time']);
require_once APPPATH . 'Database/Migrations/2026-08-04-160000_CreateLegacyImportRegistry.php';
require_once APPPATH . 'Database/Migrations/2026-08-04-160100_ConvertItemRateToExactDecimal.php';

use App\Domain\Legacy\LegacySourceReference;
use App\Database\Migrations\ConvertItemRateToExactDecimal;
use App\Database\Migrations\CreateLegacyImportRegistry;
use App\Services\Legacy\LegacyImportRegistryService;
use Config\Database;

$passed = $failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $condition ? $passed++ : $failed++;
};

$config = config('Database');
$admin = Database::connect($config->default, false);
$sourceDatabase = (string) $admin->query('SELECT DATABASE() AS name')->getRow()->name;
if ($sourceDatabase === '' || str_contains(strtolower($sourceDatabase), 'production')) {
    throw new RuntimeException('Legacy foundation tests require a verified local development connection.');
}
$testDatabase = preg_replace('/[^a-zA-Z0-9_]/', '_', $sourceDatabase) . '_legacy_' . bin2hex(random_bytes(4));
$quoted = '`' . $testDatabase . '`';
$admin->query("CREATE DATABASE {$quoted} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

try {
    $config->default['database'] = $testDatabase;
    $config->tests = $config->default;
    $config->defaultGroup = 'default';
    $db = db_connect('default');
    $prefix = $db->getPrefix();
    $items = $db->protectIdentifiers($prefix . 'items');
    (new CreateLegacyImportRegistry(Database::forge($db)))->up();
    $db->resetDataCache();
    (new CreateLegacyImportRegistry(Database::forge($db)))->up();
    $db->query("CREATE TABLE {$items} (id INT AUTO_INCREMENT PRIMARY KEY, rate DOUBLE NOT NULL) ENGINE=InnoDB");
    $db->table('items')->insert(['rate' => '2500']);
    $db->resetDataCache();
    $assert($db->tableExists('items') && $db->fieldExists('rate', 'items'), 'La tabla temporal items respeta DBPrefix.');
    (new ConvertItemRateToExactDecimal(Database::forge($db)))->up();

    $service = new LegacyImportRegistryService($db);
    $batchId = $service->startBatch([
        'source_system' => 'fc2', 'source_owner_id' => '15',
        'source_owner_key' => 'DOLD860620EW7', 'entity_scope' => 'foundation_test',
    ]);
    $assert($batchId > 0 && $db->table('legacy_import_batches')->where('id', $batchId)->countAllResults() === 1, 'Crear lote.');
    $service->markRunning($batchId);
    $service->completeBatchWithWarnings($batchId, ['warnings' => 1]);
    $batch = $db->table('legacy_import_batches')->where('id', $batchId)->get()->getRow();
    $assert($batch->status === 'completed_with_warnings' && $batch->started_at !== null && $batch->completed_at !== null, 'Cambiar estados del lote.');

    $source = new LegacySourceReference('fc2', 'productos', '15', '44');
    $snapshot = ['price' => '12.3400', 'description' => 'Test'];
    $result = $service->recordImport($batchId, $source, $snapshot, 'items', '900');
    $assert($result->mappingId > 0 && $service->findMapping($source)?->destination_id === '900', 'Registrar mapping.');

    $duplicateBlocked = false;
    try {
        $service->recordImport($batchId, $source, $snapshot, 'items', '901');
    } catch (RuntimeException) {
        $duplicateBlocked = true;
    }
    $assert($duplicateBlocked && $db->table('legacy_import_mappings')->countAllResults() === 1, 'Impedir mapping duplicado.');
    $assert($service->decide($source, ['description' => 'Test', 'price' => '12.3400'])->action === 'skip', 'Mismo origen y hash produce skip.');
    $assert($service->decide($source, ['description' => 'Test', 'price' => '12.3500'])->action === 'update', 'Hash distinto produce candidato update.');
    $assert($service->decide($source, $snapshot, false)->action === 'conflict', 'Destino faltante produce conflict.');

    $hashA = $service->hashSnapshot(['b' => ['y' => 2, 'x' => 1], 'a' => '12.3400']);
    $hashB = $service->hashSnapshot(['a' => '12.3400', 'b' => ['x' => 1, 'y' => 2]]);
    $assert(hash_equals($hashA, $hashB), 'Hash canónico independiente del orden de claves.');
    $json = $service->canonicalJson(['amount' => '12.3400']);
    $assert(str_contains($json, '"12.3400"'), 'Hashing conserva strings monetarios sin float.');
    $clean = $service->sanitizeSnapshot([
        'name' => 'safe', 'password' => 'hidden', 'nested' => ['api_key' => 'hidden', 'value' => 'visible'],
        'private_key_pem' => 'hidden', 'csd_password' => 'hidden',
        'attachment' => '-----BEGIN PRIVATE KEY----- hidden',
    ]);
    $assert($clean === ['name' => 'safe', 'nested' => ['value' => 'visible']], 'Snapshot excluye campos sensibles.');

    $before = $db->table('legacy_import_batches')->countAllResults();
    try {
        $service->transactional(function () use ($db): void {
            $db->table('legacy_import_batches')->insert([
                'source_system' => 'rollback', 'entity_scope' => 'test', 'status' => 'pending',
                'created_at' => get_current_utc_time(),
            ]);
            throw new RuntimeException('forced rollback');
        });
    } catch (RuntimeException) {
    }
    $assert($db->table('legacy_import_batches')->countAllResults() === $before, 'Transacción revertida ante error.');

    $values = ['0', '0.000001', '12.3456', '999999999999.999999'];
    foreach ($values as $value) {
        $db->table('items')->insert(['rate' => $value]);
    }
    $stored = array_column($db->table('items')->orderBy('id')->get()->getResultArray(), 'rate');
    $assert($stored === ['2500.000000', '0.000000', '0.000001', '12.345600', '999999999999.999999'], 'La migración DECIMAL(18,6) conserva valores existentes, límites y seis decimales.');
} catch (Throwable $error) {
    echo '[FAIL] ' . $error::class . ': ' . $error->getMessage() . PHP_EOL;
    $failed++;
} finally {
    $admin->query("DROP DATABASE IF EXISTS {$quoted}");
}

echo PHP_EOL . "{$passed} passed, {$failed} failed." . PHP_EOL;
exit($failed ? 1 : 0);
