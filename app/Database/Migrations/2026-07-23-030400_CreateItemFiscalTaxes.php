<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
use RuntimeException;
class CreateItemFiscalTaxes extends Migration {
 public function up(): void { if($this->db->tableExists('item_fiscal_taxes')) return; foreach(['item_fiscal_settings','taxes'] as $t) if(!$this->db->tableExists($t)) throw new RuntimeException("Required table $t is missing."); $this->forge->addField([
  'id'=>['type'=>'INT','unsigned'=>true,'auto_increment'=>true], 'item_fiscal_setting_id'=>['type'=>'INT','unsigned'=>true], 'tax_id'=>['type'=>'INT','unsigned'=>true],
  'sort_order'=>['type'=>'INT','unsigned'=>true,'default'=>0], 'is_active'=>['type'=>'TINYINT','constraint'=>1,'default'=>1],
  'created_at'=>['type'=>'DATETIME','null'=>true], 'updated_at'=>['type'=>'DATETIME','null'=>true],
 ]);$this->forge->addKey('id',true);$this->forge->addUniqueKey(['item_fiscal_setting_id','tax_id']);$this->forge->addKey(['item_fiscal_setting_id','is_active']);$this->forge->addKey('tax_id');$this->forge->createTable('item_fiscal_taxes'); }
 public function down(): void { /* Relations are retained to avoid deleting fiscal preparation. */ }
}
