<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
use RuntimeException;
class CreateItemFiscalSettings extends Migration {
 public function up(): void { if($this->db->tableExists('item_fiscal_settings')) return; foreach(['items','sat_product_service_keys','sat_unit_keys','sat_tax_object_codes'] as $t) if(!$this->db->tableExists($t)) throw new RuntimeException("Required table $t is missing."); $this->forge->addField([
  'id'=>['type'=>'INT','unsigned'=>true,'auto_increment'=>true], 'item_id'=>['type'=>'INT','unsigned'=>true], 'item_type'=>['type'=>'VARCHAR','constraint'=>20],
  'sat_product_service_key_id'=>['type'=>'INT','unsigned'=>true,'null'=>true], 'sat_unit_key_id'=>['type'=>'INT','unsigned'=>true,'null'=>true],
  'commercial_unit'=>['type'=>'VARCHAR','constraint'=>120,'null'=>true], 'tax_object_code_id'=>['type'=>'INT','unsigned'=>true,'null'=>true],
  'fiscal_description'=>['type'=>'TEXT','null'=>true], 'identification_number'=>['type'=>'VARCHAR','constraint'=>100,'null'=>true],
  'is_default'=>['type'=>'TINYINT','constraint'=>1,'default'=>1], 'status'=>['type'=>'VARCHAR','constraint'=>20,'default'=>'incomplete'],
  'valid_from'=>['type'=>'DATE','null'=>true], 'valid_to'=>['type'=>'DATE','null'=>true], 'created_by'=>['type'=>'INT','unsigned'=>true,'null'=>true],
  'created_at'=>['type'=>'DATETIME','null'=>true], 'updated_at'=>['type'=>'DATETIME','null'=>true], 'deleted'=>['type'=>'TINYINT','constraint'=>1,'default'=>0],
 ]);$this->forge->addKey('id',true);$this->forge->addKey(['item_id','deleted','is_default']);$this->forge->addKey('sat_product_service_key_id');$this->forge->addKey('sat_unit_key_id');$this->forge->addKey('tax_object_code_id');$this->forge->createTable('item_fiscal_settings'); }
 public function down(): void { /* Fiscal history is retained; removal requires explicit backup and review. */ }
}
