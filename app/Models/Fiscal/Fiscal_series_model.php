<?php
namespace App\Models\Fiscal;

use App\Models\Crud_model;

class Fiscal_series_model extends Crud_model
{
    public function __construct(){ parent::__construct('fiscal_series'); }

    public function activeForIssuer(int $issuerId, ?string $type = null)
    {
        $where = ['issuer_profile_id' => $issuerId, 'is_active' => 1, 'deleted' => 0];
        if ($type !== null) $where['document_type'] = $type;
        return $this->get_all_where($where, 1000000, 0, 'series');
    }

    public function setDefault(int $issuerId, string $type, int $seriesId): bool
    {
        $this->db->transStart();
        $this->db->table($this->table)->where(['issuer_profile_id' => $issuerId, 'document_type' => $type])->update(['is_default' => 0]);
        $this->db->table($this->table)->where(['id' => $seriesId, 'issuer_profile_id' => $issuerId, 'document_type' => $type, 'deleted' => 0])->update(['is_default' => 1]);
        $this->db->transComplete();
        return $this->db->transStatus();
    }
}
