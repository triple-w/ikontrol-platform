<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$databaseConfig = config('Database');
$db = Config\Database::connect($databaseConfig->default, false);
$documents = [];
foreach ([9, 10, 12, 13, 14] as $id) {
    $row = $db->table('fiscal_documents')->select('id,status')->where('id', $id)->get(1)->getRow();
    $documents[] = ['id' => $id, 'status' => $row?->status];
}
$attempt = $db->table('fiscal_stamp_attempts')
    ->select('id,fiscal_document_id,status')->where('id', 11)->get(1)->getRow();
$temporaryDatabases = 0;
$temporaryDatabaseNames = [];
foreach ($db->query('SHOW DATABASES')->getResultArray() as $row) {
    $databaseName=(string)reset($row);
    if (str_contains($databaseName, '_increment02_')) {
        $temporaryDatabases++;
        $temporaryDatabaseNames[]=$databaseName;
    }
}
$fiscal = config('Fiscal');
echo json_encode([
    'fiscal_enabled' => $fiscal->enabled,
    'environment' => $fiscal->environment,
    'allow_real_pac' => $fiscal->allowRealPac,
    'adapter' => $fiscal->pacAdapter,
    'documents' => $documents,
    'attempt_11' => $attempt,
    'pdf_generation_attempts' => $db->tableExists('fiscal_pdf_generation_attempts')
        ? $db->table('fiscal_pdf_generation_attempts')->countAllResults()
        : null,
    'pdf_templates' => $db->tableExists('fiscal_issuer_pdf_templates')
        ? $db->table('fiscal_issuer_pdf_templates')->countAllResults()
        : null,
    'temporary_databases' => $temporaryDatabases,
    'temporary_database_names' => $temporaryDatabaseNames,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
