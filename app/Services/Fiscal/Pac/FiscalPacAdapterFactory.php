<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pac;

use App\Contracts\Fiscal\Pac\PacAdapterInterface;
use Config\Fiscal;
use Config\TimbradorXpress;
use RuntimeException;

/**
 * Single authority boundary for selecting any PAC implementation.
 */
final class FiscalPacAdapterFactory
{
    public function __construct(
        private readonly ?Fiscal $fiscal = null,
        private readonly ?TimbradorXpress $timbradorXpress = null,
        private readonly ?PacAdapterInterface $fake = null
    ) {
    }

    public function create(): PacAdapterInterface
    {
        $fiscal = $this->fiscal ?? config('Fiscal');

        if (!$fiscal->enabled) {
            throw new RuntimeException('El módulo fiscal está deshabilitado por configuración del servidor.');
        }

        return match ($fiscal->pacAdapter) {
            'fake' => $this->createFake($fiscal),
            'timbradorxpress' => $this->createTimbradorXpress($fiscal),
            default => throw new RuntimeException('El adaptador PAC configurado no está permitido.'),
        };
    }

    private function createFake(Fiscal $fiscal): PacAdapterInterface
    {
        if ($fiscal->runtimeMode !== 'automated_test' || ENVIRONMENT !== 'testing' || PHP_SAPI !== 'cli') {
            throw new RuntimeException('FakePacAdapter sólo está permitido en pruebas automatizadas CLI.');
        }
        return $this->fake ?? new FakePacAdapter($fiscal->fakePacScenario ?? 'success');
    }

    public function provider(): string
    {
        return ($this->fiscal ?? config('Fiscal'))->pacAdapter;
    }

    public function environment(): string
    {
        $fiscal=$this->fiscal??config('Fiscal');
        return $fiscal->pacAdapter==='timbradorxpress'
            ?($this->timbradorXpress??config('TimbradorXpress'))->environment
            :$fiscal->environment;
    }

    private function createTimbradorXpress(Fiscal $fiscal): PacAdapterInterface
    {
        if (!$fiscal->allowRealPac) throw new RuntimeException('Las llamadas reales al PAC están deshabilitadas.');
        $runtimeProvider = $this->timbradorXpress ?? config('TimbradorXpress');
        if ($fiscal->runtimeMode === 'integration') {
            if ($fiscal->environment !== 'development' || $runtimeProvider->environment !== 'sandbox' || $runtimeProvider->productionEnabled) {
                throw new RuntimeException('El modo integration requiere PAC development.');
            }
            $runtimeProvider->assertSandbox();
            if (!$runtimeProvider->isConfigured()) throw new RuntimeException('PAC no configurado.');
            return new TimbradorXpressRestAdapter($runtimeProvider);
        }
        if ($fiscal->runtimeMode === 'production') {
            if ($fiscal->environment !== 'production' || $runtimeProvider->environment !== 'production' || !$runtimeProvider->productionEnabled) {
                throw new RuntimeException('La configuración productiva del PAC no es consistente.');
            }
            if (!$runtimeProvider->isConfigured()) throw new RuntimeException('PAC no configurado.');
            return new TimbradorXpressRestAdapter($runtimeProvider);
        }
        throw new RuntimeException('El PAC real no está permitido en automated_test.');

        /*
        if (!$fiscal->allowRealPac) {
            throw new RuntimeException('Las llamadas reales al PAC están deshabilitadas.');
        }
        if ($fiscal->environment !== 'sandbox') {
            throw new RuntimeException('TimbradorXpress sólo está permitido en sandbox durante esta etapa.');
        }

        $provider = $this->timbradorXpress ?? config('TimbradorXpress');
        if ($provider->environment !== 'sandbox' || $provider->productionEnabled) {
            throw new RuntimeException('La configuración maestra y TimbradorXpress no coinciden en sandbox seguro.');
        }
        $provider->assertSandbox();
        if (!$provider->isConfigured()) {
            throw new RuntimeException('PAC no configurado.');
        }

        return new TimbradorXpressRestAdapter($provider);
        */
    }
}
