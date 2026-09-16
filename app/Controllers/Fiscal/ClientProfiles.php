<?php
namespace App\Controllers\Fiscal;

use App\Controllers\Security_Controller;
use App\Models\Fiscal\Fiscal_profiles_model;
use App\Models\Fiscal\Sat_cfdi_uses_model;
use App\Models\Fiscal\Sat_tax_regimes_model;
use App\Services\Fiscal\FiscalReadinessService;

class ClientProfiles extends Security_Controller
{
    private Fiscal_profiles_model $profiles;

    public function __construct()
    {
        parent::__construct();
        $this->profiles = new Fiscal_profiles_model();
    }

    private function allowed(bool $manage = false): bool
    {
        if ($this->login_user->is_admin) return true;
        $permissions = $this->login_user->permissions;
        if (!is_array($permissions)) $permissions = @unserialize((string) $permissions) ?: [];
        return (bool) get_array_value($permissions, $manage ? 'can_manage_fiscal_profiles' : 'can_view_fiscal_profiles');
    }

    private function guard(bool $manage = false): void
    {
        if (!$this->allowed($manage)) app_redirect('forbidden');
    }

    public function index($clientId = 0)
    {
        $this->guard();
        validate_numeric_value($clientId);
        return $this->template->view('fiscal/client_profiles/index', ['client_id' => $clientId, 'can_manage' => $this->allowed(true)]);
    }

    public function list_data($clientId = 0): void
    {
        $this->guard();
        validate_numeric_value($clientId);
        $rows = [];
        foreach ($this->profiles->forClient((int) $clientId)->getResult() as $profile) {
            $ready = $this->readiness($profile);
            $rows[] = [
                $profile->legal_name ?: '-', $profile->rfc ?: '-', app_lang('fiscal_profile_' . $profile->status),
                $ready['is_ready'] ? app_lang('ready_to_invoice') : app_lang('incomplete'),
                $profile->is_default ? app_lang('yes') : app_lang('no'),
                $this->allowed(true) ? modal_anchor(get_uri('fiscal/client-profiles/form'), '<i data-feather="edit" class="icon-16"></i>', ['data-post-id' => $profile->id, 'data-post-client_id' => $clientId, 'title' => app_lang('edit')]) : ''
            ];
        }
        echo json_encode(['data' => $rows]);
    }

    public function form()
    {
        $this->guard(true);
        $id = (int) $this->request->getPost('id');
        $clientId = (int) $this->request->getPost('client_id');
        $profile = $this->profiles->get_one($id);
        $client = db_connect()->table('clients')->where(['id' => $clientId, 'deleted' => 0])->get()->getRow();
        $regimes = ['' => '-'] + (new Sat_tax_regimes_model())->getActiveDropdown(['code', 'description']);
        $uses = ['' => '-'] + (new Sat_cfdi_uses_model())->getActiveDropdown(['code', 'description']);
        return $this->template->view('fiscal/client_profiles/modal_form', [
            'model_info' => $profile, 'client_id' => $clientId, 'client_info' => $client,
            'regimes' => $regimes, 'uses' => $uses
        ]);
    }

