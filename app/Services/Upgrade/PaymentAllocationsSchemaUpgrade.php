<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

use App\Services\Upgrade\Legacy\PhysicalSchemaInspector;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** Additive compatibility upgrade for historical administrative allocations. */
final class PaymentAllocationsSchemaUpgrade
{
    private const COLUMNS = [
        'deactivated_at' => 'DATETIME NULL DEFAULT NULL',
        'deactivated_by' => 'INT NULL DEFAULT NULL',
        'deactivation_reason' => 'VARCHAR(500) NULL DEFAULT NULL',
    ];

    public function __construct(private BaseConnection $db)
    {
    }

    /** @return array{table:string,added:list<string>,columns:list<string>} */
    public function apply(): array
    {
        $inspector = new PhysicalSchemaInspector($this->db, (string) $this->db->DBPrefix);
        if (! $inspector->tableExists('payment_allocations')) {
            throw new RuntimeException('Falta la tabla payment_allocations; el upgrade dirigido no puede recrearla.');
        }

        $table = $this->db->protectIdentifiers($inspector->physicalName('payment_allocations'));
        $added = [];
        foreach (self::COLUMNS as $column => $definition) {
            if ($inspector->columnExists('payment_allocations', $column)) continue;
            $this->db->query(
                'ALTER TABLE ' . $table . ' ADD COLUMN '
                . $this->db->protectIdentifiers($column) . ' ' . $definition
            );
            $added[] = $column;
        }

        $missing = [];
        foreach (array_keys(self::COLUMNS) as $column) {
            if (! $inspector->columnExists('payment_allocations', $column)) $missing[] = $column;
        }
        if ($missing !== []) {
            throw new RuntimeException('payment_allocations quedó incompleta: ' . implode(', ', $missing));
        }

        return [
            'table' => $inspector->physicalName('payment_allocations'),
            'added' => $added,
            'columns' => array_keys(self::COLUMNS),
        ];
    }
}
