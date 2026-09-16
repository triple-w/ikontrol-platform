<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Services\Fiscal\Stamps\FiscalStampAccountService;
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
        $environmentAware = $this->db->fieldExists('environment', 'fiscal_stamp_accounts');
        $select = 'p.id issuer_profile_id,p.rfc,p.legal_name,p.environment profile_environment,p.status profile_status,'
            . 'a.id stamp_account_id,COALESCE(a.available_balance,0) available_balance,'
            . 'COALESCE(a.reserved_balance,0) reserved_balance,COALESCE(a.status,\'missing\') account_status,a.updated_at';
        if ($environmentAware) {
            $select .= ',a.environment account_environment';
        }
        return $this->db->table('fiscal_profiles p')->select($select, false)
            ->join('fiscal_stamp_accounts a', 'a.issuer_profile_id=p.id' . ($environmentAware ? ' AND a.environment=p.environment' : ''), 'left')
            ->where('p.profile_type', 'issuer')
            ->orderBy('p.id')->get()->getResultArray();
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
