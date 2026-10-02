CREATE TABLE IF NOT EXISTS `{{prefix}}product_supplier_cost_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_type` varchar(20) DEFAULT NULL,
  `source_id` int(11) DEFAULT NULL,
  `source_item_id` int(11) DEFAULT NULL,
  `source_folio` varchar(80) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `supplier_id` int(10) unsigned NOT NULL,
  `proposal_id` int(11) DEFAULT NULL,
  `proposal_item_id` int(11) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `unit_cost` decimal(18,6) NOT NULL,
  `sale_unit_price` decimal(18,6) DEFAULT NULL,
  `quantity` decimal(18,6) DEFAULT NULL,
  `currency` char(3) NOT NULL DEFAULT 'MXN',
  `quoted_at` datetime DEFAULT NULL,
  `recorded_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `source_status` varchar(20) NOT NULL,
  `snapshot_version` int(10) unsigned NOT NULL DEFAULT 1,
  `economic_hash` char(64) NOT NULL,
  `notes` text DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `idempotency_key` char(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cost_history_source_economic` (`source_type`,`source_item_id`,`economic_hash`),
  UNIQUE KEY `uq_cost_history_manual_idempotency` (`idempotency_key`),
  KEY `fk_cost_history_client` (`client_id`),
  KEY `product_id_quoted_at` (`product_id`,`quoted_at`),
  KEY `supplier_id_quoted_at` (`supplier_id`,`quoted_at`),
  KEY `proposal_id_source_status` (`proposal_id`,`source_status`),
  KEY `idx_cost_history_product_supplier_date` (`product_id`,`supplier_id`,`quoted_at`),
  KEY `idx_cost_history_source` (`source_type`,`source_id`,`source_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}suppliers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(180) NOT NULL,
  `rfc` varchar(13) DEFAULT NULL,
  `normalized_name` varchar(180) NOT NULL DEFAULT '',
  `contact_name` varchar(150) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `email` varchar(180) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_supplier_name_active` (`name`,`deleted`),
  UNIQUE KEY `uq_suppliers_rfc` (`rfc`),
  KEY `status_deleted` (`status`,`deleted`),
  KEY `idx_suppliers_normalized_name` (`normalized_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}warehouses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(180) NOT NULL,
  `code` varchar(40) NOT NULL,
  `address` text DEFAULT NULL,
  `responsible_name` varchar(180) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `status_deleted` (`status`,`deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}warehouse_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `folio` varchar(30) DEFAULT NULL,
  `movement_type` varchar(20) NOT NULL,
  `warehouse_id` int(10) unsigned NOT NULL,
  `movement_date` datetime NOT NULL,
  `reference_type` varchar(40) DEFAULT NULL,
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `reference_text` varchar(220) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `created_by` int(10) unsigned DEFAULT NULL,
  `confirmed_by` int(10) unsigned DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `cancelled_by` int(10) unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `folio` (`folio`),
  KEY `warehouse_id_status_movement_date` (`warehouse_id`,`status`,`movement_date`),
  KEY `movement_type_status_movement_date` (`movement_type`,`status`,`movement_date`),
  KEY `reference_type_reference_id` (`reference_type`,`reference_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}warehouse_products` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(220) NOT NULL,
  `description` text DEFAULT NULL,
  `internal_code` varchar(100) NOT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `label_logo` varchar(255) DEFAULT NULL,
  `control_unit` varchar(40) NOT NULL,
  `category` varchar(120) DEFAULT NULL,
  `brand` varchar(120) DEFAULT NULL,
  `model` varchar(120) DEFAULT NULL,
  `variant` varchar(120) DEFAULT NULL,
  `size` varchar(80) DEFAULT NULL,
  `color` varchar(80) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `internal_code` (`internal_code`),
  UNIQUE KEY `barcode` (`barcode`),
  KEY `name_status_deleted` (`name`,`status`,`deleted`),
  KEY `category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `{{prefix}}warehouse_transfers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `folio` varchar(30) DEFAULT NULL,
  `origin_warehouse_id` int(10) unsigned NOT NULL,
  `destination_warehouse_id` int(10) unsigned NOT NULL,
  `transfer_date` datetime NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `carrier` varchar(120) DEFAULT NULL,
  `tracking_number` varchar(120) DEFAULT NULL,
  `tracking_created_at` datetime DEFAULT NULL,
  `picked_up_at` datetime DEFAULT NULL,
  `dispatched_at` datetime DEFAULT NULL,
  `estimated_arrival_at` datetime DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `reference_type` varchar(40) DEFAULT NULL,
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `reference_text` varchar(220) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `dispatch_movement_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `folio` (`folio`),
  KEY `fk_wt_dispatch_movement` (`dispatch_movement_id`),
  KEY `origin_warehouse_id_status_transfer_date` (`origin_warehouse_id`,`status`,`transfer_date`),
  KEY `destination_warehouse_id_status_transfer_date` (`destination_warehouse_id`,`status`,`transfer_date`),
  KEY `tracking_number` (`tracking_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
