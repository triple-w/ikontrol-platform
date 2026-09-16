<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
use RuntimeException;
class CreateFiscalDocumentArtifacts extends Migration{
 public function up():void{if($this->db->tableExists('fiscal_document_artifacts'))return;if(!$this->db->tableExists('fiscal_documents'))throw new RuntimeException('Cannot create artifacts: fiscal_documents is missing.');$this->forge->addField([
  'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],'fiscal_document_id'=>['type'=>'BIGINT','unsigned'=>true],
  'artifact_type'=>['type'=>'VARCHAR','constraint'=>30,'default'=>'pre_xml'],'storage_path'=>['type'=>'VARCHAR','constraint'=>255],
  'sha256'=>['type'=>'CHAR','constraint'=>64],'byte_size'=>['type'=>'BIGINT','unsigned'=>true],
  'builder_version'=>['type'=>'VARCHAR','constraint'=>20],'schema_version'=>['type'=>'VARCHAR','constraint'=>20],
  'schema_sha256'=>['type'=>'CHAR','constraint'=>64,'null'=>true],'validation_status'=>['type'=>'VARCHAR','constraint'=>40],
  'validation_payload'=>['type'=>'LONGTEXT','null'=>true],'created_by'=>['type'=>'INT','unsigned'=>true,'null'=>true],
  'created_at'=>['type'=>'DATETIME','null'=>true],'superseded_at'=>['type'=>'DATETIME','null'=>true]]);
  $this->forge->addKey('id',true);$this->forge->addUniqueKey(['fiscal_document_id','artifact_type','builder_version','sha256'],'uq_fiscal_artifact_idempotency');$this->forge->addKey(['fiscal_document_id','artifact_type','superseded_at'],false,false,'idx_fiscal_artifact_active');$this->forge->createTable('fiscal_document_artifacts');}
 public function down():void{if($this->db->tableExists('fiscal_document_artifacts'))$this->forge->dropTable('fiscal_document_artifacts');}
}
