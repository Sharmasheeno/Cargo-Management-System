<?php
// superadmin/full_report.php
//faras cargo - Complete Professional System Report
// Includes: P&L, Balance Sheet, Containers, Customer Debt, Warehouse, Financials

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

// Get filter values
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');
require_once __DIR__ . '/../includes/sa_scope.php';
$tenant_filter = ($role === 'superadmin') ? (isset($_GET['tenant']) ? (int)$_GET['tenant'] : sa_selected_tenant_id_int()) : $session_tenant_id;
$period_type = $_GET['period_type'] ?? 'month';
$include_details = isset($_GET['details']) && $_GET['details'] == 1;

// Base tenant for financials (if superadmin with no filter, show all? Actually P&L needs specific or aggregated)
$tenant_id_fs = ($role === 'superadmin') ? ($tenant_filter > 0 ? $tenant_filter : null) : $session_tenant_id;

// ============================================
// 1. PROFIT & LOSS STATEMENT (From Journal Entries)
// ============================================
$pl_data = [];
$total_revenue_pl = 0;
$total_expense_pl = 0;

if ($tenant_id_fs) {
    // Revenue Accounts (4xxx)
    $stmt_rev = $pdo->prepare("
        SELECT account_code, account_name, SUM(credit - debit) as amount
        FROM journal_entries
        WHERE tenant_id = ? AND account_code LIKE '4%' AND entry_date BETWEEN ? AND ?
        GROUP BY account_code, account_name
        ORDER BY account_code
    ");
    $stmt_rev->execute([$tenant_id_fs, $date_from, $date_to]);
    $revenues = $stmt_rev->fetchAll(PDO::FETCH_ASSOC);
    
    // Expense Accounts (5xxx)
    $stmt_exp = $pdo->prepare("
        SELECT account_code, account_name, SUM(debit - credit) as amount
        FROM journal_entries
        WHERE tenant_id = ? AND account_code LIKE '5%' AND entry_date BETWEEN ? AND ?
        GROUP BY account_code, account_name
        ORDER BY account_code
    ");
    $stmt_exp->execute([$tenant_id_fs, $date_from, $date_to]);
    $expenses = $stmt_exp->fetchAll(PDO::FETCH_ASSOC);
    
    $total_revenue_pl = array_sum(array_column($revenues, 'amount'));
    $total_expense_pl = array_sum(array_column($expenses, 'amount'));
    $net_profit_pl = $total_revenue_pl - $total_expense_pl;
} else {
    // Superadmin with no tenant filter - aggregate all tenants
    $rev_all = $pdo->prepare("
        SELECT account_code, account_name, SUM(credit - debit) as amount
        FROM journal_entries j
        JOIN tenants t ON j.tenant_id = t.id
        WHERE account_code LIKE '4%' AND entry_date BETWEEN ? AND ?
        GROUP BY account_code, account_name
    ");
    $rev_all->execute([$date_from, $date_to]);
    $revenues = $rev_all->fetchAll(PDO::FETCH_ASSOC);
    
    $exp_all = $pdo->prepare("
        SELECT account_code, account_name, SUM(debit - credit) as amount
        FROM journal_entries j
        JOIN tenants t ON j.tenant_id = t.id
        WHERE account_code LIKE '5%' AND entry_date BETWEEN ? AND ?
        GROUP BY account_code, account_name
    ");
    $exp_all->execute([$date_from, $date_to]);
    $expenses = $exp_all->fetchAll(PDO::FETCH_ASSOC);
    
    $total_revenue_pl = array_sum(array_column($revenues, 'amount'));
    $total_expense_pl = array_sum(array_column($expenses, 'amount'));
    $net_profit_pl = $total_revenue_pl - $total_expense_pl;
}

// ============================================
// 2. BALANCE SHEET (From Chart of Accounts)
// ============================================
$assets = [];
$liabilities = [];
$equity = [];
$total_assets = 0;
$total_liabilities = 0;
$total_equity = 0;

if ($tenant_id_fs) {
    $stmt_bs = $pdo->prepare("SELECT account_code, account_name, account_type, balance FROM chart_of_accounts WHERE tenant_id = ? ORDER BY account_code");
    $stmt_bs->execute([$tenant_id_fs]);
    $accounts = $stmt_bs->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt_bs = $pdo->prepare("SELECT account_code, account_name, account_type, balance FROM chart_of_accounts ORDER BY account_code");
    $stmt_bs->execute();
    $accounts = $stmt_bs->fetchAll(PDO::FETCH_ASSOC);
}

foreach ($accounts as $acc) {
    if ($acc['account_type'] == 'asset') {
        $assets[] = $acc;
        $total_assets += $acc['balance'];
    } elseif ($acc['account_type'] == 'liability') {
        $liabilities[] = $acc;
        $total_liabilities += $acc['balance'];
    } elseif ($acc['account_type'] == 'equity') {
        $equity[] = $acc;
        $total_equity += $acc['balance'];
    }
}
$total_liabilities_equity = $total_liabilities + $total_equity;

// ============================================
// 3. CONTAINER ANALYSIS
// ============================================
$container_where = "";
$container_params = [];
if ($tenant_filter > 0) {
    $container_where = "WHERE tenant_id = ?";
    $container_params = [$tenant_filter];
}
$stmt_containers = $pdo->prepare("
    SELECT c.*, 
           COALESCE((SELECT SUM(cbm_used) FROM cargo_manifest_items WHERE container_id = c.id), 0) as used_cbm,
           (c.size_cbm - COALESCE((SELECT SUM(cbm_used) FROM cargo_manifest_items WHERE container_id = c.id), 0)) as remaining_cbm,
           ROUND(COALESCE((SELECT SUM(cbm_used) FROM cargo_manifest_items WHERE container_id = c.id), 0) / NULLIF(c.size_cbm, 0) * 100, 2) as utilization
    FROM containers c
    $container_where
    ORDER BY c.id DESC
");
$stmt_containers->execute($container_params);
$containers = $stmt_containers->fetchAll(PDO::FETCH_ASSOC);

// Container status distribution
$stmt_status = $pdo->prepare("SELECT status, COUNT(*) as count FROM containers $container_where GROUP BY status");
$stmt_status->execute($container_params);
$container_status = $stmt_status->fetchAll(PDO::FETCH_ASSOC);

// Container origin distribution
$stmt_origin = $pdo->prepare("SELECT origin, COUNT(*) as count FROM containers $container_where GROUP BY origin");
$stmt_origin->execute($container_params);
$container_origins = $stmt_origin->fetchAll(PDO::FETCH_ASSOC);

// Utilization by container
$stmt_util = $pdo->prepare("
    SELECT c.container_number, c.container_type, c.size_cbm, 
           COALESCE(SUM(cmi.cbm_used), 0) as used_cbm,
           ROUND(COALESCE(SUM(cmi.cbm_used), 0) / NULLIF(c.size_cbm, 0) * 100, 2) as utilization
    FROM containers c
    LEFT JOIN cargo_manifest_items cmi ON c.id = cmi.container_id
    $container_where
    GROUP BY c.id
    HAVING used_cbm > 0
    ORDER BY utilization DESC
");
$stmt_util->execute($container_params);
$container_utilization = $stmt_util->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// 4. CUSTOMER DEBT ANALYSIS
// ============================================
$debt_where = "";
$debt_params = [];
if ($tenant_filter > 0) {
    $debt_where = "WHERE tenant_id = ?";
    $debt_params = [$tenant_filter];
}
$stmt_debt = $pdo->prepare("
    SELECT id, customer_name, phone, debt_amount, loyalty_points,
           (SELECT COUNT(*) FROM invoices WHERE customer_id = c.id AND status != 'paid') as open_invoices,
           (SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM invoices WHERE customer_id = c.id AND status != 'paid') as total_due
    FROM customers c
    $debt_where
    HAVING total_due > 0
    ORDER BY total_due DESC
");
$stmt_debt->execute($debt_params);
$debt_customers = $stmt_debt->fetchAll(PDO::FETCH_ASSOC);

// Debt aging buckets
$aging_buckets = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
$aging_sql = "SELECT DATEDIFF(NOW(), due_date) as days_overdue, (total_amount - paid_amount) as due_amount FROM invoices WHERE status != 'paid' AND (total_amount - paid_amount) > 0";
if ($tenant_filter > 0) $aging_sql .= " AND tenant_id = $tenant_filter";
$aging_invoices = $pdo->query($aging_sql)->fetchAll(PDO::FETCH_ASSOC);
foreach ($aging_invoices as $inv) {
    $days = (int)$inv['days_overdue'];
    $amount = (float)$inv['due_amount'];
    if ($days <= 30) $aging_buckets['0-30'] += $amount;
    elseif ($days <= 60) $aging_buckets['31-60'] += $amount;
    elseif ($days <= 90) $aging_buckets['61-90'] += $amount;
    else $aging_buckets['90+'] += $amount;
}
$total_aging = array_sum($aging_buckets);

// Debt collection log
$stmt_collection = $pdo->prepare("
    SELECT dcl.*, c.customer_name, u.full_name as collector_name
    FROM debt_collection_log dcl
    LEFT JOIN customers c ON dcl.customer_id = c.id
    LEFT JOIN users u ON dcl.collected_by = u.id
    WHERE dcl.created_at BETWEEN ? AND ?
    ORDER BY dcl.created_at DESC
    LIMIT 50
");
$stmt_collection->execute([$date_from . " 00:00:00", $date_to . " 23:59:59"]);
$collection_log = $stmt_collection->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// 5. WAREHOUSE STOCK REPORT
// ============================================
$wh_where = "";
$wh_params = [];
if ($tenant_filter > 0) {
    $wh_where = "WHERE ws.tenant_id = ?";
    $wh_params = [$tenant_filter];
}
$stmt_wh = $pdo->prepare("
    SELECT ws.*, t.name as tenant_name, c.customer_name,
           (ws.volume_cbm * ws.unit_price) as total_value
    FROM warehouse_stock ws
    LEFT JOIN tenants t ON ws.tenant_id = t.id
    LEFT JOIN customers c ON ws.customer_id = c.id
    $wh_where
    ORDER BY ws.volume_cbm DESC
");
$stmt_wh->execute($wh_params);
$warehouse_stocks = $stmt_wh->fetchAll(PDO::FETCH_ASSOC);

// Warehouse summary
$wh_summary = [
    'total_items' => count($warehouse_stocks),
    'total_quantity' => array_sum(array_column($warehouse_stocks, 'quantity')),
    'total_cbm' => array_sum(array_column($warehouse_stocks, 'volume_cbm')),
    'total_value' => array_sum(array_column($warehouse_stocks, 'total_value')),
    'low_stock' => count(array_filter($warehouse_stocks, function($w) { return $w['quantity'] <= $w['minimum_stock'] && $w['minimum_stock'] > 0; })),
];

// Stock by origin
$origin_names = ['china_yiwu' => 'Yiwu', 'china_guangzhou' => 'Guangzhou', 'dubai' => 'Dubai', 'local' => 'Local'];
$stock_by_origin = [];
foreach ($warehouse_stocks as $w) {
    $origin = $w['origin'];
    if (!isset($stock_by_origin[$origin])) {
        $stock_by_origin[$origin] = ['items' => 0, 'qty' => 0, 'cbm' => 0, 'value' => 0];
    }
    $stock_by_origin[$origin]['items']++;
    $stock_by_origin[$origin]['qty'] += $w['quantity'];
    $stock_by_origin[$origin]['cbm'] += $w['volume_cbm'];
    $stock_by_origin[$origin]['value'] += $w['total_value'];
}

// ============================================
// 6. FINANCIAL METRICS (From Invoices)
// ============================================
$inv_where = "";
$inv_params = [];
if ($tenant_filter > 0) {
    $inv_where = "WHERE tenant_id = ?";
    $inv_params = [$tenant_filter];
}
$stmt_inv_metrics = $pdo->prepare("
    SELECT 
        COALESCE(SUM(total_amount), 0) as total_revenue,
        COALESCE(SUM(paid_amount), 0) as total_collected,
        COALESCE(SUM(total_amount - paid_amount), 0) as accounts_receivable,
        COUNT(*) as invoice_count,
        COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count,
        COUNT(CASE WHEN status = 'overdue' THEN 1 END) as overdue_count
    FROM invoices i
    $inv_where
");
$stmt_inv_metrics->execute($inv_params);
$invoice_metrics = $stmt_inv_metrics->fetch(PDO::FETCH_ASSOC);

// Cash flow from receipts and payments
$stmt_cashflow = $pdo->prepare("
    SELECT 
        (SELECT COALESCE(SUM(amount), 0) FROM receipts r $inv_where) as total_receipts,
        (SELECT COALESCE(SUM(amount), 0) FROM payments p $inv_where) as total_payments
");
$stmt_cashflow->execute($inv_params);
$cashflow = $stmt_cashflow->fetch(PDO::FETCH_ASSOC);
$net_cashflow = $cashflow['total_receipts'] - $cashflow['total_payments'];

// Monthly comparison
$stmt_monthly = $pdo->prepare("
    SELECT DATE_FORMAT(invoice_date, '%Y-%m') as month, 
           COALESCE(SUM(total_amount), 0) as revenue,
           COUNT(*) as invoices
    FROM invoices i
    $inv_where
    GROUP BY DATE_FORMAT(invoice_date, '%Y-%m')
    ORDER BY month DESC
    LIMIT 12
");
$stmt_monthly->execute($inv_params);
$monthly_data = $stmt_monthly->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// 7. SYSTEM STATISTICS
// ============================================
$stats_where = ($tenant_filter > 0) ? "WHERE tenant_id = $tenant_filter" : "";
$user_where = ($tenant_filter > 0) ? "WHERE tenant_id = $tenant_filter AND role_type != 'superadmin'" : "WHERE role_type != 'superadmin'";

$system_stats = [
    'total_companies' => ($role === 'superadmin' && !$tenant_filter) ? $pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn() : 1,
    'active_companies' => ($role === 'superadmin' && !$tenant_filter) ? $pdo->query("SELECT COUNT(*) FROM tenants WHERE is_active = 1")->fetchColumn() : 1,
    'total_users' => $pdo->query("SELECT COUNT(*) FROM users $user_where")->fetchColumn(),
    'total_customers' => $pdo->query("SELECT COUNT(*) FROM customers $stats_where")->fetchColumn(),
    'total_containers' => $pdo->query("SELECT COUNT(*) FROM containers $stats_where")->fetchColumn(),
    'total_trips' => $pdo->query("SELECT COUNT(*) FROM trucking_trips $stats_where")->fetchColumn(),
    'total_invoices' => $pdo->query("SELECT COUNT(*) FROM invoices $stats_where")->fetchColumn(),
    'total_receipts' => $pdo->query("SELECT COUNT(*) FROM receipts $stats_where")->fetchColumn(),
    'total_stock_items' => $wh_summary['total_items'],
];

// Role distribution
$stmt_roles = $pdo->query("SELECT role_type, COUNT(*) as count FROM users WHERE role_type != 'superadmin' GROUP BY role_type");
$role_distribution = $stmt_roles->fetchAll(PDO::FETCH_ASSOC);
$role_names = ['company_admin' => 'Company Admin', 'staff' => 'Staff', 'customer' => 'Customer'];

// ============================================
// EXPORT HANDLER
// ============================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="curdun_full_report_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['Cargo Management System - FULL SYSTEM REPORT']);
    fputcsv($output, ['Generated:', date('Y-m-d H:i:s')]);
    fputcsv($output, ['Period:', $date_from . ' to ' . $date_to]);
    fputcsv($output, []);
    
    fputcsv($output, ['PROFIT & LOSS STATEMENT']);
    fputcsv($output, ['Account Code', 'Account Name', 'Amount']);
    foreach ($revenues as $r) {
        fputcsv($output, [$r['account_code'], $r['account_name'], $r['amount']]);
    }
    foreach ($expenses as $e) {
        fputcsv($output, [$e['account_code'], $e['account_name'], '-' . number_format($e['amount'], 2)]);
    }
    fputcsv($output, ['', 'NET PROFIT', $net_profit_pl]);
    fputcsv($output, []);
    
    fputcsv($output, ['BALANCE SHEET']);
    fputcsv($output, ['ASSETS']);
    foreach ($assets as $a) {
        fputcsv($output, [$a['account_code'], $a['account_name'], $a['balance']]);
    }
    fputcsv($output, ['LIABILITIES']);
    foreach ($liabilities as $l) {
        fputcsv($output, [$l['account_code'], $l['account_name'], $l['balance']]);
    }
    fputcsv($output, ['EQUITY']);
    foreach ($equity as $e) {
        fputcsv($output, [$e['account_code'], $e['account_name'], $e['balance']]);
    }
    fputcsv($output, []);
    
    fputcsv($output, ['CUSTOMER DEBT ANALYSIS']);
    fputcsv($output, ['Customer Name', 'Phone', 'Total Due', 'Open Invoices']);
    foreach ($debt_customers as $d) {
        fputcsv($output, [$d['customer_name'], $d['phone'], $d['total_due'], $d['open_invoices']]);
    }
    fputcsv($output, []);
    
    fputcsv($output, ['WAREHOUSE STOCK']);
    fputcsv($output, ['Stock Name', 'Origin', 'Quantity', 'Volume (CBM)', 'Unit Price', 'Total Value', 'Customer']);
    foreach ($warehouse_stocks as $w) {
        fputcsv($output, [$w['stock_name'], $origin_names[$w['origin']] ?? $w['origin'], $w['quantity'], $w['volume_cbm'], $w['unit_price'], $w['total_value'], $w['customer_name'] ?? '-']);
    }
    fputcsv($output, []);
    
    fputcsv($output, ['CONTAINERS']);
    fputcsv($output, ['Container Number', 'Type', 'Status', 'Capacity (CBM)', 'Used (CBM)', 'Utilization %']);
    foreach ($containers as $c) {
        fputcsv($output, [$c['container_number'], $c['container_type'], $c['status'], $c['size_cbm'], $c['used_cbm'], $c['utilization']]);
    }
    
    fclose($output);
    exit;
}

// Include header
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    :root {
        --curdun-violet: #2D1859;
        --curdun-yellow: #F5C410;
        --curdun-violet-light: #4B2C85;
        --curdun-dark: #2D2D2D;
        --curdun-success: #0F7A3A;
        --curdun-danger: #B42318;
        --curdun-info: #17a2b8;
    }
    
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    
    @media print {
        body { background: white; padding: 0; margin: 0; font-size: 9px; }
        .no-print, .page-header, .filters-card, .btn-filter, .btn-reset, .btn-export, .action-buttons, .tab-buttons {
            display: none !important;
        }
        .container-fluid { padding: 0 !important; margin: 0 !important; }
        .report-section { break-inside: avoid; page-break-inside: avoid; margin-bottom: 20px; }
        .stat-card, .info-card { box-shadow: none !important; border: 1px solid #ddd !important; }
        .profit-summary { background: #2D1859 !important; color: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .data-table th, .data-table td { padding: 4px 6px !important; font-size: 8px !important; }
        .col-md-3, .col-md-4, .col-md-6 { width: 50%; float: left; }
        @page { size: A4; margin: 1.2cm; }
        .report-header { margin-bottom: 15px; }
        h1, h2, h3 { margin-top: 0; }
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
    }
    .page-header h1 { color: white; font-size: 24px; margin: 0; }
    
    .filters-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 25px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    .filter-form { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
    .filter-group { flex: 1; min-width: 150px; }
    .filter-group label { font-size: 12px; font-weight: 600; color: #6c757d; margin-bottom: 5px; display: block; }
    .filter-group input, .filter-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px; }
    .btn-filter { background: var(--curdun-violet); color: white; border: none; padding: 8px 20px; border-radius: 8px; cursor: pointer; }
    .btn-reset { background: #f0f0f0; border: none; padding: 8px 20px; border-radius: 8px; cursor: pointer; }
    .btn-export { background: var(--curdun-success); color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
    
    .tab-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 20px;
        background: white;
        padding: 15px;
        border-radius: 12px;
    }
    .tab-btn {
        padding: 8px 20px;
        border: none;
        border-radius: 30px;
        background: #f0f0f0;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.3s;
    }
    .tab-btn.active {
        background: var(--curdun-violet);
        color: white;
    }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    
    .profit-summary {
        background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light));
        color: white;
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 25px;
    }
    .profit-summary .profit-number { font-size: 32px; font-weight: 700; }
    
    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        border-left: 3px solid var(--curdun-violet);
    }
    .stat-card h3 { font-size: 12px; color: #6c757d; margin-bottom: 8px; }
    .stat-card .stat-number { font-size: 28px; font-weight: 700; color: var(--curdun-violet); }
    
    .info-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    .info-card h4 {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--curdun-violet);
    }
    
    .data-table {
        width: 100%;
        border-collapse: collapse;
    }
    .data-table th, .data-table td {
        padding: 10px 12px;
        text-align: left;
        border-bottom: 1px solid #eee;
    }
    .data-table th {
        background: #f8f6f9;
        font-weight: 600;
    }
    .data-table tr:hover { background: #faf8fb; }
    
    .amount-positive { color: var(--curdun-success); font-weight: 600; }
    .amount-negative { color: var(--curdun-danger); font-weight: 600; }
    
    .report-header { text-align: center; margin-bottom: 20px; }
    .report-header h2 { color: var(--curdun-violet); }
    .report-footer { text-align: center; margin-top: 30px; padding-top: 15px; border-top: 1px solid #eee; font-size: 9px; }
    
    @media (max-width: 768px) {
        .page-header { flex-direction: column; text-align: center; }
        .filter-form { flex-direction: column; }
        .filter-group { width: 100%; }
        .tab-buttons { overflow-x: auto; flex-wrap: nowrap; }
    }
</style>

<div class="container-fluid" style="padding: 20px;">
    <!-- Header (No Print) -->
    <div class="no-print page-header">
        <h1><i class="fas fa-chart-pie"></i> CURDUN - Full System Report</h1>
        <div>
            <a href="?export=csv&<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn-export">
                <i class="fas fa-file-csv"></i> Export CSV
            </a>
            <button onclick="window.print()" class="btn-export" style="background: var(--curdun-info); margin-left: 10px;">
                <i class="fas fa-print"></i> Print Report
            </button>
        </div>
    </div>
    
    <!-- Filters (No Print) -->
    <div class="no-print filters-card">
        <form method="GET" class="filter-form" id="filterForm">
            <div class="filter-group">
                <label><i class="fas fa-calendar"></i> From Date</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-calendar"></i> To Date</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
            </div>
            <?php if ($role === 'superadmin'): ?>
            <div class="filter-group">
                <label><i class="fas fa-building"></i> Tenant</label>
                <select name="tenant">
                    <option value="0">All Tenants</option>
                    <?php foreach ($tenants as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $tenant_filter == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="filter-group">
                <label><i class="fas fa-info-circle"></i> Details</label>
                <select name="details">
                    <option value="0" <?= !$include_details ? 'selected' : '' ?>>Summary Only</option>
                    <option value="1" <?= $include_details ? 'selected' : '' ?>>Include Details</option>
                </select>
            </div>
            <div class="filter-group">
                <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply Filter</button>
                <a href="?date_from=<?= date('Y-m-01') ?>&date_to=<?= date('Y-m-t') ?>" class="btn-reset">Reset</a>
            </div>
        </form>
    </div>
    
    <!-- Tab Navigation (No Print) -->
    <div class="no-print tab-buttons">
        <button class="tab-btn active" data-tab="tab-pl"><i class="fas fa-chart-line"></i> P&L / Balance</button>
        <button class="tab-btn" data-tab="tab-containers"><i class="fas fa-box"></i> Containers</button>
        <button class="tab-btn" data-tab="tab-debt"><i class="fas fa-users"></i> Customer Debt</button>
        <button class="tab-btn" data-tab="tab-warehouse"><i class="fas fa-warehouse"></i> Warehouse</button>
        <button class="tab-btn" data-tab="tab-financials"><i class="fas fa-dollar-sign"></i> Financial Metrics</button>
        <button class="tab-btn" data-tab="tab-stats"><i class="fas fa-chart-simple"></i> System Stats</button>
    </div>
    
    <!-- TAB 1: PROFIT & LOSS + BALANCE SHEET -->
    <div class="tab-content active" id="tab-pl">
        <!-- Profit Summary -->
        <div class="profit-summary">
            <div class="row">
                <div class="col-md-3">
                    <h4>Total Revenue</h4>
                    <div class="profit-number">$<?= number_format($total_revenue_pl, 2) ?></div>
                </div>
                <div class="col-md-3">
                    <h4>Total Expenses</h4>
                    <div class="profit-number">$<?= number_format($total_expense_pl, 2) ?></div>
                </div>
                <div class="col-md-3">
                    <h4>Net Profit / Loss</h4>
                    <div class="profit-number <?= $net_profit_pl >= 0 ? 'text-success' : 'text-danger' ?>">
                        $<?= number_format($net_profit_pl, 2) ?>
                    </div>
                </div>
                <div class="col-md-3">
                    <h4>Profit Margin</h4>
                    <div class="profit-number"><?= $total_revenue_pl > 0 ? number_format(($net_profit_pl / $total_revenue_pl) * 100, 1) : 0 ?>%</div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="info-card">
                    <h4><i class="fas fa-chart-line"></i> Profit & Loss Statement</h4>
                    <table class="data-table">
                        <thead><tr><th>Account</th><th class="text-right">Amount</th></tr></thead>
                        <tbody>
                            <tr style="background:#f8f9fa;"><td><strong>REVENUE</strong></td><td class="text-right"></td></tr>
                            <?php foreach ($revenues as $r): ?>
                            <tr><td><?= htmlspecialchars($r['account_name']) ?></td><td class="text-right amount-positive">$<?= number_format($r['amount'], 2) ?></td></tr>
                            <?php endforeach; ?>
                            <tr style="border-top:2px solid #ddd;"><td><strong>Total Revenue</strong></td><td class="text-right"><strong>$<?= number_format($total_revenue_pl, 2) ?></strong></td></tr>
                            <tr style="background:#f8f9fa;"><td><strong>EXPENSES</strong></td><td class="text-right"></td></tr>
                            <?php foreach ($expenses as $e): ?>
                            <tr><td><?= htmlspecialchars($e['account_name']) ?></td><td class="text-right amount-negative">($<?= number_format($e['amount'], 2) ?>)</td></tr>
                            <?php endforeach; ?>
                            <tr style="border-top:2px solid #ddd; background:#e9f7ef;"><td><strong>NET PROFIT</strong></td><td class="text-right <?= $net_profit_pl >= 0 ? 'amount-positive' : 'amount-negative' ?>"><strong>$<?= number_format($net_profit_pl, 2) ?></strong></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="col-md-6">
                <div class="info-card">
                    <h4><i class="fas fa-scale-balanced"></i> Balance Sheet</h4>
                    <table class="data-table">
                        <thead><tr><th>Account</th><th class="text-right">Balance</th></tr></thead>
                        <tbody>
                            <tr style="background:#f8f9fa;"><td><strong>ASSETS</strong></td><td class="text-right"></td></tr>
                            <?php foreach ($assets as $a): ?>
                            <tr><td><?= htmlspecialchars($a['account_name']) ?></td><td class="text-right">$<?= number_format($a['balance'], 2) ?></td></tr>
                            <?php endforeach; ?>
                            <tr><td><strong>Total Assets</strong></td><td class="text-right"><strong>$<?= number_format($total_assets, 2) ?></strong></td></tr>
                            <tr style="background:#f8f9fa;"><td><strong>LIABILITIES</strong></td><td class="text-right"></td></tr>
                            <?php foreach ($liabilities as $l): ?>
                            <tr><td><?= htmlspecialchars($l['account_name']) ?></td><td class="text-right">$<?= number_format($l['balance'], 2) ?></td></tr>
                            <?php endforeach; ?>
                            <tr><td><strong>Total Liabilities</strong></td><td class="text-right"><strong>$<?= number_format($total_liabilities, 2) ?></strong></td></tr>
                            <tr style="background:#f8f9fa;"><td><strong>EQUITY</strong></td><td class="text-right"></td></tr>
                            <?php foreach ($equity as $e): ?>
                            <tr><td><?= htmlspecialchars($e['account_name']) ?></td><td class="text-right">$<?= number_format($e['balance'], 2) ?></td></tr>
                            <?php endforeach; ?>
                            <tr><td><strong>Total Equity</strong></td><td class="text-right"><strong>$<?= number_format($total_equity, 2) ?></strong></td></tr>
                            <tr style="border-top:2px solid #ddd;"><td><strong>Liabilities + Equity</strong></td><td class="text-right"><strong>$<?= number_format($total_liabilities_equity, 2) ?></strong></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- TAB 2: CONTAINERS -->
    <div class="tab-content" id="tab-containers">
        <div class="row">
            <div class="col-md-4">
                <div class="stat-card">
                    <h3>Total Containers</h3>
                    <div class="stat-number"><?= number_format($system_stats['total_containers']) ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <h3>Loaded Containers</h3>
                    <div class="stat-number"><?= number_format(array_sum(array_column(array_filter($container_status, function($s) { return $s['status'] == 'loaded'; }), 'count'))) ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <h3>Avg Utilization</h3>
                    <div class="stat-number"><?= count($container_utilization) > 0 ? round(array_sum(array_column($container_utilization, 'utilization')) / count($container_utilization), 1) : 0 ?>%</div>
                </div>
            </div>
        </div>
        
        <div class="info-card">
            <h4><i class="fas fa-list"></i> All Containers</h4>
            <div class="table-container" style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Container #</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Origin</th>
                            <th>Capacity (CBM)</th>
                            <th>Used (CBM)</th>
                            <th>Remaining</th>
                            <th>Utilization</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($containers as $c): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($c['container_number']) ?></strong></td>
                            <td><?= htmlspecialchars($c['container_type']) ?></td>
                            <td><?= ucfirst(htmlspecialchars($c['status'])) ?></td>
                            <td><?= htmlspecialchars($origin_names[$c['origin']] ?? $c['origin']) ?></td>
                            <td><?= number_format($c['size_cbm'], 2) ?></td>
                            <td><?= number_format($c['used_cbm'], 2) ?></td>
                            <td><?= number_format($c['remaining_cbm'], 2) ?></td>
                            <td>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar" style="width: <?= $c['utilization'] ?>%; background: <?= $c['utilization'] > 90 ? '#B42318' : ($c['utilization'] > 70 ? '#ff9800' : '#0F7A3A') ?>;">
                                        <?= $c['utilization'] ?>%
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($containers)): ?>
                        <tr><td colspan="8" class="text-center">No containers found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php if ($include_details && !empty($container_utilization)): ?>
        <div class="info-card">
            <h4><i class="fas fa-chart-line"></i> Container Utilization (Top 10 by Usage)</h4>
            <table class="data-table">
                <thead><tr><th>Container #</th><th>Type</th><th>Capacity</th><th>Used CBM</th><th>Utilization</th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($container_utilization, 0, 10) as $cu): ?>
                    <tr>
                        <td><?= htmlspecialchars($cu['container_number']) ?></td>
                        <td><?= $cu['container_type'] ?></td>
                        <td><?= number_format($cu['size_cbm'], 2) ?></td>
                        <td><?= number_format($cu['used_cbm'], 2) ?></td>
                        <td><span class="badge <?= $cu['utilization'] > 90 ? 'bg-danger' : ($cu['utilization'] > 70 ? 'bg-warning' : 'bg-success') ?>"><?= $cu['utilization'] ?>%</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- TAB 3: CUSTOMER DEBT -->
    <div class="tab-content" id="tab-debt">
        <div class="row">
            <div class="col-md-4">
                <div class="stat-card" style="border-left-color: var(--curdun-danger);">
                    <h3>Total Customer Debt</h3>
                    <div class="stat-number text-danger">$<?= number_format($invoice_metrics['accounts_receivable'], 2) ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <h3>Overdue Invoices</h3>
                    <div class="stat-number"><?= number_format($invoice_metrics['overdue_count']) ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <h3>Collection Rate</h3>
                    <div class="stat-number"><?= $invoice_metrics['total_revenue'] > 0 ? number_format(($invoice_metrics['total_collected'] / $invoice_metrics['total_revenue']) * 100, 1) : 0 ?>%</div>
                </div>
            </div>
        </div>
        
        <div class="info-card">
            <h4><i class="fas fa-clock"></i> Accounts Receivable Aging</h4>
            <table class="data-table">
                <thead><tr><th>Bucket</th><th class="text-right">Amount</th><th>Percentage</th></tr></thead>
                <tbody>
                    <?php foreach ($aging_buckets as $bucket => $amount): ?>
                    <tr>
                        <td><?= $bucket ?> Days</td>
                        <td class="text-right <?= $amount > 0 ? 'text-danger' : '' ?>">$<?= number_format($amount, 2) ?></td>
                        <td><?= $total_aging > 0 ? number_format(($amount / $total_aging) * 100, 1) : 0 ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="border-top:2px solid #ddd;">
                        <td><strong>Total Due</strong></td>
                        <td class="text-right"><strong>$<?= number_format($total_aging, 2) ?></strong></td>
                        <td>100%</td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="info-card">
            <h4><i class="fas fa-users"></i> Customers with Outstanding Debt</h4>
            <div class="table-container" style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Total Due</th>
                            <th>Open Invoices</th>
                            <th>Loyalty Points</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($debt_customers as $d): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($d['customer_name']) ?></strong></td>
                            <td><?= htmlspecialchars($d['phone']) ?></td>
                            <td class="text-danger">$<?= number_format($d['total_due'], 2) ?></td>
                            <td><?= $d['open_invoices'] ?></td>
                            <td><?= number_format($d['loyalty_points']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($debt_customers)): ?>
                        <tr><td colspan="5" class="text-center">No customers with outstanding debt</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php if ($include_details && !empty($collection_log)): ?>
        <div class="info-card">
            <h4><i class="fas fa-history"></i> Debt Collection Log (Last 50 entries for period)</h4>
            <table class="data-table">
                <thead><tr><th>Date</th><th>Customer</th><th>Action</th><th>Amount</th><th>Collected By</th><th>Notes</th></tr></thead>
                <tbody>
                    <?php foreach ($collection_log as $log): ?>
                    <tr>
                        <td><?= date('Y-m-d H:i', strtotime($log['created_at'])) ?></td>
                        <td><?= htmlspecialchars($log['customer_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($log['action_type']) ?></td>
                        <td class="amount-positive">$<?= number_format($log['amount_collected'], 2) ?></td>
                        <td><?= htmlspecialchars($log['collector_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars(substr($log['notes'] ?? '', 0, 50)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- TAB 4: WAREHOUSE -->
    <div class="tab-content" id="tab-warehouse">
        <div class="row">
            <div class="col-md-3">
                <div class="stat-card"><h3>Total Items</h3><div class="stat-number"><?= number_format($wh_summary['total_items']) ?></div></div>
            </div>
            <div class="col-md-3">
                <div class="stat-card"><h3>Total Quantity</h3><div class="stat-number"><?= number_format($wh_summary['total_quantity']) ?></div></div>
            </div>
            <div class="col-md-3">
                <div class="stat-card"><h3>Total Volume (CBM)</h3><div class="stat-number"><?= number_format($wh_summary['total_cbm'], 2) ?></div></div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="border-left-color: var(--curdun-danger);"><h3>Low Stock Alerts</h3><div class="stat-number text-danger"><?= number_format($wh_summary['low_stock']) ?></div></div>
            </div>
        </div>
        
        <div class="info-card">
            <h4><i class="fas fa-globe"></i> Stock by Origin</h4>
            <table class="data-table">
                <thead><tr><th>Origin</th><th>Items</th><th>Quantity</th><th>Volume (CBM)</th><th>Total Value</th></tr></thead>
                <tbody>
                    <?php foreach ($stock_by_origin as $origin => $data): ?>
                    <tr>
                        <td><strong><?= $origin_names[$origin] ?? $origin ?></strong></td>
                        <td><?= number_format($data['items']) ?></td>
                        <td><?= number_format($data['qty']) ?></td>
                        <td><?= number_format($data['cbm'], 2) ?></td>
                        <td class="amount-positive">$<?= number_format($data['value'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="info-card">
            <h4><i class="fas fa-boxes"></i> All Warehouse Stock</h4>
            <div class="table-container" style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Stock Name</th>
                            <th>Origin</th>
                            <th>Quantity</th>
                            <th>Volume (CBM)</th>
                            <th>Unit Price</th>
                            <th>Total Value</th>
                            <th>Location</th>
                            <th>Customer</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($warehouse_stocks as $w): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($w['stock_name']) ?></strong></td>
                            <td><?= $origin_names[$w['origin']] ?? $w['origin'] ?></td>
                            <td class="<?= $w['quantity'] <= $w['minimum_stock'] ? 'text-danger' : '' ?>"><?= number_format($w['quantity']) ?></td>
                            <td><?= number_format($w['volume_cbm'], 2) ?></td>
                            <td>$<?= number_format($w['unit_price'], 2) ?></td>
                            <td class="amount-positive">$<?= number_format($w['total_value'], 2) ?></td>
                            <td><?= htmlspecialchars($w['location'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($w['customer_name'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($warehouse_stocks)): ?>
                        <tr><td colspan="8" class="text-center">No stock items found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- TAB 5: FINANCIAL METRICS -->
    <div class="tab-content" id="tab-financials">
        <div class="row">
            <div class="col-md-4">
                <div class="stat-card"><h3>Total Revenue</h3><div class="stat-number">$<?= number_format($invoice_metrics['total_revenue'], 2) ?></div></div>
            </div>
            <div class="col-md-4">
                <div class="stat-card"><h3>Total Collected</h3><div class="stat-number">$<?= number_format($invoice_metrics['total_collected'], 2) ?></div></div>
            </div>
            <div class="col-md-4">
                <div class="stat-card"><h3>Net Cash Flow</h3><div class="stat-number <?= $net_cashflow >= 0 ? 'text-success' : 'text-danger' ?>">$<?= number_format($net_cashflow, 2) ?></div></div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="info-card">
                    <h4><i class="fas fa-chart-simple"></i> Invoice Metrics</h4>
                    <table class="data-table">
                        <tr><td>Total Invoices</td><td class="text-right"><?= number_format($invoice_metrics['invoice_count']) ?></td></tr>
                        <tr><td>Paid Invoices</td><td class="text-right"><?= number_format($invoice_metrics['paid_count']) ?></td></tr>
                        <tr><td>Overdue Invoices</td><td class="text-right text-danger"><?= number_format($invoice_metrics['overdue_count']) ?></td></tr>
                        <tr><td>Accounts Receivable</td><td class="text-right text-danger">$<?= number_format($invoice_metrics['accounts_receivable'], 2) ?></td></tr>
                        <tr><td>Cash Inflow (Receipts)</td><td class="text-right amount-positive">$<?= number_format($cashflow['total_receipts'], 2) ?></td></tr>
                        <tr><td>Cash Outflow (Payments)</td><td class="text-right amount-negative">($<?= number_format($cashflow['total_payments'], 2) ?>)</td></tr>
                    </table>
                </div>
            </div>
            <div class="col-md-6">
                <div class="info-card">
                    <h4><i class="fas fa-calendar-alt"></i> Monthly Revenue Trend (Last 12 Months)</h4>
                    <table class="data-table">
                        <thead><tr><th>Month</th><th class="text-right">Revenue</th><th class="text-right">Invoices</th></tr></thead>
                        <tbody>
                            <?php foreach ($monthly_data as $m): ?>
                            <tr>
                                <td><?= date('M Y', strtotime($m['month'] . '-01')) ?></td>
                                <td class="text-right amount-positive">$<?= number_format($m['revenue'], 2) ?></td>
                                <td class="text-right"><?= number_format($m['invoices']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <?php if ($include_details): ?>
        <div class="info-card">
            <h4><i class="fas fa-file-invoice-dollar"></i> Detailed Journal Entries (First 20)</h4>
            <?php
            $stmt_je = $pdo->prepare("SELECT je.*, t.name as tenant_name FROM journal_entries je LEFT JOIN tenants t ON je.tenant_id = t.id ORDER BY je.entry_date DESC LIMIT 20");
            $stmt_je->execute();
            $journal_entries = $stmt_je->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <table class="data-table">
                <thead><tr><th>Date</th><th>Tenant</th><th>Entry #</th><th>Account Name</th><th>Debit</th><th>Credit</th><th>Description</th></tr></thead>
                <tbody>
                    <?php foreach ($journal_entries as $je): ?>
                    <tr>
                        <td><?= $je['entry_date'] ?></td>
                        <td><?= htmlspecialchars($je['tenant_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($je['entry_number'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($je['account_name']) ?></td>
                        <td class="text-right">$<?= number_format($je['debit'], 2) ?></td>
                        <td class="text-right">$<?= number_format($je['credit'], 2) ?></td>
                        <td><?= htmlspecialchars(substr($je['description'] ?? '', 0, 40)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- TAB 6: SYSTEM STATISTICS -->
    <div class="tab-content" id="tab-stats">
        <div class="row">
            <?php 
            $sys_stats_display = [
                ['Total Companies', $system_stats['total_companies'], 'fa-building', 'primary'],
                ['Active Companies', $system_stats['active_companies'], 'fa-check-circle', 'success'],
                ['Total Users', $system_stats['total_users'], 'fa-users', 'info'],
                ['Total Customers', $system_stats['total_customers'], 'fa-user-friends', 'warning'],
                ['Total Containers', $system_stats['total_containers'], 'fa-box', 'dark'],
                ['Total Trips', $system_stats['total_trips'], 'fa-truck', 'secondary'],
                ['Total Invoices', $system_stats['total_invoices'], 'fa-file-invoice', 'danger'],
                ['Total Receipts', $system_stats['total_receipts'], 'fa-receipt', 'success'],
                ['Stock Items', $system_stats['total_stock_items'], 'fa-warehouse', 'info']
            ];
            foreach ($sys_stats_display as $s): ?>
            <div class="col-md-3">
                <div class="stat-card">
                    <h3><i class="fas <?= $s[2] ?>"></i> <?= $s[0] ?></h3>
                    <div class="stat-number"><?= number_format($s[1]) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="info-card">
            <h4><i class="fas fa-user-tag"></i> User Role Distribution</h4>
            <table class="data-table">
                <thead><tr><th>Role</th><th class="text-right">Count</th><th>Percentage</th></tr></thead>
                <tbody>
                    <?php 
                    $total_users = $system_stats['total_users'];
                    foreach ($role_distribution as $r): 
                        $pct = $total_users > 0 ? ($r['count'] / $total_users) * 100 : 0;
                    ?>
                    <tr>
                        <td><?= $role_names[$r['role_type']] ?? $r['role_type'] ?></td>
                        <td class="text-right"><?= number_format($r['count']) ?></td>
                        <td><?= number_format($pct, 1) ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="info-card">
            <h4><i class="fas fa-chart-pie"></i> System Overview</h4>
            <div class="row">
                <div class="col-md-6">
                    <div class="progress mb-3" style="height: 30px;">
                        <div class="progress-bar bg-success" style="width: <?= $system_stats['active_companies'] > 0 ? ($system_stats['active_companies'] / $system_stats['total_companies']) * 100 : 0 ?>%">
                            Active Companies: <?= $system_stats['active_companies'] ?> / <?= $system_stats['total_companies'] ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="progress mb-3" style="height: 30px;">
                        <div class="progress-bar bg-info" style="width: <?= $system_stats['total_customers'] > 0 ? min(100, ($system_stats['total_users'] / max(1, $system_stats['total_customers'])) * 10) : 0 ?>%">
                            Users vs Customers: <?= $system_stats['total_users'] ?> / <?= $system_stats['total_customers'] ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($include_details): ?>
        <div class="info-card">
            <h4><i class="fas fa-list"></i> Recent System Activity (First 20 from audit_logs)</h4>
            <?php
            $audit_where = ($tenant_filter > 0) ? "WHERE tenant_id = $tenant_filter" : "";
            $audit_logs = $pdo->query("SELECT * FROM audit_logs $audit_where ORDER BY created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <table class="data-table">
                <thead><tr><th>Date</th><th>Action</th><th>Table</th><th>Record ID</th><th>IP Address</th></tr></thead>
                <tbody>
                    <?php foreach ($audit_logs as $log): ?>
                    <tr>
                        <td><?= date('Y-m-d H:i', strtotime($log['created_at'])) ?></td>
                        <td><?= htmlspecialchars($log['action']) ?></td>
                        <td><?= htmlspecialchars($log['table_name'] ?? '-') ?></td>
                        <td><?= $log['record_id'] ?? '-' ?></td>
                        <td><?= htmlspecialchars($log['ip_address'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Report Footer -->
    <div class="report-footer">
        <p>Cargo Management System - Full System Report</p>
        <p>Generated: <?= date('Y-m-d H:i:s') ?> | Period: <?= date('d/m/Y', strtotime($date_from)) ?> - <?= date('d/m/Y', strtotime($date_to)) ?></p>
        <p>&copy; <?= date('Y') ?> Cargo Management System. All rights reserved.</p>
    </div>
</div>

<script>
// Tab switching functionality
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const tabId = this.getAttribute('data-tab');
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        
        // Save active tab to sessionStorage
        sessionStorage.setItem('activeReportTab', tabId);
    });
});

// Restore active tab from sessionStorage
const savedTab = sessionStorage.getItem('activeReportTab');
if (savedTab && document.getElementById(savedTab)) {
    document.querySelectorAll('.tab-btn').forEach(btn => {
        if (btn.getAttribute('data-tab') === savedTab) {
            btn.click();
        }
    });
}
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>