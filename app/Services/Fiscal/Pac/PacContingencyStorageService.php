<?php
declare(strict_types=1);
namespace App\Services\Fiscal\Pac;
use RuntimeException;

final class PacContingencyStorageService
{
    private string $root;
    public function __construct(private readonly PacSecretVault $vault,?string $root=null){$this->root=rtrim($root?:WRITEPATH.'fiscal/pac-contingency','/\\');}
    public function store(int $attemptId,string $xml):array
    {
        return $this->storePayload($attemptId,$xml);
    }
    public function storePayload(int $attemptId,string $xml):array
    {
        if(!is_dir($this->root)&&!mkdir($this->root,0700,true)&&!is_dir($this->root))throw new RuntimeException('No fue posible crear contingencia PAC.');
        @chmod($this->root,0700);$name=bin2hex(random_bytes(24)).'.enc';$target=$this->root.DIRECTORY_SEPARATOR.$name;$tmp=$target.'.tmp';
        $encrypted=$this->vault->encrypt($xml);
        if(file_put_contents($tmp,$encrypted,LOCK_EX)===false||!rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('No fue posible conservar la respuesta PAC de contingencia.');}
        @chmod($target,0600);
        return ['path'=>'fiscal/pac-contingency/'.$name,'hash'=>hash('sha256',$xml),'attempt_id'=>$attemptId];
    }
    public function read(string $relative):string
    {
        if(!preg_match('#^fiscal/pac-contingency/([a-f0-9]{48}\.enc)$#',$relative,$m))throw new RuntimeException('Ruta de contingencia inválida.');
        $base=realpath($this->root);$path=realpath($this->root.DIRECTORY_SEPARATOR.$m[1]);
        if(!$base||!$path||!str_starts_with($path,$base.DIRECTORY_SEPARATOR))throw new RuntimeException('Acceso a contingencia denegado.');
        return $this->vault->decrypt((string)file_get_contents($path));
    }
}
