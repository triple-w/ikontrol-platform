<?php

declare(strict_types=1);

namespace Tests\DatabaseTargetIsolation\Migrations;

use CodeIgniter\Database\Migration;

final class CreateIsolationProbe extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'runner_database' => ['type' => 'VARCHAR', 'constraint' => 128],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('isolation_probe');
        $database = (string) $this->db->query('SELECT DATABASE() AS name')->getRow()->name;
        $this->db->table('isolation_probe')->insert(['runner_database' => $database]);
    }

    public function down(): void
    {
        throw new \RuntimeException('The isolation fixture must never run down().');
    }
}
