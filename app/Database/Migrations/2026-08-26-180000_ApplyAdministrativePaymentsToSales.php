<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class ApplyAdministrativePaymentsToSales extends Migration
{
    public function up(): void
    {
        $p = $this->db->DBPrefix;

        // C2.5 allocations are disposable test data and represented the wrong fiscal relationship.
        if ($this->db->fieldExists('fiscal_document_id', 'payment_allocations')) {
            $this->db->table('payment_allocations')->emptyTable();
            if (!$this->indexExists('idx_allocation_payment')) $this->db->query("ALTER TABLE {$p}payment_allocations ADD KEY idx_allocation_payment (invoice_payment_id)");
            if ($this->constraintExists('fk_allocation_document')) $this->db->query("ALTER TABLE {$p}payment_allocations DROP FOREIGN KEY fk_allocation_document");
            foreach (['uq_payment_document', 'idx_allocation_document_status', 'invoice_payment_id_fiscal_document_id'] as $index) {
                if ($this->indexExists($index)) $this->db->query("ALTER TABLE {$p}payment_allocations DROP INDEX {$index}");
            }
            $this->forge->dropColumn('payment_allocations', 'fiscal_document_id');
        }
        if (!$this->db->fieldExists('invoice_id', 'payment_allocations')) {
            $this->forge->addColumn('payment_allocations', ['invoice_id' => ['type' => 'INT', 'null' => false, 'after' => 'invoice_payment_id']]);
        }
        if (!$this->indexExists('uq_payment_sale')) $this->db->query("ALTER TABLE {$p}payment_allocations ADD UNIQUE KEY uq_payment_sale (invoice_payment_id,invoice_id)");
        if (!$this->indexExists('idx_allocation_sale_status')) $this->db->query("ALTER TABLE {$p}payment_allocations ADD KEY idx_allocation_sale_status (invoice_id,status,deleted)");
        if (!$this->constraintExists('fk_allocation_sale')) $this->db->query("ALTER TABLE {$p}payment_allocations ADD CONSTRAINT fk_allocation_sale FOREIGN KEY(invoice_id) REFERENCES {$p}invoices(id) ON DELETE RESTRICT");
    }

    private function indexExists(string $name): bool
    {
        return (bool) $this->db->query('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? LIMIT 1', [$this->db->DBPrefix.'payment_allocations', $name])->getRow();
    }

    private function constraintExists(string $name): bool
    {
        return (bool) $this->db->query('SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name=? AND constraint_name=? LIMIT 1', [$this->db->DBPrefix.'payment_allocations', $name])->getRow();
    }

    public function down(): void
    {
        // This migration intentionally discards erroneous C2.5 test allocations.
    }
}
