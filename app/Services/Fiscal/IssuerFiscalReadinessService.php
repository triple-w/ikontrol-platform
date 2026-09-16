<?php
declare(strict_types=1);
namespace App\Services\Fiscal;

use App\Models\Fiscal\Fiscal_profiles_model;
use App\Models\Fiscal\Sat_tax_regimes_model;

class IssuerFiscalReadinessService
{
    public function evaluate(?int $issuerProfileId, ?int $companyId = null): array
    {
        $result=['issuer_profile_id'=>$issuerProfileId,'status'=>'not_configured','is_ready'=>false,'errors'=>[],'warnings'=>[],'missing_fields'=>[]];
        if (!$issuerProfileId) { $result['errors'][]='No se ha seleccionado una razón social emisora.'; return $result; }
        $profile=(new Fiscal_profiles_model())->get_one($issuerProfileId);
        if (!$profile->id || $profile->profile_type!=='issuer' || ($companyId && (int)$profile->company_id!==$companyId)) { $result['errors'][]='El perfil emisor no existe para la empresa seleccionada.'; return $result; }
        if ($profile->status==='inactive') { $result['status']='inactive'; $result['errors'][]='El perfil emisor está inactivo.'; return $result; }
        $required=['rfc'=>'Falta RFC del emisor.','legal_name'=>'Falta razón social del emisor.','tax_regime_id'=>'Falta régimen fiscal.','fiscal_postal_code'=>'Falta código postal fiscal.','expedition_postal_code'=>'Falta código postal de expedición.','fiscal_country_code'=>'Falta país del emisor.'];
        foreach($required as$field=>$message){if(trim((string)($profile->$field??''))===''){$result['missing_fields'][]=$field;$result['errors'][]=$message;}}
        if ($profile->tax_regime_id) { $regime=(new Sat_tax_regimes_model())->get_one($profile->tax_regime_id); if(!$regime->id||!(int)$regime->is_active)$result['errors'][]='El régimen fiscal está inactivo.'; }
        foreach(['fiscal_street'=>'Falta calle del domicilio fiscal.','fiscal_neighborhood'=>'Falta colonia.','fiscal_municipality'=>'Falta municipio.','fiscal_state'=>'Falta estado.','trade_name'=>'El nombre comercial no está definido.'] as$field=>$warning){if(trim((string)($profile->$field??''))==='')$result['warnings'][]=$warning;}
        $result['status']=$result['errors']?'incomplete':'ready';$result['is_ready']=!$result['errors'];
        return$result;
    }
}
