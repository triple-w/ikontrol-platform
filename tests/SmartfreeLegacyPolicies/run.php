<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\AdministrativePaymentService;
use App\Services\Fiscal\FiscalIssuerResolver;
use App\Services\Fiscal\LegacyFiscalEnvironmentNormalizer;
use App\Services\Sales\SalePaymentEligibilityService;
use Config\Database;

helper(['date_time']);
$local = config(Database::class)->default;
if (! in_array((string) $local['hostname'], ['localhost','127.0.0.1','::1'], true)) throw new RuntimeException('Local fixture server required.');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli($local['hostname'], $local['username'], $local['password'], '', (int) $local['port']);
$owned = 'ikontrol_test_smartfree_policies_' . bin2hex(random_bytes(5));
$admin->query('CREATE DATABASE `' . $owned . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$db = Database::connect(array_replace($local, ['DSN'=>'','database'=>$owned,'DBPrefix'=>'','pConnect'=>false,'DBDebug'=>true,'failover'=>[]]), false);
register_shutdown_function(static function () use ($admin, $owned): void {
    $admin->query('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $owned) . '`');
    $admin->close();
});

foreach ([
    'CREATE TABLE clients (id INT PRIMARY KEY, deleted TINYINT NOT NULL)',
    'CREATE TABLE invoices (id INT PRIMARY KEY, client_id INT NOT NULL, type VARCHAR(20) NOT NULL DEFAULT \'invoice\', invoice_total DECIMAL(18,6) NOT NULL, status VARCHAR(30) NOT NULL, commercial_status VARCHAR(30) NULL, closed_at DATETIME NULL, closed_by INT NULL, closure_reason VARCHAR(500) NULL, deleted TINYINT NOT NULL)',
    'CREATE TABLE invoice_payments (id INT AUTO_INCREMENT PRIMARY KEY, invoice_id INT NULL, client_id INT NOT NULL, payment_date DATE NOT NULL, payment_method_id INT NOT NULL, destination_financial_account_id INT NOT NULL, note TEXT NULL, reference VARCHAR(100) NULL, amount DECIMAL(18,6) NOT NULL, status VARCHAR(20) NOT NULL, deleted TINYINT NOT NULL, created_at DATETIME NULL, created_by INT NULL)',
    'CREATE TABLE payment_allocations (id INT AUTO_INCREMENT PRIMARY KEY, invoice_payment_id INT NOT NULL, invoice_id INT NOT NULL, amount_applied DECIMAL(18,6) NOT NULL, allocation_date DATE NOT NULL, status VARCHAR(20) NOT NULL, deleted TINYINT NOT NULL, deactivated_at DATETIME NULL, deactivated_by INT NULL, deactivation_reason VARCHAR(255) NULL, created_by INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE KEY uq_payment_sale (invoice_payment_id,invoice_id))',
    'CREATE TABLE financial_accounts (id INT PRIMARY KEY, currency VARCHAR(3) NOT NULL, is_active TINYINT NOT NULL, deleted TINYINT NOT NULL)',
    'CREATE TABLE financial_account_movements (id INT AUTO_INCREMENT PRIMARY KEY, financial_account_id INT NOT NULL, direction VARCHAR(10) NOT NULL, amount DECIMAL(18,6) NOT NULL, movement_date DATE NOT NULL, reference_type VARCHAR(60) NOT NULL, reference_id INT NOT NULL, movement_role VARCHAR(20) NOT NULL, description VARCHAR(255) NULL, is_active TINYINT NOT NULL, reversed_movement_id INT NULL, created_by INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE KEY uq_movement_source (reference_type,reference_id,movement_role))',
    'CREATE TABLE fiscal_profiles (id INT PRIMARY KEY, profile_type VARCHAR(20) NOT NULL, company_id INT NULL, environment VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, is_default TINYINT NOT NULL, valid_from DATE NULL, valid_to DATE NULL, tax_regime_id INT NULL)',
    'CREATE TABLE commercial_lifecycle_audit (id INT AUTO_INCREMENT PRIMARY KEY, entity_type VARCHAR(20), entity_id INT, event VARCHAR(80), old_status VARCHAR(30) NULL, new_status VARCHAR(30) NULL, reason VARCHAR(500) NULL, user_id INT NULL, created_at DATETIME)',
] as $sql) $db->query($sql);
$db->table('clients')->insert(['id'=>1,'deleted'=>0]);
$db->table('financial_accounts')->insert(['id'=>1,'currency'=>'MXN','is_active'=>1,'deleted'=>0]);

$pass = 0;
$assert = static function (bool $condition, string $message) use (&$pass): void {
    if (! $condition) throw new RuntimeException('[FAIL] ' . $message);
    $pass++; echo '[PASS] ' . $message . PHP_EOL;
};
$sale = static function (int $id, string $status, ?string $commercial, string $total='100.000000', int $deleted=0) use ($db): void {
    $db->table('invoices')->insert(['id'=>$id,'client_id'=>1,'invoice_total'=>$total,'status'=>$status,'commercial_status'=>$commercial,'deleted'=>$deleted]);
};
$sale(1386, 'not_paid', null);
$sale(2, 'not_paid', 'closed');
$sale(3, 'partially_paid', 'open');
$sale(4, 'cancelled', null);
$sale(5, 'not_paid', null, '100.000000', 1);
$sale(6, 'paid', 'closed');
$db->table('payment_allocations')->insert(['invoice_payment_id'=>90,'invoice_id'=>3,'amount_applied'=>'40.000000','allocation_date'=>'2026-10-02','status'=>'active','deleted'=>0]);
$db->table('payment_allocations')->insert(['invoice_payment_id'=>91,'invoice_id'=>6,'amount_applied'=>'100.000000','allocation_date'=>'2026-10-02','status'=>'active','deleted'=>0]);

$policy = new SalePaymentEligibilityService($db);
$assert($policy->evaluate(1386)['allowed'], 'commercial_status NULL con saldo admite pago');
$assert($policy->evaluate(2)['allowed'], 'commercial_status closed con saldo admite cobranza');
$partial = $policy->evaluate(3);
$assert($partial['allowed'] && $partial['balance'] === '60.000000', 'venta parcialmente pagada conserva saldo cobrable');
$assert($policy->evaluate(4)['code'] === 'SALE_CANCELLED', 'venta cancelada queda bloqueada');
$assert($policy->evaluate(5)['code'] === 'SALE_NOT_FOUND', 'venta eliminada queda bloqueada');
$assert($policy->evaluate(6)['code'] === 'SALE_BALANCE_SETTLED', 'venta pagada sin saldo devuelve bloqueo controlado');

$payments = new AdministrativePaymentService($db);
$payment = $payments->save([
    'invoice_id'=>1386, 'client_id'=>1, 'payment_date'=>'2026-10-02', 'payment_method_id'=>1,
    'destination_financial_account_id'=>1, 'amount'=>'40.000000', 'reference'=>'fixture',
    'created_at'=>'2026-10-02 12:00:00', 'created_by'=>1,
]);
$afterPayment = $db->table('invoices')->where('id', 1386)->get(1)->getRow();
$assert($afterPayment->status === 'partially_paid' && $policy->evaluate(1386)['balance'] === '60.000000', 'registrar pago sincroniza status y saldo');
$payments->save([
    'invoice_id'=>1386, 'client_id'=>1, 'payment_date'=>'2026-10-02', 'payment_method_id'=>1,
    'destination_financial_account_id'=>1, 'amount'=>'40.000000', 'reference'=>'fixture editado',
], $payment);
$assert($db->table('financial_account_movements')->where(['reference_type'=>'invoice_payment','reference_id'=>$payment,'movement_role'=>'original'])->countAllResults() === 1, 'actualizar pago no duplica movimiento financiero');

$db->table('fiscal_profiles')->insertBatch([
    ['id'=>1,'profile_type'=>'issuer','company_id'=>1,'environment'=>'legacy','status'=>'ready','is_default'=>1],
    ['id'=>2,'profile_type'=>'issuer','company_id'=>2,'environment'=>'development','status'=>'ready','is_default'=>1],
    ['id'=>3,'profile_type'=>'issuer','company_id'=>3,'environment'=>'production','status'=>'ready','is_default'=>1],
]);
$fiscal = (object) ['environment'=>'development'];
$normalizer = new LegacyFiscalEnvironmentNormalizer($db, $fiscal);
$dryRun = $normalizer->normalize(true);
$assert($dryRun['matched'] === 1 && $dryRun['updated'] === 0 && $db->table('fiscal_profiles')->where('id',1)->get(1)->getRow()->environment === 'legacy', 'dry-run identifica legacy sin escribir');
$result = $normalizer->normalize(false);
$assert($result['updated'] === 1 && $db->table('fiscal_profiles')->where('id',1)->get(1)->getRow()->environment === 'development', 'legacy se normaliza al ambiente fiscal configurado');
$second = $normalizer->normalize(false);
$assert($second['matched'] === 0 && $second['updated'] === 0, 'normalización repetida es idempotente');
$assert($db->table('fiscal_profiles')->where('id',2)->get(1)->getRow()->environment === 'development'
    && $db->table('fiscal_profiles')->where('id',3)->get(1)->getRow()->environment === 'production', 'perfiles con ambiente explícito no se modifican');
$resolver = new FiscalIssuerResolver($db);
$assert((int) ($resolver->resolve(1, 'development')?->id ?? 0) === 1, 'resolver detecta emisor legacy después de normalizarlo');
$assert($resolver->resolve(2, 'production') === null && $resolver->resolve(3, 'development') === null, 'development y production permanecen aislados');
$db->table('fiscal_profiles')->insert(['id'=>4,'profile_type'=>'issuer','company_id'=>2,'environment'=>'legacy','status'=>'ready','is_default'=>0]);
$conflictRejected = false;
try { $normalizer->normalize(false); } catch (RuntimeException $error) { $conflictRejected = str_contains($error->getMessage(), 'revisión manual'); }
$assert($conflictRejected && $db->table('fiscal_profiles')->where('id',4)->get(1)->getRow()->environment === 'legacy', 'normalización rechaza ambigüedad cuando ya existe emisor en el ambiente destino');
$onboarding = file_get_contents(APPPATH . 'Services/Fiscal/FiscalOnboardingReadinessService.php');
$saleReadiness = file_get_contents(APPPATH . 'Services/Fiscal/SaleFiscalReadinessService.php');
$assert(str_contains($onboarding, 'FiscalIssuerResolver') && str_contains($saleReadiness, 'FiscalIssuerResolver'), 'onboarding y revisión de venta usan el mismo resolver después de normalizar');
$controller = file_get_contents(APPPATH . 'Controllers/Invoice_payments.php');
$assert(str_contains($controller, 'SalePaymentEligibilityService') && ! str_contains($controller, "in_array(\$saleLifecycle->commercial_status"), 'save_payment consume la policy canónica y no decide por commercial_status');
$paymentView = file_get_contents(APPPATH . 'Views/invoices/payment_modal_form.php');
$assert(str_contains($controller, 'invoice_display_id') && str_contains($controller, '$row->display_id') && str_contains($paymentView, 'esc($invoice_display_id'), 'modal y selector presentan display_id y conservan invoice_id interno');

echo "passed={$pass}\n";
