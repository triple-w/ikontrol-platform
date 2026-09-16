<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class CreateFiscalDocumentSignatures extends Migration
{
    public function up(): void
    {
        foreach (['fiscal_documents', 'fiscal_document_artifacts', 'fiscal_issuer_certificates'] as $table) {
            if (!$this->db->tableExists($table)) {
                throw new RuntimeException("Cannot create fiscal_document_signatures: {$table} is missing.");
            }
        }
        if ($this->db->tableExists('fiscal_document_signatures')) {
            return;
        }
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'fiscal_document_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'pre_xml_artifact_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'certificate_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'original_chain_artifact_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'signed_xml_artifact_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'pre_xml_sha256' => ['type' => 'CHAR', 'constraint' => 64],
            'original_chain_sha256' => ['type' => 'CHAR', 'constraint' => 64],
            'signed_xml_sha256' => ['type' => 'CHAR', 'constraint' => 64],
            'signature_verified' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'xsd_status' => ['type' => 'VARCHAR', 'constraint' => 40],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['fiscal_document_id', 'pre_xml_sha256', 'certificate_id'], 'uq_fiscal_document_signature');
        $this->forge->addKey(['fiscal_document_id', 'created_at'], false, false, 'idx_fiscal_document_signature');
        $this->forge->createTable('fiscal_document_signatures');
    }

    public function down(): void
    {
        if ($this->db->tableExists('fiscal_document_signatures')) {
            $this->forge->dropTable('fiscal_document_signatures');
        }
    }
}
