<?php
// tenant_admin/reports.php
// Cargo Management System - Complete Business Report with Clickable Financial Data
// FIXED: Customer names now display correctly from payments/receipts

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$allowed_roles = ['superadmin', 'company_admin', 'tenant_admin', 'admin'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowed_roles, true)) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

$session_tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
$user_name = $_SESSION['user_name'] ?? ($_SESSION['full_name'] ?? 'Admin');
$user_role = $_SESSION['role'] ?? 'staff';
$user_id = $_SESSION['user_id'] ?? 0;

if (!$session_tenant_id && ($_SESSION['role'] ?? '') !== 'superadmin') {
    header("Location: ../dashboard.php?error=no_tenant");
    exit;
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($value): string {
    return '$' . number_format((float)$value, 2);
}

function num($value, int $decimals = 0): string {
    return number_format((float)$value, $decimals);
}

function safeDate($date): string {
    if (!$date || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') return '-';
    return date('d/m/Y', strtotime($date));
}

function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function qOne(PDO $pdo, string $sql, array $params = [], $default = 0) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $default : $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function qRows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function getTenantName(PDO $pdo, int $tenant_id): string {
    if ($tenant_id <= 0) return 'My Company';
    if (!tableExists($pdo, 'tenants')) return 'My Company';
    $name = qOne($pdo, "SELECT COALESCE(name, company_name, 'My Company') FROM tenants WHERE id = ? LIMIT 1", [$tenant_id], 'My Company');
    return (string)$name;
}

function statusBadge(string $status): string {
    $status = strtolower(trim($status ?: 'unknown'));
    $class = match ($status) {
        'paid','active','delivered','sent','completed','cleared','success' => 'success',
        'overdue','failed','cancelled','inactive','held' => 'danger',
        'pending','draft','loading','received','in_progress' => 'warning',
        default => 'info'
    };
    return '<span class="badge bg-' . $class . '">' . h(ucwords(str_replace('_', ' ', $status))) . '</span>';
}

// ============================================
// GET FILTERS
// ============================================
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) $date_to = date('Y-m-t');
$report = $_GET['report'] ?? 'overview';
$valid_reports = ['overview','sales','payments','debit','receivables','inventory','customers','containers','warehouse','sms','profit_loss','balance_sheet'];
if (!in_array($report, $valid_reports, true)) $report = 'overview';

$tenant_name = getTenantName($pdo, $session_tenant_id);
$dateParams = [$session_tenant_id, $date_from, $date_to];
$tenantParam = [$session_tenant_id];

// ============================================
// CHECK TABLE EXISTENCE
// ============================================
$hasInvoices = tableExists($pdo, 'invoices');
$hasInvoiceItems = tableExists($pdo, 'invoice_items');
$hasPayments = tableExists($pdo, 'payments');
$hasReceipts = tableExists($pdo, 'receipts');
$hasCustomers = tableExists($pdo, 'customers');
$hasContainers = tableExists($pdo, 'containers');
$hasWarehouse = tableExists($pdo, 'warehouse_stock');
$hasSMSLogs = tableExists($pdo, 'sms_logs');
$hasBulkCampaigns = tableExists($pdo, 'bulk_sms_campaigns');
$hasExpenses = tableExists($pdo, 'expenses');
$hasJournalEntries = tableExists($pdo, 'journal_entries');
$hasChartAccounts = tableExists($pdo, 'chart_of_accounts');
$hasBankAccounts = tableExists($pdo, 'bank_accounts');

$customerNameExpr = $hasCustomers ? "COALESCE(c.customer_name, '-')" : "'-'";

// ============================================
// CORE METRICS
// ============================================
$totalSales = $hasInvoices ? qOne($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE tenant_id=? AND invoice_date BETWEEN ? AND ? AND COALESCE(is_active,1)=1", $dateParams) : 0;
$paidSales = $hasInvoices ? qOne($pdo, "SELECT COALESCE(SUM(paid_amount),0) FROM invoices WHERE tenant_id=? AND invoice_date BETWEEN ? AND ? AND COALESCE(is_active,1)=1", $dateParams) : 0;
$unpaidSales = max(0, (float)$totalSales - (float)$paidSales);
$invoiceCount = $hasInvoices ? qOne($pdo, "SELECT COUNT(*) FROM invoices WHERE tenant_id=? AND invoice_date BETWEEN ? AND ? AND COALESCE(is_active,1)=1", $dateParams) : 0;

$totalPayments = $hasPayments ? qOne($pdo, "SELECT COALESCE(SUM(amount),0) FROM payments WHERE tenant_id=? AND payment_date BETWEEN ? AND ? AND COALESCE(is_active,1)=1", $dateParams) : 0;
$totalReceipts = $hasReceipts ? qOne($pdo, "SELECT COALESCE(SUM(amount),0) FROM receipts WHERE tenant_id=? AND payment_date BETWEEN ? AND ?", $dateParams) : $paidSales;

$totalExpenses = 0;
if ($hasExpenses) {
    $totalExpenses += (float)qOne($pdo, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE tenant_id=? AND expense_date BETWEEN ? AND ? AND COALESCE(is_active,1)=1", $dateParams);
}
if ($hasPayments) {
    $totalExpenses += (float)$totalPayments;
}
$netProfit = (float)$totalSales - (float)$totalExpenses;
$profitMargin = $totalSales > 0 ? ($netProfit / $totalSales) * 100 : 0;

$totalReceivable = $hasInvoices ? qOne($pdo, "SELECT COALESCE(SUM(GREATEST(total_amount - paid_amount,0)),0) FROM invoices WHERE tenant_id=? AND COALESCE(is_active,1)=1 AND status != 'cancelled'", $tenantParam) : 0;
$warehouseValue = $hasWarehouse ? qOne($pdo, "SELECT COALESCE(SUM(COALESCE(volume_cbm,0) * COALESCE(unit_price,0)),0) FROM warehouse_stock WHERE tenant_id=?", $tenantParam) : 0;
$warehouseQty = $hasWarehouse ? qOne($pdo, "SELECT COALESCE(SUM(quantity),0) FROM warehouse_stock WHERE tenant_id=?", $tenantParam) : 0;
$warehouseItems = $hasWarehouse ? qOne($pdo, "SELECT COUNT(*) FROM warehouse_stock WHERE tenant_id=?", $tenantParam) : 0;

$totalCustomers = $hasCustomers ? qOne($pdo, "SELECT COUNT(*) FROM customers WHERE tenant_id=? AND COALESCE(is_active,1)=1", $tenantParam) : 0;
$totalContainers = $hasContainers ? qOne($pdo, "SELECT COUNT(*) FROM containers WHERE tenant_id=? AND COALESCE(is_active,1)=1", $tenantParam) : 0;
$totalCustomerDebt = $hasCustomers ? qOne($pdo, "SELECT COALESCE(SUM(debt_amount),0) FROM customers WHERE tenant_id=? AND COALESCE(is_active,1)=1", $tenantParam) : 0;
$totalCustomerSpend = $hasCustomers ? qOne($pdo, "SELECT COALESCE(SUM(total_spent),0) FROM customers WHERE tenant_id=? AND COALESCE(is_active,1)=1", $tenantParam) : 0;
if ((float)$totalCustomerSpend <= 0 && $hasInvoices) {
    $totalCustomerSpend = qOne($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE tenant_id=? AND COALESCE(is_active,1)=1 AND status != 'cancelled'", $tenantParam);
}

$totalSMS = 0;
if ($hasSMSLogs) {
    $totalSMS = qOne($pdo, "SELECT COUNT(*) FROM sms_logs WHERE tenant_id=? AND DATE(created_at) BETWEEN ? AND ?", $dateParams);
} elseif ($hasBulkCampaigns) {
    $totalSMS = qOne($pdo, "SELECT COALESCE(SUM(total_sent),0) FROM bulk_sms_campaigns WHERE tenant_id=? AND DATE(created_at) BETWEEN ? AND ?", $dateParams);
}

$overdueInvoiceCount = $hasInvoices ? qOne($pdo, "SELECT COUNT(*) FROM invoices WHERE tenant_id=? AND (status='overdue' OR (due_date < CURDATE() AND status != 'paid')) AND COALESCE(is_active,1)=1", $tenantParam) : 0;

// AR Aging Buckets
$agingBuckets = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
if ($hasInvoices) {
    $agingInvoices = qRows($pdo, "
        SELECT DATEDIFF(CURDATE(), due_date) as days_overdue, GREATEST(total_amount - paid_amount,0) as due_amount
        FROM invoices WHERE tenant_id=? AND status != 'paid' AND GREATEST(total_amount - paid_amount,0) > 0 AND COALESCE(is_active,1)=1
    ", $tenantParam);
    foreach ($agingInvoices as $inv) {
        $days = (int)$inv['days_overdue'];
        $amount = (float)$inv['due_amount'];
        if ($days <= 30) $agingBuckets['0-30'] += $amount;
        elseif ($days <= 60) $agingBuckets['31-60'] += $amount;
        elseif ($days <= 90) $agingBuckets['61-90'] += $amount;
        else $agingBuckets['90+'] += $amount;
    }
}

// Payment Methods Distribution
$paymentMethods = [];
if ($hasPayments) {
    $paymentMethods = qRows($pdo, "
        SELECT payment_method, COUNT(*) as count, COALESCE(SUM(amount),0) as total
        FROM payments WHERE tenant_id=? AND payment_date BETWEEN ? AND ? AND COALESCE(is_active,1)=1
        GROUP BY payment_method
    ", $dateParams);
}

// Monthly Sales Trend
$monthlySales = $hasInvoices ? qRows($pdo, "
    SELECT DATE_FORMAT(invoice_date,'%Y-%m') AS label, COALESCE(SUM(total_amount),0) AS value
    FROM invoices WHERE tenant_id=? AND invoice_date BETWEEN ? AND ? AND COALESCE(is_active,1)=1
    GROUP BY DATE_FORMAT(invoice_date,'%Y-%m') ORDER BY label
", $dateParams) : [];

// ============================================
// RECENT INVOICES - CUSTOMER NAME FIXED
// ============================================
$recentInvoices = [];
if ($hasInvoices) {
    if ($hasCustomers) {
        $sql = "SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.paid_amount, i.status, 
                       COALESCE(c.customer_name, '-') AS customer_name, i.customer_id
                FROM invoices i 
                LEFT JOIN customers c ON c.id = i.customer_id AND c.tenant_id = i.tenant_id
                WHERE i.tenant_id=? AND COALESCE(i.is_active,1)=1 
                ORDER BY i.invoice_date DESC, i.id DESC LIMIT 10";
    } else {
        $sql = "SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.paid_amount, i.status, 
                       '-' AS customer_name, i.customer_id
                FROM invoices i 
                WHERE i.tenant_id=? AND COALESCE(i.is_active,1)=1 
                ORDER BY i.invoice_date DESC, i.id DESC LIMIT 10";
    }
    $recentInvoices = qRows($pdo, $sql, $tenantParam);
}

// ============================================
// RECENT PAYMENTS - CUSTOMER NAME FIXED
// ============================================
$recentPayments = [];
if ($hasPayments) {
    if ($hasCustomers) {
        $sql = "SELECT p.payment_number, p.payment_date, p.amount, p.payment_method, 
                       COALESCE(c.customer_name, '-') AS customer_name, p.customer_id
                FROM payments p 
                LEFT JOIN customers c ON c.id = p.customer_id AND c.tenant_id = p.tenant_id
                WHERE p.tenant_id=? AND COALESCE(p.is_active,1)=1 
                ORDER BY p.payment_date DESC, p.id DESC LIMIT 10";
    } else {
        $sql = "SELECT p.payment_number, p.payment_date, p.amount, p.payment_method, 
                       '-' AS customer_name, p.customer_id
                FROM payments p 
                WHERE p.tenant_id=? AND COALESCE(p.is_active,1)=1 
                ORDER BY p.payment_date DESC, p.id DESC LIMIT 10";
    }
    $recentPayments = qRows($pdo, $sql, $tenantParam);
}

// ============================================
// TOP CUSTOMERS - FIXED
// ============================================
$topCustomers = [];
if ($hasCustomers) {
    $topCustomers = qRows($pdo, "
        SELECT c.id, c.customer_name, c.phone, c.email, COALESCE(c.debt_amount,0) AS debt_amount,
               COALESCE(SUM(i.total_amount),0) AS total_spent, COUNT(i.id) AS invoice_count
        FROM customers c 
        LEFT JOIN invoices i ON i.customer_id = c.id AND i.tenant_id = c.tenant_id AND COALESCE(i.is_active,1)=1
        WHERE c.tenant_id = ? 
        GROUP BY c.id, c.customer_name, c.phone, c.email, c.debt_amount
        ORDER BY total_spent DESC LIMIT 10
    ", $tenantParam);
}

$containerStatus = $hasContainers ? qRows($pdo, "SELECT COALESCE(status,'unknown') AS label, COUNT(*) AS value FROM containers WHERE tenant_id=? GROUP BY status", $tenantParam) : [];

// Full Data Tables
$salesRows = $hasInvoices ? qRows($pdo, "
    SELECT i.id, i.invoice_number, i.invoice_date, i.due_date, i.total_amount, i.paid_amount,
           GREATEST(i.total_amount-i.paid_amount,0) AS balance, i.status, 
           COALESCE(c.customer_name, '-') AS customer_name, i.customer_id
    FROM invoices i 
    LEFT JOIN customers c ON c.id = i.customer_id AND c.tenant_id = i.tenant_id
    WHERE i.tenant_id=? AND i.invoice_date BETWEEN ? AND ? AND COALESCE(i.is_active,1)=1
    ORDER BY i.invoice_date DESC LIMIT 500
", $dateParams) : [];

$paymentRows = $hasPayments ? qRows($pdo, "
    SELECT p.payment_number, p.payment_date, p.amount, p.payment_method, p.category, 
           COALESCE(c.customer_name, '-') AS customer_name
    FROM payments p 
    LEFT JOIN customers c ON c.id = p.customer_id AND c.tenant_id = p.tenant_id
    WHERE p.tenant_id=? AND p.payment_date BETWEEN ? AND ? AND COALESCE(p.is_active,1)=1
    ORDER BY p.payment_date DESC LIMIT 500
", $dateParams) : [];


// Debit Rows - shows outgoing/debit transactions with customer/supplier names when available
$debitRows = [];
if ($hasJournalEntries) {
    // Journal debit rows can be created from payments, so join back to payments/customers
    // using reference_id, payment_number, or payment_number inside description.
    $debitRows = qRows($pdo, "
        SELECT je.entry_number, je.entry_date, je.account_code, je.account_name, je.debit, je.credit,
               (je.debit - je.credit) AS debit_balance, je.description,
               COALESCE(c.customer_name, p.supplier_name, '-') AS customer_name
        FROM journal_entries je
        LEFT JOIN payments p ON p.tenant_id = je.tenant_id
            AND (
                (LOWER(COALESCE(je.reference_type,'')) IN ('payment','payments') AND p.id = je.reference_id)
                OR p.payment_number = je.entry_number
                OR je.description LIKE CONCAT('%', p.payment_number, '%')
            )
        LEFT JOIN customers c ON c.id = p.customer_id AND c.tenant_id = p.tenant_id
        WHERE je.tenant_id=? AND je.entry_date BETWEEN ? AND ? AND COALESCE(je.debit,0) > 0
        GROUP BY je.id
        ORDER BY je.entry_date DESC, je.id DESC LIMIT 500
    ", $dateParams);
}
if (empty($debitRows)) {
    if ($hasPayments) {
        $debitRows = array_merge($debitRows, qRows($pdo, "
            SELECT p.payment_number AS entry_number, p.payment_date AS entry_date,
                   'PAY' AS account_code, COALESCE(p.category,'Payment / Outgoing') AS account_name,
                   p.amount AS debit, 0 AS credit, p.amount AS debit_balance,
                   CONCAT(COALESCE(p.payment_method,''), ' ', COALESCE(p.reference_number,''), ' ', COALESCE(p.notes,'')) AS description,
                   COALESCE(c.customer_name, p.supplier_name, '-') AS customer_name
            FROM payments p
            LEFT JOIN customers c ON c.id = p.customer_id AND c.tenant_id = p.tenant_id
            WHERE p.tenant_id=? AND p.payment_date BETWEEN ? AND ? AND COALESCE(p.is_active,1)=1
            ORDER BY p.payment_date DESC, p.id DESC LIMIT 500
        ", $dateParams));
    }
    if ($hasExpenses) {
        $debitRows = array_merge($debitRows, qRows($pdo, "
            SELECT e.expense_number AS entry_number, e.expense_date AS entry_date,
                   'EXP' AS account_code, COALESCE(e.expense_category,'Expense') AS account_name,
                   e.amount AS debit, 0 AS credit, e.amount AS debit_balance,
                   COALESCE(e.notes,'') AS description,
                   COALESCE(e.vendor_name,'-') AS customer_name
            FROM expenses e
            WHERE e.tenant_id=? AND e.expense_date BETWEEN ? AND ? AND COALESCE(e.is_active,1)=1
            ORDER BY e.expense_date DESC, e.id DESC LIMIT 500
        ", $dateParams));
    }
}
$totalDebit = array_sum(array_map(fn($r) => (float)($r['debit'] ?? 0), $debitRows));

$receivableRows = $hasInvoices ? qRows($pdo, "
    SELECT i.id, i.invoice_number, i.invoice_date, i.due_date, i.total_amount, i.paid_amount,
           GREATEST(i.total_amount-i.paid_amount,0) AS balance,
           DATEDIFF(CURDATE(), i.due_date) AS days_overdue, i.status,
           COALESCE(c.customer_name, '-') AS customer_name, i.customer_id
    FROM invoices i 
    LEFT JOIN customers c ON c.id = i.customer_id AND c.tenant_id = i.tenant_id
    WHERE i.tenant_id=? AND GREATEST(i.total_amount-i.paid_amount,0)>0 AND i.status != 'cancelled' AND COALESCE(i.is_active,1)=1
    ORDER BY i.due_date ASC LIMIT 500
", $tenantParam) : [];

$inventoryRows = $hasWarehouse ? qRows($pdo, "
    SELECT ws.id, ws.stock_name, ws.quantity, ws.volume_cbm, ws.unit_price,
           (COALESCE(ws.volume_cbm,0)*COALESCE(ws.unit_price,0)) AS value_amount,
           ws.origin, COALESCE(c.customer_name, '-') AS customer_name, ws.customer_id
    FROM warehouse_stock ws 
    LEFT JOIN customers c ON c.id = ws.customer_id AND c.tenant_id = ws.tenant_id
    WHERE ws.tenant_id=? ORDER BY ws.id DESC LIMIT 500
", $tenantParam) : [];

$customerRows = $hasCustomers ? qRows($pdo, "
    SELECT c.id, c.customer_name, c.phone, c.email, c.debt_amount, c.total_spent, c.total_cbm_shipped, c.is_active,
           COUNT(i.id) AS invoice_count, COALESCE(SUM(i.total_amount),0) AS invoice_total
    FROM customers c 
    LEFT JOIN invoices i ON i.customer_id = c.id AND i.tenant_id = c.tenant_id AND COALESCE(i.is_active,1)=1
    WHERE c.tenant_id = ? 
    GROUP BY c.id, c.customer_name, c.phone, c.email, c.debt_amount, c.total_spent, c.total_cbm_shipped, c.is_active
    ORDER BY c.id DESC LIMIT 500
", $tenantParam) : [];

$containerRows = $hasContainers ? qRows($pdo, "
    SELECT container_number, container_type, origin, status, current_location, size_cbm, estimated_arrival
    FROM containers WHERE tenant_id=? ORDER BY id DESC LIMIT 500
", $tenantParam) : [];

$smsRows = [];
if ($hasSMSLogs) {
    $smsRows = qRows($pdo, "SELECT phone_number, message, status, created_at FROM sms_logs WHERE tenant_id=? AND DATE(created_at) BETWEEN ? AND ? ORDER BY id DESC LIMIT 500", $dateParams);
} elseif ($hasBulkCampaigns) {
    $smsRows = qRows($pdo, "SELECT campaign_name, message_text, total_recipients, total_sent, total_delivered, total_failed, status, created_at FROM bulk_sms_campaigns WHERE tenant_id=? AND DATE(created_at) BETWEEN ? AND ? ORDER BY id DESC LIMIT 500", $dateParams);
}

// ============================================
// PROFIT & LOSS 
// ============================================
$plRevenueRows = [];
$plExpenseRows = [];

if ($hasJournalEntries) {
    $plRevenueRows = qRows($pdo, "
        SELECT COALESCE(account_code,'') AS account_code, account_name,
               COALESCE(SUM(credit - debit),0) AS amount,
               COUNT(*) AS entry_count
        FROM journal_entries
        WHERE tenant_id=? AND entry_date BETWEEN ? AND ?
          AND (account_code LIKE '4%' OR LOWER(account_name) LIKE '%revenue%' OR LOWER(account_name) LIKE '%sales%')
        GROUP BY account_code, account_name
        HAVING amount != 0
        ORDER BY account_code, account_name
    ", $dateParams);

    $plExpenseRows = qRows($pdo, "
        SELECT COALESCE(account_code,'') AS account_code, account_name,
               COALESCE(SUM(debit - credit),0) AS amount,
               COUNT(*) AS entry_count
        FROM journal_entries
        WHERE tenant_id=? AND entry_date BETWEEN ? AND ?
          AND (account_code LIKE '5%' OR LOWER(account_name) LIKE '%expense%' OR LOWER(account_name) LIKE '%cost%')
        GROUP BY account_code, account_name
        HAVING amount != 0
        ORDER BY account_code, account_name
    ", $dateParams);
}

if (empty($plRevenueRows) && $hasInvoices) {
    $plRevenueRows[] = [
        'account_code' => '4000',
        'account_name' => 'Invoice Sales Revenue',
        'amount' => (float)$totalSales,
        'entry_count' => (int)$invoiceCount
    ];
}

if (empty($plExpenseRows)) {
    if ($hasExpenses) {
        $expCats = qRows($pdo, "
            SELECT COALESCE(expense_category,'Other Expense') AS account_name,
                   COALESCE(SUM(amount),0) AS amount,
                   COUNT(*) AS entry_count
            FROM expenses
            WHERE tenant_id=? AND expense_date BETWEEN ? AND ? AND COALESCE(is_active,1)=1
            GROUP BY expense_category
            ORDER BY amount DESC
        ", $dateParams);
        foreach ($expCats as $r) {
            $plExpenseRows[] = [
                'account_code' => '5000',
                'account_name' => $r['account_name'],
                'amount' => (float)$r['amount'],
                'entry_count' => (int)$r['entry_count']
            ];
        }
    }
    if ($hasPayments) {
        $payCats = qRows($pdo, "
            SELECT COALESCE(category,'Payments / Outgoing') AS account_name,
                   COALESCE(SUM(amount),0) AS amount,
                   COUNT(*) AS entry_count
            FROM payments
            WHERE tenant_id=? AND payment_date BETWEEN ? AND ? AND COALESCE(is_active,1)=1
            GROUP BY category
            ORDER BY amount DESC
        ", $dateParams);
        foreach ($payCats as $r) {
            $plExpenseRows[] = [
                'account_code' => '5100',
                'account_name' => $r['account_name'],
                'amount' => (float)$r['amount'],
                'entry_count' => (int)$r['entry_count']
            ];
        }
    }
}

$plTotalRevenue = array_sum(array_map(fn($r) => (float)($r['amount'] ?? 0), $plRevenueRows));
$plTotalExpenses = array_sum(array_map(fn($r) => (float)($r['amount'] ?? 0), $plExpenseRows));
$plNetProfit = $plTotalRevenue - $plTotalExpenses;
$plFinalLabel = $plNetProfit >= 0 ? 'Final Profit' : 'Final Loss';

// ============================================
// BALANCE SHEET
// ============================================
$balanceAssetRows = [];
$balanceLiabilityRows = [];
$balanceEquityRows = [];

$totalBankBalance = $hasBankAccounts ? qOne($pdo, "SELECT COALESCE(SUM(current_balance),0) FROM bank_accounts WHERE tenant_id=? AND COALESCE(is_active,1)=1", $tenantParam) : 0;

if ($hasChartAccounts) {
    $coaRows = qRows($pdo, "
        SELECT account_code, account_name, account_type, COALESCE(balance,0) AS balance
        FROM chart_of_accounts
        WHERE (tenant_id=? OR tenant_id IS NULL) AND COALESCE(is_active,1)=1
        ORDER BY account_type, account_code
    ", $tenantParam);

    foreach ($coaRows as $acc) {
        $type = strtolower((string)$acc['account_type']);
        $balance = (float)($acc['balance'] ?? 0);
        
        if ($acc['account_code'] === '1100') $balance = $totalBankBalance;
        if ($acc['account_code'] === '1200') $balance = (float)$totalReceivable;
        if ($acc['account_code'] === '1300' || $acc['account_code'] === '1400') $balance = (float)$warehouseValue;
        
        if ($type === 'asset') {
            $balanceAssetRows[] = ['account_code' => $acc['account_code'], 'account_name' => $acc['account_name'], 'account_type' => 'asset', 'balance' => $balance];
        } elseif ($type === 'liability') {
            $balanceLiabilityRows[] = ['account_code' => $acc['account_code'], 'account_name' => $acc['account_name'], 'account_type' => 'liability', 'balance' => $balance];
        } elseif ($type === 'equity') {
            $balanceEquityRows[] = ['account_code' => $acc['account_code'], 'account_name' => $acc['account_name'], 'account_type' => 'equity', 'balance' => $balance];
        }
    }
}

if (empty($balanceAssetRows)) {
    $balanceAssetRows = [
        ['account_code' => '1100', 'account_name' => 'Cash & Bank', 'account_type' => 'asset', 'balance' => (float)$totalBankBalance],
        ['account_code' => '1200', 'account_name' => 'Accounts Receivable', 'account_type' => 'asset', 'balance' => (float)$totalReceivable],
        ['account_code' => '1300', 'account_name' => 'Inventory / Warehouse', 'account_type' => 'asset', 'balance' => (float)$warehouseValue],
    ];
}
if (empty($balanceLiabilityRows)) {
    $balanceLiabilityRows[] = ['account_code' => '2100', 'account_name' => 'Accounts Payable', 'account_type' => 'liability', 'balance' => 0];
}
if (empty($balanceEquityRows)) {
    $balanceEquityRows[] = ['account_code' => '3100', 'account_name' => 'Owner\'s Equity', 'account_type' => 'equity', 'balance' => 0];
}

$bsTotalAssets = array_sum(array_map(fn($r) => (float)($r['balance'] ?? 0), $balanceAssetRows));
$bsTotalLiabilities = array_sum(array_map(fn($r) => (float)($r['balance'] ?? 0), $balanceLiabilityRows));
$bsTotalEquity = array_sum(array_map(fn($r) => (float)($r['balance'] ?? 0), $balanceEquityRows));
$bsCheck = $bsTotalAssets - ($bsTotalLiabilities + $bsTotalEquity);

if (abs($bsCheck) > 0.01) {
    foreach ($balanceEquityRows as &$eq) {
        if ($eq['account_code'] === '3200' || strpos($eq['account_name'], 'Retained') !== false) {
            $eq['balance'] += $bsCheck;
            break;
        }
    }
    $bsTotalEquity = array_sum(array_map(fn($r) => (float)($r['balance'] ?? 0), $balanceEquityRows));
}

// ============================================
// RENDER FUNCTIONS
// ============================================
function renderTable(array $rows, array $columns, $enableClickable = true) {
    if (!$rows) {
        echo '<div class="alert alert-warning"><i class="fa-solid fa-circle-info"></i> No data found.</div>';
        return;
    }

    echo '<div class="table-responsive"><table class="table table-hover"><thead><tr>';
    foreach ($columns as $label) echo '<th>' . h($label) . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        echo '<tr>';
        foreach (array_keys($columns) as $key) {
            $value = $row[$key] ?? '-';

            if ($key === 'status') {
                echo '<td>' . statusBadge((string)$value) . '</td>';
                continue;
            }
            if ($key === 'is_active') {
                echo '<td>' . statusBadge((int)$value === 1 ? 'active' : 'inactive') . '</td>';
                continue;
            }
            if ($key === 'days_overdue') {
                $days = (int)$value;
                $class = $days > 90 ? 'danger' : ($days > 60 ? 'warning' : ($days > 30 ? 'info' : 'secondary'));
                echo '<td><span class="badge bg-' . $class . '">' . ($days > 0 ? $days . ' days' : 'Not overdue') . '</span></td>';
                continue;
            }
            if ($key === 'customer_name') {
                $customerId = $row['customer_id'] ?? '';
                if ($enableClickable && !empty($value) && $value !== '-') {
                    echo '<td class="customer-clickable"><span class="clickable-customer" data-customer-name="' . h($value) . '" data-customer-id="' . h($customerId) . '">' . h($value) . '</span></td>';
                } else {
                    echo '<td>' . h($value) . '</td>';
                }
                continue;
            }

            $moneyKeys = ['total_amount','amount','balance','due_amount','paid_amount','debt_amount','total_spent','invoice_total','value_amount','total','subtotal','grand_total','unit_price','debit','credit','debit_balance'];
            if ($enableClickable && in_array($key, $moneyKeys, true) && is_numeric($value)) {
                if (in_array($key, ['debit','credit','debit_balance'], true)) {
                    $type = 'debit_details';
                } elseif ($key === 'amount' && array_key_exists('payment_number', $row)) {
                    $type = 'payments_amount';
                } elseif ($key === 'balance' || $key === 'due_amount') {
                    $type = 'receivables';
                } elseif ($key === 'debt_amount') {
                    $type = 'customer_debt';
                } elseif ($key === 'total_spent' || $key === 'invoice_total') {
                    $type = 'customer_total_spent';
                } elseif ($key === 'value_amount') {
                    $type = 'warehouse_value';
                } else {
                    $type = 'total_sales';
                }
                $class = in_array($key, ['balance','due_amount','debt_amount'], true) ? 'amount-neg' : 'amount-pos';

                // Row-level popup: when a money value inside a table is clicked,
                // show ONLY this row/source amount, not the whole report.
                $detailColumns = [];
                $detailRow = [];
                foreach ($columns as $rk => $rl) {
                    $detailColumns[] = (string)$rl;
                    $rv = $row[$rk] ?? '-';
                    if ($rk === 'status') {
                        $detailRow[] = statusBadge((string)$rv);
                    } elseif ($rk === 'is_active') {
                        $detailRow[] = statusBadge((int)$rv === 1 ? 'active' : 'inactive');
                    } elseif ($rk === 'days_overdue') {
                        $detailRow[] = ((int)$rv > 0 ? (int)$rv . ' days' : 'Not overdue');
                    } elseif (is_numeric($rv) && in_array($rk, $moneyKeys, true)) {
                        $detailRow[] = money($rv);
                    } else {
                        $detailRow[] = h($rv);
                    }
                }

                $detailPayload = [
                    'type' => 'single_row_amount',
                    'title' => ucwords(str_replace('_', ' ', $key)) . ' Source Detail',
                    'total' => (float)$value,
                    'clicked_label' => $columns[$key] ?? ucwords(str_replace('_', ' ', $key)),
                    'clicked_value' => money($value),
                    'columns' => $detailColumns,
                    'rows' => [$detailRow]
                ];
                $detailJson = h(json_encode($detailPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                echo "<td class=\"" . $class . "\"><span class=\"clickable-amount\" data-detail=\'" . $detailJson . "\'>" . money($value) . "</span></td>";
                continue;
            }

            if (is_numeric($value) && (strpos($key, 'amount') !== false || strpos($key, 'total') !== false || strpos($key, 'price') !== false || strpos($key, 'value') !== false || $key === 'balance')) {
                echo '<td class="amount-pos">' . money($value) . '</td>';
            } else {
                echo '<td>' . h($value) . '</td>';
            }
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function renderPaymentMethodsTable(array $methods) {
    if (!$methods) { echo '<div class="alert alert-warning">No payment data found.</div>'; return; }
    echo '<div class="table-responsive"><table class="table table-hover"><thead><tr><th>Payment Method</th><th>Count</th><th>Total Amount</th></tr></thead><tbody>';
    foreach ($methods as $m) {
        echo '<tr>';
        echo '<td>' . h(ucfirst($m['payment_method'] ?? 'Unknown')) . '</td>';
        echo '<td><span class="clickable-count" data-method="' . h($m['payment_method']) . '" data-type="payment_method_count">' . num($m['count']) . '</span></td>';
        echo '<td class="amount-pos"><span class="clickable-amount" data-detail=\'{"type":"payment_method","title":"' . h(ucfirst($m['payment_method'])) . ' Payments","method":"' . h($m['payment_method']) . '"}\'>' . money($m['total']) . '</span></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function renderAgingTable(array $buckets, $clickable = true) {
    $total = array_sum($buckets);
    if ($total == 0) {
        echo '<div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> No outstanding receivables!</div>';
        return;
    }
    echo '<div class="table-responsive"><table class="table table-hover"><thead><tr><th>Aging Bucket</th><th>Amount Due</th><th>Percentage</th><th>Progress</th></tr></thead><tbody>';
    foreach ($buckets as $bucket => $amount) {
        $percent = $total > 0 ? ($amount / $total) * 100 : 0;
        $barClass = $bucket === '0-30' ? 'bg-success' : ($bucket === '31-60' ? 'bg-warning' : ($bucket === '61-90' ? 'bg-info' : 'bg-danger'));
        echo '<tr>';
        echo '<td><strong>' . h($bucket) . ' days</strong></td>';
        if ($clickable && $amount > 0) {
            echo '<td class="amount-neg"><span class="clickable-amount" data-detail=\'{"type":"aging_bucket","title":"AR Aging - ' . h($bucket) . ' Days","bucket":"' . h($bucket) . '"}\'>' . money($amount) . '</span></td>';
        } else {
            echo '<td class="amount-neg">' . money($amount) . '</td>';
        }
        echo '<td>' . number_format($percent, 1) . '%</td>';
        echo '<td><div class="progress" style="height:8px;"><div class="progress-bar ' . $barClass . '" style="width:' . $percent . '%"></div></div></td>';
        echo '</tr>';
    }
    echo '<tr style="border-top:2px solid #dee2e6;"><td><strong>Total Due</strong></td><td class="amount-neg"><strong>' . money($total) . '</strong></td><td colspan="2"></td></tr>';
    echo '</tbody></table></div>';
}

// ============================================
// AJAX HANDLER
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_details') {
    header('Content-Type: application/json; charset=utf-8');
    
    $detailType = $_GET['detail_type'] ?? '';
    $from = $_GET['date_from'] ?? $date_from;
    $to = $_GET['date_to'] ?? $date_to;
    $bucket = $_GET['bucket'] ?? '';
    $method = $_GET['method'] ?? '';
    $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
    
    $response = ['success' => true, 'title' => 'Details', 'company' => $tenant_name, 'period' => ['from' => $from, 'to' => $to], 'columns' => [], 'rows' => [], 'total' => 0];
    
    try {
        if ($detailType === 'total_sales') {
            $response['title'] = 'Total Sales Source - Invoices';
            $response['columns'] = ['Invoice #', 'Customer', 'Date', 'Total Amount', 'Paid', 'Balance', 'Status'];
            $sql = "SELECT i.invoice_number, COALESCE(c.customer_name, '-') AS customer_name, i.invoice_date, i.total_amount, i.paid_amount,
                           GREATEST(i.total_amount - i.paid_amount,0) as balance, i.status
                    FROM invoices i 
                    LEFT JOIN customers c ON c.id = i.customer_id AND c.tenant_id = i.tenant_id
                    WHERE i.tenant_id=? AND i.invoice_date BETWEEN ? AND ? AND COALESCE(i.is_active,1)=1
                    ORDER BY i.invoice_date DESC LIMIT 500";
            $rows = qRows($pdo, $sql, [$session_tenant_id, $from, $to]);
            foreach ($rows as $r) {
                $response['rows'][] = [h($r['invoice_number']), h($r['customer_name']), safeDate($r['invoice_date']), money($r['total_amount']), money($r['paid_amount']), money($r['balance']), statusBadge($r['status'])];
                $response['total'] += (float)$r['total_amount'];
            }
        }
        elseif ($detailType === 'receivables') {
            $response['title'] = 'Accounts Receivable Details';
            $response['columns'] = ['Invoice #', 'Customer', 'Due Date', 'Total', 'Paid', 'Due', 'Days Overdue', 'Status'];
            $sql = "SELECT i.invoice_number, COALESCE(c.customer_name, '-') AS customer_name, i.due_date, i.total_amount, i.paid_amount,
                           GREATEST(i.total_amount - i.paid_amount,0) as due_amount,
                           DATEDIFF(CURDATE(), i.due_date) as days_overdue, i.status
                    FROM invoices i 
                    LEFT JOIN customers c ON c.id = i.customer_id AND c.tenant_id = i.tenant_id
                    WHERE i.tenant_id=? AND GREATEST(i.total_amount - i.paid_amount,0) > 0 AND i.status != 'cancelled' AND COALESCE(i.is_active,1)=1
                    ORDER BY i.due_date ASC LIMIT 500";
            $rows = qRows($pdo, $sql, $tenantParam);
            foreach ($rows as $r) {
                $response['rows'][] = [h($r['invoice_number']), h($r['customer_name']), safeDate($r['due_date']), money($r['total_amount']), money($r['paid_amount']), money($r['due_amount']), (int)$r['days_overdue'] . ' days', statusBadge($r['status'])];
                $response['total'] += (float)$r['due_amount'];
            }
        }
        elseif ($detailType === 'net_profit') {
            $response['title'] = 'Net Profit Breakdown';
            $response['columns'] = ['Type', 'Amount'];
            $response['rows'] = [
                ['Total Revenue', money($totalSales)],
                ['Total Expenses', money($totalExpenses)],
                ['<strong>Net Profit</strong>', '<strong>' . money($netProfit) . '</strong>']
            ];
            $response['total'] = $netProfit;
        }
        elseif ($detailType === 'warehouse_value') {
            $response['title'] = 'Warehouse Stock Value Details';
            $response['columns'] = ['Item Name', 'Customer', 'Quantity', 'Unit Price', 'Value'];
            $sql = "SELECT ws.stock_name, COALESCE(c.customer_name, '-') AS customer_name, ws.quantity, ws.unit_price,
                           (COALESCE(ws.volume_cbm,0) * COALESCE(ws.unit_price,0)) as value_amount
                    FROM warehouse_stock ws 
                    LEFT JOIN customers c ON c.id = ws.customer_id AND c.tenant_id = ws.tenant_id
                    WHERE ws.tenant_id=? ORDER BY value_amount DESC LIMIT 500";
            $rows = qRows($pdo, $sql, $tenantParam);
            foreach ($rows as $r) {
                $response['rows'][] = [h($r['stock_name']), h($r['customer_name']), num($r['quantity']), money($r['unit_price']), money($r['value_amount'])];
                $response['total'] += (float)$r['value_amount'];
            }
        }
        elseif ($detailType === 'payments_amount') {
            $response['title'] = 'Payments Details - Customer Names';
            $response['columns'] = ['Payment #', 'Customer', 'Date', 'Amount', 'Method', 'Category', 'Reference'];
            $sql = "SELECT p.payment_number, COALESCE(c.customer_name, '-') AS customer_name, p.payment_date, p.amount,
                           COALESCE(p.payment_method,'-') AS payment_method, COALESCE(p.category,'-') AS category,
                           COALESCE(p.reference_number,'-') AS reference_number
                    FROM payments p
                    LEFT JOIN customers c ON c.id = p.customer_id AND c.tenant_id = p.tenant_id
                    WHERE p.tenant_id=? AND p.payment_date BETWEEN ? AND ? AND COALESCE(p.is_active,1)=1
                    ORDER BY p.payment_date DESC, p.id DESC LIMIT 500";
            $rows = qRows($pdo, $sql, [$session_tenant_id, $from, $to]);
            foreach ($rows as $r) {
                $response['rows'][] = [h($r['payment_number']), h($r['customer_name']), safeDate($r['payment_date']), money($r['amount']), h($r['payment_method']), h($r['category']), h($r['reference_number'])];
                $response['total'] += (float)$r['amount'];
            }
        }
        elseif ($detailType === 'payment_method' && $method) {
            $response['title'] = 'Payment Method: ' . ucfirst($method);
            $response['columns'] = ['Payment #', 'Customer', 'Date', 'Amount', 'Category'];
            $sql = "SELECT p.payment_number, COALESCE(c.customer_name, '-') AS customer_name, p.payment_date, p.amount, p.category
                    FROM payments p 
                    LEFT JOIN customers c ON c.id = p.customer_id AND c.tenant_id = p.tenant_id
                    WHERE p.tenant_id=? AND p.payment_method=? AND p.payment_date BETWEEN ? AND ? AND COALESCE(p.is_active,1)=1
                    ORDER BY p.payment_date DESC LIMIT 500";
            $rows = qRows($pdo, $sql, [$session_tenant_id, $method, $from, $to]);
            foreach ($rows as $r) {
                $response['rows'][] = [h($r['payment_number']), h($r['customer_name']), safeDate($r['payment_date']), money($r['amount']), h($r['category'])];
                $response['total'] += (float)$r['amount'];
            }
        }
        elseif ($detailType === 'aging_bucket' && $bucket) {
            $response['title'] = 'AR Aging: ' . $bucket . ' Days';
            $response['columns'] = ['Invoice #', 'Customer', 'Due Date', 'Due Amount', 'Days Overdue'];
            $minDays = 0; $maxDays = 999;
            if ($bucket === '0-30') { $minDays = 0; $maxDays = 30; }
            elseif ($bucket === '31-60') { $minDays = 31; $maxDays = 60; }
            elseif ($bucket === '61-90') { $minDays = 61; $maxDays = 90; }
            elseif ($bucket === '90+') { $minDays = 90; $maxDays = 99999; }
            
            $sql = "SELECT i.invoice_number, COALESCE(c.customer_name, '-') AS customer_name, i.due_date,
                           GREATEST(i.total_amount - i.paid_amount,0) as due_amount,
                           DATEDIFF(CURDATE(), i.due_date) as days_overdue
                    FROM invoices i 
                    LEFT JOIN customers c ON c.id = i.customer_id AND c.tenant_id = i.tenant_id
                    WHERE i.tenant_id=? AND GREATEST(i.total_amount - i.paid_amount,0) > 0 AND COALESCE(i.is_active,1)=1
                    HAVING days_overdue >= ? AND days_overdue <= ?
                    ORDER BY days_overdue DESC LIMIT 500";
            $rows = qRows($pdo, $sql, [$session_tenant_id, $minDays, $maxDays]);
            foreach ($rows as $r) {
                $response['rows'][] = [h($r['invoice_number']), h($r['customer_name']), safeDate($r['due_date']), money($r['due_amount']), (int)$r['days_overdue'] . ' days'];
                $response['total'] += (float)$r['due_amount'];
            }
        }
        elseif ($detailType === 'customer_debt') {
            $response['title'] = 'Customer Debt Details';
            $response['columns'] = ['Customer', 'Phone', 'Email', 'Debt Amount'];
            $rows = qRows($pdo, "SELECT customer_name, phone, email, debt_amount FROM customers WHERE tenant_id=? AND debt_amount > 0 AND COALESCE(is_active,1)=1 ORDER BY debt_amount DESC LIMIT 500", $tenantParam);
            foreach ($rows as $r) {
                $response['rows'][] = [h($r['customer_name']), h($r['phone']), h($r['email']), money($r['debt_amount'])];
                $response['total'] += (float)$r['debt_amount'];
            }
        }
        elseif ($detailType === 'customer_debt_detail' && $customerId) {
            $response['title'] = 'Customer Debt Breakdown';
            $response['columns'] = ['Invoice #', 'Invoice Date', 'Due Date', 'Total Amount', 'Paid', 'Due Amount', 'Status'];
            $rows = qRows($pdo, "
                SELECT invoice_number, invoice_date, due_date, total_amount, paid_amount,
                       GREATEST(total_amount - paid_amount,0) as due_amount, status
                FROM invoices 
                WHERE tenant_id=? AND customer_id=? AND GREATEST(total_amount - paid_amount,0) > 0 AND COALESCE(is_active,1)=1
                ORDER BY due_date ASC
            ", [$session_tenant_id, $customerId]);
            foreach ($rows as $r) {
                $response['rows'][] = [h($r['invoice_number']), safeDate($r['invoice_date']), safeDate($r['due_date']), money($r['total_amount']), money($r['paid_amount']), money($r['due_amount']), statusBadge($r['status'])];
                $response['total'] += (float)$r['due_amount'];
            }
        }
        elseif ($detailType === 'customer_spent' && $customerId) {
            $response['title'] = 'Customer Spend Source / Why Customer Paid';
            $response['formula'] = 'This popup shows the source of the customer money: invoices, invoice items/reasons, paid amount, unpaid balance, and related payments.';
            $response['columns'] = ['Source', 'Customer', 'Source #', 'Date', 'Reason / Item / Category', 'Total / Amount', 'Paid', 'Balance', 'Status / Method'];
            $response['rows'] = [];
            $response['total'] = 0;

            if ($hasInvoices) {
                if ($hasInvoiceItems) {
                    $rows = qRows($pdo, "
                        SELECT c.customer_name, i.invoice_number, i.invoice_date, i.total_amount, i.paid_amount,
                               GREATEST(i.total_amount - i.paid_amount,0) AS balance, i.status,
                               GROUP_CONCAT(DISTINCT COALESCE(ii.item_name, ii.description) ORDER BY ii.id SEPARATOR ', ') AS reason_text
                        FROM invoices i
                        LEFT JOIN customers c ON c.id = i.customer_id AND c.tenant_id = i.tenant_id
                        LEFT JOIN invoice_items ii ON ii.invoice_id = i.id
                        WHERE i.tenant_id=? AND i.customer_id=? AND COALESCE(i.is_active,1)=1 AND i.status != 'cancelled'
                        GROUP BY c.customer_name, i.id, i.invoice_number, i.invoice_date, i.total_amount, i.paid_amount, i.status
                        ORDER BY i.invoice_date DESC, i.id DESC LIMIT 300
                    ", [$session_tenant_id, $customerId]);
                } else {
                    $rows = qRows($pdo, "
                        SELECT c.customer_name, i.invoice_number, i.invoice_date, i.total_amount, i.paid_amount,
                               GREATEST(i.total_amount - i.paid_amount,0) AS balance, i.status,
                               COALESCE(i.notes,'Invoice charge') AS reason_text
                        FROM invoices i
                        LEFT JOIN customers c ON c.id = i.customer_id AND c.tenant_id = i.tenant_id
                        WHERE i.tenant_id=? AND i.customer_id=? AND COALESCE(i.is_active,1)=1 AND i.status != 'cancelled'
                        ORDER BY i.invoice_date DESC, i.id DESC LIMIT 300
                    ", [$session_tenant_id, $customerId]);
                }
                foreach ($rows as $r) {
                    $reason = trim((string)($r['reason_text'] ?? '')) ?: 'Invoice charge';
                    $response['rows'][] = ['Invoice', h($r['customer_name']), h($r['invoice_number']), safeDate($r['invoice_date']), h($reason), money($r['total_amount']), money($r['paid_amount']), money($r['balance']), statusBadge($r['status'])];
                    $response['total'] += (float)$r['total_amount'];
                }
            }

            if ($hasPayments) {
                $rows = qRows($pdo, "
                    SELECT COALESCE(c.customer_name, '-') AS customer_name, p.payment_number, p.payment_date,
                           p.amount, COALESCE(p.payment_method,'-') AS payment_method,
                           COALESCE(p.category, p.notes, p.reference_number, 'Customer payment') AS reason_text
                    FROM payments p
                    LEFT JOIN customers c ON c.id = p.customer_id AND c.tenant_id = p.tenant_id
                    WHERE p.tenant_id=? AND p.customer_id=? AND p.payment_date BETWEEN ? AND ? AND COALESCE(p.is_active,1)=1
                    ORDER BY p.payment_date DESC, p.id DESC LIMIT 300
                ", [$session_tenant_id, $customerId, $from, $to]);
                foreach ($rows as $r) {
                    $response['rows'][] = ['Payment', h($r['customer_name']), h($r['payment_number']), safeDate($r['payment_date']), h($r['reason_text']), money($r['amount']), money($r['amount']), money(0), h($r['payment_method'])];
                }
            }
        }
        elseif ($detailType === 'customer_total_spent') {
            $response['title'] = 'Customer Total Spend - Source Details';
            $response['formula'] = 'Total Customer Spend = invoice totals by customer. Rows show why/how the amount came: invoice items or notes, paid amount, balance, and status.';
            $response['columns'] = ['Customer', 'Phone', 'Invoice #', 'Invoice Date', 'Reason / Item', 'Invoice Total', 'Paid', 'Balance', 'Status'];
            $response['rows'] = [];
            $response['total'] = 0;

            if ($hasCustomers && $hasInvoices) {
                if ($hasInvoiceItems) {
                    $rows = qRows($pdo, "
                        SELECT c.customer_name, c.phone, i.invoice_number, i.invoice_date, i.total_amount, i.paid_amount,
                               GREATEST(i.total_amount - i.paid_amount,0) AS balance, i.status,
                               GROUP_CONCAT(DISTINCT COALESCE(ii.item_name, ii.description) ORDER BY ii.id SEPARATOR ', ') AS reason_text
                        FROM invoices i
                        LEFT JOIN customers c ON c.id = i.customer_id AND c.tenant_id = i.tenant_id
                        LEFT JOIN invoice_items ii ON ii.invoice_id = i.id
                        WHERE i.tenant_id=? AND i.invoice_date BETWEEN ? AND ? AND COALESCE(i.is_active,1)=1 AND i.status != 'cancelled'
                        GROUP BY c.customer_name, c.phone, i.id, i.invoice_number, i.invoice_date, i.total_amount, i.paid_amount, i.status
                        HAVING total_amount > 0
                        ORDER BY i.invoice_date DESC, i.id DESC LIMIT 500
                    ", [$session_tenant_id, $from, $to]);
                } else {
                    $rows = qRows($pdo, "
                        SELECT c.customer_name, c.phone, i.invoice_number, i.invoice_date, i.total_amount, i.paid_amount,
                               GREATEST(i.total_amount - i.paid_amount,0) AS balance, i.status,
                               COALESCE(i.notes,'Invoice charge') AS reason_text
                        FROM invoices i
                        LEFT JOIN customers c ON c.id = i.customer_id AND c.tenant_id = i.tenant_id
                        WHERE i.tenant_id=? AND i.invoice_date BETWEEN ? AND ? AND COALESCE(i.is_active,1)=1 AND i.status != 'cancelled'
                        ORDER BY i.invoice_date DESC, i.id DESC LIMIT 500
                    ", [$session_tenant_id, $from, $to]);
                }
                foreach ($rows as $r) {
                    $reason = trim((string)($r['reason_text'] ?? '')) ?: 'Invoice charge';
                    $response['rows'][] = [h($r['customer_name']), h($r['phone']), h($r['invoice_number']), safeDate($r['invoice_date']), h($reason), money($r['total_amount']), money($r['paid_amount']), money($r['balance']), statusBadge($r['status'])];
                    $response['total'] += (float)$r['total_amount'];
                }
            }

            // Fallback: if there are no invoice rows, show customers.total_spent so popup never lies with empty data.
            if (empty($response['rows']) && $hasCustomers) {
                $rows = qRows($pdo, "
                    SELECT customer_name, phone, COALESCE(total_spent,0) AS total_spent
                    FROM customers
                    WHERE tenant_id=? AND COALESCE(is_active,1)=1 AND COALESCE(total_spent,0) > 0
                    ORDER BY total_spent DESC LIMIT 500
                ", $tenantParam);
                foreach ($rows as $r) {
                    $response['rows'][] = [h($r['customer_name']), h($r['phone']), '-', '-', 'Stored customer total_spent value - no invoice source found', money($r['total_spent']), '-', '-', '-'];
                    $response['total'] += (float)$r['total_spent'];
                }
            }
        }
        elseif ($detailType === 'debit_details') {
            $response['title'] = 'Debit Details - Customer / Supplier Names';
            $response['formula'] = 'Debit = journal debit entries joined to payments/customers. If journal entries are empty, it uses payments + expenses.';
            $response['columns'] = ['Source #', 'Customer / Supplier', 'Date', 'Account / Category', 'Debit', 'Credit', 'Balance', 'Description'];
            $response['rows'] = [];
            $response['total'] = 0;
            if ($hasJournalEntries) {
                $rows = qRows($pdo, "
                    SELECT je.entry_number, je.entry_date, je.account_code, je.account_name, je.debit, je.credit,
                           (je.debit-je.credit) AS debit_balance, je.description,
                           COALESCE(c.customer_name, p.supplier_name, '-') AS customer_name
                    FROM journal_entries je
                    LEFT JOIN payments p ON p.tenant_id = je.tenant_id
                        AND (
                            (LOWER(COALESCE(je.reference_type,'')) IN ('payment','payments') AND p.id = je.reference_id)
                            OR p.payment_number = je.entry_number
                            OR je.description LIKE CONCAT('%', p.payment_number, '%')
                        )
                    LEFT JOIN customers c ON c.id = p.customer_id AND c.tenant_id = p.tenant_id
                    WHERE je.tenant_id=? AND je.entry_date BETWEEN ? AND ? AND COALESCE(je.debit,0) > 0
                    GROUP BY je.id
                    ORDER BY je.entry_date DESC, je.id DESC LIMIT 500
                ", [$session_tenant_id, $from, $to]);
                foreach ($rows as $r) {
                    $response['rows'][] = [h($r['entry_number']), h($r['customer_name']), safeDate($r['entry_date']), h(($r['account_code'] ? $r['account_code'].' - ' : '').$r['account_name']), money($r['debit']), money($r['credit']), money($r['debit_balance']), h($r['description'])];
                    $response['total'] += (float)$r['debit'];
                }
            }
            if (empty($response['rows'])) {
                if ($hasPayments) {
                    $rows = qRows($pdo, "
                        SELECT p.payment_number, COALESCE(c.customer_name, p.supplier_name, '-') AS customer_name,
                               p.payment_date, COALESCE(p.category,'Payment / Outgoing') AS category,
                               p.amount, COALESCE(p.payment_method,'') AS payment_method, COALESCE(p.reference_number,'') AS reference_number
                        FROM payments p
                        LEFT JOIN customers c ON c.id = p.customer_id AND c.tenant_id = p.tenant_id
                        WHERE p.tenant_id=? AND p.payment_date BETWEEN ? AND ? AND COALESCE(p.is_active,1)=1
                        ORDER BY p.payment_date DESC, p.id DESC LIMIT 500
                    ", [$session_tenant_id, $from, $to]);
                    foreach ($rows as $r) {
                        $response['rows'][] = [h($r['payment_number']), h($r['customer_name']), safeDate($r['payment_date']), h($r['category']), money($r['amount']), money(0), money($r['amount']), h(trim($r['payment_method'].' '.$r['reference_number']))];
                        $response['total'] += (float)$r['amount'];
                    }
                }
                if ($hasExpenses) {
                    $rows = qRows($pdo, "
                        SELECT expense_number, COALESCE(vendor_name,'-') AS customer_name, expense_date,
                               COALESCE(expense_category,'Expense') AS category, amount, COALESCE(notes,'') AS notes
                        FROM expenses
                        WHERE tenant_id=? AND expense_date BETWEEN ? AND ? AND COALESCE(is_active,1)=1
                        ORDER BY expense_date DESC, id DESC LIMIT 500
                    ", [$session_tenant_id, $from, $to]);
                    foreach ($rows as $r) {
                        $response['rows'][] = [h($r['expense_number']), h($r['customer_name']), safeDate($r['expense_date']), h($r['category']), money($r['amount']), money(0), money($r['amount']), h($r['notes'])];
                        $response['total'] += (float)$r['amount'];
                    }
                }
            }
        }
        elseif ($detailType === 'pl_revenue') {
            $response['title'] = 'Profit & Loss Revenue - How It Was Calculated';
            $response['formula'] = 'Total Revenue = SUM(revenue account credits - debits) OR SUM(invoices.total_amount).';
            $response['columns'] = ['Source', 'Account / Invoice', 'Date', 'Customer / Description', 'Amount'];
            $response['rows'] = [];
            $response['total'] = 0;
            if ($hasJournalEntries) {
                $rows = qRows($pdo, "
                    SELECT entry_date, entry_number, account_code, account_name, description, (credit-debit) AS amount
                    FROM journal_entries
                    WHERE tenant_id=? AND entry_date BETWEEN ? AND ?
                      AND (account_code LIKE '4%' OR LOWER(account_name) LIKE '%revenue%' OR LOWER(account_name) LIKE '%sales%')
                    ORDER BY entry_date DESC LIMIT 500
                ", [$session_tenant_id, $from, $to]);
                foreach ($rows as $r) {
                    $response['rows'][] = ['journal_entries', h(($r['account_code'] ? $r['account_code'].' - ' : '').$r['account_name']), safeDate($r['entry_date']), h(($r['entry_number'] ?? '').' '.$r['description']), money($r['amount'])];
                    $response['total'] += (float)$r['amount'];
                }
            } else {
                $sql = "SELECT i.invoice_number, COALESCE(c.customer_name, '-') AS customer_name, i.invoice_date, i.total_amount
                        FROM invoices i 
                        LEFT JOIN customers c ON c.id = i.customer_id AND c.tenant_id = i.tenant_id
                        WHERE i.tenant_id=? AND i.invoice_date BETWEEN ? AND ? AND COALESCE(i.is_active,1)=1
                        ORDER BY i.invoice_date DESC LIMIT 500";
                $rows = qRows($pdo, $sql, [$session_tenant_id, $from, $to]);
                foreach ($rows as $r) {
                    $response['rows'][] = ['invoices', h($r['invoice_number']), safeDate($r['invoice_date']), h($r['customer_name']), money($r['total_amount'])];
                    $response['total'] += (float)$r['total_amount'];
                }
            }
        }
        elseif ($detailType === 'pl_expenses') {
            $response['title'] = 'Profit & Loss Expenses - How It Was Calculated';
            $response['formula'] = 'Total Expenses = SUM(expense account debits - credits) OR SUM(expenses.amount + payments.amount).';
            $response['columns'] = ['Source', 'Category / Account', 'Date', 'Description', 'Amount'];
            $response['rows'] = [];
            $response['total'] = 0;
            if ($hasJournalEntries) {
                $rows = qRows($pdo, "
                    SELECT entry_date, entry_number, account_code, account_name, description, (debit-credit) AS amount
                    FROM journal_entries
                    WHERE tenant_id=? AND entry_date BETWEEN ? AND ?
                      AND (account_code LIKE '5%' OR LOWER(account_name) LIKE '%expense%' OR LOWER(account_name) LIKE '%cost%')
                    ORDER BY entry_date DESC LIMIT 500
                ", [$session_tenant_id, $from, $to]);
                foreach ($rows as $r) {
                    $response['rows'][] = ['journal_entries', h(($r['account_code'] ? $r['account_code'].' - ' : '').$r['account_name']), safeDate($r['entry_date']), h(($r['entry_number'] ?? '').' '.$r['description']), money($r['amount'])];
                    $response['total'] += (float)$r['amount'];
                }
            } else {
                if ($hasExpenses) {
                    $rows = qRows($pdo, "SELECT expense_category, amount, expense_date, vendor_name FROM expenses WHERE tenant_id=? AND expense_date BETWEEN ? AND ? AND COALESCE(is_active,1)=1 ORDER BY expense_date DESC LIMIT 500", [$session_tenant_id, $from, $to]);
                    foreach ($rows as $r) {
                        $response['rows'][] = ['expenses', h($r['expense_category']), safeDate($r['expense_date']), h($r['vendor_name']), money($r['amount'])];
                        $response['total'] += (float)$r['amount'];
                    }
                }
                if ($hasPayments) {
                    $rows = qRows($pdo, "SELECT category, amount, payment_date, supplier_name, payment_number FROM payments WHERE tenant_id=? AND payment_date BETWEEN ? AND ? AND COALESCE(is_active,1)=1 ORDER BY payment_date DESC LIMIT 500", [$session_tenant_id, $from, $to]);
                    foreach ($rows as $r) {
                        $response['rows'][] = ['payments', h($r['category'] ?: 'Payment'), safeDate($r['payment_date']), h(($r['payment_number'] ?? '').' '.$r['supplier_name']), money($r['amount'])];
                        $response['total'] += (float)$r['amount'];
                    }
                }
            }
        }
        elseif ($detailType === 'pl_net_profit') {
            $response['title'] = 'Profit / Loss Calculation';
            $response['formula'] = 'Final Profit/Loss = Total Revenue - Total Expenses';
            $response['columns'] = ['Step', 'Formula', 'Amount'];
            $response['rows'] = [
                ['1', 'Total Revenue', money($plTotalRevenue)],
                ['2', 'Total Expenses', money($plTotalExpenses)],
                ['3', money($plTotalRevenue) . ' - ' . money($plTotalExpenses), '<strong>' . money($plNetProfit) . '</strong>']
            ];
            $response['total'] = $plNetProfit;
        }
        elseif ($detailType === 'bs_assets' || $detailType === 'bs_liabilities' || $detailType === 'bs_equity') {
            $kind = $detailType === 'bs_assets' ? 'Assets' : ($detailType === 'bs_liabilities' ? 'Liabilities' : 'Equity');
            $sourceRows = $detailType === 'bs_assets' ? $balanceAssetRows : ($detailType === 'bs_liabilities' ? $balanceLiabilityRows : $balanceEquityRows);
            $response['title'] = 'Balance Sheet ' . $kind . ' - How It Was Calculated';
            $response['formula'] = 'Balance Sheet equation: Assets = Liabilities + Equity';
            $response['columns'] = ['Account Code', 'Account Name', 'Type', 'Balance'];
            foreach ($sourceRows as $r) {
                $response['rows'][] = [h($r['account_code'] ?? ''), h($r['account_name'] ?? ''), h($r['account_type'] ?? strtolower($kind)), money($r['balance'] ?? 0)];
                $response['total'] += (float)($r['balance'] ?? 0);
            }
        }
        else {
            $response['success'] = false;
            $response['message'] = 'Unknown detail type';
        }
        
        echo json_encode($response);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// EXPORT HANDLER
// ============================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $report . '_report_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    
    fputcsv($out, [strtoupper($report) . ' REPORT - ' . $tenant_name]);
    fputcsv($out, ['Period:', safeDate($date_from) . ' - ' . safeDate($date_to)]);
    fputcsv($out, ['Generated:', date('d/m/Y H:i:s')]);
    fputcsv($out, []);
    
    $exportData = match($report) {
        'sales' => ['SALES REPORT', ['Invoice #','Customer','Date','Due Date','Total','Paid','Balance','Status'], $salesRows],
        'payments' => ['PAYMENTS REPORT', ['Payment #','Customer','Date','Amount','Method','Category'], $paymentRows],
        'debit' => ['DEBIT REPORT', ['Entry #','Customer','Date','Account','Debit','Credit','Balance','Description'], $debitRows],
        'receivables' => ['RECEIVABLES REPORT', ['Invoice #','Customer','Due Date','Total','Paid','Due','Days Overdue','Status'], $receivableRows],
        'inventory','warehouse' => ['INVENTORY REPORT', ['Item','Customer','Quantity','CBM','Unit Price','Value','Origin'], $inventoryRows],
        'customers' => ['CUSTOMER REPORT', ['Customer','Phone','Email','Debt','Total Spent','Invoices','Status'], $customerRows],
        'containers' => ['CONTAINER REPORT', ['Container #','Type','Origin','Status','Location','CBM','ETA'], $containerRows],
        'sms' => ['SMS REPORT', $hasSMSLogs ? ['Phone','Message','Status','Date'] : ['Campaign','Message','Recipients','Sent','Delivered','Failed','Status','Date'], $smsRows],
        'profit_loss' => ['PROFIT & LOSS REPORT', ['Account Code','Account Name','Entries','Amount'], array_merge($plRevenueRows, $plExpenseRows, [['account_code'=>'','account_name'=>$plFinalLabel,'entry_count'=>'','amount'=>$plNetProfit]])],
        'balance_sheet' => ['BALANCE SHEET REPORT', ['Account Code','Account Name','Type','Balance'], array_merge($balanceAssetRows, $balanceLiabilityRows, $balanceEquityRows)],
        default => ['OVERVIEW', ['Metric','Value'], [['Total Sales',$totalSales],['Net Profit',$netProfit],['Receivables',$totalReceivable],['Warehouse Value',$warehouseValue],['Total Customers',$totalCustomers],['Total Containers',$totalContainers],['Total SMS',$totalSMS]]]
    };
    
    fputcsv($out, [$exportData[0]]);
    fputcsv($out, $exportData[1]);
    foreach ($exportData[2] as $row) {
        $line = [];
        foreach ($exportData[1] as $col) {
            $key = match($col) {
                'Invoice #','Invoice No' => $row['invoice_number'] ?? '',
                'Customer' => $row['customer_name'] ?? '',
                'Date','Invoice Date' => $row['invoice_date'] ?? '',
                'Due Date' => $row['due_date'] ?? '',
                'Total','Total Amount','Amount' => $row['total_amount'] ?? $row['amount'] ?? '',
                'Paid' => $row['paid_amount'] ?? '',
                'Balance' => $row['balance'] ?? '',
                'Status' => $row['status'] ?? '',
                'Payment #' => $row['payment_number'] ?? '',
                'Entry #' => $row['entry_number'] ?? '',
                'Method' => $row['payment_method'] ?? '',
                'Category' => $row['category'] ?? '',
                'Account' => $row['account_name'] ?? '',
                'Debit' => $row['debit'] ?? '',
                'Credit' => $row['credit'] ?? '',
                'Description' => $row['description'] ?? '',
                'Days Overdue' => $row['days_overdue'] ?? '',
                'Item','Stock Name' => $row['stock_name'] ?? '',
                'Quantity' => $row['quantity'] ?? '',
                'CBM' => $row['volume_cbm'] ?? '',
                'Unit Price' => $row['unit_price'] ?? '',
                'Value' => $row['value_amount'] ?? '',
                'Origin' => $row['origin'] ?? '',
                'Phone' => $row['phone'] ?? '',
                'Email' => $row['email'] ?? '',
                'Debt' => $row['debt_amount'] ?? '',
                'Total Spent' => $row['total_spent'] ?? '',
                'Invoices' => $row['invoice_count'] ?? '',
                'Container #' => $row['container_number'] ?? '',
                'Type' => $row['container_type'] ?? '',
                'Location' => $row['current_location'] ?? '',
                'ETA' => $row['estimated_arrival'] ?? '',
                'Message' => $row['message'] ?? $row['message_text'] ?? '',
                'Campaign' => $row['campaign_name'] ?? '',
                'Recipients' => $row['total_recipients'] ?? '',
                'Sent' => $row['total_sent'] ?? '',
                'Delivered' => $row['total_delivered'] ?? '',
                'Failed' => $row['total_failed'] ?? '',
                'Account Code' => $row['account_code'] ?? '',
                'Account Name' => $row['account_name'] ?? '',
                'Entries' => $row['entry_count'] ?? '',
                'Type' => $row['account_type'] ?? '',
                'Balance' => $row['balance'] ?? '',
                default => ''
            };
            $line[] = is_numeric($key) ? $key : h($key);
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

// ============================================
// INCLUDE HEADER
// ============================================
$page_title = "Complete Business Report";
$header_path = __DIR__ . '/../includes/header.php';
if (file_exists($header_path)) {
    require_once $header_path;
} else {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?= h($page_title) ?> - <?= h($tenant_name) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
        <style>
            :root { --brand: #2D1859; --brand2: #4B2C85; }
            body { background: #f4f6fb; font-family: 'Segoe UI', system-ui, sans-serif; }
            .navbar-brand { font-weight: 800; color: var(--brand); }
        </style>
    </head>
    <body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand" href="../dashboard.php">
                <i class="fa-solid fa-chart-line"></i> Cargo Management System
            </a>
            <div class="ms-auto">
                <span class="text-muted"><?= h($user_name) ?></span>
                <a href="../logout.php" class="btn btn-sm btn-outline-danger ms-2"><i class="fa-solid fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </nav>
    <?php
}
?>

<!-- Main Content -->
<style>
    :root { --brand: #2D1859; --brand2: #4B2C85; --soft: #f7f3fa; --border: #e8dff0; }
    body { background: #f4f6fb; font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; color: #1f2937; }
    .page { padding: 22px; }
    .report-header { background: linear-gradient(135deg, var(--brand), var(--brand2)); color: #fff; border-radius: 22px; padding: 24px; margin-bottom: 25px; box-shadow: 0 12px 30px rgba(45,24,89,.2); }
    .report-header h1 { font-size: 28px; font-weight: 800; margin: 0; }
    .filter-card, .cardx { background: #fff; border: 1px solid var(--border); border-radius: 18px; box-shadow: 0 8px 24px rgba(15,23,42,.06); }
    .filter-card { padding: 18px; margin: 18px 0; }
    .metric { padding: 20px; border-radius: 18px; background: #fff; border: 1px solid var(--border); height: 100%; transition: all 0.3s; }
    .metric:hover { transform: translateY(-3px); box-shadow: 0 12px 30px rgba(0,0,0,.1); }
    .metric .icon { width: 46px; height: 46px; border-radius: 14px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 20px; }
    .metric .label { color: #6b7280; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
    .metric .value { font-size: 24px; font-weight: 900; margin-top: 4px; }
    .tabs { display: flex; gap: 10px; flex-wrap: wrap; margin: 18px 0; }
    .tabs a { padding: 10px 20px; border-radius: 40px; background: #fff; border: 1px solid var(--border); color: #374151; text-decoration: none; font-weight: 700; transition: all 0.3s; }
    .tabs a.active, .tabs a:hover { background: var(--brand); color: #fff; border-color: var(--brand); }
    .cardx { padding: 20px; margin-bottom: 20px; }
    .section-title { font-size: 18px; font-weight: 900; color: var(--brand); margin-bottom: 16px; border-left: 4px solid var(--brand); padding-left: 12px; }
    .table thead th { background: var(--brand); color: #fff; border: 0; font-size: 13px; font-weight: 600; white-space: nowrap; }
    .table td { vertical-align: middle; font-size: 13px; }
    .amount-pos { color: #07803b; font-weight: 800; }
    .amount-neg { color: #c02626; font-weight: 800; }
    .clickable-amount, .clickable-count, .clickable-customer { cursor: pointer; text-decoration: underline dotted; color: var(--brand); font-weight: 700; transition: all 0.2s; }
    .clickable-amount:hover, .clickable-count:hover, .clickable-customer:hover { background: rgba(82,0,102,0.1); padding: 2px 4px; border-radius: 6px; }
    .chart-box { height: 310px; }
    .btn-brand { background: var(--brand); color: #fff; border: 0; padding: 10px 20px; border-radius: 12px; font-weight: 600; }
    .btn-brand:hover { background: #390047; color: #fff; }
    .small-note { color: #6b7280; font-size: 12px; margin-top: 20px; text-align: center; }
    .modal-xl { max-width: 1200px; }
    .modal-header { background: linear-gradient(135deg, var(--brand), var(--brand2)); color: #fff; border-radius: 16px 16px 0 0; }
    .modal-header .btn-close { filter: brightness(0) invert(1); }
    .badge { padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
    .bg-success { background: #EEFBF3 !important; color: #155724; }
    .bg-danger { background: #f8d7da !important; color: #721c24; }
    .bg-warning { background: #fff3cd !important; color: #856404; }
    .bg-info { background: #d1ecf1 !important; color: #0c5460; }
    .customer-detail-card { background: #f8f9fa; padding: 15px; border-radius: 12px; margin-bottom: 20px; border-left: 4px solid var(--brand); }
    .text-brand { color: var(--brand); font-weight: 700; }
    .metric-sm { margin-top: 10px; }
    .metric-sm .label { font-size: 11px; color: #6b7280; }
    .metric-sm .value { font-size: 18px; font-weight: 800; }
    @media print {
        .no-print { display: none !important; }
        body { background: #fff; }
        .page { padding: 0; }
        .report-header, .cardx, .metric { box-shadow: none; border: 1px solid #ddd; }
        .tabs { display: none; }
        .chart-box { height: 220px; }
        .clickable-amount, .clickable-customer { text-decoration: none; color: #000; }
    }
    @media (max-width: 768px) {
        .page { padding: 12px; }
        .metric .value { font-size: 18px; }
        .tabs a { padding: 6px 12px; font-size: 12px; }
    }
</style>

<div class="page">
    <!-- Report Header -->
    <div class="report-header d-flex justify-content-between align-items-center flex-wrap gap-3 no-print">
        <div>
            <h1><i class="fa-solid fa-chart-pie"></i> Complete Business Report</h1>
            <div class="mt-2 opacity-75"><i class="fa-regular fa-calendar"></i> <?= safeDate($date_from) ?> - <?= safeDate($date_to) ?> | <i class="fa-regular fa-building"></i> <?= h($tenant_name) ?></div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-light" href="?<?= h(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>"><i class="fa-solid fa-file-csv"></i> CSV Export</a>
            <button class="btn btn-light" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        </div>
    </div>

    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4" style="border-bottom: 2px solid #2D1859; padding-bottom: 15px;">
        <h2 style="color:#2D1859;"><?= h($tenant_name) ?> - Business Report</h2>
        <p>Period: <?= safeDate($date_from) ?> - <?= safeDate($date_to) ?> | Generated: <?= date('d/m/Y H:i:s') ?></p>
    </div>

    <!-- Filter Bar -->
    <div class="filter-card no-print">
        <form method="get" class="row g-3 align-items-end">
            <input type="hidden" name="report" value="<?= h($report) ?>">
            <div class="col-md-3"><label class="form-label fw-bold"><i class="fa-regular fa-calendar"></i> From Date</label><input type="date" name="date_from" value="<?= h($date_from) ?>" class="form-control"></div>
            <div class="col-md-3"><label class="form-label fw-bold"><i class="fa-regular fa-calendar"></i> To Date</label><input type="date" name="date_to" value="<?= h($date_to) ?>" class="form-control"></div>
            <div class="col-md-2"><button class="btn btn-brand w-100"><i class="fa-solid fa-filter"></i> Apply</button></div>
            <div class="col-md-2"><a href="?report=<?= h($report) ?>" class="btn btn-outline-secondary w-100"><i class="fa-solid fa-rotate-left"></i> Reset</a></div>
        </form>
    </div>

    <!-- Tab Navigation -->
    <div class="tabs no-print">
        <?php foreach (['overview'=>'Overview','sales'=>'Sales','payments'=>'Payments','debit'=>'Debit','receivables'=>'Receivables','inventory'=>'Inventory','customers'=>'Customers','containers'=>'Containers','warehouse'=>'Warehouse','sms'=>'SMS','profit_loss'=>'Profit & Loss','balance_sheet'=>'Balance Sheet'] as $key=>$label): ?>
            <a class="<?= $key === $report ? 'active' : '' ?>" href="?<?= h(http_build_query(array_merge($_GET, ['report'=>$key, 'export'=>null]))) ?>">
                <?= h($label) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- ============================================ -->
    <!-- OVERVIEW TAB -->
    <!-- ============================================ -->
    <?php if ($report === 'overview'): ?>
        <div class="row g-3">
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="metric">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><div class="label">Total Sales</div><div class="value clickable-amount" data-detail='{"type":"total_sales","title":"Total Sales Source"}'> <?= money($totalSales) ?></div></div>
                        <div class="icon bg-primary"><i class="fa-solid fa-chart-line"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="metric">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><div class="label">Net Profit</div><div class="value <?= $netProfit >= 0 ? 'amount-pos' : 'amount-neg' ?> clickable-amount" data-detail='{"type":"net_profit","title":"Net Profit Breakdown"}'> <?= money($netProfit) ?></div></div>
                        <div class="icon bg-<?= $netProfit >= 0 ? 'success' : 'danger' ?>"><i class="fa-solid fa-chart-pie"></i></div>
                    </div>
                    <small class="text-muted">Margin: <?= number_format($profitMargin, 1) ?>%</small>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="metric">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><div class="label">Receivables</div><div class="value amount-neg clickable-amount" data-detail='{"type":"receivables","title":"Accounts Receivable"}'> <?= money($totalReceivable) ?></div></div>
                        <div class="icon bg-danger"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="metric">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><div class="label">Warehouse Value</div><div class="value clickable-amount" data-detail='{"type":"warehouse_value","title":"Warehouse Stock Value"}'> <?= money($warehouseValue) ?></div></div>
                        <div class="icon bg-info"><i class="fa-solid fa-warehouse"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="metric">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><div class="label">Total Customers</div><div class="value clickable-customer" data-customer-name="all" data-customer-id="all"><?= num($totalCustomers) ?></div></div>
                        <div class="icon bg-secondary"><i class="fa-solid fa-users"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="metric">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><div class="label">Total Containers</div><div class="value"><?= num($totalContainers) ?></div></div>
                        <div class="icon bg-warning"><i class="fa-solid fa-ship"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="metric">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><div class="label">Total SMS</div><div class="value"><?= num($totalSMS) ?></div></div>
                        <div class="icon bg-dark"><i class="fa-solid fa-message"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="metric">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><div class="label">Customer Debt</div><div class="value amount-neg clickable-amount" data-detail='{"type":"customer_debt","title":"Customer Debt Details"}'> <?= money($totalCustomerDebt) ?></div></div>
                        <div class="icon bg-danger"><i class="fa-solid fa-credit-card"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-3">
            <div class="col-lg-8"><div class="cardx"><div class="section-title"><i class="fa-solid fa-chart-line"></i> Monthly Revenue Trend</div><div class="chart-box"><canvas id="monthlySalesChart"></canvas></div></div></div>
            <div class="col-lg-4"><div class="cardx"><div class="section-title"><i class="fa-solid fa-chart-pie"></i> Container Status</div><div class="chart-box"><canvas id="containerStatusChart"></canvas></div></div></div>
        </div>

        <div class="row">
            <div class="col-lg-6"><div class="cardx"><div class="section-title"><i class="fa-solid fa-file-invoice"></i> Recent Invoices</div><?php renderTable($recentInvoices, ['invoice_number'=>'Invoice #','customer_name'=>'Customer','invoice_date'=>'Date','total_amount'=>'Total','status'=>'Status']); ?></div></div>
            <div class="col-lg-6"><div class="cardx"><div class="section-title"><i class="fa-solid fa-credit-card"></i> Recent Payments</div><?php renderTable($recentPayments, ['payment_number'=>'Payment #','customer_name'=>'Customer','payment_date'=>'Date','amount'=>'Amount','payment_method'=>'Method']); ?></div></div>
        </div>
        <div class="row">
            <div class="col-lg-6"><div class="cardx"><div class="section-title"><i class="fa-solid fa-trophy"></i> Top Customers</div><?php renderTable($topCustomers, ['customer_name'=>'Customer','phone'=>'Phone','total_spent'=>'Total Spent','debt_amount'=>'Debt','invoice_count'=>'Invoices']); ?></div></div>
            <div class="col-lg-6"><div class="cardx"><div class="section-title"><i class="fa-solid fa-chart-simple"></i> AR Aging Summary</div><?php renderAgingTable($agingBuckets); ?></div></div>
        </div>
    <?php endif; ?>

    <!-- ============================================ -->
    <!-- SALES TAB -->
    <!-- ============================================ -->
    <?php if ($report === 'sales'): ?>
        <div class="row g-3 mb-3">
            <div class="col-md-3"><div class="metric"><div class="label">Total Sales</div><div class="value amount-pos clickable-amount" data-detail='{"type":"total_sales","title":"Total Sales Source"}'> <?= money($totalSales) ?></div></div></div>
            <div class="col-md-3"><div class="metric"><div class="label">Paid Sales</div><div class="value amount-pos"><?= money($paidSales) ?></div></div></div>
            <div class="col-md-3"><div class="metric"><div class="label">Unpaid Sales</div><div class="value amount-neg clickable-amount" data-detail='{"type":"receivables","title":"Unpaid Sales Details"}'> <?= money($unpaidSales) ?></div></div></div>
            <div class="col-md-3"><div class="metric"><div class="label">Invoice Count</div><div class="value"><?= num($invoiceCount) ?></div></div></div>
        </div>
        <div class="cardx"><div class="section-title"><i class="fa-solid fa-table-list"></i> Sales Report (<?= safeDate($date_from) ?> - <?= safeDate($date_to) ?>)</div><?php renderTable($salesRows, ['invoice_number'=>'Invoice #','customer_name'=>'Customer','invoice_date'=>'Date','due_date'=>'Due Date','total_amount'=>'Total','paid_amount'=>'Paid','balance'=>'Balance','status'=>'Status'], true); ?></div>
    <?php endif; ?>

    <!-- ============================================ -->
    <!-- PAYMENTS TAB -->
    <!-- ============================================ -->
    <?php if ($report === 'payments'): ?>
        <div class="row g-3 mb-3">
            <div class="col-md-4"><div class="metric"><div class="label">Total Payments</div><div class="value clickable-amount" data-detail='{"type":"payments_amount","title":"Payment Details"}'> <?= money($totalPayments) ?></div></div></div>
            <div class="col-md-4"><div class="metric"><div class="label">Cash Inflow</div><div class="value amount-pos clickable-amount" data-detail='{"type":"total_sales","title":"Cash Inflow Source"}'> <?= money($totalReceipts) ?></div></div></div>
            <div class="col-md-4"><div class="metric"><div class="label">Net Cash Flow</div><div class="value <?= ($totalReceipts-$totalPayments)>=0?'amount-pos':'amount-neg' ?>"><?= money($totalReceipts-$totalPayments) ?></div></div></div>
        </div>
        <div class="row">
            <div class="col-lg-5"><div class="cardx"><div class="section-title"><i class="fa-solid fa-chart-pie"></i> Payment Methods</div><div class="chart-box"><canvas id="paymentMethodsChart"></canvas></div></div></div>
            <div class="col-lg-7"><div class="cardx"><div class="section-title"><i class="fa-solid fa-table-list"></i> Payment Methods Breakdown</div><?php renderPaymentMethodsTable($paymentMethods); ?></div></div>
        </div>
        <div class="cardx mt-2"><div class="section-title"><i class="fa-solid fa-list"></i> Payment Transactions</div><?php renderTable($paymentRows, ['payment_number'=>'Payment #','customer_name'=>'Customer','payment_date'=>'Date','amount'=>'Amount','payment_method'=>'Method','category'=>'Category']); ?></div>
    <?php endif; ?>

    <!-- ============================================ -->
    <!-- DEBIT TAB -->
    <!-- ============================================ -->
    <?php if ($report === 'debit'): ?>
        <div class="row g-3 mb-3">
            <div class="col-md-4"><div class="metric"><div class="label">Total Debit</div><div class="value amount-neg clickable-amount" data-detail='{"type":"debit_details","title":"Debit Details"}'><?= money($totalDebit) ?></div></div></div>
            <div class="col-md-4"><div class="metric"><div class="label">Debit Records</div><div class="value"><?= num(count($debitRows)) ?></div></div></div>
            <div class="col-md-4"><div class="metric"><div class="label">Source</div><div class="value"><?= $hasJournalEntries ? 'journal_entries' : 'payments + expenses' ?></div></div></div>
        </div>
        <div class="cardx">
            <div class="section-title"><i class="fa-solid fa-arrow-down-wide-short"></i> Debit Report</div>
            <?php renderTable($debitRows, ['entry_number'=>'Entry #','customer_name'=>'Customer / Supplier','entry_date'=>'Date','account_code'=>'Code','account_name'=>'Account / Category','debit'=>'Debit','credit'=>'Credit','debit_balance'=>'Balance','description'=>'Description'], true); ?>
        </div>
    <?php endif; ?>

    <!-- ============================================ -->
    <!-- RECEIVABLES TAB -->
    <!-- ============================================ -->
    <?php if ($report === 'receivables'): ?>
        <div class="row g-3 mb-3">
            <div class="col-md-4"><div class="metric"><div class="label">Total Receivables</div><div class="value amount-neg clickable-amount" data-detail='{"type":"receivables","title":"All Receivables"}'> <?= money($totalReceivable) ?></div></div></div>
            <div class="col-md-4"><div class="metric"><div class="label">Overdue Invoices</div><div class="value amount-neg"><?= num($overdueInvoiceCount) ?></div></div></div>
            <div class="col-md-4"><div class="metric"><div class="label">Unpaid Balance</div><div class="value amount-neg clickable-amount" data-detail='{"type":"receivables","title":"Unpaid Invoices"}'> <?= money($unpaidSales) ?></div></div></div>
        </div>
        <div class="cardx"><div class="section-title"><i class="fa-solid fa-chart-simple"></i> AR Aging Summary</div><?php renderAgingTable($agingBuckets, true); ?></div>
        <div class="cardx mt-2"><div class="section-title"><i class="fa-solid fa-file-invoice"></i> Receivable Invoices</div><?php renderTable($receivableRows, ['invoice_number'=>'Invoice #','customer_name'=>'Customer','due_date'=>'Due Date','total_amount'=>'Total','paid_amount'=>'Paid','balance'=>'Due','days_overdue'=>'Days Overdue','status'=>'Status']); ?></div>
    <?php endif; ?>

    <!-- ============================================ -->
    <!-- INVENTORY / WAREHOUSE TAB -->
    <!-- ============================================ -->
    <?php if ($report === 'inventory' || $report === 'warehouse'): ?>
        <div class="row g-3 mb-3">
            <div class="col-md-3"><div class="metric"><div class="label">Warehouse Value</div><div class="value clickable-amount" data-detail='{"type":"warehouse_value","title":"Warehouse Stock Value"}'> <?= money($warehouseValue) ?></div></div></div>
            <div class="col-md-3"><div class="metric"><div class="label">Total Quantity</div><div class="value"><?= num($warehouseQty) ?></div></div></div>
            <div class="col-md-3"><div class="metric"><div class="label">Stock Items</div><div class="value"><?= num($warehouseItems) ?></div></div></div>
            <div class="col-md-3"><div class="metric"><div class="label">Avg Value/Item</div><div class="value"><?= $warehouseItems > 0 ? money($warehouseValue/$warehouseItems) : money(0) ?></div></div></div>
        </div>
        <div class="cardx"><div class="section-title"><i class="fa-solid fa-boxes"></i> <?= $report === 'warehouse' ? 'Warehouse Stock Report' : 'Inventory Report' ?></div><?php renderTable($inventoryRows, ['stock_name'=>'Item','customer_name'=>'Customer','quantity'=>'Qty','volume_cbm'=>'CBM','unit_price'=>'Unit Price','value_amount'=>'Value','origin'=>'Origin']); ?></div>
    <?php endif; ?>

    <!-- ============================================ -->
    <!-- CUSTOMERS TAB -->
    <!-- ============================================ -->
    <?php if ($report === 'customers'): ?>
        <div class="row g-3 mb-3">
            <div class="col-md-4"><div class="metric"><div class="label">Total Customers</div><div class="value"><?= num($totalCustomers) ?></div></div></div>
            <div class="col-md-4"><div class="metric"><div class="label">Total Customer Debt</div><div class="value amount-neg clickable-amount" data-detail='{"type":"customer_debt","title":"Customer Debt Details"}'> <?= money($totalCustomerDebt) ?></div></div></div>
            <div class="col-md-4"><div class="metric"><div class="label">Total Customer Spend</div><div class="value amount-pos clickable-amount" data-detail='{"type":"customer_total_spent","title":"Customer Total Spent"}'> <?= money($totalCustomerSpend) ?></div></div></div>
        </div>
        <div class="cardx"><div class="section-title"><i class="fa-solid fa-users"></i> Customer Report</div><?php renderTable($customerRows, ['customer_name'=>'Customer','phone'=>'Phone','email'=>'Email','debt_amount'=>'Debt','total_spent'=>'Total Spent','invoice_total'=>'Invoice Total','invoice_count'=>'Invoices','total_cbm_shipped'=>'CBM Shipped','is_active'=>'Status']); ?></div>
    <?php endif; ?>

    <!-- ============================================ -->
    <!-- CONTAINERS TAB -->
    <!-- ============================================ -->
    <?php if ($report === 'containers'): ?>
        <div class="cardx"><div class="section-title"><i class="fa-solid fa-ship"></i> Container Report</div><?php renderTable($containerRows, ['container_number'=>'Container #','container_type'=>'Type','origin'=>'Origin','status'=>'Status','current_location'=>'Location','size_cbm'=>'CBM','estimated_arrival'=>'ETA']); ?></div>
    <?php endif; ?>

    <!-- ============================================ -->
    <!-- SMS TAB -->
    <!-- ============================================ -->
    <?php if ($report === 'sms'): ?>
        <div class="row g-3 mb-3">
            <div class="col-md-4"><div class="metric"><div class="label">Total SMS Sent</div><div class="value"><?= num($totalSMS) ?></div></div></div>
            <div class="col-md-4"><div class="metric"><div class="label">Table Source</div><div class="value"><?= $hasSMSLogs ? 'sms_logs' : ($hasBulkCampaigns ? 'bulk_sms_campaigns' : 'N/A') ?></div></div></div>
            <div class="col-md-4"><div class="metric"><div class="label">Records Found</div><div class="value"><?= num(count($smsRows)) ?></div></div></div>
        </div>
        <div class="cardx"><div class="section-title"><i class="fa-solid fa-message"></i> SMS Report</div><?php if ($hasSMSLogs): ?><?php renderTable($smsRows, ['phone_number'=>'Phone','message'=>'Message','status'=>'Status','created_at'=>'Date']); ?><?php else: ?><?php renderTable($smsRows, ['campaign_name'=>'Campaign','message_text'=>'Message','total_recipients'=>'Recipients','total_sent'=>'Sent','total_delivered'=>'Delivered','total_failed'=>'Failed','status'=>'Status','created_at'=>'Date']); ?><?php endif; ?></div>
    <?php endif; ?>

    <!-- ============================================ -->
    <!-- PROFIT & LOSS TAB -->
    <!-- ============================================ -->
    <?php if ($report === 'profit_loss'): ?>
        <div class="row g-3 mb-3">
            <div class="col-md-4"><div class="metric"><div class="label">Total Revenue</div><div class="value amount-pos clickable-amount" data-detail='{"type":"pl_revenue","title":"Profit & Loss - Revenue Source"}'><?= money($plTotalRevenue) ?></div></div></div>
            <div class="col-md-4"><div class="metric"><div class="label">Total Expenses</div><div class="value amount-neg clickable-amount" data-detail='{"type":"pl_expenses","title":"Profit & Loss - Expense Source"}'><?= money($plTotalExpenses) ?></div></div></div>
            <div class="col-md-4"><div class="metric"><div class="label"><?= h($plFinalLabel) ?></div><div class="value <?= $plNetProfit >= 0 ? 'amount-pos' : 'amount-neg' ?> clickable-amount" data-detail='{"type":"pl_net_profit","title":"How Net Profit Was Calculated"}'><?= money($plNetProfit) ?></div></div></div>
        </div>

        <div class="cardx">
            <div class="section-title"><i class="fa-solid fa-scale-balanced"></i> Profit & Loss Statement</div>
            
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Account Code</th><th>Account Name</th><th>Entries</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                        <tr class="table-light"><td colspan="4"><strong>Revenue</strong></tr>
                        <?php foreach ($plRevenueRows as $r): ?>
                            <tr>
                                <td><?= h($r['account_code'] ?? '') ?></td>
                                <td><?= h($r['account_name'] ?? '') ?></td>
                                <td><?= num($r['entry_count'] ?? 0) ?></td>
                                <td class="text-end amount-pos"><span class="clickable-amount" data-detail='{"type":"pl_revenue","title":"Revenue Breakdown"}'><?= money($r['amount'] ?? 0) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr><td colspan="3"><strong>Total Revenue</strong></td><td class="text-end amount-pos"><strong><?= money($plTotalRevenue) ?></strong></td></tr>

                        <tr class="table-light"><td colspan="4"><strong>Expenses</strong></td></tr>
                        <?php foreach ($plExpenseRows as $r): ?>
                            <tr>
                                <td><?= h($r['account_code'] ?? '') ?></td>
                                <td><?= h($r['account_name'] ?? '') ?></td>
                                <td><?= num($r['entry_count'] ?? 0) ?></td>
                                <td class="text-end amount-neg"><span class="clickable-amount" data-detail='{"type":"pl_expenses","title":"Expense Breakdown"}'><?= money($r['amount'] ?? 0) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr><td colspan="3"><strong>Total Expenses</strong></td><td class="text-end amount-neg"><strong><?= money($plTotalExpenses) ?></strong></td></tr>
                        <tr style="border-top:3px solid #2D1859;"><td colspan="3"><strong><?= strtoupper($plFinalLabel) ?></strong></td><td class="text-end <?= $plNetProfit >= 0 ? 'amount-pos' : 'amount-neg' ?>"><strong><?= money($plNetProfit) ?></strong></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- ============================================ -->
    <!-- BALANCE SHEET TAB -->
    <!-- ============================================ -->
    <?php if ($report === 'balance_sheet'): ?>
        <div class="row g-3 mb-3">
            <div class="col-md-4"><div class="metric"><div class="label">Total Assets</div><div class="value amount-pos clickable-amount" data-detail='{"type":"bs_assets","title":"Balance Sheet - Assets Source"}'><?= money($bsTotalAssets) ?></div></div></div>
            <div class="col-md-4"><div class="metric"><div class="label">Total Liabilities</div><div class="value amount-neg clickable-amount" data-detail='{"type":"bs_liabilities","title":"Balance Sheet - Liabilities Source"}'><?= money($bsTotalLiabilities) ?></div></div></div>
            <div class="col-md-4"><div class="metric"><div class="label">Total Equity</div><div class="value clickable-amount" data-detail='{"type":"bs_equity","title":"Balance Sheet - Equity Source"}'><?= money($bsTotalEquity) ?></div></div></div>
        </div>
        
        <div class="cardx">
            <div class="section-title"><i class="fa-solid fa-building-columns"></i> Balance Sheet</div>
            
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Account Code</th><th>Account Name</th><th>Type</th><th class="text-end">Balance</th></tr></thead>
                    <tbody>
                        <tr class="table-light"><td colspan="4"><strong>Assets</strong> (Total: <?= money($bsTotalAssets) ?>)</td></tr>
                        <?php foreach ($balanceAssetRows as $r): ?>
                            <tr>
                                <td><?= h($r['account_code'] ?? '') ?></td>
                                <td><?= h($r['account_name'] ?? '') ?></td>
                                <td>Asset</td>
                                <td class="text-end amount-pos"><span class="clickable-amount" data-detail='{"type":"bs_assets","title":"Asset Breakdown - <?= h($r['account_name'] ?? '') ?>"}'><?= money($r['balance'] ?? 0) ?></span></td>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="table-light"><td colspan="4"><strong>Liabilities</strong> (Total: <?= money($bsTotalLiabilities) ?>)</td></tr>
                        <?php foreach ($balanceLiabilityRows as $r): ?>
                            <tr>
                                <td><?= h($r['account_code'] ?? '') ?></td>
                                <td><?= h($r['account_name'] ?? '') ?></td>
                                <td>Liability</td>
                                <td class="text-end amount-neg"><span class="clickable-amount" data-detail='{"type":"bs_liabilities","title":"Liability Breakdown - <?= h($r['account_name'] ?? '') ?>"}'><?= money($r['balance'] ?? 0) ?></span></td>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="table-light"><td colspan="4"><strong>Equity</strong> (Total: <?= money($bsTotalEquity) ?>)</td></tr>
                        <?php foreach ($balanceEquityRows as $r): ?>
                            <tr>
                                <td><?= h($r['account_code'] ?? '') ?></td>
                                <td><?= h($r['account_name'] ?? '') ?></td>
                                <td>Equity</td>
                                <td class="text-end"><span class="clickable-amount" data-detail='{"type":"bs_equity","title":"Equity Breakdown - <?= h($r['account_name'] ?? '') ?>"}'><?= money($r['balance'] ?? 0) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <tr style="border-top:3px solid #2D1859;"><td colspan="3"><strong>Total Liabilities + Equity</strong></td>
                        <td class="text-end"><strong><?= money($bsTotalLiabilities + $bsTotalEquity) ?></strong></td></tr>
                        <tr class="table-primary"><td colspan="3"><strong>Assets - (Liabilities + Equity)</strong></td>
                        <td class="text-end <?= abs($bsCheck) < 0.01 ? 'amount-pos' : 'amount-neg' ?>"><strong><?= money($bsCheck) ?></strong> <?= abs($bsCheck) < 0.01 ? '✓' : '⚠️' ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="small-note no-print">Generated by <?= h($user_name) ?> on <?= date('d/m/Y H:i:s') ?> | Cargo Management System - Smart Logistics</div>
</div>

<!-- Modals -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa-solid fa-info-circle"></i> <span id="modalTitle">Details</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="text-center p-5"><div class="spinner-border text-primary"></div> Loading data...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fa-solid fa-times"></i> Close</button>
                <button type="button" class="btn btn-brand" onclick="printModalContent()"><i class="fa-solid fa-print"></i> Print</button>
                <button type="button" class="btn btn-brand" onclick="exportModalToCSV()"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="customerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa-solid fa-user"></i> <span id="customerModalTitle">Customer Details</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="customerModalBody">
                <div class="text-center p-5"><div class="spinner-border text-primary"></div> Loading customer data...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Chart Initialization
<?php if ($report === 'overview'): ?>
const monthlySales = <?= json_encode($monthlySales, JSON_UNESCAPED_UNICODE) ?>;
const containerStatus = <?= json_encode($containerStatus, JSON_UNESCAPED_UNICODE) ?>;
if (monthlySales.length > 0) {
    new Chart(document.getElementById('monthlySalesChart'), { 
        type: 'line', 
        data: { labels: monthlySales.map(x=>x.label), datasets: [{ label: 'Revenue', data: monthlySales.map(x=>Number(x.value)), tension: .35, fill: true, borderColor: '#2D1859', backgroundColor: 'rgba(45,24,89,0.1)' }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { callback: v => '$' + v.toLocaleString() } } } }
    });
}
if (containerStatus.length > 0) {
    new Chart(document.getElementById('containerStatusChart'), { 
        type: 'doughnut', 
        data: { labels: containerStatus.map(x=>x.label), datasets: [{ data: containerStatus.map(x=>Number(x.value)), backgroundColor: ['#17a2b8','#ffc107','#fd7e14','#28a745','#6f42c1','#dc3545'] }] }, 
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
}
<?php endif; ?>

<?php if ($report === 'payments'): ?>
const paymentMethods = <?= json_encode(array_map(fn($pm) => ['label' => $pm['payment_method'], 'value' => (float)$pm['total']], $paymentMethods), JSON_UNESCAPED_UNICODE) ?>;
if (paymentMethods.length > 0) { 
    new Chart(document.getElementById('paymentMethodsChart'), { 
        type: 'pie', 
        data: { labels: paymentMethods.map(x=>x.label), datasets: [{ data: paymentMethods.map(x=>x.value), backgroundColor: ['#28a745','#17a2b8','#ffc107','#6c757d','#dc3545','#520066'] }] }, 
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    }); 
}
<?php endif; ?>

// Clickable Amount Handler
$(document).on('click', '.clickable-amount', function() {
    const detailData = $(this).data('detail');
    if (!detailData) return;
    
    let detail;
    try { detail = typeof detailData === 'string' ? JSON.parse(detailData) : detailData; }
    catch(e) { console.error(e); return; }

    // If the clicked amount belongs to a table row, show only that exact row/source.
    // This prevents a $0.04 item from opening the full Warehouse/Inventory report.
    if (detail.type === 'single_row_amount') {
        $('#modalTitle').text(detail.title || 'Source Detail');
        let html = '<div class="text-center mb-3"><h5><?= h($tenant_name) ?></h5><p class="text-muted">Period: <?= h($date_from) ?> to <?= h($date_to) ?></p></div>';
        html += '<div class="alert alert-info mb-3"><strong>' + h(detail.clicked_label || 'Amount') + ': ' + h(detail.clicked_value || moneyFormat(detail.total || 0)) + '</strong></div>';
        if (detail.rows && detail.rows.length > 0) {
            html += '<div class="table-responsive"><table class="table table-bordered table-striped"><thead><tr>';
            (detail.columns || []).forEach(col => html += '<th>' + h(col) + '</th>');
            html += '</tr></thead><tbody>';
            detail.rows.forEach(row => { html += '<tr>'; row.forEach(cell => html += '<td>' + (cell ?? '') + '</td>'); html += '</tr>'; });
            html += '</tbody></table></div>';
        } else {
            html += '<div class="alert alert-warning">No records found.</div>';
        }
        $('#modalBody').html(html);
        $('#detailModal').modal('show');
        return;
    }
    
    const params = new URLSearchParams({
        ajax: 'get_details',
        detail_type: detail.type,
        date_from: '<?= h($date_from) ?>',
        date_to: '<?= h($date_to) ?>',
        bucket: detail.bucket || '',
        method: detail.method || '',
        customer_id: detail.customer_id || ''
    });
    
    $('#modalTitle').text(detail.title || 'Details');
    $('#modalBody').html('<div class="text-center p-5"><div class="spinner-border text-primary"></div> Loading data...</div>');
    $('#detailModal').modal('show');
    
    $.ajax({ 
        url: window.location.pathname + '?' + params.toString(), 
        method: 'GET', 
        dataType: 'json',
        success: function(data) {
            if (!data.success) { 
                $('#modalBody').html('<div class="alert alert-danger">Error: ' + (data.message || 'Unknown error') + '</div>'); 
                return; 
            }
            
            let html = '<div class="text-center mb-3"><h5>' + h(data.company) + '</h5><p class="text-muted">Period: ' + h(data.period?.from) + ' to ' + h(data.period?.to) + '</p></div>';
            html += '<div class="alert alert-info mb-3"><strong>Total: ' + moneyFormat(data.total || 0) + '</strong>' + (data.formula ? '<br><small><strong>Formula:</strong> ' + h(data.formula) + '</small>' : '') + '</div>';
            
            if (data.rows && data.rows.length > 0) {
                html += '<div class="table-responsive"><table class="table table-bordered table-striped"><thead><tr>';
                (data.columns || []).forEach(col => html += '<th>' + h(col) + '</th>');
                html += '</tr></thead><tbody>';
                data.rows.forEach(row => { html += '<tr>'; row.forEach(cell => html += '<td>' + (cell ?? '') + '</td>'); html += '</tr>'; });
                html += '</tbody></table></div>';
            } else { 
                html += '<div class="alert alert-warning">No records found.</div>'; 
            }
            
            $('#modalBody').html(html);
        },
        error: function(xhr) { 
            $('#modalBody').html('<div class="alert alert-danger">Error loading data. Please try again.</div>'); 
        }
    });
});

// Customer Click Handler
$(document).on('click', '.clickable-customer', function() {
    const customerName = $(this).data('customer-name');
    const customerId = $(this).data('customer-id');
    
    if (customerId === 'all') {
        $('#customerModalTitle').text('All Customers Overview');
        $('#customerModalBody').html('<div class="text-center p-5"><div class="spinner-border text-primary"></div> Loading customer data...</div>');
        $('#customerModal').modal('show');
        
        $.ajax({
            url: window.location.pathname + '?ajax=get_details&detail_type=customer_debt&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>',
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                if (data.success && data.rows) {
                    let html = '<div class="alert alert-info"><strong>Total Customer Debt: ' + moneyFormat(data.total) + '</strong></div>';
                    html += '<div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Customer</th><th>Phone</th><th>Email</th><th>Debt Amount</th></tr></tr></thead><tbody>';
                    data.rows.forEach(row => {
                        html += '<tr><td>' + row[0] + '</td><td>' + row[1] + '</td><td>' + row[2] + '</td><td>' + row[3] + '</td></tr>';
                    });
                    html += '</tbody></table></div>';
                    $('#customerModalBody').html(html);
                } else {
                    $('#customerModalBody').html('<div class="alert alert-warning">No customer data found.</div>');
                }
            },
            error: function() {
                $('#customerModalBody').html('<div class="alert alert-danger">Error loading customer data.</div>');
            }
        });
        return;
    }
    
    if (!customerId) return;
    
    $('#customerModalTitle').text('Customer: ' + customerName);
    $('#customerModalBody').html('<div class="text-center p-5"><div class="spinner-border text-primary"></div> Loading customer data...</div>');
    $('#customerModal').modal('show');
    
    $.ajax({
        url: window.location.pathname + '?ajax=get_details&detail_type=customer_spent&customer_id=' + customerId + '&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>',
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            if (data.success) {
                let html = '<div class="alert alert-info"><strong>Total Spent: ' + moneyFormat(data.total) + '</strong></div>';
                if (data.rows && data.rows.length > 0) {
                    html += '<div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Source</th><th>Customer</th><th>Source #</th><th>Date</th><th>Reason / Item / Category</th><th>Total / Amount</th><th>Paid</th><th>Balance</th><th>Status / Method</th></tr></thead><tbody>';
                    data.rows.forEach(row => {
                        html += '<tr>' + row.map(cell => '<td>' + cell + '</td>').join('') + '</tr>';
                    });
                    html += '</tbody></table></div>';
                } else {
                    html += '<div class="alert alert-warning">No purchase history found.</div>';
                }
                $('#customerModalBody').html(html);
            } else {
                $('#customerModalBody').html('<div class="alert alert-danger">Error loading customer data.</div>');
            }
        },
        error: function() {
            $('#customerModalBody').html('<div class="alert alert-danger">Error loading customer data.</div>');
        }
    });
});

function moneyFormat(value) { 
    const num = parseFloat(value || 0); 
    return '$' + num.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); 
}

function h(str) { 
    if (!str) return ''; 
    return String(str).replace(/[&<>]/g, function(m) { 
        return {'&':'&amp;','<':'&lt;','>':'&gt;'}[m]; 
    }); 
}

function printModalContent() {
    const content = $('#modalBody').html();
    const title = $('#modalTitle').text();
    const win = window.open('', '_blank');
    win.document.write('<html><head><title>' + title + '</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body>');
    win.document.write('<div class="container mt-4"><h3 class="text-center" style="color:#2D1859;">' + title + '</h3><hr/>' + content + '</div></body></html>');
    win.document.close();
    win.print();
}

function exportModalToCSV() {
    const table = $('#modalBody table');
    if (!table.length) {
        alert('No data to export');
        return;
    }
    
    let csv = [];
    const headers = [];
    table.find('thead th').each(function() {
        headers.push('"' + $(this).text().replace(/"/g, '""') + '"');
    });
    csv.push(headers.join(','));
    
    table.find('tbody tr').each(function() {
        const row = [];
        $(this).find('td').each(function() {
            row.push('"' + $(this).text().replace(/"/g, '""') + '"');
        });
        csv.push(row.join(','));
    });
    
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = $('#modalTitle').text().replace(/[^a-z0-9]/gi, '_') + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
</script>

<?php
$footer_path = __DIR__ . '/../includes/footer.php';
if (file_exists($footer_path)) {
    require_once $footer_path;
} else {
    ?>
    <footer class="text-center text-muted py-3 mt-4 border-top">
        <small>&copy; <?= date('Y') ?> Cargo Management System. All rights reserved.</small>
    </footer>
    </body>
    </html>
    <?php
}
?>
