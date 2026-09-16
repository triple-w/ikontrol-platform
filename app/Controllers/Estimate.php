<?php

namespace App\Controllers;

use App\Services\EstimateAcceptanceService;

class Estimate extends Security_Controller {

    function __construct() {
        parent::__construct(false);
    }

    function index() {
        app_redirect("forbidden");
    }

    function preview($estimate_id = 0, $public_key = "") {
        if (!($estimate_id && $public_key)) {
            show_404();
        }

        validate_numeric_value($estimate_id);

        //check public key
        $estimate_info = $this->Estimates_model->get_one($estimate_id);
        if ($estimate_info->public_key !== $public_key) {
            show_404();
        }

        $view_data = array();

        $estimate_data = get_estimate_making_data($estimate_id);
        if (!$estimate_data) {
            show_404();
        }

        $view_data['estimate_preview'] = prepare_estimate_pdf($estimate_data, "html");
        $view_data['show_close_preview'] = false; //don't show back button
        $view_data['estimate_id'] = $estimate_id;
        $view_data['estimate_type'] = "public";
        $view_data['public_key'] = clean_data($public_key);

        return view("estimates/estimate_public_preview", $view_data);
    }

    //update estimate status
    function update_estimate_status($estimate_id, $public_key, $status) {
        validate_numeric_value($estimate_id);
        if (!($estimate_id && $public_key && $status)) {
            show_404();
        }

        $estimate_info = $this->Estimates_model->get_one($estimate_id);
        if (!($estimate_info->id && !$estimate_info->deleted && $estimate_info->public_key === $public_key)) {
            show_404();
        }

        $public_url = get_uri("estimate/preview/{$estimate_id}/{$public_key}");

        // A repeated acceptance of an already converted estimate is successful and read-only.
        if ($status === "accepted" && ($estimate_info->status === "converted" || $estimate_info->converted_sale_id)) {
            echo json_encode(array(
                "success" => true,
                "status" => "accepted",
                "message" => app_lang("estimate_already_accepted_and_processed"),
                "invoice_action" => "existing",
                "invoice_id" => (int) $estimate_info->converted_sale_id,
                "public_url" => $public_url
            ));
            return;
        }

        // Public decisions are only valid while the estimate is pending.
        if (($status == "accepted" || $status == "declined") && $estimate_info->status === "sent") {
            $estimate_data = array("status" => $status);
            if ($status === "accepted") {
                $acceptance_service = new EstimateAcceptanceService();
                $actor = (int) ($estimate_info->created_by ?: 1);
                try {
                    $result = $acceptance_service->acceptAndFulfill(
                        (int) $estimate_id,
                        $estimate_data,
                        $acceptance_service->shouldCreateInvoiceOnAcceptance(),
                        $actor
                    );
                } catch (\Throwable $e) {
                    echo json_encode(array("success" => false, "message" => EstimateAcceptanceService::userMessage($e)));
                    return;
                }
            } else {
                $estimate_id = $this->Estimates_model->ci_save($estimate_data, $estimate_id);
            }

            $fresh_estimate = $this->Estimates_model->get_one($estimate_id);

            //create notification
            if ($status == "accepted") {
                log_notification("estimate_accepted", array("estimate_id" => $estimate_id), isset($this->login_user->id) ? $this->login_user->id : "999999996");
                $this->session->setFlashdata("success_message", app_lang($acceptance_service->resultMessageKey($result)));
            } else if ($status == "declined") {
                log_notification("estimate_rejected", array("estimate_id" => $estimate_id), isset($this->login_user->id) ? $this->login_user->id : "999999996");
                $this->session->setFlashdata("error_message", app_lang('estimate_rejected'));
            }

            echo json_encode(array(
                "success" => true,
                "status" => $fresh_estimate->status,
                "message" => $status === "accepted"
                    ? app_lang($acceptance_service->resultMessageKey($result))
                    : app_lang("estimate_rejected"),
                "public_url" => $public_url
            ));
            return;
        }


        echo json_encode(array(
            "success" => false,
            "status" => $estimate_info->status,
            "message" => app_lang("estimate_status_cannot_be_changed"),
            "public_url" => $public_url
        ));
    }

