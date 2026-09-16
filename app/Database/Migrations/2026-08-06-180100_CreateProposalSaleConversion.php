<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateProposalSaleConversion extends Migration
{
    public function up(): void
    {
        $proposalFields = [];
        foreach ([
            'converted_sale_id' => ['type' => 'INT', 'null' => true, 'after' => 'status'],
            'converted_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'converted_sale_id'],
            'converted_by' => ['type' => 'INT', 'null' => true, 'after' => 'converted_at'],
            'accepted_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'accepted_by'],
        ] as $name => $definition) {
            if (! $this->db->fieldExists($name, 'proposals')) {
                $proposalFields[$name] = $definition;
            }
        }
        if ($proposalFields) {
            $this->forge->addColumn('proposals', $proposalFields);
        }

        if (! $this->db->fieldExists('proposal_id', 'invoices')) {
            $this->forge->addColumn('invoices', [
                'proposal_id' => ['type' => 'INT', 'null' => true, 'after' => 'estimate_id'],
            ]);
        }

        $proposals = $this->db->protectIdentifiers($this->db->prefixTable('proposals'));
        $invoices = $this->db->protectIdentifiers($this->db->prefixTable('invoices'));
        if (! isset($this->db->getIndexData('proposals')['uq_proposals_converted_sale'])) {
            $this->db->query("CREATE UNIQUE INDEX `uq_proposals_converted_sale` ON {$proposals} (`converted_sale_id`)");
        }
        if (! isset($this->db->getIndexData('proposals')['idx_proposals_converted_by'])) {
            $this->db->query("CREATE INDEX `idx_proposals_converted_by` ON {$proposals} (`converted_by`)");
        }
        if (! isset($this->db->getIndexData('invoices')['uq_invoices_proposal'])) {
            $this->db->query("CREATE UNIQUE INDEX `uq_invoices_proposal` ON {$invoices} (`proposal_id`)");
        }
    }

    public function down(): void
    {
        $proposals = $this->db->protectIdentifiers($this->db->prefixTable('proposals'));
        $invoices = $this->db->protectIdentifiers($this->db->prefixTable('invoices'));
        $proposalIndexes = $this->db->getIndexData('proposals');
        $invoiceIndexes = $this->db->getIndexData('invoices');
        if (isset($invoiceIndexes['uq_invoices_proposal'])) {
            $this->db->query("DROP INDEX `uq_invoices_proposal` ON {$invoices}");
        }
        foreach (['uq_proposals_converted_sale', 'idx_proposals_converted_by'] as $index) {
            if (isset($proposalIndexes[$index])) {
                $this->db->query("DROP INDEX `{$index}` ON {$proposals}");
            }
        }
        if ($this->db->fieldExists('proposal_id', 'invoices')) {
            $this->forge->dropColumn('invoices', 'proposal_id');
        }
        foreach (['accepted_at', 'converted_by', 'converted_at', 'converted_sale_id'] as $field) {
            if ($this->db->fieldExists($field, 'proposals')) {
                $this->forge->dropColumn('proposals', $field);
            }
        }
    }
}
