<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

/**
 * Read-only vocabulary that keeps runtime, logical fiscal and PAC transport
 * environments distinguishable. It does not select a transport or loosen a
 * PAC guard; P09 will centralize those decisions.
 */
final class FiscalRuntimeContext
{
    /** @return array{runtime_mode:string,fiscal_environment:string,pac_provider:string,transport_environment:string,real_pac_allowed:bool,coherent:bool} */
    public static function from(object $fiscal, object $pac): array
    {
        $runtime = (string) ($fiscal->runtimeMode ?? '');
        $fiscalEnvironment = (string) ($fiscal->environment ?? '');
        $provider = (string) ($fiscal->pacAdapter ?? '');
        $transport = $provider === 'timbradorxpress'
            ? (string) ($pac->environment ?? '')
            : $fiscalEnvironment;
        $coherent = match ($runtime) {
            'integration' => $fiscalEnvironment === 'development' && $transport === 'sandbox',
            'production' => $fiscalEnvironment === 'production' && $transport === 'production',
            'automated_test' => true,
            default => false,
        };

        return [
            'runtime_mode' => $runtime,
            'fiscal_environment' => $fiscalEnvironment,
            'pac_provider' => $provider,
            'transport_environment' => $transport,
            'real_pac_allowed' => ! empty($fiscal->allowRealPac),
            'coherent' => $coherent,
        ];
    }
}