    public function save(): void
    {
        $this->guard(true);
        $id = (int) $this->request->getPost('id');
        $clientId = (int) $this->request->getPost('client_id');
        $status = (string) $this->request->getPost('status');
        if (!in_array($status, ['draft', 'incomplete', 'ready', 'inactive'], true)) $status = 'draft';
        $fiscalCountryCode = strtoupper(trim((string) $this->request->getPost('fiscal_country_code')));
        if ($fiscalCountryCode !== '' && !preg_match('/^[A-Z]{3}$/', $fiscalCountryCode)) {
            echo json_encode(['success' => false, 'message' => app_lang('fiscal_country_code_invalid')]);
            return;
        }

        $data = [
            'profile_type' => 'receiver', 'client_id' => $clientId,
            'rfc' => strtoupper(trim((string) $this->request->getPost('rfc'))),
            'legal_name' => trim((string) $this->request->getPost('legal_name')),
            'tax_regime_id' => $this->request->getPost('tax_regime_id') ?: null,
            'fiscal_postal_code' => trim((string) $this->request->getPost('fiscal_postal_code')),
            'default_cfdi_use_id' => $this->request->getPost('default_cfdi_use_id') ?: null,
            'tax_residency_country' => strtoupper(trim((string) $this->request->getPost('tax_residency_country'))),
            'foreign_tax_registration' => trim((string) $this->request->getPost('foreign_tax_registration')),
            'fiscal_street' => $this->nullablePost('fiscal_street'),
            'fiscal_external_number' => $this->nullablePost('fiscal_external_number'),
            'fiscal_internal_number' => $this->nullablePost('fiscal_internal_number'),
            'fiscal_neighborhood' => $this->nullablePost('fiscal_neighborhood'),
            'fiscal_locality' => $this->nullablePost('fiscal_locality'),
            'fiscal_municipality' => $this->nullablePost('fiscal_municipality'),
            'fiscal_state' => $this->nullablePost('fiscal_state'),
            'fiscal_country_code' => $fiscalCountryCode ?: null,
            'fiscal_address_reference' => $this->nullablePost('fiscal_address_reference'),
            'status' => $status, 'is_default' => $this->request->getPost('is_default') ? 1 : 0,
            'updated_at' => get_current_utc_time()
        ];

        if ($id) {
            $existing = $this->profiles->get_one($id);
            if (!$existing->id || (int) $existing->client_id !== $clientId) {
                echo json_encode(['success' => false, 'message' => app_lang('error_occurred')]);
                return;
            }
        }
        if ($status === 'ready') {
            $probe = (object) $data;
            $probe->id = $id ?: null;
            $regime = $data['tax_regime_id'] ? (new Sat_tax_regimes_model())->get_one($data['tax_regime_id']) : null;
            $use = $data['default_cfdi_use_id'] ? (new Sat_cfdi_uses_model())->get_one($data['default_cfdi_use_id']) : null;
            if (!(new FiscalReadinessService())->evaluate($probe, $regime, $use)['is_ready']) $data['status'] = 'incomplete';
        }
        if (!$id) {
            $data['created_by'] = $this->login_user->id;
            $data['created_at'] = get_current_utc_time();
        }
        $saved = $this->profiles->ci_save($data, $id);
        if ($saved && $data['is_default']) $this->profiles->setDefault($clientId, (int) $saved);
        echo json_encode(['success' => (bool) $saved, 'id' => $saved, 'message' => $saved ? app_lang('record_saved') : app_lang('error_occurred')]);
    }

    public function set_default(): void
    {
        $this->guard(true);
        $id = (int) $this->request->getPost('id');
        $profile = $this->profiles->get_one($id);
        echo json_encode(['success' => $profile->id ? $this->profiles->setDefault((int) $profile->client_id, $id) : false]);
    }

    public function deactivate(): void
    {
        $this->guard(true);
        $id = (int) $this->request->getPost('id');
        $data = ['status' => 'inactive', 'is_default' => 0, 'updated_at' => get_current_utc_time()];
        echo json_encode(['success' => (bool) $this->profiles->ci_save($data, $id)]);
    }

    private function nullablePost(string $name): ?string
    {
        $value = trim((string) $this->request->getPost($name));
        return $value === '' ? null : $value;
    }

    private function readiness(object $profile): array
    {
        $regime = $profile->tax_regime_id ? (new Sat_tax_regimes_model())->get_one($profile->tax_regime_id) : null;
        $use = $profile->default_cfdi_use_id ? (new Sat_cfdi_uses_model())->get_one($profile->default_cfdi_use_id) : null;
        return (new FiscalReadinessService())->evaluate($profile, $regime, $use);
    }
}
