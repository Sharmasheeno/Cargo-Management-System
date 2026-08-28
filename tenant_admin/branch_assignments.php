<?php
// tenant_admin/branches.php
// Branch & Staff Management for Cargo Management System - Tenant Admin

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

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Tenant Admin';
$current_user_id = $_SESSION['user_id'];

// Get tenant name
$tenant_name = '';
try {
    $stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
    $stmt->execute([$session_tenant_id]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_name = $tenant['name'] ?? 'My Company';
} catch (PDOException $e) {
    $tenant_name = 'My Company';
}

// ── Ensure branches table columns exist ─────────────────────────────────────────────────────
try {
    $pdo->exec("ALTER TABLE branches ADD COLUMN IF NOT EXISTS branch_code VARCHAR(50) NOT NULL DEFAULT ''");
    $pdo->exec("ALTER TABLE branches ADD COLUMN IF NOT EXISTS branch_name VARCHAR(255) NOT NULL DEFAULT ''");
    $pdo->exec("ALTER TABLE branches ADD COLUMN IF NOT EXISTS branch_type ENUM('main','warehouse','office','store','customs','port') DEFAULT 'office'");
    $pdo->exec("ALTER TABLE branches ADD COLUMN IF NOT EXISTS address TEXT DEFAULT NULL");
    $pdo->exec("ALTER TABLE branches ADD COLUMN IF NOT EXISTS phone VARCHAR(50) DEFAULT NULL");
    $pdo->exec("ALTER TABLE branches ADD COLUMN IF NOT EXISTS email VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE branches ADD COLUMN IF NOT EXISTS manager_name VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE branches ADD COLUMN IF NOT EXISTS manager_phone VARCHAR(50) DEFAULT NULL");
    $pdo->exec("ALTER TABLE branches ADD COLUMN IF NOT EXISTS location_lat DECIMAL(10,8) DEFAULT NULL");
    $pdo->exec("ALTER TABLE branches ADD COLUMN IF NOT EXISTS location_lng DECIMAL(11,8) DEFAULT NULL");
    $pdo->exec("ALTER TABLE branches ADD COLUMN IF NOT EXISTS opening_time TIME DEFAULT NULL");
    $pdo->exec("ALTER TABLE branches ADD COLUMN IF NOT EXISTS closing_time TIME DEFAULT NULL");
    $pdo->exec("ALTER TABLE branches ADD COLUMN IF NOT EXISTS max_capacity_cbm DECIMAL(15,2) DEFAULT 0.00");
    $pdo->exec("ALTER TABLE branches ADD COLUMN IF NOT EXISTS current_used_cbm DECIMAL(15,2) DEFAULT 0.00");
    $pdo->exec("ALTER TABLE branches ADD COLUMN IF NOT EXISTS status ENUM('active','inactive','temporary_closed','permanently_closed') DEFAULT 'active'");
    $pdo->exec("ALTER TABLE branches ADD COLUMN IF NOT EXISTS created_by INT(11) DEFAULT NULL");
} catch (PDOException $e) {
    // Ignore errors if columns already exist
}

// Create user_branch_assignments table if not exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_branch_assignments (
        id INT(11) NOT NULL AUTO_INCREMENT,
        user_id INT(11) NOT NULL,
        branch_id INT(11) NOT NULL,
        is_primary TINYINT(1) DEFAULT 0,
        can_manage_branch TINYINT(1) DEFAULT 0,
        permissions LONGTEXT DEFAULT NULL,
        assigned_by INT(11) DEFAULT NULL,
        assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_user_branch (user_id, branch_id),
        KEY idx_user_id (user_id),
        KEY idx_branch_id (branch_id),
        KEY idx_is_primary (is_primary),
        KEY assigned_by (assigned_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
    // Table might already exist
}

// Create branch_activity_logs table if not exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS branch_activity_logs (
        id INT(11) NOT NULL AUTO_INCREMENT,
        branch_id INT(11) NOT NULL,
        user_id INT(11) NOT NULL,
        action VARCHAR(100) NOT NULL,
        description TEXT DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_branch_id (branch_id),
        KEY idx_user_id (user_id),
        KEY idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
    // Table might already exist
}

// Branch type definitions
$branch_types = [
    'main' => 'Head Office 🏢',
    'warehouse' => 'Warehouse 📦',
    'office' => 'Office 📋',
    'store' => 'Store 🏪',
    'customs' => 'Customs 🛃',
    'port' => 'Port ⚓'
];

$branch_type_colors = [
    'main' => '#8B5CF6',
    'warehouse' => '#F59E0B',
    'office' => '#3B82F6',
    'store' => '#10B981',
    'customs' => '#EF4444',
    'port' => '#06B6D6'
];

$status_names = [
    'active' => 'Active',
    'inactive' => 'Inactive',
    'temporary_closed' => 'Temporarily Closed',
    'permanently_closed' => 'Permanently Closed'
];

$status_colors = [
    'active' => '#10B981',
    'inactive' => '#6B7280',
    'temporary_closed' => '#F59E0B',
    'permanently_closed' => '#EF4444'
];

// Function to create user account for branch manager
function createBranchManagerUser($pdo, $tenant_id, $branch_id, $manager_name, $manager_phone, $branch_name, $created_by) {
    try {
        $email = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $manager_name)) . '@' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $branch_name)) . '.curdun.com';
        if (empty($email) || strpos($email, '@') === false) {
            $email = 'branch_' . $branch_id . '@' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $branch_name)) . '.curdun.com';
        }
        
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND tenant_id = ?");
        $check->execute([$email, $tenant_id]);
        if ($check->fetch()) {
            return ['success' => false, 'message' => 'User already exists with email: ' . $email];
        }
        
        $password_hash = password_hash('123', PASSWORD_DEFAULT);
        
        $sql = "INSERT INTO users (tenant_id, full_name, email, password_hash, role, role_type, phone, created_by, created_at, is_active) 
                VALUES (?, ?, ?, ?, 'staff', 'branch_manager', ?, ?, NOW(), 1)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tenant_id, $manager_name, $email, $password_hash, $manager_phone, $created_by]);
        
        $new_user_id = $pdo->lastInsertId();
        
        $assignSql = "INSERT INTO user_branch_assignments (user_id, branch_id, is_primary, can_manage_branch, assigned_by, assigned_at) 
                      VALUES (?, ?, 1, 1, ?, NOW())";
        $assignStmt = $pdo->prepare($assignSql);
        $assignStmt->execute([$new_user_id, $branch_id, $created_by]);
        
        return [
            'success' => true, 
            'user_id' => $new_user_id,
            'email' => $email,
            'password' => '123',
            'message' => "User account created for $manager_name (Email: $email, Password: 123)"
        ];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Failed to create user: ' . $e->getMessage()];
    }
}

// Function to log branch activity
function logBranchActivity($pdo, $branch_id, $user_id, $action, $description = null) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $pdo->prepare("INSERT INTO branch_activity_logs (branch_id, user_id, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$branch_id, $user_id, $action, $description, $ip]);
        return true;
    } catch (PDOException $e) {
        error_log("Failed to log branch activity: " . $e->getMessage());
        return false;
    }
}

