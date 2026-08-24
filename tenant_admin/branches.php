<?php
// tenant_admin/branches.php
// Branch Management for Cargo Management System - Tenant Admin

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
$_col_patches = [
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS branch_code VARCHAR(50) NOT NULL DEFAULT ''",
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS branch_name VARCHAR(255) NOT NULL DEFAULT ''",
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS branch_type ENUM('main','warehouse','office','store','customs','port') DEFAULT 'office'",
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS address TEXT DEFAULT NULL",
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS phone VARCHAR(50) DEFAULT NULL",
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS email VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS manager_name VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS manager_phone VARCHAR(50) DEFAULT NULL",
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS location_lat DECIMAL(10,8) DEFAULT NULL",
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS location_lng DECIMAL(11,8) DEFAULT NULL",
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS opening_time TIME DEFAULT NULL",
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS closing_time TIME DEFAULT NULL",
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS max_capacity_cbm DECIMAL(15,2) DEFAULT 0.00",
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS current_used_cbm DECIMAL(15,2) DEFAULT 0.00",
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS status ENUM('active','inactive','temporary_closed','permanently_closed') DEFAULT 'active'",
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS created_by INT(11) DEFAULT NULL",
    "ALTER TABLE branches MODIFY COLUMN branch_code VARCHAR(50) NOT NULL",
    "ALTER TABLE branches MODIFY COLUMN branch_name VARCHAR(255) NOT NULL",
    // Add user_branch_assignments table if not exists
    "CREATE TABLE IF NOT EXISTS `user_branch_assignments` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `branch_id` int(11) NOT NULL,
        `is_primary` tinyint(1) DEFAULT 0,
        `can_manage_branch` tinyint(1) DEFAULT 0,
        `permissions` longtext DEFAULT NULL,
        `assigned_by` int(11) DEFAULT NULL,
        `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_user_branch` (`user_id`,`branch_id`),
        KEY `idx_user_id` (`user_id`),
        KEY `idx_branch_id` (`branch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];
foreach ($_col_patches as $_cp) {
    try { $pdo->exec($_cp); } catch (PDOException $e) { /* ignore */ }
}
unset($_col_patches, $_cp);

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
        // Generate email from manager name or use branch email
        $email = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $manager_name)) . '@' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $branch_name)) . '.curdun.com';
        if (empty($email) || strpos($email, '@') === false) {
            $email = 'branch_' . $branch_id . '@' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $branch_name)) . '.curdun.com';
        }
        
        // Check if user already exists with this email
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND tenant_id = ?");
        $check->execute([$email, $tenant_id]);
        if ($check->fetch()) {
            return ['success' => false, 'message' => 'User already exists with email: ' . $email];
        }
        
        // Hash password '123'
        $password_hash = password_hash('123', PASSWORD_DEFAULT);
        
        // Insert user
        $sql = "INSERT INTO users (tenant_id, full_name, email, password_hash, role, role_type, phone, created_by, created_at, is_active) 
                VALUES (?, ?, ?, ?, 'staff', 'branch_manager', ?, ?, NOW(), 1)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tenant_id, $manager_name, $email, $password_hash, $manager_phone, $created_by]);
        
        $new_user_id = $pdo->lastInsertId();
        
        // Assign user to branch
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

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
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
                    WHERE uba.branch_id = b.id AND uba.is_primary = 1 LIMIT 1) as manager_email
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
                        <th style="padding: 12px;">Manager & Contact</th>
                        <th style="padding: 12px;">Capacity</th>
                        <th style="padding: 12px;">Status</th>
                        <th style="padding: 12px;">User Account</th>
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
                            $hasManagerAccount = !empty($branch['manager_email']);
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
                                    <?php if (!empty($branch['manager_name'])): ?>
                                        <div><strong><?= htmlspecialchars($branch['manager_name']) ?></strong></div>
                                        <div style="font-size: 12px; color: #6c757d;">
                                            <i class="fas fa-phone"></i> <?= htmlspecialchars($branch['manager_phone'] ?? '-') ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                    <div style="font-size: 11px; margin-top: 5px;">
                                        <i class="fas fa-users"></i> Staff: <?= $branch['staff_count'] ?? 0 ?>
                                    </div>
                                 </th>
                                <td style="padding: 12px;">
                                    <div><?= number_format($branch['max_capacity_cbm'] ?? 0, 2) ?> CBM</div>
                                    <div class="progress-bar-container" style="width: 100px; height: 4px; background: #e0e0e0; border-radius: 2px; margin-top: 5px;">
                                        <div class="progress-bar" style="width: <?= $capacityPercent ?>%; height: 100%; background: <?= $capacityPercent > 90 ? '#ef4444' : ($capacityPercent > 70 ? '#f59e0b' : '#10b981') ?>; border-radius: 2px;"></div>
                                    </div>
                                    <div style="font-size: 10px;">Used: <?= number_format($branch['current_used_cbm'] ?? 0, 2) ?> CBM</div>
                                 </th>
                                <td style="padding: 12px;">
                                    <span class="status-badge" style="background: <?= $statusColor ?>20; color: <?= $statusColor ?>; padding: 4px 10px; border-radius: 20px; font-size: 11px;">
                                        <?= $statusName ?>
                                    </span>
                                 </th>
                                <td style="padding: 12px;">
                                    <?php if ($hasManagerAccount): ?>
                                        <span class="badge" style="background: #10B98120; color: #10B981; padding: 4px 8px; border-radius: 12px; font-size: 11px;">
                                            <i class="fas fa-check-circle"></i> Account Created
                                        </span>
                                    <?php else: ?>
                                        <?php if (!empty($branch['manager_name'])): ?>
                                            <button class="btn-create-user" data-id="<?= $branch['id'] ?>" data-name="<?= htmlspecialchars($branch['branch_name']) ?>" data-manager="<?= htmlspecialchars($branch['manager_name']) ?>" data-phone="<?= htmlspecialchars($branch['manager_phone']) ?>" style="background: #3B82F6; color: white; border: none; padding: 4px 10px; border-radius: 12px; font-size: 11px; cursor: pointer;">
                                                <i class="fas fa-user-plus"></i> Create Account
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">No manager</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
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
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 50px;">
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
    
    elseif ($action === 'create_user_account') {
        $branch_id = (int)($_POST['branch_id'] ?? 0);
        $tenant_id = $session_tenant_id;
        
        try {
            // Get branch details
            $stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$branch_id, $tenant_id]);
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
                // Check if branch code already exists for this tenant
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
                
                // Auto-create user account if manager name is provided and create_user is checked
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
                
                echo json_encode(['success' => true, 'message' => $message, 'user_result' => $user_result]);
            } else {
                // Verify branch belongs to this tenant
                $check = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND tenant_id = ?");
                $check->execute([$id, $tenant_id]);
                if (!$check->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Branch not found or you do not have permission']);
                    exit;
                }
                
                // Check if branch code already exists for this tenant (excluding current branch)
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
                
                // Check if we need to create user account for existing branch
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
                SUM(max_capacity_cbm) as total_capacity
            FROM branches
            WHERE tenant_id = ?
        ");
        $stmt->execute([$session_tenant_id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($stats);
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
        
        /* Page Header */
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
            box-shadow: 0 10px 25px -5px rgba(82, 0, 102, 0.15);
        }
        .page-header h1 { color: white; font-size: 24px; margin: 0; font-weight: 600; }
        .page-header h1 i { margin-right: 10px; color: var(--secondary); }
        .page-header .company-badge {
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
        }
        
        /* Buttons */
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
            box-shadow: 0 4px 12px rgba(244, 221, 8, 0.3);
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
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
        
        /* Filters Card */
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
        .filter-group input:focus, .filter-group select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(82,0,102,0.1); }
        .btn-filter, .btn-reset { padding: 10px 22px; border-radius: 10px; font-weight: 500; font-size: 13px; cursor: pointer; transition: all 0.2s; border: none; }
        .btn-filter { background: var(--primary); color: white; }
        .btn-filter:hover { background: var(--primary-light); }
        .btn-reset { background: var(--gray-100); color: var(--gray-700); border: 1px solid var(--gray-200); }
        .btn-reset:hover { background: var(--gray-200); }
        
        /* Table Container */
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
        
        /* Action Buttons */
        .action-buttons { display: flex; gap: 6px; flex-wrap: wrap; }
        .action-btn { width: 30px; height: 30px; border-radius: 8px; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; }
        .btn-view { background: #eef2ff; color: #4f46e5; }
        .btn-view:hover { background: #4f46e5; color: white; }
        .btn-edit { background: #fff7ed; color: #ea580c; }
        .btn-edit:hover { background: #ea580c; color: white; }
        .btn-status { background: #fef3c7; color: #d97706; }
        .btn-status:hover { background: #d97706; color: white; }
        .btn-delete { background: #fef2f2; color: #dc2626; }
        .btn-delete:hover { background: #dc2626; color: white; }
        .btn-create-user { background: #3B82F6; color: white; border: none; padding: 4px 10px; border-radius: 12px; font-size: 11px; cursor: pointer; transition: all 0.2s; }
        .btn-create-user:hover { background: #2563EB; transform: translateY(-1px); }
        
        /* Progress Bar */
        .progress-bar-container { width: 100px; height: 4px; background: #e0e0e0; border-radius: 2px; overflow: hidden; }
        .progress-bar { height: 100%; border-radius: 2px; transition: width 0.3s ease; }
        
        /* Status Badge */
        .status-badge, .type-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        
        /* Pagination */
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 25px; }
        .pagination-link, .active-page {
            padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 500;
            background: white; border: 1px solid var(--gray-200); cursor: pointer; transition: all 0.2s;
        }
        .pagination-link:hover { background: var(--gray-100); border-color: var(--gray-300); }
        .active-page { background: var(--primary); color: white; border-color: var(--primary); cursor: default; }
        
        /* Modal Styles */
        .modal-header { background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: white; border-bottom: none; border-radius: 16px 16px 0 0; }
        .modal-header .close { color: white; opacity: 0.8; }
        .modal-header .close:hover { opacity: 1; }
        .modal-title i { margin-right: 8px; color: var(--secondary); }
        .form-group { margin-bottom: 1rem; }
        .form-group label { font-size: 12px; font-weight: 600; color: var(--gray-700); margin-bottom: 5px; display: block; }
        .form-control { border-radius: 10px; border: 1px solid var(--gray-300); padding: 10px 14px; font-size: 13px; }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(82,0,102,0.1); }
        
        /* Checkbox */
        .checkbox-group { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
        .checkbox-group input { width: 18px; height: 18px; cursor: pointer; }
        .checkbox-group label { margin: 0; cursor: pointer; font-size: 13px; }
        
        /* Section Divider */
        .section-divider { margin: 20px 0 15px; padding-top: 10px; border-top: 2px solid var(--gray-200); }
        .section-title { font-size: 15px; font-weight: 600; color: var(--primary); margin-bottom: 15px; }
        .section-title i { margin-right: 8px; color: var(--secondary); }
        
        /* Alert */
        .alert-custom { 
            position: fixed; 
            top: 85px; 
            right: 20px; 
            z-index: 9999; 
            min-width: 320px; 
            border-radius: 12px; 
            border-left: 4px solid; 
            animation: slideIn 0.3s ease; 
        }
        .alert-success { background: #ecfdf5; color: #065f46; border-left-color: #10b981; }
        .alert-error { background: #fef2f2; color: #991b1b; border-left-color: #ef4444; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
        .loading-spinner { text-align: center; padding: 50px; }
        .loading-spinner i { font-size: 40px; color: var(--primary); animation: spin 1s linear infinite; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        
        .empty-state { text-align: center; padding: 60px; color: var(--gray-500); }
        .empty-state i { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }
        .text-muted { color: var(--gray-500); }
        
        /* Credentials Display */
        .credentials-display {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 12px;
            padding: 12px;
            margin-top: 15px;
        }
        .credentials-display p { margin: 5px 0; font-size: 12px; }
        .credentials-display strong { color: #166534; }
        .copy-btn { background: #22c55e; color: white; border: none; padding: 2px 8px; border-radius: 6px; font-size: 11px; cursor: pointer; margin-left: 5px; }
        
        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: center; }
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
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
                                <label>Branch Type <span class="text-danger">*</span></label>
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
                                <textarea name="address" id="modalAddress" class="form-control" rows="2" placeholder="Full address of the branch..."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Manager Section -->
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
                    
                    <!-- Auto Create User Account Checkbox -->
                    <div class="checkbox-group" id="createUserGroup" style="display: none;">
                        <input type="checkbox" name="create_user" id="createUserCheckbox" value="1">
                        <label for="createUserCheckbox">Auto-create Manager User Account (Password: 123)</label>
                    </div>
                    
                    <!-- Opening Hours Section -->
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
                    
                    <!-- Capacity Section -->
                    <div class="section-divider"></div>
                    <div class="section-title"><i class="fas fa-cubes"></i> Storage Capacity</div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Maximum Capacity (CBM)</label>
                                <input type="number" step="0.01" name="max_capacity_cbm" id="modalMaxCapacity" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Current Used Capacity (CBM)</label>
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
                <p>Are you sure you want to permanently delete</p>
                <p><strong id="deleteBranchName"></strong>?</p>
                <div class="alert alert-warning mt-2">
                    <i class="fas fa-exclamation-triangle"></i> <strong>Warning!</strong> If this branch has staff or containers linked, you cannot delete it.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Yes, Delete</button>
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
    
    // Show/hide create user checkbox based on manager name input
    function toggleCreateUserCheckbox() {
        let managerName = $('#modalManagerName').val().trim();
        if (managerName !== '' && !isEditMode) {
            $('#createUserGroup').show();
        } else {
            $('#createUserGroup').hide();
            $('#createUserCheckbox').prop('checked', false);
        }
    }
    
    $('#modalManagerName').on('input', function() {
        toggleCreateUserCheckbox();
    });
    
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
            error: function() { $('#branches-table-container').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading data</p></div>'); }
        });
    }
    
    function loadStats() {
        let data = { ajax_action: 'get_stats' };
        $.ajax({ url: window.location.href, type: 'POST', data: data, dataType: 'json',
            success: function(s) {
                $('#stat-total').text(s.total||0); 
                $('#stat-active').text(s.active||0); 
                $('#stat-main').text(s.main_branches||0); 
                $('#stat-warehouse').text(s.warehouses||0);
            }
        });
    }
    
    function attachTableEvents() {
        $('.view-branch').off('click').on('click', function() {
            $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'get_branch', id: $(this).data('id') }, dataType: 'json',
                success: function(b) {
                    $('#viewModalBody').html(`
                        <div class="row">
                            <div class="col-4"><strong>Branch Code:</strong></div><div class="col-8"><strong>${escapeHtml(b.branch_code)}</strong></div>
                            <div class="col-4"><strong>Branch Name:</strong></div><div class="col-8">${escapeHtml(b.branch_name)}</div>
                            <div class="col-4"><strong>Branch Type:</strong></div><div class="col-8">${getBranchTypeName(b.branch_type)}</div>
                            <div class="col-4"><strong>Phone:</strong></div><div class="col-8">${escapeHtml(b.phone||'-')}</div>
                            <div class="col-4"><strong>Email:</strong></div><div class="col-8">${escapeHtml(b.email||'-')}</div>
                            <div class="col-4"><strong>Manager:</strong></div><div class="col-8">${escapeHtml(b.manager_name||'-')} (${escapeHtml(b.manager_phone||'-')})</div>
                            <div class="col-4"><strong>Working Hours:</strong></div><div class="col-8">${b.opening_time||'N/A'} - ${b.closing_time||'N/A'}</div>
                            <div class="col-4"><strong>Capacity:</strong></div><div class="col-8">${parseFloat(b.max_capacity_cbm||0).toFixed(2)} CBM</div>
                            <div class="col-4"><strong>Used Capacity:</strong></div><div class="col-8">${parseFloat(b.current_used_cbm||0).toFixed(2)} CBM</div>
                            <div class="col-4"><strong>Address:</strong></div><div class="col-8">${escapeHtml(b.address||'-')}</div>
                            <div class="col-4"><strong>Status:</strong></div><div class="col-8">${getStatusName(b.status)}</div>
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
        
        $('.btn-create-user').off('click').on('click', function() {
            let branchId = $(this).data('id');
            let branchName = $(this).data('name');
            let managerName = $(this).data('manager');
            let managerPhone = $(this).data('phone');
            
            if (!managerName) {
                showAlert('error', 'Please set a manager name for this branch first');
                return;
            }
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    ajax_action: 'create_user_account',
                    branch_id: branchId,
                },
                dataType: 'json',
                success: function(r) {
                    if (r.success) {
                        let html = `
                            <p><strong>Branch:</strong> ${escapeHtml(branchName)}</p>
                            <p><strong>Manager:</strong> ${escapeHtml(managerName)}</p>
                            <div class="credentials-display">
                                <p><strong>Email:</strong> ${escapeHtml(r.email)} <button class="copy-btn" onclick="copyToClipboard('${r.email}')"><i class="fas fa-copy"></i> Copy</button></p>
                                <p><strong>Password:</strong> ${r.password} <button class="copy-btn" onclick="copyToClipboard('${r.password}')"><i class="fas fa-copy"></i> Copy</button></p>
                                <p><strong>Login URL:</strong> <span class="text-primary">../login.php</span></p>
                            </div>
                            <div class="alert alert-info mt-2">
                                <i class="fas fa-info-circle"></i> User can login with email and password above. Role: Branch Manager
                            </div>
                        `;
                        $('#userAccountModalBody').html(html);
                        $('#userAccountModal').modal('show');
                        loadBranches(); // Refresh to show the account created badge
                    } else {
                        showAlert('error', r.message);
                    }
                }
            });
        });
        
        $('.pagination a').off('click').on('click', function(e) { e.preventDefault(); if($(this).data('page')) { currentPage = $(this).data('page'); loadBranches(); } });
    }
    
    function getBranchTypeName(type) {
        const types = {
            'main': 'Head Office 🏢',
            'warehouse': 'Warehouse 📦',
            'office': 'Office 📋',
            'store': 'Store 🏪',
            'customs': 'Customs 🛃',
            'port': 'Port ⚓'
        };
        return types[type] || type;
    }
    
    function getStatusName(status) {
        const statuses = {
            'active': 'Active',
            'inactive': 'Inactive',
            'temporary_closed': 'Temporarily Closed',
            'permanently_closed': 'Permanently Closed'
        };
        return statuses[status] || status;
    }
    
    function showAlert(type, msg) { 
        const alertClass = type === 'success' ? 'alert-success' : 'alert-error';
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        $('#alert-placeholder').html(`<div class="alert alert-custom ${alertClass} alert-dismissible fade show"><i class="fas ${icon} mr-2"></i> ${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
        setTimeout(()=>$('.alert-custom').fadeOut(5000, function(){$(this).remove();}), 5000); 
    }
    
    function escapeHtml(t) { if(!t) return ''; return t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
    
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
                    
                    // Show user account info if created
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
    
    loadBranches(); 
    loadStats();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>