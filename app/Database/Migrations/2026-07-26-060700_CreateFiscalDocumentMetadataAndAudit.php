<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class CreateFiscalDocumentMetadataAndAudit extends Migration{
 public function up():void{
  if(!$this->db->tableExists('fiscal_document_metadata')){$this->forge->addField([
   'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],'fiscal_document_id'=>['type'=>'BIGINT','unsigned'=>true],
   'metadata_json'=>['type'=>'LONGTEXT'],'warnings_json'=>['type'=>'LONGTEXT','null'=>true],
   'rules_version'=>['type'=>'VARCHAR','constraint'=>30,'default'=>'ikontrol-fiscal-draft-v1'],'payment_total_snapshot'=>['type'=>'DECIMAL','constraint'=>'18,2','default'=>'0.00'],
   'created_at'=>['type'=>'DATETIME','null'=>true]]);
   $this->forge->addKey('id',true);$this->forge->addUniqueKey('fiscal_document_id','uq_fiscal_document_metadata');$this->forge->createTable('fiscal_document_metadata');}
  if(!$this->db->tableExists('fiscal_document_audit')){$this->forge->addField([
   'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],'fiscal_document_id'=>['type'=>'BIGINT','unsigned'=>true,'null'=>true],
   'invoice_id'=>['type'=>'INT','unsigned'=>true,'null'=>true],'user_id'=>['type'=>'INT','unsigned'=>true,'null'=>true],
   'action'=>['type'=>'VARCHAR','constraint'=>40],'reason'=>['type'=>'VARCHAR','constraint'=>500,'null'=>true],
   'previous_hash'=>['type'=>'CHAR','constraint'=>64,'null'=>true],'new_hash'=>['type'=>'CHAR','constraint'=>64,'null'=>true],
   'created_at'=>['type'=>'DATETIME','null'=>true]]);
   $this->forge->addKey('id',true);$this->forge->addKey(['fiscal_document_id','created_at']);$this->forge->addKey(['invoice_id','created_at']);$this->forge->createTable('fiscal_document_audit');}
 }
 public function down():void{foreach(['fiscal_document_audit','fiscal_document_metadata']as$t)if($this->db->tableExists($t))$this->forge->dropTable($t);}
}
