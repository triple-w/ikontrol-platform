<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use CodeIgniter\Database\BaseConnection;

/** Projects safe instance fiscal modes without exposing credentials. */
final class FiscalInstanceModeService
{
    public const DISABLED = 'DISABLED';
    public const ONBOARDING = 'ONBOARDING';
    public const PRODUCTION = 'PRODUCTION';

    public function __construct(
        private ?BaseConnection $db = null,
        private ?object $fiscal = null,
        private ?object $pac = null,
        private mixed $readinessProvider = null
    ) {
        $this->db ??= db_connect();
        $this->fiscal ??= config('Fiscal');
        $this->pac ??= config('TimbradorXpress');
    }

    public function inspect(): array
    {
        $enabled = ! empty($this->fiscal->enabled);
        $requestedProduction = ($this->fiscal->runtimeMode ?? '') === 'production'
            || ($this->fiscal->environment ?? '') === 'production';
        $readiness = $enabled
            ? ($this->readinessProvider !== null
                ? ($this->readinessProvider)()
                : (new FiscalOnboardingReadinessService($this->db, $this->fiscal, $this->pac))->inspect())
            : ['state' => 'DISABLED', 'ready' => false, 'blockers' => ['El módulo fiscal está deshabilitado.'], 'details' => []];
        $productionChecks = [
            'runtime_mode' => ($this->fiscal->runtimeMode ?? '') === 'production',
            'enabled' => $enabled,
            'environment' => ($this->fiscal->environment ?? '') === 'production',
            'real_adapter' => ! in_array(($this->fiscal->pacAdapter ?? ''), ['', 'fake'], true),
            'allow_real_pac' => ! empty($this->fiscal->allowRealPac),
            'stamping_enabled' => ! empty($this->fiscal->stampingEnabled),
            'readiness' => ! empty($readiness['ready']),
        ];
        $mode = ! $enabled
            ? self::DISABLED
            : (! in_array(false, $productionChecks, true) ? self::PRODUCTION : self::ONBOARDING);
        $blocking = $readiness['blockers'] ?? [];
        if ($requestedProduction || $mode === self::PRODUCTION) {
            foreach ($productionChecks as $check => $passed) if (! $passed) $blocking[] = 'Producción: ' . $check . ' no está satisfecho.';
        }

        return [
            'mode' => $mode,
            'requested_mode' => $requestedProduction ? self::PRODUCTION : ($enabled ? self::ONBOARDING : self::DISABLED),
            'enabled' => $enabled,
            'pac_adapter' => (string) ($this->fiscal->pacAdapter ?? ''),
            'real_pac_allowed' => ! empty($this->fiscal->allowRealPac),
            'stamping_enabled' => ! empty($this->fiscal->stampingEnabled),
            'csd_encryption_key_configured' => method_exists($this->fiscal, 'hasValidCsdEncryptionKey')
                ? $this->fiscal->hasValidCsdEncryptionKey()
                : preg_match('/^[a-f0-9]{64}$/', (string) ($this->fiscal->csdEncryptionKey ?? '')) === 1,
            'pac_encryption_key_configured' => method_exists($this->fiscal, 'hasValidPacEncryptionKey')
                ? $this->fiscal->hasValidPacEncryptionKey()
                : strlen((string) ($this->fiscal->pacEncryptionKey ?? '')) >= 32,
            'catalogs' => $readiness['details']['catalogs'] ?? [],
            'issuer' => $readiness['details']['issuer'] ?? ['status' => 'NOT_INSTALLED'],
            'ready' => $mode === self::PRODUCTION,
            'blocking_reasons' => array_values(array_unique($blocking)),
            'production_checks' => $productionChecks,
        ];
    }
}
