<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class ExtendFiscalDraftWorkflow extends Migration
{
    public function up(): void
    {
        if (!$this->db->tableExists('fiscal_drafts') || !$this->db->tableExists('invoice_items')) {
            throw new RuntimeException('C2.2 requires the C2.1 draft model.');
        }
        foreach ([
            'receiver_profile_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'fiscal_series_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'conditions' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'observations' => ['type' => 'TEXT', 'null' => true],
            'discarded_reason' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'discarded_at' => ['type' => 'DATETIME', 'null' => true],
            'ready_at' => ['type' => 'DATETIME', 'null' => true],
        ] as $name => $definition) {
            if (!$this->db->fieldExists($name, 'fiscal_drafts')) {
                $this->forge->addColumn('fiscal_drafts', [$name => $definition]);
            }
        }
        if (!$this->db->tableExists('fiscal_draft_items')) {
            $money = ['type' => 'DECIMAL', 'constraint' => '18,6', 'default' => '0.000000'];
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'fiscal_draft_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'sale_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'sale_item_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'product_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'quantity' => ['type' => 'DECIMAL', 'constraint' => '18,6'],
                'unit_price' => $money,
                'discount' => $money,
                'subtotal' => $money,
                'tax' => $money,
                'total' => $money,
                'fiscal_snapshot' => ['type' => 'LONGTEXT'],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('fiscal_draft_id');
            $this->forge->addKey('sale_id');
            $this->forge->addKey('sale_item_id');
            $this->forge->addUniqueKey(['fiscal_draft_id', 'sale_item_id'], 'uq_fiscal_draft_sale_item');
            $this->forge->createTable('fiscal_draft_items');
        }
        if (!$this->db->tableExists('fiscal_draft_audit')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'fiscal_draft_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'sale_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'event' => ['type' => 'VARCHAR', 'constraint' => 50],
                'summary_json' => ['type' => 'TEXT', 'null' => true],
                'created_at' => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('fiscal_draft_id');
            $this->forge->addKey('sale_id');
            $this->forge->addKey('event');
            $this->forge->createTable('fiscal_draft_audit');
        }
    }

    public function down(): void
    {
        foreach (['fiscal_draft_audit', 'fiscal_draft_items'] as $table) {
            if ($this->db->tableExists($table)) $this->forge->dropTable($table);
        }
        foreach (['receiver_profile_id','fiscal_series_id','conditions', 'observations', 'discarded_reason', 'discarded_at', 'ready_at'] as $field) {
            if ($this->db->fieldExists($field, 'fiscal_drafts')) $this->forge->dropColumn('fiscal_drafts', $field);
        }
    }
}
