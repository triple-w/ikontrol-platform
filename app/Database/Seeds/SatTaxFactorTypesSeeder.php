<?php
namespace App\Database\Seeds;
use App\Database\Seeds\ExplicitConnectionSeeder as Seeder;
class SatTaxFactorTypesSeeder extends Seeder { public function run(){ $now=date('Y-m-d H:i:s'); foreach([['Tasa','Tasa'],['Cuota','Cuota'],['Exento','Exento']] as $r){ if(!$this->db->table('sat_tax_factor_types')->where('code',$r[0])->countAllResults()) $this->db->table('sat_tax_factor_types')->insert(['code'=>$r[0],'name'=>$r[1],'is_active'=>1,'created_at'=>$now,'updated_at'=>$now]); } } }
