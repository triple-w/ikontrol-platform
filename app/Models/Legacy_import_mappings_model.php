<?php

namespace App\Models;

class Legacy_import_mappings_model extends Crud_model
{
    public function __construct($db = null)
    {
        parent::__construct('legacy_import_mappings', $db);
    }

    public function findSource(string $system, string $table, ?string $ownerId, string $sourceId): ?object
    {
        return $this->db->table($this->table)->where([
            'source_system' => $system,
            'source_table' => $table,
            'source_owner_id' => $ownerId ?? '',
            'source_id' => $sourceId,
        ])->get(1)->getRow();
    }
}
