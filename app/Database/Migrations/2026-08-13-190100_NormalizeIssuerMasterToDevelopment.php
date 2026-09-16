<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class NormalizeIssuerMasterToDevelopment extends Migration
{
    public function up():void
    {
        $issuerIds=[];
        if($this->db->tableExists('fiscal_profiles')){
            $rows=$this->db->table('fiscal_profiles')->select('id')->where(['profile_type'=>'issuer','status'=>'ready'])->whereIn('environment',['preview','legacy'])->get()->getResult();
            $issuerIds=array_map(static fn($row)=>(int)$row->id,$rows);
            if($issuerIds)$this->db->table('fiscal_profiles')->whereIn('id',$issuerIds)->update(['environment'=>'development']);
        }
        if($issuerIds&&$this->db->tableExists('fiscal_series')&&$this->db->fieldExists('environment','fiscal_series')){
            $this->db->table('fiscal_series')->whereIn('issuer_profile_id',$issuerIds)->whereIn('environment',['preview','legacy'])->update(['environment'=>'development']);
        }
    }

    public function down():void
    {
        // Historical preview/legacy provenance is not guessed during rollback.
    }
}
