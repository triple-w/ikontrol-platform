<?php

declare(strict_types=1);

namespace Ikontrol\Release;

use RuntimeException;
use ZipArchive;

final class ReleaseArtifactBuilder
{
    private const DEPLOYABLE_PREFIXES = [
        'app/',
        'assets/',
        'plugins/',
        'public/',
        'resources/',
        'system/',
        'updates/',
    ];

    private const DEPLOYABLE_ROOT_FILES = [
        'index.php',
        'spark',
    ];

    private const PROTECTED_PREFIXES = [
        '.git/',
        'backups/',
        'docs/',
        'documentation/',
        'files/',
        'logs/',
        'sessions/',
        'tests/',
        'tools/',
        'uploads/',
        'writable/',
    ];

    private const PRIVATE_EXTENSIONS = [
        'cer', 'key', 'pem', 'pfx', 'p12', 'jks',
    ];

    public function __construct(private readonly string $repositoryRoot)
    {
        if (!is_dir($repositoryRoot . DIRECTORY_SEPARATOR . '.git')) {
            throw new RuntimeException('RELEASE_REPOSITORY_INVALID');
        }
    }

    /**
     * @return array{manifest: array<string, mixed>, manifest_path: string, artifact_path: string, artifact_sha256: string, checksum_path: string}
     */
    public function build(string $specPath, string $outputDirectory): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('RELEASE_ZIP_EXTENSION_REQUIRED');
        }

        $spec = $this->loadSpec($specPath);
        $this->assertCleanExactCheckout($spec['commit_sha']);
        $this->assertCommit($spec['from_commit_sha']);
        $this->assertVersionFile($spec['commit_sha'], $spec['version']);
        $this->assertVersionFile($spec['from_commit_sha'], $spec['from_version']);

        $changes = $this->changedDeployableFiles($spec['from_commit_sha'], $spec['commit_sha']);
        $files = [];
        $removed = [];

        foreach ($changes as $change) {
            $path = $change['path'];
            if ($change['status'] === 'D') {
                $source = $this->blob($spec['from_commit_sha'], $path);
                $removed[] = [
                    'path' => $path,
                    'from_sha256' => hash('sha256', $source),
                ];
                continue;
            }

            $target = $this->blob($spec['commit_sha'], $path);
            $from = $this->blobIfPresent($spec['from_commit_sha'], $path);
            $files[] = [
                'path' => $path,
                'sha256' => hash('sha256', $target),
                'size' => strlen($target),
                'from_sha256' => $from === null ? null : hash('sha256', $from),
                'change' => $from === null ? 'added' : 'modified',
            ];
        }

        usort($files, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        usort($removed, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        $manifest = [
            'schema_version' => 1,
            'package_type' => 'delta',
            'version' => $spec['version'],
            'release_id' => $spec['release_id'],
            'release_ref' => $spec['release_ref'],
            'commit_sha' => $spec['commit_sha'],
            'from_version' => $spec['from_version'],
            'from_commit_sha' => $spec['from_commit_sha'],
            'generated_at' => $this->git(['show', '-s', '--format=%cI', $spec['commit_sha']], true),
            'new_file_from_sha256' => null,
            'files' => $files,
            'removed_files' => $removed,
        ];

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $manifestRelative = 'updates/' . $spec['version'] . '/deployment-manifest.json';
        self::assertSafePath($manifestRelative);

        $outputDirectory = rtrim($outputDirectory, '/\\');
        $manifestPath = $outputDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $manifestRelative);
        $artifactPath = $outputDirectory . DIRECTORY_SEPARATOR . 'ikontrol-platform-' . $spec['version'] . '.zip';
        $checksumPath = $artifactPath . '.sha256';
        $this->ensureDirectory(dirname($manifestPath));

        if (file_put_contents($manifestPath, $json) === false) {
            throw new RuntimeException('RELEASE_MANIFEST_WRITE_FAILED');
        }

        if (is_file($artifactPath) && !unlink($artifactPath)) {
            throw new RuntimeException('RELEASE_ARTIFACT_REPLACE_FAILED');
        }

        $zip = new ZipArchive();
        if ($zip->open($artifactPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('RELEASE_ARTIFACT_OPEN_FAILED');
        }

        $timestamp = max(315532800, (int) $this->git(['show', '-s', '--format=%ct', $spec['commit_sha']], true));
        foreach ($files as $file) {
            $contents = $this->blob($spec['commit_sha'], $file['path']);
            $this->addZipEntry($zip, $file['path'], $contents, $timestamp);
        }
        $this->addZipEntry($zip, $manifestRelative, $json, $timestamp);

        if (!$zip->close()) {
            throw new RuntimeException('RELEASE_ARTIFACT_CLOSE_FAILED');
        }

        $artifactSha = hash_file('sha256', $artifactPath);
        if (!is_string($artifactSha)) {
            throw new RuntimeException('RELEASE_ARTIFACT_HASH_FAILED');
        }
        $checksum = $artifactSha . '  ' . basename($artifactPath) . "\n";
        if (file_put_contents($checksumPath, $checksum) === false) {
            throw new RuntimeException('RELEASE_CHECKSUM_WRITE_FAILED');
        }

        $this->verifyArtifact($artifactPath, $manifest);

        return [
            'manifest' => $manifest,
            'manifest_path' => $manifestPath,
            'artifact_path' => $artifactPath,
            'artifact_sha256' => $artifactSha,
            'checksum_path' => $checksumPath,
        ];
    }

    /** @param array<string, mixed> $manifest */
    public function verifyArtifact(string $artifactPath, array $manifest): void
    {
        $zip = new ZipArchive();
        if ($zip->open($artifactPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('RELEASE_ARTIFACT_INVALID');
        }

        $expected = [];
        foreach ($manifest['files'] as $file) {
            $expected[$file['path']] = $file['sha256'];
        }
        $manifestPath = 'updates/' . $manifest['version'] . '/deployment-manifest.json';
        $manifestFound = false;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $path = $zip->getNameIndex($index);
            if (!is_string($path)) {
                $zip->close();
                throw new RuntimeException('RELEASE_ARTIFACT_ENTRY_INVALID');
            }
            self::assertSafePath($path);
            if ($path === $manifestPath) {
                $manifestFound = true;
                continue;
            }
            if (!isset($expected[$path])) {
                $zip->close();
                throw new RuntimeException('RELEASE_ARTIFACT_UNDECLARED_FILE:' . $path);
            }
            $contents = $zip->getFromIndex($index);
            if (!is_string($contents) || !hash_equals($expected[$path], hash('sha256', $contents))) {
                $zip->close();
                throw new RuntimeException('RELEASE_ARTIFACT_FILE_HASH_MISMATCH:' . $path);
            }
            unset($expected[$path]);
        }

        $zip->close();
        if (!$manifestFound || $expected !== []) {
            throw new RuntimeException('RELEASE_ARTIFACT_INCOMPLETE');
        }
    }

    public static function assertSafePath(string $path): void
    {
        $normalized = str_replace('\\', '/', $path);
        if ($path === '' || $normalized !== $path || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new RuntimeException('RELEASE_PATH_INVALID:' . $path);
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('RELEASE_PATH_TRAVERSAL:' . $path);
            }
        }
    }

    /** @return array<string, string> */
    private function loadSpec(string $specPath): array
    {
        $decoded = json_decode((string) file_get_contents($specPath), true, 512, JSON_THROW_ON_ERROR);
        foreach (['version', 'release_id', 'release_ref', 'commit_sha', 'from_version', 'from_commit_sha'] as $key) {
            if (!isset($decoded[$key]) || !is_string($decoded[$key]) || trim($decoded[$key]) === '') {
                throw new RuntimeException('RELEASE_SPEC_FIELD_REQUIRED:' . $key);
            }
        }
        foreach (['commit_sha', 'from_commit_sha'] as $key) {
            if (preg_match('/^[a-f0-9]{40}$/', $decoded[$key]) !== 1) {
                throw new RuntimeException('RELEASE_SPEC_COMMIT_INVALID:' . $key);
            }
        }
        if (preg_match('/^\d+\.\d+\.\d+$/', $decoded['version']) !== 1 || preg_match('/^\d+\.\d+\.\d+$/', $decoded['from_version']) !== 1) {
            throw new RuntimeException('RELEASE_SPEC_VERSION_INVALID');
        }
        if ($decoded['commit_sha'] === $decoded['from_commit_sha']) {
            throw new RuntimeException('RELEASE_SPEC_COMMITS_IDENTICAL');
        }

        return $decoded;
    }

    private function assertCleanExactCheckout(string $commitSha): void
    {
        $status = $this->git(['status', '--porcelain=v1', '--untracked-files=all']);
        if ($status !== '') {
            throw new RuntimeException('RELEASE_CHECKOUT_NOT_CLEAN');
        }
        $head = $this->git(['rev-parse', 'HEAD'], true);
        if (!hash_equals($commitSha, $head)) {
            throw new RuntimeException('RELEASE_CHECKOUT_COMMIT_MISMATCH');
        }
    }

    private function assertCommit(string $commitSha): void
    {
        $this->git(['cat-file', '-e', $commitSha . '^{commit}']);
    }

    private function assertVersionFile(string $commitSha, string $version): void
    {
        $contents = $this->blob($commitSha, 'app/Config/Version.php');
        if (preg_match("/const VERSION = ['\"]" . preg_quote($version, '/') . "['\"]/", $contents) !== 1) {
            throw new RuntimeException('RELEASE_VERSION_SOURCE_MISMATCH:' . $commitSha);
        }
    }

    /** @return list<array{status: string, path: string}> */
    private function changedDeployableFiles(string $fromCommit, string $targetCommit): array
    {
        $raw = $this->git(['diff', '--name-status', '-z', '--find-renames', $fromCommit, $targetCommit]);
        if ($raw === '') {
            return [];
        }
        $tokens = explode("\0", rtrim($raw, "\0"));
        $changes = [];
        for ($i = 0, $count = count($tokens); $i < $count;) {
            $status = $tokens[$i++];
            $code = $status[0] ?? '';
            if ($code === 'R' || $code === 'C') {
                $oldPath = $tokens[$i++] ?? '';
                $newPath = $tokens[$i++] ?? '';
                if ($this->isDeployable($oldPath)) {
                    $changes[] = ['status' => 'D', 'path' => $oldPath];
                }
                if ($this->isDeployable($newPath)) {
                    $changes[] = ['status' => 'A', 'path' => $newPath];
                }
                continue;
            }
            $path = $tokens[$i++] ?? '';
            if (in_array($code, ['A', 'M', 'D'], true) && $this->isDeployable($path)) {
                $changes[] = ['status' => $code, 'path' => $path];
            }
        }

        return $changes;
    }

    private function isDeployable(string $path): bool
    {
        self::assertSafePath($path);
        $lower = strtolower($path);
        if ($lower === '.env' || str_starts_with($lower, '.env.') || $lower === '.git' || str_contains($lower, 'bootstrap-receipt')) {
            return false;
        }
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return false;
            }
        }
        $extension = strtolower(pathinfo($lower, PATHINFO_EXTENSION));
        if (in_array($extension, self::PRIVATE_EXTENSIONS, true)) {
            return false;
        }
        if (in_array($path, self::DEPLOYABLE_ROOT_FILES, true)) {
            return true;
        }
        foreach (self::DEPLOYABLE_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function blob(string $commitSha, string $path): string
    {
        self::assertSafePath($path);
        return $this->git(['show', $commitSha . ':' . $path]);
    }

    private function blobIfPresent(string $commitSha, string $path): ?string
    {
        self::assertSafePath($path);
        [$exitCode, $output] = $this->run(['git', '-C', $this->repositoryRoot, 'show', $commitSha . ':' . $path]);
        return $exitCode === 0 ? $output : null;
    }

    /** @param list<string> $arguments */
    private function git(array $arguments, bool $trim = false): string
    {
        [$exitCode, $output, $error] = $this->run(array_merge(['git', '-C', $this->repositoryRoot], $arguments), true);
        if ($exitCode !== 0) {
            throw new RuntimeException('RELEASE_GIT_FAILED:' . trim($error));
        }
        return $trim ? trim($output) : $output;
    }

    /** @param list<string> $command @return array{0: int, 1: string, 2: string} */
    private function run(array $command, bool $captureError = false): array
    {
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('RELEASE_PROCESS_START_FAILED');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        return [$exitCode, is_string($output) ? $output : '', $captureError && is_string($error) ? $error : ''];
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
        if (!$zip->addFromString($path, $contents)) {
            throw new RuntimeException('RELEASE_ARTIFACT_ADD_FAILED:' . $path);
        }
        if (!$zip->setMtimeName($path, $timestamp)) {
            throw new RuntimeException('RELEASE_ARTIFACT_TIMESTAMP_FAILED:' . $path);
        }
        $zip->setCompressionName($path, ZipArchive::CM_DEFLATE, 9);
    }
}
