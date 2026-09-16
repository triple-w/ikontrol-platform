<?php
namespace App\Database\Seeds;
use App\Database\Seeds\ExplicitConnectionSeeder as Seeder;
class SatProductServiceKeysSeeder extends Seeder { public function run(){ $now=date('Y-m-d H:i:s');$inserted=0;$updated=0;$rows=[
 ['01010101','No existe en el catálogo','CFDI 4.0 carga mínima'],
 ['43211503','Computadoras notebook','CFDI 4.0 carga mínima'],
 ['81112100','Servicios de Internet','CFDI 4.0 carga mínima'],
 ]; foreach($rows as $r){$data=['description'=>$r[1],'valid_from'=>null,'valid_to'=>null,'is_active'=>1,'source_version'=>$r[2],'updated_at'=>$now];$found=$this->db->table('sat_product_service_keys')->where('code',$r[0])->get()->getRow();if($found){$this->db->table('sat_product_service_keys')->where('id',$found->id)->update($data);$updated++;}else{$data['code']=$r[0];$data['created_at']=$now;$this->db->table('sat_product_service_keys')->insert($data);$inserted++;}}echo "sat_product_service_keys: inserted=$inserted updated=$updated".PHP_EOL; } }
