<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class CreateFiscalDocumentTaxTotals extends Migration{
 public function up():void{if($this->db->tableExists('fiscal_document_tax_totals'))return;$this->forge->addField([
  'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],'fiscal_document_id'=>['type'=>'BIGINT','unsigned'=>true],
  'tax_code'=>['type'=>'VARCHAR','constraint'=>3],'tax_type'=>['type'=>'VARCHAR','constraint'=>20],
  'factor_type'=>['type'=>'VARCHAR','constraint'=>10],'rate_or_quota'=>['type'=>'DECIMAL','constraint'=>'18,6','null'=>true],
  'taxable_base'=>['type'=>'DECIMAL','constraint'=>'18,2','default'=>'0.00'],'amount'=>['type'=>'DECIMAL','constraint'=>'18,2','default'=>'0.00'],
  'created_at'=>['type'=>'DATETIME','null'=>true]]);
  $this->forge->addKey('id',true);$this->forge->addUniqueKey(['fiscal_document_id','tax_code','tax_type','factor_type','rate_or_quota'],'uq_fiscal_document_tax_total');$this->forge->createTable('fiscal_document_tax_totals');}
 public function down():void{if($this->db->tableExists('fiscal_document_tax_totals'))$this->forge->dropTable('fiscal_document_tax_totals');}
}
