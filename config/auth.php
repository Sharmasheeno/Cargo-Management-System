<?php
// config/auth.php
// Authentication functions forfaras cargo

// Load constants first
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/db_connect.php';

// ============================================
// SESSION START (if not already started)
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// NOTE: isLoggedIn() is now defined in functions.php
// DO NOT REDEFINE IT HERE!
// ============================================

// ============================================
// LOGIN FUNCTION
// ============================================

/**
 * Login user with email and password
 * @param string $email User email
 * @param string $password User password
 * @return bool True if login successful
 */
function loginUser($email, $password) {
    global $pdo;
    
    try {
        // Get user from isticmaalayaasha table
        $stmt = $pdo->prepare("
            SELECT * FROM isticmaalayaasha 
            WHERE email = ? AND firfircoon = 1 
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['magaca_dhameestiran'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_phone'] = $user['taleefan'];
            
            // Get user's primary company and role
            $stmt2 = $pdo->prepare("
                SELECT 
                    ish.shirkad_id, 
                    ish.door_id, 
                    dr.magaca_door, 
                    dr.heerka_door,
                    s.magaca as company_name,
                    s.midab_asaasi as company_color
                FROM isticmaalayaasha_shirkadaha ish
                JOIN doorarka_shaqaalaha dr ON ish.door_id = dr.id
                JOIN shirkadaha s ON ish.shirkad_id = s.id
                WHERE ish.isticmaale_id = ? AND ish.waa_mid_koowaad = 1 AND ish.firfircoon = 1
                LIMIT 1
            ");
            $stmt2->execute([$user['id']]);
            $companyData = $stmt2->fetch(PDO::FETCH_ASSOC);
            
            if ($companyData) {
                $_SESSION['company_id'] = $companyData['shirkad_id'];
                $_SESSION['company_name'] = $companyData['company_name'];
                $_SESSION['company_color'] = $companyData['company_color'];
                $_SESSION['role_id'] = $companyData['door_id'];
                $_SESSION['role'] = $companyData['magaca_door'];
                $_SESSION['role_level'] = $companyData['heerka_door'];
            } else {
                // If no company found, check if user is super admin
                $stmt3 = $pdo->prepare("
                    SELECT * FROM doorarka_shaqaalaha 
                    WHERE magaca_door = 'super_admin' AND heerka_door = 0
                ");
                $stmt3->execute();
                $superAdminRole = $stmt3->fetch(PDO::FETCH_ASSOC);
                
                if ($superAdminRole) {
                    $_SESSION['role_id'] = $superAdminRole['id'];
                    $_SESSION['role'] = 'super_admin';
                    $_SESSION['role_level'] = 0;
                }
            }
            
            // Update last login
            $updateStmt = $pdo->prepare("
                UPDATE isticmaalayaasha 
                SET galitaan_time = NOW(), ip_galitaan = ? 
                WHERE id = ?
            ");
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $updateStmt->execute([$ip, $user['id']]);
            
            return true;
        }
        return false;
    } catch(PDOException $e) {
        error_log("Login Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Login with username (alternative)
 * @param string $username Username
 * @param string $password Password
 * @return bool True if login successful
 */
function loginWithUsername($username, $password) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM isticmaalayaasha 
            WHERE magaca_dhameestiran = ? AND firfircoon = 1 
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['magaca_dhameestiran'];
            $_SESSION['user_email'] = $user['email'];
            
            $updateStmt = $pdo->prepare("
                UPDATE isticmaalayaasha 
                SET galitaan_time = NOW() 
                WHERE id = ?
            ");
            $updateStmt->execute([$user['id']]);
            
            return true;
        }
        return false;
    } catch(PDOException $e) {
        error_log("Login Error: " . $e->getMessage());
        return false;
    }
}

// ============================================
// AUTHENTICATION CHECK FUNCTIONS
// ============================================

/**
 * Check if user is super admin
 * @return bool True if super admin
 */
function isSuperAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin';
}

/**
 * Check if user is company admin
 * @return bool True if company admin
 */
function isCompanyAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'company_admin';
}

/**
 * Check if user is manager
 * @return bool True if manager
 */
function isManager() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'manager';
}

/**
 * Check if user is senior staff
 * @return bool True if senior staff
 */
function isSeniorStaff() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'senior_staff';
}

/**
 * Check if user is junior staff
 * @return bool True if junior staff
 */
function isJuniorStaff() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'junior_staff';
}

