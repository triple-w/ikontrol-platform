<?php
declare(strict_types=1);

namespace App\Models\Fiscal;

use App\Models\Crud_model;

final class Fiscal_issuer_certificates_model extends Crud_model
{
    public function __construct()
    {
        parent::__construct('fiscal_issuer_certificates');
    }

    public function forIssuer(int $issuerId)
    {
        return $this->db->table($this->table)
            ->where(['issuer_profile_id' => $issuerId, 'deleted' => 0])
            ->orderBy('is_default', 'DESC')->orderBy('id', 'DESC')->get();
    }

    public function active(int $id): ?object
    {
        return $this->db->table($this->table)
            ->where(['id' => $id, 'status' => 'valid', 'deleted' => 0])->get(1)->getRow();
    }
}
