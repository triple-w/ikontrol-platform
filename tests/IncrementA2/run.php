<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $message) use (&$pass, &$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$config = file_get_contents($root . '/app/Config/Fiscal.php');
$vault = file_get_contents($root . '/app/Services/Fiscal/Signing/CsdSecretVault.php');
$controller = file_get_contents($root . '/app/Controllers/Fiscal/InvoiceReview.php');
$draft = file_get_contents($root . '/app/Views/fiscal/invoices/draft.php');
$certificateController = file_get_contents($root . '/app/Controllers/Fiscal/Certificates.php');
$secretForm = file_get_contents($root . '/app/Views/fiscal/certificates/secret_form.php');
$security = file_get_contents($root . '/app/Config/Security.php');
$filters = file_get_contents($root . '/app/Config/Filters.php');
$routes = file_get_contents($root . '/app/Config/FiscalRoutes.php');

$assert(str_contains($config, 'csdEncryptionKey') && str_contains($config, 'assertValidCsdEncryptionKey'), 'Config Fiscal is the single authority for the CSD master key.');
$assert(str_contains($config, 'CSD_ENCRYPTION_KEYS_REUSED'), 'PAC and CSD master-key reuse is explicitly blocked.');
$assert(str_contains($vault, 'aes-256-gcm') && str_contains($vault, 'random_bytes'), 'Vault uses authenticated AES-256-GCM and a random nonce.');
$assert(!str_contains($controller, "getPost('private_key_password')"), 'Invoice signing controller never receives a CSD password.');
$assert(!preg_match("/name=[\"']private_key_password[\"']/", $draft), 'Fiscal invoice view contains no password input.');
$assert(str_contains($certificateController, 'configure_secret') && str_contains($routes, 'certificates/secret/configure'), 'Administrative secret configuration has an explicit protected POST route.');
$assert(str_contains($routes, "['filter' => 'csrf']"), 'CSD secret mutation is protected by the CodeIgniter CSRF filter.');
$assert(!preg_match('/log_message\\([^\\n]+password/i', $certificateController . $controller), 'Controllers do not log password values.');
$assert(!str_contains($vault, 'pacEncryptionKey') || str_contains($config, 'CSD_ENCRYPTION_KEYS_REUSED'), 'The PAC key is never used as CSD encryption material.');
$assert(str_contains($secretForm, 'csrf_field()'), 'The AJAX CSD password form renders a current CSRF field inside the form.');
$assert(str_contains($secretForm, 'new FormData(form[0])'), 'The AJAX request serializes the real form, including its CSRF field.');
$assert(str_contains($certificateController, "'name' => csrf_token()") && str_contains($certificateController, "'hash' => csrf_hash()"), 'Every controlled secret response returns the current CSRF token.');
$assert(str_contains($secretForm, 'result.csrf.name') && str_contains($secretForm, 'result.csrf.hash'), 'The modal updates its hidden CSRF field after an AJAX response.');
$assert(str_contains($secretForm, 'xhr.status === 403') && str_contains($secretForm, "password.val('')"), 'A CSRF rejection clears the password and shows a controlled modal error.');
$assert(str_contains($security, "public bool \$regenerate = false"), 'The effective CodeIgniter CSRF regeneration policy is characterized.');
$assert(str_contains($filters, "'csrf'          => CSRF::class") && !str_contains($routes, 'except'), 'The CSRF filter remains available and the CSD route is not excluded.');

echo PHP_EOL . "{$pass} passed, {$fail} failed." . PHP_EOL;
exit($fail ? 1 : 0);
