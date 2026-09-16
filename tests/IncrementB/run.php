<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
helper(['general', 'date_time', 'plugin', 'currency']);
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';

$pass = 0;
$fail = 0;
$assert = static function (bool $condition, string $message) use (&$pass, &$fail): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $condition ? $pass++ : $fail++;
};

$db = require dirname(__DIR__) . '/Increment02/isolated_database.php';
require_once APPPATH.'Database/Migrations/2026-08-04-170000_CreateFiscalStampCommercialControl.php';
(new App\Database\Migrations\CreateFiscalStampCommercialControl())->up();
$settings = [];
foreach ($db->table('settings')->get()->getResult() as $setting) {
    $settings[$setting->setting_name] = $setting->setting_value;
}
$settings['timezone'] = $settings['timezone'] ?: 'UTC';
config('Rise')->app_settings_array = $settings;
session();
$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ikontrol_increment_b_' . bin2hex(random_bytes(6));
$preXmlRoot = $temp . DIRECTORY_SEPARATOR . 'prexml';
$artifactRoot = $temp . DIRECTORY_SEPARATOR . 'artifacts';
$contingencyRoot = $temp . DIRECTORY_SEPARATOR . 'contingency';
foreach ([$preXmlRoot, $artifactRoot, $contingencyRoot] as $directory) {
    mkdir($directory, 0700, true);
}
$clean = static function (string $path) use (&$clean): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $target = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($target) ? $clean($target) : @unlink($target);
    }
    @rmdir($path);
};
register_shutdown_function(static fn() => $clean($temp));

$reference = $db->table('fiscal_documents')->where('id', 12)->get(1)->getRow();
if (!$reference) {
    throw new RuntimeException('The isolated fixture requires the previously verified fiscal configuration.');
}
$sourceInvoiceId = (int) $reference->invoice_id;
$userId = (int) $db->table('users')->where(['is_admin' => 1, 'deleted' => 0])->get(1)->getRow()->id;
$sourceInvoice = (array) $db->table('invoices')->where('id', $sourceInvoiceId)->get(1)->getRow();
$sourceItems = $db->table('invoice_items')->where(['invoice_id' => $sourceInvoiceId, 'deleted' => 0])->get()->getResultArray();
$paymentForm = (string) $reference->payment_form_code;
$paymentMethod = (string) $reference->payment_method_code;
$currency = (string) $reference->currency_code;

$newSale = static function () use ($db, $sourceInvoice, $sourceItems): int {
    $invoice = $sourceInvoice;
    unset($invoice['id']);
    $invoice['number_sequence'] = (int) ($invoice['number_sequence'] ?? 0) + random_int(1000, 9999);
    $invoice['deleted'] = 0;
    $db->table('invoices')->insert($invoice);
    $invoiceId = (int) $db->insertID();
    foreach ($sourceItems as $sourceItem) {
        $item = $sourceItem;
        unset($item['id']);
        $item['invoice_id'] = $invoiceId;
        $db->table('invoice_items')->insert($item);
    }
    return $invoiceId;
};

$prepare = static function (int $invoiceId) use ($reference, $userId): array {
    $simulation = (new App\Services\Fiscal\SaleTaxPricingSimulationService())->simulate(
        $invoiceId,
        (int) $reference->issuer_profile_id,
        (int) $reference->receiver_profile_id,
        (int) $reference->fiscal_series_id,
        null,
        $userId,
        true
    );
    if ($simulation['status'] === 'confirmation_required') {
        (new App\Services\Fiscal\SaleTaxAdjustmentService())->confirmAndApply(
            (int) $simulation['id'],
            $userId,
            true
        );
        $simulation = (new App\Services\Fiscal\SaleTaxPricingSimulationService())->simulate(
            $invoiceId,
            (int) $reference->issuer_profile_id,
            (int) $reference->receiver_profile_id,
            (int) $reference->fiscal_series_id,
            null,
            $userId,
            true
        );
    }
    if (!empty($simulation['errors'])) {
        throw new RuntimeException(implode(' ', $simulation['errors']));
    }
    return $simulation;
};

$fiscal = config('Fiscal');
$fiscal->enabled = true;
$fiscal->environment = 'local';
$fiscal->allowRealPac = false;
$fiscal->pacAdapter = 'fake';
$provider = config('TimbradorXpress');
$stampAccounts = new App\Services\Fiscal\Stamps\FiscalStampAccountService($db);
$stampAccounts->allocate((int)$reference->issuer_profile_id,10,'Fixture commercial stamps',null,'increment-b:allocation');

