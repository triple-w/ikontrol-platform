<?php
namespace App\Models\Fiscal;
class Sat_product_service_keys_model extends Sat_catalog_model {
    public function __construct(){parent::__construct('sat_product_service_keys');}
    public function search(string $term,int $page=1,int $limit=20):array{
        $term=trim($term);$page=max(1,$page);$limit=min(max($limit,1),50);
        if(mb_strlen($term)<3)return['results'=>[],'more'=>false];
        $exact=$this->db->escape($term);$prefix=$this->db->escape($this->db->escapeLikeString($term).'%');
        $rank="CASE WHEN code = $exact THEN 0 WHEN code LIKE $prefix THEN 1 WHEN description LIKE $prefix THEN 2 ELSE 3 END";
        $builder=$this->db->table($this->table)->select("id,code,description,$rank AS search_rank",false)->where('is_active',1)->groupStart()->like('code',$term)->orLike('description',$term)->groupEnd()->orderBy('search_rank')->orderBy('code')->limit($limit+1,($page-1)*$limit);
        $rows=$builder->get()->getResult();$more=count($rows)>$limit;if($more)array_pop($rows);
        return['results'=>array_map(fn($row)=>['id'=>(int)$row->id,'text'=>$row->code.' - '.$row->description],$rows),'more'=>$more];
    }
}
