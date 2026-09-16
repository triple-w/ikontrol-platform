<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
use RuntimeException;
class AddIssuerTaxPricingPolicy extends Migration{
 public function up():void{if(!$this->db->tableExists('fiscal_profiles'))throw new RuntimeException('Cannot add issuer pricing policy: fiscal_profiles is missing.');if(!$this->db->fieldExists('tax_pricing_mode','fiscal_profiles'))$this->forge->addColumn('fiscal_profiles',['tax_pricing_mode'=>['type'=>'VARCHAR','constraint'=>20,'null'=>true]]);if(!$this->db->fieldExists('allow_sale_tax_pricing_override','fiscal_profiles'))$this->forge->addColumn('fiscal_profiles',['allow_sale_tax_pricing_override'=>['type'=>'TINYINT','constraint'=>1,'default'=>0]]);}
 public function down():void{if(!$this->db->tableExists('fiscal_profiles'))return;foreach(['allow_sale_tax_pricing_override','tax_pricing_mode']as$f)if($this->db->fieldExists($f,'fiscal_profiles'))$this->forge->dropColumn('fiscal_profiles',$f);}
}
