<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pac;

use App\Contracts\Fiscal\Pac\PacAdapterInterface;
use App\Domain\Fiscal\Pac\PacResponse;
use App\Domain\Fiscal\Pac\StampRequest;
use RuntimeException;
use DOMDocument;

/**
 * Deterministic, network-free PAC used by tests and local stabilization.
 */
final class FakePacAdapter implements PacAdapterInterface
{
    public int $stampCalls = 0;
    public int $statusCalls = 0;

    public function __construct(
        private readonly string $scenario = 'transport_not_sent',
        private readonly ?string $stampedXml = null,
        private readonly ?string $pdfBase64 = null
    ) {
    }

    public function stamp(StampRequest $request): PacResponse
    {
        $this->stampCalls++;

        return match ($this->scenario) {
            'success', 'success_complete' => $this->success($request, [], $this->pdfBase64 ?? self::fixturePdf()),
            'success_pdf_missing' => $this->success($request),
            'success_pdf_invalid' => $this->success($request, [], base64_encode('not-a-pdf')),
            'rejected' => new PacResponse(
                'FAKE_REJECTED',
                'El PAC falso rechazó el CFDI de forma controlada.',
                null,
                422,
                ['adapter' => 'fake', 'request_sent' => true]
            ),
            'timeout_unknown' => new PacResponse(
                null,
                'No fue posible confirmar la respuesta del PAC falso.',
                null,
                0,
                ['adapter' => 'fake', 'request_sent' => true],
                true,
                true
            ),
            'transport_not_sent' => new PacResponse(
                null,
                'La solicitud no salió del proceso local.',
                null,
                0,
                ['adapter' => 'fake', 'request_sent' => false],
                true,
                false
            ),
            'persistence_error' => $this->success($request, ['force_persistence_error' => true], $this->pdfBase64 ?? self::fixturePdf()),
            default => throw new RuntimeException('Escenario de PAC falso no soportado.'),
        };
    }

    public function getStampStatus(array $query): PacResponse
    {
        $this->statusCalls++;

        return new PacResponse(
            null,
            'La consulta externa está deshabilitada en el PAC falso.',
            null,
            0,
            ['adapter' => 'fake', 'request_sent' => false],
            true,
            false
        );
    }

    private function success(StampRequest $request, array $metadata = [], ?string $pdfBase64 = null): PacResponse
    {
        $stampedXml = $this->stampedXml;
        $uuid = '123E4567-E89B-42D3-A456-426614174000';
        $stampDate = '2026-07-24T12:00:00';
        if ($stampedXml === null || trim($stampedXml) === '') {
            [$stampedXml, $uuid, $stampDate] = $this->stampFixture($request);
        }

        return new PacResponse(
            '200',
            'Timbrado simulado correctamente.',
            json_encode([
                'XML' => $stampedXml,
                'UUID' => $uuid,
                'FechaTimbrado' => $stampDate,
                'PDF' => $pdfBase64,
            ], JSON_UNESCAPED_SLASHES),
            200,
            ['adapter' => 'fake', 'request_sent' => true] + $metadata
        );
    }

    public static function fixturePdf(): string
    {
        return FakePacPdfFixture::base64();
    }

    private function stampFixture(StampRequest $request): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        if (!$dom->loadXML($request->signedXml, LIBXML_NONET | LIBXML_NOBLANKS) || !$dom->documentElement) {
            throw new RuntimeException('El PAC falso recibió XML inválido.');
        }
        $root = $dom->documentElement;
        $hash = strtoupper(hash('sha256', $request->signedXml));
        $uuid = sprintf(
            '%s-%s-4%s-8%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            substr($hash, 17, 3),
            substr($hash, 20, 12)
        );
        $stampDate = date('Y-m-d\TH:i:s');
        $complement = $dom->createElementNS('http://www.sat.gob.mx/cfd/4', 'cfdi:Complemento');
        $tfd = $dom->createElementNS(
            'http://www.sat.gob.mx/TimbreFiscalDigital',
            'tfd:TimbreFiscalDigital'
        );
        foreach ([
            'Version' => '1.1',
            'UUID' => $uuid,
            'FechaTimbrado' => $stampDate,
            'RfcProvCertif' => 'AAA010101AAA',
            'SelloCFD' => (string) $root->getAttribute('Sello'),
            'NoCertificadoSAT' => '30001000000500000001',
            'SelloSAT' => base64_encode(hash('sha256', $request->signedXml . '|fake-sat', true)),
        ] as $name => $value) {
            $tfd->setAttribute($name, $value);
        }
        $complement->appendChild($tfd);
        $root->appendChild($complement);

        return [(string) $dom->saveXML(), $uuid, $stampDate];
    }
}
