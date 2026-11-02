<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Additive audit store for previewed environment changes. It stores no secrets. */
final class CreateFiscalEnvironmentChangeRequests extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('fiscal_environment_change_requests')) return;
        $this->forge->addField([
            'id' => ['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],
            'requested_environment' => ['type'=>'VARCHAR','constraint'=>20],
            'current_environment' => ['type'=>'VARCHAR','constraint'=>20],
            'status' => ['type'=>'VARCHAR','constraint'=>30,'default'=>'preview_only'],
            'reason' => ['type'=>'VARCHAR','constraint'=>500],
            'before_context_id' => ['type'=>'VARCHAR','constraint'=>40],
            'after_context_id' => ['type'=>'VARCHAR','constraint'=>40],
            'preflight_json' => ['type'=>'TEXT'],
            'requested_by' => ['type'=>'BIGINT','unsigned'=>true],
            'requested_at' => ['type'=>'DATETIME'],
            'verified_at' => ['type'=>'DATETIME','null'=>true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['requested_environment','requested_at'], false, false, 'idx_fiscal_env_request_environment');
        $this->forge->addKey(['requested_by','requested_at'], false, false, 'idx_fiscal_env_request_user');
        $this->forge->createTable('fiscal_environment_change_requests');
    }

    public function down(): void
    {
        // Audit history is intentionally retained on rollback.
    }
}
