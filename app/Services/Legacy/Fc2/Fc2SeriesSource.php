<?php
declare(strict_types=1);
namespace App\Services\Legacy\Fc2;
use App\Contracts\Legacy\Fc2\Fc2SeriesSourceInterface;use App\Domain\Legacy\Fc2\Fc2SeriesRecord;
final class Fc2SeriesSource implements Fc2SeriesSourceInterface {
 public function __construct(private$db=null,private?Fc2DataNormalizer$n=null){$this->db??=db_connect('fc2_legacy');$this->n??=new Fc2DataNormalizer();}
 public function allByOwner(int$ownerId):array{$out=[];foreach($this->db->table('folios')->where('users_id',$ownerId)->orderBy('id')->get()->getResultArray()as$r){$data=['legacy_id'=>(string)$r['id'],'tipo'=>$this->n->text($r['tipo']),'serie'=>$this->n->text($r['serie']),'folio'=>(string)$r['folio']];$snapshot=$data+['users_id'=>(string)$r['users_id']];$out[]=new Fc2SeriesRecord($ownerId,(int)$r['id'],$snapshot,$this->n->hash($snapshot),$data);}return$out;}
}
