<?php
namespace App\Models\Fiscal;

use App\Models\Crud_model;
use App\Services\Fiscal\FiscalRuntimeContext;
use App\Services\Fiscal\FiscalSeriesEnvironmentService;

class Fiscal_series_model extends Crud_model
{
    public function __construct(){ parent::__construct('fiscal_series'); }

    public function activeForIssuer(int $issuerId, ?string $type = null, ?string $environment = null)
    {
        return(new FiscalSeriesEnvironmentService($this->db))->activeForIssuer($issuerId,$type,$environment);
    }

    public function setDefault(int $issuerId, string $type, int $seriesId, ?string$environment=null): bool
    {
        $environment=$environment?:FiscalRuntimeContext::fiscalEnvironment(config('Fiscal'));
        $this->db->transStart();
        $this->db->table($this->table)->where(['issuer_profile_id' => $issuerId, 'document_type' => $type,'environment'=>$environment])->update(['is_default' => 0]);
        $this->db->table($this->table)->where(['id' => $seriesId, 'issuer_profile_id' => $issuerId, 'document_type' => $type,'environment'=>$environment, 'deleted' => 0])->update(['is_default' => 1]);
        $this->db->transComplete();
        return $this->db->transStatus();
    }
}
