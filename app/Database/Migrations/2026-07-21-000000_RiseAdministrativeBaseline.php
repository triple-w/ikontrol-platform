<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class RiseAdministrativeBaseline extends Migration
{
    private const VERSION = 'rise-administrative-baseline-1';

    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'settings',
        'users',
        'roles',
        'clients',
        'items',
        'estimates',
        'estimate_items',
        'invoices',
        'invoice_items',
        'invoice_payments',
        'payment_methods',
        'taxes',
        'company',
    ];

    public function up(): void
    {
        $missingTables = [];

        foreach (self::REQUIRED_TABLES as $table) {
            if (! $this->db->tableExists($table)) {
                $missingTables[] = $this->db->DBPrefix . $table;
            }
        }

        if ($missingTables !== []) {
            throw new RuntimeException(
                'RISE baseline aborted. The database is missing required tables: '
                . implode(', ', $missingTables)
                . '. Install the existing RISE schema from install1/database.sql before running migrations.'
            );
        }

        if (! $this->db->tableExists('app_schema_versions')) {
            $this->forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 10,
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'version' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 100,
                ],
                'description' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                ],
                'applied_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                ],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('version');
            $this->forge->createTable('app_schema_versions');
        }

        $versions = $this->db->table('app_schema_versions');
        if (! $versions->where('version', self::VERSION)->countAllResults()) {
            $versions->insert([
                'version'     => self::VERSION,
                'description' => 'Verified baseline for the existing RISE administrative schema.',
                'applied_at'  => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function down(): void
    {
        // Never alter or remove any RISE administrative table.
        if ($this->db->tableExists('app_schema_versions')) {
            $this->db->table('app_schema_versions')
                ->where('version', self::VERSION)
                ->delete();
        }
    }
}
