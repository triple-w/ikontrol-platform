<?php

declare(strict_types=1);

namespace Ikontrol\LegacyBootstrap;

use RuntimeException;

/** Copies a versioned bootstrap payload into a legacy CodeIgniter project without overwriting files. */
final class LegacyBootstrapInstaller
{
    private string $sourceRoot;
    private string $targetRoot;

    public function __construct(string $sourceRoot, string $targetRoot, private string $manifestPath)
    {
        $source = realpath($sourceRoot);
        $target = realpath($targetRoot);
        if ($source === false || $target === false || ! is_dir($source) || ! is_dir($target)) {
            throw new RuntimeException('Source and target roots must already exist.');
        }
        $this->sourceRoot = rtrim(str_replace('\\', '/', $source), '/');
        $this->targetRoot = rtrim(str_replace('\\', '/', $target), '/');
    }

    /** @return array<string,mixed> */
    public function inspect(): array
    {
        $manifest = $this->manifest();
        $sourceErrors = [];
        $conflicts = [];
        $create = [];
        $identical = [];

        foreach ($manifest['files'] as $entry) {
            $path = $this->safePath((string)($entry['path'] ?? ''));
            $expected = strtolower((string)($entry['sha256'] ?? ''));
            $source = $this->sourcePath($path);
            $target = $this->targetPath($path);
            if (! is_file($source)) {
                $sourceErrors[] = ['path'=>$path,'reason'=>'source_missing'];
                continue;
            }
            $actual = hash_file('sha256', $source);
            if (! preg_match('/^[0-9a-f]{64}$/', $expected) || ! hash_equals($expected, $actual)) {
                $sourceErrors[] = ['path'=>$path,'reason'=>'source_checksum_mismatch','expected'=>$expected,'actual'=>$actual];
                continue;
            }
            if (is_dir($target)) {
                $conflicts[] = ['path'=>$path,'reason'=>'target_is_directory'];
            } elseif (is_file($target)) {
                $targetHash = hash_file('sha256', $target);
                if (hash_equals($expected, $targetHash)) $identical[] = $path;
                else $conflicts[] = ['path'=>$path,'reason'=>'target_differs','expected'=>$expected,'actual'=>$targetHash];
            } elseif (file_exists($target)) {
                $conflicts[] = ['path'=>$path,'reason'=>'target_not_regular_file'];
            } else {
                $create[] = $path;
            }
        }

        $status = $sourceErrors !== [] ? 'SOURCE_INVALID'
            : ($conflicts !== [] ? 'CONFLICT'
                : ($create === [] ? 'INSTALLED' : 'READY_TO_INSTALL'));
        return [
            'status'=>$status,
            'compatible'=>in_array($status, ['INSTALLED','READY_TO_INSTALL'], true),
            'bootstrap_version'=>(string)$manifest['bootstrap_version'],
            'canonical_version'=>(string)$manifest['canonical_version'],
            'source_root'=>$this->sourceRoot,
            'target_root'=>$this->targetRoot,
            'create'=>$create,
            'identical'=>$identical,
            'conflicts'=>$conflicts,
            'source_errors'=>$sourceErrors,
            'writes'=>0,
        ];
    }

    /** @return array<string,mixed> */
    public function install(): array
    {
        $inspection = $this->inspect();
        if (! $inspection['compatible']) {
            throw new RuntimeException('Legacy bootstrap preflight failed: '.$inspection['status']);
        }
        if ($inspection['status'] === 'INSTALLED') return $inspection;

        $created = [];
        foreach ($inspection['create'] as $path) {
            $source = $this->sourcePath($path);
            $target = $this->targetPath($path);
            $directory = dirname($target);
            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new RuntimeException("Unable to create bootstrap directory for {$path}.");
            }
            $this->assertInsideTarget($directory);
            if (file_exists($target)) {
                if (is_file($target) && hash_equals(hash_file('sha256', $source), hash_file('sha256', $target))) continue;
                throw new RuntimeException("Bootstrap target appeared concurrently: {$path}.");
            }
            $temporary = $target.'.ikontrol-bootstrap-'.bin2hex(random_bytes(6));
            if (! copy($source, $temporary) || ! hash_equals(hash_file('sha256', $source), hash_file('sha256', $temporary))) {
                if (is_file($temporary)) @unlink($temporary);
                throw new RuntimeException("Unable to stage bootstrap file {$path}.");
            }
            if (! rename($temporary, $target)) {
                @unlink($temporary);
                throw new RuntimeException("Unable to install bootstrap file {$path}.");
            }
            $created[] = $path;
        }

