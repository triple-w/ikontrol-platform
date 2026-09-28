<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Generic template identity from the Navika change, reviewed for canonical Base.
 * Keep historical content and selections; never infer a template from its HTML.
 */
final class AddProposalTemplateSelection extends Migration
{
    public function up(): void
    {
        $this->db->resetDataCache();
        if (! $this->db->tableExists('proposals')) {
            throw new RuntimeException('AddProposalTemplateSelection requires the proposals baseline table.');
        }

        if (! $this->db->fieldExists('proposal_template_id', 'proposals')) {
            $this->forge->addColumn('proposals', [
                'proposal_template_id' => ['type' => 'INT', 'null' => true, 'default' => null],
            ]);
            $this->db->resetDataCache();
        }

        // Presence alone is not evidence that a partially deployed schema is valid.
        foreach ($this->db->getFieldData('proposals') as $field) {
            if ($field->name !== 'proposal_template_id') {
                continue;
            }
            // SQLite exposes SQL NULL as text; MySQLi exposes PHP null.
            $nullDefault = $field->default === null || $field->default === 'NULL';
            if (! in_array(strtolower($field->type), ['int', 'integer'], true)
                || ! $field->nullable || ! $nullDefault) {
                throw new RuntimeException('proposal_template_id must be nullable INT with NULL default; reconcile its existing definition without rewriting historical proposals.');
            }
            return;
        }

        throw new RuntimeException('The proposal template selection column could not be verified.');
    }

    public function down(): void
    {
        // Reverting application code must not discard persisted template identity.
        // The additive nullable column is compatible with the previous application.
    }
}
