<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddFinancialLedgerGuards extends Migration
{
    public function up(): void
    {
        $p = $this->db->DBPrefix;
        $invalid = [
            'invoice_payments' => "amount IS NULL OR amount<=0 OR status NOT IN ('active','cancelled')",
            'expenses' => "amount IS NULL OR amount<=0 OR financial_total IS NULL OR financial_total<=0 OR status NOT IN ('active','cancelled')",
            'financial_account_transfers' => "amount<=0 OR source_account_id=destination_account_id OR status NOT IN ('active','cancelled')",
        ];
        foreach ($invalid as $table => $where) {
            if ((int) $this->db->query("SELECT COUNT(*) c FROM {$p}{$table} WHERE {$where}")->getRow()->c > 0) {
                throw new \RuntimeException("Existen datos financieros incompatibles en {$table}; límpielos explícitamente antes de aplicar restricciones.");
            }
        }
        $this->forge->modifyColumn('invoice_payments', ['amount'=>['name'=>'amount','type'=>'DECIMAL','constraint'=>'18,6','null'=>false]]);
        $this->forge->modifyColumn('expenses', [
            'amount'=>['name'=>'amount','type'=>'DECIMAL','constraint'=>'18,6','null'=>false],
            'financial_total'=>['name'=>'financial_total','type'=>'DECIMAL','constraint'=>'18,6','null'=>false],
        ]);
        foreach ([
            "ALTER TABLE {$p}invoice_payments ADD CONSTRAINT chk_invoice_payment_amount CHECK (amount>0)",
            "ALTER TABLE {$p}invoice_payments ADD CONSTRAINT chk_invoice_payment_status CHECK (status IN ('active','cancelled'))",
            "ALTER TABLE {$p}expenses ADD CONSTRAINT chk_expense_amount CHECK (amount>0 AND financial_total>0)",
            "ALTER TABLE {$p}expenses ADD CONSTRAINT chk_expense_status CHECK (status IN ('active','cancelled'))",
            "ALTER TABLE {$p}financial_account_transfers ADD CONSTRAINT chk_transfer_amount CHECK (amount>0)",
            "ALTER TABLE {$p}financial_account_transfers ADD CONSTRAINT chk_transfer_accounts CHECK (source_account_id<>destination_account_id)",
            "ALTER TABLE {$p}financial_account_transfers ADD CONSTRAINT chk_transfer_status CHECK (status IN ('active','cancelled'))",
        ] as $sql) {
            $this->db->query($sql);
        }
    }

    public function down(): void
    {
        // Deliberately non-destructive: financial integrity constraints are not removed automatically.
    }
}
