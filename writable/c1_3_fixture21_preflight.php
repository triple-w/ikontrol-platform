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
$document = $db->table('fiscal_documents')
    ->select('id,series,folio,status,issuer_profile_id,source_snapshot_hash,deleted')
    ->where('id', 21)->get(1)->getRow();
$stamp = $db->table('fiscal_document_stamps')
    ->select('fiscal_document_id,uuid,stamped_xml_sha256,pdf_status,pac_pdf_artifact_id')
    ->where('fiscal_document_id', 21)->get(1)->getRow();
$xml = $db->table('fiscal_document_artifacts')
    ->select('id,fiscal_document_id,artifact_type,sha256,validation_status,superseded_at')
    ->where('id', 46)->get(1)->getRow();
$attempt = $db->table('fiscal_pdf_generation_attempts')
    ->select('id,document_id,provider,status,requires_reconciliation')
    ->where('id', 4)->get(1)->getRow();
$pdf = $db->table('fiscal_document_binary_artifacts')
    ->select('id,fiscal_document_id,artifact_type,provider,validation_status,artifact_status,pdf_generation_attempt_id,decoded_size_bytes')
    ->where('id', 8)->get(1)->getRow();
$otherRealPdfs = $db->table('fiscal_document_binary_artifacts')
    ->select('id')->where('fiscal_document_id', 21)->where('artifact_type', 'pac_pdf')
    ->where('provider !=', 'fake')->get()->getResultArray();
$realAttempts = $db->table('fiscal_pdf_generation_attempts')
    ->select('id')->where('document_id', 21)->where('provider', 'timbradorxpress-tools')
    ->get()->getResultArray();
$metadata = $db->table('fiscal_document_metadata')
    ->select('id')->where('fiscal_document_id', 21)
    ->like('metadata_json', '"source":"imported_test_fixture"')->get(1)->getRow();

$checks = [
    'document_identity' => $document && (int) $document->id === 21
        && (string) $document->series === 'FC2-A' && (string) $document->folio === '14'
        && (int) $document->deleted === 0,
    'uuid_present' => $stamp && trim((string) $stamp->uuid) !== '',
    'xml_46_matches' => $xml && (int) $xml->fiscal_document_id === 21
        && $xml->artifact_type === 'stamped_xml' && $xml->validation_status === 'valid_imported'
        && $xml->superseded_at === null,
    'attempt_4_matches' => $attempt && (int) $attempt->document_id === 21
        && $attempt->provider === 'fake' && $attempt->status === 'success',
    'pdf_8_matches' => $pdf && (int) $pdf->fiscal_document_id === 21
        && $pdf->artifact_type === 'pac_pdf' && $pdf->provider === 'fake'
        && $pdf->validation_status === 'valid' && (int) $pdf->decoded_size_bytes === 60729
        && (int) $pdf->pdf_generation_attempt_id === 4,
    'stamp_points_to_pdf_8' => $stamp && (int) $stamp->pac_pdf_artifact_id === 8,
    'no_real_pdf' => count($otherRealPdfs) === 0,
    'no_real_attempt' => count($realAttempts) === 0,
    'imported_metadata_present' => $metadata !== null,
];

echo json_encode([
    'all_checks_pass' => !in_array(false, $checks, true),
    'checks' => $checks,
    'uuid' => (string) ($stamp->uuid ?? ''),
    'xml_hash' => (string) ($xml->sha256 ?? ''),
    'pdf_status' => (string) ($stamp->pdf_status ?? ''),
    'document_status' => (string) ($document->status ?? ''),
    'stamp_attempts_total' => $db->table('fiscal_stamp_attempts')->countAllResults(),
    'stamp_attempts_document_21' => $db->table('fiscal_stamp_attempts')->where('fiscal_document_id', 21)->countAllResults(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
