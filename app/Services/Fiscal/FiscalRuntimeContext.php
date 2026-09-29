<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use Config\TimbradorXpress;
use RuntimeException;

/**
 * Canonical boundary between application runtime and fiscal operation.
 * Fiscal records use development/production while TimbradorXpress calls the
 * same environments sandbox/production. Application runtime selects neither.
 */
final class FiscalRuntimeContext
{
    public const DEVELOPMENT = 'development';
    public const PRODUCTION = 'production';
    public const SANDBOX = 'sandbox';

    /** @return array{runtime_mode:string,fiscal_mode:string,fiscal_environment:string,pac_provider:string,transport_environment:string,pac_endpoint:?string,pac_configured:bool,production_guard_satisfied:bool,real_pac_allowed:bool,coherent:bool,operational:bool,errors:list<string>} */
    public static function from(object $fiscal, object $pac): array
    {
        $runtime = strtolower(trim((string) ($fiscal->runtimeMode ?? '')));
        $fiscalEnvironment = self::normalizeFiscalEnvironment((string) ($fiscal->environment ?? ''));
        $mode = $fiscalEnvironment === self::PRODUCTION ? self::PRODUCTION : self::SANDBOX;
        $provider = strtolower(trim((string) ($fiscal->pacAdapter ?? '')));
        $transport = $provider === 'timbradorxpress'
            ? strtolower(trim((string) ($pac->environment ?? '')))
            : $fiscalEnvironment;
        $endpoint = $provider === 'timbradorxpress'
            ? (property_exists($pac, 'baseUrl') ? (string) $pac->baseUrl : self::endpointFor($fiscalEnvironment))
            : null;
        $configured = $provider === 'fake'
            || (method_exists($pac, 'isConfigured') ? (bool) $pac->isConfigured() : trim((string) ($pac->apiKey ?? '')) !== '');
        $productionGuard = $fiscalEnvironment !== self::PRODUCTION
            || !property_exists($pac, 'productionEnabled')
            || !empty($pac->productionEnabled);
        $errors = [];
        $coherenceErrors = [];

        if (!in_array($fiscalEnvironment, [self::DEVELOPMENT, self::PRODUCTION], true)) {
            $coherenceErrors[] = 'El ambiente fiscal debe ser sandbox/development o production.';
        }
        if ($provider === 'timbradorxpress') {
            if ($transport !== self::transportFor($fiscalEnvironment)) $coherenceErrors[] = 'El ambiente fiscal y el transporte PAC no coinciden.';
            if ($endpoint !== self::endpointFor($fiscalEnvironment)) $coherenceErrors[] = 'El endpoint PAC no corresponde al ambiente fiscal.';
            if (!$productionGuard) $errors[] = 'TimbradorXpress production no fue habilitado explícitamente.';
            if (!$configured) $errors[] = 'La credencial PAC del ambiente fiscal no está configurada.';
        } elseif ($provider === 'fake') {
            if ($runtime !== 'automated_test') $coherenceErrors[] = 'El PAC fake sólo está permitido en pruebas automatizadas.';
        } else {
            $coherenceErrors[] = 'El adaptador PAC configurado no está permitido.';
        }

        $errors = array_merge($coherenceErrors, $errors);
        $coherent = $coherenceErrors === [];
        $realPacAllowed = !empty($fiscal->allowRealPac);

        return [
            'runtime_mode' => $runtime,
            'fiscal_mode' => $mode,
            'fiscal_environment' => $fiscalEnvironment,
            'pac_provider' => $provider,
            'transport_environment' => $transport,
            'pac_endpoint' => $endpoint,
            'pac_configured' => $configured,
            'production_guard_satisfied' => $productionGuard,
            'real_pac_allowed' => $realPacAllowed,
            'coherent' => $coherent,
            'operational' => $coherent && $productionGuard && $configured && !empty($fiscal->enabled) && ($provider === 'fake' || $realPacAllowed),
            'errors' => $errors,
        ];
    }

    public static function fiscalEnvironment(object $fiscal): string
    {
        return self::normalizeFiscalEnvironment((string) ($fiscal->environment ?? ''));
    }

    public static function transportFor(string $fiscalEnvironment): string
    {
        return self::normalizeFiscalEnvironment($fiscalEnvironment) === self::PRODUCTION ? self::PRODUCTION : self::SANDBOX;
    }

    public static function endpointFor(string $fiscalEnvironment): string
    {
        return self::normalizeFiscalEnvironment($fiscalEnvironment) === self::PRODUCTION
            ? TimbradorXpress::PRODUCTION_URL
            : TimbradorXpress::SANDBOX_URL;
    }

    public static function assertPacOperational(object $fiscal, object $pac): array
    {
        $context = self::from($fiscal, $pac);
        if (!$context['operational']) throw new RuntimeException($context['errors'][0] ?? 'La operación fiscal no está habilitada.');
        return $context;
    }

    private static function normalizeFiscalEnvironment(string $environment): string
    {
        $environment = strtolower(trim($environment));
        return in_array($environment, ['local', 'sandbox', 'development'], true) ? self::DEVELOPMENT : $environment;
    }
}
