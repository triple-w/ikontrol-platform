<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class CreateCommercialFiscalAllocationModel extends Migration
{
    public function up(): void
    {
        foreach (['invoices', 'fiscal_documents'] as $required) {
            if (!$this->db->tableExists($required)) {
                throw new RuntimeException("C2.1 precondition missing: {$required}");
            }
        }
        $money = ['type' => 'DECIMAL', 'constraint' => '18,6', 'default' => '0.000000'];

        if (!$this->db->tableExists('fiscal_document_sales')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'fiscal_document_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'sale_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'allocated_subtotal' => $money,
                'allocated_tax' => $money,
                'allocated_total' => $money,
                'allocation_status' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'active'],
                'created_by' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('fiscal_document_id');
            $this->forge->addKey('sale_id');
            $this->forge->addKey('allocation_status');
            $this->forge->addUniqueKey(['fiscal_document_id', 'sale_id'], 'uq_fiscal_document_sale');
            $this->forge->createTable('fiscal_document_sales');
        }

        if (!$this->db->tableExists('fiscal_drafts')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'issuer_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'customer_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'document_type' => ['type' => 'CHAR', 'constraint' => 1, 'default' => 'I'],
                'provisional_series' => ['type' => 'VARCHAR', 'constraint' => 25, 'default' => ''],
                'issue_date' => ['type' => 'DATETIME'],
                'currency_code' => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'MXN'],
                'exchange_rate' => ['type' => 'DECIMAL', 'constraint' => '18,6', 'default' => '1.000000'],
                'payment_form_code' => ['type' => 'VARCHAR', 'constraint' => 3, 'null' => true],
                'payment_method_code' => ['type' => 'VARCHAR', 'constraint' => 3, 'null' => true],
                'cfdi_use_code' => ['type' => 'VARCHAR', 'constraint' => 5],
                'receiver_tax_regime_code' => ['type' => 'VARCHAR', 'constraint' => 5],
                'receiver_postal_code' => ['type' => 'VARCHAR', 'constraint' => 5],
                'expedition_postal_code' => ['type' => 'VARCHAR', 'constraint' => 5],
                'subtotal' => $money,
                'discount' => $money,
                'tax_total' => $money,
                'total' => $money,
                'fiscal_payload' => ['type' => 'LONGTEXT'],
                'status' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'draft'],
                'created_by' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'updated_by' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey(['issuer_id', 'status']);
            $this->forge->addKey(['customer_id', 'status']);
            $this->forge->addKey('issue_date');
            $this->forge->createTable('fiscal_drafts');
        }

        if (!$this->db->tableExists('fiscal_draft_sales')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'fiscal_draft_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'sale_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'allocated_subtotal' => $money,
                'allocated_tax' => $money,
                'allocated_total' => $money,
                'allocation_status' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'reserved'],
                'created_by' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('fiscal_draft_id');
            $this->forge->addKey('sale_id');
            $this->forge->addKey('allocation_status');
            $this->forge->addUniqueKey(['fiscal_draft_id', 'sale_id'], 'uq_fiscal_draft_sale');
            $this->forge->createTable('fiscal_draft_sales');
        }

        if (!$this->db->tableExists('fiscal_document_relations')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'source_document_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'related_document_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'relation_type' => ['type' => 'VARCHAR', 'constraint' => 30],
                'created_by' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('source_document_id');
            $this->forge->addKey('related_document_id');
            $this->forge->addKey('relation_type');
            $this->forge->addUniqueKey(
                ['source_document_id', 'related_document_id', 'relation_type'],
                'uq_fiscal_document_relation'
            );
            $this->forge->createTable('fiscal_document_relations');
        }

        if (!$this->db->fieldExists('cancellation_reason', 'invoices')) {
            $this->forge->addColumn('invoices', [
                'cancellation_reason' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'after' => 'cancelled_by'],
            ]);
        }
        $this->migrateLegacyRelations();
    }

    private function migrateLegacyRelations(): void
    {
        $rows = $this->db->table('fiscal_documents')
            ->select('id,invoice_id,status,subtotal,transferred_tax_total,withheld_tax_total,total,created_by,created_at,updated_at')
            ->where('invoice_id >', 0)->get()->getResultArray();
        foreach ($rows as $row) {
            if (!$this->db->table('invoices')->where('id', (int) $row['invoice_id'])->countAllResults()) {
                continue;
            }
            if ($this->db->table('fiscal_document_sales')->where([
                'fiscal_document_id' => (int) $row['id'],
                'sale_id' => (int) $row['invoice_id'],
            ])->countAllResults()) {
                continue;
            }
            $status = strtolower((string) $row['status']);
            $allocationStatus = str_contains($status, 'cancel') ? 'cancelled'
                : (str_starts_with($status, 'stamped') ? 'active' : 'legacy');
            $tax = $this->fromMicros(
                $this->toMicros((string) $row['transferred_tax_total'])
                - $this->toMicros((string) $row['withheld_tax_total'])
            );
            $this->db->table('fiscal_document_sales')->insert([
                'fiscal_document_id' => (int) $row['id'],
                'sale_id' => (int) $row['invoice_id'],
                'allocated_subtotal' => $this->decimal((string) $row['subtotal']),
                'allocated_tax' => $this->decimal($tax),
                'allocated_total' => $this->decimal((string) $row['total']),
                'allocation_status' => $allocationStatus,
                'created_by' => $row['created_by'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ]);
        }
    }

    private function decimal(string $value): string
    {
        return $this->fromMicros($this->toMicros($value));
    }

    private function toMicros(string $value): int
    {
        $value = trim($value);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,6}))?$/', $value, $match)) {
            throw new RuntimeException('Invalid legacy fiscal decimal.');
        }
        return ($match[1] === '-' ? -1 : 1)
            * (((int) $match[2] * 1000000) + (int) str_pad($match[3] ?? '', 6, '0'));
    }

    private function fromMicros(int $value): string
    {
        $sign = $value < 0 ? '-' : '';
        $value = abs($value);
        return $sign . intdiv($value, 1000000) . '.' . str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function down(): void
    {
        if ($this->db->fieldExists('cancellation_reason', 'invoices')) {
            $this->forge->dropColumn('invoices', 'cancellation_reason');
        }
        foreach (['fiscal_document_relations', 'fiscal_draft_sales', 'fiscal_drafts', 'fiscal_document_sales'] as $table) {
            if ($this->db->tableExists($table)) $this->forge->dropTable($table);
        }
    }
}
