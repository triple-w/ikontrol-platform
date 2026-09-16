<?php
declare(strict_types=1);

use Config\Database;

$databaseConfig = config('Database');
$source = Database::connect($databaseConfig->default, false);
$sourceDatabase = (string) $source->query('SELECT DATABASE() AS database_name')->getRow()->database_name;
if ($sourceDatabase === '' || str_contains(strtolower($sourceDatabase), 'production')) {
    throw new RuntimeException('Increment02 tests require a verified local development source database.');
}

$testDatabase = preg_replace('/[^a-zA-Z0-9_]/', '_', $sourceDatabase) . '_increment02_' . bin2hex(random_bytes(4));
$testDatabaseQuoted = '`' . $testDatabase . '`';
$sourceDatabaseQuoted = '`' . str_replace('`', '``', $sourceDatabase) . '`';

$source->query("CREATE DATABASE $testDatabaseQuoted CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach ($source->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->getResultArray() as $row) {
        $table = (string) reset($row);
        $tableQuoted = '`' . str_replace('`', '``', $table) . '`';
        $source->query("CREATE TABLE $testDatabaseQuoted.$tableQuoted LIKE $sourceDatabaseQuoted.$tableQuoted");
        $source->query("INSERT INTO $testDatabaseQuoted.$tableQuoted SELECT * FROM $sourceDatabaseQuoted.$tableQuoted");
    }
} catch (Throwable $e) {
    $source->query("DROP DATABASE IF EXISTS $testDatabaseQuoted");
    throw $e;
}

$databaseConfig->default['database'] = $testDatabase;
$databaseConfig->tests = $databaseConfig->default;
$databaseConfig->defaultGroup = 'default';
$isolatedDb = db_connect('default');

register_shutdown_function(static function () use ($source, $testDatabaseQuoted): void {
    $source->query("DROP DATABASE IF EXISTS $testDatabaseQuoted");
});

return $isolatedDb;
