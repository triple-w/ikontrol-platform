<?php

namespace App\Controllers;

use App\Libraries\Stripe;
use App\Models\Fiscal\Sat_tax_codes_model;
use App\Models\Fiscal\Sat_tax_factor_types_model;
use App\Services\Fiscal\TaxFiscalConfigurationService;

class Taxes extends Security_Controller {

    function __construct() {
        parent::__construct();
        if (!($this->login_user->is_admin || get_array_value($this->login_user->permissions, 'can_manage_all_kinds_of_settings') || $this->_can_fiscal_tax('view') || $this->_can_fiscal_tax('manage'))) {
            app_redirect('forbidden');
        }
    }

    function index() {
        return $this->template->rander("taxes/index", array('can_view_fiscal_tax_settings' => $this->_can_fiscal_tax('view') || $this->_can_fiscal_tax('manage')));
    }

    function modal_form() {
        if (!($this->login_user->is_admin || get_array_value($this->login_user->permissions, 'can_manage_all_kinds_of_settings') || $this->_can_fiscal_tax('manage'))) app_redirect('forbidden');

        $this->validate_submitted_data(array(
            "id" => "numeric"
        ));

        $view_data['model_info'] = $this->Taxes_model->get_one($this->request->getPost('id'));
        $view_data['tax_codes'] = array('' => '-') + (new Sat_tax_codes_model())->getActiveDropdown(array('code', 'name'));
        $view_data['factor_types'] = array('' => '-') + (new Sat_tax_factor_types_model())->getActiveDropdown(array('name'));
        $view_data['can_manage_fiscal_tax_settings'] = $this->_can_fiscal_tax('manage');
        return $this->template->view('taxes/modal_form', $view_data);
    }

    function save() {
        if (!($this->login_user->is_admin || get_array_value($this->login_user->permissions, 'can_manage_all_kinds_of_settings') || $this->_can_fiscal_tax('manage'))) app_redirect('forbidden');

        $this->validate_submitted_data(array(
            "id" => "numeric",
            "title" => "required",
            "percentage" => "required"
        ));

        $id = $this->request->getPost('id');
        $data = array(
            "title" => $this->request->getPost('title'),
            "percentage" => unformat_currency($this->request->getPost('percentage'))
        );
        if (db_connect()->fieldExists('use_for_fiscal', 'taxes') && $this->_can_fiscal_tax('manage')) {
            $input = $this->request->getPost();
            $taxCodeId = $input['sat_tax_code_id'] ?? null;
            $factorId = $input['factor_type_id'] ?? null;
            $taxCode = $taxCodeId ? (new Sat_tax_codes_model())->get_one($taxCodeId) : null;
            $factor = $factorId ? (new Sat_tax_factor_types_model())->get_one($factorId) : null;
            $prepared = (new TaxFiscalConfigurationService())->prepare($input, $taxCode, $factor);
            if ($prepared['errors']) { echo json_encode(array('success' => false, 'message' => implode(' ', $prepared['errors']))); return; }
            $data += $prepared['data'] + array('updated_at' => get_current_utc_time());
        }
        try { $save_id = $this->Taxes_model->ci_save($data, $id); }
        catch (\Throwable $e) { log_message('error', 'Tax save failed: {exception}', ['exception' => $e]); echo json_encode(array('success' => false, 'message' => app_lang('error_occurred'))); return; }
        if ($save_id) {
            echo json_encode(array("success" => true, "data" => $this->_row_data($save_id), 'id' => $save_id, 'message' => app_lang('record_saved')));
        } else {
            echo json_encode(array("success" => false, 'message' => app_lang('error_occurred')));
        }
    }

    function delete() {
        if (!$this->_can_manage_taxes()) app_redirect('forbidden');
        $this->validate_submitted_data(array(
            "id" => "numeric|required"
        ));

        $id = $this->request->getPost('id');
        if ($this->request->getPost('undo')) {
            if ($this->Taxes_model->delete($id, true)) {
                echo json_encode(array("success" => true, "data" => $this->_row_data($id), "message" => app_lang('record_undone')));
            } else {
                echo json_encode(array("success" => false, app_lang('error_occurred')));
            }
        } else {
            if ($this->Taxes_model->delete($id)) {
                echo json_encode(array("success" => true, 'message' => app_lang('record_deleted')));
            } else {
                echo json_encode(array("success" => false, 'message' => app_lang('record_cannot_be_deleted')));
            }
        }
    }

