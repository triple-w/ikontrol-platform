<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class SeparatePacCreditsAndReconciliation extends Migration{
 public function up(){if(!$this->db->tableExists('fiscal_pac_credit_snapshots')){$this->forge->addField(['id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],'provider'=>['type'=>'VARCHAR','constraint'=>50],'environment'=>['type'=>'VARCHAR','constraint'=>20],'available_credits'=>['type'=>'INT','unsigned'=>true],'consulted_at'=>['type'=>'DATETIME'],'provider_code'=>['type'=>'VARCHAR','constraint'=>30,'null'=>true],'created_at'=>['type'=>'DATETIME']]);$this->forge->addKey('id',true);$this->forge->addKey(['provider','environment','consulted_at']);$this->forge->createTable('fiscal_pac_credit_snapshots');}foreach(['reconciled_at'=>['type'=>'DATETIME','null'=>true],'reconciled_by'=>['type'=>'BIGINT','unsigned'=>true,'null'=>true],'reconciliation_source'=>['type'=>'VARCHAR','constraint'=>80,'null'=>true]]as$n=>$d)if(!$this->db->fieldExists($n,'fiscal_stamp_attempts'))$this->forge->addColumn('fiscal_stamp_attempts',[$n=>$d]);}
 public function down(){}
}
