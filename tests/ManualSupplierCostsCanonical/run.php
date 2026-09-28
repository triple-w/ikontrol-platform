<?php

declare(strict_types=1);

foreach (['fiscal.enabled' => 'false', 'fiscal.stampingEnabled' => 'false', 'fiscal.allowRealPac' => 'false',
    'fiscal.runtimeMode' => 'automated_test', 'fiscal.pacAdapter' => 'fake'] as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require dirname(__DIR__) . '/bootstrap.php';
require_once APPPATH . 'Database/Migrations/2026-09-28-130000_EnableCanonicalManualSupplierCostHistory.php';

use App\Database\Migrations\EnableCanonicalManualSupplierCostHistory;
use App\Services\SupplierCostHistoryService;
use Config\Database;

helper('date_time');
$mysql = in_array('--mysql', $argv, true);
$worker = in_array('--race-worker', $argv, true);
$server = null;
$owned = null;
$db = null;
$passed = 0;
$exit = 0;
$assert = static function (bool $ok, string $label) use (&$passed): void {
    if (! $ok) throw new RuntimeException($label);
    $passed++;
    echo '[PASS] ' . $label . PHP_EOL;
};
$reject = static function (callable $action, string $label) use ($assert): void {
    $caught = false;
    try { $action(); } catch (InvalidArgumentException | RuntimeException $e) { $caught = true; }
    $assert($caught, $label);
};
$input = ['product_id' => 1, 'supplier_id' => 1, 'unit_cost' => '12.340000', 'notes' => 'Cotización independiente', 'idempotency_key' => 'capture-1'];

try {
    $config = ['DSN' => '', 'hostname' => '', 'username' => '', 'password' => '', 'database' => ':memory:',
        'DBDriver' => 'SQLite3', 'DBPrefix' => 'p03_', 'pConnect' => false, 'DBDebug' => true, 'foreignKeys' => true];
    if ($worker) {
        $config = json_decode(base64_decode((string) getenv('P03_FIXTURE_CONFIG')), true, 512, JSON_THROW_ON_ERROR);
        if (! preg_match('/^ikontrol_test_p03_[a-f0-9]{12}$/D', $config['database'])
            || ! in_array($config['hostname'], ['localhost', '127.0.0.1', '::1'], true)) throw new RuntimeException('Unsafe worker target.');
    } elseif ($mysql) {
        $local = config(Database::class)->default;
        if (! in_array($local['hostname'], ['localhost', '127.0.0.1', '::1'], true)) throw new RuntimeException('Local fixture server required.');
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        // No source database selected; only a newly owned synthetic schema is accessed.
        $server = new mysqli($local['hostname'], $local['username'], $local['password'], '', (int) $local['port']);
        $name = 'ikontrol_test_p03_' . bin2hex(random_bytes(6));
        $server->query('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        $owned = $name;
        $config = array_replace($local, ['DSN' => '', 'database' => $owned, 'DBDriver' => 'MySQLi', 'DBPrefix' => 'p03_',
            'pConnect' => false, 'DBDebug' => true, 'failover' => []]);
    }
    $db = Database::connect($config, false);
    if ($worker) {
        $actual = $db->query('SELECT DATABASE() n')->getRow()->n;
        if ($actual !== $config['database']) throw new RuntimeException('Worker identity mismatch.');
        $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->transBegin();
        $db->table('product_supplier_cost_history')->countAllResults(); // Establish snapshot before competing insert.
        $start = (float) getenv('P03_RACE_START');
        while (microtime(true) < $start) usleep(10000);
        $result = (new SupplierCostHistoryService($db))->saveManual($input + ['quoted_at' => '2026-09-07'], 77);
        if (! $db->transStatus()) throw new RuntimeException('Conflict poisoned caller transaction.');
        $db->transCommit();
        echo json_encode($result) . PHP_EOL;
        $db->close();
        exit(0);
    }
    if ($mysql) {
        $assert($db->query('SELECT DATABASE() n')->getRow()->n === $owned, 'owned MySQL target verified');
    } else {
        $assert($db->getDatabase() === ':memory:', 'SQLite memory target verified');
        $db->initialize();
        $db->connID->createFunction('CONCAT', static fn (...$args) => in_array(null, $args, true) ? null : implode('', $args));
    }
    $forge = Database::forge($db);
    $create = static function (string $table, array $fields) use ($forge): void {
        $forge->addField($fields); $forge->addKey('id', true); $forge->createTable($table);
    };
    $id = ['type' => 'INT', 'auto_increment' => true];
    $int = ['type' => 'INT'];
    $nullableInt = ['type' => 'INT', 'null' => true];
    $str = ['type' => 'VARCHAR', 'constraint' => 180];
    $create('items', ['id' => $id, 'title' => $str, 'deleted' => ['type' => 'INT', 'default' => 0]]);
    $create('suppliers', ['id' => $id, 'name' => $str, 'status' => ['type' => 'VARCHAR', 'constraint' => 20], 'deleted' => ['type' => 'INT', 'default' => 0]]);
    $create('clients', ['id' => $id, 'company_name' => $str]);
    foreach (['proposals', 'estimates', 'invoices'] as $table) {
        $create($table, ['id' => $id, 'client_id' => $int, 'public_key' => $str, 'status' => $str, 'deleted' => ['type' => 'INT', 'default' => 0]]);
    }
    foreach (['proposal' => 'proposal_items', 'estimate' => 'estimate_items', 'invoice' => 'invoice_items'] as $type => $table) {
        $create($table, ['id' => $id, $type . '_id' => $int, 'item_id' => $int, 'supplier_id' => $nullableInt,
            'cost' => ['type' => 'DECIMAL', 'constraint' => '18,6'], 'rate' => ['type' => 'DECIMAL', 'constraint' => '18,6'],
            'quantity' => ['type' => 'DECIMAL', 'constraint' => '18,6'], 'deleted' => ['type' => 'INT', 'default' => 0]]);
    }
    foreach (['invoice_payments', 'financial_account_movements', 'payment_allocations'] as $table) $create($table, ['id' => $id, 'sentinel' => $str]);
    $fields = ['id' => $id, 'source_type' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
        'source_id' => $nullableInt, 'source_item_id' => $nullableInt, 'source_folio' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
        'product_id' => $int, 'supplier_id' => $int, 'proposal_id' => $nullableInt, 'proposal_item_id' => $nullableInt,
        'client_id' => $int, 'unit_cost' => ['type' => 'DECIMAL', 'constraint' => '18,6'],
        'sale_unit_price' => ['type' => 'DECIMAL', 'constraint' => '18,6'], 'quantity' => ['type' => 'DECIMAL', 'constraint' => '18,6'],
        'currency' => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'MXN'], 'quoted_at' => ['type' => 'DATETIME'],
        'recorded_by' => $nullableInt, 'created_at' => ['type' => 'DATETIME'], 'source_status' => ['type' => 'VARCHAR', 'constraint' => 20],
        'snapshot_version' => ['type' => 'INT', 'default' => 1], 'economic_hash' => ['type' => 'CHAR', 'constraint' => 64]];
    $originalNames = array_keys($fields);
    if (! $mysql) {
        foreach (['client_id', 'sale_unit_price', 'quantity', 'quoted_at'] as $field) $fields[$field]['null'] = true;
        $fields += ['notes' => ['type' => 'TEXT', 'null' => true], 'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'idempotency_key' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true]];
    }
    $forge->addField($fields); $forge->addKey('id', true);
    $forge->addUniqueKey(['source_type', 'source_item_id', 'economic_hash'], 'uq_cost_history_source_economic');
    $forge->addKey(['product_id', 'supplier_id', 'quoted_at'], false, false, 'idx_cost_history_product_supplier_date');
    foreach (['product_id' => 'items', 'supplier_id' => 'suppliers', 'client_id' => 'clients'] as $col => $parent) $forge->addForeignKey($col, $parent, 'id', 'RESTRICT', 'RESTRICT');
    if (! $mysql) $forge->addUniqueKey('idempotency_key', 'uq_cost_history_manual_idempotency');
    $forge->createTable('product_supplier_cost_history');
    $db->table('items')->insert(['id' => 1, 'title' => 'Fixture product', 'deleted' => 0]);
    $db->table('suppliers')->insert(['id' => 1, 'name' => 'Fixture supplier', 'status' => 'active', 'deleted' => 0]);
    $db->table('clients')->insert(['id' => 1, 'company_name' => 'Fixture client']);
    foreach (['proposal' => 'proposals', 'estimate' => 'estimates', 'invoice' => 'invoices'] as $type => $table) {
        $db->table($table)->insert(['id' => 1, 'client_id' => 1, 'public_key' => 'fixture-' . $type, 'status' => $type === 'invoice' ? 'open' : 'sent', 'deleted' => 0]);
        $db->table($type . '_items')->insert(['id' => 1, $type . '_id' => 1, 'item_id' => 1, 'supplier_id' => 1,
            'cost' => '10.000000', 'rate' => '15.000000', 'quantity' => '2.000000', 'deleted' => 0]);
    }
    $service = new SupplierCostHistoryService($db);
    foreach (['proposal', 'estimate', 'invoice'] as $type) $assert($service->snapshotDocument($type, 1, $type === 'invoice' ? 'open' : 'sent', 1) === 1, 'formal ' . $type . ' before manual support');
    // A legacy row with incomplete generic origin must remain untouched, not silently backfilled.
    $legacy = $db->table('product_supplier_cost_history')->where('id', 1)->get()->getRowArray();
    unset($legacy['id']); $legacy['source_type'] = null; $legacy['source_id'] = null; $legacy['source_item_id'] = null;
    $legacy['source_folio'] = null; $legacy['economic_hash'] = str_repeat('a', 64);
    $db->table('product_supplier_cost_history')->insert($legacy);
    $before = $db->table('product_supplier_cost_history')->select($originalNames)->orderBy('id')->get()->getResultArray();
    $migration = new EnableCanonicalManualSupplierCostHistory($forge);
    if ($mysql) {
        $reject(fn () => $service->saveManual($input, 1), 'manual write blocked before deployment');
        $migration->up(); $migration->up(); $migration->down();
        $assert($before === $db->table('product_supplier_cost_history')->select($originalNames)->orderBy('id')->get()->getResultArray(), 'migration/repeat/down preserve every formal and legacy value');
        $assert(count($db->getForeignKeyData('product_supplier_cost_history')) === 3, 'foreign keys preserved across nullable conversion');
    } else {
        $reject(fn () => $migration->up(), 'MySQL DDL cannot run on SQLite');
    }
    $snapshot = static function () use ($db): array {
        $out = [];
        foreach (['proposals', 'estimates', 'invoices', 'proposal_items', 'estimate_items', 'invoice_items', 'invoice_payments', 'financial_account_movements', 'payment_allocations'] as $t) $out[$t] = $db->table($t)->orderBy('id')->get()->getResultArray();
        return $out;
    };
    $administrative = $snapshot();
    $created = $service->saveManual($input, 1);
    $manual = $service->manual($created['id']);
    $assert($created['created'] && $manual->source_type === 'manual' && $manual->source_status === 'manual', 'explicit manual origin');
    foreach (['client_id', 'sale_unit_price', 'quantity', 'quoted_at', 'source_id', 'source_item_id', 'proposal_id', 'proposal_item_id'] as $field) $assert($manual->$field === null, 'manual nullable ' . $field);
    $assert((int) $manual->product_id === 1 && (int) $manual->supplier_id === 1 && $manual->notes === $input['notes'], 'product supplier and notes preserved');
    $retry = $service->saveManual($input, 1);
    $assert(! $retry['created'] && $retry['id'] === $created['id'], 'same key same payload replays existing row');
    foreach (['unit_cost' => '99', 'notes' => 'different', 'quoted_at' => '2026-09-08'] as $key => $value) $reject(fn () => $service->saveManual(array_replace($input, [$key => $value]), 1), 'same key rejects changed ' . $key);
    $assert($manual == $service->manual($created['id']), 'retries never update existing capture');
    $dated = $service->saveManual(array_replace($input, ['idempotency_key' => 'dated', 'quoted_at' => '2026-09-07', 'sale_unit_price' => '0', 'quantity' => '2']), 1);
    $assert($service->manual($dated['id'])->quoted_at === '2026-09-07 00:00:00', 'explicit date normalized exactly');
    $assert((string) $service->manual($dated['id'])->sale_unit_price !== '', 'explicit zero sale value allowed, distinct from NULL');
    $assert($service->saveManual($input, 2)['created'], 'idempotency scoped to actor');
    foreach ([['unit_cost' => '0'], ['unit_cost' => '1e3'], ['unit_cost' => '1000000000000'], ['unit_cost' => '1.1234567'],
        ['quoted_at' => '2026-02-30'], ['currency' => 'USD'], ['product_id' => 999], ['supplier_id' => 999], ['product_id' => '1oops'],
        ['client_id' => 1], ['source_type' => 'invoice'], ['idempotency_key' => 'bad token'], ['quantity' => '0']] as $bad) {
        $reject(fn () => $service->saveManual(array_replace($input, ['idempotency_key' => 'invalid'], $bad), 1), 'invalid capture rejected: ' . json_encode($bad));
    }
    $reject(fn () => $service->saveManual($input, 1, 1), 'editing any existing row is not exposed by P03');
    $assert($service->manual(1) === null, 'formal history cannot be retrieved as manual');
    $assert(count($service->productHistory(1)) === 7 && count($service->supplierHistory(1)) === 7, 'manual and formal history visible together without inner client join');
    foreach (['proposal', 'estimate', 'invoice'] as $type) $assert($service->snapshotDocument($type, 1, $type === 'invoice' ? 'open' : 'sent', 1) === 0, 'formal idempotency unchanged: ' . $type);
    $assert($before === $db->table('product_supplier_cost_history')->select($originalNames)->where('id <=', 4)->orderBy('id')->get()->getResultArray(), 'all prior history untouched after manual capture');
    $assert($administrative === $snapshot(), 'no commercial documents or financial mutations');
    $db->table('invoice_items')->where('id', 1)->update(['cost' => '11.000000']);
    $assert($service->snapshotInvoice(1, 'open', 1) === 1, 'new formal economics can coexist after manual captures');
    $assert($before === $db->table('product_supplier_cost_history')->select($originalNames)->where('id <=', 4)->orderBy('id')->get()->getResultArray(), 'new formal version preserves prior history');
    $assert($manual == $service->manual($created['id']), 'formal snapshot never modifies manual row');
    if ($mysql) {
        $all = $db->table('product_supplier_cost_history')->orderBy('id')->get()->getResultArray();
        $foundRowsDb = Database::connect(array_replace($config, ['foundRows' => true]), false);
        try {
            $reject(fn () => (new SupplierCostHistoryService($foundRowsDb))->saveManual($input, 8), 'ambiguous foundRows creation reporting is rejected');
        } finally {
            $foundRowsDb->close();
        }
        $migration->up(); $migration->down();
        $assert($all === $db->table('product_supplier_cost_history')->orderBy('id')->get()->getResultArray(), 'repeat migration preserves manual NULLs and identities');
        $env = getenv();
        $env['P03_FIXTURE_CONFIG'] = base64_encode(json_encode($config, JSON_THROW_ON_ERROR));
        $env['P03_RACE_START'] = (string) (microtime(true) + 2);
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $pipes = [];
            $proc = proc_open([PHP_BINARY, __FILE__, '--race-worker'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH, $env);
            if (! is_resource($proc)) throw new RuntimeException('Cannot start concurrency fixture.');
            fclose($pipes[0]); $workers[] = [$proc, $pipes];
        }
        $results = [];
        foreach ($workers as [$proc, $pipes]) {
            $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($proc) !== 0) throw new RuntimeException('Race worker failed: ' . $stderr . $stdout);
            $results[] = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
        }
        $assert($results[0]['id'] === $results[1]['id'] && count(array_filter($results, fn ($r) => $r['created'])) === 1, 'two MySQL processes share one id and only one creation under REPEATABLE READ');
        $assert($db->table('product_supplier_cost_history')->where('recorded_by', 77)->countAllResults() === 1, 'unique index prevents concurrent duplicate');
        // Partial deployment with wrong index must fail before any schema/data changes.
        $db->query('ALTER TABLE p03_product_supplier_cost_history DROP INDEX uq_cost_history_manual_idempotency, ADD INDEX uq_cost_history_manual_idempotency(idempotency_key)');
        $db->resetDataCache();
        $reject(fn () => $migration->up(), 'incompatible existing index rejected');
        $reject(fn () => $service->saveManual($input, 5), 'manual writes refuse nonunique idempotency index');
        $db->query('ALTER TABLE p03_product_supplier_cost_history DROP INDEX uq_cost_history_manual_idempotency');
        $copy = $db->table('product_supplier_cost_history')->where('id', $created['id'])->get()->getRowArray();
        unset($copy['id']); $db->table('product_supplier_cost_history')->insert($copy);
        $db->query('ALTER TABLE p03_product_supplier_cost_history DROP COLUMN updated_at');
        $db->resetDataCache();
        $reject(fn () => $migration->up(), 'duplicate keys rejected during preflight');
        $assert(! $db->fieldExists('updated_at', 'product_supplier_cost_history'), 'duplicate-key preflight performs no preceding DDL');
    }
    echo $passed . " passed, 0 failed. No source data or PAC accessed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
    $exit = 1;
} finally {
    if ($db) $db->close();
    if ($server && $owned) {
        if (! preg_match('/^ikontrol_test_p03_[a-f0-9]{12}$/D', $owned)) throw new RuntimeException('Unsafe cleanup target.');
        $server->query('DROP DATABASE `' . $owned . '`');
        $server->close();
        echo "Owned synthetic MySQL schema removed.\n";
    }
}
exit($exit);
