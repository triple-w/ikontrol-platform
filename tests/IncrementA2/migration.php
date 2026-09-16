<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
helper(['general', 'date_time', 'plugin']);
$db = require dirname(__DIR__) . '/Increment02/isolated_database.php';
$initialSecretCount = $db->tableExists('fiscal_issuer_certificate_secrets')
    ? $db->table('fiscal_issuer_certificate_secrets')->countAllResults()
    : 0;
service('migrations')->setNamespace('App')->latest();

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $message) use (&$pass, &$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

foreach ([
    'fiscal_issuer_certificate_secrets',
    'fiscal_issuer_certificate_secret_audit',
] as $table) {
    $assert($db->tableExists($table), "{$table} exists after isolated migrations.");
}
foreach ([
    'fiscal_issuer_certificate_id', 'secret_type', 'encrypted_payload',
    'encryption_version', 'status', 'validated_at', 'rotated_at',
] as $field) {
    $assert($db->fieldExists($field, 'fiscal_issuer_certificate_secrets'), "Secret field {$field} exists.");
}
$indexes = $db->getIndexData('fiscal_issuer_certificate_secrets');
$unique = false;
$indexed = false;
foreach ($indexes as $index) {
    $fields = $index->fields ?? [];
    $unique = $unique || (
        ($index->type ?? '') === 'UNIQUE'
        && $fields === ['fiscal_issuer_certificate_id', 'secret_type']
    );
    $indexed = $indexed || in_array('fiscal_issuer_certificate_id', $fields, true);
}
$assert($unique, 'One secret row per certificate/type is enforced.');
$assert($indexed, 'Certificate secret lookup is indexed.');
$assert(
    $db->table('fiscal_issuer_certificate_secrets')->countAllResults() === $initialSecretCount,
    'Migration creates no secret or fixture.'
);
$migration = file_get_contents(APPPATH . 'Database/Migrations/2026-07-30-100000_CreateCsdCertificateSecrets.php');
$assert(str_contains($migration, 'dropTable') && !str_contains($migration, "password' =>"), 'Rollback is explicit and migration contains no plaintext credential.');

echo PHP_EOL . "{$pass} passed, {$fail} failed." . PHP_EOL;
exit($fail ? 1 : 0);
