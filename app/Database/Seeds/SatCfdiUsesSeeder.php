<?php
namespace App\Database\Seeds;
use App\Database\Seeds\ExplicitConnectionSeeder as Seeder;
class SatCfdiUsesSeeder extends Seeder { public function run(){ $now=date('Y-m-d H:i:s'); $rows=[['G01','Adquisición de mercancías'],['G02','Devoluciones, descuentos o bonificaciones'],['G03','Gastos en general'],['S01','Sin efectos fiscales'],['CP01','Pagos']]; foreach($rows as $r) if(!$this->db->table('sat_cfdi_uses')->where('code',$r[0])->countAllResults()) $this->db->table('sat_cfdi_uses')->insert(['code'=>$r[0],'description'=>$r[1],'applies_to_individual'=>1,'applies_to_company'=>1,'valid_from'=>null,'valid_to'=>null,'is_active'=>1,'created_at'=>$now,'updated_at'=>$now]); } }
