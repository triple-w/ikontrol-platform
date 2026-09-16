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
$id = 13;
$document = $db->table('fiscal_documents')->select('id,series,folio,status')
    ->where(['id' => $id, 'series' => 'A', 'folio' => 13, 'deleted' => 0])->get(1)->getRow();
$stamp = $db->table('fiscal_document_stamps')
    ->select('uuid,stamped_xml_sha256,pdf_status,pac_pdf_artifact_id')
    ->where('fiscal_document_id', $id)->get(1)->getRow();
if (!$document || !$stamp || trim((string) $stamp->uuid) === '') {
    throw new RuntimeException('A-13 precondition failed.');
}
$before = [
    'uuid' => (string) $stamp->uuid,
    'xml_hash' => (string) $stamp->stamped_xml_sha256,
    'stamp_attempts' => $db->table('fiscal_stamp_attempts')->countAllResults(),
    'document_stamp_attempts' => $db->table('fiscal_stamp_attempts')->where('fiscal_document_id', $id)->countAllResults(),
    'pdf_attempts' => $db->table('fiscal_pdf_generation_attempts')->where('document_id', $id)->countAllResults(),
];

$result = (new App\Services\Fiscal\Pdf\FiscalPacPdfGenerationService($db))->generate($id, 1);
$afterStamp = $db->table('fiscal_document_stamps')
    ->select('uuid,stamped_xml_sha256,pdf_status,pac_pdf_artifact_id')
    ->where('fiscal_document_id', $id)->get(1)->getRow();
$artifact = $db->table('fiscal_document_binary_artifacts')
    ->select('id,provider,validation_status,decoded_size_bytes,decoded_sha256')
    ->where('id', (int) $afterStamp->pac_pdf_artifact_id)->get(1)->getRow();
$projection = (new App\Services\Fiscal\FiscalInvoiceCenterQueryService($db))
    ->search(['series' => 'A', 'folio' => '13'])[0] ?? null;

echo json_encode([
    'document_id' => $id,
    'success' => $result->success,
    'result_status' => $result->status,
    'provider_code' => $result->providerCode,
    'adapter_provider' => (string) ($artifact->provider ?? ''),
    'pdf_attempt_id' => $result->attemptId,
    'pdf_artifact_id' => $result->pdfArtifactId,
    'pdf_valid' => ($artifact->validation_status ?? '') === 'valid',
    'pdf_size' => (int) ($artifact->decoded_size_bytes ?? 0),
    'pdf_status_after' => (string) $afterStamp->pdf_status,
    'uuid_before' => $before['uuid'],
    'uuid_after' => (string) $afterStamp->uuid,
    'uuid_unchanged' => hash_equals($before['uuid'], (string) $afterStamp->uuid),
    'xml_hash_before' => $before['xml_hash'],
    'xml_hash_after' => (string) $afterStamp->stamped_xml_sha256,
    'xml_hash_unchanged' => hash_equals($before['xml_hash'], (string) $afterStamp->stamped_xml_sha256),
    'stamp_attempts_before' => $before['stamp_attempts'],
    'stamp_attempts_after' => $db->table('fiscal_stamp_attempts')->countAllResults(),
    'document_stamp_attempts_before' => $before['document_stamp_attempts'],
    'document_stamp_attempts_after' => $db->table('fiscal_stamp_attempts')->where('fiscal_document_id', $id)->countAllResults(),
    'pdf_attempts_before' => $before['pdf_attempts'],
    'pdf_attempts_after' => $db->table('fiscal_pdf_generation_attempts')->where('document_id', $id)->countAllResults(),
    'projection_pdf_available' => (bool) ($projection->pdf_available ?? false),
    'projection_pdf_status' => (string) ($projection->pdf_status ?? ''),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
