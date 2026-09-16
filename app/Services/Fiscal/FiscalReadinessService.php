<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
class FiscalReadinessService {
    public function evaluate(?object $profile, ?object $regime=null, ?object $cfdiUse=null): array {
        $result=['is_ready'=>false,'errors'=>[],'warnings'=>[],'missing_fields'=>[],'profile_id'=>$profile->id ?? null];
        if(!$profile){ $result['errors'][]='Sin perfil fiscal.'; return $result; }
        foreach(['rfc'=>'RFC','legal_name'=>'razón social','tax_regime_id'=>'régimen fiscal','fiscal_postal_code'=>'código postal fiscal','default_cfdi_use_id'=>'Uso CFDI'] as $field=>$label) if(trim((string)($profile->$field ?? ''))==='') $result['missing_fields'][]=$field;
        if($result['missing_fields']) $result['errors'][]='Faltan: '.implode(', ',array_map(fn($f)=>['rfc'=>'RFC','legal_name'=>'razón social','tax_regime_id'=>'régimen fiscal','fiscal_postal_code'=>'código postal fiscal','default_cfdi_use_id'=>'Uso CFDI'][$f],$result['missing_fields'])).'.';
        if(($profile->tax_regime_id ?? null) && (!$regime || !(int)($regime->is_active ?? 0))) $result['errors'][]='Clave de régimen inactiva o inexistente.';
        if(($profile->default_cfdi_use_id ?? null) && (!$cfdiUse || !(int)($cfdiUse->is_active ?? 0))) $result['errors'][]='Clave de Uso CFDI inactiva o inexistente.';
        if(($profile->status ?? '')==='inactive') $result['errors'][]='El perfil está inactivo.';
        if(($profile->rfc ?? '') && !preg_match('/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/',(string)$profile->rfc)) $result['warnings'][]='El formato del RFC requiere revisión; esto no valida su existencia ante el SAT.';
        $addressMissing=[];
        foreach(['fiscal_street'=>'calle','fiscal_neighborhood'=>'colonia','fiscal_municipality'=>'municipio o alcaldía','fiscal_state'=>'estado','fiscal_country_code'=>'país'] as $field=>$label) if(trim((string)($profile->$field ?? ''))==='') $addressMissing[]=$label;
        if($addressMissing) $result['warnings'][]='Dirección fiscal complementaria incompleta. Falta: '.implode(', ',$addressMissing).'.';
        $result['is_ready']=$result['errors']===[] && $result['missing_fields']===[];
        return $result;
    }
}
