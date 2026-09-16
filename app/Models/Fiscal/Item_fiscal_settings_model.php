<?php
namespace App\Models\Fiscal;
use App\Models\Crud_model;
class Item_fiscal_settings_model extends Crud_model { public function __construct(){parent::__construct('item_fiscal_settings');} public function activeForItem(int$itemId): ?object{return $this->db->table($this->table)->where(['item_id'=>$itemId,'deleted'=>0,'is_default'=>1])->orderBy('id','DESC')->get(1)->getRow();} public function setDefault(int$itemId,int$id):bool{$this->db->transStart();$this->db->table($this->table)->where(['item_id'=>$itemId,'deleted'=>0])->update(['is_default'=>0]);$this->db->table($this->table)->where(['id'=>$id,'item_id'=>$itemId,'deleted'=>0])->update(['is_default'=>1]);$this->db->transComplete();return $this->db->transStatus();} }
