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

$db = db_connect();
$documentId = 21;
$expectedUuid = 'C0B6D517-5B00-46F0-9247-1B3002EC142F';
$expectedXmlHash = '3bea0d3d8f65d21d95bd42739c27fa2c0b72e47f55a882566ec5a6a0255fa876';

$stamp = $db->table('fiscal_document_stamps')->where('fiscal_document_id', $documentId)->get(1)->getRow();
if (!$stamp || !hash_equals($expectedUuid, (string) $stamp->uuid)
    || !hash_equals($expectedXmlHash, (string) $stamp->stamped_xml_sha256)) {
    throw new RuntimeException('Fixture 21 identity check failed; no cleanup performed.');
}
$artifact = $db->table('fiscal_document_binary_artifacts')
    ->where('id', (int) $stamp->pac_pdf_artifact_id)
    ->where('fiscal_document_id', $documentId)
    ->where('artifact_type', 'pac_pdf')
    ->where('provider', 'fake')
    ->get(1)->getRow();
if (!$artifact) {
    throw new RuntimeException('No exact fake artifact linked to fixture 21; no cleanup performed.');
}
$attempt = $db->table('fiscal_pdf_generation_attempts')
    ->where('id', (int) $artifact->pdf_generation_attempt_id)
    ->where('document_id', $documentId)
    ->where('provider', 'fake')
    ->where('status', 'success')
    ->get(1)->getRow();
if (!$attempt) {
    throw new RuntimeException('No exact successful fake attempt linked to fixture 21; no cleanup performed.');
}

$beforeStampAttempts = $db->table('fiscal_stamp_attempts')->countAllResults();
$db->transStart();
$db->table('fiscal_document_stamps')->where('fiscal_document_id', $documentId)
    ->where('pac_pdf_artifact_id', (int) $artifact->id)
    ->update(['pac_pdf_artifact_id' => null, 'pdf_status' => 'pending', 'pdf_template' => null]);
$db->table('fiscal_documents')->where('id', $documentId)
    ->update(['status' => 'stamped_pdf_pending']);
$db->table('fiscal_document_binary_artifacts')->where('id', (int) $artifact->id)
    ->where('fiscal_document_id', $documentId)->delete();
$db->table('fiscal_pdf_generation_attempts')->where('id', (int) $attempt->id)
    ->where('document_id', $documentId)->delete();
$db->transComplete();
if (!$db->transStatus()) {
    throw new RuntimeException('Transactional cleanup failed.');
}

$afterStamp = $db->table('fiscal_document_stamps')->where('fiscal_document_id', $documentId)->get(1)->getRow();
echo json_encode([
    'document_id' => $documentId,
    'removed_pdf_attempt_id' => (int) $attempt->id,
    'removed_pdf_artifact_id' => (int) $artifact->id,
    'uuid_unchanged' => hash_equals($expectedUuid, (string) $afterStamp->uuid),
    'xml_hash_unchanged' => hash_equals($expectedXmlHash, (string) $afterStamp->stamped_xml_sha256),
    'pdf_status' => (string) $afterStamp->pdf_status,
    'pdf_artifact_present' => $afterStamp->pac_pdf_artifact_id !== null,
    'stamp_attempts_before' => $beforeStampAttempts,
    'stamp_attempts_after' => $db->table('fiscal_stamp_attempts')->countAllResults(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
