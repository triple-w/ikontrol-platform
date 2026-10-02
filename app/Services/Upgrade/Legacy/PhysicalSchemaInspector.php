<?php

declare(strict_types=1);

namespace App\Services\Upgrade\Legacy;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** Reads physical schema metadata without CodeIgniter's table metadata cache. */
final class PhysicalSchemaInspector
{
    public function __construct(private BaseConnection $db, private string $prefix)
    {
        if (! preg_match('/^[A-Za-z0-9_]*$/', $prefix)) throw new RuntimeException('Invalid database prefix.');
    }

    public function tableExists(string $logicalTable): bool
    {
        return (bool) $this->db->query(
            'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1',
            [$this->physicalName($logicalTable)]
        )->getRow();
    }

    public function columnExists(string $logicalTable, string $column): bool
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $column)) throw new RuntimeException('Invalid column identifier.');
        return (bool) $this->db->query(
            'SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1',
            [$this->physicalName($logicalTable), $column]
        )->getRow();
    }

    public function physicalName(string $logicalTable): string
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $logicalTable)) throw new RuntimeException('Invalid table identifier.');
        return $this->prefix . $logicalTable;
    }
}
