<?php

declare(strict_types=1);

// Requires an explicitly restored local copy; it never creates, drops or imports a database.
require dirname(__DIR__) . '/bootstrap.php';

use App\Services\Upgrade\Legacy\SmartfreePrefiscalBridgeService;

$database = $argv[1] ?? null;

if (! $database) {
    fwrite(
        STDERR,
        "usage: php tests/SmartfreeBridge/run.php <local-copy>\n"
    );

    exit(2);
}

$service = new SmartfreePrefiscalBridgeService($database);

$result = $service->dryRun();

if (
    empty($result['already_completed'])
    || ($result['after']['invoice_total'] ?? '') !== '7977784.550000'
    || ($result['after']['payment_total'] ?? '') !== '7704623.022300'
) {
    throw new RuntimeException('Bridge reconciliation failed.');
}

echo "[PASS] completed bridge is idempotent and preserves certified monetary totals\n";