    function accept_estimate_modal_form($estimate_id = 0, $public_key = "") {
        validate_numeric_value($estimate_id);
        if (!$estimate_id) {
            show_404();
        }

        $estimate_info = $this->Estimates_model->get_one($estimate_id);
        if (!$estimate_info->id || $estimate_info->deleted || !EstimateAcceptanceService::acceptsStatus((string) $estimate_info->status)) {
            show_404();
        }

        if ($public_key) {
            //public estimate
            if ($estimate_info->public_key !== $public_key) {
                show_404();
            }

            $view_data["show_info_fields"] = true;
        } else {
            //estimate preview, should be logged in client contact
            $this->access_only_clients();
            if ($this->login_user->user_type === "client" && $this->login_user->client_id !== $estimate_info->client_id) {
                show_404();
            }

            $view_data["show_info_fields"] = false;
        }

        $view_data["model_info"] = $estimate_info;
        return $this->template->view('estimates/accept_estimate_modal_form', $view_data);
    }

    function accept_estimate() {
        $validation_array = array(
            "id" => "numeric|required",
            "public_key" => "required",
            "email" => "valid_email"
        );

        if (get_setting("add_signature_option_on_accepting_estimate")) {
            $validation_array["signature"] = "required";
        }

        $this->validate_submitted_data($validation_array);

        $estimate_id = $this->request->getPost("id");
        $estimate_info = $this->Estimates_model->get_one($estimate_id);
        if (!$estimate_info->id || $estimate_info->deleted) {
            show_404();
        }

        $public_key = $this->request->getPost("public_key");
        if ($estimate_info->public_key !== $public_key) {
            show_404();
        }

        if ($estimate_info->status === "converted" || $estimate_info->converted_sale_id) {
            echo json_encode(array(
                "success" => true,
                "status" => "accepted",
                "message" => app_lang("estimate_already_accepted_and_processed"),
                "invoice_action" => "existing",
                "invoice_id" => (int) $estimate_info->converted_sale_id,
                "public_url" => get_uri("estimate/preview/{$estimate_id}/{$public_key}")
            ));
            return;
        }

        if (!EstimateAcceptanceService::acceptsStatus((string) $estimate_info->status)) {
            echo json_encode(array("success" => false, "message" => app_lang("estimate_status_cannot_be_changed")));
            return;
        }

        $name = $this->request->getPost("name");
        $email = $this->request->getPost("email");
        $signature = $this->request->getPost("signature");

        $meta_data = array();
        $estimate_data = array();

        if ($signature) {
            $signature = explode(",", $signature);
            $signature = get_array_value($signature, 1);
            $signature = base64_decode($signature);
            $signature = serialize(move_temp_file("signature.jpg", get_setting("timeline_file_path"), "estimate", NULL, "", $signature));

            $meta_data["signature"] = $signature;
            $meta_data["signed_date"] = get_current_utc_time();
        }

        if ($name) {
            //from public estimate
            if (!$email) {
                show_404();
            }

            $meta_data["name"] = clean_data($name);
            $meta_data["email"] = clean_data($email);
        } else {
            //from preview, should be logged in client contact
            $this->init_permission_checker("estimate");
            $this->access_only_allowed_members_or_client_contact($estimate_info->client_id);
            if ($this->login_user->user_type === "client" && $this->login_user->client_id !== $estimate_info->client_id) {
                show_404();
            }

            $estimate_data["accepted_by"] = $this->login_user->id;
        }

        $estimate_data["meta_data"] = serialize($meta_data);
        $estimate_data["status"] = "accepted";

        $actor = (int) (($name ? $estimate_info->created_by : $this->login_user->id) ?: 1);
        $acceptance_service = new EstimateAcceptanceService();
        try {
            $result = $acceptance_service->acceptAndFulfill(
                (int) $estimate_id,
                $estimate_data,
                $acceptance_service->shouldCreateInvoiceOnAcceptance(),
                $actor
            );
        } catch (\Throwable $e) {
            echo json_encode(array("success" => false, "message" => EstimateAcceptanceService::userMessage($e)));
            return;
        }
        if ($result["accepted"] ?? false) {
            $fresh_estimate = $this->Estimates_model->get_one($estimate_id);
            log_notification("estimate_accepted", array("estimate_id" => $estimate_id), ($name ? "999999996" : $this->login_user->id));
            echo json_encode(array(
                "success" => true,
                "status" => $fresh_estimate->status,
                "message" => app_lang($acceptance_service->resultMessageKey($result)),
                "invoice_action" => $result["invoice_action"],
                "invoice_id" => $result["invoice_id"],
                "public_url" => get_uri("estimate/preview/{$estimate_id}/{$public_key}")
            ));
        } else {
            echo json_encode(array("success" => false, "message" => app_lang("error_occurred")));
        }
    }
}

/* End of file Estimate.php */
/* Location: ./app/controllers/Estimate.php */
