<?php
namespace App\Database\Seeds;
use App\Database\Seeds\ExplicitConnectionSeeder as Seeder;
class SatTaxRegimesSeeder extends Seeder { public function run(){ $now=date('Y-m-d H:i:s'); $rows=[
['601','General de Ley Personas Morales',0,1],['603','Personas Morales con Fines no Lucrativos',0,1],['605','Sueldos y Salarios e Ingresos Asimilados a Salarios',1,0],['606','Arrendamiento',1,0],['612','Personas Físicas con Actividades Empresariales y Profesionales',1,0],['616','Sin obligaciones fiscales',1,0],['621','Incorporación Fiscal',1,0],['625','Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas',1,0],['626','Régimen Simplificado de Confianza',1,1]];
foreach($rows as $r) if(!$this->db->table('sat_tax_regimes')->where('code',$r[0])->countAllResults()) $this->db->table('sat_tax_regimes')->insert(['code'=>$r[0],'description'=>$r[1],'applies_to_individual'=>$r[2],'applies_to_company'=>$r[3],'valid_from'=>null,'valid_to'=>null,'is_active'=>1,'created_at'=>$now,'updated_at'=>$now]); } }
