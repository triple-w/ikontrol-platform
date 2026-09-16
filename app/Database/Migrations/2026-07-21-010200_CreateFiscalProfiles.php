<?php

declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
use RuntimeException;

class CreateFiscalProfiles extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('fiscal_profiles')) return;
        if (! $this->db->tableExists('clients')) throw new RuntimeException('Cannot create fiscal_profiles: RISE clients table is missing.');
        $this->forge->addField([
            'id'=>['type'=>'INT','unsigned'=>true,'auto_increment'=>true],
            'profile_type'=>['type'=>'VARCHAR','constraint'=>20,'default'=>'receiver'],
            'client_id'=>['type'=>'INT','unsigned'=>true,'null'=>true],
            'company_id'=>['type'=>'INT','unsigned'=>true,'null'=>true],
            'rfc'=>['type'=>'VARCHAR','constraint'=>13,'null'=>true],
            'legal_name'=>['type'=>'VARCHAR','constraint'=>254,'null'=>true],
            'tax_regime_id'=>['type'=>'INT','unsigned'=>true,'null'=>true],
            'fiscal_postal_code'=>['type'=>'VARCHAR','constraint'=>5,'null'=>true],
            'default_cfdi_use_id'=>['type'=>'INT','unsigned'=>true,'null'=>true],
            'tax_residency_country'=>['type'=>'CHAR','constraint'=>3,'null'=>true],
            'foreign_tax_registration'=>['type'=>'VARCHAR','constraint'=>40,'null'=>true],
            'is_default'=>['type'=>'TINYINT','constraint'=>1,'default'=>0],
            'valid_from'=>['type'=>'DATE','null'=>true], 'valid_to'=>['type'=>'DATE','null'=>true],
            'status'=>['type'=>'VARCHAR','constraint'=>20,'default'=>'draft'],
            'created_by'=>['type'=>'INT','unsigned'=>true,'null'=>true],
            'created_at'=>['type'=>'DATETIME','null'=>true], 'updated_at'=>['type'=>'DATETIME','null'=>true],
        ]);
        $this->forge->addKey('id',true); $this->forge->addKey(['client_id','profile_type','status']);
        $this->forge->addKey(['client_id','is_default']); $this->forge->addKey('tax_regime_id'); $this->forge->addKey('default_cfdi_use_id');
        $this->forge->createTable('fiscal_profiles');
    }
    public function down(): void { if($this->db->tableExists('fiscal_profiles')) $this->forge->dropTable('fiscal_profiles'); }
}
