<?php
// tenant_admin/customers.php
// Customer Management for Cargo Management System - Tenant Admin

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
require_once __DIR__ . '/../includes/MessagingService.php';
$messaging = new MessagingService($pdo);

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


// ================= AUTOMATIC LOGIN CREDENTIALS NOTIFICATION =================
// PHPMailer configuration for automatic Gmail sending
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../config/secrets.php';
if (!defined('SMTP_HOST')) define('SMTP_HOST', 'smtp.gmail.com');
if (!defined('SMTP_PORT')) define('SMTP_PORT', 587);
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', SMTP_USERNAME);
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', 'Cargo Management System');

// GreenAPI - real values come from config/secrets.php (gitignored)
if (!defined('GREEN_API_ID')) define('GREEN_API_ID', getenv('GREEN_API_ID') ?: '');
if (!defined('GREEN_API_URL')) define('GREEN_API_URL', getenv('GREEN_API_URL') ?: '');

function customerPortalLoginUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/tenant_admin/customers.php');
    return $scheme . $host . $dir . '/../login.php';
}

function shortNotifyError($result): string {
    $raw = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE);
    if (stripos($raw, 'disabled') !== false) return 'WhatsApp disabled settings-ka.';
    if (stripos($raw, 'quota') !== false || stripos($raw, 'QUOTE') !== false) return 'WhatsApp quota wuu dhammaaday.';
    if (stripos($raw, 'authorized') !== false || stripos($raw, 'Unauthorized') !== false) return 'WhatsApp QR lama authorize-gareyn.';
    if (stripos($raw, 'curl') !== false || stripos($raw, 'timed out') !== false) return 'WhatsApp cURL/internet hubi.';
    return 'WhatsApp lama dirin.';
}

function normalizeCustomerPhone($phone): string {
    $phone = preg_replace('/\D/', '', (string)$phone);
    if ($phone === '') return '';
    if (strlen($phone) === 9 && in_array($phone[0], ['6','7'], true)) return '252' . $phone;
    if (strlen($phone) === 10 && $phone[0] === '0') return '252' . substr($phone, 1);
    if (strlen($phone) === 12 && substr($phone, 0, 3) === '252') return $phone;
    return '252' . ltrim($phone, '0');
}

function buildCustomerCredentialMessage(array $data, string $tenantName): string {
    $name = $data['customer_name'] ?? $data['user_name'] ?? 'Macaamiil';
    $email = $data['email'] ?? '-';
    $password = $data['password'] ?? '123';
    $loginUrl = customerPortalLoginUrl();
    return "🔐 *USER ACCESS / LOGIN CREDENTIALS*\n\n" .
           "Mudane/Marwo {$name},\n\n" .
           "Akoonkaaga customer portal-ka waa la furay. Hoos ka eeg login-kaaga:\n\n" .
           "📧 *Email:* {$email}\n" .
           "🔑 *Password:* {$password}\n" .
           "🌐 *Login URL:* {$loginUrl}\n" .
           "👤 *Role:* Customer\n\n" .
           "Fadlan password-ka beddel marka aad gasho.\n\n" .
           "Mahadsanid,\n*{$tenantName}*";
}

function sendCustomerWhatsAppDirect($phone, string $message): array {
    $formatted = normalizeCustomerPhone($phone);
    if ($formatted === '') return ['success' => false, 'message' => 'Phone ma jiro'];
    if (!function_exists('curl_init')) return ['success' => false, 'message' => 'cURL lama shidin'];

    $url = rtrim(GREEN_API_URL, '/') . '/waInstance' . GREEN_API_ID . '/sendMessage/' . GREEN_API_TOKEN;
    $payload = ['chatId' => $formatted . '@c.us', 'message' => $message];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $decoded = json_decode((string)$response, true);
    if ($httpCode === 200 && isset($decoded['idMessage'])) {
        return ['success' => true, 'message' => 'WhatsApp waa la diray', 'idMessage' => $decoded['idMessage']];
    }
    return ['success' => false, 'message' => shortNotifyError($error ?: ($decoded ?: $response)), 'raw' => $decoded ?: $response];
}

function loadPHPMailerFiles(): bool {
    $autoloads = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../PHPMailer/vendor/autoload.php'
    ];
    foreach ($autoloads as $file) {
        if (file_exists($file)) { require_once $file; return class_exists('PHPMailer\\PHPMailer\\PHPMailer'); }
    }
    $base = __DIR__ . '/../PHPMailer/src/';
    if (file_exists($base . 'PHPMailer.php')) {
        require_once $base . 'Exception.php';
        require_once $base . 'SMTP.php';
        require_once $base . 'PHPMailer.php';
    }
    return class_exists('PHPMailer\\PHPMailer\\PHPMailer');
}

function sendCustomerCredentialsEmail(array $data, string $tenantName): array {
    if (empty($data['email'])) return ['success' => false, 'message' => 'Email ma jiro'];
    if (!loadPHPMailerFiles()) return ['success' => false, 'message' => 'PHPMailer lama helin. Ku rakib composer require phpmailer/phpmailer'];

    $name = $data['customer_name'] ?? $data['user_name'] ?? 'Customer';
    $password = $data['password'] ?? '123';
    $loginUrl = customerPortalLoginUrl();

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($data['email'], $name);
        $mail->isHTML(true);
        $mail->Subject = 'Customer Portal Login Credentials';
        $mail->Body = "
            <h2>User Login Credentials</h2>
            <p>Mudane/Marwo <b>" . htmlspecialchars($name) . "</b>,</p>
            <p>Akoonkaaga customer portal-ka waa la furay.</p>
            <p><b>Email:</b> " . htmlspecialchars($data['email']) . "</p>
            <p><b>Password:</b> " . htmlspecialchars($password) . "</p>
            <p><b>Login URL:</b> <a href='" . htmlspecialchars($loginUrl) . "'>" . htmlspecialchars($loginUrl) . "</a></p>
            <p><b>Role:</b> Customer</p>
            <p>Fadlan password-ka beddel marka aad gasho.</p>
            <p>Mahadsanid,<br><b>" . htmlspecialchars($tenantName) . "</b></p>
        ";
        $mail->AltBody = "User Login Credentials\nEmail: {$data['email']}\nPassword: {$password}\nLogin URL: {$loginUrl}\nRole: Customer";
        $mail->send();
        return ['success' => true, 'message' => 'Email waa la diray'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Email lama dirin: ' . $e->getMessage()];
    }
}

