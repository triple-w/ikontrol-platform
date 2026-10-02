<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\Fiscal\FiscalInstanceModeService;
use App\Services\Instance\InstanceFeaturesService;
use App\Services\Upgrade\InstanceUpgradeService;
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
$plan = (new InstanceUpgradeService($db))->plan('1.1.0');
$assert($plan['compatible'] && $plan['current_version'] === '1.0.0' && count($plan['packages']) === 1, 'read-only plan resolves explicit 1.0.0 to 1.1.0 package');
$assert(! $db->tableExists('instance_upgrade_runs'), 'upgrade plan performs zero writes');
$failed = false;
try { (new InstanceUpgradeService($db))->execute('1.1.0'); } catch (RuntimeException $error) { $failed = str_contains($error->getMessage(), 'manifest'); }
$assert($failed && $db->table('instance_upgrade_runs')->where('status', 'failed')->countAllResults() === 1, 'missing official artifacts fails and records diagnosable state');
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ikontrol-upgrade-catalog-' . bin2hex(random_bytes(4));
mkdir($root, 0700, true);
copy(dirname(__DIR__) . '/Fixtures/SatCatalogs/product_service.csv', $root . '/product_service.csv');
$manifest = ['schema_version' => 1, 'generated_at' => '2026-10-01T00:00:00Z', 'catalogs' => [[
    'catalog_name' => 'product-service', 'source' => 'test fixture, not official', 'source_version' => 'fixture-1',
    'generated_at' => '2026-10-01T00:00:00Z', 'file' => 'product_service.csv',
    'checksum' => 'sha256:' . hash_file('sha256', $root . '/product_service.csv'), 'row_count' => 2,
    'complete_authoritative' => true,
]]];
file_put_contents($root . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES));
try {
    $result = (new InstanceUpgradeService($db, $root))->execute('1.1.0');
    $assert($result['current_version'] === '1.1.0', 'failed upgrade resumes safely and records target version');
    $assert($db->table('sat_product_service_keys')->countAllResults() === 2, 'catalog fixture imports once during directed upgrade');
    $again = (new InstanceUpgradeService($db, $root))->execute('1.1.0');
    $assert($again['completed'] === [] && $db->table('sat_product_service_keys')->countAllResults() === 2, 'second upgrade is idempotent and creates no duplicate catalog rows');
    $features = (new InstanceFeaturesService($db))->all();
    $assert($features['clients'] && $features['sales'] && $features['payments'] && array_key_exists('fiscal', $features), 'instance feature source preserves mandatory core and projects optional modules');
    $ready = ['state' => 'READY', 'ready' => true, 'blockers' => [], 'details' => ['catalogs' => [], 'issuer' => ['status' => 'READY']]];
    $production = (object) ['runtimeMode' => 'production', 'enabled' => true, 'environment' => 'production', 'pacAdapter' => 'timbradorxpress', 'allowRealPac' => true, 'stampingEnabled' => true, 'csdEncryptionKey' => str_repeat('a', 64), 'pacEncryptionKey' => str_repeat('b', 32)];
    $mode = (new FiscalInstanceModeService($db, $production, (object) [], static fn () => $ready))->inspect();
    $assert($mode['mode'] === 'PRODUCTION' && $mode['ready'], 'PRODUCTION requires and accepts every explicit guard');
    $production->allowRealPac = false;
    $blocked = (new FiscalInstanceModeService($db, $production, (object) [], static fn () => $ready))->inspect();
    $assert($blocked['mode'] === 'ONBOARDING' && ! $blocked['ready'], 'one missing production guard projects ONBOARDING and blocks PAC');
} finally {
    foreach (glob($root . '/*') ?: [] as $file) unlink($file);
    rmdir($root);
}
echo "passed={$pass}\n";
