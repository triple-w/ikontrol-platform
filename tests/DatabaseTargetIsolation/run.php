<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Config\Database;
use Config\Services;

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (! $condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    $passed++;
    echo '[PASS] ' . $message . PHP_EOL;
};

$admin = Database::connect('default', false);
$operational = (string) $admin->query('SELECT DATABASE() AS name')->getRow()->name;
if ($operational !== 'ikontrol_new') {
    throw new RuntimeException('Isolation test requires the verified local admin connection.');
}

$suffix = bin2hex(random_bytes(4));
$defaultName = 'ikontrol_isolation_default_' . $suffix;
$cleanName = 'ikontrol_isolation_clean_' . $suffix;
foreach ([$defaultName, $cleanName] as $name) {
    if (! preg_match('/^ikontrol_isolation_(default|clean)_[a-f0-9]{8}$/', $name)) {
        throw new RuntimeException('Unsafe temporary database name.');
    }
}

$quote = static fn (string $name): string => '`' . $name . '`';
$schemaDigest = static function ($db): string {
    $database = (string) $db->query('SELECT DATABASE() AS name')->getRow()->name;
    $tables = $db->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
        [$database]
    )->getResultArray();
    $state = [];
    foreach (array_column($tables, 'TABLE_NAME') as $table) {
        $state[$table] = $db->query('SELECT * FROM ' . $db->protectIdentifiers($table))->getResultArray();
    }
    return hash('sha256', json_encode($state, JSON_UNESCAPED_SLASHES));
};

try {
    $admin->query('CREATE DATABASE ' . $quote($defaultName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    $admin->query('CREATE DATABASE ' . $quote($cleanName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');

    $config = config('Database');
    $defaultConfig = $config->default;
    $defaultConfig['database'] = $defaultName;
    $defaultConfig['DBPrefix'] = 'ikontrol_';
    $cleanConfig = $config->default;
    $cleanConfig['database'] = $cleanName;
    $cleanConfig['DBPrefix'] = 'ikontrol_';

    $default = Database::connect($defaultConfig, false);
    $clean = Database::connect($cleanConfig, false);
    foreach ([[$default, 'default-marker'], [$clean, 'clean-marker']] as [$connection, $marker]) {
        $connection->query('CREATE TABLE ' . $connection->protectIdentifiers('ikontrol_marker')
            . ' (id INT PRIMARY KEY, marker VARCHAR(64) NOT NULL) ENGINE=InnoDB');
        $connection->table('marker')->insert(['id' => 1, 'marker' => $marker]);
    }

    $defaultBefore = $schemaDigest($default);
    $runner = Services::migrations(config('Migrations'), $clean, false);
    $migration = __DIR__ . '/Migrations/2026-08-04-170000_CreateIsolationProbe.php';
    $assert($runner->force($migration, 'Tests\\DatabaseTargetIsolation\\Migrations', 'clean_build'), 'Fixture migration ran through the clean-bound runner.');

    $reflection = new ReflectionObject($runner);
    $property = $reflection->getProperty('db');
    $property->setAccessible(true);
    $runnerDb = $property->getValue($runner);
    $runnerDatabase = (string) $runnerDb->query('SELECT DATABASE() AS name')->getRow()->name;
    $assert($runnerDatabase === $cleanName, 'Runner physical connection is the temporary clean database.');
    $assert($runnerDb === $clean, 'Runner and supplied clean connection are the same object.');

    $assert($clean->tableExists('migrations'), 'Migration repository exists only on clean.');
    $assert($clean->table('migrations')->where('group', 'clean_build')->countAllResults() === 1, 'Repository recorded exactly one clean_build migration.');
    $assert(! $default->tableExists('migrations'), 'Default migration repository was not created or changed.');
    $assert(! $default->tableExists('isolation_probe'), 'Default schema did not receive the fixture table.');
    $assert($schemaDigest($default) === $defaultBefore, 'Default schema and marker digest did not change.');
    $probe = $clean->table('isolation_probe')->get()->getRowArray();
    $assert(($probe['runner_database'] ?? null) === $cleanName, 'SELECT DATABASE() inside the migration returned the clean database.');
    $assert($default->table('marker')->get()->getRow()->marker === 'default-marker', 'Default marker remained intact.');
    $assert($clean->table('marker')->get()->getRow()->marker === 'clean-marker', 'Clean marker remained distinct.');

    echo "passed={$passed}\n";
} finally {
    foreach ([$defaultName, $cleanName] as $name) {
        if (preg_match('/^ikontrol_isolation_(default|clean)_[a-f0-9]{8}$/', $name)) {
            $admin->query('DROP DATABASE IF EXISTS ' . $quote($name));
        }
    }
}
