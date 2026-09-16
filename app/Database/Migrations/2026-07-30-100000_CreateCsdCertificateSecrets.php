<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class CreateCsdCertificateSecrets extends Migration
{
    public function up(): void
    {
        if (!$this->db->tableExists('fiscal_issuer_certificates')) {
            throw new RuntimeException('Cannot create CSD secrets: fiscal_issuer_certificates is missing.');
        }

        if (!$this->db->tableExists('fiscal_issuer_certificate_secrets')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'fiscal_issuer_certificate_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'secret_type' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'private_key_password'],
                'encrypted_payload' => ['type' => 'LONGTEXT'],
                'encryption_version' => ['type' => 'VARCHAR', 'constraint' => 30],
                'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
                'validated_at' => ['type' => 'DATETIME'],
                'created_at' => ['type' => 'DATETIME'],
                'updated_at' => ['type' => 'DATETIME'],
                'rotated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(
                ['fiscal_issuer_certificate_id', 'secret_type'],
                'uq_csd_certificate_secret_type'
            );
            $this->forge->addKey(['fiscal_issuer_certificate_id', 'status']);
            $this->forge->createTable('fiscal_issuer_certificate_secrets');
        }

        if (!$this->db->tableExists('fiscal_issuer_certificate_secret_audit')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'fiscal_issuer_certificate_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'action' => ['type' => 'VARCHAR', 'constraint' => 50],
                'result' => ['type' => 'VARCHAR', 'constraint' => 20],
                'error_code' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
                'created_at' => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey(['fiscal_issuer_certificate_id', 'created_at']);
            $this->forge->createTable('fiscal_issuer_certificate_secret_audit');
        }
    }

    public function down(): void
    {
        foreach ([
            'fiscal_issuer_certificate_secret_audit',
            'fiscal_issuer_certificate_secrets',
        ] as $table) {
            if ($this->db->tableExists($table)) {
                $this->forge->dropTable($table);
            }
        }
    }
}
