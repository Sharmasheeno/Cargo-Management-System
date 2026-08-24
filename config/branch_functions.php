<?php
// config/branch_functions.php
// Branch Management Functions

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db_connect.php';

/**
 * Get all branches for a tenant
 */
function getTenantBranches($tenant_id, $status = null) {
    global $pdo;
    try {
        $sql = "SELECT b.*, 
                       COUNT(DISTINCT uba.user_id) as total_staff,
                       COUNT(DISTINCT bs.warehouse_stock_id) as total_stock_items,
                       SUM(bs.quantity) as total_stock_quantity
                FROM branches b
                LEFT JOIN user_branch_assignments uba ON b.id = uba.branch_id
                LEFT JOIN branch_stock bs ON b.id = bs.branch_id
                WHERE b.tenant_id = ?";
        $params = [$tenant_id];
        
        if ($status) {
            $sql .= " AND b.status = ?";
            $params[] = $status;
        }
        
        $sql .= " GROUP BY b.id ORDER BY b.branch_name ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("getTenantBranches Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get single branch by ID
 */
function getBranchById($branch_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
        $stmt->execute([$branch_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("getBranchById Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Create new branch
 */
function createBranch($data) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO branches (tenant_id, branch_code, branch_name, branch_type, address, phone, email, 
                                 manager_name, manager_phone, location_lat, location_lng, opening_time, 
                                 closing_time, max_capacity_cbm, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['tenant_id'], $data['branch_code'], $data['branch_name'], $data['branch_type'],
            $data['address'] ?? null, $data['phone'] ?? null, $data['email'] ?? null,
            $data['manager_name'] ?? null, $data['manager_phone'] ?? null, $data['location_lat'] ?? null,
            $data['location_lng'] ?? null, $data['opening_time'] ?? null, $data['closing_time'] ?? null,
            $data['max_capacity_cbm'] ?? 0, $data['status'] ?? 'active', $_SESSION['user_id'] ?? null
        ]);
        
        $branch_id = $pdo->lastInsertId();
        
        // Log activity
        logBranchActivity($branch_id, $_SESSION['user_id'], 'branch_created', "Branch {$data['branch_name']} created");
        
        return $branch_id;
    } catch(PDOException $e) {
        error_log("createBranch Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Update branch
 */
function updateBranch($branch_id, $data) {
    global $pdo;
    try {
        $fields = [];
        $params = [];
        
        $allowed_fields = ['branch_code', 'branch_name', 'branch_type', 'address', 'phone', 'email', 
                          'manager_name', 'manager_phone', 'location_lat', 'location_lng', 
                          'opening_time', 'closing_time', 'max_capacity_cbm', 'status'];
        
        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        
        if (empty($fields)) return false;
        
        $params[] = $branch_id;
        $sql = "UPDATE branches SET " . implode(", ", $fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute($params);
        
        // Log activity
        logBranchActivity($branch_id, $_SESSION['user_id'], 'branch_updated', "Branch updated");
        
        return $result;
    } catch(PDOException $e) {
        error_log("updateBranch Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete branch (soft delete - set status to inactive)
 */
function deleteBranch($branch_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("UPDATE branches SET status = 'inactive' WHERE id = ?");
        $result = $stmt->execute([$branch_id]);
        
        logBranchActivity($branch_id, $_SESSION['user_id'], 'branch_deleted', "Branch marked as inactive");
        
        return $result;
    } catch(PDOException $e) {
        error_log("deleteBranch Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Assign user to branch
 */
function assignUserToBranch($user_id, $branch_id, $is_primary = false, $can_manage_branch = false, $permissions = null) {
    global $pdo;
    try {
        // Check if already assigned
        $check = $pdo->prepare("SELECT id FROM user_branch_assignments WHERE user_id = ? AND branch_id = ?");
        $check->execute([$user_id, $branch_id]);
        
        if ($check->fetch()) {
            return updateUserBranchAssignment($user_id, $branch_id, $is_primary, $can_manage_branch, $permissions);
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO user_branch_assignments (user_id, branch_id, is_primary, can_manage_branch, permissions, assigned_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $result = $stmt->execute([$user_id, $branch_id, $is_primary ? 1 : 0, $can_manage_branch ? 1 : 0, 
                                   $permissions ? json_encode($permissions) : null, $_SESSION['user_id'] ?? null]);
        
        if ($is_primary) {
            // Set as primary branch in users table
            $stmt2 = $pdo->prepare("UPDATE users SET default_branch_id = ? WHERE id = ?");
            $stmt2->execute([$branch_id, $user_id]);
        }
        
        logBranchActivity($branch_id, $_SESSION['user_id'], 'user_assigned', "User ID: $user_id assigned to branch");
        
        return $result;
    } catch(PDOException $e) {
        error_log("assignUserToBranch Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Update user branch assignment
 */
function updateUserBranchAssignment($user_id, $branch_id, $is_primary = false, $can_manage_branch = false, $permissions = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            UPDATE user_branch_assignments 
            SET is_primary = ?, can_manage_branch = ?, permissions = ?
            WHERE user_id = ? AND branch_id = ?
        ");
        return $stmt->execute([$is_primary ? 1 : 0, $can_manage_branch ? 1 : 0, 
                               $permissions ? json_encode($permissions) : null, $user_id, $branch_id]);
    } catch(PDOException $e) {
        error_log("updateUserBranchAssignment Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Remove user from branch
 */
function removeUserFromBranch($user_id, $branch_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("DELETE FROM user_branch_assignments WHERE user_id = ? AND branch_id = ?");
        return $stmt->execute([$user_id, $branch_id]);
    } catch(PDOException $e) {
        error_log("removeUserFromBranch Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user branches
 */
function getUserBranches($user_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT b.*, uba.is_primary, uba.can_manage_branch, uba.permissions
            FROM branches b
            JOIN user_branch_assignments uba ON b.id = uba.branch_id
            WHERE uba.user_id = ? AND b.status = 'active'
            ORDER BY uba.is_primary DESC, b.branch_name ASC
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("getUserBranches Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get branch staff
 */
function getBranchStaff($branch_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT u.*, uba.is_primary, uba.can_manage_branch, uba.permissions
            FROM users u
            JOIN user_branch_assignments uba ON u.id = uba.user_id
            WHERE uba.branch_id = ? AND u.is_active = 1
            ORDER BY uba.is_primary DESC, u.full_name ASC
        ");
        $stmt->execute([$branch_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("getBranchStaff Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Log branch activity
 */
function logBranchActivity($branch_id, $user_id, $action, $description) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO branch_activity_logs (branch_id, user_id, action, description, ip_address)
            VALUES (?, ?, ?, ?, ?)
        ");
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return $stmt->execute([$branch_id, $user_id, $action, $description, $ip]);
    } catch(PDOException $e) {
        error_log("logBranchActivity Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user's current branch (from session or default)
 */
function getCurrentUserBranch() {
    global $pdo;
    
    if (isset($_SESSION['current_branch_id'])) {
        $branch = getBranchById($_SESSION['current_branch_id']);
        if ($branch) return $branch;
    }
    
    $user_id = $_SESSION['user_id'] ?? null;
    if ($user_id) {
        $branches = getUserBranches($user_id);
        if (!empty($branches)) {
            $_SESSION['current_branch_id'] = $branches[0]['id'];
            return $branches[0];
        }
    }
    
    return null;
}

/**
 * Set current branch in session
 */
function setCurrentBranch($branch_id) {
    $_SESSION['current_branch_id'] = $branch_id;
    return true;
}

/**
 * Check if user has access to branch
 */
function userHasBranchAccess($user_id, $branch_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM user_branch_assignments 
            WHERE user_id = ? AND branch_id = ?
        ");
        $stmt->execute([$user_id, $branch_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    } catch(PDOException $e) {
        return false;
    }
}

/**
 * Get branch statistics
 */
function getBranchStatistics($branch_id) {
    global $pdo;
    try {
        $stats = [];
        
        // Total staff
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM user_branch_assignments WHERE branch_id = ?");
        $stmt->execute([$branch_id]);
        $stats['total_staff'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Total stock items
        $stmt = $pdo->prepare("SELECT COUNT(*) as count, SUM(quantity) as total_quantity FROM branch_stock WHERE branch_id = ?");
        $stmt->execute([$branch_id]);
        $stock = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_stock_items'] = $stock['count'] ?? 0;
        $stats['total_stock_quantity'] = $stock['total_quantity'] ?? 0;
        
        // Storage usage percentage
        $branch = getBranchById($branch_id);
        if ($branch && $branch['max_capacity_cbm'] > 0) {
            $stats['storage_usage_percent'] = round(($branch['current_used_cbm'] / $branch['max_capacity_cbm']) * 100, 2);
        } else {
            $stats['storage_usage_percent'] = 0;
        }
        
        // Pending transfers
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM branch_transfers 
            WHERE (from_branch_id = ? OR to_branch_id = ?) AND status = 'pending'
        ");
        $stmt->execute([$branch_id, $branch_id]);
        $stats['pending_transfers'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        return $stats;
    } catch(PDOException $e) {
        error_log("getBranchStatistics Error: " . $e->getMessage());
        return [];
    }
}