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
    public function validate(int $issuerId): array
    {
        $message = 'Completa y activa el emisor fiscal antes de guardar una serie.';
        if ($issuerId < 1 || ! $this->db->tableExists('fiscal_profiles')) {
            return ['valid' => false, 'message' => $message, 'errors' => ['No se seleccionó un emisor fiscal válido.'], 'configuration_path' => 'fiscal/issuers'];
        }
        $environment = FiscalRuntimeContext::fiscalEnvironment($this->fiscal);
        $profile = (new FiscalIssuerResolver($this->db))->resolveById($issuerId, null, $environment);
        if (! $profile) {
            return ['valid' => false, 'message' => $message, 'errors' => ['El emisor seleccionado no existe.'], 'configuration_path' => 'fiscal/issuers'];
        }
        $readiness = $this->issuerEvaluator !== null
            ? ($this->issuerEvaluator)($issuerId, $profile)
            : (new IssuerFiscalReadinessService($this->db))->evaluate($issuerId, isset($profile->company_id) ? (int) $profile->company_id : null);
        if (empty($readiness['is_ready'])) {
            return ['valid' => false, 'message' => $message, 'errors' => array_values($readiness['errors'] ?? ['El emisor está incompleto.']), 'configuration_path' => 'fiscal/issuers'];
        }
        return ['valid' => true, 'message' => '', 'errors' => [], 'configuration_path' => 'fiscal/issuers'];
    }
}
