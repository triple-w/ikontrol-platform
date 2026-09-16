<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class CreateFiscalDocumentItemTaxes extends Migration{
 public function up():void{if($this->db->tableExists('fiscal_document_item_taxes'))return;$this->forge->addField([
  'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],'fiscal_document_item_id'=>['type'=>'BIGINT','unsigned'=>true],
  'administrative_tax_id'=>['type'=>'INT','unsigned'=>true,'null'=>true],'tax_code'=>['type'=>'VARCHAR','constraint'=>3],
  'tax_type'=>['type'=>'VARCHAR','constraint'=>20],'factor_type'=>['type'=>'VARCHAR','constraint'=>10],
  'rate_or_quota'=>['type'=>'DECIMAL','constraint'=>'18,6','null'=>true],'taxable_base'=>['type'=>'DECIMAL','constraint'=>'18,2','default'=>'0.00'],
  'amount'=>['type'=>'DECIMAL','constraint'=>'18,2','default'=>'0.00'],'sort_order'=>['type'=>'INT','unsigned'=>true,'default'=>0],
  'created_at'=>['type'=>'DATETIME','null'=>true]]);
  $this->forge->addKey('id',true);$this->forge->addKey(['fiscal_document_item_id','sort_order']);$this->forge->createTable('fiscal_document_item_taxes');}
 public function down():void{if($this->db->tableExists('fiscal_document_item_taxes'))$this->forge->dropTable('fiscal_document_item_taxes');}
}
