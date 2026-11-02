<?php
declare(strict_types=1);
namespace App\Services\Fiscal;

/** Keeps existing UI grouping while replacing the fiscal payment action/detail. */
final class FiscalUnifiedReadinessActionService
{
    private FiscalReadinessActionService$base;
    public function __construct(){$this->base=new FiscalReadinessActionService();}
    public function onboardingChecklist(array$readiness):array{return array_map(fn(array$row):array=>$this->paymentRow($row,$readiness),$this->base->onboardingChecklist($readiness));}
    public function saleBlockerGroups(array$sale,array$onboarding,int$clientId):array{return$this->groups($this->base->saleBlockerGroups($sale,$onboarding,$clientId),$onboarding);}
    public function draftBlockerGroups(array$blockers,array$onboarding,int$clientId):array{return$this->groups($this->base->draftBlockerGroups($blockers,$onboarding,$clientId),$onboarding);}
    private function paymentRow(array$row,array$readiness):array{if(($row['key']??'')!=='payment_methods')return$row;$payments=$readiness['details']['payment_methods']??[];$row['detail']=$this->detail($payments);if(($row['status']??'')!=='OK')$row['action']=['label'=>'Configurar mappings fiscales','path'=>'fiscal/payment-methods'];return$row;}
    private function groups(array$groups,array$readiness):array{unset($groups['payment_methods']);return$groups;}
    private function detail(array$p):string{return(int)($p['mapped']??0).' / '.(int)($p['configured']??$p['available']??0).' métodos administrativos mapeados; diagnóstico informativo hasta que un pago participe en PUE o complemento.';}
}
