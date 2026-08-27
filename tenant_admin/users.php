<?php
// tenant_admin/users.php
// User Management for Cargo Management System - Tenant Admin
// WITH PROFILE IMAGE UPLOAD + AUTO PASSWORD 123 + AUTO ADD TO CUSTOMERS TABLE

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

// Role conversion helper: keeps drivers.user_id in sync with users.role_type
// changes without hard-deleting a profile referenced by historical trips.
if (!function_exists('users_admin_apply_role_conversion')) {
    function users_admin_apply_role_conversion(PDO $pdo, int $user_id, int $tenant_id,
        string $old_role, string $new_role, string $full_name, string $phone, int $is_active): void {
        if ($old_role === $new_role) {
            if ($new_role === 'driver') {
                $upd = $pdo->prepare("UPDATE drivers SET full_name = ?, phone = ?, is_active = ?
                                      WHERE user_id = ? AND tenant_id = ?");
                $upd->execute([$full_name, $phone, $is_active, $user_id, $tenant_id]);
            }
            return;
        }
        if ($new_role === 'driver') {
            // Convert to driver: reactivate the existing profile if one exists
            // for this user (preserving historical trip linkage), else create one.
            $find = $pdo->prepare("SELECT id FROM drivers WHERE user_id = ? AND tenant_id = ? LIMIT 1");
            $find->execute([$user_id, $tenant_id]);
            $profile_id = (int)$find->fetchColumn();
            if ($profile_id > 0) {
                $upd = $pdo->prepare("UPDATE drivers SET full_name = ?, phone = ?, is_active = 1 WHERE id = ?");
                $upd->execute([$full_name, $phone, $profile_id]);
            } else {
                $ins = $pdo->prepare("INSERT INTO drivers (tenant_id, user_id, full_name, phone, is_active, created_by, created_at)
                                      VALUES (?, ?, ?, ?, 1, ?, NOW())");
                $ins->execute([$tenant_id, $user_id, $full_name, $phone, $_SESSION['user_id'] ?? null]);
            }
        }
        if ($old_role === 'driver') {
            // Convert away from driver: deactivate the profile but keep the row
            // so trucking_trips.driver_id references stay intact.
            $upd = $pdo->prepare("UPDATE drivers SET is_active = 0 WHERE user_id = ? AND tenant_id = ?");
            $upd->execute([$user_id, $tenant_id]);
        }
    }
}

// Handle AJAX requests FIRST before any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];
    
    if ($action === 'get_users') {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $search = $_POST['search'] ?? '';
        $role_filter = $_POST['role'] ?? '';
        $status_filter = $_POST['status'] ?? '';
        
        $where_conditions = ["u.tenant_id = ?", "u.role_type != 'superadmin'", "u.id != ?"];
        $params = [$session_tenant_id, $_SESSION['user_id']];
        
        if (!empty($search)) {
            $where_conditions[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if (!empty($role_filter)) {
            $where_conditions[] = "u.role_type = ?";
            $params[] = $role_filter;
        }
        
        if ($status_filter !== '') {
            $where_conditions[] = "u.is_active = ?";
            $params[] = $status_filter == 'active' ? 1 : 0;
        }
        
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
        
        // Get total count
        $count_sql = "SELECT COUNT(*) as total FROM users u $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_users = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_users / $limit);
        
        // Get users
        $sql = "
            SELECT u.*, t.name as tenant_name 
            FROM users u
            LEFT JOIN tenants t ON u.tenant_id = t.id
            $where_clause
            ORDER BY u.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate table HTML
        ob_start(); ?>
        <div style="overflow-x: auto; width: 100%;">
            <table class="users-table" style="min-width: 1200px; width: 100%;">
                <thead>
                    <tr>
                        <th style="min-width: 60px;">ID</th>
                        <th style="min-width: 220px;">User Info</th>
                        <th style="min-width: 200px;">Contact</th>
                        <th style="min-width: 120px;">Role</th>
                        <th style="min-width: 120px;">Level</th>
                        <th style="min-width: 100px;">Status</th>
                        <th style="min-width: 100px;">Date</th>
                        <th style="min-width: 120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($users) > 0): ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= $user['id'] ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <img src="<?= !empty($user['profile_image']) ? '../' . htmlspecialchars($user['profile_image']) : '../uploads/profiles/default.png' ?>" 
                                             style="width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid #2D1859;">
                                        <div>
                                            <strong><?= htmlspecialchars($user['full_name']) ?></strong>
                                            <div style="font-size: 11px; color: #6c757d;">
                                                <i class="fas fa-calendar"></i> <?= date('d/m/Y', strtotime($user['created_at'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                 </th>
                                <td>
                                    <div style="font-size: 13px;">
                                        <div><i class="fas fa-envelope"></i> <?= htmlspecialchars($user['email']) ?></div>
                                        <?php if (!empty($user['phone'])): ?>
                                            <div><i class="fas fa-phone"></i> <?= htmlspecialchars($user['phone']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                 </th>
                                <td>
                                    <span class="role-badge role-<?= $user['role_type'] ?>">
                                        <?php
                                        $role_names = [
                                            'company_admin' => 'Company Admin',
                                            'tenant_admin' => 'Tenant Admin',
                                            'branch_manager' => 'Branch Manager',
                                            'staff' => 'Staff',
                                            'reception_clerk' => 'Reception Clerk',
                                            'warehouse_supervisor' => 'Warehouse Supervisor',
                                            'logistics_supervisor' => 'Logistics Supervisor',
                                            'finance_manager' => 'Finance Manager',
                                            'clerk' => 'Clerk',
                                            'driver' => 'Driver',
                                            'delivery_agent' => 'Delivery Agent',
                                            'customer' => 'Customer'
                                        ];

                                        echo $role_names[$user['role_type']] ?? ucfirst($user['role_type']);
                                        ?>
                                    </span>
                                 </th>
                                <td>
                                    <?php if (!empty($user['staff_level']) && $user['role_type'] != 'customer'): ?>
                                        <span class="staff-level-badge"><?= str_replace('_', ' ', htmlspecialchars($user['staff_level'])) ?></span>
                                    <?php else: ?>
                                        <span style="font-size: 12px; color: #6c757d;">-</span>
                                    <?php endif; ?>
                                 </th>
                                <td>
                                    <span class="status-badge <?= $user['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                        <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                 </th>
                                <td><?= date('d/m/Y', strtotime($user['created_at'])) ?> </th>
                                <td>
                                    <div class="action-buttons">
                                        <button class="action-btn btn-edit edit-user" data-id="<?= $user['id'] ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="action-btn btn-toggle toggle-status" data-id="<?= $user['id'] ?>">
                                            <i class="fas <?= $user['is_active'] ? 'fa-ban' : 'fa-check-circle' ?>"></i>
                                        </button>
                                        <button class="action-btn btn-delete delete-user" data-id="<?= $user['id'] ?>" data-name="<?= htmlspecialchars($user['full_name']) ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                 </th>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 50px;">
                                <div class="empty-state">
                                    <i class="fas fa-users-slash"></i>
                                    <p>No users found</p>
                                    <button class="btn-primary-custom" id="addUserBtnEmpty" style="margin-top: 10px;">
                                        <i class="fas fa-user-plus"></i> Add New User
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
        
        // Generate pagination HTML
        ob_start();
        if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a data-page="<?= $page-1 ?>"><i class="fas fa-chevron-left"></i> Previous</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active"><?= $i ?></span>
                    <?php else: ?>
                        <a data-page="<?= $i ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a data-page="<?= $page+1 ?>">Next <i class="fas fa-chevron-right"></i></a>
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
    
    elseif ($action === 'get_user') {
        $id = $_POST['id'] ?? 0;
        // Verify user belongs to this tenant
        $check = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
        $check->execute([$id]);
        $u = $check->fetch();
        if (!$u || $u['tenant_id'] != $session_tenant_id) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT id, full_name, email, phone, role_type, tenant_id, is_active, staff_level, profile_image FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($user);
        exit;
    }
    
    elseif ($action === 'save_user') {
        require_once __DIR__ . '/../includes/admin_audit.php';
        // Accept id from either the modal's `user_id` or a generic `id` post
        // key. A supplied id > 0 always means UPDATE and must never silently
        // fall through to CREATE if the id is unowned/unknown.
        $raw_id_specific = $_POST['user_id'] ?? '';
        $raw_id_generic  = $_POST['id'] ?? '';
        $id = ($raw_id_specific !== '' ? $raw_id_specific : $raw_id_generic);
        $update_intent = ($id !== '' && (int)$id > 0);
        $tenant_id = $session_tenant_id; // Force tenant_admin's tenant
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role_type = $_POST['role_type'] ?? 'staff';
        $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
        $staff_level = ($role_type == 'customer') ? null : (!empty($_POST['staff_level']) ? $_POST['staff_level'] : null);
        $password = $_POST['password'] ?? '';
        
        // Handle profile image upload
        $profile_image_path = $_POST['existing_profile_image'] ?? '';
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../../uploads/profiles/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $maxSize = 2 * 1024 * 1024;
            
            if ($_FILES['profile_image']['size'] <= $maxSize && in_array($ext, $allowed)) {
                $profileName = 'user_' . time() . '_' . uniqid() . '.' . $ext;
                $targetPath = $uploadDir . $profileName;
                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $targetPath)) {
                    $profile_image_path = 'uploads/profiles/' . $profileName;
                }
            }
        }
        
        if (empty($full_name) || empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Full name and email are required']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();

            if (!$update_intent) {
                // Check if email exists in this tenant
                $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND tenant_id = ?");
                $check->execute([$email, $tenant_id]);
                if ($check->fetch()) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => '❌ This email is already in use!']);
                    exit;
                }

                // Secure temporary-password provisioning: honor an
                // admin-supplied password if it meets policy; otherwise
                // generate a cryptographically random 12-char temporary
                // password and return it once.
                $min_password_len = 8;
                $weak_passwords = ['123', '1234', '12345', '123456', 'password', 'admin', 'test', '0000'];
                $temp_password_generated = false;
                if ($password !== '' && strlen($password) >= $min_password_len && !in_array(strtolower($password), $weak_passwords, true)) {
                    $temporary_password = $password;
                } else {
                    $alphabet = 'ABCDEFGHIJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
                    $temporary_password = '';
                    for ($i = 0; $i < 12; $i++) {
                        $temporary_password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                    }
                    $temp_password_generated = true;
                }
                $hashed = password_hash($temporary_password, PASSWORD_DEFAULT);

                // Insert into users table
                $sql = "INSERT INTO users (full_name, email, phone, password_hash, role_type, tenant_id, is_active, staff_level, profile_image, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$full_name, $email, $phone, $hashed, $role_type, $tenant_id, $is_active, $staff_level, $profile_image_path]);
                $new_user_id = $pdo->lastInsertId();

                // Driver provisioning atomicity: a users row with
                // role_type=driver is invalid without a paired
                // drivers.user_id profile. Insert the profile in the same
                // transaction so a failure on either side rolls back the
                // entire operation and cannot leave an orphan driver login.
                if ($role_type === 'driver') {
                    $driver_license = trim((string)($_POST['license_number'] ?? ''));
                    $driver_ins = $pdo->prepare("INSERT INTO drivers
                        (tenant_id, user_id, full_name, phone, license_number, is_active, created_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                    $driver_ins->execute([
                        $tenant_id, (int)$new_user_id, $full_name, $phone,
                        $driver_license !== '' ? $driver_license : null,
                        $is_active, $_SESSION['user_id'],
                    ]);
                }

                // If role is customer, also add to customers table
                if ($role_type == 'customer') {
                    // Check if customer already exists with same email or phone
                    $check_customer = $pdo->prepare("SELECT id FROM customers WHERE (email = ? OR phone = ?) AND tenant_id = ?");
                    $check_customer->execute([$email, $phone, $tenant_id]);
                    if (!$check_customer->fetch()) {
                        $sql_customer = "INSERT INTO customers (tenant_id, user_id, customer_name, phone, email, is_active, created_by, created_at)
                                         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                        $stmt_customer = $pdo->prepare($sql_customer);
                        $stmt_customer->execute([$tenant_id, $new_user_id, $full_name, $phone, $email, $is_active, $_SESSION['user_id']]);
                    }
                }

                record_admin_audit($pdo, 'USER_CREATED', 'users', (int)$new_user_id,
                    null,
                    ['full_name' => $full_name, 'email' => $email, 'role_type' => $role_type, 'tenant_id' => $tenant_id, 'is_active' => $is_active],
                    $tenant_id);

                $pdo->commit();
                $password_note = $temp_password_generated
                    ? "<br>🔑 Temporary password (show once): <strong class='text-success'>" . htmlspecialchars($temporary_password, ENT_QUOTES) . "</strong>"
                    : "<br>🔑 The password you supplied has been set.";
                echo json_encode(['success' => true, 'message' => "✅ User '$full_name' has been added!" . $password_note]);
            } else {
                // Verify user belongs to this tenant. Fetch full row for audit.
                $check = $pdo->prepare("SELECT id, tenant_id, role_type, full_name, email, phone, is_active FROM users WHERE id = ?");
                $check->execute([(int)$id]);
                $existing = $check->fetch(PDO::FETCH_ASSOC);
                if (!$existing || (int)$existing['tenant_id'] !== (int)$tenant_id) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'User not found or you do not have permission']);
                    exit;
                }
                $old_role_type = (string)$existing['role_type'];

                if (!empty($password)) {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $sql = "UPDATE users SET full_name=?, email=?, phone=?, password_hash=?, role_type=?, is_active=?, staff_level=?, profile_image=? WHERE id=? AND tenant_id=?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$full_name, $email, $phone, $hashed, $role_type, $is_active, $staff_level, $profile_image_path, $id, $tenant_id]);
                    
                    // Update customer table if role is customer
                    if ($role_type == 'customer') {
                        $check_customer = $pdo->prepare("SELECT id FROM customers WHERE user_id = ? AND tenant_id = ?");
                        $check_customer->execute([$id, $tenant_id]);
                        if ($check_customer->fetch()) {
                            $sql_customer = "UPDATE customers SET customer_name=?, phone=?, email=?, is_active=?, tenant_id=? WHERE user_id=? AND tenant_id=?";
                            $stmt_customer = $pdo->prepare($sql_customer);
                            $stmt_customer->execute([$full_name, $phone, $email, $is_active, $tenant_id, $id, $tenant_id]);
                        } else {
                            $sql_customer = "INSERT INTO customers (tenant_id, user_id, customer_name, phone, email, is_active, created_by, created_at) 
                                             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                            $stmt_customer = $pdo->prepare($sql_customer);
                            $stmt_customer->execute([$tenant_id, $id, $full_name, $phone, $email, $is_active, $_SESSION['user_id']]);
                        }
                    }
                    
                    users_admin_apply_role_conversion($pdo, (int)$id, $tenant_id, $old_role_type, $role_type, $full_name, $phone, $is_active);
                    record_admin_audit($pdo, 'USER_UPDATED', 'users', (int)$id,
                        $existing,
                        ['full_name' => $full_name, 'email' => $email, 'phone' => $phone, 'role_type' => $role_type, 'is_active' => $is_active, 'password_changed' => true],
                        $tenant_id);
                    if ($old_role_type !== $role_type) {
                        record_admin_audit($pdo, 'USER_ROLE_CHANGED', 'users', (int)$id,
                            ['role_type' => $old_role_type], ['role_type' => $role_type], $tenant_id);
                    }
                    echo json_encode(['success' => true, 'message' => "✅ User '$full_name' has been updated!<br>🔑 Password has been changed!"]);
                } else {
                    $sql = "UPDATE users SET full_name=?, email=?, phone=?, role_type=?, is_active=?, staff_level=?, profile_image=? WHERE id=? AND tenant_id=?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$full_name, $email, $phone, $role_type, $is_active, $staff_level, $profile_image_path, $id, $tenant_id]);
                    
                    // Update customer table if role is customer
                    if ($role_type == 'customer') {
                        $check_customer = $pdo->prepare("SELECT id FROM customers WHERE user_id = ? AND tenant_id = ?");
                        $check_customer->execute([$id, $tenant_id]);
                        if ($check_customer->fetch()) {
                            $sql_customer = "UPDATE customers SET customer_name=?, phone=?, email=?, is_active=?, tenant_id=? WHERE user_id=? AND tenant_id=?";
                            $stmt_customer = $pdo->prepare($sql_customer);
                            $stmt_customer->execute([$full_name, $phone, $email, $is_active, $tenant_id, $id, $tenant_id]);
                        } else {
                            $sql_customer = "INSERT INTO customers (tenant_id, user_id, customer_name, phone, email, is_active, created_by, created_at) 
                                             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                            $stmt_customer = $pdo->prepare($sql_customer);
                            $stmt_customer->execute([$tenant_id, $id, $full_name, $phone, $email, $is_active, $_SESSION['user_id']]);
                        }
                    }
                    
                    users_admin_apply_role_conversion($pdo, (int)$id, $tenant_id, $old_role_type, $role_type, $full_name, $phone, $is_active);
                    record_admin_audit($pdo, 'USER_UPDATED', 'users', (int)$id,
                        $existing,
                        ['full_name' => $full_name, 'email' => $email, 'phone' => $phone, 'role_type' => $role_type, 'is_active' => $is_active],
                        $tenant_id);
                    if ($old_role_type !== $role_type) {
                        record_admin_audit($pdo, 'USER_ROLE_CHANGED', 'users', (int)$id,
                            ['role_type' => $old_role_type], ['role_type' => $role_type], $tenant_id);
                    }
                    echo json_encode(['success' => true, 'message' => "✅ User '$full_name' has been updated!"]);
                }

                $pdo->commit();
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'delete_user') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        
        // Verify user belongs to this tenant
        $check = $pdo->prepare("SELECT tenant_id, role_type FROM users WHERE id = ?");
        $check->execute([$id]);
        $user = $check->fetch();
        if (!$user || $user['tenant_id'] != $session_tenant_id) {
            echo json_encode(['success' => false, 'message' => 'User not found or you do not have permission']);
            exit;
        }
        
        if ($id == $_SESSION['user_id']) {
            echo json_encode(['success' => false, 'message' => 'You cannot delete your own account!']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            if ($user['role_type'] == 'customer') {
                // Delete from customers table first
                $stmt_customer = $pdo->prepare("DELETE FROM customers WHERE user_id = ? AND tenant_id = ?");
                $stmt_customer->execute([$id, $session_tenant_id]);
            }
            
            // Delete from users table
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND tenant_id = ? AND role_type != 'superadmin'");
            $stmt->execute([$id, $session_tenant_id]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'User has been deleted!']);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'toggle_status') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        
        // Verify user belongs to this tenant
        $check = $pdo->prepare("SELECT tenant_id, role_type, is_active, full_name FROM users WHERE id = ?");
        $check->execute([$id]);
        $user = $check->fetch();
        if (!$user || $user['tenant_id'] != $session_tenant_id) {
            echo json_encode(['success' => false, 'message' => 'User not found or you do not have permission']);
            exit;
        }
        
        if ($id == $_SESSION['user_id']) {
            echo json_encode(['success' => false, 'message' => 'You cannot change your own status!']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            $new_status = $user['is_active'] ? 0 : 1;
            $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$new_status, $id, $session_tenant_id]);
            
            // Also update customer table if role is customer
            if ($user['role_type'] == 'customer') {
                $stmt_customer = $pdo->prepare("UPDATE customers SET is_active = ? WHERE user_id = ? AND tenant_id = ?");
                $stmt_customer->execute([$new_status, $id, $session_tenant_id]);
            }
            
            $pdo->commit();
            
            if ($new_status) {
                $message = "✅ User '{$user['full_name']}' is now ACTIVE!";
            } else {
                $message = "⚠️ User '{$user['full_name']}' is now INACTIVE!";
            }
            
            echo json_encode(['success' => true, 'message' => $message]);
            
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'get_stats') {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN role_type = 'company_admin' THEN 1 ELSE 0 END) as company_admin,
                SUM(CASE WHEN role_type = 'staff' THEN 1 ELSE 0 END) as staff,
                SUM(CASE WHEN role_type = 'customer' THEN 1 ELSE 0 END) as customer
            FROM users 
            WHERE tenant_id = ? AND role_type != 'superadmin' AND id != ?
        ");
        $stmt->execute([$session_tenant_id, $_SESSION['user_id']]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($stats ?: ['total' => 0, 'company_admin' => 0, 'staff' => 0, 'customer' => 0]);
        exit;
    }
    exit;
}

// Include header after AJAX handling
require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - <?= htmlspecialchars($tenant_name) ?> | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root {
            --curdun-violet: #2D1859;
            --curdun-yellow: #F5C410;
            --curdun-violet-light: #4B2C85;
            --curdun-gray: #6b6c72;
            --curdun-dark: #393a3d;
            --curdun-info: #0077c5;
            --curdun-success: #2ca01c;
            --curdun-danger: #d52b1e;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f5f8; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: var(--curdun-dark); }

        .page-header {
            background: #fff;
            border-bottom: 1px solid #e0e1e6;
            padding: 20px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .page-header h1 { color: var(--curdun-dark); font-size: 24px; font-weight: 700; margin: 0; }
        .page-header h1 i { color: var(--curdun-violet); margin-right: 10px; }
        .page-header .company-badge {
            background: rgba(82,0,102,0.1);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            color: var(--curdun-violet);
        }

        .btn-primary-custom {
            background: var(--curdun-violet);
            color: white;
            border: none;
            padding: 10px 22px;
            border-radius: 20px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .btn-primary-custom:hover {
            background: var(--curdun-violet-light);
            color: white;
            transform: translateY(-1px);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .stat-card-sm {
            background: white;
            border-radius: 8px;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid #e0e1e6;
            transition: transform 0.2s ease;
        }
        .stat-card-sm:hover { transform: translateY(-2px); }
        .stat-card-sm .stat-info h4 { font-size: 12px; color: var(--curdun-gray); margin: 0 0 5px 0; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card-sm .stat-info .stat-number { font-size: 24px; font-weight: 700; color: var(--curdun-dark); }
        .stat-card-sm .stat-icon { width: 40px; height: 40px; background: #f4f5f8; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .stat-card-sm .stat-icon i { font-size: 18px; color: var(--curdun-violet); }

        .filters-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
            border: 1px solid #e0e1e6;
        }
        .filter-form { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 180px; }
        .filter-group label { display: block; font-size: 12px; font-weight: 600; color: var(--curdun-gray); margin-bottom: 5px; text-transform: uppercase; }
        .filter-group input, .filter-group select { width: 100%; padding: 10px 12px; border: 1px solid #babec5; border-radius: 4px; font-size: 14px; outline: none; }
        .filter-group input:focus, .filter-group select:focus { border-color: var(--curdun-violet); }
        .btn-filter { background: #fff; color: var(--curdun-dark); border: 1px solid #babec5; padding: 10px 20px; border-radius: 20px; cursor: pointer; font-weight: 600; }
        .btn-filter:hover { background: #f4f5f8; }
        .btn-reset { background: none; color: var(--curdun-info); border: none; padding: 8px 10px; cursor: pointer; font-size: 14px; }

        .users-table-container {
            background: white;
            border-radius: 8px;
            border: 1px solid #e0e1e6;
            overflow-x: auto;
            width: 100%;
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1100px;
        }
        
        .users-table th {
            background: #fff;
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e0e1e6;
            color: var(--curdun-gray);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .users-table td {
            padding: 15px;
            border-bottom: 1px solid #f4f5f8;
            vertical-align: middle;
            font-size: 14px;
        }
        .users-table tr:hover { background: #f9f9fb; }

        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .status-active { background: #EEFBF3; color: #0F7A3A; }
        .status-inactive { background: #f4f5f8; color: #6b6c72; }
        
        .role-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; background: #f4f5f8; color: var(--curdun-dark); }
        .staff-level-badge { background: #fff3e0; color: #e65100; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-block; }

        .action-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        .action-btn { background: none; border: none; cursor: pointer; font-size: 16px; transition: 0.2s; }
        .action-btn i { color: var(--curdun-info); }
        .action-btn:hover i { color: var(--curdun-violet); }
        .btn-delete i { color: var(--curdun-danger); }
        .btn-delete:hover i { color: #a51d14; }

        .alert-custom { 
            position: fixed; 
            top: 20px; 
            right: 20px; 
            z-index: 9999; 
            min-width: 300px; 
            animation: slideIn 0.3s ease; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        @keyframes slideIn { 
            from { transform: translateX(100%); opacity: 0; } 
            to { transform: translateX(0); opacity: 1; } 
        }
        .alert-success { background: #EEFBF3; color: #0F7A3A; border-left: 4px solid #0F7A3A; }
        .alert-error { background: #FEF0EE; color: #B42318; border-left: 4px solid #B42318; }

        .empty-state { text-align: center; padding: 50px; color: var(--curdun-gray); }
        .empty-state i { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }

        .modal-header { background: #f4f5f8; color: var(--curdun-dark); border-bottom: 1px solid #e0e1e6; border-radius: 16px 16px 0 0; }
        .modal-title { font-weight: 700; }
        .modal-header .close { color: var(--curdun-dark); }

        .loading-spinner { text-align: center; padding: 50px; }
        .loading-spinner i { font-size: 48px; color: var(--curdun-violet); animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        .image-preview {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--curdun-violet);
            margin-top: 5px;
        }

        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 25px; flex-wrap: wrap; margin-bottom: 25px; }
        .pagination a, .pagination span { padding: 8px 14px; border-radius: 8px; text-decoration: none; color: var(--curdun-dark); background: white; border: 1px solid #ddd; cursor: pointer; transition: all 0.3s ease; }
        .pagination .active { background: var(--curdun-violet); color: white; border-color: var(--curdun-violet); }
        .pagination a:hover { background: var(--curdun-violet-light); color: white; transform: translateY(-2px); }

        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: center; }
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            .action-buttons { flex-direction: column; gap: 5px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .alert-custom { left: 20px; right: 20px; min-width: auto; }
        }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-users"></i> User Management</h1>
        <div class="d-flex gap-3 align-items-center">
            <span class="company-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($tenant_name) ?></span>
            <button type="button" class="btn-primary-custom" id="addUserBtn">
                <i class="fas fa-user-plus"></i> Add User
            </button>
        </div>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card-sm">
            <div class="stat-info"><h4>Total Users</h4><div class="stat-number" id="stat-total">0</div></div>
            <div class="stat-icon"><i class="fas fa-users"></i></div>
        </div>
        <div class="stat-card-sm">
            <div class="stat-info"><h4>Company Admins</h4><div class="stat-number" id="stat-company-admin">0</div></div>
            <div class="stat-icon"><i class="fas fa-building"></i></div>
        </div>
        <div class="stat-card-sm">
            <div class="stat-info"><h4>Staff</h4><div class="stat-number" id="stat-staff">0</div></div>
            <div class="stat-icon"><i class="fas fa-briefcase"></i></div>
        </div>
        <div class="stat-card-sm">
            <div class="stat-info"><h4>Customers</h4><div class="stat-number" id="stat-customer">0</div></div>
            <div class="stat-icon"><i class="fas fa-user-friends"></i></div>
        </div>
    </div>

    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group"><label><i class="fas fa-search"></i> Search</label><input type="text" id="searchInput" placeholder="Name, Email, Phone..."></div>
            <div class="filter-group"><label><i class="fas fa-user-tag"></i> Role</label><select id="roleFilter"><option value="">All</option><option value="company_admin">Company Admin</option><option value="staff">Staff</option><option value="customer">Customer</option></select></div>
            <div class="filter-group"><label><i class="fas fa-circle"></i> Status</label><select id="statusFilter"><option value="">All</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
            <div class="filter-group"><button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Filter</button><button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Reset</button></div>
        </div>
    </div>

    <div id="users-table-container"><div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i><p>Loading users...</p></div></div>
    <div id="pagination-container"></div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalLabel">New User</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="userForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="user_id">
                    <input type="hidden" name="existing_profile_image" id="existing_profile_image">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Full Name *</label>
                                <input type="text" name="full_name" id="modalFullName" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Email Address *</label>
                                <input type="email" name="email" id="modalEmail" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="text" name="phone" id="modalPhone" class="form-control">
                            </div>
                        </div>
                        
                        <!-- Password Field - Hidden for Create, Shown for Edit -->
                        <div class="col-md-6" id="password_field" style="display: none;">
                            <div class="form-group">
                                <label>Password</label>
                                <input type="password" name="password" id="modalPassword" class="form-control" placeholder="Leave empty to keep current password">
                                <small class="text-muted">Only fill if you want to change password</small>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Role</label>
                                <select name="role_type" id="modalRole" class="form-control">
                                    <option value="company_admin">Company Admin</option>
                                    <option value="branch_manager">Branch Manager</option>
                                    <option value="staff">Staff</option>
                                    <option value="reception_clerk">Staff — Reception Clerk</option>
                                    <option value="warehouse_supervisor">Staff — Warehouse Supervisor</option>
                                    <option value="logistics_supervisor">Staff — Logistics Supervisor</option>
                                    <option value="finance_manager">Staff — Finance Manager</option>
                                    <option value="clerk">Staff — Clerk</option>
                                    <option value="driver">Driver</option>
                                    <option value="delivery_agent">Delivery Agent / Courier</option>
                                    <option value="customer">Customer</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-6" id="staff_level_field">
                            <div class="form-group">
                                <label>Staff Level</label>
                                <select name="staff_level" id="modalStaffLevel" class="form-control">
                                    <option value="">Select Level...</option>
                                    <option value="general_manager">General Manager</option>
                                    <option value="operations_manager">Operations Manager</option>
                                    <option value="finance_manager">Finance Manager</option>
                                    <option value="logistics_supervisor">Logistics Supervisor</option>
                                    <option value="warehouse_supervisor">Warehouse Supervisor</option>
                                    <option value="dispatcher">Dispatcher</option>
                                    <option value="loader_supervisor">Loader Supervisor</option>
                                    <option value="trainer">Trainer</option>
                                    <option value="senior_driver">Senior Driver</option>
                                    <option value="driver">Driver</option>
                                    <option value="junior_driver">Junior Driver</option>
                                    <option value="loader">Loader</option>
                                    <option value="clerk">Clerk</option>
                                    <option value="customer_service">Customer Service</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="is_active" id="modalIsActive" class="form-control">
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Profile Image</label>
                                <input type="file" name="profile_image" id="profile_image" class="form-control" accept="image/*">
                                <small class="text-muted">JPG, PNG, GIF, WEBP (Max 2MB)</small>
                                <div id="profilePreview"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="info-box mt-3">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Note:</strong> New users will automatically receive password: <strong class="text-success">123</strong>.
                        Customers are automatically added to the Customers table.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Save User</button>
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
                <h5 class="modal-title">Delete User</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete <strong id="deleteUserName"></strong>?<br><br>
                <span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Warning: This action is permanent!</span>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
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

    // Show/Hide Staff Level based on role
    function toggleStaffLevel() {
        const role = $('#modalRole').val();
        if (role === 'customer') {
            $('#staff_level_field').hide();
            $('#modalStaffLevel').val('');
        } else {
            $('#staff_level_field').show();
        }
    }
    
    // Call on role change
    $('#modalRole').on('change', toggleStaffLevel);
    
    // Preview profile image
    $('#profile_image').on('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                $('#profilePreview').html(`<img src="${e.target.result}" class="image-preview">`);
            };
            reader.readAsDataURL(file);
        }
    });

    function loadUsers() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'get_users',
                page: currentPage,
                search: $('#searchInput').val(),
                role: $('#roleFilter').val(),
                status: $('#statusFilter').val()
            },
            dataType: 'json',
            success: function(response) {
                $('#users-table-container').html(response.table_html);
                $('#pagination-container').html(response.pagination_html);
                attachTableEvents();
            },
            error: function() {
                $('#users-table-container').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading data</p></div>');
            }
        });
    }

    function loadStats() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_stats' },
            dataType: 'json',
            success: function(stats) {
                $('#stat-total').text(stats.total || 0);
                $('#stat-company-admin').text(stats.company_admin || 0);
                $('#stat-staff').text(stats.staff || 0);
                $('#stat-customer').text(stats.customer || 0);
            }
        });
    }

    function attachTableEvents() {
        $('.edit-user').off('click').on('click', function() {
            const id = $(this).data('id');
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_user', id: id },
                dataType: 'json',
                success: function(user) {
                    $('#userModalLabel').text('Edit User');
                    $('#user_id').val(user.id);
                    $('#modalFullName').val(user.full_name);
                    $('#modalEmail').val(user.email);
                    $('#modalPhone').val(user.phone);
                    $('#modalRole').val(user.role_type);
                    $('#modalIsActive').val(user.is_active);
                    $('#modalStaffLevel').val(user.staff_level);
                    $('#existing_profile_image').val(user.profile_image || '');
                    
                    // Show password field for edit
                    $('#password_field').show();
                    $('#modalPassword').val('');
                    
                    // Toggle staff level based on role
                    toggleStaffLevel();
                    
                    if (user.profile_image) {
                        $('#profilePreview').html(`<img src="../${user.profile_image}" class="image-preview">`);
                    } else {
                        $('#profilePreview').empty();
                    }
                    
                    $('#userModal').modal('show');
                }
            });
        });

        $('.delete-user').off('click').on('click', function() {
            deleteId = $(this).data('id');
            $('#deleteUserName').text($(this).data('name'));
            $('#deleteModal').modal('show');
        });

        $('.toggle-status').off('click').on('click', function() {
            if (confirm('Are you sure you want to change this user\'s status?')) {
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: { ajax_action: 'toggle_status', id: $(this).data('id') },
                    dataType: 'json',
                    success: function(res) {
                        showAlert(res.success ? 'success' : 'error', res.message);
                        if (res.success) { loadUsers(); loadStats(); }
                    }
                });
            }
        });

        $('.pagination a').off('click').on('click', function(e) {
            e.preventDefault();
            const page = $(this).data('page');
            if (page) { currentPage = page; loadUsers(); }
        });
    }

    function showAlert(type, msg) {
        const alertClass = type === 'success' ? 'alert-success' : 'alert-error';
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        $('#alert-placeholder').html(`<div class="alert alert-custom ${alertClass} alert-dismissible fade show"><i class="fas ${icon} mr-2"></i> ${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
        setTimeout(() => $('.alert-custom').fadeOut(5000, function() { $(this).remove(); }), 5000);
    }

    $('#userForm').submit(function(e) {
        e.preventDefault();
        
        if (!$('#modalFullName').val()) { showAlert('error', 'Please enter the full name'); return; }
        if (!$('#modalEmail').val()) { showAlert('error', 'Please enter the email address'); return; }
        
        const formData = new FormData(this);
        formData.append('ajax_action', 'save_user');
        
        const submitBtn = $(this).find('button[type="submit"]');
        const originalText = submitBtn.html();
        submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Saving...').prop('disabled', true);
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#userModal').modal('hide');
                    loadUsers();
                    loadStats();
                    showAlert('success', res.message);
                    $('#userForm')[0].reset();
                    $('#user_id').val('');
                    $('#profilePreview').empty();
                    $('#password_field').hide();
                    toggleStaffLevel();
                } else { 
                    showAlert('error', res.message); 
                }
                submitBtn.html(originalText).prop('disabled', false);
            },
            error: function() { 
                showAlert('error', 'An error occurred');
                submitBtn.html(originalText).prop('disabled', false);
            }
        });
    });

    $('#confirmDeleteBtn').click(function() {
        if (deleteId) {
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'delete_user', id: deleteId },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        $('#deleteModal').modal('hide');
                        loadUsers();
                        loadStats();
                        showAlert('success', res.message);
                    } else { 
                        showAlert('error', res.message); 
                    }
                    deleteId = null;
                }
            });
        }
    });

    $('#addUserBtn, #addUserBtnEmpty').click(function() {
        $('#userModalLabel').text('New User');
        $('#userForm')[0].reset();
        $('#user_id').val('');
        $('#password_field').hide();
        $('#modalPassword').val('');
        $('#modalIsActive').val(1);
        $('#profilePreview').empty();
        $('#modalRole').val('customer');
        toggleStaffLevel();
        $('#info_text').remove();
        $('#userModal').modal('show');
    });

    $('#applyFilters').click(function() { currentPage = 1; loadUsers(); loadStats(); });
    $('#resetFilters').click(function() { $('#searchInput').val(''); $('#roleFilter').val(''); $('#statusFilter').val(''); currentPage = 1; loadUsers(); loadStats(); });
    $('#searchInput').keypress(function(e) { if (e.which === 13) { currentPage = 1; loadUsers(); } });

    toggleStaffLevel();
    loadUsers();
    loadStats();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>