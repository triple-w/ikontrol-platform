<?php
declare(strict_types=1);

namespace App\Services\Upgrade;

/** Canonical minimum schema consumed by the fiscal 1.1.6 integration. */
final class Fiscal116SchemaContract
{
    public const PREREQUISITE_COLUMNS = [
        'fiscal_documents'=>['id','issuer_profile_id','environment','status','deleted'],
        'fiscal_document_stamps'=>['id','fiscal_document_id','uuid','stamped_xml_artifact_id'],
        'fiscal_document_artifacts'=>['id','fiscal_document_id','artifact_type','storage_path','sha256','superseded_at'],
        'fiscal_document_issuers'=>['fiscal_document_id','rfc'],
        'fiscal_document_receivers'=>['fiscal_document_id','rfc'],
        'fiscal_cancellation_requests'=>['id','fiscal_document_id','environment','status'],
        'fiscal_stamp_movements'=>['id','idempotency_key'],
        'fiscal_profiles'=>['id','company_id','profile_type','rfc','environment','status'],
        'fiscal_series'=>['issuer_profile_id','document_type','series','environment','initial_folio','current_folio','is_default','is_active','deleted','updated_at'],
        'fiscal_stamp_accounts'=>['id','issuer_profile_id','environment','available_balance','reserved_balance','status'],
        'payment_methods'=>['id','title','deleted','available_on_invoice'],
        'fiscal_payment_method_mappings'=>['id','payment_method_id','sat_payment_form_code','is_active'],
        'sat_payment_forms'=>['code','name','is_active'],
        'item_fiscal_settings'=>['id','item_id','commercial_unit','fiscal_description','sat_product_service_key_id','sat_unit_key_id','tax_object_code_id','status','deleted','is_default'],
        'item_fiscal_taxes'=>['id','item_fiscal_setting_id','tax_id','is_active'],
    ];

    public const PREREQUISITE_INDEXES = [
        'fiscal_document_stamps'=>[
            'uq_stamp_document'=>['columns'=>['fiscal_document_id'],'unique'=>true],
            'uq_stamp_uuid'=>['columns'=>['uuid'],'unique'=>true],
        ],
        'fiscal_document_artifacts'=>[
            'idx_fiscal_artifact_active'=>['columns'=>['fiscal_document_id','artifact_type','superseded_at'],'unique'=>false],
        ],
        'fiscal_series'=>[
            'uq_fiscal_series_issuer_type_series'=>['columns'=>['issuer_profile_id','document_type','series'],'unique'=>true],
        ],
        'fiscal_stamp_accounts'=>[
            'uq_stamp_account_issuer_environment'=>['columns'=>['issuer_profile_id','environment'],'unique'=>true],
        ],
        'fiscal_stamp_movements'=>[
            'uq_stamp_movement_idempotency'=>['columns'=>['idempotency_key'],'unique'=>true],
        ],
        'fiscal_payment_method_mappings'=>[
            'uq_fiscal_payment_method_mapping'=>['columns'=>['payment_method_id'],'unique'=>true],
        ],
    ];

    public const NEW_TABLES = [
        'fiscal_environment_change_requests'=>[
            'columns'=>['id','requested_environment','current_environment','status','reason','before_context_id','after_context_id','preflight_json','requested_by','requested_at','verified_at'],
            'indexes'=>['PRIMARY'=>['id'],'idx_fiscal_env_request_environment'=>['requested_environment','requested_at'],'idx_fiscal_env_request_user'=>['requested_by','requested_at']],
        ],
        'fiscal_sat_status_queries'=>[
            'columns'=>['id','fiscal_document_id','fiscal_document_stamp_id','cancellation_request_id','uuid','provider','environment','idempotency_key','lock_document_id','status','normalized_status','authoritative_status','previous_authoritative_status','provider_code','provider_message','http_status','request_sent','timed_out','requires_reconciliation','commercial_policy','commercial_credit_delta','may_have_provider_cost','queried_by','started_at','completed_at','cooldown_until','created_at','updated_at'],
            'indexes'=>['PRIMARY'=>['id'],'uq_fiscal_sat_status_idempotency'=>['idempotency_key'],'uq_fiscal_sat_status_document_lock'=>['lock_document_id'],'idx_fiscal_sat_status_document'=>['fiscal_document_id','created_at']],
        ],
        'fiscal_sat_status_artifacts'=>[
            'columns'=>['id','fiscal_sat_status_query_id','artifact_type','content_encoding','content_base64','decoded_mime_type','decoded_size_bytes','decoded_sha256','created_by','created_at'],
            'indexes'=>['PRIMARY'=>['id'],'uq_fiscal_sat_status_artifact'=>['fiscal_sat_status_query_id','artifact_type']],
        ],
    ];
}
