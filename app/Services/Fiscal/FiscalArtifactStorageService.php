<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;
use Throwable;

final class FiscalArtifactStorageService
{
    private const TYPES = ['original_chain' => 'txt', 'signed_xml' => 'xml', 'stamped_xml' => 'xml', 'pdf' => 'pdf'];
    private $db;
    private string $root;

    public function __construct($db = null, ?string $root = null)
    {
        $this->db = $db ?: db_connect();
        $this->root = rtrim($root ?: WRITEPATH . 'fiscal/artifacts', '/\\');
    }

    public function store(int $documentId, string $type, string $contents, string $version, string $schemaVersion, string $schemaHash, string $validationStatus, array $validation, int $userId): object
    {
        if (!isset(self::TYPES[$type])) {
            throw new RuntimeException('Tipo de artefacto fiscal no permitido.');
        }
        $hash = hash('sha256', $contents);
        $existing = $this->db->table('fiscal_document_artifacts')->where([
            'fiscal_document_id' => $documentId, 'artifact_type' => $type,
            'builder_version' => $version, 'sha256' => $hash, 'superseded_at' => null,
        ])->get(1)->getRow();
        if ($existing) {
            $this->read($existing);
            return $existing;
        }
        $this->ensureDirectory();
        $name = bin2hex(random_bytes(24)) . '.' . self::TYPES[$type];
        $relative = 'fiscal/artifacts/' . $name;
        $target = $this->root . DIRECTORY_SEPARATOR . $name;
        $temporary = $target . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('No fue posible almacenar el artefacto fiscal de forma atómica.');
        }
        @chmod($target, 0600);
        $this->db->transBegin();
        try {
            $now = get_current_utc_time();
            $this->db->table('fiscal_document_artifacts')->where([
                'fiscal_document_id' => $documentId, 'artifact_type' => $type, 'superseded_at' => null,
            ])->update(['superseded_at' => $now]);
            $data = [
                'fiscal_document_id' => $documentId, 'artifact_type' => $type,
                'storage_path' => $relative, 'sha256' => $hash, 'byte_size' => strlen($contents),
                'builder_version' => $version, 'schema_version' => $schemaVersion,
                'schema_sha256' => $schemaHash, 'validation_status' => $validationStatus,
                'validation_payload' => json_encode($validation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_by' => $userId, 'created_at' => $now,
            ];
            $this->db->table('fiscal_document_artifacts')->insert($data);
            $data['id'] = (int) $this->db->insertID();
            if (!$this->db->transStatus()) {
                throw new RuntimeException('No fue posible registrar el artefacto fiscal.');
            }
            $this->db->transCommit();
            return (object) $data;
        } catch (Throwable $e) {
            $this->db->transRollback();
            @unlink($target);
            throw $e;
        }
    }

    public function read(object $artifact): string
    {
        $extension = self::TYPES[$artifact->artifact_type] ?? null;
        if (!$extension || !preg_match('#^(fiscal/artifacts|fiscal-private/artifacts)/([a-f0-9]{48}\.' . $extension . ')$#', $artifact->storage_path, $match)) {
            throw new RuntimeException('Ruta de artefacto fiscal inválida.');
        }
        $root = $match[1] === 'fiscal-private/artifacts'
            ? WRITEPATH . 'fiscal-private/artifacts'
            : $this->root;
        $base = realpath($root);
        $path = realpath($root . DIRECTORY_SEPARATOR . $match[2]);
        if (!$base || !$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Acceso al artefacto fiscal denegado.');
        }
        $contents = file_get_contents($path);
        if ($contents === false || !hash_equals((string) $artifact->sha256, hash('sha256', $contents))) {
            throw new RuntimeException('El artefacto fiscal no supera la verificación de integridad.');
        }
        return $contents;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->root) && !mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new RuntimeException('No fue posible crear el almacenamiento privado de artefactos.');
        }
        @chmod($this->root, 0700);
    }
}
