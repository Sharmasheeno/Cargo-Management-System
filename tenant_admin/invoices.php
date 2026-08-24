<?php
// tenant_admin/invoices.php
// Invoices Management for Cargo Management System - Tenant Admin
// WITH DELETE AND REFUND FUNCTIONALITY

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

// GREEN API CONFIGURATION - automatic WhatsApp
// Real values come from config/secrets.php (gitignored) or server environment variables.
require_once __DIR__ . '/../config/secrets.php';
if (!defined('GREEN_API_ID')) {
    define('GREEN_API_ID', getenv('GREEN_API_ID') ?: '');
}
if (!defined('GREEN_API_URL')) {
    define('GREEN_API_URL', getenv('GREEN_API_URL') ?: '');
}


// Check if services exist, if not create fallbacks
if (!class_exists('AccountingService')) {
    class AccountingService {
        private $pdo;
        private $tenant_id;
        private $user_id;
        
        public function __construct($pdo, $tenant_id, $user_id) {
            $this->pdo = $pdo;
            $this->tenant_id = $tenant_id;
            $this->user_id = $user_id;
        }
        
        public function journalizeInvoice($invoice_id) {
            try {
                $stmt = $this->pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, created_at) VALUES (?, 'CREATE_INVOICE', 'invoices', ?, NOW())");
                $stmt->execute([$this->user_id, $invoice_id]);
            } catch (Exception $e) {}
            return true;
        }
        
        public function journalizeReceipt($receipt_id) {
            try {
                $stmt = $this->pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, created_at) VALUES (?, 'CREATE_RECEIPT', 'receipts', ?, NOW())");
                $stmt->execute([$this->user_id, $receipt_id]);
            } catch (Exception $e) {}
            return true;
        }
        
        public function journalizeRefund($refund_id) {
            try {
                $stmt = $this->pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, created_at) VALUES (?, 'CREATE_REFUND', 'refunds', ?, NOW())");
                $stmt->execute([$this->user_id, $refund_id]);
            } catch (Exception $e) {}
            return true;
        }
    }
}

if (!class_exists('MessagingService')) {
    class MessagingService {
        private $pdo;
        private $idInstance = GREEN_API_ID;
        private $apiTokenInstance = GREEN_API_TOKEN;
        private $apiUrl = GREEN_API_URL;

        public function __construct($pdo) {
            $this->pdo = $pdo;
        }

        private function normalizePhone($phone) {
            $phone = preg_replace('/\D/', '', (string)$phone);
            if ($phone === '') return '';
            if (strlen($phone) === 9 && in_array($phone[0], ['6', '7'], true)) return '252' . $phone;
            if (strlen($phone) === 10 && $phone[0] === '0') return '252' . substr($phone, 1);
            if (strlen($phone) === 12 && substr($phone, 0, 3) === '252') return $phone;
            return '252' . ltrim($phone, '0');
        }

        public function sendWhatsApp($phone, $message) {
            $formattedPhone = $this->normalizePhone($phone);
            if ($formattedPhone === '') {
                return ['success' => false, 'message' => 'Telefoon sax ah lama helin'];
            }

            $url = rtrim($this->apiUrl, '/') . "/waInstance{$this->idInstance}/sendMessage/{$this->apiTokenInstance}";
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
                return ['success' => true, 'message' => 'WhatsApp waa la diray', 'message_id' => $decoded['idMessage'], 'api_response' => $decoded];
            }

            return ['success' => false, 'message' => $error ?: ($decoded['message'] ?? $response ?? 'WhatsApp API error')];
        }
    }
}

require_once __DIR__ . '/../includes/audit_helper.php';

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

function invoiceTableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetch(PDO::FETCH_NUM);
    } catch (Throwable $e) {
        return false;
    }
}

function invoiceColumnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function ensureInvoiceWhatsAppLogTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_invoice_logs (
        id INT(11) NOT NULL AUTO_INCREMENT,
        tenant_id INT(11) NOT NULL,
        invoice_id INT(11) DEFAULT NULL,
        customer_id INT(11) DEFAULT NULL,
        phone VARCHAR(50) DEFAULT NULL,
        message TEXT NOT NULL,
        send_status VARCHAR(30) DEFAULT 'pending',
        api_response TEXT DEFAULT NULL,
        reminder_type VARCHAR(50) DEFAULT 'invoice_auto',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_tenant_invoice (tenant_id, invoice_id),
        KEY idx_tenant_customer_date (tenant_id, customer_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function getInvoiceTenantInfo(PDO $pdo, int $tenantId): array {
    try {
        $stmt = $pdo->prepare("SELECT id, name, code, phone, address FROM tenants WHERE id = ? LIMIT 1");
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    } catch (Throwable $e) {}
    return ['id' => $tenantId, 'name' => 'My Company', 'code' => '', 'phone' => '', 'address' => ''];
}


function invoiceStatusInfo(array $invoice): array {
    $total = (float)($invoice['total_amount'] ?? 0);
    $paid = (float)($invoice['paid_amount'] ?? 0);
    $dueDate = trim((string)($invoice['due_date'] ?? ''));
    $rawStatus = strtolower(trim((string)($invoice['status'] ?? '')));

    if ($rawStatus === 'cancelled' || $rawStatus === 'refunded') {
        return ['code' => $rawStatus, 'en' => ucfirst($rawStatus), 'so' => $rawStatus === 'cancelled' ? 'La joojiyay' : 'Lacag celin', 'emoji' => '🚫'];
    }

    if ($total <= 0 || $paid >= $total) {
        return ['code' => 'paid', 'en' => 'Paid', 'so' => 'Waa la bixiyay', 'emoji' => '✅'];
    }

    if ($dueDate !== '' && $dueDate !== '0000-00-00' && strtotime($dueDate) < strtotime(date('Y-m-d'))) {
        return ['code' => 'overdue', 'en' => 'Overdue', 'so' => 'Waqtigeedii dhaaftay', 'emoji' => '🔴'];
    }

    if ($paid > 0 && $paid < $total) {
        return ['code' => 'partial', 'en' => 'Partial', 'so' => 'Qayb ayaa la bixiyay', 'emoji' => '🟡'];
    }

    return ['code' => 'unpaid', 'en' => 'Unpaid', 'so' => 'Lama bixin', 'emoji' => '⚪'];
}

function invoiceStatusCodeForDatabase(array $invoice): string {
    $code = invoiceStatusInfo($invoice)['code'];
    // Your database enum supports: draft, sent, paid, overdue, cancelled.
    // partial/unpaid are shown in UI from calculation, but stored as sent to avoid SQL enum errors.
    if ($code === 'paid' || $code === 'overdue' || $code === 'cancelled') return $code;
    return 'sent';
}

function invoiceStatusWhereClause(string $statusFilter, array &$params): ?string {
    $today = date('Y-m-d');
    switch ($statusFilter) {
        case 'paid':
            return 'COALESCE(i.paid_amount,0) >= COALESCE(i.total_amount,0) AND COALESCE(i.total_amount,0) > 0';
        case 'overdue':
            $params[] = $today;
            return "COALESCE(i.paid_amount,0) < COALESCE(i.total_amount,0) AND i.due_date IS NOT NULL AND i.due_date <> '0000-00-00' AND DATE(i.due_date) < ?";
        case 'partial':
            $params[] = $today;
            return "COALESCE(i.paid_amount,0) > 0 AND COALESCE(i.paid_amount,0) < COALESCE(i.total_amount,0) AND (i.due_date IS NULL OR i.due_date = '0000-00-00' OR DATE(i.due_date) >= ?)";
        case 'unpaid':
            $params[] = $today;
            return "COALESCE(i.paid_amount,0) <= 0 AND COALESCE(i.total_amount,0) > 0 AND (i.due_date IS NULL OR i.due_date = '0000-00-00' OR DATE(i.due_date) >= ?)";
        default:
            return null;
    }
}



function getInvoicePaidAmountFromReceipts(PDO $pdo, int $tenantId, int $invoiceId): float {
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) AS paid_total
            FROM receipts
            WHERE tenant_id = ?
              AND invoice_id = ?
        ");
        $stmt->execute([$tenantId, $invoiceId]);
        return max(0, (float)$stmt->fetchColumn());
    } catch (Throwable $e) {
        return 0.00;
    }
}

function syncInvoicePaymentFromReceipts(PDO $pdo, int $tenantId, int $invoiceId): array {
    $stmt = $pdo->prepare("
        SELECT id, tenant_id, customer_id, total_amount, paid_amount, due_date, status
        FROM invoices
        WHERE id = ? AND tenant_id = ?
        LIMIT 1
    ");
    $stmt->execute([$invoiceId, $tenantId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        return ['success' => false, 'message' => 'Invoice lama helin'];
    }

    $paidFromReceipts = getInvoicePaidAmountFromReceipts($pdo, $tenantId, $invoiceId);
    $paidFromReceipts = min($paidFromReceipts, (float)($invoice['total_amount'] ?? 0));

    $statusInvoice = $invoice;
    $statusInvoice['paid_amount'] = $paidFromReceipts;
    $newStatus = invoiceStatusCodeForDatabase($statusInvoice);

    $upd = $pdo->prepare("
        UPDATE invoices
        SET paid_amount = ?, status = ?, updated_at = NOW()
        WHERE id = ? AND tenant_id = ?
    ");
    $upd->execute([$paidFromReceipts, $newStatus, $invoiceId, $tenantId]);

    return [
        'success' => true,
        'paid_amount' => $paidFromReceipts,
        'status' => $newStatus,
        'due_amount' => max(0, (float)$invoice['total_amount'] - $paidFromReceipts)
    ];
}

function syncInvoiceListPaymentFromReceipts(PDO $pdo, int $tenantId, array $invoices): array {
    foreach ($invoices as &$invoice) {
        if (!empty($invoice['id'])) {
            $sync = syncInvoicePaymentFromReceipts($pdo, $tenantId, (int)$invoice['id']);
            if (!empty($sync['success'])) {
                $invoice['paid_amount'] = $sync['paid_amount'];
                $invoice['status'] = $sync['status'];
            }
        }
    }
    unset($invoice);
    return $invoices;
}


function formatWhatsAppShortError($result): string {
    $raw = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE);

    if (stripos($raw, 'QUOTE_ALLOWED') !== false ||
        stripos($raw, 'CORRESPONDENTS_QUOTE_EXCEEDED') !== false ||
        stripos($raw, 'Monthly quota has been exceeded') !== false ||
        stripos($raw, 'quota') !== false) {
        return 'WhatsApp quota wuu dhammaaday. Business plan u beddel ama number allowed ah isticmaal.';
    }

    if (stripos($raw, 'not authorized') !== false || stripos($raw, 'Unauthorized') !== false) {
        return 'WhatsApp lama dirin: GreenAPI login/QR lama authorize-gareyn.';
    }

    if (stripos($raw, 'curl') !== false || stripos($raw, 'Could not resolve') !== false || stripos($raw, 'timed out') !== false) {
        return 'WhatsApp lama dirin: internet/cURL server-ka hubi.';
    }

    if (is_array($result) && !empty($result['message'])) {
        $msg = trim((string)$result['message']);
        return mb_strlen($msg) > 90 ? mb_substr($msg, 0, 90) . '...' : $msg;
    }

    return 'WhatsApp lama dirin. GreenAPI hubi.';
}

function appendWhatsAppAlertText(string $baseMessage, array $waResult): string {
    if (!empty($waResult['success'])) {
        return $baseMessage . ' WhatsApp waa la diray.';
    }
    return $baseMessage . ' ' . formatWhatsAppShortError($waResult);
}



function normalizeInvoicePhoneGreenAPI($phone): string {
    $phone = preg_replace('/\D/', '', (string)$phone);
    if ($phone === '') return '';
    if (strlen($phone) === 9 && in_array($phone[0], ['6', '7'], true)) return '252' . $phone;
    if (strlen($phone) === 10 && $phone[0] === '0') return '252' . substr($phone, 1);
    if (strlen($phone) === 12 && substr($phone, 0, 3) === '252') return $phone;
    return '252' . ltrim($phone, '0');
}

function sendInvoiceWhatsAppGreenAPI($phone, string $message): array {
    $formattedPhone = normalizeInvoicePhoneGreenAPI($phone);
    if ($formattedPhone === '') {
        return ['success' => false, 'message' => 'Telefoon sax ah lama helin'];
    }

    if (!function_exists('curl_init')) {
        return ['success' => false, 'message' => 'PHP cURL extension lama shidin. XAMPP/php.ini ka fur extension=curl'];
    }

    if (!defined('GREEN_API_ID') || !defined('GREEN_API_TOKEN') || GREEN_API_ID === '' || GREEN_API_TOKEN === '') {
        return ['success' => false, 'message' => 'GREEN_API_ID ama GREEN_API_TOKEN lama dejin'];
    }

    $payload = [
        'chatId' => $formattedPhone . '@c.us',
        'message' => $message
    ];

    // GreenAPI endpoint is normally sendMessage. Some old examples used SendMessage, so we try both.
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
            'url' => $url,
            'chatId' => $payload['chatId'],
            'api_response' => $decoded ?: $response
        ];

        if ($httpCode === 200 && isset($decoded['idMessage'])) {
            return [
                'success' => true,
                'message' => 'WhatsApp automatic waa la diray',
                'message_id' => $decoded['idMessage'],
                'endpoint' => $endpoint,
                'chatId' => $payload['chatId'],
                'api_response' => $decoded
            ];
        }
    }

    if ($lastResponse) {
        $lastResponse['raw_message'] = $lastResponse['message'] ?? '';
        $lastResponse['message'] = formatWhatsAppShortError($lastResponse);
        return $lastResponse;
    }

    return ['success' => false, 'message' => 'WhatsApp lama dirin. GreenAPI hubi.'];
}

function buildSomaliInvoiceWhatsAppMessage(array $invoice, array $tenantInfo): string {
    $customerName = $invoice['customer_name'] ?? 'Macaamiil';
    $invoiceNumber = $invoice['invoice_number'] ?? '-';
    $totalAmount = '$' . number_format((float)($invoice['total_amount'] ?? 0), 2);
    $paidAmount = '$' . number_format((float)($invoice['paid_amount'] ?? 0), 2);
    $dueAmount = '$' . number_format(max(0, (float)($invoice['total_amount'] ?? 0) - (float)($invoice['paid_amount'] ?? 0)), 2);
    $dueDate = !empty($invoice['due_date']) ? date('d/m/Y', strtotime($invoice['due_date'])) : '-';

    $message  = "Macmiil {$customerName}\n";
    $message .= "Invoice: {$invoiceNumber}\n";
    $message .= "Total: {$totalAmount}\n";
    $message .= "Paid: {$paidAmount}\n";
    $message .= "Balance: {$dueAmount}\n";
    $message .= "Due: {$dueDate}";
    return $message;
}

