CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_cancellation_artifacts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_cancellation_request_id` bigint(20) unsigned NOT NULL,
  `fiscal_cancellation_attempt_id` bigint(20) unsigned NOT NULL,
  `artifact_type` varchar(30) NOT NULL DEFAULT 'cancellation_ack',
  `content_encoding` varchar(20) NOT NULL DEFAULT 'base64',
  `content_base64` longtext NOT NULL,
  `decoded_mime_type` varchar(80) NOT NULL,
  `decoded_size_bytes` bigint(20) unsigned NOT NULL,
  `decoded_sha256` char(64) NOT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fiscal_cancellation_request_id_artifact_type` (`fiscal_cancellation_request_id`,`artifact_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_cancellation_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_cancellation_request_id` bigint(20) unsigned NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'prepared',
  `provider_code` varchar(100) DEFAULT NULL,
  `provider_message` text DEFAULT NULL,
  `response_hash` char(64) DEFAULT NULL,
  `requires_reconciliation` tinyint(1) NOT NULL DEFAULT 0,
  `started_at` datetime NOT NULL,
  `responded_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fiscal_cancellation_request_id` (`fiscal_cancellation_request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_cancellation_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_document_id` bigint(20) unsigned NOT NULL,
  `fiscal_document_stamp_id` bigint(20) unsigned NOT NULL,
  `uuid` char(36) NOT NULL,
  `issuer_rfc` varchar(20) NOT NULL,
  `receiver_rfc` varchar(20) NOT NULL,
  `total` decimal(18,6) NOT NULL,
  `cancellation_reason` char(2) NOT NULL,
  `replacement_uuid` char(36) DEFAULT NULL,
  `provider` varchar(40) NOT NULL,
  `environment` varchar(20) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'requested',
  `provider_code` varchar(100) DEFAULT NULL,
  `provider_message` text DEFAULT NULL,
  `requested_at` datetime NOT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `idempotency_key` char(64) NOT NULL,
  `requires_reconciliation` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `fiscal_document_id_status` (`fiscal_document_id`,`status`),
  KEY `uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_credit_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_fiscal_document_id` bigint(20) unsigned NOT NULL,
  `source_invoice_id` int(10) unsigned NOT NULL,
  `fiscal_document_id` bigint(20) unsigned DEFAULT NULL,
  `client_id` int(10) unsigned NOT NULL,
  `issuer_profile_id` int(10) unsigned NOT NULL,
  `receiver_profile_id` int(10) unsigned NOT NULL,
  `issue_date` datetime NOT NULL,
  `relation_type_code` varchar(3) NOT NULL DEFAULT '01',
  `currency_code` char(3) NOT NULL DEFAULT 'MXN',
  `payment_form_code` varchar(3) NOT NULL,
  `payment_method_code` varchar(3) NOT NULL,
  `cfdi_use_code` varchar(5) NOT NULL DEFAULT 'G02',
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `subtotal` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `discount` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `transferred_tax_total` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `withheld_tax_total` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `total` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `stamped_at` datetime DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_credit_note_fiscal_document` (`fiscal_document_id`),
  KEY `source_fiscal_document_id` (`source_fiscal_document_id`),
  KEY `source_invoice_id` (`source_invoice_id`),
  KEY `client_id` (`client_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_credit_note_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_credit_note_id` bigint(20) unsigned NOT NULL,
  `source_fiscal_document_item_id` bigint(20) unsigned NOT NULL,
  `quantity` decimal(18,6) NOT NULL,
  `unit_value` decimal(18,6) NOT NULL,
  `discount` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `subtotal` decimal(18,6) NOT NULL,
  `transferred_tax_total` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `withheld_tax_total` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `total` decimal(18,6) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_credit_note_source_line` (`fiscal_credit_note_id`,`source_fiscal_document_item_id`),
  KEY `source_fiscal_document_item_id` (`source_fiscal_document_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int(10) unsigned DEFAULT NULL,
  `source_draft_id` bigint(20) unsigned DEFAULT NULL,
  `issuer_profile_id` int(10) unsigned NOT NULL,
  `receiver_profile_id` int(10) unsigned NOT NULL,
  `fiscal_series_id` int(10) unsigned NOT NULL,
  `pricing_preparation_id` bigint(20) unsigned DEFAULT NULL,
  `document_type` varchar(20) NOT NULL DEFAULT 'income',
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `version` int(10) unsigned NOT NULL DEFAULT 1,
  `series` varchar(25) NOT NULL DEFAULT '',
  `folio` bigint(20) unsigned NOT NULL,
  `issue_date` datetime NOT NULL,
  `expedition_postal_code` varchar(5) NOT NULL,
  `currency_code` char(3) NOT NULL,
  `exchange_rate` decimal(18,6) DEFAULT NULL,
  `payment_form_code` varchar(3) NOT NULL,
  `payment_method_code` varchar(3) NOT NULL,
  `cfdi_use_code` varchar(5) NOT NULL,
  `export_code` varchar(3) NOT NULL DEFAULT '01',
  `subtotal` decimal(18,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `transferred_tax_total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `withheld_tax_total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `administrative_total_reference` decimal(18,2) NOT NULL DEFAULT 0.00,
  `pricing_mode` varchar(20) NOT NULL,
  `source_snapshot_hash` char(64) NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `stamp_updated_at` datetime DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  `environment` varchar(20) DEFAULT 'legacy',
  `data_origin` varchar(30) DEFAULT 'operational',
  `is_test_fixture` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fiscal_document_folio` (`issuer_profile_id`,`document_type`,`series`,`folio`),
  UNIQUE KEY `uq_fiscal_document_source_draft` (`source_draft_id`),
  KEY `idx_fiscal_document_invoice_status` (`invoice_id`,`status`,`deleted`),
  KEY `idx_fiscal_document_snapshot` (`invoice_id`,`source_snapshot_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_document_artifacts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_document_id` bigint(20) unsigned NOT NULL,
  `artifact_type` varchar(30) NOT NULL DEFAULT 'pre_xml',
  `storage_path` varchar(255) NOT NULL,
  `sha256` char(64) NOT NULL,
  `byte_size` bigint(20) unsigned NOT NULL,
  `builder_version` varchar(20) NOT NULL,
  `schema_version` varchar(20) NOT NULL,
  `schema_sha256` char(64) DEFAULT NULL,
  `validation_status` varchar(40) NOT NULL,
  `validation_payload` longtext DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `superseded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fiscal_artifact_idempotency` (`fiscal_document_id`,`artifact_type`,`builder_version`,`sha256`),
  KEY `idx_fiscal_artifact_active` (`fiscal_document_id`,`artifact_type`,`superseded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_document_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_document_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(40) NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `previous_hash` char(64) DEFAULT NULL,
  `new_hash` char(64) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fiscal_document_id_created_at` (`fiscal_document_id`,`created_at`),
  KEY `invoice_id_created_at` (`invoice_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_document_binary_artifacts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_document_id` bigint(20) unsigned NOT NULL,
  `stamp_attempt_id` bigint(20) unsigned NOT NULL,
  `pdf_generation_attempt_id` bigint(20) unsigned DEFAULT NULL,
  `artifact_type` varchar(30) NOT NULL,
  `content_encoding` varchar(20) NOT NULL DEFAULT 'base64',
  `content_base64` longtext NOT NULL,
  `decoded_mime_type` varchar(80) NOT NULL,
  `decoded_size_bytes` bigint(20) unsigned NOT NULL,
  `decoded_sha256` char(64) NOT NULL,
  `provider` varchar(40) NOT NULL,
  `template` varchar(80) DEFAULT NULL,
  `template_code` varchar(40) DEFAULT NULL,
  `uuid` char(36) NOT NULL,
  `validation_status` varchar(30) NOT NULL,
  `artifact_status` varchar(20) DEFAULT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL,
  `superseded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `stamp_attempt_id` (`stamp_attempt_id`),
  KEY `uuid` (`uuid`),
  KEY `idx_fiscal_binary_active` (`fiscal_document_id`,`artifact_type`,`artifact_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_document_issuers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_document_id` bigint(20) unsigned NOT NULL,
  `rfc` varchar(13) NOT NULL,
  `legal_name` varchar(254) NOT NULL,
  `tax_regime_code` varchar(5) NOT NULL,
  `fiscal_postal_code` varchar(5) NOT NULL,
  `expedition_postal_code` varchar(5) NOT NULL,
  `country_code` char(3) NOT NULL DEFAULT 'MEX',
  `street` varchar(255) DEFAULT NULL,
  `external_number` varchar(30) DEFAULT NULL,
  `internal_number` varchar(30) DEFAULT NULL,
  `neighborhood` varchar(150) DEFAULT NULL,
  `locality` varchar(150) DEFAULT NULL,
  `municipality` varchar(150) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fiscal_document_issuer` (`fiscal_document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_document_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_document_id` bigint(20) unsigned NOT NULL,
  `invoice_item_id` int(10) unsigned DEFAULT NULL,
  `item_id` int(10) unsigned DEFAULT NULL,
  `line_number` int(10) unsigned NOT NULL,
  `product_service_code` varchar(8) NOT NULL,
  `identification_number` varchar(100) DEFAULT NULL,
  `quantity` decimal(18,6) NOT NULL,
  `unit_code` varchar(5) NOT NULL,
  `unit_name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `unit_value` decimal(18,6) NOT NULL,
  `gross_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `tax_object_code` varchar(3) NOT NULL,
  `taxable_base` decimal(18,2) NOT NULL DEFAULT 0.00,
  `transferred_tax_total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `withheld_tax_total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fiscal_document_line` (`fiscal_document_id`,`line_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_document_item_taxes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_document_item_id` bigint(20) unsigned NOT NULL,
  `administrative_tax_id` int(10) unsigned DEFAULT NULL,
  `tax_code` varchar(3) NOT NULL,
  `tax_type` varchar(20) NOT NULL,
  `factor_type` varchar(10) NOT NULL,
  `rate_or_quota` decimal(18,6) DEFAULT NULL,
  `taxable_base` decimal(18,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fiscal_document_item_id_sort_order` (`fiscal_document_item_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_document_metadata` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_document_id` bigint(20) unsigned NOT NULL,
  `metadata_json` longtext NOT NULL,
  `warnings_json` longtext DEFAULT NULL,
  `rules_version` varchar(30) NOT NULL DEFAULT 'ikontrol-fiscal-draft-v1',
  `payment_total_snapshot` decimal(18,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fiscal_document_metadata` (`fiscal_document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_document_receivers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_document_id` bigint(20) unsigned NOT NULL,
  `rfc` varchar(13) NOT NULL,
  `legal_name` varchar(254) NOT NULL,
  `tax_regime_code` varchar(5) NOT NULL,
  `fiscal_postal_code` varchar(5) NOT NULL,
  `cfdi_use_code` varchar(5) NOT NULL,
  `fiscal_residence_country_code` char(3) DEFAULT NULL,
  `foreign_tax_registration` varchar(40) DEFAULT NULL,
  `street` varchar(255) DEFAULT NULL,
  `external_number` varchar(30) DEFAULT NULL,
  `internal_number` varchar(30) DEFAULT NULL,
  `neighborhood` varchar(150) DEFAULT NULL,
  `locality` varchar(150) DEFAULT NULL,
  `municipality` varchar(150) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fiscal_document_receiver` (`fiscal_document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_document_relations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_document_id` bigint(20) unsigned NOT NULL,
  `related_document_id` bigint(20) unsigned NOT NULL,
  `relation_type` varchar(30) NOT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fiscal_document_relation` (`source_document_id`,`related_document_id`,`relation_type`),
  KEY `source_document_id` (`source_document_id`),
  KEY `related_document_id` (`related_document_id`),
  KEY `relation_type` (`relation_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_document_sales` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_document_id` bigint(20) unsigned NOT NULL,
  `sale_id` bigint(20) unsigned NOT NULL,
  `allocated_subtotal` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `allocated_tax` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `allocated_total` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `allocation_status` varchar(30) NOT NULL DEFAULT 'active',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fiscal_document_sale` (`fiscal_document_id`,`sale_id`),
  KEY `fiscal_document_id` (`fiscal_document_id`),
  KEY `sale_id` (`sale_id`),
  KEY `allocation_status` (`allocation_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_document_signatures` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_document_id` bigint(20) unsigned NOT NULL,
  `pre_xml_artifact_id` bigint(20) unsigned NOT NULL,
  `certificate_id` bigint(20) unsigned NOT NULL,
  `original_chain_artifact_id` bigint(20) unsigned NOT NULL,
  `signed_xml_artifact_id` bigint(20) unsigned NOT NULL,
  `pre_xml_sha256` char(64) NOT NULL,
  `original_chain_sha256` char(64) NOT NULL,
  `signed_xml_sha256` char(64) NOT NULL,
  `signature_verified` tinyint(1) NOT NULL DEFAULT 0,
  `xsd_status` varchar(40) NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fiscal_document_signature` (`fiscal_document_id`,`pre_xml_sha256`,`certificate_id`),
  KEY `idx_fiscal_document_signature` (`fiscal_document_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_document_stamps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_document_id` bigint(20) unsigned NOT NULL,
  `stamp_attempt_id` bigint(20) unsigned NOT NULL,
  `stamped_xml_artifact_id` bigint(20) unsigned NOT NULL,
  `pac_pdf_artifact_id` bigint(20) unsigned DEFAULT NULL,
  `pdf_status` varchar(30) DEFAULT 'pending',
  `pdf_template` varchar(80) DEFAULT NULL,
  `uuid` char(36) NOT NULL,
  `stamp_date` datetime NOT NULL,
  `pac_rfc` varchar(13) NOT NULL,
  `sat_certificate_number` varchar(40) NOT NULL,
  `cfd_seal` text NOT NULL,
  `sat_seal` text NOT NULL,
  `tfd_version` varchar(10) NOT NULL,
  `provider` varchar(40) NOT NULL,
  `environment` varchar(20) NOT NULL,
  `stamped_xml_sha256` char(64) NOT NULL,
  `created_at` datetime NOT NULL,
  `provider_original_chain` mediumtext DEFAULT NULL,
  `sat_original_chain` mediumtext DEFAULT NULL,
  `qr_data` text DEFAULT NULL,
  `auxiliary_warnings` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stamp_document` (`fiscal_document_id`),
  UNIQUE KEY `uq_stamp_uuid` (`uuid`),
  UNIQUE KEY `uq_stamp_artifact` (`stamped_xml_artifact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_document_tax_totals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_document_id` bigint(20) unsigned NOT NULL,
  `tax_code` varchar(3) NOT NULL,
  `tax_type` varchar(20) NOT NULL,
  `factor_type` varchar(10) NOT NULL,
  `rate_or_quota` decimal(18,6) DEFAULT NULL,
  `taxable_base` decimal(18,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fiscal_document_tax_total` (`fiscal_document_id`,`tax_code`,`tax_type`,`factor_type`,`rate_or_quota`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_drafts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_document_id` bigint(20) unsigned DEFAULT NULL,
  `issuer_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `document_type` char(1) NOT NULL DEFAULT 'I',
  `provisional_series` varchar(25) NOT NULL DEFAULT '',
  `issue_date` datetime NOT NULL,
  `currency_code` char(3) NOT NULL DEFAULT 'MXN',
  `exchange_rate` decimal(18,6) NOT NULL DEFAULT 1.000000,
  `payment_form_code` varchar(3) DEFAULT NULL,
  `payment_method_code` varchar(3) DEFAULT NULL,
  `cfdi_use_code` varchar(5) NOT NULL,
  `receiver_tax_regime_code` varchar(5) NOT NULL,
  `receiver_postal_code` varchar(5) NOT NULL,
  `expedition_postal_code` varchar(5) NOT NULL,
  `subtotal` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `discount` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `tax_total` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `total` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `fiscal_payload` longtext NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `receiver_profile_id` bigint(20) unsigned DEFAULT NULL,
  `fiscal_series_id` bigint(20) unsigned DEFAULT NULL,
  `conditions` varchar(255) DEFAULT NULL,
  `observations` text DEFAULT NULL,
  `discarded_reason` varchar(500) DEFAULT NULL,
  `discarded_at` datetime DEFAULT NULL,
  `ready_at` datetime DEFAULT NULL,
  `snapshot_version` int(11) DEFAULT 1,
  `requires_snapshot_refresh` tinyint(1) DEFAULT 0,
  `snapshot_completed_at` datetime DEFAULT NULL,
  `environment` varchar(20) DEFAULT 'legacy',
  `data_origin` varchar(30) DEFAULT 'operational',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fiscal_draft_document` (`fiscal_document_id`),
  KEY `issuer_id_status` (`issuer_id`,`status`),
  KEY `customer_id_status` (`customer_id`,`status`),
  KEY `issue_date` (`issue_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_draft_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_draft_id` bigint(20) unsigned DEFAULT NULL,
  `sale_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `event` varchar(50) NOT NULL,
  `summary_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fiscal_draft_id` (`fiscal_draft_id`),
  KEY `sale_id` (`sale_id`),
  KEY `event` (`event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_draft_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_draft_id` bigint(20) unsigned NOT NULL,
  `sale_id` bigint(20) unsigned NOT NULL,
  `sale_item_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` decimal(18,6) NOT NULL,
  `unit_price` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `discount` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `subtotal` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `tax` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `total` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `fiscal_snapshot` longtext NOT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fiscal_draft_sale_item` (`fiscal_draft_id`,`sale_item_id`),
  KEY `fiscal_draft_id` (`fiscal_draft_id`),
  KEY `sale_id` (`sale_id`),
  KEY `sale_item_id` (`sale_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_draft_item_taxes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_draft_id` bigint(20) unsigned NOT NULL,
  `fiscal_draft_item_id` bigint(20) unsigned NOT NULL,
  `sale_id` bigint(20) unsigned DEFAULT NULL,
  `sale_item_id` bigint(20) unsigned DEFAULT NULL,
  `tax_type` varchar(20) NOT NULL,
  `tax_code` varchar(10) NOT NULL,
  `factor_type` varchar(20) NOT NULL,
  `rate_or_quota` decimal(18,6) DEFAULT NULL,
  `tax_base` decimal(18,6) NOT NULL,
  `tax_amount` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `is_exempt` tinyint(1) NOT NULL DEFAULT 0,
  `calculation_order` int(11) NOT NULL DEFAULT 0,
  `source` varchar(30) NOT NULL DEFAULT 'snapshot',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_draft_item_tax` (`fiscal_draft_item_id`,`tax_type`,`tax_code`,`factor_type`,`rate_or_quota`),
  KEY `fiscal_draft_id` (`fiscal_draft_id`),
  KEY `fiscal_draft_item_id` (`fiscal_draft_item_id`),
  KEY `sale_id` (`sale_id`),
  KEY `sale_item_id` (`sale_item_id`),
  KEY `tax_type` (`tax_type`),
  KEY `tax_code` (`tax_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_draft_sales` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_draft_id` bigint(20) unsigned NOT NULL,
  `sale_id` bigint(20) unsigned NOT NULL,
  `allocated_subtotal` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `allocated_tax` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `allocated_total` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `allocation_status` varchar(30) NOT NULL DEFAULT 'reserved',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fiscal_draft_sale` (`fiscal_draft_id`,`sale_id`),
  KEY `fiscal_draft_id` (`fiscal_draft_id`),
  KEY `sale_id` (`sale_id`),
  KEY `allocation_status` (`allocation_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_issuer_certificates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `issuer_profile_id` int(10) unsigned NOT NULL,
  `certificate_number` varchar(40) NOT NULL,
  `certificate_serial_hex` varchar(128) DEFAULT NULL,
  `certificate_subject` varchar(500) NOT NULL,
  `certificate_rfc` varchar(13) NOT NULL,
  `valid_from` datetime NOT NULL,
  `valid_to` datetime NOT NULL,
  `certificate_sha256` char(64) NOT NULL,
  `public_certificate_path` varchar(255) NOT NULL,
  `encrypted_private_key_path` varchar(255) NOT NULL,
  `private_key_sha256` char(64) NOT NULL,
  `encryption_key_version` varchar(20) NOT NULL DEFAULT 'password-v1',
  `status` varchar(30) NOT NULL DEFAULT 'pending_validation',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_issuer_certificate_hash` (`issuer_profile_id`,`certificate_sha256`),
  KEY `idx_issuer_certificate_status` (`issuer_profile_id`,`status`,`is_default`,`deleted`),
  KEY `idx_issuer_certificate_validity` (`valid_from`,`valid_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_issuer_certificate_secrets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_issuer_certificate_id` bigint(20) unsigned NOT NULL,
  `secret_type` varchar(40) NOT NULL DEFAULT 'private_key_password',
  `encrypted_payload` longtext NOT NULL,
  `encryption_version` varchar(30) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `validated_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `rotated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_csd_certificate_secret_type` (`fiscal_issuer_certificate_id`,`secret_type`),
  KEY `fiscal_issuer_certificate_id_status` (`fiscal_issuer_certificate_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_issuer_certificate_secret_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_issuer_certificate_id` bigint(20) unsigned NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `result` varchar(20) NOT NULL,
  `error_code` varchar(60) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fiscal_issuer_certificate_id_created_at` (`fiscal_issuer_certificate_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_issuer_pdf_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `issuer_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(40) NOT NULL,
  `document_type` char(1) NOT NULL,
  `template_code` varchar(40) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fiscal_pdf_template` (`issuer_id`,`provider`,`document_type`),
  KEY `issuer_id` (`issuer_id`),
  KEY `provider` (`provider`),
  KEY `document_type` (`document_type`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_pac_configurations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(40) NOT NULL,
  `environment` varchar(20) NOT NULL,
  `base_url` varchar(255) NOT NULL,
  `encrypted_api_key` text NOT NULL,
  `api_key_last_four` varchar(4) NOT NULL,
  `connection_timeout_seconds` int(10) unsigned NOT NULL DEFAULT 10,
  `request_timeout_seconds` int(10) unsigned NOT NULL DEFAULT 45,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `last_tested_at` datetime DEFAULT NULL,
  `last_test_status` varchar(30) DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pac_provider_environment` (`provider`,`environment`),
  KEY `idx_pac_default` (`environment`,`is_active`,`is_default`,`deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_pac_credit_consultations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `issuer_profile_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(40) NOT NULL,
  `environment` varchar(20) NOT NULL,
  `available_credits` int(10) unsigned NOT NULL,
  `provider_code` varchar(50) DEFAULT NULL,
  `provider_message` varchar(500) DEFAULT NULL,
  `http_status` smallint(5) unsigned DEFAULT NULL,
  `response_sha256` char(64) NOT NULL,
  `consulted_at` datetime NOT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `issuer_profile_id` (`issuer_profile_id`),
  KEY `environment` (`environment`),
  KEY `consulted_at` (`consulted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_pac_credit_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(50) NOT NULL,
  `environment` varchar(20) NOT NULL,
  `available_credits` int(10) unsigned NOT NULL,
  `consulted_at` datetime NOT NULL,
  `provider_code` varchar(30) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `provider_environment_consulted_at` (`provider`,`environment`,`consulted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_payment_method_mappings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_method_id` int(10) unsigned NOT NULL,
  `sat_payment_form_code` varchar(3) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fiscal_payment_method_mapping` (`payment_method_id`),
  KEY `idx_fiscal_payment_form_mapping` (`sat_payment_form_code`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_pdf_generation_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned NOT NULL,
  `stamp_id` bigint(20) unsigned NOT NULL,
  `stamp_attempt_id` bigint(20) unsigned NOT NULL,
  `uuid` char(36) NOT NULL,
  `provider` varchar(40) NOT NULL,
  `environment` varchar(20) NOT NULL,
  `template_code` varchar(40) NOT NULL,
  `status` varchar(30) NOT NULL,
  `provider_code` varchar(50) DEFAULT NULL,
  `provider_message` text DEFAULT NULL,
  `request_sent` tinyint(1) NOT NULL DEFAULT 0,
  `retryable` tinyint(1) NOT NULL DEFAULT 0,
  `requires_reconciliation` tinyint(1) NOT NULL DEFAULT 0,
  `idempotency_key` char(64) NOT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fiscal_pdf_attempt_idempotency` (`idempotency_key`),
  KEY `document_id` (`document_id`),
  KEY `stamp_id` (`stamp_id`),
  KEY `stamp_attempt_id` (`stamp_attempt_id`),
  KEY `uuid` (`uuid`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_pdf_template_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `template_id` bigint(20) unsigned NOT NULL,
  `issuer_id` bigint(20) unsigned NOT NULL,
  `action` varchar(40) NOT NULL,
  `old_template_code` varchar(40) DEFAULT NULL,
  `new_template_code` varchar(40) DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `template_id` (`template_id`),
  KEY `issuer_id` (`issuer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_profiles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `profile_type` varchar(20) NOT NULL DEFAULT 'receiver',
  `client_id` int(10) unsigned DEFAULT NULL,
  `company_id` int(10) unsigned DEFAULT NULL,
  `rfc` varchar(13) DEFAULT NULL,
  `legal_name` varchar(254) DEFAULT NULL,
  `tax_regime_id` int(10) unsigned DEFAULT NULL,
  `fiscal_postal_code` varchar(5) DEFAULT NULL,
  `default_cfdi_use_id` int(10) unsigned DEFAULT NULL,
  `tax_residency_country` char(3) DEFAULT NULL,
  `foreign_tax_registration` varchar(40) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `fiscal_street` varchar(255) DEFAULT NULL,
  `fiscal_external_number` varchar(40) DEFAULT NULL,
  `fiscal_internal_number` varchar(40) DEFAULT NULL,
  `fiscal_neighborhood` varchar(180) DEFAULT NULL,
  `fiscal_locality` varchar(180) DEFAULT NULL,
  `fiscal_municipality` varchar(180) DEFAULT NULL,
  `fiscal_state` varchar(180) DEFAULT NULL,
  `fiscal_country_code` char(3) DEFAULT NULL,
  `fiscal_address_reference` varchar(500) DEFAULT NULL,
  `trade_name` varchar(254) DEFAULT NULL,
  `expedition_postal_code` varchar(5) DEFAULT NULL,
  `email` varchar(254) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `tax_pricing_mode` varchar(20) DEFAULT NULL,
  `allow_sale_tax_pricing_override` tinyint(1) DEFAULT 0,
  `environment` varchar(20) DEFAULT 'legacy',
  PRIMARY KEY (`id`),
  KEY `client_id_profile_type_status` (`client_id`,`profile_type`,`status`),
  KEY `client_id_is_default` (`client_id`,`is_default`),
  KEY `tax_regime_id` (`tax_regime_id`),
  KEY `default_cfdi_use_id` (`default_cfdi_use_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_series` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `issuer_profile_id` int(10) unsigned NOT NULL,
  `document_type` varchar(20) NOT NULL,
  `series` varchar(25) NOT NULL DEFAULT '',
  `initial_folio` bigint(20) unsigned NOT NULL DEFAULT 1,
  `current_folio` bigint(20) unsigned NOT NULL DEFAULT 0,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `environment` varchar(20) DEFAULT 'legacy',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fiscal_series_issuer_type_series` (`issuer_profile_id`,`document_type`,`series`),
  KEY `idx_fiscal_series_default` (`issuer_profile_id`,`document_type`,`is_default`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_stamp_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `issuer_profile_id` bigint(20) unsigned NOT NULL,
  `environment` varchar(20) DEFAULT 'development',
  `available_balance` int(10) unsigned NOT NULL DEFAULT 0,
  `reserved_balance` int(10) unsigned NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stamp_account_issuer_environment` (`issuer_profile_id`,`environment`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_stamp_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_document_id` bigint(20) unsigned NOT NULL,
  `signed_xml_artifact_id` bigint(20) unsigned NOT NULL,
  `pac_configuration_id` bigint(20) unsigned DEFAULT NULL,
  `provider` varchar(40) NOT NULL,
  `environment` varchar(20) NOT NULL,
  `operation` varchar(30) NOT NULL DEFAULT 'timbrar',
  `pac_endpoint` varchar(255) DEFAULT NULL,
  `request_hash` char(64) NOT NULL,
  `idempotency_key` char(64) NOT NULL,
  `attempt_number` int(10) unsigned NOT NULL DEFAULT 1,
  `status` varchar(40) NOT NULL DEFAULT 'pending',
  `started_at` datetime NOT NULL,
  `sent_at` datetime DEFAULT NULL,
  `transport_opened_at` datetime DEFAULT NULL,
  `request_sent` tinyint(1) DEFAULT NULL,
  `request_sent_confirmed_at` datetime DEFAULT NULL,
  `response_received_at` datetime DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `http_status` int(10) unsigned DEFAULT NULL,
  `provider_code` varchar(50) DEFAULT NULL,
  `provider_message` varchar(500) DEFAULT NULL,
  `error_category` varchar(50) DEFAULT NULL,
  `retryable` tinyint(1) NOT NULL DEFAULT 0,
  `pac_reference` varchar(120) DEFAULT NULL,
  `uuid` char(36) DEFAULT NULL,
  `response_hash` char(64) DEFAULT NULL,
  `contingency_path` varchar(255) DEFAULT NULL,
  `duration_ms` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `recommended_action` varchar(500) DEFAULT NULL,
  `requires_reconciliation` tinyint(1) DEFAULT 0,
  `response_content_type` varchar(160) DEFAULT NULL,
  `response_body_length` bigint(20) unsigned DEFAULT NULL,
  `response_body_sha256` char(64) DEFAULT NULL,
  `parsing_phase` varchar(40) DEFAULT NULL,
  `response_error_class` varchar(160) DEFAULT NULL,
  `response_error_message` varchar(500) DEFAULT NULL,
  `response_structure` text DEFAULT NULL,
  `reconciled_at` datetime DEFAULT NULL,
  `reconciled_by` bigint(20) unsigned DEFAULT NULL,
  `reconciliation_source` varchar(80) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stamp_idempotency` (`idempotency_key`),
  KEY `idx_stamp_document_status` (`fiscal_document_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}fiscal_stamp_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stamp_account_id` bigint(20) unsigned NOT NULL,
  `environment` varchar(20) DEFAULT 'development',
  `movement_type` varchar(50) NOT NULL,
  `quantity` int(11) NOT NULL,
  `available_before` int(10) unsigned NOT NULL,
  `available_after` int(10) unsigned NOT NULL,
  `reserved_before` int(10) unsigned NOT NULL,
  `reserved_after` int(10) unsigned NOT NULL,
  `fiscal_document_id` bigint(20) unsigned DEFAULT NULL,
  `pac_attempt_id` bigint(20) unsigned DEFAULT NULL,
  `fiscal_document_stamp_id` bigint(20) unsigned DEFAULT NULL,
  `fiscal_cancellation_request_id` bigint(20) unsigned DEFAULT NULL,
  `idempotency_key` varchar(191) NOT NULL,
  `consumption_key` varchar(191) DEFAULT NULL,
  `reason` text NOT NULL,
  `reference` varchar(191) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stamp_movement_idempotency` (`idempotency_key`),
  UNIQUE KEY `uq_stamp_consumption` (`consumption_key`),
  KEY `stamp_account_id` (`stamp_account_id`),
  KEY `fiscal_document_id` (`fiscal_document_id`),
  KEY `pac_attempt_id` (`pac_attempt_id`),
  KEY `fiscal_document_stamp_id` (`fiscal_document_stamp_id`),
  KEY `fiscal_cancellation_request_id` (`fiscal_cancellation_request_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}item_fiscal_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `item_id` int(10) unsigned NOT NULL,
  `item_type` varchar(20) NOT NULL,
  `sat_product_service_key_id` int(10) unsigned DEFAULT NULL,
  `sat_unit_key_id` int(10) unsigned DEFAULT NULL,
  `commercial_unit` varchar(120) DEFAULT NULL,
  `tax_object_code_id` int(10) unsigned DEFAULT NULL,
  `fiscal_description` text DEFAULT NULL,
  `identification_number` varchar(100) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 1,
  `status` varchar(20) NOT NULL DEFAULT 'incomplete',
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `item_id_deleted_is_default` (`item_id`,`deleted`,`is_default`),
  KEY `sat_product_service_key_id` (`sat_product_service_key_id`),
  KEY `sat_unit_key_id` (`sat_unit_key_id`),
  KEY `tax_object_code_id` (`tax_object_code_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}item_fiscal_taxes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `item_fiscal_setting_id` int(10) unsigned NOT NULL,
  `tax_id` int(10) unsigned NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_fiscal_setting_id_tax_id` (`item_fiscal_setting_id`,`tax_id`),
  KEY `item_fiscal_setting_id_is_active` (`item_fiscal_setting_id`,`is_active`),
  KEY `tax_id` (`tax_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}payment_complements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `issuer_profile_id` int(11) DEFAULT NULL,
  `fiscal_document_id` bigint(20) unsigned DEFAULT NULL,
  `issue_date` datetime NOT NULL,
  `stamped_at` datetime DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(10) unsigned DEFAULT NULL,
  `cancellation_reason` varchar(500) DEFAULT NULL,
  `last_stamp_error` text DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_complement_fiscal_document` (`fiscal_document_id`),
  KEY `idx_pc_status_active` (`status`,`deleted`),
  KEY `idx_pc_client_active` (`client_id`,`deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}payment_complement_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `payment_complement_payment_id` int(10) unsigned NOT NULL,
  `payment_allocation_id` int(10) unsigned DEFAULT NULL,
  `allocation_origin` varchar(24) DEFAULT 'preexisting',
  `allocation_amount_before` decimal(18,6) DEFAULT 0.000000,
  `allocation_amount_after` decimal(18,6) DEFAULT 0.000000,
  `invoice_id` int(11) NOT NULL,
  `fiscal_document_id` bigint(20) unsigned NOT NULL,
  `document_uuid` varchar(36) NOT NULL,
  `currency_dr` varchar(3) DEFAULT NULL,
  `equivalence_dr` decimal(18,6) DEFAULT NULL,
  `installment_number` int(11) NOT NULL,
  `previous_balance` decimal(18,6) NOT NULL,
  `amount_paid` decimal(18,6) NOT NULL,
  `remaining_balance` decimal(18,6) NOT NULL,
  `tax_object_code` varchar(3) DEFAULT NULL,
  `snapshot_json` longtext DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_pc_document_payment` (`payment_complement_payment_id`),
  KEY `fk_pc_document_sale` (`invoice_id`),
  KEY `idx_pc_allocation_document` (`payment_allocation_id`,`fiscal_document_id`,`deleted`),
  KEY `idx_pc_document_fiscal_active` (`fiscal_document_id`,`deleted`),
  KEY `idx_pc_allocation_origin` (`payment_allocation_id`,`allocation_origin`,`deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}payment_complement_external_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `payment_complement_id` int(10) unsigned NOT NULL,
  `payment_complement_payment_id` int(10) unsigned NOT NULL,
  `uuid` char(36) NOT NULL,
  `series` varchar(25) NOT NULL DEFAULT '',
  `folio` varchar(40) NOT NULL DEFAULT '',
  `currency_code` char(3) NOT NULL,
  `equivalence_dr` decimal(28,10) NOT NULL,
  `payment_method_code` varchar(3) NOT NULL DEFAULT 'PPD',
  `tax_object_code` char(2) NOT NULL,
  `installment_number` int(10) unsigned NOT NULL,
  `previous_balance` decimal(18,6) NOT NULL,
  `paid_amount` decimal(18,6) NOT NULL,
  `remaining_balance` decimal(18,6) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  `active_uuid` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pc_external_active_uuid` (`payment_complement_id`,`active_uuid`),
  KEY `idx_pc_external_complement_active` (`payment_complement_id`,`deleted`),
  KEY `idx_pc_external_payment_active` (`payment_complement_payment_id`,`deleted`),
  KEY `idx_pc_external_uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}payment_complement_external_taxes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `external_document_id` int(10) unsigned NOT NULL,
  `tax_type` varchar(15) NOT NULL,
  `base` decimal(18,6) NOT NULL,
  `tax_code` char(3) NOT NULL,
  `factor_type` varchar(10) NOT NULL,
  `rate_or_quota` decimal(18,6) DEFAULT NULL,
  `amount` decimal(18,6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pc_external_tax_document` (`external_document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}payment_complement_fiscal_snapshots` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `payment_complement_id` int(10) unsigned NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `status` varchar(30) NOT NULL DEFAULT 'preview',
  `payload_json` longtext NOT NULL,
  `xml_content` longtext DEFAULT NULL,
  `sha256` varchar(64) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `payment_complement_id_version` (`payment_complement_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}payment_complement_payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `payment_complement_id` int(10) unsigned NOT NULL,
  `source_invoice_payment_id` int(11) DEFAULT NULL,
  `payment_date` date NOT NULL,
  `payment_form_code` varchar(3) DEFAULT NULL,
  `currency_code` varchar(3) NOT NULL DEFAULT 'MXN',
  `exchange_rate` decimal(18,6) NOT NULL DEFAULT 1.000000,
  `amount` decimal(18,6) NOT NULL,
  `operation_number` varchar(100) DEFAULT NULL,
  `ordering_bank_rfc` varchar(13) DEFAULT NULL,
  `ordering_bank_name` varchar(150) DEFAULT NULL,
  `ordering_account` varchar(100) DEFAULT NULL,
  `beneficiary_bank_rfc` varchar(13) DEFAULT NULL,
  `beneficiary_account` varchar(100) DEFAULT NULL,
  `payment_chain_type` varchar(2) DEFAULT NULL,
  `payment_certificate` text DEFAULT NULL,
  `payment_chain` text DEFAULT NULL,
  `payment_signature` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_pc_source_payment_active` (`source_invoice_payment_id`,`deleted`),
  KEY `idx_pc_payment_header_active` (`payment_complement_id`,`deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}sat_cfdi_uses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `description` varchar(255) NOT NULL,
  `applies_to_individual` tinyint(1) NOT NULL DEFAULT 0,
  `applies_to_company` tinyint(1) NOT NULL DEFAULT 0,
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `valid_from_valid_to` (`valid_from`,`valid_to`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}sat_currencies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` char(3) NOT NULL,
  `name` varchar(120) NOT NULL,
  `requires_exchange_rate` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sat_currencies_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}sat_payment_forms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(3) NOT NULL,
  `name` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sat_payment_forms_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}sat_payment_methods` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(3) NOT NULL,
  `name` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sat_payment_methods_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}sat_product_service_keys` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(8) NOT NULL,
  `description` varchar(500) NOT NULL,
  `normalized_description` varchar(600) DEFAULT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `source_version` varchar(80) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `description` (`description`),
  KEY `idx_sat_active_code` (`is_active`,`code`),
  KEY `idx_sat_active_normalized_description` (`is_active`,`normalized_description`),
  KEY `is_active_valid_from_valid_to` (`is_active`,`valid_from`,`valid_to`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}sat_tax_codes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(3) NOT NULL,
  `name` varchar(30) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}sat_tax_factor_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(30) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}sat_tax_object_codes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(2) NOT NULL,
  `description` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}sat_tax_regimes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `description` varchar(255) NOT NULL,
  `applies_to_individual` tinyint(1) NOT NULL DEFAULT 0,
  `applies_to_company` tinyint(1) NOT NULL DEFAULT 0,
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `valid_from_valid_to` (`valid_from`,`valid_to`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}sat_unit_keys` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `normalized_description` varchar(600) DEFAULT NULL,
  `symbol` varchar(30) DEFAULT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `source_version` varchar(80) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `name` (`name`),
  KEY `idx_sat_active_code` (`is_active`,`code`),
  KEY `idx_sat_active_normalized_description` (`is_active`,`normalized_description`),
  KEY `is_active_valid_from_valid_to` (`is_active`,`valid_from`,`valid_to`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}sat_catalog_installations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `catalog_name` varchar(80) NOT NULL,
  `source` varchar(255) NOT NULL,
  `source_version` varchar(120) NOT NULL,
  `source_checksum` char(64) NOT NULL,
  `source_generated_at` datetime DEFAULT NULL,
  `installed_at` datetime NOT NULL,
  `row_count` int unsigned NOT NULL,
  `active_row_count` int unsigned NOT NULL,
  `metadata_json` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sat_catalog_installations_name` (`catalog_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}sale_fiscal_pricing_preparations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int unsigned NOT NULL,
  `issuer_profile_id` int unsigned NOT NULL,
  `receiver_profile_id` int unsigned DEFAULT NULL,
  `fiscal_series_id` int unsigned DEFAULT NULL,
  `pricing_mode` varchar(20) NOT NULL,
  `administrative_subtotal` decimal(18,2) NOT NULL DEFAULT 0.00,
  `administrative_discount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `administrative_tax_total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `administrative_total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `estimated_fiscal_base` decimal(18,2) NOT NULL DEFAULT 0.00,
  `estimated_fiscal_discount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `estimated_fiscal_tax_total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `estimated_fiscal_total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `difference_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `previous_balance` decimal(18,2) NOT NULL DEFAULT 0.00,
  `estimated_balance` decimal(18,2) NOT NULL DEFAULT 0.00,
  `payment_total_snapshot` decimal(18,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'simulated',
  `requires_confirmation` tinyint(1) NOT NULL DEFAULT 0,
  `confirmed_by` int unsigned DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `applied_to_sale` tinyint(1) NOT NULL DEFAULT 0,
  `applied_by` int unsigned DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  `calculation_payload` longtext DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sale_fiscal_pricing_invoice_status` (`invoice_id`,`status`),
  KEY `idx_sale_fiscal_pricing_issuer_created` (`issuer_profile_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
