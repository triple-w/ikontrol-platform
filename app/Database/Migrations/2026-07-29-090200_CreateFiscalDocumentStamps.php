<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
use RuntimeException;

final class CreateFiscalDocumentStamps extends Migration
{
    public function up(): void
    {
        foreach(['fiscal_documents','fiscal_stamp_attempts','fiscal_document_artifacts']as$t)if(!$this->db->tableExists($t))throw new RuntimeException("Cannot create fiscal stamps: {$t} missing.");
        if($this->db->tableExists('fiscal_document_stamps'))return;
        $this->forge->addField([
            'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],
            'fiscal_document_id'=>['type'=>'BIGINT','unsigned'=>true],
            'stamp_attempt_id'=>['type'=>'BIGINT','unsigned'=>true],
            'stamped_xml_artifact_id'=>['type'=>'BIGINT','unsigned'=>true],
            'uuid'=>['type'=>'CHAR','constraint'=>36],
            'stamp_date'=>['type'=>'DATETIME'],
            'pac_rfc'=>['type'=>'VARCHAR','constraint'=>13],
            'sat_certificate_number'=>['type'=>'VARCHAR','constraint'=>40],
            'cfd_seal'=>['type'=>'TEXT'],
            'sat_seal'=>['type'=>'TEXT'],
            'tfd_version'=>['type'=>'VARCHAR','constraint'=>10],
            'provider'=>['type'=>'VARCHAR','constraint'=>40],
            'environment'=>['type'=>'VARCHAR','constraint'=>20],
            'stamped_xml_sha256'=>['type'=>'CHAR','constraint'=>64],
            'created_at'=>['type'=>'DATETIME'],
        ]);
        $this->forge->addKey('id',true);
        $this->forge->addUniqueKey('fiscal_document_id','uq_stamp_document');
        $this->forge->addUniqueKey('uuid','uq_stamp_uuid');
        $this->forge->addUniqueKey('stamped_xml_artifact_id','uq_stamp_artifact');
        $this->forge->createTable('fiscal_document_stamps');
    }
    public function down(): void { if($this->db->tableExists('fiscal_document_stamps'))$this->forge->dropTable('fiscal_document_stamps'); }
}
