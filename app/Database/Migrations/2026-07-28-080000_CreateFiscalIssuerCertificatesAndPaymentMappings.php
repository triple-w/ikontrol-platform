<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class CreateFiscalIssuerCertificatesAndPaymentMappings extends Migration
{
    public function up(): void
    {
        foreach (['fiscal_profiles', 'payment_methods', 'sat_payment_forms'] as $table) {
            if (!$this->db->tableExists($table)) {
                throw new RuntimeException("Cannot prepare Increment 08: {$table} is missing.");
            }
        }

        if (!$this->db->tableExists('fiscal_issuer_certificates')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'issuer_profile_id' => ['type' => 'INT', 'unsigned' => true],
                'certificate_number' => ['type' => 'VARCHAR', 'constraint' => 40],
                'certificate_serial_hex' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
                'certificate_subject' => ['type' => 'VARCHAR', 'constraint' => 500],
                'certificate_rfc' => ['type' => 'VARCHAR', 'constraint' => 13],
                'valid_from' => ['type' => 'DATETIME'],
                'valid_to' => ['type' => 'DATETIME'],
                'certificate_sha256' => ['type' => 'CHAR', 'constraint' => 64],
                'public_certificate_path' => ['type' => 'VARCHAR', 'constraint' => 255],
                'encrypted_private_key_path' => ['type' => 'VARCHAR', 'constraint' => 255],
                'private_key_sha256' => ['type' => 'CHAR', 'constraint' => 64],
                'encryption_key_version' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'password-v1'],
                'status' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'pending_validation'],
                'is_default' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
                'revoked_at' => ['type' => 'DATETIME', 'null' => true],
                'deleted' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['issuer_profile_id', 'certificate_sha256'], 'uq_issuer_certificate_hash');
            $this->forge->addKey(['issuer_profile_id', 'status', 'is_default', 'deleted'], false, false, 'idx_issuer_certificate_status');
            $this->forge->addKey(['valid_from', 'valid_to'], false, false, 'idx_issuer_certificate_validity');
            $this->forge->createTable('fiscal_issuer_certificates');
        }

        if (!$this->db->tableExists('fiscal_payment_method_mappings')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'payment_method_id' => ['type' => 'INT', 'unsigned' => true],
                'sat_payment_form_code' => ['type' => 'VARCHAR', 'constraint' => 3],
                'is_active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('payment_method_id', 'uq_fiscal_payment_method_mapping');
            $this->forge->addKey(['sat_payment_form_code', 'is_active'], false, false, 'idx_fiscal_payment_form_mapping');
            $this->forge->createTable('fiscal_payment_method_mappings');
        }
    }

    public function down(): void
    {
        foreach (['fiscal_payment_method_mappings', 'fiscal_issuer_certificates'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->forge->dropTable($table);
            }
        }
    }
}
