<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pac;

use App\Contracts\Fiscal\Pac\PacAdapterInterface;
use App\Services\Fiscal\FiscalRuntimeContext;
use Config\Fiscal;
use Config\TimbradorXpress;
use RuntimeException;

/** Single authority boundary for selecting any PAC implementation. */
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
        if (!$fiscal->enabled) throw new RuntimeException('El módulo fiscal está deshabilitado por configuración del servidor.');

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
        return $this->context()['transport_environment'];
    }

    /** @return array<string,mixed> */
    public function context(): array
    {
        $fiscal = $this->fiscal ?? config('Fiscal');
        $pac = $this->timbradorXpress ?? config('TimbradorXpress');
        return FiscalRuntimeContext::from($fiscal, $pac);
    }

    private function createTimbradorXpress(Fiscal $fiscal): PacAdapterInterface
    {
        $provider = $this->timbradorXpress ?? config('TimbradorXpress');
        FiscalRuntimeContext::assertPacOperational($fiscal, $provider);
        return new TimbradorXpressRestAdapter($provider, null, $fiscal);
    }
}
