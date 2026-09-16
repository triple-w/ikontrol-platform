<?php
declare(strict_types=1);
namespace App\Services\Legacy\Fc2;
use App\Contracts\Legacy\Fc2\Fc2ClientSourceInterface;use App\Domain\Legacy\Fc2\Fc2ClientRecord;
final class Fc2ClientSource implements Fc2ClientSourceInterface {
 public function __construct(private$db=null,private?Fc2DataNormalizer$n=null){$this->db??=db_connect('fc2_legacy');$this->n??=new Fc2DataNormalizer();}
 public function iterateByOwner(int$ownerId,int$chunkSize=200):iterable{$last=0;$chunkSize=max(1,min(1000,$chunkSize));do{$rows=$this->db->table('clientes')->where('users_id',$ownerId)->where('id >',$last)->orderBy('id','ASC')->limit($chunkSize)->get()->getResultArray();foreach($rows as$r){$last=(int)$r['id'];yield$this->record($ownerId,$r);}}while(count($rows)===$chunkSize);}
 private function record(int$o,array$r):Fc2ClientRecord{$rfc=$this->n->rfc($r['rfc']);$data=['legacy_id'=>(string)$r['id'],'rfc'=>$rfc,'rfc_original'=>(string)$r['rfc'],'rfc_valid'=>$this->n->validRfc($rfc),'razon_social'=>$this->n->text($r['razon_social']),'razon_social_original'=>(string)$r['razon_social'],'razon_social_comparison'=>$this->n->duplicateText($r['razon_social']),'regimen_fiscal'=>$this->n->text($r['regimen_fiscal']),'codigo_postal'=>$this->n->text($r['codigo_postal']),'email'=>$this->n->text($r['email']),'email_comparison'=>$this->n->emailComparison($r['email']),'telefono'=>$this->n->text($r['telefono']),'contacto'=>$this->n->text($r['nombre_contacto']),'pais'=>$this->n->text($r['pais']),'observaciones'=>null,'domicilio'=>[]];foreach(['calle','no_ext','no_int','colonia','municipio','localidad','estado']as$k)$data['domicilio'][$k]=$this->n->text($r[$k]);$snapshot=$data+['users_id'=>(string)$r['users_id']];return new Fc2ClientRecord($o,(int)$r['id'],$snapshot,$this->n->hash($snapshot),$data);}
}
