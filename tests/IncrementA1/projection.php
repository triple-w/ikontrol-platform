<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
helper(['general', 'date_time', 'plugin']);
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';

$db = require dirname(__DIR__) . '/Increment02/isolated_database.php';
service('migrations')->setNamespace('App')->latest();
session();

$pass = 0;
$fail = 0;
$assert = static function (bool $condition, string $message) use (&$pass, &$fail): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $condition ? $pass++ : $fail++;
};

$fiscal = (new ReflectionClass(Config\Fiscal::class))->newInstanceWithoutConstructor();
$fiscal->enabled = true;
$fiscal->environment = 'local';
$fiscal->allowRealPac = false;
$fiscal->pacAdapter = 'fake';
$fiscal->pacEncryptionKey = '';
$fiscal->stampingSendingStaleMinutes = 5;

$invoice = $db->table('invoices')->where('deleted', 0)->get(1)->getRow();
$user = $db->table('users')->where('deleted', 0)->get(1)->getRow();
$now = get_current_utc_time();
$makeDocument = static function (string $status, int $folio) use ($db, $invoice, $user, $now): int {
    $db->table('fiscal_documents')->insert([
        'invoice_id' => $invoice->id,
        'issuer_profile_id' => 1,
        'receiver_profile_id' => 1,
        'fiscal_series_id' => 1,
        'document_type' => 'income',
        'status' => $status,
        'version' => 1,
        'series' => 'A1',
        'folio' => $folio,
        'issue_date' => $now,
        'expedition_postal_code' => '06000',
        'currency_code' => 'MXN',
        'payment_form_code' => '01',
        'payment_method_code' => 'PUE',
        'cfdi_use_code' => 'S01',
        'export_code' => '01',
        'subtotal' => '100.00',
        'discount' => '0.00',
        'transferred_tax_total' => '16.00',
        'withheld_tax_total' => '0.00',
        'total' => '116.00',
        'administrative_total_reference' => '116.00',
        'pricing_mode' => 'tax_exclusive',
        'source_snapshot_hash' => hash('sha256', (string) $folio),
        'created_by' => $user->id,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted' => 0,
    ]);
    return (int) $db->insertID();
};

$withoutAttempt = $makeDocument('stamping', 9101);
$view = (new App\Services\Fiscal\Pac\FiscalDocumentStatusPresenter($db, $fiscal))
    ->forDocument($withoutAttempt);
$assert(
    $view->visibleStatus === 'unknown' && $view->requiresReconciliation && !$view->canStamp,
    'A stamping document without a durable attempt is unknown and cannot be resent.'
);

$withStaleAttempt = $makeDocument('stamping', 9102);
$db->table('fiscal_stamp_attempts')->insert([
    'fiscal_document_id' => $withStaleAttempt,
    'signed_xml_artifact_id' => 1,
    'pac_configuration_id' => null,
    'provider' => 'fake',
    'environment' => 'local',
    'operation' => 'timbrar3',
    'request_hash' => str_repeat('a', 64),
    'idempotency_key' => hash('sha256', 'a1-stale'),
    'attempt_number' => 1,
    'status' => 'sending',
    'started_at' => gmdate('Y-m-d H:i:s', time() - 3600),
    'sent_at' => gmdate('Y-m-d H:i:s', time() - 3600),
    'retryable' => 0,
    'requires_reconciliation' => 0,
    'created_by' => $user->id,
    'created_at' => gmdate('Y-m-d H:i:s', time() - 3600),
    'updated_at' => gmdate('Y-m-d H:i:s', time() - 3600),
]);
$view = (new App\Services\Fiscal\Pac\FiscalDocumentStatusPresenter($db, $fiscal))
    ->forDocument($withStaleAttempt);
$assert(
    $view->visibleStatus === 'unknown' && $view->requiresReconciliation && !$view->canStamp,
    'A stale sending attempt is projected as unknown without changing persisted state.'
);
$assert(
    $db->table('fiscal_documents')->where('id', $withStaleAttempt)->get()->getRow()->status === 'stamping'
        && $db->table('fiscal_stamp_attempts')->where('fiscal_document_id', $withStaleAttempt)->get()->getRow()->status === 'sending',
    'Projection is read-only.'
);

$plain = $makeDocument('ready', 9103);
$view = (new App\Services\Fiscal\Pac\FiscalDocumentStatusPresenter($db, $fiscal))->forDocument($plain);
$assert(
    $view->visibleStatus === 'draft' && !$view->pdfAvailable,
    'A document without attempt, stamp or PDF produces a controlled projection.'
);
$html = view('fiscal/pac/result', [
    'status_view' => $view,
    'document' => $view->document,
    'stamp' => $view->stamp,
    'attempt' => $view->attempt,
    'pdf' => $view->pdf,
]);
$assert(
    str_contains($html, 'No disponible') && str_contains($html, 'PDF'),
    'Result view renders an incomplete document without null errors.'
);
$admin = $db->table('users')->where(['deleted' => 0, 'user_type' => 'staff', 'is_admin' => 1])->get(1)->getRow();
if ($admin) {
    session()->set('user_id', (int) $admin->id);
    $request = Config\Services::incomingrequest(null, false);
    $request->setMethod('POST');
    $response = Config\Services::response(null, false);
    $response->setStatusCode(200);
    $controller = new App\Controllers\Fiscal\Stamping();
    $controller->initController($request, $response, service('logger'));
    $result = $controller->result($plain);
    $body = $result instanceof CodeIgniter\HTTP\ResponseInterface ? $result->getBody() : (string) $result;
    $assert(
        $response->getStatusCode() === 200 && str_contains($body, 'No disponible'),
        'Authenticated result endpoint returns 200 without attempt, stamp or PDF.'
    );
} else {
    $assert(false, 'Isolated database provides an administrator for result HTTP characterization.');
}

$assert($db->tableExists('fiscal_document_binary_artifacts'), 'PDF Base64 table exists after isolated migrations.');
foreach (['pac_pdf_artifact_id', 'pdf_status', 'pdf_template'] as $field) {
    $assert($db->fieldExists($field, 'fiscal_document_stamps'), "Stamp column {$field} exists.");
}

echo PHP_EOL . "{$pass} passed, {$fail} failed." . PHP_EOL;
exit($fail ? 1 : 0);
