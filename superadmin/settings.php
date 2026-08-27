<?php
// superadmin/settings.php
// System Settings forfaras cargo - Super Admin
// FULLY FIXED VERSION

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is superadmin or company_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['superadmin', 'company_admin'])) {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION['role'];
$session_tenant_id = $_SESSION['tenant_id'] ?? 0;

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/MessagingService.php';
$messaging = new MessagingService($pdo);

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Super Admin';

// Get tenant_id
$tenant_id = ($role === 'superadmin') ? null : $session_tenant_id;

// Create or get default tenant if none exists
try {
    if ($role === 'superadmin') {
        $stmt = $pdo->prepare("SELECT id FROM tenants WHERE code = 'default' LIMIT 1");
        $stmt->execute();
        $defaultTenant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$defaultTenant) {
            $stmt = $pdo->prepare("INSERT INTO tenants (name, code, subscription_plan, is_active) VALUES ('Curdun Cargo Default', 'default', 'enterprise', 1)");
            $stmt->execute();
            $tenant_id = $pdo->lastInsertId();
        } else {
            $tenant_id = $defaultTenant['id'];
        }
    }
} catch (PDOException $e) {
    error_log("Tenant setup error: " . $e->getMessage());
}

$settings = [];

try {
    // Create system_settings table if not exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NULL,
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT,
            setting_type ENUM('text', 'number', 'boolean', 'json', 'password') DEFAULT 'text',
            category VARCHAR(50) DEFAULT 'general',
            description TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT,
            UNIQUE KEY unique_setting (tenant_id, setting_key)
        )
    ");
    
    // Insert default settings if not exists
    $default_settings = [
        // General Settings
        ['system_name', 'Cargo Management System', 'text', 'general', 'System Name'],
        ['system_timezone', 'Africa/Mogadishu', 'text', 'general', 'System Timezone'],
        ['date_format', 'd/m/Y', 'text', 'general', 'Date Format'],
        ['time_format', 'H:i:s', 'text', 'general', 'Time Format'],
        ['default_language', 'so', 'text', 'general', 'Default Language'],
        ['default_currency', 'USD', 'text', 'general', 'Default Currency'],
        ['currency_symbol', '$', 'text', 'general', 'Currency Symbol'],
        ['items_per_page', '15', 'number', 'general', 'Items Per Page'],
        
        // Security Settings
        ['session_timeout', '3600', 'number', 'security', 'Session Timeout (seconds)'],
        ['max_login_attempts', '5', 'number', 'security', 'Maximum Login Attempts'],
        ['lockout_time', '900', 'number', 'security', 'Lockout Time (seconds)'],
        ['password_expiry_days', '90', 'number', 'security', 'Password Expiry Days'],
        ['two_factor_auth', '0', 'boolean', 'security', 'Enable Two Factor Authentication'],
        ['ip_whitelist', '', 'text', 'security', 'IP Whitelist (comma separated)'],
        ['force_ssl', '1', 'boolean', 'security', 'Force SSL/HTTPS'],
        
        // Email Settings
        ['smtp_host', 'smtp.gmail.com', 'text', 'email', 'SMTP Host'],
        ['smtp_port', '587', 'number', 'email', 'SMTP Port'],
        ['smtp_encryption', 'tls', 'text', 'email', 'SMTP Encryption'],
        ['smtp_username', '', 'text', 'email', 'SMTP Username'],
        ['smtp_password', '', 'password', 'email', 'SMTP Password'],
        ['from_email', 'noreply@curduncargo.com', 'text', 'email', 'From Email'],
        ['from_name', 'Cargo Management System', 'text', 'email', 'From Name'],
        
        // SMS Settings
        ['sms_provider', 'twilio', 'text', 'sms', 'SMS Provider'],
        ['sms_api_key', '', 'password', 'sms', 'SMS API Key'],
        ['sms_api_secret', '', 'password', 'sms', 'SMS API Secret'],
        ['sms_from_number', '', 'text', 'sms', 'SMS From Number'],
        ['sms_enabled', '0', 'boolean', 'sms', 'Enable SMS'],
        
        // Backup Settings
        ['auto_backup', '1', 'boolean', 'backup', 'Auto Backup'],
        ['backup_frequency', 'daily', 'text', 'backup', 'Backup Frequency'],
        ['backup_time', '02:00', 'text', 'backup', 'Backup Time'],
        ['backup_retention_days', '30', 'number', 'backup', 'Retention Days'],
        ['backup_destination', 'local', 'text', 'backup', 'Destination'],
        
        // API Settings
        ['api_enabled', '1', 'boolean', 'api', 'Enable API'],
        ['api_rate_limit', '1000', 'number', 'api', 'Rate Limit'],
        ['api_key', '', 'password', 'api', 'API Key'],
        ['api_allowed_ips', '', 'text', 'api', 'Allowed IPs'],
        
        // Log Settings
        ['log_retention_days', '90', 'number', 'log', 'Log Retention'],
        ['log_level', 'info', 'text', 'log', 'Log Level'],
        ['enable_audit_log', '1', 'boolean', 'log', 'Audit Log'],
        
        // Cache Settings
        ['cache_enabled', '1', 'boolean', 'cache', 'Enable Cache'],
        ['cache_ttl', '3600', 'number', 'cache', 'Cache TTL'],
        
        // System Limits
        ['max_file_size', '5242880', 'number', 'limit', 'Max File Size'],
        ['allowed_file_types', 'jpg,jpeg,png,gif,pdf', 'text', 'limit', 'Allowed Types'],
        ['max_containers_per_page', '50', 'number', 'limit', 'Max Containers'],
        ['max_trips_per_page', '50', 'number', 'limit', 'Max Trips']
    ];
    
    // Use INSERT IGNORE for simplicity
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO system_settings (tenant_id, setting_key, setting_value, setting_type, category, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($default_settings as $setting) {
        try {
            $stmt->execute([$tenant_id, $setting[0], $setting[1], $setting[2], $setting[3], $setting[4]]);
        } catch (PDOException $e) {
            error_log("Settings insert error: " . $e->getMessage());
        }
    }
    
    // Generate API key if empty
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE (tenant_id = ? OR tenant_id IS NULL) AND setting_key = 'api_key'");
    $stmt->execute([$tenant_id]);
    $api_key = $stmt->fetchColumn();
    
    if (empty($api_key) || $api_key === '') {
        $new_api_key = 'ck_' . bin2hex(random_bytes(24));
        $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE (tenant_id = ? OR tenant_id IS NULL) AND setting_key = 'api_key'");
        $stmt->execute([$new_api_key, $tenant_id]);
    }
    
    // Get all settings
    $stmt = $pdo->prepare("SELECT setting_key, setting_value, setting_type, category FROM system_settings WHERE tenant_id = ? OR tenant_id IS NULL");
    $stmt->execute([$tenant_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
} catch (PDOException $e) {
    error_log("Settings error: " . $e->getMessage());
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
    // deltas can be recorded per action. Sensitive keys are masked by
    // admin_audit_mask_sensitive() before the audit row is written.
    $settings_snapshot = function(array $keys) use ($pdo, $tenant_id): array {
        if (empty($keys)) return [];
        $in = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE (tenant_id = ? OR tenant_id IS NULL) AND setting_key IN ($in)");
        $stmt->execute(array_merge([$tenant_id], $keys));
        $out = [];
        foreach ($keys as $k) { $out[$k] = null; }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
        }
        return $out;
    };
    $settings_audit = function(string $section, array $keys, array $before) use ($pdo, $settings_snapshot): void {
        $after = $settings_snapshot($keys);
        record_admin_audit(
            $pdo,
            'PLATFORM_SETTINGS_UPDATED',
            'settings',
            null,
            ['_section' => $section] + $before,
            ['_section' => $section] + $after,
            null
        );
    };

    // Helper function to update settings
    $updateSetting = function($key, $value) use ($pdo, $tenant_id, $user_id) {
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (tenant_id, setting_key, setting_value, updated_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP
        ");
        return $stmt->execute([$tenant_id, $key, $value, $user_id]);
    };
    
    if ($action === 'save_general') {
        $general_settings = [
            'system_name', 'system_timezone', 'date_format', 'time_format',
            'default_language', 'default_currency', 'currency_symbol', 'items_per_page'
        ];
        $__audit_before = $settings_snapshot($general_settings);
        try {
            $pdo->beginTransaction();
            foreach ($general_settings as $key) {
                $value = $_POST[$key] ?? $settings[$key] ?? '';
                $updateSetting($key, $value);
            }
            
            $_SESSION['language'] = $_POST['default_language'] ?? 'so';
            $_SESSION['currency'] = $_POST['default_currency'] ?? 'USD';
            
            $pdo->commit();
            $settings_audit('general', $general_settings, $__audit_before);
            $message = "General settings saved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_security') {
        $security_settings = [
            'session_timeout', 'max_login_attempts', 'lockout_time',
            'password_expiry_days', 'two_factor_auth', 'ip_whitelist', 'force_ssl'
        ];
        $__audit_before = $settings_snapshot($security_settings);
        try {
            $pdo->beginTransaction();
            foreach ($security_settings as $key) {
                $value = $_POST[$key] ?? $settings[$key] ?? '';
                if ($key == 'two_factor_auth' || $key == 'force_ssl') {
                    $value = isset($_POST[$key]) ? '1' : '0';
                }
                $updateSetting($key, $value);
            }
            $pdo->commit();
            $settings_audit('security', $security_settings, $__audit_before);
            $message = "Security settings saved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_email') {
        $email_settings = [
            'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username',
            'smtp_password', 'from_email', 'from_name'
        ];
        $__audit_before = $settings_snapshot($email_settings);
        try {
            $pdo->beginTransaction();
            foreach ($email_settings as $key) {
                $value = $_POST[$key] ?? $settings[$key] ?? '';
                $updateSetting($key, $value);
            }
            $pdo->commit();
            $settings_audit('email', $email_settings, $__audit_before);
            $message = "Email settings saved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_sms') {
        $sms_settings = [
            'sms_provider', 'sms_api_key', 'sms_api_secret', 'sms_from_number', 'sms_enabled',
            'whatsapp_provider', 'whatsapp_api_url', 'whatsapp_token', 'whatsapp_instance_id', 'whatsapp_enabled'
        ];
        $__audit_before = $settings_snapshot($sms_settings);
        try {
            $pdo->beginTransaction();
            foreach ($sms_settings as $key) {
                $value = $_POST[$key] ?? $settings[$key] ?? '';
                if ($key == 'sms_enabled') {
                    $value = isset($_POST[$key]) ? '1' : '0';
                }
                $updateSetting($key, $value);
            }
            $pdo->commit();
            $settings_audit('sms', $sms_settings, $__audit_before);
            $message = "SMS settings saved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_backup') {
        $backup_settings = [
            'auto_backup', 'backup_frequency', 'backup_time', 'backup_retention_days', 'backup_destination'
        ];
        $__audit_before = $settings_snapshot($backup_settings);
        try {
            $pdo->beginTransaction();
            foreach ($backup_settings as $key) {
                $value = $_POST[$key] ?? $settings[$key] ?? '';
                if ($key == 'auto_backup') {
                    $value = isset($_POST[$key]) ? '1' : '0';
                }
                $updateSetting($key, $value);
            }
            $pdo->commit();
            $settings_audit('backup', $backup_settings, $__audit_before);
            $message = "Backup settings saved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_api') {
        $api_settings = ['api_enabled', 'api_rate_limit', 'api_allowed_ips', 'api_key'];
        $__audit_before = $settings_snapshot($api_settings);
        try {
            $pdo->beginTransaction();
            foreach ($api_settings as $key) {
                $value = $_POST[$key] ?? $settings[$key] ?? '';
                if ($key == 'api_enabled') {
                    $value = isset($_POST[$key]) ? '1' : '0';
                }
                $updateSetting($key, $value);
            }
            
            // Generate new API key if requested
            if (isset($_POST['generate_api_key'])) {
                $new_api_key = 'ck_' . bin2hex(random_bytes(24));
                $updateSetting('api_key', $new_api_key);
                $message = "New API key generated successfully!";
            } else {
                $message = "API settings saved successfully!";
            }
            $message_type = "success";
            
            $pdo->commit();
            $settings_audit('api', $api_settings, $__audit_before);

            // Refresh settings
            $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE tenant_id = ? OR tenant_id IS NULL");
            $stmt->execute([$tenant_id]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    elseif ($action === 'test_whatsapp') {
        $to = $_POST['test_phone'] ?? '';
        if ($to) {
            $result = $messaging->sendWhatsApp($to, "Cargo Management System: Kani waa fariin tijaabo ah oo ka timid system-kaaga. WhatsApp API-gaagu si sax ah ayuu u shaqaynayaa! ✅");
            $message = $result['message'];
            $message_type = $result['success'] ? "success" : "error";
        } else {
            $message = "Fadlan geli lambarka aad tijaabinayso.";
            $message_type = "error";
        }
    }
    
    elseif ($action === 'save_limits') {
        $limit_settings = [
            'max_file_size', 'allowed_file_types', 'max_containers_per_page', 'max_trips_per_page'
        ];
        $__audit_before = $settings_snapshot($limit_settings);
        try {
            $pdo->beginTransaction();
            foreach ($limit_settings as $key) {
                $value = $_POST[$key] ?? $settings[$key] ?? '';
                $updateSetting($key, $value);
            }
            $pdo->commit();
            $settings_audit('limits', $limit_settings, $__audit_before);
            $message = "System limits saved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    // Test email
    elseif ($action === 'test_email') {
        $to = $_POST['test_email'] ?? '';
        if ($to && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $subject = "Cargo Management System - Test Email";
            $message_body = "Test email from Cargo Management System\nTime: " . date('Y-m-d H:i:s');
            $headers = "From: " . ($settings['from_email'] ?? 'noreply@curduncargo.com');
            
            if (mail($to, $subject, $message_body, $headers)) {
                $message = "Test email sent to $to!";
                $message_type = "success";
            } else {
                $message = "Failed to send test email.";
                $message_type = "error";
            }
        } else {
            $message = "Valid email required.";
            $message_type = "error";
        }
    }
    
    // Run backup
    elseif ($action === 'run_backup') {
        $backup_dir = __DIR__ . '/../../backups/';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0777, true);
        }
        
        $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = $backup_dir . $filename;
        
        try {
            // Get database config from db.php variables
            global $pdo;
            $dbname = 'curdun_cargo_system';
            
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $backup_content = "-- Cargo Management System Backup\n-- " . date('Y-m-d H:i:s') . "\n\n";
            $backup_content .= "CREATE DATABASE IF NOT EXISTS `$dbname`;\nUSE `$dbname`;\n\n";
            
            foreach ($tables as $table) {
                $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
                $backup_content .= "DROP TABLE IF EXISTS `$table`;\n";
                $backup_content .= $create['Create Table'] . ";\n\n";
                
                $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    foreach ($rows as $row) {
                        $columns = array_keys($row);
                        $values = array_map(function($val) use ($pdo) {
                            if ($val === null) return 'NULL';
                            return $pdo->quote($val);
                        }, array_values($row));
                        $backup_content .= "INSERT INTO `$table` (`" . implode("`, `", $columns) . "`) VALUES (" . implode(", ", $values) . ");\n";
                    }
                    $backup_content .= "\n";
                }
            }
            
            file_put_contents($filepath, $backup_content);
            
            if (file_exists($filepath) && filesize($filepath) > 0) {
                $message = "Backup created: $filename (" . round(filesize($filepath)/1024, 2) . " KB)";
                $message_type = "success";
            } else {
                throw new Exception("Backup file not created");
            }
        } catch (Exception $e) {
            $message = "Backup error: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    // Clear cache
    elseif ($action === 'clear_cache') {
        $cache_dir = __DIR__ . '/../../cache/';
        if (is_dir($cache_dir)) {
            $files = glob($cache_dir . '*');
            $deleted = 0;
            foreach ($files as $file) {
                if (is_file($file) && unlink($file)) $deleted++;
            }
            $message = "Cache cleared! ($deleted files)";
            $message_type = "success";
        } else {
            $message = "Cache directory not found.";
            $message_type = "error";
        }
    }
}

// Get settings by category
$settings_by_category = [];
try {
    $stmt = $pdo->prepare("SELECT setting_key, setting_value, setting_type, category FROM system_settings WHERE tenant_id = ? OR tenant_id IS NULL");
    $stmt->execute([$tenant_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings_by_category[$row['category']][$row['setting_key']] = [
            'value' => $row['setting_value'],
            'type' => $row['setting_type']
        ];
    }
} catch (PDOException $e) {
    error_log("Fetch error: " . $e->getMessage());
}

$timezones = [
    'Africa/Mogadishu' => 'Africa/Mogadishu (Somalia)',
    'Africa/Nairobi' => 'Africa/Nairobi (Kenya)',
    'Asia/Dubai' => 'Asia/Dubai (UAE)',
    'UTC' => 'UTC'
];

$languages = [
    'so' => 'Soomaali',
    'en' => 'English',
    'ar' => 'العربية'
];

$currencies = [
    'USD' => 'US Dollar ($)',
    'EUR' => 'Euro (€)',
    'GBP' => 'British Pound (£)',
    'SOS' => 'Somali Shilling (SSh)',
    'AED' => 'UAE Dirham (د.إ)'
];

$sms_providers = [
    'twilio' => 'Twilio',
    'africastalking' => 'Africa\'s Talking',
    'messagebird' => 'MessageBird',
    'vonage' => 'Vonage',
    'ultramsg' => 'WhatsApp (UltraMsg)',
    'whapi' => 'WhatsApp (Whapi)'
];

$backup_frequencies = [
    'daily' => 'Daily',
    'weekly' => 'Weekly',
    'monthly' => 'Monthly'
];

$backup_destinations = [
    'local' => 'Local Server',
    'ftp' => 'FTP Server',
    's3' => 'Amazon S3'
];

$encryption_types = [
    'tls' => 'TLS',
    'ssl' => 'SSL',
    'none' => 'None'
];

require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root {
            --curdun-violet: #2D1859;
            --curdun-yellow: #F5C410;
            --curdun-violet-light: #4B2C85;
            --curdun-yellow-dark: #D4A70C;
            --curdun-gray: #6c757d;
            --curdun-dark: #2D2D2D;
            --curdun-success: #0F7A3A;
            --curdun-danger: #B42318;
            --curdun-info: #17a2b8;
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

        .btn-secondary-custom {
            background: var(--curdun-gray);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-secondary-custom:hover {
            background: var(--curdun-dark);
            transform: translateY(-2px);
        }

        .alert {
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            animation: slideIn 0.3s ease;
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

        .api-key-box {
            font-family: monospace;
            background: #f5f5f5;
            padding: 10px;
            border-radius: 8px;
            font-size: 13px;
            word-break: break-all;
            border: 1px solid #ddd;
        }

        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: center; }
            .settings-tabs { justify-content: center; }
            .form-row { flex-direction: column; }
            .alert { left: 20px; right: 20px; min-width: auto; top: 70px; }
        }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?>" id="alertBox">
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
        <h1><i class="fas fa-cog"></i> <?= $role === 'company_admin' ? 'Habaynta Shirkadda' : 'Habaynta Nidaamka' ?></h1>
    </div>

    <div class="settings-tabs">
        <button class="settings-tab active" data-tab="general"><i class="fas fa-globe"></i> Guud ahaan</button>
        <button class="settings-tab" data-tab="security"><i class="fas fa-shield-alt"></i> Amniga</button>
        <button class="settings-tab" data-tab="email"><i class="fas fa-envelope"></i> Emailka</button>
        <button class="settings-tab" data-tab="sms"><i class="fab fa-whatsapp"></i> SMS & WhatsApp</button>
        <button class="settings-tab" data-tab="backup"><i class="fas fa-database"></i> Kaabista (Backup)</button>
        <button class="settings-tab" data-tab="api"><i class="fas fa-code"></i> API</button>
        <button class="settings-tab" data-tab="limits"><i class="fas fa-tachometer-alt"></i> Xuduudaha</button>
    </div>

    <!-- General Tab -->
    <div id="general-tab" class="tab-pane active">
        <form method="POST" class="settings-card">
            <input type="hidden" name="action" value="save_general">
            <h3><i class="fas fa-globe"></i> General Settings</h3>
            <div class="form-row">
                <div class="form-group">
                    <label>System Name</label>
                    <input type="text" name="system_name" value="<?= htmlspecialchars($settings['system_name'] ?? 'Cargo Management System') ?>">
                </div>
                <div class="form-group">
                    <label>Default Language</label>
                    <select name="default_language">
                        <?php foreach ($languages as $code => $name): ?>
                            <option value="<?= $code ?>" <?= ($settings['default_language'] ?? 'so') == $code ? 'selected' : '' ?>><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Timezone</label>
                    <select name="system_timezone">
                        <?php foreach ($timezones as $tz => $name): ?>
                            <option value="<?= $tz ?>" <?= ($settings['system_timezone'] ?? 'Africa/Mogadishu') == $tz ? 'selected' : '' ?>><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Default Currency</label>
                    <select name="default_currency">
                        <?php foreach ($currencies as $code => $name): ?>
                            <option value="<?= $code ?>" <?= ($settings['default_currency'] ?? 'USD') == $code ? 'selected' : '' ?>><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Currency Symbol</label>
                    <input type="text" name="currency_symbol" value="<?= htmlspecialchars($settings['currency_symbol'] ?? '$') ?>">
                </div>
                <div class="form-group">
                    <label>Date Format</label>
                    <select name="date_format">
                        <option value="d/m/Y" <?= ($settings['date_format'] ?? 'd/m/Y') == 'd/m/Y' ? 'selected' : '' ?>>DD/MM/YYYY</option>
                        <option value="m/d/Y" <?= ($settings['date_format'] ?? 'd/m/Y') == 'm/d/Y' ? 'selected' : '' ?>>MM/DD/YYYY</option>
                        <option value="Y-m-d" <?= ($settings['date_format'] ?? 'd/m/Y') == 'Y-m-d' ? 'selected' : '' ?>>YYYY-MM-DD</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
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

    <!-- Security Tab -->
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
            <div class="form-group">
                <label class="switch-label">
                    <label class="switch">
                        <input type="checkbox" name="two_factor_auth" <?= ($settings['two_factor_auth'] ?? '0') == '1' ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    Two Factor Authentication
                </label>
            </div>
            <div class="form-group">
                <label class="switch-label">
                    <label class="switch">
                        <input type="checkbox" name="force_ssl" <?= ($settings['force_ssl'] ?? '1') == '1' ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    Force SSL/HTTPS
                </label>
            </div>
            <div class="form-group">
                <label>IP Whitelist</label>
                <input type="text" name="ip_whitelist" value="<?= htmlspecialchars($settings['ip_whitelist'] ?? '') ?>">
            </div>
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Security Settings</button>
        </form>
    </div>

    <!-- Email Tab -->
    <div id="email-tab" class="tab-pane">
        <form method="POST" class="settings-card">
            <input type="hidden" name="action" value="save_email">
            <h3><i class="fas fa-envelope"></i> Email Settings</h3>
            <div class="form-row">
                <div class="form-group">
                    <label>SMTP Host</label>
                    <input type="text" name="smtp_host" value="<?= htmlspecialchars($settings['smtp_host'] ?? 'smtp.gmail.com') ?>">
                </div>
                <div class="form-group">
                    <label>SMTP Port</label>
                    <input type="number" name="smtp_port" value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Encryption</label>
                    <select name="smtp_encryption">
                        <?php foreach ($encryption_types as $type => $name): ?>
                            <option value="<?= $type ?>" <?= ($settings['smtp_encryption'] ?? 'tls') == $type ? 'selected' : '' ?>><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>SMTP Username</label>
                    <input type="text" name="smtp_username" value="<?= htmlspecialchars($settings['smtp_username'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>SMTP Password</label>
                    <input type="password" name="smtp_password" value="<?= htmlspecialchars($settings['smtp_password'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>From Email</label>
                    <input type="email" name="from_email" value="<?= htmlspecialchars($settings['from_email'] ?? 'noreply@curduncargo.com') ?>">
                </div>
            </div>
            <div class="form-group">
                <label>From Name</label>
                <input type="text" name="from_name" value="<?= htmlspecialchars($settings['from_name'] ?? 'Cargo Management System') ?>">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Test Email</label>
                    <input type="email" name="test_email" placeholder="Enter email to test">
                </div>
                <div class="form-group">
                    <button type="submit" name="action" value="test_email" class="btn-secondary-custom">Send Test</button>
                </div>
            </div>
            <button type="submit" name="action" value="save_email" class="btn-save"><i class="fas fa-save"></i> Save Email Settings</button>
        </form>
    </div>

    <!-- SMS & WhatsApp Tab -->
    <div id="sms-tab" class="tab-pane">
        <form method="POST" class="settings-card">
            <input type="hidden" name="action" value="save_sms">
            <h3><i class="fab fa-whatsapp"></i> SMS & WhatsApp Settings</h3>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="switch-label">
                            <label class="switch">
                                <input type="checkbox" name="sms_enabled" <?= ($settings['sms_enabled'] ?? '0') == '1' ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                            Enable SMS
                        </label>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>SMS Provider</label>
                            <select name="sms_provider">
                                <?php foreach ($sms_providers as $provider => $name): 
                                    if (strpos($provider, 'whatsapp') !== false || $provider == 'ultramsg' || $provider == 'whapi') continue; ?>
                                    <option value="<?= $provider ?>" <?= ($settings['sms_provider'] ?? 'twilio') == $provider ? 'selected' : '' ?>><?= $name ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>From Number</label>
                            <input type="text" name="sms_from_number" value="<?= htmlspecialchars($settings['sms_from_number'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>API Key (SMS)</label>
                            <input type="password" name="sms_api_key" value="<?= htmlspecialchars($settings['sms_api_key'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>API Secret (SMS)</label>
                            <input type="password" name="sms_api_secret" value="<?= htmlspecialchars($settings['sms_api_secret'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6" style="border-left: 1px solid #eee;">
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
                            <input type="text" name="whatsapp_instance_id" value="<?= htmlspecialchars($settings['whatsapp_instance_id'] ?? '') ?>" placeholder="e.g. instance12345">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>API URL</label>
                        <input type="text" name="whatsapp_api_url" value="<?= htmlspecialchars($settings['whatsapp_api_url'] ?? '') ?>" placeholder="https://api.ultramsg.com/...">
                    </div>
                    <div class="form-group">
                        <label>API Token</label>
                        <input type="password" name="whatsapp_token" value="<?= htmlspecialchars($settings['whatsapp_token'] ?? '') ?>">
                    </div>
                    
                    <div class="form-row mt-3">
                        <div class="form-group col-md-8">
                            <label>Test WhatsApp Number</label>
                            <input type="text" name="test_phone" placeholder="e.g. 252615123456">
                        </div>
                        <div class="form-group col-md-4 d-flex align-items-end">
                            <button type="submit" name="action" value="test_whatsapp" class="btn btn-info w-100" style="height: 40px;"><i class="fab fa-whatsapp"></i> Test</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <hr>
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Messaging Settings</button>
        </form>
    </div>

    <!-- Backup Tab -->
    <div id="backup-tab" class="tab-pane">
        <form method="POST" class="settings-card">
            <input type="hidden" name="action" value="save_backup">
            <h3><i class="fas fa-database"></i> Backup Settings</h3>
            <div class="form-group">
                <label class="switch-label">
                    <label class="switch">
                        <input type="checkbox" name="auto_backup" <?= ($settings['auto_backup'] ?? '1') == '1' ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    Auto Backup
                </label>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Frequency</label>
                    <select name="backup_frequency">
                        <?php foreach ($backup_frequencies as $freq => $name): ?>
                            <option value="<?= $freq ?>" <?= ($settings['backup_frequency'] ?? 'daily') == $freq ? 'selected' : '' ?>><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Backup Time</label>
                    <input type="time" name="backup_time" value="<?= htmlspecialchars($settings['backup_time'] ?? '02:00') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Destination</label>
                    <select name="backup_destination">
                        <?php foreach ($backup_destinations as $dest => $name): ?>
                            <option value="<?= $dest ?>" <?= ($settings['backup_destination'] ?? 'local') == $dest ? 'selected' : '' ?>><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Retention (days)</label>
                    <input type="number" name="backup_retention_days" value="<?= htmlspecialchars($settings['backup_retention_days'] ?? '30') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <button type="submit" name="action" value="run_backup" class="btn-secondary-custom"><i class="fas fa-play"></i> Run Backup Now</button>
                </div>
            </div>
            <button type="submit" name="action" value="save_backup" class="btn-save"><i class="fas fa-save"></i> Save Backup Settings</button>
        </form>
    </div>

    <!-- API Tab -->
    <div id="api-tab" class="tab-pane">
        <form method="POST" class="settings-card">
            <input type="hidden" name="action" value="save_api">
            <h3><i class="fas fa-code"></i> API Settings</h3>
            
            <div class="form-group">
                <label class="switch-label">
                    <label class="switch">
                        <input type="checkbox" name="api_enabled" <?= ($settings['api_enabled'] ?? '1') == '1' ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    Enable API Access
                </label>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>API Rate Limit (requests per hour)</label>
                    <input type="number" name="api_rate_limit" value="<?= htmlspecialchars($settings['api_rate_limit'] ?? '1000') ?>">
                </div>
                <div class="form-group">
                    <label>API Key</label>
                    <div class="api-key-box mb-2">
                        <code><?= htmlspecialchars($settings['api_key'] ?? 'Not generated yet') ?></code>
                    </div>
                    <button type="submit" name="generate_api_key" value="1" class="btn-secondary-custom">
                        <i class="fas fa-sync-alt"></i> Generate New API Key
                    </button>
                </div>
            </div>
            
            <div class="form-group">
                <label>API Allowed IPs</label>
                <input type="text" name="api_allowed_ips" value="<?= htmlspecialchars($settings['api_allowed_ips'] ?? '') ?>" placeholder="* (all) or 192.168.1.1,10.0.0.1">
                <small class="text-muted">Leave empty for all IPs, or enter comma separated IP addresses</small>
            </div>
            
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save API Settings</button>
        </form>
    </div>

    <!-- Limits Tab -->
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
                    <label>Allowed File Types</label>
                    <input type="text" name="allowed_file_types" value="<?= htmlspecialchars($settings['allowed_file_types'] ?? 'jpg,jpeg,png,gif,pdf') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Max Containers Per Page</label>
                    <input type="number" name="max_containers_per_page" value="<?= htmlspecialchars($settings['max_containers_per_page'] ?? '50') ?>">
                </div>
                <div class="form-group">
                    <label>Max Trips Per Page</label>
                    <input type="number" name="max_trips_per_page" value="<?= htmlspecialchars($settings['max_trips_per_page'] ?? '50') ?>">
                </div>
            </div>
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save System Limits</button>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$('.settings-tab').click(function() {
    const tab = $(this).data('tab');
    $('.settings-tab').removeClass('active');
    $(this).addClass('active');
    $('.tab-pane').removeClass('active');
    $(`#${tab}-tab`).addClass('active');
    localStorage.setItem('activeSettingsTab', tab);
});

const lastTab = localStorage.getItem('activeSettingsTab');
if (lastTab) {
    $('.settings-tab').removeClass('active');
    $(`.settings-tab[data-tab="${lastTab}"]`).addClass('active');
    $('.tab-pane').removeClass('active');
    $(`#${lastTab}-tab`).addClass('active');
}
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>