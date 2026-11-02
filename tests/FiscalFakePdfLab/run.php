<?php
declare(strict_types=1);

$environment = [
    'CI_ENVIRONMENT' => 'development',
    'IKONTROL_RC1_LAB' => 'false',
    'fiscal.enabled' => 'true',
    'fiscal.stampingEnabled' => 'true',
    'fiscal.allowRealPac' => 'false',
    'fiscal.runtimeMode' => 'integration',
    'fiscal.environment' => 'development',
    'fiscal.pacAdapter' => 'fake',
    'fiscal.pdf.provider' => 'fake',
];
foreach ($environment as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Domain\Fiscal\Pdf\PacPdfGenerationRequest;
use App\Domain\Fiscal\Pdf\PacPdfGenerationResult;
use App\Domain\Fiscal\Pac\StampRequest;
use App\Services\Fiscal\Pac\FakePacAdapter;
use App\Services\Fiscal\Pdf\FiscalPdfGenerationAdapterFactory;
use App\Services\Fiscal\Pdf\FiscalPacPdfGenerationService;
use App\Services\Fiscal\Pdf\LocalFakeFiscalPdfGenerationAdapter;

$pass = 0;
$fail = 0;
$assert = static function (bool $condition, string $message) use (&$pass, &$fail): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $condition ? $pass++ : $fail++;
};

$fiscal = new class extends Config\Fiscal {
    public function __construct()
    {
    }

    public function allowsLabFakePac(): bool
    {
        return true;
    }
};
$fiscal->enabled = true;
$fiscal->runtimeMode = 'integration';
$fiscal->environment = 'development';
$fiscal->pacAdapter = 'fake';
$fiscal->allowRealPac = false;
$pdfConfig = new Config\FiscalPdfProvider();
$pdfConfig->provider = 'fake';
$pdfConfig->enabled = false;

$adapter = (new FiscalPdfGenerationAdapterFactory($fiscal, $pdfConfig))->create();
$assert($adapter instanceof LocalFakeFiscalPdfGenerationAdapter, 'RC1 fake PDF uses the local adapter while the external PDF provider is disabled.');

$request = new PacPdfGenerationRequest(
    21,
    31,
    41,
    '123E4567-E89B-42D3-A456-426614174000',
    '<cfdi:Comprobante/>',
    'RC1-SIMULATED-LOCAL',
    [
        'serie' => 'RC1LAB',
        'folio' => '7',
        'fecha' => '2026-10-31T12:00:00',
        'emisor_razon_social' => 'Emisor sintético RC1',
        'emisor_rfc' => 'AAA010101AAA',
        'receptor_razon_social' => 'PUBLICO EN GENERAL',
        'receptor_rfc' => 'XAXX010101000',
        'subtotal' => '2000.00',
        'total' => '2320.00',
        'moneda' => 'MXN',
    ]
);
$result = $adapter->generate($request);
$bytes = base64_decode((string) $result->pdfBase64, true);
$assert($result->success && $result->provider === 'fake-local' && $bytes !== false && str_starts_with($bytes, '%PDF-'), 'Local adapter produces a valid in-memory PDF.');
$assert($bytes !== false && str_contains($bytes, 'SIMULACION / SIN VALIDEZ FISCAL') && str_contains($bytes, '2320.00'), 'Generated PDF labels simulation and uses this document’s amount.');

