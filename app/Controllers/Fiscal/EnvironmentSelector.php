<?php
declare(strict_types=1);

namespace App\Controllers\Fiscal;

use App\Controllers\Security_Controller;
use App\Services\Fiscal\FiscalEnvironmentSelectorService;
use App\Services\Fiscal\FiscalRuntimeContext;
use Throwable;

final class EnvironmentSelector extends Security_Controller
{
    public function index()
    {
        $this->guard();
        $service = new FiscalEnvironmentSelectorService();
        $target = (string) ($this->request->getGet('target') ?: FiscalRuntimeContext::fiscalEnvironment(config('Fiscal')));
        return $this->template->rander('fiscal/environment_selector/index', [
            'preview' => $service->inspect($target), 'latest' => $service->latest(),
        ]);
    }

    public function preview(): void
    {
        $this->guard();
        try {
            $result = (new FiscalEnvironmentSelectorService())->requestPreview(
                (string) $this->request->getPost('environment'),
                (string) $this->request->getPost('reason'),
                (string) $this->request->getPost('confirmation'),
                (int) $this->login_user->id,
                $this->allowed()
            );
            echo json_encode(['success'=>true,'message'=>'Previsualización auditada; el ambiente no cambió.','data'=>$result], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch (Throwable $error) {
            log_message('warning', 'Fiscal environment preview blocked: {type}', ['type'=>get_class($error)]);
            echo json_encode(['success'=>false,'message'=>$this->safeMessage($error->getMessage())], JSON_UNESCAPED_UNICODE);
        }
    }

    private function allowed(): bool
    {
        if (!$this->login_user->is_admin || empty(config('Fiscal')->environmentSelectorEnabled)) return false;
        $permissions = is_array($this->login_user->permissions)
            ? $this->login_user->permissions : (@unserialize((string) $this->login_user->permissions) ?: []);
        return (bool) get_array_value($permissions, 'fiscal.environment.manage');
    }

    private function guard(): void { if (!$this->allowed()) app_redirect('forbidden'); }

    private function safeMessage(string $code): string
    {
        return match ($code) {
            'FISCAL_ENVIRONMENT_SELECTOR_DISABLED' => 'El selector de ambiente está deshabilitado.',
            'FISCAL_ENVIRONMENT_PERMISSION_REQUIRED' => 'No tiene el permiso fiscal requerido.',
            'FISCAL_ENVIRONMENT_CONFIRMATION_INVALID' => 'La confirmación no coincide con el ambiente solicitado.',
            'FISCAL_ENVIRONMENT_REASON_INVALID' => 'Indique un motivo de entre 12 y 500 caracteres.',
            'FISCAL_ENVIRONMENT_OPERATIONS_IN_PROGRESS' => 'Hay operaciones fiscales pendientes; no se puede continuar.',
            'FISCAL_ENVIRONMENT_SELECTOR_SCHEMA_MISSING' => 'Falta instalar el registro aditivo de auditoría del selector.',
            'FISCAL_ENVIRONMENT_READ_BACK_MISMATCH' => 'El contexto cambió durante la verificación; la solicitud quedó bloqueada para revisión.',
            default => 'No fue posible previsualizar el cambio de ambiente.',
        };
    }
}
