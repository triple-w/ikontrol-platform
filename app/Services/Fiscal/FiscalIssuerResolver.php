<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use DateTimeImmutable;

/** Resolves issuer identity/configuration independently from CSD readiness. */
final class FiscalIssuerResolver
{
    private mixed $db;

    public function __construct(mixed $db = null)
    {
        $this->db = $db ?: db_connect();
    }

    public function resolve(?int $companyId = null, ?string $environment = null, ?DateTimeImmutable $on = null): ?object
    {
        return $this->candidates($companyId,$environment,$on)[0]??null;
    }

    public function resolveById(int $issuerId,?int $companyId=null,?string $environment=null,?DateTimeImmutable $on=null):?object
    {
        foreach($this->candidates($companyId,$environment,$on) as $candidate)if((int)$candidate->id===$issuerId)return$candidate;
        return null;
    }

    /** Issuer identity/configuration candidates, independent from CSD readiness. */
    public function profileCandidates(?int $companyId=null,?string $environment=null,?DateTimeImmutable $on=null):array
    {
        $environment=strtolower(trim((string)($environment?:config('Fiscal')->environment)));$moment=$on?:new DateTimeImmutable('now');$today=$moment->format('Y-m-d');
        $builder=$this->db->table('fiscal_profiles fp')->select('fp.*')->where('fp.profile_type','issuer')->whereIn('fp.status',['active','ready'])->groupStart()->where('fp.valid_from',null)->orWhere('fp.valid_from <=',$today)->groupEnd()->groupStart()->where('fp.valid_to',null)->orWhere('fp.valid_to >=',$today)->groupEnd();
        if($companyId!==null)$builder->where('fp.company_id',$companyId);if($environment!=='')$builder->where('fp.environment',$environment);
        $profiles=$builder->orderBy('fp.is_default','DESC')->orderBy('fp.id','ASC')->get()->getResult();
        $hasRegimes=$this->db->tableExists('sat_tax_regimes');$hasCertificates=$this->db->tableExists('fiscal_issuer_certificates');
        foreach($profiles as$profile){
            $profile->tax_regime_code=null;$profile->tax_regime_description=null;$profile->certificate_id=null;$profile->certificate_number=null;$profile->certificate_valid_to=null;
            if(!empty($profile->tax_regime_id)&&$hasRegimes){
                $regime=$this->db->table('sat_tax_regimes')->select('code,description')->where('id',$profile->tax_regime_id)->get(1)->getRow();
                if($regime){$profile->tax_regime_code=$regime->code;$profile->tax_regime_description=$regime->description;}
            }
            if($hasCertificates){
                $certificate=$this->db->table('fiscal_issuer_certificates')->where(['issuer_profile_id'=>$profile->id,'deleted'=>0])->orderBy('is_default','DESC')->orderBy('valid_to','DESC')->get(1)->getRow();
                if($certificate){$profile->certificate_id=$certificate->id;$profile->certificate_number=$certificate->certificate_number;$profile->certificate_valid_to=$certificate->valid_to;}
            }
        }
        return$profiles;
    }

    /** Valid certificate records for an issuer; callers may apply operational file/secret checks. */
    public function certificateCandidates(int$issuerId,?DateTimeImmutable$on=null):array
    {
        $now=($on?:new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        return$this->db->table('fiscal_issuer_certificates')->where(['issuer_profile_id'=>$issuerId,'deleted'=>0,'status'=>'valid'])->where('valid_from <=',$now)->where('valid_to >=',$now)->orderBy('is_default','DESC')->orderBy('valid_to','DESC')->orderBy('id','ASC')->get()->getResult();
    }

    public function candidates(?int $companyId=null,?string $environment=null,?DateTimeImmutable $on=null):array
    {
        return $this->profileCandidates($companyId, $environment, $on);
    }
}
