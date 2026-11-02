<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/** Adds explicit, non-secret checks to the canonical FiscalRuntimeContext. */
final class FiscalRuntimeDiagnosticService
{
    public function inspect(?object $fiscal = null, ?object $pac = null): array
    {
        $fiscal ??= config('Fiscal');
        $pac ??= config('TimbradorXpress');
        $context = FiscalRuntimeContext::from($fiscal, $pac);
        $endpointAllowed = ($context['pac_provider'] ?? '') === 'fake'
            || ($context['pac_endpoint'] ?? null) === FiscalRuntimeContext::endpointFor((string) $context['fiscal_environment']);
        $adapterAllowed = ($context['pac_provider'] ?? '') === 'timbradorxpress'
            || (($context['pac_provider'] ?? '') === 'fake' && ($context['runtime_mode'] ?? '') === 'automated_test');
        $credentialPresent = !empty($context['pac_configured']);
        $fiscalEnabled = !empty($fiscal->enabled);
        $stampingEnabled = !empty($fiscal->stampingEnabled);
        $pacOperational = !empty($context['operational']);
        $safeIdentity = [
            'runtime' => (string) ($context['runtime_mode'] ?? ''),
            'environment' => (string) ($context['fiscal_environment'] ?? ''),
            'adapter' => (string) ($context['pac_provider'] ?? ''),
            'transport' => (string) ($context['transport_environment'] ?? ''),
            'endpoint' => (string) ($context['pac_endpoint'] ?? ''),
            'credential_present' => $credentialPresent,
            'fiscal_enabled' => $fiscalEnabled,
            'stamping_enabled' => $stampingEnabled,
            'real_pac_allowed' => !empty($context['real_pac_allowed']),
        ];
        $context += [
            'logical_environment' => $context['fiscal_environment'],
            'adapter' => $context['pac_provider'],
            'endpoint_allowed' => $endpointAllowed,
            'adapter_allowed' => $adapterAllowed,
            'credential_present' => $credentialPresent,
            'fiscal_enabled' => $fiscalEnabled,
            'stamping_enabled' => $stampingEnabled,
            'pac_operational' => $pacOperational,
            'stamping_operational' => $pacOperational && $stampingEnabled,
            'context_id' => 'fctx-' . substr(hash('sha256', (string) json_encode($safeIdentity, JSON_UNESCAPED_SLASHES)), 0, 20),
            'provider_host' => (string) (parse_url((string) ($context['pac_endpoint'] ?? ''), PHP_URL_HOST) ?: ''),
            'stamping_operation' => ($context['pac_provider'] ?? '') === 'timbradorxpress' ? 'timbrarConSello' : 'fake',
            'production_disabled' => ($context['fiscal_environment'] ?? '') !== 'production'
                && empty($pac->productionEnabled),
            'provider_cost_policy' => 'NOT_VERIFIED',
        ];
        $checks = [
            'fiscal_enabled' => $fiscalEnabled,
            'adapter_allowed' => $adapterAllowed,
            'transport_matches_environment' => !empty($context['coherent']),
            'endpoint_allowed' => $endpointAllowed,
            'credential_present' => $credentialPresent,
            'real_pac_opt_in' => ($context['pac_provider'] ?? '') === 'fake' || !empty($context['real_pac_allowed']),
            'production_transport_enabled' => ($context['fiscal_environment'] ?? '') !== 'production'
                || !empty($context['production_guard_satisfied']),
            'stamping_enabled' => $stampingEnabled,
        ];
        $context['checks'] = $checks;
        $context['blocking_reasons'] = $this->blockingReasons($context, $checks);
        return $context;
    }

    private function blockingReasons(array $context, array $checks): array
    {
        $labels = [
            'fiscal_enabled' => 'El módulo fiscal está deshabilitado.',
            'adapter_allowed' => 'El adaptador PAC seleccionado no está permitido.',
            'transport_matches_environment' => 'El transporte PAC no coincide con el ambiente lógico.',
            'endpoint_allowed' => 'El endpoint PAC no pertenece a la lista permitida para el ambiente.',
            'credential_present' => 'Falta la credencial del ambiente activo.',
            'real_pac_opt_in' => 'El PAC real requiere habilitación explícita.',
            'production_transport_enabled' => 'El transporte PAC de producción no fue habilitado explícitamente.',
            'stamping_enabled' => 'El timbrado está deshabilitado.',
        ];
        $reasons = [];
        foreach ($checks as $key => $passed) if (!$passed) $reasons[] = $labels[$key];
        foreach (($context['errors'] ?? []) as $error) if (is_string($error) && trim($error) !== '') $reasons[] = $error;
        return array_values(array_unique($reasons));
    }
}
