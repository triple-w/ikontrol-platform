<?php

declare(strict_types=1);

// Set safety switches before the test bootstrap loads any instance configuration.
foreach (['fiscal.enabled' => 'false', 'fiscal.stampingEnabled' => 'false',
    'fiscal.allowRealPac' => 'false', 'fiscal.previewMode' => 'true',
    'fiscal.runtimeMode' => 'automated_test', 'fiscal.pacAdapter' => 'fake'] as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require dirname(__DIR__) . '/bootstrap.php';
require_once APPPATH . 'Database/Migrations/2026-09-25-120000_AddProposalTemplateSelection.php';

use App\Database\Migrations\AddProposalTemplateSelection;
use Config\Database;

$passed = 0;
$assert = static function (bool $condition, string $description) use (&$passed): void {
    if (! $condition) {
        throw new RuntimeException($description);
    }
    $passed++;
    fwrite(STDOUT, '[PASS] ' . $description . PHP_EOL);
};

// Explicit nonshared memory connection: no default/tests group or instance DSN.
$mysql = in_array('--mysql', $argv, true);
$ownedDatabase = null;
$server = null;
$mysqlConfig = null;
$exitCode = 0;
$connection = static function (string $prefix) use (&$mysqlConfig, &$ownedDatabase) {
    $configuration = $mysqlConfig ?? [
        'DSN' => '', 'hostname' => '', 'username' => '', 'password' => '',
        'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => $prefix,
        'pConnect' => false, 'DBDebug' => true, 'foreignKeys' => true,
    ];
    $configuration['DBPrefix'] = $prefix;
    $db = Database::connect($configuration, false);
    if ($ownedDatabase !== null) {
        $actual = $db->query('SELECT DATABASE() AS name')->getRow()->name;
        if ($actual !== $ownedDatabase || ! preg_match('/^ikontrol_test_schema_[a-f0-9]{12}$/D', $actual)) {
            throw new RuntimeException('Refusing a database outside the schema-test fixture.');
        }
        // Only these synthetic tables exist in the database created by this run.
        $db->query('DROP TABLE IF EXISTS proposals, probe_proposals');
        $db->resetDataCache();
    } elseif ($db->DBDriver !== 'SQLite3' || $db->getDatabase() !== ':memory:') {
        throw new RuntimeException('Schema tests require an isolated test database.');
    }
    return $db;
};

try {
    if ($mysql) {
        $configuration = config(Database::class)->default;
        if (! in_array($configuration['hostname'], ['localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException('MySQL schema fixtures require a local server.');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        // Connect without selecting the source database. Never clone business data.
        $server = new mysqli($configuration['hostname'], $configuration['username'], $configuration['password'], '', (int) $configuration['port']);
        $name = 'ikontrol_test_schema_' . bin2hex(random_bytes(6));
        // No IF NOT EXISTS: ownership is established only by successful creation.
        $server->query('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        $ownedDatabase = $name;
        $mysqlConfig = array_replace($configuration, [
            'DSN' => '', 'database' => $name, 'DBDriver' => 'MySQLi',
            'pConnect' => false, 'DBDebug' => true, 'failover' => [],
        ]);
    }
    foreach (['', 'probe_'] as $prefix) {
        $db = $connection($prefix);
        try {
            $forge = Database::forge($db);
            $forge->addField([
                'id' => ['type' => 'INT', 'auto_increment' => true],
                'content' => ['type' => 'TEXT'],
                'status' => ['type' => 'VARCHAR', 'constraint' => 20],
                'deleted' => ['type' => 'INT', 'default' => 0],
            ]);
            $forge->addKey('id', true);
            $forge->createTable('proposals');
            $rows = [
                ['id' => 1, 'content' => '<p>{PROPOSAL_ITEMS}</p>', 'status' => 'draft', 'deleted' => 0],
                ['id' => 2, 'content' => '<p>Historical custom content</p>', 'status' => 'accepted', 'deleted' => 0],
                ['id' => 3, 'content' => '<p>Archived content</p>', 'status' => 'declined', 'deleted' => 1],
            ];
            $db->table('proposals')->insertBatch($rows);
            $before = $db->table('proposals')->orderBy('id')->get()->getResultArray();
            $migration = new AddProposalTemplateSelection($forge);
            $migration->up();
            $fields = array_column($db->getFieldData('proposals'), null, 'name');
            $assert($fields['proposal_template_id']->nullable && in_array($fields['proposal_template_id']->default, [null, 'NULL'], true), $prefix . 'nullable column with NULL default');
            $assert($db->table('proposals')->where('proposal_template_id IS NULL', null, false)->countAllResults() === 3, $prefix . 'no inferred identities for historical proposals');
            $after = $db->table('proposals')->select('id,content,status,deleted')->orderBy('id')->get()->getResultArray();
            $assert($before === $after, $prefix . 'historical content, status and deleted rows preserved');

            // A former template may no longer exist; this migration must not need it.
            $db->table('proposals')->where('id', 2)->update(['proposal_template_id' => 987]);
            $migration->up();
            $assert((int) $db->table('proposals')->where('id', 2)->get()->getRow()->proposal_template_id === 987, $prefix . 'repeat preserves existing selection without template lookup');
            $migration->down();
            $assert($db->fieldExists('proposal_template_id', 'proposals')
                && (int) $db->table('proposals')->where('id', 2)->get()->getRow()->proposal_template_id === 987, $prefix . 'code rollback does not discard selection');
            $assert($db->table('proposals')->countAllResults() === 3, $prefix . 'no proposals created or removed');
        } finally {
            $db->close();
        }
    }

    $db = $connection('');
    try {
        $blocked = false;
        try {
            (new AddProposalTemplateSelection(Database::forge($db)))->up();
        } catch (RuntimeException $error) {
            $blocked = str_contains($error->getMessage(), 'requires the proposals');
        }
        $assert($blocked && ! $db->tableExists('proposals'), 'missing baseline fails without creating a synthetic table');
    } finally {
        $db->close();
    }

    foreach (['TEXT NULL', 'INT NOT NULL DEFAULT 0', 'INT NULL DEFAULT 7'] as $definition) {
        $db = $connection('');
        try {
            $db->query('CREATE TABLE proposals (id INTEGER PRIMARY KEY, content TEXT, proposal_template_id ' . $definition . ')');
            $db->query("INSERT INTO proposals VALUES (1, 'unchanged', 42)");
            $before = $db->table('proposals')->get()->getResultArray();
            $blocked = false;
            try {
                (new AddProposalTemplateSelection(Database::forge($db)))->up();
            } catch (RuntimeException $error) {
                $blocked = str_contains($error->getMessage(), 'must be nullable INT');
            }
            $assert($blocked && $before === $db->table('proposals')->get()->getResultArray(), 'incompatible ' . $definition . ' is rejected without rewriting data');
        } finally {
            $db->close();
        }
    }

    fwrite(STDOUT, "\n{$passed} passed, 0 failed. " . ($mysql ? 'Isolated MySQL fixture' : 'SQLite memory') . "; no source data or PAC access.\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if ($server !== null && $ownedDatabase !== null) {
        // This name can only be assigned after this process created it above.
        if (! preg_match('/^ikontrol_test_schema_[a-f0-9]{12}$/D', $ownedDatabase)) {
            throw new RuntimeException('Refusing cleanup outside the owned fixture database.');
        }
        $server->query('DROP DATABASE `' . $ownedDatabase . '`');
        $server->close();
        fwrite(STDOUT, "Owned MySQL fixture removed.\n");
    }
}
exit($exitCode);
