<?php
declare(strict_types=1);
namespace App\Services\Legacy\Fc2;
use RuntimeException;
final class Fc2ConnectionGuard {
 private const TABLES=['users','users_perfil','users_info_factura','users_info_factura_documentos','users_logo','clientes','productos','folios','clave_prod_serv','clave_unidad'];
 public function __construct(private$db=null,private$destinationDb=null){$this->db??=db_connect('fc2_legacy');$this->destinationDb??=db_connect('default');}
 public function verify(string$rfc,int$ownerId):array{
  try{$source=(string)$this->db->query('SELECT DATABASE() db')->getRow()->db;$destination=(string)$this->destinationDb->query('SELECT DATABASE() db')->getRow()->db;}catch(\Throwable){throw new RuntimeException('No fue posible conectar de forma segura al origen FC2.');}
  if($source===''||strcasecmp($source,$destination)===0)throw new RuntimeException('La conexión FC2 apunta a la base iKontrol o no identifica una base distinta.');
  $missing=[];foreach(self::TABLES as$t)if(!$this->db->tableExists($t))$missing[]=$t;if($missing)throw new RuntimeException('FC2 no contiene todas las tablas requeridas: '.implode(', ',$missing).'.');
  $n=new Fc2DataNormalizer();$expected=$n->rfc($rfc);$profiles=$this->db->table('users_perfil')->select('id,users_id,rfc')->where('rfc',$expected)->get()->getResult();
  if(count($profiles)!==1)throw new RuntimeException(count($profiles)?'El emisor FC2 es ambiguo para el RFC solicitado.':'No se encontró el emisor FC2 solicitado.');
  $profile=$profiles[0];if((int)$profile->users_id!==$ownerId)throw new RuntimeException('El RFC FC2 no corresponde al users.id esperado.');
  $user=$this->db->table('users')->select('id,username')->where('id',$ownerId)->get(1)->getRow();if(!$user||$n->rfc($user->username)!==$expected)throw new RuntimeException('La identidad del usuario FC2 no corresponde al RFC esperado.');
  return['source_database'=>$this->maskDatabase($source),'profile_id'=>(int)$profile->id,'owner_id'=>$ownerId,'rfc'=>$expected];
 }
 public function maskDatabase(string$name):string{$l=strlen($name);return$l<=4?str_repeat('*',$l):substr($name,0,2).str_repeat('*',$l-4).substr($name,-2);}
}
