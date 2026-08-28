<?php
// includes/functions.php

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
require_once(__DIR__ . '/../config/db_connect.php');

// Check if user has permission - FIXED VERSION
if (!function_exists('hasPermission')) {
    function hasPermission($menu_item) {
        if (!isset($_SESSION['user']['user_id'])) {
            return false;
        }

        $user_id = $_SESSION['user']['user_id'];
        global $pdo;

        try {
            // Check if user has permission for this menu item
            $stmt = $pdo->prepare("
                SELECT status 
                FROM user_permissions 
                WHERE user_id = ? AND menu_item = ?
            ");
            $stmt->execute([$user_id, $menu_item]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            // If no specific permission exists, default to allowed (show menu item)
            if (!$result) {
                return true;
            }

            // Return true only if status is 'allowed'
            return $result['status'] === 'allowed';
        } catch (PDOException $e) {
            error_log("Permission check error: " . $e->getMessage());
            return false;
        }
    }
}

// Create user_permissions table if not exists
function createPermissionsTable() {
    global $pdo;
    
    $sql = "CREATE TABLE IF NOT EXISTS user_permissions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        menu_item VARCHAR(100) NOT NULL,
        status ENUM('allowed', 'denied') DEFAULT 'allowed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_menu (user_id, menu_item),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    
    $pdo->exec($sql);
    
    // Create menu items table
    $sql2 = "CREATE TABLE IF NOT EXISTS menu_items (
        id INT PRIMARY KEY AUTO_INCREMENT,
        menu_key VARCHAR(100) UNIQUE NOT NULL,
        menu_name VARCHAR(255) NOT NULL,
        parent_id INT NULL,
        icon VARCHAR(100),
        sort_order INT DEFAULT 0,
        is_active BOOLEAN DEFAULT TRUE,
        required_roles TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    
    $pdo->exec($sql2);
}

// Get user role
function getUserRole() {
    return $_SESSION['user']['role'] ?? 'guest';
}

// Get user company ID
function getUserCompanyId() {
    return $_SESSION['user']['company_id'] ?? null;
}

// Get user branch ID
function getUserBranchId() {
    return $_SESSION['user']['branch_id'] ?? null;
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user']['user_id']);
}

// ------------------------------------------------------------------------
// Staff role_type groups (single source of truth)
//
// login.php stores the specific sub-role into $_SESSION['role'] as an alias
// (see login.php's session-setting code), so a literal === 'staff' check
// only matches the generic staff account and incorrectly locks out every
// staff sub-role. Every staff/*.php page must instead check role_type
// (falling back to role) against one of these groups.
//
// staffFamilyRoleTypes() - "is this any valid staff account at all" for
//                          shared pages like dashboard and shipments.
// The narrower helpers below match the real workflow ownership boundaries:
// reception intake, physical warehouse custody, transport planning, finance.
//
// Adding a new staff role_type in the future only requires editing it
// here - not hunting down every staff/*.php file that duplicated the list.
// ------------------------------------------------------------------------
if (!function_exists('staffFamilyRoleTypes')) {
    function staffFamilyRoleTypes(): array {
        return ['staff', 'reception_clerk', 'warehouse_supervisor', 'logistics_supervisor', 'finance_manager', 'clerk'];
    }
}

if (!function_exists('staffReceptionRoleTypes')) {
    function staffReceptionRoleTypes(): array {
        return ['reception_clerk'];
    }
}

if (!function_exists('staffWarehouseRoleTypes')) {
    function staffWarehouseRoleTypes(): array {
        return ['warehouse_supervisor'];
    }
}

if (!function_exists('staffLogisticsRoleTypes')) {
    function staffLogisticsRoleTypes(): array {
        return ['logistics_supervisor'];
    }
}

if (!function_exists('staffFinanceRoleTypes')) {
    function staffFinanceRoleTypes(): array {
        return ['finance_manager'];
    }
}

if (!function_exists('roleDisplayName')) {
    function roleDisplayName(?string $role_type): string {
        $role_type = strtolower(trim((string)$role_type));
        $labels = [
            'superadmin' => 'Super Admin',
            'company_admin' => 'Tenant Admin',
            'tenant_admin' => 'Tenant Admin',
            'branch_manager' => 'Branch Manager',
            'reception_clerk' => 'Reception Clerk',
            'warehouse_supervisor' => 'Warehouse Supervisor',
            'logistics_supervisor' => 'Logistics Supervisor',
            'finance_manager' => 'Finance Manager',
            'delivery_agent' => 'Delivery Agent',
            'driver' => 'Driver',
            'customer' => 'Customer',
            'clerk' => 'Assistant Worker',
            'staff' => 'Staff',
        ];
        return $labels[$role_type] ?? ucwords(str_replace('_', ' ', $role_type));
    }
}

// Redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ../login.php');
        exit();
    }
}

