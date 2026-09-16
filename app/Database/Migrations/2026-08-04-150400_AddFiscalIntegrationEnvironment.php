<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

final class AddFiscalIntegrationEnvironment extends Migration
{
    public function up():void
    {
        $this->add('fiscal_profiles','environment',['type'=>'VARCHAR','constraint'=>20,'default'=>'legacy']);
        $this->add('fiscal_series','environment',['type'=>'VARCHAR','constraint'=>20,'default'=>'legacy']);
        $this->add('fiscal_drafts','environment',['type'=>'VARCHAR','constraint'=>20,'default'=>'legacy']);
        $this->add('fiscal_drafts','data_origin',['type'=>'VARCHAR','constraint'=>30,'default'=>'operational']);
        $this->add('fiscal_documents','environment',['type'=>'VARCHAR','constraint'=>20,'default'=>'legacy']);
        $this->add('fiscal_documents','data_origin',['type'=>'VARCHAR','constraint'=>30,'default'=>'operational']);
        $this->add('fiscal_documents','is_test_fixture',['type'=>'TINYINT','constraint'=>1,'default'=>0]);
        $this->db->query('UPDATE '.$this->db->prefixTable('fiscal_documents').' d SET d.is_test_fixture=1,d.data_origin=\'automated_test\' WHERE EXISTS (SELECT 1 FROM '.$this->db->prefixTable('fiscal_document_stamps').' s WHERE s.fiscal_document_id=d.id AND (s.provider=\'fake\' OR s.pac_rfc=\'AAA010101AAA\'))');
        $this->db->table('fiscal_drafts')->where('id',2)->update(['data_origin'=>'integration_candidate','environment'=>'development']);
        $auditUserId=(int)($this->db->table('users')->selectMin('id')->where('deleted',0)->get()->getRow('id')??0);
        if($auditUserId>0&&!$this->db->table('commercial_lifecycle_audit')->where(['entity_type'=>'sale','entity_id'=>56,'event'=>'integration_candidate_classified'])->countAllResults())$this->db->table('commercial_lifecycle_audit')->insert([
            'entity_type'=>'sale','entity_id'=>56,'event'=>'integration_candidate_classified',
            'old_status'=>null,'new_status'=>null,'reason'=>'C2.3 candidate; not stamped.',
            'user_id'=>$auditUserId,'created_at'=>gmdate('Y-m-d H:i:s'),
        ]);
    }
    private function add(string$table,string$field,array$definition):void
    {
        if(!$this->db->fieldExists($field,$table))$this->forge->addColumn($table,[$field=>$definition]);
    }
    public function down():void
    {
        foreach(['fiscal_profiles'=>['environment'],'fiscal_series'=>['environment'],'fiscal_drafts'=>['environment','data_origin'],'fiscal_documents'=>['environment','data_origin','is_test_fixture']]as$table=>$fields)foreach($fields as$field)if($this->db->fieldExists($field,$table))$this->forge->dropColumn($table,$field);
    }
}
