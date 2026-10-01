<?php
namespace App\Models\Fiscal;
use App\Services\Fiscal\SatCatalogTextNormalizer;
class Sat_unit_keys_model extends Sat_catalog_model {
    public function __construct(){parent::__construct('sat_unit_keys');}
    public function search(string $term,int $page=1,int $limit=20):array{
        $term=trim($term);$page=max(1,$page);$limit=min(max($limit,1),50);
        if(mb_strlen($term)<3&&!preg_match('/^[A-Za-z0-9]+$/',$term))return['results'=>[],'more'=>false];
        $descriptionTerm=SatCatalogTextNormalizer::description($term);
        $exact=$this->db->escape($term);$codePrefix=$this->db->escape($this->db->escapeLikeString($term).'%');$descriptionPrefix=$this->db->escape($this->db->escapeLikeString($descriptionTerm).'%');
        $rank="CASE WHEN code = $exact THEN 0 WHEN code LIKE $codePrefix THEN 1 WHEN normalized_description LIKE $descriptionPrefix THEN 2 ELSE 3 END";
        $builder=$this->db->table($this->table)->select("id,code,name,description,$rank AS search_rank",false)->where('is_active',1)->groupStart()->like('code',$term,'after')->orLike('normalized_description',$descriptionTerm,'after')->groupEnd()->orderBy('search_rank')->orderBy('code')->limit($limit+1,($page-1)*$limit);
        $rows=$builder->get()->getResult();$more=count($rows)>$limit;if($more)array_pop($rows);
        return['results'=>array_map(fn($row)=>['id'=>(int)$row->id,'text'=>$row->code.' - '.$row->name],$rows),'more'=>$more];
    }
}
