<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class TraceComplementGeneratedAllocations extends Migration
{
    public function up(): void
    {
        if (!$this->db->fieldExists('allocation_origin', 'payment_complement_documents')) {
            $this->forge->addColumn('payment_complement_documents', [
                'allocation_origin' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'preexisting', 'after' => 'payment_allocation_id'],
                'allocation_amount_before' => ['type' => 'DECIMAL', 'constraint' => '18,6', 'default' => '0.000000', 'after' => 'allocation_origin'],
                'allocation_amount_after' => ['type' => 'DECIMAL', 'constraint' => '18,6', 'default' => '0.000000', 'after' => 'allocation_amount_before'],
            ]);
        }
        $prefix = $this->db->DBPrefix;
        $exists = $this->db->query('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? LIMIT 1', [$prefix.'payment_complement_documents', 'idx_pc_allocation_origin'])->getRow();
        if (!$exists) $this->db->query("ALTER TABLE {$prefix}payment_complement_documents ADD KEY idx_pc_allocation_origin (payment_allocation_id,allocation_origin,deleted)");
    }

    public function down(): void {}
}
