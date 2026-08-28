<?php
// superadmin/tenants/index.php or tenants.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['superadmin', 'company_admin'])) {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION['role'];
$session_tenant_id = $_SESSION['tenant_id'] ?? 0;

require_once __DIR__ . '/../config/db_connect.php';

/*
|--------------------------------------------------------------------------
| PHPMailer
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/../config/secrets.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', SMTP_USERNAME);
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', 'Cargo Management System');

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Super Admin';

function getLoginUrl($customLoginLink = null)
{
    // Haddii la bixiyay custom login link, isticmaal taas
    if (!empty($customLoginLink)) {
        return $customLoginLink;
    }
    
    // Hadii kale isticmaal default link
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    
    return $protocol . '://' . $host . '/superadmin/login.php';
}

function sendCompanyAdminEmail($toEmail, $adminName, $companyName, $username, $password, $customLoginLink = null)
{
    $loginUrl = getLoginUrl($customLoginLink);
    
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $adminName);
        
        $mail->isHTML(true);
        $mail->Subject = 'Cargo Management System - Account-kaaga waa la abuuray';
        
        $safeAdminName = htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8');
        $safeCompanyName = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
        $safeUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $safePassword = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');
        $safeLoginUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
        
        $mail->Body = "
        <div style='font-family:Arial,sans-serif;background:#f4f6f9;padding:25px;'>
            <div style='max-width:600px;margin:auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #ddd;'>
                <div style='background:#2D1859;color:white;padding:22px;text-align:center;'>
                    <h2 style='margin:0;'>Cargo Management System</h2>
                    <p style='margin:6px 0 0;'>Company Admin Account</p>
                </div>
                
                <div style='padding:25px;color:#333;'>
                    <h3>Asc {$safeAdminName},</h3>
                    
                    <p>Waxaa laguu abuuray account cusub oo aad ku maamuli karto shirkadda:</p>
                    
                    <p><strong>Shirkadda:</strong> {$safeCompanyName}</p>
                    
                    <div style='background:#f8f6f9;border-left:4px solid #2D1859;padding:15px;margin:20px 0;'>
                        <p><strong>Username / Email:</strong> {$safeUsername}</p>
                        <p><strong>Password:</strong> {$safePassword}</p>
                    </div>
                    
                    <p>Si aad u gasho system-ka, riix button-ka hoose:</p>
                    
                    <p style='text-align:center;margin:25px 0;'>
                        <a href='{$safeLoginUrl}' style='background:#F5C410;color:#2D1859;padding:12px 25px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block;'>
                            Login System
                        </a>
                    </p>
                    
                    <p style='font-size:13px;color:#777;'>Ama copy garee link-kan:</p>
                    <p style='font-size:13px;color:#2D1859;'>{$safeLoginUrl}</p>

                    <p style='color:#B42318;font-size:13px;'>
                        Fadlan password-ka beddel marka ugu horreysa ee aad gasho system-ka.
                    </p>
                </div>
            </div>
        </div>";
        
        $mail->AltBody = "Cargo Management System Account\n\nCompany: {$companyName}\nUsername: {$username}\nPassword: {$password}\nLogin: {$loginUrl}";
        
        return $mail->send();
        
    } catch (Exception $e) {
        error_log('PHPMailer Error: ' . $mail->ErrorInfo);
        return false;
    }
}

function calculateSubscriptionEndDate($startDate, $billingCycle)
{
    $date = new DateTime($startDate);
    
    switch ($billingCycle) {
        case 'monthly':
            $date->modify('+1 month');
            break;
        case 'quarterly':
            $date->modify('+3 months');
            break;
        case 'bi_annual':
            $date->modify('+6 months');
            break;
        case 'annual':
            $date->modify('+1 year');
            break;
        default:
            $date->modify('+1 month');
    }
    
    return $date->format('Y-m-d');
}

function updateExpiredSubscriptions($pdo)
{
    $today = date('Y-m-d');
    
    $stmt = $pdo->prepare("
        UPDATE tenants 
        SET is_active = 0, subscription_status = 'expired', updated_at = NOW()
        WHERE subscription_end_date IS NOT NULL 
        AND subscription_end_date < ?
        AND is_active = 1
        AND subscription_status = 'active'
    ");
    $stmt->execute([$today]);
    
    $stmt2 = $pdo->prepare("
        UPDATE users u
        JOIN tenants t ON u.tenant_id = t.id
        SET u.is_active = 0
        WHERE t.subscription_end_date IS NOT NULL 
        AND t.subscription_end_date < ?
        AND u.is_active = 1
        AND u.role_type != 'superadmin'
    ");
    $stmt2->execute([$today]);
    
    return $stmt->rowCount();
}

try {
    $pdo->exec("ALTER TABLE tenants ADD COLUMN IF NOT EXISTS subscription_start_date DATE DEFAULT NULL");
    $pdo->exec("ALTER TABLE tenants ADD COLUMN IF NOT EXISTS subscription_end_date DATE DEFAULT NULL");
    $pdo->exec("ALTER TABLE tenants ADD COLUMN IF NOT EXISTS billing_cycle ENUM('monthly','quarterly','bi_annual','annual') DEFAULT 'monthly'");
    $pdo->exec("ALTER TABLE tenants ADD COLUMN IF NOT EXISTS subscription_status ENUM('active','expired','cancelled','trial') DEFAULT 'active'");
    $pdo->exec("ALTER TABLE tenants ADD COLUMN IF NOT EXISTS auto_renew TINYINT(1) DEFAULT 1");
    $pdo->exec("ALTER TABLE tenants ADD COLUMN IF NOT EXISTS last_invoice_date DATE DEFAULT NULL");
    $pdo->exec("ALTER TABLE tenants ADD COLUMN IF NOT EXISTS subscription_price DECIMAL(10,2) DEFAULT 0.00");
    $pdo->exec("ALTER TABLE tenants ADD COLUMN IF NOT EXISTS custom_login_link VARCHAR(255) DEFAULT NULL");
} catch (PDOException $e) {
}

updateExpiredSubscriptions($pdo);

/*
|--------------------------------------------------------------------------
| GET EXPORT
|--------------------------------------------------------------------------
*/
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action === 'export_tenants') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=tenants_export_' . date('Y-m-d') . '.csv');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        
        fputcsv($output, [
            'ID',
            'Company Name',
            'Code',
            'Email',
            'Phone',
            'Address',
            'Billing Cycle',
            'Price',
            'Status',
            'Start Date',
            'End Date',
            'Admin Name',
            'Admin Email',
            'Custom Login Link'
        ]);
        
        $where_conditions = [];
        $params = [];
        
        $search = $_GET['search'] ?? '';
        if (!empty($search)) {
            $where_conditions[] = "(t.name LIKE ? OR t.code LIKE ? OR t.email LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($role === 'company_admin') {
            $where_conditions[] = "t.id = ?";
            $params[] = $session_tenant_id;
        }
        
        $where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);
        
        $sql = "SELECT t.*, u.full_name as admin_name, u.email as admin_email 
                FROM tenants t 
                LEFT JOIN users u ON t.id = u.tenant_id AND u.role_type = 'company_admin' 
                $where_clause 
                ORDER BY t.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['id'],
                $row['name'],
                $row['code'],
                $row['email'],
                $row['phone'],
                $row['address'],
                $row['billing_cycle'],
                $row['subscription_price'],
                $row['is_active'] ? 'Active' : 'Inactive',
                $row['subscription_start_date'],
                $row['subscription_end_date'],
                $row['admin_name'],
                $row['admin_email'],
                $row['custom_login_link']
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    if ($action === 'download_sample') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=tenants_sample.csv');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        
        fputcsv($output, [
            'Company Name',
            'Code',
            'Email',
            'Phone',
            'Address',
            'Billing Cycle',
            'Admin Name',
            'Admin Email',
            'Custom Login Link'
        ]);
        
        fputcsv($output, [
            'Example Logistics',
            'EXL',
            'info@example.com',
            '123456789',
            'Main St, City',
            'monthly',
            'John Doe',
            'john@example.com',
            'https://example.com/superadmin/login.php'
        ]);
        
        fclose($output);
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| AJAX
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['ajax_action'];
    
    if ($action === 'get_tenants') {
        $page = isset($_POST['page']) ? max(1, (int)$_POST['page']) : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $search = $_POST['search'] ?? '';
        $status_filter = $_POST['status'] ?? '';
        $subscription_status_filter = $_POST['subscription_status'] ?? '';
        
        $where_conditions = [];
        $params = [];
        
        if (!empty($search)) {
            $where_conditions[] = "(t.name LIKE ? OR t.code LIKE ? OR t.email LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
            for ($i = 0; $i < 6; $i++) {
                $params[] = "%$search%";
            }
        }
        
        if ($status_filter !== '') {
            $where_conditions[] = "t.is_active = ?";
            $params[] = $status_filter === 'active' ? 1 : 0;
        }
        
        if (!empty($subscription_status_filter)) {
            $where_conditions[] = "t.subscription_status = ?";
            $params[] = $subscription_status_filter;
        }
        
        if ($role === 'company_admin') {
            $where_conditions[] = "t.id = ?";
            $params[] = $session_tenant_id;
        }
        
        $where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);
        
        $count_sql = "SELECT COUNT(DISTINCT t.id) as total 
                      FROM tenants t 
                      LEFT JOIN users u ON t.id = u.tenant_id AND u.role_type = 'company_admin'
                      $where_clause";
        
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_tenants = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = max(1, ceil($total_tenants / $limit));
        
        $sql = "
            SELECT 
                t.*,
                u.id as admin_id,
                u.full_name as admin_name,
                u.email as admin_email,
                u.phone as admin_phone,
                u.profile_image as admin_profile_image,
                u.created_at as admin_created_at,
                (SELECT COUNT(*) FROM users WHERE tenant_id = t.id AND role_type != 'superadmin') as total_users,
                (SELECT COUNT(*) FROM users WHERE tenant_id = t.id AND role_type != 'superadmin' AND is_active = 1) as active_users,
                DATEDIFF(t.subscription_end_date, CURDATE()) as days_remaining
            FROM tenants t
            LEFT JOIN users u ON t.id = u.tenant_id AND u.role_type = 'company_admin'
            $where_clause
            GROUP BY t.id
            ORDER BY t.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_start();
        ?>
        <div class="tenants-table-container">
            <table class="tenants-table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Shirkadda</th>
                    <th>Code</th>
                    <th>Maamulaha</th>
                    <th>Users</th>
                    <th>Active</th>
                    <th>Billing</th>
                    <th>Sub. Xaalad</th>
                    <th>Mudada</th>
                    <th>Xaaladda</th>
                    <th>Taariikhda</th>
                    <th>Falalka</th>
                </tr>
                </thead>
                <tbody>
                <?php if (count($tenants) > 0): ?>
                    <?php foreach ($tenants as $tenant): ?>
                        <?php
                        $daysRemaining = $tenant['days_remaining'] ?? null;
                        $expiryClass = '';
                        $expiryText = '-';
                        
                        if (!empty($tenant['subscription_end_date'])) {
                            if ($daysRemaining !== null && $daysRemaining < 0) {
                                $expiryClass = 'text-danger';
                                $expiryText = 'Dhammaaday';
                            } elseif ($daysRemaining !== null && $daysRemaining <= 7) {
                                $expiryClass = 'text-warning';
                                $expiryText = $daysRemaining . ' maalmood';
                            } else {
                                $expiryClass = 'text-success';
                                $expiryText = $daysRemaining . ' maalmood';
                            }
                        }
                        
                        $billingLabels = [
                            'monthly' => 'Bil kasta',
                            'quarterly' => '3 bilood',
                            'bi_annual' => '6 bilood',
                            'annual' => 'Sannad'
                        ];
                        
                        $billingLabel = $billingLabels[$tenant['billing_cycle'] ?? 'monthly'] ?? 'Bil kasta';
                        
                        $subStatusColors = [
                            'active' => '#2e7d32',
                            'expired' => '#c62828',
                            'cancelled' => '#ff8f00',
                            'trial' => '#1565c0'
                        ];
                        
                        $subStatusNames = [
                            'active' => 'Firfircoon',
                            'expired' => 'Dhammaaday',
                            'cancelled' => 'La Joojiyay',
                            'trial' => 'Tijaabo'
                        ];
                        
                        $subStatus = $tenant['subscription_status'] ?? 'active';
                        $subColor = $subStatusColors[$subStatus] ?? '#6c757d';
                        ?>
                        <tr>
                            <td><?= (int)$tenant['id'] ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:12px;">
                                    <?php if (!empty($tenant['logo_url'])): ?>
                                        <img src="../<?= htmlspecialchars($tenant['logo_url']) ?>" style="width:45px;height:45px;border-radius:10px;object-fit:cover;border:2px solid #2D1859;">
                                    <?php else: ?>
                                        <div style="width:45px;height:45px;background:#2D1859;border-radius:10px;display:flex;align-items:center;justify-content:center;color:white;">
                                            <i class="fas fa-building"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <strong><?= htmlspecialchars($tenant['name']) ?></strong>
                                        <div style="font-size:11px;color:#6c757d;">
                                            <i class="fas fa-envelope"></i> <?= htmlspecialchars($tenant['email'] ?? '-') ?>
                                        </div>
                                        <div style="font-size:11px;color:#6c757d;">
                                            <i class="fas fa-phone"></i> <?= htmlspecialchars($tenant['phone'] ?? '-') ?>
                                        </div>
                                        <?php if (!empty($tenant['custom_login_link'])): ?>
                                            <div style="font-size:10px;color:#2D1859;">
                                                <i class="fas fa-link"></i> Custom URL
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                             </div>
                            <td><span class="code-badge"><?= htmlspecialchars($tenant['code']) ?></span></td>
                            <td>
                                <?php if (!empty($tenant['admin_name'])): ?>
                                    <div style="background:#EEFBF3;padding:10px;border-radius:10px;display:flex;align-items:center;gap:12px;">
                                        <img src="<?= !empty($tenant['admin_profile_image']) ? '../' . htmlspecialchars($tenant['admin_profile_image']) : '../uploads/profiles/default.png' ?>" style="width:45px;height:45px;border-radius:50%;object-fit:cover;border:2px solid #2D1859;">
                                        <div>
                                            <strong><?= htmlspecialchars($tenant['admin_name']) ?></strong>
                                            <div style="font-size:12px;">
                                                <div><i class="fas fa-envelope"></i> <?= htmlspecialchars($tenant['admin_email']) ?></div>
                                                <div><i class="fas fa-phone"></i> <?= htmlspecialchars($tenant['admin_phone'] ?? '-') ?></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted"><i class="fas fa-exclamation-triangle"></i> Ma jiro maamulaha</span>
                                <?php endif; ?>
                             </div>
                            <td>
                                <span class="badge" style="background:#2D1859;color:white;padding:6px 12px;border-radius:20px;">
                                    <?= (int)($tenant['total_users'] ?? 0) ?>
                                </span>
                             </div>
                            <td>
                                <span class="badge" style="background:#0F7A3A;color:white;padding:6px 12px;border-radius:20px;">
                                    <?= (int)($tenant['active_users'] ?? 0) ?>
                                </span>
                             </div>
                            <td>
                                <span style="background:#e8eaf6;color:#3949ab;padding:4px 10px;border-radius:20px;font-size:11px;">
                                    <?= $billingLabel ?>
                                </span>
                                <?php if ((float)($tenant['subscription_price'] ?? 0) > 0): ?>
                                    <div style="font-size:10px;color:#1565c0;margin-top:3px;">
                                        $<?= number_format((float)$tenant['subscription_price'], 2) ?>
                                    </div>
                                <?php endif; ?>
                             </div>
                            <td>
                                <span style="background:<?= $subColor ?>20;color:<?= $subColor ?>;padding:4px 10px;border-radius:20px;font-size:11px;">
                                    <?= $subStatusNames[$subStatus] ?? htmlspecialchars($subStatus) ?>
                                </span>
                             </div>
                            <td>
                                <?php if (!empty($tenant['subscription_start_date']) && !empty($tenant['subscription_end_date'])): ?>
                                    <div style="font-size:11px;">
                                        <div><i class="fas fa-play-circle"></i> <?= date('d/m/Y', strtotime($tenant['subscription_start_date'])) ?></div>
                                        <div><i class="fas fa-stop-circle"></i> <?= date('d/m/Y', strtotime($tenant['subscription_end_date'])) ?></div>
                                        <div class="<?= $expiryClass ?>">
                                            <i class="fas fa-hourglass-half"></i> <?= $expiryText ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                             </div>
                            <td>
                                <span class="status-badge <?= $tenant['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                    <?= $tenant['is_active'] ? 'Firfircoon' : 'Aan Firfircooneyn' ?>
                                </span>
                             </div>
                            <td><?= !empty($tenant['created_at']) ? date('d/m/Y', strtotime($tenant['created_at'])) : '-' ?> </div>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn btn-edit edit-tenant" data-id="<?= (int)$tenant['id'] ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="action-btn btn-renew renew-subscription" data-id="<?= (int)$tenant['id'] ?>" data-name="<?= htmlspecialchars($tenant['name']) ?>">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                    <button class="action-btn btn-toggle toggle-status" data-id="<?= (int)$tenant['id'] ?>">
                                        <i class="fas <?= $tenant['is_active'] ? 'fa-ban' : 'fa-check-circle' ?>"></i>
                                    </button>
                                    <?php if ($role === 'superadmin'): ?>
                                        <button class="action-btn btn-delete delete-tenant" data-id="<?= (int)$tenant['id'] ?>" data-name="<?= htmlspecialchars($tenant['name']) ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                             </div>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="12" style="text-align:center;padding:50px;">
                            <div class="empty-state">
                                <i class="fas fa-building-slash"></i>
                                <p>Ma jiraan shirkado ku habboon shaandhaynta</p>
                                <?php if ($role === 'superadmin'): ?>
                                    <button class="btn-primary-custom" id="addTenantBtnEmpty">
                                        <i class="fas fa-plus-circle"></i> Ku dar Shirkad Cusub
                                    </button>
                                <?php endif; ?>
                            </div>
                         </div>
                    </tr>
                <?php endif; ?>
                </tbody>
             </div>
        </div>
        <?php
        $table_html = ob_get_clean();
        
        ob_start();
        if ($total_pages > 1):
            ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a data-page="<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i> Hore</a>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active"><?= $i ?></span>
                    <?php else: ?>
                        <a data-page="<?= $i ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a data-page="<?= $page + 1 ?>">Danbe <i class="fas fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        <?php
        endif;
        $pagination_html = ob_get_clean();
        
        echo json_encode([
            'table_html' => $table_html,
            'pagination_html' => $pagination_html
        ]);
        exit;
    }
    
    if ($action === 'get_tenant') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($role === 'company_admin' && $id != $session_tenant_id) {
            echo json_encode(['success' => false, 'message' => 'Ma laguu ogola inaad xogtaan aragto']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                t.*,
                u.id as admin_id,
                u.full_name as admin_name,
                u.email as admin_email,
                u.phone as admin_phone,
                u.profile_image as admin_profile_image
            FROM tenants t
            LEFT JOIN users u ON t.id = u.tenant_id AND u.role_type = 'company_admin'
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode($tenant ?: []);
        exit;
    }
    
    if ($action === 'save_tenant') {
        $id = $_POST['tenant_id'] ?? '';
        
        if ($role === 'company_admin') {
            if (empty($id)) {
                echo json_encode(['success' => false, 'message' => 'Ma laguu ogola inaad abuurto shirkad cusub']);
                exit;
            }
            
            if ((int)$id !== (int)$session_tenant_id) {
                echo json_encode(['success' => false, 'message' => 'Ma laguu ogola inaad wax ka beddesho shirkad kale']);
                exit;
            }
        }
        
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $billing_cycle = $_POST['billing_cycle'] ?? 'monthly';
        $is_active = (int)($_POST['is_active'] ?? 1);
        $loyalty_cbm_points = (int)($_POST['loyalty_cbm_points'] ?? 10);
        $loyalty_amount_points = (int)($_POST['loyalty_amount_points'] ?? 5);
        $default_language = $_POST['default_language'] ?? 'so';
        $timezone = $_POST['timezone'] ?? 'Africa/Mogadishu';
        $subscription_start_date = !empty($_POST['subscription_start_date']) ? $_POST['subscription_start_date'] : date('Y-m-d');
        $auto_renew = isset($_POST['auto_renew']) ? 1 : 0;
        $subscription_price = !empty($_POST['subscription_price']) ? (float)$_POST['subscription_price'] : 0;
        $custom_login_link = !empty($_POST['custom_login_link']) ? trim($_POST['custom_login_link']) : null;
        $subscription_end_date = calculateSubscriptionEndDate($subscription_start_date, $billing_cycle);
        
        $admin_name = trim($_POST['admin_name'] ?? '');
        $admin_email = trim($_POST['admin_email'] ?? '');
        $admin_phone = trim($_POST['admin_phone'] ?? '');
        $admin_id = $_POST['admin_id'] ?? '';
        
        if (empty($name) || empty($code)) {
            echo json_encode(['success' => false, 'message' => 'Magaca iyo Code-ga waa lagama maarmaan']);
            exit;
        }
        
        if (empty($id) && (empty($admin_name) || empty($admin_email) || empty($admin_phone))) {
            echo json_encode(['success' => false, 'message' => 'Magaca, Emailka iyo Telefoonka Maamulaha waa lagama maarmaan']);
            exit;
        }
        
        $allowedCycles = ['monthly', 'quarterly', 'bi_annual', 'annual'];
        if (!in_array($billing_cycle, $allowedCycles, true)) {
            $billing_cycle = 'monthly';
        }
        
        $logo_path = $_POST['existing_logo'] ?? '';
        
        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../../uploads/companies/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $ext = strtolower(pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
            $maxSize = 2 * 1024 * 1024;
            
            if ($_FILES['company_logo']['size'] <= $maxSize && in_array($ext, $allowed, true)) {
                $logoName = 'company_' . time() . '_' . uniqid() . '.' . $ext;
                $targetPath = $uploadDir . $logoName;
                
                if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $targetPath)) {
                    $logo_path = 'uploads/companies/' . $logoName;
                }
            }
        }
        
        $admin_profile_path = $_POST['existing_admin_profile'] ?? '';
        
        if (isset($_FILES['admin_profile_image']) && $_FILES['admin_profile_image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../../uploads/profiles/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $ext = strtolower(pathinfo($_FILES['admin_profile_image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $maxSize = 2 * 1024 * 1024;
            
            if ($_FILES['admin_profile_image']['size'] <= $maxSize && in_array($ext, $allowed, true)) {
                $profileName = 'admin_' . time() . '_' . uniqid() . '.' . $ext;
                $targetPath = $uploadDir . $profileName;
                
                if (move_uploaded_file($_FILES['admin_profile_image']['tmp_name'], $targetPath)) {
                    $admin_profile_path = 'uploads/profiles/' . $profileName;
                }
            }
        }
        
        try {
            if (empty($id)) {
                $check = $pdo->prepare("SELECT id FROM tenants WHERE code = ? LIMIT 1");
                $check->execute([$code]);
                
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'message' => "Code-ga '$code' waxaa horay loo isticmaalay"]);
                    exit;
                }
                
                if (!empty($admin_email)) {
                    $check2 = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                    $check2->execute([$admin_email]);
                    
                    if ($check2->fetch()) {
                        echo json_encode(['success' => false, 'message' => "Emailka '$admin_email' waxaa horay loo isticmaalay"]);
                        exit;
                    }
                }
                
                $pdo->beginTransaction();
                
                $sql = "INSERT INTO tenants (
                    name, code, email, phone, address, is_active, 
                    loyalty_cbm_points, loyalty_amount_points, default_language, timezone, logo_url,
                    billing_cycle, subscription_price, subscription_start_date, subscription_end_date,
                    subscription_status, auto_renew, custom_login_link, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, 'active', ?, ?, ?, NOW()
                )";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $name,
                    $code,
                    $email,
                    $phone,
                    $address,
                    $is_active,
                    $loyalty_cbm_points,
                    $loyalty_amount_points,
                    $default_language,
                    $timezone,
                    $logo_path,
                    $billing_cycle,
                    $subscription_price,
                    $subscription_start_date,
                    $subscription_end_date,
                    $auto_renew,
                    $custom_login_link,
                    $_SESSION['user_id']
                ]);
                
                $tenant_id = $pdo->lastInsertId();

                // Secure temporary-password provisioning (replaces legacy fixed '123').
                require_once __DIR__ . '/../includes/admin_audit.php';
                $__sa_alphabet = 'ABCDEFGHIJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
                $plainPassword = '';
                for ($__i = 0; $__i < 12; $__i++) {
                    $plainPassword .= $__sa_alphabet[random_int(0, strlen($__sa_alphabet) - 1)];
                }
                $emailSent = false;
                
                if (!empty($admin_name) && !empty($admin_email)) {
                    $hashed_password = password_hash($plainPassword, PASSWORD_DEFAULT);
                    
                    $sql2 = "INSERT INTO users (
                        tenant_id, email, password_hash, full_name, phone, role_type, 
                        is_active, profile_image, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, 'company_admin', ?, ?, ?, NOW())";
                    
                    $stmt2 = $pdo->prepare($sql2);
                    $stmt2->execute([
                        $tenant_id,
                        $admin_email,
                        $hashed_password,
                        $admin_name,
                        $admin_phone,
                        $is_active,
                        $admin_profile_path,
                        $_SESSION['user_id']
                    ]);
                }
                
                record_admin_audit($pdo, 'TENANT_CREATED', 'tenants', (int)$tenant_id,
                    null,
                    ['name' => $name, 'code' => $code, 'billing_cycle' => $billing_cycle, 'is_active' => $is_active, 'admin_email' => $admin_email],
                    (int)$tenant_id);
                $pdo->commit();

                if (!empty($admin_email)) {
                    $emailSent = sendCompanyAdminEmail(
                        $admin_email,
                        $admin_name,
                        $name,
                        $admin_email,
                        $plainPassword,
                        $custom_login_link
                    );
                }
                
                $renewalMessage = $auto_renew ? 'Waxaa iskiis u cusboonaan doona' : 'Waa inaad gacanta ku cusboonaysiisaa';
                $customLinkMsg = $custom_login_link ? "<br><span style='color:#2D1859;'>🔗 Custom Login Link: $custom_login_link</span>" : "";
                
                $emailMsg = $emailSent
                    ? "<br><span style='color:green;'>✅ Email ogeysiis ah waa loo diray maamulaha.</span>"
                    : "<br><span style='color:red;'>⚠️ Company waa la abuuray, laakiin email-ka lama dirin. Hubi SMTP/App Password.</span>";
                
                echo json_encode([
                    'success' => true,
                    'message' => "Shirkad '$name' waa la abuuray!<br>
                    Maamulaha: $admin_email<br>
                    Password (show once): $plainPassword<br>
                    Login Link: <a href='" . getLoginUrl($custom_login_link) . "' target='_blank'>" . getLoginUrl($custom_login_link) . "</a><br>
                    Billing: $billing_cycle<br>
                    Qiimaha: $" . number_format($subscription_price, 2) . "<br>
                    Dhammaadka: " . date('d/m/Y', strtotime($subscription_end_date)) . "<br>
                    $renewalMessage
                    $customLinkMsg
                    $emailMsg"
                ]);
                exit;
            }
            
            // Ownership/existence gate for update: a supplied tenant id must resolve.
            require_once __DIR__ . '/../includes/admin_audit.php';
            $__existing_tenant = null;
            $__t_chk = $pdo->prepare("SELECT id, name, code, is_active, billing_cycle, subscription_price, auto_renew, default_language, timezone FROM tenants WHERE id = ? LIMIT 1");
            $__t_chk->execute([(int)$id]);
            $__existing_tenant = $__t_chk->fetch(PDO::FETCH_ASSOC);
            if (!$__existing_tenant) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found']);
                exit;
            }

            $sql = "UPDATE tenants SET
                    name=?, email=?, phone=?, address=?, is_active=?,
                    loyalty_cbm_points=?, loyalty_amount_points=?, default_language=?, timezone=?,
                    logo_url=?, billing_cycle=?, subscription_price=?,
                    subscription_start_date=?, subscription_end_date=?, auto_renew=?,
                    custom_login_link=?, updated_at=NOW()
                    WHERE id=?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $name,
                $email,
                $phone,
                $address,
                $is_active,
                $loyalty_cbm_points,
                $loyalty_amount_points,
                $default_language,
                $timezone,
                $logo_path,
                $billing_cycle,
                $subscription_price,
                $subscription_start_date,
                $subscription_end_date,
                $auto_renew,
                $custom_login_link,
                $id
            ]);
            
            if (!empty($admin_name) && !empty($admin_email)) {
                if (!empty($admin_id)) {
                    $sql3 = "UPDATE users 
                             SET full_name=?, email=?, phone=?, is_active=?, profile_image=? 
                             WHERE id=? AND role_type='company_admin'";
                    
                    $stmt3 = $pdo->prepare($sql3);
                    $stmt3->execute([
                        $admin_name,
                        $admin_email,
                        $admin_phone,
                        $is_active,
                        $admin_profile_path,
                        $admin_id
                    ]);
                } else {
                    $checkEmail = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                    $checkEmail->execute([$admin_email]);
                    
                    if ($checkEmail->fetch()) {
                        echo json_encode(['success' => false, 'message' => "Emailka '$admin_email' waxaa horay loo isticmaalay"]);
                        exit;
                    }
                    
                    // Secure temporary-password provisioning (replaces legacy fixed '123').
                    $__sa_alphabet = 'ABCDEFGHIJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
                    $plainPassword = '';
                    for ($__i = 0; $__i < 12; $__i++) {
                        $plainPassword .= $__sa_alphabet[random_int(0, strlen($__sa_alphabet) - 1)];
                    }
                    $hashed_password = password_hash($plainPassword, PASSWORD_DEFAULT);

                    $sql4 = "INSERT INTO users (
                        tenant_id, email, password_hash, full_name, phone, role_type,
                        is_active, profile_image, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, 'company_admin', ?, ?, ?, NOW())";
                    
                    $stmt4 = $pdo->prepare($sql4);
                    $stmt4->execute([
                        $id,
                        $admin_email,
                        $hashed_password,
                        $admin_name,
                        $admin_phone,
                        $is_active,
                        $admin_profile_path,
                        $_SESSION['user_id']
                    ]);
                    
                    sendCompanyAdminEmail($admin_email, $admin_name, $name, $admin_email, $plainPassword, $custom_login_link);
                }
            }
            
            record_admin_audit($pdo, 'TENANT_UPDATED', 'tenants', (int)$id,
                $__existing_tenant,
                ['name' => $name, 'is_active' => $is_active, 'billing_cycle' => $billing_cycle, 'subscription_price' => $subscription_price, 'auto_renew' => $auto_renew],
                (int)$id);
            echo json_encode(['success' => true, 'message' => 'Shirkad la cusboonaysiiyay!']);
            exit;
            
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
            exit;
        }
    }
    
    if ($action === 'renew_subscription') {
        if ($role === 'company_admin') {
            echo json_encode(['success' => false, 'message' => 'Ma laguu ogola inaad cusboonaysiiso']);
            exit;
        }
        
        $id = (int)($_POST['id'] ?? 0);
        $billing_cycle = $_POST['billing_cycle'] ?? 'monthly';
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT subscription_end_date, subscription_status FROM tenants WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$tenant) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Shirkadda lama helin']);
                exit;
            }
            
            $start_date = date('Y-m-d');
            $end_date = calculateSubscriptionEndDate($start_date, $billing_cycle);
            
            $updateStmt = $pdo->prepare("
                UPDATE tenants 
                SET billing_cycle = ?, 
                    subscription_start_date = ?,
                    subscription_end_date = ?,
                    subscription_status = 'active',
                    is_active = 1,
                    last_invoice_date = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$billing_cycle, $start_date, $end_date, $start_date, $id]);
            
            $userStmt = $pdo->prepare("
                UPDATE users 
                SET is_active = 1 
                WHERE tenant_id = ? AND role_type != 'superadmin'
            ");
            $userStmt->execute([$id]);
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => "Cusboonaysiinta waa la sameeyay!<br>
                Billing: " . ucfirst($billing_cycle) . "<br>
                Taariikhda cusub: " . date('d/m/Y', strtotime($end_date))
            ]);
            exit;
            
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
            exit;
        }
    }
    
    if ($action === 'delete_tenant') {
        if ($role === 'company_admin') {
            echo json_encode(['success' => false, 'message' => 'Ma laguu ogola inaad tirtirto shirkadda']);
            exit;
        }
        
        $id = (int)($_POST['id'] ?? 0);
        
        try {
            $stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$tenant) {
                echo json_encode(['success' => false, 'message' => 'Shirkadda lama helin']);
                exit;
            }
            
            // Safety gate: refuse to hard-delete a tenant with operational data.
            // Deactivate the tenant instead (soft-shutdown preserves history).
            $dep = [];
            foreach ([
                'shipments' => 'shipments',
                'containers' => 'containers',
                'trucking_trips' => 'trucking_trips',
                'invoices' => 'invoices',
                'receipts' => 'receipts',
                'payments' => 'payments',
                'warehouse_stock' => 'warehouse_stock',
                'customers' => 'customers',
                'branches' => 'branches',
            ] as $label => $tbl) {
                try {
                    $q = $pdo->prepare("SELECT COUNT(*) FROM `$tbl` WHERE tenant_id = ?");
                    $q->execute([$id]);
                    $n = (int)$q->fetchColumn();
                    if ($n > 0) $dep[$label] = $n;
                } catch (Throwable $e) {}
            }
            if (!empty($dep)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Tenant has operational history and cannot be hard-deleted. Deactivate it instead. Dependencies: ' . http_build_query($dep, '', ', '),
                ]);
                exit;
            }

            require_once __DIR__ . '/../includes/admin_audit.php';
            $pdo->beginTransaction();

            $stmt2 = $pdo->prepare("DELETE FROM users WHERE tenant_id = ?");
            $stmt2->execute([$id]);

            $stmt3 = $pdo->prepare("DELETE FROM tenants WHERE id = ?");
            $stmt3->execute([$id]);

            record_admin_audit($pdo, 'TENANT_DELETED', 'tenants', (int)$id,
                ['name' => $tenant['name']],
                null,
                (int)$id);
            $pdo->commit();

            echo json_encode(['success' => true, 'message' => "Shirkad '{$tenant['name']}' waa la tirtiray!"]);
            exit;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'toggle_status') {
        if ($role === 'company_admin') {
            echo json_encode(['success' => false, 'message' => 'Ma laguu ogola inaad beddesho xaaladda']);
            exit;
        }
        
        $id = (int)($_POST['id'] ?? 0);
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT is_active, name, subscription_end_date FROM tenants WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$tenant) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Shirkadda lama helin']);
                exit;
            }
            
            $new_status = $tenant['is_active'] ? 0 : 1;
            
            if ($new_status == 1 && !empty($tenant['subscription_end_date']) && $tenant['subscription_end_date'] < date('Y-m-d')) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Ma awoodid inaad firfircooniso shirkad qorshaheedu dhammaaday. Fadlan marka hore cusboonaysii qorshaha.']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE tenants SET is_active = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $id]);
            
            $stmt2 = $pdo->prepare("UPDATE users SET is_active = ? WHERE tenant_id = ? AND role_type != 'superadmin'");
            $stmt2->execute([$new_status, $id]);

            require_once __DIR__ . '/../includes/admin_audit.php';
            record_admin_audit($pdo,
                $new_status ? 'TENANT_REACTIVATED' : 'TENANT_DEACTIVATED',
                'tenants', (int)$id,
                ['is_active' => (int)$tenant['is_active']],
                ['is_active' => (int)$new_status],
                (int)$id);
            $pdo->commit();

            $message = $new_status
                ? "✅ Shirkadda '{$tenant['name']}' hadda waa FIRFIRCOON!"
                : "⚠️ Shirkadda '{$tenant['name']}' hadda waa AAN FIRFIRCOONEYN!";
            
            echo json_encode(['success' => true, 'message' => $message]);
            exit;
            
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
            exit;
        }
    }
    
    if ($action === 'get_stats') {
        if ($role === 'company_admin') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total, SUM(is_active) as active
                FROM tenants 
                WHERE id = ?
            ");
            $stmt->execute([$session_tenant_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'total' => 1,
                'active' => $result['active'] ?? 0,
                'expired_soon' => 0
            ]);
            exit;
        }
        
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN subscription_end_date IS NOT NULL 
                    AND subscription_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                    AND is_active = 1 THEN 1 ELSE 0 END) as expired_soon
            FROM tenants
        ");
        
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit;
    }
    
    if ($action === 'import_tenants') {
        if (!isset($_FILES['excel_file'])) {
            echo json_encode(['success' => false, 'message' => 'Fayl lama dooran!']);
            exit;
        }
        
        $file = $_FILES['excel_file']['tmp_name'];
        $handle = fopen($file, "r");
        
        if (!$handle) {
            echo json_encode(['success' => false, 'message' => 'Faylka lama akhrin karo']);
            exit;
        }
        
        fgetcsv($handle);
        
        $imported = 0;
        $errors = [];
        $line = 1;
        
        try {
            $pdo->beginTransaction();
            
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                $line++;
                
                $name = trim($data[0] ?? '');
                $code = trim($data[1] ?? '');
                $email = trim($data[2] ?? '');
                $phone = trim($data[3] ?? '');
                $address = trim($data[4] ?? '');
                $cycle = strtolower(trim($data[5] ?? 'monthly'));
                $admin_name = trim($data[6] ?? '');
                $admin_email = trim($data[7] ?? '');
                $custom_login_link = trim($data[8] ?? '');
                
                if (empty($name) || empty($code)) {
                    continue;
                }
                
                if (!in_array($cycle, ['monthly', 'quarterly', 'bi_annual', 'annual'], true)) {
                    $cycle = 'monthly';
                }
                
                $stmt = $pdo->prepare("SELECT id FROM tenants WHERE code = ? LIMIT 1");
                $stmt->execute([$code]);
                
                if ($stmt->fetch()) {
                    $errors[] = "Line $line: Code '$code' horay ayaa loo isticmaalay.";
                    continue;
                }
                
                if (!empty($admin_email)) {
                    $stmtEmail = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                    $stmtEmail->execute([$admin_email]);
                    
                    if ($stmtEmail->fetch()) {
                        $errors[] = "Line $line: Email '$admin_email' horay ayaa loo isticmaalay.";
                        continue;
                    }
                }
                
                $start_date = date('Y-m-d');
                $end_date = calculateSubscriptionEndDate($start_date, $cycle);
                
                $stmt = $pdo->prepare("
                    INSERT INTO tenants (
                        name, code, email, phone, address, billing_cycle, 
                        subscription_start_date, subscription_end_date, subscription_status, 
                        is_active, custom_login_link, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', 1, ?, ?, NOW())
                ");
                $stmt->execute([$name, $code, $email, $phone, $address, $cycle, $start_date, $end_date, $custom_login_link ?: null, $user_id]);
                
                $tenant_id = $pdo->lastInsertId();
                
                if (!empty($admin_name) && !empty($admin_email)) {
                    // Secure temporary-password provisioning (replaces legacy fixed '123').
                    $__sa_alphabet = 'ABCDEFGHIJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
                    $plainPassword = '';
                    for ($__i = 0; $__i < 12; $__i++) {
                        $plainPassword .= $__sa_alphabet[random_int(0, strlen($__sa_alphabet) - 1)];
                    }
                    $hashed_password = password_hash($plainPassword, PASSWORD_DEFAULT);

                    $stmt2 = $pdo->prepare("
                        INSERT INTO users (
                            tenant_id, email, password_hash, full_name, phone, role_type, 
                            is_active, created_by, created_at
                        ) VALUES (?, ?, ?, ?, ?, 'company_admin', 1, ?, NOW())
                    ");
                    $stmt2->execute([$tenant_id, $admin_email, $hashed_password, $admin_name, $phone, $user_id]);
                    
                    sendCompanyAdminEmail($admin_email, $admin_name, $name, $admin_email, $plainPassword, $custom_login_link);
                }
                
                $imported++;
            }
            
            $pdo->commit();
            
            $msg = "Import-ka waa lagu guulaystay! ($imported shirkadood).";
            if (count($errors) > 0) {
                $msg .= "<br>Digniin: " . count($errors) . " saf ayaa laga booday.";
            }
            
            echo json_encode(['success' => true, 'message' => $msg]);
            fclose($handle);
            exit;
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            fclose($handle);
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'Action lama yaqaan']);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <title>Maareynta Shirkadaha - Cargo Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    
    <style>
        :root {
            --curdun-violet: #2D1859;
            --curdun-yellow: #F5C410;
            --curdun-violet-light: #4B2C85;
            --curdun-yellow-dark: #D4A70C;
            --curdun-gray: #6c757d;
            --curdun-dark: #2D2D2D;
            --curdun-success: #0F7A3A;
            --curdun-danger: #B42318;
            --curdun-warning: #ffc107;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            background: #f4f6f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light));
            border-radius: 16px;
            padding: 20px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .page-header h1 {
            color: white;
            font-size: 24px;
            margin: 0;
        }
        
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
            transition: all .3s ease;
            cursor: pointer;
        }
        
        .btn-primary-custom:hover {
            background: var(--curdun-yellow-dark);
            color: var(--curdun-violet);
            transform: translateY(-2px);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
            box-shadow: 0 2px 4px rgba(0,0,0,.05);
            border-left: 3px solid var(--curdun-violet);
        }
        
        .stat-card-sm h4 {
            font-size: 12px;
            color: var(--curdun-gray);
            margin-bottom: 5px;
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: var(--curdun-violet);
        }
        
        .stat-icon {
            width: 45px;
            height: 45px;
            background: rgba(82,0,102,.1);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .stat-icon i {
            font-size: 22px;
            color: var(--curdun-violet);
        }
        
        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,.05);
        }
        
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 150px;
        }
        
        .filter-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--curdun-gray);
            margin-bottom: 5px;
        }
        
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .btn-filter {
            background: var(--curdun-violet);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        .btn-reset {
            background: #f0f0f0;
            color: var(--curdun-dark);
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            margin-left: 10px;
            cursor: pointer;
        }
        
        .tenants-table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,.05);
            overflow-x: auto;
            width: 100%;
        }
        
        .tenants-table {
            width: 100%;
            min-width: 1500px;
            border-collapse: collapse;
        }
        
        .tenants-table th,
        .tenants-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        
        .tenants-table th {
            background: #f8f6f9;
            font-weight: 600;
            color: var(--curdun-dark);
            font-size: 13px;
            white-space: nowrap;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .status-active { background: #EEFBF3; color: #0F7A3A; }
        .status-inactive { background: #FEF0EE; color: #B42318; }
        
        .code-badge {
            background: #f0f0f0;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-family: monospace;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            border: none;
        }
        
        .btn-edit { background: #e3f2fd; color: #1565c0; }
        .btn-delete { background: #FEF0EE; color: #B42318; }
        .btn-toggle { background: #f5f5f5; color: #2D2D2D; }
        .btn-renew { background: #fff3e0; color: #e65100; }
        
        .alert {
            padding: 12px 20px;
            border-radius: 8px;
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
        }
        
        .alert-success { background: #EEFBF3; color: #0F7A3A; border-left: 4px solid #0F7A3A; }
        .alert-error { background: #FEF0EE; color: #B42318; border-left: 4px solid #B42318; }
        
        .empty-state {
            text-align: center;
            padding: 50px;
            color: var(--curdun-gray);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: .5;
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light));
            color: white;
        }
        
        .modal-header .close {
            color: white;
            opacity: 1;
        }
        
        .loading-spinner {
            text-align: center;
            padding: 50px;
        }
        
        .loading-spinner i {
            font-size: 48px;
            color: var(--curdun-violet);
        }
        
        .info-box {
            background: #e8eaf6;
            border-left: 3px solid var(--curdun-violet);
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 13px;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 25px;
            flex-wrap: wrap;
        }
        
        .pagination a,
        .pagination span {
            padding: 8px 14px;
            border-radius: 8px;
            text-decoration: none;
            color: var(--curdun-dark);
            background: white;
            border: 1px solid #ddd;
            cursor: pointer;
        }
        
        .pagination .active {
            background: var(--curdun-violet);
            color: white;
            border-color: var(--curdun-violet);
        }
        
        .image-preview {
            width: 80px;
            height: 80px;
            border-radius: 10px;
            object-fit: cover;
            border: 2px solid var(--curdun-violet);
            margin-top: 5px;
        }
        
        .admin-image-preview {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--curdun-violet);
            margin-top: 5px;
        }
        
        .subscription-card {
            background: #f8f6f9;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .subscription-card h6 {
            color: var(--curdun-violet);
            margin-bottom: 15px;
        }
        
        .price-input {
            font-weight: bold;
            background-color: #fff8e1;
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

<div class="container-fluid" style="padding:20px;">
    <div id="alert-placeholder"></div>
    
    <div class="page-header">
        <h1>
            <i class="fas fa-building"></i>
            <?= $role === 'company_admin' ? 'Xogta Shirkadda' : 'Maareynta Shirkadaha' ?>
        </h1>
        
        <div class="d-flex gap-3 align-items-center">
            <?php if ($role === 'superadmin'): ?>
                <button type="button" class="btn-primary-custom" id="addTenantBtn">
                    <i class="fas fa-plus-circle"></i> Shirkad Cusub
                </button>
                
                <div class="dropdown">
                    <button class="btn btn-light dropdown-toggle" type="button" data-toggle="dropdown" style="border-radius:8px;padding:10px 15px;font-weight:600;">
                        <i class="fas fa-file-csv"></i> CSV
                    </button>
                    <div class="dropdown-menu dropdown-menu-right">
                        <a class="dropdown-item" href="?action=export_tenants" id="exportTenantsBtn">
                            <i class="fas fa-download mr-2"></i> Export Tenants
                        </a>
                        <a class="dropdown-item" href="#" data-toggle="modal" data-target="#importModal">
                            <i class="fas fa-upload mr-2"></i> Import Tenants
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="?action=download_sample">
                            <i class="fas fa-file-download mr-2"></i> Download Sample
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card-sm">
            <div>
                <h4>Wadarta Shirkadaha</h4>
                <div class="stat-number" id="stat-total">0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-building"></i></div>
        </div>
        
        <div class="stat-card-sm">
            <div>
                <h4>Shirkadaha Firfircoon</h4>
                <div class="stat-number" id="stat-active">0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
        </div>
        
        <div class="stat-card-sm">
            <div>
                <h4>7 Maalmood Ka Hartay</h4>
                <div class="stat-number" id="stat-expiring">0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
        </div>
    </div>
    
    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Raadin</label>
                <input type="text" id="searchInput" placeholder="Magaca, Code, Email, Telefoon...">
            </div>
            
            <div class="filter-group">
                <label><i class="fas fa-circle"></i> Xaaladda</label>
                <select id="statusFilter">
                    <option value="">Dhammaan</option>
                    <option value="active">Firfircoon</option>
                    <option value="inactive">Aan Firfircooneyn</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label><i class="fas fa-ticket-alt"></i> Subscription</label>
                <select id="subscriptionStatusFilter">
                    <option value="">Dhammaan</option>
                    <option value="active">Active</option>
                    <option value="expired">Expired</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="trial">Trial</option>
                </select>
            </div>
            
            <div class="filter-group">
                <button class="btn-filter" id="applyFilters">
                    <i class="fas fa-filter"></i> Shaandheey
                </button>
                <button class="btn-reset" id="resetFilters">
                    <i class="fas fa-undo"></i> Nadiifi
                </button>
            </div>
        </div>
    </div>
    
    <div id="tenants-table-container">
        <div class="loading-spinner">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading...</p>
        </div>
    </div>
    
    <div id="pagination-container"></div>
</div>

<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header">
                <h5><i class="fas fa-file-import"></i> Soo geli Shirkado CSV</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            
            <form id="importForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        Fadlan soo geli CSV oo kaliya.
                        <a href="?action=download_sample">Download sample</a>.
                    </div>
                    
                    <div class="form-group">
                        <label>Dooro Faylka CSV</label>
                        <input type="file" name="excel_file" class="form-control" accept=".csv" required>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button>
                    <button type="submit" class="btn" style="background:#2D1859;color:white;">Soo geli</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="tenantModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 id="tenantModalLabel">Shirkad Cusub</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            
            <form id="tenantForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="tenant_id" id="tenant_id">
                    <input type="hidden" name="admin_id" id="admin_id">
                    <input type="hidden" name="existing_logo" id="existing_logo">
                    <input type="hidden" name="existing_admin_profile" id="existing_admin_profile">
                    
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Magaca Shirkadda *</label>
                            <input type="text" name="name" id="modalName" class="form-control" required>
                        </div>
                        
                        <div class="col-md-6 form-group">
                            <label>Code-ga Shirkadda *</label>
                            <input type="text" name="code" id="modalCode" class="form-control" required>
                            <small class="text-muted">Tusaale: FARAS, CURDUN, DALMAR</small>
                        </div>
                        
                        <div class="col-md-6 form-group">
                            <label>Emailka Shirkadda</label>
                            <input type="email" name="email" id="modalEmail" class="form-control">
                        </div>
                        
                        <div class="col-md-6 form-group">
                            <label>Telefoonka Shirkadda</label>
                            <input type="text" name="phone" id="modalPhone" class="form-control">
                        </div>
                        
                        <div class="col-md-12 form-group">
                            <label>Cinwaanka</label>
                            <textarea name="address" id="modalAddress" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <div class="col-md-6 form-group">
                            <label>Logo Shirkadda</label>
                            <input type="file" name="company_logo" id="company_logo" class="form-control" accept="image/*">
                            <small class="text-muted">Max 2MB</small>
                            <div id="logoPreview"></div>
                        </div>
                    </div>
                    
                    <div class="subscription-card">
                        <h6><i class="fas fa-calendar-alt"></i> Subscription</h6>
                        
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label>Billing Cycle</label>
                                <select name="billing_cycle" id="modalBillingCycle" class="form-control">
                                    <option value="monthly">Bil kasta</option>
                                    <option value="quarterly">3 Bilood</option>
                                    <option value="bi_annual">6 Bilood</option>
                                    <option value="annual">Sannad</option>
                                </select>
                            </div>
                            
                            <div class="col-md-4 form-group">
                                <label>Qiimaha USD</label>
                                <input type="number" step="0.01" name="subscription_price" id="modalPrice" class="form-control price-input" value="0">
                            </div>
                            
                            <div class="col-md-4 form-group">
                                <label>Taariikhda Bilaabanka</label>
                                <input type="date" name="subscription_start_date" id="modalStartDate" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>
                            
                            <div class="col-md-6 form-group">
                                <label>Taariikhda Dhammaadka</label>
                                <input type="text" id="modalEndDateDisplay" class="form-control" readonly>
                                <input type="hidden" name="subscription_end_date" id="modalEndDate">
                            </div>
                            
                            <div class="col-md-6 form-group">
                                <label>
                                    <input type="checkbox" name="auto_renew" id="modalAutoRenew" value="1" checked>
                                    Auto Renew
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 form-group">
                            <label>Xaaladda</label>
                            <select name="is_active" id="modalIsActive" class="form-control">
                                <option value="1">Firfircoon</option>
                                <option value="0">Aan Firfircooneyn</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3 form-group">
                            <label>Luqadda</label>
                            <select name="default_language" id="modalLanguage" class="form-control">
                                <option value="so">Soomaali</option>
                                <option value="en">English</option>
                                <option value="ar">Arabic</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3 form-group">
                            <label>CBM Points</label>
                            <input type="number" name="loyalty_cbm_points" id="modalCbmPoints" class="form-control" value="10">
                        </div>
                        
                        <div class="col-md-3 form-group">
                            <label>Amount Points</label>
                            <input type="number" name="loyalty_amount_points" id="modalAmountPoints" class="form-control" value="5">
                        </div>
                        
                        <div class="col-md-6 form-group">
                            <label>Timezone</label>
                            <select name="timezone" id="modalTimezone" class="form-control">
                                <option value="Africa/Mogadishu">Africa/Mogadishu</option>
                                <option value="Africa/Nairobi">Africa/Nairobi</option>
                                <option value="Asia/Dubai">Asia/Dubai</option>
                            </select>
                        </div>
                        
                        <div class="col-md-12 form-group">
                            <label>Login Link-ga Company Admin</label>
                            <input type="url" name="custom_login_link" id="modalLoginLink" class="form-control"
                                   placeholder="Tusaale: https://companydomain.com/superadmin/login.php">
                            <small class="text-muted">
                                Link-kan ayaa email-ka loogu dirayaa company admin. Haddii aad ka tagto, default login link ayaa la isticmaali doonaa.
                            </small>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <h6><i class="fas fa-user-shield"></i> Maamulaha Shirkadda</h6>
                    
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Magaca Maamulaha *</label>
                            <input type="text" name="admin_name" id="modalAdminName" class="form-control" required>
                        </div>
                        
                        <div class="col-md-6 form-group">
                            <label>Emailka Maamulaha *</label>
                            <input type="email" name="admin_email" id="modalAdminEmail" class="form-control" required>
                            <small class="text-muted">Password default: <strong>123</strong></small>
                        </div>
                        
                        <div class="col-md-6 form-group">
                            <label>Telefoonka Maamulaha *</label>
                            <input type="text" name="admin_phone" id="modalAdminPhone" class="form-control" required>
                        </div>
                        
                        <div class="col-md-6 form-group">
                            <label>Sawirka Maamulaha</label>
                            <input type="file" name="admin_profile_image" id="admin_profile_image" class="form-control" accept="image/*">
                            <div id="adminProfilePreview"></div>
                        </div>
                    </div>
                    
                    <div class="info-box">
                        <i class="fas fa-envelope"></i>
                        Marka company cusub la abuuro, email ayaa loo dirayaa maamulaha oo wata username, password iyo login link.
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-custom">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="renewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#e65100,#ff8f00);">
                <h5><i class="fas fa-sync-alt"></i> Cusboonaysii Qorshaha</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            
            <form id="renewForm">
                <div class="modal-body">
                    <input type="hidden" id="renewTenantId">
                    
                    <p>Cusboonaysii shirkadda: <strong id="renewTenantName"></strong></p>
                    
                    <div class="form-group">
                        <label>Billing Cycle</label>
                        <select id="renewBillingCycle" class="form-control">
                            <option value="monthly">Bil kasta</option>
                            <option value="quarterly">3 Bilood</option>
                            <option value="bi_annual">6 Bilood</option>
                            <option value="annual">Sannad</option>
                        </select>
                    </div>
                    
                    <div class="info-box">
                        Shirkadda iyo users-keeda waa la firfircoonaysiin doonaa.
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background:#e65100;color:white;">Cusboonaysii</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5>Tirtir Shirkad</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            
            <div class="modal-body">
                Ma hubtaa inaad tirtirto <strong id="deleteTenantName"></strong>?
                <br><br>
                <span class="text-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    Tani waxay tirtiraysaa users-ka shirkadda.
                </span>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Tirtir</button>
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
$(document).ready(function () {
    let currentPage = 1;
    let deleteId = null;
    
    function calculateEndDate(startDate, billingCycle) {
        let date = new Date(startDate);
        
        if (billingCycle === 'monthly') date.setMonth(date.getMonth() + 1);
        if (billingCycle === 'quarterly') date.setMonth(date.getMonth() + 3);
        if (billingCycle === 'bi_annual') date.setMonth(date.getMonth() + 6);
        if (billingCycle === 'annual') date.setFullYear(date.getFullYear() + 1);
        
        return date.toISOString().split('T')[0];
    }
    
    function updateEndDate() {
        const billingCycle = $('#modalBillingCycle').val();
        const startDate = $('#modalStartDate').val();
        
        if (startDate && billingCycle) {
            const endDate = calculateEndDate(startDate, billingCycle);
            $('#modalEndDateDisplay').val(endDate);
            $('#modalEndDate').val(endDate);
        }
    }
    
    $('#modalBillingCycle, #modalStartDate').on('change', updateEndDate);
    
    $('#company_logo').on('change', function (e) {
        const file = e.target.files[0];
        
        if (file) {
            const reader = new FileReader();
            reader.onload = function (ev) {
                $('#logoPreview').html(`<img src="${ev.target.result}" class="image-preview">`);
            };
            reader.readAsDataURL(file);
        }
    });
    
    $('#admin_profile_image').on('change', function (e) {
        const file = e.target.files[0];
        
        if (file) {
            const reader = new FileReader();
            reader.onload = function (ev) {
                $('#adminProfilePreview').html(`<img src="${ev.target.result}" class="admin-image-preview">`);
            };
            reader.readAsDataURL(file);
        }
    });
    
    function showAlert(type, msg) {
        $('#alert-placeholder').html(`
            <div class="alert alert-${type} alert-dismissible fade show">
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
                ${msg}
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        `);
        
        setTimeout(function () {
            $('.alert').fadeOut(500, function () {
                $(this).remove();
            });
        }, 6000);
    }
    
    function loadStats() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_stats' },
            dataType: 'json',
            success: function (stats) {
                $('#stat-total').text(stats.total || 0);
                $('#stat-active').text(stats.active || 0);
                $('#stat-expiring').text(stats.expired_soon || 0);
            }
        });
    }
    
    function loadTenants() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'get_tenants',
                page: currentPage,
                search: $('#searchInput').val(),
                status: $('#statusFilter').val(),
                subscription_status: $('#subscriptionStatusFilter').val()
            },
            dataType: 'json',
            success: function (response) {
                $('#tenants-table-container').html(response.table_html);
                $('#pagination-container').html(response.pagination_html);
                attachTableEvents();
                
                let search = $('#searchInput').val();
                $('#exportTenantsBtn').attr('href', `?action=export_tenants&search=${encodeURIComponent(search)}`);
            },
            error: function () {
                $('#tenants-table-container').html(`
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Error loading data</p>
                    </div>
                `);
            }
        });
    }
    
    function attachTableEvents() {
        $('.edit-tenant').off('click').on('click', function () {
            const id = $(this).data('id');
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_tenant', id: id },
                dataType: 'json',
                success: function (data) {
                    $('#tenantModalLabel').text('Wax ka beddel Shirkad');
                    
                    $('#tenant_id').val(data.id || '');
                    $('#admin_id').val(data.admin_id || '');
                    $('#modalName').val(data.name || '');
                    $('#modalCode').val(data.code || '');
                    $('#modalEmail').val(data.email || '');
                    $('#modalPhone').val(data.phone || '');
                    $('#modalAddress').val(data.address || '');
                    $('#modalBillingCycle').val(data.billing_cycle || 'monthly');
                    $('#modalIsActive').val(data.is_active ?? 1);
                    $('#modalCbmPoints').val(data.loyalty_cbm_points || 10);
                    $('#modalAmountPoints').val(data.loyalty_amount_points || 5);
                    $('#modalLanguage').val(data.default_language || 'so');
                    $('#modalTimezone').val(data.timezone || 'Africa/Mogadishu');
                    $('#modalStartDate').val(data.subscription_start_date || new Date().toISOString().split('T')[0]);
                    $('#modalPrice').val(data.subscription_price || 0);
                    $('#modalAutoRenew').prop('checked', data.auto_renew == 1);
                    $('#modalLoginLink').val(data.custom_login_link || '');
                    
                    $('#modalAdminName').val(data.admin_name || '');
                    $('#modalAdminEmail').val(data.admin_email || '');
                    $('#modalAdminPhone').val(data.admin_phone || '');
                    
                    $('#existing_logo').val(data.logo_url || '');
                    $('#existing_admin_profile').val(data.admin_profile_image || '');
                    
                    $('#logoPreview').empty();
                    $('#adminProfilePreview').empty();
                    
                    if (data.logo_url) {
                        $('#logoPreview').html(`<img src="../${data.logo_url}" class="image-preview">`);
                    }
                    
                    if (data.admin_profile_image) {
                        $('#adminProfilePreview').html(`<img src="../${data.admin_profile_image}" class="admin-image-preview">`);
                    }
                    
                    updateEndDate();
                    $('#tenantModal').modal('show');
                }
            });
        });
        
        $('.delete-tenant').off('click').on('click', function () {
            deleteId = $(this).data('id');
            $('#deleteTenantName').text($(this).data('name'));
            $('#deleteModal').modal('show');
        });
        
        $('.toggle-status').off('click').on('click', function () {
            if (!confirm('Ma hubtaa inaad beddesho xaaladda shirkaddan?')) return;
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    ajax_action: 'toggle_status',
                    id: $(this).data('id')
                },
                dataType: 'json',
                success: function (res) {
                    showAlert(res.success ? 'success' : 'error', res.message);
                    
                    if (res.success) {
                        loadTenants();
                        loadStats();
                    }
                }
            });
        });
        
        $('.renew-subscription').off('click').on('click', function () {
            $('#renewTenantId').val($(this).data('id'));
            $('#renewTenantName').text($(this).data('name'));
            $('#renewModal').modal('show');
        });
        
        $('.pagination a').off('click').on('click', function (e) {
            e.preventDefault();
            
            const page = $(this).data('page');
            
            if (page) {
                currentPage = page;
                loadTenants();
            }
        });
        
        $('#addTenantBtnEmpty').off('click').on('click', openAddTenantModal);
    }
    
    function openAddTenantModal() {
        $('#tenantModalLabel').text('Shirkad Cusub');
        
        $('#tenantForm')[0].reset();
        
        $('#tenant_id').val('');
        $('#admin_id').val('');
        $('#existing_logo').val('');
        $('#existing_admin_profile').val('');
        
        $('#modalCbmPoints').val(10);
        $('#modalAmountPoints').val(5);
        $('#modalLanguage').val('so');
        $('#modalTimezone').val('Africa/Mogadishu');
        $('#modalIsActive').val(1);
        $('#modalBillingCycle').val('monthly');
        $('#modalStartDate').val(new Date().toISOString().split('T')[0]);
        $('#modalPrice').val(0);
        $('#modalAutoRenew').prop('checked', true);
        $('#modalLoginLink').val('');
        
        $('#logoPreview').empty();
        $('#adminProfilePreview').empty();
        
        updateEndDate();
        $('#tenantModal').modal('show');
    }
    
    $('#addTenantBtn').on('click', openAddTenantModal);
    
    $('#tenantForm').on('submit', function (e) {
        e.preventDefault();
        
        if (!$('#modalName').val()) {
            showAlert('error', 'Fadlan geli Magaca Shirkadda');
            return;
        }
        
        if (!$('#modalCode').val()) {
            showAlert('error', 'Fadlan geli Code-ga Shirkadda');
            return;
        }
        
        const tenantId = $('#tenant_id').val();
        
        if (!tenantId && (!$('#modalAdminName').val() || !$('#modalAdminEmail').val() || !$('#modalAdminPhone').val())) {
            showAlert('error', 'Fadlan geli Magaca, Emailka iyo Telefoonka Maamulaha');
            return;
        }
        
        const formData = new FormData(this);
        formData.append('ajax_action', 'save_tenant');
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (res) {
                showAlert(res.success ? 'success' : 'error', res.message);
                
                if (res.success) {
                    $('#tenantModal').modal('hide');
                    $('#tenantForm')[0].reset();
                    $('#logoPreview').empty();
                    $('#adminProfilePreview').empty();
                    loadTenants();
                    loadStats();
                }
            },
            error: function (xhr) {
                showAlert('error', 'Khalad ayaa dhacay. Check PHP error log ama SMTP config.');
            }
        });
    });
    
    $('#renewForm').on('submit', function (e) {
        e.preventDefault();
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'renew_subscription',
                id: $('#renewTenantId').val(),
                billing_cycle: $('#renewBillingCycle').val()
            },
            dataType: 'json',
            success: function (res) {
                showAlert(res.success ? 'success' : 'error', res.message);
                
                if (res.success) {
                    $('#renewModal').modal('hide');
                    loadTenants();
                    loadStats();
                }
            }
        });
    });
    
    $('#importForm').on('submit', function (e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        formData.append('ajax_action', 'import_tenants');
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (res) {
                showAlert(res.success ? 'success' : 'error', res.message);
                
                if (res.success) {
                    $('#importModal').modal('hide');
                    $('#importForm')[0].reset();
                    loadTenants();
                    loadStats();
                }
            },
            error: function () {
                showAlert('error', 'Khalad ayaa dhacay intii lagu guda jiray import.');
            }
        });
    });
    
    $('#confirmDeleteBtn').on('click', function () {
        if (!deleteId) return;
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'delete_tenant',
                id: deleteId
            },
            dataType: 'json',
            success: function (res) {
                showAlert(res.success ? 'success' : 'error', res.message);
                
                if (res.success) {
                    $('#deleteModal').modal('hide');
                    loadTenants();
                    loadStats();
                }
                
                deleteId = null;
            }
        });
    });
    
    $('#applyFilters').on('click', function () {
        currentPage = 1;
        loadTenants();
    });
    
    $('#resetFilters').on('click', function () {
        $('#searchInput').val('');
        $('#statusFilter').val('');
        $('#subscriptionStatusFilter').val('');
        currentPage = 1;
        loadTenants();
    });
    
    $('#searchInput').on('keypress', function (e) {
        if (e.which === 13) {
            currentPage = 1;
            loadTenants();
        }
    });
    
    updateEndDate();
    loadTenants();
    loadStats();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>