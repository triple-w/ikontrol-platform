<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class UnifyCommercialItemPricingAndSupplierHistory extends Migration
{
 public function up(){
  foreach(['proposal_items','estimate_items','invoice_items'] as $table){if(!$this->db->fieldExists('price_origin',$table))$this->forge->addColumn($table,['price_origin'=>['type'=>'VARCHAR','constraint'=>20,'null'=>true,'after'=>'rate']]);}
  foreach(['estimate_items','invoice_items'] as $table){if(!$this->db->fieldExists('supplier_id',$table)){$this->forge->addColumn($table,['supplier_id'=>['type'=>'INT','unsigned'=>true,'null'=>true,'after'=>'item_id']]);$this->db->query('ALTER TABLE '.$this->db->prefixTable($table).' ADD INDEX idx_'.$table.'_supplier (supplier_id)');}}
  $h='product_supplier_cost_history';if(!$this->db->tableExists($h))return;$t=$this->db->prefixTable($h);
  foreach(['source_type'=>['type'=>'VARCHAR','constraint'=>20,'null'=>true,'after'=>'id'],'source_id'=>['type'=>'INT','null'=>true,'after'=>'source_type'],'source_item_id'=>['type'=>'INT','null'=>true,'after'=>'source_id'],'source_folio'=>['type'=>'VARCHAR','constraint'=>80,'null'=>true,'after'=>'source_item_id']] as $name=>$field)if(!$this->db->fieldExists($name,$h))$this->forge->addColumn($h,[$name=>$field]);
  $this->db->query("UPDATE {$t} SET source_type='proposal',source_id=proposal_id,source_item_id=proposal_item_id WHERE source_type IS NULL");
  foreach(['fk_cost_history_proposal_item','fk_cost_history_proposal'] as $fk)try{$this->db->query("ALTER TABLE {$t} DROP FOREIGN KEY {$fk}");}catch(\Throwable){}
  $this->db->query("ALTER TABLE {$t} MODIFY proposal_id INT NULL, MODIFY proposal_item_id INT NULL");
  try{$this->db->query("ALTER TABLE {$t} DROP INDEX uq_supplier_cost_item_economic");}catch(\Throwable){}
  $indexes=$this->db->getIndexData($h);if(!isset($indexes['uq_cost_history_source_economic']))$this->db->query("ALTER TABLE {$t} ADD UNIQUE KEY uq_cost_history_source_economic(source_type,source_item_id,economic_hash)");if(!isset($indexes['idx_cost_history_source']))$this->db->query("ALTER TABLE {$t} ADD INDEX idx_cost_history_source(source_type,source_id,source_item_id)");
 }
 public function down(){/* Datos y semántica histórica nueva se conservan deliberadamente. */}
}
