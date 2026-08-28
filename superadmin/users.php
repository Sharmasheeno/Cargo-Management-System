<?php
// superadmin/users.php
// User Management forfaras cargo - Super Admin
// WITH PROFILE IMAGE UPLOAD + AUTO PASSWORD 123 + AUTO ADD TO CUSTOMERS TABLE

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
require_once __DIR__ . '/../includes/sa_scope.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Super Admin';

// Get all tenants for filter dropdown (Super Admin only)
$tenants = [];
if ($role === 'superadmin') {
    try {
        $stmt = $pdo->query("SELECT id, name FROM tenants ORDER BY name");
        $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $tenants = [];
    }
}

// Handle Export Actions (GET)
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action === 'export_users') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=users_export_'.date('Y-m-d').'.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['ID', 'Full Name', 'Email', 'Phone', 'Role', 'Tenant', 'Staff Level', 'Status', 'Created At']);
        
        $where_conditions = ["u.role_type != 'superadmin'"];
        $params = [];
        
        $search = $_GET['search'] ?? '';
        $role_filter = $_GET['role'] ?? '';
        $tenant_filter = $_GET['tenant'] ?? '';
        
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
        if (!empty($tenant_filter)) {
            $where_conditions[] = "u.tenant_id = ?";
            $params[] = $tenant_filter;
        }
        
        $where_clause = implode(" AND ", $where_conditions);
        
        $sql = "SELECT u.id, u.full_name, u.email, u.phone, u.role_type, t.name as tenant_name, u.staff_level, u.is_active, u.created_at 
                FROM users u 
                LEFT JOIN tenants t ON u.tenant_id = t.id 
                WHERE $where_clause 
                ORDER BY u.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['id'],
                $row['full_name'],
                $row['email'],
                $row['phone'],
                $row['role_type'],
                $row['tenant_name'],
                $row['staff_level'],
                $row['is_active'] ? 'Active' : 'Inactive',
                $row['created_at']
            ]);
        }
        fclose($output);
        exit;
    }
    
    if ($action === 'download_sample') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=users_sample.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, ['Full Name', 'Email', 'Phone', 'Role (staff/customer/company_admin)', 'Tenant Name', 'Staff Level']);
        fputcsv($output, ['Test User', 'test@example.com', '123456789', 'staff', 'Example Logistics', 'Manager']);
        fclose($output);
        exit;
    }
}

