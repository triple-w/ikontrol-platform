<?php
declare(strict_types=1);

namespace App\Controllers\Fiscal;

use App\Controllers\Security_Controller;
use App\Services\Fiscal\FiscalIssuerResolver;
use App\Services\Fiscal\FiscalRuntimeContext;
use App\Services\Fiscal\Stamps\FiscalStampBalancePresentationService;

final class StampBalanceEnvironment extends Security_Controller
{
    public function index()
    {
        if (!$this->canView()) app_redirect('forbidden');
        $db = db_connect();
        $environment = FiscalRuntimeContext::fiscalEnvironment(config('Fiscal'));
        $companyId = function_exists('get_default_company_id') ? (int)get_default_company_id() : null;
        $issuer = (new FiscalIssuerResolver($db))->resolve($companyId ?: null, $environment);
        $projection = $issuer ? (new FiscalStampBalancePresentationService($db))->forIssuer((int)$issuer->id, $environment) : [
            'commercial'=>['available'=>0,'reserved'=>0,'consumed'=>0,'usable'=>0,'status'=>'issuer_missing','reason'=>'No existe un emisor listo en el ambiente activo.'],
            'pac'=>['known'=>false,'available_credits'=>null,'reason'=>'No se puede consultar el saldo PAC sin emisor.'],
            'movements'=>[], 'resolution'=>['status'=>'issuer_missing'],
        ];
        return $this->template->rander('fiscal/stamps/balance_environment', compact('environment','issuer','projection'));
    }

    private function canView(): bool
    {
        if ($this->login_user->is_admin) return true;
        $permissions = is_array($this->login_user->permissions) ? $this->login_user->permissions : (@unserialize((string)$this->login_user->permissions) ?: []);
        return !empty($permissions['fiscal.stamps.view_balance']);
    }
}
