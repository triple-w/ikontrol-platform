<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pdf;

use App\Contracts\Fiscal\Pdf\PacPdfGenerationAdapterInterface;
use App\Domain\Fiscal\Pdf\PacPdfGenerationRequest;
use App\Domain\Fiscal\Pdf\PacPdfGenerationResult;
use Config\Fiscal;
use RuntimeException;

final class LocalFakeFiscalPdfGenerationAdapter implements PacPdfGenerationAdapterInterface
{
    public function __construct(private readonly ?Fiscal $fiscal = null)
    {
    }

    public function generate(PacPdfGenerationRequest $request): PacPdfGenerationResult
    {
        if (!(($this->fiscal ?? config('Fiscal'))->allowsLabFakePac())) {
            throw new RuntimeException('FISCAL_LOCAL_FAKE_PDF_NOT_ALLOWED');
        }
        require_once APPPATH . 'ThirdParty/tcpdf/tcpdf.php';
        if (!class_exists(\TCPDF::class)) {
            throw new RuntimeException('FISCAL_LOCAL_PDF_ENGINE_UNAVAILABLE');
        }

        $pdf = new \TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
        $pdf->SetCreator('iKontrol RC1 laboratorio');
        $pdf->SetTitle('Representacion fiscal simulada');
        $pdf->SetSubject('Simulacion local sin validez fiscal');
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        $pdf->SetCompression(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 12, 'SIMULACION / SIN VALIDEZ FISCAL', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 7, 'Este archivo es una representacion de laboratorio; no es un CFDI legal ni una respuesta autoritativa del SAT o de un PAC.', 0, 'C');
        $pdf->Ln(5);

        foreach ([
            'UUID simulado' => $request->uuid,
            'Serie' => $request->printMetadata['serie'] ?? '',
            'Folio' => $request->printMetadata['folio'] ?? '',
            'Fecha' => $request->printMetadata['fecha'] ?? '',
            'Emisor' => $request->printMetadata['emisor_razon_social'] ?? '',
            'RFC emisor' => $request->printMetadata['emisor_rfc'] ?? '',
            'Receptor' => $request->printMetadata['receptor_razon_social'] ?? '',
            'RFC receptor' => $request->printMetadata['receptor_rfc'] ?? '',
            'Subtotal' => $request->printMetadata['subtotal'] ?? '',
            'Total' => $request->printMetadata['total'] ?? '',
            'Moneda' => $request->printMetadata['moneda'] ?? '',
        ] as $label => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $text = trim((string) preg_replace('/[\r\n\t]+/', ' ', (string) $value));
            if ($text === '') {
                continue;
            }
            $pdf->MultiCell(0, 6, $label . ': ' . mb_substr($text, 0, 240), 0, 'L');
        }

        $bytes = $pdf->Output('', 'S');
        if (!is_string($bytes) || !str_starts_with($bytes, '%PDF-')) {
            throw new RuntimeException('FISCAL_LOCAL_PDF_OUTPUT_INVALID');
        }

        return new PacPdfGenerationResult(
            true,
            'fake-local',
            'LOCAL',
            'PDF simulado local generado; sin validez fiscal.',
            base64_encode($bytes),
            'application/pdf',
            $request->templateCode,
            false,
            false,
            'success'
        );
    }
}
