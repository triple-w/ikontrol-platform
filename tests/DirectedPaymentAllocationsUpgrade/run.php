<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\AdministrativePaymentService;
use App\Services\Upgrade\InstanceUpgradeService;
use App\Services\Upgrade\Legacy\PhysicalSchemaInspector;
use App\Services\Upgrade\PaymentAllocationsSchemaUpgrade;
use Config\Database;

helper(['date_time']);
$local = config(Database::class)->default;
if (! in_array((string) $local['hostname'], ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Local fixture server required.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli($local['hostname'], $local['username'], $local['password'], '', (int) $local['port']);
$owned = 'ikontrol_test_allocations_upgrade_' . bin2hex(random_bytes(5));
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
$allocationTable = static function ($db, string $extra = ''): void {
    $table = $db->protectIdentifiers($db->prefixTable('payment_allocations'));
    $db->query("CREATE TABLE {$table} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        invoice_payment_id INT NOT NULL,
        invoice_id INT NOT NULL,
        amount_applied DECIMAL(18,6) NOT NULL,
        allocation_date DATE NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_by INT NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        deleted TINYINT NOT NULL DEFAULT 0{$extra},
        UNIQUE KEY uq_legacy_payment_allocation (invoice_payment_id,invoice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};

// Historical schema: execute the directed 1.1.1 -> 1.1.2 package in place.
$old = $connect('old_');
$old->query('CREATE TABLE old_app_schema_versions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, version VARCHAR(100) NOT NULL UNIQUE, description VARCHAR(255) NOT NULL, applied_at DATETIME NOT NULL)');
$old->table('app_schema_versions')->insert(['version'=>'ikontrol-1.1.1','description'=>'fixture','applied_at'=>'2026-10-02 00:00:00']);
$allocationTable($old);
$old->table('payment_allocations')->insert(['invoice_payment_id'=>5,'invoice_id'=>7,'amount_applied'=>'12.340000','allocation_date'=>'2026-10-02','status'=>'active','deleted'=>0]);
$before = (array) $old->table('payment_allocations')->where('id', 1)->get(1)->getRow();
$plan = (new InstanceUpgradeService($old))->plan('1.1.2');
$assert($plan['compatible'] && count($plan['packages']) === 1 && $plan['packages'][0]['release_id'] === 'ikontrol-1.1.2-payment-allocations-compatibility', 'plan identifica exclusivamente el paquete 1.1.2');
$result = (new InstanceUpgradeService($old))->execute('1.1.2');
$assert($result['current_version'] === '1.1.2', 'upgrade registra versión 1.1.2 tras verificar el esquema');
$oldSchema = new PhysicalSchemaInspector($old, 'old_');
foreach (['deactivated_at','deactivated_by','deactivation_reason'] as $column) {
    $assert($oldSchema->columnExists('payment_allocations', $column), "upgrade agrega {$column}");
}
$after = (array) $old->table('payment_allocations')->where('id', 1)->get(1)->getRow();
$assert($after['amount_applied'] === $before['amount_applied'] && $after['status'] === $before['status'] && $after['deactivated_at'] === null && $after['deactivated_by'] === null && $after['deactivation_reason'] === null, 'aplicación histórica e importes permanecen intactos');
$again = (new InstanceUpgradeService($old))->execute('1.1.2');
$assert($again['completed'] === [], 'segunda ejecución del paquete no repite pasos');

// Partial and current schemas add no redundant columns.
$partial = $connect('partial_');
$allocationTable($partial, ', deactivated_at DATETIME NULL');
$partialResult = (new PaymentAllocationsSchemaUpgrade($partial))->apply();
sort($partialResult['added']);
$assert($partialResult['added'] === ['deactivated_by','deactivation_reason'], 'esquema parcial agrega exclusivamente columnas faltantes');

$current = $connect('current_');
$allocationTable($current, ', deactivated_at DATETIME NULL, deactivated_by INT NULL, deactivation_reason VARCHAR(500) NULL');
$firstCurrent = (new PaymentAllocationsSchemaUpgrade($current))->apply();
$secondCurrent = (new PaymentAllocationsSchemaUpgrade($current))->apply();
$assert($firstCurrent['added'] === [] && $secondCurrent['added'] === [], 'esquema moderno permanece intacto e idempotente');

$createPaymentFixture = static function ($db, bool $forceLateFailure = false) use ($allocationTable): void {
    $p = (string) $db->DBPrefix;
    $db->query("CREATE TABLE {$p}clients (id INT PRIMARY KEY, company_name VARCHAR(100), deleted TINYINT NOT NULL DEFAULT 0)");
    $db->query("CREATE TABLE {$p}financial_accounts (id INT PRIMARY KEY, name VARCHAR(100), currency CHAR(3), is_active TINYINT, deleted TINYINT)");
    $db->query("CREATE TABLE {$p}payment_methods (id INT PRIMARY KEY, title VARCHAR(100), default_financial_account_id INT NULL, deleted TINYINT)");
    $db->query("CREATE TABLE {$p}invoices (id INT PRIMARY KEY, client_id INT, type VARCHAR(20), invoice_total DECIMAL(18,6), status VARCHAR(30), commercial_status VARCHAR(30) NULL, closed_at DATETIME NULL, closed_by INT NULL, closure_reason VARCHAR(500) NULL, deleted TINYINT)");
    $db->query("CREATE TABLE {$p}invoice_payments (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT, invoice_id INT NULL, amount DECIMAL(18,6), payment_date DATE, payment_method_id INT, destination_financial_account_id INT, transaction_id VARCHAR(100) NULL, note TEXT NULL, reference VARCHAR(150) NULL, status VARCHAR(20), cancelled_at DATETIME NULL, cancelled_by INT NULL, cancellation_reason VARCHAR(500) NULL, created_by INT NULL, created_at DATETIME NULL, deleted TINYINT)");
    $db->query("CREATE TABLE {$p}financial_account_movements (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, financial_account_id INT, direction VARCHAR(3), amount DECIMAL(18,6), movement_date DATE, reference_type VARCHAR(50), reference_id INT, movement_role VARCHAR(20) NOT NULL DEFAULT 'original', reversal_of_movement_id INT UNSIGNED NULL, reversed_movement_id INT UNSIGNED NULL, reversal_reason VARCHAR(500) NULL, description TEXT NULL, is_active TINYINT, created_by INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE KEY uq_source_role(reference_type,reference_id,movement_role))");
    $allocationTable($db, ', deactivated_at DATETIME NULL, deactivated_by INT NULL, deactivation_reason VARCHAR(500) NULL');
    $db->query("CREATE TABLE {$p}commercial_lifecycle_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, entity_type VARCHAR(20), entity_id INT, event VARCHAR(80), old_status VARCHAR(30) NULL, new_status VARCHAR(30) NULL, reason VARCHAR(500) NULL, user_id INT NULL, created_at DATETIME)");
    $db->table('clients')->insert(['id'=>1,'company_name'=>'Fixture','deleted'=>0]);
    $db->table('financial_accounts')->insert(['id'=>1,'name'=>'Caja','currency'=>'MXN','is_active'=>1,'deleted'=>0]);
    $db->table('payment_methods')->insert(['id'=>1,'title'=>'Efectivo','default_financial_account_id'=>1,'deleted'=>0]);
    $db->table('invoices')->insert(['id'=>10,'client_id'=>1,'type'=>'invoice','invoice_total'=>'100.000000','status'=>'not_paid','commercial_status'=>null,'deleted'=>0]);
    if ($forceLateFailure) {
        $db->query("CREATE TRIGGER {$p}fail_invoice_status BEFORE UPDATE ON {$p}invoices FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced late failure'");
    }
};
$paymentData = ['invoice_id'=>10,'client_id'=>1,'amount'=>'40.000000','payment_date'=>'2026-10-02','payment_method_id'=>1,'destination_financial_account_id'=>1,'reference'=>'fixture','created_by'=>1,'created_at'=>'2026-10-02 00:00:00'];

// Runtime regression: the complete transaction creates each canonical record once.
$runtime = $connect('runtime_');
$createPaymentFixture($runtime);
$service = new AdministrativePaymentService($runtime);
$paymentId = $service->save($paymentData);
$assert($paymentId > 0 && $runtime->table('invoice_payments')->countAllResults() === 1, 'pago nuevo crea invoice_payment');
$assert($runtime->table('financial_account_movements')->where(['reference_type'=>'invoice_payment','reference_id'=>$paymentId,'movement_role'=>'original'])->countAllResults() === 1, 'pago crea un movimiento financiero original');
$assert($runtime->table('payment_allocations')->where(['invoice_payment_id'=>$paymentId,'invoice_id'=>10])->countAllResults() === 1, 'PaymentAllocationService inserta la aplicación después del upgrade');
$invoice = $runtime->table('invoices')->where('id',10)->get(1)->getRow();
$assert($invoice->status === 'partially_paid', 'aplicación actualiza el estado de cobranza de la venta');
$service->save($paymentData, $paymentId);
$assert($runtime->table('financial_account_movements')->where(['reference_type'=>'invoice_payment','reference_id'=>$paymentId,'movement_role'=>'original'])->countAllResults() === 1, 'actualizar el pago no duplica el movimiento financiero');

// A failure after payment, movement and allocation writes rolls back the whole transaction.
$rollback = $connect('rollback_');
$createPaymentFixture($rollback, true);
$failed = false;
try {
    (new AdministrativePaymentService($rollback))->save($paymentData);
} catch (Throwable) {
    $failed = true;
}
$assert($failed, 'fixture provoca un error posterior durante la sincronización del estado');
$assert($rollback->table('invoice_payments')->countAllResults() === 0
    && $rollback->table('financial_account_movements')->countAllResults() === 0
    && $rollback->table('payment_allocations')->countAllResults() === 0,
    'rollback elimina pago, movimiento y aplicación de la transacción fallida');

echo "passed={$pass}\n";
