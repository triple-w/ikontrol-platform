<?php

declare(strict_types=1);

use Ikontrol\Release\ReleaseArtifactBuilder;

require_once __DIR__ . '/ReleaseArtifactBuilder.php';

$options = getopt('', ['repo::', 'spec::', 'output-dir::']);
$platformRoot = dirname(__DIR__, 2);
$repository = isset($options['repo']) ? (string) $options['repo'] : $platformRoot;
$spec = isset($options['spec']) ? (string) $options['spec'] : __DIR__ . '/releases/1.1.5.json';
$output = isset($options['output-dir']) ? (string) $options['output-dir'] : $platformRoot . '/writable/releases/1.1.5';

try {
    $result = (new ReleaseArtifactBuilder(rtrim($repository, '/\\')))->build($spec, $output);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'status' => 'error',
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
