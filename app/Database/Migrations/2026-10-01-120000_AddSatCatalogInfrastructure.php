<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class AddSatCatalogInfrastructure extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('sat_catalog_installations')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'catalog_name' => ['type' => 'VARCHAR', 'constraint' => 80],
                'source' => ['type' => 'VARCHAR', 'constraint' => 255],
                'source_version' => ['type' => 'VARCHAR', 'constraint' => 120],
                'source_checksum' => ['type' => 'CHAR', 'constraint' => 64],
                'source_generated_at' => ['type' => 'DATETIME', 'null' => true],
                'installed_at' => ['type' => 'DATETIME'],
                'row_count' => ['type' => 'INT', 'unsigned' => true],
                'active_row_count' => ['type' => 'INT', 'unsigned' => true],
                'metadata_json' => ['type' => 'TEXT', 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('catalog_name', 'uq_sat_catalog_installations_name');
            $this->forge->createTable('sat_catalog_installations');
        }

        foreach (['sat_product_service_keys', 'sat_unit_keys'] as $table) {
            if (! $this->db->tableExists($table)) {
                throw new RuntimeException("SAT catalog prerequisite missing: {$table}");
            }
            if (! $this->db->fieldExists('normalized_description', $table)) {
                $this->forge->addColumn($table, ['normalized_description' => ['type' => 'VARCHAR', 'constraint' => 600, 'null' => true]]);
            }
            $this->db->resetDataCache();
            $description = $table === 'sat_unit_keys' ? "COALESCE(NULLIF(name, ''), description, '')" : 'description';
            foreach ($this->db->table($table)->select('id, ' . $description . ' AS description')->get()->getResultArray() as $row) {
                $this->db->table($table)->where('id', $row['id'])->update(['normalized_description' => self::normalize((string) $row['description'])]);
            }
            $this->index($table, 'idx_sat_active_code', 'is_active,code');
            $this->index($table, 'idx_sat_active_normalized_description', 'is_active,normalized_description');
        }
    }

    public function down(): void
    {
        // Catalog metadata and normalized values protect installed history.
    }

    private function index(string $table, string $name, string $columns): void
    {
        if ($this->db->DBDriver === 'SQLite3') return;
        $exists = $this->db->query('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? LIMIT 1', [$this->db->prefixTable($table), $name])->getRow();
        if (! $exists) $this->db->query('ALTER TABLE ' . $this->db->protectIdentifiers($this->db->prefixTable($table)) . " ADD KEY {$name} ({$columns})");
    }

    private static function normalize(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        return preg_replace('/[^a-z0-9]+/', ' ', $value) ? trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value)) : '';
    }
}
