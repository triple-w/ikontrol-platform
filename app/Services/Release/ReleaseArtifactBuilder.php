<?php

declare(strict_types=1);

namespace App\Services\Release;

use JsonException;
use RuntimeException;
use ZipArchive;

final class ReleaseArtifactBuilder
{
    public const BUILDER_SCHEMA = 1;
    public const BUILDER_VERSION = '1.0.0';

    private const VERSION_PATTERN = '/\A\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?(?:\+[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?\z/';
    private const LOGICAL_FIELDS = ['schema_version', 'product', 'version', 'channel', 'from_versions', 'migrations', 'commands', 'health_checks', 'requires_backup'];
    private const PAYLOAD_PREFIXES = ['app/', 'assets/', 'plugins/', 'public/', 'resources/', 'system/', 'updates/'];
    private const PAYLOAD_ROOT_FILES = ['index.php', 'spark', '.ikontrol-template.json', '.ikontroladmin-diagnostic-tools.json'];
    private const BUILD_ONLY_PATHS = ['app/Commands/IkontrolReleaseBuild.php', 'app/Services/Release/'];
    private const PROTECTED_PREFIXES = ['.git/', '.github/', 'backups/', 'dist/', 'docs/', 'documentation/', 'files/', 'logs/', 'node_modules/', 'sessions/', 'tests/', 'tools/', 'uploads/', 'vendor/', 'writable/'];
    private const PRIVATE_EXTENSIONS = ['cer', 'key', 'pem', 'pfx', 'p12', 'jks'];
    private const MAX_MANIFEST_BYTES = 262144;

    public function __construct(private readonly string $repositoryRoot)
    {
        $git = $this->repositoryRoot . DIRECTORY_SEPARATOR . '.git';
        if (!is_dir($git) && !is_file($git)) {
            throw new RuntimeException('RELEASE_REPOSITORY_INVALID');
        }
    }

    /** @return array<string, mixed> */
    public function build(string $version, string $outputDirectory, bool $allowDirty = false, ?string $specPath = null): array
    {
        $this->assertVersion($version);
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('RELEASE_ZIP_EXTENSION_REQUIRED');
        }
        $clean = $this->workingTreeClean();
        if (!$clean && !$allowDirty) {
            throw new RuntimeException('RELEASE_CHECKOUT_NOT_CLEAN');
        }
        $commit = $this->git(['rev-parse', 'HEAD'], true);
        if (preg_match('/\A[a-f0-9]{40}\z/', $commit) !== 1) {
            throw new RuntimeException('RELEASE_COMMIT_INVALID');
        }

        $specPath ??= $this->repositoryRoot . '/tools/release/releases/' . $version . '.json';
        $spec = $this->loadBuildSpec($specPath, $version);
        $logicalPath = 'updates/' . $version . '/manifest.json';
        $logicalBytes = $this->blob($commit, $logicalPath);
        $logical = $this->validateLogicalManifest($logicalBytes, $version);
        $this->assertBuildSpecMatchesManifest($spec, $logical);
        $this->assertVersionSource($commit, $version, $spec['release_tag']);
        $this->assertReleaseTag($spec['release_tag'], $commit);

        $targetTree = $this->tree($commit);
        $sourceTrees = [];
        foreach ($logical['from_versions'] as $sourceVersion) {
            $sourceRef = $spec['source_refs'][$sourceVersion] ?? null;
            if (!is_string($sourceRef) || preg_match('/\A[a-f0-9]{40}\z/', $sourceRef) !== 1) {
                throw new RuntimeException('RELEASE_SOURCE_REF_REQUIRED:' . $sourceVersion);
            }
            $this->assertCommit($sourceRef);
            $this->assertSourceVersion($sourceRef, $sourceVersion);
            $sourceTree = $this->tree($sourceRef);
            foreach ($sourceTree as $path => $_entry) {
                if ($this->isPayloadPath($path, $version) && !isset($targetTree[$path])) {
                    throw new RuntimeException('RELEASE_REMOVED_FILE_UNSUPPORTED:' . $path);
                }
            }
            $sourceTrees[$sourceVersion] = $sourceTree;
        }

