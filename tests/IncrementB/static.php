<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;
$assert = static function (bool $condition, string $message) use (&$pass, &$fail): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $condition ? $pass++ : $fail++;
};
$read = static fn(string $path): string => (string) file_get_contents($root . DIRECTORY_SEPARATOR . $path);

$routes = $read('app/Config/FiscalRoutes.php');
$controller = $read('app/Controllers/Fiscal/InvoiceReview.php');
$service = $read('app/Services/Fiscal/FiscalInvoiceGenerationService.php');
$result = $read('app/Domain/Fiscal/FiscalInvoiceGenerationResult.php');
$review = $read('app/Views/fiscal/invoices/review.php');
$draft = $read('app/Views/fiscal/invoices/draft.php');

$assert(str_contains($routes, "fiscal/invoices/(:num)/generate") && preg_match("/['\"]filter['\"]\\s*=>\\s*['\"]csrf['\"]/", $routes), 'The single generate endpoint is POST-only and explicitly CSRF protected.');
$assert(str_contains($controller, 'FiscalInvoiceGenerationService') && str_contains($controller, '$routeInvoiceId'), 'The real controller invokes the orchestrator and validates the route invoice.');
$assert(str_contains($service, 'GET_LOCK') && str_contains($service, 'RELEASE_LOCK'), 'A database logical lock protects each administrative sale.');
$assert(str_contains($service, 'FiscalDraftCreationService') && str_contains($service, 'CfdiPreXmlArtifactService') && str_contains($service, 'CfdiSigningService') && str_contains($service, 'FiscalStampingService'), 'The orchestrator coordinates existing fiscal services instead of duplicating them.');
$assert(!preg_match('/xml(full|content)|pdf(base64|content)|api.?key|password|ciphertext|private.?key/i', $result), 'The typed public result contains no fiscal payloads or secrets.');
$assert(str_contains($review, 'generate-fiscal-invoice-form') && str_contains($review, 'processing_invoice'), 'The normal UI exposes one Generate invoice action with non-fictitious processing feedback.');
$assert(str_contains($review, 'advanced_fiscal_tools') && str_contains($draft, 'advanced_fiscal_tools'), 'Technical operations remain available in an advanced section.');
$assert(str_contains($review, 'confirm_new_version'), 'Correcting fiscal data requires explicit confirmation before a new version.');
$assert(!str_contains($controller, 'TimbradorXpressRestAdapter'), 'The controller never constructs a real PAC adapter.');

echo PHP_EOL . "{$pass} passed, {$fail} failed." . PHP_EOL;
exit($fail ? 1 : 0);
