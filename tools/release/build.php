<?php

declare(strict_types=1);

use App\Services\Release\ReleaseArtifactBuilder;

require_once dirname(__DIR__, 2) . '/app/Services/Release/ReleaseArtifactBuilder.php';

$options = getopt('', ['version:', 'repo::', 'output-dir::', 'allow-dirty']);
$root = dirname(__DIR__, 2);
$version = isset($options['version']) ? (string) $options['version'] : '';
$repository = isset($options['repo']) ? (string) $options['repo'] : $root;
$output = isset($options['output-dir']) ? (string) $options['output-dir'] : $root . '/dist';

try {
    $result = (new ReleaseArtifactBuilder(rtrim($repository, '/\\')))->build($version, $output, array_key_exists('allow-dirty', $options));
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status' => 'ERROR', 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
