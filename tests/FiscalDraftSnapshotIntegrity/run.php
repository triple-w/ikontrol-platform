<?php
declare(strict_types=1);

if (!extension_loaded('sqlite3')) {
    fwrite(STDERR, "BLOCKED: sqlite3 required.\n");
    exit(2);
}

require dirname(__DIR__).'/bootstrap.php';

use App\Services\Fiscal\FiscalDraftSnapshotService;
use App\Services\Fiscal\Stamps\FiscalStampWalletResolver;
use App\Services\Upgrade\Fiscal116SchemaContract;
use Config\Database;

$config = [
    'DSN' => '',
    'hostname' => '',
    'username' => '',
    'password' => '',
    'database' => ':memory:',
    'DBDriver' => 'SQLite3',
    'DBPrefix' => '',
    'pConnect' => false,
    'foreignKeys' => false,
];
$databaseConfig = config(Database::class);
$databaseConfig->default = $config;
$db = Database::connect($config, true);
$db->initialize();
if ($db->DBDriver !== 'SQLite3' || $db->getDatabase() !== ':memory:') {
    throw new RuntimeException('Unsafe target: SQLite memory database required.');
}

foreach ([
    'CREATE TABLE fiscal_drafts(id INTEGER PRIMARY KEY,snapshot_version INTEGER,requires_snapshot_refresh INTEGER,fiscal_payload TEXT)',
    'CREATE TABLE fiscal_draft_items(id INTEGER PRIMARY KEY,fiscal_draft_id INTEGER,fiscal_snapshot TEXT,quantity TEXT,subtotal TEXT,discount TEXT,sale_id INTEGER)',
    'CREATE TABLE fiscal_draft_item_taxes(id INTEGER PRIMARY KEY,fiscal_draft_item_id INTEGER,calculation_order INTEGER,tax_base TEXT,tax_type TEXT,tax_code TEXT,factor_type TEXT,rate_or_quota TEXT,tax_amount TEXT)',
    'CREATE TABLE fiscal_draft_sales(id INTEGER PRIMARY KEY,fiscal_draft_id INTEGER,sale_id INTEGER,allocation_status TEXT,allocated_total TEXT)',
    'CREATE TABLE fiscal_profiles(id INTEGER PRIMARY KEY,company_id INTEGER,profile_type TEXT,rfc TEXT,environment TEXT,status TEXT)',
    'CREATE TABLE fiscal_stamp_accounts(id INTEGER PRIMARY KEY,issuer_profile_id INTEGER,environment TEXT,status TEXT)',
] as $sql) {
    $db->query($sql);
}

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $message) use (&$pass, &$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$payload = json_encode([
    'issuer_snapshot' => ['rfc' => 'AAA010101AAA', 'tax_regime_code' => '601'],
    'receiver_snapshot' => ['rfc' => 'XAXX010101000'],
    'series_snapshot' => ['series' => 'TEST'],
], JSON_THROW_ON_ERROR);
$line = json_encode([
    'snapshot_version' => 2,
    'object_tax' => '01',
    'transferred_total' => '0.000000',
    'withheld_total' => '0.000000',
], JSON_THROW_ON_ERROR);
$db->table('fiscal_drafts')->insert([
    'id' => 1,
    'snapshot_version' => 2,
    'requires_snapshot_refresh' => 0,
    'fiscal_payload' => $payload,
]);
$db->table('fiscal_draft_items')->insert([
    'id' => 1,
    'fiscal_draft_id' => 1,
    'fiscal_snapshot' => $line,
    'quantity' => '1.000000',
    'subtotal' => '100.000000',
    'discount' => '0.000000',
    'sale_id' => 11,
]);
$valid = (new FiscalDraftSnapshotService($db))->getCompleteFiscalSnapshot(1);
$assert(count($valid['items']) === 1 && $valid['totals']['total'] === '100.00', 'valid version 2 snapshot and persisted item are recovered');

$db->table('fiscal_drafts')->insert([
    'id' => 2,
    'snapshot_version' => 1,
    'requires_snapshot_refresh' => 1,
    'fiscal_payload' => '{}',
]);
$incomplete = false;
try {
    (new FiscalDraftSnapshotService($db))->getCompleteFiscalSnapshot(2);
} catch (RuntimeException $exception) {
    $incomplete = $exception->getMessage() === 'FISCAL_DRAFT_SNAPSHOT_INCOMPLETE';
}
$assert($incomplete, 'legacy or refresh-required snapshot is classified as incomplete');

$db->table('fiscal_drafts')->insert([
    'id' => 3,
    'snapshot_version' => 2,
    'requires_snapshot_refresh' => 0,
    'fiscal_payload' => '{',
]);
$corruptPayload = false;
try {
    (new FiscalDraftSnapshotService($db))->getCompleteFiscalSnapshot(3);
} catch (RuntimeException $exception) {
    $corruptPayload = $exception->getMessage() === 'FISCAL_DRAFT_SNAPSHOT_CORRUPT';
}
$assert($corruptPayload, 'malformed aggregate JSON is classified as corrupt');

$db->table('fiscal_drafts')->insert([
    'id' => 4,
    'snapshot_version' => 2,
    'requires_snapshot_refresh' => 0,
    'fiscal_payload' => $payload,
]);
$db->table('fiscal_draft_items')->insert([
    'id' => 2,
    'fiscal_draft_id' => 4,
    'fiscal_snapshot' => '{',
    'quantity' => '1.000000',
    'subtotal' => '100.000000',
    'discount' => '0.000000',
    'sale_id' => 12,
]);
$corruptLine = false;
try {
    (new FiscalDraftSnapshotService($db))->getCompleteFiscalSnapshot(4);
} catch (RuntimeException $exception) {
    $corruptLine = $exception->getMessage() === 'FISCAL_DRAFT_SNAPSHOT_CORRUPT';
}
$assert($corruptLine, 'malformed concept JSON is classified as corrupt');

$db->table('fiscal_drafts')->insert([
    'id' => 5,
    'snapshot_version' => 3,
    'requires_snapshot_refresh' => 0,
    'fiscal_payload' => $payload,
]);
$unsupported = false;
try {
    (new FiscalDraftSnapshotService($db))->getCompleteFiscalSnapshot(5);
} catch (RuntimeException $exception) {
    $unsupported = $exception->getMessage() === 'FISCAL_DRAFT_SNAPSHOT_UNSUPPORTED';
}
$assert($unsupported, 'unknown future snapshot version fails closed as unsupported');

$db->table('fiscal_profiles')->insert([
    'id' => 7,
    'company_id' => 1,
    'profile_type' => 'issuer',
    'rfc' => 'AAA010101AAA',
    'environment' => 'development',
    'status' => 'ready',
]);
$wallet = (new FiscalStampWalletResolver($db))->resolve(7, 'development');
$assert($wallet['status'] === 'account_missing', 'wallet resolution supports fiscal_profiles schema without deleted column');
$assert(!in_array('deleted', Fiscal116SchemaContract::PREREQUISITE_COLUMNS['fiscal_profiles'], true), 'upgrade schema contract does not require undeclared fiscal_profiles.deleted');

echo "TOTAL PASS={$pass} FAIL={$fail}. SQLite :memory: only; synthetic data; no network.\n";
exit($fail ? 1 : 0);
