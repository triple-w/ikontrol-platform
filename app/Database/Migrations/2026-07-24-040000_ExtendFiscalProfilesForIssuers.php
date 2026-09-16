<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class ExtendFiscalProfilesForIssuers extends Migration
{
    private array $fields = [
        'trade_name' => ['type' => 'VARCHAR', 'constraint' => 254, 'null' => true],
        'expedition_postal_code' => ['type' => 'VARCHAR', 'constraint' => 5, 'null' => true],
        'email' => ['type' => 'VARCHAR', 'constraint' => 254, 'null' => true],
        'phone' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
    ];

    public function up(): void
    {
        if (!$this->db->tableExists('fiscal_profiles')) {
            throw new RuntimeException('Cannot prepare issuer profiles: fiscal_profiles is missing.');
        }
        foreach ($this->fields as $name => $definition) {
            if (!$this->db->fieldExists($name, 'fiscal_profiles')) {
                $this->forge->addColumn('fiscal_profiles', [$name => $definition]);
            }
        }
    }

    public function down(): void
    {
        if (!$this->db->tableExists('fiscal_profiles')) return;
        foreach (array_reverse(array_keys($this->fields)) as $name) {
            if ($this->db->fieldExists($name, 'fiscal_profiles')) $this->forge->dropColumn('fiscal_profiles', $name);
        }
    }
}
