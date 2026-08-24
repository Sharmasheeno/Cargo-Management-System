
-- TABLES


DROP TABLE IF EXISTS `assignments`;
CREATE TABLE `assignments` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `trip_id` int(11) DEFAULT NULL,
  `assigned_to_driver_id` int(11) DEFAULT NULL,
  `assigned_to_loader_id` int(11) DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `task_type` varchar(100) DEFAULT NULL,
  `task_description` text DEFAULT NULL,
  `status` enum('pending','in_progress','completed','cancelled') DEFAULT 'pending',
  `priority` int(11) DEFAULT 1,
  `due_date` date DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `completed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) DEFAULT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `bank_accounts`;
CREATE TABLE `bank_accounts` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `account_name` varchar(255) NOT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `account_number` varchar(100) DEFAULT NULL,
  `account_type` varchar(50) DEFAULT NULL,
  `currency` varchar(3) DEFAULT 'USD',
  `opening_balance` decimal(15,2) DEFAULT 0.00,
  `current_balance` decimal(15,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `bank_reconciliations`;
CREATE TABLE `bank_reconciliations` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `bank_account_id` int(11) NOT NULL,
  `reconciliation_date` date NOT NULL,
  `statement_ending_balance` decimal(15,2) NOT NULL,
  `statement_start_date` date NOT NULL,
  `statement_end_date` date NOT NULL,
  `book_balance` decimal(15,2) NOT NULL,
  `difference_amount` decimal(15,2) NOT NULL,
  `is_reconciled` tinyint(1) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `bank_transactions`;
CREATE TABLE `bank_transactions` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `bank_account_id` int(11) DEFAULT NULL,
  `transaction_date` date DEFAULT curdate(),
  `transaction_type` enum('income','expense','transfer') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` text DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reconciled` tinyint(1) DEFAULT 0,
  `reconciled_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `bill_payments`;
CREATE TABLE `bill_payments` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `bill_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_method` enum('cash','bank_transfer','check','credit_card','other') DEFAULT 'cash',
  `reference_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `branches`;
CREATE TABLE `branches` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `branch_code` varchar(50) NOT NULL,
  `branch_name` varchar(255) NOT NULL,
  `branch_type` enum('main','warehouse','office','store','customs','port') DEFAULT 'office',
  `address` text DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `manager_name` varchar(255) DEFAULT NULL,
  `manager_phone` varchar(50) DEFAULT NULL,
  `location_lat` decimal(10,8) DEFAULT NULL,
  `location_lng` decimal(11,8) DEFAULT NULL,
  `opening_time` time DEFAULT NULL,
  `closing_time` time DEFAULT NULL,
  `max_capacity_cbm` decimal(15,2) DEFAULT 0.00,
  `current_used_cbm` decimal(15,2) DEFAULT 0.00,
  `status` enum('active','inactive','temporary_closed','permanently_closed') DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `branch_activity_logs`;
CREATE TABLE `branch_activity_logs` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `branch_stock`;
CREATE TABLE `branch_stock` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `warehouse_stock_id` int(11) NOT NULL,
  `quantity` int(11) DEFAULT 0,
  `reserved_quantity` int(11) DEFAULT 0,
  `location_in_branch` varchar(255) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `branch_transfers`;
CREATE TABLE `branch_transfers` (
  `id` int(11) NOT NULL,
  `transfer_number` varchar(100) NOT NULL,
  `from_branch_id` int(11) NOT NULL,
  `to_branch_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `transfer_type` enum('stock_transfer','staff_transfer','vehicle_transfer') DEFAULT 'stock_transfer',
  `status` enum('pending','approved','in_transit','completed','cancelled','rejected') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `branch_transfer_items`;
