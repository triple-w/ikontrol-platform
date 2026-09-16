<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class CreateSatTaxObjectCodes extends Migration {
 public function up(): void { if($this->db->tableExists('sat_tax_object_codes')) return; $this->forge->addField([
  'id'=>['type'=>'INT','unsigned'=>true,'auto_increment'=>true], 'code'=>['type'=>'VARCHAR','constraint'=>2], 'description'=>['type'=>'VARCHAR','constraint'=>255],
  'is_active'=>['type'=>'TINYINT','constraint'=>1,'default'=>1], 'valid_from'=>['type'=>'DATE','null'=>true], 'valid_to'=>['type'=>'DATE','null'=>true],
  'created_at'=>['type'=>'DATETIME','null'=>true], 'updated_at'=>['type'=>'DATETIME','null'=>true],
 ]);$this->forge->addKey('id',true);$this->forge->addUniqueKey('code');$this->forge->addKey('is_active');$this->forge->createTable('sat_tax_object_codes'); }
 public function down(): void { /* Additive catalog retained to protect future references. */ }
}
