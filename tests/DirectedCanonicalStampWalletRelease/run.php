<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\Instance\InstanceVersionService;
use App\Services\Upgrade\InstanceUpgradeService;
use Config\Database;
use Config\Version;

$local = config(Database::class)->default;
if (! in_array((string) $local['hostname'], ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Local fixture server required.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli($local['hostname'], $local['username'], $local['password'], '', (int) $local['port']);
$owned = 'ikontrol_test_release_115_' . bin2hex(random_bytes(5));
$admin->query('CREATE DATABASE `' . $owned . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$base = is_array($local) ? $local : get_object_vars($local);
$db = Database::connect(array_replace($base, ['DSN'=>'', 'database'=>$owned, 'DBPrefix'=>'rel_', 'pConnect'=>false, 'DBDebug'=>true, 'failover'=>[]]), false);
register_shutdown_function(static function () use ($admin, $owned): void {
    $admin->query('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $owned) . '`');
    $admin->close();
});

$pass = 0;
$ok = static function (bool $value, string $message) use (&$pass): void {
    if (! $value) throw new RuntimeException('[FAIL] ' . $message);
    $pass++;
    echo '[PASS] ' . $message . PHP_EOL;
};
$p = 'rel_';
$db->query("CREATE TABLE {$p}app_schema_versions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,version VARCHAR(100) UNIQUE,description VARCHAR(255),applied_at DATETIME)");
$db->query("CREATE TABLE {$p}instance_upgrade_runs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,from_version VARCHAR(40),to_version VARCHAR(40),release_id VARCHAR(150),release_checksum CHAR(64),status VARCHAR(20),started_at DATETIME,completed_at DATETIME NULL,failed_at DATETIME NULL,error_message VARCHAR(1000) NULL)");
$db->query("CREATE TABLE {$p}instance_upgrade_steps (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,upgrade_run_id BIGINT UNSIGNED,step VARCHAR(100),status VARCHAR(20),result_json LONGTEXT NULL,started_at DATETIME NULL,completed_at DATETIME NULL,UNIQUE KEY uq_run_step(upgrade_run_id,step))");
$db->query("CREATE TABLE {$p}fiscal_stamp_accounts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,issuer_profile_id BIGINT UNSIGNED,environment VARCHAR(20),available_balance INT,reserved_balance INT,status VARCHAR(20))");
$db->query("CREATE TABLE {$p}fiscal_stamp_movements (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,stamp_account_id BIGINT UNSIGNED,movement_type VARCHAR(50),quantity INT)");
$db->table('app_schema_versions')->insert(['version'=>'ikontrol-1.1.4','description'=>'fixture','applied_at'=>'2026-10-07 00:00:00']);
$db->table('fiscal_stamp_accounts')->insert(['issuer_profile_id'=>1,'environment'=>'production','available_balance'=>473,'reserved_balance'=>0,'status'=>'active']);

$versions = new InstanceVersionService($db);
$upgrades = new InstanceUpgradeService($db);
$plan = $upgrades->plan('1.1.5');
$ok(Version::VERSION === '1.1.5' && $versions->canonical() === '1.1.5', 'La versión canónica reportada es 1.1.5.');
$ok($plan['compatible'] && $plan['current_version'] === '1.1.4' && $plan['target_version'] === '1.1.5', 'El plan 1.1.4 -> 1.1.5 es compatible.');
$ok(count($plan['packages']) === 1, 'El plan contiene un solo release.');
$package = $plan['packages'][0];
$ok($package['release_id'] === 'ikontrol-1.1.5-canonical-stamp-wallet-resolution', 'El release_id canónico está registrado.');
$ok($package['steps'] === ['record_version'], 'El único step es record_version.');

$balanceBefore = $db->table('fiscal_stamp_accounts')->where('id', 1)->get(1)->getRowArray();
$movementCountBefore = $db->table('fiscal_stamp_movements')->countAllResults();
$result = $upgrades->execute('1.1.5');
$balanceAfter = $db->table('fiscal_stamp_accounts')->where('id', 1)->get(1)->getRowArray();
$ok($result['current_version'] === '1.1.5' && $versions->current() === '1.1.5', 'La ejecución registra iKontrol 1.1.5.');
$ok($balanceBefore === $balanceAfter && $db->table('fiscal_stamp_movements')->countAllResults() === $movementCountBefore, 'El release no cambia saldos ni movimientos de timbres.');
$ok($db->table('instance_upgrade_steps')->where(['step'=>'record_version','status'=>'completed'])->countAllResults() === 1, 'Sólo record_version queda registrado como step completado.');
$again = $upgrades->execute('1.1.5');
$ok($again['completed'] === [] && $db->table('app_schema_versions')->where('version', 'ikontrol-1.1.5')->countAllResults() === 1, 'La segunda ejecución es idempotente.');

$source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/Upgrade/InstanceUpgradeService.php');
$ok(! str_contains($source, 'migrate(') && ! str_contains($source, 'spark migrate'), 'El versionador dirigido no invoca migrate.');

echo 'passed=' . $pass . PHP_EOL;
