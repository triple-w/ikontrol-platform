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
$query = new App\Services\Fiscal\FiscalInvoiceCenterQueryService($db);
$rows = $query->search([]);
$ids = array_map(static fn ($row): int => (int) $row->id, $rows);
$selected = [];
foreach ($rows as $row) {
    if (!in_array((int) $row->id, [9, 10, 12, 13, 14, 21], true)) {
        continue;
    }
    $selected[] = [
        'id' => (int) $row->id,
        'issuer_id' => (int) $row->issuer_profile_id,
        'sale_id' => (int) $row->invoice_id,
        'document_type' => (string) $row->document_type,
        'status' => (string) $row->status,
        'visible_status' => (string) $row->visible_status,
        'pdf_status' => (string) $row->pdf_status,
        'uuid_present' => trim((string) $row->uuid) !== '',
        'stamped_xml_present' => (bool) $row->xml_available,
        'active_pdf_present' => (bool) $row->pdf_available,
        'unknown_pdf_attempt' => (bool) $row->pdf_attempt_unknown,
        'imported_fixture' => (int) $row->is_imported_fixture === 1,
    ];
}
$stamp = $db->table('fiscal_document_stamps')->where('fiscal_document_id', 21)->get(1)->getRow();
$document21 = $db->table('fiscal_documents')
    ->select('id,invoice_id,issuer_profile_id,document_type,status,created_by,created_at,deleted')
    ->where('id', 21)->get(1)->getRowArray();
$pdfAttempts = $db->table('fiscal_pdf_generation_attempts')
    ->select('id, document_id, provider, status, created_at')
    ->where('document_id', 21)->orderBy('id', 'ASC')->get()->getResultArray();
$artifacts = $db->table('fiscal_document_binary_artifacts')
    ->select('id, fiscal_document_id, artifact_type, provider, validation_status, pdf_generation_attempt_id, decoded_size_bytes')
    ->where('fiscal_document_id', 21)->orderBy('id', 'ASC')->get()->getResultArray();

echo json_encode([
    'table' => $db->prefixTable('fiscal_documents'),
    'total' => count($rows),
    'ids' => $ids,
    'selected' => $selected,
    'type_i_count' => count($query->search(['type' => 'I'])),
    'type_p_count' => count($query->search(['type' => 'P'])),
    'document_21' => [
        'document' => $document21,
        'uuid' => (string) ($stamp->uuid ?? ''),
        'xml_hash' => (string) ($stamp->stamped_xml_sha256 ?? ''),
        'pdf_attempts' => $pdfAttempts,
        'artifacts' => $artifacts,
    ],
    'translations' => [
        'billing' => app_lang('fiscal_billing'),
        'invoices' => app_lang('fiscal_invoices'),
        'templates' => app_lang('fiscal_pdf_templates'),
    ],
    'active_admin_user_ids' => array_map(
        static fn ($row): int => (int) $row->id,
        $db->table('users')->select('id')->where(['is_admin' => 1, 'deleted' => 0, 'status' => 'active'])->get()->getResult()
    ),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
