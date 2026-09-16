<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

final class DeprecateDatabasePacCredentials extends Migration
{
    public function up():void
    {
        if($this->db->tableExists('fiscal_pac_configurations')){
            $this->db->table('fiscal_pac_configurations')->update(['encrypted_api_key'=>'','is_active'=>0,'is_default'=>0,'deleted'=>1,'updated_at'=>date('Y-m-d H:i:s')]);
        }
        if($this->db->tableExists('fiscal_stamp_attempts')&&$this->db->fieldExists('pac_configuration_id','fiscal_stamp_attempts')){
            $this->forge->modifyColumn('fiscal_stamp_attempts',['pac_configuration_id'=>['type'=>'BIGINT','unsigned'=>true,'null'=>true]]);
        }
        if($this->db->tableExists('roles')&&$this->db->fieldExists('permissions','roles')){
            foreach($this->db->table('roles')->select('id,permissions')->get()->getResult() as $role){
                $permissions=@unserialize((string)$role->permissions);
                if(!is_array($permissions))continue;
                unset($permissions['fiscal_pac_view'],$permissions['fiscal_pac_manage'],$permissions['fiscal_stamp_production']);
                $this->db->table('roles')->where('id',$role->id)->update(['permissions'=>serialize($permissions)]);
            }
        }
    }
    public function down():void
    {
        // Secret values are intentionally non-recoverable. Rollback must never restore them.
    }
}
