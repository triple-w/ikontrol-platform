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
$id = 21;
$beforeStamp = $db->table('fiscal_document_stamps')->where('fiscal_document_id', $id)->get(1)->getRow();
$before = [
    'uuid' => (string) $beforeStamp->uuid,
    'xml_hash' => (string) $beforeStamp->stamped_xml_sha256,
    'stamp_attempts' => $db->table('fiscal_stamp_attempts')->countAllResults(),
    'pdf_attempts' => $db->table('fiscal_pdf_generation_attempts')->where('document_id', $id)->countAllResults(),
];
$result = (new App\Services\Fiscal\Pdf\FiscalPacPdfGenerationService())->generate($id, 1);
$afterStamp = $db->table('fiscal_document_stamps')->where('fiscal_document_id', $id)->get(1)->getRow();
$artifact = $result->pdfArtifactId
    ? $db->table('fiscal_document_binary_artifacts')->where('id', $result->pdfArtifactId)->get(1)->getRow()
    : null;
$after = [
    'uuid' => (string) $afterStamp->uuid,
    'xml_hash' => (string) $afterStamp->stamped_xml_sha256,
    'stamp_attempts' => $db->table('fiscal_stamp_attempts')->countAllResults(),
    'pdf_attempts' => $db->table('fiscal_pdf_generation_attempts')->where('document_id', $id)->countAllResults(),
];
$query = new App\Services\Fiscal\FiscalInvoiceCenterQueryService($db);
$listed = $query->search(['uuid' => substr($before['uuid'], 0, 8)]);
$detail = $query->detail($id);
echo json_encode([
    'success' => $result->success,
    'status' => $result->status,
    'provider_code' => $result->providerCode,
    'attempt_id' => $result->attemptId,
    'artifact_id' => $result->pdfArtifactId,
    'provider' => $artifact?->provider,
    'size_bytes' => $artifact?->decoded_size_bytes,
    'pdf_sha256' => $artifact?->decoded_sha256,
    'uuid_unchanged' => hash_equals($before['uuid'], $after['uuid']),
    'xml_hash_unchanged' => hash_equals($before['xml_hash'], $after['xml_hash']),
    'stamp_attempts_before' => $before['stamp_attempts'],
    'stamp_attempts_after' => $after['stamp_attempts'],
    'pdf_attempts_before' => $before['pdf_attempts'],
    'pdf_attempts_after' => $after['pdf_attempts'],
    'uuid_filter_matches' => count($listed),
    'detail_loaded' => $detail !== null,
    'detail_artifacts' => count($detail['artifacts'] ?? []),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
