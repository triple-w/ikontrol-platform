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
$attemptId = 4;
$artifactId = 8;
$xmlId = 46;

$document = $db->table('fiscal_documents')->where([
    'id' => $documentId, 'series' => 'FC2-A', 'folio' => 14, 'deleted' => 0,
])->get(1)->getRow();
$stamp = $db->table('fiscal_document_stamps')->where([
    'fiscal_document_id' => $documentId, 'pac_pdf_artifact_id' => $artifactId,
])->get(1)->getRow();
$xml = $db->table('fiscal_document_artifacts')->where([
    'id' => $xmlId, 'fiscal_document_id' => $documentId,
    'artifact_type' => 'stamped_xml', 'validation_status' => 'valid_imported',
    'superseded_at' => null,
])->get(1)->getRow();
$attempt = $db->table('fiscal_pdf_generation_attempts')->where([
    'id' => $attemptId, 'document_id' => $documentId,
    'provider' => 'fake', 'status' => 'success',
])->get(1)->getRow();
$pdf = $db->table('fiscal_document_binary_artifacts')->where([
    'id' => $artifactId, 'fiscal_document_id' => $documentId,
    'artifact_type' => 'pac_pdf', 'provider' => 'fake',
    'validation_status' => 'valid', 'pdf_generation_attempt_id' => $attemptId,
])->get(1)->getRow();
$realPdfs = $db->table('fiscal_document_binary_artifacts')
    ->where('fiscal_document_id', $documentId)->where('artifact_type', 'pac_pdf')
    ->where('provider !=', 'fake')->countAllResults();
$realAttempts = $db->table('fiscal_pdf_generation_attempts')
    ->where('document_id', $documentId)->where('provider', 'timbradorxpress-tools')
    ->countAllResults();
if (!$document || !$stamp || !$xml || !$attempt || !$pdf || $realPdfs !== 0 || $realAttempts !== 0) {
    throw new RuntimeException('Exact cleanup preconditions failed; no rows were changed.');
}

$uuidBefore = (string) $stamp->uuid;
$xmlHashBefore = (string) $xml->sha256;
$stampAttemptsBefore = $db->table('fiscal_stamp_attempts')->countAllResults();
$documentStampAttemptsBefore = $db->table('fiscal_stamp_attempts')
    ->where('fiscal_document_id', $documentId)->countAllResults();

$db->transStart();
$db->table('fiscal_document_stamps')->where([
    'fiscal_document_id' => $documentId, 'pac_pdf_artifact_id' => $artifactId,
])->update([
    'pac_pdf_artifact_id' => null,
    'pdf_status' => 'pending',
    'pdf_template' => null,
]);
$db->table('fiscal_documents')->where([
    'id' => $documentId, 'status' => 'stamped', 'deleted' => 0,
])->update(['status' => 'stamped_pdf_pending']);
$db->table('fiscal_document_binary_artifacts')->where([
    'id' => $artifactId, 'fiscal_document_id' => $documentId,
    'provider' => 'fake', 'pdf_generation_attempt_id' => $attemptId,
])->delete();
$db->table('fiscal_pdf_generation_attempts')->where([
    'id' => $attemptId, 'document_id' => $documentId,
    'provider' => 'fake', 'status' => 'success',
])->delete();
$db->transComplete();
if (!$db->transStatus()) {
    throw new RuntimeException('Fixture cleanup transaction failed.');
}

$afterDocument = $db->table('fiscal_documents')->where('id', $documentId)->get(1)->getRow();
$afterStamp = $db->table('fiscal_document_stamps')->where('fiscal_document_id', $documentId)->get(1)->getRow();
$afterXml = $db->table('fiscal_document_artifacts')->where('id', $xmlId)->get(1)->getRow();
echo json_encode([
    'document_id' => $documentId,
    'removed_attempt_id' => $attemptId,
    'removed_artifact_id' => $artifactId,
    'uuid_before' => $uuidBefore,
    'uuid_after' => (string) $afterStamp->uuid,
    'uuid_unchanged' => hash_equals($uuidBefore, (string) $afterStamp->uuid),
    'xml_id' => (int) $afterXml->id,
    'xml_hash_before' => $xmlHashBefore,
    'xml_hash_after' => (string) $afterXml->sha256,
    'xml_hash_unchanged' => hash_equals($xmlHashBefore, (string) $afterXml->sha256),
    'pdf_status' => (string) $afterStamp->pdf_status,
    'document_status' => (string) $afterDocument->status,
    'fake_attempt_present' => $db->table('fiscal_pdf_generation_attempts')->where('id', $attemptId)->countAllResults() > 0,
    'fake_artifact_present' => $db->table('fiscal_document_binary_artifacts')->where('id', $artifactId)->countAllResults() > 0,
    'stamp_attempts_before' => $stampAttemptsBefore,
    'stamp_attempts_after' => $db->table('fiscal_stamp_attempts')->countAllResults(),
    'document_stamp_attempts_before' => $documentStampAttemptsBefore,
    'document_stamp_attempts_after' => $db->table('fiscal_stamp_attempts')->where('fiscal_document_id', $documentId)->countAllResults(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