function sendAutomaticInvoiceWhatsApp(PDO $pdo, int $tenantId, int $invoiceId, string $type = 'invoice_auto'): array {
    ensureInvoiceWhatsAppLogTable($pdo);

    $stmt = $pdo->prepare("SELECT i.*, c.customer_name, c.phone AS customer_phone, tt.trip_number
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        LEFT JOIN trucking_trips tt ON i.trip_id = tt.id
        WHERE i.id = ? AND i.tenant_id = ? LIMIT 1");
    $stmt->execute([$invoiceId, $tenantId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) return ['success' => false, 'message' => 'Invoice lama helin'];
    if (empty($invoice['customer_phone'])) return ['success' => false, 'message' => 'Customer-ka telefoon kuma jiro'];

    $tenantInfo = getInvoiceTenantInfo($pdo, $tenantId);
    $message = buildSomaliInvoiceWhatsAppMessage($invoice, $tenantInfo);
    // Direct GreenAPI call: this makes it truly automatic, not a wa.me/manual browser link.
    $result = sendInvoiceWhatsAppGreenAPI($invoice['customer_phone'], $message);

    try {
        $log = $pdo->prepare("INSERT INTO whatsapp_invoice_logs
            (tenant_id, invoice_id, customer_id, phone, message, send_status, api_response, reminder_type, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $log->execute([
            $tenantId,
            $invoiceId,
            $invoice['customer_id'] ?? null,
            $invoice['customer_phone'],
            $message,
            !empty($result['success']) ? 'sent' : 'failed',
            json_encode($result, JSON_UNESCAPED_UNICODE),
            $type
        ]);
    } catch (Throwable $e) {}

    return $result;
}

function exportInvoicesCSV(PDO $pdo, int $tenantId, string $tenantName): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="invoices_export_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Company', $tenantName]);
    fputcsv($out, ['Generated', date('Y-m-d H:i:s')]);
    fputcsv($out, []);
    fputcsv($out, ['invoice_number','customer_phone','customer_name','invoice_date','due_date','trip_number','subtotal','commission_amount','trucking_cost','handling_cost','tax_rate','discount','discount_type','total_cbm','notes','status','total_amount','paid_amount']);

    $stmt = $pdo->prepare("SELECT i.*, c.customer_name, c.phone AS customer_phone, tt.trip_number
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        LEFT JOIN trucking_trips tt ON i.trip_id = tt.id
        WHERE i.tenant_id = ?
        ORDER BY i.created_at DESC, i.id DESC");
    $stmt->execute([$tenantId]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $r['invoice_number'] ?? '',
            $r['customer_phone'] ?? '',
            $r['customer_name'] ?? '',
            $r['invoice_date'] ?? '',
            $r['due_date'] ?? '',
            $r['trip_number'] ?? '',
            number_format((float)($r['subtotal'] ?? 0), 2, '.', ''),
            number_format((float)($r['commission_amount'] ?? 0), 2, '.', ''),
            number_format((float)($r['trucking_cost'] ?? 0), 2, '.', ''),
            number_format((float)($r['handling_cost'] ?? 0), 2, '.', ''),
            number_format((float)($r['tax_rate'] ?? 0), 2, '.', ''),
            number_format((float)($r['discount'] ?? 0), 2, '.', ''),
            $r['discount_type'] ?? 'fixed',
            number_format((float)($r['total_cbm'] ?? 0), 2, '.', ''),
            $r['notes'] ?? '',
            $r['status'] ?? 'unpaid',
            number_format((float)($r['total_amount'] ?? 0), 2, '.', ''),
            number_format((float)($r['paid_amount'] ?? 0), 2, '.', '')
        ]);
    }
    fclose($out);
    exit;
}

function downloadInvoiceImportTemplate(): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="invoice_import_template.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['customer_phone','customer_name','invoice_number','invoice_date','due_date','subtotal','commission_amount','trucking_cost','handling_cost','tax_rate','discount','discount_type','total_cbm','notes','send_whatsapp']);
    fputcsv($out, ['25261XXXXXXX','Ahmed Mohamed','',date('Y-m-d'),date('Y-m-d', strtotime('+30 days')),'100','0','0','0','0','0','fixed','0','Imported invoice','yes']);
    fclose($out);
    exit;
}

function importInvoicesFromCSV(PDO $pdo, int $tenantId, int $userId, array $file): array {
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new Exception('CSV file lama helin');
    }

    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) throw new Exception('CSV file lama furi karo');

    $header = fgetcsv($handle);
    if (!$header) throw new Exception('CSV header lama helin');
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $map = array_flip(array_map('trim', $header));

    $required = ['customer_phone','customer_name','invoice_date','due_date','subtotal'];
    foreach ($required as $col) {
        if (!isset($map[$col])) throw new Exception("Column-ka '{$col}' waa qasab");
    }

    $summary = ['inserted' => 0, 'updated' => 0, 'whatsapp_sent' => 0, 'whatsapp_failed' => 0, 'errors' => []];

    while (($row = fgetcsv($handle)) !== false) {
        if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue;
        try {
            $get = function($key, $default = '') use ($row, $map) {
                return isset($map[$key]) ? trim((string)($row[$map[$key]] ?? $default)) : $default;
            };
            $phone = $get('customer_phone');
            $customerName = $get('customer_name');
            if ($phone === '' || $customerName === '') throw new Exception('customer_phone iyo customer_name waa qasab');

            $stmt = $pdo->prepare("SELECT id FROM customers WHERE tenant_id = ? AND phone = ? LIMIT 1");
            $stmt->execute([$tenantId, $phone]);
            $customerId = $stmt->fetchColumn();
            if (!$customerId) {
                $stmt = $pdo->prepare("INSERT INTO customers (tenant_id, customer_name, phone, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$tenantId, $customerName, $phone]);
                $customerId = (int)$pdo->lastInsertId();
            }

            $invoiceNumber = $get('invoice_number');
            if ($invoiceNumber === '') {
                $invoiceNumber = 'INV-' . date('YmdHis') . '-' . random_int(1000, 9999);
            }
            $invoiceDate = $get('invoice_date', date('Y-m-d')) ?: date('Y-m-d');
            $dueDate = $get('due_date', date('Y-m-d', strtotime('+30 days'))) ?: date('Y-m-d', strtotime('+30 days'));
            $subtotal = (float)str_replace(',', '.', $get('subtotal', '0'));
            $commission = (float)str_replace(',', '.', $get('commission_amount', '0'));
            $trucking = (float)str_replace(',', '.', $get('trucking_cost', '0'));
            $handling = (float)str_replace(',', '.', $get('handling_cost', '0'));
            $taxRate = (float)str_replace(',', '.', $get('tax_rate', '0'));
            $discount = (float)str_replace(',', '.', $get('discount', '0'));
            $discountType = $get('discount_type', 'fixed') ?: 'fixed';
            $totalCbm = (float)str_replace(',', '.', $get('total_cbm', '0'));
            $notes = $get('notes', '');
            $sendWhatsapp = strtolower($get('send_whatsapp', 'yes')) !== 'no';

            $baseTotal = $subtotal + $commission + $trucking + $handling;
            $taxAmount = $baseTotal * ($taxRate / 100);
            $discountAmount = $discountType === 'percentage' ? $baseTotal * ($discount / 100) : $discount;
            $totalAmount = max(0, $baseTotal + $taxAmount - $discountAmount);
            $statusForImport = invoiceStatusCodeForDatabase(['total_amount' => $totalAmount, 'paid_amount' => 0, 'due_date' => $dueDate]);

            $check = $pdo->prepare("SELECT id, total_amount FROM invoices WHERE tenant_id = ? AND invoice_number = ? LIMIT 1");
            $check->execute([$tenantId, $invoiceNumber]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $diff = $totalAmount - (float)$existing['total_amount'];
                $upd = $pdo->prepare("UPDATE invoices SET
                    customer_id = :customer_id,
                    invoice_date = :invoice_date,
                    due_date = :due_date,
                    subtotal = :subtotal,
                    commission_amount = :commission_amount,
                    trucking_cost = :trucking_cost,
                    handling_cost = :handling_cost,
                    tax = :tax,
                    tax_rate = :tax_rate,
                    discount = :discount,
                    discount_type = :discount_type,
                    total_amount = :total_amount,
                    total_cbm = :total_cbm,
                    notes = :notes,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id AND tenant_id = :tenant_id");
                $upd->execute([
                    ':customer_id' => $customerId,
                    ':invoice_date' => $invoiceDate,
                    ':due_date' => $dueDate,
                    ':subtotal' => $baseTotal,
                    ':commission_amount' => $commission,
                    ':trucking_cost' => $trucking,
                    ':handling_cost' => $handling,
                    ':tax' => $taxAmount,
                    ':tax_rate' => $taxRate,
                    ':discount' => $discount,
                    ':discount_type' => $discountType,
                    ':total_amount' => $totalAmount,
                    ':total_cbm' => $totalCbm,
                    ':notes' => $notes,
                    ':status' => $statusForImport,
                    ':id' => $existing['id'],
                    ':tenant_id' => $tenantId
                ]);
                $pdo->prepare("UPDATE customers SET debt_amount = GREATEST(COALESCE(debt_amount,0) + ?, 0), updated_at = NOW() WHERE id = ? AND tenant_id = ?")->execute([$diff, $customerId, $tenantId]);
                $invoiceId = (int)$existing['id'];
                $summary['updated']++;
            } else {
                $ins = $pdo->prepare("INSERT INTO invoices (
                    tenant_id, customer_id, invoice_number, invoice_date, due_date,
                    subtotal, commission_amount, trucking_cost, handling_cost, tax, tax_rate,
                    discount, discount_type, total_amount, paid_amount, total_cbm, notes,
                    status, created_by, created_at
                ) VALUES (
                    :tenant_id, :customer_id, :invoice_number, :invoice_date, :due_date,
                    :subtotal, :commission_amount, :trucking_cost, :handling_cost, :tax, :tax_rate,
                    :discount, :discount_type, :total_amount, 0, :total_cbm, :notes,
                    :status, :created_by, NOW()
                )");
                $ins->execute([
                    ':tenant_id' => $tenantId,
                    ':customer_id' => $customerId,
                    ':invoice_number' => $invoiceNumber,
                    ':invoice_date' => $invoiceDate,
                    ':due_date' => $dueDate,
                    ':subtotal' => $baseTotal,
                    ':commission_amount' => $commission,
                    ':trucking_cost' => $trucking,
                    ':handling_cost' => $handling,
                    ':tax' => $taxAmount,
                    ':tax_rate' => $taxRate,
                    ':discount' => $discount,
                    ':discount_type' => $discountType,
                    ':total_amount' => $totalAmount,
                    ':total_cbm' => $totalCbm,
                    ':notes' => $notes,
                    ':status' => $statusForImport,
                    ':created_by' => $userId
                ]);
                $invoiceId = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE customers SET debt_amount = COALESCE(debt_amount,0) + ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?")->execute([$totalAmount, $customerId, $tenantId]);
                $summary['inserted']++;
            }

            if ($sendWhatsapp) {
                $wa = sendAutomaticInvoiceWhatsApp($pdo, $tenantId, $invoiceId, 'invoice_import');
                if (!empty($wa['success'])) $summary['whatsapp_sent']++; else $summary['whatsapp_failed']++;
            }
        } catch (Throwable $e) {
            $summary['errors'][] = $e->getMessage();
        }
    }

    fclose($handle);
    return $summary;
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    exportInvoicesCSV($pdo, (int)$session_tenant_id, $tenant_name);
}

if (isset($_GET['template']) && $_GET['template'] === 'invoice_import') {
    downloadInvoiceImportTemplate();
}

// Get customers for this tenant
$customers = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.customer_name, c.phone, c.email, c.debt_amount
        FROM customers c
        WHERE c.tenant_id = ? AND c.is_active = 1
        ORDER BY c.customer_name
    ");
    $stmt->execute([$session_tenant_id]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customers = [];
}

// Get trips for this tenant
$trips = [];
try {
    $stmt = $pdo->prepare("
        SELECT tt.id, tt.trip_number, tt.total_cbm, c.container_number
        FROM trucking_trips tt
        LEFT JOIN containers c ON tt.container_id = c.id
        WHERE tt.tenant_id = ?
        ORDER BY tt.created_at DESC
        LIMIT 500
    ");
    $stmt->execute([$session_tenant_id]);
    $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $trips = [];
}

// Get tenant sequence for invoice number generation
$sequence = null;
try {
    $stmt = $pdo->prepare("SELECT prefix, current_number, padding FROM tenant_sequences WHERE tenant_id = ? AND sequence_name = 'invoice'");
    $stmt->execute([$session_tenant_id]);
    $sequence = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $sequence = null;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];

    // Accept decimal points/comma decimals from all forms.
    function decimal_input($key, $default = 0) {
        $value = $_POST[$key] ?? $default;
        if (is_array($value)) {
            return $default;
        }
        $value = str_replace(',', '.', trim((string)$value));
        return is_numeric($value) ? (float)$value : (float)$default;
    }
    
    if ($action === 'import_invoices') {
        try {
            $summary = importInvoicesFromCSV($pdo, (int)$session_tenant_id, (int)$_SESSION['user_id'], $_FILES['csv_file'] ?? []);
            echo json_encode([
                'success' => true,
                'message' => "Import complete: {$summary['inserted']} cusub, {$summary['updated']} update. WhatsApp: {$summary['whatsapp_sent']} diray, {$summary['whatsapp_failed']} fashilmay.",
                'summary' => $summary
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'customer_search') {
        $q = trim((string)($_POST['q'] ?? ''));
        $limit = 20;
        if ($q === '') {
            echo json_encode(['success' => true, 'customers' => []]);
            exit;
        }
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare("\n            SELECT id, customer_name, phone, email, COALESCE(debt_amount, 0) AS debt_amount\n            FROM customers\n            WHERE tenant_id = ?\n              AND COALESCE(is_active, 1) = 1\n              AND (id = ? OR customer_name LIKE ? OR phone LIKE ?)\n            ORDER BY customer_name ASC\n            LIMIT {$limit}\n        ");
        $stmt->execute([$session_tenant_id, ctype_digit($q) ? (int)$q : 0, $like, $like]);
        echo json_encode(['success' => true, 'customers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'quick_add_customer') {
        $name = $_POST['customer_name'];
        $phone = $_POST['phone'];
        $email = $_POST['email'];
        $address = $_POST['address'];
        $tenant_id = $session_tenant_id;
        
        $stmt = $pdo->prepare("INSERT INTO customers (tenant_id, customer_name, phone, email, address, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        if ($stmt->execute([$tenant_id, $name, $phone, $email, $address])) {
            $new_id = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'id' => $new_id, 'name' => $name, 'phone' => $phone]);
        } else { 
            echo json_encode(['success' => false, 'message' => 'Failed to save customer']); 
        }
        exit;
    }
    
    if ($action === 'get_invoices') {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $search = $_POST['search'] ?? '';
        $customer_filter = isset($_POST['customer']) ? (int)$_POST['customer'] : 0;
        $status_filter = $_POST['status'] ?? 'all';
        $date_from = $_POST['date_from'] ?? '';
        $date_to = $_POST['date_to'] ?? '';
        
        $where_conditions = ["i.tenant_id = ?"];
        $params = [$session_tenant_id];
        
        if (!empty($search)) {
            $where_conditions[] = "(i.invoice_number LIKE ? OR c.customer_name LIKE ? OR tt.trip_number LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($customer_filter > 0) {
            $where_conditions[] = "i.customer_id = ?";
            $params[] = $customer_filter;
        }
        
        if ($status_filter !== 'all') {
            $statusWhere = invoiceStatusWhereClause($status_filter, $params);
            if ($statusWhere) {
                $where_conditions[] = "($statusWhere)";
            }
        }
        
        if (!empty($date_from)) {
            $where_conditions[] = "DATE(i.invoice_date) >= ?";
            $params[] = $date_from;
        }
        
        if (!empty($date_to)) {
            $where_conditions[] = "DATE(i.invoice_date) <= ?";
            $params[] = $date_to;
        }
        
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
        
        $count_sql = "SELECT COUNT(*) as total FROM invoices i
                      LEFT JOIN customers c ON i.customer_id = c.id
                      LEFT JOIN trucking_trips tt ON i.trip_id = tt.id
                      $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_invoices = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_invoices / $limit);
        
        $sql = "
            SELECT i.*, 
                   c.customer_name, c.phone as customer_phone, c.email as customer_email, c.debt_amount,
                   (SELECT SUM(total_amount) FROM invoices WHERE customer_id = c.id AND tenant_id = ?) as total_invoiced_all,
                   (SELECT COALESCE(SUM(amount), 0) FROM receipts WHERE invoice_id = i.id AND tenant_id = ?) as total_paid_all,
                   tt.trip_number,
                   u.full_name as created_by_name
            FROM invoices i
            LEFT JOIN customers c ON i.customer_id = c.id
            LEFT JOIN trucking_trips tt ON i.trip_id = tt.id
            LEFT JOIN users u ON i.created_by = u.id
            $where_clause
            ORDER BY i.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $full_params = array_merge([$session_tenant_id, $session_tenant_id], $params);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($full_params);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $invoices = syncInvoiceListPaymentFromReceipts($pdo, (int)$session_tenant_id, $invoices);
        
        ob_start(); ?>
        <div style="overflow-x: auto; width: 100%;">
            <table class="invoices-table" style="min-width: 1300px; width: 100%;">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Invoice #</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Trip</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Due</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($invoices) > 0): ?>
                        <?php foreach ($invoices as $invoice): 
                            $dueAmount = (float)$invoice['total_amount'] - (float)$invoice['paid_amount'];
                            $statusInfo = invoiceStatusInfo($invoice);
                            $statusClass = 'status-' . $statusInfo['code'];
                            $statusText = $statusInfo['en'];
                            $isOverdue = ($statusInfo['code'] === 'overdue');
                        ?>
                            <tr class="<?= $isOverdue ? 'overdue-row' : '' ?>">
                                <td><?= $invoice['id'] ?></td>
                                <td><strong><?= htmlspecialchars($invoice['invoice_number']) ?></strong><div style="font-size: 10px;">Due: <?= date('d/m/Y', strtotime($invoice['due_date'])) ?></div></td>
                                <td><?= date('d/m/Y', strtotime($invoice['invoice_date'])) ?> </td>
                                <td><strong><?= htmlspecialchars($invoice['customer_name'] ?? '-') ?></strong><div style="font-size: 11px;"><?= htmlspecialchars($invoice['customer_phone'] ?? '-') ?></div> </td>
                                <td><?= htmlspecialchars($invoice['trip_number'] ?? '-') ?> </td>
                                <td><strong>$<?= number_format($invoice['total_amount'], 2) ?></strong> </td>
                                <td>$<?= number_format($invoice['paid_amount'], 2) ?> </td>
                                <td><strong class="<?= $dueAmount > 0 ? 'text-danger' : 'text-success' ?>">$<?= number_format($dueAmount, 2) ?></strong> </td>
                                <td><span class="status-badge <?= $statusClass ?>"><?= $statusText ?></span><?= $isOverdue ? '<div style="font-size: 10px; color: #B42318;"><i class="fas fa-exclamation-triangle"></i> Overdue</div>' : '' ?> </td>
                                <td><div class="action-buttons">
                                    <button class="action-btn btn-view view-invoice" data-id="<?= $invoice['id'] ?>"><i class="fas fa-eye"></i></button>
                                    <button class="action-btn btn-edit edit-invoice" data-id="<?= $invoice['id'] ?>"><i class="fas fa-edit"></i></button>
                                    <button class="action-btn btn-payment add-payment" data-id="<?= $invoice['id'] ?>" data-number="<?= htmlspecialchars($invoice['invoice_number']) ?>" data-due="<?= $dueAmount ?>"><i class="fas fa-money-bill-wave"></i></button>
                                    <?php if ($invoice['paid_amount'] > 0 && $invoice['status'] != 'refunded'): ?>
                                    <button class="action-btn btn-refund refund-invoice" data-id="<?= $invoice['id'] ?>" data-number="<?= htmlspecialchars($invoice['invoice_number']) ?>" data-paid="<?= $invoice['paid_amount'] ?>"><i class="fas fa-undo-alt"></i></button>
                                    <?php endif; ?>
                                    <button class="action-btn btn-whatsapp whatsapp-invoice" 
                                        data-id="<?= $invoice['id'] ?>"
                                        data-phone="<?= htmlspecialchars($invoice['customer_phone'] ?? '') ?>" 
                                        data-number="<?= htmlspecialchars($invoice['invoice_number']) ?>" 
                                        data-amount="<?= number_format((float)($invoice['total_amount'] ?? 0), 2) ?>" 
                                        data-due="<?= number_format((float)$dueAmount, 2) ?>"
                                        data-total-debt="<?= number_format((float)($invoice['debt_amount'] ?? 0), 2) ?>"
                                        data-total-invoiced="<?= number_format((float)($invoice['total_invoiced_all'] ?? 0), 2) ?>"
                                        data-total-paid-all="<?= number_format((float)($invoice['total_paid_all'] ?? 0), 2) ?>"
                                        data-tenant="<?= htmlspecialchars($tenant_name) ?>"
                                        data-name="<?= htmlspecialchars($invoice['customer_name'] ?? 'Customer') ?>"><i class="fab fa-whatsapp"></i></button>
                                    <button class="action-btn btn-print print-invoice" data-id="<?= $invoice['id'] ?>"><i class="fas fa-print"></i></button>
                                    <button class="action-btn btn-delete delete-invoice" data-id="<?= $invoice['id'] ?>" data-name="<?= htmlspecialchars($invoice['invoice_number']) ?>"><i class="fas fa-trash"></i></button>
                                </div> </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="10" style="text-align: center; padding: 50px;"><div class="empty-state"><i class="fas fa-file-invoice"></i><p>No invoices found</p><button class="btn-primary-custom" id="addInvoiceBtnEmpty"><i class="fas fa-plus-circle"></i> New Invoice</button></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        $table_html = ob_get_clean();
        
        ob_start();
        if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?><a data-page="<?= $page-1 ?>">Previous</a><?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?><span class="active"><?= $i ?></span><?php else: ?><a data-page="<?= $i ?>"><?= $i ?></a><?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?><a data-page="<?= $page+1 ?>">Next</a><?php endif; ?>
            </div>
        <?php endif;
        $pagination_html = ob_get_clean();
        
        ob_clean();
        echo json_encode(['table_html' => $table_html, 'pagination_html' => $pagination_html]);
        exit;
    }
    
    elseif ($action === 'get_invoice') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("
            SELECT i.*, 
                   c.customer_name, c.phone as customer_phone, c.email as customer_email, c.debt_amount,
                   tt.trip_number, tt.total_cbm,
                   u.full_name as created_by_name
            FROM invoices i
            LEFT JOIN customers c ON i.customer_id = c.id
            LEFT JOIN trucking_trips tt ON i.trip_id = tt.id
            LEFT JOIN users u ON i.created_by = u.id
            WHERE i.id = ? AND i.tenant_id = ?
        ");
        $stmt->execute([$id, $session_tenant_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($invoice) {
            $stmtItems = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
            $stmtItems->execute([$id]);
            $invoice['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode($invoice);
        exit;
    }
    
    // ADD PAYMENT TO INVOICE
    elseif ($action === 'add_payment') {
        $invoice_id = !empty($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0;
        $amount = decimal_input('amount');
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $reference_number = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if (!$invoice_id) {
            echo json_encode(['success' => false, 'message' => 'Invoice not found']);
            exit;
        }
        
        if ($amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Please enter the payment amount']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Get invoice details
            $invStmt = $pdo->prepare("
                SELECT i.*, c.id as customer_id, c.debt_amount, c.customer_name,
                       i.tenant_id, i.invoice_number, i.total_amount, i.paid_amount
                FROM invoices i
                LEFT JOIN customers c ON i.customer_id = c.id
                WHERE i.id = ? AND i.tenant_id = ?
            ");
            $invStmt->execute([$invoice_id, $session_tenant_id]);
            $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invoice) {
                echo json_encode(['success' => false, 'message' => 'Invoice not found']);
                exit;
            }
            
            $due_amount = $invoice['total_amount'] - $invoice['paid_amount'];
            
            if ($amount > $due_amount) {
                echo json_encode(['success' => false, 'message' => "Payment amount ($$amount) exceeds due amount ($$due_amount)"]);
                exit;
            }
            
            // Generate payment number
            $payment_number = 'PMT-' . date('Ymd') . '-' . rand(1000, 9999);
            $check = $pdo->prepare("SELECT id FROM payments WHERE payment_number = ?");
            $check->execute([$payment_number]);
            while ($check->fetch()) {
                $payment_number = 'PMT-' . date('Ymd') . '-' . rand(1000, 9999);
                $check->execute([$payment_number]);
            }
            
            // Insert payment record
            $payStmt = $pdo->prepare("
                INSERT INTO payments (tenant_id, payment_number, customer_id, invoice_id, amount, payment_date, 
                payment_method, reference_number, notes, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $payStmt->execute([
                $session_tenant_id, $payment_number, $invoice['customer_id'], $invoice_id, 
                $amount, $payment_date, $payment_method, $reference_number, $notes, $_SESSION['user_id']
            ]);
            
            $new_payment_id = $pdo->lastInsertId();
            
            // Update invoice paid amount
            $new_paid_amount = $invoice['paid_amount'] + $amount;
            $statusCalcInvoice = $invoice;
            $statusCalcInvoice['paid_amount'] = $new_paid_amount;
            $new_status = invoiceStatusCodeForDatabase($statusCalcInvoice);
            $updateInv = $pdo->prepare("UPDATE invoices SET paid_amount = ?, status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
            $updateInv->execute([$new_paid_amount, $new_status, $invoice_id, $session_tenant_id]);
            
            // Update customer debt
            $updateDebt = $pdo->prepare("UPDATE customers SET debt_amount = GREATEST(debt_amount - ?, 0), updated_at = NOW() WHERE id = ? AND tenant_id = ?");
            $updateDebt->execute([$amount, $invoice['customer_id'], $session_tenant_id]);
            
            // Add to cash_flow as inflow
            $cashStmt = $pdo->prepare("INSERT INTO cash_flow (tenant_id, flow_date, inflow, description, created_at) VALUES (?, ?, ?, ?, NOW())");
            $cashStmt->execute([$session_tenant_id, $payment_date, $amount, "Payment for invoice: {$invoice['invoice_number']} - {$invoice['customer_name']}"]);
            
            // Add to receipts
            $receipt_number = 'RCP-' . date('Ymd') . '-' . rand(1000, 9999);
            $rcpStmt = $pdo->prepare("
                INSERT INTO receipts (tenant_id, receipt_number, invoice_id, customer_id, amount, payment_date, payment_method, reference_number, notes, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $rcpStmt->execute([
                $session_tenant_id, $receipt_number, $invoice_id, $invoice['customer_id'],
                $amount, $payment_date, $payment_method, $reference_number, $notes, $_SESSION['user_id']
            ]);
            $new_receipt_id = $pdo->lastInsertId();
            
            // Add to debt_collection_log
            $logStmt = $pdo->prepare("
                INSERT INTO debt_collection_log (tenant_id, customer_id, invoice_id, action_type, amount_collected, notes, collected_by, created_at) 
                VALUES (?, ?, ?, 'payment_received', ?, ?, ?, NOW())
            ");
            $logStmt->execute([
                $session_tenant_id, $invoice['customer_id'], $invoice_id,
                $amount, "Payment received for invoice {$invoice['invoice_number']} - Amount: $$amount", $_SESSION['user_id']
            ]);

            // ERP INTEGRATION: POST TO LEDGER (if class exists)
            if (class_exists('AccountingService')) {
                $accounting = new AccountingService($pdo, $session_tenant_id, $_SESSION['user_id']);
                $accounting->journalizeReceipt($new_receipt_id);
            }
            
            LogAudit($pdo, 'ADD_PAYMENT', 'payments', $new_payment_id, null, ['amount' => $amount, 'invoice' => $invoice['invoice_number']]);

            $pdo->commit();

            $waResult = sendAutomaticInvoiceWhatsApp($pdo, (int)$session_tenant_id, (int)$invoice_id, 'invoice_payment_' . $new_status);
            $waText = !empty($waResult['success']) ? ' WhatsApp waa la diray.' : ' ' . formatWhatsAppShortError($waResult);
            
            echo json_encode([
                'success' => true, 
                'message' => "Payment of $$amount recorded successfully for invoice {$invoice['invoice_number']}!" . $waText,
                'new_paid' => $new_paid_amount,
                'new_status' => $new_status,
                'due_remaining' => $invoice['total_amount'] - $new_paid_amount,
                'whatsapp' => $waResult
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // REFUND / CREDIT NOTE - LACAG CELIN
    elseif ($action === 'refund_payment') {
        $invoice_id = !empty($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0;
        $refund_amount = decimal_input('refund_amount');
        $refund_reason = trim($_POST['refund_reason'] ?? 'Customer refund');
        $refund_date = $_POST['refund_date'] ?? date('Y-m-d');
        
        if (!$invoice_id) {
            echo json_encode(['success' => false, 'message' => 'Invoice not found']);
            exit;
        }
        
        if ($refund_amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Please enter the refund amount']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Get invoice details
            $invStmt = $pdo->prepare("
                SELECT i.*, c.id as customer_id, c.debt_amount, c.customer_name,
                       i.tenant_id, i.invoice_number, i.total_amount, i.paid_amount
                FROM invoices i
                LEFT JOIN customers c ON i.customer_id = c.id
                WHERE i.id = ? AND i.tenant_id = ?
            ");
            $invStmt->execute([$invoice_id, $session_tenant_id]);
            $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invoice) {
                echo json_encode(['success' => false, 'message' => 'Invoice not found']);
                exit;
            }
            
            if ($refund_amount > $invoice['paid_amount']) {
                echo json_encode(['success' => false, 'message' => "Refund amount ($$refund_amount) exceeds paid amount ($$invoice[paid_amount])"]);
                exit;
            }
            
            // Generate refund/credit note number
            $refund_number = 'CRN-' . date('Ymd') . '-' . rand(1000, 9999);
            $check = $pdo->prepare("SELECT id FROM receipts WHERE receipt_number = ?");
            $check->execute([$refund_number]);
            while ($check->fetch()) {
                $refund_number = 'CRN-' . date('Ymd') . '-' . rand(1000, 9999);
                $check->execute([$refund_number]);
            }
            
            // Create a negative receipt for refund
            $rcpStmt = $pdo->prepare("
                INSERT INTO receipts (tenant_id, receipt_number, invoice_id, customer_id, amount, payment_date, payment_method, reference_number, notes, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'refund', ?, ?, ?, NOW())
            ");
            $rcpStmt->execute([
                $session_tenant_id, $refund_number, $invoice_id, $invoice['customer_id'],
                -$refund_amount, $refund_date, "REFUND-" . $refund_number, $refund_reason, $_SESSION['user_id']
            ]);
            $new_refund_id = $pdo->lastInsertId();
            
            // Update invoice paid amount
            $new_paid_amount = $invoice['paid_amount'] - $refund_amount;
            $statusCalcInvoice = $invoice;
            $statusCalcInvoice['paid_amount'] = max(0, $new_paid_amount);
            $new_status = invoiceStatusCodeForDatabase($statusCalcInvoice);
            
            $updateInv = $pdo->prepare("UPDATE invoices SET paid_amount = ?, status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
            $updateInv->execute([max(0, $new_paid_amount), $new_status, $invoice_id, $session_tenant_id]);
            
            // Update customer debt (increase since we're refunding)
            $updateDebt = $pdo->prepare("UPDATE customers SET debt_amount = debt_amount + ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
            $updateDebt->execute([$refund_amount, $invoice['customer_id'], $session_tenant_id]);
            
            // Add to cash_flow as outflow (negative inflow)
            $cashStmt = $pdo->prepare("INSERT INTO cash_flow (tenant_id, flow_date, outflow, description, created_at) VALUES (?, ?, ?, ?, NOW())");
            $cashStmt->execute([$session_tenant_id, $refund_date, $refund_amount, "Refund for invoice: {$invoice['invoice_number']} - {$invoice['customer_name']}"]);
            
            // Add to debt_collection_log as refund
            $logStmt = $pdo->prepare("
                INSERT INTO debt_collection_log (tenant_id, customer_id, invoice_id, action_type, amount_collected, notes, collected_by, created_at) 
                VALUES (?, ?, ?, 'refund_issued', ?, ?, ?, NOW())
            ");
            $logStmt->execute([
                $session_tenant_id, $invoice['customer_id'], $invoice_id,
                -$refund_amount, "Refund issued for invoice {$invoice['invoice_number']} - Amount: $$refund_amount - Reason: $refund_reason", $_SESSION['user_id']
            ]);

            // ERP INTEGRATION
            if (class_exists('AccountingService')) {
                $accounting = new AccountingService($pdo, $session_tenant_id, $_SESSION['user_id']);
                $accounting->journalizeRefund($new_refund_id);
            }
            
            LogAudit($pdo, 'REFUND_PAYMENT', 'receipts', $new_refund_id, null, ['amount' => $refund_amount, 'invoice' => $invoice['invoice_number']]);

            $pdo->commit();

            $waResult = sendAutomaticInvoiceWhatsApp($pdo, (int)$session_tenant_id, (int)$invoice_id, 'invoice_refund_' . $new_status);
            $waText = !empty($waResult['success']) ? ' WhatsApp waa la diray.' : ' ' . formatWhatsAppShortError($waResult);
            
            echo json_encode([
                'success' => true, 
                'message' => "Refund of $$refund_amount processed successfully for invoice {$invoice['invoice_number']}!" . $waText,
                'new_paid' => max(0, $new_paid_amount),
                'new_status' => $new_status,
                'refund_number' => $refund_number,
                'whatsapp' => $waResult
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'check_tenant_complete') {
        // For tenant_admin, tenant is always valid
        $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? AND is_active = 1");
        $stmt->execute([$session_tenant_id]);
        $tenant_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tenant_data) {
            echo json_encode(['success' => false, 'complete' => false, 'message' => 'Company not found or inactive']);
            exit;
        }
        
        // Auto-fix missing code
        if (empty($tenant_data['code'])) {
            $newCode = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $tenant_data['name']), 0, 3));
            if (empty($newCode)) $newCode = 'INV';
            $upd = $pdo->prepare("UPDATE tenants SET code = ? WHERE id = ?");
            $upd->execute([$newCode, $session_tenant_id]);
            $tenant_data['code'] = $newCode;
        }

        // Auto-fix missing address
        if (empty($tenant_data['address'])) {
            $upd = $pdo->prepare("UPDATE tenants SET address = 'Mogadishu, Somalia' WHERE id = ?");
            $upd->execute([$session_tenant_id]);
            $tenant_data['address'] = 'Mogadishu, Somalia';
        }

        // Auto-initialize sequence if missing
        $seqStmt = $pdo->prepare("SELECT * FROM tenant_sequences WHERE tenant_id = ? AND sequence_name = 'invoice'");
        $seqStmt->execute([$session_tenant_id]);
        $sequence_data = $seqStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sequence_data) {
            $prefix = $tenant_data['code'] ?: 'INV';
            $ins = $pdo->prepare("INSERT INTO tenant_sequences (tenant_id, sequence_name, prefix, current_number, padding) VALUES (?, 'invoice', ?, 1, 5)");
            $ins->execute([$session_tenant_id, $prefix]);
            
            // Re-fetch
            $seqStmt->execute([$session_tenant_id]);
            $sequence_data = $seqStmt->fetch(PDO::FETCH_ASSOC);
        }
        
        echo json_encode([
            'success' => true, 
            'complete' => true, 
            'message' => 'Company is ready', 
            'tenant' => $tenant_data, 
            'sequence' => $sequence_data
        ]);
        exit;
    }
    
    elseif ($action === 'save_invoice') {
        $id = $_POST['invoice_id'] ?? '';
        $tenant_id = $session_tenant_id;
        $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $trip_id = !empty($_POST['trip_id']) ? (int)$_POST['trip_id'] : null;
        $invoice_number = trim($_POST['invoice_number'] ?? '');
        $invoice_date = $_POST['invoice_date'] ?? date('Y-m-d');
        $due_date = $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
        
        $subtotal = decimal_input('subtotal');
        $commission_amount = decimal_input('commission_amount');
        $trucking_cost = decimal_input('trucking_cost');
        $handling_cost = decimal_input('handling_cost');
        $tax_rate = decimal_input('tax_rate');
        $discount = decimal_input('discount');
        $discount_type = $_POST['discount_type'] ?? 'fixed';
        $notes = trim($_POST['notes'] ?? '');
        $total_cbm = decimal_input('total_cbm');

        // Verify customer belongs to this tenant
        $checkCustomer = $pdo->prepare("SELECT id FROM customers WHERE id = ? AND tenant_id = ?");
        $checkCustomer->execute([$customer_id, $tenant_id]);
        if (!$checkCustomer->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Customer not found or does not belong to your company']);
            exit;
        }

        // Sum up line items for true subtotal
        $items_total = 0;
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $itemQtys = $_POST['qtys'] ?? [];
            $itemRates = $_POST['rates'] ?? [];
            for ($i = 0; $i < count($_POST['items']); $i++) {
                $items_total += (float)str_replace(',', '.', ($itemQtys[$i] ?? 0)) * (float)str_replace(',', '.', ($itemRates[$i] ?? 0));
            }
        }
        
        $base_total = $items_total + $subtotal + $commission_amount + $trucking_cost + $handling_cost;
        $tax_amount = $base_total * ($tax_rate / 100);
        $discount_amount = ($discount_type === 'percentage') ? $base_total * ($discount / 100) : $discount;
        $total_amount = $base_total + $tax_amount - $discount_amount;
        
        if (empty($invoice_number)) {
            echo json_encode(['success' => false, 'message' => 'Invoice number is required']);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $statusCalcForSave = ['total_amount' => $total_amount, 'paid_amount' => 0, 'due_date' => $due_date];
            $calculatedStatus = invoiceStatusCodeForDatabase($statusCalcForSave);

            if (empty($id)) {
                // Check if number already exists
                $check = $pdo->prepare("SELECT id FROM invoices WHERE invoice_number = ? AND tenant_id = ?");
                $check->execute([$invoice_number, $tenant_id]);
                if ($check->fetch()) {
                    $invoice_number .= '-' . rand(10, 99);
                }

                $sql = "INSERT INTO invoices (
                            tenant_id, customer_id, trip_id, invoice_number, invoice_date, due_date,
                            subtotal, commission_amount, trucking_cost, handling_cost, tax, tax_rate,
                            discount, discount_type, total_amount, paid_amount, total_cbm, notes,
                            status, created_by, created_at
                        ) VALUES (
                            :tenant_id, :customer_id, :trip_id, :invoice_number, :invoice_date, :due_date,
                            :subtotal, :commission_amount, :trucking_cost, :handling_cost, :tax, :tax_rate,
                            :discount, :discount_type, :total_amount, 0, :total_cbm, :notes,
                            :status, :created_by, NOW()
                        )";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':tenant_id' => $tenant_id,
                    ':customer_id' => $customer_id,
                    ':trip_id' => $trip_id,
                    ':invoice_number' => $invoice_number,
                    ':invoice_date' => $invoice_date,
                    ':due_date' => $due_date,
                    ':subtotal' => $base_total,
                    ':commission_amount' => $commission_amount,
                    ':trucking_cost' => $trucking_cost,
                    ':handling_cost' => $handling_cost,
                    ':tax' => $tax_amount,
                    ':tax_rate' => $tax_rate,
                    ':discount' => $discount,
                    ':discount_type' => $discount_type,
                    ':total_amount' => $total_amount,
                    ':total_cbm' => $total_cbm,
                    ':notes' => $notes,
                    ':status' => $calculatedStatus,
                    ':created_by' => $_SESSION['user_id']
                ]);
                
                $id = $pdo->lastInsertId();

                // ERP Integration
                if (class_exists('AccountingService')) {
                    $accounting = new AccountingService($pdo, $tenant_id, $_SESSION['user_id']);
                    $accounting->journalizeInvoice($id);
                }

                // Update customer debt
                $updateDebt = $pdo->prepare("UPDATE customers SET debt_amount = debt_amount + ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
                $updateDebt->execute([$total_amount, $customer_id, $tenant_id]);
                
                $message = "Invoice '$invoice_number' has been created!";
            } else {
                // Verify invoice belongs to this tenant
                $checkInvoice = $pdo->prepare("SELECT total_amount FROM invoices WHERE id = ? AND tenant_id = ?");
                $checkInvoice->execute([$id, $tenant_id]);
                $oldInv = $checkInvoice->fetch();
                
                if (!$oldInv) {
                    echo json_encode(['success' => false, 'message' => 'Invoice not found or you do not have permission']);
                    exit;
                }
                
                $diff = $total_amount - ($oldInv['total_amount'] ?? 0);
                
                $sql = "UPDATE invoices SET
                            customer_id = :customer_id,
                            trip_id = :trip_id,
                            invoice_number = :invoice_number,
                            invoice_date = :invoice_date,
                            due_date = :due_date,
                            subtotal = :subtotal,
                            commission_amount = :commission_amount,
                            trucking_cost = :trucking_cost,
                            handling_cost = :handling_cost,
                            tax = :tax,
                            tax_rate = :tax_rate,
                            discount = :discount,
                            discount_type = :discount_type,
                            total_amount = :total_amount,
                            total_cbm = :total_cbm,
                            notes = :notes,
                            status = :status,
                            updated_at = NOW()
                        WHERE id = :id AND tenant_id = :tenant_id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':customer_id' => $customer_id,
                    ':trip_id' => $trip_id,
                    ':invoice_number' => $invoice_number,
                    ':invoice_date' => $invoice_date,
                    ':due_date' => $due_date,
                    ':subtotal' => $base_total,
                    ':commission_amount' => $commission_amount,
                    ':trucking_cost' => $trucking_cost,
                    ':handling_cost' => $handling_cost,
                    ':tax' => $tax_amount,
                    ':tax_rate' => $tax_rate,
                    ':discount' => $discount,
                    ':discount_type' => $discount_type,
                    ':total_amount' => $total_amount,
                    ':total_cbm' => $total_cbm,
                    ':notes' => $notes,
                    ':status' => $calculatedStatus,
                    ':id' => $id,
                    ':tenant_id' => $tenant_id
                ]);
                
                // Update customer debt with difference
                $updateDebt = $pdo->prepare("UPDATE customers SET debt_amount = debt_amount + ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
                $updateDebt->execute([$diff, $customer_id, $tenant_id]);

                // Clear old items for this invoice
                $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$id]);
                
                $message = "Invoice '$invoice_number' has been updated!";
            }

            // Save line items (Shared for both insert and update)
            if (isset($_POST['items']) && is_array($_POST['items'])) {
                $itemNames = $_POST['items'];
                $itemDescs = $_POST['descriptions'] ?? [];
                $itemQtys = $_POST['qtys'] ?? [];
                $itemRates = $_POST['rates'] ?? [];

                $itemStmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_name, description, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
                for ($i = 0; $i < count($itemNames); $i++) {
                    $name = trim($itemNames[$i]);
                    if (empty($name)) continue;
                    $qty = (float)str_replace(',', '.', ($itemQtys[$i] ?? 0));
                    $rate = (float)str_replace(',', '.', ($itemRates[$i] ?? 0));
                    $itemStmt->execute([$id, $name, ($itemDescs[$i] ?? ''), $qty, $rate, ($qty * $rate)]);
                }
            }

            $pdo->commit();

            $waResult = sendAutomaticInvoiceWhatsApp($pdo, (int)$tenant_id, (int)$id, empty($_POST['invoice_id']) ? 'invoice_created' : 'invoice_updated');
            $waText = !empty($waResult['success']) ? ' WhatsApp waa la diray.' : ' ' . formatWhatsAppShortError($waResult);
            echo json_encode(['success' => true, 'message' => $message . $waText, 'whatsapp' => $waResult]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // DELETE INVOICE - FIXED VERSION (works with tables that may not have invoice_id column)
    elseif ($action === 'delete_invoice') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Invalid invoice ID']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Get invoice details
            $stmt = $pdo->prepare("SELECT invoice_number, customer_id, tenant_id, total_amount, paid_amount FROM invoices WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $session_tenant_id]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invoice) {
                echo json_encode(['success' => false, 'message' => 'Invoice not found']);
                exit;
            }

            // Calculate remaining debt to reduce from customer
            $debtToReduce = $invoice['total_amount'] - $invoice['paid_amount'];
            
            // Delete from debt_collection_log (using invoice_id column)
            try {
                $deleteLogs = $pdo->prepare("DELETE FROM debt_collection_log WHERE invoice_id = ?");
                $deleteLogs->execute([$id]);
            } catch (PDOException $e) {
                // Column might not exist, try alternative
            }
            
            // Delete from debt_follow_ups (using invoice_id column)
            try {
                $deleteFollowups = $pdo->prepare("DELETE FROM debt_follow_ups WHERE invoice_id = ?");
                $deleteFollowups->execute([$id]);
            } catch (PDOException $e) {}
            
            // Delete from overdue_alerts (using invoice_id column)
            try {
                $deleteAlerts = $pdo->prepare("DELETE FROM overdue_alerts WHERE invoice_id = ?");
                $deleteAlerts->execute([$id]);
            } catch (PDOException $e) {}
            
            // Delete receipts
            $deleteReceipts = $pdo->prepare("DELETE FROM receipts WHERE invoice_id = ?");
            $deleteReceipts->execute([$id]);
            
            // Delete payments
            $deletePayments = $pdo->prepare("DELETE FROM payments WHERE invoice_id = ?");
            $deletePayments->execute([$id]);
            
            // Delete invoice items
            $deleteItems = $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
            $deleteItems->execute([$id]);
            
            // Update customer debt (decrease by the remaining due amount)
            if ($debtToReduce > 0) {
                $updateDebt = $pdo->prepare("UPDATE customers SET debt_amount = GREATEST(debt_amount - ?, 0), updated_at = NOW() WHERE id = ? AND tenant_id = ?");
                $updateDebt->execute([$debtToReduce, $invoice['customer_id'], $session_tenant_id]);
            }
            
            // Delete the invoice
            $stmt = $pdo->prepare("DELETE FROM invoices WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $session_tenant_id]);
            
            // Log the deletion
            LogAudit($pdo, 'DELETE_INVOICE', 'invoices', $id, null, ['invoice_number' => $invoice['invoice_number']]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => "Invoice '{$invoice['invoice_number']}' has been deleted successfully!"]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'get_stats') {
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total_invoices,
                SUM(CASE WHEN paid_calc >= total_amount AND total_amount > 0 THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN paid_calc <= 0 AND total_amount > 0 AND (due_date IS NULL OR due_date = '0000-00-00' OR DATE(due_date) >= ?) THEN 1 ELSE 0 END) AS unpaid_count,
                SUM(CASE WHEN paid_calc > 0 AND paid_calc < total_amount AND (due_date IS NULL OR due_date = '0000-00-00' OR DATE(due_date) >= ?) THEN 1 ELSE 0 END) AS partial_count,
                SUM(CASE WHEN paid_calc < total_amount AND due_date IS NOT NULL AND due_date <> '0000-00-00' AND DATE(due_date) < ? THEN 1 ELSE 0 END) AS overdue_count,
                COALESCE(SUM(total_amount),0) AS total_amount,
                COALESCE(SUM(paid_calc),0) AS total_paid,
                COALESCE(SUM(GREATEST(total_amount - paid_calc, 0)),0) AS total_due
            FROM (
                SELECT
                    i.id,
                    i.total_amount,
                    i.due_date,
                    LEAST(
                        COALESCE(i.total_amount,0),
                        GREATEST(COALESCE((SELECT SUM(r.amount) FROM receipts r WHERE r.tenant_id = i.tenant_id AND r.invoice_id = i.id), 0), 0)
                    ) AS paid_calc
                FROM invoices i
                WHERE i.tenant_id = ?
            ) x
        ");
        $stmt->execute([$today, $today, $today, $session_tenant_id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        $monthlyStmt = $pdo->prepare("
            SELECT DATE_FORMAT(invoice_date, '%Y-%m') as month, COUNT(*) as count, SUM(total_amount) as total
            FROM invoices WHERE tenant_id = ? GROUP BY DATE_FORMAT(invoice_date, '%Y-%m') ORDER BY month DESC LIMIT 6
        ");
        $monthlyStmt->execute([$session_tenant_id]);
        $monthly = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);

        ob_clean();
        echo json_encode(['stats' => $stats, 'monthly' => $monthly]);
        exit;
    }

    elseif ($action === 'generate_invoice_number') {
        $year = date('Y');
        $month = date('m');
        
        $seqStmt = $pdo->prepare("SELECT prefix, current_number, padding FROM tenant_sequences WHERE tenant_id = ? AND sequence_name = 'invoice'");
        $seqStmt->execute([$session_tenant_id]);
        $sequence = $seqStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($sequence) {
            $prefix = $sequence['prefix'] ?: 'INV';
            $current = $sequence['current_number'];
            $padding = $sequence['padding'];
            
            $updateStmt = $pdo->prepare("UPDATE tenant_sequences SET current_number = current_number + 1 WHERE tenant_id = ? AND sequence_name = 'invoice'");
            $updateStmt->execute([$session_tenant_id]);
            
            $number = str_pad($current, $padding, '0', STR_PAD_LEFT);
            $invoice_number = $prefix . $year . $month . '-' . $number;
            echo json_encode(['success' => true, 'invoice_number' => $invoice_number]);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'No invoice sequence found for this company', 'no_sequence' => true]);
            exit;
        }
    }
    
    elseif ($action === 'get_trip_details') {
        $trip_id = (int)($_POST['trip_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, trip_number, total_cbm FROM trucking_trips WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$trip_id, $session_tenant_id]);
        $trip = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($trip);
        exit;
    }
    
    elseif ($action === 'get_customer_stock_total') {
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        if ($customer_id > 0) {
            $stmt = $pdo->prepare("SELECT SUM(quantity * unit_price) as total_stock_value, SUM(volume_cbm) as total_cbm FROM warehouse_stock WHERE customer_id = ? AND tenant_id = ?");
            $stmt->execute([$customer_id, $session_tenant_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode([
                'success' => true, 
                'total_value' => (float)$result['total_stock_value'],
                'total_cbm' => (float)$result['total_cbm']
            ]);
        } else {
            echo json_encode(['success' => false, 'total_value' => 0, 'total_cbm' => 0]);
        }
        exit;
    }

    elseif ($action === 'get_tenant_customers') {
        $stmt = $pdo->prepare("SELECT id, customer_name, phone FROM customers WHERE tenant_id = ? AND is_active = 1 ORDER BY customer_name");
        $stmt->execute([$session_tenant_id]);
        $customers_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($customers_list);
        exit;
    }
    
    elseif ($action === 'get_tenant_trips') {
        $stmt = $pdo->prepare("SELECT id, trip_number FROM trucking_trips WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 100");
        $stmt->execute([$session_tenant_id]);
        $trips_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($trips_list);
        exit;
    }

    elseif ($action === 'get_invoices_by_customer') {
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, invoice_number, (total_amount - paid_amount) as due_amount FROM invoices WHERE customer_id = ? AND tenant_id = ? AND status IN ('unpaid', 'partial') ORDER BY created_at DESC");
        $stmt->execute([$customer_id, $session_tenant_id]);
        $invoices_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['invoices' => $invoices_list]);
        exit;
    }
    
    elseif ($action === 'send_invoice_whatsapp') {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invoice ID lama helin']);
            exit;
        }
        $result = sendAutomaticInvoiceWhatsApp($pdo, (int)$session_tenant_id, $invoiceId, 'invoice_manual_button');
        if (empty($result['success'])) {
            $result['message'] = formatWhatsAppShortError($result);
        }
        echo json_encode($result);
        exit;
    }

    elseif ($action === 'send_whatsapp_api') {
        $phone = $_POST['phone'] ?? '';
        $message = $_POST['message'] ?? '';
        
        if (empty($phone) || empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Phone and message are required!']);
            exit;
        }

        $result = sendInvoiceWhatsAppGreenAPI($phone, $message);
        if (empty($result['success'])) {
            $result['message'] = formatWhatsAppShortError($result);
        }
        echo json_encode($result);
        exit;
    }
    
    exit;
}

// Get invoice template
$templateStmt = $pdo->prepare("SELECT message_content FROM message_templates WHERE template_key = 'invoice_new'");
$templateStmt->execute();
$invoiceTemplate = $templateStmt->fetchColumn();
if (!$invoiceTemplate) {
    $invoiceTemplate = 'Dear {customer_name},

This is a reminder regarding your account with *{tenant}*.

*Current Invoice Details:*
- Invoice Number: #{invoice_number}
- Amount Due: *${due}*
- Total Invoice Amount: ${amount}

*Account Summary:*
- Total Invoiced: ${total_invoiced}
- Total Paid: ${total_paid}
- Outstanding Balance: *${total_debt}*

Please make your payment as soon as possible. Thank you.

*Invoice Link:* {link}

Best regards,
*{tenant}*';
}

require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice Management - <?= htmlspecialchars($tenant_name) ?> | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --curdun-violet: #2D1859;
            --curdun-yellow: #F5C410;
            --curdun-violet-light: #4B2C85;
            --curdun-gray: #6b6c72;
            --curdun-dark: #393a3d;
            --curdun-success: #2ca01c;
            --curdun-danger: #B42318;
            --curdun-info: #0077c5;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f5f8; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: var(--curdun-dark); }
        
        .page-header { background: #fff; border-bottom: 1px solid #e0e1e6; padding: 20px 25px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .page-header h1 { color: var(--curdun-dark); font-size: 24px; font-weight: 700; margin: 0; }
        .page-header h1 i { color: var(--curdun-violet); margin-right: 10px; }
        .page-header .company-badge {
            background: rgba(82,0,102,0.1);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            color: var(--curdun-violet);
        }
        
        .btn-primary-custom { background: var(--curdun-violet); color: white; border: none; padding: 10px 20px; border-radius: 20px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s ease; cursor: pointer; }
        .btn-primary-custom:hover { background: var(--curdun-violet-light); transform: translateY(-1px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: white; border: 1px solid #e0e1e6; border-radius: 8px; padding: 20px; display: flex; flex-direction: column; }
        .stat-card .stat-info h4 { font-size: 13px; color: var(--curdun-gray); margin: 0 0 10px 0; font-weight: 600; text-transform: uppercase; }
        .stat-card .stat-info .stat-number { font-size: 28px; font-weight: 700; color: var(--curdun-dark); margin-bottom: 10px; }
        .stat-card-danger { border-top: 4px solid #B42318; }
        .stat-card-success { border-top: 4px solid #2ca01c; }
        
        .filters-card { background: white; border: 1px solid #e0e1e6; border-radius: 8px; padding: 20px; margin-bottom: 25px; }
        .filter-form { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 150px; }
        .filter-group label { display: block; font-size: 13px; font-weight: 600; color: var(--curdun-dark); margin-bottom: 6px; }
        .filter-group input, .filter-group select { width: 100%; padding: 10px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        .btn-filter { background: white; color: var(--curdun-dark); border: 1px solid #ccc; padding: 10px 20px; border-radius: 20px; font-weight: 600; cursor: pointer; }
        .btn-reset { background: white; color: var(--curdun-info); border: none; padding: 10px 20px; font-weight: 600; cursor: pointer; }
        
        .invoices-table-container { background: white; border: 1px solid #e0e1e6; border-radius: 8px; overflow-x: auto; width: 100%; }
        .invoices-table { width: 100%; border-collapse: collapse; min-width: 1200px; }
        .invoices-table th, .invoices-table td { padding: 15px 20px; text-align: left; border-bottom: 1px solid #e0e1e6; vertical-align: middle; }
        .invoices-table th { background: white; font-weight: 600; color: var(--curdun-gray); font-size: 13px; }
        .invoices-table tr:hover { background: #f9f9fb; }
        
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .status-paid { background: #e8f5e9; color: #108000; }
        .status-unpaid { background: #f4f5f8; color: #393a3d; }
        .status-partial { background: #e3f2fd; color: #0077c5; }
        .status-overdue { background: #fce8e6; color: #d32f2f; }
        .status-refunded { background: #fff3e0; color: #ff9800; }
        
        .action-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        .action-btn { padding: 5px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; border: none; transition: 0.3s; }
        .btn-view { background: #e3f2fd; color: #1565c0; }
        .btn-edit { background: #fff8e1; color: #f57c00; }
        .btn-payment { background: #EEFBF3; color: #0F7A3A; }
        .btn-refund { background: #fff3e0; color: #ff9800; }
        .btn-print { background: #f5f5f5; color: #424242; }
        .btn-delete { background: #FEF0EE; color: #B42318; }
        .btn-whatsapp { background: #dcf8c5; color: #25D366; }
        
        .alert-custom { position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideIn 0.3s ease; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .alert-success { background: #EEFBF3; color: #0F7A3A; border-left: 4px solid #0F7A3A; }
        .alert-error { background: #fce8e6; color: #B42318; border-left: 4px solid #B42318; }
        
        .modal-header { background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light)); color: white; border-bottom: none; border-radius: 8px 8px 0 0; }
        .modal-header .close { color: white; opacity: 0.8; }
        .btn-save-invoice { background: var(--curdun-yellow); color: #000; border: none; font-weight: 700; padding: 12px 30px; border-radius: 8px; transition: 0.3s; }
        .btn-save-invoice:hover { background: #e5cf07; transform: translateY(-1px); }
        
        .auto-number-badge { background: #f4f5f8; padding: 8px 12px; border-radius: 4px; font-size: 14px; display: inline-block; margin-bottom: 15px; border: 1px solid #e0e1e6; }
        .tenant-warning { background: #fce8e6; border-left: 4px solid #B42318; padding: 12px 15px; border-radius: 4px; margin-bottom: 15px; color: #B42318; }
        
        .line-item-table th:nth-child(3),
        .line-item-table td:nth-child(3) {
            min-width: 120px;
            width: 120px;
        }
        .line-item-table th:nth-child(4),
        .line-item-table td:nth-child(4),
        .line-item-table th:nth-child(5),
        .line-item-table td:nth-child(5) {
            min-width: 130px;
        }
        .item-qty,
        .item-rate,
        .item-amount,
        .fee-input,
        #paymentAmount,
        #refundAmount {
            min-width: 95px;
            height: 42px;
            padding: 9px 12px !important;
            font-size: 15px;
            border: 1px solid #cfd4dc;
            border-radius: 8px;
            text-align: center;
            background: #fff;
        }
        .item-qty:focus,
        .item-rate:focus,
        .item-amount:focus,
        .fee-input:focus,
        #paymentAmount:focus,
        #refundAmount:focus {
            border-color: var(--curdun-violet);
            box-shadow: 0 0 0 3px rgba(82,0,102,0.14);
            outline: none;
        }
        .item-amount {
            background: #f8f9fc;
            font-weight: 700;
        }


        .customer-search-wrap { position: relative; }
        .customer-search-input { width: 100%; height: 42px; border: 1px solid #ced4da; border-radius: 8px; padding: 9px 12px; }
        .customer-search-results {
            position: absolute; top: 100%; left: 0; right: 0; z-index: 3000;
            background: #fff; border: 1px solid #d1d5db; border-radius: 10px;
            max-height: 260px; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,.12); display: none;
        }
        .customer-search-item { padding: 10px 12px; cursor: pointer; border-bottom: 1px solid #f1f1f1; }
        .customer-search-item:hover { background: #f7f3fa; }
        .customer-search-item strong { display: block; color: var(--curdun-violet); }
        .customer-search-item small { color: #666; }
        .customer-search-empty { padding: 12px; text-align: center; }

        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: left; align-items: flex-start; }
            .filter-form { flex-direction: column; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-file-invoice"></i> Invoice Management</h1>
        <div class="d-flex gap-3 align-items-center">
            <span class="company-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($tenant_name) ?></span>
            <a href="?export=csv" class="btn-primary-custom" style="background:#2ca01c;text-decoration:none;"><i class="fas fa-file-csv"></i> Export CSV</a>
            <a href="?template=invoice_import" class="btn-primary-custom" style="background:#0077c5;text-decoration:none;"><i class="fas fa-download"></i> Template</a>
            <button type="button" class="btn-primary-custom" id="importInvoiceBtn" style="background:#ff9800;"><i class="fas fa-upload"></i> Import CSV</button>
            <button type="button" class="btn-primary-custom" id="addInvoiceBtn"><i class="fas fa-plus-circle"></i> New Invoice</button>
        </div>
    </div>

    <form id="invoiceImportForm" enctype="multipart/form-data" style="display:none;">
        <input type="file" id="invoiceCsvFile" name="csv_file" accept=".csv,text/csv">
    </form>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-info"><h4>Total Invoices</h4><div class="stat-number" id="stat-total">0</div></div></div>
        <div class="stat-card stat-card-success"><div class="stat-info"><h4>Paid</h4><div class="stat-number" id="stat-paid">0</div></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Unpaid</h4><div class="stat-number" id="stat-unpaid">0</div></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Partial</h4><div class="stat-number" id="stat-partial">0</div></div></div>
        <div class="stat-card stat-card-danger"><div class="stat-info"><h4>Overdue</h4><div class="stat-number" id="stat-overdue">0</div></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Total Amount</h4><div class="stat-number" id="stat-total-amount">$0</div></div></div>
    </div>

    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group"><label><i class="fas fa-search"></i> Search</label><input type="text" id="searchInput" placeholder="Invoice #, Customer..."></div>
            <div class="filter-group"><label><i class="fas fa-user"></i> Customer</label>
                <input type="hidden" id="customerFilter" value="0">
                <div class="customer-search-wrap">
                    <input type="text" id="customerFilterSearch" class="customer-search-input" placeholder="Search customer name or phone..." autocomplete="off">
                    <div id="customerFilterResults" class="customer-search-results"></div>
                </div>
            </div>
            <div class="filter-group"><label><i class="fas fa-tag"></i> Status</label><select id="statusFilter"><option value="all">All</option><option value="paid">Paid</option><option value="unpaid">Unpaid</option><option value="partial">Partial</option><option value="overdue">Overdue</option><option value="refunded">Refunded</option></select></div>
            <div class="filter-group"><label><i class="fas fa-calendar"></i> From</label><input type="date" id="dateFrom"></div>
            <div class="filter-group"><label><i class="fas fa-calendar"></i> To</label><input type="date" id="dateTo"></div>
            <div class="filter-group"><button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Filter</button><button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Reset</button></div>
        </div>
    </div>

    <div id="invoices-table-container"><div class="loading-spinner text-center p-5"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading invoices...</p></div></div>
    <div id="pagination-container"></div>
</div>

<!-- Invoice Modal -->
<div class="modal fade" id="invoiceModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header"><h5 class="modal-title" id="invoiceModalLabel"><i class="fas fa-file-invoice"></i> New Invoice</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
            <form id="invoiceForm"><div class="modal-body">
                <input type="hidden" name="invoice_id" id="invoice_id">
                <div id="tenantValidationWarning" class="tenant-warning" style="display: none;"><i class="fas fa-exclamation-triangle"></i> <span id="tenantWarningMessage"></span></div>
                <div class="alert alert-info auto-number-badge"><i class="fas fa-magic"></i> Invoice Number: <strong id="autoInvoiceNumber">-</strong><input type="hidden" name="invoice_number" id="modalInvoiceNumber"></div>
                <div class="row">
                    <div class="col-md-6"><div class="form-group"><label>Customer <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="hidden" name="customer_id" id="modalCustomerId" required>
                            <input type="text" id="modalCustomerSearch" class="form-control customer-search-input" placeholder="Search customer name or phone..." autocomplete="off">
                            <div id="modalCustomerResults" class="customer-search-results"></div>
                            <div class="input-group-append"><button type="button" class="btn btn-outline-secondary" id="quickAddCustomerBtn" title="Add New Customer">+</button></div>
                        </div>
                    </div></div>
                    <div class="col-md-6"><div class="form-group"><label>Trip (Optional)</label><select name="trip_id" id="modalTripId" class="form-control"><option value="">Select Trip...</option><?php foreach ($trips as $tr): ?><option value="<?= $tr['id'] ?>"><?= htmlspecialchars($tr['trip_number']) ?> (<?= $tr['total_cbm'] ?> CBM)</option><?php endforeach; ?></select></div></div>
                    <div class="col-md-6"><div class="form-group"><label>Invoice Date</label><input type="date" name="invoice_date" id="modalInvoiceDate" class="form-control" value="<?= date('Y-m-d') ?>"></div></div>
                    <div class="col-md-6"><div class="form-group"><label>Due Date</label><input type="date" name="due_date" id="modalDueDate" class="form-control" value="<?= date('Y-m-d', strtotime('+30 days')) ?>"></div></div>
                    
                    <div class="col-md-12 mt-3">
                        <div class="section-title mb-2" style="font-size: 14px; font-weight: 700; color: var(--curdun-violet);">
                            <i class="fas fa-list"></i> Invoice Items
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered line-item-table" id="lineItemTable">
                                <thead>
                                    <tr>
                                        <th style="width: 25%;">Item</th>
                                        <th style="width: 35%;">Description</th>
                                        <th style="width: 120px; min-width: 120px;">Qty</th>
                                        <th style="width: 130px; min-width: 130px;">Rate</th>
                                        <th style="width: 130px; min-width: 130px;">Total</th>
                                        <th style="width: 50px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="lineItemBody">
                                    <tr>
                                        <td><input type="text" name="items[]" class="form-control form-control-sm" placeholder="Item name"></td>
                                        <td><input type="text" name="descriptions[]" class="form-control form-control-sm" placeholder="Description..."></td>
                                        <td><input type="text" inputmode="decimal" name="qtys[]" class="form-control form-control-sm item-qty" value="1" placeholder="0.00"></td>
                                        <td><input type="text" inputmode="decimal" name="rates[]" class="form-control form-control-sm item-rate" value="0.00" placeholder="0.00"></td>
                                        <td><input type="text" inputmode="decimal" class="form-control form-control-sm item-amount" value="0.00" readonly></td>
                                        <td class="text-center"><i class="fas fa-times remove-line"></i></td>
                                    </tr>
                                </tbody>
                            </table>
                            <button type="button" class="add-line-btn" id="addLineBtn"><i class="fas fa-plus"></i> Add Item</button>
                        </div>
                    </div>

                    <div class="col-md-12 mt-3">
                        <hr>
                        <div class="section-title mb-2" style="font-size: 14px; font-weight: 700; color: var(--curdun-violet);">
                            <i class="fas fa-plus-circle"></i> Other Fees
                        </div>
                        <div class="row">
                            <div class="col-md-3"><div class="form-group"><label>Freight (CBM)</label><input type="number" step="any" inputmode="decimal" name="subtotal" id="modalSubtotal" class="form-control fee-input" value="0.00"></div></div>
                            <div class="col-md-3"><div class="form-group"><label>Commission</label><input type="number" step="any" inputmode="decimal" name="commission_amount" id="modalCommission" class="form-control fee-input" value="0.00"></div></div>
                            <div class="col-md-3"><div class="form-group"><label>Trucking Cost</label><input type="number" step="any" inputmode="decimal" name="trucking_cost" id="modalTrucking" class="form-control fee-input" value="0.00"></div></div>
                            <div class="col-md-3"><div class="form-group"><label>Handling Cost</label><input type="number" step="any" inputmode="decimal" name="handling_cost" id="modalHandling" class="form-control fee-input" value="0.00"></div></div>
                        </div>
                    </div>

                    <div class="col-md-7 mt-4"><div class="form-group"><label>Notes (Internal)</label><textarea name="notes" id="modalNotes" class="form-control" rows="3"></textarea></div></div>
                    
                    <div class="col-md-5 mt-4">
                        <div class="invoice-summary-box" style="background: #f8f9fc; border-radius: 12px; padding: 20px;">
                            <div class="d-flex justify-content-between mb-2"><span>Subtotal:</span><strong id="displaySubtotal">$0.00</strong></div>
                            <div class="d-flex justify-content-between mb-2"><span>Tax (%):</span><input type="number" step="any" inputmode="decimal" name="tax_rate" id="modalTaxRate" class="form-control form-control-sm text-right fee-input" style="width: 80px;" value="0"></div>
                            <div class="d-flex justify-content-between mb-2"><span>Discount:</span>
                                <div class="input-group input-group-sm" style="width: 150px;">
                                    <input type="number" step="any" inputmode="decimal" name="discount" id="modalDiscount" class="form-control text-right fee-input" value="0">
                                    <select name="discount_type" id="modalDiscountType" class="form-control fee-input"><option value="fixed">$</option><option value="percentage">%</option></select>
                                </div>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between" style="font-size: 20px; font-weight: 800; color: var(--curdun-violet);"><span>Total ($):</span><span id="totalAmountDisplay">0.00</span></div>
                            <input type="hidden" name="total_amount" id="modalTotalAmount" value="0">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="submit" class="btn-save-invoice" id="saveInvoiceBtn">Save Invoice</button>
            </div>
        </form>
        </div>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content" style="border-radius: 16px;"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-file-invoice"></i> Invoice Details</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body" id="viewModalBody"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button><button type="button" class="btn btn-primary" id="printInvoiceBtn"><i class="fas fa-print"></i> Print</button></div></div></div></div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="fas fa-money-bill-wave"></i> Record Payment</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <form id="paymentForm"><div class="modal-body">
                <input type="hidden" name="invoice_id" id="paymentInvoiceId">
                <p><strong>Invoice:</strong> <span id="paymentInvoiceNumber"></span></p>
                <p><strong>Due Amount:</strong> $<span id="paymentDueAmount">0.00</span></p>
                <div class="form-group"><label>Payment Amount <span class="text-danger">*</span></label><input type="number" step="any" inputmode="decimal" name="amount" id="paymentAmount" class="form-control" required></div>
                <div class="form-group"><label>Payment Date</label><input type="date" name="payment_date" id="paymentDate" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                <div class="form-group"><label>Payment Method</label><select name="payment_method" id="paymentMethod" class="form-control"><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option><option value="check">Check</option><option value="mobile_money">Mobile Money</option></select></div>
                <div class="form-group"><label>Reference Number</label><input type="text" name="reference_number" id="paymentReference" class="form-control" placeholder="Transaction ID, Check No."></div>
                <div class="form-group"><label>Notes</label><textarea name="notes" id="paymentNotes" class="form-control" rows="2"></textarea></div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success">Save Payment</button></div></form>
        </div>
    </div>
</div>

<!-- Refund Modal - LACAG CELIN -->
<div class="modal fade" id="refundModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header" style="background: #ff9800; color: white;"><h5 class="modal-title"><i class="fas fa-undo-alt"></i> Issue Refund / Credit Note</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <form id="refundForm"><div class="modal-body">
                <input type="hidden" name="invoice_id" id="refundInvoiceId">
                <p><strong>Invoice:</strong> <span id="refundInvoiceNumber"></span></p>
                <p><strong>Amount Paid:</strong> $<span id="refundPaidAmount">0.00</span></p>
                <div class="form-group"><label>Refund Amount <span class="text-danger">*</span></label><input type="number" step="any" inputmode="decimal" name="refund_amount" id="refundAmount" class="form-control" required></div>
                <div class="form-group"><label>Refund Date</label><input type="date" name="refund_date" id="refundDate" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                <div class="form-group"><label>Refund Reason</label><textarea name="refund_reason" id="refundReason" class="form-control" rows="3" placeholder="Reason for refund..."></textarea></div>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> This will create a credit note and increase the customer's outstanding balance by the refund amount.
                </div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning">Issue Refund</button></div></form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="fas fa-trash"></i> Confirm Delete</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <div class="modal-body">
                <p>Are you sure you want to delete invoice <strong id="deleteInvoiceName"></strong>?</p>
                <p class="text-danger">This action cannot be undone! All related payments, receipts, and collection logs will be deleted.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" id="confirmDeleteBtn">Yes, Delete</button></div>
        </div>
    </div>
</div>

<!-- Quick Add Customer Modal -->
<div class="modal fade" id="quickAddCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="fas fa-user-plus"></i> Quick Add Customer</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <form id="quickAddCustomerForm"><div class="modal-body">
                <div class="form-group"><label>Customer Name <span class="text-danger">*</span></label><input type="text" name="customer_name" class="form-control" required></div>
                <div class="form-group"><label>Phone Number <span class="text-danger">*</span></label><input type="text" name="phone" class="form-control" required></div>
                <div class="form-group"><label>Email (Optional)</label><input type="email" name="email" class="form-control"></div>
                <div class="form-group"><label>Address</label><input type="text" name="address" class="form-control" value="Mogadishu"></div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success">Save Customer</button></div></form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const invoiceTemplateStr = <?= json_encode($invoiceTemplate) ?>;

$(document).ready(function() {
    let currentPage = 1;

    function cleanDecimal(value) {
        if (value === null || value === undefined) return '';
        value = String(value).trim().replace(/,/g, '.').replace(/[^0-9.]/g, '');
        const parts = value.split('.');
        if (parts.length > 2) {
            value = parts[0] + '.' + parts.slice(1).join('');
        }
        return value;
    }

    function toDecimal(value) {
        const cleaned = cleanDecimal(value);
        const parsed = parseFloat(cleaned);
        return isNaN(parsed) ? 0 : parsed;
    }

    $(document).on('input', '.item-qty, .item-rate, .fee-input, #paymentAmount, #refundAmount', function() {
        const cleaned = cleanDecimal(this.value);
        if (this.value !== cleaned) {
            this.value = cleaned;
        }
    });
    let deleteId = null;
    let refundId = null;
    let tenantValid = true;

    let customerSearchTimers = {};
    const customerSelectedLabels = {};

    function customerLabel(customer) {
        const phone = customer.phone ? ' - ' + customer.phone : '';
        const debt = Number(customer.debt_amount || 0).toFixed(2);
        return `${customer.customer_name}${phone} | Balance $${debt}`;
    }

    function renderCustomerResults(resultBox, hiddenInput, searchInput, customers, allowAll) {
        let html = '';
        if (allowAll) {
            html += `<div class="customer-search-item" data-id="0" data-name="All Customers" data-phone="" data-debt="0"><strong>All Customers</strong><small>Dhammaan customers</small></div>`;
        }
        if (customers.length) {
            customers.forEach(c => {
                html += `<div class="customer-search-item" data-id="${c.id}" data-name="${escapeHtml(c.customer_name)}" data-phone="${escapeHtml(c.phone || '')}" data-debt="${Number(c.debt_amount || 0).toFixed(2)}">
                    <strong>${escapeHtml(c.customer_name)}</strong>
                    <small>${escapeHtml(c.phone || '-')} | Balance $${Number(c.debt_amount || 0).toFixed(2)}</small>
                </div>`;
            });
        } else if (!allowAll) {
            html = `<div class="customer-search-empty">Customer lama helin<br><button type="button" class="btn btn-sm btn-success mt-2" id="quickAddCustomerFromSearch">Add Customer</button></div>`;
        }
        resultBox.html(html).show();
    }

    function setupCustomerSearch(config) {
        const searchInput = $(config.search);
        const hiddenInput = $(config.hidden);
        const resultBox = $(config.results);
        const allowAll = !!config.allowAll;

        searchInput.on('focus input', function() {
            const q = $.trim(searchInput.val());
            clearTimeout(customerSearchTimers[config.search]);
            customerSearchTimers[config.search] = setTimeout(function() {
                if (allowAll && q === '') {
                    renderCustomerResults(resultBox, hiddenInput, searchInput, [], true);
                    return;
                }
                if (q.length < 1) {
                    resultBox.hide().empty();
                    return;
                }
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    dataType: 'json',
                    data: { ajax_action: 'customer_search', q: q },
                    success: function(res) {
                        renderCustomerResults(resultBox, hiddenInput, searchInput, res.customers || [], allowAll);
                    },
                    error: function() {
                        resultBox.html('<div class="customer-search-empty text-danger">Search error</div>').show();
                    }
                });
            }, 250);
        });

        resultBox.on('click', '.customer-search-item', function() {
            const id = $(this).data('id');
            const name = $(this).data('name');
            const phone = $(this).data('phone') || '';
            const debt = $(this).data('debt') || '0.00';
            hiddenInput.val(id).trigger('change');
            const label = id == 0 ? 'All Customers' : `${name}${phone ? ' - ' + phone : ''} | Balance $${debt}`;
            searchInput.val(label);
            customerSelectedLabels[config.hidden] = label;
            resultBox.hide().empty();
            if (typeof config.onSelect === 'function') config.onSelect({id, name, phone, debt});
        });

        searchInput.on('blur', function() {
            setTimeout(function() {
                resultBox.hide();
                const currentId = hiddenInput.val();
                if (currentId && customerSelectedLabels[config.hidden]) {
                    searchInput.val(customerSelectedLabels[config.hidden]);
                }
            }, 180);
        });
    }

    function setSelectedCustomerForInvoice(customer) {
        if (!customer || !customer.id) return;
        $('#modalCustomerId').val(customer.id).trigger('change');
        const label = customerLabel(customer);
        $('#modalCustomerSearch').val(label);
        customerSelectedLabels['#modalCustomerId'] = label;
    }

    function selectInvoiceCustomerById(customerId) {
        if (!customerId) return;
        $.ajax({
            url: window.location.href,
            type: 'POST',
            dataType: 'json',
            data: { ajax_action: 'customer_search', q: String(customerId) },
            success: function(res) {
                let found = (res.customers || []).find(c => String(c.id) === String(customerId));
                if (!found && res.customers && res.customers.length === 1) found = res.customers[0];
                if (found) setSelectedCustomerForInvoice(found);
            }
        });
    }


    setupCustomerSearch({
        search: '#customerFilterSearch',
        hidden: '#customerFilter',
        results: '#customerFilterResults',
        allowAll: true,
        onSelect: function() { currentPage = 1; loadInvoices(); loadStats(); }
    });
    setupCustomerSearch({
        search: '#modalCustomerSearch',
        hidden: '#modalCustomerId',
        results: '#modalCustomerResults',
        allowAll: false,
        onSelect: function(customer) {
            $('#modalSubtotal').val('0.00');
            calculateTotal();
        }
    });

    function calculateTotal() {
        let itemsSubtotal = 0;
        
        $('.item-amount').each(function() {
            itemsSubtotal += toDecimal($(this).val());
        });

        const cbmFreight = toDecimal($('#modalSubtotal').val());
        const comm = toDecimal($('#modalCommission').val());
        const truck = toDecimal($('#modalTrucking').val());
        const handle = toDecimal($('#modalHandling').val());
        
        let subtotal = itemsSubtotal + cbmFreight + comm + truck + handle;

        const taxRate = toDecimal($('#modalTaxRate').val());
        const discount = toDecimal($('#modalDiscount').val());
        const discountType = $('#modalDiscountType').val();
        
        const tax = subtotal * (taxRate / 100);
        let discountAmount = discountType === 'percentage' ? subtotal * (discount / 100) : discount;
        const total = subtotal + tax - discountAmount;

        $('#displaySubtotal').text('$' + subtotal.toFixed(2));
        $('#totalAmountDisplay').text(total.toFixed(2));
        $('#modalTotalAmount').val(total.toFixed(2));
    }

    $(document).on('input', '.fee-input', calculateTotal);
    $(document).on('change', '.fee-input', calculateTotal);
    
    $('#addLineBtn').click(function() {
        const newRow = `
        <tr>
            <td><input type="text" name="items[]" class="form-control form-control-sm" placeholder="Item name"></td>
            <td><input type="text" name="descriptions[]" class="form-control form-control-sm" placeholder="Description..."></td>
            <td><input type="text" inputmode="decimal" name="qtys[]" class="form-control form-control-sm item-qty" value="1" placeholder="0.00"></td>
            <td><input type="text" inputmode="decimal" name="rates[]" class="form-control form-control-sm item-rate" value="0.00" placeholder="0.00"></td>
            <td><input type="text" inputmode="decimal" class="form-control form-control-sm item-amount" value="0.00" readonly></td>
            <td class="text-center"><i class="fas fa-times remove-line"></i></td>
        </tr>`;
        $('#lineItemBody').append(newRow);
    });

    $(document).on('click', '.remove-line', function() {
        if ($('#lineItemBody tr').length > 1) {
            $(this).closest('tr').remove();
            calculateTotal();

    // CLEAN IMPORT CSV HANDLER
    $('#importInvoiceBtn').off('click').on('click', function() {
        $('#invoiceCsvFile').val('').trigger('click');
    });

    $('#invoiceCsvFile').off('change').on('change', function() {
        const file = this.files[0];
        if (!file) return;
        if (!confirm('Ma hubtaa inaad import-gareyso invoices-kan? Haddii send_whatsapp=yes, WhatsApp automatic ayaa loo diri doonaa customer-ka.')) return;
        const formData = new FormData();
        formData.append('ajax_action', 'import_invoices');
        formData.append('csv_file', file);
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    showAlert('success', res.message || 'Import complete');
                    loadInvoices();
                    loadStats();
                } else {
                    showAlert('error', res.message || 'Import failed');
                }
            },
            error: function() {
                showAlert('error', 'Server error while importing CSV');
            }
        });
    });
        }
    });

    $(document).on('input', '.item-qty, .item-rate', function() {
        const row = $(this).closest('tr');
        const qty = toDecimal(row.find('.item-qty').val());
        const rate = toDecimal(row.find('.item-rate').val());
        const amount = qty * rate;
        row.find('.item-amount').val(amount.toFixed(2));
        calculateTotal();
    });

    function checkTenantComplete() {
        $.ajax({ 
            url: window.location.href, 
            type: 'POST', 
            data: { ajax_action: 'check_tenant_complete' }, 
            dataType: 'json',
            success: function(res) {
                if (res.complete === true) { 
                    $('#tenantValidationWarning').hide(); 
                    $('#saveInvoiceBtn').prop('disabled', false); 
                    tenantValid = true; 
                    generateInvoiceNumber(); 
                } else { 
                    $('#tenantWarningMessage').html(res.message); 
                    $('#tenantValidationWarning').show(); 
                    $('#saveInvoiceBtn').prop('disabled', true); 
                    tenantValid = false; 
                    $('#autoInvoiceNumber').text('Cannot generate'); 
                }
            },
            error: function() { 
                $('#tenantWarningMessage').html('Error occurred'); 
                $('#tenantValidationWarning').show(); 
                $('#saveInvoiceBtn').prop('disabled', true); 
                tenantValid = false; 
            }
        });
    }
    
    function generateInvoiceNumber() {
        if (!tenantValid) return;
        $('#autoInvoiceNumber').html('<i class="fas fa-spinner fa-spin"></i> Generating...');
        $.ajax({ 
            url: window.location.href, 
            type: 'POST', 
            data: { ajax_action: 'generate_invoice_number' }, 
            dataType: 'json',
            success: function(res) {
                if (res.success) { 
                    $('#autoInvoiceNumber').text(res.invoice_number); 
                    $('#modalInvoiceNumber').val(res.invoice_number); 
                } else { 
                    $('#autoInvoiceNumber').text('Error'); 
                    if (res.no_sequence) { 
                        $('#tenantWarningMessage').html(res.message); 
                        $('#tenantValidationWarning').show(); 
                        $('#saveInvoiceBtn').prop('disabled', true); 
                        tenantValid = false; 
                    }
                }
            },
            error: function() { $('#autoInvoiceNumber').text('Error'); }
        });
    }
    
    function loadInvoices() {
        $.ajax({ 
            url: window.location.href, 
            type: 'POST', 
            data: { 
                ajax_action: 'get_invoices', 
                page: currentPage, 
                search: $('#searchInput').val(), 
                customer: $('#customerFilter').val(), 
                status: $('#statusFilter').val(), 
                date_from: $('#dateFrom').val(), 
                date_to: $('#dateTo').val() 
            }, 
            dataType: 'json',
            success: function(response) { 
                $('#invoices-table-container').html(response.table_html); 
                $('#pagination-container').html(response.pagination_html); 
                attachTableEvents(); 
            },
            error: function() { $('#invoices-table-container').html('<div class="empty-state text-center p-5"><i class="fas fa-exclamation-triangle"></i><p>Error loading data</p></div>'); }
        });
    }

    function loadStats() {
        $.ajax({ 
            url: window.location.href, 
            type: 'POST', 
            data: { ajax_action: 'get_stats' }, 
            dataType: 'json',
            success: function(data) {
                const stats = data.stats;
                $('#stat-total').text(stats.total_invoices || 0);
                $('#stat-paid').text(stats.paid_count || 0);
                $('#stat-unpaid').text(stats.unpaid_count || 0);
                $('#stat-partial').text(stats.partial_count || 0);
                $('#stat-overdue').text(stats.overdue_count || 0);
                $('#stat-total-amount').text('$' + (parseFloat(stats.total_amount || 0).toFixed(2)));
            }
        });
    }

    function getInvoiceHTML(inv) {
        const due = inv.total_amount - inv.paid_amount;
        const statusText = { 'paid': 'Paid', 'unpaid': 'Unpaid', 'partial': 'Partial', 'overdue': 'Overdue', 'refunded': 'Refunded' }[inv.status] || inv.status;
        
        return `
        <div style="font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #393a3d; max-width: 800px; margin: 0 auto; background: white; border: 1px solid #e0e1e6; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden;">
            <div style="background: #2D1859; color: white; padding: 15px 20px;">
                <h3 style="margin: 0;">Cargo Management System</h3>
                <small>Invoice Details</small>
            </div>
            <div style="padding: 30px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 30px;">
                    <div><strong>Bill To:</strong><br>${escapeHtml(inv.customer_name || '-')}<br>${escapeHtml(inv.customer_phone || '')}</div>
                    <div style="text-align: right;"><strong>Invoice #:</strong> ${escapeHtml(inv.invoice_number)}<br><strong>Date:</strong> ${inv.invoice_date}<br><strong>Due Date:</strong> ${inv.due_date}</div>
                </div>
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
                    <thead><tr style="background: #f0f0f0;"><th style="padding: 10px; text-align: left;">Description</th><th style="padding: 10px; text-align: right;">Qty</th><th style="padding: 10px; text-align: right;">Rate</th><th style="padding: 10px; text-align: right;">Amount</th></tr></thead>
                    <tbody>
                        ${(inv.items && inv.items.length > 0) ? inv.items.map(item => `
                        <tr><td style="padding: 10px;">${escapeHtml(item.item_name)}<br><small>${escapeHtml(item.description || '')}</small></td><td style="padding: 10px; text-align: right;">${item.quantity}</td><td style="padding: 10px; text-align: right;">$${parseFloat(item.unit_price).toFixed(2)}</div><td style="padding: 10px; text-align: right;">$${parseFloat(item.total_price).toFixed(2)}</div></td>
                        `).join('') : ''}
                        ${parseFloat(inv.subtotal || 0) > 0 ? `<tr><td colspan="3" style="padding: 10px; text-align: right;">Freight (CBM):</div><td style="padding: 10px; text-align: right;">$${parseFloat(inv.subtotal).toFixed(2)}</div></tr>` : ''}
                        ${parseFloat(inv.commission_amount || 0) > 0 ? `<tr><td colspan="3" style="padding: 10px; text-align: right;">Commission:</div><td style="padding: 10px; text-align: right;">$${parseFloat(inv.commission_amount).toFixed(2)}</div></tr>` : ''}
                        ${parseFloat(inv.trucking_cost || 0) > 0 ? `<tr><td colspan="3" style="padding: 10px; text-align: right;">Trucking:</div><td style="padding: 10px; text-align: right;">$${parseFloat(inv.trucking_cost).toFixed(2)}</div></tr>` : ''}
                        ${parseFloat(inv.handling_cost || 0) > 0 ? `<tr><td colspan="3" style="padding: 10px; text-align: right;">Handling:</div><td style="padding: 10px; text-align: right;">$${parseFloat(inv.handling_cost).toFixed(2)}</div></tr>` : ''}
                        ${parseFloat(inv.tax || 0) > 0 ? `<tr><td colspan="3" style="padding: 10px; text-align: right;">Tax (${inv.tax_rate}%):</div><td style="padding: 10px; text-align: right;">$${parseFloat(inv.tax).toFixed(2)}</div></tr>` : ''}
                        ${parseFloat(inv.discount || 0) > 0 ? `<tr><td colspan="3" style="padding: 10px; text-align: right;">Discount:</div><td style="padding: 10px; text-align: right;">-$${parseFloat(inv.discount).toFixed(2)}</div></tr>` : ''}
                        <tr style="border-top: 2px solid #ddd;"><td colspan="3" style="padding: 10px; text-align: right; font-weight: bold;">Total:</div><td style="padding: 10px; text-align: right; font-weight: bold;">$${parseFloat(inv.total_amount || 0).toFixed(2)}</div></tr>
                        <tr><td colspan="3" style="padding: 10px; text-align: right;">Paid:</div><td style="padding: 10px; text-align: right;">$${parseFloat(inv.paid_amount || 0).toFixed(2)}</div></tr>
                        <tr style="background: #EEFBF3;"><td colspan="3" style="padding: 10px; text-align: right; font-weight: bold;">Balance Due:</div><td style="padding: 10px; text-align: right; font-weight: bold;">$${due.toFixed(2)}</div></tr>
                    </tbody>
                </table>
                <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 12px;">
                    Thank you for your business! Generated by Cargo Management System System.
                </div>
            </div>
        </div>
        `;
    }

    function attachTableEvents() {
        $('.view-invoice').click(function() { 
            const id = $(this).data('id'); 
            $.ajax({ 
                url: window.location.href, 
                type: 'POST', 
                data: { ajax_action: 'get_invoice', id: id }, 
                dataType: 'json', 
                success: function(inv) { 
                    $('#viewModalBody').html(getInvoiceHTML(inv)); 
                    $('#viewModal').modal('show'); 
                } 
            }); 
        });
        
        $('.edit-invoice').click(function() { 
            const id = $(this).data('id'); 
            $.ajax({ 
                url: window.location.href, 
                type: 'POST', 
                data: { ajax_action: 'get_invoice', id: id }, 
                dataType: 'json', 
                success: function(inv) { 
                    $('#invoiceModalLabel').text('Edit Invoice'); 
                    $('#invoice_id').val(inv.id); 
                    $('#modalCustomerId').val(inv.customer_id);
                    selectInvoiceCustomerById(inv.customer_id); 
                    $('#modalTripId').val(inv.trip_id); 
                    $('#modalInvoiceDate').val(inv.invoice_date); 
                    $('#modalDueDate').val(inv.due_date); 
                    $('#modalSubtotal').val(inv.subtotal); 
                    $('#modalCommission').val(inv.commission_amount); 
                    $('#modalTrucking').val(inv.trucking_cost); 
                    $('#modalHandling').val(inv.handling_cost); 
                    $('#modalTaxRate').val(inv.tax_rate); 
                    $('#modalDiscount').val(inv.discount); 
                    $('#modalDiscountType').val(inv.discount_type); 
                    $('#modalNotes').val(inv.notes); 
                    $('#autoInvoiceNumber').text(inv.invoice_number); 
                    $('#modalInvoiceNumber').val(inv.invoice_number); 
                    
                    if (inv.items && inv.items.length > 0) {
                        $('#lineItemBody').empty();
                        inv.items.forEach(item => {
                            const row = `
                            <tr>
                                <td><input type="text" name="items[]" class="form-control form-control-sm" value="${escapeHtml(item.item_name)}"></td>
                                <td><input type="text" name="descriptions[]" class="form-control form-control-sm" value="${escapeHtml(item.description || '')}"></td>
                                <td><input type="text" inputmode="decimal" name="qtys[]" class="form-control form-control-sm item-qty" value="${item.quantity}" placeholder="0.00"></td>
                                <td><input type="text" inputmode="decimal" name="rates[]" class="form-control form-control-sm item-rate" value="${item.unit_price}" placeholder="0.00"></td>
                                <td><input type="text" inputmode="decimal" class="form-control form-control-sm item-amount" value="${item.total_price}" readonly></td>
                                <td class="text-center"><i class="fas fa-times remove-line"></i></td>
                            </tr>`;
                            $('#lineItemBody').append(row);
                        });
                    }
                    
                    calculateTotal(); 
                    $('#invoiceModal').modal('show'); 
                } 
            }); 
        });
        
        $('.add-payment').click(function() { 
            const id = $(this).data('id'); 
            const number = $(this).data('number'); 
            const dueAmount = $(this).data('due'); 
            $('#paymentInvoiceId').val(id); 
            $('#paymentInvoiceNumber').text(number); 
            $('#paymentDueAmount').text(dueAmount.toFixed(2)); 
            $('#paymentAmount').val('').attr('max', dueAmount); 
            $('#paymentModal').modal('show'); 
        });
        
        $('.refund-invoice').click(function() { 
            const id = $(this).data('id'); 
            const number = $(this).data('number'); 
            const paidAmount = $(this).data('paid'); 
            $('#refundInvoiceId').val(id); 
            $('#refundInvoiceNumber').text(number); 
            $('#refundPaidAmount').text(paidAmount.toFixed(2)); 
            $('#refundAmount').val('').attr('max', paidAmount); 
            $('#refundModal').modal('show'); 
        });
        
        $('.whatsapp-invoice').click(function() {
            const btn = $(this);
            const invoiceId = btn.data('id');
            if (!invoiceId) {
                showAlert('error', 'Invoice ID lama helin');
                return;
            }
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'send_invoice_whatsapp', invoice_id: invoiceId },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        showAlert('success', res.message || 'WhatsApp automatic waa la diray');
                    } else {
                        showAlert('error', res.message || 'WhatsApp lama dirin');
                    }
                    btn.prop('disabled', false).html('<i class="fab fa-whatsapp"></i>');
                },
                error: function() {
                    showAlert('error', 'Server error: WhatsApp lama dirin');
                    btn.prop('disabled', false).html('<i class="fab fa-whatsapp"></i>');
                }
            });
        });

        $('.print-invoice').click(function() { 
            const id = $(this).data('id'); 
            $.ajax({ 
                url: window.location.href, 
                type: 'POST', 
                data: { ajax_action: 'get_invoice', id: id }, 
                dataType: 'json', 
                success: function(inv) { 
                    const w = window.open('', '_blank'); 
                    w.document.write('<html><head><title>Invoice ' + escapeHtml(inv.invoice_number) + '</title></head><body style="margin:0;padding:20px;">' + getInvoiceHTML(inv) + '</body></html>'); 
                    w.document.close(); 
                    setTimeout(function() { w.print(); }, 300); 
                } 
            }); 
        });
        
        $('.delete-invoice').click(function() { 
            deleteId = $(this).data('id'); 
            $('#deleteInvoiceName').text($(this).data('name')); 
            $('#deleteModal').modal('show'); 
        });
        
        $('.pagination a').click(function(e) { 
            e.preventDefault(); 
            const page = $(this).data('page'); 
            if (page) { currentPage = page; loadInvoices(); } 
        });
    }
    
    function escapeHtml(text) { 
        if (!text) return ''; 
        return String(text).replace(/[&<>]/g, function(m) { 
            if (m === '&') return '&amp;'; 
            if (m === '<') return '&lt;'; 
            if (m === '>') return '&gt;'; 
            return m; 
        }); 
    }
    
    function showAlert(type, msg) { 
        const alertClass = type === 'success' ? 'alert-success' : 'alert-error';
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        $('#alert-placeholder').html(`<div class="alert alert-custom ${alertClass} alert-dismissible fade show"><i class="fas ${icon} mr-2"></i> ${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`); 
        setTimeout(() => $('.alert-custom').fadeOut(5000, function() { $(this).remove(); }), 5000); 
    }

    $('#invoiceForm').submit(function(e) {
        e.preventDefault();
        
        if (!$('#modalInvoiceNumber').val() || $('#modalInvoiceNumber').val() === '-') { 
            generateInvoiceNumber();
            showAlert('info', 'Generating invoice number... Please click Save again in a moment.');
            return; 
        }

        if (!$('#modalCustomerId').val()) { 
            showAlert('error', 'Please select a customer'); 
            return; 
        }
        
        const formData = new FormData(this);
        formData.append('ajax_action', 'save_invoice');
        
        $.ajax({ 
            url: window.location.href, 
            type: 'POST', 
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json', 
            success: function(res) { 
                if (res.success) { 
                    $('#invoiceModal').modal('hide'); 
                    loadInvoices(); 
                    loadStats(); 
                    showAlert('success', res.message); 
                    $('#invoiceForm')[0].reset(); 
                    calculateTotal(); 
                } else { 
                    showAlert('error', res.message); 
                } 
            }, 
            error: function() { showAlert('error', 'An error occurred'); } 
        });
    });
    
    $('#paymentForm').submit(function(e) {
        e.preventDefault();
        const amount = parseFloat($('#paymentAmount').val());
        const dueAmount = parseFloat($('#paymentDueAmount').text());
        if (isNaN(amount) || amount <= 0) { showAlert('error', 'Please enter the payment amount'); return; }
        if (amount > dueAmount) { showAlert('error', `Payment amount ($${amount.toFixed(2)}) exceeds due amount ($${dueAmount.toFixed(2)})`); return; }
        
        const btn = $(this).find('button[type="submit"]');
        const originalText = btn.html();
        btn.html('<i class="fas fa-spinner fa-spin"></i> Processing...').prop('disabled', true);
        
        $.ajax({ 
            url: window.location.href, 
            type: 'POST', 
            data: { 
                ajax_action: 'add_payment', 
                invoice_id: $('#paymentInvoiceId').val(), 
                amount: amount, 
                payment_date: $('#paymentDate').val(), 
                payment_method: $('#paymentMethod').val(), 
                reference_number: $('#paymentReference').val(), 
                notes: $('#paymentNotes').val() 
            }, 
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#paymentModal').modal('hide');
                    loadInvoices();
                    loadStats();
                    showAlert('success', res.message);
                    $('#paymentForm')[0].reset();
                } else { 
                    showAlert('error', res.message); 
                }
                btn.html(originalText).prop('disabled', false);
            },
            error: function() { 
                showAlert('error', 'An error occurred'); 
                btn.html(originalText).prop('disabled', false); 
            }
        });
    });
    
    $('#refundForm').submit(function(e) {
        e.preventDefault();
        const amount = parseFloat($('#refundAmount').val());
        const paidAmount = parseFloat($('#refundPaidAmount').text());
        if (isNaN(amount) || amount <= 0) { showAlert('error', 'Please enter the refund amount'); return; }
        if (amount > paidAmount) { showAlert('error', `Refund amount ($${amount.toFixed(2)}) exceeds paid amount ($${paidAmount.toFixed(2)})`); return; }
        
        const btn = $(this).find('button[type="submit"]');
        const originalText = btn.html();
        btn.html('<i class="fas fa-spinner fa-spin"></i> Processing...').prop('disabled', true);
        
        $.ajax({ 
            url: window.location.href, 
            type: 'POST', 
            data: { 
                ajax_action: 'refund_payment', 
                invoice_id: $('#refundInvoiceId').val(), 
                refund_amount: amount, 
                refund_date: $('#refundDate').val(), 
                refund_reason: $('#refundReason').val()
            }, 
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#refundModal').modal('hide');
                    loadInvoices();
                    loadStats();
                    showAlert('success', res.message);
                    $('#refundForm')[0].reset();
                } else { 
                    showAlert('error', res.message); 
                }
                btn.html(originalText).prop('disabled', false);
            },
            error: function() { 
                showAlert('error', 'An error occurred'); 
                btn.html(originalText).prop('disabled', false); 
            }
        });
    });

    $('#confirmDeleteBtn').click(function() {
        if (deleteId) { 
            const btn = $(this);
            btn.html('<i class="fas fa-spinner fa-spin"></i> Deleting...').prop('disabled', true);
            
            $.ajax({ 
                url: window.location.href, 
                type: 'POST', 
                data: { ajax_action: 'delete_invoice', id: deleteId }, 
                dataType: 'json', 
                success: function(res) { 
                    if (res.success) { 
                        $('#deleteModal').modal('hide'); 
                        loadInvoices(); 
                        loadStats(); 
                        showAlert('success', res.message); 
                    } else { 
                        showAlert('error', res.message); 
                    } 
                    deleteId = null; 
                    btn.html('Yes, Delete').prop('disabled', false);
                },
                error: function() { 
                    showAlert('error', 'An error occurred while deleting'); 
                    btn.html('Yes, Delete').prop('disabled', false);
                }
            }); 
        }
    });

    $('#addInvoiceBtn, #addInvoiceBtnEmpty').click(function() {
        $('#invoiceModalLabel').text('New Invoice');
        $('#invoiceForm')[0].reset();
        $('#invoice_id').val('');
        $('#lineItemBody').html(`
        <tr>
            <td><input type="text" name="items[]" class="form-control form-control-sm" placeholder="Item name"></td>
            <td><input type="text" name="descriptions[]" class="form-control form-control-sm" placeholder="Description..."></td>
            <td><input type="text" inputmode="decimal" name="qtys[]" class="form-control form-control-sm item-qty" value="1" placeholder="0.00"></td>
            <td><input type="text" inputmode="decimal" name="rates[]" class="form-control form-control-sm item-rate" value="0.00" placeholder="0.00"></td>
            <td><input type="text" inputmode="decimal" class="form-control form-control-sm item-amount" value="0.00" readonly></td>
            <td class="text-center"><i class="fas fa-times remove-line"></i></td>
        </tr>
        `);
        $('#modalInvoiceDate').val(new Date().toISOString().split('T')[0]);
        $('#modalDueDate').val(new Date(new Date().setDate(new Date().getDate() + 30)).toISOString().split('T')[0]);
        $('#modalSubtotal').val(0); 
        $('#modalTaxRate').val(0); 
        $('#modalDiscount').val(0);
        $('#tenantValidationWarning').hide(); 
        tenantValid = true; 
        $('#saveInvoiceBtn').prop('disabled', false);
        calculateTotal();
        checkTenantComplete();
        $('#invoiceModal').modal('show');
    });

    $('#quickAddCustomerBtn').click(function() {
        $('#quickAddCustomerModal').modal('show');
    });
    
    $('#quickAddCustomerForm').submit(function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('ajax_action', 'quick_add_customer');
        $.ajax({ 
            url: window.location.href, 
            type: 'POST', 
            data: formData, 
            processData: false, 
            contentType: false, 
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    setSelectedCustomerForInvoice({id: res.id, customer_name: res.name, phone: res.phone || '', debt_amount: 0});
                    $('#quickAddCustomerModal').modal('hide');
                    $('#quickAddCustomerForm')[0].reset();
                    showAlert('success', 'Customer added successfully!');
                } else { 
                    showAlert('error', res.message); 
                }
            }
        });
    });

    $('#printInvoiceBtn').click(function() { 
        const printContent = $('#viewModalBody').html(); 
        const w = window.open('', '_blank'); 
        w.document.write('<html><head><title>Print Invoice</title></head><body style="margin:0;padding:20px;">' + printContent + '</body></html>'); 
        w.document.close(); 
        setTimeout(function() { w.print(); }, 300); 
    });

    $('#applyFilters').click(function() { 
        currentPage = 1; 
        loadInvoices(); 
        loadStats(); 
    });
    
    $('#resetFilters').click(function() { 
        $('#searchInput').val(''); 
        $('#customerFilter').val('0');
        $('#customerFilterSearch').val(''); 
        $('#statusFilter').val('all'); 
        $('#dateFrom').val(''); 
        $('#dateTo').val(''); 
        currentPage = 1; 
        loadInvoices(); 
        loadStats(); 
    });
    
    $('#searchInput').keypress(function(e) { 
        if (e.which === 13) { 
            currentPage = 1; 
            loadInvoices(); 
        } 
    });

    calculateTotal();
    
    $('#modalCustomerId').change(function() {
        const customerId = $(this).val();
        if (customerId) {
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_customer_stock_total', customer_id: customerId },
                dataType: 'json',
                success: function(res) {
                    if (res.success && res.total_value > 0) {
                        if ($('#modalSubtotal').val() == 0 || $('#modalSubtotal').val() == '') {
                            $('#modalSubtotal').val(res.total_value);
                            showAlert('info', 'Customer warehouse value has been auto-filled as Freight.');
                            $('#importInvoiceBtn').click(function() {
        $('#invoiceCsvFile').val('').trigger('click');
    });

    $('#invoiceCsvFile').change(function() {
        const file = this.files[0];
        if (!file) return;
        if (!confirm('Ma hubtaa inaad import-gareyso invoices-kan? Haddii send_whatsapp=yes, WhatsApp automatic ayaa loo diri doonaa customer-ka.')) return;
        const formData = new FormData();
        formData.append('ajax_action', 'import_invoices');
        formData.append('csv_file', file);
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    showAlert('success', res.message || 'Import complete');
                    loadInvoices();
                    loadStats();
                } else {
                    showAlert('error', res.message || 'Import failed');
                }
            },
            error: function() {
                showAlert('error', 'Server error while importing CSV');
            }
        });
    });

    calculateTotal();
                        }
                    }
                }
            });
        }
    });

    loadInvoices();
    loadStats();
    checkTenantComplete();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>