CREATE TABLE `branch_transfer_items` (
  `id` int(11) NOT NULL,
  `transfer_id` int(11) NOT NULL,
  `warehouse_stock_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `transferred_quantity` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `bulk_sms_campaigns`;
CREATE TABLE `bulk_sms_campaigns` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `campaign_name` varchar(255) NOT NULL,
  `message_text` text NOT NULL,
  `total_recipients` int(11) DEFAULT 0,
  `total_sent` int(11) DEFAULT 0,
  `total_delivered` int(11) DEFAULT 0,
  `total_failed` int(11) DEFAULT 0,
  `status` varchar(50) DEFAULT 'pending',
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `bulk_sms_recipients`;
CREATE TABLE `bulk_sms_recipients` (
  `id` int(11) NOT NULL,
  `campaign_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `phone_number` varchar(50) NOT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `sent_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `cargo_manifest_items`;
CREATE TABLE `cargo_manifest_items` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `container_id` int(11) NOT NULL,
  `shipment_id` int(11) DEFAULT NULL,
  `warehouse_stock_id` int(11) DEFAULT NULL,
  `stock_name` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL,
  `cbm_used` decimal(10,4) DEFAULT 0.0000,
  `invoice_id` int(11) DEFAULT NULL,
  `added_at` datetime DEFAULT current_timestamp(),
  `mogadishu_status` enum('not_arrived','in_warehouse','taken','delivered') NOT NULL DEFAULT 'not_arrived',
  `mogadishu_received_date` datetime DEFAULT NULL,
  `mogadishu_taken_date` datetime DEFAULT NULL,
  `points_used_at_delivery` decimal(12,2) DEFAULT 0.00,
  `discount_applied` decimal(15,2) DEFAULT 0.00,
  `storage_fee` decimal(15,2) DEFAULT 0.00,
  `weight_kg` decimal(15,2) DEFAULT 0.00,
  `unit_price` decimal(15,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `cash_flow`;
CREATE TABLE `cash_flow` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `flow_date` date NOT NULL,
  `inflow` decimal(15,2) DEFAULT 0.00,
  `outflow` decimal(15,2) DEFAULT 0.00,
  `net_flow` decimal(15,2) DEFAULT 0.00,
  `balance` decimal(15,2) DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `category` varchar(100) DEFAULT 'Other'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `chart_of_accounts`;
CREATE TABLE `chart_of_accounts` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `account_code` varchar(20) NOT NULL,
  `account_name` varchar(255) NOT NULL,
  `account_type` enum('asset','liability','equity','revenue','expense') DEFAULT 'asset',
  `balance` decimal(15,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `containers`;
CREATE TABLE `containers` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `container_number` varchar(100) NOT NULL,
  `container_type` enum('20ft','40ft','40hc','lcl') DEFAULT '20ft',
  `size_cbm` decimal(10,2) DEFAULT NULL,
  `origin` enum('china_yiwu','china_guangzhou','dubai','local') NOT NULL DEFAULT 'china_yiwu',
  `status` enum('received','loading','loaded','shipped','dispatched','at_port','ready','delivered') DEFAULT 'received',
  `received_date` date DEFAULT NULL,
  `shipped_date` date DEFAULT NULL,
  `delivered_date` date DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `weight_kg` decimal(15,2) DEFAULT 0.00,
  `current_location` varchar(255) DEFAULT NULL,
  `arrival_date` date DEFAULT NULL,
  `departure_date` date DEFAULT NULL,
  `estimated_arrival` date DEFAULT NULL,
  `tracking_number` varchar(100) DEFAULT NULL,
  `seal_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `shipping_line` varchar(255) DEFAULT NULL,
  `shipping_line_code` varchar(50) DEFAULT NULL,
  `bl_number` varchar(100) DEFAULT NULL,
  `vessel_name` varchar(255) DEFAULT NULL,
  `voyage_number` varchar(100) DEFAULT NULL,
  `port_of_loading` varchar(255) DEFAULT NULL,
  `port_of_discharge` varchar(255) DEFAULT NULL,
  `eta_port` date DEFAULT NULL,
  `etd_port` date DEFAULT NULL,
  `customs_status` enum('pending','cleared','held') DEFAULT 'pending',
  `created_by` int(11) DEFAULT NULL,
  `current_branch_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `customer_name` varchar(255) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `debt_amount` decimal(15,2) DEFAULT 0.00,
  `total_spent` decimal(15,2) DEFAULT 0.00,
  `loyalty_points` decimal(12,2) DEFAULT 0.00,
  `total_cbm_shipped` decimal(12,2) DEFAULT 0.00,
  `can_use_loyalty` tinyint(1) DEFAULT 1,
  `address` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `credit_limit` decimal(15,2) DEFAULT 0.00 COMMENT 'Maximum credit allowed',
  `payment_terms` int(11) DEFAULT 30 COMMENT 'Payment due days (e.g., Net 30)',
  `user_id` int(11) DEFAULT NULL COMMENT 'Linked user account ID from users table',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `customer_notifications`;
CREATE TABLE `customer_notifications` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `notification_type` varchar(50) DEFAULT 'info',
  `is_read` tinyint(1) DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `link_url` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `customer_portal_sessions`;
CREATE TABLE `customer_portal_sessions` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `session_token` varchar(255) NOT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `debt_collection_log`;
CREATE TABLE `debt_collection_log` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `action_type` varchar(50) NOT NULL,
  `notes` text DEFAULT NULL,
  `amount_collected` decimal(15,2) DEFAULT 0.00,
  `collected_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `debt_follow_ups`;
CREATE TABLE `debt_follow_ups` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `follow_up_date` date DEFAULT curdate(),
  `follow_up_type` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `assigned_to` int(11) DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `device_tokens`;
CREATE TABLE `device_tokens` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `device_token` varchar(255) NOT NULL,
  `device_type` varchar(20) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_used` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `drivers`;
CREATE TABLE `drivers` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `full_name` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `license_number` varchar(100) DEFAULT NULL,
  `license_expiry` date DEFAULT NULL,
  `employee_id` varchar(100) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `total_trips` int(11) DEFAULT 0,
  `rating` decimal(3,2) DEFAULT 0.00,
  `salary_type` varchar(50) DEFAULT 'fixed',
  `salary_amount` decimal(15,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `exchange_rates`;
CREATE TABLE `exchange_rates` (
  `id` int(11) NOT NULL,
  `from_currency` varchar(3) NOT NULL,
  `to_currency` varchar(3) NOT NULL,
  `rate` decimal(15,6) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `expenses`;
CREATE TABLE `expenses` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `expense_number` varchar(100) NOT NULL,
  `expense_category` varchar(100) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `expense_date` date DEFAULT curdate(),
  `vendor_name` varchar(255) DEFAULT NULL,
  `receipt_image` text DEFAULT NULL,
  `trip_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `expense_categories`;
CREATE TABLE `expense_categories` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `invoices`;
CREATE TABLE `invoices` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `trip_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `subtotal` decimal(15,2) DEFAULT 0.00,
  `commission_amount` decimal(15,2) DEFAULT 0.00,
  `trucking_cost` decimal(15,2) DEFAULT 0.00,
  `handling_cost` decimal(15,2) DEFAULT 0.00,
  `tax` decimal(15,2) DEFAULT 0.00,
  `tax_rate` decimal(5,2) DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `discount` decimal(15,2) DEFAULT 0.00,
  `discount_type` varchar(20) DEFAULT 'fixed',
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_cbm` decimal(15,5) DEFAULT 0.00000,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `status` enum('draft','sent','paid','overdue','cancelled') DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `invoice_items`;
CREATE TABLE `invoice_items` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `item_name` varchar(255) DEFAULT NULL,
  `warehouse_stock_id` int(11) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `cbm_used` decimal(10,4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `journal_entries`;
CREATE TABLE `journal_entries` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `entry_number` varchar(100) NOT NULL,
  `entry_date` date DEFAULT curdate(),
  `account_name` varchar(255) NOT NULL,
  `account_code` varchar(20) DEFAULT NULL,
  `debit` decimal(15,2) DEFAULT 0.00,
  `credit` decimal(15,2) DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `live_locations`;
CREATE TABLE `live_locations` (
  `id` int(11) NOT NULL,
  `trip_id` int(11) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `speed` decimal(5,2) DEFAULT 0.00,
  `heading` decimal(5,2) DEFAULT 0.00,
  `accuracy` decimal(5,2) DEFAULT NULL,
  `altitude` decimal(8,2) DEFAULT NULL,
  `battery_level` int(11) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'active',
  `last_ping` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `loaders`;
CREATE TABLE `loaders` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `full_name` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `employee_id` varchar(100) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `total_tasks` int(11) DEFAULT 0,
  `rating` decimal(3,2) DEFAULT 0.00,
  `salary_type` varchar(50) DEFAULT 'daily',
  `salary_amount` decimal(15,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `loyalty_points_log`;
CREATE TABLE `loyalty_points_log` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `points_earned` decimal(12,2) DEFAULT 0.00,
  `points_redeemed` decimal(12,2) DEFAULT 0.00,
  `cbm_earned` decimal(10,2) DEFAULT 0.00,
  `amount_earned` decimal(15,2) DEFAULT 0.00,
  `reason` varchar(255) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `menu_items`;
CREATE TABLE `menu_items` (
  `id` int(11) NOT NULL,
  `menu_key` varchar(100) NOT NULL,
  `menu_name` varchar(255) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `icon` varchar(100) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `required_roles` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `message_templates`;
CREATE TABLE `message_templates` (
  `id` int(11) NOT NULL,
  `template_key` varchar(100) NOT NULL,
  `template_name` varchar(150) DEFAULT NULL,
  `message_content` text NOT NULL,
  `template_type` varchar(30) DEFAULT 'whatsapp',
  `status` varchar(20) DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `overdue_alerts`;
CREATE TABLE `overdue_alerts` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `days_overdue` int(11) NOT NULL,
  `alert_level` int(11) DEFAULT 1,
  `message_sent` tinyint(1) DEFAULT 0,
  `sms_sent` tinyint(1) DEFAULT 0,
  `email_sent` tinyint(1) DEFAULT 0,
  `resolved` tinyint(1) DEFAULT 0,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `packages`;
CREATE TABLE `packages` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `tracking_number` varchar(100) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_phone` varchar(50) DEFAULT NULL,
  `customer_email` varchar(255) DEFAULT NULL,
  `package_name` varchar(255) NOT NULL,
  `package_type` enum('document','parcel','cargo','pallet','container') DEFAULT 'parcel',
  `weight_kg` decimal(10,2) DEFAULT 0.00,
  `length_cm` decimal(10,2) DEFAULT 0.00,
  `width_cm` decimal(10,2) DEFAULT 0.00,
  `height_cm` decimal(10,2) DEFAULT 0.00,
  `volume_cbm` decimal(10,4) DEFAULT 0.0000,
  `declared_value` decimal(15,2) DEFAULT 0.00,
  `origin` varchar(100) DEFAULT NULL,
  `destination` varchar(100) DEFAULT NULL,
  `status` enum('pending','received','in_transit','warehouse','out_for_delivery','delivered','cancelled') DEFAULT 'pending',
  `current_location` varchar(255) DEFAULT NULL,
  `current_branch_id` int(11) DEFAULT NULL,
  `received_date` datetime DEFAULT NULL,
  `shipped_date` datetime DEFAULT NULL,
  `estimated_delivery` date DEFAULT NULL,
  `delivered_date` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `storage_fee` decimal(15,2) DEFAULT 0.00,
  `shipping_cost` decimal(15,2) DEFAULT 0.00,
  `insurance_amount` decimal(15,2) DEFAULT 0.00,
  `invoice_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `package_assignments`;
CREATE TABLE `package_assignments` (
  `id` int(11) NOT NULL,
  `package_id` int(11) NOT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assignment_type` enum('delivery','pickup','processing') DEFAULT 'delivery',
  `assigned_at` datetime DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `package_tracking_history`;
CREATE TABLE `package_tracking_history` (
  `id` int(11) NOT NULL,
  `package_id` int(11) NOT NULL,
  `status` varchar(50) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `is_used` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `payment_number` varchar(100) NOT NULL,
  `supplier_name` varchar(255) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `discount_applied` decimal(12,2) DEFAULT 0.00,
  `points_used` decimal(12,2) DEFAULT 0.00,
  `original_amount` decimal(12,2) DEFAULT 0.00,
  `payment_date` date DEFAULT curdate(),
  `payment_method` varchar(50) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `reference_number` varchar(255) DEFAULT NULL,
  `bank_account_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `payment_methods`;
CREATE TABLE `payment_methods` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `method_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `point_redemptions`;
CREATE TABLE `point_redemptions` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `points_used` decimal(12,2) NOT NULL,
  `discount_amount` decimal(12,2) NOT NULL,
  `redemption_date` datetime NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `status` enum('pending','applied','cancelled') DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp(),
  `applied_to_payment_id` int(11) DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  `receipt_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `push_notifications`;
CREATE TABLE `push_notifications` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `status` varchar(50) DEFAULT 'pending',
  `sent_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `receipts`;
CREATE TABLE `receipts` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `receipt_number` varchar(100) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_date` date DEFAULT curdate(),
  `payment_method` varchar(50) DEFAULT NULL,
  `reference_number` varchar(255) DEFAULT NULL,
  `bank_account_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `original_amount` decimal(12,2) DEFAULT 0.00,
  `discount_applied` decimal(12,2) DEFAULT 0.00,
  `points_used` decimal(12,2) DEFAULT 0.00,
  `points_earned` decimal(12,2) DEFAULT 0.00,
  `loyalty_points_awarded` tinyint(1) DEFAULT 0,
  `updated_at` datetime DEFAULT NULL,
  `points_discount_amount` decimal(15,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `reconciliation_items`;
CREATE TABLE `reconciliation_items` (
  `id` int(11) NOT NULL,
  `reconciliation_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `is_matched` tinyint(1) DEFAULT 1,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `reports_log`;
CREATE TABLE `reports_log` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `report_type` varchar(50) NOT NULL,
  `report_name` varchar(255) DEFAULT NULL,
  `generated_by` int(11) DEFAULT NULL,
  `parameters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`parameters`)),
  `file_url` text DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `name` enum('general_manager','operations_manager','finance_manager','logistics_supervisor','warehouse_supervisor','dispatcher','loader_supervisor','trainer','senior_driver','driver','junior_driver','loader','clerk','customer_service') NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `level` int(11) NOT NULL,
  `is_system` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
  `id` int(11) NOT NULL,
  `role_id` int(11) DEFAULT NULL,
  `module` varchar(50) NOT NULL,
  `action` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `sms_auto_replies`;
CREATE TABLE `sms_auto_replies` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `keyword` varchar(100) NOT NULL,
  `reply_message` text NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `priority` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `sms_conversations`;
CREATE TABLE `sms_conversations` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `phone_number` varchar(50) NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_message_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(50) DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `sms_messages`;
CREATE TABLE `sms_messages` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `phone_number` varchar(50) NOT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `message_text` text NOT NULL,
  `direction` enum('inbox','outbox') DEFAULT 'outbox',
  `status` enum('pending','sent','delivered','failed') DEFAULT 'pending',
  `bulk_id` varchar(100) DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `sms_templates`;
CREATE TABLE `sms_templates` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `template_name` varchar(255) NOT NULL,
  `template_content` text NOT NULL,
  `variables` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`variables`)),
  `category` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `staff_assignments`;
CREATE TABLE `staff_assignments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `role_id` int(11) DEFAULT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `status` enum('active','inactive','suspended','on_leave') DEFAULT 'active',
  `start_date` date DEFAULT curdate(),
  `end_date` date DEFAULT NULL,
  `salary` decimal(15,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `staff_performance`;
CREATE TABLE `staff_performance` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `tasks_completed` int(11) DEFAULT 0,
  `trips_completed` int(11) DEFAULT 0,
  `on_time_percentage` decimal(5,2) DEFAULT 0.00,
  `rating` decimal(3,2) DEFAULT 0.00,
  `customer_rating` decimal(3,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `stock_alerts`;
CREATE TABLE `stock_alerts` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `warehouse_stock_id` int(11) DEFAULT NULL,
  `alert_type` varchar(50) DEFAULT 'low_stock',
  `threshold_value` int(11) DEFAULT 10,
  `is_triggered` tinyint(1) DEFAULT 0,
  `triggered_at` timestamp NULL DEFAULT NULL,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledged_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `stock_movements`;
CREATE TABLE `stock_movements` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `warehouse_stock_id` int(11) NOT NULL,
  `quantity_change` int(11) NOT NULL,
  `previous_quantity` int(11) NOT NULL,
  `new_quantity` int(11) NOT NULL,
  `movement_type` enum('in','out','move','adjust') NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `support_faqs`;
CREATE TABLE `support_faqs` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `question` varchar(500) NOT NULL,
  `answer` text NOT NULL,
  `category` varchar(50) DEFAULT 'general',
  `display_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `support_tickets`;
CREATE TABLE `support_tickets` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `ticket_number` varchar(50) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `category` varchar(50) DEFAULT 'general',
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `status` enum('open','in_progress','waiting','resolved','closed') DEFAULT 'open',
  `attachment_url` varchar(500) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `resolved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `support_ticket_replies`;
CREATE TABLE `support_ticket_replies` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_type` enum('customer','admin') DEFAULT 'customer',
  `message` text NOT NULL,
  `attachment_url` varchar(500) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `system_logs`;
CREATE TABLE `system_logs` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) DEFAULT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_data`)),
  `new_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_data`)),
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `system_plans`;
CREATE TABLE `system_plans` (
  `id` int(11) NOT NULL,
  `plan_name` varchar(100) NOT NULL,
  `price_monthly` decimal(10,2) DEFAULT 0.00,
  `max_users` int(11) DEFAULT 0,
  `max_customers` int(11) DEFAULT 0,
  `max_containers` int(11) DEFAULT 0,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('text','number','boolean','json') DEFAULT 'text',
  `description` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `tax_rates`;
CREATE TABLE `tax_rates` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `tax_name` varchar(100) NOT NULL,
  `tax_rate` decimal(5,2) NOT NULL,
  `tax_type` enum('VAT','Sales Tax','Income Tax','Withholding','Customs','Other') DEFAULT 'VAT',
  `tax_number` varchar(100) DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `effective_from` date DEFAULT NULL,
  `effective_to` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `tax_returns`;
CREATE TABLE `tax_returns` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `tax_rate_id` int(11) NOT NULL,
  `return_period` varchar(20) NOT NULL,
  `return_year` int(4) NOT NULL,
  `return_month` int(2) DEFAULT NULL,
  `return_quarter` int(1) DEFAULT NULL,
  `filing_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `taxable_amount` decimal(15,2) DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `penalties` decimal(15,2) DEFAULT 0.00,
  `interest` decimal(15,2) DEFAULT 0.00,
  `total_due` decimal(15,2) DEFAULT 0.00,
  `amount_paid` decimal(15,2) DEFAULT 0.00,
  `status` enum('draft','filed','paid','overdue','amended') DEFAULT 'draft',
  `payment_reference` varchar(100) DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `filed_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `tax_settings`;
CREATE TABLE `tax_settings` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `default_tax_rate_id` int(11) DEFAULT NULL,
  `tax_calculation_method` enum('exclusive','inclusive') DEFAULT 'exclusive',
  `enable_tax_invoicing` tinyint(1) DEFAULT 1,
  `tax_period` enum('monthly','quarterly','annually') DEFAULT 'monthly',
  `tax_authority_name` varchar(255) DEFAULT NULL,
  `tax_authority_email` varchar(100) DEFAULT NULL,
  `tax_authority_phone` varchar(50) DEFAULT NULL,
  `tax_office_address` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `tax_transactions`;
CREATE TABLE `tax_transactions` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `tax_rate_id` int(11) NOT NULL,
  `transaction_type` enum('invoice','expense','purchase','sale','credit_note','debit_note') NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `taxable_amount` decimal(15,2) NOT NULL,
  `tax_amount` decimal(15,2) NOT NULL,
  `tax_date` date NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `tenants`;
CREATE TABLE `tenants` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `subscription_plan` varchar(50) DEFAULT 'basic',
  `logo_url` varchar(255) DEFAULT NULL,
  `loyalty_cbm_points` int(11) DEFAULT 10,
  `loyalty_amount_points` int(11) DEFAULT 5,
  `default_language` varchar(10) DEFAULT 'so',
  `timezone` varchar(100) DEFAULT 'Africa/Mogadishu',
  `warehouse_capacity` decimal(10,2) DEFAULT 100.00,
  `created_by` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `subscription_start_date` date DEFAULT NULL,
  `subscription_end_date` date DEFAULT NULL,
  `billing_cycle` enum('monthly','quarterly','bi_annual','annual') DEFAULT 'monthly',
  `subscription_status` enum('active','expired','cancelled','trial') DEFAULT 'active',
  `auto_renew` tinyint(1) DEFAULT 1,
  `last_invoice_date` date DEFAULT NULL,
  `subscription_price` decimal(10,2) DEFAULT 0.00,
  `point_money_value` decimal(10,2) DEFAULT 0.10,
  `custom_login_link` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `tenant_sequences`;
CREATE TABLE `tenant_sequences` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `sequence_name` varchar(100) NOT NULL,
  `prefix` varchar(20) DEFAULT NULL,
  `current_number` int(11) DEFAULT 1,
  `padding` int(11) DEFAULT 5
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `tenant_subscriptions`;
CREATE TABLE `tenant_subscriptions` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `status` varchar(30) DEFAULT 'active',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `translations`;
CREATE TABLE `translations` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `language_code` enum('en','so','ar') NOT NULL,
  `translation_key` varchar(255) NOT NULL,
  `translation_value` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `trucking_trips`;
CREATE TABLE `trucking_trips` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `container_id` int(11) NOT NULL,
  `trip_number` varchar(100) NOT NULL,
  `total_cbm` decimal(10,2) DEFAULT 0.00,
  `status` enum('pending','received','loading','loaded','in_transit','delivered','completed') DEFAULT 'pending',
  `loaded_at` datetime DEFAULT NULL,
  `departed_at` datetime DEFAULT NULL,
  `arrived_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `driver_name` varchar(255) DEFAULT NULL,
  `driver_phone` varchar(50) DEFAULT NULL,
  `truck_plate` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `from_branch_id` int(11) DEFAULT NULL,
  `to_branch_id` int(11) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `trucks`;
CREATE TABLE `trucks` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `truck_number` varchar(100) NOT NULL,
  `plate_number` varchar(50) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `capacity_cbm` decimal(10,2) DEFAULT NULL,
  `capacity_kg` decimal(10,2) DEFAULT NULL,
  `current_driver_id` int(11) DEFAULT NULL,
  `fuel_consumption` decimal(8,2) DEFAULT NULL,
  `last_maintenance` date DEFAULT NULL,
  `total_trips` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `full_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `role` enum('superadmin','admin','staff','customer') DEFAULT 'staff',
  `role_type` varchar(50) DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `last_login` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `default_branch_id` int(11) DEFAULT NULL,
  `available_branches` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`available_branches`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users 
ADD COLUMN staff_level VARCHAR(50) DEFAULT NULL AFTER role_type;
DROP TABLE IF EXISTS `user_branch_assignments`;
CREATE TABLE `user_branch_assignments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  `can_manage_branch` tinyint(1) DEFAULT 0,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `user_permissions`;
CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `menu_item` varchar(100) NOT NULL,
  `status` enum('allowed','denied') DEFAULT 'allowed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `vendors`;
CREATE TABLE `vendors` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `vendor_name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `tax_number` varchar(100) DEFAULT NULL,
  `payment_terms` varchar(50) DEFAULT 'net_30',
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `vendor_bills`;
CREATE TABLE `vendor_bills` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `bill_number` varchar(100) NOT NULL,
  `bill_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `subtotal` decimal(15,2) DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `tax_rate` decimal(5,2) DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) DEFAULT 0.00,
  `amount_paid` decimal(15,2) DEFAULT 0.00,
  `status` enum('draft','pending','paid','overdue','cancelled') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `attachment_path` varchar(500) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `warehouse_stock`;
CREATE TABLE `warehouse_stock` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `origin` enum('china_yiwu','china_guangzhou','dubai','local') NOT NULL DEFAULT 'china_yiwu',
  `stock_name` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `length_cm` decimal(10,2) NOT NULL DEFAULT 0.00,
  `width_cm` decimal(10,2) NOT NULL DEFAULT 0.00,
  `height_cm` decimal(10,2) NOT NULL DEFAULT 0.00,
  `volume_cbm` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `location` varchar(255) DEFAULT NULL,
  `bin_location` varchar(100) DEFAULT NULL,
  `zone` varchar(50) DEFAULT NULL,
  `minimum_stock` int(11) DEFAULT 0,
  `maximum_stock` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `last_updated` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `last_invoice_id` int(11) DEFAULT NULL,
  `last_invoice_date` date DEFAULT NULL,
  `mogadishu_received_date` datetime DEFAULT NULL,
  `mogadishu_taken_date` datetime DEFAULT NULL,
  `storage_fee` decimal(15,2) DEFAULT 0.00,
  `mogadishu_status` enum('not_arrived','in_warehouse','taken','delivered') NOT NULL DEFAULT 'not_arrived',
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- INDEXES, AUTO_INCREMENT AND FOREIGN KEYS


ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `trip_id` (`trip_id`),
  ADD KEY `assigned_to_driver_id` (`assigned_to_driver_id`),
  ADD KEY `assigned_to_loader_id` (`assigned_to_loader_id`),
  ADD KEY `assigned_by` (`assigned_by`),
  ADD KEY `completed_by` (`completed_by`);


ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`);


ALTER TABLE `bank_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `created_by` (`created_by`);


ALTER TABLE `bank_reconciliations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `bank_account_id` (`bank_account_id`);


ALTER TABLE `bank_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `bank_account_id` (`bank_account_id`),
  ADD KEY `created_by` (`created_by`);


ALTER TABLE `bill_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `bill_id` (`bill_id`);


ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_branch_code` (`tenant_id`,`branch_code`),
  ADD KEY `idx_tenant_id` (`tenant_id`),
  ADD KEY `idx_branch_type` (`branch_type`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `created_by` (`created_by`);


ALTER TABLE `branch_activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_branch_id` (`branch_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_created_at` (`created_at`);


ALTER TABLE `branch_stock`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_branch_stock` (`branch_id`,`warehouse_stock_id`),
  ADD KEY `idx_branch_id` (`branch_id`),
  ADD KEY `idx_warehouse_stock_id` (`warehouse_stock_id`);


ALTER TABLE `branch_transfers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_transfer_number` (`transfer_number`),
  ADD KEY `idx_from_branch` (`from_branch_id`),
  ADD KEY `idx_to_branch` (`to_branch_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `requested_by` (`requested_by`),
  ADD KEY `approved_by` (`approved_by`);


ALTER TABLE `branch_transfer_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_transfer_id` (`transfer_id`),
  ADD KEY `idx_warehouse_stock_id` (`warehouse_stock_id`);


ALTER TABLE `bulk_sms_campaigns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `created_by` (`created_by`);


ALTER TABLE `bulk_sms_recipients`
  ADD PRIMARY KEY (`id`),
  ADD KEY `campaign_id` (`campaign_id`),
  ADD KEY `customer_id` (`customer_id`);


ALTER TABLE `cargo_manifest_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant_id` (`tenant_id`),
  ADD KEY `idx_container_id` (`container_id`),
  ADD KEY `idx_shipment_id` (`shipment_id`),
  ADD KEY `idx_warehouse_stock_id` (`warehouse_stock_id`),
  ADD KEY `idx_invoice_id` (`invoice_id`),
  ADD KEY `idx_cargo_manifest_added_at` (`added_at`),
  ADD KEY `idx_cargo_manifest_shipment` (`shipment_id`),
  ADD KEY `idx_cargo_manifest_container` (`container_id`);


ALTER TABLE `cash_flow`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`);


ALTER TABLE `chart_of_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `account_code` (`account_code`);


ALTER TABLE `containers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_container_number` (`tenant_id`,`container_number`),
  ADD KEY `idx_tenant_id` (`tenant_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_origin` (`origin`),
  ADD KEY `idx_containers_status` (`status`),
  ADD KEY `current_branch_id` (`current_branch_id`);


ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant_id` (`tenant_id`),
  ADD KEY `idx_phone` (`phone`),
  ADD KEY `idx_customers_tenant_active` (`tenant_id`,`is_active`),
  ADD KEY `idx_credit_limit` (`credit_limit`),
  ADD KEY `idx_payment_terms` (`payment_terms`),
  ADD KEY `idx_user_id` (`user_id`);


ALTER TABLE `customer_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `customer_id` (`customer_id`);


ALTER TABLE `customer_portal_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_token` (`session_token`),
  ADD KEY `customer_id` (`customer_id`);


ALTER TABLE `debt_collection_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `collected_by` (`collected_by`);


ALTER TABLE `debt_follow_ups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `assigned_to` (`assigned_to`);


ALTER TABLE `device_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_device` (`tenant_id`,`device_token`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `customer_id` (`customer_id`);


ALTER TABLE `drivers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `created_by` (`created_by`);


ALTER TABLE `exchange_rates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_rate` (`from_currency`,`to_currency`);


ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `expense_number` (`expense_number`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `trip_id` (`trip_id`),
  ADD KEY `created_by` (`created_by`);


ALTER TABLE `expense_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`);


ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_invoice_number` (`tenant_id`,`invoice_number`),
  ADD KEY `idx_tenant_id` (`tenant_id`),
  ADD KEY `idx_customer_id` (`customer_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_invoices_invoice_date` (`invoice_date`);


ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_invoice_id` (`invoice_id`),
  ADD KEY `idx_warehouse_stock_id` (`warehouse_stock_id`);


ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_journal_entry_number` (`entry_number`);


ALTER TABLE `live_locations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `driver_id` (`driver_id`),
  ADD KEY `idx_live_locations_trip` (`trip_id`),
  ADD KEY `idx_live_locations_last_ping` (`last_ping`);


ALTER TABLE `loaders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `created_by` (`created_by`);


ALTER TABLE `loyalty_points_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `created_by` (`created_by`);


ALTER TABLE `menu_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `menu_key` (`menu_key`);


ALTER TABLE `message_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `template_key` (`template_key`);


ALTER TABLE `overdue_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `idx_overdue_alerts_customer` (`customer_id`);


ALTER TABLE `packages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_tracking_number` (`tracking_number`),
  ADD UNIQUE KEY `tracking_number` (`tracking_number`),
  ADD KEY `idx_tenant_id` (`tenant_id`),
  ADD KEY `idx_customer_id` (`customer_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_tracking_number` (`tracking_number`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `current_branch_id` (`current_branch_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `invoice_id` (`invoice_id`);


ALTER TABLE `package_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_package_id` (`package_id`),
  ADD KEY `idx_assigned_to` (`assigned_to`),
  ADD KEY `idx_assigned_by` (`assigned_by`);


ALTER TABLE `package_tracking_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_package_id` (`package_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `created_by` (`created_by`);


ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_user_id` (`user_id`);


ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payment_number` (`payment_number`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_payments_customer` (`customer_id`),
  ADD KEY `idx_payments_invoice` (`invoice_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `invoice_id` (`invoice_id`);


ALTER TABLE `payment_methods`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`);


ALTER TABLE `point_redemptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_tenant` (`tenant_id`),
  ADD KEY `idx_status` (`status`);


ALTER TABLE `push_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `customer_id` (`customer_id`);


ALTER TABLE `receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_receipt_payment_id` (`payment_id`);


ALTER TABLE `reconciliation_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reconciliation_id` (`reconciliation_id`),
  ADD KEY `transaction_id` (`transaction_id`);


ALTER TABLE `reports_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `generated_by` (`generated_by`);


ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`);


ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_permission` (`role_id`,`module`,`action`);


ALTER TABLE `sms_auto_replies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`);


ALTER TABLE `sms_conversations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_conversation` (`tenant_id`,`phone_number`),
  ADD KEY `customer_id` (`customer_id`);


ALTER TABLE `sms_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_sms_messages_phone` (`phone_number`);


ALTER TABLE `sms_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`);


ALTER TABLE `staff_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `role_id` (`role_id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `assigned_by` (`assigned_by`);


ALTER TABLE `staff_performance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `user_id` (`user_id`);


ALTER TABLE `stock_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `warehouse_stock_id` (`warehouse_stock_id`),
  ADD KEY `acknowledged_by` (`acknowledged_by`);


ALTER TABLE `stock_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant_id` (`tenant_id`),
  ADD KEY `idx_warehouse_stock_id` (`warehouse_stock_id`),
  ADD KEY `idx_movement_type` (`movement_type`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_stock_movements_created_at` (`created_at`);


ALTER TABLE `support_faqs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant_id` (`tenant_id`),
  ADD KEY `idx_is_active` (`is_active`);


ALTER TABLE `support_tickets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_number` (`ticket_number`),
  ADD KEY `idx_customer_id` (`customer_id`),
  ADD KEY `idx_tenant_id` (`tenant_id`),
  ADD KEY `idx_status` (`status`);


ALTER TABLE `support_ticket_replies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ticket_id` (`ticket_id`);


ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `user_id` (`user_id`);


ALTER TABLE `system_plans`
  ADD PRIMARY KEY (`id`);


ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);


ALTER TABLE `tax_rates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`);


ALTER TABLE `tax_returns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `tax_rate_id` (`tax_rate_id`);


ALTER TABLE `tax_settings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `default_tax_rate_id` (`default_tax_rate_id`);


ALTER TABLE `tax_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `tax_rate_id` (`tax_rate_id`),
  ADD KEY `idx_transaction` (`transaction_type`,`transaction_id`);


ALTER TABLE `tenants`
  ADD PRIMARY KEY (`id`);


ALTER TABLE `tenant_sequences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_sequence` (`tenant_id`,`sequence_name`);


ALTER TABLE `tenant_subscriptions`
  ADD PRIMARY KEY (`id`);


ALTER TABLE `translations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_translation` (`tenant_id`,`language_code`,`translation_key`);


ALTER TABLE `trucking_trips`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_trip_number` (`tenant_id`,`trip_number`),
  ADD KEY `idx_tenant_id` (`tenant_id`),
  ADD KEY `idx_container_id` (`container_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_trucking_trips_loaded_at` (`loaded_at`),
  ADD KEY `from_branch_id` (`from_branch_id`),
  ADD KEY `to_branch_id` (`to_branch_id`),
  ADD KEY `driver_id` (`driver_id`);


ALTER TABLE `trucks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `current_driver_id` (`current_driver_id`),
  ADD KEY `created_by` (`created_by`);


ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_email` (`email`),
  ADD KEY `idx_tenant_id` (`tenant_id`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_users_role_active` (`role`,`is_active`),
  ADD KEY `idx_role_type` (`role_type`),
  ADD KEY `default_branch_id` (`default_branch_id`);


ALTER TABLE `user_branch_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_user_branch` (`user_id`,`branch_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_branch_id` (`branch_id`),
  ADD KEY `idx_is_primary` (`is_primary`),
  ADD KEY `assigned_by` (`assigned_by`);


ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_menu` (`user_id`,`menu_item`);


ALTER TABLE `vendors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`);


ALTER TABLE `vendor_bills`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_bill_per_tenant` (`tenant_id`,`bill_number`),
  ADD KEY `vendor_id` (`vendor_id`);


ALTER TABLE `warehouse_stock`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant_id` (`tenant_id`),
  ADD KEY `idx_customer_id` (`customer_id`),
  ADD KEY `idx_origin` (`origin`),
  ADD KEY `idx_stock_name` (`stock_name`),
  ADD KEY `idx_location` (`location`),
  ADD KEY `idx_updated_by` (`updated_by`),
  ADD KEY `idx_warehouse_stock_origin` (`origin`),
  ADD KEY `idx_warehouse_stock_quantity` (`quantity`),
  ADD KEY `idx_warehouse_stock_min_max` (`minimum_stock`,`maximum_stock`);


ALTER TABLE `assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `bank_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `bank_reconciliations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `bank_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `bill_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `branches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `branch_activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `branch_stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `branch_transfers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `branch_transfer_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `bulk_sms_campaigns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `bulk_sms_recipients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `cargo_manifest_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `cash_flow`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `chart_of_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `containers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;


ALTER TABLE `customer_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `customer_portal_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `debt_collection_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `debt_follow_ups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `device_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `drivers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `exchange_rates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `expense_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `invoice_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `journal_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `live_locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `loaders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `loyalty_points_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `menu_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `message_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `overdue_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `packages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `package_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `package_tracking_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;


ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `payment_methods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `point_redemptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `push_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `receipts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `reconciliation_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `reports_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `role_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `sms_auto_replies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `sms_conversations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `sms_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `sms_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `staff_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `staff_performance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `stock_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `stock_movements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `support_faqs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `support_tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `support_ticket_replies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `system_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `system_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `tax_rates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `tax_returns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `tax_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `tax_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `tenants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;


ALTER TABLE `tenant_sequences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `tenant_subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `translations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `trucking_trips`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `trucks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;


ALTER TABLE `user_branch_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `user_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `vendors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `vendor_bills`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `warehouse_stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `assignments`
  ADD CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `assignments_ibfk_2` FOREIGN KEY (`trip_id`) REFERENCES `trucking_trips` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `assignments_ibfk_3` FOREIGN KEY (`assigned_to_driver_id`) REFERENCES `drivers` (`id`),
  ADD CONSTRAINT `assignments_ibfk_4` FOREIGN KEY (`assigned_to_loader_id`) REFERENCES `loaders` (`id`),
  ADD CONSTRAINT `assignments_ibfk_5` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `assignments_ibfk_6` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`);


ALTER TABLE `bank_accounts`
  ADD CONSTRAINT `bank_accounts_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bank_accounts_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);


ALTER TABLE `bank_reconciliations`
  ADD CONSTRAINT `bank_reconciliations_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bank_reconciliations_ibfk_2` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE CASCADE;


ALTER TABLE `bank_transactions`
  ADD CONSTRAINT `bank_transactions_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bank_transactions_ibfk_2` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`),
  ADD CONSTRAINT `bank_transactions_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);


ALTER TABLE `bill_payments`
  ADD CONSTRAINT `bill_payments_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bill_payments_ibfk_2` FOREIGN KEY (`bill_id`) REFERENCES `vendor_bills` (`id`) ON DELETE CASCADE;


ALTER TABLE `branches`
  ADD CONSTRAINT `branches_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `branches_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;


ALTER TABLE `branch_activity_logs`
  ADD CONSTRAINT `branch_activity_logs_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `branch_activity_logs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;


ALTER TABLE `branch_stock`
  ADD CONSTRAINT `branch_stock_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `branch_stock_ibfk_2` FOREIGN KEY (`warehouse_stock_id`) REFERENCES `warehouse_stock` (`id`) ON DELETE CASCADE;


ALTER TABLE `branch_transfers`
  ADD CONSTRAINT `branch_transfers_ibfk_1` FOREIGN KEY (`from_branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `branch_transfers_ibfk_2` FOREIGN KEY (`to_branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `branch_transfers_ibfk_3` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `branch_transfers_ibfk_4` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;


ALTER TABLE `branch_transfer_items`
  ADD CONSTRAINT `branch_transfer_items_ibfk_1` FOREIGN KEY (`transfer_id`) REFERENCES `branch_transfers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `branch_transfer_items_ibfk_2` FOREIGN KEY (`warehouse_stock_id`) REFERENCES `warehouse_stock` (`id`) ON DELETE CASCADE;


ALTER TABLE `bulk_sms_campaigns`
  ADD CONSTRAINT `bulk_sms_campaigns_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bulk_sms_campaigns_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);


ALTER TABLE `bulk_sms_recipients`
  ADD CONSTRAINT `bulk_sms_recipients_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `bulk_sms_campaigns` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bulk_sms_recipients_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);


ALTER TABLE `cargo_manifest_items`
  ADD CONSTRAINT `fk_cargo_container` FOREIGN KEY (`container_id`) REFERENCES `containers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cargo_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cargo_stock` FOREIGN KEY (`warehouse_stock_id`) REFERENCES `warehouse_stock` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cargo_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cargo_trip` FOREIGN KEY (`shipment_id`) REFERENCES `trucking_trips` (`id`) ON DELETE SET NULL;


ALTER TABLE `cash_flow`
  ADD CONSTRAINT `cash_flow_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;


ALTER TABLE `containers`
  ADD CONSTRAINT `containers_ibfk_1` FOREIGN KEY (`current_branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_containers_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;


ALTER TABLE `customers`
  ADD CONSTRAINT `fk_customers_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;


ALTER TABLE `customer_notifications`
  ADD CONSTRAINT `customer_notifications_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_notifications_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);


ALTER TABLE `customer_portal_sessions`
  ADD CONSTRAINT `customer_portal_sessions_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE;


ALTER TABLE `debt_collection_log`
  ADD CONSTRAINT `debt_collection_log_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `debt_collection_log_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `debt_collection_log_ibfk_3` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`),
  ADD CONSTRAINT `debt_collection_log_ibfk_4` FOREIGN KEY (`collected_by`) REFERENCES `users` (`id`);


ALTER TABLE `debt_follow_ups`
  ADD CONSTRAINT `debt_follow_ups_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  ADD CONSTRAINT `debt_follow_ups_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `debt_follow_ups_ibfk_3` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`);


ALTER TABLE `device_tokens`
  ADD CONSTRAINT `device_tokens_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `device_tokens_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `device_tokens_ibfk_3` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);


ALTER TABLE `drivers`
  ADD CONSTRAINT `drivers_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `drivers_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `drivers_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);


ALTER TABLE `expenses`
  ADD CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `expenses_ibfk_2` FOREIGN KEY (`trip_id`) REFERENCES `trucking_trips` (`id`),
  ADD CONSTRAINT `expenses_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);


ALTER TABLE `expense_categories`
  ADD CONSTRAINT `expense_categories_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;


ALTER TABLE `invoices`
  ADD CONSTRAINT `fk_invoices_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_invoices_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_invoices_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;


ALTER TABLE `invoice_items`
  ADD CONSTRAINT `fk_invoice_items_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_invoice_items_stock` FOREIGN KEY (`warehouse_stock_id`) REFERENCES `warehouse_stock` (`id`) ON DELETE SET NULL;


ALTER TABLE `journal_entries`
  ADD CONSTRAINT `journal_entries_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `journal_entries_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);


ALTER TABLE `live_locations`
  ADD CONSTRAINT `live_locations_ibfk_1` FOREIGN KEY (`trip_id`) REFERENCES `trucking_trips` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `live_locations_ibfk_2` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`);


ALTER TABLE `loaders`
  ADD CONSTRAINT `loaders_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `loaders_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `loaders_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);


ALTER TABLE `loyalty_points_log`
  ADD CONSTRAINT `loyalty_points_log_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `loyalty_points_log_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `loyalty_points_log_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);


ALTER TABLE `overdue_alerts`
  ADD CONSTRAINT `overdue_alerts_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `overdue_alerts_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `overdue_alerts_ibfk_3` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`);


ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;


ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_payments_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);


ALTER TABLE `payment_methods`
  ADD CONSTRAINT `payment_methods_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;


ALTER TABLE `push_notifications`
  ADD CONSTRAINT `push_notifications_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `push_notifications_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `push_notifications_ibfk_3` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);


ALTER TABLE `receipts`
  ADD CONSTRAINT `receipts_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `receipts_ibfk_2` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`),
  ADD CONSTRAINT `receipts_ibfk_3` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `receipts_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);


ALTER TABLE `reconciliation_items`
  ADD CONSTRAINT `reconciliation_items_ibfk_1` FOREIGN KEY (`reconciliation_id`) REFERENCES `bank_reconciliations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reconciliation_items_ibfk_2` FOREIGN KEY (`transaction_id`) REFERENCES `bank_transactions` (`id`) ON DELETE CASCADE;


ALTER TABLE `reports_log`
  ADD CONSTRAINT `reports_log_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reports_log_ibfk_2` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`);


ALTER TABLE `roles`
  ADD CONSTRAINT `roles_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;


ALTER TABLE `role_permissions`
  ADD CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;


ALTER TABLE `sms_auto_replies`
  ADD CONSTRAINT `sms_auto_replies_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;


ALTER TABLE `sms_conversations`
  ADD CONSTRAINT `sms_conversations_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sms_conversations_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);


ALTER TABLE `sms_messages`
  ADD CONSTRAINT `sms_messages_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sms_messages_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `sms_messages_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);


ALTER TABLE `sms_templates`
  ADD CONSTRAINT `sms_templates_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`);


ALTER TABLE `staff_assignments`
  ADD CONSTRAINT `staff_assignments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `staff_assignments_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `staff_assignments_ibfk_3` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `staff_assignments_ibfk_4` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`);


ALTER TABLE `staff_performance`
  ADD CONSTRAINT `staff_performance_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `staff_performance_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);


