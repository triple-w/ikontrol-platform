<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class IndexSupplierCostComparison extends Migration
{
    private string $name = 'idx_cost_history_product_supplier_date';
    public function up()
    {
        if (!$this->db->tableExists('product_supplier_cost_history')) return;
        $indexes=$this->db->getIndexData('product_supplier_cost_history');
        if(!isset($indexes[$this->name])) $this->db->query('ALTER TABLE '.$this->db->prefixTable('product_supplier_cost_history').' ADD INDEX '.$this->name.' (product_id,supplier_id,quoted_at)');
    }
    public function down()
    {
        if($this->db->tableExists('product_supplier_cost_history')&&isset($this->db->getIndexData('product_supplier_cost_history')[$this->name])) $this->db->query('ALTER TABLE '.$this->db->prefixTable('product_supplier_cost_history').' DROP INDEX '.$this->name);
    }
}