        $receipt = $this->writeReceipt($created);
        $result = $this->inspect();
        if ($result['status'] !== 'INSTALLED') throw new RuntimeException('Bootstrap post-check did not reach INSTALLED.');
        $result['writes'] = count($created);
        $result['created'] = $created;
        $result['receipt'] = $receipt;
        return $result;
    }

    /** Read-only rollback inventory. It never removes files. @return array<string,mixed> */
    public function rollbackPlan(): array
    {
        $manifest = $this->manifest();
        $receipt = $this->receiptPath((string)$manifest['bootstrap_version']);
        if (! is_file($receipt)) return ['status'=>'RECEIPT_MISSING','safe'=>false,'receipt'=>$receipt,'removable'=>[],'modified'=>[],'missing'=>[]];
        $data = json_decode((string)file_get_contents($receipt), true);
        if (! is_array($data) || ($data['bootstrap_version'] ?? null) !== $manifest['bootstrap_version'] || ! is_array($data['created_files'] ?? null)) {
            return ['status'=>'RECEIPT_INVALID','safe'=>false,'receipt'=>$receipt,'removable'=>[],'modified'=>[],'missing'=>[]];
        }
        $expected=[];foreach($manifest['files']as$entry)$expected[(string)$entry['path']]=(string)$entry['sha256'];
        $removable=[];$modified=[];$missing=[];
        foreach($data['created_files']as$raw){
            $path=$this->safePath((string)$raw);
            if(!isset($expected[$path])){$modified[]=['path'=>$path,'reason'=>'not_in_manifest'];continue;}
            $target=$this->targetPath($path);
            if(!is_file($target)){$missing[]=$path;continue;}
            $actual=hash_file('sha256',$target);
            if(hash_equals($expected[$path],$actual))$removable[]=$path;
            else$modified[]=['path'=>$path,'reason'=>'changed_after_install','expected'=>$expected[$path],'actual'=>$actual];
        }
        return['status'=>$modified===[]?'SAFE_TO_ROLL_BACK':'MANUAL_REVIEW_REQUIRED','safe'=>$modified===[],'receipt'=>$receipt,'removable'=>$removable,'modified'=>$modified,'missing'=>$missing];
    }

    /** @return array<string,mixed> */
    private function manifest(): array
    {
        if (! is_file($this->manifestPath)) throw new RuntimeException('Legacy bootstrap manifest is missing.');
        $manifest = json_decode((string)file_get_contents($this->manifestPath), true);
        if (! is_array($manifest) || ($manifest['schema_version'] ?? null) !== 1
            || ! is_string($manifest['bootstrap_version'] ?? null)
            || ! is_string($manifest['canonical_version'] ?? null)
            || ! is_array($manifest['files'] ?? null) || $manifest['files'] === []) {
            throw new RuntimeException('Legacy bootstrap manifest is invalid.');
        }
        return $manifest;
    }

    private function safePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)
            || in_array('..', explode('/', $path), true) || str_contains($path, "\0")) {
            throw new RuntimeException('Unsafe bootstrap path: '.$path);
        }
        return $path;
    }

    private function sourcePath(string $path): string { return $this->sourceRoot.'/'.$path; }
    private function targetPath(string $path): string { return $this->targetRoot.'/'.$path; }

    private function assertInsideTarget(string $directory): void
    {
        $resolved = realpath($directory);
        if ($resolved === false) throw new RuntimeException('Unable to resolve bootstrap target directory.');
        $resolved = rtrim(str_replace('\\', '/', $resolved), '/');
        if ($resolved !== $this->targetRoot && ! str_starts_with($resolved.'/', $this->targetRoot.'/')) {
            throw new RuntimeException('Bootstrap target escapes the selected legacy root.');
        }
    }

    private function writeReceipt(array $created): string
    {
        $manifest = $this->manifest();
        $directory = $this->targetRoot.'/.ikontrol-legacy-bootstrap';
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create bootstrap receipt directory.');
        }
        $this->assertInsideTarget($directory);
        $path = $this->receiptPath((string)$manifest['bootstrap_version']);
        if (is_file($path)) return $path;
        $payload = [
            'bootstrap_version'=>$manifest['bootstrap_version'],
            'canonical_version'=>$manifest['canonical_version'],
            'installed_at'=>gmdate('c'),
            'created_files'=>$created,
            'manifest_sha256'=>hash_file('sha256', $this->manifestPath),
        ];
        if (file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            throw new RuntimeException('Unable to write bootstrap receipt.');
        }
        return $path;
    }

    private function receiptPath(string $bootstrapVersion): string
    {
        return $this->targetRoot.'/.ikontrol-legacy-bootstrap/'.preg_replace('/[^A-Za-z0-9._-]/', '-', $bootstrapVersion).'.json';
    }
}
