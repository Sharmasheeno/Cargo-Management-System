<?php
// superadmin/customer.php
// Customer Management forfaras cargo - Super Admin

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

// CSRF: every POST to this handler must carry a valid session token.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
}

require_once __DIR__ . '/../includes/MessagingService.php';
$messaging = new MessagingService($pdo);

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

// Handle AJAX and Download requests
$action = $_REQUEST['ajax_action'] ?? '';
if ($action) {
    // These actions return files, not JSON
    if ($action !== 'export_customers' && $action !== 'download_sample') {
        header('Content-Type: application/json');
    }
    
    if ($action === 'get_customers') {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $search = $_POST['search'] ?? '';
        $status_filter = $_POST['status'] ?? '';
        $tenant_filter = ($role === 'superadmin') ? (isset($_POST['tenant']) ? (int)$_POST['tenant'] : sa_selected_tenant_id_int()) : $session_tenant_id;
        $debt_filter = $_POST['debt_filter'] ?? '';
        
        $where_conditions = ["1=1"];
        $params = [];
        
        if (!empty($search)) {
            $where_conditions[] = "(c.customer_name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($status_filter !== '') {
            $where_conditions[] = "c.is_active = ?";
            $params[] = $status_filter == 'active' ? 1 : 0;
        }
        
        if ($tenant_filter > 0) {
            $where_conditions[] = "c.tenant_id = ?";
            $params[] = $tenant_filter;
        } elseif ($role === 'company_admin') {
            $where_conditions[] = "c.tenant_id = ?";
            $params[] = $session_tenant_id;
        }
        
        if ($debt_filter === 'has_debt') {
            $where_conditions[] = "c.debt_amount > 0";
        } elseif ($debt_filter === 'no_debt') {
            $where_conditions[] = "c.debt_amount = 0";
        } elseif ($debt_filter === 'high_debt') {
            $where_conditions[] = "c.debt_amount > c.credit_limit";
        }
        
        $where_clause = implode(" AND ", $where_conditions);
        
        // Get total count
        $count_sql = "SELECT COUNT(*) as total FROM customers c WHERE $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_customers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_customers / $limit);
        
        // Get customers with tenant info and user info
        $sql = "
            SELECT 
                c.*, 
                t.name as tenant_name,
                u.email as user_email,
                u.full_name as user_full_name,
                u.id as user_account_id
            FROM customers c
            LEFT JOIN tenants t ON c.tenant_id = t.id
            LEFT JOIN users u ON c.user_id = u.id
            WHERE $where_clause
            ORDER BY c.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate table HTML
        ob_start(); ?>
        <div style="overflow-x: auto; width: 100%;">
            <table class="customers-table" style="min-width: 1500px; width: 100%;">
                <thead>
                    <tr style="border-top: 1px solid #eee;">
                        <th style="width: 40px; text-align: center;"><input type="checkbox" id="selectAll"></th>
                        <th style="min-width: 200px;">Business Name <i class="fas fa-caret-down ml-1" style="font-size: 10px;"></i></th>
                        <th style="min-width: 200px;">Name <i class="fas fa-caret-down ml-1" style="font-size: 10px;"></i></th>
                        <th style="min-width: 150px;">Mobile <i class="fas fa-caret-down ml-1" style="font-size: 10px;"></i></th>
                        <th style="min-width: 150px;">GSTN</th>
                        <th style="min-width: 130px; text-align: right;">Receivables <i class="fas fa-caret-down ml-1" style="font-size: 10px;"></i></th>
                        <th style="min-width: 130px; text-align: right;">Payables <i class="fas fa-caret-down ml-1" style="font-size: 10px;"></i></th>
                        <th style="min-width: 120px; text-align: center;">Points <i class="fas fa-star" style="color: #ff9800;"></i></th>
                        <th style="width: 100px; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($customers) > 0): ?>
                        <?php foreach ($customers as $customer): ?>
                            <tr class="<?= $customer['debt_amount'] > 0 ? 'has-debt' : '' ?>">
                                <td style="text-align: center;"><input type="checkbox" class="customer-checkbox" value="<?= $customer['id'] ?>"></td>
                                <td>
                                    <a href="#" class="view-history" data-id="<?= $customer['id'] ?>" data-name="<?= htmlspecialchars($customer['customer_name']) ?>" style="color: #0077c5; font-weight: 500; text-decoration: none;">
                                        <?= htmlspecialchars($customer['customer_name']) ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($customer['customer_name']) ?></td>
                                <td><?= htmlspecialchars($customer['phone'] ?: '') ?></td>
                                <td><?= htmlspecialchars($customer['address'] ?: '') ?></td>
                                <td style="text-align: right; font-weight: 600;">
                                    <?php 
                                        $debt = (float)$customer['debt_amount'];
                                        echo $debt > 0 ? number_format($debt, 2) : '0.00';
                                    ?>
                                </td>
                                <td style="text-align: right; font-weight: 600;">
                                    <?php 
                                        echo $debt < 0 ? number_format(abs($debt), 2) : '0.00';
                                    ?>
                                </td>
                                <td style="text-align: center;">
                                    <span class="points-badge" style="cursor: pointer;" onclick="showPointsHistory(<?= $customer['id'] ?>, '<?= htmlspecialchars($customer['customer_name']) ?>')">
                                        <i class="fas fa-star" style="color: #ff9800;"></i>
                                        <?= number_format((float)$customer['loyalty_points'], 0) ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <div style="display: flex; justify-content: center; gap: 15px; align-items: center;">
                                        <i class="fas fa-edit edit-customer" data-id="<?= $customer['id'] ?>" style="color: #4e73df; cursor: pointer; font-size: 16px;" title="Edit"></i>
                                        
                                        <div class="dropdown">
                                            <i class="fas fa-ellipsis-v dropdown-toggle" id="dropdownMenu<?= $customer['id'] ?>" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="color: #999; cursor: pointer; padding: 10px; font-size: 18px;" title="More Actions"></i>
                                            <div class="dropdown-menu dropdown-menu-right dropdown-menu-custom" aria-labelledby="dropdownMenu<?= $customer['id'] ?>">
                                                <a class="dropdown-item-custom redeem-points" data-id="<?= $customer['id'] ?>" data-name="<?= htmlspecialchars($customer['customer_name']) ?>" data-points="<?= (float)$customer['loyalty_points'] ?>">
                                                    <i class="fas fa-ticket-alt text-warning"></i> Redeem Points
                                                </a>
                                                <a class="dropdown-item-custom points-history" data-id="<?= $customer['id'] ?>" data-name="<?= htmlspecialchars($customer['customer_name']) ?>">
                                                    <i class="fas fa-chart-line text-info"></i> Points History
                                                </a>
                                                <div class="dropdown-divider"></div>
                                                <a class="dropdown-item-custom view-history" data-id="<?= $customer['id'] ?>" data-name="<?= htmlspecialchars($customer['customer_name']) ?>">
                                                    <i class="fas fa-eye text-primary"></i> View Details
                                                </a>
                                                <a class="dropdown-item-custom whatsapp-customer" data-phone="<?= htmlspecialchars($customer['phone']) ?>" data-name="<?= htmlspecialchars($customer['customer_name']) ?>">
                                                    <i class="fab fa-whatsapp text-success"></i> WhatsApp
                                                </a>
                                                <a class="dropdown-item-custom print-statement" data-id="<?= $customer['id'] ?>" data-name="<?= htmlspecialchars($customer['customer_name']) ?>">
                                                    <i class="fas fa-print text-info"></i> Print Statement
                                                </a>
                                                <a class="dropdown-item-custom download-statement" data-id="<?= $customer['id'] ?>" data-name="<?= htmlspecialchars($customer['customer_name']) ?>">
                                                    <i class="fas fa-download text-secondary"></i> Download
                                                </a>
                                                <a class="dropdown-item-custom toggle-status" data-id="<?= $customer['id'] ?>">
                                                    <i class="fas fa-undo text-warning"></i> Undo / Toggle
                                                </a>
                                                <div class="dropdown-divider"></div>
                                                <a class="dropdown-item-custom text-danger delete-customer" data-id="<?= $customer['id'] ?>" data-name="<?= htmlspecialchars($customer['customer_name']) ?>">
                                                    <i class="fas fa-trash"></i> Delete
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="13" style="text-align: center; padding: 50px;">
                                <div class="empty-state">
                                    <i class="fas fa-users-slash"></i>
                                    <p>Ma jiraan macaamiil</p>
                                    <button class="btn-primary-custom" id="addCustomerBtnEmpty" style="margin-top: 10px;">
                                        <i class="fas fa-user-plus"></i> Ku dar Macaamil Cusub
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
    
    elseif ($action === 'get_customer') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($customer);
        exit;
    }
    
    elseif ($action === 'save_customer') {
        $id = $_POST['customer_id'] ?? '';
        $customer_name = trim($_POST['customer_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $tenant_id = !empty($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : null;
        $address = trim($_POST['address'] ?? '');
        $credit_limit = !empty($_POST['credit_limit']) ? (float)$_POST['credit_limit'] : 0;
        $payment_terms = !empty($_POST['payment_terms']) ? (int)$_POST['payment_terms'] : 30;
        $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
        
        if (empty($customer_name)) {
            echo json_encode(['success' => false, 'message' => 'Magaca macaamilka waa lagama maarmaan']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            if (empty($id)) {
                // CHECK: Email duplicate (haddii email la bixiyay)
                if (!empty($email)) {
                    $check_email = $pdo->prepare("SELECT id FROM customers WHERE email = ?");
                    $check_email->execute([$email]);
                    if ($check_email->fetch()) {
                        echo json_encode(['success' => false, 'message' => '❌ Emailkan waxaa horay loo isticmaalay! Fadlan isticmaal email kale.']);
                        exit;
                    }
                    
                    // CHECK: Email in users table (Global system email check)
                    $check_user_email = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $check_user_email->execute([$email]);
                    if ($check_user_email->fetch()) {
                        echo json_encode(['success' => false, 'message' => '❌ Emailkan waxaa horay loogu isticmaalay system-ka (User Account)! Fadlan isticmaal email kale.']);
                        exit;
                    }
                }
                
                // CHECK: Phone duplicate (haddii telefoon la bixiyay)
                if (!empty($phone)) {
                    $check_phone = $pdo->prepare("SELECT id FROM customers WHERE phone = ?");
                    $check_phone->execute([$phone]);
                    if ($check_phone->fetch()) {
                        echo json_encode(['success' => false, 'message' => '❌ Telefoonkan waxaa horay loo isticmaalay! Fadlan isticmaal telefoon kale.']);
                        exit;
                    }
                }
                
                // Insert into customers table
                $sql = "INSERT INTO customers (tenant_id, customer_name, phone, email, address, credit_limit, payment_terms, is_active, created_by, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$tenant_id, $customer_name, $phone, $email, $address, $credit_limit, $payment_terms, $is_active, $_SESSION['user_id']]);
                $new_customer_id = $pdo->lastInsertId();
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => "✅ Macaamil '$customer_name' waa la daray!", 'customer_id' => $new_customer_id]);
            } else {
                // CHECK: Email duplicate for update (excluding current customer)
                if (!empty($email)) {
                    $check_email = $pdo->prepare("SELECT id FROM customers WHERE email = ? AND id != ?");
                    $check_email->execute([$email, $id]);
                    if ($check_email->fetch()) {
                        echo json_encode(['success' => false, 'message' => '❌ Emailkan waxaa horay loo isticmaalay macaamil kale!']);
                        exit;
                    }
                    
                    // CHECK: Email in users table (Global system email check, exclude their own user account if they have one)
                    $check_user_email = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != (SELECT user_id FROM customers WHERE id = ?)");
                    $check_user_email->execute([$email, $id]);
                    if ($check_user_email->fetch()) {
                        echo json_encode(['success' => false, 'message' => '❌ Emailkan waxaa horay loogu isticmaalay system-ka (User Account)! Fadlan isticmaal email kale.']);
                        exit;
                    }
                }
                
                // CHECK: Phone duplicate for update (excluding current customer)
                if (!empty($phone)) {
                    $check_phone = $pdo->prepare("SELECT id FROM customers WHERE phone = ? AND id != ?");
                    $check_phone->execute([$phone, $id]);
                    if ($check_phone->fetch()) {
                        echo json_encode(['success' => false, 'message' => '❌ Telefoonkan waxaa horay loo isticmaalay macaamil kale!']);
                        exit;
                    }
                }
                
                // Update existing customer
                $sql = "UPDATE customers SET customer_name=?, phone=?, email=?, address=?, credit_limit=?, payment_terms=?, is_active=?, tenant_id=? WHERE id=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$customer_name, $phone, $email, $address, $credit_limit, $payment_terms, $is_active, $tenant_id, $id]);
                
                // Also update the linked user account if exists
                $check_user = $pdo->prepare("SELECT id FROM users WHERE id = (SELECT user_id FROM customers WHERE id = ?)");
                $check_user->execute([$id]);
                $user_account = $check_user->fetch();
                
                if ($user_account) {
                    $update_user = $pdo->prepare("UPDATE users SET full_name=?, email=?, phone=? WHERE id=?");
                    $update_user->execute([$customer_name, $email, $phone, $user_account['id']]);
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => "✅ Macaamil '$customer_name' waa la cusboonaysiiyay!"]);
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'create_user_account') {
        $customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        
        try {
            // Get customer details
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $stmt->execute([$customer_id]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$customer) {
                echo json_encode(['success' => false, 'message' => 'Macaamil lama helin!']);
                exit;
            }
            
            // Check if user account already exists
            if ($customer['user_id']) {
                echo json_encode(['success' => false, 'message' => 'Macaamilkan wuxuu horey u leeyahay akoon user!']);
                exit;
            }
            
            // Check if email is provided
            if (empty($customer['email'])) {
                echo json_encode(['success' => false, 'message' => '❌ Macaamilku ma lahan email! Fadlan ku dar email ka hor inta aadan u samayn Akoon User.']);
                exit;
            }
            
            // Check if email already exists in users table
            $check_email = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check_email->execute([$customer['email']]);
            if ($check_email->fetch()) {
                echo json_encode(['success' => false, 'message' => '❌ Emailkan waxaa horay loogu isticmaalay user kale! Fadlan isticmaal email kale.']);
                exit;
            }
            
            // Check if phone already exists in users table
            if (!empty($customer['phone'])) {
                $check_phone = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
                $check_phone->execute([$customer['phone']]);
                if ($check_phone->fetch()) {
                    echo json_encode(['success' => false, 'message' => '❌ Telefoonkan waxaa horay loogu isticmaalay user kale! Fadlan isticmaal telefoon kale.']);
                    exit;
                }
            }
            
            // Create user account with default password 123
            $default_password = '123';
            $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);
            
            $sql = "INSERT INTO users (tenant_id, email, password_hash, full_name, phone, role_type, is_active, created_by, created_at) 
                    VALUES (?, ?, ?, ?, ?, 'customer', ?, ?, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $customer['tenant_id'],
                $customer['email'],
                $hashed_password,
                $customer['customer_name'],
                $customer['phone'],
                $customer['is_active'],
                $_SESSION['user_id']
            ]);
            
            $new_user_id = $pdo->lastInsertId();
            
            // Update customer with user_id
            $update = $pdo->prepare("UPDATE customers SET user_id = ? WHERE id = ?");
            $update->execute([$new_user_id, $customer_id]);
            
            echo json_encode([
                'success' => true, 
                'message' => "✅ Akoon user waa loo sameeyay macaamil '{$customer['customer_name']}'!<br>🔑 Password: <strong class='text-success'>123</strong>",
                'user_id' => $new_user_id
            ]);
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'delete_customer') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        
        try {
            $pdo->beginTransaction();
            
            // Get customer details
            $stmt = $pdo->prepare("SELECT user_id FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if customer has invoices
            $check = $pdo->prepare("SELECT COUNT(*) as count FROM invoices WHERE customer_id = ?");
            $check->execute([$id]);
            $invoice_count = $check->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($invoice_count > 0) {
                echo json_encode(['success' => false, 'message' => 'Macaamilkan wuxuu leeyahay biilal, marka hore tirtir biilasha!']);
                exit;
            }
            
            // Delete point redemptions
            $delete_redemptions = $pdo->prepare("DELETE FROM point_redemptions WHERE customer_id = ?");
            $delete_redemptions->execute([$id]);
            
            // Delete loyalty points log
            $delete_log = $pdo->prepare("DELETE FROM loyalty_points_log WHERE customer_id = ?");
            $delete_log->execute([$id]);
            
            // Delete linked user account if exists
            if ($customer['user_id']) {
                $delete_user = $pdo->prepare("DELETE FROM users WHERE id = ? AND role_type = 'customer'");
                $delete_user->execute([$customer['user_id']]);
            }
            
            // Delete from customers table
            $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Macaamil iyo akoonkiisa waa la tirtiray!']);
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
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT is_active, customer_name, user_id FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$customer) {
                echo json_encode(['success' => false, 'message' => 'Macaamil lama helin']);
                exit;
            }
            
            $new_status = $customer['is_active'] ? 0 : 1;
            $stmt = $pdo->prepare("UPDATE customers SET is_active = ? WHERE id = ?");
            $stmt->execute([$new_status, $id]);
            
            // Also update linked user account status
            if ($customer['user_id']) {
                $update_user = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                $update_user->execute([$new_status, $customer['user_id']]);
            }
            
            $pdo->commit();
            
            if ($new_status) {
                $message = "✅ Macaamil '{$customer['customer_name']}' hadda waa FIRFIRCOON!";
            } else {
                $message = "⚠️ Macaamil '{$customer['customer_name']}' hadda waa AAN FIRFIRCOONEYN!";
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
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
                SUM(debt_amount) as total_debt,
                SUM(total_spent) as total_spent,
                SUM(loyalty_points) as total_points,
                SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END) as has_user_account
            FROM customers
        ");
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit;
    }
    
    elseif ($action === 'get_statement') {
        $customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        
        // Get customer info
        $stmt = $pdo->prepare("SELECT customer_name, debt_amount FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get Invoices
        $stmt = $pdo->prepare("SELECT 'Invoice' as type, invoice_number as ref, invoice_date as date, total_amount as debit, 0 as credit, status FROM invoices WHERE customer_id = ?");
        $stmt->execute([$customer_id]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get Receipts (Payments) - Fixed receipt_date to payment_date
        $stmt = $pdo->prepare("SELECT 'Payment' as type, receipt_number as ref, payment_date as date, 0 as debit, amount as credit, 'Paid' as status FROM receipts WHERE customer_id = ?");
        $stmt->execute([$customer_id]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Merge and Sort
        $transactions = array_merge($invoices, $payments);
        usort($transactions, function($a, $b) {
            $dateA = strtotime($a['date'] ?? '0');
            $dateB = strtotime($b['date'] ?? '0');
            return $dateB - $dateA;
        });
        
        echo json_encode([
            'success' => true,
            'customer' => $customer,
            'transactions' => $transactions
        ]);
        exit;
    }

    elseif ($action === 'get_customer_history') {
        $id = (int)($_POST['id'] ?? 0);
        
        // Get Invoices
        $stmt = $pdo->prepare("SELECT 'invoice' as type, invoice_number as ref, invoice_date as date, total_amount as amount, status FROM invoices WHERE customer_id = ?");
        $stmt->execute([$id]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get Receipts
        $stmt = $pdo->prepare("SELECT 'payment' as type, receipt_number as ref, payment_date as date, amount, 'Paid' as status FROM receipts WHERE customer_id = ?");
        $stmt->execute([$id]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $history = array_merge($invoices, $payments);
        usort($history, function($a, $b) {
            return strtotime($b['date'] ?? '0') - strtotime($a['date'] ?? '0');
        });
        
        echo json_encode($history);
        exit;
    }
    
    // ============ POINT REDEMPTION ACTIONS ============
    
    elseif ($action === 'get_points_history') {
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        
        // Get points earned and redeemed
        $stmt = $pdo->prepare("
            SELECT 
                'earned' as transaction_type,
                points_earned as points,
                points_redeemed as points_redeemed_field,
                reason,
                reference_type,
                reference_id,
                created_at
            FROM loyalty_points_log 
            WHERE customer_id = ?
            UNION ALL
            SELECT 
                'redeemed' as transaction_type,
                points_used as points,
                0 as points_redeemed_field,
                CONCAT('Redeemed for ', redemption_type, ' - ', notes) as reason,
                reference_type,
                reference_id,
                created_at
            FROM point_redemptions 
            WHERE customer_id = ?
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$customer_id, $customer_id]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($history);
        exit;
    }
    
    elseif ($action === 'get_redemptions') {
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        
        $stmt = $pdo->prepare("
            SELECT 
                pr.*,
                u.full_name as approved_by_name,
                u2.full_name as created_by_name
            FROM point_redemptions pr
            LEFT JOIN users u ON pr.approved_by = u.id
            LEFT JOIN users u2 ON pr.created_by = u2.id
            WHERE pr.customer_id = ?
            ORDER BY pr.created_at DESC
        ");
        $stmt->execute([$customer_id]);
        $redemptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($redemptions);
        exit;
    }
    
    elseif ($action === 'redeem_points') {
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $points_to_redeem = (float)($_POST['points_to_redeem'] ?? 0);
        $redemption_type = $_POST['redemption_type'] ?? 'discount';
        $notes = $_POST['notes'] ?? '';
        
        if ($points_to_redeem <= 0) {
            echo json_encode(['success' => false, 'message' => 'Fadlan geli tirada dhibcaha aad rabto inaad isticmaasho!']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Get customer current points
            $stmt = $pdo->prepare("SELECT loyalty_points, tenant_id, customer_name FROM customers WHERE id = ?");
            $stmt->execute([$customer_id]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$customer) {
                echo json_encode(['success' => false, 'message' => 'Macaamil lama helin!']);
                exit;
            }
            
            if ($customer['loyalty_points'] < $points_to_redeem) {
                echo json_encode(['success' => false, 'message' => 'Dhibcaha aad isticmaali rabto way ka badan yihiin dhibcaha aad haysato!']);
                exit;
            }
            
            // Get point value from tenant settings
            $stmt = $pdo->prepare("SELECT point_money_value FROM tenants WHERE id = ?");
            $stmt->execute([$customer['tenant_id']]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
            $point_value = $tenant ? (float)$tenant['point_money_value'] : 0.10; // Default 0.10 = 10 cents per point
            
            $discount_amount = $points_to_redeem * $point_value;
            $redemption_code = 'RDM-' . strtoupper(uniqid());
            
            // Insert redemption record
            $stmt = $pdo->prepare("
                INSERT INTO point_redemptions 
                (tenant_id, customer_id, redemption_code, points_used, discount_amount, redemption_type, status, notes, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'approved', ?, ?, NOW())
            ");
            $stmt->execute([
                $customer['tenant_id'],
                $customer_id,
                $redemption_code,
                $points_to_redeem,
                $discount_amount,
                $redemption_type,
                $notes,
                $_SESSION['user_id']
            ]);
            
            $redemption_id = $pdo->lastInsertId();
            
            // Update customer points
            $new_points = $customer['loyalty_points'] - $points_to_redeem;
            $update = $pdo->prepare("UPDATE customers SET loyalty_points = ? WHERE id = ?");
            $update->execute([$new_points, $customer_id]);
            
            // Add to loyalty points log
            $log = $pdo->prepare("
                INSERT INTO loyalty_points_log 
                (tenant_id, customer_id, points_redeemed, points_earned, reason, reference_type, reference_id, created_by, created_at) 
                VALUES (?, ?, ?, 0, ?, 'redemption', ?, ?, NOW())
            ");
            $log->execute([
                $customer['tenant_id'],
                $customer_id,
                $points_to_redeem,
                "Points redeemed for {$redemption_type} - {$notes}",
                $redemption_id,
                $_SESSION['user_id']
            ]);
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => "✅ Dhibcaha waa la isticmaalay!<br>💰 Discount: $" . number_format($discount_amount, 2) . "<br>📋 Code: {$redemption_code}<br>⭐ Points remaining: " . number_format($new_points, 0),
                'new_points' => $new_points,
                'discount_amount' => $discount_amount,
                'redemption_code' => $redemption_code
            ]);
            
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'update_redemption_status') {
        $redemption_id = (int)($_POST['redemption_id'] ?? 0);
        $new_status = $_POST['status'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        $allowed_statuses = ['pending', 'approved', 'used', 'cancelled', 'expired'];
        if (!in_array($new_status, $allowed_statuses)) {
            echo json_encode(['success' => false, 'message' => 'Xaalad aan sax ahayn!']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("
                UPDATE point_redemptions 
                SET status = ?, notes = CONCAT(COALESCE(notes, ''), ' | Updated to ', ?, ': ', ?), updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$new_status, $new_status, $notes, $redemption_id]);
            
            echo json_encode(['success' => true, 'message' => "Xaaladda redemption waa la beddelay: {$new_status}"]);
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'export_customers') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=customers_export_'.date('Y-m-d').'.csv');
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM for Excel compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['ID', 'Tenant Name', 'Name', 'Phone', 'Email', 'Address', 'Debt Amount', 'Credit Limit', 'Payment Terms', 'Loyalty Points', 'Status', 'Created At']);
        
        $search = $_GET['search'] ?? '';
        $status_filter = $_GET['status'] ?? '';
        $tenant_filter = isset($_GET['tenant']) ? (int)$_GET['tenant'] : 0;
        $debt_filter = $_GET['debt_filter'] ?? '';
        
        $where_conditions = ["1=1"];
        $params = [];
        
        if (!empty($search)) {
            $where_conditions[] = "(c.customer_name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($status_filter !== '') {
            $where_conditions[] = "c.is_active = ?";
            $params[] = $status_filter == 'active' ? 1 : 0;
        }
        
        if ($tenant_filter > 0) {
            $where_conditions[] = "c.tenant_id = ?";
            $params[] = $tenant_filter;
        }
        
        if ($debt_filter === 'has_debt') {
            $where_conditions[] = "c.debt_amount > 0";
        } elseif ($debt_filter === 'no_debt') {
            $where_conditions[] = "c.debt_amount = 0";
        }
        
        $where_clause = implode(" AND ", $where_conditions);
        
        $sql = "SELECT c.id, t.name as tenant_name, c.customer_name, c.phone, c.email, c.address, c.debt_amount, c.credit_limit, c.payment_terms, c.loyalty_points, c.is_active, c.created_at 
                FROM customers c 
                LEFT JOIN tenants t ON c.tenant_id = t.id 
                WHERE $where_clause 
                ORDER BY c.customer_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['is_active'] = $row['is_active'] ? 'Active' : 'Inactive';
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }
    
    elseif ($action === 'import_customers') {
        if (!isset($_FILES['excel_file'])) {
            echo json_encode(['success' => false, 'message' => 'Fayl lama soo dooran!']);
            exit;
        }
        
        $file = $_FILES['excel_file']['tmp_name'];
        $handle = fopen($file, "r");
        
        // Skip header
        $header = fgetcsv($handle);
        
        $imported = 0;
        $updated = 0;
        $errors = [];
        $line = 1;
        
        try {
            $pdo->beginTransaction();
            
            // Pre-fetch tenants for name mapping
            $stmt = $pdo->query("SELECT id, name FROM tenants");
            $tenants_map = [];
            while ($t = $stmt->fetch()) {
                $tenants_map[strtolower($t['name'])] = $t['id'];
            }
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $line++;
                // Columns: Tenant ID/Name, Customer Name, Phone, Email, Address, Initial Debt, Credit Limit, Payment Terms
                $tenant_val = trim($data[0] ?? '');
                $name = trim($data[1] ?? '');
                $phone = trim($data[2] ?? '');
                $email = trim($data[3] ?? '');
                $address = trim($data[4] ?? '');
                $debt = (float)(str_replace(['$', ','], '', $data[5] ?? 0));
                $credit_limit = (float)(str_replace(['$', ','], '', $data[6] ?? 0));
                $payment_terms = (int)($data[7] ?? 30);
                
                if (empty($name)) continue;
                
                // Determine Tenant ID
                $target_tenant_id = null;
                if (is_numeric($tenant_val)) {
                    $target_tenant_id = (int)$tenant_val;
                } elseif (!empty($tenant_val)) {
                    $target_tenant_id = $tenants_map[strtolower($tenant_val)] ?? null;
                }
                
                if (!$target_tenant_id) {
                    $errors[] = "Line $line: Shirkadda '$tenant_val' lama helin.";
                    continue;
                }
                
                // Check if exists
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE (customer_name = ? OR (phone != '' AND phone = ?)) AND tenant_id = ?");
                $stmt->execute([$name, $phone, $target_tenant_id]);
                $existing = $stmt->fetch();
                
                if ($existing) {
                    $sql = "UPDATE customers SET debt_amount = debt_amount + ?, phone = ?, email = ?, address = ?, credit_limit = ?, payment_terms = ? WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$debt, $phone, $email, $address, $credit_limit, $payment_terms, $existing['id']]);
                    $updated++;
                } else {
                    $sql = "INSERT INTO customers (tenant_id, customer_name, phone, email, address, debt_amount, credit_limit, payment_terms, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$target_tenant_id, $name, $phone, $email, $address, $debt, $credit_limit, $payment_terms, $_SESSION['user_id']]);
                    $imported++;
                }
            }
            
            if (count($errors) > 0 && $imported == 0 && $updated == 0) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Khalad: ' . implode("<br>", array_slice($errors, 0, 3))]);
            } else {
                $pdo->commit();
                $msg = "Import waa lagu guuleystay! (Cusub: $imported, La cusboonaysiiyay: $updated)";
                if (count($errors) > 0) $msg .= "<br>Digniin: " . count($errors) . " saf ayaan la soo gelin.";
                echo json_encode(['success' => true, 'message' => $msg]);
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        fclose($handle);
        exit;
    }
    
    elseif ($action === 'download_sample') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=customer_import_sample.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, ['Tenant ID or Name', 'Customer Name', 'Phone', 'Email', 'Address', 'Initial Debt', 'Credit Limit', 'Payment Terms']);
        
        $stmt = $pdo->query("SELECT id, name FROM tenants LIMIT 1");
        $t = $stmt->fetch();
        $t_name = $t ? $t['name'] : 'My Company';
        
        fputcsv($output, [$t_name, 'Ali Ahmed', '252615123456', 'ali@example.com', 'Hodan, Mogadishu', '500.00', '2000', '30']);
        fputcsv($output, [$t_name, 'Maryan Abdi', '252615654321', 'maryan@example.com', 'Boondheere, Mogadishu', '250.50', '1000', '15']);
        fclose($output);
        exit;
    }
    
    elseif ($action === 'send_sms') {
        $customer_id = $_POST['customer_id'] ?? 0;
        $phone = $_POST['phone'] ?? '';
        $message = $_POST['message'] ?? '';
        $type = $_POST['send_type'] ?? 'whatsapp';

        if (empty($phone) || empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Lambar iyo fariin waa loo baahan yahay!']);
            exit;
        }

        if ($type === 'whatsapp') {
            $result = $messaging->sendWhatsApp($phone, $message);
        } else {
            $result = $messaging->sendSMS($phone, $message);
        }

        echo json_encode($result);
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
    <title>Maareynta Macaamiisha - Super Admin | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root {
            --curdun-violet: #3f51b5;
            --curdun-yellow: #f8f9fc;
            --curdun-violet-light: #5c6bc0;
            --curdun-gray: #6b6c72;
            --curdun-dark: #333333;
            --curdun-info: #0077c5;
            --curdun-success: #2ca01c;
            --curdun-danger: #d52b1e;
            --curdun-warning: #ff9800;
            --table-header-bg: #f8f9fa;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }

        .page-header {
            background: transparent;
            padding: 10px 0;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .page-header h1 { color: #333; font-size: 22px; font-weight: 600; margin: 0; }
        .header-actions { display: flex; align-items: center; gap: 10px; }
        .icon-btn { background: white; border: 1px solid #ddd; width: 38px; height: 38px; border-radius: 6px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; color: #555; }
        .icon-btn:hover { background: #f0f0f0; border-color: #ccc; }

        .btn-primary-custom {
            background: var(--curdun-yellow);
            color: var(--curdun-violet);
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .btn-primary-custom:hover {
            background: var(--curdun-yellow-dark);
            color: var(--curdun-violet);
            transform: translateY(-2px);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .stat-card-sm {
            background: white;
            border-radius: 12px;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border-left: 3px solid var(--curdun-violet);
            transition: transform 0.3s ease;
        }
        .stat-card-sm:hover { transform: translateY(-3px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .stat-card-sm .stat-info h4 { font-size: 12px; color: var(--curdun-gray); margin: 0 0 5px 0; }
        .stat-card-sm .stat-info .stat-number { font-size: 28px; font-weight: 700; color: var(--curdun-violet); }
        .stat-card-sm .stat-icon { width: 45px; height: 45px; background: rgba(82,0,102,0.1); border-radius: 10px; display: flex; align-items: center; justify-content: center; }
        .stat-card-sm .stat-icon i { font-size: 22px; color: var(--curdun-violet); }

        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .filter-form { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 150px; }
        .filter-group label { display: block; font-size: 12px; font-weight: 600; color: var(--curdun-gray); margin-bottom: 5px; }
        .filter-group input, .filter-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; }
        .btn-filter { background: var(--curdun-violet); color: white; border: none; padding: 8px 20px; border-radius: 8px; cursor: pointer; }
        .btn-reset { background: #f0f0f0; color: var(--curdun-dark); border: none; padding: 8px 20px; border-radius: 8px; margin-left: 10px; cursor: pointer; }

        .customers-table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            overflow-x: auto;
            width: 100%;
        }
        
        .customers-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1500px;
        }
        
        .customers-table th, .customers-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        .dropdown-toggle::after {
            display: none !important;
        }
        .customers-table th {
            background: var(--table-header-bg);
            font-weight: 600;
            color: #555;
            font-size: 13px;
            padding: 12px 15px;
            border-top: 1px solid #eee;
            border-bottom: 1px solid #eee;
        }
        .customers-table td {
            padding: 15px;
            font-size: 14px;
            color: #444;
            border-bottom: 1px solid #f1f1f1;
        }
        .customers-table tr:hover { background: #fdfdfd; }
        .customers-table tr.has-debt { background: #fffcf5; }

        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .status-active { background: #EEFBF3; color: #0F7A3A; }
        .status-inactive { background: #FEF0EE; color: #B42318; }

        .points-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #fff8e1;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: #e65100;
            cursor: pointer;
            transition: 0.2s;
        }
        .points-badge:hover {
            background: #ffe082;
            transform: scale(1.05);
        }

        .debt-info {
            transition: all 0.2s;
            padding: 4px;
            border-radius: 4px;
        }
        .debt-info:hover {
            background-color: #f8f9fa;
            transform: scale(1.05);
        }
        .debt-info .debt-amount {
            font-weight: 700;
            color: var(--curdun-danger);
        }
        .debt-warning .debt-amount {
            color: var(--curdun-warning);
            animation: pulse 1s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        /* Dropdown Menu Styles */
        .dropdown-menu-custom {
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border: 1px solid #eee;
            padding: 8px 0;
            min-width: 180px;
        }
        .dropdown-item-custom {
            padding: 8px 20px;
            font-size: 14px;
            color: #444;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: 0.2s;
            text-decoration: none !important;
        }
        .dropdown-item-custom:hover {
            background-color: #f8f9fc;
            color: var(--curdun-violet);
        }
        .dropdown-item-custom i {
            width: 18px;
            text-align: center;
            font-size: 14px;
        }
        .dropdown-item-custom.text-danger:hover {
            background-color: #fff5f5;
            color: #d52b1e;
        }

        .alert { padding: 12px 20px; border-radius: 8px; position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .alert-success { background: #EEFBF3; color: #0F7A3A; border-left: 4px solid #0F7A3A; }
        .alert-error { background: #FEF0EE; color: #B42318; border-left: 4px solid #B42318; }

        .empty-state { text-align: center; padding: 50px; color: var(--curdun-gray); }
        .empty-state i { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }

        .modal-header { background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light)); color: white; }
        .modal-header .close { color: white; opacity: 1; }
        .modal-header .close:hover { color: var(--curdun-yellow); }

        .loading-spinner { text-align: center; padding: 50px; }
        .loading-spinner i { font-size: 48px; color: var(--curdun-violet); animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 25px; flex-wrap: wrap; }
        .pagination a, .pagination span { padding: 8px 14px; border-radius: 8px; text-decoration: none; color: var(--curdun-dark); background: white; border: 1px solid #ddd; cursor: pointer; transition: all 0.3s ease; }
        .pagination .active { background: var(--curdun-violet); color: white; border-color: var(--curdun-violet); }
        .pagination a:hover { background: var(--curdun-violet-light); color: white; transform: translateY(-2px); }

        .points-badge-clickable {
            cursor: pointer;
            transition: all 0.2s;
        }
        .points-badge-clickable:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: center; }
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            .alert { left: 20px; right: 20px; min-width: auto; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1>Customer</h1>
        <div class="header-actions">
            <div class="icon-btn" id="searchIconBtn" title="Search"><i class="fas fa-search"></i></div>
            <button type="button" class="btn btn-primary" id="addCustomerBtn" style="background-color: var(--curdun-violet); border: none; padding: 8px 20px; font-weight: 500;">
                Add Customer
            </button>
            <div class="dropdown">
                <div class="icon-btn dropdown-toggle" id="headerMoreMenu" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="More Options">
                    <i class="fas fa-bars"></i>
                </div>
                <div class="dropdown-menu dropdown-menu-right dropdown-menu-custom" aria-labelledby="headerMoreMenu">
                    <a class="dropdown-item-custom" id="exportExcelBtn">
                        <i class="fas fa-file-excel text-success"></i> Export Customers (Excel)
                    </a>
                    <a class="dropdown-item-custom" id="importExcelBtn">
                        <i class="fas fa-file-import text-primary"></i> Import Customers (Excel)
                    </a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item-custom" id="refreshDataBtn">
                        <i class="fas fa-sync-alt text-info"></i> Refresh Data
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card-sm">
            <div class="stat-info"><h4>Wadarta Macaamiisha</h4><div class="stat-number" id="stat-total">0</div></div>
            <div class="stat-icon"><i class="fas fa-users"></i></div>
        </div>
        <div class="stat-card-sm">
            <div class="stat-info"><h4>Macaamiisha Firfircoon</h4><div class="stat-number" id="stat-active">0</div></div>
            <div class="stat-icon"><i class="fas fa-user-check"></i></div>
        </div>
        <div class="stat-card-sm">
            <div class="stat-info"><h4>Wadarta Deynta</h4><div class="stat-number" id="stat-total-debt">$0</div></div>
            <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
        </div>
        <div class="stat-card-sm">
            <div class="stat-info"><h4>Macaamiisha Akoon leh</h4><div class="stat-number" id="stat-has-user">0</div></div>
            <div class="stat-icon"><i class="fas fa-user-circle"></i></div>
        </div>
        <div class="stat-card-sm">
            <div class="stat-info"><h4>Wadarta Dhibcaha</h4><div class="stat-number" id="stat-total-points">0</div></div>
            <div class="stat-icon"><i class="fas fa-star" style="color: #ff9800;"></i></div>
        </div>
    </div>

    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group"><label><i class="fas fa-search"></i> Raadin</label><input type="text" id="searchInput" placeholder="Magaca, Telefoonka, Emailka..."></div>
            <div class="filter-group"><label><i class="fas fa-building"></i> Shirkadda</label><select id="tenantFilter"><option value="">Dhammaan</option><?php foreach ($tenants as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?></select></div>
            <div class="filter-group"><label><i class="fas fa-circle"></i> Xaaladda</label><select id="statusFilter"><option value="">Dhammaan</option><option value="active">Firfircoon</option><option value="inactive">Aan Firfircooneyn</option></select></div>
            <div class="filter-group"><label><i class="fas fa-money-bill"></i> Deynta</label><select id="debtFilter"><option value="">Dhammaan</option><option value="has_debt">Luu leeyahay Deyn</option><option value="no_debt">Deyn la'aan</option><option value="high_debt">Deyn Dheer (Ka badan limitka)</option></select></div>
            <div class="filter-group"><button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Shaandheey</button><button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Nadiifi</button></div>
        </div>
    </div>

    <div id="customers-table-container"><div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i><p>Loading customers...</p></div></div>
    <div id="pagination-container"></div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="customerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="customerModalLabel">Macaamil Cusub</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="customerForm">
                <div class="modal-body">
                    <input type="hidden" name="customer_id" id="customer_id">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Magaca Macaamilka *</label>
                                <input type="text" name="customer_name" id="modalCustomerName" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Telefoonka</label>
                                <input type="text" name="phone" id="modalPhone" class="form-control">
                                <small class="text-muted">Waa inuu noqdaa mid aan horay loo isticmaalin</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Emailka</label>
                                <input type="email" name="email" id="modalEmail" class="form-control">
                                <small class="text-muted">Waa inuu noqdaa mid aan horay loo isticmaalin</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Shirkadda</label>
                                <select name="tenant_id" id="modalTenantId" class="form-control">
                                    <option value="">Dooro Shirkad...</option>
                                    <?php foreach ($tenants as $t): ?>
                                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Cinwaanka</label>
                                <textarea name="address" id="modalAddress" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Limitka Deynta</label>
                                <input type="number" step="0.01" name="credit_limit" id="modalCreditLimit" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Muddada Bixinta (Maalmood)</label>
                                <input type="number" name="payment_terms" id="modalPaymentTerms" class="form-control" value="30">
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

<!-- SMS Modal -->
<div class="modal fade" id="smsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fab fa-whatsapp"></i> U dir Fariin (API)</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="smsForm">
                <div class="modal-body">
                    <input type="hidden" name="customer_id" id="smsCustomerId">
                    <div class="form-group">
                        <label>Magaca Macaamilka</label>
                        <input type="text" id="smsCustomerName" class="form-control" readonly>
                    </div>
                    <div class="form-group">
                        <label>Telefoonka</label>
                        <input type="text" name="phone" id="smsPhone" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Fariinta</label>
                        <textarea name="message" id="smsMessage" class="form-control" rows="4" required placeholder="Fariintaada halkan ku qor..."></textarea>
                        <small class="text-muted"><span id="charCount">0</span> xaraf</small>
                    </div>
                    <div class="form-group">
                        <label>Nooca Fariinta</label>
                        <select name="send_type" id="smsSendType" class="form-control">
                            <option value="whatsapp">WhatsApp API (Auto)</option>
                            <option value="sms">SMS API</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom"><i class="fas fa-paper-plane"></i> Dir Fariinta</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Point Redemption Modal -->
<div class="modal fade" id="redeemPointsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #ff9800, #f57c00);">
                <h5 class="modal-title"><i class="fas fa-ticket-alt"></i> Redeem Points</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form id="redeemPointsForm">
                <div class="modal-body">
                    <input type="hidden" name="customer_id" id="redeemCustomerId">
                    <div class="form-group">
                        <label>Macaamilka</label>
                        <input type="text" id="redeemCustomerName" class="form-control" readonly>
                    </div>
                    <div class="form-group">
                        <label>Dhibcaha Hadda Jira</label>
                        <div class="form-control bg-light" id="currentPointsDisplay" style="font-size: 20px; font-weight: bold; color: #ff9800;">0</div>
                    </div>
                    <div class="form-group">
                        <label>Dhibcaha Aad Rabto Inaad Isticmaasho</label>
                        <input type="number" name="points_to_redeem" id="pointsToRedeem" class="form-control" min="1" step="1" required>
                        <small class="text-muted">100 dhibcood = $10 discount</small>
                    </div>
                    <div class="form-group">
                        <label>Nooca Isticmaalka</label>
                        <select name="redemption_type" id="redemptionType" class="form-control">
                            <option value="discount">Discount on Invoice</option>
                            <option value="cashback">Cashback</option>
                            <option value="gift">Gift Item</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Qoraal / Sababta</label>
                        <textarea name="notes" id="redemptionNotes" class="form-control" rows="3" placeholder="Tusaale: 10% discount on invoice #1234..."></textarea>
                    </div>
                    <div class="alert alert-info" id="discountPreview" style="display: none;">
                        <i class="fas fa-calculator"></i> <strong>Discount Preview:</strong> <span id="previewDiscountAmount">$0.00</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning"><i class="fas fa-gift"></i> Redeem Points</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Points History Modal -->
<div class="modal fade" id="pointsHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #7b1fa2, #9c27b0);">
                <h5 class="modal-title"><i class="fas fa-chart-line"></i> Points History: <span id="pointsHistoryCustomerName"></span></h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" style="max-height: 500px; overflow-y: auto;">
                <ul class="nav nav-tabs" id="pointsHistoryTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="points-log-tab" data-toggle="tab" href="#pointsLog" role="tab">Points Log</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="redemptions-tab" data-toggle="tab" href="#redemptionsList" role="tab">Redemptions</a>
                    </li>
                </ul>
                <div class="tab-content mt-3">
                    <div class="tab-pane fade show active" id="pointsLog" role="tabpanel">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr><th>Taariikhda</th><th>Nooca</th><th>Dhibcaha</th><th>Sababta</th><th>Ref</th></tr>
                            </thead>
                            <tbody id="pointsLogBody">
                                <td><td colspan="5" class="text-center">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="tab-pane fade" id="redemptionsList" role="tabpanel">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr><th>Taariikhda</th><th>Code</th><th>Dhibcaha</th><th>Discount</th><th>Nooca</th><th>Xaaladda</th><th>Qoraal</th></tr>
                            </thead>
                            <tbody id="redemptionsBody">
                                <tr><td colspan="7" class="text-center">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Statement Modal -->
<div class="modal fade" id="statementModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-invoice-dollar"></i> Statement: <span id="statementCustomerName"></span></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <small class="text-muted">Wadarta Deynta (Current Debt)</small>
                        <h3 id="statementDebtAmount" style="margin: 0; color: #B42318;">$ 0.00</h3>
                    </div>
                    <button class="btn btn-sm btn-outline-primary" onclick="window.print()"><i class="fas fa-print"></i> Print Statement</button>
                </div>
                <table class="table table-sm table-hover">
                    <thead>
                        <tr><th>Date</th><th>Type</th><th>Reference</th><th class="text-right">Debit ($)</th><th class="text-right">Credit ($)</th><th>Status</th></tr>
                    </thead>
                    <tbody id="statementBody">
                        <!-- AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Import Excel Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-import"></i> Import Macaamiil (Excel/CSV)</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="importForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <p class="text-muted small">Fadlan so geli fayl CSV ah oo leh tiirarkan: <br><strong>Name, Phone, Email, Address, Debt</strong></p>
                    <div style="margin-bottom: 15px;">
                        <a href="?ajax_action=download_sample" class="btn btn-sm btn-outline-info"><i class="fas fa-download"></i> Soo deji Sample-ka (Template)</a>
                    </div>
                    <div class="form-group">
                        <label>Dooro Faylka (CSV)</label>
                        <input type="file" name="excel_file" id="excel_file" class="form-control" accept=".csv" required>
                    </div>
                    <div class="alert alert-info py-2" style="font-size: 11px; position: static;">
                        <i class="fas fa-info-circle"></i> Haddii macaamilka magaciisa horay u jiray, deynta waa lagu darayaa (Update).
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-upload"></i> Bilow Import-ka</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white"><h5 class="modal-title">Tirtir Macaamil</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <div class="modal-body">Ma hubtaa inaad tirtirto <strong id="deleteCustomerName"></strong>?<br><br><span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Digniin: Tirtirista waa joogto!</span></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" id="confirmDeleteBtn">Tirtir</button></div>
        </div>
    </div>
</div>

<!-- History Modal (Transaction History) -->
<div class="modal fade" id="historyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: #7b1fa2;">
                <h5 class="modal-title"><i class="fas fa-history"></i> Dhaqdhaqaaqa Macaamilka: <span id="historyCustomerName"></span></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" style="max-height: 500px; overflow-y: auto;">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr><th>Taariikhda</th><th>Nooca</th><th>Tixraaca</th><th>Qadarka</th><th>Xaaladda</th></tr>
                    </thead>
                    <tbody id="historyTableBody">
                        <!-- History items will be loaded here -->
                    </tbody>
                </table>
            </div>
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
    let pointValue = 0.10; // Will be loaded from settings

    // Load point value from tenant
    function loadPointValue() {
        $.ajax({
            url: '../api/get_tenant_settings.php',
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                if (data.point_money_value) {
                    pointValue = parseFloat(data.point_money_value);
                }
            }
        });
    }
    loadPointValue();

    function loadCustomers() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'get_customers',
                page: currentPage,
                search: $('#searchInput').val(),
                status: $('#statusFilter').val(),
                tenant: $('#tenantFilter').val(),
                debt_filter: $('#debtFilter').val()
            },
            dataType: 'json',
            success: function(response) {
                $('#customers-table-container').html(response.table_html);
                $('#pagination-container').html(response.pagination_html);
                attachTableEvents();
            },
            error: function() {
                $('#customers-table-container').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading data</p></div>');
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
                $('#stat-active').text(stats.active || 0);
                $('#stat-total-debt').text('$ ' + (parseFloat(stats.total_debt) || 0).toFixed(2));
                $('#stat-has-user').text(stats.has_user_account || 0);
                $('#stat-total-points').text(Math.round(stats.total_points || 0));
            }
        });
    }

    function attachTableEvents() {
        // Initialize Bootstrap dropdowns
        $('.dropdown-toggle').dropdown();
        
        $('.edit-customer').off('click').on('click', function() {
            const id = $(this).data('id');
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_customer', id: id },
                dataType: 'json',
                success: function(customer) {
                    $('#customerModalLabel').text('Wax ka beddel Macaamil');
                    $('#customer_id').val(customer.id);
                    $('#modalCustomerName').val(customer.customer_name);
                    $('#modalPhone').val(customer.phone);
                    $('#modalEmail').val(customer.email);
                    $('#modalAddress').val(customer.address);
                    $('#modalTenantId').val(customer.tenant_id);
                    $('#modalCreditLimit').val(customer.credit_limit);
                    $('#modalPaymentTerms').val(customer.payment_terms);
                    $('#modalIsActive').val(customer.is_active);
                    $('#customerModal').modal('show');
                }
            });
        });

        $('.delete-customer').off('click').on('click', function() {
            deleteId = $(this).data('id');
            $('#deleteCustomerName').text($(this).data('name'));
            $('#deleteModal').modal('show');
        });

        $('.toggle-status').off('click').on('click', function() {
            if (confirm('Ma hubtaa inaad beddesho xaaladda macaamilkan?')) {
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: { ajax_action: 'toggle_status', id: $(this).data('id') },
                    dataType: 'json',
                    success: function(res) {
                        showAlert(res.success ? 'success' : 'error', res.message);
                        if (res.success) { loadCustomers(); loadStats(); }
                    }
                });
            }
        });

        // Redeem points button
        $('.redeem-points').off('click').on('click', function() {
            const id = $(this).data('id');
            const name = $(this).data('name');
            const points = $(this).data('points');
            
            $('#redeemCustomerId').val(id);
            $('#redeemCustomerName').val(name);
            $('#currentPointsDisplay').text(Math.round(points) + ' ⭐');
            $('#pointsToRedeem').val('');
            $('#redemptionNotes').val('');
            $('#discountPreview').hide();
            $('#redeemPointsModal').modal('show');
        });
        
        // Points history button
        $('.points-history').off('click').on('click', function() {
            const id = $(this).data('id');
            const name = $(this).data('name');
            $('#pointsHistoryCustomerName').text(name);
            $('#pointsHistoryModal').modal('show');
            
            // Load points log
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_points_history', customer_id: id },
                dataType: 'json',
                success: function(history) {
                    let html = '';
                    if (history.length > 0) {
                        history.forEach(item => {
                            let pointsText = '';
                            let pointsClass = '';
                            if (item.transaction_type === 'earned') {
                                pointsText = '+' + parseFloat(item.points).toFixed(0);
                                pointsClass = 'text-success';
                            } else {
                                pointsText = '-' + parseFloat(item.points).toFixed(0);
                                pointsClass = 'text-danger';
                            }
                            html += `<tr>
                                <td>${new Date(item.created_at).toLocaleString()}</td>
                                <td><span class="badge badge-${item.transaction_type === 'earned' ? 'success' : 'warning'}">${item.transaction_type}</span></td>
                                <td class="${pointsClass} fw-bold">${pointsText}</td>
                                <td><small>${item.reason || '-'}</small></td>
                                <td><small>${item.reference_type || '-'}</small></td>
                            </tr>`;
                        });
                    } else {
                        html = '<tr><td colspan="5" class="text-center text-muted">Ma jiro wax dhibco ah oo la helay</td></tr>';
                    }
                    $('#pointsLogBody').html(html);
                }
            });
            
            // Load redemptions
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_redemptions', customer_id: id },
                dataType: 'json',
                success: function(redemptions) {
                    let html = '';
                    if (redemptions.length > 0) {
                        redemptions.forEach(r => {
                            let statusClass = '';
                            if (r.status === 'approved') statusClass = 'success';
                            else if (r.status === 'used') statusClass = 'info';
                            else if (r.status === 'cancelled') statusClass = 'danger';
                            else statusClass = 'secondary';
                            
                            html += `<tr>
                                <td>${new Date(r.created_at).toLocaleString()}</td>
                                <td><code>${r.redemption_code}</code></td>
                                <td class="text-warning fw-bold">-${parseFloat(r.points_used).toFixed(0)}</td>
                                <td class="text-success">$${parseFloat(r.discount_amount).toFixed(2)}</td>
                                <td><span class="badge badge-info">${r.redemption_type}</span></td>
                                <td><span class="badge badge-${statusClass}">${r.status}</span></td>
                                <td><small>${r.notes || '-'}</small></td>
                             </tr>`;
                        });
                    } else {
                        html = '<tr><td colspan="7" class="text-center text-muted">Ma jiro wax redemption ah oo la sameeyay</td></tr>';
                    }
                    $('#redemptionsBody').html(html);
                }
            });
        });

        // Calculate discount preview
        $('#pointsToRedeem').off('input').on('input', function() {
            let points = parseInt($(this).val()) || 0;
            let currentPoints = parseInt($('#currentPointsDisplay').text()) || 0;
            
            if (points > currentPoints) {
                $(this).addClass('is-invalid');
                $('#discountPreview').hide();
                return;
            } else {
                $(this).removeClass('is-invalid');
            }
            
            let discount = points * pointValue;
            $('#previewDiscountAmount').text('$' + discount.toFixed(2));
            if (points > 0) {
                $('#discountPreview').show();
            } else {
                $('#discountPreview').hide();
            }
        });
        
        // Handle redeem form submit
        $('#redeemPointsForm').off('submit').on('submit', function(e) {
            e.preventDefault();
            
            let points = parseInt($('#pointsToRedeem').val()) || 0;
            let currentPoints = parseInt($('#currentPointsDisplay').text()) || 0;
            
            if (points <= 0) {
                showAlert('error', 'Fadlan geli tirada dhibcaha aad rabto inaad isticmaasho!');
                return;
            }
            
            if (points > currentPoints) {
                showAlert('error', 'Dhibcaha aad isticmaali rabto way ka badan yihiin dhibcaha aad haysato!');
                return;
            }
            
            let confirmMsg = `Ma hubtaa inaad isticmaasho ${points} dhibcood?\n\n💰 Discount: $${(points * pointValue).toFixed(2)}\n📋 Nooca: ${$('#redemptionType').val()}`;
            if (!confirm(confirmMsg)) return;
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: $(this).serialize() + '&ajax_action=redeem_points',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        $('#redeemPointsModal').modal('hide');
                        showAlert('success', res.message);
                        loadCustomers();
                        loadStats();
                    } else {
                        showAlert('error', res.message);
                    }
                },
                error: function() {
                    showAlert('error', 'Khalad ayaa dhacay marka la isticmaalayo dhibcaha');
                }
            });
        });
        
        // Click on points badge to show history
        $('.points-badge').off('click').on('click', function() {
            // This is handled by the inline onclick
        });
        
        $('.whatsapp-customer').off('click').on('click', function() {
            let phone = $(this).data('phone').toString().replace(/\D/g, '');
            const name = $(this).data('name');
            
            if (phone.length === 9 && (phone.startsWith('6') || phone.startsWith('7'))) {
                phone = '252' + phone;
            }
            
            if (!phone) { alert('Macaamilkan ma lahan lambar telefoon!'); return; }
            const message = `Asc ${name}, `;
            const url = `https://api.whatsapp.com/send?phone=${phone}&text=${encodeURIComponent(message)}`;
            window.open(url, '_blank');
        });

        $('.print-statement').off('click').on('click', function() {
            const id = $(this).data('id');
            $('.btn-debt[data-id="' + id + '"]').trigger('click');
            setTimeout(() => { window.print(); }, 1000);
        });

        $('.download-statement').off('click').on('click', function() {
            showAlert('info', 'Download feature is being prepared...');
        });
        
        $('.view-history').off('click').on('click', function() {
            const id = $(this).data('id');
            const name = $(this).data('name');
            $('#historyCustomerName').text(name);
            $('#historyTableBody').html('<tr><td colspan="5" class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading history...</td></tr>');
            $('#historyModal').modal('show');

            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_customer_history', id: id },
                dataType: 'json',
                success: function(history) {
                    let html = '';
                    let totalBalance = 0;
                    if (history.length > 0) {
                        history.forEach(item => {
                            const isInvoice = item.type === 'invoice';
                            const amt = parseFloat(item.amount);
                            totalBalance += isInvoice ? amt : -amt;
                            
                            const typeLabel = isInvoice ? '<span class="badge badge-info">Biil</span>' : '<span class="badge badge-success">Bixin</span>';
                            const amountClass = isInvoice ? 'text-danger' : 'text-success';
                            const amountSign = isInvoice ? '+' : '-';
                            const rowUrl = isInvoice ? `invoices.php?search=${item.ref}` : `payments.php?search=${item.ref}`;
                            
                            html += `
                                <tr style="cursor: pointer;" onclick="window.location.href='${rowUrl}'">
                                    <td>${new Date(item.date).toLocaleDateString('en-GB')}</td>
                                    <td>${typeLabel}</td>
                                    <td><strong class="text-primary">${item.ref}</strong></td>
                                    <td class="${amountClass}">${amountSign} $${amt.toFixed(2)}</td>
                                    <td><small>${item.status || ''}</small></td>
                                 </tr>
                            `;
                        });
                        
                        html += `
                            <tr style="background: #fdf2f2; font-weight: 800; border-top: 2px solid #dee2e6;">
                                <td colspan="3" class="text-right">Wadarta Deynta Haray:</td>
                                <td class="${totalBalance >= 0 ? 'text-danger' : 'text-success'}">
                                    ${totalBalance >= 0 ? '+' : '-'} $${Math.abs(totalBalance).toFixed(2)}
                                 </td>
                                <td></td>
                             </tr>
                        `;
                    } else {
                        html = '<tr><td colspan="5" class="text-center text-muted">Ma jiro wax dhaqdhaqaaq ah oo la helay</td></tr>';
                    }
                    $('#historyTableBody').html(html);
                }
            });
        });

        $('.pagination a').off('click').on('click', function(e) {
            e.preventDefault();
            const page = $(this).data('page');
            if (page) { currentPage = page; loadCustomers(); }
        });
    }

    function showAlert(type, msg) {
        $('#alert-placeholder').html(`<div class="alert alert-${type} alert-dismissible fade show"><i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
        setTimeout(() => $('.alert').fadeOut(5000, function() { $(this).remove(); }), 5000);
    }

    $('#customerForm').submit(function(e) {
        e.preventDefault();
        
        if (!$('#modalCustomerName').val()) { showAlert('error', 'Fadlan geli Magaca Macaamilka'); return; }
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $(this).serialize() + '&ajax_action=save_customer',
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#customerModal').modal('hide');
                    loadCustomers();
                    loadStats();
                    showAlert('success', res.message);
                    $('#customerForm')[0].reset();
                    $('#customer_id').val('');
                } else { showAlert('error', res.message); }
            },
            error: function() { showAlert('error', 'Khalad ayaa dhacay'); }
        });
    });

    $('#smsForm').submit(function(e) {
        e.preventDefault();
        
        if (!$('#smsPhone').val()) { showAlert('error', 'Fadlan geli Telefoonka'); return; }
        if (!$('#smsMessage').val()) { showAlert('error', 'Fadlan geli Fariinta'); return; }
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $(this).serialize() + '&ajax_action=send_sms',
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#smsModal').modal('hide');
                    showAlert('success', res.message);
                } else { showAlert('error', res.message); }
            },
            error: function() { showAlert('error', 'Khalad ayaa dhacay marka la dirayo SMS'); }
        });
    });

    $('#smsMessage').on('input', function() {
        $('#charCount').text($(this).val().length);
    });

    $('#confirmDeleteBtn').click(function() {
        if (deleteId) {
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'delete_customer', id: deleteId },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        $('#deleteModal').modal('hide');
                        loadCustomers();
                        loadStats();
                        showAlert('success', res.message);
                    } else { showAlert('error', res.message); }
                    deleteId = null;
                }
            });
        }
    });

    $('#addCustomerBtn, #addCustomerBtnEmpty').click(function() {
        $('#customerModalLabel').text('Macaamil Cusub');
        $('#customerForm')[0].reset();
        $('#customer_id').val('');
        $('#modalIsActive').val(1);
        $('#modalCreditLimit').val(0);
        $('#modalPaymentTerms').val(30);
        $('#customerModal').modal('show');
    });

    $('#applyFilters').click(function() { currentPage = 1; loadCustomers(); });
    $('#resetFilters').click(function() { $('#searchInput').val(''); $('#tenantFilter').val(''); $('#statusFilter').val(''); $('#debtFilter').val(''); currentPage = 1; loadCustomers(); });
    $('#searchInput').keypress(function(e) { if (e.which === 13) { currentPage = 1; loadCustomers(); } });

    $('#searchIconBtn').click(function() {
        $('.filters-card').slideToggle();
    });

    $('.filters-card').hide();

    $('#exportExcelBtn').click(function() {
        window.location.href = window.location.href + (window.location.href.includes('?') ? '&' : '?') + 'ajax_action=export_customers';
    });

    $('#importExcelBtn').click(function() {
        $('#importModal').modal('show');
    });

    $('#importForm').submit(function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('ajax_action', 'import_customers');
        
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
                    loadCustomers();
                    loadStats();
                    showAlert('success', res.message);
                } else { showAlert('error', res.message); }
            },
            error: function() { showAlert('error', 'Khalad dhacay xilligii import-ka'); }
        });
    });

    $('#refreshDataBtn').click(function() {
        currentPage = 1;
        loadCustomers();
        loadStats();
        showAlert('success', 'Xogta waa la cusboonaysiiyay');
    });

    // Initialize
    loadCustomers();
    loadStats();
});

function showPointsHistory(customerId, customerName) {
    $('#pointsHistoryCustomerName').text(customerName);
    $('#pointsHistoryModal').modal('show');
    
    // Load points log
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: { ajax_action: 'get_points_history', customer_id: customerId },
        dataType: 'json',
        success: function(history) {
            let html = '';
            if (history.length > 0) {
                history.forEach(item => {
                    let pointsText = '';
                    let pointsClass = '';
                    if (item.transaction_type === 'earned') {
                        pointsText = '+' + parseFloat(item.points).toFixed(0);
                        pointsClass = 'text-success';
                    } else {
                        pointsText = '-' + parseFloat(item.points).toFixed(0);
                        pointsClass = 'text-danger';
                    }
                    html += `<tr>
                        <td>${new Date(item.created_at).toLocaleString()}</td>
                        <td><span class="badge badge-${item.transaction_type === 'earned' ? 'success' : 'warning'}">${item.transaction_type}</span></td>
                        <td class="${pointsClass} fw-bold">${pointsText}</td>
                        <td><small>${item.reason || '-'}</small></td>
                        <td><small>${item.reference_type || '-'}</small></td>
                    </tr>`;
                });
            } else {
                html = '<tr><td colspan="5" class="text-center text-muted">Ma jiro wax dhibco ah oo la helay</td></tr>';
            }
            $('#pointsLogBody').html(html);
        }
    });
    
    // Load redemptions
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: { ajax_action: 'get_redemptions', customer_id: customerId },
        dataType: 'json',
        success: function(redemptions) {
            let html = '';
            if (redemptions.length > 0) {
                redemptions.forEach(r => {
                    let statusClass = '';
                    if (r.status === 'approved') statusClass = 'success';
                    else if (r.status === 'used') statusClass = 'info';
                    else if (r.status === 'cancelled') statusClass = 'danger';
                    else statusClass = 'secondary';
                    
                    html += `<tr>
                        <td>${new Date(r.created_at).toLocaleString()}</td>
                        <td><code>${r.redemption_code}</code></td>
                        <td class="text-warning fw-bold">-${parseFloat(r.points_used).toFixed(0)}</td>
                        <td class="text-success">$${parseFloat(r.discount_amount).toFixed(2)}</td>
                        <td><span class="badge badge-info">${r.redemption_type}</span></td>
                        <td><span class="badge badge-${statusClass}">${r.status}</span></td>
                        <td><small>${r.notes || '-'}</small></td>
                    </tr>`;
                });
            } else {
                html = '<tr><td colspan="7" class="text-center text-muted">Ma jiro wax redemption ah oo la sameeyay</td></tr>';
            }
            $('#redemptionsBody').html(html);
        }
    });
}
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>