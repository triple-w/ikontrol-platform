<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
$db = require dirname(__DIR__) . '/Increment02/isolated_database.php';
$migrations = service('migrations')->setNamespace('App');
$migrations->latest();

$pass = 0;
$fail = 0;
$assert = static function (bool $condition, string $message) use (&$pass, &$fail): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $condition ? $pass++ : $fail++;
};

$assert($db->tableExists('fiscal_document_binary_artifacts'), 'Fresh migration creates binary artifacts.');
$fields = array_column($db->getFieldData('fiscal_document_binary_artifacts'), 'name');
foreach ([
    'fiscal_document_id', 'stamp_attempt_id', 'artifact_type', 'content_base64',
    'decoded_mime_type', 'decoded_size_bytes', 'decoded_sha256', 'validation_status',
] as $field) {
    $assert(in_array($field, $fields, true), "Binary artifact field {$field} exists.");
}
$indexes = $db->getIndexData('fiscal_document_binary_artifacts');
$indexFields = [];
foreach ($indexes as $index) {
    foreach ($index->fields as $field) $indexFields[] = $field;
}
$assert(in_array('stamp_attempt_id', $indexFields, true), 'stamp_attempt_id is indexed.');
$assert(in_array('uuid', $indexFields, true), 'UUID is indexed.');
$assert(
    isset($indexes['uq_fiscal_binary_document_type']) && $indexes['uq_fiscal_binary_document_type']->type === 'UNIQUE',
    'Document/type duplicate protection exists.'
);
foreach (['pac_pdf_artifact_id', 'pdf_status', 'pdf_template'] as $field) {
    $assert($db->fieldExists($field, 'fiscal_document_stamps'), "Stamp field {$field} exists.");
}
$assert(
    $db->table('fiscal_document_binary_artifacts')->countAllResults() === 0,
    'Migration creates no PDF fixture or user data.'
);

echo PHP_EOL . "{$pass} passed, {$fail} failed." . PHP_EOL;
exit($fail ? 1 : 0);
