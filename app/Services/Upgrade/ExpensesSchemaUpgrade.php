<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

use App\Services\Upgrade\Legacy\PhysicalSchemaInspector;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** Additive compatibility upgrade for pre-ledger expense tables. */
final class ExpensesSchemaUpgrade
{
    private const COLUMNS = [
        'financial_total' => "DECIMAL(18,6) NOT NULL DEFAULT '0.000000'",
        'source_financial_account_id' => 'INT UNSIGNED NULL DEFAULT NULL',
        'status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
        'cancelled_at' => 'DATETIME NULL DEFAULT NULL',
        'cancelled_by' => 'INT NULL DEFAULT NULL',
        'cancellation_reason' => 'VARCHAR(500) NULL DEFAULT NULL',
    ];

    public function __construct(private BaseConnection $db)
    {
    }

    /** @return array{table:string,added:list<string>,financial_totals_backfilled:int,statuses_backfilled:int,columns:list<string>} */
    public function apply(): array
    {
        $inspector = new PhysicalSchemaInspector($this->db, (string) $this->db->DBPrefix);
        if (! $inspector->tableExists('expenses')) {
            throw new RuntimeException('Falta la tabla expenses; el upgrade dirigido no puede recrearla.');
        }

        $table = $this->db->protectIdentifiers($inspector->physicalName('expenses'));
        $added = [];
        foreach (self::COLUMNS as $column => $definition) {
            if ($inspector->columnExists('expenses', $column)) continue;
            $this->db->query(
                'ALTER TABLE ' . $table . ' ADD COLUMN '
                . $this->db->protectIdentifiers($column) . ' ' . $definition
            );
            $added[] = $column;
        }

        $financialTotalsBackfilled = 0;
        if (in_array('financial_total', $added, true)) {
            $financialTotalsBackfilled = $this->backfillFinancialTotals($inspector, $table);
        }

        $statusWhere = in_array('status', $added, true) ? '' : " WHERE status IS NULL OR status=''";
        $this->db->query(
            'UPDATE ' . $table . " SET status=IF(deleted=1,'cancelled','active')" . $statusWhere
        );
        $statusesBackfilled = $this->db->affectedRows();

        $missing = [];
        foreach (array_keys(self::COLUMNS) as $column) {
            if (! $inspector->columnExists('expenses', $column)) $missing[] = $column;
        }
        if ($missing !== []) {
            throw new RuntimeException('expenses quedó incompleta: ' . implode(', ', $missing));
        }

        return [
            'table' => $inspector->physicalName('expenses'),
            'added' => $added,
            'financial_totals_backfilled' => $financialTotalsBackfilled,
            'statuses_backfilled' => $statusesBackfilled,
            'columns' => array_keys(self::COLUMNS),
        ];
    }

    private function backfillFinancialTotals(PhysicalSchemaInspector $inspector, string $expenses): int
    {
        if ($inspector->tableExists('taxes') && $inspector->columnExists('taxes', 'percentage')) {
            $taxes = $this->db->protectIdentifiers($inspector->physicalName('taxes'));
            $this->db->query(
                'UPDATE ' . $expenses . ' e '
                . 'LEFT JOIN ' . $taxes . ' t1 ON t1.id=e.tax_id '
                . 'LEFT JOIN ' . $taxes . ' t2 ON t2.id=e.tax_id2 '
                . 'SET e.financial_total=ROUND(e.amount'
                . '+(e.amount*COALESCE(t1.percentage,0)/100)'
                . '+(e.amount*COALESCE(t2.percentage,0)/100),6)'
            );
            return $this->db->affectedRows();
        }

        $taxed = (int) ($this->db->query(
            'SELECT COUNT(*) total FROM ' . $expenses . ' WHERE COALESCE(tax_id,0)<>0 OR COALESCE(tax_id2,0)<>0'
        )->getRow()->total ?? 0);
        if ($taxed > 0) {
            throw new RuntimeException('No se puede calcular financial_total: existen gastos con impuestos y falta el catálogo taxes.');
        }
        $this->db->query('UPDATE ' . $expenses . ' SET financial_total=ROUND(amount,6)');
        return $this->db->affectedRows();
    }
}
