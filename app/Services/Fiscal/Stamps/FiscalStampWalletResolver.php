<?php

declare(strict_types=1);

namespace App\Services\Fiscal\Stamps;

use CodeIgniter\Database\BaseConnection;

/** Resolves the one physical commercial-stamp wallet for a fiscal identity. */
final class FiscalStampWalletResolver
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    /** @return array{issuer_profile_id:int,wallet_issuer_profile_id:?int,environment:string,account:?object,status:string} */
    public function resolve(int $issuerId, string $environment): array
    {
        $environment = $this->normalizeEnvironment($environment);
        $issuer = $this->issuer($issuerId);
        if (! $issuer || (int) ($issuer->deleted ?? 0) !== 0) {
            return $this->result($issuerId, $environment, null, 'issuer_missing');
        }

        $issuerEnvironment = $this->explicitEnvironment((string) ($issuer->environment ?? ''));
        if ($issuerEnvironment !== null && $issuerEnvironment !== $environment) {
            return $this->result($issuerId, $environment, null, 'environment_mismatch');
        }

        $exact = $this->accountForIssuer($issuerId, $environment);
        if ($exact) {
            return $this->result($issuerId, $environment, $exact, (string) $exact->status);
        }

        $rfc = strtoupper(trim((string) ($issuer->rfc ?? '')));
        if ($rfc === '') {
            return $this->result($issuerId, $environment, null, 'account_missing');
        }

        $profiles = $this->db->table('fiscal_profiles')
            ->select('id,environment')
            ->where('profile_type', 'issuer')
            ->where('deleted', 0)
            ->where('UPPER(TRIM(rfc)) = ' . $this->db->escape($rfc), null, false);
        if ($this->db->fieldExists('company_id', 'fiscal_profiles')) {
            $companyId = $issuer->company_id ?? null;
            if ($companyId !== null) {
                $profiles->where('company_id', $companyId);
            }
        }

        $matches = [];
        foreach ($profiles->get()->getResult() as $candidate) {
            $candidateEnvironment = $this->explicitEnvironment((string) ($candidate->environment ?? ''));
            if ($candidateEnvironment !== null && $candidateEnvironment !== $environment) {
                continue;
            }
            $account = $this->accountForIssuer((int) $candidate->id, $environment);
            if ($account) {
                $matches[(int) $account->id] = $account;
            }
        }

        if (count($matches) === 1) {
            $account = reset($matches);
            return $this->result($issuerId, $environment, $account, (string) $account->status);
        }

        return $this->result($issuerId, $environment, null, count($matches) > 1 ? 'account_ambiguous' : 'account_missing');
    }

    public function normalizeEnvironment(string $environment): string
    {
        $environment = strtolower(trim($environment));
        if (in_array($environment, ['local', 'sandbox', 'test', 'testing', 'development'], true)) {
            return 'development';
        }
        return $environment === 'production' ? 'production' : '';
    }

    private function issuer(int $issuerId): ?object
    {
        $fields = ['id', 'rfc', 'environment', 'deleted'];
        if ($this->db->fieldExists('company_id', 'fiscal_profiles')) {
            $fields[] = 'company_id';
        }
        return $this->db->table('fiscal_profiles')->select(implode(',', $fields))
            ->where(['id' => $issuerId, 'profile_type' => 'issuer'])->get(1)->getRow();
    }

    private function accountForIssuer(int $issuerId, string $environment): ?object
    {
        $builder = $this->db->table('fiscal_stamp_accounts')->where('issuer_profile_id', $issuerId);
        if ($this->db->fieldExists('environment', 'fiscal_stamp_accounts')) {
            $builder->where('environment', $environment);
        }
        return $builder->get(1)->getRow();
    }

    private function explicitEnvironment(string $environment): ?string
    {
        $normalized = $this->normalizeEnvironment($environment);
        return $normalized === '' ? null : $normalized;
    }

    private function result(int $issuerId, string $environment, ?object $account, string $status): array
    {
        return [
            'issuer_profile_id' => $issuerId,
            'wallet_issuer_profile_id' => $account ? (int) $account->issuer_profile_id : null,
            'environment' => $environment,
            'account' => $account,
            'status' => $status,
        ];
    }
}
