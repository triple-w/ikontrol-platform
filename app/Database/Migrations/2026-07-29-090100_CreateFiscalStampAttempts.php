<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
use RuntimeException;

final class CreateFiscalStampAttempts extends Migration
{
    public function up(): void
    {
        foreach(['fiscal_documents','fiscal_document_artifacts','fiscal_pac_configurations'] as $t)if(!$this->db->tableExists($t))throw new RuntimeException("Cannot create stamp attempts: {$t} missing.");
        if($this->db->tableExists('fiscal_stamp_attempts'))return;
        $this->forge->addField([
            'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],
            'fiscal_document_id'=>['type'=>'BIGINT','unsigned'=>true],
            'signed_xml_artifact_id'=>['type'=>'BIGINT','unsigned'=>true],
            'pac_configuration_id'=>['type'=>'BIGINT','unsigned'=>true],
            'provider'=>['type'=>'VARCHAR','constraint'=>40],
            'environment'=>['type'=>'VARCHAR','constraint'=>20],
            'operation'=>['type'=>'VARCHAR','constraint'=>30,'default'=>'timbrar'],
            'request_hash'=>['type'=>'CHAR','constraint'=>64],
            'idempotency_key'=>['type'=>'CHAR','constraint'=>64],
            'attempt_number'=>['type'=>'INT','unsigned'=>true,'default'=>1],
            'status'=>['type'=>'VARCHAR','constraint'=>40,'default'=>'pending'],
            'started_at'=>['type'=>'DATETIME'],
            'sent_at'=>['type'=>'DATETIME','null'=>true],
            'responded_at'=>['type'=>'DATETIME','null'=>true],
            'http_status'=>['type'=>'INT','unsigned'=>true,'null'=>true],
            'provider_code'=>['type'=>'VARCHAR','constraint'=>50,'null'=>true],
            'provider_message'=>['type'=>'VARCHAR','constraint'=>500,'null'=>true],
            'error_category'=>['type'=>'VARCHAR','constraint'=>50,'null'=>true],
            'retryable'=>['type'=>'TINYINT','constraint'=>1,'default'=>0],
            'pac_reference'=>['type'=>'VARCHAR','constraint'=>120,'null'=>true],
            'uuid'=>['type'=>'CHAR','constraint'=>36,'null'=>true],
            'response_hash'=>['type'=>'CHAR','constraint'=>64,'null'=>true],
            'contingency_path'=>['type'=>'VARCHAR','constraint'=>255,'null'=>true],
            'duration_ms'=>['type'=>'INT','unsigned'=>true,'null'=>true],
            'created_by'=>['type'=>'INT','unsigned'=>true,'null'=>true],
            'created_at'=>['type'=>'DATETIME'],
            'updated_at'=>['type'=>'DATETIME','null'=>true],
        ]);
        $this->forge->addKey('id',true);
        $this->forge->addUniqueKey('idempotency_key','uq_stamp_idempotency');
        $this->forge->addKey(['fiscal_document_id','status'],false,false,'idx_stamp_document_status');
        $this->forge->createTable('fiscal_stamp_attempts');
    }
    public function down(): void { if($this->db->tableExists('fiscal_stamp_attempts'))$this->forge->dropTable('fiscal_stamp_attempts'); }
}
