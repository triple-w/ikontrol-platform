<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateFinancialAccounts extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('financial_accounts')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'name' => ['type' => 'VARCHAR', 'constraint' => 150],
                'type' => ['type' => 'VARCHAR', 'constraint' => 30],
                'description' => ['type' => 'TEXT', 'null' => true],
                'currency' => ['type' => 'VARCHAR', 'constraint' => 3, 'default' => 'MXN'],
                'opening_balance' => ['type' => 'DECIMAL', 'constraint' => '18,6', 'default' => 0],
                'is_active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'deleted' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'created_by' => ['type' => 'INT', 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('financial_accounts');
        }
        if (!$this->db->tableExists('financial_account_movements')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'financial_account_id' => ['type' => 'INT', 'unsigned' => true],
                'direction' => ['type' => 'VARCHAR', 'constraint' => 3],
                'amount' => ['type' => 'DECIMAL', 'constraint' => '18,6'],
                'movement_date' => ['type' => 'DATE'],
                'reference_type' => ['type' => 'VARCHAR', 'constraint' => 50],
                'reference_id' => ['type' => 'INT'],
                'description' => ['type' => 'TEXT', 'null' => true],
                'is_active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'created_by' => ['type' => 'INT', 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey(['reference_type', 'reference_id']);
            $this->forge->createTable('financial_account_movements');
        }
        if (!$this->db->tableExists('financial_account_transfers')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'source_account_id' => ['type' => 'INT', 'unsigned' => true],
                'destination_account_id' => ['type' => 'INT', 'unsigned' => true],
                'amount' => ['type' => 'DECIMAL', 'constraint' => '18,6'],
                'transfer_date' => ['type' => 'DATE'],
                'note' => ['type' => 'TEXT', 'null' => true],
                'deleted' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'created_by' => ['type' => 'INT', 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('financial_account_transfers');
        }
        if (!$this->db->fieldExists('destination_financial_account_id', 'invoice_payments')) {
            $this->forge->addColumn('invoice_payments', ['destination_financial_account_id' => ['type' => 'INT', 'null' => true, 'after' => 'payment_method_id']]);
        }
        if (!$this->db->fieldExists('source_financial_account_id', 'expenses')) {
            $this->forge->addColumn('expenses', ['source_financial_account_id' => ['type' => 'INT', 'null' => true, 'after' => 'amount']]);
        }
    }

    public function down() {}
}
