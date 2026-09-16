<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pac;

use RuntimeException;

final class PacPdfValidator
{
    public function __construct(private readonly int $maxDecodedBytes=10485760){}

    public function validate(string $base64):array
    {
        $clean=trim($base64);
        if(str_starts_with(strtolower($clean),'data:'))throw new RuntimeException('El PDF del PAC no debe contener una data URI.');
        if($clean===''||strlen($clean)>($this->maxDecodedBytes*4/3)+16)throw new RuntimeException('El PDF Base64 está vacío o excede el límite permitido.');
        if(preg_match('/\s/',$clean))throw new RuntimeException('El PDF Base64 contiene espacios internos no permitidos.');
        $bytes=base64_decode($clean,true);
        if($bytes===false)throw new RuntimeException('El PAC devolvió Base64 inválido para el PDF.');
        $size=strlen($bytes);
        if($size===0||$size>$this->maxDecodedBytes)throw new RuntimeException('El PDF decodificado está vacío o excede el límite permitido.');
        if(!str_starts_with($bytes,'%PDF-'))throw new RuntimeException('El contenido decodificado no tiene encabezado PDF.');
        $prefix=strtolower(substr(ltrim($bytes),0,64));if(str_starts_with($prefix,'<html')||str_starts_with($prefix,'<!doctype')||str_starts_with($prefix,'{'))throw new RuntimeException('El contenido del PAC no es un PDF.');
        if(!preg_match('/%%EOF\s*$/s',substr($bytes,-2048)))throw new RuntimeException('El PDF no contiene un cierre válido.');
        $pages=$this->validateStructure($bytes);
        return['content_base64'=>$clean,'bytes'=>$bytes,'decoded_size_bytes'=>$size,'decoded_sha256'=>hash('sha256',$bytes),'decoded_mime_type'=>'application/pdf','validation_status'=>'valid','page_count'=>$pages];
    }

    private function validateStructure(string $bytes):int
    {
        require_once APPPATH.'ThirdParty/tcpdf/tcpdf_parser.php';
        try{
            $parser=new \TCPDF_PARSER($bytes,[
                'die_for_errors'=>false,
                'ignore_filter_decoding_errors'=>false,
                'ignore_missing_filter_decoders'=>false,
            ]);
            [$xref,$objects]=$parser->getParsedData();
            $root=$xref['trailer']['root']??null;
            if(!is_string($root)||!isset($objects[$root]))throw new RuntimeException('Catálogo PDF ausente.');
            $catalog=$this->dictionary($objects[$root]);
            $this->requireName($this->value($catalog,'Type'),'Catalog');
            $pages=$this->value($catalog,'Pages');
            if(($pages[0]??null)!=='objref')throw new RuntimeException('El catálogo no contiene una referencia Pages válida.');
            $visited=[];
            $count=$this->countPages((string)$pages[1],$objects,$visited,false,false);
            if($count<1)throw new RuntimeException('El PDF no contiene páginas renderizables.');
            return$count;
        }catch(\Throwable $e){
            throw new RuntimeException('PDF_STRUCTURE_INVALID: '.$e->getMessage(),0,$e);
        }
    }

    private function countPages(string $reference,array $objects,array &$visited,bool $inheritedResources,bool $inheritedMediaBox):int
    {
        if(isset($visited[$reference])||!isset($objects[$reference]))throw new RuntimeException('Árbol de páginas circular o incompleto.');
        $visited[$reference]=true;
        $dictionary=$this->dictionary($objects[$reference]);
        $type=$this->value($dictionary,'Type');
        $name=(($type[0]??null)==='/')?(string)$type[1]:'';
        $hasResources=$inheritedResources||$this->value($dictionary,'Resources')!==null;
        $hasMediaBox=$inheritedMediaBox||$this->value($dictionary,'MediaBox')!==null;
        if($name==='Page'){
            if(!$hasResources||!$hasMediaBox||$this->value($dictionary,'Contents')===null)throw new RuntimeException('Página sin MediaBox, Resources o Contents.');
            return 1;
        }
        if($name!=='Pages')throw new RuntimeException('El árbol Pages contiene un objeto de tipo inválido.');
        $kids=$this->value($dictionary,'Kids');
        if(($kids[0]??null)!=='['||!is_array($kids[1]??null)||$kids[1]===[])throw new RuntimeException('Nodo Pages sin hijos.');
        $count=0;
        foreach($kids[1]as$kid){
            if(($kid[0]??null)!=='objref')throw new RuntimeException('Referencia de página inválida.');
            $count+=$this->countPages((string)$kid[1],$objects,$visited,$hasResources,$hasMediaBox);
        }
        $declared=$this->value($dictionary,'Count');
        if(($declared[0]??null)!=='numeric'||(int)$declared[1]!==$count)throw new RuntimeException('El conteo declarado de páginas no coincide.');
        return$count;
    }

    private function dictionary(array $object):array
    {
        foreach($object as$element)if(($element[0]??null)==='<<'&&is_array($element[1]??null))return$element[1];
        throw new RuntimeException('Objeto PDF sin diccionario.');
    }

    private function value(array $dictionary,string $key):?array
    {
        foreach($dictionary as$i=>$token)if(($token[0]??null)==='/'&&($token[1]??null)===$key)return$dictionary[$i+1]??null;
        return null;
    }

    private function requireName(?array $token,string $name):void
    {
        if(($token[0]??null)!=='/'||($token[1]??null)!==$name)throw new RuntimeException("Tipo PDF {$name} ausente.");
    }
}
