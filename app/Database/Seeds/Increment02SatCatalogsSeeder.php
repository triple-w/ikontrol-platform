<?php
namespace App\Database\Seeds;
use CodeIgniter\Database\Seeder;
class Increment02SatCatalogsSeeder extends Seeder { public function run(){ foreach([SatTaxCodesSeeder::class,SatTaxFactorTypesSeeder::class,SatTaxRegimesSeeder::class,SatCfdiUsesSeeder::class] as $s) $this->call($s); } }
