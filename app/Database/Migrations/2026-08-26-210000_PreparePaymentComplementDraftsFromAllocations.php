<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class PreparePaymentComplementDraftsFromAllocations extends Migration
{
    public function up(): void
    {
        foreach (['payment_complements', 'payment_complement_payments', 'payment_complement_documents', 'payment_allocations'] as $table) {
            if (!$this->db->tableExists($table)) throw new RuntimeException("Falta la tabla {$table}.");
        }

        // C2.5 contained only disposable prototype drafts. They cannot be interpreted as fiscal history.
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        $this->db->table('payment_complement_fiscal_snapshots')->truncate();
        $this->db->table('payment_complement_documents')->truncate();
        $this->db->table('payment_complement_payments')->truncate();
        $this->db->table('payment_complements')->truncate();
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');

        if (!$this->db->fieldExists('cancelled_at', 'payment_complements')) $this->forge->addColumn('payment_complements', ['cancelled_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'updated_at']]);
        if (!$this->db->fieldExists('cancelled_by', 'payment_complements')) $this->forge->addColumn('payment_complements', ['cancelled_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'cancelled_at']]);
        if (!$this->db->fieldExists('cancellation_reason', 'payment_complements')) $this->forge->addColumn('payment_complements', ['cancellation_reason' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'after' => 'cancelled_by']]);
        if (!$this->db->fieldExists('payment_allocation_id', 'payment_complement_documents')) $this->forge->addColumn('payment_complement_documents', ['payment_allocation_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'payment_complement_payment_id']]);
        if (!$this->db->fieldExists('invoice_id', 'payment_complement_documents')) $this->forge->addColumn('payment_complement_documents', ['invoice_id' => ['type' => 'INT', 'null' => true, 'after' => 'payment_allocation_id']]);
        $this->db->query('ALTER TABLE '.$this->db->prefixTable('payment_complement_documents').' MODIFY fiscal_document_id BIGINT UNSIGNED NOT NULL');
        $this->db->query('ALTER TABLE '.$this->db->prefixTable('payment_complement_documents').' MODIFY payment_allocation_id INT UNSIGNED NOT NULL, MODIFY invoice_id INT NOT NULL');
        $this->foreign('payment_complement_payments','fk_pc_payment_header','payment_complement_id','payment_complements','id');
        $this->foreign('payment_complement_payments','fk_pc_payment_source','source_invoice_payment_id','invoice_payments','id');
        $this->foreign('payment_complement_documents','fk_pc_document_payment','payment_complement_payment_id','payment_complement_payments','id');
        $this->foreign('payment_complement_documents','fk_pc_document_allocation','payment_allocation_id','payment_allocations','id');
        $this->foreign('payment_complement_documents','fk_pc_document_sale','invoice_id','invoices','id');
        $this->foreign('payment_complement_documents','fk_pc_document_fiscal','fiscal_document_id','fiscal_documents','id');
        $this->index('payment_complement_payments','idx_pc_source_payment_active','source_invoice_payment_id,deleted');
        $this->index('payment_complement_payments','idx_pc_payment_header_active','payment_complement_id,deleted');
        $this->index('payment_complement_documents','idx_pc_allocation_document','payment_allocation_id,fiscal_document_id,deleted');
        $this->index('payment_complement_documents','idx_pc_document_fiscal_active','fiscal_document_id,deleted');
        $this->index('payment_complements','idx_pc_status_active','status,deleted');
        $this->index('payment_complements','idx_pc_client_active','client_id,deleted');
    }

    public function down(): void {}

    private function foreign(string $table,string $name,string $column,string $parent,string $parentColumn):void
    {
        $exists=$this->db->query('SELECT COUNT(*) n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?',[$this->db->prefixTable($table),$name])->getRow()->n;
        if(!$exists)$this->db->query('ALTER TABLE '.$this->db->prefixTable($table).' ADD CONSTRAINT '.$name.' FOREIGN KEY ('.$column.') REFERENCES '.$this->db->prefixTable($parent).'('.$parentColumn.')');
    }
    private function index(string $table,string $name,string $columns):void
    {
        $exists=$this->db->query('SELECT COUNT(*) n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',[$this->db->prefixTable($table),$name])->getRow()->n;
        if(!$exists)$this->db->query('ALTER TABLE '.$this->db->prefixTable($table).' ADD KEY '.$name.' ('.$columns.')');
    }
}
