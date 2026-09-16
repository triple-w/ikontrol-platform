<?php
namespace App\Database\Seeds;
use App\Database\Seeds\ExplicitConnectionSeeder as Seeder;
class SatTaxCodesSeeder extends Seeder { public function run(){ $now=date('Y-m-d H:i:s'); foreach([['001','ISR'],['002','IVA'],['003','IEPS']] as $r){ if(!$this->db->table('sat_tax_codes')->where('code',$r[0])->countAllResults()) $this->db->table('sat_tax_codes')->insert(['code'=>$r[0],'name'=>$r[1],'description'=>$r[1],'is_active'=>1,'created_at'=>$now,'updated_at'=>$now]); } } }
