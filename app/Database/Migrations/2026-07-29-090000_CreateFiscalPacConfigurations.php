<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

final class CreateFiscalPacConfigurations extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('fiscal_pac_configurations')) return;
        $this->forge->addField([
            'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],
            'provider'=>['type'=>'VARCHAR','constraint'=>40],
            'environment'=>['type'=>'VARCHAR','constraint'=>20],
            'base_url'=>['type'=>'VARCHAR','constraint'=>255],
            'encrypted_api_key'=>['type'=>'TEXT'],
            'api_key_last_four'=>['type'=>'VARCHAR','constraint'=>4],
            'connection_timeout_seconds'=>['type'=>'INT','unsigned'=>true,'default'=>10],
            'request_timeout_seconds'=>['type'=>'INT','unsigned'=>true,'default'=>45],
            'is_active'=>['type'=>'TINYINT','constraint'=>1,'default'=>1],
            'is_default'=>['type'=>'TINYINT','constraint'=>1,'default'=>0],
            'created_by'=>['type'=>'INT','unsigned'=>true,'null'=>true],
            'created_at'=>['type'=>'DATETIME','null'=>true],
            'updated_at'=>['type'=>'DATETIME','null'=>true],
            'last_tested_at'=>['type'=>'DATETIME','null'=>true],
            'last_test_status'=>['type'=>'VARCHAR','constraint'=>30,'null'=>true],
            'deleted'=>['type'=>'TINYINT','constraint'=>1,'default'=>0],
        ]);
        $this->forge->addKey('id',true);
        $this->forge->addUniqueKey(['provider','environment'],'uq_pac_provider_environment');
        $this->forge->addKey(['environment','is_active','is_default','deleted'],false,false,'idx_pac_default');
        $this->forge->createTable('fiscal_pac_configurations');
    }
    public function down(): void { if($this->db->tableExists('fiscal_pac_configurations'))$this->forge->dropTable('fiscal_pac_configurations'); }
}
