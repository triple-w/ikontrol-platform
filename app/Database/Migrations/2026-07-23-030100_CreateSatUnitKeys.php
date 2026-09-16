<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class CreateSatUnitKeys extends Migration {
 public function up(): void { if($this->db->tableExists('sat_unit_keys')) return; $this->forge->addField([
  'id'=>['type'=>'INT','unsigned'=>true,'auto_increment'=>true], 'code'=>['type'=>'VARCHAR','constraint'=>10], 'name'=>['type'=>'VARCHAR','constraint'=>120],
  'description'=>['type'=>'VARCHAR','constraint'=>500,'null'=>true], 'symbol'=>['type'=>'VARCHAR','constraint'=>30,'null'=>true],
  'valid_from'=>['type'=>'DATE','null'=>true], 'valid_to'=>['type'=>'DATE','null'=>true], 'is_active'=>['type'=>'TINYINT','constraint'=>1,'default'=>1],
  'source_version'=>['type'=>'VARCHAR','constraint'=>80,'null'=>true], 'created_at'=>['type'=>'DATETIME','null'=>true], 'updated_at'=>['type'=>'DATETIME','null'=>true],
 ]);$this->forge->addKey('id',true);$this->forge->addUniqueKey('code');$this->forge->addKey('name');$this->forge->addKey(['is_active','valid_from','valid_to']);$this->forge->createTable('sat_unit_keys'); }
 public function down(): void { /* Additive catalog retained to protect future references. */ }
}
