<?php

declare(strict_types=1);

namespace App\Services\Legacy;

use App\Domain\Legacy\LegacyImportDecision;
use App\Domain\Legacy\LegacyImportResult;
use App\Domain\Legacy\LegacySourceReference;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class LegacyImportRegistryService
{
    private const BATCH_STATUSES = ['pending', 'running', 'completed', 'completed_with_warnings', 'failed', 'rolled_back'];
    private const MAPPING_STATUSES = ['pending', 'imported', 'updated', 'skipped', 'conflict', 'failed'];
    private const ACTIONS = ['insert', 'update', 'skip', 'conflict', 'error'];

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password', 'passwd', 'password_hash', 'token', 'access_token', 'refresh_token',
        'api_key', 'apikey', 'secret', 'client_secret', 'private_key', 'private_key_password',
        'key', 'key_content', 'key_pem', 'pem', 'pem_content', 'cer_content',
        'csd_password', 'contrasena_csd', 'certificate_password',
    ];

    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?: db_connect('default');
    }

    public function startBatch(array $data): int
    {
        foreach (['source_system', 'entity_scope'] as $required) {
            if (trim((string) ($data[$required] ?? '')) === '') {
                throw new InvalidArgumentException("{$required} is required to start a legacy import batch.");
            }
        }
        $now = get_current_utc_time();
        $row = [
            'source_system' => trim((string) $data['source_system']),
            'source_database' => $this->nullableString($data['source_database'] ?? null),
            'source_owner_id' => $this->nullableString($data['source_owner_id'] ?? null),
            'source_owner_key' => $this->nullableString($data['source_owner_key'] ?? null),
            'entity_scope' => trim((string) $data['entity_scope']),
            'source_backup_name' => $this->nullableString($data['source_backup_name'] ?? null),
            'source_backup_hash' => $this->validatedHash($data['source_backup_hash'] ?? null),
            'status' => 'pending',
            'created_by' => isset($data['created_by']) ? (int) $data['created_by'] : null,
            'summary_json' => isset($data['summary']) ? $this->canonicalJson($this->sanitizeSnapshot($data['summary'])) : null,
            'created_at' => $now,
            'updated_at' => null,
        ];
        if (! $this->db->table('legacy_import_batches')->insert($row)) {
            throw new RuntimeException('Unable to create the legacy import batch.');
        }
        return (int) $this->db->insertID();
    }

    public function markRunning(int $batchId): void
    {
        $this->updateBatch($batchId, 'running', ['started_at' => get_current_utc_time(), 'completed_at' => null, 'error_message' => null]);
    }

    public function completeBatch(int $batchId, array $summary = []): void
    {
        $this->finishBatch($batchId, 'completed', $summary);
    }

    public function completeBatchWithWarnings(int $batchId, array $summary = []): void
    {
        $this->finishBatch($batchId, 'completed_with_warnings', $summary);
    }

    public function failBatch(int $batchId, string $message, array $summary = []): void
    {
        $this->updateBatch($batchId, 'failed', [
            'completed_at' => get_current_utc_time(),
            'summary_json' => $summary === [] ? null : $this->canonicalJson($this->sanitizeSnapshot($summary)),
            'error_message' => $message,
        ]);
    }

    public function findMapping(LegacySourceReference $source): ?object
    {
        return $this->db->table('legacy_import_mappings')->where($source->where())->get(1)->getRow();
    }

    public function decide(LegacySourceReference $source, array $snapshot, bool $destinationExists = true): LegacyImportDecision
    {
        $mapping = $this->findMapping($source);
        if (! $mapping) {
            return new LegacyImportDecision('insert', 'source_not_registered');
        }
        if ($mapping->destination_table !== null && $mapping->destination_id !== null && ! $destinationExists) {
            return new LegacyImportDecision('conflict', 'registered_destination_missing', $mapping);
        }
        $hash = $this->hashSnapshot($snapshot);
        if (hash_equals((string) $mapping->source_hash, $hash)) {
            return new LegacyImportDecision('skip', 'source_unchanged', $mapping);
        }
        return new LegacyImportDecision('update', 'source_changed', $mapping);
    }

    public function recordImport(int $batchId, LegacySourceReference $source, array $snapshot, string $destinationTable, string $destinationId, ?string $destinationHash = null, array $warnings = []): LegacyImportResult
    {
        return $this->record($batchId, $source, $snapshot, 'imported', 'insert', $destinationTable, $destinationId, $destinationHash, $warnings, false);
    }

    public function recordUpdate(int $batchId, LegacySourceReference $source, array $snapshot, string $destinationTable, string $destinationId, ?string $destinationHash = null, array $warnings = []): LegacyImportResult
    {
        return $this->record($batchId, $source, $snapshot, 'updated', 'update', $destinationTable, $destinationId, $destinationHash, $warnings, true);
    }

    public function recordSkip(int $batchId, LegacySourceReference $source, array $snapshot, array $warnings = []): LegacyImportResult
    {
        return $this->record($batchId, $source, $snapshot, 'skipped', 'skip', null, null, null, $warnings, true);
    }

    public function recordConflict(int $batchId, LegacySourceReference $source, array $snapshot, array $warnings = []): LegacyImportResult
    {
        return $this->record($batchId, $source, $snapshot, 'conflict', 'conflict', null, null, null, $warnings, true);
    }

    public function recordError(int $batchId, LegacySourceReference $source, array $snapshot, array $warnings = []): LegacyImportResult
    {
        return $this->record($batchId, $source, $snapshot, 'failed', 'error', null, null, null, $warnings, true);
    }

    public function hashSnapshot(array $snapshot): string
    {
        return hash('sha256', $this->canonicalJson($this->sanitizeSnapshot($snapshot)));
    }

    public function canonicalJson(array $snapshot): string
    {
        $canonical = $this->canonicalize($snapshot);
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        return $json;
    }

    public function sanitizeSnapshot(array $snapshot): array
    {
        $clean = [];
        foreach ($snapshot as $key => $value) {
            $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', (string) $key));
            if ($this->isSensitiveKey(trim($normalized, '_'))) {
                continue;
            }
            if (is_string($value) && preg_match('/-----BEGIN (?:ENCRYPTED |RSA |EC )?(?:PRIVATE KEY|CERTIFICATE)-----/', $value)) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->sanitizeSnapshot($value) : $value;
        }
        return $clean;
    }

    public function transactional(callable $operation)
    {
        $this->db->transBegin();
        try {
            $result = $operation();
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Legacy registry transaction failed.');
            }
            $this->db->transCommit();
            return $result;
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
    }

    private function record(int $batchId, LegacySourceReference $source, array $snapshot, string $status, string $action, ?string $destinationTable, ?string $destinationId, ?string $destinationHash, array $warnings, bool $allowExisting): LegacyImportResult
    {
        $this->assertMappingState($status, $action);
        return $this->transactional(function () use ($batchId, $source, $snapshot, $status, $action, $destinationTable, $destinationId, $destinationHash, $warnings, $allowExisting): LegacyImportResult {
            $table = $this->db->protectIdentifiers($this->db->prefixTable('legacy_import_mappings'));
            $where = $source->where();
            $existing = $this->db->query(
                "SELECT * FROM {$table} WHERE source_system=? AND source_table=? AND source_owner_id=? AND source_id=? FOR UPDATE",
                [$where['source_system'], $where['source_table'], $where['source_owner_id'], $where['source_id']]
            )->getRow();
            if ($existing && ! $allowExisting) {
                throw new RuntimeException('The legacy source row is already registered.');
            }
            $clean = $this->sanitizeSnapshot($snapshot);
            $now = get_current_utc_time();
            $data = [
                'import_batch_id' => $batchId,
                'source_hash' => hash('sha256', $this->canonicalJson($clean)),
                'destination_table' => $destinationTable ?? ($existing->destination_table ?? null),
                'destination_id' => $destinationId ?? ($existing->destination_id ?? null),
                'destination_hash' => $destinationHash !== null
                    ? $this->validatedHash($destinationHash)
                    : ($existing->destination_hash ?? null),
                'status' => $status,
                'action' => $action,
                'warnings_json' => $warnings === [] ? null : $this->canonicalJson($this->sanitizeSnapshot($warnings)),
                'source_snapshot_json' => $this->canonicalJson($clean),
                'imported_at' => in_array($status, ['imported', 'updated'], true) ? $now : ($existing->imported_at ?? null),
                'updated_at' => $now,
            ];
            if ($existing) {
                $this->db->table('legacy_import_mappings')->where('id', $existing->id)->update($data);
                $id = (int) $existing->id;
            } else {
                $data += $source->where() + ['created_at' => $now];
                $this->db->table('legacy_import_mappings')->insert($data);
                $id = (int) $this->db->insertID();
            }
            if ($id < 1) {
                throw new RuntimeException('Unable to register the legacy source mapping.');
            }
            return new LegacyImportResult($id, $status, $action);
        });
    }

    private function finishBatch(int $batchId, string $status, array $summary): void
    {
        $this->updateBatch($batchId, $status, [
            'completed_at' => get_current_utc_time(),
            'summary_json' => $this->canonicalJson($this->sanitizeSnapshot($summary)),
            'error_message' => null,
        ]);
    }

    private function updateBatch(int $batchId, string $status, array $data): void
    {
        if (! in_array($status, self::BATCH_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid legacy batch status.');
        }
        $data['status'] = $status;
        $data['updated_at'] = get_current_utc_time();
        $builder = $this->db->table('legacy_import_batches')->where('id', $batchId);
        if (! $builder->update($data) || $this->db->affectedRows() < 1) {
            throw new RuntimeException('Legacy import batch not found or not updated.');
        }
    }

    private function assertMappingState(string $status, string $action): void
    {
        if (! in_array($status, self::MAPPING_STATUSES, true) || ! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Invalid legacy mapping status or action.');
        }
    }

    private function canonicalize($value)
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('Floating-point values are not allowed in canonical legacy snapshots; use decimal strings.');
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        if (in_array($key, self::SENSITIVE_KEYS, true)) {
            return true;
        }
        return str_contains($key, 'password') || str_contains($key, 'token') || str_contains($key, 'api_key')
            || str_contains($key, 'private_key') || str_contains($key, 'csd_secret');
    }

    private function nullableString($value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function validatedHash($hash): ?string
    {
        $hash = $this->nullableString($hash);
        if ($hash !== null && ! preg_match('/^[a-f0-9]{64}$/i', $hash)) {
            throw new InvalidArgumentException('Hashes must contain exactly 64 hexadecimal characters.');
        }
        return $hash === null ? null : strtolower($hash);
    }
}
