<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateLegacyImportRegistry extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('legacy_import_batches')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'source_system' => ['type' => 'VARCHAR', 'constraint' => 50],
                'source_database' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'source_owner_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'source_owner_key' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'entity_scope' => ['type' => 'VARCHAR', 'constraint' => 50],
                'source_backup_name' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'source_backup_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
                'status' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'pending'],
                'started_at' => ['type' => 'DATETIME', 'null' => true],
                'completed_at' => ['type' => 'DATETIME', 'null' => true],
                'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'summary_json' => ['type' => 'LONGTEXT', 'null' => true],
                'error_message' => ['type' => 'LONGTEXT', 'null' => true],
                'created_at' => ['type' => 'DATETIME'],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey(['source_system', 'source_owner_key'], false, false, 'idx_legacy_batch_source_owner');
            $this->forge->addKey('status', false, false, 'idx_legacy_batch_status');
            $this->forge->createTable('legacy_import_batches');
        }

        if (! $this->db->tableExists('legacy_import_mappings')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'import_batch_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'source_system' => ['type' => 'VARCHAR', 'constraint' => 50],
                'source_table' => ['type' => 'VARCHAR', 'constraint' => 100],
                // Empty string is the canonical representation of an absent owner. This
                // avoids MySQL UNIQUE/NULL semantics and makes source identity reliable.
                'source_owner_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => ''],
                'source_id' => ['type' => 'VARCHAR', 'constraint' => 100],
                'destination_table' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'destination_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'source_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
                'destination_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
                'status' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'pending'],
                'action' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
                'warnings_json' => ['type' => 'LONGTEXT', 'null' => true],
                'source_snapshot_json' => ['type' => 'LONGTEXT', 'null' => true],
                'imported_at' => ['type' => 'DATETIME', 'null' => true],
                'created_at' => ['type' => 'DATETIME'],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(
                ['source_system', 'source_table', 'source_owner_id', 'source_id'],
                'uq_legacy_mapping_source'
            );
            $this->forge->addKey('import_batch_id', false, false, 'idx_legacy_mapping_batch');
            $this->forge->addKey(['destination_table', 'destination_id'], false, false, 'idx_legacy_mapping_destination');
            $this->forge->addKey('status', false, false, 'idx_legacy_mapping_status');
            $this->forge->createTable('legacy_import_mappings');
        }
    }

    public function down(): void
    {
        // Registry data is evidence. Rollback is deliberately non-destructive.
    }
}
