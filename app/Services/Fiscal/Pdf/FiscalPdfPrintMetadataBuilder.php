<?php
declare(strict_types=1);namespace App\Services\Fiscal\Pdf;
use DOMDocument;use DOMXPath;use RuntimeException;
final class FiscalPdfPrintMetadataBuilder{
 public function build(string$xml,object$document,object$issuer,object$receiver):array{$dom=new DOMDocument();if(!$dom->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS)||!$dom->documentElement)throw new RuntimeException('FISCAL_PDF_XML_INVALID');$x=new DOMXPath($dom);$x->registerNamespace('cfdi','http://www.sat.gob.mx/cfd/4');$root=$dom->documentElement;$r=$x->query('/cfdi:Comprobante/cfdi:Receptor')->item(0);$type=(string)$root->getAttribute('TipoDeComprobante');return['tipo_comprobante'=>$type,'tipo_nombre'=>match($type){'I'=>'INGRESO','E'=>'EGRESO','T'=>'TRASLADO',default=>$type},'receptor_rfc'=>$r?(string)$r->attributes->getNamedItem('Rfc')?->nodeValue:(string)$receiver->rfc,'receptor_razon_social'=>$r?(string)$r->attributes->getNamedItem('Nombre')?->nodeValue:(string)$receiver->legal_name,'comentarios_pdf'=>'','serie'=>(string)$root->getAttribute('Serie'),'folio'=>(string)$root->getAttribute('Folio')];}
}