/**
 * Check if user is trainee
 * @return bool True if trainee
 */
function isTrainee() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'trainee';
}

/**
 * Check if user is staff (any staff role)
 * @return bool True if staff
 */
function isStaff() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], [
        'manager', 'senior_staff', 'junior_staff', 'trainee'
    ]);
}

/**
 * Check if user has specific role level
 * @param int $level Minimum role level required
 * @return bool True if user has sufficient level
 */
function hasRoleLevel($level) {
    return isset($_SESSION['role_level']) && $_SESSION['role_level'] <= $level;
}

/**
 * Require authentication - redirect if not logged in
 * NOTE: Uses isLoggedIn() from functions.php
 */
function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/login.php');
        exit();
    }
}

/**
 * Require super admin - redirect if not super admin
 */
function requireSuperAdmin() {
    requireAuth();
    if (!isSuperAdmin()) {
        header('Location: ' . APP_URL . '/index.php');
        exit();
    }
}

/**
 * Require company admin - redirect if not company admin or super admin
 */
function requireCompanyAdmin() {
    requireAuth();
    if (!isCompanyAdmin() && !isSuperAdmin()) {
        header('Location: ' . APP_URL . '/index.php');
        exit();
    }
}

/**
 * Require manager or higher - redirect if not manager, admin, or super admin
 */
function requireManager() {
    requireAuth();
    if (!isManager() && !isCompanyAdmin() && !isSuperAdmin()) {
        header('Location: ' . APP_URL . '/index.php');
        exit();
    }
}

/**
 * Require specific role
 * @param string|array $roles Allowed role(s)
 */
function requireRole($roles) {
    requireAuth();
    if (isSuperAdmin()) return true;
    
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    
    if (!in_array($_SESSION['role'] ?? '', $roles)) {
        header('Location: ' . APP_URL . '/index.php');
        exit();
    }
}

// ============================================
// USER DATA FUNCTIONS
// ============================================

/**
 * Get current logged in user data
 * @return array|false User data or false
 */
