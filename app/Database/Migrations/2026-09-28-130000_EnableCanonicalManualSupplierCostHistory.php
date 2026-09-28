<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Add manual costs without replaying supplier backfills or changing formal origins. */
final class EnableCanonicalManualSupplierCostHistory extends Migration
{
    private const TABLE = 'product_supplier_cost_history';

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            throw new RuntimeException('Manual cost schema migration requires MySQLi; use a dedicated MySQL fixture.');
        }
        $this->db->resetDataCache();
        if (! $this->db->tableExists(self::TABLE)) {
            throw new RuntimeException('Reconcile the formal supplier history schema first.');
        }
        $table = $this->db->prefixTable(self::TABLE);
        $columns = [];
        foreach ($this->db->query(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            [$table]
        )->getResultArray() as $column) {
            $columns[$column['COLUMN_NAME']] = $column;
        }
        // Validate every precondition before the first DDL (MySQL DDL commits implicitly).
        foreach (['source_type', 'source_id', 'source_item_id', 'source_folio', 'proposal_id', 'proposal_item_id'] as $name) {
            if (! isset($columns[$name]) || $columns[$name]['IS_NULLABLE'] !== 'YES') {
                throw new RuntimeException('Generic history origin must already be reconciled: ' . $name);
            }
        }
        $optional = ['client_id' => 'int', 'sale_unit_price' => 'decimal(18,6)', 'quantity' => 'decimal(18,6)', 'quoted_at' => 'datetime'];
        foreach ($optional as $name => $type) {
            if (! isset($columns[$name]) || $this->type($columns[$name]['COLUMN_TYPE']) !== $type
                || ! in_array($columns[$name]['COLUMN_DEFAULT'], [null, 'NULL'], true)) {
                throw new RuntimeException('Incompatible history column: ' . $name);
            }
        }
        $additions = [
            'notes' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'updated_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'idempotency_key' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'default' => null],
        ];
        foreach (['notes' => 'text', 'updated_at' => 'datetime', 'idempotency_key' => 'char(64)'] as $name => $type) {
            if (isset($columns[$name]) && ($this->type($columns[$name]['COLUMN_TYPE']) !== $type
                || $columns[$name]['IS_NULLABLE'] !== 'YES'
                || ! in_array($columns[$name]['COLUMN_DEFAULT'], [null, 'NULL'], true))) {
                throw new RuntimeException('Incompatible manual history column: ' . $name);
            }
        }
        $indexes = $this->db->getIndexData(self::TABLE);
        $economic = $indexes['uq_cost_history_source_economic'] ?? null;
        if (! $economic || $economic->type !== 'UNIQUE' || $economic->fields !== ['source_type', 'source_item_id', 'economic_hash']) {
            throw new RuntimeException('Formal history economic identity must already be reconciled.');
        }
        $unique = $indexes['uq_cost_history_manual_idempotency'] ?? null;
        if ($unique && ($unique->type !== 'UNIQUE' || $unique->fields !== ['idempotency_key'])) {
            throw new RuntimeException('Incompatible manual idempotency index.');
        }
        if (isset($columns['idempotency_key']) && $this->db->table(self::TABLE)
            ->select('idempotency_key')->where('idempotency_key IS NOT NULL', null, false)
            ->groupBy('idempotency_key')->having('COUNT(*) > 1', null, false)->get(1)->getRow()) {
            throw new RuntimeException('Duplicate manual keys require review; no rows have been changed.');
        }

        $changes = [];
        foreach ($optional as $name => $type) {
            if ($columns[$name]['IS_NULLABLE'] !== 'YES') {
                $changes[] = 'MODIFY ' . $this->db->escapeIdentifiers($name) . ' ' . strtoupper($type) . ' NULL DEFAULT NULL';
            }
        }
        if ($changes) {
            $this->db->query('ALTER TABLE ' . $this->db->escapeIdentifiers($table) . ' ' . implode(', ', $changes));
        }
        foreach ($additions as $name => $definition) {
            if (! isset($columns[$name])) {
                $this->forge->addColumn(self::TABLE, [$name => $definition]);
                $this->db->resetDataCache();
            }
        }
        if (! $unique) {
            $this->db->query('ALTER TABLE ' . $this->db->escapeIdentifiers($table)
                . ' ADD UNIQUE KEY uq_cost_history_manual_idempotency (idempotency_key)');
        }
        $this->db->resetDataCache();
    }

    public function down(): void
    {
        // Retain historical/manual rows and their nullable semantics on code rollback.
    }

    private function type(string $type): string
    {
        return preg_replace('/^int\(\d+\)$/', 'int', strtolower($type));
    }
}
