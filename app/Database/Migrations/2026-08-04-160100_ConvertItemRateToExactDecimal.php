<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class ConvertItemRateToExactDecimal extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('items') || ! $this->db->fieldExists('rate', 'items')) {
            throw new RuntimeException('Cannot convert items.rate: the operational items table or rate column is missing.');
        }

        $table = $this->db->protectIdentifiers($this->db->prefixTable('items'));
        $this->db->query("ALTER TABLE {$table} MODIFY `rate` DECIMAL(18,6) NOT NULL DEFAULT 0.000000");
    }

    public function down(): void
    {
        // Converting exact decimal prices back to DOUBLE would be lossy.
    }
}
