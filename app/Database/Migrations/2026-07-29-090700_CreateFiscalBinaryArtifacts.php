<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class CreateFiscalBinaryArtifacts extends Migration
{
    public function up():void
    {
        foreach(['fiscal_documents','fiscal_stamp_attempts','fiscal_document_stamps']as$table)if(!$this->db->tableExists($table))throw new RuntimeException("Cannot create fiscal binary artifacts: {$table} missing.");
        if(!$this->db->tableExists('fiscal_document_binary_artifacts')){
            $this->forge->addField([
                'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],
                'fiscal_document_id'=>['type'=>'BIGINT','unsigned'=>true],
                'stamp_attempt_id'=>['type'=>'BIGINT','unsigned'=>true],
                'artifact_type'=>['type'=>'VARCHAR','constraint'=>30],
                'content_encoding'=>['type'=>'VARCHAR','constraint'=>20,'default'=>'base64'],
                'content_base64'=>['type'=>'LONGTEXT'],
                'decoded_mime_type'=>['type'=>'VARCHAR','constraint'=>80],
                'decoded_size_bytes'=>['type'=>'BIGINT','unsigned'=>true],
                'decoded_sha256'=>['type'=>'CHAR','constraint'=>64],
                'provider'=>['type'=>'VARCHAR','constraint'=>40],
                'template'=>['type'=>'VARCHAR','constraint'=>80,'null'=>true],
                'uuid'=>['type'=>'CHAR','constraint'=>36],
                'validation_status'=>['type'=>'VARCHAR','constraint'=>30],
                'created_by'=>['type'=>'BIGINT','unsigned'=>true],
                'created_at'=>['type'=>'DATETIME'],
            ]);
            $this->forge->addKey('id',true);
            $this->forge->addUniqueKey(['fiscal_document_id','artifact_type'],'uq_fiscal_binary_document_type');
            // CI 4.6 addKey() accepts field/primary/unique only. Passing an
            // index name as a fourth argument caused the original migration
            // to fail before the table was created.
            $this->forge->addKey('stamp_attempt_id');
            $this->forge->addKey('uuid');
            $this->forge->createTable('fiscal_document_binary_artifacts');
        }
        $fields=[];
        if(!$this->db->fieldExists('pac_pdf_artifact_id','fiscal_document_stamps'))$fields['pac_pdf_artifact_id']=['type'=>'BIGINT','unsigned'=>true,'null'=>true,'after'=>'stamped_xml_artifact_id'];
        if(!$this->db->fieldExists('pdf_status','fiscal_document_stamps'))$fields['pdf_status']=['type'=>'VARCHAR','constraint'=>30,'default'=>'pending','after'=>'pac_pdf_artifact_id'];
        if(!$this->db->fieldExists('pdf_template','fiscal_document_stamps'))$fields['pdf_template']=['type'=>'VARCHAR','constraint'=>80,'null'=>true,'after'=>'pdf_status'];
        if($fields)$this->forge->addColumn('fiscal_document_stamps',$fields);
    }
    public function down():void
    {
        if($this->db->tableExists('fiscal_document_stamps'))foreach(['pdf_template','pdf_status','pac_pdf_artifact_id']as$field)if($this->db->fieldExists($field,'fiscal_document_stamps'))$this->forge->dropColumn('fiscal_document_stamps',$field);
        if($this->db->tableExists('fiscal_document_binary_artifacts'))$this->forge->dropTable('fiscal_document_binary_artifacts');
    }
}
