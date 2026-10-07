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
        private ?FiscalStampAccountService $accounts = null
    ) {
        $this->db ??= db_connect();
        $this->accounts ??= new FiscalStampAccountService($this->db);
    }

    /** @return array{issuer_profile_id:int,environment:string,account_id:?int,available:int,reserved:int,usable:int,status:string} */
    public function forIssuer(int $issuerId, ?string $environment = null): array
    {
        $environment = $this->environment($environment);
        $issuer = $this->db->table('fiscal_profiles')
            ->select('id,environment,status,deleted')
            ->where(['id'=>$issuerId,'profile_type'=>'issuer'])
            ->get(1)->getRow();
        if (! $issuer || (int)($issuer->deleted ?? 0) !== 0) {
            return $this->empty($issuerId, $environment, 'issuer_missing');
        }
        $issuerEnvironment = $this->explicitEnvironment((string)($issuer->environment ?? ''));
        if ($issuerEnvironment !== null && $issuerEnvironment !== $environment) {
            return $this->empty($issuerId, $environment, 'environment_mismatch');
        }

        $balance = $this->accounts->getBalance($issuerId, $environment);
        $account = $this->db->table('fiscal_stamp_accounts')->select('id')
            ->where('issuer_profile_id', $issuerId);
        if ($this->db->fieldExists('environment', 'fiscal_stamp_accounts')) {
            $account->where('environment', $environment);
        }
        $accountId = $account->get(1)->getRow();
        $available = max(0, (int)$balance['available']);
        $reserved = max(0, (int)$balance['reserved']);
        $status = (string)$balance['status'];
        return [
            'issuer_profile_id'=>$issuerId,
            'environment'=>$environment,
            'account_id'=>$accountId ? (int)$accountId->id : null,
            'available'=>$available,
            'reserved'=>$reserved,
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

    private function explicitEnvironment(string $environment): ?string
    {
        $environment = strtolower(trim($environment));
        if (in_array($environment, ['local','sandbox','test','testing','development'], true)) return 'development';
        return $environment === 'production' ? 'production' : null;
    }

    private function empty(int $issuerId, string $environment, string $status): array
    {
        return ['issuer_profile_id'=>$issuerId,'environment'=>$environment,'account_id'=>null,'available'=>0,'reserved'=>0,'usable'=>0,'status'=>$status];
    }
}
