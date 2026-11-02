<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root . '/app/Services/Fiscal/Pac/FiscalStampingService.php');
$outer = (string) file_get_contents($root . '/app/Services/Fiscal/FiscalDraftStampingService.php');
$preflight = (string) file_get_contents($root . '/app/Services/Fiscal/FiscalDraftStampingPreflightService.php');
$pass = 0;
$assert = static function (bool $condition, string $label) use (&$pass): void {
    if (! $condition) throw new RuntimeException('[FAIL] ' . $label);
    ++$pass;
    echo '[PASS] ' . $label . PHP_EOL;
};

$assert(
    str_contains($service, 'requireReadyForSaleFlow($draftId, true)')
        && ! str_contains($service, 'requireReady($draftId, true)'),
    'final PAC gate uses the canonical sale-flow preflight'
);
$assert(
    str_contains($outer, 'requireReadyForSaleFlow($draftId, $documentId>0)')
        && str_contains($outer, 'stampDraftDocument($documentId, $userId, true)'),
    'outer preparation and inner PAC gate preserve the same policy'
);
$assert(
    str_contains($preflight, "['draft','open','closed']")
        && str_contains($preflight, "['closed']"),
    'sale-flow permits reserved open sales without weakening standalone drafts'
);
$assert(
    strpos($service, 'requireReadyForSaleFlow($draftId, true)')
        < strpos($service, '$factory->create()')
        && strpos($service, '$factory->create()') < strpos($service, 'reserveForAttempt'),
    'preflight remains before adapter construction and wallet reservation'
);
$assert(
    str_contains($service, 'validateDraftDocumentConsistency($draftId, $documentId)'),
    'allocation and prepared-document consistency remains mandatory'
);
$assert(
    substr_count($service, '$this->prepare($documentId, $userId, $provider, $environment, $operation, $endpoint)') === 1,
    'single durable attempt preparation path is preserved'
);

echo "TOTAL PASS={$pass} FAIL=0; static regression, no database and no network.\n";
