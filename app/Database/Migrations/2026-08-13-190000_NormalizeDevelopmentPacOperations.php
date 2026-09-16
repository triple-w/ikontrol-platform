<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class NormalizeDevelopmentPacOperations extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('fiscal_stamp_accounts') && !$this->db->fieldExists('environment', 'fiscal_stamp_accounts')) {
            $this->forge->addColumn('fiscal_stamp_accounts', [
                'environment' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'development', 'after' => 'issuer_profile_id'],
            ]);
            $table = $this->db->protectIdentifiers($this->db->prefixTable('fiscal_stamp_accounts'));
            $indexes = $this->db->query("SHOW INDEX FROM {$table}")->getResult();
            if (array_filter($indexes, static fn($row) => $row->Key_name === 'uq_stamp_account_issuer')) {
                $this->db->query("ALTER TABLE {$table} DROP INDEX uq_stamp_account_issuer");
            }
            $this->db->query("ALTER TABLE {$table} ADD UNIQUE KEY uq_stamp_account_issuer_environment (issuer_profile_id, environment)");
        }
        if ($this->db->tableExists('fiscal_stamp_movements') && !$this->db->fieldExists('environment', 'fiscal_stamp_movements')) {
            $this->forge->addColumn('fiscal_stamp_movements', [
                'environment' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'development', 'after' => 'stamp_account_id'],
            ]);
        }
        if ($this->db->tableExists('fiscal_stamp_attempts')) {
            $fields = [
                'response_content_type' => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true],
                'response_body_length' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'response_body_sha256' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
                'parsing_phase' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'response_error_class' => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true],
                'response_error_message' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'response_structure' => ['type' => 'TEXT', 'null' => true],
            ];
            foreach ($fields as $name => $definition) {
                if (!$this->db->fieldExists($name, 'fiscal_stamp_attempts')) {
                    $this->forge->addColumn('fiscal_stamp_attempts', [$name => $definition]);
                }
            }
        }
        if (!$this->db->tableExists('fiscal_pac_credit_consultations')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'issuer_profile_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'provider' => ['type' => 'VARCHAR', 'constraint' => 40],
                'environment' => ['type' => 'VARCHAR', 'constraint' => 20],
                'available_credits' => ['type' => 'INT', 'unsigned' => true],
                'provider_code' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
                'provider_message' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'http_status' => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
                'response_sha256' => ['type' => 'CHAR', 'constraint' => 64],
                'consulted_at' => ['type' => 'DATETIME'],
                'created_by' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            foreach (['issuer_profile_id', 'environment', 'consulted_at'] as $key) $this->forge->addKey($key);
            $this->forge->createTable('fiscal_pac_credit_consultations');
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('fiscal_pac_credit_consultations')) $this->forge->dropTable('fiscal_pac_credit_consultations');
    }
}
