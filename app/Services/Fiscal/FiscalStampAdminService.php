<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Services\Fiscal\Stamps\FiscalStampAccountService;
use App\Services\Fiscal\Stamps\FiscalStampBalanceService;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

final class FiscalStampAdminService
{
    public function __construct(private ?BaseConnection $db = null, private ?FiscalStampAccountService $accounts = null)
    {
        $this->db ??= db_connect();
        $this->accounts ??= new FiscalStampAccountService($this->db);
    }

    public function getAccounts(): array
    {
        $profiles = $this->db->table('fiscal_profiles')
            ->select('id,rfc,legal_name,environment,status,deleted')
            ->where('profile_type', 'issuer')->orderBy('id')->get()->getResult();
        $balances = new FiscalStampBalanceService($this->db, $this->accounts);
        $rows = [];
        foreach ($profiles as $profile) {
            $environment = in_array((string)$profile->environment, ['development','production'], true)
                ? (string)$profile->environment
                : FiscalRuntimeContext::fiscalEnvironment(config('Fiscal'));
            $balance = $balances->forIssuer((int)$profile->id, $environment);
            if ($balance['account_id'] && $balance['wallet_issuer_profile_id'] !== (int) $profile->id) {
                continue;
            }
            $account = $balance['account_id'] ? $this->db->table('fiscal_stamp_accounts')->select('updated_at')->where('id',$balance['account_id'])->get(1)->getRow() : null;
            $rows[] = [
                'issuer_profile_id'=>(int)$profile->id,'rfc'=>(string)$profile->rfc,'legal_name'=>(string)$profile->legal_name,
                'profile_environment'=>(string)$profile->environment,'profile_status'=>(string)$profile->status,
                'stamp_account_id'=>$balance['account_id'],'available_balance'=>$balance['available'],
                'reserved_balance'=>$balance['reserved'],'usable_balance'=>$balance['usable'],
                'account_status'=>$balance['status'],'updated_at'=>$account->updated_at ?? null,
                'account_environment'=>$balance['environment'],
            ];
        }
        return $rows;
    }

    public function getHistory(?int $issuerId = null, ?string $type = null, ?string $from = null, ?string $to = null): array
    {
        $builder = $this->db->table('fiscal_stamp_movements m')
            ->select('m.*,a.issuer_profile_id,p.rfc,p.legal_name')
            ->join('fiscal_stamp_accounts a', 'a.id=m.stamp_account_id')
            ->join('fiscal_profiles p', 'p.id=a.issuer_profile_id');
        if ($issuerId) $builder->where('a.issuer_profile_id', $issuerId);
        if ($type) $builder->where('m.movement_type', $type);
        if ($from) $builder->where('m.created_at >=', $from . ' 00:00:00');
        if ($to) $builder->where('m.created_at <=', $to . ' 23:59:59');
        return $builder->orderBy('m.id', 'DESC')->limit(500)->get()->getResultArray();
    }

    public function credit(int $issuerId, string $environment, int $quantity, string $reason, ?string $reference, string $requestId): object
    {
        return $this->adjust($issuerId, $environment, $quantity, $reason, $reference, $requestId);
    }

    public function debit(int $issuerId, string $environment, int $quantity, string $reason, ?string $reference, string $requestId): object
    {
        return $this->adjust($issuerId, $environment, -$quantity, $reason, $reference, $requestId);
    }

    private function adjust(int $issuerId, string $environment, int $signedQuantity, string $reason, ?string $reference, string $requestId): object
    {
        if ($issuerId < 1 || $signedQuantity === 0) throw new InvalidArgumentException('La cantidad debe ser mayor que cero.');
        if (!in_array($environment, ['development', 'production'], true)) throw new InvalidArgumentException('El ambiente fiscal no es válido.');
        $reason = trim($reason);$reference = trim((string)$reference);
        if ($reason === '') throw new InvalidArgumentException('El motivo es obligatorio.');
        if (!preg_match('/^[a-f0-9]{32}$/', $requestId)) throw new InvalidArgumentException('La solicitud no es válida.');
        $key = 'external-stamp-admin:' . hash('sha256', implode('|', [$requestId,$issuerId,$environment,$signedQuantity]));
        return $this->accounts->adjust($issuerId, $signedQuantity, mb_substr($reason,0,1000), null, $key, $reference === '' ? null : mb_substr($reference,0,191), $environment);
    }
}
