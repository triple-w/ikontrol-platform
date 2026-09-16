<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class AddCompleteFiscalAddressToProfiles extends Migration
{
    private array $fields = [
        'fiscal_street' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        'fiscal_external_number' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
        'fiscal_internal_number' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
        'fiscal_neighborhood' => ['type' => 'VARCHAR', 'constraint' => 180, 'null' => true],
        'fiscal_locality' => ['type' => 'VARCHAR', 'constraint' => 180, 'null' => true],
        'fiscal_municipality' => ['type' => 'VARCHAR', 'constraint' => 180, 'null' => true],
        'fiscal_state' => ['type' => 'VARCHAR', 'constraint' => 180, 'null' => true],
        'fiscal_country_code' => ['type' => 'CHAR', 'constraint' => 3, 'null' => true],
        'fiscal_address_reference' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
    ];

    public function up(): void
    {
        if (!$this->db->tableExists('fiscal_profiles')) {
            throw new RuntimeException('Cannot extend fiscal_profiles: table is missing.');
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