        $payloadTree = [];
        foreach ($targetTree as $path => $entry) {
            if (!$this->isPayloadPath($path, $version)) {
                continue;
            }
            if ($entry['mode'] === '120000') {
                throw new RuntimeException('RELEASE_SYMLINK_FORBIDDEN:' . $path);
            }
            $this->assertNoPrivateMaterial($path);
            $changed = in_array($path, self::PAYLOAD_ROOT_FILES, true);
            foreach ($sourceTrees as $sourceTree) {
                if (!isset($sourceTree[$path]) || $sourceTree[$path]['mode'] !== $entry['mode'] || $sourceTree[$path]['object'] !== $entry['object']) {
                    $changed = true;
                    break;
                }
            }
            if ($changed) {
                $payloadTree[$path] = $entry;
            }
        }
        ksort($payloadTree, SORT_STRING);
        if (!isset($payloadTree['spark'], $payloadTree['index.php'])) {
            throw new RuntimeException('RELEASE_MINIMUM_APPLICATION_MISSING');
        }

        $payload = $this->readTreeBlobs($payloadTree);
        $sourceBlobs = [];
        foreach ($sourceTrees as $sourceVersion => $sourceTree) {
            $needed = [];
            foreach ($payloadTree as $path => $_entry) {
                if (isset($sourceTree[$path]) && $sourceTree[$path]['mode'] !== '120000') {
                    $needed[$path] = $sourceTree[$path];
                }
            }
            $sourceBlobs[$sourceVersion] = $this->readTreeBlobs($needed);
        }

        $files = [];
        $uncompressedBytes = 0;
        foreach ($payload as $path => $contents) {
            $file = ['path' => $path, 'sha256' => hash('sha256', $contents), 'size' => strlen($contents)];
            $from = [];
            foreach ($logical['from_versions'] as $sourceVersion) {
                if (isset($sourceBlobs[$sourceVersion][$path])) {
                    $from[$sourceVersion] = hash('sha256', $sourceBlobs[$sourceVersion][$path]);
                }
            }
            if ($from !== []) {
                $file['from_sha256'] = $from;
            }
            $files[] = $file;
            $uncompressedBytes += strlen($contents);
        }

        $deploymentManifest = [
            'schema_version' => 1,
            'product' => 'ikontrol',
            'version' => $version,
            'release_id' => $spec['release_id'],
            'commit_sha' => $commit,
            'files' => $files,
        ];
        $deploymentJson = $this->encode($deploymentManifest);
        if (strlen($deploymentJson) > self::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('RELEASE_DEPLOYMENT_MANIFEST_TOO_LARGE');
        }
        $manifestRelativePath = 'updates/' . $version . '/deployment-manifest.json';
        self::assertSafePath($manifestRelativePath);