ALTER TABLE `stock_alerts`
  ADD CONSTRAINT `stock_alerts_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `stock_alerts_ibfk_2` FOREIGN KEY (`warehouse_stock_id`) REFERENCES `warehouse_stock` (`id`),
  ADD CONSTRAINT `stock_alerts_ibfk_3` FOREIGN KEY (`acknowledged_by`) REFERENCES `users` (`id`);


ALTER TABLE `stock_movements`
  ADD CONSTRAINT `fk_movements_stock` FOREIGN KEY (`warehouse_stock_id`) REFERENCES `warehouse_stock` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_movements_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_movements_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;


ALTER TABLE `support_tickets`
  ADD CONSTRAINT `support_tickets_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE;


ALTER TABLE `support_ticket_replies`
  ADD CONSTRAINT `support_ticket_replies_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE;


ALTER TABLE `system_logs`
  ADD CONSTRAINT `system_logs_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `system_logs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);


ALTER TABLE `tax_rates`
  ADD CONSTRAINT `tax_rates_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;


ALTER TABLE `tax_returns`
  ADD CONSTRAINT `tax_returns_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tax_returns_ibfk_2` FOREIGN KEY (`tax_rate_id`) REFERENCES `tax_rates` (`id`) ON DELETE CASCADE;


ALTER TABLE `tax_settings`
  ADD CONSTRAINT `tax_settings_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tax_settings_ibfk_2` FOREIGN KEY (`default_tax_rate_id`) REFERENCES `tax_rates` (`id`) ON DELETE SET NULL;


