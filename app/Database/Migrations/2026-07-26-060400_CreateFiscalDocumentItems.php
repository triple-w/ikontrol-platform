<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class CreateFiscalDocumentItems extends Migration{
 public function up():void{if($this->db->tableExists('fiscal_document_items'))return;$m=['type'=>'DECIMAL','constraint'=>'18,2','default'=>'0.00'];$this->forge->addField([
  'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],'fiscal_document_id'=>['type'=>'BIGINT','unsigned'=>true],
  'invoice_item_id'=>['type'=>'INT','unsigned'=>true,'null'=>true],'item_id'=>['type'=>'INT','unsigned'=>true,'null'=>true],
  'line_number'=>['type'=>'INT','unsigned'=>true],'product_service_code'=>['type'=>'VARCHAR','constraint'=>8],
  'identification_number'=>['type'=>'VARCHAR','constraint'=>100,'null'=>true],'quantity'=>['type'=>'DECIMAL','constraint'=>'18,6'],
  'unit_code'=>['type'=>'VARCHAR','constraint'=>5],'unit_name'=>['type'=>'VARCHAR','constraint'=>100],
  'description'=>['type'=>'TEXT'],'unit_value'=>['type'=>'DECIMAL','constraint'=>'18,6'],'gross_amount'=>$m,'discount'=>$m,
  'tax_object_code'=>['type'=>'VARCHAR','constraint'=>3],'taxable_base'=>$m,'transferred_tax_total'=>$m,
  'withheld_tax_total'=>$m,'line_total'=>$m,'created_at'=>['type'=>'DATETIME','null'=>true]]);
  $this->forge->addKey('id',true);$this->forge->addUniqueKey(['fiscal_document_id','line_number'],'uq_fiscal_document_line');$this->forge->createTable('fiscal_document_items');}
 public function down():void{if($this->db->tableExists('fiscal_document_items'))$this->forge->dropTable('fiscal_document_items');}
}
