<?php

declare(strict_types=1);

namespace App\Controllers\Fiscal;

use App\Controllers\Security_Controller;
use App\Services\Fiscal\FiscalOnboardingReadinessService;

final class Onboarding extends Security_Controller
{
    public function index()
    {
        if (! $this->allowed()) {
            app_redirect('forbidden');
        }

        return $this->template->rander('fiscal/onboarding/index', [
            'status' => (new FiscalOnboardingReadinessService())->inspect(),
        ]);
    }

    public function status(): void
    {
        if (! $this->allowed()) {
            app_redirect('forbidden');
        }

        $this->response->setContentType('application/json');
        echo json_encode(
            (new FiscalOnboardingReadinessService())->inspect(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    private function allowed(): bool
    {
        if ($this->login_user->is_admin) {
            return true;
        }

        $permissions = is_array($this->login_user->permissions)
            ? $this->login_user->permissions
            : (@unserialize((string) $this->login_user->permissions) ?: []);

        return (bool) get_array_value($permissions, 'fiscal_pac_status_view');
    }
}
