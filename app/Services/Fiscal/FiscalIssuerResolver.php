<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use DateTimeImmutable;

/** Resolves the effective issuer without relying on legacy active flags. */
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
        return$builder->orderBy('fp.is_default','DESC')->orderBy('fp.id','ASC')->get()->getResult();
    }

    /** Valid certificate records for an issuer; callers may apply operational file/secret checks. */
    public function certificateCandidates(int$issuerId,?DateTimeImmutable$on=null):array
    {
        $now=($on?:new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        return$this->db->table('fiscal_issuer_certificates')->where(['issuer_profile_id'=>$issuerId,'deleted'=>0,'status'=>'valid'])->where('valid_from <=',$now)->where('valid_to >=',$now)->orderBy('is_default','DESC')->orderBy('valid_to','DESC')->orderBy('id','ASC')->get()->getResult();
    }

    public function candidates(?int $companyId=null,?string $environment=null,?DateTimeImmutable $on=null):array
    {
        $environment = strtolower(trim((string) ($environment ?: config('Fiscal')->environment)));
        $moment = $on ?: new DateTimeImmutable('now');$today=$moment->format('Y-m-d');$now=$moment->format('Y-m-d H:i:s');

        $certificates=$this->db->prefixTable('fiscal_issuer_certificates');
        $secrets=$this->db->prefixTable('fiscal_issuer_certificate_secrets');
        $regimes=$this->db->prefixTable('sat_tax_regimes');
        $builder = $this->db->table('fiscal_profiles fp')
            ->select('fp.*,tr.code tax_regime_code,tr.description tax_regime_description,c.id certificate_id,c.certificate_number,c.valid_to certificate_valid_to')
            ->join($regimes.' tr','tr.id=fp.tax_regime_id','left')
            ->join($certificates.' c','c.issuer_profile_id=fp.id AND c.deleted=0 AND c.status=\'valid\' AND c.valid_from <= '.$this->db->escape($now).' AND c.valid_to >= '.$this->db->escape($now).' AND UPPER(c.certificate_rfc)=UPPER(fp.rfc)','inner',false)
            ->join($secrets.' cs','cs.fiscal_issuer_certificate_id=c.id AND cs.status=\'active\'','inner',false)
            ->where('fp.profile_type', 'issuer')
            ->where('fp.status','ready')
            ->groupStart()->where('fp.valid_from', null)->orWhere('fp.valid_from <=', $today)->groupEnd()
            ->groupStart()->where('fp.valid_to', null)->orWhere('fp.valid_to >=', $today)->groupEnd();

        if ($companyId !== null) {
            $builder->where('fp.company_id', $companyId);
        }
        if ($environment !== '') {
            $builder->where('fp.environment', $environment);
        }

        // Migrated data may have no default. A ready, valid candidate still wins.
        return $builder->orderBy('fp.is_default','DESC')->orderBy('c.is_default','DESC')->orderBy('fp.id','ASC')->get()->getResult();
    }
}