ALTER TABLE `tax_transactions`
  ADD CONSTRAINT `tax_transactions_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tax_transactions_ibfk_2` FOREIGN KEY (`tax_rate_id`) REFERENCES `tax_rates` (`id`) ON DELETE CASCADE;


ALTER TABLE `tenant_sequences`
  ADD CONSTRAINT `tenant_sequences_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;


ALTER TABLE `translations`
  ADD CONSTRAINT `translations_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;


ALTER TABLE `trucking_trips`
  ADD CONSTRAINT `fk_trips_container` FOREIGN KEY (`container_id`) REFERENCES `containers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_trips_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `trucking_trips_ibfk_1` FOREIGN KEY (`from_branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `trucking_trips_ibfk_10` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `trucking_trips_ibfk_2` FOREIGN KEY (`to_branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;


ALTER TABLE `trucks`
  ADD CONSTRAINT `trucks_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `trucks_ibfk_10` FOREIGN KEY (`current_driver_id`) REFERENCES `drivers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `trucks_ibfk_2` FOREIGN KEY (`current_driver_id`) REFERENCES `drivers` (`id`),
  ADD CONSTRAINT `trucks_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);


ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`default_branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;


ALTER TABLE `user_branch_assignments`
  ADD CONSTRAINT `user_branch_assignments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_branch_assignments_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_branch_assignments_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;