// Handle AJAX requests FIRST before any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    
    $action = $_POST['ajax_action'];
    
    if ($action === 'get_users') {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $search = $_POST['search'] ?? '';
        $role_filter = $_POST['role'] ?? '';
        $status_filter = $_POST['status'] ?? '';
        $tenant_filter = ($role === 'superadmin') ? (isset($_POST['tenant']) ? (int)$_POST['tenant'] : sa_selected_tenant_id_int()) : $session_tenant_id;
        
        $where_conditions = ["role_type != 'superadmin'"];
        $params = [];
        
        if (!empty($search)) {
            $where_conditions[] = "(full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if (!empty($role_filter)) {
            $where_conditions[] = "role_type = ?";
            $params[] = $role_filter;
        }
        
        if ($status_filter !== '') {
            $where_conditions[] = "is_active = ?";
            $params[] = $status_filter == 'active' ? 1 : 0;
        }
        
        if ($tenant_filter > 0) {
            $where_conditions[] = "tenant_id = ?";
            $params[] = $tenant_filter;
        } elseif ($role === 'company_admin') {
            $where_conditions[] = "tenant_id = ?";
            $params[] = $session_tenant_id;
        }
        
        $where_clause = implode(" AND ", $where_conditions);
        
        // Get total count
        $count_sql = "SELECT COUNT(*) as total FROM users WHERE $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_users = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_users / $limit);
        
        // Get users with tenant info
        $sql = "
            SELECT u.*, t.name as tenant_name 
            FROM users u
            LEFT JOIN tenants t ON u.tenant_id = t.id
            WHERE $where_clause
            ORDER BY u.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate table HTML
        ob_start(); ?>
        <div style="overflow-x: auto; width: 100%;">
            <table class="users-table" style="min-width: 1300px; width: 100%;">
                <thead>
                    <tr>
                        <th style="min-width: 60px;">ID</th>
                        <th style="min-width: 220px;">Macluumaadka</th>
                        <th style="min-width: 200px;">Xiriirka</th>
                        <th style="min-width: 120px;">Doorka</th>
                        <th style="min-width: 150px;">Shirkadda</th>
                        <th style="min-width: 150px;">Heerka</th>
                        <th style="min-width: 100px;">Xaaladda</th>
                        <th style="min-width: 100px;">Taariikhda</th>
                        <th style="min-width: 120px;">Falalka</th>
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
                                </td>
                                <td>
                                    <div style="font-size: 13px;">
                                        <div><i class="fas fa-envelope"></i> <?= htmlspecialchars($user['email']) ?></div>
                                        <?php if (!empty($user['phone'])): ?>
                                            <div><i class="fas fa-phone"></i> <?= htmlspecialchars($user['phone']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="role-badge role-<?= $user['role_type'] ?>">
                                        <?php
                                        $role_names = [
                                            'company_admin' => 'Maamulaha Shirkadda',
                                            'staff' => 'Shaqaale',
                                            'customer' => 'Macaamil'
                                        ];
                                        echo $role_names[$user['role_type']] ?? ucfirst($user['role_type']);
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($user['tenant_name']): ?>
                                        <span style="font-size: 12px;"><?= htmlspecialchars($user['tenant_name']) ?></span>
                                    <?php else: ?>
                                        <span style="font-size: 12px; color: #6c757d;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($user['staff_level']) && $user['role_type'] != 'customer'): ?>
                                        <span class="staff-level-badge"><?= str_replace('_', ' ', htmlspecialchars($user['staff_level'])) ?></span>
                                    <?php else: ?>
                                        <span style="font-size: 12px; color: #6c757d;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge <?= $user['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                        <?= $user['is_active'] ? 'Firfircoon' : 'Aan Firfircooneyn' ?>
                                    </span>
                                </td>
                                <td><?= date('d/m/Y', strtotime($user['created_at'])) ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="action-btn btn-edit edit-user" data-id="<?= $user['id'] ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="action-btn btn-toggle toggle-status" data-id="<?= $user['id'] ?>">
                                            <i class="fas <?= $user['is_active'] ? 'fa-ban' : 'fa-check-circle' ?>"></i>
                                        </button>
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <button class="action-btn btn-delete delete-user" data-id="<?= $user['id'] ?>" data-name="<?= htmlspecialchars($user['full_name']) ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 50px;">
                                <div class="empty-state">
                                    <i class="fas fa-users-slash"></i>
                                    <p>Ma jiraan isticmaaleysaasha</p>
                                    <button class="btn-primary-custom" id="addUserBtnEmpty" style="margin-top: 10px;">
                                        <i class="fas fa-user-plus"></i> Ku dar Isticmaale Cusub
                                    </button>
                                </div>
                            </td>
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
                    <a data-page="<?= $page-1 ?>"><i class="fas fa-chevron-left"></i> Hore</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active"><?= $i ?></span>
                    <?php else: ?>
                        <a data-page="<?= $i ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a data-page="<?= $page+1 ?>">Danbe <i class="fas fa-chevron-right"></i></a>
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
        // Safety check for company_admin
        if ($role === 'company_admin') {
            $check = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
            $check->execute([$id]);
            $u = $check->fetch();
            if (!$u || $u['tenant_id'] != $session_tenant_id) {
                echo json_encode(['success' => false, 'message' => 'Ma laguu ogola inaad xogtaan aragto']);
                exit;
            }
        }
        $stmt = $pdo->prepare("SELECT id, full_name, email, phone, role_type, tenant_id, is_active, staff_level, profile_image FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($user);
        exit;
    }
    
    elseif ($action === 'save_user') {
        $id = $_POST['user_id'] ?? '';
        
        // Safety check for company_admin
        if ($role === 'company_admin') {
            if (!empty($id)) {
                $check = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
                $check->execute([$id]);
                $u = $check->fetch();
                if (!$u || $u['tenant_id'] != $session_tenant_id) {
                    echo json_encode(['success' => false, 'message' => 'Ma laguu ogola inaad wax ka beddesho isticmaalahan']);
                    exit;
                }
            }
            $_POST['tenant_id'] = $session_tenant_id; // Always force their own tenant_id
        }
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role_type = $_POST['role_type'] ?? 'staff';
        // Role allowlist: even Super Admin must not store malformed role strings.
        $allowed_role_types = [
            'superadmin', 'tenant_admin', 'company_admin',
            'branch_manager', 'staff', 'reception_clerk',
            'warehouse_supervisor', 'logistics_supervisor', 'finance_manager',
            'clerk', 'driver', 'delivery_agent', 'customer',
        ];
        if (!in_array($role_type, $allowed_role_types, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid role_type: ' . htmlspecialchars($role_type, ENT_QUOTES)]);
            exit;
        }
        $tenant_id = !empty($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : null;
        $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
        // For customer role, staff_level is always NULL
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
            echo json_encode(['success' => false, 'message' => 'Magaca iyo Emailka waa lagama maarmaan']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            if (empty($id)) {
                // Check if email exists
                $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $check->execute([$email]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Emailkan waxaa horay loo isticmaalay']);
                    exit;
                }
                
                // Secure temporary-password provisioning: honor admin-supplied
                // password when it meets policy, else generate a random 12-char
                // temporary password. Never persist plaintext.
                $__sa_alphabet = 'ABCDEFGHIJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
                $weak_passwords = ['123', '1234', '12345', '123456', 'password', 'admin', 'test', '0000'];
                $sa_temp_generated = false;
                if ($password !== '' && strlen($password) >= 8 && !in_array(strtolower($password), $weak_passwords, true)) {
                    $default_password = $password;
                } else {
                    $default_password = '';
                    for ($__i = 0; $__i < 12; $__i++) {
                        $default_password .= $__sa_alphabet[random_int(0, strlen($__sa_alphabet) - 1)];
                    }
                    $sa_temp_generated = true;
                }
                $hashed = password_hash($default_password, PASSWORD_DEFAULT);

                // Insert into users table
                $sql = "INSERT INTO users (full_name, email, phone, password_hash, role_type, tenant_id, is_active, staff_level, profile_image, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$full_name, $email, $phone, $hashed, $role_type, $tenant_id, $is_active, $staff_level, $profile_image_path]);
                $new_user_id = $pdo->lastInsertId();
                
                // If role is customer, also add to customers table
                if ($role_type == 'customer') {
                    // Check if customer already exists with same email or phone
                    $check_customer = $pdo->prepare("SELECT id FROM customers WHERE email = ? OR phone = ?");
                    $check_customer->execute([$email, $phone]);
                    if (!$check_customer->fetch()) {
                        $sql_customer = "INSERT INTO customers (tenant_id, user_id, customer_name, phone, email, is_active, created_by, created_at) 
                                         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                        $stmt_customer = $pdo->prepare($sql_customer);
                        $stmt_customer->execute([$tenant_id, $new_user_id, $full_name, $phone, $email, $is_active, $_SESSION['user_id']]);
                    }
                }
                
                require_once __DIR__ . '/../includes/admin_audit.php';
                record_admin_audit($pdo, 'USER_CREATED', 'users', (int)$new_user_id,
                    null,
                    ['full_name' => $full_name, 'email' => $email, 'role_type' => $role_type, 'tenant_id' => $tenant_id, 'is_active' => $is_active],
                    $tenant_id);
                $pdo->commit();
                $__note = $sa_temp_generated
                    ? "<br>🔑 Temporary password (show once): <strong class='text-success'>" . htmlspecialchars($default_password, ENT_QUOTES) . "</strong>"
                    : "<br>🔑 The password you supplied has been set.";
                echo json_encode(['success' => true, 'message' => "✅ Isticmaale '$full_name' waa la daray!" . $__note]);
            } else {
                // Update existing user
                if (!empty($password)) {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $sql = "UPDATE users SET full_name=?, email=?, phone=?, password_hash=?, role_type=?, tenant_id=?, is_active=?, staff_level=?, profile_image=? WHERE id=?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$full_name, $email, $phone, $hashed, $role_type, $tenant_id, $is_active, $staff_level, $profile_image_path, $id]);
                    
                    // Update customer table if role is customer
                    if ($role_type == 'customer') {
                        $check_customer = $pdo->prepare("SELECT id FROM customers WHERE user_id = ?");
                        $check_customer->execute([$id]);
                        if ($check_customer->fetch()) {
                            $sql_customer = "UPDATE customers SET customer_name=?, phone=?, email=?, is_active=?, tenant_id=? WHERE user_id=?";
                            $stmt_customer = $pdo->prepare($sql_customer);
                            $stmt_customer->execute([$full_name, $phone, $email, $is_active, $tenant_id, $id]);
                        } else {
                            $sql_customer = "INSERT INTO customers (tenant_id, user_id, customer_name, phone, email, is_active, created_by, created_at) 
                                             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                            $stmt_customer = $pdo->prepare($sql_customer);
                            $stmt_customer->execute([$tenant_id, $id, $full_name, $phone, $email, $is_active, $_SESSION['user_id']]);
                        }
                    }
                    
                    echo json_encode(['success' => true, 'message' => "✅ Isticmaale '$full_name' waa la cusboonaysiiyay!<br>🔑 Password waa la beddelay!"]);
                } else {
                    $sql = "UPDATE users SET full_name=?, email=?, phone=?, role_type=?, tenant_id=?, is_active=?, staff_level=?, profile_image=? WHERE id=?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$full_name, $email, $phone, $role_type, $tenant_id, $is_active, $staff_level, $profile_image_path, $id]);
                    
                    // Update customer table if role is customer
                    if ($role_type == 'customer') {
                        $check_customer = $pdo->prepare("SELECT id FROM customers WHERE user_id = ?");
                        $check_customer->execute([$id]);
                        if ($check_customer->fetch()) {
                            $sql_customer = "UPDATE customers SET customer_name=?, phone=?, email=?, is_active=?, tenant_id=? WHERE user_id=?";
                            $stmt_customer = $pdo->prepare($sql_customer);
                            $stmt_customer->execute([$full_name, $phone, $email, $is_active, $tenant_id, $id]);
                        } else {
                            $sql_customer = "INSERT INTO customers (tenant_id, user_id, customer_name, phone, email, is_active, created_by, created_at) 
                                             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                            $stmt_customer = $pdo->prepare($sql_customer);
                            $stmt_customer->execute([$tenant_id, $id, $full_name, $phone, $email, $is_active, $_SESSION['user_id']]);
                        }
                    }
                    
                    echo json_encode(['success' => true, 'message' => "✅ Isticmaale '$full_name' waa la cusboonaysiiyay!<br>🔑 Password isku mid ayaa hadhay (123)"]);
                }
                
                $pdo->commit();
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'delete_user') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        
        // Safety check for company_admin
        if ($role === 'company_admin') {
            $check = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
            $check->execute([$id]);
            $u = $check->fetch();
            if (!$u || $u['tenant_id'] != $session_tenant_id) {
                echo json_encode(['success' => false, 'message' => 'Ma laguu ogola inaad tirtirto isticmaalahan']);
                exit;
            }
        }
        if ($id == $_SESSION['user_id']) {
            echo json_encode(['success' => false, 'message' => 'Kuma delete garayn kartid akoonkaaga!']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Get user role before deleting
            $stmt = $pdo->prepare("SELECT role_type FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            
            if ($user && $user['role_type'] == 'customer') {
                // Delete from customers table first
                $stmt_customer = $pdo->prepare("DELETE FROM customers WHERE user_id = ?");
                $stmt_customer->execute([$id]);
            }
            
            // Delete from users table
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role_type != 'superadmin'");
            $stmt->execute([$id]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Isticmaale waa la tirtiray!']);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'toggle_status') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        
        // Safety check for company_admin
        if ($role === 'company_admin') {
            $check = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
            $check->execute([$id]);
            $u = $check->fetch();
            if (!$u || $u['tenant_id'] != $session_tenant_id) {
                echo json_encode(['success' => false, 'message' => 'Ma laguu ogola inaad beddesho xaaladda isticmaalahan']);
                exit;
            }
        }
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT is_active, full_name, role_type FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                echo json_encode(['success' => false, 'message' => 'Isticmaale lama helin']);
                exit;
            }
            
            $new_status = $user['is_active'] ? 0 : 1;
            $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmt->execute([$new_status, $id]);
            
            // Also update customer table if role is customer
            if ($user['role_type'] == 'customer') {
                $stmt_customer = $pdo->prepare("UPDATE customers SET is_active = ? WHERE user_id = ?");
                $stmt_customer->execute([$new_status, $id]);
            }
            
            $pdo->commit();
            
            if ($new_status) {
                $message = "✅ Isticmaale '{$user['full_name']}' hadda waa FIRFIRCOON!";
            } else {
                $message = "⚠️ Isticmaale '{$user['full_name']}' hadda waa AAN FIRFIRCOONEYN!";
            }
            
            echo json_encode(['success' => true, 'message' => $message]);
            
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'get_stats') {
        if ($role === 'company_admin') {
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN role_type = 'company_admin' THEN 1 ELSE 0 END) as company_admin,
                    SUM(CASE WHEN role_type = 'staff' THEN 1 ELSE 0 END) as staff,
                    SUM(CASE WHEN role_type = 'customer' THEN 1 ELSE 0 END) as customer
                FROM users WHERE tenant_id = ? AND role_type != 'superadmin'
            ");
            $stmt->execute([$session_tenant_id]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
            exit;
        }
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN role_type = 'company_admin' THEN 1 ELSE 0 END) as company_admin,
                SUM(CASE WHEN role_type = 'staff' THEN 1 ELSE 0 END) as staff,
                SUM(CASE WHEN role_type = 'customer' THEN 1 ELSE 0 END) as customer
            FROM users WHERE role_type != 'superadmin'
        ");
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit;
    }
    elseif ($action === 'import_users') {
        if (!isset($_FILES['excel_file'])) {
            echo json_encode(['success' => false, 'message' => 'Fayl lama dooran!']);
            exit;
        }
        
        $file = $_FILES['excel_file']['tmp_name'];
        $handle = fopen($file, "r");
        fgetcsv($handle); // Skip header
        
        $imported = 0;
        $errors = [];
        $line = 1;
        
        try {
            $pdo->beginTransaction();
            
            // Pre-fetch tenants
            $tenants_map = [];
            $stmt = $pdo->query("SELECT id, name FROM tenants");
            while ($t = $stmt->fetch()) {
                $tenants_map[strtolower($t['name'])] = $t['id'];
            }
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $line++;
                // Columns: Full Name, Email, Phone, Role, Tenant Name, Staff Level
                $full_name = trim($data[0] ?? '');
                $email = trim($data[1] ?? '');
                $phone = trim($data[2] ?? '');
                $role_type = strtolower(trim($data[3] ?? 'staff'));
                $tenant_name = trim($data[4] ?? '');
                $staff_level = trim($data[5] ?? '');
                
                if (empty($full_name) || empty($email)) continue;
                
                // Check if email exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $errors[] = "Line $line: Email '$email' horay ayaa loo isticmaalay.";
                    continue;
                }
                
                $t_id = null;
                if ($role === 'superadmin' && !empty($tenant_name)) {
                    $t_id = $tenants_map[strtolower($tenant_name)] ?? null;
                } elseif ($role === 'company_admin') {
                    $t_id = $session_tenant_id;
                }
                
                // Secure temporary-password provisioning (replaces legacy fixed '123' in CSV import).
                $__sa_alphabet = 'ABCDEFGHIJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
                $__temp_pw = '';
                for ($__i = 0; $__i < 12; $__i++) {
                    $__temp_pw .= $__sa_alphabet[random_int(0, strlen($__sa_alphabet) - 1)];
                }
                $hashed_password = password_hash($__temp_pw, PASSWORD_DEFAULT);

                // Insert user
                $stmt = $pdo->prepare("INSERT INTO users (tenant_id, email, password_hash, full_name, phone, role_type, staff_level, is_active, created_at) VALUES (?,?,?,?,?,?,?,1,NOW())");
                $stmt->execute([$t_id, $email, $hashed_password, $full_name, $phone, $role_type, $staff_level]);
                $new_u_id = $pdo->lastInsertId();
                
                // If role is customer, also add to customers table
                if ($role_type == 'customer') {
                    $stmt_customer = $pdo->prepare("INSERT INTO customers (tenant_id, user_id, customer_name, phone, email, is_active, created_by, created_at) VALUES (?, ?, ?, ?, ?, 1, ?, NOW())");
                    $stmt_customer->execute([$t_id, $new_u_id, $full_name, $phone, $email, $user_id]);
                }
                
                $imported++;
            }
            
            $pdo->commit();
            $msg = "Import-ka waa lagu guulaystay! ($imported isticmaale).";
            if (count($errors) > 0) $msg .= "<br>Digniin: " . count($errors) . " saf ayaa laga booday.";
            echo json_encode(['success' => true, 'message' => $msg]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        fclose($handle);
        exit;
    }
    exit;
}

// Include header after AJAX handling
require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maareynta Isticmaaleysaasha - Super Admin | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root {
            --curdun-violet: #2ca01c; /* QB Green */
            --curdun-yellow: #f4f5f8;
            --curdun-violet-light: #108000;
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
            box-shadow: 0 4px 10px rgba(44, 160, 28, 0.2);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        .stat-card-sm .stat-info h4 { font-size: 13px; color: var(--curdun-gray); margin: 0 0 5px 0; font-weight: 600; text-transform: uppercase; }
        .stat-card-sm .stat-info .stat-number { font-size: 26px; font-weight: 700; color: var(--curdun-dark); }
        .stat-card-sm .stat-icon { width: 45px; height: 45px; background: #f4f5f8; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .stat-card-sm .stat-icon i { font-size: 20px; color: var(--curdun-violet); }

        .filters-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
            border: 1px solid #e0e1e6;
        }
        .filter-form { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 180px; }
        .filter-group label { display: block; font-size: 12px; font-weight: 600; color: var(--curdun-gray); margin-bottom: 5px; }
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

        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; border: 1px solid transparent; }
        .status-active { background: #EEFBF3; color: #0F7A3A; border-color: #c8e6c9; }
        .status-inactive { background: #f4f5f8; color: #6b6c72; border-color: #e0e1e6; }
        
        .role-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; background: #f4f5f8; color: var(--curdun-dark); border: 1px solid #e0e1e6; }
        .staff-level-badge { background: #fff3e0; color: #e65100; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-block; }

        .action-buttons { display: flex; gap: 10px; }
        .action-btn { background: none; border: none; color: var(--curdun-info); cursor: pointer; font-size: 16px; transition: color 0.2s; }
        .action-btn:hover { color: var(--curdun-violet); }
        .btn-delete { color: var(--curdun-danger); }
        .btn-delete:hover { color: #a51d14; }

        .alert { padding: 12px 20px; border-radius: 8px; position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .alert-success { background: #EEFBF3; color: #0F7A3A; border-left: 4px solid #0F7A3A; }
        .alert-error { background: #FEF0EE; color: #B42318; border-left: 4px solid #B42318; }

        .empty-state { text-align: center; padding: 50px; color: var(--curdun-gray); }
        .empty-state i { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }

        .modal-header { background: #f4f5f8; color: var(--curdun-dark); border-bottom: 1px solid #e0e1e6; }
        .modal-title { font-weight: 700; }
        .modal-header .close { color: var(--curdun-dark); }

        .loading-spinner { text-align: center; padding: 50px; }
        .loading-spinner i { font-size: 48px; color: var(--curdun-violet); animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        .info-box { background: #f4f5f8; border-left: 3px solid var(--curdun-violet); padding: 10px 15px; border-radius: 4px; margin-bottom: 15px; font-size: 13px; }
        .info-box i { color: var(--curdun-violet); margin-right: 8px; }

        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 25px; padding-bottom: 30px; }
        .pagination a, .pagination span { padding: 8px 16px; border-radius: 4px; text-decoration: none; color: var(--curdun-dark); background: white; border: 1px solid #babec5; cursor: pointer; }
        .pagination .active { background: var(--curdun-violet); color: white; border-color: var(--curdun-violet); }
        .pagination a:hover { background: var(--curdun-violet-light); color: white; transform: translateY(-2px); }

        .image-preview {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--curdun-violet);
            margin-top: 5px;
        }

        .users-table-container::-webkit-scrollbar { height: 8px; }
        .users-table-container::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .users-table-container::-webkit-scrollbar-thumb { background: var(--curdun-violet); border-radius: 10px; }

        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: center; }
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            .action-buttons { flex-direction: column; }
            .alert { left: 20px; right: 20px; min-width: auto; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-users"></i> Maareynta Isticmaaleysaasha</h1>
        <div class="d-flex gap-3 align-items-center">
            <button type="button" class="btn-primary-custom" id="addUserBtn">
                <i class="fas fa-user-plus"></i> Isticmaale Cusub
            </button>
            <div class="dropdown">
                <button class="btn btn-light dropdown-toggle" type="button" data-toggle="dropdown" style="border-radius: 20px; padding: 10px 15px; font-weight: 600; border: 1px solid #babec5;">
                    <i class="fas fa-file-csv"></i> CSV
                </button>
                <div class="dropdown-menu dropdown-menu-right" style="border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.1);">
                    <a class="dropdown-item" href="?action=export_users" id="exportUsersBtn"><i class="fas fa-download mr-2"></i> Export Users</a>
                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#importModal"><i class="fas fa-upload mr-2"></i> Import Users</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="?action=download_sample"><i class="fas fa-file-download mr-2"></i> Download Sample</a>
                </div>
            </div>
        </div>
    </div>
    <div class="stats-grid">
        <div class="stat-card-sm">
            <div class="stat-info"><h4>Wadarta Isticmaaleysaasha</h4><div class="stat-number" id="stat-total">0</div></div>
            <div class="stat-icon"><i class="fas fa-users"></i></div>
        </div>
        <div class="stat-card-sm">
            <div class="stat-info"><h4>Maamulayaasha Shirkadaha</h4><div class="stat-number" id="stat-company-admin">0</div></div>
            <div class="stat-icon"><i class="fas fa-building"></i></div>
        </div>
        <div class="stat-card-sm">
            <div class="stat-info"><h4>Shaqaalaha</h4><div class="stat-number" id="stat-staff">0</div></div>
            <div class="stat-icon"><i class="fas fa-briefcase"></i></div>
        </div>
        <div class="stat-card-sm">
            <div class="stat-info"><h4>Macaamiisha</h4><div class="stat-number" id="stat-customer">0</div></div>
            <div class="stat-icon"><i class="fas fa-user-friends"></i></div>
        </div>
    </div>

    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group"><label><i class="fas fa-search"></i> Raadin</label><input type="text" id="searchInput" placeholder="Magaca, Emailka, Telefoonka..."></div>
            <div class="filter-group"><label><i class="fas fa-user-tag"></i> Doorka</label><select id="roleFilter"><option value="">Dhammaan</option><option value="company_admin">Maamulaha Shirkadda</option><option value="staff">Shaqaale</option><option value="customer">Macaamil</option></select></div>
            <?php if ($role === 'superadmin'): ?>
            <div class="filter-group"><label><i class="fas fa-building"></i> Shirkadda</label><select id="tenantFilter"><option value="">Dhammaan</option><?php foreach ($tenants as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div class="filter-group"><label><i class="fas fa-circle"></i> Xaaladda</label><select id="statusFilter"><option value="">Dhammaan</option><option value="active">Firfircoon</option><option value="inactive">Aan Firfircooneyn</option></select></div>
            <div class="filter-group"><button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Shaandheey</button><button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Nadiifi</button></div>
        </div>
    </div>

    <div id="users-table-container"><div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i><p>Loading users...</p></div></div>
    <div id="pagination-container"></div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 8px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-import"></i> Soo geli Isticmaale (CSV)</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="importForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i> Fadlan soo geli faylka CSV oo kaliya. 
                        <a href="?action=download_sample" class="alert-link">Halkan ka soo deji sample-ka</a>.
                    </div>
                    <div class="form-group">
                        <label>Dooro Faylka (CSV)</label>
                        <input type="file" name="excel_file" class="form-control" accept=".csv" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button>
                    <button type="submit" class="btn" style="background: var(--curdun-violet); color: white;">Soo geli (Import)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalLabel">Isticmaale Cusub</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="userForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="user_id">
                    <input type="hidden" name="existing_profile_image" id="existing_profile_image">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Magaca buuxa *</label>
                                <input type="text" name="full_name" id="modalFullName" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Emailka *</label>
                                <input type="email" name="email" id="modalEmail" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Telefoonka</label>
                                <input type="text" name="phone" id="modalPhone" class="form-control">
                            </div>
                        </div>
                        
                        <!-- Password Field - Hidden for Create, Shown for Edit -->
                        <div class="col-md-6" id="password_field" style="display: none;">
                            <div class="form-group">
                                <label>Password</label>
                                <input type="password" name="password" id="modalPassword" class="form-control" placeholder="Ka tag haddii aadan rabin inaad beddesho">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> 
                                    Haddii aad beddesho, password-ga cusub wuxuu noqonayaa waxaad geliso
                                </small>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Doorka</label>
                                <select name="role_type" id="modalRole" class="form-control">
                                    <option value="company_admin">Maamulaha Shirkadda</option>
                                    <option value="staff">Shaqaale</option>
                                    <option value="customer">Macaamil</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Shirkadda</label>
                                <select name="tenant_id" id="modalTenantId" class="form-control" <?= $role === 'company_admin' ? 'disabled' : '' ?>>
                                    <?php if ($role === 'superadmin'): ?>
                                    <option value="">Dooro Shirkad...</option>
                                    <?php foreach ($tenants as $t): ?>
                                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                    <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="<?= $session_tenant_id ?>" selected>Shirkaddaada</option>
                                    <?php endif; ?>
                                </select>
                                <?php if ($role === 'company_admin'): ?>
                                    <input type="hidden" name="tenant_id" value="<?= $session_tenant_id ?>">
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Xaaladda</label>
                                <select name="is_active" id="modalIsActive" class="form-control">
                                    <option value="1">Firfircoon</option>
                                    <option value="0">Aan Firfircooneyn</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Staff Level - Hidden for Customer role -->
                        <div class="col-md-6" id="staff_level_field">
                            <div class="form-group">
                                <label>Staff Level</label>
                                <select name="staff_level" id="modalStaffLevel" class="form-control">
                                    <option value="">Dooro...</option>
                                    <option value="general_manager">Maamulaha Guud</option>
                                    <option value="operations_manager">Maamulaha Hawlaha</option>
                                    <option value="finance_manager">Maamulaha Maaliyadda</option>
                                    <option value="logistics_supervisor">Kormeere Saadka</option>
                                    <option value="warehouse_supervisor">Kormeere Bakhaarka</option>
                                    <option value="dispatcher">Dirayaasha</option>
                                    <option value="loader_supervisor">Kormeere Rarka</option>
                                    <option value="trainer">Tababare</option>
                                    <option value="senior_driver">Darawal Sare</option>
                                    <option value="driver">Darawal</option>
                                    <option value="junior_driver">Darawal Cusub</option>
                                    <option value="loader">Raraha</option>
                                    <option value="clerk">Assistant Worker</option>
                                    <option value="customer_service">Adeegga Macaamiisha</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Sawirka Profile-ka</label>
                                <input type="file" name="profile_image" id="profile_image" class="form-control" accept="image/*">
                                <small class="text-muted">JPG, PNG, GIF, WEBP (Max 2MB)</small>
                                <div id="profilePreview"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white"><h5 class="modal-title">Tirtir Isticmaale</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <div class="modal-body">Ma hubtaa inaad tirtirto <strong id="deleteUserName"></strong>?<br><br><span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Digniin: Tirtirista waa joogto!</span></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" id="confirmDeleteBtn">Tirtir</button></div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
// [csrf-shim] inline jQuery pages need the same ajaxSetup guard that
// includes/footer.php installs. Attach X-CSRF-Token to every same-origin
// mutation from this page.
(function () {
    var m = document.querySelector('meta[name="csrf-token"]');
    if (!m || !window.jQuery) return;
    var token = m.getAttribute('content') || '';
    jQuery.ajaxSetup({
        beforeSend: function (xhr, settings) {
            var method = (settings.type || 'GET').toUpperCase();
            if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') return;
            if (settings.crossDomain) return;
            xhr.setRequestHeader('X-CSRF-Token', token);
            if (settings.data instanceof FormData && !settings.data.has('csrf_token')) {
                settings.data.append('csrf_token', token);
            }
        }
    });

// [async-error-shim] Standardize AJAX failure handling so every finance
// page shows a controlled error instead of a permanent spinner. This
// runs after the jQuery.ajaxSetup shim above, so both live on the same
// jQuery instance.
(function () {
    if (!window.jQuery) return;
    if (window.__FIN_ASYNC_SHIM__) return;
    window.__FIN_ASYNC_SHIM__ = true;
    // Install an ajaxSend handler that marks the click-source button
    // with data-finance-pending. Fires once per shim install.
    if (!window.__FIN_SEND_MARK__) {
        window.__FIN_SEND_MARK__ = true;
        jQuery(document).on('ajaxSend', function (event, xhr, settings) {
            try {
                if (!settings || settings.crossDomain) return;
                var el = document.activeElement;
                if (!el) return;
                var tag = (el.tagName || '').toUpperCase();
                if (tag !== 'BUTTON' && !(tag === 'INPUT' && (el.type || '').toLowerCase() === 'submit')) return;
                var $el = jQuery(el);
                // If it isn't disabled at the moment ajax fires, the
                // caller isn't gating this button on the request, so
                // don't mark it.
                if (!$el.prop('disabled')) return;
                if ($el.attr('data-finance-pending') === '1') return;
                if ($el.attr('data-original-html') === undefined) {
                    $el.attr('data-original-html', $el.html());
                }
                $el.attr('data-finance-pending', '1');
            } catch (e) {}
        });
    }
    jQuery(document).ajaxError(function (event, xhr, settings, thrownError) {
        // Skip cross-domain or explicitly-suppressed calls.
        if (settings && settings.crossDomain) return;
        if (settings && settings.suppressGlobalError) return;
        var msg;
        try {
            var body = xhr && xhr.responseText ? xhr.responseText : '';
            var parsed = null;
            try { parsed = body ? JSON.parse(body) : null; } catch (e) {}
            if (parsed && parsed.message) msg = parsed.message;
            else if (xhr && xhr.status === 0) msg = 'Network error — request could not complete';
            else if (xhr && xhr.status === 403) msg = 'Not authorized (403)';
            else if (xhr && xhr.status === 404) msg = 'Endpoint not found (404)';
            else if (xhr && xhr.status >= 500) msg = 'Server error (' + xhr.status + ')';
            else msg = 'Request failed' + (xhr && xhr.status ? ' (' + xhr.status + ')' : '');
        } catch (e) {
            msg = 'Request failed';
        }
        // Try Bootstrap toast first if present; fall back to alert.
        try {
            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.toast) {
                var $c = jQuery('#toast-container');
                if (!$c.length) {
                    $c = jQuery('<div id="toast-container" style="position:fixed;top:20px;right:20px;z-index:99999;"></div>').appendTo('body');
                }
                var $t = jQuery('<div class="alert alert-danger" role="alert" style="min-width:280px;box-shadow:0 2px 8px rgba(0,0,0,.15);">' + jQuery('<div/>').text(msg).html() + '</div>');
                $c.append($t);
                setTimeout(function () { $t.fadeOut(400, function(){ jQuery(this).remove(); }); }, 5000);
                return;
            }
        } catch (e) {}
                // [async-error-shim-v3] Targeted UI-state recovery. Only restores
        // buttons that were explicitly marked at ajaxSend time with
        // data-finance-pending="1" and whose original HTML was captured
        // in data-original-html. Never touches other disabled controls —
        // tenant-validation locks, RBAC locks, workflow gates, and
        // missing-required-selection blockers all stay locked as
        // intended.
        try {
            jQuery('[data-finance-pending="1"]').each(function () {
                var $b = jQuery(this);
                var orig = $b.attr('data-original-html');
                if (orig !== undefined && orig !== null) $b.html(orig);
                $b.prop('disabled', false);
                $b.removeAttr('data-finance-pending');
                $b.removeAttr('data-original-html');
            });
        } catch (e) {}
        // Only alert once per 5-second window to prevent alert-storms.
        if (!window.__FIN_ALERT_LOCK__) {
            window.__FIN_ALERT_LOCK__ = true;
            try { window.alert(msg); } catch (e) {}
            setTimeout(function () { window.__FIN_ALERT_LOCK__ = false; }, 5000);
        }
    });
})();
})();
</script>
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
                status: $('#statusFilter').val(),
                tenant: $('#tenantFilter').val()
            },
            dataType: 'json',
            success: function(response) {
                $('#users-table-container').html(response.table_html);
                $('#pagination-container').html(response.pagination_html);
                attachTableEvents();
                
                // Update export link with current filters
                let search = $('#searchInput').val();
                let role = $('#roleFilter').val();
                let tenant = $('#tenantFilter').val();
                $('#exportUsersBtn').attr('href', `?action=export_users&search=${encodeURIComponent(search)}&role=${role}&tenant=${tenant}`);
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
                    $('#userModalLabel').text('Wax ka beddel Isticmaale');
                    $('#user_id').val(user.id);
                    $('#modalFullName').val(user.full_name);
                    $('#modalEmail').val(user.email);
                    $('#modalPhone').val(user.phone);
                    $('#modalRole').val(user.role_type);
                    $('#modalTenantId').val(user.tenant_id);
                    $('#modalIsActive').val(user.is_active);
                    $('#modalStaffLevel').val(user.staff_level);
                    $('#existing_profile_image').val(user.profile_image || '');
                    
                    // Show password field for edit
                    $('#password_field').show();
                    $('#modalPassword').val('');
                    $('#modalPassword').prop('required', false);
                    
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
            if (confirm('Ma hubtaa inaad beddesho xaaladda isticmaalahan?')) {
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
        $('#alert-placeholder').html(`<div class="alert alert-${type} alert-dismissible fade show"><i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
        setTimeout(() => $('.alert').fadeOut(5000, function() { $(this).remove(); }), 5000);
    }

    $('#userForm').submit(function(e) {
        e.preventDefault();
        
        if (!$('#modalFullName').val()) { showAlert('error', 'Fadlan geli Magaca buuxa'); return; }
        if (!$('#modalEmail').val()) { showAlert('error', 'Fadlan geli Emailka'); return; }
        
        const formData = new FormData(this);
        formData.append('ajax_action', 'save_user');
        
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
                    // Hide password field for new user
                    $('#password_field').hide();
                    // Reset staff level visibility
                    toggleStaffLevel();
                } else { showAlert('error', res.message); }
            },
            error: function() { showAlert('error', 'Khalad ayaa dhacay'); }
        });
    });

    $('#importForm').submit(function(e) {
        e.preventDefault();
        let formData = new FormData(this);
        formData.append('ajax_action', 'import_users');
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#importModal').modal('hide');
                    loadUsers();
                    loadStats();
                    showAlert('success', res.message);
                    $('#importForm')[0].reset();
                } else {
                    showAlert('error', res.message);
                }
            },
            error: function() {
                showAlert('error', 'Khalad ayaa dhacay intii lagu guda jiray soo gelinta.');
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
                    } else { showAlert('error', res.message); }
                    deleteId = null;
                }
            });
        }
    });

    $('#addUserBtn').click(function() {
        $('#userModalLabel').text('Isticmaale Cusub');
        $('#userForm')[0].reset();
        $('#user_id').val('');
        // Hide password field for new user (auto 123)
        $('#password_field').hide();
        $('#modalPassword').val('');
        $('#modalIsActive').val(1);
        $('#profilePreview').empty();
        // Set default role and toggle staff level
        $('#modalRole').val('customer');
        toggleStaffLevel();
        $('#userModal').modal('show');
    });

    $('#applyFilters').click(function() { currentPage = 1; loadUsers(); });
    $('#resetFilters').click(function() { $('#searchInput').val(''); $('#roleFilter').val(''); $('#tenantFilter').val(''); $('#statusFilter').val(''); currentPage = 1; loadUsers(); });
    $('#searchInput').keypress(function(e) { if (e.which === 13) { currentPage = 1; loadUsers(); } });

    // Initialize
    toggleStaffLevel();
    loadUsers();
    loadStats();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