// Generate tracking number
function generateTrackingNumber() {
    $prefix = 'TRK';
    $date = date('Ymd');
    $random = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    return $prefix . '-' . $date . '-' . $random;
}

// Generate invoice number
function generateInvoiceNumber() {
    $prefix = 'INV';
    $date = date('Ymd');
    $random = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
    return $prefix . '-' . $date . '-' . $random;
}

// Generate reception number
function generateReceptionNumber() {
    global $pdo;
    $stmt = $pdo->prepare("SELECT MAX(id) as last_id FROM receptions");
    $stmt->execute();
    $result = $stmt->fetch();
    $new_id = ($result['last_id'] ?? 0) + 1;
    return 'REC-' . date('Ymd') . '-' . str_pad($new_id, 5, '0', STR_PAD_LEFT);
}

// Calculate storage fee
function calculateStorageFee($package_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT p.*, c.storage_daily_rate 
        FROM packages p
        JOIN companies c ON p.company_id = c.id
        WHERE p.id = ?
    ");
    $stmt->execute([$package_id]);
    $package = $stmt->fetch();
    
    if ($package && $package['status'] == 'arrived' && $package['arrival_date']) {
        $arrival_date = new DateTime($package['arrival_date']);
        $current_date = new DateTime();
        $days_diff = $arrival_date->diff($current_date)->days;
        
        // Free storage for first 7 days
        if ($days_diff > 7) {
            $storage_days = $days_diff - 7;
            $fee = $storage_days * $package['storage_daily_rate'];
            return $fee;
        }
    }
    
    return 0;
}

// Update package storage fee
function updatePackageStorageFee($package_id) {
    global $pdo;
    
    $fee = calculateStorageFee($package_id);
    
    $stmt = $pdo->prepare("
        UPDATE packages 
        SET storage_fee_total = ?
        WHERE id = ?
    ");
    return $stmt->execute([$fee, $package_id]);
}

// Get customer balance
function getCustomerBalance($customer_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT SUM(total_amount) as total_invoiced, SUM(paid_amount) as total_paid
        FROM invoices
        WHERE customer_id = ? AND status != 'cancelled'
    ");
    $stmt->execute([$customer_id]);
    $result = $stmt->fetch();
    
    return ($result['total_invoiced'] ?? 0) - ($result['total_paid'] ?? 0);
}

// Log activity
function logActivity($user_id, $action, $table_name = null, $record_id = null, $old_data = null, $new_data = null) {
    global $pdo;
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (user_id, action, table_name, record_id, old_data, new_data, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    return $stmt->execute([
        $user_id, $action, $table_name, $record_id,
        $old_data ? json_encode($old_data) : null,
        $new_data ? json_encode($new_data) : null,
        $ip_address, $user_agent
    ]);
}

// Send notification
function sendNotification($user_id, $title, $message, $type = null) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type)
        VALUES (?, ?, ?, ?)
    ");
    return $stmt->execute([$user_id, $title, $message, $type]);
}

// Send alert
function sendAlert($company_id, $alert_type, $title, $message, $branch_id = null, $customer_id = null, $priority = 'normal') {
    global $pdo;
    
    $stmt = $pdo->prepare("
        INSERT INTO alerts (company_id, branch_id, customer_id, alert_type, title, message, priority)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    return $stmt->execute([$company_id, $branch_id, $customer_id, $alert_type, $title, $message, $priority]);
}

// Format currency
function formatCurrency($amount, $currency = 'USD') {
    $symbols = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'SOS' => 'S'
    ];
    
    $symbol = $symbols[$currency] ?? '$';
    return $symbol . number_format($amount, 2);
}

