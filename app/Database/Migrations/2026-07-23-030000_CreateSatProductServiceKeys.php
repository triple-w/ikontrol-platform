<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class CreateSatProductServiceKeys extends Migration {
 public function up(): void { if($this->db->tableExists('sat_product_service_keys')) return; $this->forge->addField([
  'id'=>['type'=>'INT','unsigned'=>true,'auto_increment'=>true], 'code'=>['type'=>'VARCHAR','constraint'=>8], 'description'=>['type'=>'VARCHAR','constraint'=>500],
  'valid_from'=>['type'=>'DATE','null'=>true], 'valid_to'=>['type'=>'DATE','null'=>true], 'is_active'=>['type'=>'TINYINT','constraint'=>1,'default'=>1],
  'source_version'=>['type'=>'VARCHAR','constraint'=>80,'null'=>true], 'created_at'=>['type'=>'DATETIME','null'=>true], 'updated_at'=>['type'=>'DATETIME','null'=>true],
 ]); $this->forge->addKey('id',true);$this->forge->addUniqueKey('code');$this->forge->addKey('description');$this->forge->addKey(['is_active','valid_from','valid_to']);$this->forge->createTable('sat_product_service_keys'); }
 public function down(): void { /* Catalog keys may be referenced; destructive rollback requires an explicit data review. */ }
}
