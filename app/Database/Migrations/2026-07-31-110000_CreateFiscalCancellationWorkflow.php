<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class CreateFiscalCancellationWorkflow extends Migration
{
    public function up(): void
    {
        foreach (['fiscal_documents', 'fiscal_document_stamps'] as $table) {
            if (!$this->db->tableExists($table)) {
                throw new RuntimeException("Cannot create fiscal cancellations: {$table} is missing.");
            }
        }
        if (!$this->db->tableExists('fiscal_cancellation_requests')) {
            $this->forge->addField([
                'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],
                'fiscal_document_id'=>['type'=>'BIGINT','unsigned'=>true],
                'fiscal_document_stamp_id'=>['type'=>'BIGINT','unsigned'=>true],
                'uuid'=>['type'=>'CHAR','constraint'=>36],
                'issuer_rfc'=>['type'=>'VARCHAR','constraint'=>20],
                'receiver_rfc'=>['type'=>'VARCHAR','constraint'=>20],
                'total'=>['type'=>'DECIMAL','constraint'=>'18,6'],
                'cancellation_reason'=>['type'=>'CHAR','constraint'=>2],
                'replacement_uuid'=>['type'=>'CHAR','constraint'=>36,'null'=>true],
                'provider'=>['type'=>'VARCHAR','constraint'=>40],
                'environment'=>['type'=>'VARCHAR','constraint'=>20],
                'status'=>['type'=>'VARCHAR','constraint'=>30,'default'=>'requested'],
                'provider_code'=>['type'=>'VARCHAR','constraint'=>100,'null'=>true],
                'provider_message'=>['type'=>'TEXT','null'=>true],
                'requested_at'=>['type'=>'DATETIME'],
                'confirmed_at'=>['type'=>'DATETIME','null'=>true],
                'cancelled_at'=>['type'=>'DATETIME','null'=>true],
                'user_id'=>['type'=>'BIGINT','unsigned'=>true],
                'idempotency_key'=>['type'=>'CHAR','constraint'=>64],
                'requires_reconciliation'=>['type'=>'TINYINT','constraint'=>1,'default'=>0],
                'created_at'=>['type'=>'DATETIME'],
                'updated_at'=>['type'=>'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('idempotency_key');
            $this->forge->addKey(['fiscal_document_id','status']);
            $this->forge->addKey('uuid');
            $this->forge->createTable('fiscal_cancellation_requests');
        }
        if (!$this->db->tableExists('fiscal_cancellation_attempts')) {
            $this->forge->addField([
                'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],
                'fiscal_cancellation_request_id'=>['type'=>'BIGINT','unsigned'=>true],
                'status'=>['type'=>'VARCHAR','constraint'=>30,'default'=>'prepared'],
                'provider_code'=>['type'=>'VARCHAR','constraint'=>100,'null'=>true],
                'provider_message'=>['type'=>'TEXT','null'=>true],
                'response_hash'=>['type'=>'CHAR','constraint'=>64,'null'=>true],
                'requires_reconciliation'=>['type'=>'TINYINT','constraint'=>1,'default'=>0],
                'started_at'=>['type'=>'DATETIME'],
                'responded_at'=>['type'=>'DATETIME','null'=>true],
                'created_at'=>['type'=>'DATETIME'],
                'updated_at'=>['type'=>'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('fiscal_cancellation_request_id');
            $this->forge->createTable('fiscal_cancellation_attempts');
        }
        if (!$this->db->tableExists('fiscal_cancellation_artifacts')) {
            $this->forge->addField([
                'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],
                'fiscal_cancellation_request_id'=>['type'=>'BIGINT','unsigned'=>true],
                'fiscal_cancellation_attempt_id'=>['type'=>'BIGINT','unsigned'=>true],
                'artifact_type'=>['type'=>'VARCHAR','constraint'=>30,'default'=>'cancellation_ack'],
                'content_encoding'=>['type'=>'VARCHAR','constraint'=>20,'default'=>'base64'],
                'content_base64'=>['type'=>'LONGTEXT'],
                'decoded_mime_type'=>['type'=>'VARCHAR','constraint'=>80],
                'decoded_size_bytes'=>['type'=>'BIGINT','unsigned'=>true],
                'decoded_sha256'=>['type'=>'CHAR','constraint'=>64],
                'created_by'=>['type'=>'BIGINT','unsigned'=>true],
                'created_at'=>['type'=>'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['fiscal_cancellation_request_id','artifact_type']);
            $this->forge->createTable('fiscal_cancellation_artifacts');
        }
    }

    public function down(): void
    {
        foreach (['fiscal_cancellation_artifacts','fiscal_cancellation_attempts','fiscal_cancellation_requests'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->forge->dropTable($table);
            }
        }
    }
}
