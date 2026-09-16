<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMinimalSatCatalogs extends Migration
{
    public function up(): void
    {
        $this->createApplicabilityCatalog('sat_tax_regimes');
        $this->createApplicabilityCatalog('sat_cfdi_uses');

        if (! $this->db->tableExists('sat_tax_codes')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'code' => ['type' => 'VARCHAR', 'constraint' => 3],
                'name' => ['type' => 'VARCHAR', 'constraint' => 30],
                'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'is_active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('code');
            $this->forge->addKey('is_active');
            $this->forge->createTable('sat_tax_codes');
        }

        if (! $this->db->tableExists('sat_tax_factor_types')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'code' => ['type' => 'VARCHAR', 'constraint' => 20],
                'name' => ['type' => 'VARCHAR', 'constraint' => 30],
                'is_active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('code');
            $this->forge->addKey('is_active');
            $this->forge->createTable('sat_tax_factor_types');
        }
    }

    private function createApplicabilityCatalog(string $table): void
    {
        if ($this->db->tableExists($table)) {
            return;
        }
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'code' => ['type' => 'VARCHAR', 'constraint' => 10],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255],
            'applies_to_individual' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'applies_to_company' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'valid_from' => ['type' => 'DATE', 'null' => true],
            'valid_to' => ['type' => 'DATE', 'null' => true],
            'is_active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('code');
        $this->forge->addKey(['valid_from', 'valid_to']);
        $this->forge->addKey('is_active');
        $this->forge->createTable($table);
    }

    public function down(): void
    {
        foreach (['sat_tax_factor_types', 'sat_tax_codes', 'sat_cfdi_uses', 'sat_tax_regimes'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->forge->dropTable($table);
            }
        }
    }
}
