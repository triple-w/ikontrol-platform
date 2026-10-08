<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\Fiscal\FiscalStampAdminService;
use App\Services\Fiscal\Stamps\FiscalStampAccountService;
use App\Services\Fiscal\Stamps\FiscalStampBalanceService;
use Config\Database;

helper(['date_time']);
$local = config(Database::class)->default;
if (! in_array((string) $local['hostname'], ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Local fixture server required.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli($local['hostname'], $local['username'], $local['password'], '', (int) $local['port']);
$owned = 'ikontrol_test_stamp_identity_' . bin2hex(random_bytes(5));
$admin->query('CREATE DATABASE `' . $owned . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$base = is_array($local) ? $local : get_object_vars($local);
$db = Database::connect(array_replace($base, ['DSN'=>'', 'database'=>$owned, 'DBPrefix'=>'wallet_', 'pConnect'=>false, 'DBDebug'=>true, 'failover'=>[]]), false);
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
$p = 'wallet_';
$db->query("CREATE TABLE {$p}fiscal_profiles (id INT PRIMARY KEY,company_id INT NULL,profile_type VARCHAR(20),rfc VARCHAR(20),legal_name VARCHAR(150),environment VARCHAR(20),status VARCHAR(20),deleted TINYINT DEFAULT 0)");
$db->query("CREATE TABLE {$p}fiscal_stamp_accounts (id INT AUTO_INCREMENT PRIMARY KEY,issuer_profile_id INT,environment VARCHAR(20),available_balance INT,reserved_balance INT,status VARCHAR(20),created_at DATETIME NULL,updated_at DATETIME NULL,UNIQUE KEY uq_issuer_env(issuer_profile_id,environment))");
$db->query("CREATE TABLE {$p}fiscal_stamp_movements (id INT AUTO_INCREMENT PRIMARY KEY,stamp_account_id INT,environment VARCHAR(20),movement_type VARCHAR(50),quantity INT,available_before INT,reserved_before INT,available_after INT,reserved_after INT,fiscal_document_id INT NULL,pac_attempt_id INT NULL,fiscal_document_stamp_id INT NULL,fiscal_cancellation_request_id INT NULL,idempotency_key VARCHAR(191) UNIQUE,consumption_key VARCHAR(191) NULL,reason TEXT,reference VARCHAR(191) NULL,created_by INT NULL,created_at DATETIME NULL)");
$db->query("CREATE TABLE {$p}fiscal_documents (id INT PRIMARY KEY,invoice_id INT NULL,issuer_profile_id INT,environment VARCHAR(20),status VARCHAR(40),deleted TINYINT DEFAULT 0)");
$db->query("CREATE TABLE {$p}fiscal_stamp_attempts (id INT PRIMARY KEY,fiscal_document_id INT,status VARCHAR(50),requires_reconciliation TINYINT,uuid VARCHAR(36) NULL)");
$db->query("CREATE TABLE {$p}fiscal_document_stamps (id INT PRIMARY KEY,fiscal_document_id INT,stamp_attempt_id INT NULL,uuid VARCHAR(36) NULL)");
$db->query("CREATE TABLE {$p}fiscal_cancellation_requests (id INT PRIMARY KEY,fiscal_document_id INT,environment VARCHAR(20),status VARCHAR(30))");

$db->table('fiscal_profiles')->insertBatch([
    ['id'=>1,'company_id'=>1,'profile_type'=>'issuer','rfc'=>'DOLD860620EW7','legal_name'=>'DOLD wallet legacy','environment'=>'production','status'=>'ready','deleted'=>0],
    ['id'=>2,'company_id'=>1,'profile_type'=>'issuer','rfc'=>'dold860620ew7 ','legal_name'=>'DOLD operativo','environment'=>'production','status'=>'ready','deleted'=>0],
    ['id'=>3,'company_id'=>1,'profile_type'=>'issuer','rfc'=>'DOLD860620EW7','legal_name'=>'DOLD desarrollo','environment'=>'development','status'=>'ready','deleted'=>0],
    ['id'=>4,'company_id'=>1,'profile_type'=>'issuer','rfc'=>'OTRO010101AAA','legal_name'=>'Otro','environment'=>'production','status'=>'ready','deleted'=>0],
    ['id'=>5,'company_id'=>2,'profile_type'=>'issuer','rfc'=>'DOLD860620EW7','legal_name'=>'DOLD otra empresa','environment'=>'production','status'=>'ready','deleted'=>0],
    ['id'=>6,'company_id'=>1,'profile_type'=>'issuer','rfc'=>'CERO010101AAA','legal_name'=>'Sin saldo','environment'=>'production','status'=>'ready','deleted'=>0],
]);
$db->table('fiscal_stamp_accounts')->insertBatch([
    ['issuer_profile_id'=>1,'environment'=>'production','available_balance'=>473,'reserved_balance'=>5,'status'=>'active'],
    ['issuer_profile_id'=>3,'environment'=>'development','available_balance'=>7,'reserved_balance'=>2,'status'=>'active'],
    ['issuer_profile_id'=>4,'environment'=>'production','available_balance'=>91,'reserved_balance'=>0,'status'=>'active'],
    ['issuer_profile_id'=>5,'environment'=>'production','available_balance'=>33,'reserved_balance'=>0,'status'=>'active'],
    ['issuer_profile_id'=>6,'environment'=>'production','available_balance'=>0,'reserved_balance'=>0,'status'=>'active'],
]);
$accountId = (int) $db->table('fiscal_stamp_accounts')->where(['issuer_profile_id'=>1,'environment'=>'production'])->get(1)->getRow()->id;
$db->table('fiscal_stamp_movements')->insert(['stamp_account_id'=>$accountId,'environment'=>'production','movement_type'=>'document_consumption','quantity'=>12,'available_before'=>485,'available_after'=>473,'reserved_before'=>5,'reserved_after'=>5,'idempotency_key'=>'fixture-consumed','reason'=>'fixture']);
$db->table('fiscal_stamp_movements')->insert(['stamp_account_id'=>$accountId,'environment'=>'production','movement_type'=>'adjustment_debit','quantity'=>-2,'available_before'=>475,'available_after'=>473,'reserved_before'=>5,'reserved_after'=>5,'idempotency_key'=>'fixture-debit','reason'=>'fixture']);
$db->table('fiscal_documents')->insert(['id'=>100,'invoice_id'=>80,'issuer_profile_id'=>2,'environment'=>'production','status'=>'stamped','deleted'=>0]);
$db->table('fiscal_stamp_attempts')->insert(['id'=>20,'fiscal_document_id'=>100,'status'=>'prepared','requires_reconciliation'=>0,'uuid'=>null]);
$db->table('fiscal_cancellation_requests')->insert(['id'=>10,'fiscal_document_id'=>100,'environment'=>'production','status'=>'sending']);

$balances = new FiscalStampBalanceService($db);
$production = $balances->forIssuer(2, 'production');
$ok($production['account_id'] === $accountId && $production['wallet_issuer_profile_id'] === 1, 'El perfil operativo resuelve la wallet histórica de la misma identidad fiscal.');
$ok($production['available'] === 473 && $production['reserved'] === 5 && $production['usable'] === 473 && $production['consumed'] === 14, 'Saldo canónico expone available, reserved, consumed y usable.');
$ok($balances->forDocument(100) === $production, 'Documento y cancel form obtienen exactamente el mismo saldo canónico.');
$ok($balances->forIssuer(6, 'production')['usable'] === 0, 'Saldo production cero bloquea de forma controlada.');
$ok($balances->forIssuer(3, 'development')['usable'] === 7 && $balances->forIssuer(3, 'production')['usable'] === 0, 'Development y production permanecen aislados.');
$ok($balances->forIssuer(4, 'production')['usable'] === 91, 'Otro RFC conserva exclusivamente su wallet.');
$ok($balances->forIssuer(5, 'production')['usable'] === 33, 'La misma RFC en otra empresa no comparte wallet.');
$ok(count($balances->recentForIssuer(2, 20, 'production')) === 2, 'La vista fiscal muestra movimientos de la wallet canónica resuelta.');

$accounts = new FiscalStampAccountService($db);
$isolationBlocked = false;
try { $accounts->getOrCreateAccountForIssuer(1, 'development'); } catch (Throwable) { $isolationBlocked = true; }
$ok($isolationBlocked, 'La escritura no crea una wallet development para un emisor production.');
$movement = $accounts->consumeCancellationRequest(10, 1);
$ok((int) $movement->stamp_account_id === $accountId && (int) $movement->available_after === 472, 'La ejecución de cancelación consume la misma wallet mostrada por el modal.');
$reservation = $accounts->reserveForAttempt(20, 1);
$ok((int) $reservation->stamp_account_id === $accountId && (int) $reservation->available_after === 471 && (int) $reservation->reserved_after === 6, 'El timbrado normal reserva sobre la misma wallet canónica.');
$ok($db->table('fiscal_stamp_accounts')->where('issuer_profile_id', 2)->countAllResults() === 0, 'No se crea una wallet duplicada para el perfil alias.');

$adminRows = (new FiscalStampAdminService($db))->getAccounts();
$current = array_values(array_filter($adminRows, static fn(array $row): bool => $row['issuer_profile_id'] === 1))[0] ?? null;
$ok($current && $current['stamp_account_id'] === $accountId && $current['available_balance'] === 471, 'Administración y flujo fiscal comparten la cuenta física y el saldo final.');
$ok(count(array_filter($adminRows, static fn(array $row): bool => $row['stamp_account_id'] === $accountId)) === 1, 'Administración no duplica una wallet física por perfiles alias.');

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$invoiceController = $read('app/Controllers/Fiscal/Invoices.php');
$invoiceFlow = $read('app/Services/Fiscal/FiscalInvoiceFlowService.php');
$cancellation = $read('app/Services/Fiscal/Cancellation/FiscalCancellationService.php');
$complements = $read('app/Controllers/Payment_complement_cancellations.php');
$stamping = $read('app/Services/Fiscal/Pac/FiscalStampingService.php');
$creditNotes = $read('app/Services/Fiscal/CreditNoteService.php');
$ok(str_contains($invoiceController, 'FiscalStampBalanceService') && str_contains($cancellation, 'FiscalStampBalanceService'), 'Modal y ejecución final de cancelación comparten FiscalStampBalanceService.');
$ok(str_contains($invoiceFlow, 'FiscalStampBalanceService') && str_contains($stamping, 'FiscalStampAccountService'), 'Preflight y timbrado usan la frontera canónica y su ledger.');
$ok(str_contains($complements, 'FiscalStampBalanceService') && str_contains($creditNotes, 'FiscalStampingService'), 'Complementos y notas de crédito reutilizan el flujo canónico.');

echo 'passed=' . $pass . PHP_EOL;
