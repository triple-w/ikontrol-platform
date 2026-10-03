<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\FinancialAccountMovementService;
use App\Services\Upgrade\FinancialAccountMovementsSchemaUpgrade;
use App\Services\Upgrade\InstanceUpgradeService;
use App\Services\Upgrade\Legacy\PhysicalSchemaInspector;
use Config\Database;

helper(['date_time']);
$local = config(Database::class)->default;
if (! in_array((string) $local['hostname'], ['localhost','127.0.0.1','::1'], true)) throw new RuntimeException('Local fixture server required.');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli($local['hostname'], $local['username'], $local['password'], '', (int) $local['port']);
$owned = 'ikontrol_test_ledger_upgrade_' . bin2hex(random_bytes(5));
$admin->query('CREATE DATABASE `' . $owned . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$base = is_array($local) ? $local : get_object_vars($local);
$connect = static fn (string $prefix) => Database::connect(array_replace($base, [
    'DSN'=>'', 'database'=>$owned, 'DBPrefix'=>$prefix, 'pConnect'=>false, 'DBDebug'=>true, 'failover'=>[],
]), false);
register_shutdown_function(static function () use ($admin, $owned): void {
    $admin->query('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $owned) . '`');
    $admin->close();
});

$pass = 0;
$assert = static function (bool $condition, string $message) use (&$pass): void {
    if (! $condition) throw new RuntimeException('[FAIL] ' . $message);
    $pass++; echo '[PASS] ' . $message . PHP_EOL;
};
$legacyTable = static function ($db, string $extra=''): void {
    $table = $db->protectIdentifiers($db->prefixTable('financial_account_movements'));
    $db->query("CREATE TABLE {$table} (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, financial_account_id INT UNSIGNED NOT NULL, direction VARCHAR(3) NOT NULL, amount DECIMAL(18,6) NOT NULL, movement_date DATE NOT NULL, reference_type VARCHAR(50) NOT NULL, reference_id INT NOT NULL, description TEXT NULL, is_active TINYINT NOT NULL DEFAULT 1, created_by INT NULL, created_at DATETIME NULL{$extra}, UNIQUE KEY uq_legacy_movement(reference_type,reference_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};

// Historical bridge schema: package 1.1.0 -> 1.1.1 must evolve it in place.
$old = $connect('old_');
$old->query('CREATE TABLE old_app_schema_versions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, version VARCHAR(100) NOT NULL UNIQUE, description VARCHAR(255) NOT NULL, applied_at DATETIME NOT NULL)');
$old->table('app_schema_versions')->insert(['version'=>'ikontrol-1.1.0','description'=>'fixture','applied_at'=>'2026-10-01 00:00:00']);
$old->query("CREATE TABLE old_financial_accounts (id INT UNSIGNED PRIMARY KEY,name VARCHAR(150),type VARCHAR(30),description TEXT NULL,currency CHAR(3),opening_balance DECIMAL(18,6),is_active TINYINT,deleted TINYINT,created_by INT NULL,created_at DATETIME NULL,updated_at DATETIME NULL)");
$old->table('financial_accounts')->insert(['id'=>1,'name'=>'Legacy','type'=>'other','currency'=>'MXN','opening_balance'=>'0','is_active'=>1,'deleted'=>0]);
$legacyTable($old);
$old->table('financial_account_movements')->insert(['financial_account_id'=>1,'direction'=>'IN','amount'=>'10','movement_date'=>'2026-10-01','reference_type'=>'invoice_payment','reference_id'=>77,'description'=>'legacy','is_active'=>1,'created_at'=>'2026-10-01 00:00:00']);
$plan = (new InstanceUpgradeService($old))->plan('1.1.1');
$assert($plan['compatible'] && count($plan['packages']) === 1 && $plan['packages'][0]['from'] === '1.1.0', 'plan dirigido identifica paquete 1.1.0 a 1.1.1 sin escribir');
$assert(! $old->fieldExists('movement_role', 'financial_account_movements'), 'precondición conserva esquema histórico sin columnas modernas');
$result = (new InstanceUpgradeService($old))->execute('1.1.1');
$assert($result['current_version'] === '1.1.1', 'upgrade registra versión 1.1.1 después del post-check');
$oldSchema = new PhysicalSchemaInspector($old, 'old_');
foreach (['movement_role','reversal_of_movement_id','reversed_movement_id','reversal_reason','updated_at'] as $column) {
    $assert($oldSchema->columnExists('financial_account_movements', $column), "upgrade agrega {$column}");
}
$legacy = $old->table('financial_account_movements')->where('id',1)->get(1)->getRow();
$assert($legacy->movement_role === 'original' && $legacy->reversal_of_movement_id === null && $legacy->reversed_movement_id === null, 'movimiento histórico queda como original sin inventar reversas');
(new FinancialAccountMovementService($old))->sync('invoice_payment',77,1,'in','10','2026-10-01',1,'actualizado');
$assert($old->table('financial_account_movements')->where(['reference_type'=>'invoice_payment','reference_id'=>77,'movement_role'=>'original'])->countAllResults() === 1, 'servicio runtime consulta movement_role sin error ni duplicar movimiento');
$again = (new InstanceUpgradeService($old))->execute('1.1.1');
$assert($again['completed'] === [], 'segunda ejecución del paquete dirigido no repite pasos');

// Partially updated schema: only absent columns are added and explicit roles survive.
$partial = $connect('partial_');
$legacyTable($partial, ', movement_role VARCHAR(20) NULL DEFAULT NULL, updated_at DATETIME NULL');
$partial->table('financial_account_movements')->insertBatch([
    ['financial_account_id'=>1,'direction'=>'in','amount'=>'1','movement_date'=>'2026-10-01','reference_type'=>'a','reference_id'=>1,'movement_role'=>null,'is_active'=>1],
    ['financial_account_id'=>1,'direction'=>'out','amount'=>'1','movement_date'=>'2026-10-01','reference_type'=>'b','reference_id'=>2,'movement_role'=>'reversal','is_active'=>1],
]);
$partialResult = (new FinancialAccountMovementsSchemaUpgrade($partial))->apply();
sort($partialResult['added']);
$assert($partialResult['added'] === ['reversal_of_movement_id','reversal_reason','reversed_movement_id'], 'esquema parcial agrega exclusivamente las tres columnas faltantes');
$roles = array_column($partial->table('financial_account_movements')->orderBy('id')->get()->getResultArray(), 'movement_role');
$assert($roles === ['original','reversal'], 'backfill corrige sólo rol vacío y conserva reversa explícita');

// Already current schema: direct step is also idempotent independently of the package registry.
$current = $connect('current_');
$legacyTable($current, ", movement_role VARCHAR(20) NOT NULL DEFAULT 'original', reversal_of_movement_id INT UNSIGNED NULL, reversed_movement_id INT UNSIGNED NULL, reversal_reason VARCHAR(500) NULL, updated_at DATETIME NULL");
$firstCurrent = (new FinancialAccountMovementsSchemaUpgrade($current))->apply();
$secondCurrent = (new FinancialAccountMovementsSchemaUpgrade($current))->apply();
$assert($firstCurrent['added'] === [] && $secondCurrent['added'] === [] && $secondCurrent['legacy_roles_normalized'] === 0, 'esquema actualizado permanece intacto en ejecuciones repetidas');

echo "passed={$pass}\n";