$pdfService = new FiscalPacPdfGenerationService(new stdClass());
$pdfAcceptance = new ReflectionMethod(FiscalPacPdfGenerationService::class, 'acceptsPdfResponse');
$fakePdfResponse = new PacPdfGenerationResult(true, 'fake-local', 'LOCAL', 'PDF simulado local.', base64_encode('%PDF-1.4 fake'), 'application/pdf', 'RC1-SIMULATED-LOCAL', false, false, 'success');
$wrongFakeCode = new PacPdfGenerationResult(true, 'fake-local', '210', 'PDF simulado local.', base64_encode('%PDF-1.4 fake'), 'application/pdf', 'RC1-SIMULATED-LOCAL', false, false, 'success');
$invalidFakePdf = new PacPdfGenerationResult(true, 'fake-local', 'LOCAL', 'PDF simulado local.', base64_encode('not a PDF'), 'application/pdf', 'RC1-SIMULATED-LOCAL', false, false, 'success');
$productionPdfResponse = new PacPdfGenerationResult(true, 'timbradorxpress-tools', '210', 'PDF generado.', base64_encode('%PDF-1.4 production'), 'application/pdf', 'default', true, false, 'success');
$assert($pdfAcceptance->invoke($pdfService, $fakePdfResponse, 'fake', true), 'PDF service accepts the local response contract only inside the RC1 fake guard.');
$assert(!$pdfAcceptance->invoke($pdfService, $wrongFakeCode, 'fake', true)
    && !$pdfAcceptance->invoke($pdfService, $invalidFakePdf, 'fake', true)
    && !$pdfAcceptance->invoke($pdfService, $fakePdfResponse, 'fake', false),
    'PDF service rejects incompatible, invalid, or out-of-lab fake responses.');
$assert($pdfAcceptance->invoke($pdfService, $productionPdfResponse, 'timbradorxpress-tools', false), 'PDF service preserves the production provider 210 contract.');

$fakePac = new FakePacAdapter('success_pdf_missing');
$fakeResponse = $fakePac->stamp(new StampRequest(
    21,
    '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Sello="seal"/>',
    hash('sha256', '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Sello="seal"/>'),
    'fake',
    'development',
    'fake-local-test'
));
$fakeData = json_decode((string) $fakeResponse->data, true);
$assert($fakeResponse->code === '200' && $fakeResponse->httpStatus === 200 && ($fakeResponse->metadata['adapter'] ?? '') === 'fake', 'Fake PAC returns its explicit success confirmation contract.');
$assert(str_contains((string) ($fakeData['XML'] ?? ''), 'NO ES RESPUESTA SAT/PAC REAL; SIN VALIDEZ FISCAL'), 'Fake stamped XML carries an explicit simulation marker.');

$outsideLab = new Config\Fiscal();
$outsideLab->enabled = true;
$outsideLab->runtimeMode = 'integration';
$outsideLab->pacAdapter = 'fake';
$blocked = false;
try {
    (new FiscalPdfGenerationAdapterFactory($outsideLab, $pdfConfig))->create();
} catch (RuntimeException $exception) {
    $blocked = $exception->getMessage() === 'FISCAL_PDF_FAKE_NOT_ALLOWED';
}
$assert($blocked, 'Fake PDF remains blocked outside the explicit RC1 lab guard.');

$preflight = (string) file_get_contents(APPPATH . 'Services/Fiscal/FiscalDraftStampingPreflightService.php');
$assert(!str_contains($preflight, 'WSTools33') && !str_contains($preflight, 'SoapClient'), 'Stamping preflight does not require the independent PDF service or SOAP.');
$stampService = (string) file_get_contents(APPPATH . 'Services/Fiscal/Pac/FiscalStampingService.php');
$draftService = (string) file_get_contents(APPPATH . 'Services/Fiscal/FiscalDraftStampingService.php');
$guard = (string) file_get_contents(APPPATH . 'Services/Fiscal/FiscalPreviewModeGuard.php');
$stampFlow = substr($draftService, strpos($draftService, 'private function stampInternal'), strpos($draftService, 'private function completeStampedLocally') - strpos($draftService, 'private function stampInternal'));
$assert(str_contains($stampService, 'function stampDraftDocument')
    && str_contains($stampService, 'requireReadyForSaleFlow($draftId, true)')
    && str_contains($stampService, 'validateDraftDocumentConsistency')
    && str_contains($draftService, 'stampDraftDocument($documentId, $userId, true)')
    && str_contains($guard, 'if ($requireGlobalReadiness)')
    && strpos($stampFlow, 'return $this->completeStampedLocally') < strpos($stampFlow, 'invalidateIfSnapshotChanged'),
    'Draft stamping validates the operation, preserves global guards, and handles a persisted stamp before snapshot invalidation.');

echo PHP_EOL . "TOTAL PASS=$pass FAIL=$fail. No database or network used." . PHP_EOL;
exit($fail === 0 ? 0 : 1);
