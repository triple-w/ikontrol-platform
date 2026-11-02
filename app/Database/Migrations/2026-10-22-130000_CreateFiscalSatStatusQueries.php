<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class CreateFiscalSatStatusQueries extends Migration
{
    public function up(): void
    {
        foreach (['fiscal_documents', 'fiscal_document_stamps'] as $required) {
            if (!$this->db->tableExists($required)) {
                throw new RuntimeException("Cannot create SAT status queries: {$required} is missing.");
            }
        }

        if (!$this->db->tableExists('fiscal_sat_status_queries')) {
            $this->forge->addField([
                'id' => ['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],
                'fiscal_document_id' => ['type'=>'BIGINT','unsigned'=>true],
                'fiscal_document_stamp_id' => ['type'=>'BIGINT','unsigned'=>true],
                'cancellation_request_id' => ['type'=>'BIGINT','unsigned'=>true,'null'=>true],
                'uuid' => ['type'=>'CHAR','constraint'=>36],
                'provider' => ['type'=>'VARCHAR','constraint'=>40],
                'environment' => ['type'=>'VARCHAR','constraint'=>20],
                'idempotency_key' => ['type'=>'VARCHAR','constraint'=>80],
                // Non-null only while an external request owns the document lock.
                'lock_document_id' => ['type'=>'BIGINT','unsigned'=>true,'null'=>true],
                'status' => ['type'=>'VARCHAR','constraint'=>40,'default'=>'prepared'],
                'normalized_status' => ['type'=>'VARCHAR','constraint'=>40,'default'=>'unknown'],
                'authoritative_status' => ['type'=>'VARCHAR','constraint'=>40,'default'=>'unknown'],
                'previous_authoritative_status' => ['type'=>'VARCHAR','constraint'=>40,'default'=>'unknown'],
                'provider_code' => ['type'=>'VARCHAR','constraint'=>100,'null'=>true],
                'provider_message' => ['type'=>'TEXT','null'=>true],
                'http_status' => ['type'=>'INT','null'=>true],
                'request_sent' => ['type'=>'TINYINT','constraint'=>1,'default'=>0],
                'timed_out' => ['type'=>'TINYINT','constraint'=>1,'default'=>0],
                'requires_reconciliation' => ['type'=>'TINYINT','constraint'=>1,'default'=>0],
                'commercial_policy' => ['type'=>'VARCHAR','constraint'=>60],
                'commercial_credit_delta' => ['type'=>'INT','default'=>0],
                'may_have_provider_cost' => ['type'=>'TINYINT','constraint'=>1,'default'=>1],
                'queried_by' => ['type'=>'BIGINT','unsigned'=>true],
                'started_at' => ['type'=>'DATETIME'],
                'completed_at' => ['type'=>'DATETIME','null'=>true],
                'cooldown_until' => ['type'=>'DATETIME','null'=>true],
                'created_at' => ['type'=>'DATETIME'],
                'updated_at' => ['type'=>'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('idempotency_key', 'uq_fiscal_sat_status_idempotency');
            $this->forge->addUniqueKey('lock_document_id', 'uq_fiscal_sat_status_document_lock');
            $this->forge->addKey(['fiscal_document_id','created_at'], false, false, 'idx_fiscal_sat_status_document');
            $this->forge->createTable('fiscal_sat_status_queries');
        }

        if (!$this->db->tableExists('fiscal_sat_status_artifacts')) {
            $this->forge->addField([
                'id' => ['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],
                'fiscal_sat_status_query_id' => ['type'=>'BIGINT','unsigned'=>true],
                'artifact_type' => ['type'=>'VARCHAR','constraint'=>30,'default'=>'provider_response'],
                'content_encoding' => ['type'=>'VARCHAR','constraint'=>20,'default'=>'base64'],
                'content_base64' => ['type'=>'LONGTEXT'],
                'decoded_mime_type' => ['type'=>'VARCHAR','constraint'=>80,'default'=>'application/json'],
                'decoded_size_bytes' => ['type'=>'BIGINT','unsigned'=>true],
                'decoded_sha256' => ['type'=>'CHAR','constraint'=>64],
                'created_by' => ['type'=>'BIGINT','unsigned'=>true],
                'created_at' => ['type'=>'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['fiscal_sat_status_query_id','artifact_type'], 'uq_fiscal_sat_status_artifact');
            $this->forge->createTable('fiscal_sat_status_artifacts');
        }
    }

    public function down(): void
    {
        foreach (['fiscal_sat_status_artifacts','fiscal_sat_status_queries'] as $table) {
            if ($this->db->tableExists($table)) $this->forge->dropTable($table);
        }
    }
}
