<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\ExpenseFinancialTotalService;
use App\Services\FinancialAccountMovementService;
use App\Services\Upgrade\ExpensesSchemaUpgrade;
use App\Services\Upgrade\InstanceUpgradeService;
use App\Services\Upgrade\Legacy\PhysicalSchemaInspector;
use Config\Database;

helper(['date_time']);
$local = config(Database::class)->default;
if (! in_array((string) $local['hostname'], ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Local fixture server required.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli($local['hostname'], $local['username'], $local['password'], '', (int) $local['port']);
$owned = 'ikontrol_test_expenses_upgrade_' . bin2hex(random_bytes(5));
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
    $pass++;
    echo '[PASS] ' . $message . PHP_EOL;
};
$legacyExpenses = static function ($db, string $extra = ''): void {
    $table = $db->protectIdentifiers($db->prefixTable('expenses'));
    $db->query("CREATE TABLE {$table} (
        id INT AUTO_INCREMENT PRIMARY KEY,
        expense_date DATE NULL, category_id INT NULL, description TEXT NULL,
        amount DECIMAL(18,6) NULL, files TEXT NULL, title VARCHAR(255) NULL,
        project_id INT NULL, user_id INT NULL, tax_id INT NULL, tax_id2 INT NULL,
        client_id INT NULL, recurring TINYINT NULL, recurring_expense_id INT NULL,
        repeat_every INT NULL, repeat_type VARCHAR(20) NULL, no_of_cycles INT NULL,
        next_recurring_date DATE NULL, no_of_cycles_completed INT NULL,
        deleted TINYINT NOT NULL DEFAULT 0, created_by INT NULL{$extra}
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
$taxes = static function ($db): void {
    $p = (string) $db->DBPrefix;
    $db->query("CREATE TABLE {$p}taxes (id INT PRIMARY KEY, percentage DECIMAL(9,6), deleted TINYINT NOT NULL DEFAULT 0)");
    $db->table('taxes')->insertBatch([
        ['id'=>1,'percentage'=>'16.000000','deleted'=>0],
        ['id'=>2,'percentage'=>'8.000000','deleted'=>0],
    ]);
};

// Historical Smartfree-like table upgraded through the directed package.
$old = $connect('old_');
$old->query('CREATE TABLE old_app_schema_versions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, version VARCHAR(100) NOT NULL UNIQUE, description VARCHAR(255) NOT NULL, applied_at DATETIME NOT NULL)');
$old->table('app_schema_versions')->insert(['version'=>'ikontrol-1.1.2','description'=>'fixture','applied_at'=>'2026-10-03 00:00:00']);
$legacyExpenses($old);
$taxes($old);
$old->table('expenses')->insertBatch([
    ['id'=>11,'expense_date'=>'2026-10-01','amount'=>'100.000000','tax_id'=>1,'tax_id2'=>2,'deleted'=>0,'created_by'=>1],
    ['id'=>12,'expense_date'=>'2026-10-01','amount'=>'50.000000','tax_id'=>0,'tax_id2'=>0,'deleted'=>1,'created_by'=>1],
]);
$plan = (new InstanceUpgradeService($old))->plan('1.1.3');
$assert($plan['compatible'] && count($plan['packages']) === 1 && $plan['packages'][0]['release_id'] === 'ikontrol-1.1.3-expenses-financial-compatibility', 'plan identifica exclusivamente el paquete 1.1.3');
$result = (new InstanceUpgradeService($old))->execute('1.1.3');
$assert($result['current_version'] === '1.1.3', 'upgrade registra versión 1.1.3 tras verificar expenses');
$schema = new PhysicalSchemaInspector($old, 'old_');
foreach (['financial_total','source_financial_account_id','status','cancelled_at','cancelled_by','cancellation_reason'] as $column) {
    $assert($schema->columnExists('expenses', $column), "upgrade agrega {$column}");
}
$definitions = [];
foreach ($old->query("SELECT column_name,data_type,column_type,is_nullable,character_maximum_length,numeric_precision,numeric_scale FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='old_expenses'")->getResultArray() as $definition) {
    $definitions[$definition['column_name']] = $definition;
}
$assert($definitions['financial_total']['data_type'] === 'decimal' && (int)$definitions['financial_total']['numeric_precision'] === 18 && (int)$definitions['financial_total']['numeric_scale'] === 6 && $definitions['financial_total']['is_nullable'] === 'NO', 'financial_total usa DECIMAL(18,6) NOT NULL');
$assert($definitions['source_financial_account_id']['data_type'] === 'int' && str_contains($definitions['source_financial_account_id']['column_type'], 'unsigned') && $definitions['source_financial_account_id']['is_nullable'] === 'YES', 'cuenta usa INT UNSIGNED NULL para no inventar clasificación histórica');
$assert($definitions['status']['data_type'] === 'varchar' && (int)$definitions['status']['character_maximum_length'] === 20, 'status usa VARCHAR(20)');
$assert($definitions['cancelled_at']['data_type'] === 'datetime' && $definitions['cancelled_by']['data_type'] === 'int' && (int)$definitions['cancellation_reason']['character_maximum_length'] === 500, 'campos de cancelación usan DATETIME, INT y VARCHAR(500)');
$active = $old->table('expenses')->where('id',11)->get(1)->getRow();
$deleted = $old->table('expenses')->where('id',12)->get(1)->getRow();
$assert((string)$active->amount === '100.000000' && (string)$active->financial_total === '124.000000', 'backfill conserva amount y calcula total con impuestos existentes');
$assert($active->source_financial_account_id === null && $active->status === 'active', 'gasto vigente queda activo sin inventar cuenta financiera');
$assert($deleted->status === 'cancelled' && $deleted->cancelled_at === null && $deleted->cancelled_by === null && $deleted->cancellation_reason === null, 'gasto eliminado conserva evidencia y cancelación sin datos inventados');
$again = (new InstanceUpgradeService($old))->execute('1.1.3');
$assert($again['completed'] === [], 'segunda ejecución del paquete no repite pasos');

// Partial schema preserves already established totals and adds only absent fields.
$partial = $connect('partial_');
$legacyExpenses($partial, ", financial_total DECIMAL(18,6) NOT NULL DEFAULT '0.000000', status VARCHAR(20) NOT NULL DEFAULT 'active', cancelled_at DATETIME NULL");
$taxes($partial);
$partial->table('expenses')->insert(['amount'=>'100.000000','financial_total'=>'999.000000','status'=>'active','deleted'=>0]);
$partialResult = (new ExpensesSchemaUpgrade($partial))->apply();
sort($partialResult['added']);
$assert($partialResult['added'] === ['cancellation_reason','cancelled_by','source_financial_account_id'], 'esquema parcial agrega exclusivamente columnas faltantes');
$assert((string)$partial->table('expenses')->get(1)->getRow()->financial_total === '999.000000', 'upgrade parcial no recalcula un total ya persistido');

// Current schema remains unchanged.
$current = $connect('current_');
$legacyExpenses($current, ", financial_total DECIMAL(18,6) NOT NULL DEFAULT '0.000000', source_financial_account_id INT UNSIGNED NULL, status VARCHAR(20) NOT NULL DEFAULT 'active', cancelled_at DATETIME NULL, cancelled_by INT NULL, cancellation_reason VARCHAR(500) NULL");
$taxes($current);
$firstCurrent = (new ExpensesSchemaUpgrade($current))->apply();
$secondCurrent = (new ExpensesSchemaUpgrade($current))->apply();
$assert($firstCurrent['added'] === [] && $secondCurrent['added'] === [], 'esquema completo permanece intacto e idempotente');

$financialFixture = static function ($db, bool $failLate = false) use ($legacyExpenses, $taxes): void {
    $p = (string) $db->DBPrefix;
    $legacyExpenses($db);
    $taxes($db);
    (new ExpensesSchemaUpgrade($db))->apply();
    $db->query("CREATE TABLE {$p}financial_accounts (id INT PRIMARY KEY, name VARCHAR(100), type VARCHAR(20), currency CHAR(3), is_active TINYINT, deleted TINYINT, created_by INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)");
    $db->query("CREATE TABLE {$p}financial_account_movements (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, financial_account_id INT, direction VARCHAR(3), amount DECIMAL(18,6), movement_date DATE, reference_type VARCHAR(50), reference_id INT, movement_role VARCHAR(20) NOT NULL DEFAULT 'original', reversal_of_movement_id INT UNSIGNED NULL, reversed_movement_id INT UNSIGNED NULL, reversal_reason VARCHAR(500) NULL, description TEXT NULL, is_active TINYINT, created_by INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE KEY uq_source_role(reference_type,reference_id,movement_role), UNIQUE KEY uq_reversal(reversal_of_movement_id))");
    $db->table('financial_accounts')->insert(['id'=>1,'name'=>'Caja','type'=>'cash','currency'=>'MXN','is_active'=>1,'deleted'=>0]);
    if ($failLate) {
        $db->query("CREATE TRIGGER {$p}fail_expense_update BEFORE UPDATE ON {$p}expenses FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced late failure'");
    }
};

// Runtime collaborators used by Expenses::save and Expenses::delete.
$runtime = $connect('runtime_');
$financialFixture($runtime);
$total = (new ExpenseFinancialTotalService($runtime))->total('100.000000', 1, 0);
$runtime->transBegin();
$runtime->table('expenses')->insert(['expense_date'=>'2026-10-04','category_id'=>1,'amount'=>'100.000000','financial_total'=>$total,'source_financial_account_id'=>1,'status'=>'active','deleted'=>0,'created_by'=>1]);
$expenseId = (int)$runtime->insertID();
(new FinancialAccountMovementService($runtime))->sync('expense',$expenseId,1,'out',$total,'2026-10-04',1,'Fixture');
$runtime->transCommit();
$saved = $runtime->table('expenses')->where('id',$expenseId)->get(1)->getRow();
$movement = $runtime->table('financial_account_movements')->where(['reference_type'=>'expense','reference_id'=>$expenseId,'movement_role'=>'original'])->get(1)->getRow();
$assert((string)$saved->financial_total === '116.000000' && (int)$saved->source_financial_account_id === 1, 'guardado persiste financial_total y cuenta de salida');
$assert($movement && $movement->direction === 'out' && (string)$movement->amount === '116.000000', 'gasto crea exactamente un movimiento OUT por el total financiero');
$movementService = new FinancialAccountMovementService($runtime);
$movementService->reverseSource('expense',$expenseId,1,'Cancelación fixture');
$runtime->table('expenses')->where('id',$expenseId)->update(['status'=>'cancelled','deleted'=>1,'cancelled_at'=>'2026-10-04 12:00:00','cancelled_by'=>1,'cancellation_reason'=>'Cancelación fixture']);
$reversal = $runtime->table('financial_account_movements')->where('reversal_of_movement_id',(int)$movement->id)->get(1)->getRow();
$assert($reversal && $reversal->direction === 'in' && $runtime->table('expenses')->where(['id'=>$expenseId,'status'=>'cancelled','deleted'=>1])->countAllResults() === 1, 'cancelar gasto crea reversa IN y conserva el original');

// Error after expense and movement writes rolls back both.
$rollback = $connect('rollback_');
$financialFixture($rollback, true);
$failed = false;
$rollback->transBegin();
try {
    $rollback->table('expenses')->insert(['expense_date'=>'2026-10-04','amount'=>'10.000000','financial_total'=>'10.000000','source_financial_account_id'=>1,'status'=>'active','deleted'=>0]);
    $rollbackId = (int)$rollback->insertID();
    (new FinancialAccountMovementService($rollback))->sync('expense',$rollbackId,1,'out','10.000000','2026-10-04',1,'Rollback');
    $rollback->table('expenses')->where('id',$rollbackId)->update(['title'=>'forced']);
    if (! $rollback->transStatus()) throw new RuntimeException('forced late failure');
    $rollback->transCommit();
} catch (Throwable) {
    $failed = true;
    $rollback->transRollback();
}
$assert($failed && $rollback->table('expenses')->countAllResults() === 0 && $rollback->table('financial_account_movements')->countAllResults() === 0, 'fallo posterior revierte integralmente gasto y movimiento');

$controller = file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Expenses.php');
$view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/expenses/modal_form.php');
$assert(str_contains($controller, 'source_financial_account_id ?? null') && str_contains($view, 'source_financial_account_id ?? 0'), 'modal tolera el modelo histórico antes del upgrade sin inventar selección');

echo "passed={$pass}\n";
