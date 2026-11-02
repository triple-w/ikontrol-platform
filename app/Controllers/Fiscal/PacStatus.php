<?php
declare(strict_types=1);

namespace App\Controllers\Fiscal;

use App\Controllers\Security_Controller;
use App\Services\Fiscal\FiscalCsdReadinessService;
use App\Services\Fiscal\FiscalIssuerResolver;
use App\Services\Fiscal\FiscalRuntimeDiagnosticService;
use App\Services\Fiscal\IssuerFiscalReadinessService;

final class PacStatus extends Security_Controller
{
    public function index()
    {
        $this->guard();
        $db = db_connect();
        $runtime = (new FiscalRuntimeDiagnosticService())->inspect();
        $issuer = (new FiscalIssuerResolver($db))->resolve(null, $runtime['fiscal_environment']);
        $issuerReadiness = (new IssuerFiscalReadinessService($db))->evaluate($issuer?->id ? (int)$issuer->id : null, $issuer?->company_id ? (int)$issuer->company_id : null);
        $csd = (new FiscalCsdReadinessService($db))->inspect($issuer);
        return $this->template->view('fiscal/pac/status_diagnostic', compact('runtime','issuerReadiness','csd'));
    }

    private function guard(): void
    {
        if ($this->login_user->is_admin) return;
        $permissions = is_array($this->login_user->permissions) ? $this->login_user->permissions : (@unserialize((string)$this->login_user->permissions) ?: []);
        if (!get_array_value($permissions, 'fiscal_pac_status_view')) app_redirect('forbidden');
    }
}