// Get dashboard statistics
function getDashboardStats($company_id = null) {
    global $pdo;
    
    $company_filter = $company_id ? "AND company_id = " . intval($company_id) : "";
    
    $stats = [];
    
    // Total customers
    $stmt = $pdo->query("
        SELECT COUNT(*) as total FROM customers WHERE 1=1 $company_filter
    ");
    $stats['total_customers'] = $stmt->fetch()['total'];
    
    // Total packages
    $stmt = $pdo->query("
        SELECT COUNT(*) as total FROM packages WHERE 1=1 $company_filter
    ");
    $stats['total_packages'] = $stmt->fetch()['total'];
    
    // Packages in warehouse
    $stmt = $pdo->query("
        SELECT COUNT(*) as total FROM packages WHERE status = 'warehouse' $company_filter
    ");
    $stats['warehouse_packages'] = $stmt->fetch()['total'];
    
    // Packages in transit
    $stmt = $pdo->query("
        SELECT COUNT(*) as total FROM packages WHERE status IN ('in_container', 'in_transit') $company_filter
    ");
    $stats['transit_packages'] = $stmt->fetch()['total'];
    
    // Total revenue
    $stmt = $pdo->query("
        SELECT SUM(total_amount) as total FROM invoices WHERE status = 'paid' $company_filter
    ");
    $stats['total_revenue'] = $stmt->fetch()['total'] ?? 0;
    
    // Outstanding balance
    $stmt = $pdo->query("
        SELECT SUM(balance_due) as total FROM invoices WHERE status IN ('pending', 'partially_paid', 'overdue') $company_filter
    ");
    $stats['outstanding_balance'] = $stmt->fetch()['total'] ?? 0;
    
    return $stats;
}

// Get recent shipments
function getRecentShipments($limit = 10, $company_id = null) {
    global $pdo;
    
    $company_filter = $company_id ? "AND p.company_id = " . intval($company_id) : "";
    
    $stmt = $pdo->prepare("
        SELECT p.*, u.full_name as customer_name
        FROM packages p
        JOIN customers c ON p.customer_id = c.id
        JOIN users u ON c.user_id = u.id
        WHERE 1=1 $company_filter
        ORDER BY p.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

// Get recent invoices
function getRecentInvoices($limit = 10, $company_id = null) {
    global $pdo;
    
    $company_filter = $company_id ? "AND i.company_id = " . intval($company_id) : "";
    
    $stmt = $pdo->prepare("
        SELECT i.*, u.full_name as customer_name
        FROM invoices i
        JOIN customers c ON i.customer_id = c.id
        JOIN users u ON c.user_id = u.id
        WHERE 1=1 $company_filter
        ORDER BY i.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

// Get low capacity branches (warehouse occupancy > 80%)
function getLowCapacityBranches($company_id = null) {
    global $pdo;
    
    $company_filter = $company_id ? "AND company_id = " . intval($company_id) : "";
    
    $stmt = $pdo->query("
        SELECT b.*, 
               (current_cbm_occupied / max_cbm_capacity * 100) as occupancy_percentage
        FROM branches b
        WHERE status = 'active' $company_filter
        AND current_cbm_occupied / max_cbm_capacity * 100 > 80
    ");
    return $stmt->fetchAll();
}

// Get package status badge
function getStatusBadge($status) {
    $badges = [
        'received' => 'badge bg-secondary',
        'warehouse' => 'badge bg-info',
        'in_container' => 'badge bg-primary',
        'in_transit' => 'badge bg-warning',
        'arrived' => 'badge bg-success',
        'delivered' => 'badge bg-dark',
        'cancelled' => 'badge bg-danger'
    ];
    
    $labels = [
        'received' => 'Received',
        'warehouse' => 'In Warehouse',
        'in_container' => 'In Container',
        'in_transit' => 'In Transit',
        'arrived' => 'Arrived',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled'
    ];
    
    $badge_class = $badges[$status] ?? 'badge bg-secondary';
    $label = $labels[$status] ?? ucfirst($status);
    
    return "<span class='$badge_class'>$label</span>";
}

// Get invoice status badge
function getInvoiceStatusBadge($status) {
    $badges = [
        'pending' => 'badge bg-warning',
        'paid' => 'badge bg-success',
        'partially_paid' => 'badge bg-info',
        'overdue' => 'badge bg-danger'
    ];
    
    $labels = [
        'pending' => 'Pending',
        'paid' => 'Paid',
        'partially_paid' => 'Partially Paid',
        'overdue' => 'Overdue'
    ];
    
    $badge_class = $badges[$status] ?? 'badge bg-secondary';
    $label = $labels[$status] ?? ucfirst($status);
    
    return "<span class='$badge_class'>$label</span>";
}

// Upload file
function uploadFile($file, $target_dir, $allowed_types = ['jpg', 'jpeg', 'png', 'pdf']) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_extension, $allowed_types)) {
        return false;
    }
    
    $max_file_size = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $max_file_size) {
        return false;
    }
    
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $new_filename = time() . '_' . bin2hex(random_bytes(8)) . '.' . $file_extension;
    $target_path = $target_dir . $new_filename;
    
    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        return $target_path;
    }
    
    return false;
}

// Get company settings
function getCompanySettings($company_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT cbm_rate, storage_daily_rate, currency 
        FROM companies 
        WHERE id = ?
    ");
    $stmt->execute([$company_id]);
    return $stmt->fetch();
}

// Validate CSRF token
function validateCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
        return false;
    }
    return true;
}

// Generate CSRF token
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Sanitize input
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Redirect with message
function redirectWithMessage($url, $message, $type = 'success') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header("Location: $url");
    exit();
}

// Get flash message
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'success';
        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

// Initialize permissions table
createPermissionsTable();
?>
