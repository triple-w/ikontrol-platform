<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Fiscal\FiscalDecimal;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Append-only manual capture. Authorization belongs to the future P07 caller. */
final class ManualSupplierCostService
{
    private const TABLE = 'product_supplier_cost_history';

    public function __construct(private BaseConnection $db)
    {
    }

    public function save(array $data, int $user): array
    {
        $this->requireSchema();
        if ($user <= 0) {
            throw new InvalidArgumentException('Se requiere un usuario responsable de la captura.');
        }
        $product = $this->positiveId($data['product_id'] ?? null);
        $supplier = $this->positiveId($data['supplier_id'] ?? null);
        $token = $data['idempotency_key'] ?? '';
        if (! is_string($token) || ! preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $token)) {
            throw new InvalidArgumentException('Se requiere un token de idempotencia válido.');
        }
        // Never accept administrative/fiscal origins through the manual entry point.
        foreach (['client_id', 'source_id', 'source_item_id', 'proposal_id', 'proposal_item_id'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                throw new InvalidArgumentException('Un costo manual no puede tener origen comercial: ' . $field);
            }
        }
        if (isset($data['source_type']) && $data['source_type'] !== 'manual') {
            throw new InvalidArgumentException('El origen debe ser manual.');
        }
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'MXN')));
        if ($currency !== 'MXN') {
            throw new InvalidArgumentException('El historial actual compara costos en MXN.');
        }
        $cost = $this->decimal($data['unit_cost'] ?? null, false);
        $sale = $this->optionalDecimal($data['sale_unit_price'] ?? null, true);
        $quantity = $this->optionalDecimal($data['quantity'] ?? null, false);
        $quoted = $this->date($data['quoted_at'] ?? null);
        $notes = $this->text($data['notes'] ?? null, 65535);
        $folio = $this->text($data['source_folio'] ?? null, 80);
        $row = [
            'source_type' => 'manual', 'source_id' => null, 'source_item_id' => null,
            'source_folio' => $folio, 'proposal_id' => null, 'proposal_item_id' => null,
            'product_id' => $product, 'supplier_id' => $supplier, 'client_id' => null,
            'unit_cost' => $cost, 'sale_unit_price' => $sale, 'quantity' => $quantity,
            'currency' => $currency, 'quoted_at' => $quoted, 'notes' => $notes,
            'recorded_by' => $user, 'source_status' => 'manual', 'snapshot_version' => 1,
            'economic_hash' => hash('sha256', implode('|', [$product, $supplier, $cost, $sale ?? 'NULL', $quantity ?? 'NULL', $currency, $quoted ?? 'NULL'])),
            // Same namespace as Navika; do not sanitize distinct tokens into one key.
            'idempotency_key' => hash('sha256', 'manual|' . $user . '|' . $token),
        ];
        $existing = $this->byKey($row['idempotency_key']);
        if ($existing) {
            return $this->replay($existing, $row);
        }
        if (! $this->db->table('items')->where(['id' => $product, 'deleted' => 0])->countAllResults()
            || ! $this->db->table('suppliers')->where(['id' => $supplier, 'deleted' => 0, 'status' => 'active'])->countAllResults()) {
            throw new InvalidArgumentException('Se requiere producto existente y proveedor activo.');
        }
        $now = gmdate('Y-m-d H:i:s');
        $insert = $row + ['created_at' => $now, 'updated_at' => $now];
        $columns = implode(',', array_map($this->db->escapeIdentifiers(...), array_keys($insert)));
        $sql = 'INSERT INTO ' . $this->db->escapeIdentifiers($this->db->prefixTable(self::TABLE))
            . ' (' . $columns . ') VALUES (' . implode(',', array_fill(0, count($insert), '?')) . ')';
        // Atomic conflict handling does not poison an outer CI transaction with a duplicate-key error.
        $sql .= $this->db->DBDriver === 'MySQLi'
            ? ' ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
            : ' ON CONFLICT(idempotency_key) DO NOTHING';
        if (! $this->db->query($sql, array_values($insert))) {
            throw new RuntimeException('No fue posible registrar el costo manual.');
        }
        $created = $this->db->affectedRows() === 1;
        $saved = $this->byKey($row['idempotency_key'], true);
        if (! $saved) {
            throw new RuntimeException('No se pudo verificar la identidad del costo manual.');
        }
        $result = $this->replay($saved, $row);
        $result['created'] = $created;
        return $result;
    }

    private function byKey(string $key, bool $currentRead = false): ?object
    {
        $table = $this->db->escapeIdentifiers($this->db->prefixTable(self::TABLE));
        // A locking read sees the winner even in a caller's MySQL REPEATABLE READ transaction.
        return $this->db->query('SELECT * FROM ' . $table . ' WHERE idempotency_key=?'
            . ($currentRead && $this->db->DBDriver === 'MySQLi' ? ' FOR UPDATE' : ''), [$key])->getRow();
    }

    private function replay(object $existing, array $expected): array
    {
        foreach ($expected as $field => $value) {
            $actual = $existing->{$field};
            if (in_array($field, ['unit_cost', 'sale_unit_price', 'quantity'], true) && $actual !== null) {
                $actual = FiscalDecimal::format(FiscalDecimal::micros((string) $actual));
            }
            if (($value === null) !== ($actual === null) || ($value !== null && (string) $actual !== (string) $value)) {
                throw new InvalidArgumentException('El token ya fue utilizado con otros datos.');
            }
        }
        return ['id' => (int) $existing->id, 'created' => false];
    }

    private function requireSchema(): void
    {
        if (! in_array($this->db->DBDriver, ['MySQLi', 'SQLite3'], true)) {
            throw new RuntimeException('Unsupported manual cost database driver.');
        }
        if ($this->db->DBDriver === 'MySQLi' && $this->db->foundRows) {
            throw new RuntimeException('Manual cost creation reporting requires MySQLi foundRows=false.');
        }
        $fields = array_column($this->db->getFieldData(self::TABLE), null, 'name');
        foreach (['client_id', 'sale_unit_price', 'quantity', 'quoted_at', 'notes', 'updated_at', 'idempotency_key'] as $name) {
            if (! isset($fields[$name]) || ! $fields[$name]->nullable) {
                throw new RuntimeException('Deploy the canonical manual cost schema first: ' . $name);
            }
        }
        $index = $this->db->getIndexData(self::TABLE)['uq_cost_history_manual_idempotency'] ?? null;
        if (! $index || $index->type !== 'UNIQUE' || $index->fields !== ['idempotency_key']) {
            throw new RuntimeException('Manual cost idempotency requires its unique index.');
        }
    }

    private function positiveId(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[1-9][0-9]{0,9}$/D', (string) $value)
            || (int) $value > 2147483647) {
            throw new InvalidArgumentException('Producto y proveedor deben ser identificadores válidos.');
        }
        return (int) $value;
    }

    private function decimal(mixed $value, bool $allowZero): string
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^(0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/D', (string) $value)) {
            throw new InvalidArgumentException('Importe inválido; use hasta seis decimales sin notación exponencial.');
        }
        $micros = FiscalDecimal::micros((string) $value);
        if (! $allowZero && $micros <= 0) {
            throw new InvalidArgumentException('Costo y cantidad deben ser positivos.');
        }
        return FiscalDecimal::format($micros);
    }

    private function optionalDecimal(mixed $value, bool $allowZero): ?string
    {
        return $value === null || $value === '' ? null : $this->decimal($value, $allowZero);
    }

    private function text(mixed $value, int $maxBytes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || strlen($value) > $maxBytes || ! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('Texto inválido o demasiado largo.');
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException('Fecha inválida.');
        }
        $format = strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d H:i:s';
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
        if (! $date || $date->format($format) !== $value || (int) $date->format('Y') < 1000) {
            throw new InvalidArgumentException('Fecha inválida; use YYYY-MM-DD o YYYY-MM-DD HH:MM:SS UTC.');
        }
        return $date->format('Y-m-d H:i:s');
    }
}
