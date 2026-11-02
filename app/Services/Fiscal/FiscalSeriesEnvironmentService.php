<?php
declare(strict_types=1);
namespace App\Services\Fiscal;

/** Single environment-aware query boundary for selectable fiscal series. */
final class FiscalSeriesEnvironmentService
{
    public function __construct(private mixed$db=null){$this->db??=db_connect();}
    public function activeForIssuer(int$issuerId,?string$type=null,?string$environment=null):object
    {
        $environment=$environment?:FiscalRuntimeContext::fiscalEnvironment(config('Fiscal'));
        $builder=$this->db->table('fiscal_series')->where(['issuer_profile_id'=>$issuerId,'environment'=>$environment,'is_active'=>1,'deleted'=>0]);if($type!==null)$builder->where('document_type',$type);return$builder->orderBy('series')->get();
    }
    public function isSelectable(object|array$series,int$issuerId,string$environment):bool
    {
        $row=(array)$series;return(int)($row['issuer_profile_id']??0)===$issuerId&&(string)($row['environment']??'')===$environment&&(int)($row['is_active']??0)===1&&(int)($row['deleted']??0)===0;
    }
}
