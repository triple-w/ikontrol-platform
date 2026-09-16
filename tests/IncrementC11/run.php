<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
helper(['general', 'date_time', 'plugin', 'currency']);
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';

$db = require dirname(__DIR__) . '/Increment02/isolated_database.php';
service('migrations')->setNamespace('App')->latest();
$settings = [];
foreach ($db->table('settings')->get()->getResult() as $setting) {
    $settings[$setting->setting_name] = $setting->setting_value;
}
$settings['timezone'] = $settings['timezone'] ?: 'UTC';
config('Rise')->app_settings_array = $settings;
session();

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $message) use (&$pass, &$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

try {
    foreach (['fiscal_issuer_pdf_templates', 'fiscal_pdf_generation_attempts', 'fiscal_pdf_template_audit'] as $table) {
        $assert($db->tableExists($table), "$table exists.");
    }
    foreach (['pdf_generation_attempt_id', 'template_code', 'artifact_status', 'superseded_at'] as $field) {
        $assert($db->fieldExists($field, 'fiscal_document_binary_artifacts'), "Binary artifacts include $field.");
    }

    $source = $db->table('fiscal_documents d')
        ->select('d.*')
        ->join('fiscal_document_stamps s', 's.fiscal_document_id=d.id')
        ->where('d.deleted', 0)->where('s.uuid !=', '')
        ->orderBy('d.id', 'DESC')->get(1)->getRow();
    if (!$source) {
        throw new RuntimeException('No stamped fixture is available in the isolated database.');
    }
    $sourceStamp = $db->table('fiscal_document_stamps')->where('fiscal_document_id', $source->id)->get(1)->getRow();
    $sourceXml = $db->table('fiscal_document_artifacts')->where('id', $sourceStamp->stamped_xml_artifact_id)->get(1)->getRow();
    $user = (int) $db->table('users')->where(['is_admin' => 1, 'deleted' => 0])->get(1)->getRow()->id;
    $now = get_current_utc_time();
    $counter = 0;

    $fixture = static function () use ($db, $source, $sourceStamp, $sourceXml, $now, &$counter): array {
        $counter++;
        $document = (array) $source;
        unset($document['id'],$document['source_draft_id']);
        $document['status'] = 'stamped_pdf_pending';
        $document['folio'] = (string) (990000 + $counter);
        $document['version'] = 500 + $counter;
        $document['source_snapshot_hash'] = hash('sha256', 'c11-document-' . $counter);
        $document['created_at'] = $now;
        $document['updated_at'] = $now;
        $db->table('fiscal_documents')->insert($document);
        $documentId = (int) $db->insertID();
        foreach (['fiscal_document_issuers', 'fiscal_document_receivers'] as $table) {
            $row = (array) $db->table($table)->where('fiscal_document_id', $source->id)->get(1)->getRow();
            unset($row['id']);
            $row['fiscal_document_id'] = $documentId;
            $db->table($table)->insert($row);
        }
        $xml = (array) $sourceXml;
        unset($xml['id']);
        $xml['fiscal_document_id'] = $documentId;
        $xml['created_at'] = $now;
        $db->table('fiscal_document_artifacts')->insert($xml);
        $xmlId = (int) $db->insertID();
        $uuid = sprintf('A1C10000-0000-4000-8000-%012d', $counter);
        $stamp = (array) $sourceStamp;
        unset($stamp['id']);
        $stamp['fiscal_document_id'] = $documentId;
        $stamp['stamped_xml_artifact_id'] = $xmlId;
        $stamp['uuid'] = $uuid;
        $stamp['pac_pdf_artifact_id'] = null;
        $stamp['pdf_status'] = 'pending';
        $stamp['pdf_template'] = null;
        $stamp['created_at'] = $now;
        $db->table('fiscal_document_stamps')->insert($stamp);
        return [$documentId, $uuid, (int) $stamp['stamp_attempt_id'], (int) $document['issuer_profile_id'], $xmlId];
    };

    $provider = new Config\FiscalPdfProvider();
    $provider->enabled = true;
    $provider->provider = 'fake';
    $provider->allowExternalPdf = false;
    $provider->defaultTemplateIncome = 'DEFAULT-I';
    $provider->defaultTemplateExpense = 'DEFAULT-E';
    $provider->defaultTemplatePayment = 'DEFAULT-P';
    $resolver = new App\Services\Fiscal\Pdf\FiscalPdfTemplateResolver($db, $provider);
    [, , , $issuerId] = $fixture();
    $fallback = $resolver->resolve($issuerId, 'fake', 'I');
    $assert($fallback->templateCode === 'DEFAULT-I' && $fallback->source === 'default', 'Template resolver uses the configured type fallback.');
    $db->table('fiscal_issuer_pdf_templates')->insert([
        'issuer_id' => $issuerId, 'provider' => 'fake', 'document_type' => 'I',
        'template_code' => 'EMISOR_X1', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $exact = $resolver->resolve($issuerId, 'fake', 'I');
    $assert($exact->templateCode === 'EMISOR_X1' && $exact->source === 'issuer', 'Exact active issuer template has priority.');
    $assert($resolver->valid('Texto-1_A.2'), 'Template codes accept safe short strings.');
    $missingProvider = clone $provider;
    $missingProvider->defaultTemplatePayment = '';
    try {
        (new App\Services\Fiscal\Pdf\FiscalPdfTemplateResolver($db, $missingProvider))
            ->resolve($issuerId, 'fake', 'P');
        $missing = false;
    } catch (Throwable) {
        $missing = true;
    }
    $assert($missing, 'Missing issuer/default template returns a typed error.');

    $fiscal = new Config\Fiscal();
    $fiscal->enabled = true;
    $fiscal->allowExternalPdf = true;
    $fiscal->environment = 'sandbox';
    $guarded = clone $provider;
    $guarded->provider = 'timbradorxpress-tools';
    $guarded->allowExternalPdf = true;
    $guarded->wsdl = 'https://tools.example.invalid/service?wsdl';
    $guarded->allowedHosts = ['tools.example.invalid'];
    $guarded->username = '';
    $guarded->password = '';
    $factoryCalls = 0;
    try {
        new App\Services\Fiscal\Pdf\TimbradorXpressToolsPdfAdapter(
            $guarded,
            $fiscal,
            static function () use (&$factoryCalls) { $factoryCalls++; }
        );
        $credentialsBlocked = false;
    } catch (Throwable) {
        $credentialsBlocked = true;
    }
    $assert($credentialsBlocked && $factoryCalls === 0, 'Missing credentials block before SoapClient creation.');
    $guarded->username = 'fixture-user';
    $guarded->password = 'fixture-password';
    $guarded->allowedHosts = ['other.example.invalid'];
    try {
        new App\Services\Fiscal\Pdf\TimbradorXpressToolsPdfAdapter($guarded, $fiscal);
        $hostBlocked = false;
    } catch (Throwable) {
        $hostBlocked = true;
    }
    $assert($hostBlocked, 'A non-allowlisted WSDL host is blocked.');
    $guarded->allowedHosts = ['tools.example.invalid'];
    $guarded->wsdl = 'http://tools.example.invalid/service?wsdl';
    $guarded->allowInsecureHttp = false;
    try {
        new App\Services\Fiscal\Pdf\TimbradorXpressToolsPdfAdapter($guarded, $fiscal);
        $httpBlocked = false;
    } catch (Throwable) {
        $httpBlocked = true;
    }
    $assert($httpBlocked, 'Plain HTTP is blocked by default.');

    $soapCall = null;
    $guarded->wsdl = 'https://tools.example.invalid/service?wsdl';
    $soap = new class($soapCall) {
        public function __construct(private mixed &$capture) {}
        public function generarPDF(...$arguments) {
            $this->capture = ['generarPDF', $arguments];
            return (object) ['code' => 210, 'message' => 'ok', 'pdf' => App\Services\Fiscal\Pac\FakePacPdfFixture::base64()];
        }
    };
    $adapter = new App\Services\Fiscal\Pdf\TimbradorXpressToolsPdfAdapter(
        $guarded,
        $fiscal,
        static fn() => $soap
    );
    $request = new App\Domain\Fiscal\Pdf\PacPdfGenerationRequest(
        1, 1, 1, 'A1C10000-0000-4000-8000-000000000001',
        '<cfdi:Comprobante/>', 'ABC-1', ['serie' => 'T'], null
    );
    $soapResult = $adapter->generate($request);
    $assert($soapResult->success && $soapResult->providerCode === '210', 'SOAP code 210 with PDF is normalized as success.');
    $assert($soapCall[0] === 'generarPDF' && count($soapCall[1]) === 6, 'Adapter invokes generarPDF with exactly six contractual parameters.');
    $assert($soapCall[1][2] === base64_encode($request->stampedXml) && $soapCall[1][3] === 'ABC-1', 'SOAP receives stamped XML Base64 and the resolved template.');
    $numericRequest = new App\Domain\Fiscal\Pdf\PacPdfGenerationRequest(
        1, 1, 1, 'A1C10000-0000-4000-8000-000000000001',
        '<cfdi:Comprobante/>', '1', ['serie' => 'T'], null
    );
    $adapter->generate($numericRequest);
    $assert($soapCall[1][3] === 1, 'The FC2 invoice template is serialized as numeric 1.');
    $assert($soapCall[1][5] === '', 'An absent logo is sent as an empty string, matching FC2.');
    $metadataXml = '<?xml version="1.0"?><cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4"'
        . ' TipoDeComprobante="I" Serie="FC2-A" Folio="14"><cfdi:Receptor Rfc="RFC" Nombre="RECEPTOR"/>'
        . '</cfdi:Comprobante>';
    $metadata = (new App\Services\Fiscal\Pdf\FiscalPdfPrintMetadataBuilder())->build(
        $metadataXml, (object) [], (object) [], (object) ['rfc' => '', 'legal_name' => '']
    );
    $assert(
        array_keys($metadata) === [
            'tipo_comprobante', 'tipo_nombre', 'receptor_rfc', 'receptor_razon_social',
            'comentarios_pdf', 'serie', 'folio',
        ] && $metadata['tipo_nombre'] === 'INGRESO',
        'Print metadata matches the seven-field active FC2 invoice structure.'
    );
    $responseAdapter = static function (object $response) use ($guarded, $fiscal) {
        $client = new class($response) {
            public function __construct(private readonly object $response) {}
            public function generarPDF(...$arguments): object { return $this->response; }
        };
        return new App\Services\Fiscal\Pdf\TimbradorXpressToolsPdfAdapter(
            $guarded, $fiscal, static fn() => $client
        );
    };
    $non210 = $responseAdapter((object) [
        'code' => 500, 'message' => 'rejected',
        'pdf' => App\Services\Fiscal\Pac\FakePacPdfFixture::base64(),
    ])->generate($request);
    $assert(!$non210->success && $non210->status === 'rejected', 'A provider code other than 210 is rejected.');
    $empty210 = $responseAdapter((object) ['code' => 210, 'message' => 'empty', 'pdf' => ''])
        ->generate($request);
    $assert(!$empty210->success, 'Code 210 without PDF is rejected.');
    $invalid210 = $responseAdapter((object) [
        'code' => 210, 'message' => 'invalid', 'pdf' => base64_encode('not-a-pdf'),
    ])->generate($request);
    $assert(!$invalid210->success, 'Code 210 with a structurally invalid PDF is rejected inside the real adapter.');
    $validPdf = App\Services\Fiscal\Pac\FakePacPdfFixture::base64();
    foreach ([
        'codigo/PDF' => (object) ['codigo' => 210, 'mensaje' => 'ok', 'PDF' => $validPdf],
        'CODIGO/MENSAJE/PDF' => (object) ['CODIGO' => 210, 'MENSAJE' => 'ok', 'PDF' => $validPdf],
    ] as $variant => $response) {
        $variantResult = $responseAdapter($response)->generate($request);
        $assert($variantResult->success, "SOAP normalizer accepts confirmed direct $variant response.");
    }
    $successWithoutPdf = $responseAdapter((object) ['code' => 210, 'message' => 'ok'])
        ->generate($request);
    $assert(
        !$successWithoutPdf->success
        && $successWithoutPdf->providerCode === '210'
        && $successWithoutPdf->providerMessage === 'El proveedor indicó éxito pero no entregó el PDF.',
        'Code 210 without PDF has the explicit provider-rejected message.'
    );
    $pdfWithoutCode = $responseAdapter((object) ['pdf' => $validPdf])->generate($request);
    $assert(!$pdfWithoutCode->success, 'A valid PDF without provider code is not accepted as success.');
    $faultClient = new class {
        public function generarPDF(...$arguments): never
        {
            throw new SoapFault('PAC-FAULT', "Proveedor rechazó\nla solicitud");
        }
    };
    $faultResult = (new App\Services\Fiscal\Pdf\TimbradorXpressToolsPdfAdapter(
        $guarded, $fiscal, static fn() => $faultClient
    ))->generate($request);
    $assert(
        !$faultResult->success
        && $faultResult->providerCode === 'PAC-FAULT'
        && !str_contains((string) $faultResult->providerMessage, "\n")
        && $faultResult->requiresReconciliation,
        'SoapFault is sanitized and normalized without a retry.'
    );

    [$documentId, $uuid, $stampAttemptId] = $fixture();
    $fake = new App\Services\Fiscal\Pdf\FakePacPdfGenerationAdapter('success');
    $stampCount = $db->table('fiscal_stamp_attempts')->where('fiscal_document_id', $documentId)->countAllResults();
    $stampBefore = $db->table('fiscal_document_stamps')->where('fiscal_document_id', $documentId)->get(1)->getRow();
    $xmlBefore = $db->table('fiscal_document_artifacts')->where('id', $stampBefore->stamped_xml_artifact_id)->get(1)->getRow();
    $service = new App\Services\Fiscal\Pdf\FiscalPacPdfGenerationService($db, $fake, $provider);
    $generated = $service->generate($documentId, $user);
    $assert($generated['success'] && $fake->calls === 1, 'Fake adapter generates one valid PDF.');
    $assert($db->table('fiscal_pdf_generation_attempts')->where('document_id', $documentId)->countAllResults() === 1, 'A durable PDF attempt is persisted.');
    [$durableId] = $fixture();
    $observingAdapter = new class($db, $durableId) implements App\Contracts\Fiscal\Pdf\PacPdfGenerationAdapterInterface {
        public bool $attemptObserved = false;
        public function __construct(private $db, private readonly int $documentId) {}
        public function generate(App\Domain\Fiscal\Pdf\PacPdfGenerationRequest $request): App\Domain\Fiscal\Pdf\PacPdfGenerationResult
        {
            $attempt = $this->db->table('fiscal_pdf_generation_attempts')
                ->where('document_id', $this->documentId)->get(1)->getRow();
            $this->attemptObserved = $attempt !== null && $attempt->status === 'sending';
            return (new App\Services\Fiscal\Pdf\FakePacPdfGenerationAdapter('success'))->generate($request);
        }
    };
    (new App\Services\Fiscal\Pdf\FiscalPacPdfGenerationService($db, $observingAdapter, $provider))
        ->generate($durableId, $user);
    $assert($observingAdapter->attemptObserved, 'The durable attempt is committed before the adapter is invoked.');
    $pdf = (new App\Services\Fiscal\Pac\PacPdfArtifactService($db))->read($documentId);
    $assert(str_starts_with($pdf['bytes'], '%PDF-'), 'Valid provider PDF is persisted as Base64 and remains renderable.');
    $stampAfter = $db->table('fiscal_document_stamps')->where('fiscal_document_id', $documentId)->get(1)->getRow();
    $xmlAfter = $db->table('fiscal_document_artifacts')->where('id', $stampAfter->stamped_xml_artifact_id)->get(1)->getRow();
    $assert($stampAfter->uuid === $uuid && $xmlAfter->sha256 === $xmlBefore->sha256, 'PDF recovery preserves UUID and stamped XML.');
    $assert($db->table('fiscal_stamp_attempts')->where('fiscal_document_id', $documentId)->countAllResults() === $stampCount, 'PDF recovery creates no stamp attempt.');
    $again = $service->generate($documentId, $user);
    $assert($again['status'] === 'existing' && $fake->calls === 1, 'Double click returns the existing PDF without another provider call.');
    $changed = $service->generate($documentId, $user, 'EMISOR_X2');
    $activeArtifact = $db->table('fiscal_document_binary_artifacts')
        ->where('id', $changed['pdf_artifact_id'])->get(1)->getRow();
    $supersededCount = $db->table('fiscal_document_binary_artifacts')
        ->where(['fiscal_document_id' => $documentId, 'artifact_status' => 'superseded'])
        ->countAllResults();
    $assert($changed['success'] && $fake->calls === 2
        && $activeArtifact->template_code === 'EMISOR_X2'
        && $supersededCount === 1, 'Changing template creates one controlled PDF version and supersedes the prior artifact.');
    $projection = (new App\Services\Fiscal\Pac\FiscalDocumentStatusPresenter($db))->forDocument($documentId);
    $assert($projection->visibleStatus === 'stamped' && $projection->pdfAvailable, 'UUID + XML + active valid PDF projects as stamped.');

    [$invalidId] = $fixture();
    $invalidFake = new App\Services\Fiscal\Pdf\FakePacPdfGenerationAdapter('invalid_pdf');
    $invalidResult = (new App\Services\Fiscal\Pdf\FiscalPacPdfGenerationService($db, $invalidFake, $provider))->generate($invalidId, $user);
    $assert(!$invalidResult['success'] && $invalidResult['status'] === 'stamped_pdf_error', 'Invalid provider PDF is rejected without affecting the stamp.');

    [$unknownId] = $fixture();
    $unknownFake = new App\Services\Fiscal\Pdf\FakePacPdfGenerationAdapter('timeout_unknown');
    $unknown = (new App\Services\Fiscal\Pdf\FiscalPacPdfGenerationService($db, $unknownFake, $provider))->generate($unknownId, $user);
    $unknownAgain = (new App\Services\Fiscal\Pdf\FiscalPacPdfGenerationService($db, $unknownFake, $provider))->generate($unknownId, $user);
    $assert($unknown['requires_reconciliation'] && $unknownFake->calls === 1 && !$unknownAgain['success'], 'Unknown PDF result requires reconciliation and is not resent.');

    [$retryId] = $fixture();
    $notSent = new App\Services\Fiscal\Pdf\FakePacPdfGenerationAdapter('transport_not_sent');
    $retryService = new App\Services\Fiscal\Pdf\FiscalPacPdfGenerationService($db, $notSent, $provider);
    $retryService->generate($retryId, $user);
    $retryService->generate($retryId, $user);
    $assert($notSent->calls === 2
        && $db->table('fiscal_pdf_generation_attempts')->where('document_id', $retryId)->countAllResults() === 2,
        'An explicit retry after transport_not_sent preserves history and creates a new durable attempt.');
    [$exceptionId] = $fixture();
    $beforeTransport = new class implements App\Contracts\Fiscal\Pdf\PacPdfGenerationAdapterInterface {
        public function generate(App\Domain\Fiscal\Pdf\PacPdfGenerationRequest $request): App\Domain\Fiscal\Pdf\PacPdfGenerationResult
        {
            throw new RuntimeException('FISCAL_PDF_SOAP_EXTENSION_MISSING');
        }
    };
    $exceptionResult = (new App\Services\Fiscal\Pdf\FiscalPacPdfGenerationService(
        $db, $beforeTransport, $provider
    ))->generate($exceptionId, $user);
    $closedAttempt = $db->table('fiscal_pdf_generation_attempts')
        ->where('document_id', $exceptionId)->get(1)->getRow();
    $assert(
        $exceptionResult['status'] === 'transport_not_sent'
        && $closedAttempt->status === 'transport_not_sent'
        && (int) $closedAttempt->request_sent === 0
        && (int) $closedAttempt->retryable === 1
        && (int) $closedAttempt->requires_reconciliation === 0
        && $closedAttempt->completed_at !== null,
        'An exception before transport closes the durable attempt as retryable transport_not_sent.'
    );
    $assert(
        $db->table('fiscal_pdf_generation_attempts')
            ->where('document_id', $exceptionId)
            ->where('status', 'sending')->where('completed_at', null)->countAllResults() === 0,
        'The exception test path leaves no orphaned sending attempt.'
    );
    $persistenceScenario = new App\Services\Fiscal\Pdf\FakePacPdfGenerationAdapter('persistence_error');
    $persistenceResult = $persistenceScenario->generate($request);
    $assert(!$persistenceResult->success && $persistenceResult->status === 'persistence_error',
        'Fake adapter exposes the persistence_error scenario without network access.');

    $source = file_get_contents(APPPATH . 'Services/Fiscal/FiscalInvoiceCenterQueryService.php');
    $assert(!str_contains($source, 'content_base64'), 'Invoice center queries never load PDF Base64.');
    $routes = file_get_contents(APPPATH . 'Config/FiscalRoutes.php');
    $assert(str_contains($routes, "fiscal/documents/(:num)/pdf/generate', 'Fiscal\\Stamping::generatePdf/$1', ['filter' => 'csrf']"), 'Document-scoped PDF generation is POST-only and CSRF protected.');
    $controllerSource = file_get_contents(APPPATH . 'Controllers/Fiscal/Stamping.php');
    $centerSource = file_get_contents(APPPATH . 'Controllers/Fiscal/Invoices.php');
    $viewSource = file_get_contents(APPPATH . 'Views/fiscal/invoices/index.php');
    $assert(str_contains($controllerSource, "'preview_url'") && str_contains($controllerSource, "'download_url'"),
        'Successful endpoint responses include protected preview and download URLs.');
    $assert(str_contains($centerSource, 'Generar PDF del PAC')
        && str_contains($viewSource, 'Plantilla:'), 'Invoice center exposes the visible action and confirmation details.');
    $assert($factoryCalls === 0, 'Automated tests made zero external connections.');
} catch (Throwable $error) {
    echo '[FAIL] ' . get_class($error) . ': ' . $error->getMessage()
        . ' at ' . $error->getFile() . ':' . $error->getLine() . PHP_EOL;
    $fail++;
}

echo PHP_EOL . "$pass passed, $fail failed." . PHP_EOL;
exit($fail ? 1 : 0);
