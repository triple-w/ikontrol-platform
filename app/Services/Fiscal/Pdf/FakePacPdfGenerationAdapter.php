<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pdf;

use App\Contracts\Fiscal\Pdf\PacPdfGenerationAdapterInterface;
use App\Domain\Fiscal\Pdf\PacPdfGenerationRequest;
use App\Domain\Fiscal\Pdf\PacPdfGenerationResult;
use App\Services\Fiscal\Pac\FakePacPdfFixture;

final class FakePacPdfGenerationAdapter implements PacPdfGenerationAdapterInterface
{
    public int $calls = 0;

    public function __construct(private readonly string $scenario = 'success')
    {
    }

    public function generate(PacPdfGenerationRequest $request): PacPdfGenerationResult
    {
        $this->calls++;
        $fixture = FakePacPdfFixture::bytes();
        return match ($this->scenario) {
            'success' => new PacPdfGenerationResult(
                true, 'fake', '210', 'PDF falso generado.', base64_encode($fixture),
                'application/pdf', $request->templateCode, true, false, 'success'
            ),
            'invalid_pdf' => new PacPdfGenerationResult(
                true, 'fake', '210', 'PDF inválido.', base64_encode('%PDF-invalid%%EOF'),
                'application/pdf', $request->templateCode, true, false, 'success'
            ),
            'empty_pdf' => new PacPdfGenerationResult(
                false, 'fake', '210', 'Respuesta sin PDF.', null,
                'application/pdf', $request->templateCode, true, false, 'rejected'
            ),
            'timeout_unknown' => new PacPdfGenerationResult(
                false, 'fake', null, 'Resultado desconocido.', null,
                'application/pdf', $request->templateCode, true, false, 'unknown', true
            ),
            'transport_not_sent' => new PacPdfGenerationResult(
                false, 'fake', null, 'La solicitud no salió.', null,
                'application/pdf', $request->templateCode, false, true, 'transport_not_sent'
            ),
            'provider_rejected' => new PacPdfGenerationResult(
                false, 'fake', '500', 'El proveedor rechazó el PDF.', null,
                'application/pdf', $request->templateCode, true, false, 'rejected'
            ),
            'persistence_error' => new PacPdfGenerationResult(
                false, 'fake', '210', 'Error simulado al persistir el PDF.', null,
                'application/pdf', $request->templateCode, true, true, 'persistence_error'
            ),
            default => new PacPdfGenerationResult(
                false, 'fake', null, 'Escenario inválido.', null,
                'application/pdf', $request->templateCode, false, false, 'rejected'
            ),
        };
    }
}
