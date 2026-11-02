<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Stamps;

/** Defensive layer that preserves the existing resolver and rejects duplicate physical accounts. */
final class FiscalStampWalletResolutionService
{
    public function __construct(private mixed $db = null, private ?FiscalStampWalletResolver $resolver = null)
    {
        $this->db ??= db_connect();
        $this->resolver ??= new FiscalStampWalletResolver($this->db);
    }

    public function resolve(int $issuerId, string $environment): array
    {
        $resolved = $this->resolver->resolve($issuerId, $environment);
        $account = $resolved['account'] ?? null;
        if (!$account) return $resolved + ['reason' => $this->message((string) ($resolved['status'] ?? 'unknown'))];
        $builder = $this->db->table('fiscal_stamp_accounts')->where('issuer_profile_id', (int) $account->issuer_profile_id);
        if ($this->db->fieldExists('environment', 'fiscal_stamp_accounts')) $builder->where('environment', $environment);
        $matches = $builder->countAllResults();
        if ($matches !== 1) {
            return [
                'issuer_profile_id'=>$issuerId, 'wallet_issuer_profile_id'=>null, 'environment'=>$environment,
                'account'=>null, 'status'=>'account_ambiguous', 'reason'=>$this->message('account_ambiguous'),
            ];
        }
        return $resolved + ['reason' => $this->message((string) $resolved['status'])];
    }

    public function message(string $status): string
    {
        return match ($status) {
            'active' => 'Wallet comercial activa para el emisor y ambiente solicitados.',
            'inactive' => 'La wallet comercial está inactiva.',
            'issuer_missing' => 'No existe el emisor fiscal solicitado.',
            'environment_mismatch' => 'El emisor pertenece a otro ambiente fiscal.',
            'account_missing' => 'No existe una wallet comercial para el emisor, RFC, compañía y ambiente.',
            'account_ambiguous' => 'Existen wallets duplicadas o ambiguas; la operación queda bloqueada.',
            default => 'No fue posible resolver una wallet comercial única.',
        };
    }
}
