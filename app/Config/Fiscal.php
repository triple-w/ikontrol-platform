<?php

namespace Config;

use App\Domain\Fiscal\Signing\CsdSecretException;
use CodeIgniter\Config\BaseConfig;

/**
 * Safe bootstrap configuration for the future Mexican fiscal domain.
 *
 * Increment 0 deliberately provides no endpoint, credential, certificate,
 * taxpayer data, or CFDI rule.
 */
class Fiscal extends BaseConfig
{
    public string $runtimeMode = 'integration';
    public bool $enabled = false;
    public bool $previewMode = false;
    public bool $stampingEnabled = false;

    public string $environment = 'development';

    public bool $allowRealPac = false;
    public bool $allowExternalPdf = false;
    public bool $environmentSelectorEnabled = false;
    public bool $environmentSelectorPreviewOnly = true;
    public bool $labMode = false;
    public bool $labSandboxTransport = false;

    public string $privateStoragePath = WRITEPATH . 'fiscal-private';

    public string $pacAdapter = 'fake';

    public string $fakePacScenario = 'success';
    public string $fakeCancellationScenario = 'accepted';

    /**
     * Local master key used only for encrypted persistent fiscal contingencies.
     * It is not a PAC credential and must never leave this installation.
     */
    public string $pacEncryptionKey = '';

    /**
     * Installation-local master key exclusively for CSD password secrets.
     * Expected format: 64 hexadecimal characters (32 random bytes).
     */
    public string $csdEncryptionKey = '';

    public string $csdEncryptionVersion = 'csd-secret-v1';

    /**
     * A sending attempt older than this value is projected as unknown.
     * This threshold never triggers an automatic retry or a database update.
     */
    public int $stampingSendingStaleMinutes = 5;
    /** Manual SAT status checks never auto-retry inside this cooldown. */
    public int $satStatusCooldownSeconds = 60;
    public int $maxIssueAgeHours = 72;
    public bool $allowFutureIssueDate = false;

