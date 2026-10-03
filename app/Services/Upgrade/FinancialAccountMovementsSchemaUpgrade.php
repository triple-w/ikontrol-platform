<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

use App\Services\Upgrade\Legacy\PhysicalSchemaInspector;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** Additive, idempotent ledger evolution for historical bridge installations. */
final class FinancialAccountMovementsSchemaUpgrade
{
    private const COLUMNS = [
        'movement_role' => "VARCHAR(20) NOT NULL DEFAULT 'original'",
        'reversal_of_movement_id' => 'INT UNSIGNED NULL DEFAULT NULL',
        'reversed_movement_id' => 'INT UNSIGNED NULL DEFAULT NULL',
        'reversal_reason' => 'VARCHAR(500) NULL DEFAULT NULL',
        'updated_at' => 'DATETIME NULL DEFAULT NULL',
    ];

    public function __construct(private BaseConnection $db)
    {
    }

    /** @return array{table:string,added:list<string>,legacy_roles_normalized:int,columns:list<string>} */
    public function apply(): array
    {
        $inspector = new PhysicalSchemaInspector($this->db, (string) $this->db->DBPrefix);
        if (! $inspector->tableExists('financial_account_movements')) {
            throw new RuntimeException('Falta la tabla financial_account_movements; el upgrade dirigido no puede recrearla.');
        }
        $table = $this->db->protectIdentifiers($inspector->physicalName('financial_account_movements'));
        $added = [];
        foreach (self::COLUMNS as $column => $definition) {
            if ($inspector->columnExists('financial_account_movements', $column)) continue;
            $this->db->query('ALTER TABLE ' . $table . ' ADD COLUMN '
                . $this->db->protectIdentifiers($column) . ' ' . $definition);
            $added[] = $column;
        }

        // Historical bridge rows are original movements. Explicit reversal rows,
        // when present in a partially upgraded schema, remain untouched.
        $this->db->query(
            'UPDATE ' . $table . " SET movement_role='original' "
            . "WHERE movement_role IS NULL OR movement_role NOT IN ('original','reversal')"
        );
        $normalized = $this->db->affectedRows();

        $missing = [];
        foreach (array_keys(self::COLUMNS) as $column) {
            if (! $inspector->columnExists('financial_account_movements', $column)) $missing[] = $column;
        }
        if ($missing !== []) throw new RuntimeException('El ledger quedó incompleto: ' . implode(', ', $missing));

        return [
            'table' => $inspector->physicalName('financial_account_movements'),
            'added' => $added,
            'legacy_roles_normalized' => $normalized,
            'columns' => array_keys(self::COLUMNS),
        ];
    }
}
