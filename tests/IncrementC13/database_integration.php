<?php
declare(strict_types=1);

define('ROOTPATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('FCPATH', ROOTPATH);
require ROOTPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
define('APPPATH', realpath($paths->appDirectory) . DIRECTORY_SEPARATOR);
define('SYSTEMPATH', realpath($paths->systemDirectory) . DIRECTORY_SEPARATOR);
define('WRITEPATH', realpath($paths->writableDirectory) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'development');
require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootTest($paths);
helper(['general', 'date_time', 'plugin', 'currency']);
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';

$db = db_connect();
$service = new App\Services\Fiscal\FiscalInvoiceCenterQueryService($db);
$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $message) use (&$pass, &$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$rows = $service->search([]);
$ids = array_map(static fn ($row): int => (int) $row->id, $rows);
$fixture = array_values(array_filter($rows, static fn ($row): bool => (int) $row->id === 21))[0] ?? null;

$assert(count($rows) === $db->table('fiscal_documents')->where('deleted', 0)->countAllResults(),
    'Empty filters preserve the full fiscal-document count.');
$assert(in_array(21, $ids, true), 'Imported fixture 21 is returned.');
$assert($fixture !== null && (int) $fixture->invoice_id === 0,
    'A fiscal document without a sale is visible.');
$assert($fixture !== null && (int) $fixture->is_imported_fixture === 1,
    'Imported fixture metadata is projected without loading metadata content.');
$assert(count($service->search(['type' => ''])) === count($rows),
    'An empty CFDI type does not filter rows.');
$assert(count($service->search(['status' => ''])) === count($rows),
    'An empty status does not filter rows.');
$assert(count($service->search([], 5, 0)) === 5,
    'Pagination limits the page without changing source eligibility.');
$source = file_get_contents(APPPATH . 'Services/Fiscal/FiscalInvoiceCenterQueryService.php');
$assert(!preg_match('/select\\([^)]*(content_base64|stamped_xml)/i', $source),
    'Listing projection excludes XML and Base64.');

session()->set('user_id', 1);
$request = service('request');
$request->setGlobal('post', []);
$controller = new App\Controllers\Fiscal\InvoiceModule();
$controller->initController($request, service('response'), service('logger'));
ob_start();
$controller->listData();
$response = json_decode((string) ob_get_clean(), true);
$assert(is_array($response['data'] ?? null) && count($response['data']) === count($rows),
    'Authenticated controller sends every eligible row to the table response.');
$assert(count(array_filter($response['data'] ?? [], static function(array $row): bool {
    $html=implode(' ', $row);
    return str_contains($html, 'Generar PDF') || str_contains($html, 'Regenerar PDF');
})) === count($response['data'] ?? []), 'Every listed fiscal document exposes one PDF generation action.');
$fixtureRows = array_values(array_filter(
    $response['data'] ?? [],
    static fn (array $row): bool => str_contains(implode(' ', $row), 'Prueba importada')
));
$assert(count($fixtureRows) === 1, 'Rendered table response contains fixture 21.');
$assert(str_contains(implode(' ', $fixtureRows[0] ?? []), 'Generar PDF'),
    'Fixture 21 exposes the configured-provider PDF action without executing it.');

$withoutXml = array_values(array_filter($rows, static fn ($row): bool => !$row->xml_available))[0] ?? null;
$stampAttemptsBefore = $db->table('fiscal_stamp_attempts')->countAllResults();
$pdfAttempts21Before = $db->table('fiscal_pdf_generation_attempts')->where('document_id', 21)->countAllResults();
$pdfController = new App\Controllers\Fiscal\Stamping();
$pdfController->initController($request, service('response'), service('logger'));
ob_start();
$pdfController->generatePdf((int) $withoutXml->id);
$missingXmlResponse = json_decode((string) ob_get_clean(), true);
$assert(($missingXmlResponse['message'] ?? '') === 'No se puede generar el PDF porque el documento todavía no tiene un XML timbrado válido.',
    'A row without stamped XML returns the explicit visible error.');
ob_start();
$pdfController->generatePdf(21);
$blockedProviderResponse = json_decode((string) ob_get_clean(), true);
$assert(($blockedProviderResponse['message'] ?? '') === 'Servicio PDF deshabilitado.',
    'A stamped document reaches configured adapter selection without fake fallback.');
$assert($db->table('fiscal_pdf_generation_attempts')->where('document_id', 21)->countAllResults() === $pdfAttempts21Before,
    'Blocked local provider creates no PDF attempt for fixture 21.');
$assert($db->table('fiscal_stamp_attempts')->countAllResults() === $stampAttemptsBefore,
    'PDF actions create no stamping attempt.');

echo PHP_EOL . "$pass passed, $fail failed." . PHP_EOL;
exit($fail ? 1 : 0);
