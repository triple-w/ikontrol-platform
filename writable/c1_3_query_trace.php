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
$service = new App\Services\Fiscal\FiscalInvoiceCenterQueryService($db);
$reflection = new ReflectionClass($service);
$baseQuery = $reflection->getMethod('baseQuery');
$baseQuery->setAccessible(true);
$applyFilters = $reflection->getMethod('applyFilters');
$applyFilters->setAccessible(true);

$empty = [
    'search'=>'','series'=>'','folio'=>'','uuid'=>'','client'=>'','rfc'=>'',
    'date_from'=>'','date_to'=>'','type'=>'','status'=>'','pdf_status'=>'',
    'cancellation_status'=>'',
];
$bad = [];
foreach ($empty as $name => $_) {
    $bad[$name] = '#fi-' . str_replace('_', '-', $name);
}

$emptyBuilder = $baseQuery->invoke($service);
$applyFilters->invoke($service, $emptyBuilder, $empty);
$emptySql = $emptyBuilder->getCompiledSelect(false);
$badBuilder = $baseQuery->invoke($service);
$applyFilters->invoke($service, $badBuilder, $bad);
$badSql = $badBuilder->getCompiledSelect(false);

$tables = array_values(array_filter(
    $db->listTables(),
    static fn (string $table): bool => (bool) preg_match('/fiscal|document|invoice|artifact|stamp/i', $table)
));

echo json_encode([
    'environment' => ENVIRONMENT,
    'database' => [
        'group' => config('Database')->defaultGroup,
        'hostname' => $db->hostname,
        'database' => $db->database,
        'prefix' => $db->DBPrefix,
        'resolved_table' => $db->prefixTable('fiscal_documents'),
    ],
    'matching_tables' => $tables,
    'stages' => [
        'A_base_table' => $db->table('fiscal_documents')->where('deleted', 0)->countAllResults(),
        'B_security_scope' => $db->table('fiscal_documents')->where('deleted', 0)->countAllResults(),
        'C_left_joins' => count($service->search([])),
        'D_status_filter' => count($service->search(['status' => ''])),
        'E_previous_form_values' => count($service->search($bad)),
        'F_correct_empty_values' => count($service->search($empty)),
        'G_final_projection' => count($service->search($empty)),
    ],
    'previous_bindings' => $bad,
    'previous_sql' => $badSql,
    'corrected_bindings' => $empty,
    'corrected_sql' => $emptySql,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
