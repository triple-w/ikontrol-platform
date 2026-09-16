<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class AddWarehouseProductLabelLogo extends Migration{
 public function up(){if(!$this->db->fieldExists('label_logo','warehouse_products'))$this->forge->addColumn('warehouse_products',['label_logo'=>['type'=>'VARCHAR','constraint'=>255,'null'=>true,'after'=>'barcode']]);}
 public function down(){if($this->db->fieldExists('label_logo','warehouse_products'))$this->forge->dropColumn('warehouse_products','label_logo');}
}
