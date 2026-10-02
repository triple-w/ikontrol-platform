<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** Additive SAT catalog infrastructure for directed installs and upgrades. */
final class SatCatalogInfrastructureService
{
    public function __construct(private BaseConnection $db)
    {
    }

    public function inspect(): array
    {
        $missing = [];
        foreach (['sat_product_service_keys', 'sat_unit_keys', 'sat_catalog_installations'] as $table) {
            if (! $this->tableExists($table)) $missing[] = $table;
        }
        $columns = [];
        foreach (['sat_product_service_keys', 'sat_unit_keys'] as $table) {
            if ($this->tableExists($table) && ! $this->columnExists($table, 'normalized_description')) $columns[] = $table . '.normalized_description';
        }
        return ['ready' => $missing === [] && $columns === [], 'missing_tables' => $missing, 'missing_columns' => $columns];
    }

    public function apply(): array
    {
        foreach (['sat_product_service_keys', 'sat_unit_keys'] as $table) {
            if (! $this->tableExists($table)) throw new RuntimeException("SAT catalog prerequisite missing: {$table}");
        }
        if (! $this->tableExists('sat_catalog_installations')) {
            $this->db->query('CREATE TABLE ' . $this->q('sat_catalog_installations') . ' (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                catalog_name VARCHAR(80) NOT NULL,
                source VARCHAR(255) NOT NULL,
                source_version VARCHAR(120) NOT NULL,
                source_checksum CHAR(64) NOT NULL,
                source_generated_at DATETIME NULL,
                installed_at DATETIME NOT NULL,
                row_count INT UNSIGNED NOT NULL,
                active_row_count INT UNSIGNED NOT NULL,
                metadata_json TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_sat_catalog_installations_name (catalog_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        }
        foreach (['sat_product_service_keys', 'sat_unit_keys'] as $table) {
            if (! $this->columnExists($table, 'normalized_description')) {
                $this->db->query('ALTER TABLE ' . $this->q($table) . ' ADD COLUMN normalized_description VARCHAR(600) NULL');
            }
            $description = $table === 'sat_unit_keys' ? "COALESCE(NULLIF(name,''),description,'')" : "COALESCE(description,'')";
            foreach ($this->db->query('SELECT id,' . $description . ' description FROM ' . $this->q($table) . ' WHERE normalized_description IS NULL')->getResultArray() as $row) {
                $this->db->table($table)->where('id', $row['id'])->update([
                    'normalized_description' => SatCatalogTextNormalizer::description((string) $row['description']),
                ]);
            }
            $this->addIndex($table, 'idx_sat_active_code', 'is_active,code');
            $this->addIndex($table, 'idx_sat_active_normalized_description', 'is_active,normalized_description');
        }
        return $this->inspect();
    }

    private function tableExists(string $table): bool
    {
        if ($this->db->DBDriver === 'SQLite3') return $this->db->tableExists($table);
        return (bool) $this->db->query('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1', [$this->db->prefixTable($table)])->getRow();
    }

    private function columnExists(string $table, string $column): bool
    {
        if ($this->db->DBDriver === 'SQLite3') return $this->db->fieldExists($column, $table);
        return (bool) $this->db->query('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1', [$this->db->prefixTable($table), $column])->getRow();
    }

    private function addIndex(string $table, string $name, string $columns): void
    {
        if ($this->db->DBDriver === 'SQLite3') return;
        $exists = $this->db->query('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? LIMIT 1', [$this->db->prefixTable($table), $name])->getRow();
        if (! $exists) $this->db->query('ALTER TABLE ' . $this->q($table) . " ADD KEY {$name} ({$columns})");
    }

    private function q(string $table): string
    {
        return $this->db->protectIdentifiers($this->db->prefixTable($table));
    }
}