ALTER TABLE `user_permissions`
  ADD CONSTRAINT `user_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;


ALTER TABLE `vendors`
  ADD CONSTRAINT `vendors_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;


ALTER TABLE `vendor_bills`
  ADD CONSTRAINT `vendor_bills_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `vendor_bills_ibfk_2` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE;


ALTER TABLE `warehouse_stock`
  ADD CONSTRAINT `fk_stock_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_stock_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_stock_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;


SET FOREIGN_KEY_CHECKS = 1;


-- VIEWS


DROP VIEW IF EXISTS `container_utilization_view`;
CREATE OR REPLACE VIEW `container_utilization_view`  AS SELECT `c`.`id` AS `container_id`, `c`.`container_number` AS `container_number`, `c`.`container_type` AS `container_type`, `c`.`size_cbm` AS `capacity`, coalesce(sum(`cmi`.`cbm_used`),0) AS `used_cbm`, `c`.`size_cbm`- coalesce(sum(`cmi`.`cbm_used`),0) AS `remaining_cbm`, round(coalesce(sum(`cmi`.`cbm_used`),0) / nullif(`c`.`size_cbm`,0) * 100,2) AS `utilization_percent`, `c`.`status` AS `status`, `c`.`origin` AS `origin` FROM (`containers` `c` left join `cargo_manifest_items` `cmi` on(`c`.`id` = `cmi`.`container_id`)) GROUP BY `c`.`id`, `c`.`container_number`, `c`.`container_type`, `c`.`size_cbm`, `c`.`status`, `c`.`origin` ;


