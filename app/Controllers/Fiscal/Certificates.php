<?php
declare(strict_types=1);

namespace App\Controllers\Fiscal;

use App\Controllers\Security_Controller;
use App\Models\Fiscal\Fiscal_issuer_certificates_model;
use App\Services\Fiscal\CsdCertificateService;
use App\Services\Fiscal\Signing\CsdCertificateSecretService;
use App\Services\Fiscal\Signing\CsdOperationalStatusService;
use App\Domain\Fiscal\Signing\CsdSecretException;

final class Certificates extends Security_Controller
{
    private Fiscal_issuer_certificates_model $certificates;

    public function __construct()
    {
        parent::__construct();
        $this->certificates = new Fiscal_issuer_certificates_model();
    }

    public function index($issuerId = 0)
    {
        $this->guard(false);
        $issuer = $this->issuer((int) $issuerId);
        return $this->template->rander('fiscal/certificates/index', [
            'issuer' => $issuer,
            'can_manage' => $this->allowed(true),
        ]);
    }

    public function list_data($issuerId = 0): void
    {
        $this->guard(false);
        $this->issuer((int) $issuerId);
        $rows = [];
        $statusService = new CsdOperationalStatusService();
        foreach ($this->certificates->forIssuer((int) $issuerId)->getResult() as $certificate) {
            $actions = '';
            $operational = $statusService->forCertificate($certificate);
            if ($this->allowed(true) && !in_array($certificate->status, ['inactive', 'revoked_internal'], true)) {
                $actions = modal_anchor(
                    get_uri('fiscal/certificates/secret/form'),
                    '<i data-feather="key" class="icon-16"></i>',
                    [
                        'title' => $operational['ready']
                            ? app_lang('update_csd_password')
                            : app_lang('configure_csd_password'),
                        'data-post-certificate_id' => $certificate->id,
                    ]
                );
                $actions .= js_anchor('<i data-feather="x" class="icon-16"></i>', [
                    'title' => app_lang('deactivate'), 'class' => 'delete',
                    'data-action-url' => get_uri('fiscal/certificates/deactivate'),
                    'data-post-id' => $certificate->id, 'data-act' => 'ajax-request',
                    'data-reload-on-success' => '1',
                ]);
            }
            $rows[] = [
                htmlspecialchars($certificate->certificate_number),
                htmlspecialchars($certificate->certificate_rfc),
                format_to_datetime($certificate->valid_from),
                format_to_datetime($certificate->valid_to),
                app_lang('csd_status_' . $certificate->status),
                htmlspecialchars($operational['label']),
                $certificate->is_default ? app_lang('yes') : app_lang('no'),
                $actions,
            ];
        }
        echo json_encode(['data' => $rows]);
    }

    public function form()
    {
        $this->guard(true);
        $issuer = $this->issuer((int) $this->request->getPost('issuer_profile_id'));
        return $this->template->view('fiscal/certificates/modal_form', ['issuer' => $issuer]);
    }

    public function upload(): void
    {
        $this->guard(true);
        $issuerId = (int) $this->request->getPost('issuer_profile_id');
        $this->issuer($issuerId);
        $certificate = $this->request->getFile('certificate_file');
        $key = $this->request->getFile('private_key_file');
        if (!$certificate || !$key || !$certificate->isValid() || !$key->isValid()) {
            echo json_encode(['success' => false, 'message' => app_lang('csd_files_required')]);
            return;
        }
        try {
            $certificateBytes = file_get_contents($certificate->getTempName());
            $keyBytes = file_get_contents($key->getTempName());
            if ($certificateBytes === false || $keyBytes === false) {
                throw new \RuntimeException('No fue posible leer los archivos cargados.');
            }
            $result = (new CsdCertificateService())->import(
                $issuerId, $certificateBytes, $certificate->getClientName(),
                $keyBytes, $key->getClientName(),
                (string) $this->request->getPost('private_key_password'),
                (bool) $this->request->getPost('is_default'),
                (int) $this->login_user->id, true
            );
            echo json_encode([
                'success' => true,
                'message' => app_lang('csd_uploaded') . ' ' . app_lang('csd_local_validity_notice'),
                'data' => ['id' => $result['certificate']->id, 'status' => $result['status']],
            ]);
        } catch (\Throwable $e) {
            log_message('warning', 'CSD upload rejected for issuer {issuer}: {type}', [
                'issuer' => $issuerId, 'type' => get_class($e),
            ]);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } finally {
            unset($certificateBytes, $keyBytes);
        }
    }

