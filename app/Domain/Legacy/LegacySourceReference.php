<?php

declare(strict_types=1);

namespace App\Domain\Legacy;

use InvalidArgumentException;

final class LegacySourceReference
{
    public readonly string $sourceSystem;
    public readonly string $sourceTable;
    public readonly string $sourceOwnerId;
    public readonly string $sourceId;

    public function __construct(string $sourceSystem, string $sourceTable, ?string $sourceOwnerId, string $sourceId)
    {
        $this->sourceSystem = trim($sourceSystem);
        $this->sourceTable = trim($sourceTable);
        $this->sourceOwnerId = trim((string) $sourceOwnerId);
        $this->sourceId = trim($sourceId);
        if ($this->sourceSystem === '' || $this->sourceTable === '' || $this->sourceId === '') {
            throw new InvalidArgumentException('Legacy source system, table and id are required.');
        }
    }

    public function where(): array
    {
        return [
            'source_system' => $this->sourceSystem,
            'source_table' => $this->sourceTable,
            'source_owner_id' => $this->sourceOwnerId,
            'source_id' => $this->sourceId,
        ];
    }
}
