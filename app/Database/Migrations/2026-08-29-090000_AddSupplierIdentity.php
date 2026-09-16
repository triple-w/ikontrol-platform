<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddSupplierIdentity extends Migration
{
    public function up()
    {
        if (!$this->db->fieldExists('rfc', 'suppliers')) {
            $this->forge->addColumn('suppliers', [
                'rfc' => ['type' => 'VARCHAR', 'constraint' => 13, 'null' => true, 'after' => 'name'],
            ]);
        }
        if (!$this->db->fieldExists('normalized_name', 'suppliers')) {
            $this->forge->addColumn('suppliers', [
                'normalized_name' => ['type' => 'VARCHAR', 'constraint' => 180, 'null' => false, 'default' => '', 'after' => 'rfc'],
            ]);
        }

        foreach ($this->db->table('suppliers')->select('id,name')->get()->getResult() as $supplier) {
            $this->db->table('suppliers')->where('id', $supplier->id)->update([
                'normalized_name' => self::normalizeName((string) $supplier->name),
            ]);
        }

        $indexes = $this->db->getIndexData('suppliers');
        if (!isset($indexes['idx_suppliers_normalized_name'])) {
            $this->db->query('ALTER TABLE ' . $this->db->prefixTable('suppliers') . ' ADD INDEX idx_suppliers_normalized_name (normalized_name)');
        }
        if (!isset($indexes['uq_suppliers_rfc'])) {
            $this->db->query('ALTER TABLE ' . $this->db->prefixTable('suppliers') . ' ADD UNIQUE INDEX uq_suppliers_rfc (rfc)');
        }
    }

    public function down()
    {
        $indexes = $this->db->getIndexData('suppliers');
        if (isset($indexes['uq_suppliers_rfc'])) {
            $this->db->query('ALTER TABLE ' . $this->db->prefixTable('suppliers') . ' DROP INDEX uq_suppliers_rfc');
        }
        if (isset($indexes['idx_suppliers_normalized_name'])) {
            $this->db->query('ALTER TABLE ' . $this->db->prefixTable('suppliers') . ' DROP INDEX idx_suppliers_normalized_name');
        }
        if ($this->db->fieldExists('normalized_name', 'suppliers')) {
            $this->forge->dropColumn('suppliers', 'normalized_name');
        }
        if ($this->db->fieldExists('rfc', 'suppliers')) {
            $this->forge->dropColumn('suppliers', 'rfc');
        }
    }

    private static function normalizeName(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($name));
        return preg_replace('/[^A-Z0-9]+/', '', strtoupper($ascii === false ? $name : $ascii)) ?? '';
    }
}
