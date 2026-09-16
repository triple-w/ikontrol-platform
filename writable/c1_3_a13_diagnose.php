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
$rows = (new App\Services\Fiscal\FiscalInvoiceCenterQueryService($db))
    ->search(['series' => 'A', 'folio' => '13']);
$row = $rows[0] ?? null;
if (!$row) {
    throw new RuntimeException('A-13 not found.');
}
$artifact = $db->table('fiscal_document_artifacts')
    ->select('id,artifact_type,sha256,validation_status,superseded_at')
    ->where([
        'fiscal_document_id' => (int) $row->id,
        'artifact_type' => 'stamped_xml',
        'superseded_at' => null,
    ])->get(1)->getRow();
$attempt = $db->table('fiscal_pdf_generation_attempts')
    ->select('id,status,requires_reconciliation')
    ->where('document_id', (int) $row->id)->orderBy('id', 'DESC')->get(1)->getRow();
$permissionUsers = [];
foreach ($db->table('users u')->select('u.id,u.is_admin,r.permissions')
    ->join('roles r', 'r.id=u.role_id AND r.deleted=0', 'left')
    ->where(['u.deleted' => 0, 'u.status' => 'active'])->get()->getResult() as $user) {
    $permissions = @unserialize((string) $user->permissions);
    $permissionUsers[] = [
        'id' => (int) $user->id,
        'is_admin' => (int) $user->is_admin === 1,
        'fiscal_pdf_generate' => (int) $user->is_admin === 1
            || (is_array($permissions) && !empty($permissions['fiscal_pdf_generate'])),
    ];
}

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
    if (strip_tags((string) ($candidate[0] ?? '')) === 'A'
        && strip_tags((string) ($candidate[1] ?? '')) === '13') {
        $rendered = implode(' ', $candidate);
        break;
    }
}

echo json_encode([
    'document_id' => (int) $row->id,
    'uuid_present' => trim((string) $row->uuid) !== '',
    'xml_present' => $artifact !== null,
    'xml_hash' => (string) ($artifact->sha256 ?? ''),
    'pdf_active' => (bool) $row->pdf_available,
    'pdf_status' => (string) $row->pdf_status,
    'pdf_unknown' => (bool) $row->pdf_attempt_unknown,
    'latest_pdf_attempt' => $attempt ? [
        'id' => (int) $attempt->id,
        'status' => (string) $attempt->status,
        'requires_reconciliation' => (int) $attempt->requires_reconciliation,
    ] : null,
    'test_user_id' => 1,
    'test_user_is_admin' => true,
    'active_user_permissions' => $permissionUsers,
    'action_visible' => str_contains($rendered, 'Generar PDF del PAC'),
    'preview_visible' => str_contains($rendered, 'Ver PDF'),
    'download_visible' => str_contains($rendered, 'Descargar PDF'),
    'rendered_row_found' => $rendered !== '',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
