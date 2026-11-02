<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Stamps;

/** Read-only projection that never mutates the commercial wallet or PAC credits. */
final class FiscalStampBalancePresentationService
{
    public function __construct(private mixed $db = null) { $this->db ??= db_connect(); }

    public function forIssuer(int $issuerId, string $environment): array
    {
        $resolution = (new FiscalStampWalletResolutionService($this->db))->resolve($issuerId, $environment);
        if (empty($resolution['account'])) {
            return [
                'commercial'=>['available'=>0,'reserved'=>0,'consumed'=>0,'usable'=>0,'status'=>$resolution['status'],'reason'=>$resolution['reason']],
                'pac'=>$this->pacCredits($issuerId, $environment), 'resolution'=>$resolution, 'movements'=>[],
            ];
        }
        $balance = (new FiscalStampBalanceService($this->db))->forIssuer($issuerId, $environment);
        $balance['reason'] = $resolution['reason'];
        return [
            'commercial'=>$balance, 'pac'=>$this->pacCredits($issuerId, $environment), 'resolution'=>$resolution,
            'movements'=>(new FiscalStampBalanceService($this->db))->recentForIssuer($issuerId, 20, $environment),
        ];
    }

    private function pacCredits(int $issuerId, string $environment): array
    {
        if (!$this->db->tableExists('fiscal_pac_credit_consultations')) {
            return ['known'=>false,'available_credits'=>null,'reason'=>'No existe historial de consultas PAC.'];
        }
        $row = $this->db->table('fiscal_pac_credit_consultations')->where([
            'issuer_profile_id'=>$issuerId, 'provider'=>'timbradorxpress', 'environment'=>$environment,
        ])->orderBy('consulted_at','DESC')->get(1)->getRow();
        if (!$row) return ['known'=>false,'available_credits'=>null,'reason'=>'No hay una consulta PAC para este emisor y ambiente.'];
        return [
            'known'=>true, 'available_credits'=>(int)$row->available_credits,
            'consulted_at'=>(string)$row->consulted_at, 'provider_code'=>(string)($row->provider_code ?? ''),
            'reason'=>'Saldo técnico informado por el PAC; no modifica la wallet comercial.',
        ];
    }
}
