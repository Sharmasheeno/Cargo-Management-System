<?php
// tenant_admin/debts.php
// Customer Balance / Debt Management - Tenant Admin
// Shows every customer: debt, credit, or cleared balance.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'tenant_admin') {
    header('Location: ../login.php');
    exit;
}

$session_tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
if ($session_tenant_id <= 0) {
    header('Location: ../dashboard.php?error=no_tenant');
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

$messaging = null;
$messagingFile = __DIR__ . '/../includes/MessagingService.php';
if (file_exists($messagingFile)) {
    require_once $messagingFile;
    if (class_exists('MessagingService')) {
        $messaging = new MessagingService($pdo);
    }
}

function money(float $amount): string
{
    return '$' . number_format($amount, 2);
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function tableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return $cache[$table] = (bool)$stmt->fetch(PDO::FETCH_NUM);
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function getTenantName(PDO $pdo, int $tenantId): string
{
    try {
        $stmt = $pdo->prepare('SELECT name FROM tenants WHERE id = ? LIMIT 1');
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['name'] ?? 'My Company';
    } catch (Throwable $e) {
        return 'My Company';
    }
}

function getTenantPhone(PDO $pdo, int $tenantId): string
{
    try {
        if (columnExists($pdo, 'tenants', 'phone')) {
            $stmt = $pdo->prepare('SELECT phone FROM tenants WHERE id = ? LIMIT 1');
            $stmt->execute([$tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $phone = trim((string)($row['phone'] ?? ''));
            if ($phone !== '') {
                return $phone;
            }
        }

        if (columnExists($pdo, 'tenants', 'manager_phone')) {
            $stmt = $pdo->prepare('SELECT manager_phone FROM tenants WHERE id = ? LIMIT 1');
            $stmt->execute([$tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $phone = trim((string)($row['manager_phone'] ?? ''));
            if ($phone !== '') {
                return $phone;
            }
        }
    } catch (Throwable $e) {
        // fallback below
    }

    return '+25261XXXXXXX';
}

$tenant_name = getTenantName($pdo, $session_tenant_id);

$greenConfig = __DIR__ . '/../config/greenapi_config.php';
if (file_exists($greenConfig)) {
    require_once $greenConfig;
}

// ============================================
// AUTOMATIC WHATSAPP DEBT REMINDER HELPERS
// ============================================
$GREEN_API_ID = defined('GREEN_API_ID') ? GREEN_API_ID : (getenv('GREEN_API_ID') ?: '');
$GREEN_API_TOKEN = defined('GREEN_API_TOKEN') ? GREEN_API_TOKEN : (getenv('GREEN_API_TOKEN') ?: '');
$GREEN_API_URL = defined('GREEN_API_URL') ? GREEN_API_URL : (getenv('GREEN_API_URL') ?: '');

function normalizeSomaliPhoneForDebt($phone): string
{
    $phone = preg_replace('/\D/', '', (string)$phone);
    if ($phone === '') return '';
    if (strlen($phone) === 9 && in_array($phone[0], ['6', '7'], true)) return '252' . $phone;
    if (strlen($phone) === 10 && $phone[0] === '0') return '252' . substr($phone, 1);
    if (strlen($phone) === 12 && substr($phone, 0, 3) === '252') return $phone;
    return '252' . ltrim($phone, '0');
}

function sendWhatsAppGreenAPIDebt($phone, $message): array
{
    global $GREEN_API_ID, $GREEN_API_TOKEN, $GREEN_API_URL;
    $formattedPhone = normalizeSomaliPhoneForDebt($phone);
    if ($formattedPhone === '') {
        return ['success' => false, 'message' => 'Telefoon sax ah lama helin'];
    }

    $url = rtrim($GREEN_API_URL, '/') . "/waInstance{$GREEN_API_ID}/sendMessage/{$GREEN_API_TOKEN}";
    $payload = [
        'chatId' => $formattedPhone . '@c.us',
        'message' => $message
    ];

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
        return ['success' => true, 'message_id' => $decoded['idMessage'], 'api_response' => $decoded];
    }
    return ['success' => false, 'message' => $error ?: ($decoded['message'] ?? $response ?? 'WhatsApp API error')];
}

function ensureDebtWhatsappLogTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_debt_logs (
        id INT(11) NOT NULL AUTO_INCREMENT,
        tenant_id INT(11) NOT NULL,
        customer_id INT(11) NOT NULL,
        phone VARCHAR(50) NOT NULL,
        debt_amount DECIMAL(15,2) DEFAULT 0.00,
        message TEXT NOT NULL,
        send_status VARCHAR(20) DEFAULT 'pending',
        api_response TEXT DEFAULT NULL,
        reminder_type VARCHAR(50) DEFAULT 'manual',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_tenant_customer_date (tenant_id, customer_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function wasDebtReminderSentRecently(PDO $pdo, int $tenantId, int $customerId, string $type = 'auto_daily'): bool
{
    ensureDebtWhatsappLogTable($pdo);
    $stmt = $pdo->prepare("SELECT id FROM whatsapp_debt_logs
        WHERE tenant_id = ? AND customer_id = ? AND reminder_type = ? AND send_status = 'sent'
          AND DATE(created_at) = CURDATE()
        LIMIT 1");
    $stmt->execute([$tenantId, $customerId, $type]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function buildSomaliDebtReminderMessage(array $customer, array $balance, string $tenantName, string $companyPhone, ?array $oldestInvoice = null): string
{
    $customerName = trim((string)($customer['customer_name'] ?? 'Macmiil'));
    if ($customerName === '') {
        $customerName = 'Macmiil';
    }

    $debtAmount = '$' . number_format((float)($balance['debt_amount'] ?? 0), 2);

    $message  = "Salaan Macmiil {$customerName}\n\n";
    $message .= "Waxaan si xushmad leh kuugu xusuusinaynaa in akoonkaaga uu leeyahay haraag dhan:\n\n";
    $message .= "{$debtAmount}\n\n";

    if ($oldestInvoice) {
        $invoiceNo = trim((string)($oldestInvoice['invoice_number'] ?? ''));
        if ($invoiceNo !== '') {
            $message .= "Invoice: {$invoiceNo}\n";
        }
    }

    $message .= "\nFadlan dhammeystir lacag bixinta.\n\n";
    $message .= "Mahadsanid.\n";
    $message .= "{$tenantName}";

    if (trim($companyPhone) !== '') {
        $message .= "\n{$companyPhone}";
    }

    return $message;
}

function sendDebtReminderWhatsApp(PDO $pdo, int $tenantId, int $customerId, string $type = 'manual'): array
{
    $stmt = $pdo->prepare('SELECT id, customer_name, phone FROM customers WHERE id = ? AND tenant_id = ? LIMIT 1');
    $stmt->execute([$customerId, $tenantId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer || empty($customer['phone'])) {
        return ['success' => false, 'message' => 'Telefoonka macaamiilka lama helin'];
    }

    $balance = getCustomerBalanceData($pdo, $customerId, $tenantId);
    if (($balance['debt_amount'] ?? 0) <= 0.009) {
        return ['success' => false, 'message' => 'Macaamiilkan deyn ma laha'];
    }

    if ($type === 'auto_daily' && wasDebtReminderSentRecently($pdo, $tenantId, $customerId, $type)) {
        return ['success' => true, 'message' => 'Fariin maanta horay ayaa loogu diray', 'skipped' => true];
    }

    $tenantName = getTenantName($pdo, $tenantId);
    $oldestInvoice = getOldestOpenInvoice($pdo, $customerId, $tenantId);
    $companyPhone = getTenantPhone($pdo, $tenantId);
    $message = buildSomaliDebtReminderMessage($customer, $balance, $tenantName, $companyPhone, $oldestInvoice);
    $result = sendWhatsAppGreenAPIDebt($customer['phone'], $message);

    try {
        ensureDebtWhatsappLogTable($pdo);
        $log = $pdo->prepare("INSERT INTO whatsapp_debt_logs
            (tenant_id, customer_id, phone, debt_amount, message, send_status, api_response, reminder_type, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $log->execute([
            $tenantId,
            $customerId,
            $customer['phone'],
            (float)$balance['debt_amount'],
            $message,
            !empty($result['success']) && empty($result['skipped']) ? 'sent' : (!empty($result['skipped']) ? 'skipped' : 'failed'),
            json_encode($result, JSON_UNESCAPED_UNICODE),
            $type
        ]);
    } catch (Throwable $e) {
        error_log('Debt WhatsApp log error: ' . $e->getMessage());
    }

    return $result;
}

function sendAutomaticDebtReminders(PDO $pdo, int $tenantId, float $minimumDebt = 1.00): array
{
    $summary = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    if (!tableExists($pdo, 'customers')) return $summary;

    $stmt = $pdo->prepare("SELECT id FROM customers WHERE tenant_id = ? AND COALESCE(phone,'') <> '' AND COALESCE(is_active,1) = 1");
    $stmt->execute([$tenantId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $customerId = (int)$row['id'];
        $balance = getCustomerBalanceData($pdo, $customerId, $tenantId);
        if ((float)$balance['debt_amount'] < $minimumDebt) continue;

        $result = sendDebtReminderWhatsApp($pdo, $tenantId, $customerId, 'auto_daily');
        if (!empty($result['skipped'])) $summary['skipped']++;
        elseif (!empty($result['success'])) $summary['sent']++;
        else {
            $summary['failed']++;
            $summary['errors'][] = $result['message'] ?? 'WhatsApp failed';
        }
    }
    return $summary;
}


function getInvoiceTotalPaid(PDO $pdo, int $invoiceId, int $tenantId): float
{
    $totalPaid = 0.0;

    if (tableExists($pdo, 'receipts')) {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) AS total_paid FROM receipts WHERE invoice_id = ? AND tenant_id = ?');
        $stmt->execute([$invoiceId, $tenantId]);
        $totalPaid += (float)($stmt->fetch(PDO::FETCH_ASSOC)['total_paid'] ?? 0);
    }

    // Payments-ka ku xiran receipt lama laba xisaabinayo.
    if (tableExists($pdo, 'payments') && columnExists($pdo, 'payments', 'invoice_id')) {
        $where = 'p.invoice_id = ? AND p.tenant_id = ?';
        if (columnExists($pdo, 'payments', 'is_active')) {
            $where .= ' AND p.is_active = 1';
        }
        if (tableExists($pdo, 'receipts') && columnExists($pdo, 'receipts', 'payment_id')) {
            $where .= ' AND NOT EXISTS (SELECT 1 FROM receipts r WHERE r.tenant_id = p.tenant_id AND r.payment_id = p.id)';
        }
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(p.amount),0) AS total_paid FROM payments p WHERE {$where}");
        $stmt->execute([$invoiceId, $tenantId]);
        $totalPaid += (float)($stmt->fetch(PDO::FETCH_ASSOC)['total_paid'] ?? 0);
    }

    return round($totalPaid, 2);
}

function updateSingleInvoicePaidAmount(PDO $pdo, int $invoiceId, int $tenantId): float
{
    if (!tableExists($pdo, 'invoices')) return 0.0;

    $totalPaid = getInvoiceTotalPaid($pdo, $invoiceId, $tenantId);
    $stmt = $pdo->prepare('SELECT total_amount FROM invoices WHERE id = ? AND tenant_id = ? LIMIT 1');
    $stmt->execute([$invoiceId, $tenantId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) return 0.0;

    $totalAmount = (float)$invoice['total_amount'];
    $paidForInvoice = min($totalPaid, max(0, $totalAmount));

    $status = 'unpaid';
    if ($totalAmount <= 0 || $paidForInvoice >= $totalAmount) $status = 'paid';
    elseif ($paidForInvoice > 0) $status = 'partial';

    if (columnExists($pdo, 'invoices', 'paid_amount') && columnExists($pdo, 'invoices', 'status')) {
        $stmt = $pdo->prepare('UPDATE invoices SET paid_amount = ?, status = ? WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$paidForInvoice, $status, $invoiceId, $tenantId]);
    }
    return $paidForInvoice;
}

function getCustomerBalanceData(PDO $pdo, int $customerId, int $tenantId): array
{
    $totalInvoiced = 0.0;
    $totalReceipts = 0.0;
    $totalPayments = 0.0;

    // 1) Total biilasha: invoices aan cancelled ahayn.
    if (tableExists($pdo, 'invoices')) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) AS total FROM invoices WHERE customer_id = ? AND tenant_id = ? AND COALESCE(status,'') != 'cancelled'");
        $stmt->execute([$customerId, $tenantId]);
        $totalInvoiced = (float)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    }

    // 2) Receipts waa lacag rasmi ah oo la qabtay.
    if (tableExists($pdo, 'receipts')) {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) AS total FROM receipts WHERE customer_id = ? AND tenant_id = ?');
        $stmt->execute([$customerId, $tenantId]);
        $totalReceipts = (float)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    }

    // 3) Payments waxaa lagu daraa oo keliya haddii aan receipt hore ugu xirnayn.
    // Tani waxay joojinaysaa double-count: receipts + payments isku lacag ah.
    if (tableExists($pdo, 'payments')) {
        $where = 'p.customer_id = ? AND p.tenant_id = ?';
        if (columnExists($pdo, 'payments', 'is_active')) {
            $where .= ' AND p.is_active = 1';
        }
        if (tableExists($pdo, 'receipts') && columnExists($pdo, 'receipts', 'payment_id')) {
            $where .= ' AND NOT EXISTS (SELECT 1 FROM receipts r WHERE r.tenant_id = p.tenant_id AND r.payment_id = p.id)';
        }

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(p.amount),0) AS total FROM payments p WHERE {$where}");
        $stmt->execute([$customerId, $tenantId]);
        $totalPayments = (float)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    }

    $totalPaid = round($totalReceipts + $totalPayments, 2);
    $netBalance = round($totalInvoiced - $totalPaid, 2);

    return [
        'total_invoiced' => round($totalInvoiced, 2),
        'total_receipts' => round($totalReceipts, 2),
        'total_payments' => round($totalPayments, 2),
        'total_paid' => $totalPaid,
        'net_balance' => $netBalance,
        'debt_amount' => max(0, $netBalance),
        'credit_amount' => max(0, -$netBalance),
        'status_label' => $netBalance > 0.009 ? 'Deyn lagu leeyahay' : ($netBalance < -0.009 ? 'Lacag u taalla' : 'Nadiif'),
        'status_class' => $netBalance > 0.009 ? 'danger' : ($netBalance < -0.009 ? 'info' : 'success')
    ];
}

function updateCustomerStoredBalance(PDO $pdo, int $customerId, int $tenantId): array
{
    $data = getCustomerBalanceData($pdo, $customerId, $tenantId);

    $sets = [];
    $params = [];
    if (columnExists($pdo, 'customers', 'debt_amount')) {
        $sets[] = 'debt_amount = ?';
        $params[] = $data['debt_amount'];
    }
    if (columnExists($pdo, 'customers', 'credit_balance')) {
        $sets[] = 'credit_balance = ?';
        $params[] = $data['credit_amount'];
    }
    if (columnExists($pdo, 'customers', 'updated_at')) {
        $sets[] = 'updated_at = NOW()';
    }

    if ($sets) {
        $params[] = $customerId;
        $params[] = $tenantId;
        $stmt = $pdo->prepare('UPDATE customers SET ' . implode(', ', $sets) . ' WHERE id = ? AND tenant_id = ?');
        $stmt->execute($params);
    }

    return $data;
}

function recalculateAllCustomerBalances(PDO $pdo, int $tenantId): array
{
    $result = ['invoices_updated' => 0, 'customers_updated' => 0, 'errors' => []];

    try {
        if (tableExists($pdo, 'invoices')) {
            $stmt = $pdo->prepare("SELECT id FROM invoices WHERE tenant_id = ? AND COALESCE(status,'') != 'cancelled'");
            $stmt->execute([$tenantId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                try {
                    updateSingleInvoicePaidAmount($pdo, (int)$row['id'], $tenantId);
                    $result['invoices_updated']++;
                } catch (Throwable $e) {
                    $result['errors'][] = 'Invoice ' . $row['id'] . ': ' . $e->getMessage();
                }
            }
        }

        $stmt = $pdo->prepare('SELECT id FROM customers WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            try {
                updateCustomerStoredBalance($pdo, (int)$row['id'], $tenantId);
                $result['customers_updated']++;
            } catch (Throwable $e) {
                $result['errors'][] = 'Customer ' . $row['id'] . ': ' . $e->getMessage();
            }
        }
    } catch (Throwable $e) {
        $result['errors'][] = $e->getMessage();
    }

    return $result;
}

function getOldestOpenInvoice(PDO $pdo, int $customerId, int $tenantId): ?array
{
    if (!tableExists($pdo, 'invoices')) return null;
    $stmt = $pdo->prepare("
        SELECT id, invoice_number, invoice_date, due_date, total_amount, COALESCE(paid_amount,0) AS paid_amount
        FROM invoices
        WHERE customer_id = ? AND tenant_id = ? AND COALESCE(status,'') != 'cancelled'
          AND (total_amount - COALESCE(paid_amount,0)) > 0.009
        ORDER BY COALESCE(due_date, invoice_date) ASC
        LIMIT 1
    ");
    $stmt->execute([$customerId, $tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}


function ensureDebtReceiptSchema(PDO $pdo): void
{
    if (!tableExists($pdo, 'receipts')) {
        $pdo->exec("CREATE TABLE receipts (
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
            notes TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_by INT(11) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_receipts_tenant_customer (tenant_id, customer_id),
            KEY idx_receipts_invoice (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $cols = [
        'receipt_number' => "VARCHAR(100) NOT NULL DEFAULT ''",
        'invoice_id' => "INT(11) DEFAULT NULL",
        'payment_id' => "INT(11) DEFAULT NULL",
        'customer_id' => "INT(11) DEFAULT NULL",
        'amount' => "DECIMAL(15,2) NOT NULL DEFAULT 0.00",
        'payment_date' => "DATE DEFAULT NULL",
        'payment_method' => "VARCHAR(50) DEFAULT 'cash'",
        'reference_number' => "VARCHAR(150) DEFAULT NULL",
        'notes' => "TEXT DEFAULT NULL",
        'created_at' => "DATETIME DEFAULT CURRENT_TIMESTAMP",
        'created_by' => "INT(11) DEFAULT NULL"
    ];
    foreach ($cols as $col => $def) {
        if (!columnExists($pdo, 'receipts', $col)) {
            try { $pdo->exec("ALTER TABLE receipts ADD COLUMN `$col` $def"); } catch (Throwable $e) {}
        }
    }
}

function generateDebtReceiptNumber(PDO $pdo, int $tenantId): string
{
    do {
        $no = 'DR-' . date('YmdHis') . '-' . random_int(100, 999);
        $stmt = $pdo->prepare('SELECT id FROM receipts WHERE tenant_id = ? AND receipt_number = ? LIMIT 1');
        $stmt->execute([$tenantId, $no]);
    } while ($stmt->fetch(PDO::FETCH_ASSOC));
    return $no;
}

function buildPaymentReceivedMessage(array $customer, float $amount, string $receiptNo, string $tenantName, string $companyPhone): string
{
    $customerName = trim((string)($customer['customer_name'] ?? 'Macaamiil')) ?: 'Macaamiil';
    $dateNow = date('d/m/Y');
    $message  = "Macmiil {$customerName},\n\n";
    $message .= "Lacag bixintaada waa la diiwaangeliyay.\n";
    $message .= "Qaddar: $" . number_format($amount, 2) . "\n";
    $message .= "Rasiid: {$receiptNo}\n";
    $message .= "Taariikh: {$dateNow}\n";
    if (trim($companyPhone) !== '') $message .= "Xiriir: {$companyPhone}\n";
    $message .= "\nMahadsanid,\n{$tenantName}";
    return $message;
}

function recordDebtPayment(PDO $pdo, int $tenantId, int $userId, array $data): array
{
    ensureDebtReceiptSchema($pdo);

    $customerId = (int)($data['customer_id'] ?? 0);
    $amount = round((float)($data['amount'] ?? 0), 2);
    $invoiceId = !empty($data['invoice_id']) ? (int)$data['invoice_id'] : null;
    $method = trim((string)($data['payment_method'] ?? 'cash')) ?: 'cash';
    $reference = trim((string)($data['reference_number'] ?? ''));
    $notes = trim((string)($data['notes'] ?? ''));
    $paymentDate = trim((string)($data['payment_date'] ?? date('Y-m-d'))) ?: date('Y-m-d');

    if ($customerId <= 0) throw new Exception('Macmiil sax ah lama helin');
    if ($amount <= 0) throw new Exception('Qaddarka lacag bixinta waa inuu ka weyn yahay 0');

    $stmt = $pdo->prepare('SELECT id, customer_name, phone FROM customers WHERE id = ? AND tenant_id = ? LIMIT 1');
    $stmt->execute([$customerId, $tenantId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) throw new Exception('Macmiilka lama helin');

    if ($invoiceId) {
        $checkInv = $pdo->prepare('SELECT id FROM invoices WHERE id = ? AND customer_id = ? AND tenant_id = ? LIMIT 1');
        $checkInv->execute([$invoiceId, $customerId, $tenantId]);
        if (!$checkInv->fetch(PDO::FETCH_ASSOC)) $invoiceId = null;
    }

    $pdo->beginTransaction();
    try {
        $receiptNo = generateDebtReceiptNumber($pdo, $tenantId);
        $stmt = $pdo->prepare("INSERT INTO receipts
            (tenant_id, receipt_number, invoice_id, customer_id, amount, payment_date, payment_method, reference_number, notes, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$tenantId, $receiptNo, $invoiceId, $customerId, $amount, $paymentDate, $method, $reference, $notes, $userId]);
        $receiptId = (int)$pdo->lastInsertId();

        if ($invoiceId) updateSingleInvoicePaidAmount($pdo, $invoiceId, $tenantId);
        updateCustomerStoredBalance($pdo, $customerId, $tenantId);
        $pdo->commit();

        $wa = ['success' => false, 'message' => 'WhatsApp lama dirin'];
        if (!empty($data['send_whatsapp']) && !empty($customer['phone'])) {
            $tenantName = getTenantName($pdo, $tenantId);
            $companyPhone = getTenantPhone($pdo, $tenantId);
            $message = buildPaymentReceivedMessage($customer, $amount, $receiptNo, $tenantName, $companyPhone);
            $wa = sendWhatsAppGreenAPIDebt($customer['phone'], $message);
        }

        return ['success' => true, 'message' => 'Lacag bixinta waa la diiwaangeliyay.', 'receipt_id' => $receiptId, 'receipt_number' => $receiptNo, 'whatsapp' => $wa];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function downloadDebtImportTemplate(): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=debt_payment_import_template.csv');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['customer_phone','customer_name','amount','payment_date','payment_method','reference_number','invoice_number','notes','send_whatsapp']);
    fputcsv($out, ['25261XXXXXXX','Ahmed Mohamed','50.00',date('Y-m-d'),'cash','REF-001','INV-0001','Payment from debt page','yes']);
    fclose($out);
    exit;
}

function importDebtPaymentsFromCSV(PDO $pdo, int $tenantId, int $userId, array $file): array
{
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) throw new Exception('CSV file lama helin');
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) throw new Exception('CSV file lama furi karo');
    $header = fgetcsv($handle);
    if (!$header) throw new Exception('CSV header lama helin');
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $map = [];
    foreach ($header as $i => $col) $map[strtolower(trim((string)$col))] = $i;
    foreach (['amount'] as $required) {
        if (!isset($map[$required])) throw new Exception("Column-ka {$required} waa qasab");
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
            $phone = preg_replace('/\D/', '', $get($row, 'customer_phone'));
            $name = $get($row, 'customer_name');
            $customerId = 0;
            if ($phone !== '') {
                $stmt = $pdo->prepare('SELECT id FROM customers WHERE tenant_id = ? AND REPLACE(REPLACE(REPLACE(phone, "+", ""), " ", ""), "-", "") LIKE ? LIMIT 1');
                $stmt->execute([$tenantId, '%' . $phone . '%']);
                $customerId = (int)$stmt->fetchColumn();
            }
            if (!$customerId && $name !== '') {
                $stmt = $pdo->prepare('SELECT id FROM customers WHERE tenant_id = ? AND customer_name = ? LIMIT 1');
                $stmt->execute([$tenantId, $name]);
                $customerId = (int)$stmt->fetchColumn();
            }
            if (!$customerId) throw new Exception('Macmiil lama helin');

            $invoiceId = null;
            $invoiceNo = $get($row, 'invoice_number');
            if ($invoiceNo !== '' && tableExists($pdo, 'invoices')) {
                $stmt = $pdo->prepare('SELECT id FROM invoices WHERE tenant_id = ? AND customer_id = ? AND invoice_number = ? LIMIT 1');
                $stmt->execute([$tenantId, $customerId, $invoiceNo]);
                $invoiceId = $stmt->fetchColumn() ?: null;
            }
            $created = recordDebtPayment($pdo, $tenantId, $userId, [
                'customer_id' => $customerId,
                'invoice_id' => $invoiceId,
                'amount' => (float)str_replace(',', '.', $get($row, 'amount', '0')),
                'payment_date' => $get($row, 'payment_date', date('Y-m-d')) ?: date('Y-m-d'),
                'payment_method' => $get($row, 'payment_method', 'cash') ?: 'cash',
                'reference_number' => $get($row, 'reference_number'),
                'notes' => $get($row, 'notes'),
                'send_whatsapp' => strtolower($get($row, 'send_whatsapp', 'no')) === 'yes' ? 1 : 0
            ]);
            $summary['inserted']++;
            if (!empty($created['whatsapp']['success'])) $summary['whatsapp_sent']++;
            elseif (strtolower($get($row, 'send_whatsapp', 'no')) === 'yes') $summary['whatsapp_failed']++;
        } catch (Throwable $e) {
            $summary['failed']++;
            $summary['errors'][] = 'Row ' . $rowNo . ': ' . $e->getMessage();
        }
    }
    fclose($handle);
    return $summary;
}

function exportDebtorsCSV(PDO $pdo, int $tenantId): void
{
    recalculateAllCustomerBalances($pdo, $tenantId);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=debtors_export_' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Customer', 'Phone', 'Email', 'Total Invoiced', 'Total Paid', 'Debt', 'Oldest Invoice', 'Days Overdue']);
    $stmt = $pdo->prepare('SELECT id, customer_name, phone, email FROM customers WHERE tenant_id = ? ORDER BY customer_name ASC');
    $stmt->execute([$tenantId]);
    while ($c = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $b = getCustomerBalanceData($pdo, (int)$c['id'], $tenantId);
        if ((float)$b['debt_amount'] <= 0.009) continue;
        $oldest = getOldestOpenInvoice($pdo, (int)$c['id'], $tenantId);
        $days = 0;
        if ($oldest) {
            $due = $oldest['due_date'] ?: $oldest['invoice_date'];
            $days = max(0, (int)floor((time() - strtotime($due)) / 86400));
        }
        fputcsv($out, [$c['customer_name'], $c['phone'], $c['email'], number_format($b['total_invoiced'], 2), number_format($b['total_paid'], 2), number_format($b['debt_amount'], 2), $oldest['invoice_number'] ?? '', $days]);
    }
    fclose($out);
    exit;
}

// ============================================
// NOTE: getInvoiceStatusBadge() function is already defined in includes/functions.php
// Do NOT redeclare it here!
// ============================================

$action = $_REQUEST['ajax_action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'download_import_template') {
        downloadDebtImportTemplate();
    }

    if ($action === 'export_debtors' || $action === 'export_customers') {
        exportDebtorsCSV($pdo, $session_tenant_id);
    }

    if ($action === 'import_payments') {
        try {
            $summary = importDebtPaymentsFromCSV($pdo, $session_tenant_id, (int)($_SESSION['user_id'] ?? 0), $_FILES['import_file'] ?? []);
            echo json_encode([
                'success' => true,
                'message' => "Import waa dhammaaday: {$summary['inserted']} lacag bixin waa la geliyay, {$summary['failed']} way fashilmeen. WhatsApp: {$summary['whatsapp_sent']} diray, {$summary['whatsapp_failed']} fashilmay.",
                'summary' => $summary
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_open_invoices') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $invoices = [];
        if ($customerId > 0 && tableExists($pdo, 'invoices')) {
            $stmt = $pdo->prepare("SELECT id, invoice_number, total_amount, COALESCE(paid_amount,0) AS paid_amount, GREATEST(total_amount - COALESCE(paid_amount,0),0) AS remaining_amount
                FROM invoices
                WHERE tenant_id = ? AND customer_id = ? AND COALESCE(status,'') != 'cancelled' AND (total_amount - COALESCE(paid_amount,0)) > 0.009
                ORDER BY COALESCE(due_date, invoice_date) ASC, id ASC");
            $stmt->execute([$session_tenant_id, $customerId]);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode(['success' => true, 'invoices' => $invoices]);
        exit;
    }

    if ($action === 'record_payment') {
        try {
            $result = recordDebtPayment($pdo, $session_tenant_id, (int)($_SESSION['user_id'] ?? 0), $_POST);
            echo json_encode($result);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }


    if ($action === 'get_summary') {
        recalculateAllCustomerBalances($pdo, $session_tenant_id);

        $stmt = $pdo->prepare('SELECT id FROM customers WHERE tenant_id = ?');
        $stmt->execute([$session_tenant_id]);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = [
            'total_customers' => count($customers),
            'debtors_count' => 0,
            'credit_count' => 0,
            'cleared_count' => 0,
            'total_debt' => 0,
            'total_credit' => 0,
            'over_limit_count' => 0,
        ];

        foreach ($customers as $c) {
            $b = getCustomerBalanceData($pdo, (int)$c['id'], $session_tenant_id);
            if ($b['net_balance'] > 0.009) {
                $summary['debtors_count']++;
                $summary['total_debt'] += $b['debt_amount'];
            } elseif ($b['net_balance'] < -0.009) {
                $summary['credit_count']++;
                $summary['total_credit'] += $b['credit_amount'];
            } else {
                $summary['cleared_count']++;
            }
        }

        if (columnExists($pdo, 'customers', 'credit_limit')) {
            $stmt = $pdo->prepare('SELECT id, credit_limit FROM customers WHERE tenant_id = ? AND COALESCE(credit_limit,0) > 0');
            $stmt->execute([$session_tenant_id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $b = getCustomerBalanceData($pdo, (int)$c['id'], $session_tenant_id);
                if ($b['debt_amount'] > (float)$c['credit_limit']) $summary['over_limit_count']++;
            }
        }

        $summary['total_customers'] = $summary['debtors_count'];
        echo json_encode(['success' => true, 'data' => $summary]);
        exit;
    }

    if ($action === 'get_customers') {
        recalculateAllCustomerBalances($pdo, $session_tenant_id);

        $page = max(1, (int)($_POST['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;
        $search = trim($_POST['search'] ?? '');
        $statusFilter = trim($_POST['status_filter'] ?? 'all');

        $where = ['c.tenant_id = ?'];
        $params = [$session_tenant_id];
        if ($search !== '') {
            $where[] = '(c.customer_name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM customers c WHERE $whereSql");
        $countStmt->execute($params);
        $totalRows = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $userJoin = tableExists($pdo, 'users') ? 'LEFT JOIN users u ON c.user_id = u.id' : '';
        $userSelect = tableExists($pdo, 'users') ? ', u.id AS user_account_id, u.email AS user_email' : ', NULL AS user_account_id, NULL AS user_email';

        $sql = "
            SELECT c.* $userSelect
            FROM customers c
            $userJoin
            WHERE $whereSql
            ORDER BY c.customer_name ASC
            LIMIT $limit OFFSET $offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $customers = [];
        foreach ($rows as $row) {
            $balance = getCustomerBalanceData($pdo, (int)$row['id'], $session_tenant_id);

            // Page-kan wuxuu soo bandhigayaa kaliya macaamiisha deynta lagu leeyahay.
            if ($balance['net_balance'] <= 0.009) continue;

            $oldest = getOldestOpenInvoice($pdo, (int)$row['id'], $session_tenant_id);
            $daysOverdue = 0;
            if ($oldest) {
                $due = $oldest['due_date'] ?: $oldest['invoice_date'];
                $daysOverdue = max(0, (int)floor((time() - strtotime($due)) / 86400));
            }

            $row['balance'] = $balance;
            $row['days_overdue'] = $daysOverdue;
            $row['over_limit'] = isset($row['credit_limit']) && (float)$row['credit_limit'] > 0 && $balance['debt_amount'] > (float)$row['credit_limit'];
            $customers[] = $row;
        }

        ob_start();
        ?>
        <div class="table-responsive">
            <table class="table table-hover balance-table mb-0">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Contact</th>
                        <th class="text-right">Total Invoiced</th>
                        <th class="text-right">Total Paid</th>
                        <th class="text-right">Debt</th>
                        <th class="text-right">Credit</th>
                        <th>Status</th>
                        <th>Overdue</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($customers): ?>
                    <?php foreach ($customers as $c): $b = $c['balance']; ?>
                        <tr class="<?= $c['over_limit'] ? 'table-danger' : '' ?>">
                            <td>
                                <strong><?= h($c['customer_name'] ?? 'Unknown') ?></strong>
                                <?php if (!empty($c['user_account_id'])): ?>
                                    <span class="badge badge-secondary ml-1">User Account</span>
                                <?php endif; ?>
                                <?php if (isset($c['is_active']) && (int)$c['is_active'] === 0): ?>
                                    <span class="badge badge-dark ml-1">Inactive</span>
                                <?php endif; ?>
                             </div>
                            <td>
                                <div><?= !empty($c['phone']) ? '<i class="fas fa-phone"></i> ' . h($c['phone']) : '<span class="text-muted">No phone</span>' ?></div>
                                <small><?= !empty($c['email']) ? '<i class="fas fa-envelope"></i> ' . h($c['email']) : '' ?></small>
                             </div>
                            <td class="text-right"><?= money((float)$b['total_invoiced']) ?></div>
                            <td class="text-right text-success"><?= money((float)$b['total_paid']) ?></div>
                            <td class="text-right text-danger font-weight-bold"><?= money((float)$b['debt_amount']) ?></div>
                            <td class="text-right text-info font-weight-bold"><?= money((float)$b['credit_amount']) ?></div>
                            <td><span class="badge badge-<?= h($b['status_class']) ?>"><?= h($b['status_label']) ?></span></div>
                            <td>
                                <?php if ($c['days_overdue'] > 0): ?>
                                    <span class="text-danger font-weight-bold"><?= (int)$c['days_overdue'] ?> days</span>
                                <?php else: ?>
                                    <span class="text-muted">Current</span>
                                <?php endif; ?>
                             </div>
                            <td class="text-center action-buttons">
                                <button class="btn btn-sm btn-info view-customer" data-id="<?= (int)$c['id'] ?>" data-name="<?= h($c['customer_name'] ?? '') ?>"><i class="fas fa-eye"></i></button>
                                <?php if ($b['debt_amount'] > 0.009): ?>
                                    <button class="btn btn-sm btn-success collect-payment" data-id="<?= (int)$c['id'] ?>" data-name="<?= h($c['customer_name'] ?? '') ?>" data-debt="<?= (float)$b['debt_amount'] ?>"><i class="fas fa-money-bill-wave"></i></button>
                                <?php endif; ?>
                                <?php if (!empty($c['phone']) && $b['debt_amount'] > 0.009): ?>
                                    <button class="btn btn-sm btn-warning send-reminder" data-id="<?= (int)$c['id'] ?>" data-name="<?= h($c['customer_name'] ?? '') ?>" data-phone="<?= h($c['phone']) ?>" data-debt="<?= (float)$b['debt_amount'] ?>"><i class="fab fa-whatsapp"></i></button>
                                <?php endif; ?>
                             </div>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="9" class="text-center p-5 text-muted">No customers found.</div></div>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        $tableHtml = ob_get_clean();

        $totalPages = max(1, (int)ceil($totalRows / $limit));
        ob_start();
        if ($totalPages > 1): ?>
            <div class="pagination-wrap">
                <?php if ($page > 1): ?><button class="page-btn" data-page="<?= $page - 1 ?>">Previous</button><?php endif; ?>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <button class="page-btn <?= $i === $page ? 'active' : '' ?>" data-page="<?= $i ?>"><?= $i ?></button>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?><button class="page-btn" data-page="<?= $page + 1 ?>">Next</button><?php endif; ?>
            </div>
        <?php endif;
        $paginationHtml = ob_get_clean();

        echo json_encode(['success' => true, 'table_html' => $tableHtml, 'pagination_html' => $paginationHtml, 'total_rows' => $totalRows]);
        exit;
    }

    if ($action === 'get_customer_details') {
        $id = (int)($_POST['id'] ?? 0);
        updateCustomerStoredBalance($pdo, $id, $session_tenant_id);

        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$id, $session_tenant_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$customer) {
            echo json_encode(['success' => false, 'message' => 'Customer not found']);
            exit;
        }

        $balance = getCustomerBalanceData($pdo, $id, $session_tenant_id);

        $invoices = [];
        if (tableExists($pdo, 'invoices')) {
            $stmt = $pdo->prepare("
                SELECT id, invoice_number, invoice_date, due_date, total_amount, COALESCE(paid_amount,0) AS paid_amount, status
                FROM invoices
                WHERE customer_id = ? AND tenant_id = ? AND COALESCE(status,'') != 'cancelled'
                ORDER BY invoice_date DESC, id DESC
            ");
            $stmt->execute([$id, $session_tenant_id]);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($invoices as &$inv) {
                $actualPaid = getInvoiceTotalPaid($pdo, (int)$inv['id'], $session_tenant_id);
                $inv['paid_amount'] = $actualPaid;
                $inv['remaining_amount'] = max(0, (float)$inv['total_amount'] - $actualPaid);
                $due = $inv['due_date'] ?: $inv['invoice_date'];
                $inv['days_overdue'] = $inv['remaining_amount'] > 0 ? max(0, (int)floor((time() - strtotime($due)) / 86400)) : 0;
            }
        }

        $receipts = [];
        if (tableExists($pdo, 'receipts')) {
            $stmt = $pdo->prepare("
                SELECT r.id, r.receipt_number, r.amount, r.payment_date, r.payment_method, r.reference_number, r.invoice_id, i.invoice_number
                FROM receipts r
                LEFT JOIN invoices i ON r.invoice_id = i.id
                WHERE r.customer_id = ? AND r.tenant_id = ?
                ORDER BY r.payment_date DESC, r.id DESC
            ");
            $stmt->execute([$id, $session_tenant_id]);
            $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode(['success' => true, 'customer' => $customer, 'balance' => $balance, 'invoices' => $invoices, 'receipts' => $receipts]);
        exit;
    }

    if ($action === 'send_reminder') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $type = $_POST['reminder_type'] ?? 'whatsapp';

        if ($type === 'sms') {
            if (!$messaging) {
                echo json_encode(['success' => false, 'message' => 'MessagingService.php is not available']);
                exit;
            }
            $stmt = $pdo->prepare('SELECT customer_name, phone FROM customers WHERE id = ? AND tenant_id = ? LIMIT 1');
            $stmt->execute([$customerId, $session_tenant_id]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$customer || empty($customer['phone'])) {
                echo json_encode(['success' => false, 'message' => 'Telefoonka macaamiilka lama helin']);
                exit;
            }
            $balance = getCustomerBalanceData($pdo, $customerId, $session_tenant_id);
            if ($balance['debt_amount'] <= 0.009) {
                echo json_encode(['success' => false, 'message' => 'Macaamiilkan deyn ma laha']);
                exit;
            }
            $tenant_name = getTenantName($pdo, $session_tenant_id);

$greenConfig = __DIR__ . '/../config/greenapi_config.php';
if (file_exists($greenConfig)) {
    require_once $greenConfig;
}
            $companyPhone = getTenantPhone($pdo, $session_tenant_id);
            $message = buildSomaliDebtReminderMessage($customer, $balance, $tenant_name, $companyPhone, getOldestOpenInvoice($pdo, $customerId, $session_tenant_id));
            echo json_encode($messaging->sendSMS($customer['phone'], $message));
            exit;
        }

        $result = sendDebtReminderWhatsApp($pdo, $session_tenant_id, $customerId, 'manual');
        echo json_encode($result);
        exit;
    }

    if ($action === 'send_auto_debt_reminders') {
        $minimumDebt = isset($_POST['minimum_debt']) ? (float)$_POST['minimum_debt'] : 1.00;
        $summary = sendAutomaticDebtReminders($pdo, $session_tenant_id, $minimumDebt);
        echo json_encode(['success' => true, 'message' => "Fariin wadareed: {$summary['sent']} waa la diray, {$summary['skipped']} waa la dhaafay, {$summary['failed']} way fashilmeen.", 'summary' => $summary]);
        exit;
    }

    if ($action === 'recalculate_all') {
        $result = recalculateAllCustomerBalances($pdo, $session_tenant_id);
        echo json_encode([
            'success' => empty($result['errors']),
            'message' => empty($result['errors'])
                ? "Balances recalculated. Invoices: {$result['invoices_updated']}, Customers: {$result['customers_updated']}"
                : 'Completed with errors: ' . implode(', ', $result['errors']),
            'details' => $result
        ]);
        exit;
    }

    if ($action === 'export_customers') {
        recalculateAllCustomerBalances($pdo, $session_tenant_id);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=customer_balances_' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Customer', 'Phone', 'Email', 'Total Invoiced', 'Total Paid', 'Debt', 'Credit', 'Balance Status']);
        $stmt = $pdo->prepare('SELECT id, customer_name, phone, email FROM customers WHERE tenant_id = ? ORDER BY customer_name ASC');
        $stmt->execute([$session_tenant_id]);
        while ($c = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $b = getCustomerBalanceData($pdo, (int)$c['id'], $session_tenant_id);
            fputcsv($out, [$c['customer_name'], $c['phone'], $c['email'], number_format($b['total_invoiced'], 2), number_format($b['total_paid'], 2), number_format($b['debt_amount'], 2), number_format($b['credit_amount'], 2), $b['status_label']]);
        }
        fclose($out);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Balances - <?= h($tenant_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root { --brand:#2D1859; --danger:#d52b1e; --success:#0F7A3A; --info:#17a2b8; --warning:#ffc107; }
        body { background:#f4f6f9; font-family:Segoe UI,Tahoma,Verdana,sans-serif; }
        .page-header { background:#fff; border-bottom:1px solid #e5e7eb; padding:20px 25px; margin-bottom:22px; display:flex; justify-content:space-between; align-items:center; gap:15px; flex-wrap:wrap; }
        .page-header h1 { font-size:24px; font-weight:800; margin:0; color:#222; }
        .page-header h1 i { color:var(--brand); margin-right:8px; }
        .stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:18px; padding:0 25px; margin-bottom:22px; }
        .stat-card { background:#fff; border-radius:14px; padding:18px; box-shadow:0 3px 10px rgba(0,0,0,.06); display:flex; justify-content:space-between; align-items:center; border-left:4px solid #ddd; }
        .stat-card.debt { border-left-color:var(--danger); } .stat-card.credit { border-left-color:var(--info); } .stat-card.clear { border-left-color:var(--success); } .stat-card.total { border-left-color:var(--brand); }
        .stat-card h4 { font-size:12px; text-transform:uppercase; color:#6b7280; margin:0 0 5px; font-weight:700; }
        .stat-card .num { font-size:25px; font-weight:800; }
        .filters-card, .table-card { background:#fff; border-radius:14px; margin:0 25px 22px; box-shadow:0 3px 10px rgba(0,0,0,.06); }
        .filters-card { padding:18px; }
        .filter-form { display:flex; flex-wrap:wrap; gap:15px; align-items:end; }
        .filter-group { flex:1; min-width:180px; }
        .filter-group label { font-size:12px; font-weight:700; color:#6b7280; text-transform:uppercase; }
        .balance-table th { background:#f8fafc; color:#374151; white-space:nowrap; }
        .balance-table td { vertical-align:middle; white-space:nowrap; }
        .action-buttons .btn { margin:1px; }
        .pagination-wrap { display:flex; justify-content:center; gap:8px; margin:20px 25px; flex-wrap:wrap; }
        .page-btn { border:1px solid #ddd; background:white; border-radius:8px; padding:8px 13px; cursor:pointer; }
        .page-btn.active { background:var(--brand); color:white; }
        .modal-header { background:linear-gradient(135deg,var(--brand),#4B2C85); color:white; }
        .modal-header .close { color:white; opacity:1; }
        .info-card { background:#f8fafc; border-radius:12px; padding:15px; margin-bottom:15px; }
        .alert-custom { position:fixed; top:20px; right:20px; z-index:9999; min-width:300px; }
        .badge { font-size:11px; padding:5px 8px; }
        @media(max-width:768px){ .stats-grid{padding:0 12px;} .filters-card,.table-card{margin-left:12px;margin-right:12px;} }
    </style>
</head>
<body>
<div class="container-fluid p-0">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1>Macaamiisha Deynta Lagu Leeyahay</h1>
        <div>
            <button class="btn btn-success" id="exportBtn">Export</button>
            <button class="btn btn-info" id="importBtn">Import</button>
            <button class="btn btn-light" id="templateBtn">Template</button>
            <button class="btn btn-warning" id="recalculateBtn">Dib u xisaabi</button>
            <button class="btn btn-success" id="autoWhatsappBtn">Fariin Wadareed</button>
            <button class="btn btn-secondary" id="refreshBtn">Refresh</button>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card total"><div><h4>Macaamiisha Deynta Leh</h4><div class="num" id="totalCustomers">0</div></div><i class="fas fa-users fa-2x text-secondary"></i></div>
        <div class="stat-card debt"><div><h4>Customers Owe You</h4><div class="num text-danger" id="totalDebt">$0.00</div><small id="debtorsCount">0 customers</small></div><i class="fas fa-arrow-down fa-2x text-danger"></i></div>
        <div class="stat-card credit"><div><h4>You Owe Customers</h4><div class="num text-info" id="totalCredit">$0.00</div><small id="creditCount">0 customers</small></div><i class="fas fa-arrow-up fa-2x text-info"></i></div>
        <div class="stat-card clear"><div><h4>Cleared Accounts</h4><div class="num text-success" id="clearedCount">0</div></div><i class="fas fa-check-circle fa-2x text-success"></i></div>
    </div>

    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group"><label>Search Customer</label><input type="text" id="searchInput" class="form-control" placeholder="Name, phone, email..."></div>
            <input type="hidden" id="statusFilter" value="debt">
            <div class="filter-group"><button class="btn btn-primary" id="filterBtn" style="background:#2D1859;border-color:#2D1859;">Filter</button> <button class="btn btn-light" id="resetBtn">Reset</button></div>
        </div>
    </div>

    <div class="table-card" id="tableContainer"><div class="text-center p-5"><i class="fas fa-spinner fa-spin"></i><p>Loading customers...</p></div></div>
    <div id="paginationContainer"></div>
</div>

<div class="modal fade" id="customerModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Customer Details: <span id="modalCustomerName"></span></h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-5"><div class="info-card"><h6>Contact</h6><p><b>Phone:</b> <span id="detailPhone">-</span></p><p><b>Email:</b> <span id="detailEmail">-</span></p><p><b>Address:</b> <span id="detailAddress">-</span></p></div></div>
                    <div class="col-md-7"><div class="info-card"><h6>Balance Summary</h6><p><b>Total Invoiced:</b> <span id="detailInvoiced">$0.00</span></p><p><b>Total Paid:</b> <span class="text-success" id="detailPaid">$0.00</span></p><p><b>Debt:</b> <span class="text-danger font-weight-bold" id="detailDebt">$0.00</span></p><p><b>Credit:</b> <span class="text-info font-weight-bold" id="detailCredit">$0.00</span></p><p><b>Status:</b> <span id="detailStatus"></span></p></div></div>
                </div>

                <h6><i class="fas fa-file-invoice"></i> Invoices</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="thead-light">
                            <tr>
                                <th>Invoice #</th>
                                <th>Date</th>
                                <th>Due Date</th>
                                <th>Total</th>
                                <th>Paid</th>
                                <th>Remaining</th>
                                <th>Overdue</th>
                                <th>Status</th>
                                <th>View</th>
                            </tr>
                        </thead>
                        <tbody id="invoiceRows"></tbody>
                    </table>
                </div>

                <h6 class="mt-3"><i class="fas fa-receipt"></i> Receipts / Payments</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="thead-light">
                            <tr>
                                <th>Receipt #</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Invoice</th>
                                <th>Reference</th>
                            </tr>
                        </thead>
                        <tbody id="receiptRows"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button class="btn btn-success" id="modalCollectBtn"><i class="fas fa-money-bill-wave"></i> Record Payment</button>
                <button class="btn btn-warning" id="modalReminderBtn"><i class="fab fa-whatsapp"></i> Reminder</button>
            </div>
        </div>
    </div>
</div>

<!-- Payment Modal - Inline AJAX -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header" style="background:#0F7A3A"><h5 class="modal-title">Diiwaan geli Lacag Bixin</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
        <form id="paymentForm">
            <div class="modal-body">
                <input type="hidden" name="customer_id" id="paymentCustomerId">
                <div class="form-group"><label>Macmiil</label><input type="text" id="paymentCustomerName" class="form-control" readonly></div>
                <div class="form-group"><label>Deynta hadda</label><input type="text" id="paymentDebt" class="form-control" readonly></div>
                <div class="form-group"><label>Invoice</label><select name="invoice_id" id="paymentInvoiceId" class="form-control"><option value="">General Payment</option></select></div>
                <div class="form-group"><label>Qaddarka la bixiyay</label><input type="number" step="0.01" name="amount" id="paymentAmount" class="form-control" required></div>
                <div class="form-group"><label>Taariikhda</label><input type="date" name="payment_date" id="paymentDate" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                <div class="form-group"><label>Habka bixinta</label><select name="payment_method" id="paymentMethod" class="form-control"><option value="cash">Cash</option><option value="mobile_money">Mobile Money</option><option value="bank_transfer">Bank Transfer</option></select></div>
                <div class="form-group"><label>Reference</label><input type="text" name="reference_number" id="paymentReference" class="form-control"></div>
                <div class="form-group"><label>Faahfaahin</label><textarea name="notes" id="paymentNotes" class="form-control" rows="2"></textarea></div>
                <div class="custom-control custom-checkbox">
                    <input type="checkbox" class="custom-control-input" id="paymentSendWhatsapp" name="send_whatsapp" value="1" checked>
                    <label class="custom-control-label" for="paymentSendWhatsapp">WhatsApp confirmation u dir macmiilka</label>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-success" type="submit">Kaydi Lacag Bixin</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Import Lacag Bixin CSV</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
        <form id="importForm" enctype="multipart/form-data">
            <div class="modal-body">
                <p class="text-muted">CSV columns: customer_phone, customer_name, amount, payment_date, payment_method, reference_number, invoice_number, notes, send_whatsapp.</p>
                <input type="file" name="import_file" id="importFile" class="form-control" accept=".csv" required>
            </div>
            <div class="modal-footer"><button class="btn btn-info" type="submit">Import Garee</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="reminderModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header" style="background:#ffc107;color:#111"><h5 class="modal-title">Send Reminder</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
        <form id="reminderForm">
            <div class="modal-body">
                <input type="hidden" id="reminderCustomerId">
                <div class="form-group"><label>Customer</label><input type="text" id="reminderCustomerName" class="form-control" readonly></div>
                <div class="form-group"><label>Phone</label><input type="text" id="reminderPhone" class="form-control" readonly></div>
                <div class="form-group"><label>Debt</label><input type="text" id="reminderDebt" class="form-control" readonly></div>
                <div class="form-group"><label>Type</label><select id="reminderType" class="form-control"><option value="whatsapp">WhatsApp</option><option value="sms">SMS</option></select></div>
            </div>
            <div class="modal-footer"><button class="btn btn-warning" type="submit">Send</button></div>
        </form>
    </div></div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(function(){
    let currentPage = 1;
    let currentCustomer = {id:null, name:'', debt:0, phone:''};

    function fmt(n){ return '$' + parseFloat(n || 0).toFixed(2); }
    function esc(s){ return $('<div>').text(s || '').html(); }
    function alertBox(type,msg){
        const cls = type === 'success' ? 'alert-success' : 'alert-danger';
        $('#alert-placeholder').html(`<div class="alert ${cls} alert-custom alert-dismissible fade show">${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
        setTimeout(()=>$('.alert-custom').fadeOut(400),3500);
    }

    function loadSummary(){
        $.post(window.location.href,{ajax_action:'get_summary'},function(res){
            if(!res.success) return;
            const d = res.data;
            $('#totalCustomers').text(d.total_customers || 0);
            $('#totalDebt').text(fmt(d.total_debt));
            $('#totalCredit').text(fmt(d.total_credit));
            $('#debtorsCount').text((d.debtors_count || 0) + ' customers');
            $('#creditCount').text((d.credit_count || 0) + ' customers');
            $('#clearedCount').text(d.cleared_count || 0);
        },'json');
    }

    function loadCustomers(){
        $('#tableContainer').html('<div class="text-center p-5"><i class="fas fa-spinner fa-spin"></i><p>Loading customers...</p></div>');
        $.post(window.location.href,{ajax_action:'get_customers',page:currentPage,search:$('#searchInput').val(),status_filter:$('#statusFilter').val()},function(res){
            if(res.success){ $('#tableContainer').html(res.table_html); $('#paginationContainer').html(res.pagination_html); bindEvents(); }
            else $('#tableContainer').html('<div class="text-center p-5 text-danger">Error loading customers</div>');
        },'json').fail(()=>$('#tableContainer').html('<div class="text-center p-5 text-danger">Server error</div>'));
    }

    function bindEvents(){
        $('.page-btn').off('click').on('click',function(){ currentPage = $(this).data('page'); loadCustomers(); });
        $('.view-customer').off('click').on('click',function(){
            const id = $(this).data('id');
            currentCustomer.id = id; currentCustomer.name = $(this).data('name');
            $('#modalCustomerName').text(currentCustomer.name);
            $('#customerModal').modal('show');
            $.post(window.location.href,{ajax_action:'get_customer_details',id:id},function(res){
                if(!res.success){ alertBox('error',res.message || 'Not found'); return; }
                const c = res.customer, b = res.balance;
                currentCustomer.debt = parseFloat(b.debt_amount || 0); currentCustomer.phone = c.phone || '';
                $('#detailPhone').text(c.phone || '-'); $('#detailEmail').text(c.email || '-'); $('#detailAddress').text(c.address || '-');
                $('#detailInvoiced').text(fmt(b.total_invoiced)); $('#detailPaid').text(fmt(b.total_paid)); $('#detailDebt').text(fmt(b.debt_amount)); $('#detailCredit').text(fmt(b.credit_amount));
                $('#detailStatus').html(`<span class="badge badge-${esc(b.status_class)}">${esc(b.status_label)}</span>`);
                let invRows = '';
                if(res.invoices && res.invoices.length){
                    res.invoices.forEach(inv=>{
                        const total = parseFloat(inv.total_amount || 0), paid = parseFloat(inv.paid_amount || 0), rem = parseFloat(inv.remaining_amount || 0), days = parseInt(inv.days_overdue || 0);
                        let statusHtml = '';
                        if(rem <= 0){
                            statusHtml = '<span class="badge badge-success">Paid</span>';
                        } else if(paid > 0 && rem > 0){
                            statusHtml = '<span class="badge badge-warning">Partial</span>';
                        } else {
                            statusHtml = '<span class="badge badge-danger">Unpaid</span>';
                        }
                        invRows += `
                            <tr>
                                <td>${esc(inv.invoice_number)}</div>
                                <td>${esc(inv.invoice_date)}</div>
                                <td>${esc(inv.due_date || '-')}</div>
                                <td class="text-danger">${fmt(total)}</div>
                                <td class="text-success">${fmt(paid)}</div>
                                <td class="font-weight-bold ${rem>0?'text-danger':'text-success'}">${fmt(rem)}</div>
                                <td class="${days>60?'text-danger':(days>30?'text-warning':'')}">${days>0?days+' days':'Current'}</div>
                                <td>${statusHtml}</div>
                                <td><a class="btn btn-sm btn-info" href="invoices.php?edit=${inv.id}" target="_blank"><i class="fas fa-eye"></i></a></div>
                            </tr>
                        `;
                    });
                } else invRows = '<tr><td colspan="9" class="text-center text-muted">No invoices found</td></tr>';
                $('#invoiceRows').html(invRows);
                let recRows = '';
                if(res.receipts && res.receipts.length){
                    res.receipts.forEach(r=>{ recRows += `
                        <tr>
                            <td>${esc(r.receipt_number)}</div>
                            <td>${esc(r.payment_date)}</div>
                            <td class="text-success">${fmt(r.amount)}</div>
                            <td>${esc(r.payment_method || '-')}</div>
                            <td>${esc(r.invoice_number || 'General Payment')}</div>
                            <td>${esc(r.reference_number || '-')}</div>
                        </tr>`;
                    });
                } else recRows = '<tr><td colspan="6" class="text-center text-muted">No receipts found</td></tr>';
                $('#receiptRows').html(recRows);
            },'json');
        });
        $('.collect-payment').off('click').on('click',function(){ openPayment($(this).data('id'),$(this).data('name'),$(this).data('debt')); });
        $('.send-reminder').off('click').on('click',function(){ openReminder($(this).data('id'),$(this).data('name'),$(this).data('phone'),$(this).data('debt')); });
    }

    function openPayment(id,name,debt){ 
        $('#paymentCustomerId').val(id); 
        $('#paymentCustomerName').val(name); 
        $('#paymentDebt').val(fmt(debt)); 
        $('#paymentAmount').val(parseFloat(debt || 0).toFixed(2));
        $('#paymentInvoiceId').html('<option value="">General Payment</option>');
        $.post(window.location.href,{ajax_action:'get_open_invoices',customer_id:id},function(res){
            if(res.success && res.invoices){
                res.invoices.forEach(inv=>{
                    $('#paymentInvoiceId').append(`<option value="${inv.id}">${esc(inv.invoice_number)} - ${fmt(inv.remaining_amount)}</option>`);
                });
            }
        },'json');
        $('#paymentModal').modal('show'); 
    }
    
    function openReminder(id,name,phone,debt){ 
        $('#reminderCustomerId').val(id); 
        $('#reminderCustomerName').val(name); 
        $('#reminderPhone').val(phone); 
        $('#reminderDebt').val(fmt(debt)); 
        $('#reminderModal').modal('show'); 
    }

    $('#paymentForm').submit(function(e){
        e.preventDefault();
        if(!$('#paymentAmount').val() || parseFloat($('#paymentAmount').val()) <= 0){
            alertBox('error', 'Please enter a valid payment amount');
            return;
        }
        const btn = $(this).find('button[type="submit"]');
        btn.html('Saving...').prop('disabled', true);
        const data = $(this).serializeArray();
        data.push({name:'ajax_action', value:'record_payment'});
        $.post(window.location.href, data, function(res){
            alertBox(res.success?'success':'error', res.message || 'Done');
            if(res.success){
                $('#paymentModal').modal('hide');
                loadSummary();
                loadCustomers();
            }
            btn.html('Kaydi Lacag Bixin').prop('disabled', false);
        }, 'json').fail(function(){
            alertBox('error','Server error while saving payment');
            btn.html('Kaydi Lacag Bixin').prop('disabled', false);
        });
    });

    $('#modalCollectBtn').click(()=>{ 
        if(currentCustomer.id && currentCustomer.debt > 0){ 
            $('#customerModal').modal('hide'); 
            openPayment(currentCustomer.id, currentCustomer.name, currentCustomer.debt); 
        } else { 
            alertBox('error', 'No debt to collect from this customer'); 
        } 
    });
    
    $('#modalReminderBtn').click(()=>{ 
        if(currentCustomer.id && currentCustomer.debt > 0){ 
            $('#customerModal').modal('hide'); 
            openReminder(currentCustomer.id, currentCustomer.name, currentCustomer.phone, currentCustomer.debt); 
        } else { 
            alertBox('error', 'Customer has no debt to remind about.'); 
        } 
    });
    
    $('#filterBtn').click(()=>{ currentPage = 1; loadCustomers(); });
    $('#resetBtn').click(()=>{ $('#searchInput').val(''); $('#statusFilter').val('all'); currentPage = 1; loadCustomers(); });
    $('#refreshBtn').click(()=>{ loadSummary(); loadCustomers(); alertBox('success','Data refreshed'); });
    $('#autoWhatsappBtn').click(function(){
        if(!confirm('Ma hubtaa inaad fariin gaaban oo wadareed u dirto dhammaan macaamiisha deynta leh?')) return;
        const btn = $(this);
        btn.html('Sending...').prop('disabled', true);
        $.post(window.location.href,{ajax_action:'send_auto_debt_reminders', minimum_debt:1},function(res){
            alertBox(res.success?'success':'error', res.message || 'Done');
            loadSummary();
            loadCustomers();
            btn.html('Fariin Wadareed').prop('disabled', false);
        },'json').fail(function(){
            alertBox('error','Server error while sending WhatsApp reminders');
            btn.html('Fariin Wadareed').prop('disabled', false);
        });
    });
    $('#recalculateBtn').click(function(){ 
        if(!confirm('Recalculate all customer balances? This may take a moment.')) return; 
        const btn = $(this);
        btn.html('<i class="fas fa-spinner fa-spin"></i> Recalculating...').prop('disabled', true);
        $.post(window.location.href,{ajax_action:'recalculate_all'},function(res){ 
            alertBox(res.success?'success':'error',res.message); 
            loadSummary(); 
            loadCustomers();
            btn.html('<i class="fas fa-calculator"></i> Recalculate').prop('disabled', false);
        },'json'); 
    });
    $('#exportBtn').click(()=>{ window.location.href = window.location.pathname + '?ajax_action=export_debtors'; });
    $('#templateBtn').click(()=>{ window.location.href = window.location.pathname + '?ajax_action=download_import_template'; });
    $('#importBtn').click(()=>{ $('#importModal').modal('show'); });
    $('#importForm').submit(function(e){
        e.preventDefault();
        const file = $('#importFile')[0].files[0];
        if(!file){ alertBox('error','Fadlan dooro CSV file'); return; }
        const fd = new FormData(this);
        fd.append('ajax_action','import_payments');
        const btn = $(this).find('button[type="submit"]');
        btn.html('Importing...').prop('disabled', true);
        $.ajax({url:window.location.href, method:'POST', data:fd, processData:false, contentType:false, dataType:'json'})
            .done(function(res){
                alertBox(res.success?'success':'error', res.message || 'Done');
                if(res.success){ $('#importModal').modal('hide'); loadSummary(); loadCustomers(); }
            })
            .fail(function(){ alertBox('error','Server error while importing'); })
            .always(function(){ btn.html('Import Garee').prop('disabled', false); });
    });
    
    $('#reminderForm').submit(function(e){
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        btn.html('<i class="fas fa-spinner fa-spin"></i> Sending...').prop('disabled', true);
        $.post(window.location.href,{
            ajax_action:'send_reminder',
            customer_id:$('#reminderCustomerId').val(),
            reminder_type:$('#reminderType').val()
        },function(res){ 
            alertBox(res.success?'success':'error',res.message || 'Done'); 
            if(res.success) $('#reminderModal').modal('hide');
            btn.html('Send').prop('disabled', false);
        },'json');
    });
    
    $('#searchInput').on('keypress',function(e){ if(e.which === 13){ currentPage = 1; loadCustomers(); }});

    loadSummary(); 
    loadCustomers();
});
</script>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>