<?php
declare(strict_types=1);

if (! extension_loaded('sqlite3')) {
    fwrite(STDERR, "BLOCKED: sqlite3 required.\n");
    exit(2);
}

foreach ([
    'fiscal.environment' => 'development',
    'fiscal.runtimeMode' => 'automated_test',
    'fiscal.pacAdapter' => 'fake',
    'fiscal.allowRealPac' => 'false',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\Fiscal\CfdiPaymentRuleService;
use App\Services\Fiscal\FiscalPaymentMethodReadinessService;
use App\Services\Fiscal\FiscalUnifiedReadinessActionService;
use App\Services\PaymentComplementReadinessService;
use Config\Database;

$config = [
    'DSN' => '',
    'hostname' => '',
    'username' => '',
    'password' => '',
    'database' => ':memory:',
    'DBDriver' => 'SQLite3',
    'DBPrefix' => '',
    'pConnect' => false,
    'foreignKeys' => false,
];
$databaseConfig = config(Database::class);
$databaseConfig->default = $config;
$db = Database::connect($config, true);
$db->initialize();
if ($db->DBDriver !== 'SQLite3' || $db->getDatabase() !== ':memory:') {
    throw new RuntimeException('Unsafe target: SQLite memory database required.');
}

foreach ([
    'CREATE TABLE payment_methods(id INTEGER PRIMARY KEY,title TEXT,deleted INTEGER,available_on_invoice INTEGER)',
    'CREATE TABLE fiscal_payment_method_mappings(id INTEGER PRIMARY KEY AUTOINCREMENT,payment_method_id INTEGER,sat_payment_form_code TEXT,is_active INTEGER)',
    'CREATE TABLE sat_payment_forms(code TEXT PRIMARY KEY,name TEXT,is_active INTEGER)',
    'CREATE TABLE invoices(id INTEGER PRIMARY KEY,invoice_total TEXT,deleted INTEGER)',
    'CREATE TABLE invoice_payments(id INTEGER PRIMARY KEY,invoice_id INTEGER,amount TEXT,payment_method_id INTEGER,payment_date TEXT,status TEXT,deleted INTEGER)',
    'CREATE TABLE payment_allocations(id INTEGER PRIMARY KEY,invoice_payment_id INTEGER,invoice_id INTEGER,amount_applied TEXT,status TEXT,deleted INTEGER)',
    'CREATE TABLE clients(id INTEGER PRIMARY KEY,company_name TEXT,vat_number TEXT)',
    'CREATE TABLE payment_complements(id INTEGER PRIMARY KEY,client_id INTEGER,issue_date TEXT,status TEXT,deleted INTEGER)',
    'CREATE TABLE payment_complement_payments(id INTEGER PRIMARY KEY,payment_complement_id INTEGER,source_invoice_payment_id INTEGER,payment_form_code TEXT,amount TEXT,deleted INTEGER)',
    'CREATE TABLE payment_complement_documents(id INTEGER PRIMARY KEY,payment_complement_payment_id INTEGER,fiscal_document_id INTEGER,invoice_id INTEGER,document_uuid TEXT,currency_dr TEXT,equivalence_dr TEXT,installment_number INTEGER,previous_balance TEXT,amount_paid TEXT,remaining_balance TEXT,deleted INTEGER)',
    'CREATE TABLE fiscal_documents(id INTEGER PRIMARY KEY,series TEXT,folio TEXT,payment_method_code TEXT,status TEXT,deleted INTEGER,cancelled_at TEXT)',
    'CREATE TABLE fiscal_document_stamps(id INTEGER PRIMARY KEY,fiscal_document_id INTEGER,uuid TEXT)',
] as $sql) {
    $db->query($sql);
}

$db->table('sat_payment_forms')->insertBatch([
    ['code' => '99', 'name' => 'Por definir', 'is_active' => 1],
    ['code' => '03', 'name' => 'Transferencia electrónica', 'is_active' => 1],
    ['code' => '04', 'name' => 'Tarjeta de crédito', 'is_active' => 1],
    ['code' => '01', 'name' => 'Efectivo', 'is_active' => 0],
]);
$db->table('invoices')->insertBatch([
    ['id' => 10, 'invoice_total' => '116.000000', 'deleted' => 0],
    ['id' => 11, 'invoice_total' => '20.000000', 'deleted' => 0],
]);

$pass = 0;
$fail = 0;
$ok = static function (bool $condition, string $message) use (&$pass, &$fail): void {
    if ($condition) {
        $pass++;
        echo "PASS {$message}\n";
    } else {
        $fail++;
        echo "FAIL {$message}\n";
    }
};
$throws = static function (callable $action, string $contains = ''): bool {
    try {
        $action();
        return false;
    } catch (Throwable $exception) {
        return $contains === '' || str_contains($exception->getMessage(), $contains);
    }
};

$rules = new CfdiPaymentRuleService($db);
$readiness = new FiscalPaymentMethodReadinessService($db);
$coverage = $readiness->inspect();
$ok(
    $coverage['configured'] === 0 && $coverage['mapped'] === 0
        && $coverage['coverage_complete'] && !$coverage['global_blocking'],
    'cero métodos administrativos reporta 0 configurados / 0 mapeados sin bloqueo global'
);
$ok(
    $rules->validateForInvoice(10, 'PPD', '99')['requires_administrative_mapping'] === false,
    'PPD/99 supera readiness sin métodos administrativos'
);

$db->table('payment_methods')->insertBatch([
    ['id' => 1, 'title' => 'Transferencia', 'deleted' => 0, 'available_on_invoice' => 1],
    ['id' => 2, 'title' => 'Sin mapping', 'deleted' => 0, 'available_on_invoice' => 1],
    ['id' => 3, 'title' => 'Desactivado', 'deleted' => 1, 'available_on_invoice' => 1],
    ['id' => 4, 'title' => 'No disponible en facturas', 'deleted' => 0, 'available_on_invoice' => 0],
]);
$coverage = $readiness->inspect();
$ok(
    $coverage['configured'] === 2 && $coverage['mapped'] === 0
        && !$coverage['coverage_complete'] && !$coverage['global_blocking'],
    'contador distingue métodos configurados y mappings válidos, excluyendo métodos desactivados'
);
$ok(
    $rules->validateForInvoice(10, 'PPD', '99')['ready'],
    'PPD/99 ignora métodos administrativos existentes pero no utilizados'
);
$ok(
    $throws(fn () => $rules->validateForInvoice(10, 'PPD', '03'), 'debe ser 99'),
    'PPD rechaza una forma SAT incompatible'
);
$ok(
    $throws(fn () => $rules->validateForInvoice(10, 'PUE', '99'), 'PUE'),
    'PUE rechaza la forma 99'
);
$ok(
    $throws(fn () => $rules->validateForInvoice(10, 'PUE', '01'), 'inactiva'),
    'PUE rechaza una forma SAT inactiva aunque exista en catálogo'
);

$db->table('fiscal_payment_method_mappings')->insert([
    'payment_method_id' => 1, 'sat_payment_form_code' => '03', 'is_active' => 1,
]);
$db->table('invoice_payments')->insert([
    'id' => 1, 'invoice_id' => 10, 'amount' => '116.000000', 'payment_method_id' => 1,
    'payment_date' => '2026-10-31', 'status' => 'active', 'deleted' => 0,
]);
$pue = $rules->validateForInvoice(10, 'PUE', '03');
$ok(
    $pue['ready'] && $pue['requires_administrative_mapping'] && $pue['payment_method_id'] === 1,
    'PUE valida pago total y forma SAT coherente con el método administrativo efectivo'
);
$suggestion = $rules->suggest(10);
$ok(
    $suggestion['payment_method_code'] === 'PUE' && $suggestion['payment_form_code'] === '03',
    'sugerencia PUE corresponde al mapping del pago efectivo'
);

$db->table('payment_methods')->insert([
    'id' => 5, 'title' => 'Tarjeta', 'deleted' => 0, 'available_on_invoice' => 1,
]);
$db->table('fiscal_payment_method_mappings')->insert([
    'payment_method_id' => 5, 'sat_payment_form_code' => '04', 'is_active' => 1,
]);
$db->table('invoice_payments')->insert([
    'id' => 2, 'invoice_id' => 10, 'amount' => '1.000000', 'payment_method_id' => 5,
    'payment_date' => '2026-10-31', 'status' => 'active', 'deleted' => 0,
]);
$ok(
    $throws(fn () => $rules->validateForInvoice(10, 'PUE', '03'), 'todos los métodos'),
    'PUE no acepta métodos de pago efectivos con mappings SAT incompatibles'
);
$mixedSuggestion = $rules->suggest(10);
$ok(
    $mixedSuggestion['payment_form_code'] === null && $mixedSuggestion['warning'] !== null,
    'la sugerencia advierte cuando los pagos efectivos no comparten una FormaPago única'
);

$db->table('payment_methods')->insert([
    'id' => 6, 'title' => 'Pago sin mapping', 'deleted' => 0, 'available_on_invoice' => 1,
]);
$db->table('invoice_payments')->insert([
    'id' => 3, 'invoice_id' => 11, 'amount' => '20.000000', 'payment_method_id' => 6,
    'payment_date' => '2026-10-31', 'status' => 'active', 'deleted' => 0,
]);
$ok(
    $throws(fn () => $rules->validateForInvoice(11, 'PUE', '03'), 'no tiene forma de pago SAT activa'),
    'PUE bloquea el método administrativo efectivamente usado cuando carece de mapping'
);
$ok(
    $readiness->forMethod(2)['status'] === 'MAPPING_MISSING',
    'complemento identifica el pago incorporado sin mapping'
);
$db->table('clients')->insert(['id' => 1, 'company_name' => 'Cliente de prueba', 'vat_number' => 'AAA010101AAA']);
$db->table('payment_complements')->insertBatch([
    ['id' => 1, 'client_id' => 1, 'issue_date' => date('Y-m-d'), 'status' => 'draft', 'deleted' => 0],
    ['id' => 2, 'client_id' => 1, 'issue_date' => date('Y-m-d'), 'status' => 'draft', 'deleted' => 0],
]);
$db->table('payment_complement_payments')->insertBatch([
    ['id' => 1, 'payment_complement_id' => 1, 'source_invoice_payment_id' => 1, 'payment_form_code' => '03', 'amount' => '116.000000', 'deleted' => 0],
    ['id' => 2, 'payment_complement_id' => 2, 'source_invoice_payment_id' => 3, 'payment_form_code' => '03', 'amount' => '20.000000', 'deleted' => 0],
]);
$complements = new PaymentComplementReadinessService($db);
$mappedComplement = $complements->check(1);
$mappedChecks = array_column($mappedComplement['checks'], 'severity', 'label');
$ok(
    ($mappedChecks['Mapping del pago #1'] ?? '') === 'correct',
    'complemento acepta el mapping válido del pago administrativo incorporado'
);
$unmappedComplement = $complements->check(2);
$unmappedChecks = array_column($unmappedComplement['checks'], 'severity', 'label');
$ok(
    ($unmappedChecks['Mapping del pago #3'] ?? '') === 'error'
        && (bool)array_filter(
            $unmappedComplement['checks'],
            static fn(array $check): bool => $check['label'] === 'Mapping del pago #3'
                && str_contains($check['detail'], 'no tiene forma de pago SAT activa')
        ),
    'complemento bloquea un pago incorporado cuyo método administrativo no tiene mapping'
);

$db->table('payment_allocations')->insert([
    'id' => 2, 'invoice_payment_id' => 3, 'invoice_id' => 11,
    'amount_applied' => '20.000000', 'status' => 'active', 'deleted' => 0,
]);

$db->table('fiscal_payment_method_mappings')->insert([
    'payment_method_id' => 2, 'sat_payment_form_code' => '99', 'is_active' => 1,
]);
$ok(
    $readiness->forMethod(2)['status'] === 'FORM_INCOMPATIBLE',
    'forma 99 activa en catálogo sigue siendo inválida para el mapping de un pago recibido'
);
$db->table('payment_allocations')->insert([
    'id' => 1, 'invoice_payment_id' => 1, 'invoice_id' => 10,
    'amount_applied' => '116.000000', 'status' => 'active', 'deleted' => 0,
]);
$allocated = $rules->validateForAllocations(
    [['sale_id' => 10, 'allocated_total' => '116.000000']],
    'PUE',
    '03'
);
$ok(
    $allocated['ready'] && $allocated['requires_administrative_mapping'],
    'preflight PUE acepta pagos aplicados y mapeados que cubren la venta'
);
$ok(
    $throws(
        fn () => $rules->validateForAllocations(
            [['sale_id' => 11, 'allocated_total' => '20.000000']],
            'PUE',
            '03'
        ),
        'forma de pago SAT activa'
    ),
    'preflight PUE bloquea mapping faltante en el pago aplicado'
);
$ok(
    !$rules->validateForAllocations(
        [['sale_id' => 11, 'allocated_total' => '20.000000']],
        'PPD',
        '99'
    )['requires_administrative_mapping'],
    'guardar/preflight PPD no exige mapping de métodos sin uso'
);
$db->table('fiscal_payment_method_mappings')->insert([
    'payment_method_id' => 1, 'sat_payment_form_code' => '03', 'is_active' => 1,
]);
$ok(
    $readiness->forMethod(1)['status'] === 'MAPPING_DUPLICATE',
    'mappings duplicados fallan de forma segura'
);

$onboarding = [
    'state' => 'ONBOARDING',
    'details' => [
        'issuer' => ['id' => 1, 'status' => 'READY'],
        'csd' => ['status' => 'READY', 'code' => 'ready', 'label' => 'ok'],
        'series' => ['status' => 'READY'],
        'pac' => ['status' => 'READY'],
        'catalogs' => [['status' => 'OK']],
        'products' => ['incomplete' => 0],
        'clients' => ['incomplete' => 0],
        'payment_methods' => $coverage,
        'stamping' => ['status' => 'BLOCKED'],
    ],
];
$actions = new FiscalUnifiedReadinessActionService();
$groups = $actions->saleBlockerGroups(
    ['errors' => [], 'issuer' => ['issuer_profile_id' => 1]],
    $onboarding,
    1
);
$ok(!isset($groups['payment_methods']), 'frontend no convierte cobertura global en bloqueo de factura');
$checklist = $actions->onboardingChecklist($onboarding);
$paymentRow = array_values(array_filter(
    $checklist,
    static fn (array $row): bool => $row['key'] === 'payment_methods'
))[0] ?? [];
$ok(
    str_contains((string)($paymentRow['detail'] ?? ''), 'métodos administrativos mapeados')
        && str_contains((string)($paymentRow['detail'] ?? ''), ' / '),
    'reporte diferencia el contador de métodos configurados del de mappings válidos'
);

echo "TOTAL PASS={$pass} FAIL={$fail}; SQLite memory only, no network.\n";
exit($fail === 0 ? 0 : 1);