function getCurrentUser() {
    global $pdo;
    
    if (!isLoggedIn()) return false;
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM isticmaalayaasha 
            WHERE id = ?
        ");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("getCurrentUser Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get current company ID
 * @return int|null Company ID or null
 */
function getCurrentCompanyId() {
    return $_SESSION['company_id'] ?? null;
}

/**
 * Get current company data
 * @return array|false Company data or false
 */
function getCurrentCompany() {
    global $pdo;
    
    $company_id = getCurrentCompanyId();
    if (!$company_id) return false;
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM shirkadaha 
            WHERE id = ?
        ");
        $stmt->execute([$company_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("getCurrentCompany Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user role name
 * @return string Role name
 */
function getUserRole() {
    return $_SESSION['role'] ?? 'guest';
}

/**
 * Get user role level
 * @return int Role level
 */
function getUserRoleLevel() {
    return $_SESSION['role_level'] ?? 99;
}

// ============================================
// PERMISSION FUNCTIONS
// ============================================

/**
 * Check if user has specific permission
 * @param string $permission_key Permission key
 * @return bool True if has permission
 */
function hasPermission($permission_key) {
    global $pdo;
    
    if (!isLoggedIn()) return false;
    if (isSuperAdmin()) return true;
    
    $user_id = $_SESSION['user_id'];
    $company_id = getCurrentCompanyId();
    
    if (!$company_id) return false;
    
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM door_awoodaha da
            JOIN isticmaalayaasha_shirkadaha ish ON ish.door_id = da.door_id
            JOIN awoodaha a ON da.awood_id = a.id
            WHERE ish.isticmaale_id = ? 
            AND ish.shirkad_id = ?
            AND ish.firfircoon = 1
            AND a.awood_key = ?
        ");
        $stmt->execute([$user_id, $company_id, $permission_key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    } catch(PDOException $e) {
        error_log("hasPermission Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Require specific permission
 * @param string $permission_key Permission key
 */
function requirePermission($permission_key) {
    requireAuth();
    if (!hasPermission($permission_key) && !isSuperAdmin()) {
        die("You don't have permission to access this page.");
    }
}

// ============================================
// LOGOUT FUNCTION
// ============================================

/**
 * Logout user - destroy session
 */
function logoutUser() {
    // Log logout activity if needed
    if (isset($_SESSION['user_id'])) {
        global $pdo;
        try {
            $stmt = $pdo->prepare("
                UPDATE isticmaalayaasha 
                SET last_login_ip = ? 
                WHERE id = ?
            ");
            $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? null, $_SESSION['user_id']]);
        } catch(PDOException $e) {
            // Silent fail
        }
    }
    
    // Destroy session
    $_SESSION = array();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
    header('Location: ' . APP_URL . '/login.php');
    exit();
}

// ============================================
// PASSWORD FUNCTIONS
// ============================================

/**
 * Change user password
 * @param int $user_id User ID
 * @param string $old_password Current password
 * @param string $new_password New password
 * @return array [success, message]
 */
function changePassword($user_id, $old_password, $new_password) {
    global $pdo;
    
    try {
        // Verify old password
        $stmt = $pdo->prepare("SELECT password_hash FROM isticmaalayaasha WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !password_verify($old_password, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Current password is incorrect'];
        }
        
        // Validate new password strength
        if (!preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[@$!%*#?&]).{8,}$/', $new_password)) {
            return ['success' => false, 'message' => 'Password does not meet requirements'];
        }
        
        // Update password
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $updateStmt = $pdo->prepare("UPDATE isticmaalayaasha SET password_hash = ? WHERE id = ?");
        
        if ($updateStmt->execute([$new_hash, $user_id])) {
            return ['success' => true, 'message' => 'Password changed successfully'];
        }
        
        return ['success' => false, 'message' => 'Failed to update password'];
    } catch(PDOException $e) {
        error_log("changePassword Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error occurred'];
    }
}

/**
 * Verify user password
 * @param int $user_id User ID
 * @param string $password Password to verify
 * @return bool True if password matches
 */
function verifyUserPassword($user_id, $password) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT password_hash FROM isticmaalayaasha WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $user && password_verify($password, $user['password_hash']);
    } catch(PDOException $e) {
        error_log("verifyUserPassword Error: " . $e->getMessage());
        return false;
    }
}

// ============================================
// SESSION MANAGEMENT
// ============================================

/**
 * Regenerate session ID for security
 */
function regenerateSession() {
    session_regenerate_id(true);
}

/**
 * Set flash message
 * @param string $key Message key
 * @param string $message Message content
 * @param string $type Message type (success, error, warning, info)
 */
function setFlashMessage($key, $message, $type = 'success') {
    $_SESSION['flash'][$key] = [
        'message' => $message,
        'type' => $type
    ];
}

/**
 * Get flash message
 * @param string $key Message key
 * @return array|null Message data or null
 */
function getFlashMessage($key) {
    if (isset($_SESSION['flash'][$key])) {
        $message = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $message;
    }
    return null;
}

/**
 * Display flash message
 * @param string $key Message key
 */
function displayFlashMessage($key) {
    $flash = getFlashMessage($key);
    if ($flash) {
        $color = $flash['type'] === 'success' ? COLOR_PRIMARY : ($flash['type'] === 'error' ? '#B42318' : COLOR_SECONDARY);
        echo "<div class='alert alert-{$flash['type']}' style='border-left: 4px solid $color; padding: 12px; margin: 10px 0; background: #f8f6f9; border-radius: 8px;'>
                <i class='fas fa-" . ($flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle') . "' style='color: $color; margin-right: 8px;'></i>
                {$flash['message']}
              </div>";
    }
}

// ============================================
// RETURN PDO FOR BACKWARD COMPATIBILITY
// ============================================
return $pdo;
?>