<?php

declare(strict_types=1);

namespace App\Services\Fiscal\Stamps;

use App\Services\Fiscal\FiscalRuntimeContext;
use CodeIgniter\Database\BaseConnection;

/** Canonical read boundary for issuer/environment stamp balances. */
final class FiscalStampBalanceService
{
    public function __construct(
        private ?BaseConnection $db = null,
        private ?FiscalStampAccountService $accounts = null,
        private ?FiscalStampWalletResolver $resolver = null
    ) {
        $this->db ??= db_connect();
        $this->accounts ??= new FiscalStampAccountService($this->db);
        $this->resolver ??= new FiscalStampWalletResolver($this->db);
    }

    /** @return array{issuer_profile_id:int,wallet_issuer_profile_id:?int,environment:string,account_id:?int,available:int,reserved:int,consumed:int,usable:int,status:string} */
    public function forIssuer(int $issuerId, ?string $environment = null): array
    {
        $environment = $this->environment($environment);
        $resolved = $this->resolver->resolve($issuerId, $environment);
        $account = $resolved['account'];
        if (! $account) {
            return $this->empty($issuerId, $environment, $resolved['status']);
        }
        $available = max(0, (int) $account->available_balance);
        $reserved = max(0, (int) $account->reserved_balance);
        $status = (string) $account->status;
        $consumed = $this->consumed((int) $account->id);
        return [
            'issuer_profile_id'=>$issuerId,
            'wallet_issuer_profile_id'=>(int) $account->issuer_profile_id,
            'environment'=>$environment,
            'account_id'=>(int) $account->id,
            'available'=>$available,
            'reserved'=>$reserved,
            'consumed'=>$consumed,
            'usable'=>$status === 'active' ? $available : 0,
            'status'=>$status,
        ];
    }

    /** @param int|object $document */
    public function forDocument(int|object $document): array
    {
        if (is_int($document)) {
            $document = $this->db->table('fiscal_documents')
                ->select('id,issuer_profile_id,environment')->where('id',$document)->get(1)->getRow();
        }
        if (! $document) return $this->empty(0, $this->environment(null), 'document_missing');
        return $this->forIssuer(
            (int)($document->issuer_profile_id ?? 0),
            $this->environment((string)($document->environment ?? ''))
        );
    }

    private function environment(?string $environment): string
    {
        $environment = strtolower(trim((string)$environment));
        if ($environment === '') return FiscalRuntimeContext::fiscalEnvironment(config('Fiscal'));
        if (in_array($environment, ['local','sandbox','test','testing','development'], true)) return 'development';
        return $environment === 'production' ? 'production' : FiscalRuntimeContext::fiscalEnvironment(config('Fiscal'));
    }

    /** @return list<array<string,mixed>> */
    public function recentForIssuer(int $issuerId, int $limit = 20, ?string $environment = null): array
    {
        $balance = $this->forIssuer($issuerId, $environment);
        if (! $balance['account_id']) return [];
        return $this->db->table('fiscal_stamp_movements')->where('stamp_account_id', $balance['account_id'])
            ->orderBy('id', 'DESC')->limit(max(1, min(100, $limit)))->get()->getResultArray();
    }

    private function consumed(int $accountId): int
    {
        $types = ['document_consumption', 'reconciliation_consumption', 'cancellation_request', 'cancellation_status_query', 'cancellation_consumption', 'adjustment_debit'];
        $row = $this->db->table('fiscal_stamp_movements')
            ->select("COALESCE(SUM(CASE WHEN movement_type='adjustment_debit' THEN ABS(quantity) ELSE quantity END),0) consumed", false)
            ->where('stamp_account_id', $accountId)->whereIn('movement_type', $types)->get()->getRow();
        return max(0, (int) ($row->consumed ?? 0));
    }

    private function empty(int $issuerId, string $environment, string $status): array
    {
        return ['issuer_profile_id'=>$issuerId,'wallet_issuer_profile_id'=>null,'environment'=>$environment,'account_id'=>null,'available'=>0,'reserved'=>0,'consumed'=>0,'usable'=>0,'status'=>$status];
    }
}
