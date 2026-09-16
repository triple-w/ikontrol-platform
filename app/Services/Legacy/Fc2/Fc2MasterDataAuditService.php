<?php
declare(strict_types=1);
namespace App\Services\Legacy\Fc2;
use App\Contracts\Legacy\Fc2\{Fc2IssuerSourceInterface,Fc2ClientSourceInterface,Fc2ProductSourceInterface,Fc2SeriesSourceInterface};
final class Fc2MasterDataAuditService {
 public const SCHEMA_VERSION='fc2-master-data-audit-v1';
 public function __construct(private Fc2ConnectionGuard$guard,private Fc2IssuerSourceInterface$issuers,private Fc2ClientSourceInterface$clients,private Fc2ProductSourceInterface$products,private Fc2SeriesSourceInterface$series,private?Fc2MasterDataCompatibilityService$compat=null){$this->compat??=new Fc2MasterDataCompatibilityService();}
 public function audit(string$rfc,int$ownerId,array$only=[]):array{$verified=$this->guard->verify($rfc,$ownerId);$only=$only?:['issuer','clients','products','series'];$findings=[];$report=['generated_at'=>gmdate('c'),'source_database'=>$verified['source_database'],'source_system'=>'fc2','source_owner_id'=>(string)$ownerId,'source_owner_key'=>$verified['rfc'],'schema_version'=>self::SCHEMA_VERSION];$hashes=[];
  if(in_array('issuer',$only,true)){$i=$this->issuers->findByRfc($rfc,$ownerId);$report['issuer_summary']=['found'=>true,'users_id'=>$ownerId,'profile_id'=>$i->sourceId,'fiscal_profile'=>true,'csd_metadata_count'=>count($i->csdMetadata),'logo_metadata_count'=>count($i->logoMetadata)];$hashes[]=$i->sourceHash;}
  if(in_array('clients',$only,true)){$rs=iterator_to_array($this->clients->iterateByOwner($ownerId),false);$x=$this->compat->clients($rs);$report['client_summary']=$this->compat->summary($rs,$x);$findings=array_merge($findings,$x);foreach($rs as$r)$hashes[]=$r->sourceHash;}
  if(in_array('products',$only,true)){$rs=iterator_to_array($this->products->iterateByOwner($ownerId),false);$x=$this->compat->products($rs);$report['product_summary']=$this->compat->summary($rs,$x);$findings=array_merge($findings,$x);foreach($rs as$r)$hashes[]=$r->sourceHash;}
  if(in_array('series',$only,true)){$rs=$this->series->allByOwner($ownerId);$x=$this->compat->series($rs);$report['series_summary']=$this->compat->summary($rs,$x);$findings=array_merge($findings,$x);foreach($rs as$r)$hashes[]=$r->sourceHash;}
  sort($hashes,SORT_STRING);$report['findings']=$findings;$report['source_hash_summary']=['record_count'=>count($hashes),'combined_sha256'=>hash('sha256',implode("\n",$hashes))];return$report;
 }
}