    public function __construct()
    {
        parent::__construct();
        $configuredMode = strtolower(trim((string) env('fiscal.runtimeMode', 'integration')));
        $this->runtimeMode = ENVIRONMENT === 'testing' ? 'automated_test' : $configuredMode;
        if (!in_array($this->runtimeMode, ['integration', 'production', 'automated_test'], true)) {
            throw new \RuntimeException('fiscal.runtimeMode debe ser integration, production o automated_test.');
        }
        if ($this->runtimeMode === 'automated_test' && (ENVIRONMENT !== 'testing' || PHP_SAPI !== 'cli')) {
            throw new \RuntimeException('automated_test sólo está permitido en pruebas CLI.');
        }
        $this->enabled = filter_var(env('fiscal.enabled', false), FILTER_VALIDATE_BOOL);
        $this->previewMode = filter_var(env('fiscal.previewMode', false), FILTER_VALIDATE_BOOL);
        // Existing automated suites inject network-free adapters; production and
        // preview remain fail-closed unless the server opts in explicitly.
        $this->stampingEnabled = filter_var(env('fiscal.stampingEnabled', ENVIRONMENT === 'testing'), FILTER_VALIDATE_BOOL);
        $configuredEnvironment = strtolower(trim((string) env('fiscal.environment', 'development')));
        $this->environment = in_array($configuredEnvironment, ['local', 'sandbox', 'development'], true)
            ? 'development'
            : $configuredEnvironment;
        if (!in_array($this->environment, ['development', 'production'], true)) {
            throw new \RuntimeException('fiscal.environment debe ser sandbox/development o production.');
        }
        $this->allowRealPac = filter_var(env('fiscal.allowRealPac', false), FILTER_VALIDATE_BOOL);
        $this->allowExternalPdf = filter_var(env('fiscal.allowExternalPdf', false), FILTER_VALIDATE_BOOL);
        $this->environmentSelectorEnabled = ENVIRONMENT !== 'production'
            && filter_var(env('fiscal.environmentSelectorEnabled', false), FILTER_VALIDATE_BOOL);
        // The shipped selector is intentionally preview-only and never writes .env.
        $this->environmentSelectorPreviewOnly = true;
        $this->labMode = filter_var(env('IKONTROL_RC1_LAB', false), FILTER_VALIDATE_BOOL);
        $this->labSandboxTransport = filter_var(env('IKONTROL_RC1_SANDBOX_TRANSPORT', false), FILTER_VALIDATE_BOOL);
        $this->pacAdapter = strtolower(trim((string) env('fiscal.pacAdapter', 'fake')));
        if ($this->runtimeMode !== 'automated_test' && $this->pacAdapter === 'fake' && ! $this->labMode) {
            throw new \RuntimeException('FakePacAdapter no está permitido fuera de pruebas automatizadas.');
        }
        $scenario = strtolower(trim((string) env('fiscal.fakePacScenario', 'success')));
        $this->fakePacScenario = in_array($scenario, [
            'success', 'rejected', 'timeout_unknown', 'transport_not_sent',
        ], true) ? $scenario : 'transport_not_sent';
        $cancellationScenario = strtolower(trim((string) env('fiscal.fakeCancellationScenario', 'accepted')));
        $this->fakeCancellationScenario = in_array($cancellationScenario, [
            'accepted', 'pending', 'pending_acceptance', 'rejected', 'timeout_unknown', 'transport_not_sent',
        ], true) ? $cancellationScenario : 'transport_not_sent';
        if ($this->labMode) {
            $fakeLab = $this->pacAdapter === 'fake' && ! $this->allowRealPac && ! $this->labSandboxTransport;
            $sandboxLab = $this->pacAdapter === 'timbradorxpress' && $this->allowRealPac && $this->labSandboxTransport
                && ! filter_var(env('TIMBRADORXPRESS_PRODUCTION_ENABLED', false), FILTER_VALIDATE_BOOL);
            if (ENVIRONMENT !== 'development' || $this->runtimeMode !== 'integration'
                || $this->environment !== 'development' || (! $fakeLab && ! $sandboxLab)) {
                throw new \RuntimeException('RC1_LAB requiere fake aislado o sandbox TimbradorXpress explícitamente autorizado; producción permanece prohibida.');
            }
        }
        $this->pacEncryptionKey = trim((string) env('fiscal.pacEncryptionKey', ''));
        $this->csdEncryptionKey = strtolower(trim((string) env('fiscal.csdEncryptionKey', '')));
        $this->stampingSendingStaleMinutes = max(
            1,
            min(120, (int) env('fiscal.stampingSendingStaleMinutes', 5))
        );
        $this->satStatusCooldownSeconds = max(0, min(3600, (int) env('fiscal.satStatusCooldownSeconds', 60)));
        $this->maxIssueAgeHours = max(1, min(720, (int) env('fiscal.maxIssueAgeHours', 72)));
        $this->allowFutureIssueDate = filter_var(
            env('fiscal.allowFutureIssueDate', false),
            FILTER_VALIDATE_BOOL
        );
    }

    public function hasValidPacEncryptionKey(): bool
    {
        return strlen($this->pacEncryptionKey) >= 32;
    }

    public function hasValidCsdEncryptionKey(): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $this->csdEncryptionKey) === 1
            && !hash_equals($this->csdEncryptionKey, strtolower($this->pacEncryptionKey));
    }

    public function assertValidCsdEncryptionKey(): void
    {
        if ($this->csdEncryptionKey === '') {
            throw new CsdSecretException(
                'CSD_ENCRYPTION_KEY_MISSING',
                'La llave de cifrado para secretos CSD no está configurada.'
            );
        }
        if (preg_match('/^[a-f0-9]{64}$/', $this->csdEncryptionKey) !== 1) {
            throw new CsdSecretException(
                'CSD_ENCRYPTION_KEY_INVALID',
                'La llave de cifrado CSD debe contener 64 caracteres hexadecimales.'
            );
        }
        if ($this->pacEncryptionKey !== ''
            && hash_equals($this->csdEncryptionKey, strtolower($this->pacEncryptionKey))) {
            throw new CsdSecretException(
                'CSD_ENCRYPTION_KEYS_REUSED',
                'La llave CSD debe ser distinta de la llave de contingencia PAC.'
            );
        }
    }

    public function allowsLabFakePac(): bool
    {
        // RC1 functional acceptance must use the real TimbradorXpress sandbox.
        // Fake adapters remain available only through the automated_test branch
        // in their factories, never through a browser or integration runtime.
        return false;
    }
}
