<?php
// config/constants.php
// Application constants forfaras cargo

// ============================================
// DATABASE CONSTANTS
// ============================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'curdun_cargo_system');
define('DB_USER', 'root');
define('DB_PASS', '');

// ============================================
// APPLICATION CONSTANTS
// ============================================
define('APP_NAME', 'Cargo Management System - Smart Logistics & Cargo Solutions');
define('APP_NAME_SHORT', 'Cargo Management System');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/curdub_smart_cargo/curdub_smart_cargo');
define('APP_ENVIRONMENT', 'development'); // development, production

// ============================================
// TIMEZONE
// ============================================
date_default_timezone_set('Africa/Mogadishu');
define('APP_TIMEZONE', 'Africa/Mogadishu');

// ============================================
// UPLOAD CONSTANTS
// ============================================
define('UPLOAD_MAX_SIZE', 5242880); // 5MB
define('UPLOAD_ALLOWED_TYPES', 'jpg,jpeg,png,gif,webp');
define('UPLOAD_PATH', __DIR__ . '/../uploads/');

// ============================================
// PAGINATION
// ============================================
define('ITEMS_PER_PAGE', 20);

// ============================================
// SECURITY
// ============================================
define('SESSION_LIFETIME', 7200); // 2 hours

// ============================================
// COLORS (CURDUN BRAND)
// ============================================
define('COLOR_PRIMARY', '#2D1859');
define('COLOR_SECONDARY', '#F5C410');
define('COLOR_PRIMARY_LIGHT', '#4B2C85');
define('COLOR_SECONDARY_DARK', '#D4A70C');

// ============================================
// SESSION START
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>