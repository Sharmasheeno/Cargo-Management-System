<?php
// config/functions.php
// General helper functions forfaras cargo
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';

// ============================================
// AUTHENTICATION FUNCTIONS
// ============================================

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Require login - redirect if not logged in
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ../login.php');
        exit();
    }
}

/**
 * Get current user ID
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user role
 */
function getCurrentUserRole() {
    return $_SESSION['role'] ?? 'guest';
}

/**
 * Get current tenant ID
 */
function getCurrentTenantId() {
    return $_SESSION['tenant_id'] ?? null;
}

/**
 * Check if user is super admin
 */
function isSuperAdmin() {
    return getCurrentUserRole() === 'superadmin';
}

/**
 * Check if user is tenant admin
 */
function isTenantAdmin() {
    $role = getCurrentUserRole();
    return $role === 'tenant_admin' || $role === 'company_admin';
}

// ============================================
// BASIC CRUD FUNCTIONS (FIXED - No deprecated errors)
// ============================================

/**
 * Get all records from a table
 */
function getAll($table, $order_by = 'id', $order_direction = 'DESC') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM $table ORDER BY $order_by $order_direction");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("getAll Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get record by ID (FIXED: removed optional parameter before required)
 */
function getById($table, $id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("getById Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get record by custom field (FIXED)
 */
function getByField($table, $field, $value) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE $field = ?");
        $stmt->execute([$value]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("getByField Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get records with custom WHERE clause
 */
function getWhere($table, $where, $params = [], $order_by = 'id', $order_direction = 'DESC') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE $where ORDER BY $order_by $order_direction");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("getWhere Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get single record with custom WHERE clause
 */
function getOneWhere($table, $where, $params = []) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE $where LIMIT 1");
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("getOneWhere Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Insert record into table
 */
function insertRecord($table, $data) {
    global $pdo;
    try {
        $fields = implode(", ", array_keys($data));
        $placeholders = ":" . implode(", :", array_keys($data));
        $stmt = $pdo->prepare("INSERT INTO $table ($fields) VALUES ($placeholders)");
        
        if ($stmt->execute($data)) {
            return $pdo->lastInsertId();
        }
        return false;
    } catch(PDOException $e) {
        error_log("insertRecord Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Update record by ID (FIXED: removed optional parameter before required)
 */
function updateRecord($table, $id, $data) {
    global $pdo;
    try {
        $setPart = implode(", ", array_map(function($k) { return "$k = :$k"; }, array_keys($data)));
        $stmt = $pdo->prepare("UPDATE $table SET $setPart WHERE id = :id");
        $data['id'] = $id;
        return $stmt->execute($data);
    } catch(PDOException $e) {
        error_log("updateRecord Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Update record by custom field (FIXED)
 */
function updateByField($table, $field, $value, $data) {
    global $pdo;
    try {
        $setPart = implode(", ", array_map(function($k) { return "$k = :$k"; }, array_keys($data)));
        $stmt = $pdo->prepare("UPDATE $table SET $setPart WHERE $field = :field_value");
        $data['field_value'] = $value;
        return $stmt->execute($data);
    } catch(PDOException $e) {
        error_log("updateByField Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete record by ID (FIXED: removed optional parameter before required)
 */
function deleteRecord($table, $id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("DELETE FROM $table WHERE id = ?");
        return $stmt->execute([$id]);
    } catch(PDOException $e) {
        error_log("deleteRecord Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete record by custom field (FIXED)
 */
function deleteByField($table, $field, $value) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("DELETE FROM $table WHERE $field = ?");
        return $stmt->execute([$value]);
    } catch(PDOException $e) {
        error_log("deleteByField Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Count records in table
 */
function countRecords($table, $where = '', $params = []) {
    global $pdo;
    try {
        $sql = "SELECT COUNT(*) as count FROM $table";
        if ($where) {
            $sql .= " WHERE $where";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] ?? 0;
    } catch(PDOException $e) {
        error_log("countRecords Error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Check if record exists by ID
 */
function recordExists($table, $id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    } catch(PDOException $e) {
        error_log("recordExists Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if record exists by custom field
 */
function recordExistsByField($table, $field, $value) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM $table WHERE $field = ?");
        $stmt->execute([$value]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    } catch(PDOException $e) {
        error_log("recordExistsByField Error: " . $e->getMessage());
        return false;
    }
}

// ============================================
// FORMATTING FUNCTIONS
// ============================================

/**
 * Format date to display format
 */
function formatDate($date, $format = 'd/m/Y') {
    if (!$date || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return '-';
    return date($format, strtotime($date));
}

/**
 * Format datetime to display format
 */
function formatDateTime($datetime, $format = 'd/m/Y H:i:s') {
    if (!$datetime) return '-';
    return date($format, strtotime($datetime));
}

/**
 * Format money
 */
function formatMoney($amount, $currency = '$') {
    return $currency . number_format($amount, 2);
}

/**
 * Sanitize input
 */
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate random string
 */
function generateRandomString($length = 10) {
    return substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, $length);
}

// ============================================
// INVOICE NUMBER GENERATION
// ============================================

/**
 * Generate auto invoice number
 */
function generateInvoiceNumber($pdo, $tenant_id = null) {
    $prefix = 'INV';
    $year = date('Y');
    $month = date('m');
    
    $pattern = "$prefix-$year$month-%";
    $stmt = $pdo->prepare("SELECT invoice_number FROM invoices WHERE invoice_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$pattern]);
    $lastInvoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($lastInvoice) {
        $parts = explode('-', $lastInvoice['invoice_number']);
        $lastSeq = (int)end($parts);
        $newSeq = $lastSeq + 1;
    } else {
        $newSeq = 1;
    }
    
    $sequence = str_pad($newSeq, 4, '0', STR_PAD_LEFT);
    return "$prefix-$year$month-$sequence";
}

/**
 * Generate receipt number
 */
function generateReceiptNumber() {
    return 'REC-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

// ============================================
// TRACKING NUMBER GENERATION
// ============================================

/**
 * Generate tracking number for packages
 * This function checks both packages and containers tables for uniqueness
 */
function generateTrackingNumber($pdo, $tenant_id = null, $prefix = 'TRK') {
    $date = date('Ymd');
    $random = strtoupper(substr(uniqid(), -4));
    
    $tracking = $prefix . '-' . $date . '-' . $random;
    
    // Check if exists in packages table
    try {
        $check = $pdo->prepare("SELECT id FROM packages WHERE tracking_number = ?");
        $check->execute([$tracking]);
        if ($check->fetch()) {
            return generateTrackingNumber($pdo, $tenant_id, $prefix);
        }
    } catch (PDOException $e) {
        // Table might not exist yet
    }
    
    // Check if exists in containers table
    try {
        $check = $pdo->prepare("SELECT id FROM containers WHERE tracking_number = ?");
        $check->execute([$tracking]);
        if ($check->fetch()) {
            return generateTrackingNumber($pdo, $tenant_id, $prefix);
        }
    } catch (PDOException $e) {
        // Table might not exist yet
    }
    
    return $tracking;
}

/**
 * Generate container tracking number
 */
function generateContainerTrackingNumber($pdo, $tenant_id = null) {
    return generateTrackingNumber($pdo, $tenant_id, 'CNTR');
}

// ============================================
// COMPANY SPECIFIC FUNCTIONS
// ============================================

/**
 * Get all active companies
 */
function getActiveCompanies() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM tenants WHERE is_active = 1 ORDER BY name ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("getActiveCompanies Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get company by ID
 */
function getCompany($company_id) {
    return getById('tenants', $company_id);
}

/**
 * Get company staff members
 */
function getCompanyStaff($company_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT u.* FROM users u
            WHERE u.tenant_id = ? AND u.role_type IN ('staff', 'company_admin')
            ORDER BY u.full_name ASC
        ");
        $stmt->execute([$company_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("getCompanyStaff Error: " . $e->getMessage());
        return [];
    }
}

// ============================================
// DASHBOARD STATS
// ============================================

/**
 * Get super admin dashboard stats
 */
function getSuperAdminDashboardStats() {
    global $pdo;
    try {
        $stats = [];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tenants");
        $stmt->execute();
        $stats['total_companies'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tenants WHERE is_active = 1");
        $stmt->execute();
        $stats['active_companies'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE role_type != 'superadmin'");
        $stmt->execute();
        $stats['total_users'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM customers");
        $stmt->execute();
        $stats['total_customers'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM containers");
        $stmt->execute();
        $stats['total_containers'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) as total FROM invoices");
        $stmt->execute();
        $stats['total_revenue'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        return $stats;
    } catch(PDOException $e) {
        error_log("getSuperAdminDashboardStats Error: " . $e->getMessage());
        return [];
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Show alert message
 */
function showAlert($message, $type = 'success') {
    $icons = [
        'success' => 'check-circle',
        'error' => 'exclamation-circle',
        'warning' => 'exclamation-triangle',
        'info' => 'info-circle'
    ];
    $icon = $icons[$type] ?? 'info-circle';
    $color = $type === 'success' ? '#2D1859' : ($type === 'error' ? '#B42318' : '#FFB400');
    echo "<div class='alert alert-$type' style='border-left: 4px solid $color; padding: 12px; margin: 10px 0; background: #f8f6f9; border-radius: 8px;'>
            <i class='fas fa-$icon' style='color: $color; margin-right: 8px;'></i> $message
          </div>";
}

/**
 * Redirect to URL
 */
function redirect($url) {
    header("Location: $url");
    exit();
}

/**
 * Get status badge HTML
 */
function getStatusBadge($status) {
    $badges = [
        'active' => '<span style="background: #2D1859; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px;">Firfircoon</span>',
        'inactive' => '<span style="background: #B42318; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px;">Aan Firfircoonayn</span>',
        'pending' => '<span style="background: #FFB400; color: #333; padding: 4px 12px; border-radius: 20px; font-size: 12px;">Sugaya</span>',
        'delivered' => '<span style="background: #2D1859; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px;">La Gaarsiiyay</span>',
        'at_port' => '<span style="background: #4B2C85; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px;">Dekedda</span>'
    ];
    return $badges[$status] ?? '<span style="background: #999; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px;">' . $status . '</span>';
}

/**
 * Upload file
 */
function uploadFile($file, $upload_dir, $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'pdf'], $max_size = 5242880) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_types)) {
        return false;
    }
    
    if ($file['size'] > $max_size) {
        return false;
    }
    
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $new_name = uniqid() . '_' . time() . '.' . $ext;
    $upload_path = $upload_dir . $new_name;
    
    if (move_uploaded_file($file['tmp_name'], $upload_path)) {
        return $upload_path;
    }
    
    return false;
}

/**
 * Log activity for audit trail
 */
function logActivity($pdo, $user_id, $action, $table_name = null, $record_id = null, $old_values = null, $new_values = null) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $old_json = $old_values ? json_encode($old_values) : null;
        $new_json = $new_values ? json_encode($new_values) : null;
        
        return $stmt->execute([$user_id, $action, $table_name, $record_id, $old_json, $new_json, $ip, $user_agent]);
    } catch (PDOException $e) {
        error_log("logActivity Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user name by ID
 */
function getUserName($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user['full_name'] ?? 'Unknown';
    } catch (PDOException $e) {
        return 'Unknown';
    }
}

/**
 * Get customer name by ID
 */
function getCustomerName($pdo, $customer_id) {
    try {
        $stmt = $pdo->prepare("SELECT customer_name FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        return $customer['customer_name'] ?? 'Unknown';
    } catch (PDOException $e) {
        return 'Unknown';
    }
}

/**
 * Get tenant name by ID
 */
function getTenantName($pdo, $tenant_id) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
        $stmt->execute([$tenant_id]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        return $tenant['name'] ?? 'Unknown';
    } catch (PDOException $e) {
        return 'Unknown';
    }
}

// Return PDO for backward compatibility
return $pdo;
?>