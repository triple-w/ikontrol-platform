<?php
declare(strict_types=1);

define('ROOTPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
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
$row = (new App\Services\Fiscal\FiscalInvoiceCenterQueryService($db))
    ->search(['series' => 'FC2-A', 'folio' => '14'])[0] ?? null;
$xml = $db->table('fiscal_document_artifacts')
    ->select('id,sha256,validation_status')->where([
        'id' => 46, 'fiscal_document_id' => 21, 'artifact_type' => 'stamped_xml',
    ])->get(1)->getRow();
$stamp = $db->table('fiscal_document_stamps')
    ->select('uuid,pdf_status,pac_pdf_artifact_id')->where('fiscal_document_id', 21)->get(1)->getRow();
$document = $db->table('fiscal_documents')
    ->select('id,status,series,folio')->where('id', 21)->get(1)->getRow();

session()->set('user_id', 1);
$request = service('request');
$request->setGlobal('post', []);
$controller = new App\Controllers\Fiscal\InvoiceModule();
$controller->initController($request, service('response'), service('logger'));
ob_start();
$controller->listData();
$payload = json_decode((string) ob_get_clean(), true);
$rendered = '';
foreach ($payload['data'] ?? [] as $candidate) {
    if (str_contains(strip_tags((string) ($candidate[0] ?? '')), 'FC2-A')
        && strip_tags((string) ($candidate[1] ?? '')) === '14') {
        $rendered = implode(' ', $candidate);
        break;
    }
}

echo json_encode([
    'document_exists' => (int) ($document->id ?? 0) === 21,
    'document' => trim((string) ($document->series ?? '') . ' ' . (string) ($document->folio ?? '')),
    'uuid' => (string) ($stamp->uuid ?? ''),
    'xml_id' => (int) ($xml->id ?? 0),
    'xml_hash' => (string) ($xml->sha256 ?? ''),
    'xml_status' => (string) ($xml->validation_status ?? ''),
    'pdf_status' => (string) ($stamp->pdf_status ?? ''),
    'document_status' => (string) ($document->status ?? ''),
    'active_pdf' => (bool) ($row->pdf_available ?? false),
    'fake_attempt_4_present' => $db->table('fiscal_pdf_generation_attempts')->where('id', 4)->countAllResults() > 0,
    'fake_artifact_8_present' => $db->table('fiscal_document_binary_artifacts')->where('id', 8)->countAllResults() > 0,
    'real_pdf_attempts' => $db->table('fiscal_pdf_generation_attempts')->where([
        'document_id' => 21, 'provider' => 'timbradorxpress-tools',
    ])->countAllResults(),
    'action_visible' => str_contains($rendered, 'Generar PDF'),
    'action_label' => str_contains($rendered, 'Generar PDF') ? 'Generar PDF' : '',
    'effective_provider_label' => str_contains($rendered, 'Servicio PDF deshabilitado') ? 'Servicio PDF deshabilitado (fake sólo para pruebas)' : '',
    'template' => str_contains($rendered, 'data-template="factura"') ? 'factura' : '',
    'stamp_attempts_total' => $db->table('fiscal_stamp_attempts')->countAllResults(),
    'stamp_attempts_document_21' => $db->table('fiscal_stamp_attempts')->where('fiscal_document_id', 21)->countAllResults(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
