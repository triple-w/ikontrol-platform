<?php
declare(strict_types=1);
namespace App\Services\Fiscal;

use RuntimeException;

/** Applies the canonical product and payment contracts to the existing onboarding inspection. */
final class FiscalUnifiedOnboardingService
{
    private FiscalOnboardingReadinessService $base;
    public function __construct(private mixed$db=null,?object$fiscal=null,?object$pac=null){$this->db??=db_connect();$this->base=new FiscalOnboardingReadinessService($this->db,$fiscal,$pac);}
    public function inspect():array
    {
        $status=$this->base->inspect();if(($status['state']??'')==='NOT_INSTALLED')return$status;
        $items=$this->db->table('items')->select('id')->where('deleted',0)->get()->getResult();$configured=0;$readiness=new ItemFiscalReadinessService($this->db);
        foreach($items as$item)if($readiness->evaluate((int)$item->id)['is_ready'])$configured++;
        $payments=(new FiscalPaymentMethodReadinessService($this->db))->inspect();
        $runtime=(new FiscalRuntimeDiagnosticService())->inspect();
        $status['details']['products']=['total'=>count($items),'configured'=>$configured,'incomplete'=>count($items)-$configured];
        $status['details']['payment_methods']=$payments;
        $status['details']['pac']=[
            'status'=>$runtime['pac_operational']?'READY':'BLOCKED',
            'logical_environment'=>$runtime['fiscal_environment'],
            'transport_environment'=>$runtime['transport_environment'],
            'adapter'=>$runtime['pac_provider'],
            'endpoint'=>$runtime['pac_endpoint'],
            'endpoint_allowed'=>$runtime['endpoint_allowed'],
            'credential_present'=>$runtime['credential_present'],
            'fiscal_enabled'=>$runtime['fiscal_enabled'],
            'stamping_enabled'=>$runtime['stamping_enabled'],
            'real_pac_allowed'=>$runtime['real_pac_allowed'],
            'context_id'=>$runtime['context_id'],
            'blocking_reasons'=>$runtime['blocking_reasons'],
        ];
        $status['blockers']=array_values(array_filter($status['blockers'],static fn(string$b):bool=>!in_array($b,['Hay productos sin configuración fiscal.','Métodos de pago sin mapping SAT completo.'],true)));
        if($configured<count($items))$status['blockers'][]='Hay productos que no cumplen el contrato fiscal efectivo.';
        $status['warnings']=$status['warnings']??[];if(!$payments['coverage_complete'])$status['warnings'][]='Hay métodos administrativos sin mapping SAT; sólo bloquearán PUE o complementos cuando participen en la operación.';
        $status['state']=$status['blockers']===[]?'READY':'ONBOARDING';$status['ready']=$status['state']==='READY';$status['details']['stamping']['status']=$status['ready']?'READY':'BLOCKED';
        return$status;
    }
    public function assertReadyForStamping():void{$status=$this->inspect();if($status['state']!=='READY')throw new RuntimeException('FISCAL_ONBOARDING_INCOMPLETE: '.($status['blockers'][0]??'Configuración fiscal incompleta.'));}
}
