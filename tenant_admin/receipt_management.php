<?php
// tenant_admin/receipt_management.php
// Professional Receipt Management: INSERT / UPDATE / DELETE + accurate loyalty points decimals.
// Points formula: points = (paid_amount / 100) * tenant.loyalty_amount_points
// Example: amount=1, rate=5 => 0.05 points. amount=89, rate=5 => 4.45 points.
// Includes TOTAL SUMMARY for receipts, amounts, points earned, points used.

// Stop PHP warnings/notices from breaking AJAX JSON.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

$user_id = (int)$_SESSION['user_id'];
$user_tenant_id = (int)($_SESSION['tenant_id'] ?? 0);

if ($user_tenant_id <= 0) {
    header("Location: ../login.php?error=no_tenant");
    exit;
}

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
                PRIMARY KEY (id),
                KEY idx_receipts_tenant (tenant_id),
                KEY idx_receipts_customer (customer_id),
                KEY idx_receipts_invoice (invoice_id),
                KEY idx_receipts_payment (payment_id),
                KEY idx_receipts_number (receipt_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    $columns = [
        'receipt_number' => "VARCHAR(100) NOT NULL DEFAULT ''",
        'invoice_id' => "INT(11) DEFAULT NULL",
        'payment_id' => "INT(11) DEFAULT NULL",
        'customer_id' => "INT(11) DEFAULT NULL",
        'amount' => "DECIMAL(15,2) NOT NULL DEFAULT 0.00",
        'payment_date' => "DATE DEFAULT NULL",
        'payment_method' => "VARCHAR(50) DEFAULT 'cash'",
        'reference_number' => "VARCHAR(150) DEFAULT NULL",
        'bank_account_id' => "INT(11) DEFAULT NULL",
        'notes' => "TEXT DEFAULT NULL",
        'created_by' => "INT(11) DEFAULT NULL",
        'updated_at' => "DATETIME DEFAULT NULL",
        'original_amount' => "DECIMAL(15,2) DEFAULT 0.00",
        'discount_applied' => "DECIMAL(15,2) DEFAULT 0.00",
        'points_used' => "DECIMAL(12,2) DEFAULT 0.00",
        'points_discount_amount' => "DECIMAL(15,2) DEFAULT 0.00",
        'points_earned' => "DECIMAL(12,2) DEFAULT 0.00",
        'loyalty_points_awarded' => "TINYINT(1) DEFAULT 0"
    ];

    foreach ($columns as $column => $definition) {
        addColumnIfMissing($pdo, 'receipts', $column, $definition);
    }

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

    $redemptionColumns = [
        'tenant_id' => "INT(11) DEFAULT NULL",
        'customer_id' => "INT(11) DEFAULT NULL",
        'points_used' => "DECIMAL(12,2) DEFAULT 0.00",
        'discount_amount' => "DECIMAL(15,2) DEFAULT 0.00",
        'redemption_date' => "DATE DEFAULT NULL",
        'invoice_id' => "INT(11) DEFAULT NULL",
        'payment_id' => "INT(11) DEFAULT NULL",
        'status' => "VARCHAR(50) DEFAULT 'applied'",
        'created_at' => "DATETIME DEFAULT CURRENT_TIMESTAMP",
        'applied_to_payment_id' => "INT(11) DEFAULT NULL",
        'applied_at' => "DATETIME DEFAULT NULL",
        'receipt_id' => "INT(11) DEFAULT NULL",
        'created_by' => "INT(11) DEFAULT NULL"
    ];

    foreach ($redemptionColumns as $column => $definition) {
        addColumnIfMissing($pdo, 'point_redemptions', $column, $definition);
    }

    if (!columnExists($pdo, 'customers', 'loyalty_points')) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN loyalty_points DECIMAL(12,2) DEFAULT 0.00");
    }

    if (!columnExists($pdo, 'bank_accounts', 'is_default')) {
        $pdo->exec("ALTER TABLE bank_accounts ADD COLUMN is_default TINYINT(1) DEFAULT 0");
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

$tenant_info = getTenantInfo($pdo, $user_tenant_id);
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
    if (!$account_id || abs($delta) < 0.00001) {
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE bank_accounts
        SET current_balance = COALESCE(current_balance, 0) + ?
        WHERE id = ? AND tenant_id = ? AND is_active = 1
    ");
    $stmt->execute([round($delta, 2), $account_id, $tenant_id]);
}

function calculateLoyaltyPoints(float $amount, float $loyalty_rate): float {
    return round(($amount / 100) * $loyalty_rate, 2);
}

function generateReceiptNumber(PDO $pdo, int $tenant_id): string {
    $prefix = 'RCP';
    $stmt = $pdo->prepare("SELECT code FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$tenant_id]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tenant && !empty($tenant['code'])) {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $tenant['code'])) . 'RCP';
    }

    do {
        $receipt_number = $prefix . '-' . date('YmdHis') . '-' . random_int(1000, 9999);
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
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
    return $receipt ?: null;
}

function getReceiptTotals(PDO $pdo, int $tenant_id, string $search = '', int $customer_id = 0): array {
    $where = ["r.tenant_id = ?"];
    $params = [$tenant_id];

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

function awardReceiptPoints(PDO $pdo, int $receipt_id, int $tenant_id, float $loyalty_rate): array {
    $receipt = getReceipt($pdo, $receipt_id, $tenant_id);

    if (!$receipt) {
        return ['success' => false, 'points' => 0, 'message' => 'Rasiidka lama helin'];
    }

    if (empty($receipt['customer_id'])) {
        return ['success' => false, 'points' => 0, 'message' => 'Rasiidkan customer kuma xirna'];
    }

    if ((int)$receipt['loyalty_points_awarded'] === 1) {
        return ['success' => false, 'points' => (float)$receipt['points_earned'], 'message' => 'Points hore ayaa loo bixiyay'];
    }

    $paid_amount = (float)$receipt['amount'];
    $points = calculateLoyaltyPoints($paid_amount, $loyalty_rate);

    if ($paid_amount <= 0 || $points <= 0) {
        $pdo->prepare("UPDATE receipts SET points_earned = 0.00, loyalty_points_awarded = 1 WHERE id = ? AND tenant_id = ?")
            ->execute([$receipt_id, $tenant_id]);

        return ['success' => true, 'points' => 0.00, 'message' => 'Lacagtu 0 ayay ahayd, points lama dhalin'];
    }

    $check = $pdo->prepare("
        SELECT id FROM loyalty_points_log
        WHERE tenant_id = ? AND reference_type = 'receipt' AND reference_id = ?
        LIMIT 1
    ");
    $check->execute([$tenant_id, $receipt_id]);
    if ($check->fetch()) {
        $pdo->prepare("UPDATE receipts SET loyalty_points_awarded = 1 WHERE id = ? AND tenant_id = ?")
            ->execute([$receipt_id, $tenant_id]);
        return ['success' => false, 'points' => $points, 'message' => 'Points log hore ayuu u jiray'];
    }

    $pdo->prepare("
        UPDATE customers
        SET loyalty_points = COALESCE(loyalty_points, 0) + ?
        WHERE id = ? AND tenant_id = ?
    ")->execute([$points, $receipt['customer_id'], $tenant_id]);

    $reason = 'Receipt #' . $receipt['receipt_number'] . ' - $' . money2($paid_amount) .
              ' / 100 x ' . points2($loyalty_rate) . ' = ' . points2($points) . ' points';

    $pdo->prepare("
        INSERT INTO loyalty_points_log
        (tenant_id, customer_id, points_earned, points_redeemed, cbm_earned, amount_earned,
         reason, reference_type, reference_id, created_by, created_at)
        VALUES (?, ?, ?, 0, 0, ?, ?, 'receipt', ?, ?, NOW())
    ")->execute([
        $tenant_id,
        $receipt['customer_id'],
        $points,
        $paid_amount,
        $reason,
        $receipt_id,
        $receipt['created_by'] ?? 0
    ]);

    $pdo->prepare("
        UPDATE receipts
        SET points_earned = ?, loyalty_points_awarded = 1
        WHERE id = ? AND tenant_id = ?
    ")->execute([$points, $receipt_id, $tenant_id]);

    return ['success' => true, 'points' => $points, 'message' => points2($points) . ' dhibcood ayaa la bixiyay'];
}

function reverseReceiptPoints(PDO $pdo, int $receipt_id, int $tenant_id): void {
    $stmt = $pdo->prepare("
        SELECT r.customer_id, r.points_earned
        FROM receipts r
        WHERE r.id = ? AND r.tenant_id = ?
        LIMIT 1
    ");
    $stmt->execute([$receipt_id, $tenant_id]);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$receipt || empty($receipt['customer_id'])) {
        return;
    }

    $points = (float)$receipt['points_earned'];
    if ($points > 0) {
        $pdo->prepare("
            UPDATE customers
            SET loyalty_points = GREATEST(COALESCE(loyalty_points, 0) - ?, 0)
            WHERE id = ? AND tenant_id = ?
        ")->execute([$points, $receipt['customer_id'], $tenant_id]);
    }

    $pdo->prepare("
        DELETE FROM loyalty_points_log
        WHERE tenant_id = ? AND reference_type = 'receipt' AND reference_id = ?
    ")->execute([$tenant_id, $receipt_id]);

    $pdo->prepare("
        UPDATE receipts
        SET points_earned = 0.00, loyalty_points_awarded = 0
        WHERE id = ? AND tenant_id = ?
    ")->execute([$receipt_id, $tenant_id]);
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

function getExistingReceiptPointsUsed(PDO $pdo, int $tenant_id, ?int $receipt_id, int $customer_id): float {
    if (!$receipt_id) {
        return 0.00;
    }
    $stmt = $pdo->prepare("SELECT COALESCE(points_used, 0) AS points_used FROM receipts WHERE id = ? AND tenant_id = ? AND customer_id = ? LIMIT 1");
    $stmt->execute([$receipt_id, $tenant_id, $customer_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? round((float)$row['points_used'], 2) : 0.00;
}

function applyPointRedemption(PDO $pdo, int $tenant_id, int $user_id, int $receipt_id, array $data): void {
    $points_used = (float)($data['points_used'] ?? 0);
    $discount_amount = (float)($data['points_discount_amount'] ?? 0);

    if ($points_used <= 0 || $discount_amount <= 0) {
        return;
    }

    $pdo->prepare("
        UPDATE customers
        SET loyalty_points = GREATEST(COALESCE(loyalty_points, 0) - ?, 0)
        WHERE id = ? AND tenant_id = ?
    ")->execute([$points_used, $data['customer_id'], $tenant_id]);

    $pdo->prepare("
        INSERT INTO point_redemptions
        (tenant_id, customer_id, points_used, discount_amount, redemption_date, invoice_id, payment_id,
         status, created_at, applied_to_payment_id, applied_at, receipt_id, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'applied', NOW(), ?, NOW(), ?, ?)
    ")->execute([
        $tenant_id,
        $data['customer_id'],
        $points_used,
        $discount_amount,
        $data['payment_date'],
        $data['invoice_id'],
        $data['payment_id'],
        $data['payment_id'],
        $receipt_id,
        $user_id
    ]);
}

function reversePointRedemption(PDO $pdo, int $tenant_id, int $receipt_id): void {
    $stmt = $pdo->prepare("
        SELECT customer_id, COALESCE(points_used, 0) AS points_used
        FROM point_redemptions
        WHERE tenant_id = ? AND receipt_id = ? AND status IN ('applied', 'used', 'completed')
    ");
    $stmt->execute([$tenant_id, $receipt_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $points = (float)$row['points_used'];
        $customer_id = (int)$row['customer_id'];
        if ($points > 0 && $customer_id > 0) {
            $pdo->prepare("
                UPDATE customers
                SET loyalty_points = COALESCE(loyalty_points, 0) + ?
                WHERE id = ? AND tenant_id = ?
            ")->execute([$points, $customer_id, $tenant_id]);
        }
    }

    $pdo->prepare("DELETE FROM point_redemptions WHERE tenant_id = ? AND receipt_id = ?")->execute([$tenant_id, $receipt_id]);

    $pdo->prepare("
        UPDATE receipts
        SET points_used = 0.00, points_discount_amount = 0.00
        WHERE id = ? AND tenant_id = ?
    ")->execute([$receipt_id, $tenant_id]);
}

function validateReceiptInput(array $post, PDO $pdo, int $tenant_id, ?int $receipt_id, float $point_money_value): array {
    $customer_id = (int)($post['customer_id'] ?? 0);
    $invoice_id = !empty($post['invoice_id']) ? (int)$post['invoice_id'] : null;
    $payment_id = !empty($post['payment_id']) ? (int)$post['payment_id'] : null;

    $original_amount = (float)($post['original_amount'] ?? $post['amount'] ?? 0);
    $discount_applied = (float)($post['discount_applied'] ?? 0);
    $points_used = (float)($post['points_used'] ?? 0);

    if ($customer_id <= 0) {
        throw new Exception('Fadlan dooro customer');
    }

    if ($original_amount < 0) {
        throw new Exception('Qadarka asalka kama yaraan karo 0');
    }

    if ($discount_applied < 0) {
        throw new Exception('Discount kama yaraan karo 0');
    }

    if ($points_used < 0) {
        throw new Exception('Points Used kama yaraan karo 0');
    }

    $available_points = getCustomerAvailablePoints($pdo, $tenant_id, $customer_id);
    $old_points_used = getExistingReceiptPointsUsed($pdo, $tenant_id, $receipt_id, $customer_id);
    $max_points_allowed = round($available_points + $old_points_used, 2);

    if ($points_used > $max_points_allowed) {
        throw new Exception('Customer-kan wuxuu haystaa ' . points2($available_points) . ' points. Intaas ka badan lama isticmaali karo.');
    }

    $points_discount_amount = calculatePointsDiscount($points_used, $point_money_value);
    $total_discount = $discount_applied + $points_discount_amount;

    if ($total_discount > $original_amount) {
        throw new Exception('Discount + points discount kama badnaan karaan Original Amount');
    }

    $amount = max(0, $original_amount - $total_discount);

    $payment_method = trim($post['payment_method'] ?? 'cash');
    $reference_number = trim($post['reference_number'] ?? '');
    $bank_account_id = !empty($post['bank_account_id']) ? (int)$post['bank_account_id'] : null;
    $payment_date = !empty($post['payment_date']) ? $post['payment_date'] : date('Y-m-d');
    $notes = trim($post['notes'] ?? '');

    if (!$bank_account_id) {
        throw new Exception('Fadlan dooro Account-ka lacagta lagu keydinayo');
    }

    $checkAccount = $pdo->prepare("SELECT id FROM bank_accounts WHERE id = ? AND tenant_id = ? AND is_active = 1 LIMIT 1");
    $checkAccount->execute([$bank_account_id, $tenant_id]);
    if (!$checkAccount->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('Account-ka la doortay lama helin ama wuu xiran yahay');
    }

    return [
        'customer_id' => $customer_id,
        'invoice_id' => $invoice_id,
        'payment_id' => $payment_id,
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

function createReceipt(PDO $pdo, int $tenant_id, int $user_id, array $data, float $loyalty_rate, float $point_money_value): array {
    $pdo->beginTransaction();

    try {
        $receipt_number = generateReceiptNumber($pdo, $tenant_id);

        $stmt = $pdo->prepare("
            INSERT INTO receipts
            (tenant_id, receipt_number, invoice_id, payment_id, customer_id, amount,
             original_amount, discount_applied, points_used, points_discount_amount, points_earned, loyalty_points_awarded,
             payment_date, payment_method, reference_number, bank_account_id, notes, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 0, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $tenant_id,
            $receipt_number,
            $data['invoice_id'],
            $data['payment_id'],
            $data['customer_id'],
            $data['amount'],
            $data['original_amount'],
            $data['discount_applied'],
            $data['points_used'],
            $data['points_discount_amount'],
            $data['payment_date'],
            $data['payment_method'],
            $data['reference_number'],
            $data['bank_account_id'],
            $data['notes'],
            $user_id
        ]);

        $receipt_id = (int)$pdo->lastInsertId();

        updateBankAccountBalance($pdo, $tenant_id, $data['bank_account_id'], (float)$data['amount']);
        applyPointRedemption($pdo, $tenant_id, $user_id, $receipt_id, $data);
        $points = awardReceiptPoints($pdo, $receipt_id, $tenant_id, $loyalty_rate);
        syncInvoicePaidFromReceipts($pdo, $tenant_id, !empty($data['invoice_id']) ? (int)$data['invoice_id'] : null);

        $pdo->commit();

        return [
            'success' => true,
            'message' => 'Rasiidka waa la abuuray. Points: ' . points2($points['points']),
            'receipt_id' => $receipt_id,
            'receipt_number' => $receipt_number,
            'invoice_id' => $data['invoice_id'],
            'points_earned' => points2($points['points'])
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function updateReceipt(PDO $pdo, int $tenant_id, int $user_id, int $receipt_id, array $data, float $loyalty_rate, float $point_money_value): array {
    $pdo->beginTransaction();

    try {
        $existing = getReceipt($pdo, $receipt_id, $tenant_id);
        if (!$existing) {
            throw new Exception('Rasiidka lama helin');
        }
        $old_invoice_id = !empty($existing['invoice_id']) ? (int)$existing['invoice_id'] : null;

        reverseReceiptPoints($pdo, $receipt_id, $tenant_id);
        reversePointRedemption($pdo, $tenant_id, $receipt_id);
        updateBankAccountBalance($pdo, $tenant_id, !empty($existing['bank_account_id']) ? (int)$existing['bank_account_id'] : null, -1 * (float)$existing['amount']);

        $stmt = $pdo->prepare("
            UPDATE receipts
            SET invoice_id = ?, payment_id = ?, customer_id = ?, amount = ?,
                original_amount = ?, discount_applied = ?, points_used = ?, points_discount_amount = ?,
                payment_date = ?, payment_method = ?, reference_number = ?,
                bank_account_id = ?, notes = ?, updated_at = NOW()
            WHERE id = ? AND tenant_id = ?
        ");

        $stmt->execute([
            $data['invoice_id'],
            $data['payment_id'],
            $data['customer_id'],
            $data['amount'],
            $data['original_amount'],
            $data['discount_applied'],
            $data['points_used'],
            $data['points_discount_amount'],
            $data['payment_date'],
            $data['payment_method'],
            $data['reference_number'],
            $data['bank_account_id'],
            $data['notes'],
            $receipt_id,
            $tenant_id
        ]);

        updateBankAccountBalance($pdo, $tenant_id, $data['bank_account_id'], (float)$data['amount']);
        applyPointRedemption($pdo, $tenant_id, $user_id, $receipt_id, $data);
        $points = awardReceiptPoints($pdo, $receipt_id, $tenant_id, $loyalty_rate);
        syncInvoicePaidFromReceipts($pdo, $tenant_id, $old_invoice_id);
        syncInvoicePaidFromReceipts($pdo, $tenant_id, !empty($data['invoice_id']) ? (int)$data['invoice_id'] : null);

        $pdo->commit();

        return [
            'success' => true,
            'message' => 'Rasiidka waa la update gareeyay. Points cusub: ' . points2($points['points']),
            'receipt_id' => $receipt_id,
            'invoice_id' => $data['invoice_id'],
            'points_earned' => points2($points['points'])
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function deleteReceipt(PDO $pdo, int $tenant_id, int $receipt_id): array {
    $pdo->beginTransaction();

    try {
        $existing = getReceipt($pdo, $receipt_id, $tenant_id);
        if (!$existing) {
            throw new Exception('Rasiidka lama helin');
        }
        $old_invoice_id = !empty($existing['invoice_id']) ? (int)$existing['invoice_id'] : null;

        reverseReceiptPoints($pdo, $receipt_id, $tenant_id);
        reversePointRedemption($pdo, $tenant_id, $receipt_id);
        updateBankAccountBalance($pdo, $tenant_id, !empty($existing['bank_account_id']) ? (int)$existing['bank_account_id'] : null, -1 * (float)$existing['amount']);

        $stmt = $pdo->prepare("DELETE FROM receipts WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$receipt_id, $tenant_id]);
        syncInvoicePaidFromReceipts($pdo, $tenant_id, $old_invoice_id);

        $pdo->commit();

        return ['success' => true, 'message' => 'Rasiidka waa la tirtiray, points-kiina waa laga celiyay'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function renderReceiptHTML(PDO $pdo, int $receipt_id, int $tenant_id, float $loyalty_rate): string {
    $receipt = getReceipt($pdo, $receipt_id, $tenant_id);

    if (!$receipt) {
        return '<div class="alert alert-danger">Rasiidka lama helin</div>';
    }

    $amount = (float)$receipt['amount'];
    $expected_points = calculateLoyaltyPoints($amount, $loyalty_rate);

    ob_start();
    ?>
    <div class="receipt-print-area">
        <div class="receipt-header">
            <h3><?= h($GLOBALS['tenant_info']['name'] ?? 'Company') ?></h3>
            <p><?= h($GLOBALS['tenant_info']['address'] ?? '') ?> <?= h($GLOBALS['tenant_info']['phone'] ?? '') ?></p>
            <h4><i class="fas fa-receipt"></i> RASIIDKA BIXINTA</h4>
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
        <div class="receipt-row">
            <strong>Points Earned:</strong>
            <span class="text-primary">
                <?= points2($receipt['points_earned']) ?>
                <small>(<?= money2($amount) ?> / 100 × <?= points2($loyalty_rate) ?> = <?= points2($expected_points) ?>)</small>
            </span>
        </div>
        <div class="receipt-row"><strong>Current Customer Points:</strong><span><?= points2($receipt['loyalty_points'] ?? 0) ?></span></div>

        <hr>

        <div class="receipt-row"><strong>Payment Method:</strong><span><?= h(ucfirst(str_replace('_', ' ', $receipt['payment_method']))) ?></span></div>
        <div class="receipt-row"><strong>Reference:</strong><span><?= h($receipt['reference_number'] ?? '-') ?></span></div>
        <?php if (!empty($receipt['bank_name']) || !empty($receipt['account_name'])): ?>
        <div class="receipt-row"><strong>Bank Account:</strong><span><?= h($receipt['bank_name'] ?? '') ?> - <?= h($receipt['account_name'] ?? '') ?></span></div>
        <?php endif; ?>
        <?php if (!empty($receipt['notes'])): ?>
            <div class="receipt-notes"><strong>Notes:</strong><br><?= nl2br(h($receipt['notes'])) ?></div>
        <?php endif; ?>

        <div class="receipt-footer">Mahadsanid.</div>
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
            <div style="font-size: 14px; opacity: 0.9;"><i class="fas fa-dollar-sign"></i> Total Amount Received</div>
            <div style="font-size: 28px; font-weight: 800;">$' . money2($totals['total_amount_received']) . '</div>
        </div>
        <div class="total-card" style="background: linear-gradient(135deg, #F59E0B, #D97706); color: white; border-radius: 14px; padding: 15px 20px; text-align: center;">
            <div style="font-size: 14px; opacity: 0.9;"><i class="fas fa-star"></i> Total Points Earned</div>
            <div style="font-size: 28px; font-weight: 800;">' . points2($totals['total_points_earned']) . '</div>
        </div>
        <div class="total-card" style="background: linear-gradient(135deg, #EF4444, #DC2626); color: white; border-radius: 14px; padding: 15px 20px; text-align: center;">
            <div style="font-size: 14px; opacity: 0.9;"><i class="fas fa-coins"></i> Total Points Used</div>
            <div style="font-size: 28px; font-weight: 800;">' . points2($totals['total_points_used']) . '</div>
        </div>
    </div>';
}


// ============================================
// EXPORT / IMPORT + AUTOMATIC WHATSAPP + INVOICE SYNC HELPERS
// ============================================
require_once __DIR__ . '/../config/secrets.php';
if (!defined('GREEN_API_ID')) {
    define('GREEN_API_ID', getenv('GREEN_API_ID') ?: '');
}
if (!defined('GREEN_API_URL')) {
    define('GREEN_API_URL', getenv('GREEN_API_URL') ?: '');
}

function normalizeSomaliPhoneReceiptAuto($phone): string {
    $phone = preg_replace('/\D/', '', (string)$phone);
    if ($phone === '') return '';
    if (strlen($phone) === 9 && in_array($phone[0], ['6', '7'], true)) return '252' . $phone;
    if (strlen($phone) === 10 && $phone[0] === '0') return '252' . substr($phone, 1);
    if (strlen($phone) === 12 && substr($phone, 0, 3) === '252') return $phone;
    return '252' . ltrim($phone, '0');
}

function formatWhatsAppReceiptError($result): string {
    $raw = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE);
    if (stripos($raw, 'QUOTE_ALLOWED') !== false || stripos($raw, 'CORRESPONDENTS_QUOTE_EXCEEDED') !== false || stripos($raw, 'quota') !== false) {
        return 'WhatsApp quota wuu dhammaaday. Business plan u beddel ama number allowed ah isticmaal.';
    }
    if (stripos($raw, 'not authorized') !== false || stripos($raw, 'Unauthorized') !== false) {
        return 'WhatsApp lama dirin: GreenAPI login/QR lama authorize-gareyn.';
    }
    if (stripos($raw, 'curl') !== false || stripos($raw, 'timed out') !== false || stripos($raw, 'Could not resolve') !== false) {
        return 'WhatsApp lama dirin: internet/cURL server-ka hubi.';
    }
    if (is_array($result) && !empty($result['message'])) {
        $msg = trim((string)$result['message']);
        return mb_strlen($msg) > 90 ? mb_substr($msg, 0, 90) . '...' : $msg;
    }
    return 'WhatsApp lama dirin. GreenAPI hubi.';
}

function sendWhatsAppGreenAPIReceiptAuto($phone, string $message): array {
    $formattedPhone = normalizeSomaliPhoneReceiptAuto($phone);
    if ($formattedPhone === '') {
        return ['success' => false, 'message' => 'Telefoon sax ah lama helin'];
    }
    if (!function_exists('curl_init')) {
        return ['success' => false, 'message' => 'PHP cURL extension lama shidin.'];
    }
    if (GREEN_API_ID === '' || GREEN_API_TOKEN === '') {
        return ['success' => false, 'message' => 'GREEN_API_ID ama GREEN_API_TOKEN lama dejin'];
    }
    $payload = ['chatId' => $formattedPhone . '@c.us', 'message' => $message];
    $endpoints = ['sendMessage', 'SendMessage'];
    $lastResponse = null;
    foreach ($endpoints as $endpoint) {
        $url = rtrim(GREEN_API_URL, '/') . '/waInstance' . GREEN_API_ID . '/' . $endpoint . '/' . GREEN_API_TOKEN;
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
        $lastResponse = [
            'success' => false,
            'message' => $error ?: ($decoded['message'] ?? $response ?? 'WhatsApp API error'),
            'http_code' => $httpCode,
            'endpoint' => $endpoint,
            'chatId' => $payload['chatId'],
            'api_response' => $decoded ?: $response
        ];
        if ($httpCode === 200 && isset($decoded['idMessage'])) {
            return ['success' => true, 'message' => 'WhatsApp waa la diray', 'message_id' => $decoded['idMessage'], 'api_response' => $decoded];
        }
    }
    if ($lastResponse) {
        $lastResponse['raw_message'] = $lastResponse['message'] ?? '';
        $lastResponse['message'] = formatWhatsAppReceiptError($lastResponse);
        return $lastResponse;
    }
    return ['success' => false, 'message' => 'WhatsApp lama dirin. GreenAPI hubi.'];
}

function ensureReceiptWhatsappLogTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_receipt_logs (
        id INT(11) NOT NULL AUTO_INCREMENT,
        tenant_id INT(11) NOT NULL,
        receipt_id INT(11) DEFAULT NULL,
        invoice_id INT(11) DEFAULT NULL,
        customer_id INT(11) DEFAULT NULL,
        phone VARCHAR(50) DEFAULT NULL,
        message TEXT NOT NULL,
        send_status VARCHAR(30) DEFAULT 'pending',
        api_response TEXT DEFAULT NULL,
        reminder_type VARCHAR(50) DEFAULT 'receipt_auto',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_wr_tenant_receipt (tenant_id, receipt_id),
        KEY idx_wr_tenant_customer_date (tenant_id, customer_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function buildReceiptWhatsAppMessageAuto(array $receipt, array $tenantInfo, string $actionLabel = 'created'): string {
    $customerName = trim((string)($receipt['customer_name'] ?? 'Macmiil')) ?: 'Macmiil';
    $receiptNo = $receipt['receipt_number'] ?? '-';
    $invoiceNo = $receipt['invoice_number'] ?? '-';
    $finalPaid = '$' . money2($receipt['amount'] ?? 0);
    $pointsEarned = points2($receipt['points_earned'] ?? 0);
    $paymentDate = !empty($receipt['payment_date']) ? date('d/m/Y', strtotime($receipt['payment_date'])) : date('d/m/Y');
    $companyName = $tenantInfo['name'] ?? 'Company';

    $message  = "Rasiid Bixin\n";
    $message .= "Macmiil: {$customerName}\n";
    $message .= "Amount: {$finalPaid}\n";
    $message .= "Receipt: {$receiptNo}\n";
    if ($invoiceNo !== '-') {
        $message .= "Invoice: {$invoiceNo}\n";
    }
    if ((float)($receipt['points_used'] ?? 0) > 0) {
        $message .= "Points Used: " . points2($receipt['points_used']) . "\n";
    }
    if ((float)($receipt['points_discount_amount'] ?? 0) > 0) {
        $message .= "Points Discount: $" . money2($receipt['points_discount_amount']) . "\n";
    }
    $message .= "Points Earned: {$pointsEarned}\n";
    $message .= "Date: {$paymentDate}\n";
    $message .= $companyName;
    return $message;
}

function sendReceiptWhatsAppAuto(PDO $pdo, int $receipt_id, int $tenant_id, array $tenantInfo, string $type = 'receipt_created'): array {
    $receipt = getReceipt($pdo, $receipt_id, $tenant_id);
    if (!$receipt) return ['success' => false, 'message' => 'Rasiidka lama helin'];
    if (empty($receipt['customer_phone'])) return ['success' => false, 'message' => 'Telefoonka customer-ka lama helin'];
    $actionLabel = $type === 'receipt_updated' ? 'updated' : 'created';
    $message = buildReceiptWhatsAppMessageAuto($receipt, $tenantInfo, $actionLabel);
    $result = sendWhatsAppGreenAPIReceiptAuto($receipt['customer_phone'], $message);
    try {
        ensureReceiptWhatsappLogTable($pdo);
        $log = $pdo->prepare("INSERT INTO whatsapp_receipt_logs
            (tenant_id, receipt_id, invoice_id, customer_id, phone, message, send_status, api_response, reminder_type, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $log->execute([
            $tenant_id,
            $receipt_id,
            $receipt['invoice_id'] ?? null,
            $receipt['customer_id'] ?? null,
            $receipt['customer_phone'],
            $message,
            !empty($result['success']) ? 'sent' : 'failed',
            json_encode($result, JSON_UNESCAPED_UNICODE),
            $type
        ]);
    } catch (Throwable $e) {
        error_log('Receipt WhatsApp log error: ' . $e->getMessage());
    }
    return $result;
}

function receiptWhatsappText(array $waResult): string {
    return !empty($waResult['success']) ? ' WhatsApp waa la diray.' : ' ' . formatWhatsAppReceiptError($waResult);
}

function receiptInvoiceStatusForDb(array $invoice): string {
    $total = (float)($invoice['total_amount'] ?? 0);
    $paid = (float)($invoice['paid_amount'] ?? 0);
    $dueDate = trim((string)($invoice['due_date'] ?? ''));
    if ($total <= 0 || $paid >= $total) return 'paid';
    if ($dueDate !== '' && $dueDate !== '0000-00-00' && strtotime($dueDate) < strtotime(date('Y-m-d'))) return 'overdue';
    return 'sent';
}

function syncInvoicePaidFromReceipts(PDO $pdo, int $tenant_id, ?int $invoice_id): void {
    if (!$invoice_id) return;
    $stmt = $pdo->prepare("SELECT id, total_amount, due_date FROM invoices WHERE id = ? AND tenant_id = ? LIMIT 1");
    $stmt->execute([$invoice_id, $tenant_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) return;
    $sum = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM receipts WHERE invoice_id = ? AND tenant_id = ?");
    $sum->execute([$invoice_id, $tenant_id]);
    $paid = max(0, (float)$sum->fetchColumn());
    $paid = min($paid, (float)$invoice['total_amount']);
    $invoice['paid_amount'] = $paid;
    $status = receiptInvoiceStatusForDb($invoice);
    $upd = $pdo->prepare("UPDATE invoices SET paid_amount = ?, status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
    $upd->execute([$paid, $status, $invoice_id, $tenant_id]);
}

function exportReceiptsCSV(PDO $pdo, int $tenant_id, array $tenantInfo): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="receipts_export_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Company', $tenantInfo['name'] ?? 'Company']);
    fputcsv($out, ['Generated', date('Y-m-d H:i:s')]);
    fputcsv($out, []);
    fputcsv($out, ['receipt_number','invoice_number','customer_name','customer_phone','original_amount','discount_applied','points_used','points_discount_amount','amount','payment_date','payment_method','reference_number','bank_account','notes','points_earned']);
    $stmt = $pdo->prepare("SELECT r.*, c.customer_name, c.phone AS customer_phone, i.invoice_number, ba.account_name
        FROM receipts r
        LEFT JOIN customers c ON r.customer_id = c.id
        LEFT JOIN invoices i ON r.invoice_id = i.id
        LEFT JOIN bank_accounts ba ON r.bank_account_id = ba.id
        WHERE r.tenant_id = ?
        ORDER BY r.created_at DESC, r.id DESC");
    $stmt->execute([$tenant_id]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $r['receipt_number'] ?? '', $r['invoice_number'] ?? '', $r['customer_name'] ?? '', $r['customer_phone'] ?? '',
            money2($r['original_amount'] ?? 0), money2($r['discount_applied'] ?? 0), points2($r['points_used'] ?? 0),
            money2($r['points_discount_amount'] ?? 0), money2($r['amount'] ?? 0), $r['payment_date'] ?? '',
            $r['payment_method'] ?? 'cash', $r['reference_number'] ?? '', $r['account_name'] ?? '', $r['notes'] ?? '', points2($r['points_earned'] ?? 0)
        ]);
    }
    fclose($out);
    exit;
}

function downloadReceiptImportTemplate(): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="receipt_import_template.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['customer_phone','customer_name','invoice_number','original_amount','discount_applied','points_used','payment_date','payment_method','reference_number','bank_account_name','notes','send_whatsapp']);
    fputcsv($out, ['25261XXXXXXX','Ahmed Mohamed','INV-00001','100','0','0',date('Y-m-d'),'cash','REF-001','Cash Account','Imported receipt','yes']);
    fclose($out);
    exit;
}

function findReceiptImportCustomer(PDO $pdo, int $tenant_id, string $phone, string $name): int {
    $phone = trim($phone);
    $name = trim($name);
    if ($phone !== '') {
        $normalized = preg_replace('/\D/', '', $phone);
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE tenant_id = ? AND REPLACE(REPLACE(REPLACE(phone, "+", ""), " ", ""), "-", "") LIKE ? LIMIT 1');
        $stmt->execute([$tenant_id, '%' . $normalized . '%']);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;
    }
    if ($name !== '') {
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE tenant_id = ? AND customer_name = ? LIMIT 1');
        $stmt->execute([$tenant_id, $name]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;
    }
    if ($phone === '' || $name === '') throw new Exception('customer_phone iyo customer_name waa qasab haddii customer cusub la abuurayo');
    $ins = $pdo->prepare('INSERT INTO customers (tenant_id, customer_name, phone, created_at) VALUES (?, ?, ?, NOW())');
    $ins->execute([$tenant_id, $name, $phone]);
    return (int)$pdo->lastInsertId();
}

function findReceiptImportInvoice(PDO $pdo, int $tenant_id, int $customer_id, string $invoiceNumber): ?int {
    $invoiceNumber = trim($invoiceNumber);
    if ($invoiceNumber === '') return null;
    $stmt = $pdo->prepare('SELECT id FROM invoices WHERE tenant_id = ? AND customer_id = ? AND invoice_number = ? LIMIT 1');
    $stmt->execute([$tenant_id, $customer_id, $invoiceNumber]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function findReceiptImportBankAccount(PDO $pdo, int $tenant_id, string $accountName): int {
    $name = trim($accountName);
    if ($name !== '') {
        $stmt = $pdo->prepare('SELECT id FROM bank_accounts WHERE tenant_id = ? AND is_active = 1 AND account_name = ? LIMIT 1');
        $stmt->execute([$tenant_id, $name]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;
    }
    $stmt = $pdo->prepare('SELECT id FROM bank_accounts WHERE tenant_id = ? AND is_active = 1 ORDER BY is_default DESC, id ASC LIMIT 1');
    $stmt->execute([$tenant_id]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;
    throw new Exception('Bank account lama helin. Fadlan account samee marka hore.');
}

function importReceiptsFromCSV(PDO $pdo, int $tenant_id, int $user_id, array $file, float $loyalty_rate, float $point_money_value, array $tenantInfo): array {
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) throw new Exception('CSV file lama helin');
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) throw new Exception('CSV file lama furi karo');
    $header = fgetcsv($handle);
    if (!$header) throw new Exception('CSV header lama helin');
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $map = [];
    foreach ($header as $i => $col) $map[strtolower(trim((string)$col))] = $i;
    foreach (['customer_phone','customer_name','original_amount'] as $col) {
        if (!isset($map[$col])) throw new Exception("Column-ka '{$col}' waa qasab");
    }
    $get = function(array $row, string $key, string $default = '') use ($map) {
        $key = strtolower($key);
        return isset($map[$key]) ? trim((string)($row[$map[$key]] ?? $default)) : $default;
    };
    $summary = ['inserted' => 0, 'failed' => 0, 'whatsapp_sent' => 0, 'whatsapp_failed' => 0, 'errors' => []];
    $rowNo = 1;
    while (($row = fgetcsv($handle)) !== false) {
        $rowNo++;
        if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue;
        try {
            $customerId = findReceiptImportCustomer($pdo, $tenant_id, $get($row, 'customer_phone'), $get($row, 'customer_name'));
            $invoiceId = findReceiptImportInvoice($pdo, $tenant_id, $customerId, $get($row, 'invoice_number'));
            $bankId = findReceiptImportBankAccount($pdo, $tenant_id, $get($row, 'bank_account_name'));
            $data = validateReceiptInput([
                'customer_id' => $customerId,
                'invoice_id' => $invoiceId,
                'original_amount' => (float)str_replace(',', '.', $get($row, 'original_amount', '0')),
                'discount_applied' => (float)str_replace(',', '.', $get($row, 'discount_applied', '0')),
                'points_used' => (float)str_replace(',', '.', $get($row, 'points_used', '0')),
                'payment_date' => $get($row, 'payment_date', date('Y-m-d')) ?: date('Y-m-d'),
                'payment_method' => $get($row, 'payment_method', 'cash') ?: 'cash',
                'reference_number' => $get($row, 'reference_number'),
                'bank_account_id' => $bankId,
                'notes' => $get($row, 'notes')
            ], $pdo, $tenant_id, null, $point_money_value);
            $created = createReceipt($pdo, $tenant_id, $user_id, $data, $loyalty_rate, $point_money_value);
            $summary['inserted']++;
            $sendWhatsapp = strtolower($get($row, 'send_whatsapp', 'yes')) !== 'no';
            if ($sendWhatsapp) {
                $wa = sendReceiptWhatsAppAuto($pdo, (int)$created['receipt_id'], $tenant_id, $tenantInfo, 'receipt_import');
                if (!empty($wa['success'])) $summary['whatsapp_sent']++; else $summary['whatsapp_failed']++;
            }
        } catch (Throwable $e) {
            $summary['failed']++;
            $summary['errors'][] = 'Row ' . $rowNo . ': ' . $e->getMessage();
        }
    }
    fclose($handle);
    return $summary;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    $action = $_POST['ajax_action'];

    try {
        if ($action === 'import_receipts') {
            $summary = importReceiptsFromCSV($pdo, $user_tenant_id, $user_id, $_FILES['csv_file'] ?? [], $tenant_loyalty_rate, $tenant_point_money_value, $tenant_info);
            json_response([
                'success' => true,
                'message' => "Import complete: {$summary['inserted']} cusub, {$summary['failed']} failed. WhatsApp: {$summary['whatsapp_sent']} diray, {$summary['whatsapp_failed']} fashilmay.",
                'summary' => $summary
            ]);
        }

        if ($action === 'get_customers') {
            $q = trim($_POST['q'] ?? '');
            $selected_id = (int)($_POST['selected_id'] ?? 0);
            $where = "tenant_id = ? AND is_active = 1";
            $params = [$user_tenant_id];

            if ($selected_id > 0) {
                $where .= " AND id = ?";
                $params[] = $selected_id;
            } elseif ($q !== '') {
                $where .= " AND (customer_name LIKE ? OR phone LIKE ?)";
                $like = '%' . $q . '%';
                $params[] = $like;
                $params[] = $like;
            }

            $stmt = $pdo->prepare("
                SELECT id, customer_name, phone, COALESCE(loyalty_points, 0) AS loyalty_points
                FROM customers
                WHERE {$where}
                ORDER BY customer_name
                LIMIT 25
            ");
            $stmt->execute($params);
            json_response(['success' => true, 'customers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        if ($action === 'quick_add_customer') {
            $name = trim($_POST['customer_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');

            if ($name === '') {
                throw new Exception('Magaca customer-ka waa qasab');
            }

            if ($phone !== '') {
                $normalized = preg_replace('/\D/', '', $phone);
                $chk = $pdo->prepare("
                    SELECT id, customer_name, phone, COALESCE(loyalty_points, 0) AS loyalty_points
                    FROM customers
                    WHERE tenant_id = ?
                      AND REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '_', '') = ?
                    LIMIT 1
                ");
                $chk->execute([$user_tenant_id, $normalized]);
                $existing = $chk->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    json_response(['success' => true, 'message' => 'Customer-kan hore ayuu u jiray, waana la doortay.', 'customer' => $existing]);
                }
            }

            $ins = $pdo->prepare("
                INSERT INTO customers (tenant_id, customer_name, phone, email, address, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, 1, NOW())
            ");
            $ins->execute([$user_tenant_id, $name, $phone, $email, $address]);
            $id = (int)$pdo->lastInsertId();
            json_response([
                'success' => true,
                'message' => 'Customer cusub waa la abuuray',
                'customer' => ['id' => $id, 'customer_name' => $name, 'phone' => $phone, 'loyalty_points' => 0]
            ]);
        }

        if ($action === 'get_invoices') {
            $customer_id = (int)($_POST['customer_id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT id, invoice_number,
                       COALESCE(total_amount, 0) AS total_amount,
                       COALESCE(paid_amount, 0) AS paid_amount,
                       GREATEST(COALESCE(total_amount, 0) - COALESCE(paid_amount, 0), 0) AS due_amount
                FROM invoices
                WHERE tenant_id = ? AND customer_id = ? AND status NOT IN ('paid', 'cancelled')
                ORDER BY id DESC
            ");
            $stmt->execute([$user_tenant_id, $customer_id]);
            json_response(['success' => true, 'invoices' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        if ($action === 'get_bank_accounts') {
            json_response(['success' => true, 'accounts' => getBankAccounts($pdo, $user_tenant_id)]);
        }

        if ($action === 'create_bank_account') {
            $data = [
                'account_name' => trim($_POST['account_name'] ?? ''),
                'bank_name' => trim($_POST['bank_name'] ?? ''),
                'account_number' => trim($_POST['account_number'] ?? ''),
                'account_type' => $_POST['account_type'] ?? 'checking',
                'currency' => $_POST['currency'] ?? 'USD',
                'opening_balance' => (float)($_POST['opening_balance'] ?? 0),
                'is_default' => isset($_POST['is_default']) ? 1 : 0
            ];
            
            if (empty($data['account_name'])) {
                throw new Exception('Account name is required');
            }
            
            json_response(createBankAccount($pdo, $user_tenant_id, $user_id, $data));
        }

        if ($action === 'update_bank_account') {
            $account_id = (int)($_POST['account_id'] ?? 0);
            if ($account_id <= 0) {
                throw new Exception('Account ID required');
            }
            
            $data = [
                'account_name' => trim($_POST['account_name'] ?? ''),
                'bank_name' => trim($_POST['bank_name'] ?? ''),
                'account_number' => trim($_POST['account_number'] ?? ''),
                'account_type' => $_POST['account_type'] ?? 'checking',
                'currency' => $_POST['currency'] ?? 'USD',
                'is_default' => isset($_POST['is_default']) ? 1 : 0
            ];
            
            if (empty($data['account_name'])) {
                throw new Exception('Account name is required');
            }
            
            json_response(updateBankAccount($pdo, $user_tenant_id, $account_id, $data));
        }

        if ($action === 'delete_bank_account') {
            $account_id = (int)($_POST['account_id'] ?? 0);
            if ($account_id <= 0) {
                throw new Exception('Account ID required');
            }
            json_response(deleteBankAccount($pdo, $user_tenant_id, $account_id));
        }

        if ($action === 'create_receipt') {
            $data = validateReceiptInput($_POST, $pdo, $user_tenant_id, null, $tenant_point_money_value);
            $created = createReceipt($pdo, $user_tenant_id, $user_id, $data, $tenant_loyalty_rate, $tenant_point_money_value);
            $wa = sendReceiptWhatsAppAuto($pdo, (int)$created['receipt_id'], $user_tenant_id, $tenant_info, 'receipt_created');
            $created['whatsapp'] = $wa;
            $created['message'] .= receiptWhatsappText($wa);
            json_response($created);
        }

        if ($action === 'update_receipt') {
            $receipt_id = (int)($_POST['receipt_id'] ?? 0);
            if ($receipt_id <= 0) {
                throw new Exception('Receipt ID missing');
            }
            $data = validateReceiptInput($_POST, $pdo, $user_tenant_id, $receipt_id, $tenant_point_money_value);
            $updated = updateReceipt($pdo, $user_tenant_id, $user_id, $receipt_id, $data, $tenant_loyalty_rate, $tenant_point_money_value);
            $wa = sendReceiptWhatsAppAuto($pdo, (int)$updated['receipt_id'], $user_tenant_id, $tenant_info, 'receipt_updated');
            $updated['whatsapp'] = $wa;
            $updated['message'] .= receiptWhatsappText($wa);
            json_response($updated);
        }

        if ($action === 'delete_receipt') {
            $receipt_id = (int)($_POST['receipt_id'] ?? 0);
            if ($receipt_id <= 0) {
                throw new Exception('Receipt ID missing');
            }
            json_response(deleteReceipt($pdo, $user_tenant_id, $receipt_id));
        }

        if ($action === 'get_receipt_data') {
            $receipt_id = (int)($_POST['receipt_id'] ?? 0);
            $receipt = getReceipt($pdo, $receipt_id, $user_tenant_id);
            if (!$receipt) {
                throw new Exception('Rasiidka lama helin');
            }
            json_response(['success' => true, 'receipt' => $receipt]);
        }

        if ($action === 'view_receipt') {
            $receipt_id = (int)($_POST['receipt_id'] ?? 0);
            json_response([
                'success' => true,
                'html' => renderReceiptHTML($pdo, $receipt_id, $user_tenant_id, $tenant_loyalty_rate)
            ]);
        }

        if ($action === 'list_receipts') {
            $page = max(1, (int)($_POST['page'] ?? 1));
            $limit = 15;
            $offset = ($page - 1) * $limit;

            $search = trim($_POST['search'] ?? '');
            $customer_id = (int)($_POST['customer_id'] ?? 0);

            $where = ["r.tenant_id = ?"];
            $params = [$user_tenant_id];

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

            $count_stmt = $pdo->prepare("
                SELECT COUNT(*) AS total
                FROM receipts r
                LEFT JOIN customers c ON r.customer_id = c.id
                $where_clause
            ");
            $count_stmt->execute($params);
            $total = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
            $total_pages = max(1, (int)ceil($total / $limit));

            // Get totals for summary
            $totals = getReceiptTotals($pdo, $user_tenant_id, $search, $customer_id);

            $stmt = $pdo->prepare("
                SELECT r.*, c.customer_name, c.phone AS customer_phone, i.invoice_number,
                       u.full_name AS created_by_name,
                       ba.account_name, ba.bank_name
                FROM receipts r
                LEFT JOIN customers c ON r.customer_id = c.id
                LEFT JOIN invoices i ON r.invoice_id = i.id
                LEFT JOIN users u ON r.created_by = u.id
                LEFT JOIN bank_accounts ba ON r.bank_account_id = ba.id
                $where_clause
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT $limit OFFSET $offset
            ");
            $stmt->execute($params);
            $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            ob_start();
            ?>
            <!-- Totals Summary Section -->
            <?= renderTotalsHTML($totals) ?>

            <div class="table-responsive">
                <table class="table table-bordered table-hover receipt-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Receipt</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Original</th>
                            <th>Discount</th>
                            <th>Final Paid</th>
                            <th>Points Used</th>
                            <th>Points Discount</th>
                            <th>Points Earned</th>
                            <th>Method</th>
                            <th>Account</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($receipts): ?>
                        <?php foreach ($receipts as $r): ?>
                            <tr>
                                <td><?= (int)$r['id'] ?></td>
                                <td>
                                    <strong><?= h($r['receipt_number']) ?></strong>
                                    <?php if (!empty($r['invoice_number'])): ?>
                                        <br><small>Invoice: <?= h($r['invoice_number']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($r['payment_date'] ?: date('Y-m-d', strtotime($r['created_at']))) ?></td>
                                <td>
                                    <strong><?= h($r['customer_name'] ?? '-') ?></strong>
                                    <?php if (!empty($r['customer_phone'])): ?>
                                        <br><small><?= h($r['customer_phone']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right">$<?= money2($r['original_amount']) ?></td>
                                <td class="text-right text-success">-$<?= money2($r['discount_applied']) ?></td>
                                <td class="text-right"><strong>$<?= money2($r['amount']) ?></strong></td>
                                <td class="text-center"><?= points2($r['points_used']) ?></td>
                                <td class="text-right text-success">-$<?= money2($r['points_discount_amount'] ?? 0) ?></td>
                                <td class="text-center">
                                    <span class="badge badge-success"><?= points2($r['points_earned']) ?></span>
                                </td>
                                <td><?= h(ucfirst(str_replace('_', ' ', $r['payment_method'] ?? 'cash'))) ?></td>
                                <td class="text-center">
                                    <?php if ($r['payment_method'] === 'bank_transfer' && !empty($r['bank_name'])): ?>
                                        <small><?= h($r['bank_name']) ?></small>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="action-buttons">
                                    <button class="btn btn-sm btn-info view-receipt" data-id="<?= (int)$r['id'] ?>">View</button>
                                    <button class="btn btn-sm btn-warning edit-receipt" data-id="<?= (int)$r['id'] ?>">Edit</button>
                                    <button class="btn btn-sm btn-danger delete-receipt" data-id="<?= (int)$r['id'] ?>">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="13" class="text-center p-4">Rasiid lama hayo</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a data-page="<?= $page - 1 ?>" class="pagination-link"><i class="fas fa-chevron-left"></i> Hore</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="active-page"><?= $i ?></span>
                        <?php elseif ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                            <a data-page="<?= $i ?>" class="pagination-link"><?= $i ?></a>
                        <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                            <span class="pagination-dots">...</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a data-page="<?= $page + 1 ?>" class="pagination-link">Danbe <i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php

            json_response([
                'success' => true,
                'table_html' => ob_get_clean(),
                'total' => $total,
                'page' => $page,
                'total_pages' => $total_pages
            ]);
        }

        json_response(['success' => false, 'message' => 'Action lama yaqaan']);
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
    <title>Receipt Management - <?= h($tenant_info['name'] ?? '') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">

    <style>
        :root {
            --primary: #2D1859;
            --primary-light: #4B2C85;
            --yellow: #F5C410;
            --border: #e5e7eb;
            --gray: #6b7280;
        }

        body {
            background: #f4f5f8;
            font-family: "Segoe UI", Tahoma, sans-serif;
        }

        .page-wrap {
            padding: 20px;
        }

        .page-header-custom {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .page-header-custom h1 {
            font-size: 24px;
            margin: 0;
            font-weight: 700;
        }

        .page-header-custom h1 i {
            color: var(--primary);
        }

        .tenant-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f3f4f6;
            color: #374151;
            border-radius: 999px;
            padding: 8px 14px;
            font-weight: 600;
            font-size: 13px;
        }

        .btn-primary-custom {
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 999px;
            font-weight: 600;
        }

        .btn-primary-custom:hover {
            background: var(--primary-light);
            color: white;
        }

        .btn-outline-primary-custom {
            background: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
            padding: 8px 16px;
            border-radius: 999px;
            font-weight: 500;
        }

        .btn-outline-primary-custom:hover {
            background: var(--primary);
            color: white;
        }

        .filters-card,
        .table-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 20px;
        }

        .filter-form {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: end;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-group label {
            font-size: 13px;
            font-weight: 700;
            color: #374151;
        }

        .receipt-table th {
            background: #f9fafb;
            font-size: 13px;
            color: #374151;
            white-space: nowrap;
        }

        .receipt-table td {
            font-size: 13px;
            vertical-align: middle;
        }

        .action-buttons {
            white-space: nowrap;
        }

        .badge-success {
            background: #10b981;
            color: white;
            padding: 5px 9px;
            border-radius: 999px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 25px;
            padding: 10px;
        }

        .pagination-link,
        .active-page {
            min-width: 42px;
            height: 42px;
            padding: 0 14px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .pagination-link {
            background: #ffffff;
            color: #374151;
            border: 1px solid #d1d5db;
        }

        .pagination-link:hover {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(82, 0, 102, 0.20);
            text-decoration: none;
        }

        .active-page {
            background: var(--primary);
            color: #ffffff;
            border: 1px solid var(--primary);
            box-shadow: 0 4px 12px rgba(82, 0, 102, 0.25);
        }

        .pagination-dots {
            padding: 0 5px;
            font-weight: bold;
            color: #6b7280;
            font-size: 14px;
        }

        .receipt-print-area {
            max-width: 540px;
            margin: 0 auto;
            background: #fff;
            padding: 20px;
        }

        .receipt-header,
        .receipt-footer {
            text-align: center;
        }

        .receipt-header {
            border-bottom: 2px solid var(--primary);
            margin-bottom: 15px;
            padding-bottom: 15px;
        }

        .receipt-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 7px 0;
            border-bottom: 1px dashed #eee;
        }

        .receipt-total {
            color: #10b981;
            font-size: 18px;
            font-weight: 800;
        }

        .receipt-notes {
            background: #f9fafb;
            padding: 10px;
            border-radius: 8px;
            margin-top: 12px;
        }


        .customer-search-wrap { position: relative; }
        .customer-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 12px 30px rgba(17, 24, 39, 0.12);
            max-height: 260px;
            overflow-y: auto;
            z-index: 1055;
            display: none;
        }
        .customer-search-item {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
        }
        .customer-search-item:hover { background: #f8f5fb; }
        .customer-search-item strong { display: block; color: #111827; }
        .customer-search-item small { color: #6b7280; }
        .selected-customer-box {
            display: none;
            margin-top: 8px;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
            border-radius: 10px;
            padding: 8px 10px;
            font-size: 13px;
        }
        .compact-btn { border-radius: 9px; font-weight: 600; }

        @media (max-width: 768px) {
            .pagination-link,
            .active-page {
                min-width: 36px;
                height: 36px;
                font-size: 12px;
                padding: 0 10px;
            }
        }
    </style>
</head>
<body>

<div class="page-wrap">
    <div id="alert-placeholder"></div>

    <div class="page-header-custom">
        <div>
            <h1><i class="fas fa-receipt"></i> Maareynta Rasiidhada</h1>
            <small class="text-muted">Insert, update, delete + loyalty points + bank accounts + totals summary</small>
        </div>

        <div class="d-flex align-items-center flex-wrap" style="gap:8px;">
            <span class="tenant-badge"><i class="fas fa-building"></i> <?= h($tenant_info['name'] ?? '') ?></span>
            <span class="tenant-badge"><i class="fas fa-star"></i> <?= points2($tenant_loyalty_rate) ?> points / $100</span>
            <a class="btn-outline-primary-custom" href="?export=csv">Export CSV</a>
            <a class="btn-outline-primary-custom" href="?template=receipt_import">Template</a>
            <button class="btn-outline-primary-custom" id="importReceiptBtn">Import CSV</button>
            <button class="btn-primary-custom" id="addReceiptBtn">Rasiid Cusub</button>
        </div>
    </div>

    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group">
                <label>Raadin</label>
                <input type="text" id="searchInput" class="form-control" placeholder="Receipt no, customer, reference...">
            </div>
            <div class="filter-group customer-search-wrap">
                <label>Customer</label>
                <input type="hidden" id="customerFilter" value="0">
                <input type="text" id="customerFilterSearch" class="form-control" placeholder="Search customer name ama phone..." autocomplete="off">
                <div id="customerFilterResults" class="customer-search-results"></div>
            </div>
            <div>
                <button class="btn btn-secondary" id="resetBtn">Nadiifi</button>
                <button class="btn-primary-custom" id="refreshBtn">Refresh</button>
            </div>
        </div>
    </div>

    <div class="table-card" id="receiptsTableBox">
        <div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>
    </div>
</div>

<!-- Receipt Form Modal -->
<div class="modal fade" id="receiptFormModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="receiptForm">
            <div class="modal-header" style="background:#2D1859;color:#fff;">
                <h5 class="modal-title" id="receiptFormTitle">Rasiid Cusub</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="receipt_id" id="receipt_id">

                <div class="row">
                    <div class="col-md-6 form-group customer-search-wrap">
                        <label>Customer *</label>
                        <input type="hidden" name="customer_id" id="customer_id" required>
                        <div class="input-group">
                            <input type="text" id="customer_search" class="form-control" placeholder="Search name ama phone..." autocomplete="off">
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-primary compact-btn" id="addCustomerBtn">Add</button>
                            </div>
                        </div>
                        <div id="customerResults" class="customer-search-results"></div>
                        <div id="selectedCustomerInfo" class="selected-customer-box"></div>
                    </div>

                    <div class="col-md-6 form-group">
                        <label>Invoice</label>
                        <select name="invoice_id" id="invoice_id" class="form-control">
                            <option value="">-- Optional --</option>
                        </select>
                    </div>

                    <div class="col-md-4 form-group">
                        <label>Original Amount *</label>
                        <input type="number" step="0.01" min="0" name="original_amount" id="original_amount" class="form-control" required>
                    </div>

                    <div class="col-md-4 form-group">
                        <label>Discount Applied</label>
                        <input type="number" step="0.01" min="0" name="discount_applied" id="discount_applied" class="form-control" value="0.00">
                    </div>

                    <div class="col-md-4 form-group">
                        <label>Final Paid</label>
                        <input type="number" step="0.01" min="0" name="amount" id="amount" class="form-control" readonly>
                    </div>

                    <div class="col-md-4 form-group">
                        <label>Points Used</label>
                        <input type="number" step="0.01" min="0" name="points_used" id="points_used" class="form-control" value="0.00">
                        <small class="text-muted" id="available_points_text">Available: 0.00 pts</small>
                    </div>

                    <div class="col-md-4 form-group">
                        <label>Points Discount</label>
                        <input type="text" id="points_discount_amount" class="form-control" readonly value="0.00">
                        <small class="text-muted" id="points_value_text">100 points = $1</small>
                    </div>

                    <div class="col-md-4 form-group">
                        <label>Expected Points Earned</label>
                        <input type="text" id="expected_points" class="form-control" readonly>
                        <small class="text-muted" id="points_formula"></small>
                    </div>

                    <div class="col-md-4 form-group">
                        <label>Payment Date</label>
                        <input type="date" name="payment_date" id="payment_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="col-md-4 form-group">
                        <label>Payment Method</label>
                        <select name="payment_method" id="payment_method" class="form-control">
                            <option value="cash">Cash</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="check">Check</option>
                        </select>
                    </div>

                    <div class="col-md-4 form-group" id="bank_account_group">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="mb-0">Account-ka Lacagta Lagu Keydinayo *</label>
                            <a href="#" id="addNewAccountLink" class="btn btn-sm btn-outline-primary">
                                Bank Accounts
                            </a>
                        </div>
                        <select name="bank_account_id" id="bank_account_id" class="form-control" required>
                            <option value="">-- Select Account --</option>
                        </select>
                        <small class="text-muted">Rasiidka lacagtan waxaa lagu darayaa balance-ka account-kan.</small>
                    </div>

                    <div class="col-md-4 form-group">
                        <label>Reference Number</label>
                        <input type="text" name="reference_number" id="reference_number" class="form-control">
                    </div>

                    <div class="col-md-12 form-group">
                        <label>Notes</label>
                        <textarea name="notes" id="notes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Xidh</button>
                <button type="submit" class="btn-primary-custom">Kaydi & Points Bixi Automatic</button>
            </div>
        </form>
    </div>
</div>


<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" id="quickCustomerForm">
            <div class="modal-header" style="background:#2D1859;color:#fff;">
                <h5 class="modal-title">Add Customer</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Customer Name *</label>
                    <input type="text" name="customer_name" id="quick_customer_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="phone" id="quick_customer_phone" class="form-control">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control">
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <textarea name="address" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Xidh</button>
                <button type="submit" class="btn-primary-custom">Save Customer</button>
            </div>
        </form>
    </div>
</div>

<!-- Bank Account Management Modal -->
<div class="modal fade" id="bankAccountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:#2D1859;color:#fff;">
                <h5 class="modal-title"><i class="fas fa-university"></i> Bank Account Management</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div id="bankAccountsList">
                    <div class="text-center p-4"><i class="fas fa-spinner fa-spin"></i> Loading accounts...</div>
                </div>
                <hr>
                <h6>Add New Account</h6>
                <form id="newBankAccountForm">
                    <div class="form-group">
                        <label>Account Name *</label>
                        <input type="text" name="account_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Bank Name</label>
                        <input type="text" name="bank_name" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Account Number</label>
                        <input type="text" name="account_number" class="form-control">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Account Type</label>
                                <select name="account_type" class="form-control">
                                    <option value="checking">Checking</option>
                                    <option value="savings">Savings</option>
                                    <option value="business">Business</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Currency</label>
                                <select name="currency" class="form-control">
                                    <option value="USD">USD</option>
                                    <option value="SOS">SOS</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Opening Balance</label>
                        <input type="number" step="0.01" name="opening_balance" class="form-control" value="0.00">
                    </div>
                    <div class="form-group">
                        <div class="checkbox">
                            <label><input type="checkbox" name="is_default" value="1"> Set as default account</label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Add Account</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit Bank Account Modal -->
<div class="modal fade" id="editBankAccountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:#2D1859;color:#fff;">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Bank Account</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editBankAccountForm">
                    <input type="hidden" name="account_id" id="edit_account_id">
                    <div class="form-group">
                        <label>Account Name *</label>
                        <input type="text" name="account_name" id="edit_account_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Bank Name</label>
                        <input type="text" name="bank_name" id="edit_bank_name" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Account Number</label>
                        <input type="text" name="account_number" id="edit_account_number" class="form-control">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Account Type</label>
                                <select name="account_type" id="edit_account_type" class="form-control">
                                    <option value="checking">Checking</option>
                                    <option value="savings">Savings</option>
                                    <option value="business">Business</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Currency</label>
                                <select name="currency" id="edit_currency" class="form-control">
                                    <option value="USD">USD</option>
                                    <option value="SOS">SOS</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="checkbox">
                            <label><input type="checkbox" name="is_default" id="edit_is_default" value="1"> Set as default account</label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Update Account</button>
                    <button type="button" class="btn btn-danger btn-block mt-2" id="deleteAccountBtn">Delete Account</button>
                </form>
            </div>
        </div>
    </div>
</div>


<!-- Import Receipts Modal -->
<div class="modal fade" id="importReceiptModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" id="importReceiptForm" enctype="multipart/form-data">
            <div class="modal-header" style="background:#2D1859;color:#fff;">
                <h5 class="modal-title"><i class="fas fa-file-import"></i> Import Receipts CSV</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    CSV columns: customer_phone, customer_name, invoice_number, original_amount, discount_applied, points_used, payment_date, payment_method, reference_number, bank_account_name, notes, send_whatsapp.
                </div>
                <div class="form-group">
                    <label>CSV File *</label>
                    <input type="file" name="csv_file" id="csv_file" class="form-control" accept=".csv" required>
                </div>
                <a href="?template=receipt_import" class="btn btn-sm btn-outline-primary"><i class="fas fa-download"></i> Download Template</a>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Xidh</button>
                <button type="submit" class="btn-primary-custom"><i class="fas fa-upload"></i> Import</button>
            </div>
        </form>
    </div>
</div>

<!-- View Receipt Modal -->
<div class="modal fade" id="viewReceiptModal" tabindex="-1">
    <div class="modal-dialog modal-lg" style="max-width:650px;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Rasiidka</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="viewReceiptBody"></div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-dismiss="modal">Xidh</button>
                <button class="btn-primary-custom" id="printReceiptBtn"><i class="fas fa-print"></i> Print</button>
            </div>
        </div>
    </div>
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

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    return String(text).replace(/[&<>"']/g, function(m) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m];
    });
}

function fixed2(value) {
    const n = Number(value || 0);
    return n.toFixed(2);
}

function showAlert(type, message) {
    const cls = type === 'success' ? 'alert-success' : 'alert-danger';
    $('#alert-placeholder').html(`
        <div class="alert ${cls} alert-dismissible fade show" style="position:fixed;top:20px;right:20px;z-index:9999;min-width:320px;">
            ${message}
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    `);
    setTimeout(() => $('.alert').fadeOut(500, function(){ $(this).remove(); }), 4500);
}

function setSelectedCustomerPoints(extraPoints = 0) {
    const customerId = $('#customer_id').val();
    const customer = customersCache.find(c => String(c.id) === String(customerId));
    selectedCustomerPoints = customer ? Number(customer.loyalty_points || 0) + Number(extraPoints || 0) : 0;
    $('#points_used').attr('max', fixed2(selectedCustomerPoints));
    $('#available_points_text').text(`Available: ${fixed2(selectedCustomerPoints)} pts`);
    $('#points_value_text').text(`100 points = $1 | 1 point = $${fixed2(POINT_MONEY_VALUE)}`);
}

function calculateFormAmounts() {
    const original = Number($('#original_amount').val() || 0);
    const discount = Number($('#discount_applied').val() || 0);
    let pointsUsed = Number($('#points_used').val() || 0);

    if (pointsUsed > selectedCustomerPoints) {
        pointsUsed = selectedCustomerPoints;
        $('#points_used').val(fixed2(pointsUsed));
        showAlert('error', `Customer-kan wuxuu haystaa ${fixed2(selectedCustomerPoints)} points oo keliya.`);
    }

    const pointsDiscount = pointsUsed * POINT_MONEY_VALUE;
    const finalPaid = Math.max(0, original - discount - pointsDiscount);
    const points = (finalPaid / 100) * LOYALTY_RATE;

    $('#points_discount_amount').val(fixed2(pointsDiscount));
    $('#amount').val(fixed2(finalPaid));
    $('#expected_points').val(fixed2(points));
    $('#points_formula').text(`Points discount: ${fixed2(pointsUsed)} pts / 100 = $${fixed2(pointsDiscount)} | Earned: ${fixed2(finalPaid)} / 100 × ${fixed2(LOYALTY_RATE)} = ${fixed2(points)}`);
}

function loadBankAccounts(callback) {
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: {ajax_action: 'get_bank_accounts'},
        dataType: 'json',
        timeout: 10000
    }).done(function(res) {
        if (res && res.success) {
            bankAccountsCache = res.accounts || [];
            
            let options = '<option value="">-- Select Account --</option>';
            bankAccountsCache.forEach(acc => {
                const selected = acc.is_default ? 'selected' : '';
                options += `<option value="${acc.id}" ${selected}>${escapeHtml(acc.bank_name || '')} - ${escapeHtml(acc.account_name)} (${acc.currency})</option>`;
            });
            $('#bank_account_id').html(options);
            
            let listHtml = '<div class="list-group">';
            if (bankAccountsCache.length === 0) {
                listHtml += '<div class="alert alert-info">No bank accounts found. Add one below.</div>';
            } else {
                bankAccountsCache.forEach(acc => {
                    listHtml += `
                        <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <div>
                                <strong>${escapeHtml(acc.account_name)}</strong><br>
                                <small>${escapeHtml(acc.bank_name || '')} - ${escapeHtml(acc.account_number || 'N/A')}</small>
                                ${acc.is_default ? '<span class="badge badge-primary ml-2">Default</span>' : ''}
                            </div>
                            <div>
                                <small>Balance: ${acc.currency} ${fixed2(acc.current_balance)}</small>
                                <button class="btn btn-sm btn-outline-secondary ml-2 edit-bank-account" data-id="${acc.id}" data-name="${escapeHtml(acc.account_name)}" data-bank="${escapeHtml(acc.bank_name || '')}" data-number="${escapeHtml(acc.account_number || '')}" data-type="${acc.account_type}" data-currency="${acc.currency}" data-default="${acc.is_default}">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </div>
                        </div>
                    `;
                });
            }
            listHtml += '</div>';
            $('#bankAccountsList').html(listHtml);
            
            $('.edit-bank-account').off('click').on('click', function() {
                $('#edit_account_id').val($(this).data('id'));
                $('#edit_account_name').val($(this).data('name'));
                $('#edit_bank_name').val($(this).data('bank'));
                $('#edit_account_number').val($(this).data('number'));
                $('#edit_account_type').val($(this).data('type'));
                $('#edit_currency').val($(this).data('currency'));
                $('#edit_is_default').prop('checked', $(this).data('default') === 1 || $(this).data('default') === true);
                $('#editBankAccountModal').modal('show');
            });
        }
        if (callback) callback();
    }).fail(function() {
        $('#bankAccountsList').html('<div class="alert alert-danger">Failed to load accounts</div>');
        if (callback) callback();
    });
}

function renderCustomerResults(targetBox, customers, mode) {
    if (!customers || customers.length === 0) {
        targetBox.html('<div class="customer-search-item"><small>Customer lama helin. Riix Add si aad u abuurto.</small></div>').show();
        return;
    }
    let html = '';
    customers.forEach(c => {
        html += `
            <div class="customer-search-item" data-mode="${mode}" data-id="${c.id}" data-name="${escapeHtml(c.customer_name)}" data-phone="${escapeHtml(c.phone || '')}" data-points="${fixed2(c.loyalty_points || 0)}">
                <strong>${escapeHtml(c.customer_name)}</strong>
                <small>${escapeHtml(c.phone || 'Phone ma jiro')} | Points: ${fixed2(c.loyalty_points || 0)}</small>
            </div>`;
    });
    targetBox.html(html).show();
}

function searchCustomers(q, mode) {
    const box = mode === 'filter' ? $('#customerFilterResults') : $('#customerResults');
    if (!q || q.trim().length < 1) {
        box.hide().empty();
        return;
    }
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: {ajax_action: 'get_customers', q: q.trim()},
        dataType: 'json',
        timeout: 12000
    }).done(function(res) {
        if (res && res.success) {
            const rows = res.customers || [];
            rows.forEach(c => {
                if (!customersCache.find(x => String(x.id) === String(c.id))) customersCache.push(c);
            });
            renderCustomerResults(box, rows, mode);
        } else {
            box.html('<div class="customer-search-item"><small>Search failed</small></div>').show();
        }
    }).fail(function() {
        box.html('<div class="customer-search-item"><small>Server error</small></div>').show();
    });
}

function selectCustomerFromObject(c, mode, reloadInvoices = true) {
    if (!c) return;
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
    $('#selectedCustomerInfo').html(`<strong>${escapeHtml(c.customer_name)}</strong><br><small>${escapeHtml(c.phone || 'Phone ma jiro')} | Points: ${fixed2(c.loyalty_points || 0)}</small>`).show();
    setSelectedCustomerPoints(0);
    if (reloadInvoices) loadInvoices(c.id);
}

function loadCustomerById(id, callback) {
    if (!id) { if (callback) callback(null); return; }
    const cached = customersCache.find(c => String(c.id) === String(id));
    if (cached) { if (callback) callback(cached); return; }
    $.post(window.location.href, {ajax_action: 'get_customers', selected_id: id}, function(res) {
        const c = res.success && res.customers && res.customers.length ? res.customers[0] : null;
        if (c && !customersCache.find(x => String(x.id) === String(c.id))) customersCache.push(c);
        if (callback) callback(c);
    }, 'json').fail(function(){ if (callback) callback(null); });
}

function loadCustomers(callback) {
    customersCache = [];
    if (callback) callback();
}

function loadInvoices(customerId, selectedId = '', autoFillInvoice = false) {
    $('#invoice_id').html('<option value="">Loading...</option>');
    if (!customerId) {
        $('#invoice_id').html('<option value="">-- Optional --</option>');
        return;
    }

    $.post(window.location.href, {ajax_action: 'get_invoices', customer_id: customerId}, function(res) {
        let opts = '<option value="">-- Optional --</option>';
        if (res.success && res.invoices) {
            res.invoices.forEach(inv => {
                const selected = String(inv.id) === String(selectedId) ? 'selected' : '';
                opts += `<option value="${inv.id}" ${selected}
                    data-total="${fixed2(inv.total_amount)}"
                    data-paid="${fixed2(inv.paid_amount)}"
                    data-due="${fixed2(inv.due_amount)}">
                    ${escapeHtml(inv.invoice_number)} - Due $${fixed2(inv.due_amount)}
                </option>`;
            });
        }
        $('#invoice_id').html(opts);

        if (autoFillInvoice && selectedId) {
            fillAmountsFromSelectedInvoice(true);
        }
    }, 'json');
}

function fillAmountsFromSelectedInvoice(forceFill = false) {
    const option = $('#invoice_id option:selected');
    const invoiceId = $('#invoice_id').val();

    if (!invoiceId) {
        if (forceFill) {
            $('#original_amount').val('0.00');
            $('#discount_applied').val('0.00');
            calculateFormAmounts();
        }
        return;
    }

    const dueAmount = Number(option.data('due') || 0);
    const currentOriginal = Number($('#original_amount').val() || 0);

    if (forceFill || currentOriginal <= 0) {
        $('#original_amount').val(fixed2(dueAmount));
        $('#discount_applied').val('0.00');
    }

    calculateFormAmounts();
}

function loadReceipts() {
    $('#receiptsTableBox').html('<div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>');

    $.ajax({
        url: window.location.href,
        type: 'POST',
        dataType: 'json',
        timeout: 30000,
        data: {
            ajax_action: 'list_receipts',
            page: currentPage,
            search: $('#searchInput').val(),
            customer_id: $('#customerFilter').val()
        }
    }).done(function(res) {
        if (res && res.success) {
            $('#receiptsTableBox').html(res.table_html || '<div class="alert alert-warning">Xog lama helin</div>');
            bindTableEvents();
        } else {
            $('#receiptsTableBox').html(`<div class="alert alert-danger">${escapeHtml((res && res.message) ? res.message : 'Error loading receipts')}</div>`);
        }
    }).fail(function(xhr, status, error) {
        console.error('list_receipts failed:', status, error, xhr.responseText);
        let serverText = xhr.responseText || error || 'Unknown server error';
        $('#receiptsTableBox').html(
            '<div class="alert alert-danger">' +
            '<strong>Receipts lama soo bandhigi karo.</strong><br>' +
            '<small>Sababta server-ka:</small>' +
            '<pre style="white-space:pre-wrap;max-height:320px;overflow:auto;background:#fff;border:1px solid #ddd;padding:10px;margin-top:8px;">' +
            escapeHtml(serverText) +
            '</pre></div>'
        );
    });
}

function resetForm() {
    $('#receiptForm')[0].reset();
    $('#receipt_id').val('');
    $('#customer_id').val('');
    $('#customer_search').val('');
    $('#customerResults').hide().empty();
    $('#selectedCustomerInfo').hide().empty();
    $('#receiptFormTitle').text('Rasiid Cusub');
    $('#payment_date').val('<?= date('Y-m-d') ?>');
    $('#discount_applied').val('0.00');
    $('#points_used').val('0.00');
    $('#points_discount_amount').val('0.00');
    setSelectedCustomerPoints(0);
    $('#amount').val('0.00');
    $('#expected_points').val('0.00');
    $('#points_formula').text('');
    $('#invoice_id').html('<option value="">-- Optional --</option>');
    $('#bank_account_group').show();
    $('#bank_account_id').val('');
}

function bindTableEvents() {
    $('.pagination-link').off('click').on('click', function() {
        currentPage = Number($(this).data('page')) || 1;
        loadReceipts();
    });

    $('.view-receipt').off('click').on('click', function() {
        const id = $(this).data('id');
        $('#viewReceiptBody').html('<div class="text-center p-4"><i class="fas fa-spinner fa-spin"></i></div>');
        $('#viewReceiptModal').modal('show');

        $.post(window.location.href, {ajax_action: 'view_receipt', receipt_id: id}, function(res) {
            $('#viewReceiptBody').html(res.success ? res.html : `<div class="alert alert-danger">${escapeHtml(res.message)}</div>`);
        }, 'json');
    });

    $('.edit-receipt').off('click').on('click', function() {
        const id = $(this).data('id');

        $.post(window.location.href, {ajax_action: 'get_receipt_data', receipt_id: id}, function(res) {
            if (!res.success) {
                showAlert('error', res.message);
                return;
            }

            const r = res.receipt;
            resetForm();

            $('#receiptFormTitle').text('Update Rasiid');
            $('#receipt_id').val(r.id);
            loadCustomerById(r.customer_id, function(c) {
                if (c) selectCustomerFromObject(c, 'form', false);
                $('#customer_id').val(r.customer_id);
                setSelectedCustomerPoints(Number(r.points_used || 0));
                loadInvoices(r.customer_id, r.invoice_id, false);
            });

            $('#original_amount').val(fixed2(r.original_amount));
            $('#discount_applied').val(fixed2(r.discount_applied));
            $('#points_used').val(fixed2(r.points_used));
            $('#points_discount_amount').val(fixed2(r.points_discount_amount || 0));
            $('#payment_date').val(r.payment_date);
            $('#payment_method').val(r.payment_method);
            $('#reference_number').val(r.reference_number || '');
            $('#bank_account_id').val(r.bank_account_id || '');
            $('#notes').val(r.notes || '');
            $('#bank_account_group').show();
            calculateFormAmounts();

            $('#receiptFormModal').modal('show');
        }, 'json');
    });

    $('.delete-receipt').off('click').on('click', function() {
        const id = $(this).data('id');
        if (!confirm('Ma hubtaa inaad tirtireyso rasiidkan? Points-kiisa waa laga celin doonaa customer-ka.')) {
            return;
        }

        $.post(window.location.href, {ajax_action: 'delete_receipt', receipt_id: id}, function(res) {
            showAlert(res.success ? 'success' : 'error', res.message);
            if (res.success) loadReceipts();
        }, 'json');
    });
}

$(document).ready(function() {
    loadCustomers(loadReceipts);
    loadBankAccounts();

    $('#addReceiptBtn').on('click', function() {
        resetForm();
        $('#receiptFormModal').modal('show');
    });
    $('#addNewAccountLink').on('click', function(e) {
        e.preventDefault();
        $('#bankAccountModal').modal('show');
    });
    let customerSearchTimer = null;
    $('#customer_search').on('input', function() {
        clearTimeout(customerSearchTimer);
        const q = $(this).val();
        customerSearchTimer = setTimeout(() => searchCustomers(q, 'form'), 250);
    });

    $('#customerFilterSearch').on('input', function() {
        clearTimeout(customerSearchTimer);
        const q = $(this).val();
        if (q.trim() === '') {
            $('#customerFilter').val('0');
            currentPage = 1;
            loadReceipts();
        }
        customerSearchTimer = setTimeout(() => searchCustomers(q, 'filter'), 250);
    });

    $(document).on('click', '.customer-search-item', function() {
        const c = {
            id: $(this).data('id'),
            customer_name: $(this).data('name'),
            phone: $(this).data('phone'),
            loyalty_points: $(this).data('points')
        };
        selectCustomerFromObject(c, $(this).data('mode'));
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('.customer-search-wrap').length) {
            $('.customer-search-results').hide();
        }
    });

    $('#addCustomerBtn').on('click', function() {
        $('#quickCustomerForm')[0].reset();
        $('#quick_customer_name').val($('#customer_search').val());
        $('#addCustomerModal').modal('show');
    });

    $('#quickCustomerForm').on('submit', function(e) {
        e.preventDefault();
        const data = $(this).serializeArray();
        data.push({name: 'ajax_action', value: 'quick_add_customer'});
        $.post(window.location.href, data, function(res) {
            showAlert(res.success ? 'success' : 'error', res.message || 'Customer saved');
            if (res.success && res.customer) {
                $('#addCustomerModal').modal('hide');
                selectCustomerFromObject(res.customer, 'form');
            }
        }, 'json').fail(function(xhr) {
            showAlert('error', 'Server error: ' + escapeHtml(xhr.responseText || 'Unknown'));
        });
    });

    $('#invoice_id').on('change', function() {
        fillAmountsFromSelectedInvoice(true);
    });

    $('#original_amount, #discount_applied, #points_used').on('input', calculateFormAmounts);

    $('#importReceiptBtn').on('click', function() {
        $('#importReceiptModal').modal('show');
    });

    $('#importReceiptForm').on('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        fd.append('ajax_action', 'import_receipts');
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                showAlert(res.success ? 'success' : 'error', res.message);
                if (res.success) {
                    $('#importReceiptModal').modal('hide');
                    $('#importReceiptForm')[0].reset();
                    loadCustomers(loadReceipts);
                    loadBankAccounts();
                }
            },
            error: function(xhr) {
                console.error('import receipts failed:', xhr.responseText);
                showAlert('error', 'Server error: ' + escapeHtml(xhr.responseText || 'Unknown error'));
            }
        });
    });

    $('#receiptForm').on('submit', function(e) {
        e.preventDefault();

        const receiptId = $('#receipt_id').val();
        const action = receiptId ? 'update_receipt' : 'create_receipt';
        const data = $(this).serializeArray();
        data.push({name: 'ajax_action', value: action});

        $.post(window.location.href, data, function(res) {
            showAlert(res.success ? 'success' : 'error', res.message);
            if (res.success) {
                $('#receiptFormModal').modal('hide');
                loadCustomers(loadReceipts);
                loadBankAccounts();
            }
        }, 'json').fail(function(xhr) {
            console.error('save receipt failed:', xhr.responseText);
            showAlert('error', 'Server error: ' + escapeHtml(xhr.responseText || 'Unknown error'));
        });
    });
    
    $('#newBankAccountForm').on('submit', function(e) {
        e.preventDefault();
        const formData = $(this).serializeArray();
        formData.push({name: 'ajax_action', value: 'create_bank_account'});
        
        $.post(window.location.href, formData, function(res) {
            if (res.success) {
                showAlert('success', 'Bank account created successfully');
                $('#newBankAccountForm')[0].reset();
                loadBankAccounts(function() {
                    if (res.id) {
                        $('#bank_account_id').val(res.id);
                    }
                });
            } else {
                showAlert('error', res.message || 'Failed to create account');
            }
        }, 'json').fail(function(xhr) {
            showAlert('error', 'Server error: ' + escapeHtml(xhr.responseText || 'Unknown'));
        });
    });
    
    $('#editBankAccountForm').on('submit', function(e) {
        e.preventDefault();
        const formData = $(this).serializeArray();
        formData.push({name: 'ajax_action', value: 'update_bank_account'});
        
        $.post(window.location.href, formData, function(res) {
            if (res.success) {
                showAlert('success', 'Bank account updated successfully');
                $('#editBankAccountModal').modal('hide');
                loadBankAccounts();
            } else {
                showAlert('error', res.message || 'Failed to update account');
            }
        }, 'json');
    });
    
    $('#deleteAccountBtn').on('click', function() {
        const accountId = $('#edit_account_id').val();
        if (!confirm('Are you sure you want to delete this bank account?')) return;
        
        $.post(window.location.href, {
            ajax_action: 'delete_bank_account',
            account_id: accountId
        }, function(res) {
            if (res.success) {
                showAlert('success', 'Bank account deleted successfully');
                $('#editBankAccountModal').modal('hide');
                loadBankAccounts();
            } else {
                showAlert('error', res.message || 'Failed to delete account');
            }
        }, 'json');
    });

    $('#searchInput').on('keyup', function(e) {
        if (e.key === 'Enter') {
            currentPage = 1;
            loadReceipts();
        }
    });

    $('#refreshBtn').on('click', function() {
        currentPage = 1;
        loadReceipts();
    });

    $('#resetBtn').on('click', function() {
        currentPage = 1;
        $('#searchInput').val('');
        $('#customerFilter').val('0');
        $('#customerFilterSearch').val('');
        $('#customerFilterResults').hide().empty();
        loadReceipts();
    });

    $('#printReceiptBtn').on('click', function() {
        const html = $('#viewReceiptBody').html();
        const w = window.open('', '_blank', 'width=800,height=700');
        w.document.write(`
            <!doctype html>
            <html>
            <head>
                <title>Receipt</title>
                <style>
                    body{font-family:Arial,sans-serif;padding:20px}
                    .receipt-print-area{max-width:540px;margin:0 auto}
                    .receipt-header,.receipt-footer{text-align:center}
                    .receipt-header{border-bottom:2px solid #2D1859;margin-bottom:15px;padding-bottom:15px}
                    .receipt-row{display:flex;justify-content:space-between;gap:15px;padding:7px 0;border-bottom:1px dashed #eee}
                    .receipt-total{color:#10b981;font-size:18px;font-weight:800}
                    .receipt-notes{background:#f9fafb;padding:10px;border-radius:8px;margin-top:12px}
                </style>
            </head>
            <body>${html}<script>window.onload=function(){window.print();setTimeout(()=>window.close(),500)}<\/script></body>
            </html>
        `);
        w.document.close();
    });
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>