$makeService = static function (App\Services\Fiscal\Pac\FakePacAdapter $adapter) use (
    $db, $fiscal, $provider, $preXmlRoot, $artifactRoot, $contingencyRoot
): App\Services\Fiscal\FiscalInvoiceGenerationService {
    $factory = new App\Services\Fiscal\Pac\FiscalPacAdapterFactory($fiscal, $provider, $adapter);
    $preXml = new App\Services\Fiscal\Cfdi40\CfdiPreXmlArtifactService($db, $preXmlRoot);
    $signing = new App\Services\Fiscal\Cfdi40\CfdiSigningService(
        $db, null, $artifactRoot, $preXmlRoot
    );
    $stamping = new App\Services\Fiscal\Pac\FiscalStampingService(
        $db,
        $factory,
        $provider,
        null,
        $artifactRoot,
        $contingencyRoot
    );
    return new App\Services\Fiscal\FiscalInvoiceGenerationService(
        $db,
        new App\Services\Fiscal\FiscalDraftCreationService($db),
        $preXml,
        $signing,
        $stamping,
        new App\Services\Fiscal\Pac\FiscalDocumentStatusPresenter($db, $fiscal),
        $factory,
        new App\Services\Fiscal\Signing\CsdOperationalStatusService($db),
        new App\Services\Fiscal\FiscalInvoiceGenerationErrorPresenter()
    );
};

$input = static function (int $preparationId) use ($reference, $paymentForm, $paymentMethod, $currency): array {
    return [
        'issuer_profile_id' => (int) $reference->issuer_profile_id,
        'receiver_profile_id' => (int) $reference->receiver_profile_id,
        'series_id' => (int) $reference->fiscal_series_id,
        'preparation_id' => $preparationId,
        'payment_form_code' => $paymentForm,
        'payment_method_code' => $paymentMethod,
        'currency_code' => $currency,
        'exchange_rate' => $reference->exchange_rate,
    ];
};

