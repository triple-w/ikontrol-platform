<?php
namespace App\Models;

final class Suppliers_model extends Crud_model
{
    protected $table = null;

    public function __construct($db = null)
    {
        $this->table = 'suppliers';
        parent::__construct($this->table, $db);
    }

    public function activeDropdown(int $includeId = 0): array
    {
        $out = ['' => 'Seleccione proveedor'];
        $builder = $this->db->table($this->table)
            ->where('deleted', 0)
            ->groupStart()->where('status', 'active');
        if ($includeId > 0) {
            $builder->orWhere('id', $includeId);
        }
        $rows = $builder->groupEnd()->orderBy('name')->get()->getResult();
        foreach ($rows as $supplier) {
            $label = $supplier->name . ($supplier->rfc ? ' · ' . $supplier->rfc : '');
            if ($supplier->status !== 'active') {
                $label .= ' (Inactivo)';
            }
            $out[$supplier->id] = $label;
        }
        return $out;
    }
}
