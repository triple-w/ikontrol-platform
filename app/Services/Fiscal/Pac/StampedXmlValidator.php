<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pac;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class StampedXmlValidator
{
    private const CFDI_NS='http://www.sat.gob.mx/cfd/4';
    private const TFD_NS='http://www.sat.gob.mx/TimbreFiscalDigital';

    public function validate(string $stampedXml,string $signedXml): array
    {
        if($stampedXml===''||strlen($stampedXml)>config('TimbradorXpress')->maxResponseBytes)throw new RuntimeException('El XML timbrado está vacío o excede el límite.');
        $stamped=$this->load($stampedXml);$signed=$this->load($signedXml);
        $root=$stamped->documentElement;
        if(!$root||$root->localName!=='Comprobante'||$root->namespaceURI!==self::CFDI_NS)throw new RuntimeException('La respuesta no contiene cfdi:Comprobante.');
        $xp=new DOMXPath($stamped);$xp->registerNamespace('tfd',self::TFD_NS);
        $nodes=$xp->query('//tfd:TimbreFiscalDigital');
        if(!$nodes||$nodes->length!==1)throw new RuntimeException('El XML no contiene exactamente un TimbreFiscalDigital.');
        /** @var DOMElement $tfd */
        $tfd=$nodes->item(0);
        $uuid=strtoupper($tfd->getAttribute('UUID'));
        if(!preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[1-5][0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/',$uuid))throw new RuntimeException('El TimbreFiscalDigital contiene un UUID inválido.');
        $required=['Version','FechaTimbrado','RfcProvCertif','SelloCFD','NoCertificadoSAT','SelloSAT'];
        foreach($required as $name)if(trim($tfd->getAttribute($name))==='')throw new RuntimeException("El TimbreFiscalDigital no contiene {$name}.");
        if(config('Fiscal')->runtimeMode!=='automated_test'&&strtoupper($tfd->getAttribute('RfcProvCertif'))==='AAA010101AAA')throw new RuntimeException('La respuesta contiene un timbre artificial no permitido.');
        $this->validateTfdSchema($tfd);
        $this->compareHeaders($signed->documentElement,$root);
        $expectedSeal=$signed->documentElement->getAttribute('Sello');
        if($expectedSeal!==''&&!hash_equals($expectedSeal,$tfd->getAttribute('SelloCFD')))throw new RuntimeException('SelloCFD no coincide con el sello local enviado.');
        if(!hash_equals($root->getAttribute('Sello'),$tfd->getAttribute('SelloCFD')))throw new RuntimeException('SelloCFD no coincide con el CFDI timbrado.');
        $copy=$this->load($stampedXml);$copyXp=new DOMXPath($copy);$copyXp->registerNamespace('tfd',self::TFD_NS);
        foreach(iterator_to_array($copyXp->query('//tfd:TimbreFiscalDigital')) as $node){$parent=$node->parentNode;$parent->removeChild($node);if(!$parent->hasChildNodes())$parent->parentNode?->removeChild($parent);}
        if($expectedSeal==='')$copy->documentElement->setAttribute('Sello','');
        if(!hash_equals(hash('sha256',(string)$signed->C14N(true,false)),hash('sha256',(string)$copy->C14N(true,false))))throw new RuntimeException('El PAC modificó contenido fiscal fuera del TimbreFiscalDigital.');
        return [
            'uuid'=>$uuid,'stamp_date'=>$tfd->getAttribute('FechaTimbrado'),'pac_rfc'=>$tfd->getAttribute('RfcProvCertif'),
            'sat_certificate_number'=>$tfd->getAttribute('NoCertificadoSAT'),'cfd_seal'=>$tfd->getAttribute('SelloCFD'),
            'sat_seal'=>$tfd->getAttribute('SelloSAT'),'tfd_version'=>$tfd->getAttribute('Version'),
            'sha256'=>hash('sha256',$stampedXml),
        ];
    }
    private function compareHeaders(DOMElement $a,DOMElement $b):void
    {
        foreach(['Version','Serie','Folio','SubTotal','Descuento','Total','Moneda','TipoDeComprobante']as$n)if($a->getAttribute($n)!==$b->getAttribute($n))throw new RuntimeException("El PAC modificó el atributo {$n}.");
        if($a->getAttribute('Sello')!==''&&$a->getAttribute('Sello')!==$b->getAttribute('Sello'))throw new RuntimeException('El PAC modificó el atributo Sello.');
        foreach(['Emisor'=>'Rfc','Receptor'=>'Rfc']as$name=>$attr){$av=$a->getElementsByTagNameNS(self::CFDI_NS,$name)->item(0)?->getAttribute($attr);$bv=$b->getElementsByTagNameNS(self::CFDI_NS,$name)->item(0)?->getAttribute($attr);if($av!==$bv)throw new RuntimeException("El PAC modificó el RFC de {$name}.");}
    }
    private function load(string $xml):DOMDocument
    {
        if(stripos($xml,'<!DOCTYPE')!==false||stripos($xml,'<!ENTITY')!==false)throw new RuntimeException('DTD y entidades no están permitidas.');
        $dom=new DOMDocument();$previous=libxml_use_internal_errors(true);
        try{if(!$dom->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS|LIBXML_NOCDATA))throw new RuntimeException('El XML del PAC no está bien formado.');}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        return $dom;
    }
    private function validateTfdSchema(DOMElement $tfd):void
    {
        $schema=ROOTPATH.'resources/fiscal/sat/cfdi40/TimbreFiscalDigitalv11.xsd';
        $types=ROOTPATH.'resources/fiscal/sat/cfdi40/tdCFDI.xsd';
        if(!is_file($schema)||!is_file($types))throw new RuntimeException('Los esquemas locales del TimbreFiscalDigital no están disponibles.');
        $dom=new DOMDocument('1.0','UTF-8');$dom->appendChild($dom->importNode($tfd,true));
        $map=['http://www.sat.gob.mx/sitio_internet/cfd/tipoDatos/tdCFDI/tdCFDI.xsd'=>$types];
        $previous=libxml_use_internal_errors(true);libxml_clear_errors();
        $old=libxml_set_external_entity_loader(static function($public,$system)use($map,$schema,$types){if(isset($map[$system]))return fopen($map[$system],'rb');$candidate=str_replace('\\','/',(string)$system);foreach([$schema,$types]as$p)if($candidate===str_replace('\\','/',$p)||$candidate==='file:///'.str_replace('\\','/',$p))return fopen($p,'rb');return null;});
        try{if(!@$dom->schemaValidate($schema)){throw new RuntimeException('El TimbreFiscalDigital no supera su XSD oficial 1.1.');}}
        finally{libxml_set_external_entity_loader(is_callable($old)?$old:null);libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
}