    public function secret_form()
    {
        $this->guard(true);
        $certificate = $this->certificates->get_one((int) $this->request->getPost('certificate_id'));
        if (!$certificate->id) {
            app_redirect('not_found');
        }
        $this->issuer((int) $certificate->issuer_profile_id);
        $status = (new CsdOperationalStatusService())->forCertificate($certificate);
        return $this->template->view('fiscal/certificates/secret_form', [
            'certificate' => $certificate,
            'csd_status' => $status,
        ]);
    }

    public function configure_secret(): void
    {
        $this->guard(true);
        $certificateId = (int) $this->request->getPost('certificate_id');
        $certificate = $this->certificates->get_one($certificateId);
        if (!$certificate->id) {
            $this->secretResponse([
                'success' => false,
                'code' => 'CSD_CERTIFICATE_NOT_READY',
                'message' => app_lang('csd_certificate_not_ready'),
            ]);
            return;
        }
        $this->issuer((int) $certificate->issuer_profile_id);
        try {
            (new CsdCertificateSecretService())->configure(
                $certificateId,
                (string) $this->request->getPost('private_key_password'),
                (int) $this->login_user->id,
                true
            );
            $this->secretResponse([
                'success' => true,
                'message' => app_lang('csd_password_configured'),
                'data' => ['certificate_id' => $certificateId, 'status' => 'ready'],
            ]);
        } catch (CsdSecretException $e) {
            log_message('warning', 'CSD secret configuration failed for certificate {id}: {code}', [
                'id' => $certificateId,
                'code' => $e->errorCode,
            ]);
            $this->secretResponse([
                'success' => false,
                'stage' => 'csd',
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'CSD secret configuration failed for certificate {id}: {type}', [
                'id' => $certificateId,
                'type' => get_class($e),
            ]);
            $this->secretResponse([
                'success' => false,
                'stage' => 'csd',
                'code' => 'CSD_SECRET_CONFIGURATION_FAILED',
                'message' => app_lang('csd_password_configuration_failed'),
            ]);
        } finally {
            unset($certificateId);
        }
    }

    /**
     * Return the current CSRF token so an AJAX modal can safely perform another
     * deliberate submission without retaining the CSD password.
     */
    private function secretResponse(array $payload): void
    {
        $payload['csrf'] = [
            'name' => csrf_token(),
            'hash' => csrf_hash(),
        ];

        echo json_encode($payload);
    }

    public function deactivate(): void
    {
        $this->guard(true);
        $id = (int) $this->request->getPost('id');
        $record = $this->certificates->get_one($id);
        $ok = false;
        if ($record->id) {
            $ok = (bool) $this->certificates->ci_save([
                'status' => 'inactive', 'is_default' => 0, 'updated_at' => get_current_utc_time(),
            ], $id);
        }
        echo json_encode(['success' => $ok, 'message' => $ok ? app_lang('record_saved') : app_lang('error_occurred')]);
    }

    private function issuer(int $id): object
    {
        $issuer = db_connect()->table('fiscal_profiles')->where([
            'id' => $id, 'profile_type' => 'issuer',
        ])->get(1)->getRow();
        if (!$issuer) {
            app_redirect('not_found');
        }
        return $issuer;
    }

    private function allowed(bool $manage): bool
    {
        if ($this->login_user->is_admin) {
            return true;
        }
        $permissions = $this->login_user->permissions;
        if (!is_array($permissions)) {
            $permissions = @unserialize((string) $permissions) ?: [];
        }
        return (bool) get_array_value($permissions, $manage ? 'fiscal_certificates_manage' : 'fiscal_certificates_view');
    }

    private function guard(bool $manage): void
    {
        if (!$this->allowed($manage)) {
            app_redirect('forbidden');
        }
    }
}