function sendAutomaticCustomerAccessNotifications(array $data, string $tenantName): array {
    $message = buildCustomerCredentialMessage($data, $tenantName);
    $emailResult = sendCustomerCredentialsEmail($data, $tenantName);
    $waResult = sendCustomerWhatsAppDirect($data['phone'] ?? '', $message);
    return [
        'email' => $emailResult,
        'whatsapp' => $waResult,
        'summary' => ($emailResult['success'] ? 'Email waa la diray.' : $emailResult['message']) . ' ' .
                     ($waResult['success'] ? 'WhatsApp waa la diray.' : $waResult['message'])
    ];
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
        $debt_filter = $_POST['debt_filter'] ?? '';
        
        $where_conditions = ["c.tenant_id = ?"];
        $params = [$session_tenant_id];
        
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
        
        if ($debt_filter === 'has_debt') {
            $where_conditions[] = "COALESCE(c.debt_amount,0) > 0";
        } elseif ($debt_filter === 'no_debt') {
            $where_conditions[] = "COALESCE(c.debt_amount,0) <= 0";
        } elseif ($debt_filter === 'high_debt') {
            $where_conditions[] = "COALESCE(c.debt_amount,0) > COALESCE(c.credit_limit,0)";
        }
        
        $where_clause = implode(" AND ", $where_conditions);
        
        // Get total count
        $count_sql = "SELECT COUNT(*) as total FROM customers c WHERE $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_customers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_customers / $limit);
        
        // Get customers with user info
        $sql = "
            SELECT 
                c.*, 
                u.email as user_email,
                u.full_name as user_full_name,
                u.id as user_account_id,
                u.is_active as user_is_active
            FROM customers c
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
            <table class="customers-table" style="min-width: 1100px; width: 100%;">
                <thead>
                    <tr style="border-top: 1px solid #eee;">
                        <th style="width: 40px; text-align: center;"><input type="checkbox" id="selectAll"></th>
                        <th style="min-width: 200px;">Customer Name</th>
                        <th style="min-width: 150px;">Mobile</th>
                        <th style="min-width: 150px;">Email</th>
                        <th style="min-width: 200px;">Address</th>
                        <th style="min-width: 130px; text-align: right;">Debt Amount</th>
                        <th style="min-width: 100px;">Status</th>
                        <th style="min-width: 120px;">User Access</th>
                        <th style="width: 100px; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($customers) > 0): ?>
                        <?php foreach ($customers as $customer): ?>
                            <tr class="<?= ((float)$customer['debt_amount'] > 0) ? 'has-debt' : '' ?>">
                                <td style="text-align: center;"><input type="checkbox" class="customer-checkbox" value="<?= $customer['id'] ?>"></td>
                                <td>
                                    <a href="#" class="view-history" data-id="<?= $customer['id'] ?>" data-name="<?= htmlspecialchars($customer['customer_name']) ?>" style="color: #0077c5; font-weight: 500; text-decoration: none;">
                                        <?= htmlspecialchars($customer['customer_name']) ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($customer['phone'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($customer['email'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($customer['address'] ?: '-') ?></td>
                                <td style="text-align: right; font-weight: 600; color: <?= ((float)$customer['debt_amount'] > 0) ? '#B42318' : '#6c757d' ?>;">
                                    <?php if ((float)$customer['debt_amount'] > 0): ?>
                                        $<?= number_format((float)$customer['debt_amount'], 2) ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge <?= $customer['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                        <?= $customer['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($customer['user_account_id']): ?>
                                        <div class="user-access-info">
                                            <span class="badge badge-success" style="background: #0F7A3A;">
                                                <i class="fas fa-check-circle"></i> Has Access
                                            </span>
                                            <div style="font-size: 10px; margin-top: 3px;">
                                                <i class="fas fa-user"></i> <?= htmlspecialchars($customer['user_email'] ?? '-') ?>
                                            </div>
                                            <button class="btn-reset-password btn-sm mt-1" data-id="<?= $customer['user_account_id'] ?>" data-name="<?= htmlspecialchars($customer['customer_name']) ?>" data-phone="<?= htmlspecialchars($customer['phone']) ?>" style="background: #ff9800; color: white; border: none; border-radius: 4px; padding: 3px 8px; font-size: 10px; cursor: pointer;">
                                                <i class="fas fa-key"></i> Reset Password
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge badge-secondary" style="background: #9e9e9e;">
                                            <i class="fas fa-lock"></i> No Access
                                        </span>
                                        <?php if ($customer['email']): ?>
                                            <button class="btn-grant-access btn-sm mt-1" 
                                                data-id="<?= $customer['id'] ?>" 
                                                data-name="<?= htmlspecialchars($customer['customer_name']) ?>"
                                                data-email="<?= htmlspecialchars($customer['email']) ?>"
                                                data-phone="<?= htmlspecialchars($customer['phone']) ?>"
                                                style="background: #2D1859; color: white; border: none; border-radius: 4px; padding: 3px 8px; font-size: 10px; cursor: pointer;">
                                                <i class="fas fa-user-plus"></i> Grant Access
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small">(No email)</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <div style="display: flex; justify-content: center; gap: 15px; align-items: center;">
                                        <i class="fas fa-edit edit-customer" data-id="<?= $customer['id'] ?>" style="color: #4e73df; cursor: pointer; font-size: 16px;" title="Edit"></i>
                                        
                                        <div class="dropdown">
                                            <i class="fas fa-ellipsis-v dropdown-toggle" id="dropdownMenu<?= $customer['id'] ?>" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="color: #999; cursor: pointer; padding: 10px; font-size: 18px;" title="More Actions"></i>
                                            <div class="dropdown-menu dropdown-menu-right dropdown-menu-custom" aria-labelledby="dropdownMenu<?= $customer['id'] ?>">
                                                <a class="dropdown-item-custom view-history" data-id="<?= $customer['id'] ?>" data-name="<?= htmlspecialchars($customer['customer_name']) ?>">
                                                    <i class="fas fa-eye text-primary"></i> View Details
                                                </a>
                                                <?php if ($customer['phone']): ?>
                                                <a class="dropdown-item-custom whatsapp-customer" data-phone="<?= htmlspecialchars($customer['phone']) ?>" data-name="<?= htmlspecialchars($customer['customer_name']) ?>">
                                                    <i class="fab fa-whatsapp text-success"></i> WhatsApp
                                                </a>
                                                <?php endif; ?>
                                                <a class="dropdown-item-custom print-statement" data-id="<?= $customer['id'] ?>" data-name="<?= htmlspecialchars($customer['customer_name']) ?>">
                                                    <i class="fas fa-print text-info"></i> Print Statement
                                                </a>
                                                <div class="dropdown-divider"></div>
                                                <a class="dropdown-item-custom toggle-status" data-id="<?= $customer['id'] ?>">
                                                    <i class="fas fa-undo text-warning"></i> Toggle Status
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
                            <td colspan="9" style="text-align: center; padding: 50px;">
                                <div class="empty-state">
                                    <i class="fas fa-users-slash"></i>
                                    <p>No customers found</p>
                                    <button class="btn-primary-custom" id="addCustomerBtnEmpty" style="margin-top: 10px;">
                                        <i class="fas fa-user-plus"></i> Add New Customer
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
    
    elseif ($action === 'get_customer') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $session_tenant_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($customer);
        exit;
    }
    
    elseif ($action === 'save_customer') {
        require_once __DIR__ . '/../includes/admin_audit.php';
        // Accept id from either `customer_id` or generic `id`. A supplied
        // id > 0 always means UPDATE and must never silently fall through
        // to CREATE if the id is unowned/unknown.
        $raw_id_specific = $_POST['customer_id'] ?? '';
        $raw_id_generic  = $_POST['id'] ?? '';
        $id = ($raw_id_specific !== '' ? $raw_id_specific : $raw_id_generic);
        $update_intent = ($id !== '' && (int)$id > 0);
        $customer_name = trim($_POST['customer_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $tenant_id = $session_tenant_id;
        $address = trim($_POST['address'] ?? '');
        $credit_limit = !empty($_POST['credit_limit']) ? (float)$_POST['credit_limit'] : 0;
        $payment_terms = isset($_POST['payment_terms']) ? (int)$_POST['payment_terms'] : 30;
        $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
        
        if (empty($customer_name)) {
            echo json_encode(['success' => false, 'message' => 'Customer name is required']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();

            if (!$update_intent) {
                // CHECK: Email duplicate
                if (!empty($email)) {
                    $check_email = $pdo->prepare("SELECT id FROM customers WHERE email = ? AND tenant_id = ?");
                    $check_email->execute([$email, $tenant_id]);
                    if ($check_email->fetch()) {
                        echo json_encode(['success' => false, 'message' => '❌ This email is already in use! Please use a different email.']);
                        exit;
                    }
                    
                    // CHECK: Email in users table (Global system email check)
                    $check_user_email = $pdo->prepare("SELECT id FROM users WHERE email = ? AND tenant_id = ?");
                    $check_user_email->execute([$email, $tenant_id]);
                    if ($check_user_email->fetch()) {
                        echo json_encode(['success' => false, 'message' => '❌ This email is already used in the system! Please use a different email.']);
                        exit;
                    }
                }
                
                // CHECK: Phone duplicate
                if (!empty($phone)) {
                    $check_phone = $pdo->prepare("SELECT id FROM customers WHERE phone = ? AND tenant_id = ?");
                    $check_phone->execute([$phone, $tenant_id]);
                    if ($check_phone->fetch()) {
                        echo json_encode(['success' => false, 'message' => '❌ This phone number is already in use! Please use a different number.']);
                        exit;
                    }
                }
                
                // Insert into customers table
                $sql = "INSERT INTO customers (tenant_id, customer_name, phone, email, address, credit_limit, payment_terms, is_active, created_by, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$tenant_id, $customer_name, $phone, $email, $address, $credit_limit, $payment_terms, $is_active, $_SESSION['user_id']]);
                $new_customer_id = $pdo->lastInsertId();
                
                record_admin_audit($pdo, 'CUSTOMER_CREATED', 'customers', (int)$new_customer_id,
                    null,
                    ['customer_name' => $customer_name, 'email' => $email, 'phone' => $phone, 'is_active' => $is_active],
                    $tenant_id);
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => "✅ Customer '$customer_name' has been added!", 'customer_id' => $new_customer_id]);
            } else {
                // Ownership gate: an update id must belong to this tenant.
                $own_chk = $pdo->prepare("SELECT id, customer_name, phone, email, address, is_active FROM customers WHERE id = ? AND tenant_id = ?");
                $own_chk->execute([(int)$id, $tenant_id]);
                $existing_customer = $own_chk->fetch(PDO::FETCH_ASSOC);
                if (!$existing_customer) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Customer not found or you do not have permission']);
                    exit;
                }
                // CHECK: Email duplicate for update
                if (!empty($email)) {
                    $check_email = $pdo->prepare("SELECT id FROM customers WHERE email = ? AND id != ? AND tenant_id = ?");
                    $check_email->execute([$email, $id, $tenant_id]);
                    if ($check_email->fetch()) {
                        echo json_encode(['success' => false, 'message' => '❌ This email is already in use by another customer!']);
                        exit;
                    }
                    
                    // CHECK: Email in users table (exclude their own user account if they have one)
                    $check_user_email = $pdo->prepare("SELECT id FROM users WHERE email = ? AND tenant_id = ? AND id != (SELECT user_id FROM customers WHERE id = ?)");
                    $check_user_email->execute([$email, $tenant_id, $id]);
                    if ($check_user_email->fetch()) {
                        echo json_encode(['success' => false, 'message' => '❌ This email is already used in the system! Please use a different email.']);
                        exit;
                    }
                }
                
                // CHECK: Phone duplicate for update
                if (!empty($phone)) {
                    $check_phone = $pdo->prepare("SELECT id FROM customers WHERE phone = ? AND id != ? AND tenant_id = ?");
                    $check_phone->execute([$phone, $id, $tenant_id]);
                    if ($check_phone->fetch()) {
                        echo json_encode(['success' => false, 'message' => '❌ This phone number is already in use by another customer!']);
                        exit;
                    }
                }
                
                // Update existing customer
                $sql = "UPDATE customers SET customer_name=?, phone=?, email=?, address=?, credit_limit=?, payment_terms=?, is_active=? WHERE id=? AND tenant_id=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$customer_name, $phone, $email, $address, $credit_limit, $payment_terms, $is_active, $id, $tenant_id]);
                
                // Also update the linked user account if exists
                $check_user = $pdo->prepare("SELECT id FROM users WHERE id = (SELECT user_id FROM customers WHERE id = ?) AND tenant_id = ?");
                $check_user->execute([$id, $tenant_id]);
                $user_account = $check_user->fetch();
                
                if ($user_account) {
                    $update_user = $pdo->prepare("UPDATE users SET full_name=?, email=?, phone=? WHERE id=? AND tenant_id=?");
                    $update_user->execute([$customer_name, $email, $phone, $user_account['id'], $tenant_id]);
                }
                
                record_admin_audit($pdo, 'CUSTOMER_UPDATED', 'customers', (int)$id,
                    $existing_customer,
                    ['customer_name' => $customer_name, 'email' => $email, 'phone' => $phone, 'address' => $address, 'is_active' => $is_active],
                    $tenant_id);
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => "✅ Customer '$customer_name' has been updated!"]);
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    // CREATE USER ACCOUNT with PASSWORD = 123
    elseif ($action === 'create_user_account') {
        $customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        
        try {
            // Get customer details
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$customer_id, $session_tenant_id]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$customer) {
                echo json_encode(['success' => false, 'message' => 'Customer not found!']);
                exit;
            }
            
            // Check if user account already exists
            if ($customer['user_id']) {
                echo json_encode(['success' => false, 'message' => 'This customer already has a user account!']);
                exit;
            }
            
            // Check if email is provided
            if (empty($customer['email'])) {
                echo json_encode(['success' => false, 'message' => '❌ Customer does not have an email! Please add an email before creating a User Account.']);
                exit;
            }
            
            // Check if email already exists in users table
            $check_email = $pdo->prepare("SELECT id FROM users WHERE email = ? AND tenant_id = ?");
            $check_email->execute([$customer['email'], $session_tenant_id]);
            if ($check_email->fetch()) {
                echo json_encode(['success' => false, 'message' => '❌ This email is already used by another user! Please use a different email.']);
                exit;
            }
            
            // Check if phone already exists in users table
            if (!empty($customer['phone'])) {
                $check_phone = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND tenant_id = ?");
                $check_phone->execute([$customer['phone'], $session_tenant_id]);
                if ($check_phone->fetch()) {
                    echo json_encode(['success' => false, 'message' => '❌ This phone number is already used by another user! Please use a different number.']);
                    exit;
                }
            }
            
            // Secure temporary-password provisioning (replaces legacy fixed '123').
            $alphabet = 'ABCDEFGHIJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
            $temporary_password = '';
            for ($i = 0; $i < 12; $i++) {
                $temporary_password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $hashed_password = password_hash($temporary_password, PASSWORD_DEFAULT);

            $pdo->beginTransaction();
            // Create user account + link customer.user_id in a single transaction
            $sql = "INSERT INTO users (tenant_id, email, password_hash, full_name, phone, role_type, is_active, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, 'customer', ?, ?, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $session_tenant_id,
                $customer['email'],
                $hashed_password,
                $customer['customer_name'],
                $customer['phone'],
                $customer['is_active'],
                $_SESSION['user_id']
            ]);

            $new_user_id = $pdo->lastInsertId();

            // Update customer with user_id
            $update = $pdo->prepare("UPDATE customers SET user_id = ? WHERE id = ? AND tenant_id = ?");
            $update->execute([$new_user_id, $customer_id, $session_tenant_id]);

            require_once __DIR__ . '/../includes/admin_audit.php';
            record_admin_audit($pdo, 'CUSTOMER_LOGIN_LINKED', 'users', (int)$new_user_id,
                null,
                ['customer_id' => $customer_id, 'email' => $customer['email'], 'role_type' => 'customer'],
                $session_tenant_id);
            $pdo->commit();

            // Send credentials automatically by Gmail + WhatsApp
            $notifyData = [
                'customer_name' => $customer['customer_name'],
                'email' => $customer['email'],
                'password' => $temporary_password,
                'phone' => $customer['phone']
            ];
            $notify = sendAutomaticCustomerAccessNotifications($notifyData, $tenant_name);

            echo json_encode([
                'success' => true,
                'message' => "✅ User account has been created for '{$customer['customer_name']}'! " . $notify['summary'],
                'user_id' => $new_user_id,
                'email' => $customer['email'],
                'password' => $temporary_password,
                'customer_name' => $customer['customer_name'],
                'phone' => $customer['phone'],
                'notify' => $notify
            ]);

        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // RESET PASSWORD to '123'
    elseif ($action === 'reset_user_password') {
        $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        
        try {
            // Verify user belongs to this tenant and is a customer
            $stmt = $pdo->prepare("SELECT u.id, u.email, u.full_name, c.phone 
                                   FROM users u
                                   LEFT JOIN customers c ON c.user_id = u.id
                                   WHERE u.id = ? AND u.tenant_id = ? AND u.role_type = 'customer'");
            $stmt->execute([$user_id, $session_tenant_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                echo json_encode(['success' => false, 'message' => 'User not found!']);
                exit;
            }
            
            // FIXED: Reset to '123' instead of random
            $new_password = '123';
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Update password
            $update = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND tenant_id = ?");
            $update->execute([$hashed_password, $user_id, $session_tenant_id]);

            // Send reset credentials automatically by Gmail + WhatsApp
            $notifyData = [
                'user_name' => $user['full_name'],
                'email' => $user['email'],
                'password' => '123',
                'phone' => $user['phone'] ?? ''
            ];
            $notify = sendAutomaticCustomerAccessNotifications($notifyData, $tenant_name);
            
            echo json_encode([
                'success' => true,
                'message' => "✅ Password has been reset to '123' for '{$user['full_name']}'! " . $notify['summary'],
                'email' => $user['email'],
                'password' => '123',
                'user_name' => $user['full_name'],
                'phone' => $user['phone'] ?? '',
                'notify' => $notify
            ]);
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'delete_customer') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid customer ID']);
            exit;
        }

        // Local safe helpers for deleting related records without breaking older databases
        $tableExistsSafe = function(string $table) use ($pdo): bool {
            try {
                $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
                $stmt->execute([$table]);
                return (bool)$stmt->fetch(PDO::FETCH_NUM);
            } catch (Throwable $e) {
                return false;
            }
        };

        $columnExistsSafe = function(string $table, string $column) use ($pdo, $tableExistsSafe): bool {
            try {
                if (!$tableExistsSafe($table)) return false;
                $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
                $stmt->execute([$column]);
                return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                return false;
            }
        };

        $safeDeleteByCustomer = function(string $table, int $customerId) use ($pdo, $columnExistsSafe): int {
            try {
                if (!$columnExistsSafe($table, 'customer_id')) return 0;
                $stmt = $pdo->prepare("DELETE FROM `$table` WHERE customer_id = ?");
                $stmt->execute([$customerId]);
                return $stmt->rowCount();
            } catch (Throwable $e) {
                return 0;
            }
        };

        $safeDeleteByInvoiceIds = function(string $table, array $invoiceIds) use ($pdo, $columnExistsSafe): int {
            try {
                if (!$invoiceIds || !$columnExistsSafe($table, 'invoice_id')) return 0;
                $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
                $stmt = $pdo->prepare("DELETE FROM `$table` WHERE invoice_id IN ($placeholders)");
                $stmt->execute($invoiceIds);
                return $stmt->rowCount();
            } catch (Throwable $e) {
                return 0;
            }
        };

        $safeNullByInvoiceIds = function(string $table, string $column, array $invoiceIds) use ($pdo, $columnExistsSafe): int {
            try {
                if (!$invoiceIds || !$columnExistsSafe($table, $column)) return 0;
                $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
                $stmt = $pdo->prepare("UPDATE `$table` SET `$column` = NULL WHERE `$column` IN ($placeholders)");
                $stmt->execute($invoiceIds);
                return $stmt->rowCount();
            } catch (Throwable $e) {
                return 0;
            }
        };
        
        try {
            $pdo->beginTransaction();
            
            // Get customer details first
            $stmt = $pdo->prepare("SELECT id, user_id, tenant_id, customer_name FROM customers WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmt->execute([$id, $session_tenant_id]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$customer) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Customer not found']);
                exit;
            }

            // Collect all invoices for this customer
            $invoiceIds = [];
            try {
                $stmtInv = $pdo->prepare("SELECT id FROM invoices WHERE customer_id = ? AND tenant_id = ?");
                $stmtInv->execute([$id, $session_tenant_id]);
                $invoiceIds = array_map('intval', $stmtInv->fetchAll(PDO::FETCH_COLUMN));
            } catch (Throwable $e) {
                $invoiceIds = [];
            }

            // Delete child records linked by invoice_id first
            // This prevents FK errors from receipts/payments/invoice_items before deleting invoices/customers.
            foreach ([
                'invoice_items',
                'receipts',
                'payments',
                'debt_collection_log',
                'debt_follow_ups',
                'overdue_alerts',
                'whatsapp_invoice_logs',
                'point_redemptions'
            ] as $table) {
                $safeDeleteByInvoiceIds($table, $invoiceIds);
            }

            // Some records should not be deleted fully; just remove invoice reference if column exists.
            $safeNullByInvoiceIds('cargo_manifest_items', 'invoice_id', $invoiceIds);
            $safeNullByInvoiceIds('packages', 'invoice_id', $invoiceIds);

            // Delete remaining child records linked directly by customer_id.
            foreach ([
                'receipts',
                'payments',
                'customer_notifications',
                'customer_portal_sessions',
                'device_tokens',
                'loyalty_points_log',
                'point_redemptions',
                'debt_collection_log',
                'debt_follow_ups',
                'overdue_alerts',
                'whatsapp_invoice_logs',
                'bulk_sms_recipients'
            ] as $table) {
                $safeDeleteByCustomer($table, $id);
            }

            // Delete invoices after their child rows are removed.
            if (!empty($invoiceIds)) {
                $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
                try {
                    $stmtDelInv = $pdo->prepare("DELETE FROM invoices WHERE tenant_id = ? AND id IN ($placeholders)");
                    $stmtDelInv->execute(array_merge([$session_tenant_id], $invoiceIds));
                } catch (Throwable $e) {}
            }

            // Delete or deactivate linked user account safely.
            // If users table is referenced elsewhere, deletion may fail; then we only deactivate it.
            if (!empty($customer['user_id'])) {
                try {
                    $delete_user = $pdo->prepare("DELETE FROM users WHERE id = ? AND role_type = 'customer' AND tenant_id = ?");
                    $delete_user->execute([(int)$customer['user_id'], $session_tenant_id]);
                } catch (Throwable $e) {
                    try {
                        $deactivate_user = $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ? AND role_type = 'customer' AND tenant_id = ?");
                        $deactivate_user->execute([(int)$customer['user_id'], $session_tenant_id]);
                    } catch (Throwable $e2) {}
                }
            }
            
            // Finally delete the customer row.
            $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $session_tenant_id]);
            
            // Log the deletion if helper exists.
            if (function_exists('LogAudit')) {
                try {
                    LogAudit($pdo, 'DELETE_CUSTOMER', 'customers', $id, null, [
                        'customer_name' => $customer['customer_name'] ?? '',
                        'deleted_invoices' => count($invoiceIds)
                    ]);
                } catch (Throwable $e) {}
            }
            
            $pdo->commit();
            echo json_encode([
                'success' => true,
                'message' => "Customer '{$customer['customer_name']}' iyo xogtiisa la xiriirta waa la tirtiray."
            ]);
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
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT is_active, customer_name, user_id FROM customers WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $session_tenant_id]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$customer) {
                echo json_encode(['success' => false, 'message' => 'Customer not found']);
                exit;
            }
            
            $new_status = $customer['is_active'] ? 0 : 1;
            $stmt = $pdo->prepare("UPDATE customers SET is_active = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$new_status, $id, $session_tenant_id]);
            
            // Also update linked user account status
            if ($customer['user_id']) {
                $update_user = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ? AND tenant_id = ?");
                $update_user->execute([$new_status, $customer['user_id'], $session_tenant_id]);
            }
            
            $pdo->commit();
            
            if ($new_status) {
                $message = "✅ Customer '{$customer['customer_name']}' is now ACTIVE!";
            } else {
                $message = "⚠️ Customer '{$customer['customer_name']}' is now INACTIVE!";
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
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
                COALESCE(SUM(CASE WHEN debt_amount > 0 THEN debt_amount ELSE 0 END), 0) as total_debt,
                SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END) as has_user_account
            FROM customers
            WHERE tenant_id = ?
        ");
        $stmt->execute([$session_tenant_id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($stats ?: ['total' => 0, 'active' => 0, 'inactive' => 0, 'total_debt' => 0, 'has_user_account' => 0]);
        exit;
    }
    
    elseif ($action === 'get_statement') {
        $customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        
        // Get customer info
        $stmt = $pdo->prepare("SELECT customer_name, debt_amount FROM customers WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$customer_id, $session_tenant_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$customer) {
            echo json_encode(['success' => false, 'message' => 'Customer not found']);
            exit;
        }
        
        // Get Invoices
        $stmt = $pdo->prepare("SELECT 'Invoice' as type, invoice_number as ref, invoice_date as date, total_amount as debit, 0 as credit, status FROM invoices WHERE customer_id = ? AND tenant_id = ?");
        $stmt->execute([$customer_id, $session_tenant_id]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get Receipts (Payments)
        $stmt = $pdo->prepare("SELECT 'Payment' as type, receipt_number as ref, payment_date as date, 0 as debit, amount as credit, 'Paid' as status FROM receipts WHERE customer_id = ? AND tenant_id = ?");
        $stmt->execute([$customer_id, $session_tenant_id]);
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
        $stmt = $pdo->prepare("
            SELECT 'invoice' as type, invoice_number as ref, invoice_date as date, total_amount as amount, status 
            FROM invoices 
            WHERE customer_id = ? AND tenant_id = ?
        ");
        $stmt->execute([$id, $session_tenant_id]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get Receipts (Payments)
        $stmt = $pdo->prepare("
            SELECT 'payment' as type, receipt_number as ref, payment_date as date, amount, 'Paid' as status 
            FROM receipts 
            WHERE customer_id = ? AND tenant_id = ?
        ");
        $stmt->execute([$id, $session_tenant_id]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $history = array_merge($invoices, $payments);
        usort($history, function($a, $b) {
            return strtotime($b['date'] ?? '0') - strtotime($a['date'] ?? '0');
        });
        
        echo json_encode($history);
        exit;
    }
    
    elseif ($action === 'export_customers') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=customers_export_'.date('Y-m-d').'.csv');
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM for Excel compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['ID', 'Name', 'Phone', 'Email', 'Address', 'Debt Amount', 'Credit Limit', 'Payment Terms', 'Has User Account', 'Status', 'Created At']);
        
        $search = $_GET['search'] ?? '';
        $status_filter = $_GET['status'] ?? '';
        $debt_filter = $_GET['debt_filter'] ?? '';
        
        $where_conditions = ["tenant_id = ?"];
        $params = [$session_tenant_id];
        
        if (!empty($search)) {
            $where_conditions[] = "(customer_name LIKE ? OR phone LIKE ? OR email LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($status_filter !== '') {
            $where_conditions[] = "is_active = ?";
            $params[] = $status_filter == 'active' ? 1 : 0;
        }
        
        if ($debt_filter === 'has_debt') {
            $where_conditions[] = "debt_amount > 0";
        } elseif ($debt_filter === 'no_debt') {
            $where_conditions[] = "debt_amount = 0";
        }
        
        $where_clause = implode(" AND ", $where_conditions);
        
        $sql = "SELECT id, customer_name, phone, email, address, debt_amount, credit_limit, payment_terms, 
                       CASE WHEN user_id IS NOT NULL THEN 'Yes' ELSE 'No' END as has_user_account,
                       CASE WHEN is_active = 1 THEN 'Active' ELSE 'Inactive' END as status,
                       created_at 
                FROM customers 
                WHERE $where_clause 
                ORDER BY customer_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }
    
    elseif ($action === 'import_customers') {
        if (!isset($_FILES['excel_file'])) {
            echo json_encode(['success' => false, 'message' => 'No file selected!']);
            exit;
        }
        
        $file = $_FILES['excel_file']['tmp_name'];
        $handle = fopen($file, "r");
        
        // Skip header
        $header = fgetcsv($handle);
        
        $imported = 0;
        $updated = 0;
        
        try {
            $pdo->beginTransaction();
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                // columns: Name, Phone, Email, Address, Initial Debt, Credit Limit, Payment Terms
                $name = trim($data[0] ?? '');
                $phone = trim($data[1] ?? '');
                $email = trim($data[2] ?? '');
                $address = trim($data[3] ?? '');
                $debt = (float)(str_replace(['$', ','], '', $data[4] ?? 0));
                $credit_limit = (float)(str_replace(['$', ','], '', $data[5] ?? 0));
                $payment_terms = (int)($data[6] ?? 30);
                
                if (empty($name)) continue;
                
                // Check if exists
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE (customer_name = ? OR (phone != '' AND phone = ?)) AND tenant_id = ?");
                $stmt->execute([$name, $phone, $session_tenant_id]);
                $existing = $stmt->fetch();
                
                if ($existing) {
                    $sql = "UPDATE customers SET debt_amount = debt_amount + ?, phone = ?, email = ?, address = ?, credit_limit = ?, payment_terms = ? WHERE id = ? AND tenant_id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$debt, $phone, $email, $address, $credit_limit, $payment_terms, $existing['id'], $session_tenant_id]);
                    $updated++;
                } else {
                    $sql = "INSERT INTO customers (tenant_id, customer_name, phone, email, address, debt_amount, credit_limit, payment_terms, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$session_tenant_id, $name, $phone, $email, $address, $debt, $credit_limit, $payment_terms, $_SESSION['user_id']]);
                    $imported++;
                }
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => "Import successful! (New: $imported, Updated: $updated)"]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        fclose($handle);
        exit;
    }
    
    elseif ($action === 'download_sample') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=customer_import_sample.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, ['Customer Name', 'Phone', 'Email', 'Address', 'Initial Debt', 'Credit Limit', 'Payment Terms']);
        fputcsv($output, ['Ali Ahmed', '252615123456', 'ali@example.com', 'Hodan, Mogadishu', '500.00', '2000', '30']);
        fputcsv($output, ['Maryan Abdi', '252615654321', 'maryan@example.com', 'Boondheere, Mogadishu', '250.50', '1000', '15']);
        fclose($output);
        exit;
    }
    
    elseif ($action === 'send_sms') {
        $customer_id = $_POST['customer_id'] ?? 0;
        $phone = $_POST['phone'] ?? '';
        $message = $_POST['message'] ?? '';
        $type = $_POST['send_type'] ?? 'whatsapp';

        if (empty($phone) || empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Phone and message are required!']);
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Management - <?= htmlspecialchars($tenant_name) ?> | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root {
            --curdun-violet: #2D1859;
            --curdun-yellow: #F5C410;
            --curdun-violet-light: #4B2C85;
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
        
        .header-actions { display: flex; align-items: center; gap: 10px; }
        .icon-btn { background: white; border: 1px solid #ddd; width: 38px; height: 38px; border-radius: 6px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; color: #555; }
        .icon-btn:hover { background: #f0f0f0; border-color: #ccc; }

        .btn-primary-custom { background: var(--curdun-violet); color: white; border: none; padding: 10px 20px; border-radius: 20px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s ease; cursor: pointer; }
        .btn-primary-custom:hover { background: var(--curdun-violet-light); transform: translateY(-1px); }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
            padding: 0 25px;
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
        .stat-card-sm .stat-info h4 { font-size: 12px; color: var(--curdun-gray); margin: 0 0 5px 0; text-transform: uppercase; font-weight: 600; }
        .stat-card-sm .stat-info .stat-number { font-size: 28px; font-weight: 700; color: var(--curdun-violet); }
        .stat-card-sm .stat-icon { width: 45px; height: 45px; background: rgba(82,0,102,0.1); border-radius: 10px; display: flex; align-items: center; justify-content: center; }
        .stat-card-sm .stat-icon i { font-size: 22px; color: var(--curdun-violet); }

        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin: 0 25px 25px 25px;
        }
        .filter-form { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 150px; }
        .filter-group label { display: block; font-size: 12px; font-weight: 600; color: var(--curdun-gray); margin-bottom: 5px; text-transform: uppercase; }
        .filter-group input, .filter-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; }
        .btn-filter { background: var(--curdun-violet); color: white; border: none; padding: 8px 20px; border-radius: 8px; cursor: pointer; }
        .btn-reset { background: #f0f0f0; color: var(--curdun-dark); border: none; padding: 8px 20px; border-radius: 8px; margin-left: 10px; cursor: pointer; }

        .customers-table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            overflow-x: auto;
            width: 100%;
            margin: 0 25px; 
            max-width: calc(100% - 50px);
        }
        
        .customers-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1100px;
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

        .modal-header { background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light)); color: white; border-radius: 16px 16px 0 0; }
        .modal-header .close { color: white; opacity: 1; }

        .loading-spinner { text-align: center; padding: 50px; }
        .loading-spinner i { font-size: 48px; color: var(--curdun-violet); animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 25px; flex-wrap: wrap; margin-bottom: 25px; }
        .pagination a, .pagination span { padding: 8px 14px; border-radius: 8px; text-decoration: none; color: var(--curdun-dark); background: white; border: 1px solid #ddd; cursor: pointer; transition: all 0.3s ease; }
        .pagination .active { background: var(--curdun-violet); color: white; border-color: var(--curdun-violet); }
        .pagination a:hover { background: var(--curdun-violet-light); color: white; transform: translateY(-2px); }

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

        /* Login Credentials Modal Styles */
        .credentials-box {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin: 15px 0;
            border-left: 4px solid #2D1859;
        }
        .credentials-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .credentials-row:last-child {
            border-bottom: none;
        }
        .credentials-label {
            font-weight: 600;
            color: #555;
            width: 100px;
        }
        .credentials-value {
            font-family: monospace;
            font-size: 16px;
            color: #2D1859;
            flex: 1;
        }
        .copy-btn {
            background: none;
            border: none;
            color: #0077c5;
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 4px;
        }
        .copy-btn:hover {
            background: #e3f2fd;
        }
        .login-note {
            background: #fff3e0;
            border-radius: 8px;
            padding: 12px;
            margin-top: 15px;
            font-size: 13px;
            color: #e65100;
        }

        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: center; }
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); padding: 0 15px; }
            .customers-table-container { margin: 0 15px; }
            .filters-card { margin: 0 15px 25px 15px; }
            .alert-custom { left: 20px; right: 20px; min-width: auto; }
        }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 0;">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-users"></i> Customer Management</h1>
        <div class="d-flex gap-3 align-items-center">
            <span class="company-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($tenant_name) ?></span>
            <div class="header-actions">
                <div class="icon-btn" id="searchIconBtn" title="Search"><i class="fas fa-search"></i></div>
                <button type="button" class="btn btn-primary-custom" id="addCustomerBtn">
                    <i class="fas fa-user-plus"></i> Add Customer
                </button>
                <div class="dropdown">
                    <div class="icon-btn dropdown-toggle" id="headerMoreMenu" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="More Options">
                        <i class="fas fa-bars"></i>
                    </div>
                    <div class="dropdown-menu dropdown-menu-right dropdown-menu-custom" aria-labelledby="headerMoreMenu">
                        <a class="dropdown-item-custom" id="exportExcelBtn">
                            <i class="fas fa-file-excel text-success"></i> Export Customers (CSV)
                        </a>
                        <a class="dropdown-item-custom" id="importExcelBtn">
                            <i class="fas fa-file-import text-primary"></i> Import Customers (CSV)
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item-custom" id="refreshDataBtn">
                            <i class="fas fa-sync-alt text-info"></i> Refresh Data
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card-sm">
            <div class="stat-info"><h4>Total Customers</h4><div class="stat-number" id="stat-total">0</div></div>
            <div class="stat-icon"><i class="fas fa-users"></i></div>
        </div>
        <div class="stat-card-sm">
            <div class="stat-info"><h4>Active Customers</h4><div class="stat-number" id="stat-active">0</div></div>
            <div class="stat-icon"><i class="fas fa-user-check"></i></div>
        </div>
        <div class="stat-card-sm">
            <div class="stat-info"><h4>Total Debt</h4><div class="stat-number" id="stat-total-debt">$0</div></div>
            <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
        </div>
        <div class="stat-card-sm">
            <div class="stat-info"><h4>With User Account</h4><div class="stat-number" id="stat-has-user">0</div></div>
            <div class="stat-icon"><i class="fas fa-user-circle"></i></div>
        </div>
    </div>

    <div class="filters-card" style="display: none;">
        <div class="filter-form">
            <div class="filter-group"><label><i class="fas fa-search"></i> Search</label><input type="text" id="searchInput" placeholder="Name, Phone, Email..."></div>
            <div class="filter-group"><label><i class="fas fa-circle"></i> Status</label><select id="statusFilter"><option value="">All</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
            <div class="filter-group"><label><i class="fas fa-money-bill"></i> Debt Filter</label><select id="debtFilter"><option value="">All</option><option value="has_debt">Has Debt</option><option value="no_debt">No Debt</option><option value="high_debt">High Debt (>Limit)</option></select></div>
            <div class="filter-group"><button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Filter</button><button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Reset</button></div>
        </div>
    </div>

    <div id="customers-table-container"><div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i><p>Loading customers...</p></div></div>
    <div id="pagination-container"></div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="customerModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header">
                <h5 class="modal-title" id="customerModalLabel">New Customer</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="customerForm">
                <div class="modal-body">
                    <input type="hidden" name="customer_id" id="customer_id">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Customer Name *</label>
                                <input type="text" name="customer_name" id="modalCustomerName" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="text" name="phone" id="modalPhone" class="form-control">
                                <small class="text-muted">Must be unique</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" name="email" id="modalEmail" class="form-control">
                                <small class="text-muted">Must be unique</small>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Address</label>
                                <textarea name="address" id="modalAddress" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Credit Limit</label>
                                <input type="number" step="0.01" name="credit_limit" id="modalCreditLimit" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Payment Terms (Days)</label>
                                <input type="number" name="payment_terms" id="modalPaymentTerms" class="form-control" value="30">
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
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Save Customer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Login Credentials Modal (NEW) -->
<div class="modal fade" id="credentialsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #0F7A3A, #4caf50);">
                <h5 class="modal-title"><i class="fas fa-key"></i> User Login Credentials</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="credentials-box">
                    <div class="credentials-row">
                        <span class="credentials-label"><i class="fas fa-user"></i> Customer:</span>
                        <span class="credentials-value" id="credCustomerName">-</span>
                    </div>
                    <div class="credentials-row">
                        <span class="credentials-label"><i class="fas fa-envelope"></i> Email:</span>
                        <span class="credentials-value" id="credEmail">-</span>
                        <button class="copy-btn" onclick="copyToClipboard('credEmail')"><i class="fas fa-copy"></i> Copy</button>
                    </div>
                    <div class="credentials-row">
                        <span class="credentials-label"><i class="fas fa-lock"></i> Password:</span>
                        <span class="credentials-value" id="credPassword">123</span>
                        <button class="copy-btn" onclick="copyToClipboard('credPassword')"><i class="fas fa-copy"></i> Copy</button>
                    </div>
                </div>
                <div class="login-note">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Login Information:</strong><br>
                    URL: <strong><?= (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/../login.php' ?></strong><br>
                    Role: <strong>Customer</strong><br>
                    <span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Default Password: <strong>123</strong></span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" id="sendCredentialsViaWhatsApp"><i class="fab fa-whatsapp"></i> Dir Mar Kale WhatsApp</button>
            </div>
        </div>
    </div>
</div>

<!-- SMS/WhatsApp Modal -->
<div class="modal fade" id="smsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fab fa-whatsapp"></i> Send Message</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="smsForm">
                <div class="modal-body">
                    <input type="hidden" name="customer_id" id="smsCustomerId">
                    <div class="form-group">
                        <label>Customer Name</label>
                        <input type="text" id="smsCustomerName" class="form-control" readonly>
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" id="smsPhone" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Message</label>
                        <textarea name="message" id="smsMessage" class="form-control" rows="4" required placeholder="Type your message here..."></textarea>
                        <small class="text-muted"><span id="charCount">0</span> characters</small>
                    </div>
                    <div class="form-group">
                        <label>Send Type</label>
                        <select name="send_type" id="smsSendType" class="form-control">
                            <option value="whatsapp">WhatsApp API</option>
                            <option value="sms">SMS API</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom"><i class="fas fa-paper-plane"></i> Send Message</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Statement Modal -->
<div class="modal fade" id="statementModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-invoice-dollar"></i> Statement: <span id="statementCustomerName"></span></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <small class="text-muted">Current Debt</small>
                        <h3 id="statementDebtAmount" style="margin: 0; color: #B42318;">$0.00</h3>
                    </div>
                    <button class="btn btn-sm btn-outline-primary" onclick="window.print()"><i class="fas fa-print"></i> Print Statement</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Reference</th>
                                <th class="text-right">Debit</th>
                                <th class="text-right">Credit</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="statementBody">
                            <!-- AJAX content -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Import Excel Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-import"></i> Import Customers (CSV)</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="importForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <p class="text-muted small">Please upload a CSV file with the following columns:<br><strong>Name, Phone, Email, Address, Debt, Credit Limit, Payment Terms</strong></p>
                    <div style="margin-bottom: 15px;">
                        <a href="?ajax_action=download_sample" class="btn btn-sm btn-outline-info"><i class="fas fa-download"></i> Download Sample Template</a>
                    </div>
                    <div class="form-group">
                        <label>Select CSV File</label>
                        <input type="file" name="excel_file" id="excel_file" class="form-control" accept=".csv" required>
                    </div>
                    <div class="alert alert-info py-2" style="font-size: 11px;">
                        <i class="fas fa-info-circle"></i> If a customer with the same name exists, their debt will be updated.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-upload"></i> Start Import</button>
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
                <h5 class="modal-title">Delete Customer</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete <strong id="deleteCustomerName"></strong>?<br><br>
                <span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Warning: This action is permanent!</span>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- History Modal -->
<div class="modal fade" id="historyModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #7b1fa2, #9c27b0); color: white;">
                <h5 class="modal-title"><i class="fas fa-history"></i> Customer Activity: <span id="historyCustomerName"></span></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" style="max-height: 500px; overflow-y: auto;">
                <div class="row mb-3" id="historyTotalsBox">
                    <div class="col-md-4 mb-2">
                        <div class="p-3 rounded" style="background:#fff5f5;border-left:4px solid #dc3545;">
                            <small class="text-muted d-block">Total Invoice</small>
                            <strong id="historyTotalDebit" class="text-danger">-</strong>
                        </div>
                    </div>
                    <div class="col-md-4 mb-2">
                        <div class="p-3 rounded" style="background:#f0fff4;border-left:4px solid #0F7A3A;">
                            <small class="text-muted d-block">Total Payment</small>
                            <strong id="historyTotalCredit" class="text-success">-</strong>
                        </div>
                    </div>
                    <div class="col-md-4 mb-2">
                        <div class="p-3 rounded" style="background:#f8f9fa;border-left:4px solid #2D1859;">
                            <small class="text-muted d-block">Wadar / Balance</small>
                            <strong id="historyTotalBalance">-</strong>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Reference / Details</th>
                                <th class="text-right">Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="historyTableBody">
                            <!-- History items loaded here -->
                        </tbody>
                    </table>
                </div>
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
    let currentCredentials = {};

    // Copy to clipboard function
    window.copyToClipboard = function(elementId) {
        const text = $('#' + elementId).text();
        navigator.clipboard.writeText(text).then(function() {
            showAlert('success', 'Copied to clipboard: ' + text);
        }, function() {
            showAlert('error', 'Failed to copy');
        });
    };

    function loadCustomers() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'get_customers',
                page: currentPage,
                search: $('#searchInput').val(),
                status: $('#statusFilter').val(),
                debt_filter: $('#debtFilter').val()
            },
            dataType: 'json',
            success: function(response) {
                $('#customers-table-container').html(response.table_html);
                $('#pagination-container').html(response.pagination_html);
                attachTableEvents();
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
                $('#customers-table-container').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading data. Please check console for details.</p><button class="btn-primary-custom mt-3" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Retry</button></div>');
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
                const totalDebt = parseFloat(stats.total_debt) || 0;
                $('#stat-total-debt').text(totalDebt > 0 ? '$ ' + totalDebt.toFixed(2) : '-');
                $('#stat-has-user').text(stats.has_user_account || 0);
            },
            error: function() {
                console.log('Error loading stats');
            }
        });
    }

    function attachTableEvents() {
        $('.dropdown-toggle').dropdown();
        
        $('.edit-customer').off('click').on('click', function() {
            const id = $(this).data('id');
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_customer', id: id },
                dataType: 'json',
                success: function(customer) {
                    $('#customerModalLabel').text('Edit Customer');
                    $('#customer_id').val(customer.id);
                    $('#modalCustomerName').val(customer.customer_name);
                    $('#modalPhone').val(customer.phone);
                    $('#modalEmail').val(customer.email);
                    $('#modalAddress').val(customer.address);
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
            if (confirm('Are you sure you want to change this customer\'s status?')) {
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

        // Grant Access Button Handler (PASSWORD = 123)
        $('.btn-grant-access').off('click').on('click', function() {
            const id = $(this).data('id');
            const name = $(this).data('name');
            const email = $(this).data('email');
            const phone = $(this).data('phone');
            
            if (!email) {
                showAlert('error', 'This customer needs an email address to create a user account!');
                return;
            }
            
            if (confirm(`Are you sure you want to create a user account for "${name}"?\n\nEmail: ${email}\nPhone: ${phone || 'N/A'}\n\nPassword: 123 (default)`)) {
                const btn = $(this);
                const originalText = btn.html();
                btn.html('<i class="fas fa-spinner fa-spin"></i> Creating...').prop('disabled', true);
                
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: { ajax_action: 'create_user_account', customer_id: id },
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            // Store credentials for modal
                            currentCredentials = {
                                customer_id: id,
                                customer_name: res.customer_name,
                                email: res.email,
                                password: '123',
                                phone: phone
                            };
                            
                            // Show credentials modal
                            $('#credCustomerName').text(res.customer_name);
                            $('#credEmail').text(res.email);
                            $('#credPassword').text('123');
                            $('#credentialsModal').modal('show');
                            
                            loadCustomers();
                            loadStats();
                            showAlert('success', res.message);
                        } else {
                            showAlert('error', res.message);
                        }
                        btn.html(originalText).prop('disabled', false);
                    },
                    error: function() {
                        showAlert('error', 'Error creating user account');
                        btn.html(originalText).prop('disabled', false);
                    }
                });
            }
        });
        
        // Reset Password Button Handler (PASSWORD = 123)
        $('.btn-reset-password').off('click').on('click', function() {
            const userId = $(this).data('id');
            const customerName = $(this).data('name');
            const phone = $(this).data('phone');
            
            if (confirm(`Are you sure you want to reset the password for "${customerName}" to 123?`)) {
                const btn = $(this);
                const originalText = btn.html();
                btn.html('<i class="fas fa-spinner fa-spin"></i>').prop('disabled', true);
                
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: { ajax_action: 'reset_user_password', user_id: userId },
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            currentCredentials = {
                                customer_name: res.user_name,
                                email: res.email,
                                password: '123',
                                phone: phone
                            };
                            
                            $('#credCustomerName').text(res.user_name);
                            $('#credEmail').text(res.email);
                            $('#credPassword').text('123');
                            $('#credentialsModal').modal('show');
                            
                            showAlert('success', res.message);
                        } else {
                            showAlert('error', res.message);
                        }
                        btn.html(originalText).prop('disabled', false);
                    },
                    error: function() {
                        showAlert('error', 'Error resetting password');
                        btn.html(originalText).prop('disabled', false);
                    }
                });
            }
        });

        $('.view-history').off('click').on('click', function(e) {
            e.preventDefault();
            const id = $(this).data('id');
            const name = $(this).data('name');
            $('#historyCustomerName').text(name);
            $('#historyTableBody').html('<tr><td colspan="5" class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading history...</td></tr>');
            $('#historyTotalDebit, #historyTotalCredit, #historyTotalBalance').text('-').removeClass('text-danger text-success').addClass('text-muted');
            $('#historyModal').modal('show');

            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_customer_history', id: id },
                dataType: 'json',
                success: function(history) {
                    let html = '';
                    let totalBalance = 0;
                    let totalDebit = 0;
                    let totalCredit = 0;
                    
                    if (history.length > 0) {
                        history.forEach(item => {
                            let typeLabel = '';
                            let amountClass = '';
                            let amountSign = '';
                            let amt = parseFloat(item.amount) || 0;
                            
                            if (item.type === 'invoice') {
                                typeLabel = '<span class="badge badge-info">Invoice</span>';
                                amountClass = 'text-danger';
                                amountSign = '+';
                                totalDebit += amt;
                                totalBalance += amt;
                            } 
                            else if (item.type === 'payment') {
                                typeLabel = '<span class="badge badge-success">Payment</span>';
                                amountClass = 'text-success';
                                amountSign = '-';
                                totalCredit += amt;
                                totalBalance -= amt;
                            }
                            
                            const rowUrl = (item.type === 'invoice') ? `invoices.php?search=${item.ref}` : 
                                           (item.type === 'payment') ? `payments.php?search=${item.ref}` : '#';
                            const isClickable = (item.type !== 'loyalty');
                            
                            html += `
                                <tr ${isClickable ? `style="cursor: pointer;" onclick="window.location.href='${rowUrl}'"` : ''}>
                                    <td>${new Date(item.date).toLocaleDateString()}</td>
                                    <td>${typeLabel}</td>
                                    <td>${item.ref || '-'}</td>
                                    <td class="${amountClass} text-right">${amountSign ? amountSign + ' ' : ''}$${amt.toFixed(2)}</td>
                                    <td>${item.status || '-'}</td>
                                </table>
                            `;
                        });
                        
                        html += `
                            <tr style="background: #f8f9fa; font-weight: 800; border-top: 2px solid #dee2e6;">
                                <td colspan="3" class="text-right">Wadar / Total:</td>
                                <td class="text-right ${totalBalance > 0 ? 'text-danger' : (totalBalance < 0 ? 'text-success' : 'text-muted')}">
                                    ${totalBalance !== 0 ? '$' + Math.abs(totalBalance).toFixed(2) : '-'}
                                </td>
                                <td>${totalBalance > 0 ? 'Customer owes' : (totalBalance < 0 ? 'Overpaid' : 'Clear')}</td>
                            </tr>
                        `;
                    } else {
                        html = '<tr><td colspan="5" class="text-center text-muted">No activity found</td></tr>';
                    }

                    $('#historyTotalDebit').text(totalDebit > 0 ? '$ ' + totalDebit.toFixed(2) : '-');
                    $('#historyTotalCredit').text(totalCredit > 0 ? '$ ' + totalCredit.toFixed(2) : '-');
                    $('#historyTotalBalance')
                        .removeClass('text-danger text-success text-muted')
                        .addClass(totalBalance > 0 ? 'text-danger' : (totalBalance < 0 ? 'text-success' : 'text-muted'))
                        .text(totalBalance !== 0 ? '$ ' + Math.abs(totalBalance).toFixed(2) : '-');

                    $('#historyTableBody').html(html);
                },
                error: function() {
                    $('#historyTableBody').html('<tr><td colspan="5" class="text-center text-danger">Error loading history</td></tr>');
                }
            });
        });

        $('.whatsapp-customer').off('click').on('click', function() {
            let phone = $(this).data('phone') ? $(this).data('phone').toString().replace(/\D/g, '') : '';
            const name = $(this).data('name');
            
            if (phone.length === 9 && (phone.startsWith('6') || phone.startsWith('7'))) {
                phone = '252' + phone;
            }
            
            if (!phone) { 
                showAlert('error', 'This customer does not have a valid phone number!'); 
                return; 
            }
            const message = `Hello ${name}, `;
            const url = `https://api.whatsapp.com/send?phone=${phone}&text=${encodeURIComponent(message)}`;
            window.open(url, '_blank');
        });

        $('.print-statement').off('click').on('click', function() {
            const id = $(this).data('id');
            const name = $(this).data('name');
            
            $('#statementCustomerName').text(name);
            $('#statementBody').html('<tr><td colspan="6" class="text-center">Loading...</td></tr>');
            $('#statementModal').modal('show');
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_statement', customer_id: id },
                dataType: 'json',
                success: function(res) {
                    if (res.success && res.customer) {
                        const currentDebt = parseFloat(res.customer.debt_amount) || 0;
                        $('#statementDebtAmount').text(currentDebt > 0 ? '$ ' + currentDebt.toFixed(2) : '-').css('color', currentDebt > 0 ? '#B42318' : '#0F7A3A');
                        let totalDebit = 0;
                        let totalCredit = 0;
                        let html = '';
                        if (res.transactions && res.transactions.length > 0) {
                            res.transactions.forEach(t => {
                                const debit = parseFloat(t.debit) || 0;
                                const credit = parseFloat(t.credit) || 0;
                                totalDebit += debit;
                                totalCredit += credit;
                                html += `
                                    <tr>
                                        <td>${t.date}</td>
                                        <td><span class="badge badge-${t.type === 'Invoice' ? 'danger' : 'success'}">${t.type}</span></td>
                                        <td>${t.ref}</td>
                                        <td class="text-right">${debit > 0 ? '$ ' + debit.toFixed(2) : '-'}</td>
                                        <td class="text-right">${credit > 0 ? '$ ' + credit.toFixed(2) : '-'}</td>
                                        <td><span class="badge badge-light">${t.status}</span></td>
                                    </tr>
                                `;
                            });
                            const balance = totalDebit - totalCredit;
                            html += `
                                <tr style="background:#f8f9fa;font-weight:800;border-top:2px solid #dee2e6;">
                                    <td colspan="3" class="text-right">Wadar / Total:</td>
                                    <td class="text-right">${totalDebit > 0 ? '$ ' + totalDebit.toFixed(2) : '-'}</td>
                                    <td class="text-right">${totalCredit > 0 ? '$ ' + totalCredit.toFixed(2) : '-'}</td>
                                    <td><span class="badge badge-${balance > 0 ? 'danger' : (balance < 0 ? 'success' : 'light')}">${balance !== 0 ? '$ ' + Math.abs(balance).toFixed(2) : 'Clear'}</span></td>
                                </tr>
                            `;
                        } else {
                            html = '<tr><td colspan="6" class="text-center">No transactions found.</td></tr>';
                        }
                        $('#statementBody').html(html);
                    } else {
                        $('#statementBody').html('<tr><td colspan="6" class="text-center text-danger">Failed to load statement.</td></tr>');
                    }
                },
                error: function() {
                    $('#statementBody').html('<tr><td colspan="6" class="text-center text-danger">Error loading statement.</td></tr>');
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
        const alertClass = type === 'success' ? 'alert-success' : 'alert-error';
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        $('#alert-placeholder').html(`<div class="alert alert-custom ${alertClass} alert-dismissible fade show"><i class="fas ${icon} mr-2"></i> ${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
        setTimeout(() => $('.alert-custom').fadeOut(5000, function() { $(this).remove(); }), 5000);
    }

    $('#customerForm').submit(function(e) {
        e.preventDefault();
        
        if (!$('#modalCustomerName').val()) { 
            showAlert('error', 'Please enter the customer name'); 
            return; 
        }
        
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
                } else { 
                    showAlert('error', res.message); 
                }
            },
            error: function() { 
                showAlert('error', 'An error occurred'); 
            }
        });
    });

    $('#smsForm').submit(function(e) {
        e.preventDefault();
        
        if (!$('#smsPhone').val()) { 
            showAlert('error', 'Please enter the phone number'); 
            return; 
        }
        if (!$('#smsMessage').val()) { 
            showAlert('error', 'Please enter the message'); 
            return; 
        }
        
        const submitBtn = $(this).find('button[type="submit"]');
        const originalText = submitBtn.html();
        submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Sending...').prop('disabled', true);
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $(this).serialize() + '&ajax_action=send_sms',
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#smsModal').modal('hide');
                    showAlert('success', res.message);
                } else { 
                    showAlert('error', res.message); 
                }
                submitBtn.html(originalText).prop('disabled', false);
            },
            error: function() { 
                showAlert('error', 'Error occurred while sending message');
                submitBtn.html(originalText).prop('disabled', false);
            }
        });
    });
    
    // Manual resend WhatsApp only if needed. Main sending is automatic from PHP after Grant Access / Reset Password.
    $('#sendCredentialsViaWhatsApp').click(function() {
        let phone = currentCredentials.phone || '';
        
        if (!phone) {
            showAlert('error', 'Customer does not have a phone number to send credentials!');
            return;
        }
        
        let formattedPhone = phone.toString().replace(/\D/g, '');
        if (formattedPhone.length === 9 && (formattedPhone.startsWith('6') || formattedPhone.startsWith('7'))) {
            formattedPhone = '252' + formattedPhone;
        }
        
        const loginUrl = "<?= (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/../login.php' ?>";
        const message = `*Welcome to ${$('#stat-total').text()} System!* 🎉\n\nYour account has been created successfully!\n\n🔐 *Login Credentials:*\n📧 Email: ${currentCredentials.email}\n🔑 Password: 123\n\n🌐 Login URL: ${loginUrl}\n👤 Role: Customer\n\nPlease change your password after first login.\n\nThank you!`;
        
        const whatsappUrl = `https://api.whatsapp.com/send?phone=${formattedPhone}&text=${encodeURIComponent(message)}`;
        window.open(whatsappUrl, '_blank');
        
        $('#credentialsModal').modal('hide');
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
                    } else { 
                        showAlert('error', res.message); 
                    }
                    deleteId = null;
                }
            });
        }
    });

    $('#addCustomerBtn, #addCustomerBtnEmpty').click(function() {
        $('#customerModalLabel').text('New Customer');
        $('#customerForm')[0].reset();
        $('#customer_id').val('');
        $('#modalIsActive').val(1);
        $('#modalCreditLimit').val(0);
        $('#modalPaymentTerms').val(30);
        $('#customerModal').modal('show');
    });

    $('#applyFilters').click(function() { 
        currentPage = 1; 
        loadCustomers(); 
    });
    
    $('#resetFilters').click(function() { 
        $('#searchInput').val(''); 
        $('#statusFilter').val(''); 
        $('#debtFilter').val(''); 
        currentPage = 1; 
        loadCustomers(); 
        loadStats();
    });
    
    $('#searchInput').keypress(function(e) { 
        if (e.which === 13) { 
            currentPage = 1; 
            loadCustomers(); 
        } 
    });

    $('#searchIconBtn').click(function() {
        $('.filters-card').slideToggle();
    });

    $('.filters-card').hide();

    $('#exportExcelBtn').click(function() {
        let params = new URLSearchParams({
            ajax_action: 'export_customers',
            search: $('#searchInput').val(),
            status: $('#statusFilter').val(),
            debt_filter: $('#debtFilter').val()
        }).toString();
        window.location.href = window.location.pathname + '?' + params;
    });

    $('#importExcelBtn').click(function() {
        $('#importModal').modal('show');
    });

    $('#importForm').submit(function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('ajax_action', 'import_customers');
        
        const submitBtn = $(this).find('button[type="submit"]');
        const originalText = submitBtn.html();
        submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Importing...').prop('disabled', true);
        
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
                } else { 
                    showAlert('error', res.message); 
                }
                submitBtn.html(originalText).prop('disabled', false);
            },
            error: function() { 
                showAlert('error', 'Error occurred during import');
                submitBtn.html(originalText).prop('disabled', false);
            }
        });
    });

    $('#refreshDataBtn').click(function() {
        currentPage = 1;
        loadCustomers();
        loadStats();
        showAlert('success', 'Data refreshed successfully');
    });

    loadCustomers();
    loadStats();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>