<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class PrepareFiscalDraftStamping extends Migration
{
    public function up(): void
    {
        if (!$this->db->fieldExists('source_draft_id', 'fiscal_documents')) {
            $this->forge->addColumn('fiscal_documents', [
                'source_draft_id' => [
                    'type' => 'BIGINT', 'unsigned' => true, 'null' => true,
                    'after' => 'invoice_id',
                ],
            ]);
            $this->db->query(
                'CREATE UNIQUE INDEX uq_fiscal_document_source_draft ON '
                . $this->db->prefixTable('fiscal_documents') . ' (source_draft_id)'
            );
        }
        if (!$this->db->fieldExists('fiscal_document_id', 'fiscal_drafts')) {
            $this->forge->addColumn('fiscal_drafts', [
                'fiscal_document_id' => [
                    'type' => 'BIGINT', 'unsigned' => true, 'null' => true,
                    'after' => 'id',
                ],
            ]);
            $this->db->query(
                'CREATE UNIQUE INDEX uq_fiscal_draft_document ON '
                . $this->db->prefixTable('fiscal_drafts') . ' (fiscal_document_id)'
            );
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('fiscal_document_id', 'fiscal_drafts')) {
            $this->forge->dropColumn('fiscal_drafts', 'fiscal_document_id');
        }
        if ($this->db->fieldExists('source_draft_id', 'fiscal_documents')) {
            $this->forge->dropColumn('fiscal_documents', 'source_draft_id');
        }
    }
}