        $outputDirectory = rtrim($outputDirectory, '/\\');
        $this->ensureDirectory($outputDirectory);
        $artifactName = 'ikontrol-platform-' . $version . '.zip';
        $artifactPath = $outputDirectory . DIRECTORY_SEPARATOR . $artifactName;
        $checksumPath = $outputDirectory . DIRECTORY_SEPARATOR . 'ikontrol-platform-' . $version . '.sha256';
        $deploymentPath = $outputDirectory . DIRECTORY_SEPARATOR . 'deployment-manifest.json';
        $buildPath = $outputDirectory . DIRECTORY_SEPARATOR . 'release-build.json';
        foreach ([$artifactPath, $checksumPath, $deploymentPath, $buildPath] as $path) {
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('RELEASE_OUTPUT_REPLACE_FAILED:' . basename($path));
            }
        }
        if (file_put_contents($deploymentPath, $deploymentJson) === false) {
            throw new RuntimeException('RELEASE_DEPLOYMENT_MANIFEST_WRITE_FAILED');
        }

        $zip = new ZipArchive();
        if ($zip->open($artifactPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('RELEASE_ARTIFACT_OPEN_FAILED');
        }
        $timestamp = max(315532800, (int) $this->git(['show', '-s', '--format=%ct', $commit], true));
        foreach ($payload as $path => $contents) {
            $this->addZipEntry($zip, $path, $contents, $timestamp);
        }
        $this->addZipEntry($zip, $manifestRelativePath, $deploymentJson, $timestamp);
        if (!$zip->close()) {
            throw new RuntimeException('RELEASE_ARTIFACT_CLOSE_FAILED');
        }

        $artifactSha = hash_file('sha256', $artifactPath);
        if (!is_string($artifactSha)) {
            throw new RuntimeException('RELEASE_ARTIFACT_HASH_FAILED');
        }
        if (file_put_contents($checksumPath, $artifactSha . '  ' . $artifactName . "\n") === false) {
            throw new RuntimeException('RELEASE_CHECKSUM_WRITE_FAILED');
        }

        $build = [
            'schema' => self::BUILDER_SCHEMA,
            'builder_version' => self::BUILDER_VERSION,
            'version' => $version,
            'release_id' => $spec['release_id'],
            'release_tag' => $spec['release_tag'],
            'commit_sha' => $commit,
            'built_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'artifact' => $artifactName,
            'artifact_sha256' => $artifactSha,
            'logical_manifest_sha256' => hash('sha256', $logicalBytes),
            'file_count' => count($files),
            'uncompressed_bytes' => $uncompressedBytes,
            'working_tree_clean' => $clean,
        ];
        if (file_put_contents($buildPath, $this->encode($build)) === false) {
            throw new RuntimeException('RELEASE_BUILD_METADATA_WRITE_FAILED');
        }
        $validation = $this->validateArtifact($artifactPath, $artifactSha);

        return [
            'status' => 'BUILT',
            'artifact' => $artifactPath,
            'artifact_sha256' => $artifactSha,
            'checksum' => $checksumPath,
            'deployment_manifest' => $deploymentPath,
            'release_build' => $buildPath,
            'file_count' => count($files),
            'uncompressed_bytes' => $uncompressedBytes,
            'validation' => $validation,
        ];
    }

    /** @return array<string, mixed> */
    public function validateArtifact(string $artifactPath, ?string $expectedArtifactSha256 = null): array
    {
        if ($expectedArtifactSha256 !== null) {
            if (preg_match('/\A[a-f0-9]{64}\z/i', $expectedArtifactSha256) !== 1) {
                throw new RuntimeException('RELEASE_EXPECTED_HASH_INVALID');
            }
            $actual = hash_file('sha256', $artifactPath);
            if (!is_string($actual) || !hash_equals(strtolower($expectedArtifactSha256), strtolower($actual))) {
                throw new RuntimeException('RELEASE_ARTIFACT_HASH_MISMATCH');
            }
        }

        $zip = new ZipArchive();
        if ($zip->open($artifactPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('RELEASE_ARTIFACT_INVALID');
        }
        try {
            $entries = [];
            $manifestCandidates = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $path = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                self::assertSafePath(rtrim($path, '/'));
                $key = strtolower(rtrim($path, '/'));
                if (isset($entries[$key])) {
                    throw new RuntimeException('RELEASE_ARTIFACT_DUPLICATE_PATH:' . $path);
                }
                $zip->getExternalAttributesIndex($index, $opsys, $attributes);
                if ($opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('RELEASE_ARTIFACT_SYMLINK_FORBIDDEN:' . $path);
                }
                $directory = str_ends_with($path, '/');
                $entries[$key] = ['path' => rtrim($path, '/'), 'index' => $index, 'directory' => $directory];
                if (!$directory && preg_match('#\Aupdates/([^/]+)/deployment-manifest\.json\z#', $path) === 1) {
                    $manifestCandidates[] = $entries[$key];
                }
            }
            if (count($manifestCandidates) !== 1) {
                throw new RuntimeException('RELEASE_DEPLOYMENT_MANIFEST_COUNT_INVALID');
            }
            $manifestBytes = $zip->getFromIndex($manifestCandidates[0]['index']);
            if (!is_string($manifestBytes)) {
                throw new RuntimeException('RELEASE_DEPLOYMENT_MANIFEST_READ_FAILED');
            }
            $manifest = json_decode($manifestBytes, true, 64, JSON_THROW_ON_ERROR);
            $this->validateDeploymentManifest($manifest);

            $expected = [];
            foreach ($manifest['files'] as $file) {
                $key = strtolower($file['path']);
                if (isset($expected[$key])) {
                    throw new RuntimeException('RELEASE_DEPLOYMENT_MANIFEST_DUPLICATE_PATH:' . $file['path']);
                }
                $expected[$key] = $file;
            }
            foreach ($entries as $key => $entry) {
                if ($entry['directory'] || $entry['path'] === $manifestCandidates[0]['path']) {
                    continue;
                }
                if (!isset($expected[$key])) {
                    throw new RuntimeException('RELEASE_ARTIFACT_UNDECLARED_FILE:' . $entry['path']);
                }
                $contents = $zip->getFromIndex($entry['index']);
                $file = $expected[$key];
                if (!is_string($contents) || strlen($contents) !== $file['size'] || !hash_equals($file['sha256'], hash('sha256', $contents))) {
                    throw new RuntimeException('RELEASE_ARTIFACT_FILE_MISMATCH:' . $entry['path']);
                }
                unset($expected[$key]);
            }
            if ($expected !== []) {
                $missing = reset($expected);
                throw new RuntimeException('RELEASE_ARTIFACT_MISSING_DECLARED_FILE:' . $missing['path']);
            }
            return ['status' => 'VALID', 'version' => $manifest['version'], 'commit_sha' => $manifest['commit_sha'], 'file_count' => count($manifest['files'])];
        } catch (JsonException) {
            throw new RuntimeException('RELEASE_DEPLOYMENT_MANIFEST_JSON_INVALID');
        } finally {
            $zip->close();
        }
    }

    public static function assertSafePath(string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:/', $path) === 1) {
            throw new RuntimeException('RELEASE_PATH_INVALID:' . $path);
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('RELEASE_PATH_TRAVERSAL:' . $path);
            }
        }
    }

    private function workingTreeClean(): bool
    {
        return $this->git(['status', '--porcelain=v1', '--untracked-files=all']) === '';
    }

    /** @return array<string, mixed> */
    private function loadBuildSpec(string $path, string $version): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('RELEASE_BUILD_SPEC_MISSING:' . $version);
        }
        try {
            $spec = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('RELEASE_BUILD_SPEC_JSON_INVALID');
        }
        $allowed = ['schema_version', 'version', 'release_id', 'release_tag', 'source_refs'];
        if (!is_array($spec) || array_is_list($spec) || array_diff(array_keys($spec), $allowed) !== [] || array_diff($allowed, array_keys($spec)) !== []) {
            throw new RuntimeException('RELEASE_BUILD_SPEC_FIELDS_INVALID');
        }
        if ($spec['schema_version'] !== 1 || $spec['version'] !== $version || !is_string($spec['release_id']) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,159}\z/', $spec['release_id']) !== 1 || $spec['release_tag'] !== 'v' . $version || !is_array($spec['source_refs']) || array_is_list($spec['source_refs'])) {
            throw new RuntimeException('RELEASE_BUILD_SPEC_INVALID');
        }
        return $spec;
    }

    /** @return array<string, mixed> */
    private function validateLogicalManifest(string $json, string $version): array
    {
        if ($json === '' || strlen($json) > self::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('RELEASE_LOGICAL_MANIFEST_SIZE_INVALID');
        }
        try {
            $manifest = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('RELEASE_LOGICAL_MANIFEST_JSON_INVALID');
        }
        if (!is_array($manifest) || array_is_list($manifest) || array_diff(array_keys($manifest), self::LOGICAL_FIELDS) !== [] || array_diff(self::LOGICAL_FIELDS, array_keys($manifest)) !== []) {
            throw new RuntimeException('RELEASE_LOGICAL_MANIFEST_FIELDS_INVALID');
        }
        if ($manifest['schema_version'] !== 1 || $manifest['product'] !== 'ikontrol' || $manifest['version'] !== $version || !in_array($manifest['channel'], ['canary', 'stable'], true) || !is_bool($manifest['requires_backup'])) {
            throw new RuntimeException('RELEASE_LOGICAL_MANIFEST_INVALID');
        }
        if ($manifest['channel'] === 'stable' && str_contains($version, '-')) {
            throw new RuntimeException('RELEASE_STABLE_PRERELEASE_INVALID');
        }
        foreach (['from_versions', 'migrations', 'commands', 'health_checks'] as $field) {
            if (!is_array($manifest[$field]) || !array_is_list($manifest[$field])) {
                throw new RuntimeException('RELEASE_LOGICAL_MANIFEST_LIST_INVALID:' . $field);
            }
        }
        if ($manifest['from_versions'] === [] || count($manifest['from_versions']) !== count(array_unique($manifest['from_versions']))) {
            throw new RuntimeException('RELEASE_FROM_VERSIONS_INVALID');
        }
        foreach ($manifest['from_versions'] as $sourceVersion) {
            $this->assertVersion($sourceVersion);
            if (version_compare($sourceVersion, $version, '>=')) {
                throw new RuntimeException('RELEASE_FROM_VERSION_NOT_OLDER:' . $sourceVersion);
            }
        }
        $migrationIds = [];
        foreach ($manifest['migrations'] as $migration) {
            if (!is_array($migration) || array_is_list($migration) || array_diff(array_keys($migration), ['id', 'required']) !== [] || array_diff(['id', 'required'], array_keys($migration)) !== [] || !is_string($migration['id']) || preg_match('/\A[0-9A-Za-z][0-9A-Za-z_-]{0,199}\z/', $migration['id']) !== 1 || !is_bool($migration['required']) || isset($migrationIds[$migration['id']])) {
                throw new RuntimeException('RELEASE_MIGRATION_DECLARATION_INVALID');
            }
            $migrationIds[$migration['id']] = true;
        }
        foreach (['commands', 'health_checks'] as $field) {
            foreach ($manifest[$field] as $command) {
                if (!is_string($command) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9:_-]{0,99}\z/', $command) !== 1) {
                    throw new RuntimeException('RELEASE_COMMAND_DECLARATION_INVALID:' . $field);
                }
            }
        }
        $this->rejectSecretKeys($manifest);
        return $manifest;
    }

    /** @param array<string, mixed> $spec @param array<string, mixed> $logical */
    private function assertBuildSpecMatchesManifest(array $spec, array $logical): void
    {
        $expected = $logical['from_versions'];
        $actual = array_keys($spec['source_refs']);
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($expected !== $actual) {
            throw new RuntimeException('RELEASE_SOURCE_REFS_MISMATCH');
        }
    }

    /** @param array<string, mixed> $manifest */
    private function validateDeploymentManifest(array $manifest): void
    {
        $required = ['schema_version', 'product', 'version', 'release_id', 'commit_sha', 'files'];
        if (array_diff(array_keys($manifest), $required) !== [] || array_diff($required, array_keys($manifest)) !== [] || $manifest['schema_version'] !== 1 || $manifest['product'] !== 'ikontrol') {
            throw new RuntimeException('RELEASE_DEPLOYMENT_MANIFEST_INVALID');
        }
        $this->assertVersion($manifest['version']);
        if (!is_string($manifest['release_id']) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,159}\z/', $manifest['release_id']) !== 1 || !is_string($manifest['commit_sha']) || preg_match('/\A[a-f0-9]{40}\z/i', $manifest['commit_sha']) !== 1 || !is_array($manifest['files']) || !array_is_list($manifest['files'])) {
            throw new RuntimeException('RELEASE_DEPLOYMENT_MANIFEST_METADATA_INVALID');
        }
        $seen = [];
        foreach ($manifest['files'] as $file) {
            if (!is_array($file) || array_is_list($file) || array_diff(array_keys($file), ['path', 'sha256', 'size', 'from_sha256']) !== [] || !is_string($file['path'] ?? null)) {
                throw new RuntimeException('RELEASE_DEPLOYMENT_FILE_INVALID');
            }
            self::assertSafePath($file['path']);
            if (!$this->isPayloadPath($file['path'], $manifest['version']) || preg_match('/\A[a-f0-9]{64}\z/', (string) ($file['sha256'] ?? '')) !== 1 || !is_int($file['size'] ?? null) || $file['size'] < 0 || isset($seen[strtolower($file['path'])])) {
                throw new RuntimeException('RELEASE_DEPLOYMENT_FILE_INVALID:' . $file['path']);
            }
            if (isset($file['from_sha256'])) {
                if (!is_array($file['from_sha256']) || array_is_list($file['from_sha256'])) {
                    throw new RuntimeException('RELEASE_FROM_HASHES_INVALID:' . $file['path']);
                }
                foreach ($file['from_sha256'] as $sourceVersion => $sha) {
                    $this->assertVersion($sourceVersion);
                    if (!is_string($sha) || preg_match('/\A[a-f0-9]{64}\z/', $sha) !== 1) {
                        throw new RuntimeException('RELEASE_FROM_HASH_INVALID:' . $file['path']);
                    }
                }
            }
            $seen[strtolower($file['path'])] = true;
        }
        if (!isset($seen['spark'], $seen['index.php'])) {
            throw new RuntimeException('RELEASE_MINIMUM_APPLICATION_MISSING');
        }
    }

    private function assertVersionSource(string $commit, string $version, string $releaseTag): void
    {
        $source = $this->blob($commit, 'app/Config/Version.php');
        if (preg_match("/const VERSION = ['\"]" . preg_quote($version, '/') . "['\"]/", $source) !== 1
            || preg_match("/const RELEASE_REF = ['\"]" . preg_quote($releaseTag, '/') . "['\"]/", $source) !== 1) {
            throw new RuntimeException('RELEASE_VERSION_SOURCE_MISMATCH');
        }
    }

    private function assertReleaseTag(string $releaseTag, string $commit): void
    {
        [$code, $resolved] = $this->run([
            'git',
            '-C',
            $this->repositoryRoot,
            'rev-parse',
            '--verify',
            'refs/tags/' . $releaseTag . '^{commit}',
        ]);
        if ($code !== 0) {
            throw new RuntimeException('RELEASE_TAG_MISSING:' . $releaseTag);
        }
        if (!hash_equals($commit, trim($resolved))) {
            throw new RuntimeException('RELEASE_TAG_COMMIT_MISMATCH:' . $releaseTag);
        }
    }

    private function assertSourceVersion(string $commit, string $version): void
    {
        $source = $this->blob($commit, 'app/Config/Version.php');
        if (preg_match("/const VERSION = ['\"]" . preg_quote($version, '/') . "['\"]/", $source) !== 1) {
            throw new RuntimeException('RELEASE_SOURCE_VERSION_MISMATCH:' . $version);
        }
    }
    private function assertVersion(mixed $version): void
    {
        if (!is_string($version) || strlen($version) > 80 || preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw new RuntimeException('RELEASE_VERSION_INVALID');
        }
    }

    /** @return array<string, array{mode:string,type:string,object:string}> */
    private function tree(string $commit): array
    {
        $raw = $this->git(['ls-tree', '-r', '-z', '--full-tree', $commit]);
        $tree = [];
        foreach (explode("\0", rtrim($raw, "\0")) as $record) {
            if (!preg_match('/\A(\d{6}) ([a-z]+) ([a-f0-9]{40})\t(.+)\z/s', $record, $matches)) {
                throw new RuntimeException('RELEASE_GIT_TREE_INVALID');
            }
            $path = str_replace('\\', '/', $matches[4]);
            self::assertSafePath($path);
            $tree[$path] = ['mode' => $matches[1], 'type' => $matches[2], 'object' => $matches[3]];
        }
        return $tree;
    }

    /** @param array<string, array{mode:string,type:string,object:string}> $tree @return array<string, string> */
    private function readTreeBlobs(array $tree): array
    {
        if ($tree === []) {
            return [];
        }
        $pipes = [];
        $process = proc_open(['git', '-C', $this->repositoryRoot, 'cat-file', '--batch'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('RELEASE_GIT_BATCH_START_FAILED');
        }
        $result = [];
        $caught = null;
        try {
            foreach ($tree as $path => $entry) {
                fwrite($pipes[0], $entry['object'] . "\n");
                fflush($pipes[0]);
                $header = fgets($pipes[1]);
                if (!is_string($header) || preg_match('/\A([a-f0-9]{40}) blob (\d+)\n\z/', $header, $matches) !== 1 || !hash_equals($entry['object'], $matches[1])) {
                    throw new RuntimeException('RELEASE_GIT_BLOB_HEADER_INVALID:' . $path);
                }
                $size = (int) $matches[2];
                $contents = '';
                while (strlen($contents) < $size) {
                    $chunk = fread($pipes[1], $size - strlen($contents));
                    if (!is_string($chunk) || $chunk === '') {
                        throw new RuntimeException('RELEASE_GIT_BLOB_READ_FAILED:' . $path);
                    }
                    $contents .= $chunk;
                }
                if (fread($pipes[1], 1) !== "\n") {
                    throw new RuntimeException('RELEASE_GIT_BLOB_TERMINATOR_INVALID:' . $path);
                }
                $result[$path] = $contents;
            }
        } catch (\Throwable $error) {
            $caught = $error;
        } finally {
            fclose($pipes[0]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if ($caught !== null) {
                throw $caught;
            }
            if ($exit !== 0) {
                throw new RuntimeException('RELEASE_GIT_BATCH_FAILED:' . trim((string) $stderr));
            }
        }
        return $result;
    }

    private function isPayloadPath(string $path, string $version): bool
    {
        self::assertSafePath($path);
        $lower = strtolower($path);
        if ($lower === '.env' || str_starts_with($lower, '.env.') || $lower === 'updates/' . strtolower($version) . '/deployment-manifest.json' || str_contains($lower, 'bootstrap-receipt')) {
            return false;
        }
        foreach (self::BUILD_ONLY_PATHS as $buildOnly) {
            $buildOnly = strtolower($buildOnly);
            if ($lower === rtrim($buildOnly, '/') || str_starts_with($lower, $buildOnly)) {
                return false;
            }
        }
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return false;
            }
        }
        if (in_array($path, self::PAYLOAD_ROOT_FILES, true)) {
            return true;
        }
        foreach (self::PAYLOAD_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function assertNoPrivateMaterial(string $path): void
    {
        if (preg_match('/\A[a-zA-Z0-9._-]+(?:\/[a-zA-Z0-9._ -]+)*\z/', $path) !== 1) {
            throw new RuntimeException('RELEASE_ADMIN_PATH_INCOMPATIBLE:' . $path);
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, self::PRIVATE_EXTENSIONS, true) || preg_match('#(^|/)(id_rsa|id_ed25519|credentials?\.json)$#i', $path) === 1) {
            throw new RuntimeException('RELEASE_PRIVATE_MATERIAL_FORBIDDEN:' . $path);
        }
    }

    /** @param array<string, mixed> $data */
    private function rejectSecretKeys(array $data): void
    {
        foreach ($data as $key => $value) {
            if (preg_match('/password|passwd|secret|token|credential|private[_-]?key|api[_-]?key/i', (string) $key) === 1) {
                throw new RuntimeException('RELEASE_LOGICAL_MANIFEST_SECRET_KEY');
            }
            if (is_array($value)) {
                $this->rejectSecretKeys($value);
            }
        }
    }

    private function assertCommit(string $commit): void
    {
        $this->git(['cat-file', '-e', $commit . '^{commit}']);
    }

    private function blob(string $commit, string $path): string
    {
        self::assertSafePath($path);
        [$code, $output] = $this->run(['git', '-C', $this->repositoryRoot, 'show', $commit . ':' . $path]);
        if ($code !== 0) {
            throw new RuntimeException('RELEASE_REQUIRED_FILE_MISSING:' . $path);
        }
        return $output;
    }

    /** @param list<string> $arguments */
    private function git(array $arguments, bool $trim = false): string
    {
        [$code, $output, $error] = $this->run(array_merge(['git', '-C', $this->repositoryRoot], $arguments));
        if ($code !== 0) {
            throw new RuntimeException('RELEASE_GIT_FAILED:' . trim($error));
        }
        return $trim ? trim($output) : $output;
    }

    /** @param list<string> $command @return array{0:int,1:string,2:string} */
    private function run(array $command): array
    {
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('RELEASE_PROCESS_START_FAILED');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), is_string($stdout) ? $stdout : '', is_string($stderr) ? $stderr : ''];
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('RELEASE_OUTPUT_DIRECTORY_FAILED');
        }
    }

    private function addZipEntry(ZipArchive $zip, string $path, string $contents, int $timestamp): void
    {
        self::assertSafePath($path);
        if (!$zip->addFromString($path, $contents) || !$zip->setMtimeName($path, $timestamp)) {
            throw new RuntimeException('RELEASE_ARTIFACT_ADD_FAILED:' . $path);
        }
        if (!$zip->setCompressionName($path, ZipArchive::CM_DEFLATE, 9)) {
            throw new RuntimeException('RELEASE_ARTIFACT_COMPRESSION_FAILED:' . $path);
        }
        if (!$zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, 0100644 << 16)) {
            throw new RuntimeException('RELEASE_ARTIFACT_ATTRIBUTES_FAILED:' . $path);
        }
    }
}
