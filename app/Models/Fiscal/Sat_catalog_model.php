<?php
namespace App\Models\Fiscal;

use App\Models\Crud_model;

abstract class Sat_catalog_model extends Crud_model
{
    public function getActiveDropdown(array $labelFields): array
    {
        $builder = $this->db->table($this->table)->select(array_merge(['id'], $labelFields))->where('is_active', 1);
        if ($labelFields) $builder->orderBy($labelFields[0], 'ASC');
        $result = [];
        foreach ($builder->get()->getResult() as $row) {
            $parts = [];
            foreach ($labelFields as $field) $parts[] = (string) ($row->$field ?? '');
            $result[(int) $row->id] = implode(' ', array_filter($parts, static fn ($value) => $value !== ''));
        }
        return $result;
    }
}
