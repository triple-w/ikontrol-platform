<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddEstimateItemCostAndProfit extends Migration
{
    public function up(): void
    {
        $fields = [];
        if (! $this->db->fieldExists('cost', 'estimate_items')) {
            $fields['cost'] = [
                'type' => 'DECIMAL',
                'constraint' => '18,6',
                'null' => true,
                'after' => 'unit_type',
            ];
        }
        if (! $this->db->fieldExists('profit_percentage', 'estimate_items')) {
            $fields['profit_percentage'] = [
                'type' => 'DECIMAL',
                'constraint' => '9,4',
                'null' => true,
                'after' => 'cost',
            ];
        }
        if ($fields) {
            $this->forge->addColumn('estimate_items', $fields);
        }
    }

    public function down(): void
    {
        foreach (['profit_percentage', 'cost'] as $field) {
            if ($this->db->fieldExists($field, 'estimate_items')) {
                $this->forge->dropColumn('estimate_items', $field);
            }
        }
    }
}
