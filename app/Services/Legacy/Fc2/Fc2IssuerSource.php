<?php
declare(strict_types=1);
namespace App\Services\Legacy\Fc2;
use App\Contracts\Legacy\Fc2\Fc2IssuerSourceInterface;use App\Domain\Legacy\Fc2\Fc2IssuerRecord;use RuntimeException;
final class Fc2IssuerSource implements Fc2IssuerSourceInterface {
 public function __construct(private$db=null,private?Fc2DataNormalizer$n=null){$this->db??=db_connect('fc2_legacy');$this->n??=new Fc2DataNormalizer();}
 public function findByRfc(string$rfc,int$expectedOwnerId):Fc2IssuerRecord{$rfc=$this->n->rfc($rfc);$rows=$this->db->table('users_perfil')->where('rfc',$rfc)->orderBy('id')->get()->getResultArray();if(count($rows)!==1)throw new RuntimeException(count($rows)?'Ambiguous FC2 issuer.':'FC2 issuer not found.');$r=$rows[0];if((int)$r['users_id']!==$expectedOwnerId)throw new RuntimeException('FC2 issuer owner mismatch.');
  $snapshot=['id'=>(string)$r['id'],'users_id'=>(string)$r['users_id'],'rfc_original'=>(string)$r['rfc'],'rfc'=>$rfc,'razon_social_original'=>(string)$r['razon_social'],'razon_social'=>$this->n->text($r['razon_social']),'regimen_fiscal'=>$this->n->text($r['numero_regimen33']?:$r['numero_regimen']),'codigo_postal'=>$this->n->text($r['codigo_postal']),'domicilio'=>$this->address($r)];
  $info=$this->db->table('users_info_factura')->select('id')->where('users_id',$expectedOwnerId)->orderBy('id')->get()->getResultArray();$csd=[];foreach($info as$i)foreach($this->db->table('users_info_factura_documentos')->select('id,tipo,_mime_type,_size,validado,revisado,numero_certificado,vigencia')->where('users_factura_info_id',$i['id'])->orderBy('id')->get()->getResultArray()as$d)$csd[]=$d;
  $logos=$this->db->table('users_logo')->select('id,_mime_type,_size')->where('users_id',$expectedOwnerId)->orderBy('id')->get()->getResultArray();
  return new Fc2IssuerRecord($expectedOwnerId,(int)$r['id'],$snapshot,$this->n->hash($snapshot),$snapshot,$csd,$logos);
 }
 private function address(array$r):array{$out=[];foreach(['calle','no_ext','no_int','colonia','municipio','localidad','estado','codigo_postal','pais','telefono','nombre_contacto']as$k)$out[$k]=$this->n->text($r[$k]??null);return$out;}
}
