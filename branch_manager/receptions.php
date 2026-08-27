<?php
// branch_manager/receipts.php
// Professional Receipt Management for Branch Managers

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is branch_manager
if (!isset($_SESSION['user_id']) || ($_SESSION['role_type'] ?? $_SESSION['role'] ?? '') !== 'branch_manager') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

$user_id = (int)$_SESSION['user_id'];
$tenant_id = (int)($_SESSION['tenant_id'] ?? 0);

if ($tenant_id <= 0) {
    header("Location: ../login.php?error=no_tenant");
    exit;
}

// Get branch manager's assigned branch
$assigned_branch_id = $_SESSION['assigned_branch_id'] ?? null;

if (!$assigned_branch_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT branch_id, is_primary, can_manage_branch 
            FROM user_branch_assignments 
            WHERE user_id = ? AND is_primary = 1
            LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $branchAssign = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($branchAssign) {
            $assigned_branch_id = $branchAssign['branch_id'];
            $_SESSION['assigned_branch_id'] = $assigned_branch_id;
        }
    } catch (PDOException $e) {}
}

if (!$assigned_branch_id) {
    echo '<div class="alert alert-danger">You are not assigned to any branch. Please contact administrator.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Get branch name for display
$branch_name = '';
try {
    $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE id = ?");
    $stmt->execute([$assigned_branch_id]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) {
        $branch_name = $branch['branch_name'];
    }
} catch (PDOException $e) {}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money2($value): string {
    return number_format((float)$value, 2, '.', '');
}

function points2($value): string {
    return number_format((float)$value, 2, '.', '');
}

function json_response(array $data): void {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetch(PDO::FETCH_NUM);
}

function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void {
    if (!columnExists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function ensureReceiptSchema(PDO $pdo): void {
    if (!tableExists($pdo, 'receipts')) {
        $pdo->exec("
            CREATE TABLE receipts (
                id INT(11) NOT NULL AUTO_INCREMENT,
                tenant_id INT(11) NOT NULL,
                receipt_number VARCHAR(100) NOT NULL,
                invoice_id INT(11) DEFAULT NULL,
                payment_id INT(11) DEFAULT NULL,
                customer_id INT(11) DEFAULT NULL,
                amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                payment_date DATE DEFAULT NULL,
                payment_method VARCHAR(50) DEFAULT 'cash',
                reference_number VARCHAR(150) DEFAULT NULL,
                bank_account_id INT(11) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL,
                created_by INT(11) DEFAULT NULL,
                original_amount DECIMAL(15,2) DEFAULT 0.00,
                discount_applied DECIMAL(15,2) DEFAULT 0.00,
                points_used DECIMAL(12,2) DEFAULT 0.00,
                points_discount_amount DECIMAL(15,2) DEFAULT 0.00,
                points_earned DECIMAL(12,2) DEFAULT 0.00,
                loyalty_points_awarded TINYINT(1) DEFAULT 0,
                branch_id INT(11) DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_receipts_tenant (tenant_id),
                KEY idx_receipts_customer (customer_id),
                KEY idx_receipts_invoice (invoice_id),
                KEY idx_receipts_payment (payment_id),
                KEY idx_receipts_number (receipt_number),
                KEY idx_receipts_branch (branch_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // Add branch_id column if missing
    addColumnIfMissing($pdo, 'receipts', 'branch_id', "INT(11) DEFAULT NULL");
    addColumnIfMissing($pdo, 'receipts', 'points_earned', "DECIMAL(12,2) DEFAULT 0.00");
    addColumnIfMissing($pdo, 'receipts', 'loyalty_points_awarded', "TINYINT(1) DEFAULT 0");
    addColumnIfMissing($pdo, 'receipts', 'original_amount', "DECIMAL(15,2) DEFAULT 0.00");
    addColumnIfMissing($pdo, 'receipts', 'discount_applied', "DECIMAL(15,2) DEFAULT 0.00");
    addColumnIfMissing($pdo, 'receipts', 'points_used', "DECIMAL(12,2) DEFAULT 0.00");
    addColumnIfMissing($pdo, 'receipts', 'points_discount_amount', "DECIMAL(15,2) DEFAULT 0.00");

    // Create bank_accounts table if it doesn't exist
    if (!tableExists($pdo, 'bank_accounts')) {
        $pdo->exec("
            CREATE TABLE bank_accounts (
                id INT(11) NOT NULL AUTO_INCREMENT,
                tenant_id INT(11) DEFAULT NULL,
                account_name VARCHAR(255) NOT NULL,
                bank_name VARCHAR(255) DEFAULT NULL,
                account_number VARCHAR(100) DEFAULT NULL,
                account_type VARCHAR(50) DEFAULT 'checking',
                currency VARCHAR(3) DEFAULT 'USD',
                opening_balance DECIMAL(15,2) DEFAULT 0.00,
                current_balance DECIMAL(15,2) DEFAULT 0.00,
                is_active TINYINT(1) DEFAULT 1,
                is_default TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_by INT(11) DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_bank_accounts_tenant (tenant_id),
                KEY idx_bank_accounts_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // Add branch_id column to customers if missing
    addColumnIfMissing($pdo, 'customers', 'branch_id', "INT(11) DEFAULT NULL");
    addColumnIfMissing($pdo, 'customers', 'loyalty_points', "DECIMAL(12,2) DEFAULT 0.00");

    if (!tableExists($pdo, 'loyalty_points_log')) {
        $pdo->exec("
            CREATE TABLE loyalty_points_log (
                id INT(11) NOT NULL AUTO_INCREMENT,
                tenant_id INT(11) DEFAULT NULL,
                customer_id INT(11) DEFAULT NULL,
                points_earned DECIMAL(12,2) DEFAULT 0.00,
                points_redeemed DECIMAL(12,2) DEFAULT 0.00,
                cbm_earned DECIMAL(10,2) DEFAULT 0.00,
                amount_earned DECIMAL(15,2) DEFAULT 0.00,
                reason VARCHAR(255) DEFAULT NULL,
                reference_type VARCHAR(50) DEFAULT NULL,
                reference_id INT(11) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_by INT(11) DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_lpl_tenant (tenant_id),
                KEY idx_lpl_customer (customer_id),
                KEY idx_lpl_reference (reference_type, reference_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    if (!tableExists($pdo, 'point_redemptions')) {
        $pdo->exec("
            CREATE TABLE point_redemptions (
                id INT(11) NOT NULL AUTO_INCREMENT,
                tenant_id INT(11) DEFAULT NULL,
                customer_id INT(11) DEFAULT NULL,
                points_used DECIMAL(12,2) DEFAULT 0.00,
                discount_amount DECIMAL(15,2) DEFAULT 0.00,
                redemption_date DATE DEFAULT NULL,
                invoice_id INT(11) DEFAULT NULL,
                payment_id INT(11) DEFAULT NULL,
                status VARCHAR(50) DEFAULT 'applied',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                applied_to_payment_id INT(11) DEFAULT NULL,
                applied_at DATETIME DEFAULT NULL,
                receipt_id INT(11) DEFAULT NULL,
                created_by INT(11) DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_pr_tenant_customer (tenant_id, customer_id),
                KEY idx_pr_invoice (invoice_id),
                KEY idx_pr_payment (payment_id),
                KEY idx_pr_receipt (receipt_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

ensureReceiptSchema($pdo);

function getTenantInfo(PDO $pdo, int $tenant_id): array {
    $stmt = $pdo->prepare("
        SELECT id, name, code, logo_url, address, phone,
               COALESCE(loyalty_amount_points, 5) AS loyalty_amount_points,
               0.01 AS point_money_value
        FROM tenants
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$tenant_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'id' => $tenant_id,
        'name' => 'Company',
        'code' => '',
        'loyalty_amount_points' => 5,
        'point_money_value' => 0.01
    ];
}

$tenant_info = getTenantInfo($pdo, $tenant_id);
$tenant_loyalty_rate = (float)$tenant_info['loyalty_amount_points'];
$tenant_point_money_value = 0.01;

function getBankAccounts(PDO $pdo, int $tenant_id): array {
    $stmt = $pdo->prepare("
        SELECT id, account_name, bank_name, account_number, account_type, 
               currency, current_balance, is_active, is_default
        FROM bank_accounts
        WHERE tenant_id = ? AND is_active = 1
        ORDER BY is_default DESC, account_name ASC
    ");
    $stmt->execute([$tenant_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function createBankAccount(PDO $pdo, int $tenant_id, int $user_id, array $data): array {
    $stmt = $pdo->prepare("
        INSERT INTO bank_accounts 
        (tenant_id, account_name, bank_name, account_number, account_type, 
         currency, opening_balance, current_balance, is_active, is_default, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $is_default = isset($data['is_default']) && $data['is_default'] ? 1 : 0;
    
    if ($is_default) {
        $pdo->prepare("UPDATE bank_accounts SET is_default = 0 WHERE tenant_id = ?")->execute([$tenant_id]);
    }
    
    $stmt->execute([
        $tenant_id,
        $data['account_name'],
        $data['bank_name'] ?? null,
        $data['account_number'] ?? null,
        $data['account_type'] ?? 'checking',
        $data['currency'] ?? 'USD',
        $data['opening_balance'] ?? 0,
        $data['opening_balance'] ?? 0,
        1,
        $is_default,
        $user_id
    ]);
    
    return ['success' => true, 'id' => $pdo->lastInsertId()];
}

function updateBankAccount(PDO $pdo, int $tenant_id, int $account_id, array $data): array {
    if (isset($data['is_default']) && $data['is_default']) {
        $pdo->prepare("UPDATE bank_accounts SET is_default = 0 WHERE tenant_id = ? AND id != ?")->execute([$tenant_id, $account_id]);
    }
    
    $stmt = $pdo->prepare("
        UPDATE bank_accounts 
        SET account_name = ?, bank_name = ?, account_number = ?, 
            account_type = ?, currency = ?, is_default = ?
        WHERE id = ? AND tenant_id = ?
    ");
    
    $stmt->execute([
        $data['account_name'],
        $data['bank_name'] ?? null,
        $data['account_number'] ?? null,
        $data['account_type'] ?? 'checking',
        $data['currency'] ?? 'USD',
        isset($data['is_default']) && $data['is_default'] ? 1 : 0,
        $account_id,
        $tenant_id
    ]);
    
    return ['success' => true];
}

function deleteBankAccount(PDO $pdo, int $tenant_id, int $account_id): array {
    $stmt = $pdo->prepare("UPDATE bank_accounts SET is_active = 0 WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$account_id, $tenant_id]);
    return ['success' => true];
}

function updateBankAccountBalance(PDO $pdo, int $tenant_id, ?int $account_id, float $delta): void {
    if (!$account_id || abs($delta) < 0.00001) return;
    $stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = COALESCE(current_balance, 0) + ? WHERE id = ? AND tenant_id = ? AND is_active = 1");
    $stmt->execute([round($delta, 2), $account_id, $tenant_id]);
}

function calculateLoyaltyPoints(float $amount, float $loyalty_rate): float {
    return round(($amount / 100) * $loyalty_rate, 2);
}

function generateReceiptNumber(PDO $pdo, int $tenant_id, int $branch_id): string {
    $prefix = 'RCP-' . $branch_id . '-';
    do {
        $receipt_number = $prefix . date('YmdHis') . '-' . random_int(1000, 9999);
        $check = $pdo->prepare("SELECT id FROM receipts WHERE receipt_number = ? AND tenant_id = ? LIMIT 1");
        $check->execute([$receipt_number, $tenant_id]);
    } while ($check->fetch());
    return $receipt_number;
}

function getReceipt(PDO $pdo, int $receipt_id, int $tenant_id): ?array {
    $stmt = $pdo->prepare("
        SELECT r.*, c.customer_name, c.phone AS customer_phone, c.email AS customer_email, c.loyalty_points,
               i.invoice_number, u.full_name AS created_by_name,
               ba.account_name, ba.bank_name
        FROM receipts r
        LEFT JOIN customers c ON r.customer_id = c.id
        LEFT JOIN invoices i ON r.invoice_id = i.id
        LEFT JOIN users u ON r.created_by = u.id
        LEFT JOIN bank_accounts ba ON r.bank_account_id = ba.id
        WHERE r.id = ? AND r.tenant_id = ?
        LIMIT 1
    ");
    $stmt->execute([$receipt_id, $tenant_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getReceiptTotals(PDO $pdo, int $tenant_id, int $branch_id, string $search = '', int $customer_id = 0): array {
    $where = ["r.tenant_id = ?", "r.branch_id = ?"];
    $params = [$tenant_id, $branch_id];

    if ($search !== '') {
        $where[] = "(r.receipt_number LIKE ? OR c.customer_name LIKE ? OR r.reference_number LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($customer_id > 0) {
        $where[] = "r.customer_id = ?";
        $params[] = $customer_id;
    }

    $where_clause = 'WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT 
            COUNT(r.id) AS total_receipts,
            COALESCE(SUM(r.amount), 0) AS total_amount_received,
            COALESCE(SUM(r.points_earned), 0) AS total_points_earned,
            COALESCE(SUM(r.points_used), 0) AS total_points_used
        FROM receipts r
        LEFT JOIN customers c ON r.customer_id = c.id
        $where_clause
    ");
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function awardReceiptPoints(PDO $pdo, int $receipt_id, int $tenant_id, float $loyalty_rate, int $branch_id): array {
    $receipt = getReceipt($pdo, $receipt_id, $tenant_id);
    if (!$receipt) return ['success' => false, 'points' => 0, 'message' => 'Receipt not found'];
    if (empty($receipt['customer_id'])) return ['success' => false, 'points' => 0, 'message' => 'Receipt has no customer'];
    if ((int)$receipt['loyalty_points_awarded'] === 1) return ['success' => false, 'points' => (float)$receipt['points_earned'], 'message' => 'Points already awarded'];

    $paid_amount = (float)$receipt['amount'];
    $points = calculateLoyaltyPoints($paid_amount, $loyalty_rate);

    if ($paid_amount <= 0 || $points <= 0) {
        $pdo->prepare("UPDATE receipts SET points_earned = 0.00, loyalty_points_awarded = 1 WHERE id = ? AND tenant_id = ?")
            ->execute([$receipt_id, $tenant_id]);
        return ['success' => true, 'points' => 0.00, 'message' => 'No points earned'];
    }

    $pdo->prepare("UPDATE customers SET loyalty_points = COALESCE(loyalty_points, 0) + ? WHERE id = ? AND tenant_id = ?")
        ->execute([$points, $receipt['customer_id'], $tenant_id]);

    $reason = 'Receipt #' . $receipt['receipt_number'] . ' - $' . money2($paid_amount) . ' / 100 x ' . points2($loyalty_rate) . ' = ' . points2($points) . ' points';

    $pdo->prepare("INSERT INTO loyalty_points_log (tenant_id, customer_id, points_earned, points_redeemed, amount_earned, reason, reference_type, reference_id, created_by, created_at) VALUES (?, ?, ?, 0, ?, ?, 'receipt', ?, ?, NOW())")
        ->execute([$tenant_id, $receipt['customer_id'], $points, $paid_amount, $reason, $receipt_id, $receipt['created_by'] ?? 0]);

    $pdo->prepare("UPDATE receipts SET points_earned = ?, loyalty_points_awarded = 1 WHERE id = ? AND tenant_id = ?")
        ->execute([$points, $receipt_id, $tenant_id]);

    return ['success' => true, 'points' => $points, 'message' => points2($points) . ' points awarded'];
}

function reverseReceiptPoints(PDO $pdo, int $receipt_id, int $tenant_id): void {
    $stmt = $pdo->prepare("SELECT r.customer_id, r.points_earned FROM receipts r WHERE r.id = ? AND r.tenant_id = ? LIMIT 1");
    $stmt->execute([$receipt_id, $tenant_id]);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$receipt || empty($receipt['customer_id'])) return;
    $points = (float)$receipt['points_earned'];
    if ($points > 0) {
        $pdo->prepare("UPDATE customers SET loyalty_points = GREATEST(COALESCE(loyalty_points, 0) - ?, 0) WHERE id = ? AND tenant_id = ?")
            ->execute([$points, $receipt['customer_id'], $tenant_id]);
    }
    $pdo->prepare("DELETE FROM loyalty_points_log WHERE tenant_id = ? AND reference_type = 'receipt' AND reference_id = ?")->execute([$tenant_id, $receipt_id]);
    $pdo->prepare("UPDATE receipts SET points_earned = 0.00, loyalty_points_awarded = 0 WHERE id = ? AND tenant_id = ?")->execute([$receipt_id, $tenant_id]);
}

function getCustomerAvailablePoints(PDO $pdo, int $tenant_id, int $customer_id): float {
    $stmt = $pdo->prepare("SELECT COALESCE(loyalty_points, 0) AS loyalty_points FROM customers WHERE id = ? AND tenant_id = ? LIMIT 1");
    $stmt->execute([$customer_id, $tenant_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? round((float)$row['loyalty_points'], 2) : 0.00;
}

function calculatePointsDiscount(float $points_used, float $point_money_value): float {
    return round($points_used * $point_money_value, 2);
}

function validateReceiptInput(array $post, PDO $pdo, int $tenant_id, ?int $receipt_id, float $point_money_value, int $branch_id): array {
    $customer_id = (int)($post['customer_id'] ?? 0);
    $invoice_id = !empty($post['invoice_id']) ? (int)$post['invoice_id'] : null;

    $original_amount = (float)($post['original_amount'] ?? $post['amount'] ?? 0);
    $discount_applied = (float)($post['discount_applied'] ?? 0);
    $points_used = (float)($post['points_used'] ?? 0);

    if ($customer_id <= 0) throw new Exception('Please select a customer');
    if ($original_amount < 0) throw new Exception('Original amount cannot be negative');
    if ($discount_applied < 0) throw new Exception('Discount cannot be negative');
    if ($points_used < 0) throw new Exception('Points used cannot be negative');

    $available_points = getCustomerAvailablePoints($pdo, $tenant_id, $customer_id);
    $old_points_used = 0;
    if ($receipt_id) {
        $stmt = $pdo->prepare("SELECT COALESCE(points_used, 0) AS points_used FROM receipts WHERE id = ? AND tenant_id = ? AND customer_id = ? LIMIT 1");
        $stmt->execute([$receipt_id, $tenant_id, $customer_id]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC);
        $old_points_used = $old ? round((float)$old['points_used'], 2) : 0;
    }
    $max_points_allowed = round($available_points + $old_points_used, 2);
    if ($points_used > $max_points_allowed) throw new Exception('Customer only has ' . points2($available_points) . ' points available');

    $points_discount_amount = calculatePointsDiscount($points_used, $point_money_value);
    $total_discount = $discount_applied + $points_discount_amount;
    if ($total_discount > $original_amount) throw new Exception('Discount + points discount cannot exceed original amount');
    $amount = max(0, $original_amount - $total_discount);

    $payment_method = trim($post['payment_method'] ?? 'cash');
    $reference_number = trim($post['reference_number'] ?? '');
    $bank_account_id = !empty($post['bank_account_id']) ? (int)$post['bank_account_id'] : null;
    $payment_date = !empty($post['payment_date']) ? $post['payment_date'] : date('Y-m-d');
    $notes = trim($post['notes'] ?? '');

    if (!$bank_account_id) throw new Exception('Please select a bank account');

    $checkAccount = $pdo->prepare("SELECT id FROM bank_accounts WHERE id = ? AND tenant_id = ? AND is_active = 1 LIMIT 1");
    $checkAccount->execute([$bank_account_id, $tenant_id]);
    if (!$checkAccount->fetch()) throw new Exception('Selected bank account not found or inactive');

    return [
        'customer_id' => $customer_id,
        'invoice_id' => $invoice_id,
        'original_amount' => round($original_amount, 2),
        'discount_applied' => round($discount_applied, 2),
        'points_used' => round($points_used, 2),
        'points_discount_amount' => round($points_discount_amount, 2),
        'amount' => round($amount, 2),
        'payment_method' => $payment_method,
        'reference_number' => $reference_number,
        'bank_account_id' => $bank_account_id,
        'payment_date' => $payment_date,
        'notes' => $notes
    ];
}

function createReceipt(PDO $pdo, int $tenant_id, int $user_id, int $branch_id, array $data, float $loyalty_rate, float $point_money_value): array {
    $pdo->beginTransaction();
    try {
        $receipt_number = generateReceiptNumber($pdo, $tenant_id, $branch_id);
        $stmt = $pdo->prepare("INSERT INTO receipts (tenant_id, branch_id, receipt_number, invoice_id, customer_id, amount, original_amount, discount_applied, points_used, points_discount_amount, payment_date, payment_method, reference_number, bank_account_id, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$tenant_id, $branch_id, $receipt_number, $data['invoice_id'], $data['customer_id'], $data['amount'], $data['original_amount'], $data['discount_applied'], $data['points_used'], $data['points_discount_amount'], $data['payment_date'], $data['payment_method'], $data['reference_number'], $data['bank_account_id'], $data['notes'], $user_id]);
        $receipt_id = (int)$pdo->lastInsertId();
        updateBankAccountBalance($pdo, $tenant_id, $data['bank_account_id'], (float)$data['amount']);
        $points = awardReceiptPoints($pdo, $receipt_id, $tenant_id, $loyalty_rate, $branch_id);
        $pdo->commit();
        return ['success' => true, 'message' => 'Receipt created. Points: ' . points2($points['points']), 'receipt_id' => $receipt_id, 'receipt_number' => $receipt_number];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function updateReceipt(PDO $pdo, int $tenant_id, int $user_id, int $branch_id, int $receipt_id, array $data, float $loyalty_rate, float $point_money_value): array {
    $pdo->beginTransaction();
    try {
        $existing = getReceipt($pdo, $receipt_id, $tenant_id);
        if (!$existing) throw new Exception('Receipt not found');
        reverseReceiptPoints($pdo, $receipt_id, $tenant_id);
        updateBankAccountBalance($pdo, $tenant_id, !empty($existing['bank_account_id']) ? (int)$existing['bank_account_id'] : null, -1 * (float)$existing['amount']);
        $stmt = $pdo->prepare("UPDATE receipts SET invoice_id = ?, customer_id = ?, amount = ?, original_amount = ?, discount_applied = ?, points_used = ?, points_discount_amount = ?, payment_date = ?, payment_method = ?, reference_number = ?, bank_account_id = ?, notes = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$data['invoice_id'], $data['customer_id'], $data['amount'], $data['original_amount'], $data['discount_applied'], $data['points_used'], $data['points_discount_amount'], $data['payment_date'], $data['payment_method'], $data['reference_number'], $data['bank_account_id'], $data['notes'], $receipt_id, $tenant_id]);
        updateBankAccountBalance($pdo, $tenant_id, $data['bank_account_id'], (float)$data['amount']);
        $points = awardReceiptPoints($pdo, $receipt_id, $tenant_id, $loyalty_rate, $branch_id);
        $pdo->commit();
        return ['success' => true, 'message' => 'Receipt updated. New points: ' . points2($points['points']), 'receipt_id' => $receipt_id];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function deleteReceipt(PDO $pdo, int $tenant_id, int $receipt_id): array {
    $pdo->beginTransaction();
    try {
        $existing = getReceipt($pdo, $receipt_id, $tenant_id);
        if (!$existing) throw new Exception('Receipt not found');
        reverseReceiptPoints($pdo, $receipt_id, $tenant_id);
        updateBankAccountBalance($pdo, $tenant_id, !empty($existing['bank_account_id']) ? (int)$existing['bank_account_id'] : null, -1 * (float)$existing['amount']);
        $stmt = $pdo->prepare("DELETE FROM receipts WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$receipt_id, $tenant_id]);
        $pdo->commit();
        return ['success' => true, 'message' => 'Receipt deleted successfully'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function renderReceiptHTML(PDO $pdo, int $receipt_id, int $tenant_id, float $loyalty_rate, array $tenant_info): string {
    $receipt = getReceipt($pdo, $receipt_id, $tenant_id);
    if (!$receipt) return '<div class="alert alert-danger">Receipt not found</div>';
    $amount = (float)$receipt['amount'];
    $expected_points = calculateLoyaltyPoints($amount, $loyalty_rate);
    ob_start();
    ?>
    <div class="receipt-print-area">
        <div class="receipt-header">
            <h3><?= h($tenant_info['name'] ?? 'Company') ?></h3>
            <p><?= h($tenant_info['address'] ?? '') ?> <?= h($tenant_info['phone'] ?? '') ?></p>
            <h4><i class="fas fa-receipt"></i> RECEIPT</h4>
        </div>
        <div class="receipt-row"><strong>Receipt No:</strong><span><?= h($receipt['receipt_number']) ?></span></div>
        <div class="receipt-row"><strong>Date:</strong><span><?= h($receipt['payment_date']) ?></span></div>
        <div class="receipt-row"><strong>Customer:</strong><span><?= h($receipt['customer_name'] ?? '-') ?></span></div>
        <div class="receipt-row"><strong>Invoice:</strong><span><?= h($receipt['invoice_number'] ?? '-') ?></span></div>
        <hr>
        <div class="receipt-row"><strong>Original Amount:</strong><span>$<?= money2($receipt['original_amount']) ?></span></div>
        <div class="receipt-row"><strong>Discount:</strong><span class="text-success">-$<?= money2($receipt['discount_applied']) ?></span></div>
        <div class="receipt-row"><strong>Final Paid:</strong><span class="receipt-total">$<?= money2($receipt['amount']) ?></span></div>
        <div class="receipt-row"><strong>Points Used:</strong><span><?= points2($receipt['points_used']) ?> pts</span></div>
        <div class="receipt-row"><strong>Points Discount:</strong><span class="text-success">-$<?= money2($receipt['points_discount_amount'] ?? 0) ?></span></div>
        <div class="receipt-row"><strong>Points Earned:</strong><span class="text-primary"><?= points2($receipt['points_earned']) ?></span></div>
        <hr>
        <div class="receipt-footer">Thank you for your payment.</div>
    </div>
    <?php
    return ob_get_clean();
}

function renderTotalsHTML(array $totals): string {
    return '
    <div class="totals-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
        <div class="total-card" style="background: linear-gradient(135deg, #2D1859, #4B2C85); color: white; border-radius: 14px; padding: 15px 20px; text-align: center;">
            <div style="font-size: 14px; opacity: 0.9;"><i class="fas fa-receipt"></i> Total Receipts</div>
            <div style="font-size: 28px; font-weight: 800;">' . number_format($totals['total_receipts'], 0) . '</div>
        </div>
        <div class="total-card" style="background: linear-gradient(135deg, #10B981, #059669); color: white; border-radius: 14px; padding: 15px 20px; text-align: center;">
            <div style="font-size: 14px; opacity: 0.9;"><i class="fas fa-dollar-sign"></i> Total Amount</div>
            <div style="font-size: 28px; font-weight: 800;">$' . money2($totals['total_amount_received']) . '</div>
        </div>
        <div class="total-card" style="background: linear-gradient(135deg, #F59E0B, #D97706); color: white; border-radius: 14px; padding: 15px 20px; text-align: center;">
            <div style="font-size: 14px; opacity: 0.9;"><i class="fas fa-star"></i> Points Earned</div>
            <div style="font-size: 28px; font-weight: 800;">' . points2($totals['total_points_earned']) . '</div>
        </div>
        <div class="total-card" style="background: linear-gradient(135deg, #EF4444, #DC2626); color: white; border-radius: 14px; padding: 15px 20px; text-align: center;">
            <div style="font-size: 14px; opacity: 0.9;"><i class="fas fa-coins"></i> Points Used</div>
            <div style="font-size: 28px; font-weight: 800;">' . points2($totals['total_points_used']) . '</div>
        </div>
    </div>';
}

// ============================================
// AJAX HANDLERS
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    $action = $_POST['ajax_action'];

    try {
        if ($action === 'get_customers') {
            $q = trim($_POST['q'] ?? '');
            $selected_id = (int)($_POST['selected_id'] ?? 0);
            $where = "tenant_id = ? AND is_active = 1";
            $params = [$tenant_id];
            if ($selected_id > 0) {
                $where .= " AND id = ?";
                $params[] = $selected_id;
            } elseif ($q !== '') {
                $where .= " AND (customer_name LIKE ? OR phone LIKE ?)";
                $like = '%' . $q . '%';
                $params[] = $like;
                $params[] = $like;
            }
            $stmt = $pdo->prepare("SELECT id, customer_name, phone, COALESCE(loyalty_points, 0) AS loyalty_points FROM customers WHERE {$where} ORDER BY customer_name LIMIT 25");
            $stmt->execute($params);
            json_response(['success' => true, 'customers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        if ($action === 'quick_add_customer') {
            $name = trim($_POST['customer_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            if ($name === '') throw new Exception('Customer name is required');
            if ($phone !== '') {
                $normalized = preg_replace('/\D/', '', $phone);
                $chk = $pdo->prepare("SELECT id, customer_name, phone, COALESCE(loyalty_points, 0) AS loyalty_points FROM customers WHERE tenant_id = ? AND REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '_', '') = ? LIMIT 1");
                $chk->execute([$tenant_id, $normalized]);
                $existing = $chk->fetch(PDO::FETCH_ASSOC);
                if ($existing) json_response(['success' => true, 'message' => 'Customer already exists', 'customer' => $existing]);
            }
            $ins = $pdo->prepare("INSERT INTO customers (tenant_id, branch_id, customer_name, phone, email, address, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
            $ins->execute([$tenant_id, $assigned_branch_id, $name, $phone, $email, $address]);
            $id = (int)$pdo->lastInsertId();
            json_response(['success' => true, 'message' => 'Customer created successfully', 'customer' => ['id' => $id, 'customer_name' => $name, 'phone' => $phone, 'loyalty_points' => 0]]);
        }

        if ($action === 'get_invoices') {
            $customer_id = (int)($_POST['customer_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, invoice_number, COALESCE(total_amount, 0) AS total_amount, COALESCE(paid_amount, 0) AS paid_amount, GREATEST(COALESCE(total_amount, 0) - COALESCE(paid_amount, 0), 0) AS due_amount FROM invoices WHERE tenant_id = ? AND customer_id = ? AND status NOT IN ('paid', 'cancelled') ORDER BY id DESC");
            $stmt->execute([$tenant_id, $customer_id]);
            json_response(['success' => true, 'invoices' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        if ($action === 'get_bank_accounts') {
            json_response(['success' => true, 'accounts' => getBankAccounts($pdo, $tenant_id)]);
        }

        if ($action === 'create_bank_account') {
            $data = ['account_name' => trim($_POST['account_name'] ?? ''), 'bank_name' => trim($_POST['bank_name'] ?? ''), 'account_number' => trim($_POST['account_number'] ?? ''), 'account_type' => $_POST['account_type'] ?? 'checking', 'currency' => $_POST['currency'] ?? 'USD', 'opening_balance' => (float)($_POST['opening_balance'] ?? 0), 'is_default' => isset($_POST['is_default']) ? 1 : 0];
            if (empty($data['account_name'])) throw new Exception('Account name is required');
            json_response(createBankAccount($pdo, $tenant_id, $user_id, $data));
        }

        if ($action === 'update_bank_account') {
            $account_id = (int)($_POST['account_id'] ?? 0);
            if ($account_id <= 0) throw new Exception('Account ID required');
            $data = ['account_name' => trim($_POST['account_name'] ?? ''), 'bank_name' => trim($_POST['bank_name'] ?? ''), 'account_number' => trim($_POST['account_number'] ?? ''), 'account_type' => $_POST['account_type'] ?? 'checking', 'currency' => $_POST['currency'] ?? 'USD', 'is_default' => isset($_POST['is_default']) ? 1 : 0];
            if (empty($data['account_name'])) throw new Exception('Account name is required');
            json_response(updateBankAccount($pdo, $tenant_id, $account_id, $data));
        }

        if ($action === 'delete_bank_account') {
            $account_id = (int)($_POST['account_id'] ?? 0);
            if ($account_id <= 0) throw new Exception('Account ID required');
            json_response(deleteBankAccount($pdo, $tenant_id, $account_id));
        }

        if ($action === 'create_receipt') {
            $data = validateReceiptInput($_POST, $pdo, $tenant_id, null, $tenant_point_money_value, $assigned_branch_id);
            $created = createReceipt($pdo, $tenant_id, $user_id, $assigned_branch_id, $data, $tenant_loyalty_rate, $tenant_point_money_value);
            json_response($created);
        }

        if ($action === 'update_receipt') {
            $receipt_id = (int)($_POST['receipt_id'] ?? 0);
            if ($receipt_id <= 0) throw new Exception('Receipt ID missing');
            $data = validateReceiptInput($_POST, $pdo, $tenant_id, $receipt_id, $tenant_point_money_value, $assigned_branch_id);
            $updated = updateReceipt($pdo, $tenant_id, $user_id, $assigned_branch_id, $receipt_id, $data, $tenant_loyalty_rate, $tenant_point_money_value);
            json_response($updated);
        }

        if ($action === 'delete_receipt') {
            $receipt_id = (int)($_POST['receipt_id'] ?? 0);
            if ($receipt_id <= 0) throw new Exception('Receipt ID missing');
            json_response(deleteReceipt($pdo, $tenant_id, $receipt_id));
        }

        if ($action === 'get_receipt_data') {
            $receipt_id = (int)($_POST['receipt_id'] ?? 0);
            $receipt = getReceipt($pdo, $receipt_id, $tenant_id);
            if (!$receipt) throw new Exception('Receipt not found');
            json_response(['success' => true, 'receipt' => $receipt]);
        }

        if ($action === 'view_receipt') {
            $receipt_id = (int)($_POST['receipt_id'] ?? 0);
            json_response(['success' => true, 'html' => renderReceiptHTML($pdo, $receipt_id, $tenant_id, $tenant_loyalty_rate, $tenant_info)]);
        }

        if ($action === 'list_receipts') {
            $page = max(1, (int)($_POST['page'] ?? 1));
            $limit = 15;
            $offset = ($page - 1) * $limit;
            $search = trim($_POST['search'] ?? '');
            $customer_id = (int)($_POST['customer_id'] ?? 0);
            $where = ["r.tenant_id = ?", "r.branch_id = ?"];
            $params = [$tenant_id, $assigned_branch_id];
            if ($search !== '') {
                $where[] = "(r.receipt_number LIKE ? OR c.customer_name LIKE ? OR r.reference_number LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            if ($customer_id > 0) {
                $where[] = "r.customer_id = ?";
                $params[] = $customer_id;
            }
            $where_clause = 'WHERE ' . implode(' AND ', $where);
            $count_stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM receipts r LEFT JOIN customers c ON r.customer_id = c.id $where_clause");
            $count_stmt->execute($params);
            $total = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
            $total_pages = max(1, (int)ceil($total / $limit));
            $totals = getReceiptTotals($pdo, $tenant_id, $assigned_branch_id, $search, $customer_id);
            $stmt = $pdo->prepare("SELECT r.*, c.customer_name, c.phone AS customer_phone, i.invoice_number, u.full_name AS created_by_name, ba.account_name, ba.bank_name FROM receipts r LEFT JOIN customers c ON r.customer_id = c.id LEFT JOIN invoices i ON r.invoice_id = i.id LEFT JOIN users u ON r.created_by = u.id LEFT JOIN bank_accounts ba ON r.bank_account_id = ba.id $where_clause ORDER BY r.created_at DESC, r.id DESC LIMIT $limit OFFSET $offset");
            $stmt->execute($params);
            $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ob_start();
            echo renderTotalsHTML($totals);
            ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover receipt-table">
                    <thead>
                        <tr><th>ID</th><th>Receipt #</th><th>Date</th><th>Customer</th><th>Original</th><th>Discount</th><th>Final</th><th>Points Used</th><th>Points Earned</th><th>Method</th><th>Account</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php if ($receipts): foreach ($receipts as $r): ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <td><strong><?= h($r['receipt_number']) ?></strong><?php if (!empty($r['invoice_number'])): ?><br><small>Inv: <?= h($r['invoice_number']) ?></small><?php endif; ?></td>
                            <td><?= h($r['payment_date'] ?: date('Y-m-d', strtotime($r['created_at']))) ?></td>
                            <td><strong><?= h($r['customer_name'] ?? '-') ?></strong><?php if (!empty($r['customer_phone'])): ?><br><small><?= h($r['customer_phone']) ?></small><?php endif; ?></td>
                            <td class="text-right">$<?= money2($r['original_amount']) ?></td>
                            <td class="text-right text-success">-$<?= money2($r['discount_applied']) ?></td>
                            <td class="text-right"><strong>$<?= money2($r['amount']) ?></strong></td>
                            <td class="text-center"><?= points2($r['points_used']) ?></td>
                            <td class="text-center"><span class="badge badge-success"><?= points2($r['points_earned']) ?></span></td>
                            <td><?= h(ucfirst(str_replace('_', ' ', $r['payment_method'] ?? 'cash'))) ?></td>
                            <td class="text-center"><?php if ($r['payment_method'] === 'bank_transfer' && !empty($r['bank_name'])): ?><small><?= h($r['bank_name']) ?></small><?php else: ?>-<?php endif; ?></td>
                            <td class="action-buttons"><button class="btn btn-sm btn-info view-receipt" data-id="<?= (int)$r['id'] ?>">View</button><button class="btn btn-sm btn-warning edit-receipt" data-id="<?= (int)$r['id'] ?>">Edit</button><button class="btn btn-sm btn-danger delete-receipt" data-id="<?= (int)$r['id'] ?>">Delete</button></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="12" class="text-center p-4">No receipts found</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total_pages > 1): ?>
                <div class="pagination"><?php if ($page > 1): ?><a data-page="<?= $page - 1 ?>" class="pagination-link"><i class="fas fa-chevron-left"></i> Previous</a><?php endif; ?><?php for ($i = 1; $i <= $total_pages; $i++): ?><?php if ($i == $page): ?><span class="active-page"><?= $i ?></span><?php elseif ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?><a data-page="<?= $i ?>" class="pagination-link"><?= $i ?></a><?php elseif ($i == $page - 3 || $i == $page + 3): ?><span class="pagination-dots">...</span><?php endif; ?><?php endfor; ?><?php if ($page < $total_pages): ?><a data-page="<?= $page + 1 ?>" class="pagination-link">Next <i class="fas fa-chevron-right"></i></a><?php endif; ?></div>
            <?php endif;
            json_response(['success' => true, 'table_html' => ob_get_clean(), 'total' => $total, 'page' => $page, 'total_pages' => $total_pages]);
        }

        json_response(['success' => false, 'message' => 'Unknown action']);
    } catch (Throwable $e) {
        json_response(['success' => false, 'message' => $e->getMessage()]);
    }
}

include_once __DIR__ . '/../includes/header.php';
?>
<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <title>Receipt Management - <?= h($branch_name) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root { --primary: #2D1859; --primary-light: #4B2C85; --yellow: #F5C410; --border: #e5e7eb; }
        body { background: #f4f5f8; font-family: "Segoe UI", Tahoma, sans-serif; }
        .page-wrap { padding: 20px; }
        .page-header-custom { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 20px 25px; display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap; margin-bottom: 20px; }
        .page-header-custom h1 { font-size: 24px; margin: 0; font-weight: 700; }
        .page-header-custom h1 i { color: var(--primary); }
        .branch-badge { display: inline-flex; align-items: center; gap: 6px; background: var(--primary); color: white; border-radius: 999px; padding: 8px 14px; font-weight: 600; font-size: 13px; }
        .btn-primary-custom { background: var(--primary); color: white; border: none; padding: 10px 18px; border-radius: 999px; font-weight: 600; }
        .btn-primary-custom:hover { background: var(--primary-light); color: white; }
        .btn-outline-primary-custom { background: transparent; border: 1px solid var(--primary); color: var(--primary); padding: 8px 16px; border-radius: 999px; font-weight: 500; }
        .filters-card, .table-card { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 18px; margin-bottom: 20px; }
        .filter-form { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; }
        .filter-group { flex: 1; min-width: 200px; }
        .filter-group label { font-size: 13px; font-weight: 700; color: #374151; }
        .receipt-table th { background: #f9fafb; font-size: 13px; white-space: nowrap; }
        .action-buttons { white-space: nowrap; }
        .badge-success { background: #10b981; color: white; padding: 5px 9px; border-radius: 999px; }
        .pagination { display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; margin-top: 25px; }
        .pagination-link, .active-page { min-width: 42px; height: 42px; padding: 0 14px; border-radius: 12px; display: flex; align-items: center; justify-content: center; text-decoration: none; font-size: 13px; font-weight: 600; cursor: pointer; }
        .pagination-link { background: #fff; color: #374151; border: 1px solid #d1d5db; }
        .pagination-link:hover { background: var(--primary); color: #fff; border-color: var(--primary); }
        .active-page { background: var(--primary); color: #fff; border: 1px solid var(--primary); }
        .pagination-dots { padding: 0 5px; font-weight: bold; color: #6b7280; }
        .receipt-print-area { max-width: 540px; margin: 0 auto; background: #fff; padding: 20px; }
        .receipt-header, .receipt-footer { text-align: center; }
        .receipt-header { border-bottom: 2px solid var(--primary); margin-bottom: 15px; padding-bottom: 15px; }
        .receipt-row { display: flex; justify-content: space-between; gap: 15px; padding: 7px 0; border-bottom: 1px dashed #eee; }
        .receipt-total { color: #10b981; font-size: 18px; font-weight: 800; }
        .customer-search-wrap { position: relative; }
        .customer-search-results { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 12px 30px rgba(17,24,39,0.12); max-height: 260px; overflow-y: auto; z-index: 1055; display: none; }
        .customer-search-item { padding: 10px 12px; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
        .customer-search-item:hover { background: #f8f5fb; }
        .selected-customer-box { display: none; margin-top: 8px; border: 1px solid #e5e7eb; background: #f9fafb; border-radius: 10px; padding: 8px 10px; font-size: 13px; }
        @media (max-width: 768px) { .pagination-link, .active-page { min-width: 36px; height: 36px; font-size: 12px; padding: 0 10px; } }
    </style>
</head>
<body>
<div class="page-wrap">
    <div class="page-header-custom">
        <div><h1><i class="fas fa-receipt"></i> Lacag Qabashada (Receipts)</h1><small class="text-muted"><?= h($branch_name) ?> - Receipt Management with Loyalty Points</small></div>
        <div class="d-flex align-items-center flex-wrap" style="gap:8px;">
            <span class="branch-badge"><i class="fas fa-code-branch"></i> <?= h($branch_name) ?></span>
            <span class="branch-badge"><i class="fas fa-star"></i> <?= points2($tenant_loyalty_rate) ?> points / $100</span>
            <button class="btn-primary-custom" id="addReceiptBtn"><i class="fas fa-plus"></i> New Receipt</button>
        </div>
    </div>

    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group"><label>Search</label><input type="text" id="searchInput" class="form-control" placeholder="Receipt #, customer, reference..."></div>
            <div class="filter-group customer-search-wrap"><label>Customer</label><input type="hidden" id="customerFilter" value="0"><input type="text" id="customerFilterSearch" class="form-control" placeholder="Search customer..." autocomplete="off"><div id="customerFilterResults" class="customer-search-results"></div></div>
            <div><button class="btn btn-secondary" id="resetBtn">Reset</button><button class="btn-primary-custom" id="refreshBtn" style="margin-left:8px;">Refresh</button></div>
        </div>
    </div>

    <div class="table-card" id="receiptsTableBox"><div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div></div>
</div>

<!-- Receipt Modal -->
<div class="modal fade" id="receiptFormModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="receiptForm">
            <div class="modal-header" style="background:#2D1859;color:#fff;"><h5 class="modal-title" id="receiptFormTitle">New Receipt</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="receipt_id" id="receipt_id">
                <div class="row">
                    <div class="col-md-6 form-group customer-search-wrap">
                        <label>Customer *</label>
                        <input type="hidden" name="customer_id" id="customer_id" required>
                        <div class="input-group"><input type="text" id="customer_search" class="form-control" placeholder="Search by name or phone..." autocomplete="off"><div class="input-group-append"><button type="button" class="btn btn-outline-primary" id="addCustomerBtn">+ Add</button></div></div>
                        <div id="customerResults" class="customer-search-results"></div>
                        <div id="selectedCustomerInfo" class="selected-customer-box"></div>
                    </div>
                    <div class="col-md-6 form-group"><label>Invoice</label><select name="invoice_id" id="invoice_id" class="form-control"><option value="">-- Optional --</option></select></div>
                    <div class="col-md-4 form-group"><label>Original Amount *</label><input type="number" step="0.01" min="0" name="original_amount" id="original_amount" class="form-control" required></div>
                    <div class="col-md-4 form-group"><label>Discount</label><input type="number" step="0.01" min="0" name="discount_applied" id="discount_applied" class="form-control" value="0.00"></div>
                    <div class="col-md-4 form-group"><label>Final Paid</label><input type="number" step="0.01" min="0" name="amount" id="amount" class="form-control" readonly></div>
                    <div class="col-md-4 form-group"><label>Points Used</label><input type="number" step="0.01" min="0" name="points_used" id="points_used" class="form-control" value="0.00"><small class="text-muted" id="available_points_text">Available: 0.00 pts</small></div>
                    <div class="col-md-4 form-group"><label>Points Discount</label><input type="text" id="points_discount_amount" class="form-control" readonly value="0.00"><small class="text-muted">100 points = $1</small></div>
                    <div class="col-md-4 form-group"><label>Points Earned</label><input type="text" id="expected_points" class="form-control" readonly><small class="text-muted" id="points_formula"></small></div>
                    <div class="col-md-4 form-group"><label>Payment Date</label><input type="date" name="payment_date" id="payment_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                    <div class="col-md-4 form-group"><label>Payment Method</label><select name="payment_method" id="payment_method" class="form-control"><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option></select></div>
                    <div class="col-md-4 form-group" id="bank_account_group"><label>Bank Account *</label><select name="bank_account_id" id="bank_account_id" class="form-control" required><option value="">-- Select Account --</option></select><small class="text-muted">Funds will be added to this account</small></div>
                    <div class="col-md-4 form-group"><label>Reference</label><input type="text" name="reference_number" id="reference_number" class="form-control"></div>
                    <div class="col-md-12 form-group"><label>Notes</label><textarea name="notes" id="notes" class="form-control" rows="2"></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn-primary-custom">Save Receipt</button></div>
        </form>
    </div>
</div>

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog"><form class="modal-content" id="quickCustomerForm"><div class="modal-header" style="background:#2D1859;color:#fff;"><h5 class="modal-title">Add Customer</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div><div class="modal-body"><div class="form-group"><label>Customer Name *</label><input type="text" name="customer_name" id="quick_customer_name" class="form-control" required></div><div class="form-group"><label>Phone</label><input type="text" name="phone" id="quick_customer_phone" class="form-control"></div><div class="form-group"><label>Email</label><input type="email" name="email" class="form-control"></div><div class="form-group"><label>Address</label><textarea name="address" class="form-control" rows="2"></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn-primary-custom">Save Customer</button></div></form></div>
</div>

<!-- View Receipt Modal -->
<div class="modal fade" id="viewReceiptModal" tabindex="-1">
    <div class="modal-dialog modal-lg" style="max-width:650px;"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Receipt</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body" id="viewReceiptBody"></div><div class="modal-footer"><button class="btn btn-secondary" data-dismiss="modal">Close</button><button class="btn-primary-custom" id="printReceiptBtn"><i class="fas fa-print"></i> Print</button></div></div></div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const LOYALTY_RATE = <?= json_encode((float)$tenant_loyalty_rate) ?>;
const POINT_MONEY_VALUE = <?= json_encode((float)$tenant_point_money_value) ?>;
let selectedCustomerPoints = 0;
let currentPage = 1;
let customersCache = [];
let bankAccountsCache = [];

function escapeHtml(text) { if (!text) return ''; return String(text).replace(/[&<>"']/g, function(m) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m]; }); }
function fixed2(value) { return Number(value || 0).toFixed(2); }
function showAlert(type, message) { const cls = type === 'success' ? 'alert-success' : 'alert-danger'; $('#alert-placeholder').remove(); $('body').append(`<div id="alert-placeholder" class="alert ${cls} alert-dismissible fade show" style="position:fixed;top:20px;right:20px;z-index:9999;min-width:320px;">${message}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`); setTimeout(() => $('#alert-placeholder').fadeOut(500, function(){ $(this).remove(); }), 4500); }
function setSelectedCustomerPoints(extraPoints = 0) { const c = customersCache.find(c => String(c.id) === String($('#customer_id').val())); selectedCustomerPoints = c ? Number(c.loyalty_points || 0) + Number(extraPoints || 0) : 0; $('#points_used').attr('max', fixed2(selectedCustomerPoints)); $('#available_points_text').text(`Available: ${fixed2(selectedCustomerPoints)} pts`); }
function calculateFormAmounts() {
    const original = Number($('#original_amount').val() || 0);
    const discount = Number($('#discount_applied').val() || 0);
    let pointsUsed = Number($('#points_used').val() || 0);
    if (pointsUsed > selectedCustomerPoints) { pointsUsed = selectedCustomerPoints; $('#points_used').val(fixed2(pointsUsed)); showAlert('error', `Customer has only ${fixed2(selectedCustomerPoints)} points`); }
    const pointsDiscount = pointsUsed * POINT_MONEY_VALUE;
    const finalPaid = Math.max(0, original - discount - pointsDiscount);
    const points = (finalPaid / 100) * LOYALTY_RATE;
    $('#points_discount_amount').val(fixed2(pointsDiscount));
    $('#amount').val(fixed2(finalPaid));
    $('#expected_points').val(fixed2(points));
    $('#points_formula').text(`Points discount: ${fixed2(pointsUsed)} pts = $${fixed2(pointsDiscount)} | Earned: ${fixed2(finalPaid)} / 100 × ${fixed2(LOYALTY_RATE)} = ${fixed2(points)}`);
}

function loadBankAccounts(callback) {
    $.post(window.location.href, {ajax_action: 'get_bank_accounts'}, function(res) {
        if (res.success) {
            bankAccountsCache = res.accounts || [];
            let opts = '<option value="">-- Select Account --</option>';
            bankAccountsCache.forEach(acc => { opts += `<option value="${acc.id}" ${acc.is_default ? 'selected' : ''}>${escapeHtml(acc.bank_name || '')} - ${escapeHtml(acc.account_name)} (${acc.currency})</option>`; });
            $('#bank_account_id').html(opts);
        }
        if (callback) callback();
    }, 'json');
}

function loadInvoices(customerId, selectedId = '') {
    $('#invoice_id').html('<option value="">Loading...</option>');
    if (!customerId) { $('#invoice_id').html('<option value="">-- Optional --</option>'); return; }
    $.post(window.location.href, {ajax_action: 'get_invoices', customer_id: customerId}, function(res) {
        let opts = '<option value="">-- Optional --</option>';
        if (res.success && res.invoices) { res.invoices.forEach(inv => { opts += `<option value="${inv.id}" ${String(inv.id) === String(selectedId) ? 'selected' : ''} data-due="${fixed2(inv.due_amount)}">${escapeHtml(inv.invoice_number)} - Due $${fixed2(inv.due_amount)}</option>`; }); }
        $('#invoice_id').html(opts);
        if (selectedId) fillAmountsFromSelectedInvoice(true);
    }, 'json');
}

function fillAmountsFromSelectedInvoice(forceFill = false) {
    const option = $('#invoice_id option:selected');
    if (!$('#invoice_id').val()) { if (forceFill) { $('#original_amount').val('0.00'); $('#discount_applied').val('0.00'); calculateFormAmounts(); } return; }
    const dueAmount = Number(option.data('due') || 0);
    if (forceFill || Number($('#original_amount').val() || 0) <= 0) { $('#original_amount').val(fixed2(dueAmount)); $('#discount_applied').val('0.00'); }
    calculateFormAmounts();
}

function selectCustomerFromObject(c, mode) {
    if (!customersCache.find(x => String(x.id) === String(c.id))) customersCache.push(c);
    if (mode === 'filter') {
        $('#customerFilter').val(c.id);
        $('#customerFilterSearch').val(`${c.customer_name} ${c.phone ? '- ' + c.phone : ''}`);
        $('#customerFilterResults').hide().empty();
        currentPage = 1;
        loadReceipts();
        return;
    }
    $('#customer_id').val(c.id);
    $('#customer_search').val(`${c.customer_name} ${c.phone ? '- ' + c.phone : ''}`);
    $('#customerResults').hide().empty();
    $('#selectedCustomerInfo').html(`<strong>${escapeHtml(c.customer_name)}</strong><br><small>${escapeHtml(c.phone || 'No phone')} | Points: ${fixed2(c.loyalty_points || 0)}</small>`).show();
    setSelectedCustomerPoints(0);
    loadInvoices(c.id);
}

function loadReceipts() {
    $('#receiptsTableBox').html('<div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>');
    $.post(window.location.href, {ajax_action: 'list_receipts', page: currentPage, search: $('#searchInput').val(), customer_id: $('#customerFilter').val()}, function(res) {
        if (res.success) { $('#receiptsTableBox').html(res.table_html); bindTableEvents(); }
        else { $('#receiptsTableBox').html(`<div class="alert alert-danger">${escapeHtml(res.message)}</div>`); }
    }, 'json');
}

function bindTableEvents() {
    $('.pagination-link').off('click').on('click', function() { currentPage = Number($(this).data('page')) || 1; loadReceipts(); });
    $('.view-receipt').off('click').on('click', function() { const id = $(this).data('id'); $('#viewReceiptBody').html('<div class="text-center p-4"><i class="fas fa-spinner fa-spin"></i></div>'); $('#viewReceiptModal').modal('show'); $.post(window.location.href, {ajax_action: 'view_receipt', receipt_id: id}, function(res) { $('#viewReceiptBody').html(res.success ? res.html : `<div class="alert alert-danger">${escapeHtml(res.message)}</div>`); }, 'json'); });
    $('.edit-receipt').off('click').on('click', function() { const id = $(this).data('id'); $.post(window.location.href, {ajax_action: 'get_receipt_data', receipt_id: id}, function(res) { if (!res.success) { showAlert('error', res.message); return; } const r = res.receipt; resetForm(); $('#receiptFormTitle').text('Update Receipt'); $('#receipt_id').val(r.id); selectCustomerFromObject({id: r.customer_id, customer_name: r.customer_name, phone: r.customer_phone, loyalty_points: r.loyalty_points}, 'form'); $('#original_amount').val(fixed2(r.original_amount)); $('#discount_applied').val(fixed2(r.discount_applied)); $('#points_used').val(fixed2(r.points_used)); $('#payment_date').val(r.payment_date); $('#payment_method').val(r.payment_method); $('#reference_number').val(r.reference_number || ''); $('#bank_account_id').val(r.bank_account_id || ''); $('#notes').val(r.notes || ''); calculateFormAmounts(); $('#receiptFormModal').modal('show'); }, 'json'); });
    $('.delete-receipt').off('click').on('click', function() { if (!confirm('Delete this receipt? Points will be reversed.')) return; $.post(window.location.href, {ajax_action: 'delete_receipt', receipt_id: $(this).data('id')}, function(res) { showAlert(res.success ? 'success' : 'error', res.message); if (res.success) loadReceipts(); }, 'json'); });
}

function resetForm() {
    $('#receiptForm')[0].reset();
    $('#receipt_id').val('');
    $('#customer_id').val('');
    $('#customer_search').val('');
    $('#customerResults').hide().empty();
    $('#selectedCustomerInfo').hide().empty();
    $('#receiptFormTitle').text('New Receipt');
    $('#payment_date').val('<?= date('Y-m-d') ?>');
    $('#discount_applied').val('0.00');
    $('#points_used').val('0.00');
    setSelectedCustomerPoints(0);
    $('#amount').val('0.00');
    $('#expected_points').val('0.00');
    $('#invoice_id').html('<option value="">-- Optional --</option>');
    $('#bank_account_id').val('');
}

$(document).ready(function() {
    loadBankAccounts();
    loadReceipts();

    $('#addReceiptBtn').on('click', function() { resetForm(); $('#receiptFormModal').modal('show'); });
    $('#refreshBtn').on('click', function() { currentPage = 1; loadReceipts(); });
    $('#resetBtn').on('click', function() { currentPage = 1; $('#searchInput').val(''); $('#customerFilter').val('0'); $('#customerFilterSearch').val(''); $('#customerFilterResults').hide().empty(); loadReceipts(); });
    $('#searchInput').on('keyup', function(e) { if (e.key === 'Enter') { currentPage = 1; loadReceipts(); } });

    let searchTimer;
    $('#customer_search').on('input', function() { clearTimeout(searchTimer); const q = $(this).val(); searchTimer = setTimeout(() => { if (q.trim().length < 1) { $('#customerResults').hide().empty(); return; } $.post(window.location.href, {ajax_action: 'get_customers', q: q.trim()}, function(res) { if (res.success) { let html = ''; res.customers.forEach(c => { html += `<div class="customer-search-item" data-id="${c.id}" data-name="${escapeHtml(c.customer_name)}" data-phone="${escapeHtml(c.phone || '')}" data-points="${fixed2(c.loyalty_points || 0)}"><strong>${escapeHtml(c.customer_name)}</strong><small>${escapeHtml(c.phone || 'No phone')} | Points: ${fixed2(c.loyalty_points || 0)}</small></div>`; }); $('#customerResults').html(html).show(); } }, 'json'); }, 300); });
    $('#customerFilterSearch').on('input', function() { clearTimeout(searchTimer); const q = $(this).val(); if (q.trim() === '') { $('#customerFilter').val('0'); currentPage = 1; loadReceipts(); } searchTimer = setTimeout(() => { if (q.trim().length < 1) { $('#customerFilterResults').hide().empty(); return; } $.post(window.location.href, {ajax_action: 'get_customers', q: q.trim()}, function(res) { if (res.success) { let html = ''; res.customers.forEach(c => { html += `<div class="customer-search-item" data-id="${c.id}" data-name="${escapeHtml(c.customer_name)}" data-phone="${escapeHtml(c.phone || '')}" data-points="${fixed2(c.loyalty_points || 0)}"><strong>${escapeHtml(c.customer_name)}</strong><small>${escapeHtml(c.phone || 'No phone')} | Points: ${fixed2(c.loyalty_points || 0)}</small></div>`; }); $('#customerFilterResults').html(html).show(); } }, 'json'); }, 300); });

    $(document).on('click', '.customer-search-item', function() { const c = { id: $(this).data('id'), customer_name: $(this).data('name'), phone: $(this).data('phone'), loyalty_points: $(this).data('points') }; selectCustomerFromObject(c, $(this).parent().attr('id') === 'customerFilterResults' ? 'filter' : 'form'); });
    $(document).on('click', function(e) { if (!$(e.target).closest('.customer-search-wrap').length) $('.customer-search-results').hide(); });

    $('#addCustomerBtn').on('click', function() { $('#quickCustomerForm')[0].reset(); $('#quick_customer_name').val($('#customer_search').val()); $('#addCustomerModal').modal('show'); });
    $('#quickCustomerForm').on('submit', function(e) { e.preventDefault(); const fd = new FormData(this); fd.append('ajax_action', 'quick_add_customer'); $.ajax({ url: window.location.href, method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json', success: function(res) { showAlert(res.success ? 'success' : 'error', res.message); if (res.success && res.customer) { $('#addCustomerModal').modal('hide'); selectCustomerFromObject(res.customer, 'form'); } }, error: function() { showAlert('error', 'Server error'); } }); });

    $('#invoice_id').on('change', function() { fillAmountsFromSelectedInvoice(true); });
    $('#original_amount, #discount_applied, #points_used').on('input', calculateFormAmounts);

    $('#receiptForm').on('submit', function(e) { e.preventDefault(); const receiptId = $('#receipt_id').val(); const action = receiptId ? 'update_receipt' : 'create_receipt'; const fd = new FormData(this); fd.append('ajax_action', action); $.ajax({ url: window.location.href, method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json', success: function(res) { showAlert(res.success ? 'success' : 'error', res.message); if (res.success) { $('#receiptFormModal').modal('hide'); loadReceipts(); loadBankAccounts(); } }, error: function() { showAlert('error', 'Server error'); } }); });

    $('#printReceiptBtn').on('click', function() { const html = $('#viewReceiptBody').html(); const w = window.open('', '_blank', 'width=800,height=700'); w.document.write(`<!doctype html><html><head><title>Receipt</title><style>body{font-family:Arial,sans-serif;padding:20px}.receipt-print-area{max-width:540px;margin:0 auto}.receipt-header,.receipt-footer{text-align:center}.receipt-header{border-bottom:2px solid #2D1859;margin-bottom:15px;padding-bottom:15px}.receipt-row{display:flex;justify-content:space-between;gap:15px;padding:7px 0;border-bottom:1px dashed #eee}.receipt-total{color:#10b981;font-size:18px;font-weight:800}</style></head><body>${html}<script>window.onload=function(){window.print();setTimeout(()=>window.close(),500)}<\/script></body></html>`); w.document.close(); });
});
</script>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>