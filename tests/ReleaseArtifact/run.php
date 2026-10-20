<?php

declare(strict_types=1);

use App\Services\Release\ReleaseArtifactBuilder;

require_once dirname(__DIR__, 2) . '/app/Services/Release/ReleaseArtifactBuilder.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS: {$message}\n";
    } else {
        $failed++;
        echo "FAIL: {$message}\n";
    }
};
$all = static function (array $values, callable $predicate): bool {
    foreach ($values as $key => $value) {
        if (!$predicate($value, $key)) {
            return false;
        }
    }
    return true;
};
$run = static function (array $command, string $cwd, array $environment = []): string {
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment === [] ? null : array_merge($_ENV, $environment));
    if (!is_resource($process)) {
        throw new RuntimeException('No fue posible iniciar el proceso de prueba.');
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
        @chmod($path, 0777);
        @rmdir($path);
        return;
    }
    @chmod($path, 0666);
    @unlink($path);
};
$logical = static fn (string $version): string => json_encode([
    'schema_version' => 1,
    'product' => 'ikontrol',
    'version' => $version,
    'channel' => 'stable',
    'from_versions' => ['1.1.4'],
    'migrations' => [],
    'commands' => [],
    'health_checks' => ['ikontrol:database-check', 'ikontrol:baseline-check'],
    'requires_backup' => true,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
$spec = static fn (string $version, string $base): string => json_encode([
    'schema_version' => 1,
    'version' => $version,
    'release_id' => 'ikontrol-' . $version . '-fixture',
    'release_tag' => 'v' . $version,
    'source_refs' => ['1.1.4' => $base],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
$versionSource = static fn (string $version): string => "<?php final class Version { public const VERSION = '{$version}'; public const RELEASE_REF = 'v{$version}'; }\n";

$root = sys_get_temp_dir() . '/ikontrol-deploy-p02-' . bin2hex(random_bytes(5));
$repo = $root . '/repo';
$outA = $root . '/dist-a';
$outB = $root . '/dist-b';
mkdir($repo, 0775, true);

try {
    $run(['git', 'init'], $repo);
    $run(['git', 'config', 'user.email', 'release-test@ikontrol.invalid'], $repo);
    $run(['git', 'config', 'user.name', 'iKontrol Release Test'], $repo);
    $write($repo, 'app/Config/Version.php', $versionSource('1.1.4'));
    $write($repo, 'app/Existing.php', "old\n");
    $write($repo, 'app/ThirdParty/VendorLibrary.php', "vendored\n");
    $write($repo, 'system/Framework.php', "framework\n");
    $write($repo, 'index.php', "index\n");
    $write($repo, 'spark', "spark\n");
    $write($repo, '.ikontrol-template.json', "{}\n");
    $write($repo, '.ikontroladmin-diagnostic-tools.json', "{}\n");
    $write($repo, '.env', "KNOWN_TEST_SECRET=do-not-package\n");
    $write($repo, 'writable/logs/runtime.log', "KNOWN_RUNTIME_SECRET\n");
    $write($repo, 'files/customer.txt', "customer\n");
    $write($repo, 'uploads/customer.txt', "upload\n");
    $write($repo, 'tests/Temporary.php', "test\n");
    $write($repo, 'tools/Temporary.php', "tool\n");
    $write($repo, 'vendor/root.php', "root vendor is not supported\n");
    $run(['git', 'add', '-f', '.'], $repo);
    $run(['git', 'commit', '-m', 'base'], $repo, ['GIT_AUTHOR_DATE' => '2026-10-07T12:00:00Z', 'GIT_COMMITTER_DATE' => '2026-10-07T12:00:00Z']);
    $base = $run(['git', 'rev-parse', 'HEAD'], $repo);

    $write($repo, 'app/Config/Version.php', $versionSource('1.1.5'));
    $write($repo, 'app/Existing.php', "new\n");
    $write($repo, 'app/New.php', "added\n");
    $write($repo, 'app/Commands/IkontrolReleaseBuild.php', "<?php // build only\n");
    $write($repo, 'app/Services/Release/Builder.php', "<?php // build only\n");
    $write($repo, 'updates/1.1.5/manifest.json', $logical('1.1.5'));
    $write($repo, 'tools/release/releases/1.1.5.json', $spec('1.1.5', $base));
    $run(['git', 'add', '-f', '.'], $repo);
    $run(['git', 'commit', '-m', 'target'], $repo, ['GIT_AUTHOR_DATE' => '2026-10-08T12:00:00Z', 'GIT_COMMITTER_DATE' => '2026-10-08T12:00:00Z']);
    $target = $run(['git', 'rev-parse', 'HEAD'], $repo);

    $builder = new ReleaseArtifactBuilder($repo);
    $missingTagRejected = false;
    try {
        $builder->build('1.1.5', $root . '/missing-tag');
    } catch (RuntimeException $error) {
        $missingTagRejected = str_contains($error->getMessage(), 'RELEASE_TAG_MISSING');
    }
    $assert($missingTagRejected, 'tag declarado es obligatorio para un build formal');
    $run(['git', 'tag', 'v1.1.5', $base], $repo);
    $mismatchedTagRejected = false;
    try {
        $builder->build('1.1.5', $root . '/mismatched-tag');
    } catch (RuntimeException $error) {
        $mismatchedTagRejected = str_contains($error->getMessage(), 'RELEASE_TAG_COMMIT_MISMATCH');
    }
    $assert($mismatchedTagRejected, 'tag declarado resuelve al commit empaquetado');
    $run(['git', 'tag', '-f', 'v1.1.5', $target], $repo);
    $first = $builder->build('1.1.5', $outA);
    $second = $builder->build('1.1.5', $outB);
    $manifestA = json_decode((string) file_get_contents($first['deployment_manifest']), true, 64, JSON_THROW_ON_ERROR);
    $manifestB = json_decode((string) file_get_contents($second['deployment_manifest']), true, 64, JSON_THROW_ON_ERROR);
    $byPath = [];
    foreach ($manifestA['files'] as $file) {
        $byPath[$file['path']] = $file;
    }

    $assert($first['status'] === 'BUILT' && $manifestA['version'] === '1.1.5', '1. build acepta SemVer válida');
    $invalidVersion = false;
    try {
        $builder->build('1.1', $root . '/invalid-version');
    } catch (RuntimeException $error) {
        $invalidVersion = str_contains($error->getMessage(), 'VERSION_INVALID');
    }
    $assert($invalidVersion, '2. build rechaza SemVer inválida');

    file_put_contents($repo . '/app/Existing.php', "dirty\n");
    $dirtyRejected = false;
    try {
        $builder->build('1.1.5', $root . '/dirty');
    } catch (RuntimeException $error) {
        $dirtyRejected = str_contains($error->getMessage(), 'NOT_CLEAN');
    }
    $run(['git', 'checkout', '--', 'app/Existing.php'], $repo);
    $assert($dirtyRejected, '3. build oficial rechaza working tree sucio');
    $assert(!isset($byPath['.env']), '4. .env queda excluido');
    $assert(!isset($byPath['writable/logs/runtime.log'], $byPath['files/customer.txt'], $byPath['uploads/customer.txt'], $byPath['app/Commands/IkontrolReleaseBuild.php'], $byPath['app/Services/Release/Builder.php']), '5. writable, archivos runtime y tooling del builder quedan excluidos');

    $unsafe = false;
    try {
        ReleaseArtifactBuilder::assertSafePath('../outside.php');
    } catch (RuntimeException) {
        $unsafe = true;
    }
    $assert($unsafe && $all($manifestA['files'], static fn (array $file): bool => !str_starts_with($file['path'], '/') && !str_contains($file['path'], '..')), '6. no existen paths absolutos ni traversal');

    $zip = new ZipArchive();
    $zip->open($first['artifact']);
    $zipPayload = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name !== 'updates/1.1.5/deployment-manifest.json') {
            $zipPayload[] = $name;
        }
    }
    sort($zipPayload, SORT_STRING);
    $manifestPaths = array_column($manifestA['files'], 'path');
    $zip->close();
    $assert($zipPayload === $manifestPaths, '7. manifest contiene todos y sólo los archivos de payload del ZIP');
    $assert(($byPath['app/Existing.php']['sha256'] ?? '') === hash('sha256', "new\n") && ($byPath['app/Existing.php']['from_sha256']['1.1.4'] ?? '') === hash('sha256', "old\n"), '8. hashes destino y origen por versión son correctos');
    $assert(hash_file('sha256', $first['artifact']) === $first['artifact_sha256'], '9. SHA-256 final del artifact es correcto');

    $tampered = $root . '/tampered.zip';
    copy($first['artifact'], $tampered);
    $handle = fopen($tampered, 'r+b');
    fseek($handle, -8, SEEK_END);
    $byte = fread($handle, 1);
    fseek($handle, -1, SEEK_CUR);
    fwrite($handle, chr(ord($byte) ^ 1));
    fclose($handle);
    $tamperRejected = false;
    try {
        $builder->validateArtifact($tampered, $first['artifact_sha256']);
    } catch (RuntimeException $error) {
        $tamperRejected = str_contains($error->getMessage(), 'HASH_MISMATCH');
    }
    $assert($tamperRejected, '10. modificación de un byte rompe validación');

    $write($repo, 'app/Config/Version.php', $versionSource('1.1.6'));
    $write($repo, 'tools/release/releases/1.1.6.json', $spec('1.1.6', $base));
    $run(['git', 'add', '-f', '.'], $repo);
    $run(['git', 'commit', '-m', 'missing logical manifest'], $repo);
    $missingRejected = false;
    try {
        $builder->build('1.1.6', $root . '/missing');
    } catch (RuntimeException $error) {
        $missingRejected = str_contains($error->getMessage(), 'REQUIRED_FILE_MISSING');
    }
    $assert($missingRejected, '11. build falla si falta updates/{version}/manifest.json');

    $write($repo, 'updates/1.1.6/manifest.json', $logical('9.9.9'));
    $run(['git', 'add', '-f', '.'], $repo);
    $run(['git', 'commit', '-m', 'mismatched logical manifest'], $repo);
    $mismatchRejected = false;
    try {
        $builder->build('1.1.6', $root . '/mismatch');
    } catch (RuntimeException $error) {
        $mismatchRejected = str_contains($error->getMessage(), 'LOGICAL_MANIFEST_INVALID');
    }
    $assert($mismatchRejected, '12. build falla si versión del manifest lógico no coincide');

    $artifactBytes = (string) file_get_contents($first['artifact']);
    $assert(!str_contains($artifactBytes, 'KNOWN_TEST_SECRET') && !str_contains($artifactBytes, 'KNOWN_RUNTIME_SECRET'), '13. artifact no contiene secretos conocidos del fixture');
    $sorted = $manifestPaths;
    sort($sorted, SORT_STRING);
    $assert($manifestPaths === $sorted, '14. deployment manifest conserva orden determinista');
    $assert($manifestA === $manifestB, '15. dos builds producen exactamente el mismo deployment manifest');
    $assert($first['artifact_sha256'] === $second['artifact_sha256'], '16. dos builds producen ZIP byte-for-byte reproducible');

    $topKeys = array_keys($manifestA);
    $validTop = $topKeys === ['schema_version', 'product', 'version', 'release_id', 'commit_sha', 'files'];
    $validFiles = $all($manifestA['files'], static function (array $file) use ($all): bool {
        $unknown = array_diff(array_keys($file), ['path', 'sha256', 'size', 'from_sha256']);
        return $unknown === [] && is_string($file['path']) && is_int($file['size']) && preg_match('/\A[a-f0-9]{64}\z/', $file['sha256']) === 1 && (!isset($file['from_sha256']) || (!array_is_list($file['from_sha256']) && $all($file['from_sha256'], static fn ($sha): bool => is_string($sha) && preg_match('/\A[a-f0-9]{64}\z/', $sha) === 1)));
    });
    $assert($validTop && $validFiles && isset($byPath['spark'], $byPath['index.php']) && $manifestA['commit_sha'] === $target && !isset($byPath['app/ThirdParty/VendorLibrary.php'], $byPath['system/Framework.php']), '17. artifact delta cumple el contrato de ReleaseArtifactService y omite archivos sin cambios');

    $run(['git', 'checkout', '--detach', $target], $repo);
    $wrongSourceSpec = $root . '/wrong-source.json';
    file_put_contents($wrongSourceSpec, $spec('1.1.5', $target));
    $wrongSourceRejected = false;
    try {
        $builder->build('1.1.5', $root . '/wrong-source', false, $wrongSourceSpec);
    } catch (RuntimeException $error) {
        $wrongSourceRejected = str_contains($error->getMessage(), 'RELEASE_SOURCE_VERSION_MISMATCH');
    }
    $assert($wrongSourceRejected, 'source_refs declara un commit de la versión origen');

    $run(['git', 'checkout', '--detach', $target], $repo);
    $run(['git', 'rm', 'app/Existing.php'], $repo);
    $run(['git', 'commit', '-m', 'removed runtime fixture'], $repo);
    $run(['git', 'tag', '-f', 'v1.1.5'], $repo);
    $removalRejected = false;
    try {
        $builder->build('1.1.5', $root . '/removed');
    } catch (RuntimeException $error) {
        $removalRejected = str_contains($error->getMessage(), 'RELEASE_REMOVED_FILE_UNSUPPORTED');
    }
    $assert($removalRejected, 'eliminaciones de runtime fallan porque Admin no borra archivos');

    $run(['git', 'checkout', '--detach', $target], $repo);
    $write($repo, 'app/private.key', "private\n");
    $run(['git', 'add', '-f', 'app/private.key'], $repo);
    $run(['git', 'commit', '-m', 'private material fixture'], $repo);
    $run(['git', 'tag', '-f', 'v1.1.5'], $repo);
    $privateRejected = false;
    try {
        $builder->build('1.1.5', $root . '/private');
    } catch (RuntimeException $error) {
        $privateRejected = str_contains($error->getMessage(), 'PRIVATE_MATERIAL_FORBIDDEN');
    }
    $assert($privateRejected, '18. material privado dentro de la allowlist hace fallar el build');

    $run(['git', 'checkout', '--detach', $target], $repo);
    $blob = $run(['git', 'hash-object', '-w', 'app/Existing.php'], $repo);
    $run(['git', 'update-index', '--add', '--cacheinfo', '120000,' . $blob . ',app/danger-link'], $repo);
    $run(['git', 'commit', '-m', 'symlink fixture'], $repo);
    $run(['git', 'tag', '-f', 'v1.1.5'], $repo);
    $symlinkRejected = false;
    try {
        $builder->build('1.1.5', $root . '/symlink', true);
    } catch (RuntimeException $error) {
        $symlinkRejected = str_contains($error->getMessage(), 'SYMLINK_FORBIDDEN');
    }
    $assert($symlinkRejected, '19. symlink dentro de la allowlist hace fallar el build');
    echo 'fixture_artifact_sha256=' . $first['artifact_sha256'] . "\n";
} finally {
    $remove($root);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
