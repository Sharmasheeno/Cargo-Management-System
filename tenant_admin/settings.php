<?php
// tenant_admin/settings.php
// Company Settings for Cargo Management System - Tenant Admin

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is tenant_admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tenant_admin') {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION['role'];
$session_tenant_id = $_SESSION['tenant_id'] ?? 0;

// Security: If no tenant is assigned, redirect
if (!$session_tenant_id) {
    header("Location: ../dashboard.php?error=no_tenant");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/MessagingService.php';
$messaging = new MessagingService($pdo);

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Tenant Admin';

// Get tenant name and details
$tenant_name = '';
$tenant_details = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmt->execute([$session_tenant_id]);
    $tenant_details = $stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_name = $tenant_details['name'] ?? 'My Company';
} catch (PDOException $e) {
    $tenant_name = 'My Company';
}

$settings = [];

try {
    // FIRST: Check if system_settings table exists and add tenant_id column if missing
    $checkTable = $pdo->query("SHOW TABLES LIKE 'system_settings'");
    if (!$checkTable->fetch()) {
        // Create table with tenant_id
        $pdo->exec("
            CREATE TABLE system_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NULL,
                setting_key VARCHAR(100) NOT NULL,
                setting_value TEXT,
                setting_type ENUM('text', 'number', 'boolean', 'json', 'password', 'textarea') DEFAULT 'text',
                category VARCHAR(50) DEFAULT 'general',
                description TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                updated_by INT,
                UNIQUE KEY unique_setting (tenant_id, setting_key),
                INDEX idx_tenant_category (tenant_id, category),
                INDEX idx_setting_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        // Check if tenant_id column exists, if not add it
        try {
            $checkColumn = $pdo->query("SHOW COLUMNS FROM system_settings LIKE 'tenant_id'");
            if (!$checkColumn->fetch()) {
                $pdo->exec("ALTER TABLE system_settings ADD COLUMN tenant_id INT NULL AFTER id");
                $pdo->exec("ALTER TABLE system_settings ADD UNIQUE KEY unique_setting (tenant_id, setting_key)");
            }
        } catch (PDOException $e) {
            error_log("Column check error: " . $e->getMessage());
        }
    }
    
    // Insert default settings for this tenant if not exists (using INSERT IGNORE)
    $default_settings = [
        // General Settings
        ['system_name', 'CURDUN CARGO', 'text', 'general', 'System Name'],
        ['system_timezone', 'Africa/Mogadishu', 'text', 'general', 'System Timezone'],
        ['date_format', 'd/m/Y', 'text', 'general', 'Date Format'],
        ['time_format', 'H:i:s', 'text', 'general', 'Time Format'],
        ['default_language', 'en', 'text', 'general', 'Default Language'],
        ['default_currency', 'USD', 'text', 'general', 'Default Currency'],
        ['currency_symbol', '$', 'text', 'general', 'Currency Symbol'],
        ['currency_position', 'before', 'text', 'general', 'Currency Position'],
        ['items_per_page', '15', 'number', 'general', 'Items Per Page'],
        ['company_name', $tenant_name, 'text', 'general', 'Company Name'],
        ['company_email', '', 'text', 'general', 'Company Email'],
        ['company_phone', '', 'text', 'general', 'Company Phone'],
        ['company_address', '', 'textarea', 'general', 'Company Address'],
        ['company_logo', '', 'text', 'general', 'Company Logo'],
        ['company_website', '', 'text', 'general', 'Company Website'],
        ['company_tax_number', '', 'text', 'general', 'Company Tax Number'],
        ['company_registration_number', '', 'text', 'general', 'Company Registration Number'],
        
        // Loyalty Settings
        ['loyalty_enabled', '1', 'boolean', 'loyalty', 'Enable Loyalty Points System'],
        ['loyalty_cbm_points', '10', 'number', 'loyalty', 'Points per CBM'],
        ['loyalty_amount_points', '5', 'number', 'loyalty', 'Points per $100 spent'],
        ['loyalty_redemption_rate', '0.10', 'number', 'loyalty', 'Points to Money Value'],
        ['loyalty_min_points_redeem', '100', 'number', 'loyalty', 'Minimum points to redeem'],
        ['loyalty_points_expiry_days', '365', 'number', 'loyalty', 'Points expiry days'],
        ['loyalty_points_on_invoice', '1', 'boolean', 'loyalty', 'Earn points on invoice'],
        ['loyalty_points_on_cbm', '1', 'boolean', 'loyalty', 'Earn points on CBM'],
        ['loyalty_points_on_money', '1', 'boolean', 'loyalty', 'Earn points on money'],
        ['loyalty_max_discount_percent', '50', 'number', 'loyalty', 'Max discount percent'],
        ['loyalty_birthday_points', '50', 'number', 'loyalty', 'Birthday bonus points'],
        ['loyalty_referral_points', '100', 'number', 'loyalty', 'Referral bonus points'],
        
        // Tax Settings
        ['tax_enabled', '1', 'boolean', 'tax', 'Enable Tax Calculation'],
        ['default_tax_rate', '0', 'number', 'tax', 'Default Tax Rate (%)'],
        ['tax_calculation_method', 'exclusive', 'text', 'tax', 'Tax Calculation Method'],
        ['tax_period', 'monthly', 'text', 'tax', 'Tax Reporting Period'],
        ['tax_authority_name', '', 'text', 'tax', 'Tax Authority Name'],
        ['tax_authority_email', '', 'text', 'tax', 'Tax Authority Email'],
        ['tax_authority_phone', '', 'text', 'tax', 'Tax Authority Phone'],
        ['tax_office_address', '', 'textarea', 'tax', 'Tax Office Address'],
        ['tax_number', '', 'text', 'tax', 'Tax Registration Number'],
        ['tax_invoice_include', '1', 'boolean', 'tax', 'Include Tax on Invoices'],
        ['tax_rounding', '2', 'number', 'tax', 'Tax Rounding Decimals'],
        
        // Invoice Settings
        ['invoice_prefix', 'INV', 'text', 'invoice', 'Invoice Prefix'],
        ['invoice_due_days', '30', 'number', 'invoice', 'Default Due Days'],
        ['invoice_terms', 'Payment is due within 30 days.', 'textarea', 'invoice', 'Invoice Terms'],
        ['invoice_footer', 'Thank you for your business!', 'textarea', 'invoice', 'Invoice Footer'],
        ['invoice_show_qr', '1', 'boolean', 'invoice', 'Show QR Code'],
        ['invoice_auto_send', '1', 'boolean', 'invoice', 'Auto-send invoice'],
        
        // Receipt Settings
        ['receipt_prefix', 'RCP', 'text', 'receipt', 'Receipt Prefix'],
        ['receipt_footer', 'Thank you for your payment!', 'textarea', 'receipt', 'Receipt Footer'],
        ['receipt_show_points', '1', 'boolean', 'receipt', 'Show Points Earned'],
        ['receipt_show_discount', '1', 'boolean', 'receipt', 'Show Discount Applied'],
        
        // Payment Settings
        ['payment_prefix', 'PMT', 'text', 'payment', 'Payment Prefix'],
        ['payment_methods', '["cash","bank_transfer","mobile_money","check"]', 'json', 'payment', 'Payment Methods'],
        ['allow_partial_payment', '1', 'boolean', 'payment', 'Allow Partial Payments'],
        ['minimum_payment_percent', '10', 'number', 'payment', 'Min Payment %'],
        
        // WhatsApp Settings
        ['whatsapp_enabled', '0', 'boolean', 'whatsapp', 'Enable WhatsApp'],
        ['whatsapp_provider', 'ultramsg', 'text', 'whatsapp', 'WhatsApp Provider'],
        ['whatsapp_api_url', '', 'text', 'whatsapp', 'WhatsApp API URL'],
        ['whatsapp_token', '', 'password', 'whatsapp', 'WhatsApp Token'],
        ['whatsapp_instance_id', '', 'text', 'whatsapp', 'Instance ID'],
        ['whatsapp_sender_number', '', 'text', 'whatsapp', 'Sender Number'],
        
        // SMS Settings
        ['sms_enabled', '0', 'boolean', 'sms', 'Enable SMS'],
        ['sms_provider', 'twilio', 'text', 'sms', 'SMS Provider'],
        ['sms_api_key', '', 'password', 'sms', 'SMS API Key'],
        ['sms_api_secret', '', 'password', 'sms', 'SMS API Secret'],
        ['sms_from_number', '', 'text', 'sms', 'SMS From Number'],
        
        // Notification Settings
        ['email_notifications', '1', 'boolean', 'notification', 'Email Notifications'],
        ['whatsapp_notifications', '1', 'boolean', 'notification', 'WhatsApp Notifications'],
        ['sms_notifications', '0', 'boolean', 'notification', 'SMS Notifications'],
        ['push_notifications', '1', 'boolean', 'notification', 'Push Notifications'],
        ['invoice_created_notify', '1', 'boolean', 'notification', 'Invoice Created'],
        ['payment_received_notify', '1', 'boolean', 'notification', 'Payment Received'],
        ['container_shipped_notify', '1', 'boolean', 'notification', 'Container Shipped'],
        ['container_arrived_notify', '1', 'boolean', 'notification', 'Container Arrived'],
        ['package_delivered_notify', '1', 'boolean', 'notification', 'Package Delivered'],
        ['debt_reminder_days', '7,14,21,30', 'text', 'notification', 'Debt Reminder Days'],
        
        // Security Settings
        ['session_timeout', '3600', 'number', 'security', 'Session Timeout'],
        ['max_login_attempts', '5', 'number', 'security', 'Max Login Attempts'],
        ['lockout_time', '900', 'number', 'security', 'Lockout Time'],
        ['password_expiry_days', '90', 'number', 'security', 'Password Expiry'],
        ['two_factor_auth', '0', 'boolean', 'security', '2FA Enabled'],
        ['force_strong_password', '1', 'boolean', 'security', 'Strong Password'],
        
        // System Limits
        ['max_file_size', '5242880', 'number', 'limit', 'Max File Size'],
        ['max_containers_per_page', '50', 'number', 'limit', 'Max Containers'],
        ['max_trips_per_page', '50', 'number', 'limit', 'Max Trips'],
        ['max_customers_per_page', '50', 'number', 'limit', 'Max Customers'],
        ['max_invoices_per_page', '50', 'number', 'limit', 'Max Invoices'],
        ['max_stock_items', '10000', 'number', 'limit', 'Max Stock Items'],
        ['backup_retention_days', '30', 'number', 'limit', 'Backup Retention'],
        
        // Branch Settings
        ['branch_enabled', '1', 'boolean', 'branch', 'Enable Branches'],
        ['allow_branch_transfer', '1', 'boolean', 'branch', 'Allow Transfer'],
        ['default_branch_id', '0', 'number', 'branch', 'Default Branch'],
        
        // Report Settings
        ['report_auto_generate', '1', 'boolean', 'report', 'Auto Reports'],
        ['report_email_recipients', '', 'text', 'report', 'Report Recipients'],
        ['report_retention_days', '365', 'number', 'report', 'Report Retention'],
        
        // Storage Settings
        ['storage_fee_enabled', '1', 'boolean', 'storage', 'Enable Storage Fee'],
        ['storage_free_days', '30', 'number', 'storage', 'Free Storage Days'],
        ['storage_fee_per_day', '5.00', 'number', 'storage', 'Fee Per Day'],
        ['storage_fee_per_cbm', '0.50', 'number', 'storage', 'Fee Per CBM']
    ];
    
    // Insert settings using INSERT IGNORE
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO system_settings (tenant_id, setting_key, setting_value, setting_type, category, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($default_settings as $setting) {
        try {
            $stmt->execute([$session_tenant_id, $setting[0], $setting[1], $setting[2], $setting[3], $setting[4]]);
        } catch (PDOException $e) {
            error_log("Settings insert error for {$setting[0]}: " . $e->getMessage());
        }
    }
    
    // Get all settings for this tenant
    $stmt = $pdo->prepare("SELECT setting_key, setting_value, setting_type, category FROM system_settings WHERE tenant_id = ? OR tenant_id IS NULL");
    $stmt->execute([$session_tenant_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    // Update tenants table with loyalty columns if needed
    try {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN IF NOT EXISTS loyalty_cbm_points INT DEFAULT 10");
        $pdo->exec("ALTER TABLE tenants ADD COLUMN IF NOT EXISTS loyalty_amount_points INT DEFAULT 5");
        $pdo->exec("ALTER TABLE tenants ADD COLUMN IF NOT EXISTS point_money_value DECIMAL(10,2) DEFAULT 0.10");
    } catch (PDOException $e) {
        error_log("Tenant columns error: " . $e->getMessage());
    }
    
} catch (PDOException $e) {
    error_log("Settings error: " . $e->getMessage());
    $message = "Database setup error: " . $e->getMessage();
    $message_type = "error";
}

// Get all tax rates for this tenant
$tax_rates = [];
try {
    // Check if tax_rates table has tenant_id column
    $checkTaxTable = $pdo->query("SHOW TABLES LIKE 'tax_rates'");
    if (!$checkTaxTable->fetch()) {
        $pdo->exec("
            CREATE TABLE tax_rates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                tax_name VARCHAR(100) NOT NULL,
                tax_rate DECIMAL(5,2) NOT NULL,
                tax_type ENUM('VAT','Sales Tax','Income Tax','Withholding','Customs','Other') DEFAULT 'VAT',
                tax_number VARCHAR(100) DEFAULT NULL,
                is_default TINYINT(1) DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                effective_from DATE DEFAULT NULL,
                effective_to DATE DEFAULT NULL,
                notes TEXT,
                created_by INT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_tenant_active (tenant_id, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        // Check if tenant_id column exists
        $checkCol = $pdo->query("SHOW COLUMNS FROM tax_rates LIKE 'tenant_id'");
        if (!$checkCol->fetch()) {
            $pdo->exec("ALTER TABLE tax_rates ADD COLUMN tenant_id INT NOT NULL AFTER id");
        }
    }
    
    $stmt = $pdo->prepare("SELECT * FROM tax_rates WHERE tenant_id = ? AND is_active = 1 ORDER BY is_default DESC, tax_name");
    $stmt->execute([$session_tenant_id]);
    $tax_rates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Insert default tax rate if none exists
    if (empty($tax_rates)) {
        $stmt = $pdo->prepare("
            INSERT INTO tax_rates (tenant_id, tax_name, tax_rate, tax_type, is_default, is_active)
            VALUES (?, 'VAT', 0, 'VAT', 1, 1)
        ");
        $stmt->execute([$session_tenant_id]);
        
        // Refresh tax rates
        $stmt = $pdo->prepare("SELECT * FROM tax_rates WHERE tenant_id = ? AND is_active = 1 ORDER BY is_default DESC, tax_name");
        $stmt->execute([$session_tenant_id]);
        $tax_rates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    error_log("Tax rates error: " . $e->getMessage());
}

// Handle form submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    require_once __DIR__ . '/../includes/admin_audit.php';
    $action = $_POST['action'] ?? '';

    // Snapshot the current value of every setting key so before/after audit
    // deltas can be recorded per action. Sensitive keys (whatsapp_token /
    // sms_api_key / sms_api_secret / smtp_password / *_password / *_secret /
    // *_token / api_key) are masked by admin_audit_mask_sensitive() before
    // the audit row is written.
    $settings_snapshot = function(array $keys) use ($pdo, $session_tenant_id): array {
        if (empty($keys)) return [];
        $in = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE tenant_id = ? AND setting_key IN ($in)");
        $stmt->execute(array_merge([$session_tenant_id], $keys));
        $out = [];
        foreach ($keys as $k) { $out[$k] = null; }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
        }
        return $out;
    };
    // Central audit record for a settings mutation. Records only when the
    // caller has observed a successful commit and passes the pre-mutation
    // snapshot. Records TENANT_SETTINGS_UPDATED with the section label so
    // reviewers can see which panel was changed.
    $settings_audit = function(string $section, array $keys, array $before) use ($pdo, $session_tenant_id, $settings_snapshot): void {
        $after = $settings_snapshot($keys);
        // Include a section tag so before/after arrays remain human-readable.
        $before_out = ['_section' => $section] + $before;
        $after_out  = ['_section' => $section] + $after;
        record_admin_audit(
            $pdo,
            'TENANT_SETTINGS_UPDATED',
            'tenants',
            (int)$session_tenant_id,
            $before_out,
            $after_out,
            (int)$session_tenant_id
        );
    };

    // Helper function to update settings
    $updateSetting = function($key, $value) use ($pdo, $session_tenant_id, $user_id) {
        // Check if record exists for this tenant
        $check = $pdo->prepare("SELECT id FROM system_settings WHERE tenant_id = ? AND setting_key = ?");
        $check->execute([$session_tenant_id, $key]);
        
        if ($check->fetch()) {
            // Update existing
            $stmt = $pdo->prepare("
                UPDATE system_settings 
                SET setting_value = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = ? AND setting_key = ?
            ");
            return $stmt->execute([$value, $user_id, $session_tenant_id, $key]);
        } else {
            // Insert new
            $stmt = $pdo->prepare("
                INSERT INTO system_settings (tenant_id, setting_key, setting_value, setting_type, updated_by)
                VALUES (?, ?, ?, 'text', ?)
            ");
            return $stmt->execute([$session_tenant_id, $key, $value, $user_id]);
        }
    };
    
    if ($action === 'save_general') {
        $general_settings = [
            'system_name', 'system_timezone', 'date_format', 'time_format',
            'default_language', 'default_currency', 'currency_symbol', 'currency_position',
            'items_per_page', 'company_name', 'company_email', 'company_phone',
            'company_address', 'company_website', 'company_tax_number', 'company_registration_number'
        ];
        $__audit_before = $settings_snapshot($general_settings);
        try {
            $pdo->beginTransaction();
            foreach ($general_settings as $key) {
                $value = $_POST[$key] ?? $settings[$key] ?? '';
                if ($key === 'currency_position' && !in_array($value, ['before', 'after'])) {
                    $value = 'before';
                }
                $updateSetting($key, $value);
            }
            
            // Update tenant name separately if changed
            $company_name = $_POST['company_name'] ?? $tenant_name;
            if ($company_name != $tenant_name) {
                $stmt = $pdo->prepare("UPDATE tenants SET name = ? WHERE id = ?");
                $stmt->execute([$company_name, $session_tenant_id]);
                $tenant_name = $company_name;
                $_SESSION['tenant_name'] = $company_name;
            }
            
            // Handle logo upload
            if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../uploads/logos/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $ext = strtolower(pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
                
                if (in_array($ext, $allowed)) {
                    $logoName = 'logo_' . $session_tenant_id . '_' . time() . '.' . $ext;
                    $targetPath = $uploadDir . $logoName;
                    if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $targetPath)) {
                        $logoPath = 'uploads/logos/' . $logoName;
                        $updateSetting('company_logo', $logoPath);
                    }
                }
            }
            
            $pdo->commit();
            $settings_audit('general', $general_settings, $__audit_before);
            $message = "✅ General settings saved successfully!";
            $message_type = "success";

            // Refresh settings
            $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE tenant_id = ?");
            $stmt->execute([$session_tenant_id]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "❌ Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_loyalty') {
        $loyalty_settings = [
            'loyalty_enabled', 'loyalty_cbm_points', 'loyalty_amount_points', 
            'loyalty_redemption_rate', 'loyalty_min_points_redeem', 'loyalty_points_expiry_days',
            'loyalty_points_on_invoice', 'loyalty_points_on_cbm', 'loyalty_points_on_money',
            'loyalty_max_discount_percent', 'loyalty_birthday_points', 'loyalty_referral_points'
        ];
        $__audit_before = $settings_snapshot($loyalty_settings);
        try {
            $pdo->beginTransaction();
            foreach ($loyalty_settings as $key) {
                if (in_array($key, ['loyalty_enabled', 'loyalty_points_on_invoice', 'loyalty_points_on_cbm', 'loyalty_points_on_money'])) {
                    $value = isset($_POST[$key]) ? '1' : '0';
                } else {
                    $value = $_POST[$key] ?? $settings[$key] ?? '0';
                }
                $updateSetting($key, $value);
            }
            
            // Also update tenants table for compatibility
            $stmt = $pdo->prepare("
                UPDATE tenants SET 
                    loyalty_cbm_points = ?,
                    loyalty_amount_points = ?,
                    point_money_value = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $_POST['loyalty_cbm_points'] ?? 10,
                $_POST['loyalty_amount_points'] ?? 5,
                ($_POST['loyalty_redemption_rate'] ?? 0.10),
                $session_tenant_id
            ]);
            
            $pdo->commit();
            $settings_audit('loyalty', $loyalty_settings, $__audit_before);
            $message = "✅ Loyalty settings saved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "❌ Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_tax') {
        $tax_settings = [
            'tax_enabled', 'default_tax_rate', 'tax_calculation_method', 'tax_period',
            'tax_authority_name', 'tax_authority_email', 'tax_authority_phone', 
            'tax_office_address', 'tax_number', 'tax_invoice_include', 'tax_rounding'
        ];
        $__audit_before = $settings_snapshot($tax_settings);
        try {
            $pdo->beginTransaction();
            foreach ($tax_settings as $key) {
                if (in_array($key, ['tax_enabled', 'tax_invoice_include'])) {
                    $value = isset($_POST[$key]) ? '1' : '0';
                } else {
                    $value = $_POST[$key] ?? $settings[$key] ?? '';
                }
                $updateSetting($key, $value);
            }
            $pdo->commit();
            $settings_audit('tax', $tax_settings, $__audit_before);
            $message = "✅ Tax settings saved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "❌ Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_invoice') {
        $invoice_settings = ['invoice_prefix', 'invoice_due_days', 'invoice_terms', 'invoice_footer', 'invoice_show_qr', 'invoice_auto_send'];
        $__audit_before = $settings_snapshot($invoice_settings);
        try {
            $pdo->beginTransaction();
            foreach ($invoice_settings as $key) {
                if (in_array($key, ['invoice_show_qr', 'invoice_auto_send'])) {
                    $value = isset($_POST[$key]) ? '1' : '0';
                } else {
                    $value = $_POST[$key] ?? $settings[$key] ?? '';
                }
                $updateSetting($key, $value);
            }
            $pdo->commit();
            $settings_audit('invoice', $invoice_settings, $__audit_before);
            $message = "✅ Invoice settings saved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "❌ Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_receipt') {
        $receipt_settings = ['receipt_prefix', 'receipt_footer', 'receipt_show_points', 'receipt_show_discount'];
        $__audit_before = $settings_snapshot($receipt_settings);
        try {
            $pdo->beginTransaction();
            foreach ($receipt_settings as $key) {
                if (in_array($key, ['receipt_show_points', 'receipt_show_discount'])) {
                    $value = isset($_POST[$key]) ? '1' : '0';
                } else {
                    $value = $_POST[$key] ?? $settings[$key] ?? '';
                }
                $updateSetting($key, $value);
            }
            $pdo->commit();
            $settings_audit('receipt', $receipt_settings, $__audit_before);
            $message = "✅ Receipt settings saved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "❌ Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_payment') {
        $payment_settings = ['payment_prefix', 'allow_partial_payment', 'minimum_payment_percent', 'payment_methods'];
        $__audit_before = $settings_snapshot($payment_settings);
        try {
            $pdo->beginTransaction();
            foreach ($payment_settings as $key) {
                if ($key === 'allow_partial_payment') {
                    $value = isset($_POST[$key]) ? '1' : '0';
                } else {
                    $value = $_POST[$key] ?? $settings[$key] ?? '';
                }
                $updateSetting($key, $value);
            }
            
            // Handle payment methods JSON
            $payment_methods = $_POST['payment_methods'] ?? [];
            $updateSetting('payment_methods', json_encode($payment_methods));
            
            $pdo->commit();
            $settings_audit('payment', $payment_settings, $__audit_before);
            $message = "✅ Payment settings saved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "❌ Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_whatsapp') {
        $whatsapp_settings = ['whatsapp_enabled', 'whatsapp_provider', 'whatsapp_api_url', 'whatsapp_token', 'whatsapp_instance_id', 'whatsapp_sender_number'];
        $__audit_before = $settings_snapshot($whatsapp_settings);
        try {
            $pdo->beginTransaction();
            foreach ($whatsapp_settings as $key) {
                if ($key === 'whatsapp_enabled') {
                    $value = isset($_POST[$key]) ? '1' : '0';
                } elseif ($key === 'whatsapp_token' && trim((string)($_POST[$key] ?? '')) === '') {
                    continue;
                } else {
                    $value = $_POST[$key] ?? $settings[$key] ?? '';
                }
                $updateSetting($key, $value);
            }
            $pdo->commit();
            $settings_audit('whatsapp', $whatsapp_settings, $__audit_before);
            $message = "✅ WhatsApp settings saved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "❌ Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_sms') {
        $sms_settings = ['sms_enabled', 'sms_provider', 'sms_api_key', 'sms_api_secret', 'sms_from_number'];
        $__audit_before = $settings_snapshot($sms_settings);
        try {
            $pdo->beginTransaction();
            foreach ($sms_settings as $key) {
                if ($key === 'sms_enabled') {
                    $value = isset($_POST[$key]) ? '1' : '0';
                } elseif (in_array($key, ['sms_api_key', 'sms_api_secret'], true) && trim((string)($_POST[$key] ?? '')) === '') {
                    continue;
                } else {
                    $value = $_POST[$key] ?? $settings[$key] ?? '';
                }
                $updateSetting($key, $value);
            }
            $pdo->commit();
            $settings_audit('sms', $sms_settings, $__audit_before);
            $message = "✅ SMS settings saved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "❌ Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_notification') {
        $notification_settings = [
            'email_notifications', 'whatsapp_notifications', 'sms_notifications', 'push_notifications',
            'invoice_created_notify', 'payment_received_notify', 'container_shipped_notify',
            'container_arrived_notify', 'package_delivered_notify', 'debt_reminder_days'
        ];
        $__audit_before = $settings_snapshot($notification_settings);
        try {
            $pdo->beginTransaction();
            foreach ($notification_settings as $key) {
                if (in_array($key, ['email_notifications', 'whatsapp_notifications', 'sms_notifications', 'push_notifications',
                                    'invoice_created_notify', 'payment_received_notify', 'container_shipped_notify',
                                    'container_arrived_notify', 'package_delivered_notify'])) {
                    $value = isset($_POST[$key]) ? '1' : '0';
                } else {
                    $value = $_POST[$key] ?? $settings[$key] ?? '';
                }
                $updateSetting($key, $value);
            }
            $pdo->commit();
            $settings_audit('notification', $notification_settings, $__audit_before);
            $message = "✅ Notification settings saved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "❌ Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_security') {
        $security_settings = ['session_timeout', 'max_login_attempts', 'lockout_time', 'password_expiry_days', 'two_factor_auth', 'force_strong_password'];
        $__audit_before = $settings_snapshot($security_settings);
        try {
            $pdo->beginTransaction();
            foreach ($security_settings as $key) {
                if (in_array($key, ['two_factor_auth', 'force_strong_password'])) {
                    $value = isset($_POST[$key]) ? '1' : '0';
                } else {
                    $value = $_POST[$key] ?? $settings[$key] ?? '';
                }
                $updateSetting($key, $value);
            }
            $pdo->commit();
            $settings_audit('security', $security_settings, $__audit_before);
            $message = "✅ Security settings saved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "❌ Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_limits') {
        $limit_settings = ['max_file_size', 'max_containers_per_page', 'max_trips_per_page', 'max_customers_per_page', 'max_invoices_per_page', 'max_stock_items', 'backup_retention_days'];
        $__audit_before = $settings_snapshot($limit_settings);
        try {
            $pdo->beginTransaction();
            foreach ($limit_settings as $key) {
                $value = $_POST[$key] ?? $settings[$key] ?? '';
                $updateSetting($key, $value);
            }
            $pdo->commit();
            $settings_audit('limits', $limit_settings, $__audit_before);
            $message = "✅ System limits saved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "❌ Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_branch') {
        $branch_settings = ['branch_enabled', 'allow_branch_transfer', 'default_branch_id'];
        $__audit_before = $settings_snapshot($branch_settings);
        try {
            $pdo->beginTransaction();
            foreach ($branch_settings as $key) {
                if (in_array($key, ['branch_enabled', 'allow_branch_transfer'])) {
                    $value = isset($_POST[$key]) ? '1' : '0';
                } else {
                    $value = $_POST[$key] ?? $settings[$key] ?? '0';
                }
                $updateSetting($key, $value);
            }
            $pdo->commit();
            $settings_audit('branch', $branch_settings, $__audit_before);
            $message = "✅ Branch settings saved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "❌ Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_storage') {
        $storage_settings = ['storage_fee_enabled', 'storage_free_days', 'storage_fee_per_day', 'storage_fee_per_cbm'];
        $__audit_before = $settings_snapshot($storage_settings);
        try {
            $pdo->beginTransaction();
            foreach ($storage_settings as $key) {
                if ($key === 'storage_fee_enabled') {
                    $value = isset($_POST[$key]) ? '1' : '0';
                } else {
                    $value = $_POST[$key] ?? $settings[$key] ?? '0';
                }
                $updateSetting($key, $value);
            }
            $pdo->commit();
            $settings_audit('storage', $storage_settings, $__audit_before);
            $message = "✅ Storage settings saved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "❌ Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_report') {
        $report_settings = ['report_auto_generate', 'report_email_recipients', 'report_retention_days'];
        $__audit_before = $settings_snapshot($report_settings);
        try {
            $pdo->beginTransaction();
            foreach ($report_settings as $key) {
                if ($key === 'report_auto_generate') {
                    $value = isset($_POST[$key]) ? '1' : '0';
                } else {
                    $value = $_POST[$key] ?? $settings[$key] ?? '';
                }
                $updateSetting($key, $value);
            }
            $pdo->commit();
            $settings_audit('report', $report_settings, $__audit_before);
            $message = "✅ Report settings saved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "❌ Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'test_whatsapp') {
        $to = $_POST['test_phone'] ?? '';
        if ($to) {
            $to = preg_replace('/[^0-9]/', '', $to);
            if (strlen($to) === 9 && ($to[0] === '6' || $to[0] === '7')) {
                $to = '252' . $to;
            }
            
            $result = $messaging->sendWhatsApp($to, "🔧 SYSTEM TEST: WhatsApp API is working correctly! ✅\nTime: " . date('Y-m-d H:i:s'));
            $message = $result['message'];
            $message_type = $result['success'] ? "success" : "error";
        } else {
            $message = "❌ Please enter a phone number to test.";
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_tax_rate') {
        $rate_id = $_POST['rate_id'] ?? 0;
        $tax_name = $_POST['tax_name'] ?? '';
        $tax_rate = $_POST['tax_rate'] ?? 0;
        $tax_type = $_POST['tax_type'] ?? 'VAT';
        $tax_number = $_POST['tax_number'] ?? '';
        $is_default = isset($_POST['is_default']) ? 1 : 0;
        $effective_from = $_POST['effective_from'] ?? null;
        $effective_to = $_POST['effective_to'] ?? null;
        $notes = $_POST['notes'] ?? '';
        
        if (empty($tax_name)) {
            $message = "❌ Tax name is required!";
            $message_type = "error";
        } else {
            try {
                if ($is_default) {
                    $pdo->prepare("UPDATE tax_rates SET is_default = 0 WHERE tenant_id = ?")->execute([$session_tenant_id]);
                }
                
                if ($rate_id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE tax_rates SET 
                            tax_name = ?, tax_rate = ?, tax_type = ?, tax_number = ?,
                            is_default = ?, effective_from = ?, effective_to = ?, notes = ?
                        WHERE id = ? AND tenant_id = ?
                    ");
                    $stmt->execute([$tax_name, $tax_rate, $tax_type, $tax_number, $is_default, $effective_from, $effective_to, $notes, $rate_id, $session_tenant_id]);
                    $message = "✅ Tax rate updated successfully!";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO tax_rates (tenant_id, tax_name, tax_rate, tax_type, tax_number, is_default, effective_from, effective_to, notes, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$session_tenant_id, $tax_name, $tax_rate, $tax_type, $tax_number, $is_default, $effective_from, $effective_to, $notes, $user_id]);
                    $message = "✅ Tax rate added successfully!";
                }
                $message_type = "success";
                
                // Refresh tax rates
                $stmt = $pdo->prepare("SELECT * FROM tax_rates WHERE tenant_id = ? AND is_active = 1 ORDER BY is_default DESC, tax_name");
                $stmt->execute([$session_tenant_id]);
                $tax_rates = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
            } catch (PDOException $e) {
                $message = "❌ Error: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
    
    elseif ($action === 'delete_tax_rate') {
        $rate_id = $_POST['rate_id'] ?? 0;
        try {
            $stmt = $pdo->prepare("UPDATE tax_rates SET is_active = 0 WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$rate_id, $session_tenant_id]);
            $message = "✅ Tax rate deleted successfully!";
            $message_type = "success";
            
            // Refresh tax rates
            $stmt = $pdo->prepare("SELECT * FROM tax_rates WHERE tenant_id = ? AND is_active = 1 ORDER BY is_default DESC, tax_name");
            $stmt->execute([$session_tenant_id]);
            $tax_rates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            $message = "❌ Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Decode JSON settings
$payment_methods_array = json_decode($settings['payment_methods'] ?? '["cash","bank_transfer","mobile_money","check"]', true);
if (!is_array($payment_methods_array)) {
    $payment_methods_array = ['cash', 'bank_transfer', 'mobile_money', 'check'];
}

$available_payment_methods = [
    'cash' => 'Cash',
    'bank_transfer' => 'Bank Transfer',
    'mobile_money' => 'Mobile Money',
    'check' => 'Check',
    'credit_card' => 'Credit Card'
];

// Get branches for branch settings
$branches = [];
try {
    $stmt = $pdo->prepare("SELECT id, branch_name FROM branches WHERE tenant_id = ? AND status = 'active'");
    $stmt->execute([$session_tenant_id]);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Branches error: " . $e->getMessage());
}

require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Settings - <?= htmlspecialchars($tenant_name) ?> | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root {
            --curdun-violet: #2D1859;
            --curdun-yellow: #F5C410;
            --curdun-violet-light: #4B2C85;
            --curdun-gray: #6c757d;
            --curdun-dark: #2D2D2D;
            --curdun-success: #0F7A3A;
            --curdun-danger: #B42318;
            --curdun-info: #17a2b8;
            --curdun-warning: #ff9800;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }

        .page-header {
            background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light));
            border-radius: 16px;
            padding: 20px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .page-header h1 { color: white; font-size: 24px; margin: 0; }
        .page-header h1 i { margin-right: 10px; }
        .page-header .company-badge {
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
        }

        .settings-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 25px;
            flex-wrap: wrap;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 10px;
        }
        .settings-tab {
            background: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            color: var(--curdun-dark);
            font-size: 14px;
        }
        .settings-tab.active {
            background: var(--curdun-violet);
            color: white;
        }
        .settings-tab:hover:not(.active) {
            background: #e0e0e0;
        }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }

        .settings-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .settings-card h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--curdun-violet);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--curdun-violet);
        }
        .settings-card h3 i { margin-right: 8px; }

        .form-group { margin-bottom: 20px; }
        .form-group label { font-weight: 600; font-size: 13px; color: var(--curdun-gray); margin-bottom: 8px; display: block; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: var(--curdun-violet);
            box-shadow: 0 0 0 3px rgba(82,0,102,0.1);
        }
        .form-row { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 20px; }
        .form-row .form-group { flex: 1; min-width: 200px; margin-bottom: 0; }

        .btn-save {
            background: var(--curdun-violet);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-save:hover {
            background: var(--curdun-yellow);
            color: var(--curdun-violet);
            transform: translateY(-2px);
        }

        .alert-custom {
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            animation: slideIn 0.3s ease;
            border-radius: 8px;
            padding: 12px 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .alert-success { background: #EEFBF3; color: #0F7A3A; border-left: 4px solid #0F7A3A; }
        .alert-error { background: #FEF0EE; color: #B42318; border-left: 4px solid #B42318; }

        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: 0.4s;
            border-radius: 24px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: 0.4s;
            border-radius: 50%;
        }
        input:checked + .slider { background-color: var(--curdun-success); }
        input:checked + .slider:before { transform: translateX(26px); }
        .switch-label { margin-left: 60px; font-size: 13px; display: inline-block; }

        .logo-preview {
            max-width: 150px;
            max-height: 150px;
            margin-top: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 5px;
        }
        
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid var(--curdun-info);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .warning-box {
            background: #fff3e0;
            border-left: 4px solid var(--curdun-warning);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .tax-rate-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .tax-rate-table th, .tax-rate-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        .tax-rate-table th {
            background: #f5f5f5;
            font-weight: 600;
            color: var(--curdun-dark);
        }
        .badge-default {
            background: #EEFBF3;
            color: #0F7A3A;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: center; }
            .settings-tabs { justify-content: center; }
            .form-row { flex-direction: column; }
            .alert-custom { left: 20px; right: 20px; min-width: auto; top: 70px; }
        }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <?php if ($message): ?>
    <div class="alert-custom alert-<?= $message_type ?>" id="alertBox">
        <i class="fas <?= $message_type == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i> <?= htmlspecialchars($message) ?>
    </div>
    <script>
        setTimeout(() => {
            const box = document.getElementById('alertBox');
            if(box) { box.style.transition = 'opacity 0.6s ease'; box.style.opacity = '0'; setTimeout(() => box.remove(), 600); }
        }, 4000);
    </script>
    <?php endif; ?>

    <div class="page-header">
        <h1><i class="fas fa-cog"></i> Company Settings</h1>
        <div class="d-flex gap-3 align-items-center">
            <span class="company-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($tenant_name) ?></span>
        </div>
    </div>

    <div class="settings-tabs">
        <button class="settings-tab active" data-tab="general"><i class="fas fa-globe"></i> General</button>
        <button class="settings-tab" data-tab="loyalty"><i class="fas fa-star"></i> Loyalty</button>
        <button class="settings-tab" data-tab="tax"><i class="fas fa-percent"></i> Tax</button>
        <button class="settings-tab" data-tab="invoice"><i class="fas fa-file-invoice"></i> Invoice</button>
        <button class="settings-tab" data-tab="receipt"><i class="fas fa-receipt"></i> Receipt</button>
        <button class="settings-tab" data-tab="payment"><i class="fas fa-credit-card"></i> Payment</button>
        <button class="settings-tab" data-tab="whatsapp"><i class="fab fa-whatsapp"></i> WhatsApp</button>
        <button class="settings-tab" data-tab="sms"><i class="fas fa-sms"></i> SMS</button>
        <button class="settings-tab" data-tab="notifications"><i class="fas fa-bell"></i> Notifications</button>
        <button class="settings-tab" data-tab="security"><i class="fas fa-shield-alt"></i> Security</button>
        <button class="settings-tab" data-tab="limits"><i class="fas fa-tachometer-alt"></i> Limits</button>
        <button class="settings-tab" data-tab="branch"><i class="fas fa-store"></i> Branches</button>
        <button class="settings-tab" data-tab="storage"><i class="fas fa-warehouse"></i> Storage</button>
        <button class="settings-tab" data-tab="report"><i class="fas fa-chart-line"></i> Reports</button>
    </div>

    <!-- ==================== GENERAL TAB ==================== -->
    <div id="general-tab" class="tab-pane active">
        <form method="POST" class="settings-card" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_general">
            <h3><i class="fas fa-globe"></i> General Settings</h3>
            <div class="info-box">
                <i class="fas fa-info-circle"></i> These settings control the basic configuration of your company and system.
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Company Name</label>
                    <input type="text" name="company_name" value="<?= htmlspecialchars($settings['company_name'] ?? $tenant_name) ?>">
                </div>
                <div class="form-group">
                    <label>System Name</label>
                    <input type="text" name="system_name" value="<?= htmlspecialchars($settings['system_name'] ?? 'CURDUN CARGO') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Company Email</label>
                    <input type="email" name="company_email" value="<?= htmlspecialchars($settings['company_email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Company Phone</label>
                    <input type="text" name="company_phone" value="<?= htmlspecialchars($settings['company_phone'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Company Website</label>
                    <input type="url" name="company_website" value="<?= htmlspecialchars($settings['company_website'] ?? '') ?>" placeholder="https://example.com">
                </div>
                <div class="form-group">
                    <label>Tax Number / VAT ID</label>
                    <input type="text" name="company_tax_number" value="<?= htmlspecialchars($settings['company_tax_number'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Registration Number</label>
                    <input type="text" name="company_registration_number" value="<?= htmlspecialchars($settings['company_registration_number'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Company Address</label>
                <textarea name="company_address" rows="2"><?= htmlspecialchars($settings['company_address'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Company Logo</label>
                <input type="file" name="company_logo" accept="image/*">
                <?php if (!empty($settings['company_logo'])): ?>
                <div>
                    <img src="../<?= htmlspecialchars($settings['company_logo']) ?>" class="logo-preview" alt="Company Logo">
                </div>
                <?php endif; ?>
                <small class="text-muted">Recommended size: 200x60px. Max 2MB.</small>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Default Language</label>
                    <select name="default_language">
                        <?php $langs = ['en' => 'English', 'so' => 'Soomaali', 'ar' => 'العربية']; ?>
                        <?php foreach ($langs as $code => $name): ?>
                            <option value="<?= $code ?>" <?= ($settings['default_language'] ?? 'en') == $code ? 'selected' : '' ?>><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Timezone</label>
                    <select name="system_timezone">
                        <?php $timezones = [
                            'Africa/Mogadishu' => 'Africa/Mogadishu (Somalia)',
                            'Africa/Nairobi' => 'Africa/Nairobi (Kenya)',
                            'Asia/Dubai' => 'Asia/Dubai (UAE)',
                            'UTC' => 'UTC'
                        ]; ?>
                        <?php foreach ($timezones as $tz => $name): ?>
                            <option value="<?= $tz ?>" <?= ($settings['system_timezone'] ?? 'Africa/Mogadishu') == $tz ? 'selected' : '' ?>><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Default Currency</label>
                    <select name="default_currency">
                        <?php $currencies = [
                            'USD' => 'US Dollar ($)',
                            'EUR' => 'Euro (€)',
                            'GBP' => 'British Pound (£)',
                            'SOS' => 'Somali Shilling (SSh)',
                            'AED' => 'UAE Dirham (د.إ)'
                        ]; ?>
                        <?php foreach ($currencies as $code => $name): ?>
                            <option value="<?= $code ?>" <?= ($settings['default_currency'] ?? 'USD') == $code ? 'selected' : '' ?>><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Currency Symbol</label>
                    <input type="text" name="currency_symbol" value="<?= htmlspecialchars($settings['currency_symbol'] ?? '$') ?>" style="width: 80px;">
                </div>
                <div class="form-group">
                    <label>Currency Position</label>
                    <select name="currency_position">
                        <option value="before" <?= ($settings['currency_position'] ?? 'before') == 'before' ? 'selected' : '' ?>>Before amount ($100)</option>
                        <option value="after" <?= ($settings['currency_position'] ?? 'before') == 'after' ? 'selected' : '' ?>>After amount (100$)</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Date Format</label>
                    <select name="date_format">
                        <option value="d/m/Y" <?= ($settings['date_format'] ?? 'd/m/Y') == 'd/m/Y' ? 'selected' : '' ?>>DD/MM/YYYY</option>
                        <option value="m/d/Y" <?= ($settings['date_format'] ?? 'd/m/Y') == 'm/d/Y' ? 'selected' : '' ?>>MM/DD/YYYY</option>
                        <option value="Y-m-d" <?= ($settings['date_format'] ?? 'd/m/Y') == 'Y-m-d' ? 'selected' : '' ?>>YYYY-MM-DD</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Time Format</label>
                    <select name="time_format">
                        <option value="H:i:s" <?= ($settings['time_format'] ?? 'H:i:s') == 'H:i:s' ? 'selected' : '' ?>>24 Hour</option>
                        <option value="h:i:s A" <?= ($settings['time_format'] ?? 'H:i:s') == 'h:i:s A' ? 'selected' : '' ?>>12 Hour</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Items Per Page</label>
                    <input type="number" name="items_per_page" value="<?= htmlspecialchars($settings['items_per_page'] ?? '15') ?>">
                </div>
            </div>
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save General Settings</button>
        </form>
    </div>

    <!-- ==================== LOYALTY TAB ==================== -->
    <div id="loyalty-tab" class="tab-pane">
        <form method="POST" class="settings-card">
            <input type="hidden" name="action" value="save_loyalty">
            <h3><i class="fas fa-star"></i> Loyalty Points Settings</h3>
            <div class="info-box">
                <i class="fas fa-info-circle"></i> Loyalty points allow customers to earn rewards based on their spending and shipments.
            </div>
            
            <div class="form-group">
                <label class="switch-label">
                    <label class="switch">
                        <input type="checkbox" name="loyalty_enabled" <?= ($settings['loyalty_enabled'] ?? '1') == '1' ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    Enable Loyalty Points System
                </label>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Points per CBM</label>
                    <input type="number" step="1" name="loyalty_cbm_points" value="<?= htmlspecialchars($settings['loyalty_cbm_points'] ?? '10') ?>">
                </div>
                <div class="form-group">
                    <label>Points per $100 Spent</label>
                    <input type="number" step="1" name="loyalty_amount_points" value="<?= htmlspecialchars($settings['loyalty_amount_points'] ?? '5') ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Points to Money Value ($ per point)</label>
                    <input type="number" step="0.01" name="loyalty_redemption_rate" value="<?= htmlspecialchars($settings['loyalty_redemption_rate'] ?? '0.10') ?>">
                </div>
                <div class="form-group">
                    <label>Minimum Points to Redeem</label>
                    <input type="number" name="loyalty_min_points_redeem" value="<?= htmlspecialchars($settings['loyalty_min_points_redeem'] ?? '100') ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Points Expiry Days</label>
                    <input type="number" name="loyalty_points_expiry_days" value="<?= htmlspecialchars($settings['loyalty_points_expiry_days'] ?? '365') ?>">
                </div>
                <div class="form-group">
                    <label>Maximum Discount Percent</label>
                    <input type="number" step="1" name="loyalty_max_discount_percent" value="<?= htmlspecialchars($settings['loyalty_max_discount_percent'] ?? '50') ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Birthday Bonus Points</label>
                    <input type="number" name="loyalty_birthday_points" value="<?= htmlspecialchars($settings['loyalty_birthday_points'] ?? '50') ?>">
                </div>
                <div class="form-group">
                    <label>Referral Bonus Points</label>
                    <input type="number" name="loyalty_referral_points" value="<?= htmlspecialchars($settings['loyalty_referral_points'] ?? '100') ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="switch-label">
                        <label class="switch">
                            <input type="checkbox" name="loyalty_points_on_invoice" <?= ($settings['loyalty_points_on_invoice'] ?? '1') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        Earn points on Invoice
                    </label>
                </div>
                <div class="form-group">
                    <label class="switch-label">
                        <label class="switch">
                            <input type="checkbox" name="loyalty_points_on_cbm" <?= ($settings['loyalty_points_on_cbm'] ?? '1') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        Earn points on CBM
                    </label>
                </div>
                <div class="form-group">
                    <label class="switch-label">
                        <label class="switch">
                            <input type="checkbox" name="loyalty_points_on_money" <?= ($settings['loyalty_points_on_money'] ?? '1') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        Earn points on Money
                    </label>
                </div>
            </div>
            
            <div class="warning-box">
                <i class="fas fa-exclamation-triangle"></i> <strong>Note:</strong> Changes to loyalty settings will affect future point calculations only.
            </div>
            
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Loyalty Settings</button>
        </form>
    </div>

    <!-- ==================== TAX TAB ==================== -->
    <div id="tax-tab" class="tab-pane">
        <form method="POST" class="settings-card">
            <input type="hidden" name="action" value="save_tax">
            <h3><i class="fas fa-percent"></i> Tax Settings</h3>
            <div class="info-box">
                <i class="fas fa-info-circle"></i> Configure tax rates and calculation methods for invoices.
            </div>
            
            <div class="form-group">
                <label class="switch-label">
                    <label class="switch">
                        <input type="checkbox" name="tax_enabled" <?= ($settings['tax_enabled'] ?? '1') == '1' ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    Enable Tax Calculation
                </label>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Default Tax Rate (%)</label>
                    <input type="number" step="0.01" name="default_tax_rate" value="<?= htmlspecialchars($settings['default_tax_rate'] ?? '0') ?>">
                </div>
                <div class="form-group">
                    <label>Tax Calculation Method</label>
                    <select name="tax_calculation_method">
                        <option value="exclusive" <?= ($settings['tax_calculation_method'] ?? 'exclusive') == 'exclusive' ? 'selected' : '' ?>>Exclusive (Tax added)</option>
                        <option value="inclusive" <?= ($settings['tax_calculation_method'] ?? 'exclusive') == 'inclusive' ? 'selected' : '' ?>>Inclusive (Tax included)</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Tax Reporting Period</label>
                    <select name="tax_period">
                        <option value="monthly" <?= ($settings['tax_period'] ?? 'monthly') == 'monthly' ? 'selected' : '' ?>>Monthly</option>
                        <option value="quarterly" <?= ($settings['tax_period'] ?? 'monthly') == 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                        <option value="annually" <?= ($settings['tax_period'] ?? 'monthly') == 'annually' ? 'selected' : '' ?>>Annually</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tax Number</label>
                    <input type="text" name="tax_number" value="<?= htmlspecialchars($settings['tax_number'] ?? '') ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Tax Authority Name</label>
                    <input type="text" name="tax_authority_name" value="<?= htmlspecialchars($settings['tax_authority_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Tax Authority Email</label>
                    <input type="email" name="tax_authority_email" value="<?= htmlspecialchars($settings['tax_authority_email'] ?? '') ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Tax Authority Phone</label>
                    <input type="text" name="tax_authority_phone" value="<?= htmlspecialchars($settings['tax_authority_phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Tax Rounding Decimals</label>
                    <input type="number" name="tax_rounding" value="<?= htmlspecialchars($settings['tax_rounding'] ?? '2') ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label>Tax Office Address</label>
                <textarea name="tax_office_address" rows="2"><?= htmlspecialchars($settings['tax_office_address'] ?? '') ?></textarea>
            </div>
            
            <div class="form-group">
                <label class="switch-label">
                    <label class="switch">
                        <input type="checkbox" name="tax_invoice_include" <?= ($settings['tax_invoice_include'] ?? '1') == '1' ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    Include Tax on Invoices
                </label>
            </div>
            
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Tax Settings</button>
        </form>
        
        <!-- Tax Rates Management -->
        <div class="settings-card">
            <h3><i class="fas fa-list"></i> Tax Rates</h3>
            <table class="tax-rate-table">
                <thead>
                    <tr>
                        <th>Tax Name</th>
                        <th>Rate (%)</th>
                        <th>Type</th>
                        <th>Tax Number</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tax_rates as $rate): ?>
                    <tr>
                        <td><?= htmlspecialchars($rate['tax_name']) ?> <?= $rate['is_default'] ? '<span class="badge-default">Default</span>' : '' ?></td>
                        <td><?= number_format($rate['tax_rate'], 2) ?>%</td>
                        <td><?= $rate['tax_type'] ?></td>
                        <td><?= htmlspecialchars($rate['tax_number'] ?? '-') ?></td>
                        <td><span class="badge badge-success">Active</span></td>
                        <td>
                            <button class="btn btn-sm btn-info" onclick="editTaxRate(<?= htmlspecialchars(json_encode($rate)) ?>)"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-danger" onclick="deleteTaxRate(<?= $rate['id'] ?>)"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <button class="btn btn-primary mt-3" onclick="showAddTaxRateModal()"><i class="fas fa-plus"></i> Add Tax Rate</button>
        </div>
    </div>

    <!-- ==================== INVOICE TAB ==================== -->
    <div id="invoice-tab" class="tab-pane">
        <form method="POST" class="settings-card">
            <input type="hidden" name="action" value="save_invoice">
            <h3><i class="fas fa-file-invoice"></i> Invoice Settings</h3>
            <div class="form-row">
                <div class="form-group">
                    <label>Invoice Prefix</label>
                    <input type="text" name="invoice_prefix" value="<?= htmlspecialchars($settings['invoice_prefix'] ?? 'INV') ?>">
                </div>
                <div class="form-group">
                    <label>Default Due Days</label>
                    <input type="number" name="invoice_due_days" value="<?= htmlspecialchars($settings['invoice_due_days'] ?? '30') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="switch-label">
                        <label class="switch">
                            <input type="checkbox" name="invoice_show_qr" <?= ($settings['invoice_show_qr'] ?? '1') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        Show QR Code
                    </label>
                </div>
                <div class="form-group">
                    <label class="switch-label">
                        <label class="switch">
                            <input type="checkbox" name="invoice_auto_send" <?= ($settings['invoice_auto_send'] ?? '1') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        Auto-send invoice
                    </label>
                </div>
            </div>
            <div class="form-group">
                <label>Default Invoice Terms</label>
                <textarea name="invoice_terms" rows="3"><?= htmlspecialchars($settings['invoice_terms'] ?? 'Payment is due within 30 days.') ?></textarea>
            </div>
            <div class="form-group">
                <label>Invoice Footer Text</label>
                <textarea name="invoice_footer" rows="2"><?= htmlspecialchars($settings['invoice_footer'] ?? 'Thank you for your business!') ?></textarea>
            </div>
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Invoice Settings</button>
        </form>
    </div>

    <!-- ==================== RECEIPT TAB ==================== -->
    <div id="receipt-tab" class="tab-pane">
        <form method="POST" class="settings-card">
            <input type="hidden" name="action" value="save_receipt">
            <h3><i class="fas fa-receipt"></i> Receipt Settings</h3>
            <div class="form-row">
                <div class="form-group">
                    <label>Receipt Prefix</label>
                    <input type="text" name="receipt_prefix" value="<?= htmlspecialchars($settings['receipt_prefix'] ?? 'RCP') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="switch-label">
                        <label class="switch">
                            <input type="checkbox" name="receipt_show_points" <?= ($settings['receipt_show_points'] ?? '1') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        Show Points Earned
                    </label>
                </div>
                <div class="form-group">
                    <label class="switch-label">
                        <label class="switch">
                            <input type="checkbox" name="receipt_show_discount" <?= ($settings['receipt_show_discount'] ?? '1') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        Show Discount Applied
                    </label>
                </div>
            </div>
            <div class="form-group">
                <label>Receipt Footer Text</label>
                <textarea name="receipt_footer" rows="2"><?= htmlspecialchars($settings['receipt_footer'] ?? 'Thank you for your payment!') ?></textarea>
            </div>
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Receipt Settings</button>
        </form>
    </div>

    <!-- ==================== PAYMENT TAB ==================== -->
    <div id="payment-tab" class="tab-pane">
        <form method="POST" class="settings-card">
            <input type="hidden" name="action" value="save_payment">
            <h3><i class="fas fa-credit-card"></i> Payment Settings</h3>
            <div class="form-row">
                <div class="form-group">
                    <label>Payment Number Prefix</label>
                    <input type="text" name="payment_prefix" value="<?= htmlspecialchars($settings['payment_prefix'] ?? 'PMT') ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Available Payment Methods</label>
                <div class="form-row">
                    <?php foreach ($available_payment_methods as $value => $label): ?>
                    <div class="form-group col-md-3">
                        <label class="switch-label">
                            <label class="switch">
                                <input type="checkbox" name="payment_methods[]" value="<?= $value ?>" <?= in_array($value, $payment_methods_array) ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                            <?= $label ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="switch-label">
                        <label class="switch">
                            <input type="checkbox" name="allow_partial_payment" <?= ($settings['allow_partial_payment'] ?? '1') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        Allow Partial Payments
                    </label>
                </div>
                <div class="form-group">
                    <label>Minimum Payment Percentage</label>
                    <input type="number" name="minimum_payment_percent" value="<?= htmlspecialchars($settings['minimum_payment_percent'] ?? '10') ?>">
                </div>
            </div>
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Payment Settings</button>
        </form>
    </div>

    <!-- ==================== WHATSAPP TAB ==================== -->
    <div id="whatsapp-tab" class="tab-pane">
        <form method="POST" class="settings-card">
            <input type="hidden" name="action" value="save_whatsapp">
            <h3><i class="fab fa-whatsapp"></i> WhatsApp API Settings</h3>
            
            <div class="form-group">
                <label class="switch-label">
                    <label class="switch">
                        <input type="checkbox" name="whatsapp_enabled" <?= ($settings['whatsapp_enabled'] ?? '0') == '1' ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    Enable WhatsApp API
                </label>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>WhatsApp Provider</label>
                    <select name="whatsapp_provider">
                        <option value="ultramsg" <?= ($settings['whatsapp_provider'] ?? '') == 'ultramsg' ? 'selected' : '' ?>>UltraMsg</option>
                        <option value="whapi" <?= ($settings['whatsapp_provider'] ?? '') == 'whapi' ? 'selected' : '' ?>>Whapi.cloud</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Instance ID</label>
                    <input type="text" name="whatsapp_instance_id" value="<?= htmlspecialchars($settings['whatsapp_instance_id'] ?? '') ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label>API URL</label>
                <input type="text" name="whatsapp_api_url" value="<?= htmlspecialchars($settings['whatsapp_api_url'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label>API Token</label>
                <input type="password" name="whatsapp_token" value="" placeholder="<?= !empty($settings['whatsapp_token']) ? 'Saved token hidden — leave blank to keep existing' : 'Enter API token' ?>">
            </div>
            
            <div class="form-group">
                <label>Sender Number</label>
                <input type="text" name="whatsapp_sender_number" value="<?= htmlspecialchars($settings['whatsapp_sender_number'] ?? '') ?>">
            </div>
            
            <div class="form-row mt-3">
                <div class="form-group col-md-8">
                    <label>Test WhatsApp Number</label>
                    <input type="text" name="test_phone" id="test_phone" placeholder="e.g., 252615123456">
                </div>
                <div class="form-group col-md-4 d-flex align-items-end">
                    <button type="submit" name="action" value="test_whatsapp" class="btn btn-info w-100"><i class="fab fa-whatsapp"></i> Test</button>
                </div>
            </div>
            
            <div class="warning-box">
                <i class="fas fa-exclamation-triangle"></i> Make sure your WhatsApp API credentials are correct before testing.
            </div>
            
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save WhatsApp Settings</button>
        </form>
    </div>

    <!-- ==================== SMS TAB ==================== -->
    <div id="sms-tab" class="tab-pane">
        <form method="POST" class="settings-card">
            <input type="hidden" name="action" value="save_sms">
            <h3><i class="fas fa-sms"></i> SMS API Settings</h3>
            
            <div class="form-group">
                <label class="switch-label">
                    <label class="switch">
                        <input type="checkbox" name="sms_enabled" <?= ($settings['sms_enabled'] ?? '0') == '1' ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    Enable SMS API
                </label>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>SMS Provider</label>
                    <select name="sms_provider">
                        <option value="twilio" <?= ($settings['sms_provider'] ?? '') == 'twilio' ? 'selected' : '' ?>>Twilio</option>
                        <option value="africastalking" <?= ($settings['sms_provider'] ?? '') == 'africastalking' ? 'selected' : '' ?>>Africa's Talking</option>
                        <option value="messagebird" <?= ($settings['sms_provider'] ?? '') == 'messagebird' ? 'selected' : '' ?>>MessageBird</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>From Number</label>
                    <input type="text" name="sms_from_number" value="<?= htmlspecialchars($settings['sms_from_number'] ?? '') ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label>API Key</label>
                <input type="password" name="sms_api_key" value="" placeholder="<?= !empty($settings['sms_api_key']) ? 'Saved key hidden — leave blank to keep existing' : 'Enter API key' ?>">
            </div>
            
            <div class="form-group">
                <label>API Secret</label>
                <input type="password" name="sms_api_secret" value="" placeholder="<?= !empty($settings['sms_api_secret']) ? 'Saved secret hidden — leave blank to keep existing' : 'Enter API secret' ?>">
            </div>
            
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save SMS Settings</button>
        </form>
    </div>

    <!-- ==================== NOTIFICATIONS TAB ==================== -->
    <div id="notifications-tab" class="tab-pane">
        <form method="POST" class="settings-card">
            <input type="hidden" name="action" value="save_notification">
            <h3><i class="fas fa-bell"></i> Notification Settings</h3>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="switch-label">
                        <label class="switch">
                            <input type="checkbox" name="email_notifications" <?= ($settings['email_notifications'] ?? '1') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        Email Notifications
                    </label>
                </div>
                <div class="form-group">
                    <label class="switch-label">
                        <label class="switch">
                            <input type="checkbox" name="whatsapp_notifications" <?= ($settings['whatsapp_notifications'] ?? '1') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        WhatsApp Notifications
                    </label>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="switch-label">
                        <label class="switch">
                            <input type="checkbox" name="sms_notifications" <?= ($settings['sms_notifications'] ?? '0') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        SMS Notifications
                    </label>
                </div>
                <div class="form-group">
                    <label class="switch-label">
                        <label class="switch">
                            <input type="checkbox" name="push_notifications" <?= ($settings['push_notifications'] ?? '1') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        Push Notifications
                    </label>
                </div>
            </div>
            
            <hr>
            <h4>Event Notifications</h4>
            <div class="form-row">
                <div class="form-group">
                    <label class="switch-label">
                        <label class="switch">
                            <input type="checkbox" name="invoice_created_notify" <?= ($settings['invoice_created_notify'] ?? '1') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        Invoice Created
                    </label>
                </div>
                <div class="form-group">
                    <label class="switch-label">
                        <label class="switch">
                            <input type="checkbox" name="payment_received_notify" <?= ($settings['payment_received_notify'] ?? '1') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        Payment Received
                    </label>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="switch-label">
                        <label class="switch">
                            <input type="checkbox" name="container_shipped_notify" <?= ($settings['container_shipped_notify'] ?? '1') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        Container Shipped
                    </label>
                </div>
                <div class="form-group">
                    <label class="switch-label">
                        <label class="switch">
                            <input type="checkbox" name="container_arrived_notify" <?= ($settings['container_arrived_notify'] ?? '1') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        Container Arrived
                    </label>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="switch-label">
                        <label class="switch">
                            <input type="checkbox" name="package_delivered_notify" <?= ($settings['package_delivered_notify'] ?? '1') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        Package Delivered
                    </label>
                </div>
            </div>
            
            <div class="form-group">
                <label>Debt Reminder Days</label>
                <input type="text" name="debt_reminder_days" value="<?= htmlspecialchars($settings['debt_reminder_days'] ?? '7,14,21,30') ?>">
            </div>
            
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Notification Settings</button>
        </form>
    </div>

    <!-- ==================== SECURITY TAB ==================== -->
    <div id="security-tab" class="tab-pane">
        <form method="POST" class="settings-card">
            <input type="hidden" name="action" value="save_security">
            <h3><i class="fas fa-shield-alt"></i> Security Settings</h3>
            <div class="form-row">
                <div class="form-group">
                    <label>Session Timeout (seconds)</label>
                    <input type="number" name="session_timeout" value="<?= htmlspecialchars($settings['session_timeout'] ?? '3600') ?>">
                </div>
                <div class="form-group">
                    <label>Max Login Attempts</label>
                    <input type="number" name="max_login_attempts" value="<?= htmlspecialchars($settings['max_login_attempts'] ?? '5') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Lockout Time (seconds)</label>
                    <input type="number" name="lockout_time" value="<?= htmlspecialchars($settings['lockout_time'] ?? '900') ?>">
                </div>
                <div class="form-group">
                    <label>Password Expiry Days</label>
                    <input type="number" name="password_expiry_days" value="<?= htmlspecialchars($settings['password_expiry_days'] ?? '90') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="switch-label">
                        <label class="switch">
                            <input type="checkbox" name="two_factor_auth" <?= ($settings['two_factor_auth'] ?? '0') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        Two-Factor Authentication
                    </label>
                </div>
                <div class="form-group">
                    <label class="switch-label">
                        <label class="switch">
                            <input type="checkbox" name="force_strong_password" <?= ($settings['force_strong_password'] ?? '1') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        Force Strong Passwords
                    </label>
                </div>
            </div>
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Security Settings</button>
        </form>
    </div>

    <!-- ==================== LIMITS TAB ==================== -->
    <div id="limits-tab" class="tab-pane">
        <form method="POST" class="settings-card">
            <input type="hidden" name="action" value="save_limits">
            <h3><i class="fas fa-tachometer-alt"></i> System Limits</h3>
            <div class="form-row">
                <div class="form-group">
                    <label>Max File Size (bytes)</label>
                    <input type="number" name="max_file_size" value="<?= htmlspecialchars($settings['max_file_size'] ?? '5242880') ?>">
                </div>
                <div class="form-group">
                    <label>Max Containers Per Page</label>
                    <input type="number" name="max_containers_per_page" value="<?= htmlspecialchars($settings['max_containers_per_page'] ?? '50') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Max Trips Per Page</label>
                    <input type="number" name="max_trips_per_page" value="<?= htmlspecialchars($settings['max_trips_per_page'] ?? '50') ?>">
                </div>
                <div class="form-group">
                    <label>Max Customers Per Page</label>
                    <input type="number" name="max_customers_per_page" value="<?= htmlspecialchars($settings['max_customers_per_page'] ?? '50') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Max Invoices Per Page</label>
                    <input type="number" name="max_invoices_per_page" value="<?= htmlspecialchars($settings['max_invoices_per_page'] ?? '50') ?>">
                </div>
                <div class="form-group">
                    <label>Maximum Stock Items</label>
                    <input type="number" name="max_stock_items" value="<?= htmlspecialchars($settings['max_stock_items'] ?? '10000') ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Backup Retention Days</label>
                <input type="number" name="backup_retention_days" value="<?= htmlspecialchars($settings['backup_retention_days'] ?? '30') ?>">
            </div>
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save System Limits</button>
        </form>
    </div>

    <!-- ==================== BRANCH TAB ==================== -->
    <div id="branch-tab" class="tab-pane">
        <form method="POST" class="settings-card">
            <input type="hidden" name="action" value="save_branch">
            <h3><i class="fas fa-store"></i> Branch Management Settings</h3>
            
            <div class="form-group">
                <label class="switch-label">
                    <label class="switch">
                        <input type="checkbox" name="branch_enabled" <?= ($settings['branch_enabled'] ?? '1') == '1' ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    Enable Branch Management
                </label>
            </div>
            
            <div class="form-group">
                <label class="switch-label">
                    <label class="switch">
                        <input type="checkbox" name="allow_branch_transfer" <?= ($settings['allow_branch_transfer'] ?? '1') == '1' ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    Allow Stock Transfer
                </label>
            </div>
            
            <div class="form-group">
                <label>Default Branch</label>
                <select name="default_branch_id">
                    <option value="0">-- Select Default Branch --</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= $branch['id'] ?>" <?= ($settings['default_branch_id'] ?? '0') == $branch['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($branch['branch_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Branch Settings</button>
        </form>
    </div>

    <!-- ==================== STORAGE TAB ==================== -->
    <div id="storage-tab" class="tab-pane">
        <form method="POST" class="settings-card">
            <input type="hidden" name="action" value="save_storage">
            <h3><i class="fas fa-warehouse"></i> Storage Fee Settings</h3>
            
            <div class="form-group">
                <label class="switch-label">
                    <label class="switch">
                        <input type="checkbox" name="storage_fee_enabled" <?= ($settings['storage_fee_enabled'] ?? '1') == '1' ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    Enable Storage Fee
                </label>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Free Storage Days</label>
                    <input type="number" name="storage_free_days" value="<?= htmlspecialchars($settings['storage_free_days'] ?? '30') ?>">
                </div>
                <div class="form-group">
                    <label>Storage Fee Per Day ($)</label>
                    <input type="number" step="0.01" name="storage_fee_per_day" value="<?= htmlspecialchars($settings['storage_fee_per_day'] ?? '5.00') ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label>Storage Fee Per CBM Per Day ($)</label>
                <input type="number" step="0.01" name="storage_fee_per_cbm" value="<?= htmlspecialchars($settings['storage_fee_per_cbm'] ?? '0.50') ?>">
            </div>
            
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Storage Settings</button>
        </form>
    </div>

    <!-- ==================== REPORTS TAB ==================== -->
    <div id="report-tab" class="tab-pane">
        <form method="POST" class="settings-card">
            <input type="hidden" name="action" value="save_report">
            <h3><i class="fas fa-chart-line"></i> Report Settings</h3>
            
            <div class="form-group">
                <label class="switch-label">
                    <label class="switch">
                        <input type="checkbox" name="report_auto_generate" <?= ($settings['report_auto_generate'] ?? '1') == '1' ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    Auto-generate Reports
                </label>
            </div>
            
            <div class="form-group">
                <label>Report Email Recipients</label>
                <input type="text" name="report_email_recipients" value="<?= htmlspecialchars($settings['report_email_recipients'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label>Report Retention Days</label>
                <input type="number" name="report_retention_days" value="<?= htmlspecialchars($settings['report_retention_days'] ?? '365') ?>">
            </div>
            
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Report Settings</button>
        </form>
    </div>
</div>

<!-- Tax Rate Modal -->
<div class="modal fade" id="taxRateModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--curdun-violet); color: white;">
                <h5 class="modal-title"><i class="fas fa-percent"></i> Tax Rate</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="save_tax_rate">
                <input type="hidden" name="rate_id" id="tax_rate_id" value="0">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Tax Name</label>
                        <input type="text" name="tax_name" id="tax_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Tax Rate (%)</label>
                        <input type="number" step="0.01" name="tax_rate" id="tax_rate" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Tax Type</label>
                        <select name="tax_type" id="tax_type" class="form-control">
                            <option value="VAT">VAT</option>
                            <option value="Sales Tax">Sales Tax</option>
                            <option value="Withholding">Withholding Tax</option>
                            <option value="Customs">Customs Duty</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Tax Number</label>
                        <input type="text" name="tax_number" id="tax_number" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="switch-label">
                            <label class="switch">
                                <input type="checkbox" name="is_default" id="is_default">
                                <span class="slider"></span>
                            </label>
                            Set as Default
                        </label>
                    </div>
                    <div class="form-group">
                        <label>Effective From</label>
                        <input type="date" name="effective_from" id="effective_from" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Effective To</label>
                        <input type="date" name="effective_to" id="effective_to" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" id="tax_notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Tax Rate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Tab switching
$('.settings-tab').click(function() {
    const tab = $(this).data('tab');
    $('.settings-tab').removeClass('active');
    $(this).addClass('active');
    $('.tab-pane').removeClass('active');
    $(`#${tab}-tab`).addClass('active');
    localStorage.setItem('activeSettingsTab', tab);
});

// Restore last active tab
const lastTab = localStorage.getItem('activeSettingsTab');
if (lastTab) {
    const tabBtn = $(`.settings-tab[data-tab="${lastTab}"]`);
    if (tabBtn.length) {
        $('.settings-tab').removeClass('active');
        tabBtn.addClass('active');
        $('.tab-pane').removeClass('active');
        $(`#${lastTab}-tab`).addClass('active');
    }
}

// Tax Rate Functions
function showAddTaxRateModal() {
    $('#tax_rate_id').val(0);
    $('#tax_name').val('');
    $('#tax_rate').val('');
    $('#tax_type').val('VAT');
    $('#tax_number').val('');
    $('#is_default').prop('checked', false);
    $('#effective_from').val('');
    $('#effective_to').val('');
    $('#tax_notes').val('');
    $('#taxRateModal').modal('show');
}

function editTaxRate(rate) {
    $('#tax_rate_id').val(rate.id);
    $('#tax_name').val(rate.tax_name);
    $('#tax_rate').val(rate.tax_rate);
    $('#tax_type').val(rate.tax_type);
    $('#tax_number').val(rate.tax_number || '');
    $('#is_default').prop('checked', rate.is_default == 1);
    $('#effective_from').val(rate.effective_from || '');
    $('#effective_to').val(rate.effective_to || '');
    $('#tax_notes').val(rate.notes || '');
    $('#taxRateModal').modal('show');
}

function deleteTaxRate(id) {
    if (confirm('Are you sure you want to delete this tax rate?')) {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                action: 'delete_tax_rate',
                rate_id: id
            },
            success: function() {
                location.reload();
            },
            error: function() {
                alert('Error deleting tax rate');
            }
        });
    }
}

// Auto-hide alert
setTimeout(() => {
    const alertBox = document.getElementById('alertBox');
    if (alertBox) {
        alertBox.style.transition = 'opacity 0.6s ease';
        alertBox.style.opacity = '0';
        setTimeout(() => alertBox.remove(), 600);
    }
}, 4000);
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
