<?php

declare(strict_types=1);

use Ikontrol\Release\ReleaseArtifactBuilder;

require_once dirname(__DIR__, 2) . '/tools/release/ReleaseArtifactBuilder.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS: {$message}\n";
        return;
    }
    $failed++;
    echo "FAIL: {$message}\n";
};
$run = static function (array $command, string $cwd, array $environment = []): string {
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment === [] ? null : array_merge($_ENV, $environment));
    if (!is_resource($process)) {
        throw new RuntimeException('No fue posible iniciar proceso de prueba.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        throw new RuntimeException(implode(' ', $command) . ': ' . $stderr);
    }
    return trim((string) $stdout);
};
$write = static function (string $root, string $path, string $contents): void {
    $target = $root . '/' . $path;
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0775, true);
    }
    file_put_contents($target, $contents);
};
$remove = static function (string $path) use (&$remove): void {
    if (!file_exists($path)) {
        return;
    }
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . '/' . $entry);
            }
        }
        rmdir($path);
        return;
    }
    @chmod($path, 0666);
    unlink($path);
};

$root = sys_get_temp_dir() . '/ikontrol-release-artifact-' . bin2hex(random_bytes(5));
$repo = $root . '/repo';
$outputA = $root . '/out-a';
$outputB = $root . '/out-b';
mkdir($repo, 0775, true);

try {
    $run(['git', 'init'], $repo);
    $run(['git', 'config', 'user.email', 'release-test@ikontrol.invalid'], $repo);
    $run(['git', 'config', 'user.name', 'iKontrol Release Test'], $repo);
    $write($repo, 'app/Config/Version.php', "<?php final class Version { public const VERSION = '1.1.4'; }\n");
    $write($repo, 'app/Existing.php', "old\n");
    $write($repo, 'app/Unchanged.php', "same\n");
    $write($repo, '.env', "SECRET=old\n");
    $write($repo, 'writable/private.key', "private\n");
    $write($repo, 'tests/NotRuntime.php', "test\n");
    $run(['git', 'add', '.'], $repo);
    $run(['git', 'commit', '-m', 'base'], $repo, ['GIT_AUTHOR_DATE' => '2026-10-07T12:00:00Z', 'GIT_COMMITTER_DATE' => '2026-10-07T12:00:00Z']);
    $base = $run(['git', 'rev-parse', 'HEAD'], $repo);

    $write($repo, 'app/Config/Version.php', "<?php final class Version { public const VERSION = '1.1.5'; }\n");
    $write($repo, 'app/Existing.php', "new\n");
    $write($repo, 'app/New.php', "added\n");
    $write($repo, '.env', "SECRET=changed\n");
    $write($repo, 'writable/private.key', "changed-private\n");
    $write($repo, 'tests/NotRuntime.php', "changed-test\n");
    $run(['git', 'add', '.'], $repo);
    $run(['git', 'commit', '-m', 'target'], $repo, ['GIT_AUTHOR_DATE' => '2026-10-08T12:00:00Z', 'GIT_COMMITTER_DATE' => '2026-10-08T12:00:00Z']);
    $target = $run(['git', 'rev-parse', 'HEAD'], $repo);

    $spec = $root . '/spec.json';
    file_put_contents($spec, json_encode([
        'version' => '1.1.5',
        'release_id' => 'ikontrol-1.1.5-canonical-stamp-wallet-resolution',
        'release_ref' => 'release/1.1.5',
        'commit_sha' => $target,
        'from_version' => '1.1.4',
        'from_commit_sha' => $base,
    ], JSON_PRETTY_PRINT));

    $builder = new ReleaseArtifactBuilder($repo);
    $first = $builder->build($spec, $outputA);
    $second = $builder->build($spec, $outputB);
    $manifest = $first['manifest'];
    $byPath = [];
    foreach ($manifest['files'] as $file) {
        $byPath[$file['path']] = $file;
    }

    $assert($manifest['version'] === '1.1.5', 'manifest declara versión 1.1.5');
    $assert($manifest['release_id'] === 'ikontrol-1.1.5-canonical-stamp-wallet-resolution', 'manifest declara release ID canónico');
    $assert($manifest['commit_sha'] === $target && strlen($target) === 40, 'manifest exige y conserva commit SHA completo');
    $assert(($byPath['app/Existing.php']['sha256'] ?? '') === hash('sha256', "new\n"), 'SHA-256 destino corresponde al blob 1.1.5');
    $assert(($byPath['app/Existing.php']['from_sha256'] ?? '') === hash('sha256', "old\n"), 'from_sha256 corresponde al blob 1.1.4');
    $assert(array_key_exists('app/New.php', $byPath) && $byPath['app/New.php']['from_sha256'] === null && $byPath['app/New.php']['change'] === 'added', 'archivo nuevo se representa con from_sha256 null');
    $assert(!isset($byPath['.env']) && !isset($byPath['writable/private.key']) && !isset($byPath['tests/NotRuntime.php']), 'archivos protegidos y pruebas quedan excluidos');

    $traversalRejected = false;
    try {
        ReleaseArtifactBuilder::assertSafePath('../escape.php');
    } catch (RuntimeException) {
        $traversalRejected = true;
    }
    $assert($traversalRejected, 'path traversal es rechazado');
    $assert($first['artifact_sha256'] === $second['artifact_sha256'], 'ZIP es reproducible desde el mismo par de commits');
    $checksum = trim((string) file_get_contents($first['checksum_path']));
    $assert(str_starts_with($checksum, hash_file('sha256', $first['artifact_path']) . '  '), 'checksum lateral del artefacto es verificable');

    $invalidSpec = $root . '/invalid-spec.json';
    file_put_contents($invalidSpec, json_encode([
        'version' => '1.1.5', 'release_id' => 'release', 'release_ref' => 'release/1.1.5',
        'commit_sha' => 'HEAD', 'from_version' => '1.1.4', 'from_commit_sha' => $base,
    ]));
    $invalidRejected = false;
    try {
        $builder->build($invalidSpec, $root . '/invalid');
    } catch (RuntimeException $error) {
        $invalidRejected = str_contains($error->getMessage(), 'COMMIT_INVALID');
    }
    $assert($invalidRejected, 'commit SHA simbólico o ausente es rechazado');

    $zip = new ZipArchive();
    $zip->open($first['artifact_path']);
    $manifestEntry = $zip->getFromName('updates/1.1.5/deployment-manifest.json');
    $zip->close();
    $assert(is_string($manifestEntry) && json_decode($manifestEntry, true)['commit_sha'] === $target, 'ZIP contiene deployment-manifest.json validable');
} finally {
    $remove($root);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
