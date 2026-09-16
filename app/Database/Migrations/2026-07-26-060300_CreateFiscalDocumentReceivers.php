<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class CreateFiscalDocumentReceivers extends Migration{
 public function up():void{if($this->db->tableExists('fiscal_document_receivers'))return;$this->forge->addField([
  'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],'fiscal_document_id'=>['type'=>'BIGINT','unsigned'=>true],
  'rfc'=>['type'=>'VARCHAR','constraint'=>13],'legal_name'=>['type'=>'VARCHAR','constraint'=>254],'tax_regime_code'=>['type'=>'VARCHAR','constraint'=>5],
  'fiscal_postal_code'=>['type'=>'VARCHAR','constraint'=>5],'cfdi_use_code'=>['type'=>'VARCHAR','constraint'=>5],
  'fiscal_residence_country_code'=>['type'=>'CHAR','constraint'=>3,'null'=>true],'foreign_tax_registration'=>['type'=>'VARCHAR','constraint'=>40,'null'=>true],
  'street'=>['type'=>'VARCHAR','constraint'=>255,'null'=>true],'external_number'=>['type'=>'VARCHAR','constraint'=>30,'null'=>true],
  'internal_number'=>['type'=>'VARCHAR','constraint'=>30,'null'=>true],'neighborhood'=>['type'=>'VARCHAR','constraint'=>150,'null'=>true],
  'locality'=>['type'=>'VARCHAR','constraint'=>150,'null'=>true],'municipality'=>['type'=>'VARCHAR','constraint'=>150,'null'=>true],
  'state'=>['type'=>'VARCHAR','constraint'=>100,'null'=>true],'created_at'=>['type'=>'DATETIME','null'=>true]]);
  $this->forge->addKey('id',true);$this->forge->addUniqueKey('fiscal_document_id','uq_fiscal_document_receiver');$this->forge->createTable('fiscal_document_receivers');}
 public function down():void{if($this->db->tableExists('fiscal_document_receivers'))$this->forge->dropTable('fiscal_document_receivers');}
}