DROP VIEW IF EXISTS `customer_stock_summary_view`;
CREATE OR REPLACE VIEW `customer_stock_summary_view`  AS SELECT `c`.`id` AS `customer_id`, `c`.`customer_name` AS `customer_name`, `c`.`phone` AS `phone`, `t`.`id` AS `tenant_id`, `t`.`name` AS `tenant_name`, count(`ws`.`id`) AS `total_items`, sum(`ws`.`quantity`) AS `total_quantity`, sum(`ws`.`volume_cbm`) AS `total_volume`, sum(`ws`.`volume_cbm` * `ws`.`unit_price`) AS `total_value` FROM ((`customers` `c` left join `tenants` `t` on(`c`.`tenant_id` = `t`.`id`)) left join `warehouse_stock` `ws` on(`c`.`id` = `ws`.`customer_id`)) GROUP BY `c`.`id`, `c`.`customer_name`, `c`.`phone`, `t`.`id`, `t`.`name` ;


DROP VIEW IF EXISTS `stock_summary_view`;
CREATE OR REPLACE VIEW `stock_summary_view`  AS SELECT `t`.`id` AS `tenant_id`, `t`.`name` AS `tenant_name`, count(`ws`.`id`) AS `total_items`, sum(`ws`.`quantity`) AS `total_quantity`, sum(`ws`.`volume_cbm`) AS `total_volume`, sum(`ws`.`volume_cbm` * `ws`.`unit_price`) AS `total_value`, count(case when `ws`.`quantity` <= `ws`.`minimum_stock` then 1 end) AS `low_stock_count`, sum(case when `ws`.`origin` = 'china_yiwu' then `ws`.`quantity` else 0 end) AS `yiwu_quantity`, sum(case when `ws`.`origin` = 'china_guangzhou' then `ws`.`quantity` else 0 end) AS `guangzhou_quantity`, sum(case when `ws`.`origin` = 'dubai' then `ws`.`quantity` else 0 end) AS `dubai_quantity`, sum(case when `ws`.`origin` = 'local' then `ws`.`quantity` else 0 end) AS `local_quantity` FROM (`tenants` `t` left join `warehouse_stock` `ws` on(`t`.`id` = `ws`.`tenant_id`)) GROUP BY `t`.`id`, `t`.`name` ;


