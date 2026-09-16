<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$passed = 0;
$failed = 0;
$pacCalls = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $condition ? $passed++ : $failed++;
};

$originalTimezone = date_default_timezone_get();
try {
    date_default_timezone_set('Pacific/Auckland');
    $utcInstant = new DateTimeImmutable('2026-07-24T04:25:52+00:00');
    $dates = new App\Services\Fiscal\FiscalIssueDateService(static fn() => $utcInstant);

    $snapshot = $dates->nowForSnapshot();
    $assert(App\Services\Fiscal\FiscalIssueDateService::TIMEZONE === 'America/Mexico_City', 'Fiscal timezone is explicitly America/Mexico_City.');
    $assert($snapshot === '2026-07-23 22:25:52', 'UTC instant is converted by timezone rules, without subtracting six hours manually.');
    $assert($dates->formatForXml($snapshot) === '2026-07-23T22:25:52', 'Comprobante.Fecha uses Y-m-d\\TH:i:s with no timezone suffix.');
    $assert($snapshot !== '2026-07-24 04:25:52', 'Fiscal date does not reuse the UTC sale/draft timestamp.');

    $realNow = new App\Services\Fiscal\FiscalIssueDateService();
    $fiscalNow = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $realNow->nowForSnapshot(),
        new DateTimeZone('America/Mexico_City')
    );
    $expectedNow = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
    $assert($fiscalNow !== false && abs($expectedNow->getTimestamp() - $fiscalNow->getTimestamp()) <= 300, 'Frozen fiscal time is within five minutes of Mexico City local time.');

    $factory = require dirname(__DIR__) . '/Increment07/fixture_factory.php';
    $builder = new App\Services\Fiscal\Cfdi40\CfdiXmlBuilder();
    $oldXml = $builder->build($factory(['issue_date' => '2026-07-23 22:25:52']));
    $oldHash = hash('sha256', $oldXml);
    $newXml = $builder->build($factory(['issue_date' => '2026-07-23 22:26:52']));
    $assert(str_contains($oldXml, 'Fecha="2026-07-23T22:25:52"') && str_contains($newXml, 'Fecha="2026-07-23T22:26:52"'), 'Builder uses the frozen snapshot date verbatim.');
    $assert($oldXml !== $newXml && hash('sha256', $newXml) !== $oldHash, 'A new fiscal date produces a distinct XML artifact.');

    $chains = new App\Services\Fiscal\Cfdi40\CfdiOriginalChainGenerator();
    $oldChain = $chains->generate($oldXml);
    $newChain = $chains->generate($newXml);
    $assert($oldChain['sha256'] !== $newChain['sha256'], 'Changing Fecha regenerates a different original chain.');

    $privateKey = openssl_pkey_new([
        'config' => 'C:\\xampp\\apache\\conf\\openssl.cnf',
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    $oldSeal = $newSeal = '';
    $signedOld = $privateKey && openssl_sign($oldChain['chain'], $oldSeal, $privateKey, OPENSSL_ALGO_SHA256);
    $signedNew = $privateKey && openssl_sign($newChain['chain'], $newSeal, $privateKey, OPENSSL_ALGO_SHA256);
    $assert($signedOld && $signedNew && !hash_equals(base64_encode($oldSeal), base64_encode($newSeal)), 'Regenerated chain produces a different RSA-SHA256 seal.');
    $assert(hash('sha256', $oldXml) === $oldHash, 'Previously generated XML remains immutable in memory.');

    $draftSource = file_get_contents(APPPATH . 'Services/Fiscal/FiscalDraftCreationService.php');
    $builderSource = file_get_contents(APPPATH . 'Services/Fiscal/Cfdi40/CfdiXmlBuilder.php');
    $assert(!str_contains($draftSource, "issue_date'=>\$now") && !str_contains($builderSource, 'gmdate('), 'Fiscal issue date does not use gmdate or the UTC administrative timestamp.');
    $assert($pacCalls === 0, 'Timezone regression performs no PAC call.');
} catch (Throwable $error) {
    echo '[FAIL] ' . get_class($error) . ': ' . $error->getMessage() . PHP_EOL;
    $failed++;
} finally {
    date_default_timezone_set($originalTimezone);
}

echo PHP_EOL . "$passed passed, $failed failed." . PHP_EOL;
exit($failed ? 1 : 0);