    function list_data() {
        $list_data = $this->Taxes_model->get_details()->getResult();
        $result = array();
        foreach ($list_data as $data) {
            $result[] = $this->_make_row($data);
        }
        echo json_encode(array("data" => $result));
    }

    private function _row_data($id) {
        $options = array("id" => $id);
        $data = $this->Taxes_model->get_details($options)->getRow();
        return $this->_make_row($data);
    }

    private function _make_row($data) {
        $stripe_taxes = js_anchor($data->stripe_tax_id ? app_lang("mapped") : app_lang("select_stripe_tax"), array('title' => "", "class" => "", "data-id" => $data->id, "data-value" => $data->stripe_tax_id, "data-act" => "update-stripe-tax"));

        $fiscalColumns = property_exists($data, 'use_for_fiscal') ? array(
            $data->sat_tax_code ?: app_lang('not_configured'), $data->fiscal_tax_type ?: app_lang('not_configured'), $data->factor_type ?: app_lang('not_configured'),
            $data->xml_rate ?: ($data->xml_quota ?: '-'), $data->use_for_administrative ? app_lang('yes') : app_lang('no'),
            $data->is_fiscal_ready ? app_lang('yes') : app_lang('no')
        ) : array('-', '-', '-', '-', app_lang('yes'), app_lang('no'));
        $base = array(
            "<span data-post-id='$data->id'>$data->title</span>",
            to_decimal_format($data->percentage),
        );
        if ($this->_can_fiscal_tax('view') || $this->_can_fiscal_tax('manage')) $base = array_merge($base, $fiscalColumns);
        return array_merge($base, array(
            $stripe_taxes,
            modal_anchor(get_uri("taxes/modal_form"), "<i data-feather='edit' class='icon-16'></i>", array("class" => "edit", "title" => app_lang('edit_tax'), "data-post-id" => $data->id))
            . js_anchor("<i data-feather='x' class='icon-16'></i>", array('title' => app_lang('delete_tax'), "class" => "delete", "data-id" => $data->id, "data-action-url" => get_uri("taxes/delete"), "data-action" => "delete"))
        ));
    }

    private function _can_fiscal_tax($action='view') {
        if ($this->login_user->is_admin) return true;
        $permissions = is_array($this->login_user->permissions) ? $this->login_user->permissions : (@unserialize($this->login_user->permissions) ?: array());
        return (bool)get_array_value($permissions, $action === 'manage' ? 'can_manage_fiscal_tax_settings' : 'can_view_fiscal_tax_settings');
    }

    private function _can_manage_taxes() { return $this->login_user->is_admin || get_array_value($this->login_user->permissions, 'can_manage_all_kinds_of_settings') || $this->_can_fiscal_tax('manage'); }
    private function _can_manage_stripe_taxes() { return $this->login_user->is_admin || get_array_value($this->login_user->permissions, 'can_manage_all_kinds_of_settings'); }

    function stripe_tax_mapping_modal_form() {
        if (!$this->_can_manage_stripe_taxes()) app_redirect('forbidden');
        $Stripe = new Stripe();
        $stripe_taxes = $Stripe->retrieve_all_taxes();

        $stripe_taxes_dropdown = array();
        foreach ($stripe_taxes as $stripe_tax) {
            $stripe_taxes_dropdown[] = array("id" => $stripe_tax->id, "text" => $stripe_tax->display_name . " (" . $stripe_tax->percentage . "%)");
        }

        $view_data["stripe_taxes_dropdown"] = $stripe_taxes_dropdown;

        return $this->template->view('settings/subscriptions/stripe_tax_mapping_modal_form', $view_data);
    }

    function save_stripe_tax($id = 0) {
        if (!$this->_can_manage_stripe_taxes()) app_redirect('forbidden');
        validate_numeric_value($id);
        $stripe_tax_id = $this->request->getPost('value');
        $data = array(
            "stripe_tax_id" => $stripe_tax_id
        );

        $save_id = $this->Taxes_model->ci_save($data, $id);

        if ($save_id) {
            echo json_encode(array("success" => true, "data" => $this->_row_data($save_id), 'id' => $save_id, "message" => app_lang('record_saved')));
        } else {
            echo json_encode(array("success" => false, app_lang('error_occurred')));
        }
    }

}

/* End of file taxes.php */
/* Location: ./app/controllers/taxes.php */
