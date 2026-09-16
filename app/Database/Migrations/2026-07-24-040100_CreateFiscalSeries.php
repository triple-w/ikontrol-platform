<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class CreateFiscalSeries extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('fiscal_series')) return;
        if (!$this->db->tableExists('fiscal_profiles')) throw new RuntimeException('Cannot create fiscal_series: fiscal_profiles is missing.');
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'issuer_profile_id' => ['type' => 'INT', 'unsigned' => true],
            'document_type' => ['type' => 'VARCHAR', 'constraint' => 20],
            'series' => ['type' => 'VARCHAR', 'constraint' => 25, 'default' => ''],
            'initial_folio' => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 1],
            'current_folio' => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'is_default' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'is_active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'deleted' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['issuer_profile_id', 'document_type', 'series'], 'uq_fiscal_series_issuer_type_series');
        $this->forge->addKey(['issuer_profile_id', 'document_type', 'is_default', 'is_active'], false, false, 'idx_fiscal_series_default');
        $this->forge->createTable('fiscal_series');
    }

    public function down(): void
    {
        if ($this->db->tableExists('fiscal_series')) $this->forge->dropTable('fiscal_series');
    }
}
