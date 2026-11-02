<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use CodeIgniter\Database\BaseConnection;

/** Enforces the issuer-before-series dependency without changing fiscal rules. */
final class FiscalSeriesIssuerValidator
{
    public function __construct(
        private ?BaseConnection $db = null,
        private mixed $issuerEvaluator = null,
        private ?object $fiscal = null
    ) {
        $this->db ??= db_connect();
        $this->fiscal ??= config('Fiscal');
    }

    /** @return array{valid:bool,message:string,errors:list<string>,configuration_path:string} */
    public function validate(int $issuerId, ?int $companyId = null, ?string $environment = null): array
    {
        $message = 'Completa y activa el emisor fiscal antes de guardar una serie.';
        if ($issuerId < 1 || ! $this->db->tableExists('fiscal_profiles')) {
            return ['valid' => false, 'message' => $message, 'errors' => ['No se seleccionó un emisor fiscal válido.'], 'configuration_path' => 'fiscal/issuers'];
        }
        $environment = strtolower(trim((string) ($environment ?: FiscalRuntimeContext::fiscalEnvironment($this->fiscal))));
        $profile = $this->db->table('fiscal_profiles')->where(['id' => $issuerId, 'profile_type' => 'issuer'])->get(1)->getRow();
        if (! $profile) {
            return ['valid' => false, 'message' => $message, 'errors' => ['El emisor seleccionado no existe.'], 'configuration_path' => 'fiscal/issuers'];
        }
        if ($companyId !== null && (int) $profile->company_id !== $companyId) {
            return ['valid' => false, 'message' => $message, 'errors' => ['El emisor pertenece a otra compañía.'], 'configuration_path' => 'fiscal/issuers'];
        }
        if ($companyId !== null && $this->db->tableExists('company') && ! $this->db->table('company')->where(['id' => $companyId, 'deleted' => 0])->countAllResults()) {
            return ['valid' => false, 'message' => $message, 'errors' => ['La compañía del emisor no está activa.'], 'configuration_path' => 'fiscal/issuers'];
        }
        $profileEnvironment = strtolower(trim((string) ($profile->environment ?? 'legacy')));
        if ($profileEnvironment !== $environment) {
            $reason = $profileEnvironment === '' || $profileEnvironment === 'legacy'
                ? 'El emisor es legacy y requiere asignación dirigida al ambiente fiscal antes de crear series.'
                : "El emisor pertenece al ambiente {$profileEnvironment}; el ambiente activo es {$environment}.";
            return ['valid' => false, 'message' => $message, 'errors' => [$reason], 'configuration_path' => 'fiscal/issuers'];
        }
        if ((string) $profile->status === 'inactive') {
            return ['valid' => false, 'message' => $message, 'errors' => ['El emisor no está activo.'], 'configuration_path' => 'fiscal/issuers'];
        }
        $readiness = $this->issuerEvaluator !== null
            ? ($this->issuerEvaluator)($issuerId, $profile)
            : (new IssuerFiscalReadinessService($this->db))->evaluate($issuerId, $companyId ?? (isset($profile->company_id) ? (int) $profile->company_id : null));
        if (empty($readiness['is_ready'])) {
            return ['valid' => false, 'message' => $message, 'errors' => array_values($readiness['errors'] ?? ['El emisor está incompleto.']), 'configuration_path' => 'fiscal/issuers'];
        }
        return ['valid' => true, 'message' => '', 'errors' => [], 'configuration_path' => 'fiscal/issuers'];
    }
}
