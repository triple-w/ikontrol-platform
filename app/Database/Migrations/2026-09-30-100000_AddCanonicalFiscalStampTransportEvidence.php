<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class AddCanonicalFiscalStampTransportEvidence extends Migration
{
    private const TABLE = 'fiscal_stamp_attempts';

    public function up(): void
    {
        if (!$this->db->tableExists(self::TABLE)) {
            throw new RuntimeException('fiscal_stamp_attempts must exist before adding transport evidence.');
        }

        $fields = [
            'pac_endpoint' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'operation'],
            'transport_opened_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'sent_at'],
            'request_sent' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true, 'default' => null, 'after' => 'transport_opened_at'],
            'request_sent_confirmed_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'request_sent'],
            'response_received_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'request_sent_confirmed_at'],
        ];

        foreach ($fields as $name => $definition) {
            if (!$this->db->fieldExists($name, self::TABLE)) {
                $this->forge->addColumn(self::TABLE, [$name => $definition]);
            }
        }
    }

    public function down(): void
    {
        foreach (['response_received_at', 'request_sent_confirmed_at', 'request_sent', 'transport_opened_at', 'pac_endpoint'] as $field) {
            if ($this->db->fieldExists($field, self::TABLE)) {
                $this->forge->dropColumn(self::TABLE, $field);
            }
        }
    }
}