-- TRIGGERS


DROP TRIGGER IF EXISTS `update_container_status_full`;
DELIMITER $$
CREATE TRIGGER `update_container_status_full` AFTER INSERT ON `cargo_manifest_items` FOR EACH ROW BEGIN
    DECLARE used_cbm DECIMAL(10,2);
    DECLARE capacity_cbm DECIMAL(10,2);
    
    SELECT COALESCE(SUM(cmi.cbm_used), 0), c.size_cbm INTO used_cbm, capacity_cbm
    FROM cargo_manifest_items cmi
    JOIN containers c ON cmi.container_id = c.id
    WHERE cmi.container_id = NEW.container_id
    GROUP BY c.size_cbm;
    
    IF used_cbm >= capacity_cbm - 0.01 THEN
        UPDATE containers SET status = 'loaded' WHERE id = NEW.container_id;
        UPDATE trucking_trips SET status = 'loaded', loaded_at = NOW() 
        WHERE container_id = NEW.container_id AND status = 'loading';
    END IF;
END
$$
DELIMITER ;


DROP TRIGGER IF EXISTS `update_trip_cbm_delete`;
DELIMITER $$
CREATE TRIGGER `update_trip_cbm_delete` AFTER DELETE ON `cargo_manifest_items` FOR EACH ROW BEGIN
    UPDATE trucking_trips 
    SET total_cbm = (
        SELECT COALESCE(SUM(cbm_used), 0) 
        FROM cargo_manifest_items 
        WHERE shipment_id = OLD.shipment_id
    )
    WHERE id = OLD.shipment_id;
