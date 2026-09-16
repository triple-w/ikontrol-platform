<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Signing;

use App\Domain\Fiscal\Signing\SignedXmlVerificationResult;
use App\Services\Fiscal\Cfdi40\CfdiOriginalChainGenerator;
use App\Services\Fiscal\Cfdi40\CfdiXsdValidator;
use DOMDocument;
use DOMXPath;

final class SignedXmlVerifier
{
    public function __construct(
        private readonly ?CfdiOriginalChainGenerator $chainGenerator = null,
        private readonly ?CfdiXsdValidator $xsdValidator = null
    ) {
    }

    public function verify(string $xml, string $expectedIssuerRfc, ?string $expectedSha256 = null): SignedXmlVerificationResult
    {
        $errors=[];$warnings=[];$hash=hash('sha256',$xml);
        $wellFormed=$xsdValid=$certificateValid=$certificateMatches=$numberMatches=$signatureValid=$chainGenerated=false;
        $number=$certificateRfc=$validFrom=$validTo=null;
        if($expectedSha256!==null&&!hash_equals(strtolower($expectedSha256),$hash))$errors[]='El hash del XML no coincide con el artefacto firmado.';
        $old=libxml_use_internal_errors(true);libxml_clear_errors();
        try{
            $dom=new DOMDocument('1.0','UTF-8');$dom->preserveWhiteSpace=false;$dom->formatOutput=false;
            if(!$dom->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS)){
                foreach(libxml_get_errors()as$e)$errors[]='XML: '.trim($e->message);
                return$this->result(false,false,false,false,false,false,false,$errors,$warnings,$hash);
            }
            $wellFormed=true;$root=$dom->documentElement;
            if(!$root||$root->localName!=='Comprobante'||$root->namespaceURI!=='http://www.sat.gob.mx/cfd/4'||$root->getAttribute('Version')!=='4.0')$errors[]='El documento no es un cfdi:Comprobante 4.0.';
            $xpath=new DOMXPath($dom);
            if($xpath->query('//*[local-name()="TimbreFiscalDigital"]')->length>0)$errors[]='El XML previo al PAC ya contiene TimbreFiscalDigital.';
            $certificate=trim($root?->getAttribute('Certificado')??'');$number=trim($root?->getAttribute('NoCertificado')??'');$seal=trim($root?->getAttribute('Sello')??'');
            if($certificate===''||$number===''||$seal==='')$errors[]='Faltan Certificado, NoCertificado o Sello.';
            $der=$certificate!==''?base64_decode($certificate,true):false;
            if($der===false)$errors[]='Certificado no contiene Base64 válido.';
            else{
                $pem="-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der),64,"\n")."-----END CERTIFICATE-----\n";
                $x509=@openssl_x509_read($pem);$parsed=$x509?openssl_x509_parse($x509,false):false;
                if(!$parsed)$errors[]='Certificado X.509 ilegible.';
                else{
                    $certificateValid=true;$certificateRfc=$this->extractRfc($parsed);$certificateMatches=$certificateRfc!==''&&$this->normalizeRfc($certificateRfc)===$this->normalizeRfc($expectedIssuerRfc);
                    if(!$certificateMatches)$errors[]='El RFC del certificado no coincide con el emisor congelado.';
                    $serial=$this->certificateNumber($parsed);$numberMatches=$serial!==''&&hash_equals($serial,$number);
                    if(!$numberMatches)$errors[]='NoCertificado no coincide con el certificado embebido.';
                    $validFrom=isset($parsed['validFrom_time_t'])?gmdate('Y-m-d H:i:s',(int)$parsed['validFrom_time_t']):null;
                    $validTo=isset($parsed['validTo_time_t'])?gmdate('Y-m-d H:i:s',(int)$parsed['validTo_time_t']):null;
                    $now=time();if(!isset($parsed['validFrom_time_t'],$parsed['validTo_time_t'])||$now<(int)$parsed['validFrom_time_t']||$now>(int)$parsed['validTo_time_t']){$certificateValid=false;$errors[]='El certificado no se encuentra vigente.';}
                    try{
                        $chain=($this->chainGenerator??new CfdiOriginalChainGenerator())->generate($xml);$chainGenerated=true;$decodedSeal=base64_decode($seal,true);
                        if($decodedSeal===false)$errors[]='El Sello no contiene Base64 válido.';
                        else{$public=openssl_pkey_get_public($pem);$signatureValid=$public&&openssl_verify($chain['chain'],$decodedSeal,$public,OPENSSL_ALGO_SHA256)===1;if(!$signatureValid)$errors[]='La firma RSA-SHA256 no es válida para la cadena original recalculada.';}
                    }catch(\Throwable$e){$errors[]='No fue posible recalcular la cadena original: '.$e->getMessage();}
                }
            }
            try{$xsd=($this->xsdValidator??new CfdiXsdValidator())->validate($xml);$xsdValid=$xsd['status']==='valid';if(!$xsdValid)$errors[]='El XML no supera la validación XSD CFDI 4.0.';}catch(\Throwable$e){$errors[]='Validación XSD no disponible: '.$e->getMessage();}
            return$this->result($wellFormed,$xsdValid,$certificateValid,$certificateMatches,$numberMatches,$signatureValid,$chainGenerated,$errors,$warnings,$hash,$number,$certificateRfc,$validFrom,$validTo);
        }finally{libxml_clear_errors();libxml_use_internal_errors($old);}
    }

    private function result(bool$wellFormed,bool$xsd,bool$certificate,bool$issuer,bool$number,bool$signature,bool$chain,array$errors,array$warnings,string$hash,?string$certificateNumber=null,?string$rfc=null,?string$from=null,?string$to=null):SignedXmlVerificationResult
    {
        return new SignedXmlVerificationResult($errors===[]&&$wellFormed&&$xsd&&$certificate&&$issuer&&$number&&$signature&&$chain,$wellFormed,$xsd,$certificate,$issuer,$number,$signature,$chain,$errors,$warnings,$hash,$certificateNumber,$rfc,$from,$to);
    }
    private function certificateNumber(array$p):string{$hex=strtoupper((string)($p['serialNumberHex']??''));$ascii=$hex!==''&&strlen($hex)%2===0?@hex2bin($hex):false;$number=is_string($ascii)&&preg_match('/^\d{20}$/',$ascii)?$ascii:(string)($p['serialNumber']??'');return preg_match('/^\d{20}$/',$number)?$number:'';}
    private function extractRfc(array $parsed): string
    {
        $values = [];
        $walk = function ($value) use (&$walk, &$values): void {
            if (is_array($value)) {
                foreach ($value as $entry) {
                    $walk($entry);
                }
            } elseif (is_scalar($value)) {
                $values[] = (string) $value;
            }
        };
        $walk($parsed['subject'] ?? []);
        $walk($parsed['extensions']['subjectAltName'] ?? '');
        foreach ($values as $value) {
            $upper = mb_strtoupper($value, 'UTF-8');
            if (preg_match('/(?:^|[^A-Z0-9&\x{00D1}])([A-Z&\x{00D1}]{3,4}[0-9]{6}[A-Z0-9]{3})(?:$|[^A-Z0-9])/u', $upper, $match)) {
                return $this->normalizeRfc($match[1]);
            }
        }
        return '';
    }
    private function normalizeRfc(string $rfc): string
    {
        return preg_replace('/[^A-Z0-9&\x{00D1}]/u', '', mb_strtoupper(trim($rfc), 'UTF-8'));
    }
}
