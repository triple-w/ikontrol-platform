<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pac;

use RuntimeException;

/**
 * Deterministic, one-page representation used only by the local fake PAC.
 */
final class FakePacPdfFixture
{
    public const UUID = '123E4567-E89B-42D3-A456-426614174000';

    public static function base64(): string
    {
        return base64_encode(self::bytes());
    }

    public static function bytes(): string
    {
        require_once APPPATH . 'ThirdParty/tcpdf/tcpdf.php';

        $pdf = new class('P', 'mm', 'A4', true, 'UTF-8', false) extends \TCPDF {
            public function __construct(...$arguments)
            {
                parent::__construct(...$arguments);
                $this->file_id=hash('md5','ikontrol-fake-pac-pdf-v2');
                $this->hash_key=hash('sha256','ikontrol-fake-pac-pdf-v2-hash-key');
            }
        };
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCompression(false);
        $pdf->setDocCreationTimestamp(0);
        $pdf->setDocModificationTimestamp(0);
        $pdf->SetCreator('iKontrol Fake PAC');
        $pdf->SetAuthor('iKontrol');
        $pdf->SetTitle('Factura de prueba sin validez fiscal');
        $pdf->SetMargins(20, 25, 20);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, 'FACTURA DE PRUEBA', 0, 1, 'C');
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->Cell(0, 9, 'FAKE PAC - SIN VALIDEZ FISCAL', 0, 1, 'C');
        $pdf->Ln(8);
        $pdf->SetFont('helvetica', '', 11);
        $pdf->Cell(45, 8, 'Serie/Folio:', 0, 0);
        $pdf->Cell(0, 8, 'A-14', 0, 1);
        $pdf->Cell(45, 8, 'UUID fake:', 0, 0);
        $pdf->Cell(0, 8, self::UUID, 0, 1);

        $bytes = $pdf->Output('', 'S');
        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('No fue posible construir el PDF del PAC falso.');
        }

        return $bytes;
    }
}