END
$$
DELIMITER ;


DROP TRIGGER IF EXISTS `update_trip_cbm_insert`;
DELIMITER $$
CREATE TRIGGER `update_trip_cbm_insert` AFTER INSERT ON `cargo_manifest_items` FOR EACH ROW BEGIN
    UPDATE trucking_trips 
    SET total_cbm = (
        SELECT COALESCE(SUM(cbm_used), 0) 
        FROM cargo_manifest_items 
        WHERE shipment_id = NEW.shipment_id
    )
    WHERE id = NEW.shipment_id;
END
$$
DELIMITER ;


DROP TRIGGER IF EXISTS `update_container_size_insert`;
DELIMITER $$
CREATE TRIGGER `update_container_size_insert` BEFORE INSERT ON `containers` FOR EACH ROW BEGIN
    IF NEW.container_type = '20ft' THEN
        SET NEW.size_cbm = 33.2;
    ELSEIF NEW.container_type = '40ft' THEN
        SET NEW.size_cbm = 67.6;
    ELSEIF NEW.container_type = '40hc' THEN
        SET NEW.size_cbm = 76.3;
    ELSE
        SET NEW.size_cbm = 999.9;
    END IF;
END
$$
DELIMITER ;


DROP TRIGGER IF EXISTS `update_container_size_update`;
DELIMITER $$
CREATE TRIGGER `update_container_size_update` BEFORE UPDATE ON `containers` FOR EACH ROW BEGIN
    IF NEW.container_type = '20ft' THEN
        SET NEW.size_cbm = 33.2;
    ELSEIF NEW.container_type = '40ft' THEN
        SET NEW.size_cbm = 67.6;
    ELSEIF NEW.container_type = '40hc' THEN
        SET NEW.size_cbm = 76.3;
    ELSE
        SET NEW.size_cbm = 999.9;
    END IF;
END
$$
DELIMITER ;


DROP TRIGGER IF EXISTS `trigger_update_debt`;
DELIMITER $$
CREATE TRIGGER `trigger_update_debt` AFTER INSERT ON `receipts` FOR EACH ROW BEGIN
    UPDATE customers 
    SET debt_amount = debt_amount - NEW.amount,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = NEW.customer_id;
END
$$
DELIMITER ;


COMMIT;
