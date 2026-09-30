<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pdf;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/** Builds print metadata from immutable stamped XML and verifies its persisted identity. */
final class FiscalPdfPrintMetadataBuilder
{
    public function build(string $xml, object $document, object $issuer, object $receiver): array
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('FISCAL_PDF_XML_INVALID');
        }
        $dom = new DOMDocument();
        if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) || !$dom->documentElement) {
            throw new RuntimeException('FISCAL_PDF_XML_INVALID');
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');
        $root = $dom->documentElement;
        $issuerNode = $xpath->query('/cfdi:Comprobante/cfdi:Emisor')?->item(0);
        $receiverNode = $xpath->query('/cfdi:Comprobante/cfdi:Receptor')?->item(0);
        if (!$issuerNode instanceof DOMElement || !$receiverNode instanceof DOMElement) {
            throw new RuntimeException('FISCAL_PDF_XML_PARTIES_MISSING');
        }

        $type = (string)$root->getAttribute('TipoDeComprobante');
        $expectedType = match (strtolower((string)$document->document_type)) {
            'income', 'i' => 'I', 'expense', 'e' => 'E', 'payment', 'p' => 'P',
            'transfer', 't' => 'T', 'payroll', 'n' => 'N', default => '',
        };
        if ($expectedType === '' || $type !== $expectedType
            || (string)$root->getAttribute('Serie') !== (string)$document->series
            || (string)$root->getAttribute('Folio') !== (string)$document->folio) {
            throw new RuntimeException('FISCAL_PDF_PERSISTED_DOCUMENT_MISMATCH');
        }

        return [
            'tipo_comprobante' => $type,
            'tipo_nombre' => match ($type) {'I'=>'INGRESO','E'=>'EGRESO','P'=>'PAGO','T'=>'TRASLADO','N'=>'NÓMINA',default=>$type},
            'serie' => (string)$document->series,
            'folio' => (string)$document->folio,
            'fecha' => (string)$root->getAttribute('Fecha'),
            'subtotal' => (string)$root->getAttribute('SubTotal'),
            'total' => (string)$root->getAttribute('Total'),
            'moneda' => (string)$root->getAttribute('Moneda'),
            'emisor_rfc' => (string)$issuerNode->getAttribute('Rfc'),
            'emisor_razon_social' => (string)$issuerNode->getAttribute('Nombre'),
            'receptor_rfc' => (string)$receiverNode->getAttribute('Rfc'),
            'receptor_razon_social' => (string)$receiverNode->getAttribute('Nombre'),
            'comentarios_pdf' => '',
        ];
    }
}
