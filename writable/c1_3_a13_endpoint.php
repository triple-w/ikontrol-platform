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
$before = $db->table('fiscal_pdf_generation_attempts')->where('document_id', 13)->countAllResults();
session()->set('user_id', 1);
$request = service('request');
$request->setGlobal('post', []);
$controller = new App\Controllers\Fiscal\Stamping();
$controller->initController($request, service('response'), service('logger'));
ob_start();
$controller->generatePdf(13);
$response = json_decode((string) ob_get_clean(), true);

echo json_encode([
    'route' => 'POST /fiscal/documents/13/pdf/generate',
    'success' => (bool) ($response['success'] ?? false),
    'status' => (string) ($response['status'] ?? ''),
    'pdf_attempts_before' => $before,
    'pdf_attempts_after' => $db->table('fiscal_pdf_generation_attempts')->where('document_id', 13)->countAllResults(),
    'preview_url_present' => !empty($response['preview_url']),
    'download_url_present' => !empty($response['download_url']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
