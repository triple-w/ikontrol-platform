<?php

declare(strict_types=1);

require __DIR__.'/LegacyBootstrapInstaller.php';

use Ikontrol\LegacyBootstrap\LegacyBootstrapInstaller;

$option = static function(string $name): ?string {
    foreach ($_SERVER['argv'] ?? [] as $argument) {
        if (is_string($argument) && str_starts_with($argument, "--{$name}=")) return substr($argument, strlen($name)+3);
    }
    return null;
};
$args = $_SERVER['argv'] ?? [];
$json = in_array('--json', $args, true);
$execute = in_array('--execute', $args, true);
$rollbackPlan = in_array('--rollback-plan', $args, true);

try {
    $target = $option('target');
    if ($target === null || $target === '') throw new RuntimeException('Use --target=/absolute/path/to/legacy-instance.');
    if ($execute && $rollbackPlan) throw new RuntimeException('--execute and --rollback-plan are mutually exclusive.');
    if ($execute && ! in_array('--yes', $args, true)) throw new RuntimeException('Use --yes after reviewing the bootstrap dry-run.');
    $source = $option('source') ?: dirname(__DIR__, 2);
    $manifest = $option('manifest') ?: $source.'/resources/upgrade/legacy-bootstrap/manifest.json';
    $installer = new LegacyBootstrapInstaller($source, $target, $manifest);
    $result = $rollbackPlan ? $installer->rollbackPlan() : ($execute ? $installer->install() : $installer->inspect());
    fwrite(STDOUT, ($json ? json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : print_r($result, true)).PHP_EOL);
    exit(($rollbackPlan ? $result['safe'] : $result['compatible']) ? 0 : 2);
} catch (Throwable $error) {
    $result = ['status'=>'ERROR','error'=>$error->getMessage()];
    fwrite(STDERR, ($json ? json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : $error->getMessage()).PHP_EOL);
    exit(2);
}