// Function to get branch staff
function getBranchStaff($pdo, $branch_id, $tenant_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.email, u.phone, u.role, u.role_type, 
                   uba.is_primary, uba.can_manage_branch, uba.permissions, uba.assigned_at, uba.id as assignment_id
            FROM user_branch_assignments uba
            JOIN users u ON uba.user_id = u.id
            WHERE uba.branch_id = ? AND u.tenant_id = ?
            ORDER BY uba.is_primary DESC, u.full_name
        ");
        $stmt->execute([$branch_id, $tenant_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Function to get available users for assignment
function getAvailableUsers($pdo, $tenant_id, $exclude_branch_id = null) {
    try {
        $sql = "
            SELECT u.id, u.full_name, u.email, u.phone, u.role, u.role_type
            FROM users u
            WHERE u.tenant_id = ? AND u.is_active = 1 AND u.role_type NOT IN ('superadmin', 'tenant_admin')
        ";
        $params = [$tenant_id];
        if ($exclude_branch_id) {
            $sql .= " AND u.id NOT IN (SELECT user_id FROM user_branch_assignments WHERE branch_id = ?)";
            $params[] = $exclude_branch_id;
        }
        $sql .= " ORDER BY u.full_name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];
    
    if ($action === 'get_branches') {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $search = $_POST['search'] ?? '';
        $branch_type_filter = $_POST['branch_type'] ?? '';
        $status_filter = $_POST['status'] ?? '';
        
        $where_conditions = ["b.tenant_id = ?"];
        $params = [$session_tenant_id];
        
        if (!empty($search)) {
            $where_conditions[] = "(b.branch_code LIKE ? OR b.branch_name LIKE ? OR b.manager_name LIKE ? OR b.address LIKE ? OR b.phone LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if (!empty($branch_type_filter)) {
            $where_conditions[] = "b.branch_type = ?";
            $params[] = $branch_type_filter;
        }
        
        if (!empty($status_filter)) {
            $where_conditions[] = "b.status = ?";
            $params[] = $status_filter;
        }
        
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
        
        $count_sql = "SELECT COUNT(*) as total FROM branches b $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_branches = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_branches / $limit);
        
        $sql = "
            SELECT b.*, 
                   (SELECT COUNT(*) FROM user_branch_assignments uba WHERE uba.branch_id = b.id) as staff_count,
                   (SELECT u.email FROM users u
                    JOIN user_branch_assignments uba ON u.id = uba.user_id
                    WHERE uba.branch_id = b.id AND uba.is_primary = 1
                      AND u.role_type = 'branch_manager' AND u.is_active = 1
                    LIMIT 1) as manager_email,
                   (SELECT u.full_name FROM users u
                    JOIN user_branch_assignments uba ON u.id = uba.user_id
                    WHERE uba.branch_id = b.id AND uba.is_primary = 1
                      AND u.role_type = 'branch_manager' AND u.is_active = 1
                    LIMIT 1) as manager_name_assigned
            FROM branches b
            $where_clause
            ORDER BY b.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate table HTML
        ob_start(); ?>
        <div style="overflow-x: auto; width: 100%;">
            <table class="branches-table" style="min-width: 1200px; width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f6f9;">
                        <th style="padding: 12px;">ID</th>
                        <th style="padding: 12px;">Code & Name</th>
                        <th style="padding: 12px;">Type</th>
                        <th style="padding: 12px;">Manager</th>
                        <th style="padding: 12px;">Contact</th>
                        <th style="padding: 12px;">Capacity</th>
                        <th style="padding: 12px;">Status</th>
                        <th style="padding: 12px;">Staff</th>
                        <th style="padding: 12px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($branches) > 0): ?>
                        <?php foreach ($branches as $branch): 
                            $typeColor = $branch_type_colors[$branch['branch_type']] ?? '#6c757d';
                            $typeName = $branch_types[$branch['branch_type']] ?? ucfirst($branch['branch_type']);
                            $statusColor = $status_colors[$branch['status']] ?? '#6c757d';
                            $statusName = $status_names[$branch['status']] ?? ucfirst($branch['status']);
                            $displayManager = !empty($branch['manager_name_assigned']) ? $branch['manager_name_assigned'] : $branch['manager_name'];
                            $capacityPercent = $branch['max_capacity_cbm'] > 0 ? min(100, ($branch['current_used_cbm'] / $branch['max_capacity_cbm']) * 100) : 0;
                        ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px;"><?= $branch['id'] ?> </td>
                                <td style="padding: 12px;">
                                    <strong><?= htmlspecialchars($branch['branch_code']) ?></strong>
                                    <div style="font-size: 14px; margin-top: 4px;"><?= htmlspecialchars($branch['branch_name']) ?></div>
                                    <div style="font-size: 12px; color: #6c757d; margin-top: 4px;">
                                        <i class="fas fa-calendar-alt"></i> <?= date('d/m/Y', strtotime($branch['created_at'])) ?>
                                    </div>
                                 </th>
                                <td style="padding: 12px;">
                                    <span class="type-badge" style="background: <?= $typeColor ?>20; color: <?= $typeColor ?>; padding: 4px 10px; border-radius: 20px; font-size: 11px;">
                                        <?= $typeName ?>
                                    </span>
                                 </th>
                                <td style="padding: 12px;">
                                    <?php if (!empty($displayManager)): ?>
                                        <strong><?= htmlspecialchars($displayManager) ?></strong>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                 </th>
                                <td style="padding: 12px;">
                                    <div><i class="fas fa-phone"></i> <?= htmlspecialchars($branch['phone'] ?? '-') ?></div>
                                    <div><i class="fas fa-envelope"></i> <?= htmlspecialchars($branch['email'] ?? '-') ?></div>
                                 </th>
                                <td style="padding: 12px;">
                                    <div><?= number_format($branch['max_capacity_cbm'] ?? 0, 2) ?> CBM</div>
                                    <div class="progress-bar-container" style="width: 80px; height: 4px; background: #e0e0e0; border-radius: 2px; margin-top: 5px;">
                                        <div class="progress-bar" style="width: <?= $capacityPercent ?>%; height: 100%; background: <?= $capacityPercent > 90 ? '#ef4444' : ($capacityPercent > 70 ? '#f59e0b' : '#10b981') ?>; border-radius: 2px;"></div>
                                    </div>
                                 </th>
                                <td style="padding: 12px;">
                                    <span class="status-badge" style="background: <?= $statusColor ?>20; color: <?= $statusColor ?>; padding: 4px 10px; border-radius: 20px; font-size: 11px;">
                                        <?= $statusName ?>
                                    </span>
                                 </th>
                                <td style="padding: 12px;">
                                    <button class="action-btn btn-staff" data-id="<?= $branch['id'] ?>" data-name="<?= htmlspecialchars($branch['branch_name']) ?>" title="Manage Staff">
                                        <i class="fas fa-users"></i>
                                    </button>
                                    <span style="font-size: 11px; margin-left: 5px;"><?= $branch['staff_count'] ?? 0 ?></span>
                                 </th>
                                <td style="padding: 12px;">
                                    <div class="action-buttons">
                                        <button class="action-btn btn-view view-branch" data-id="<?= $branch['id'] ?>" title="View Details"><i class="fas fa-eye"></i></button>
                                        <button class="action-btn btn-edit edit-branch" data-id="<?= $branch['id'] ?>" title="Edit"><i class="fas fa-edit"></i></button>
                                        <button class="action-btn btn-status update-status" data-id="<?= $branch['id'] ?>" data-status="<?= $branch['status'] ?>" title="Update Status"><i class="fas fa-exchange-alt"></i></button>
                                        <button class="action-btn btn-delete delete-branch" data-id="<?= $branch['id'] ?>" data-name="<?= htmlspecialchars($branch['branch_name']) ?>" title="Delete"><i class="fas fa-trash"></i></button>
                                    </div>
                                 </th>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <td>
                            <td colspan="9" style="text-align: center; padding: 50px;">
                                <div class="empty-state">
                                    <i class="fas fa-building" style="font-size: 48px; opacity: 0.5;"></i>
                                    <p>No branches found</p>
                                    <button class="btn-primary-custom" id="addBranchBtnEmpty">
                                        <i class="fas fa-plus-circle"></i> Add New Branch
                                    </button>
                                </div>
                             </th>
                        </tr>
                    <?php endif; ?>
                </tbody>
             </table>
        </div>
        <?php
        $table_html = ob_get_clean();
        
        ob_start();
        if ($total_pages > 1): ?>
            <div class="pagination" style="display: flex; justify-content: center; gap: 8px; margin-top: 25px;">
                <?php if ($page > 1): ?>
                    <a data-page="<?= $page-1 ?>" class="pagination-link"><i class="fas fa-chevron-left"></i> Previous</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active-page"><?= $i ?></span>
                    <?php else: ?>
                        <a data-page="<?= $i ?>" class="pagination-link"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a data-page="<?= $page+1 ?>" class="pagination-link">Next <i class="fas fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        <?php endif;
        $pagination_html = ob_get_clean();
        
        echo json_encode([
            'table_html' => $table_html,
            'pagination_html' => $pagination_html
        ]);
        exit;
    }
    
    // STAFF MANAGEMENT ACTIONS
    
    elseif ($action === 'get_branch_staff') {
        $branch_id = (int)($_POST['branch_id'] ?? 0);
        
        $staff = getBranchStaff($pdo, $branch_id, $session_tenant_id);
        
        // Get branch info
        $stmt = $pdo->prepare("SELECT branch_name, tenant_id FROM branches WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$branch_id, $session_tenant_id]);
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get available users for this tenant
        $available_users = getAvailableUsers($pdo, $session_tenant_id, $branch_id);
        
        echo json_encode([
            'success' => true,
            'branch' => $branch,
            'staff' => $staff,
            'available_users' => $available_users,
            'roles' => []
        ]);
        exit;
    }
    
    elseif ($action === 'assign_user_to_branch') {
        $branch_id = (int)($_POST['branch_id'] ?? 0);
        $user_id = (int)($_POST['user_id'] ?? 0);
        $is_primary = isset($_POST['is_primary']) && $_POST['is_primary'] == '1' ? 1 : 0;
        $can_manage_branch = isset($_POST['can_manage_branch']) && $_POST['can_manage_branch'] == '1' ? 1 : 0;
        $permissions = $_POST['permissions'] ?? null;
        
        try {
            // Verify branch belongs to this tenant
            $checkBranch = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND tenant_id = ?");
            $checkBranch->execute([$branch_id, $session_tenant_id]);
            if (!$checkBranch->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Branch not found']);
                exit;
            }
            
            // Verify user belongs to this tenant, capture role_type for the
            // is_primary (= branch manager) role gate below.
            $checkUser = $pdo->prepare("SELECT id, role_type FROM users WHERE id = ? AND tenant_id = ?");
            $checkUser->execute([$user_id, $session_tenant_id]);
            $userRow = $checkUser->fetch(PDO::FETCH_ASSOC);
            if (!$userRow) {
                echo json_encode(['success' => false, 'message' => 'User not found']);
                exit;
            }

            // Integrity gate: on this screen `is_primary = 1` means "this user
            // is the branch's Branch Manager". Only users whose authoritative
            // role_type is already `branch_manager` may hold that flag; we do
            // NOT silently promote a Reception Clerk / Warehouse Supervisor
            // / etc. Change the user's role first via User Management, then
            // assign as primary.
            if ($is_primary && ($userRow['role_type'] ?? '') !== 'branch_manager') {
                echo json_encode(['success' => false, 'message' => 'Only a user whose role is Branch Manager can be assigned as the branch\'s primary manager. Change the user\'s role first, then assign.']);
                exit;
            }

            // Check if assignment already exists
            $check = $pdo->prepare("SELECT id FROM user_branch_assignments WHERE user_id = ? AND branch_id = ?");
            $check->execute([$user_id, $branch_id]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'User already assigned to this branch']);
                exit;
            }

            // If setting as primary, remove primary from other users in this branch
            if ($is_primary) {
                $stmt = $pdo->prepare("UPDATE user_branch_assignments SET is_primary = 0 WHERE branch_id = ?");
                $stmt->execute([$branch_id]);
            }
            
            $sql = "INSERT INTO user_branch_assignments (user_id, branch_id, is_primary, can_manage_branch, permissions, assigned_by, assigned_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id, $branch_id, $is_primary, $can_manage_branch, $permissions, $current_user_id]);
            
            // Log activity
            $user_info = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
            $user_info->execute([$user_id]);
            $user = $user_info->fetch(PDO::FETCH_ASSOC);
            
            logBranchActivity($pdo, $branch_id, $current_user_id, 'assign_user', "Assigned user {$user['full_name']} to branch");
            
            echo json_encode(['success' => true, 'message' => "User assigned to branch successfully"]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'remove_user_from_branch') {
        $assignment_id = (int)($_POST['assignment_id'] ?? 0);
        
        try {
            // Get assignment details first for logging
            $stmt = $pdo->prepare("
                SELECT uba.*, u.full_name, b.branch_name 
                FROM user_branch_assignments uba
                JOIN users u ON uba.user_id = u.id
                JOIN branches b ON uba.branch_id = b.id
                WHERE uba.id = ? AND b.tenant_id = ?
            ");
            $stmt->execute([$assignment_id, $session_tenant_id]);
            $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$assignment) {
                echo json_encode(['success' => false, 'message' => 'Assignment not found']);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM user_branch_assignments WHERE id = ?");
            $stmt->execute([$assignment_id]);
            
            logBranchActivity($pdo, $assignment['branch_id'], $current_user_id, 'remove_user', "Removed user {$assignment['full_name']} from branch");
            
            echo json_encode(['success' => true, 'message' => "User removed from branch successfully"]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'update_user_branch_role') {
        $assignment_id = (int)($_POST['assignment_id'] ?? 0);
        $is_primary = isset($_POST['is_primary']) && $_POST['is_primary'] == '1' ? 1 : 0;
        $can_manage_branch = isset($_POST['can_manage_branch']) && $_POST['can_manage_branch'] == '1' ? 1 : 0;
        $permissions = $_POST['permissions'] ?? null;
        
        try {
            // Get branch_id + assigned user's role_type for the role gate below.
            $stmt = $pdo->prepare("SELECT uba.branch_id, b.tenant_id, u.role_type
                                    FROM user_branch_assignments uba
                                    JOIN branches b ON uba.branch_id = b.id
                                    JOIN users u ON u.id = uba.user_id
                                    WHERE uba.id = ? AND b.tenant_id = ?");
            $stmt->execute([$assignment_id, $session_tenant_id]);
            $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$assignment) {
                echo json_encode(['success' => false, 'message' => 'Assignment not found']);
                exit;
            }

            // Same integrity gate as assign_user_to_branch: only a user whose
            // authoritative role_type is `branch_manager` may hold is_primary.
            if ($is_primary && ($assignment['role_type'] ?? '') !== 'branch_manager') {
                echo json_encode(['success' => false, 'message' => 'Only a user whose role is Branch Manager can be assigned as the branch\'s primary manager. Change the user\'s role first, then assign.']);
                exit;
            }

            // If setting as primary, remove primary from other users in this branch
            if ($is_primary) {
                $stmt = $pdo->prepare("UPDATE user_branch_assignments SET is_primary = 0 WHERE branch_id = ? AND id != ?");
                $stmt->execute([$assignment['branch_id'], $assignment_id]);
            }
            
            $sql = "UPDATE user_branch_assignments SET is_primary = ?, can_manage_branch = ?, permissions = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$is_primary, $can_manage_branch, $permissions, $assignment_id]);
            
            logBranchActivity($pdo, $assignment['branch_id'], $current_user_id, 'update_user_role', "Updated user role/permissions in branch");
            
            echo json_encode(['success' => true, 'message' => "User role updated successfully"]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // BRANCH CRUD ACTIONS
    
    elseif ($action === 'create_user_account') {
        $branch_id = (int)($_POST['branch_id'] ?? 0);
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$branch_id, $session_tenant_id]);
            $branch = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$branch) {
                echo json_encode(['success' => false, 'message' => 'Branch not found']);
                exit;
            }
            
            if (empty($branch['manager_name'])) {
                echo json_encode(['success' => false, 'message' => 'Please set a manager name for this branch first']);
                exit;
            }
            
            $result = createBranchManagerUser(
                $pdo,
                $branch['tenant_id'],
                $branch_id,
                $branch['manager_name'],
                $branch['manager_phone'],
                $branch['branch_name'],
                $_SESSION['user_id']
            );
            
            echo json_encode($result);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'get_branch') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("
            SELECT b.*
            FROM branches b
            WHERE b.id = ? AND b.tenant_id = ?
        ");
        $stmt->execute([$id, $session_tenant_id]);
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($branch);
        exit;
    }
    
    elseif ($action === 'save_branch') {
        $id = $_POST['branch_id'] ?? '';
        $tenant_id = $session_tenant_id;
        $branch_code = strtoupper(trim($_POST['branch_code'] ?? ''));
        $branch_name = trim($_POST['branch_name'] ?? '');
        $branch_type = $_POST['branch_type'] ?? 'office';
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $manager_name = trim($_POST['manager_name'] ?? '');
        $manager_phone = trim($_POST['manager_phone'] ?? '');
        $opening_time = !empty($_POST['opening_time']) ? $_POST['opening_time'] : null;
        $closing_time = !empty($_POST['closing_time']) ? $_POST['closing_time'] : null;
        $location_lat = !empty($_POST['location_lat']) ? (float)$_POST['location_lat'] : null;
        $location_lng = !empty($_POST['location_lng']) ? (float)$_POST['location_lng'] : null;
        $max_capacity_cbm = (float)($_POST['max_capacity_cbm'] ?? 0);
        $current_used_cbm = (float)($_POST['current_used_cbm'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        $create_user = isset($_POST['create_user']) && $_POST['create_user'] == '1';
        
        if (empty($branch_code) || empty($branch_name)) {
            echo json_encode(['success' => false, 'message' => 'Branch code and name are required']);
            exit;
        }
        
        try {
            if (empty($id)) {
                $check = $pdo->prepare("SELECT id FROM branches WHERE branch_code = ? AND tenant_id = ?");
                $check->execute([$branch_code, $tenant_id]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'message' => "Branch code '$branch_code' already exists for your company"]);
                    exit;
                }
                
                $sql = "INSERT INTO branches (tenant_id, branch_code, branch_name, branch_type, address, phone, email,
                        manager_name, manager_phone, opening_time, closing_time, location_lat, location_lng,
                        max_capacity_cbm, current_used_cbm, status, created_by, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$tenant_id, $branch_code, $branch_name, $branch_type, $address, $phone, $email,
                               $manager_name, $manager_phone, $opening_time, $closing_time, $location_lat, $location_lng,
                               $max_capacity_cbm, $current_used_cbm, $status, $_SESSION['user_id']]);
                
                $new_branch_id = $pdo->lastInsertId();
                $message = "Branch '$branch_name' has been saved!";
                $user_result = null;
                
                if ($create_user && !empty($manager_name)) {
                    $user_result = createBranchManagerUser(
                        $pdo,
                        $tenant_id,
                        $new_branch_id,
                        $manager_name,
                        $manager_phone,
                        $branch_name,
                        $_SESSION['user_id']
                    );
                    if ($user_result['success']) {
                        $message .= " " . $user_result['message'];
                    }
                }
                
                logBranchActivity($pdo, $new_branch_id, $_SESSION['user_id'], 'create_branch', "Created branch: $branch_name");
                
                echo json_encode(['success' => true, 'message' => $message, 'user_result' => $user_result]);
            } else {
                // Verify branch belongs to this tenant
                $check = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND tenant_id = ?");
                $check->execute([$id, $tenant_id]);
                if (!$check->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Branch not found or you do not have permission']);
                    exit;
                }
                
                $checkCode = $pdo->prepare("SELECT id FROM branches WHERE branch_code = ? AND tenant_id = ? AND id != ?");
                $checkCode->execute([$branch_code, $tenant_id, $id]);
                if ($checkCode->fetch()) {
                    echo json_encode(['success' => false, 'message' => "Branch code '$branch_code' already exists for your company"]);
                    exit;
                }
                
                $sql = "UPDATE branches 
                        SET branch_code=?, branch_name=?, branch_type=?, address=?, phone=?, email=?,
                            manager_name=?, manager_phone=?, opening_time=?, closing_time=?, location_lat=?, location_lng=?,
                            max_capacity_cbm=?, current_used_cbm=?, status=?
                        WHERE id=? AND tenant_id=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$branch_code, $branch_name, $branch_type, $address, $phone, $email,
                               $manager_name, $manager_phone, $opening_time, $closing_time, $location_lat, $location_lng,
                               $max_capacity_cbm, $current_used_cbm, $status, $id, $tenant_id]);
                
                $message = "Branch '$branch_name' has been updated!";
                
                $create_user_now = isset($_POST['create_user_now']) && $_POST['create_user_now'] == '1';
                if ($create_user_now && !empty($manager_name)) {
                    $user_result = createBranchManagerUser(
                        $pdo,
                        $tenant_id,
                        $id,
                        $manager_name,
                        $manager_phone,
                        $branch_name,
                        $_SESSION['user_id']
                    );
                    if ($user_result['success']) {
                        $message .= " " . $user_result['message'];
                    }
                }
                
                logBranchActivity($pdo, $id, $_SESSION['user_id'], 'update_branch', "Updated branch: $branch_name");
                
                echo json_encode(['success' => true, 'message' => $message]);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'delete_branch') {
        $id = $_POST['id'] ?? 0;
        try {
            // Verify branch belongs to this tenant
            $check = $pdo->prepare("SELECT branch_name, tenant_id FROM branches WHERE id = ? AND tenant_id = ?");
            $check->execute([$id, $session_tenant_id]);
            $branch = $check->fetch();
            
            if (!$branch) {
                echo json_encode(['success' => false, 'message' => 'Branch not found']);
                exit;
            }
            
            // Check if branch has users assigned
            $checkUsers = $pdo->prepare("SELECT COUNT(*) as count FROM user_branch_assignments WHERE branch_id = ?");
            $checkUsers->execute([$id]);
            $user_count = $checkUsers->fetch(PDO::FETCH_ASSOC)['count'];
            
            // Check if branch has containers assigned
            $checkContainers = $pdo->prepare("SELECT COUNT(*) as count FROM containers WHERE current_branch_id = ? AND tenant_id = ?");
            $checkContainers->execute([$id, $session_tenant_id]);
            $container_count = $checkContainers->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($user_count > 0 || $container_count > 0) {
                echo json_encode(['success' => false, 'message' => "This branch has $user_count staff and $container_count containers linked. Please remove them first."]);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM branches WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $session_tenant_id]);
            
            echo json_encode(['success' => true, 'message' => "Branch '{$branch['branch_name']}' has been deleted!"]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'update_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        
        $allowed_statuses = ['active', 'inactive', 'temporary_closed', 'permanently_closed'];
        if (!in_array($status, $allowed_statuses)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status']);
            exit;
        }
        
        try {
            // Verify branch belongs to this tenant
            $check = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND tenant_id = ?");
            $check->execute([$id, $session_tenant_id]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Branch not found']);
                exit;
            }
            
            $sql = "UPDATE branches SET status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$status, $id, $session_tenant_id]);
            
            logBranchActivity($pdo, $id, $current_user_id, 'update_status', "Changed branch status to: $status");
            
            echo json_encode(['success' => true, 'message' => 'Branch status updated successfully!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'get_stats') {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN branch_type = 'main' THEN 1 ELSE 0 END) as main_branches,
                SUM(CASE WHEN branch_type = 'warehouse' THEN 1 ELSE 0 END) as warehouses,
                SUM(CASE WHEN branch_type = 'office' THEN 1 ELSE 0 END) as offices,
                SUM(CASE WHEN branch_type = 'port' THEN 1 ELSE 0 END) as ports,
                SUM(max_capacity_cbm) as total_capacity,
                (SELECT COUNT(DISTINCT user_id) FROM user_branch_assignments uba JOIN branches b ON uba.branch_id = b.id WHERE b.tenant_id = ?) as total_staff
            FROM branches
            WHERE tenant_id = ?
        ");
        $stmt->execute([$session_tenant_id, $session_tenant_id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($stats);
        exit;
    }
    
    elseif ($action === 'get_branch_activity_logs') {
        $branch_id = (int)($_POST['branch_id'] ?? 0);
        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 50;
        
        try {
            $sql = "SELECT l.*, u.full_name as user_name
                    FROM branch_activity_logs l
                    LEFT JOIN users u ON l.user_id = u.id
                    WHERE l.branch_id = ? AND (u.tenant_id = ? OR u.tenant_id IS NULL)
                    ORDER BY l.created_at DESC
                    LIMIT " . intval($limit);
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$branch_id, $session_tenant_id]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!$logs) {
                $logs = [];
            }
            
            echo json_encode(['success' => true, 'logs' => $logs]);
        } catch (PDOException $e) {
            error_log("Activity logs error: " . $e->getMessage());
            echo json_encode(['success' => true, 'logs' => [], 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    exit;
}

// Include header
require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Management - <?= htmlspecialchars($tenant_name) ?> | Cargo Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        /* CSS Styles (same as original) */
        :root {
            --primary: #2D1859;
            --primary-light: #4B2C85;
            --secondary: #F5C410;
            --secondary-dark: #D4A70C;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: var(--gray-50); font-family: 'Inter', sans-serif; }
        
        .page-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border-radius: 20px;
            padding: 20px 30px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 10px 25px -5px rgba(82,0,102,0.15);
        }
        .page-header h1 { color: white; font-size: 24px; margin: 0; font-weight: 600; }
        .page-header h1 i { margin-right: 10px; color: var(--secondary); }
        .page-header .company-badge {
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
        }
        
        .btn-primary-custom {
            background: var(--secondary);
            color: var(--primary);
            border: none;
            padding: 10px 24px;
            border-radius: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            cursor: pointer;
            font-size: 14px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .btn-primary-custom:hover {
            background: var(--secondary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(244,221,8,0.3);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid var(--gray-200);
            transition: all 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); }
        .stat-info h4 { font-size: 11px; color: var(--gray-500); margin: 0 0 5px 0; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-info .stat-number { font-size: 22px; font-weight: 700; color: var(--primary); }
        .stat-icon { width: 45px; height: 45px; background: rgba(82,0,102,0.08); border-radius: 12px; display: flex; align-items: center; justify-content: center; }
        .stat-icon i { font-size: 20px; color: var(--primary); }
        
        @media (max-width: 768px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        
        .filters-card {
            background: white;
            border-radius: 16px;
            padding: 20px 25px;
            margin-bottom: 25px;
            border: 1px solid var(--gray-200);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .filter-form { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 160px; }
        .filter-group label { display: block; font-size: 12px; font-weight: 600; color: var(--gray-600); margin-bottom: 6px; }
        .filter-group input, .filter-group select {
            width: 100%; padding: 10px 14px; border: 1px solid var(--gray-300); border-radius: 10px;
            font-size: 13px; transition: all 0.2s;
        }
        .btn-filter, .btn-reset { padding: 10px 22px; border-radius: 10px; font-weight: 500; font-size: 13px; cursor: pointer; border: none; }
        .btn-filter { background: var(--primary); color: white; }
        .btn-filter:hover { background: var(--primary-light); }
        .btn-reset { background: var(--gray-100); color: var(--gray-700); border: 1px solid var(--gray-200); }
        .btn-reset:hover { background: var(--gray-200); }
        
        .branches-table-container {
            background: white;
            border-radius: 16px;
            border: 1px solid var(--gray-200);
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .branches-table { width: 100%; border-collapse: collapse; }
        .branches-table th { padding: 14px 16px; background: var(--gray-50); color: var(--gray-700); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--gray-200); }
        .branches-table td { padding: 14px 16px; border-bottom: 1px solid var(--gray-100); font-size: 13px; vertical-align: middle; }
        .branches-table tr:hover { background: var(--gray-50); }
        
        .action-buttons { display: flex; gap: 6px; flex-wrap: wrap; }
        .action-btn { width: 32px; height: 32px; border-radius: 8px; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; }
        .btn-view { background: #eef2ff; color: #4f46e5; }
        .btn-view:hover { background: #4f46e5; color: white; }
        .btn-edit { background: #fff7ed; color: #ea580c; }
        .btn-edit:hover { background: #ea580c; color: white; }
        .btn-status { background: #fef3c7; color: #d97706; }
        .btn-status:hover { background: #d97706; color: white; }
        .btn-staff { background: #d1fae5; color: #10b981; }
        .btn-staff:hover { background: #10b981; color: white; }
        .btn-delete { background: #fef2f2; color: #dc2626; }
        .btn-delete:hover { background: #dc2626; color: white; }
        
        .status-badge, .type-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .progress-bar-container { width: 80px; height: 4px; background: #e0e0e0; border-radius: 2px; overflow: hidden; }
        .progress-bar { height: 100%; border-radius: 2px; transition: width 0.3s ease; }
        
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 25px; }
        .pagination-link, .active-page {
            padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 500;
            background: white; border: 1px solid var(--gray-200); cursor: pointer; transition: all 0.2s;
        }
        .pagination-link:hover { background: var(--gray-100); border-color: var(--gray-300); }
        .active-page { background: var(--primary); color: white; border-color: var(--primary); cursor: default; }
        
        .modal-header { background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: white; border-bottom: none; border-radius: 16px 16px 0 0; }
        .modal-header .close { color: white; opacity: 0.8; }
        .modal-title i { margin-right: 8px; color: var(--secondary); }
        .form-group { margin-bottom: 1rem; }
        .form-group label { font-size: 12px; font-weight: 600; color: var(--gray-700); margin-bottom: 5px; display: block; }
        .form-control { border-radius: 10px; border: 1px solid var(--gray-300); padding: 10px 14px; font-size: 13px; }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(82,0,102,0.1); }
        
        .checkbox-group { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
        .checkbox-group input { width: 18px; height: 18px; cursor: pointer; }
        .checkbox-group label { margin: 0; cursor: pointer; font-size: 13px; }
        
        .section-divider { margin: 20px 0 15px; padding-top: 10px; border-top: 2px solid var(--gray-200); }
        .section-title { font-size: 15px; font-weight: 600; color: var(--primary); margin-bottom: 15px; }
        .section-title i { margin-right: 8px; color: var(--secondary); }
        
        .staff-table { width: 100%; border-collapse: collapse; }
        .staff-table th { padding: 10px 12px; background: var(--gray-50); font-size: 12px; font-weight: 600; border-bottom: 1px solid var(--gray-200); }
        .staff-table td { padding: 10px 12px; border-bottom: 1px solid var(--gray-100); font-size: 13px; }
        .staff-table tr:hover { background: var(--gray-50); }
        
        .primary-badge { background: #fef3c7; color: #d97706; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 600; }
        .manager-badge { background: #d1fae5; color: #10b981; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 600; }
        .role-badge { padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 600; }
        .role-staff { background: #d1fae5; color: #065f46; }
        
        .alert-custom { position: fixed; top: 85px; right: 20px; z-index: 9999; min-width: 320px; border-radius: 12px; border-left: 4px solid; animation: slideIn 0.3s ease; }
        .alert-success { background: #ecfdf5; color: #065f46; border-left-color: #10b981; }
        .alert-error { background: #fef2f2; color: #991b1b; border-left-color: #ef4444; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
        .loading-spinner { text-align: center; padding: 50px; }
        .loading-spinner i { font-size: 40px; color: var(--primary); animation: spin 1s linear infinite; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        
        .empty-state { text-align: center; padding: 60px; color: var(--gray-500); }
        .empty-state i { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }
        .text-muted { color: var(--gray-500); }
        
        .credentials-display {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 12px;
            padding: 12px;
            margin-top: 15px;
        }
        .credentials-display p { margin: 5px 0; font-size: 12px; }
        .copy-btn { background: #22c55e; color: white; border: none; padding: 2px 8px; border-radius: 6px; font-size: 11px; cursor: pointer; margin-left: 5px; }
        
        .nav-tabs .nav-link { color: var(--gray-700); border: none; padding: 12px 20px; font-weight: 500; }
        .nav-tabs .nav-link.active { color: var(--primary); border-bottom: 2px solid var(--primary); background: transparent; }
        .tab-content { padding: 20px 0; }
        
        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: center; }
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-building"></i> Branch Management</h1>
        <div class="d-flex gap-3 align-items-center">
            <span class="company-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($tenant_name) ?></span>
            <button type="button" class="btn-primary-custom" id="addBranchBtn">
                <i class="fas fa-plus-circle"></i> Add Branch
            </button>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-info"><h4>Total Branches</h4><div class="stat-number" id="stat-total">0</div></div><div class="stat-icon"><i class="fas fa-building"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Active</h4><div class="stat-number" id="stat-active">0</div></div><div class="stat-icon"><i class="fas fa-check-circle"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Head Offices</h4><div class="stat-number" id="stat-main">0</div></div><div class="stat-icon"><i class="fas fa-star"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Warehouses</h4><div class="stat-number" id="stat-warehouse">0</div></div><div class="stat-icon"><i class="fas fa-warehouse"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Total Staff</h4><div class="stat-number" id="stat-staff">0</div></div><div class="stat-icon"><i class="fas fa-users"></i></div></div>
    </div>

    <!-- Filters -->
    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group"><label><i class="fas fa-search"></i> Search</label><input type="text" id="searchInput" placeholder="Search by code, name, manager..."></div>
            <div class="filter-group"><label><i class="fas fa-tag"></i> Branch Type</label><select id="branchTypeFilter"><option value="">All</option><option value="main">Head Office</option><option value="warehouse">Warehouse</option><option value="office">Office</option><option value="store">Store</option><option value="customs">Customs</option><option value="port">Port</option></select></div>
            <div class="filter-group"><label><i class="fas fa-chart-line"></i> Status</label><select id="statusFilter"><option value="">All</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="temporary_closed">Temporarily Closed</option><option value="permanently_closed">Permanently Closed</option></select></div>
            <div class="filter-group"><button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Filter</button><button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Reset</button></div>
        </div>
    </div>

    <div id="branches-table-container"><div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i><p>Loading branches...</p></div></div>
    <div id="pagination-container"></div>
</div>

<!-- Create/Edit Branch Modal -->
<div class="modal fade" id="branchModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header">
                <h5 class="modal-title" id="branchModalLabel"><i class="fas fa-plus-circle"></i> Add Branch</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="branchForm">
                <div class="modal-body">
                    <input type="hidden" name="branch_id" id="branch_id">
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Branch Code <span class="text-danger">*</span></label>
                                <input type="text" name="branch_code" id="modalBranchCode" class="form-control" required placeholder="BR-001">
                                <small class="text-muted">Unique code for this branch</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Branch Name <span class="text-danger">*</span></label>
                                <input type="text" name="branch_name" id="modalBranchName" class="form-control" required placeholder="Mogadishu Head Office">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Branch Type</label>
                                <select name="branch_type" id="modalBranchType" class="form-control">
                                    <option value="main">Head Office 🏢</option>
                                    <option value="warehouse">Warehouse 📦</option>
                                    <option value="office">Office 📋</option>
                                    <option value="store">Store 🏪</option>
                                    <option value="customs">Customs 🛃</option>
                                    <option value="port">Port ⚓</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Phone</label>
                                <input type="text" name="phone" id="modalPhone" class="form-control" placeholder="+252 XX XXX XXXX">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="email" id="modalEmail" class="form-control" placeholder="branch@curdun.com">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Address</label>
                                <textarea name="address" id="modalAddress" class="form-control" rows="2" placeholder="Full address..."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="section-divider"></div>
                    <div class="section-title"><i class="fas fa-user-circle"></i> Branch Manager</div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Manager Name</label>
                                <input type="text" name="manager_name" id="modalManagerName" class="form-control" placeholder="Ahmed Hassan">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Manager Phone</label>
                                <input type="text" name="manager_phone" id="modalManagerPhone" class="form-control" placeholder="+252 XX XXX XXXX">
                            </div>
                        </div>
                    </div>
                    
                    <div class="checkbox-group" id="createUserGroup" style="display: none;">
                        <input type="checkbox" name="create_user" id="createUserCheckbox" value="1">
                        <label for="createUserCheckbox">Auto-create User Account (Password: 123)</label>
                    </div>
                    
                    <div class="section-divider"></div>
                    <div class="section-title"><i class="fas fa-clock"></i> Working Hours</div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Opening Time</label>
                                <input type="time" name="opening_time" id="modalOpeningTime" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Closing Time</label>
                                <input type="time" name="closing_time" id="modalClosingTime" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="section-divider"></div>
                    <div class="section-title"><i class="fas fa-cubes"></i> Storage Capacity</div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Max Capacity (CBM)</label>
                                <input type="number" step="0.01" name="max_capacity_cbm" id="modalMaxCapacity" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Current Used (CBM)</label>
                                <input type="number" step="0.01" name="current_used_cbm" id="modalCurrentUsed" class="form-control" value="0">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" id="modalStatus" class="form-control">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="temporary_closed">Temporarily Closed</option>
                                    <option value="permanently_closed">Permanently Closed</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Save Branch</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Staff Management Modal -->
<div class="modal fade" id="staffModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-users"></i> Staff Management: <span id="staffBranchName"></span></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" id="staffTabs" role="tablist">
                    <li class="nav-item"><a class="nav-link active" id="current-staff-tab" data-toggle="tab" href="#currentStaff"><i class="fas fa-user-check"></i> Current Staff</a></li>
                    <li class="nav-item"><a class="nav-link" id="assign-staff-tab" data-toggle="tab" href="#assignStaff"><i class="fas fa-user-plus"></i> Add Staff</a></li>
                    <li class="nav-item"><a class="nav-link" id="activity-logs-tab" data-toggle="tab" href="#activityLogs"><i class="fas fa-history"></i> Activity Logs</a></li>
                </ul>
                <div class="tab-content" id="staffTabsContent">
                    <div class="tab-pane fade show active" id="currentStaff">
                        <div id="currentStaffList" class="mt-3"><div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading staff...</div></div>
                    </div>
                    <div class="tab-pane fade" id="assignStaff">
                        <div id="availableUsersList" class="mt-3"><div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading available users...</div></div>
                    </div>
                    <div class="tab-pane fade" id="activityLogs">
                        <div id="activityLogsList" class="mt-3"><div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading activity logs...</div></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- View Branch Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-eye"></i> Branch Details</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Status Update Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exchange-alt"></i> Update Status</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="statusForm">
                <div class="modal-body">
                    <input type="hidden" name="branch_id" id="statusBranchId">
                    <div class="form-group">
                        <label>New Status</label>
                        <select name="status" id="statusNewStatus" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="temporary_closed">Temporarily Closed</option>
                            <option value="permanently_closed">Permanently Closed</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-trash"></i> Delete Branch</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete?</p>
                <p><strong id="deleteBranchName"></strong></p>
                <div class="alert alert-warning mt-2">
                    <i class="fas fa-exclamation-triangle"></i> If this branch has staff or containers linked, you cannot delete it.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- User Account Created Modal -->
<div class="modal fade" id="userAccountModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-user-check"></i> User Account Created</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="userAccountModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    let currentPage = 1;
    let deleteId = null;
    let isEditMode = false;
    let currentBranchId = null;
    
    function toggleCreateUserCheckbox() {
        let managerName = $('#modalManagerName').val().trim();
        if (managerName !== '' && !isEditMode) {
            $('#createUserGroup').show();
        } else {
            $('#createUserGroup').hide();
            $('#createUserCheckbox').prop('checked', false);
        }
    }
    
    $('#modalManagerName').on('input', toggleCreateUserCheckbox);
    
    function loadBranches() {
        let data = {
            ajax_action: 'get_branches',
            page: currentPage,
            search: $('#searchInput').val(),
            branch_type: $('#branchTypeFilter').val(),
            status: $('#statusFilter').val()
        };
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(res) {
                $('#branches-table-container').html(res.table_html);
                $('#pagination-container').html(res.pagination_html);
                attachTableEvents();
            },
            error: function() { 
                $('#branches-table-container').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading data</p></div>');
            }
        });
    }
    
    function loadStats() {
        let data = { ajax_action: 'get_stats' };
        $.ajax({ 
            url: window.location.href, 
            type: 'POST', 
            data: data, 
            dataType: 'json',
            success: function(s) {
                $('#stat-total').text(s.total||0); 
                $('#stat-active').text(s.active||0); 
                $('#stat-main').text(s.main_branches||0); 
                $('#stat-warehouse').text(s.warehouses||0);
                $('#stat-staff').text(s.total_staff||0);
            }
        });
    }
    
    function loadBranchStaff(branchId) {
        $('#currentStaffList').html('<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading staff...</div>');
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_branch_staff', branch_id: branchId },
            dataType: 'json',
            success: function(res) {
                if (res.success && res.staff) {
                    if (res.staff.length === 0) {
                        $('#currentStaffList').html('<div class="empty-state"><i class="fas fa-users-slash"></i><p>No staff assigned to this branch</p></div>');
                    } else {
                        let html = '<table class="staff-table"><thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Permissions</th><th>Actions</th></tr></thead><tbody>';
                        for (let s of res.staff) {
                            html += `<tr>
                                <td><strong>${escapeHtml(s.full_name)}</strong></td>
                                <td>${escapeHtml(s.email || '-')}</td>
                                <td>${escapeHtml(s.phone || '-')}</td>
                                <td><span class="role-badge role-staff">${escapeHtml(s.role_type || s.role || 'Staff')}</span></td>
                                <td>
                                    ${s.is_primary ? '<span class="primary-badge"><i class="fas fa-star"></i> Branch Manager</span> ' : ''}
                                    ${s.can_manage_branch ? '<span class="manager-badge"><i class="fas fa-chalkboard-user"></i> Can Manage</span>' : ''}
                                </td>
                                <td>
                                    <button class="action-btn edit-staff" data-id="${s.id}" data-assignment-id="${s.assignment_id}" data-is-primary="${s.is_primary}" data-can-manage="${s.can_manage_branch}" title="Edit"><i class="fas fa-edit"></i></button>
                                    <button class="action-btn remove-staff" data-assignment-id="${s.assignment_id}" data-name="${escapeHtml(s.full_name)}" title="Remove"><i class="fas fa-user-minus"></i></button>
                                </td>
                            </tr>`;
                        }
                        html += '</tbody></table>';
                        $('#currentStaffList').html(html);
                        
                        $('.edit-staff').off('click').on('click', function() {
                            let assignmentId = $(this).data('assignment-id');
                            let isPrimary = $(this).data('is-primary');
                            let canManage = $(this).data('can-manage');
                            showEditStaffModal(assignmentId, isPrimary, canManage);
                        });
                        
                        $('.remove-staff').off('click').on('click', function() {
                            let assignmentId = $(this).data('assignment-id');
                            let name = $(this).data('name');
                            if (confirm(`Are you sure you want to remove ${name} from this branch?`)) {
                                $.ajax({
                                    url: window.location.href,
                                    type: 'POST',
                                    data: { ajax_action: 'remove_user_from_branch', assignment_id: assignmentId },
                                    dataType: 'json',
                                    success: function(r) {
                                        if (r.success) {
                                            showAlert('success', r.message);
                                            loadBranchStaff(currentBranchId);
                                            loadAvailableUsers(currentBranchId);
                                            loadBranches();
                                            loadStats();
                                        } else {
                                            showAlert('error', r.message);
                                        }
                                    }
                                });
                            }
                        });
                    }
                } else {
                    $('#currentStaffList').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading staff</p></div>');
                }
            },
            error: function() {
                $('#currentStaffList').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading staff</p></div>');
            }
        });
    }
    
    function loadAvailableUsers(branchId) {
        $('#availableUsersList').html('<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading available users...</div>');
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_branch_staff', branch_id: branchId },
            dataType: 'json',
            success: function(res) {
                if (res.success && res.available_users) {
                    if (res.available_users.length === 0) {
                        $('#availableUsersList').html('<div class="empty-state"><i class="fas fa-user-plus"></i><p>No available users to assign</p></div>');
                    } else {
                        let html = '<table class="staff-table"><thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Actions</th></tr></thead><tbody>';
                        for (let u of res.available_users) {
                            html += `<tr>
                                <td><strong>${escapeHtml(u.full_name)}</strong></td>
                                <td>${escapeHtml(u.email || '-')}</td>
                                <td>${escapeHtml(u.phone || '-')}</td>
                                <td><span class="role-badge role-staff">${escapeHtml(u.role_type || u.role || 'Staff')}</span></td>
                                <td><button class="action-btn assign-user" data-user-id="${u.id}" data-name="${escapeHtml(u.full_name)}" title="Assign to Branch"><i class="fas fa-user-plus"></i></button></td>
                            </tr>`;
                        }
                        html += '</tbody></table>';
                        $('#availableUsersList').html(html);
                        
                        $('.assign-user').off('click').on('click', function() {
                            let userId = $(this).data('user-id');
                            let name = $(this).data('name');
                            showAssignUserModal(userId, name);
                        });
                    }
                } else {
                    $('#availableUsersList').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading available users</p></div>');
                }
            },
            error: function() {
                $('#availableUsersList').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading available users</p></div>');
            }
        });
    }
    
    function loadActivityLogs(branchId) {
        $('#activityLogsList').html('<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading activity logs...</div>');
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_branch_activity_logs', branch_id: branchId, limit: 50 },
            dataType: 'json',
            success: function(res) {
                if (res.success && res.logs && res.logs.length > 0) {
                    let html = '<table class="staff-table"><thead><tr><th>Time</th><th>Action</th><th>User</th><th>Description</th></tr></thead><tbody>';
                    for (let log of res.logs) {
                        let logDate = log.created_at ? new Date(log.created_at).toLocaleString() : '-';
                        html += `<tr>
                            <td style="white-space: nowrap;">${logDate}</td>
                            <td><span class="badge" style="background:#3b82f6; color:white; padding:4px 8px; border-radius:12px;">${escapeHtml(log.action)}</span></td>
                            <td>${escapeHtml(log.user_name || '-')}</td>
                            <td>${escapeHtml(log.description || '-')}</td>
                        </tr>`;
                    }
                    html += '</tbody></table>';
                    $('#activityLogsList').html(html);
                } else {
                    $('#activityLogsList').html('<div class="empty-state"><i class="fas fa-history"></i><p>No activity logs found</p></div>');
                }
            },
            error: function() {
                $('#activityLogsList').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading activity logs</p></div>');
            }
        });
    }
    
    function showAssignUserModal(userId, userName) {
        const modalHtml = `
            <div class="modal fade" id="assignUserModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content" style="border-radius: 16px;">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-user-plus"></i> Assign Staff</h5>
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                        </div>
                        <div class="modal-body">
                            <p>Staff: <strong>${escapeHtml(userName)}</strong></p>
                            <div class="checkbox-group">
                                <input type="checkbox" id="assignIsPrimary" value="1">
                                <label for="assignIsPrimary">Make Branch Manager (Primary)</label>
                            </div>
                            <div class="checkbox-group mt-2">
                                <input type="checkbox" id="assignCanManage" value="1">
                                <label for="assignCanManage">Can manage this branch</label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary-custom" id="confirmAssignUser">Assign</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(modalHtml);
        $('#assignUserModal').modal('show');
        
        $('#confirmAssignUser').off('click').on('click', function() {
            let isPrimary = $('#assignIsPrimary').is(':checked') ? 1 : 0;
            let canManage = $('#assignCanManage').is(':checked') ? 1 : 0;
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    ajax_action: 'assign_user_to_branch',
                    branch_id: currentBranchId,
                    user_id: userId,
                    is_primary: isPrimary,
                    can_manage_branch: canManage
                },
                dataType: 'json',
                success: function(r) {
                    $('#assignUserModal').modal('hide');
                    if (r.success) {
                        showAlert('success', r.message);
                        loadBranchStaff(currentBranchId);
                        loadAvailableUsers(currentBranchId);
                        loadBranches();
                        loadStats();
                    } else {
                        showAlert('error', r.message);
                    }
                    $('#assignUserModal').remove();
                }
            });
        });
        
        $('#assignUserModal').on('hidden.bs.modal', function() { $('#assignUserModal').remove(); });
    }
    
    function showEditStaffModal(assignmentId, currentIsPrimary, currentCanManage) {
        const modalHtml = `
            <div class="modal fade" id="editStaffModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content" style="border-radius: 16px;">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-edit"></i> Update Permissions</h5>
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div class="checkbox-group">
                                <input type="checkbox" id="editIsPrimary" value="1" ${currentIsPrimary ? 'checked' : ''}>
                                <label for="editIsPrimary">Make Branch Manager (Primary)</label>
                            </div>
                            <div class="checkbox-group mt-2">
                                <input type="checkbox" id="editCanManage" value="1" ${currentCanManage ? 'checked' : ''}>
                                <label for="editCanManage">Can manage this branch</label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary-custom" id="confirmEditStaff">Update</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(modalHtml);
        $('#editStaffModal').modal('show');
        
        $('#confirmEditStaff').off('click').on('click', function() {
            let isPrimary = $('#editIsPrimary').is(':checked') ? 1 : 0;
            let canManage = $('#editCanManage').is(':checked') ? 1 : 0;
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    ajax_action: 'update_user_branch_role',
                    assignment_id: assignmentId,
                    is_primary: isPrimary,
                    can_manage_branch: canManage
                },
                dataType: 'json',
                success: function(r) {
                    $('#editStaffModal').modal('hide');
                    if (r.success) {
                        showAlert('success', r.message);
                        loadBranchStaff(currentBranchId);
                        loadBranches();
                        loadStats();
                    } else {
                        showAlert('error', r.message);
                    }
                    $('#editStaffModal').remove();
                }
            });
        });
        
        $('#editStaffModal').on('hidden.bs.modal', function() { $('#editStaffModal').remove(); });
    }
    
    function attachTableEvents() {
        $('.view-branch').off('click').on('click', function() {
            $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'get_branch', id: $(this).data('id') }, dataType: 'json',
                success: function(b) {
                    let statusName = b.status === 'active' ? 'Active' : (b.status === 'inactive' ? 'Inactive' : (b.status === 'temporary_closed' ? 'Temporarily Closed' : 'Permanently Closed'));
                    let typeName = b.branch_type === 'main' ? 'Head Office' : (b.branch_type === 'warehouse' ? 'Warehouse' : (b.branch_type === 'office' ? 'Office' : (b.branch_type === 'store' ? 'Store' : (b.branch_type === 'customs' ? 'Customs' : 'Port'))));
                    $('#viewModalBody').html(`
                        <div class="row">
                            <div class="col-4"><strong>Branch Code:</strong></div><div class="col-8"><strong>${escapeHtml(b.branch_code)}</strong></div>
                            <div class="col-4"><strong>Branch Name:</strong></div><div class="col-8">${escapeHtml(b.branch_name)}</div>
                            <div class="col-4"><strong>Branch Type:</strong></div><div class="col-8">${escapeHtml(typeName)}</div>
                            <div class="col-4"><strong>Phone:</strong></div><div class="col-8">${escapeHtml(b.phone||'-')}</div>
                            <div class="col-4"><strong>Email:</strong></div><div class="col-8">${escapeHtml(b.email||'-')}</div>
                            <div class="col-4"><strong>Manager:</strong></div><div class="col-8">${escapeHtml(b.manager_name||'-')}</div>
                            <div class="col-4"><strong>Working Hours:</strong></div><div class="col-8">${b.opening_time||'N/A'} - ${b.closing_time||'N/A'}</div>
                            <div class="col-4"><strong>Max Capacity:</strong></div><div class="col-8">${parseFloat(b.max_capacity_cbm||0).toFixed(2)} CBM</div>
                            <div class="col-4"><strong>Used Capacity:</strong></div><div class="col-8">${parseFloat(b.current_used_cbm||0).toFixed(2)} CBM</div>
                            <div class="col-4"><strong>Address:</strong></div><div class="col-8">${escapeHtml(b.address||'-')}</div>
                            <div class="col-4"><strong>Status:</strong></div><div class="col-8">${escapeHtml(statusName)}</div>
                        </div>
                    `);
                    $('#viewModal').modal('show');
                }
            });
        });
        
        $('.edit-branch').off('click').on('click', function() {
            isEditMode = true;
            $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'get_branch', id: $(this).data('id') }, dataType: 'json',
                success: function(b) {
                    $('#branchModalLabel').text('Edit Branch');
                    $('#branch_id').val(b.id);
                    $('#modalBranchCode').val(b.branch_code);
                    $('#modalBranchName').val(b.branch_name);
                    $('#modalBranchType').val(b.branch_type);
                    $('#modalPhone').val(b.phone);
                    $('#modalEmail').val(b.email);
                    $('#modalAddress').val(b.address);
                    $('#modalManagerName').val(b.manager_name);
                    $('#modalManagerPhone').val(b.manager_phone);
                    $('#modalOpeningTime').val(b.opening_time);
                    $('#modalClosingTime').val(b.closing_time);
                    $('#modalMaxCapacity').val(b.max_capacity_cbm);
                    $('#modalCurrentUsed').val(b.current_used_cbm);
                    $('#modalStatus').val(b.status);
                    $('#createUserGroup').hide();
                    $('#createUserCheckbox').prop('checked', false);
                    $('#branchModal').modal('show');
                }
            });
        });
        
        $('.update-status').off('click').on('click', function() {
            $('#statusBranchId').val($(this).data('id'));
            $('#statusNewStatus').val($(this).data('status'));
            $('#statusModal').modal('show');
        });
        
        $('.btn-staff').off('click').on('click', function() {
            currentBranchId = $(this).data('id');
            let branchName = $(this).data('name');
            $('#staffBranchName').text(branchName);
            loadBranchStaff(currentBranchId);
            loadAvailableUsers(currentBranchId);
            loadActivityLogs(currentBranchId);
            $('#staffModal').modal('show');
        });
        
        $('.delete-branch').off('click').on('click', function() { 
            deleteId = $(this).data('id'); 
            $('#deleteBranchName').text($(this).data('name')); 
            $('#deleteModal').modal('show'); 
        });
        
        $('#confirmDeleteBtn').off('click').on('click', function() {
            if(deleteId) $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'delete_branch', id: deleteId }, dataType: 'json',
                success: function(r) { if(r.success) { $('#deleteModal').modal('hide'); loadBranches(); loadStats(); showAlert('success', r.message); } else showAlert('error', r.message); }
            });
        });
        
        $('#statusForm').off('submit').on('submit', function(e) {
            e.preventDefault();
            $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'update_status', id: $('#statusBranchId').val(), status: $('#statusNewStatus').val() }, dataType: 'json',
                success: function(r) { if(r.success) { $('#statusModal').modal('hide'); loadBranches(); loadStats(); showAlert('success', r.message); } else showAlert('error', r.message); }
            });
        });
        
        $('.pagination a').off('click').on('click', function(e) { e.preventDefault(); if($(this).data('page')) { currentPage = $(this).data('page'); loadBranches(); } });
    }
    
    function showAlert(type, msg) { 
        const alertClass = type === 'success' ? 'alert-success' : 'alert-error';
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        $('#alert-placeholder').html(`<div class="alert alert-custom ${alertClass} alert-dismissible fade show"><i class="fas ${icon} mr-2"></i> ${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
        setTimeout(()=>$('.alert-custom').fadeOut(5000, function(){$(this).remove();}), 5000); 
    }
    
    function escapeHtml(t) { 
        if(!t) return ''; 
        return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); 
    }
    
    window.copyToClipboard = function(text) {
        navigator.clipboard.writeText(text).then(function() {
            showAlert('success', 'Copied to clipboard!');
        });
    };
    
    $('#branchForm').submit(function(e) {
        e.preventDefault();
        let formData = $(this).serialize();
        let createUser = $('#createUserCheckbox').is(':checked') ? '&create_user=1' : '';
        let createUserNow = isEditMode && $('#modalManagerName').val().trim() !== '' ? '&create_user_now=1' : '';
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData + '&ajax_action=save_branch' + createUser + createUserNow,
            dataType: 'json',
            success: function(r) {
                if (r.success) {
                    $('#branchModal').modal('hide');
                    loadBranches();
                    loadStats();
                    showAlert('success', r.message);
                    $('#branchForm')[0].reset();
                    isEditMode = false;
                    toggleCreateUserCheckbox();
                    
                    if (r.user_result && r.user_result.success) {
                        setTimeout(() => {
                            let html = `
                                <div class="credentials-display">
                                    <p><strong>Email:</strong> ${escapeHtml(r.user_result.email)} <button class="copy-btn" onclick="copyToClipboard('${r.user_result.email}')"><i class="fas fa-copy"></i> Copy</button></p>
                                    <p><strong>Password:</strong> ${r.user_result.password} <button class="copy-btn" onclick="copyToClipboard('${r.user_result.password}')"><i class="fas fa-copy"></i> Copy</button></p>
                                </div>
                            `;
                            $('#userAccountModalBody').html('<p>User account created successfully for branch manager!</p>' + html);
                            $('#userAccountModal').modal('show');
                        }, 500);
                    }
                } else {
                    showAlert('error', r.message);
                }
            }
        });
    });
    
    $('#addBranchBtn, #addBranchBtnEmpty').click(function() { 
        isEditMode = false;
        $('#branchModalLabel').text('Add Branch'); 
        $('#branchForm')[0].reset(); 
        $('#branch_id').val(''); 
        $('#modalStatus').val('active');
        $('#createUserGroup').hide();
        $('#createUserCheckbox').prop('checked', false);
        $('#branchModal').modal('show'); 
    });
    
    $('#applyFilters').click(function() { currentPage = 1; loadBranches(); loadStats(); });
    $('#resetFilters').click(function() { 
        $('#searchInput').val(''); 
        $('#branchTypeFilter').val(''); 
        $('#statusFilter').val(''); 
        currentPage = 1; 
        loadBranches(); 
        loadStats(); 
    });
    
    $('#staffModal').on('shown.bs.modal', function() {
        if ($('#current-staff-tab').hasClass('active')) {
            loadBranchStaff(currentBranchId);
        } else if ($('#assign-staff-tab').hasClass('active')) {
            loadAvailableUsers(currentBranchId);
        } else if ($('#activity-logs-tab').hasClass('active')) {
            loadActivityLogs(currentBranchId);
        }
    });
    
    $('#staffModal').on('click', '#current-staff-tab', function() { loadBranchStaff(currentBranchId); });
    $('#staffModal').on('click', '#assign-staff-tab', function() { loadAvailableUsers(currentBranchId); });
    $('#staffModal').on('click', '#activity-logs-tab', function() { loadActivityLogs(currentBranchId); });
    
    loadBranches(); 
    loadStats();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>