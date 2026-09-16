<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class HomologateCommercialItemsAndFiscalOverrides extends Migration
{
 public function up(){foreach(['items','proposal_items','invoice_items']as$table){if(!$this->db->fieldExists('cost',$table))$this->forge->addColumn($table,['cost'=>['type'=>'DECIMAL','constraint'=>'18,6','null'=>true,'after'=>'unit_type']]);}foreach(['proposal_items','invoice_items']as$table){if(!$this->db->fieldExists('profit_percentage',$table))$this->forge->addColumn($table,['profit_percentage'=>['type'=>'DECIMAL','constraint'=>'9,6','null'=>true,'after'=>'cost']]);}if(!$this->db->fieldExists('fiscal_override_json','invoice_items'))$this->forge->addColumn('invoice_items',['fiscal_override_json'=>['type'=>'LONGTEXT','null'=>true,'after'=>'taxable']]);}
 public function down(){if($this->db->fieldExists('fiscal_override_json','invoice_items'))$this->forge->dropColumn('invoice_items','fiscal_override_json');foreach(['proposal_items','invoice_items']as$table)if($this->db->fieldExists('profit_percentage',$table))$this->forge->dropColumn($table,'profit_percentage');foreach(['items','proposal_items','invoice_items']as$table)if($this->db->fieldExists('cost',$table))$this->forge->dropColumn($table,'cost');}
}