try {
    $projectsBefore = $db->table('projects')->countAllResults();
    $successSale = $newSale();
    $successPrep = $prepare($successSale);
    $successAdapter = new App\Services\Fiscal\Pac\FakePacAdapter('success');
    $successService = $makeService($successAdapter);
    $success = $successService->generate($successSale, $input((int) $successPrep['id']), $userId, true);
    if (!$success->success) {
        echo '[INFO] success scenario stopped at ' . $success->stage . '/' . $success->status
            . ': ' . $success->message . PHP_EOL;
        $failedArtifact = $db->table('fiscal_document_artifacts')
            ->where(['fiscal_document_id' => $success->documentId, 'artifact_type' => 'pre_xml'])
            ->orderBy('id', 'DESC')->get(1)->getRow();
        $failedValidation = $failedArtifact ? json_decode((string) $failedArtifact->validation_payload, true) : [];
        echo '[INFO] XSD: ' . json_encode($failedValidation['xsd']['errors'] ?? [], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
    $successDocument = $db->table('fiscal_documents')->where('id', $success->documentId)->get(1)->getRow();
    $assert($success->success && $success->status === 'stamped', 'One normal action completes as stamped with FakePacAdapter.');
    $assert($successAdapter->stampCalls === 1, 'Success invokes the fake adapter exactly once.');
    $assert($success->uuid !== null && $success->xmlAvailable, 'Success persists UUID and stamped XML.');
    $assert($success->pdfAvailable, 'Normal fake success includes the mandatory validated PDF.');
    $assert($successDocument && $successDocument->status === 'stamped', 'The generated fiscal document reaches stamped.');
    $assert($db->table('fiscal_document_items')->where('fiscal_document_id', $success->documentId)->countAllResults() === count($sourceItems), 'The snapshot contains every sale item.');
    $assert($db->table('fiscal_document_signatures')->where('fiscal_document_id', $success->documentId)->countAllResults() === 1, 'The normal action signs automatically without a password prompt.');
    $assert($db->table('fiscal_stamp_attempts')->where('fiscal_document_id', $success->documentId)->countAllResults() === 1, 'Exactly one durable attempt is recorded.');
    $again = $successService->generate($successSale, $input((int) $successPrep['id']), $userId, true);
    $assert($again->status === 'stamped' && $successAdapter->stampCalls === 1, 'Repeated generation returns the existing stamp without another adapter call.');

    $rejectSale = $newSale();
    $rejectPrep = $prepare($rejectSale);
    $rejectAdapter = new App\Services\Fiscal\Pac\FakePacAdapter('rejected');
    $rejectService = $makeService($rejectAdapter);
    $rejected = $rejectService->generate($rejectSale, $input((int) $rejectPrep['id']), $userId, true);
    echo '[INFO] rejection scenario: ' . $rejected->stage . '/' . $rejected->status . PHP_EOL;
    $assert(!$rejected->success && $rejected->correctable && $rejected->providerCode !== null, 'Fake rejection returns a visible correctable provider result.');
    $assert($db->table('fiscal_document_stamps')->where('fiscal_document_id', $rejected->documentId)->countAllResults() === 0, 'Rejected XML stores no UUID or stamp.');
    $rejectedAgain = $rejectService->generate($rejectSale, $input((int) $rejectPrep['id']), $userId, true);
    $assert($rejectedAgain->status === 'correctable_error' && $rejectAdapter->stampCalls === 1, 'Rejected XML is not resent until a corrected version is explicitly confirmed.');

    $timeoutSale = $newSale();
    $timeoutPrep = $prepare($timeoutSale);
    $timeoutAdapter = new App\Services\Fiscal\Pac\FakePacAdapter('timeout_unknown');
    $timeoutService = $makeService($timeoutAdapter);
    $unknown = $timeoutService->generate($timeoutSale, $input((int) $timeoutPrep['id']), $userId, true);
    echo '[INFO] timeout scenario: ' . $unknown->stage . '/' . $unknown->status . PHP_EOL;
    $assert($unknown->status === 'unknown' && $unknown->requiresReconciliation && !$unknown->retryable, 'Fake timeout is unknown and requires reconciliation.');
    $unknownAgain = $timeoutService->generate($timeoutSale, $input((int) $timeoutPrep['id']), $userId, true);
    $assert($unknownAgain->status === 'unknown' && $timeoutAdapter->stampCalls === 1, 'Unknown is never resent automatically.');

    $concurrentSale = $newSale();
    $concurrentPrep = $prepare($concurrentSale);
    $concurrentAdapter = new App\Services\Fiscal\Pac\FakePacAdapter('success');
    $otherConnection = Config\Database::connect(config('Database')->default, false);
    $lockName = 'ikontrol:fiscal:invoice:' . $concurrentSale;
    $otherConnection->query('SELECT GET_LOCK(?, 0)', [$lockName]);
    try {
        $processing = $makeService($concurrentAdapter)->generate(
            $concurrentSale,
            $input((int) $concurrentPrep['id']),
            $userId,
            true
        );
    } finally {
        $otherConnection->query('SELECT RELEASE_LOCK(?)', [$lockName]);
    }
    $assert($processing->status === 'processing' && $concurrentAdapter->stampCalls === 0, 'A concurrent request is blocked before creating or stamping a duplicate.');
    $assert($db->table('fiscal_documents')->where('invoice_id', $concurrentSale)->countAllResults() === 0, 'Lock contention leaves no partial fiscal document.');

    $cert = $db->table('fiscal_issuer_certificates')->where([
        'issuer_profile_id' => (int) $reference->issuer_profile_id,
        'status' => 'valid',
        'deleted' => 0,
    ])->orderBy('is_default', 'DESC')->get(1)->getRow();
    $db->table('fiscal_issuer_certificate_secrets')->where([
        'fiscal_issuer_certificate_id' => (int) $cert->id,
        'status' => 'active',
    ])->update(['status' => 'inactive']);
    $blockedSale = $newSale();
    $blockedPrep = $prepare($blockedSale);
    $blockedAdapter = new App\Services\Fiscal\Pac\FakePacAdapter('success');
    $blocked = $makeService($blockedAdapter)->generate($blockedSale, $input((int) $blockedPrep['id']), $userId, true);
    $assert(!$blocked->success && $blocked->stage === 'csd' && $blockedAdapter->stampCalls === 0, 'A non-ready CSD blocks before XML signing and PAC invocation.');
    $assert($db->table('fiscal_documents')->where('invoice_id', $blockedSale)->countAllResults() === 0, 'CSD failure creates no fiscal document or PAC attempt.');

    $assert($db->table('projects')->countAllResults() === $projectsBefore, 'The orchestrator creates zero projects.');
    $assert(!$fiscal->allowRealPac && $fiscal->pacAdapter === 'fake', 'Tests keep real PAC calls disabled and use the fake adapter.');
} catch (Throwable $error) {
    echo '[FAIL] ' . get_class($error) . ': ' . $error->getMessage()
        . ' at ' . $error->getFile() . ':' . $error->getLine() . PHP_EOL;
    $fail++;
}

echo PHP_EOL . "{$pass} passed, {$fail} failed." . PHP_EOL;
exit($fail ? 1 : 0);
