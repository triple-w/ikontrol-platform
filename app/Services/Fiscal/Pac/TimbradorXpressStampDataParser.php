<?php
declare(strict_types=1);
namespace App\Services\Fiscal\Pac;
use App\Domain\Fiscal\Pac\PacResponse;
use RuntimeException;

final class TimbradorXpressStampDataParser
{
    private const FIELDS=['XML','UUID','FechaTimbrado','NoCertificado','NoCertificadoSAT','CadenaOriginal','CadenaOriginalSAT','Sello','SelloSAT','CodigoQR','PDF'];
    public function parse(PacResponse $response):array
    {
        if($response->data===null||trim($response->data)==='')throw new RuntimeException('La respuesta timbrar3 no contiene data.');
        $raw=trim($response->data);
        if(str_starts_with($raw,'<?xml')||str_starts_with($raw,'<cfdi:Comprobante')){
            $dom=new \DOMDocument();$previous=libxml_use_internal_errors(true);$loaded=$dom->loadXML($raw,LIBXML_NONET|LIBXML_NOBLANKS);libxml_clear_errors();libxml_use_internal_errors($previous);
            if(!$loaded||$dom->documentElement?->localName!=='Comprobante'||$dom->documentElement?->namespaceURI!=='http://www.sat.gob.mx/cfd/4')throw new RuntimeException('data contiene XML, pero no es CFDI 4.0.');
            $xpath=new \DOMXPath($dom);$xpath->registerNamespace('tfd','http://www.sat.gob.mx/TimbreFiscalDigital');if($xpath->query('//tfd:TimbreFiscalDigital')->length!==1)throw new RuntimeException('data contiene CFDI sin TimbreFiscalDigital único.');
            $out=array_fill_keys(self::FIELDS,null);$out['XML']=$raw;return$out;
        }
        try{$data=json_decode($response->data,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new RuntimeException('data de timbrar3 no contiene JSON válido.');}
        if(!is_array($data))throw new RuntimeException('data de timbrar3 no es un objeto.');
        $out=[];foreach(self::FIELDS as$field){$value=$data[$field]??$data[strtolower($field)]??null;if($value!==null&&!is_scalar($value))throw new RuntimeException("El campo {$field} de timbrar3 tiene un tipo inválido.");$out[$field]=$value===null?null:(string)$value;}
        if(trim((string)$out['XML'])==='')throw new RuntimeException('timbrar3 no devolvió XML.');
        return$out;
    }
}
