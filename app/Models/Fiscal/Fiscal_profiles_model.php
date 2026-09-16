<?php
namespace App\Models\Fiscal;
use App\Models\Crud_model;
class Fiscal_profiles_model extends Crud_model {
    public function __construct(){ parent::__construct('fiscal_profiles'); }
    public function forClient(int $clientId){ return $this->get_all_where(['client_id'=>$clientId,'profile_type'=>'receiver'],1000000,0,'is_default'); }
    public function setDefault(int $clientId,int $profileId): bool { $this->db->transStart(); $this->db->table($this->table)->where('client_id',$clientId)->update(['is_default'=>0]); $this->db->table($this->table)->where(['id'=>$profileId,'client_id'=>$clientId])->update(['is_default'=>1]); $this->db->transComplete(); return $this->db->transStatus(); }
    public function issuers(?int $companyId=null){$where=['profile_type'=>'issuer'];if($companyId)$where['company_id']=$companyId;return$this->get_all_where($where,1000000,0,'legal_name');}
    public function activeIssuers(?int $companyId=null){$where=['profile_type'=>'issuer','status'=>'ready'];if($companyId)$where['company_id']=$companyId;return$this->get_all_where($where,1000000,0,'legal_name');}
    public function defaultIssuer(?int $companyId=null){$where=['profile_type'=>'issuer','status'=>'ready','is_default'=>1];if($companyId)$where['company_id']=$companyId;return$this->get_one_where($where);}
    public function setIssuerDefault(?int $companyId,int $profileId):bool{$this->db->transStart();$builder=$this->db->table($this->table)->where('profile_type','issuer');$companyId?$builder->where('company_id',$companyId):$builder->where('company_id',null);$builder->update(['is_default'=>0]);$target=['id'=>$profileId,'profile_type'=>'issuer'];$companyId?$target['company_id']=$companyId:$target['company_id']=null;$this->db->table($this->table)->where($target)->update(['is_default'=>1]);$this->db->transComplete();return$this->db->transStatus();}
}
