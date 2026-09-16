<?php
namespace App\Database\Seeds;
use CodeIgniter\Database\Seeder;
class Increment03ItemFiscalCatalogsSeeder extends Seeder { public function run(){foreach([SatProductServiceKeysSeeder::class,SatUnitKeysSeeder::class,SatTaxObjectCodesSeeder::class]as$s)$this->call($s);} }
