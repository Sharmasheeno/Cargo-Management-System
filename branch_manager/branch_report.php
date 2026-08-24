<?php
// branch_manager/branch_report.php
// Branch Report - summary cards for this branch only (reception/warehouse volume,
// container/trip throughput, revenue). A scoped-down cousin of tenant_admin/reports.php.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db_connect.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    die('Database connection failed: $pdo not found. Check config/db_connect.php');
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role_type'] ?? $_SESSION['role'] ?? '') !== 'branch_manager') {
    header("Location: ../login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
$user_name = $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'Branch Manager';

if ($tenant_id <= 0) {
    header("Location: ../login.php?error=no_tenant");
    exit;
}

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
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container mt-4"><div class="alert alert-danger">You are not assigned to any branch. Please contact administrator.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}
$assigned_branch_id = (int)$assigned_branch_id;

$stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1");
$stmt->execute([$assigned_branch_id, $tenant_id]);
$current_branch = $stmt->fetch(PDO::FETCH_ASSOC);
$branch_name = $current_branch['branch_name'] ?? 'My Branch';

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money2($value): string {
    return number_format((float)$value, 2, '.', '');
}

// -----------------------------------------------------
// Period selection
// -----------------------------------------------------
$period = $_GET['period'] ?? '30d';
$custom_from = $_GET['from'] ?? '';
$custom_to = $_GET['to'] ?? '';

switch ($period) {
    case 'today':
        $date_from = date('Y-m-d');
        $date_to = date('Y-m-d');
        break;
    case '7d':
        $date_from = date('Y-m-d', strtotime('-6 days'));
        $date_to = date('Y-m-d');
        break;
    case 'this_month':
        $date_from = date('Y-m-01');
        $date_to = date('Y-m-d');
        break;
    case 'custom':
        $date_from = $custom_from ?: date('Y-m-d', strtotime('-30 days'));
        $date_to = $custom_to ?: date('Y-m-d');
        break;
    case '30d':
    default:
        $period = '30d';
        $date_from = date('Y-m-d', strtotime('-29 days'));
        $date_to = date('Y-m-d');
        break;
}

// -----------------------------------------------------
// Metrics (all scoped strictly to this tenant + this branch)
// -----------------------------------------------------

// Warehouse / reception volume: items received into warehouse_stock at this branch
$warehouse_stats = ['items_received' => 0, 'total_cbm' => 0, 'total_value' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS items_received, COALESCE(SUM(volume_cbm),0) AS total_cbm, COALESCE(SUM(quantity * unit_price),0) AS total_value
        FROM warehouse_stock
        WHERE tenant_id = ? AND branch_id = ? AND DATE(created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$tenant_id, $assigned_branch_id, $date_from, $date_to]);
    $warehouse_stats = array_merge($warehouse_stats, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {}

// Current warehouse on-hand snapshot (not period-bound)
$warehouse_onhand = ['stock_items' => 0, 'stock_cbm' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS stock_items, COALESCE(SUM(volume_cbm),0) AS stock_cbm
        FROM warehouse_stock
        WHERE tenant_id = ? AND branch_id = ? AND is_active = 1 AND mogadishu_status != 'taken'
    ");
    $stmt->execute([$tenant_id, $assigned_branch_id]);
    $warehouse_onhand = array_merge($warehouse_onhand, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {}

// Container throughput at this branch
$container_stats = ['total' => 0, 'delivered' => 0, 'in_progress' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
               SUM(CASE WHEN status NOT IN ('delivered') THEN 1 ELSE 0 END) AS in_progress
        FROM containers
        WHERE tenant_id = ? AND current_branch_id = ? AND DATE(created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$tenant_id, $assigned_branch_id, $date_from, $date_to]);
    $container_stats = array_merge($container_stats, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {}

// Trip throughput at this branch
$trip_stats = ['total' => 0, 'completed' => 0, 'active' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
               SUM(CASE WHEN status NOT IN ('completed','delivered') THEN 1 ELSE 0 END) AS active
        FROM trucking_trips
        WHERE tenant_id = ? AND branch_id = ? AND DATE(created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$tenant_id, $assigned_branch_id, $date_from, $date_to]);
    $trip_stats = array_merge($trip_stats, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {}

// Branch transfers involving this branch
$transfer_stats = ['total' => 0, 'incoming' => 0, 'outgoing' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN to_branch_id = ? THEN 1 ELSE 0 END) AS incoming,
               SUM(CASE WHEN from_branch_id = ? THEN 1 ELSE 0 END) AS outgoing
        FROM branch_transfers
        WHERE tenant_id = ? AND (from_branch_id = ? OR to_branch_id = ?) AND DATE(requested_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$assigned_branch_id, $assigned_branch_id, $tenant_id, $assigned_branch_id, $assigned_branch_id, $date_from, $date_to]);
    $transfer_stats = array_merge($transfer_stats, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {}

// Revenue: receipts collected at this branch (direct branch_id)
$receipt_revenue = ['count' => 0, 'total_amount' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS count, COALESCE(SUM(amount),0) AS total_amount
        FROM receipts
        WHERE tenant_id = ? AND branch_id = ? AND payment_date BETWEEN ? AND ?
    ");
    $stmt->execute([$tenant_id, $assigned_branch_id, $date_from, $date_to]);
    $receipt_revenue = array_merge($receipt_revenue, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {}

// Invoices billed for trips of this branch (join-through-trips, same as invoices.php)
$invoice_stats = ['count' => 0, 'total_billed' => 0, 'total_paid' => 0, 'outstanding' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS count, COALESCE(SUM(total_amount),0) AS total_billed,
               COALESCE(SUM(paid_amount),0) AS total_paid, COALESCE(SUM(total_amount - paid_amount),0) AS outstanding
        FROM invoices
        WHERE tenant_id = ? AND trip_id IN (SELECT id FROM trucking_trips WHERE tenant_id = ? AND branch_id = ?)
          AND invoice_date BETWEEN ? AND ?
    ");
    $stmt->execute([$tenant_id, $tenant_id, $assigned_branch_id, $date_from, $date_to]);
    $invoice_stats = array_merge($invoice_stats, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {}

// Expenses for trips of this branch (join-through-trips, same as expenses.php)
$expense_stats = ['count' => 0, 'total_amount' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS count, COALESCE(SUM(amount),0) AS total_amount
        FROM expenses
        WHERE tenant_id = ? AND trip_id IN (SELECT id FROM trucking_trips WHERE tenant_id = ? AND branch_id = ?)
          AND expense_date BETWEEN ? AND ?
    ");
    $stmt->execute([$tenant_id, $tenant_id, $assigned_branch_id, $date_from, $date_to]);
    $expense_stats = array_merge($expense_stats, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {}

$net_revenue = (float)$receipt_revenue['total_amount'] - (float)$expense_stats['total_amount'];

// Daily receipt revenue trend for a simple chart (last 14 days of the selected range, capped)
$trend_labels = [];
$trend_values = [];
try {
    $stmt = $pdo->prepare("
        SELECT payment_date, COALESCE(SUM(amount),0) AS total
        FROM receipts
        WHERE tenant_id = ? AND branch_id = ? AND payment_date BETWEEN ? AND ?
        GROUP BY payment_date
        ORDER BY payment_date ASC
    ");
    $stmt->execute([$tenant_id, $assigned_branch_id, $date_from, $date_to]);
    $trendRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($trendRows as $tr) {
        $trend_labels[] = date('d M', strtotime($tr['payment_date']));
        $trend_values[] = round((float)$tr['total'], 2);
    }
} catch (Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Report - <?= h($branch_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        body { background:#f4f6f9; }
        .page-wrap { padding: 20px; }
        .hero { background: linear-gradient(135deg,#2D1859,#4B2C85); color:#fff; border-radius:18px; padding:22px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
        .hero h3 { margin:0; font-weight:700; }
        .hero small { opacity:.9; }
        .stat-card { background:#fff; border-radius:16px; padding:18px; box-shadow:0 6px 18px rgba(0,0,0,.06); border:1px solid #eee; height:100%; }
        .stat-card .num { font-size:26px; font-weight:800; color:#2D1859; }
        .stat-card .lbl { color:#6c757d; font-size:13px; }
        .stat-card .sub { font-size:12px; color:#8a8a8a; margin-top:4px; }
        .section-title { font-weight:700; color:#2D1859; margin: 25px 0 12px; }
        .panel { background:#fff; border-radius:16px; padding:18px; box-shadow:0 6px 18px rgba(0,0,0,.06); border:1px solid #eee; }
        .btn-main { background:#2D1859; color:#fff; border:0; }
        .btn-main:hover { background:#1F0F3D; color:#fff; }
        .period-form select, .period-form input { display:inline-block; width:auto; }
    </style>
</head>
<body>
<div class="page-wrap">
    <div class="hero">
        <div>
            <h3><i class="fas fa-chart-simple"></i> Branch Report</h3>
            <small>Branch: <?= h($branch_name) ?> <?= !empty($current_branch['branch_code']) ? '(' . h($current_branch['branch_code']) . ')' : '' ?> · <?= h($date_from) ?> to <?= h($date_to) ?></small>
        </div>
    </div>

    <div class="panel mb-4">
        <form method="get" class="form-inline period-form">
            <label class="mr-2 mb-0">Period:</label>
            <select name="period" class="form-control mr-2 mb-2" onchange="this.form.submit()">
                <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>Today</option>
                <option value="7d" <?= $period === '7d' ? 'selected' : '' ?>>Last 7 Days</option>
                <option value="30d" <?= $period === '30d' ? 'selected' : '' ?>>Last 30 Days</option>
                <option value="this_month" <?= $period === 'this_month' ? 'selected' : '' ?>>This Month</option>
                <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Custom Range</option>
            </select>
            <?php if ($period === 'custom'): ?>
                <input type="date" name="from" value="<?= h($date_from) ?>" class="form-control mr-2 mb-2">
                <input type="date" name="to" value="<?= h($date_to) ?>" class="form-control mr-2 mb-2">
                <button type="submit" class="btn btn-main mb-2"><i class="fas fa-check"></i> Apply</button>
            <?php endif; ?>
        </form>
    </div>

    <div class="section-title"><i class="fas fa-dollar-sign"></i> Revenue Summary</div>
    <div class="row">
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num">$<?= money2($receipt_revenue['total_amount']) ?></div><div class="lbl">Receipts Collected</div><div class="sub"><?= number_format((int)$receipt_revenue['count']) ?> receipt(s)</div></div></div>
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num">$<?= money2($invoice_stats['total_billed']) ?></div><div class="lbl">Invoiced (Trip-Linked)</div><div class="sub"><?= number_format((int)$invoice_stats['count']) ?> invoice(s), $<?= money2($invoice_stats['outstanding']) ?> outstanding</div></div></div>
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num">$<?= money2($expense_stats['total_amount']) ?></div><div class="lbl">Expenses (Trip-Linked)</div><div class="sub"><?= number_format((int)$expense_stats['count']) ?> expense(s)</div></div></div>
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num" style="color:<?= $net_revenue >= 0 ? '#0F7A3A' : '#B42318' ?>">$<?= money2($net_revenue) ?></div><div class="lbl">Net (Receipts - Expenses)</div></div></div>
    </div>

    <div class="section-title"><i class="fas fa-warehouse"></i> Warehouse &amp; Reception Volume</div>
    <div class="row">
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$warehouse_stats['items_received']) ?></div><div class="lbl">Items Received (Period)</div></div></div>
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num"><?= number_format((float)$warehouse_stats['total_cbm'], 2) ?></div><div class="lbl">CBM Received (Period)</div></div></div>
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$warehouse_onhand['stock_items']) ?></div><div class="lbl">Currently On-Hand (Items)</div></div></div>
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num"><?= number_format((float)$warehouse_onhand['stock_cbm'], 2) ?></div><div class="lbl">Currently On-Hand (CBM)</div></div></div>
    </div>

    <div class="section-title"><i class="fas fa-boxes-stacked"></i> Container &amp; Trip Throughput</div>
    <div class="row">
        <div class="col-md-2 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$container_stats['total']) ?></div><div class="lbl">Containers (Period)</div></div></div>
        <div class="col-md-2 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$container_stats['delivered']) ?></div><div class="lbl">Delivered</div></div></div>
        <div class="col-md-2 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$container_stats['in_progress']) ?></div><div class="lbl">In Progress</div></div></div>
        <div class="col-md-2 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$trip_stats['total']) ?></div><div class="lbl">Trips (Period)</div></div></div>
        <div class="col-md-2 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$trip_stats['completed']) ?></div><div class="lbl">Trips Completed</div></div></div>
        <div class="col-md-2 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$trip_stats['active']) ?></div><div class="lbl">Trips Active</div></div></div>
    </div>

    <div class="section-title"><i class="fas fa-exchange-alt"></i> Branch Transfers</div>
    <div class="row">
        <div class="col-md-4 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$transfer_stats['total']) ?></div><div class="lbl">Total Transfers (Period)</div></div></div>
        <div class="col-md-4 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$transfer_stats['incoming']) ?></div><div class="lbl">Incoming</div></div></div>
        <div class="col-md-4 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$transfer_stats['outgoing']) ?></div><div class="lbl">Outgoing</div></div></div>
    </div>

    <?php if (count($trend_values) > 1): ?>
    <div class="section-title"><i class="fas fa-chart-line"></i> Daily Receipt Revenue</div>
    <div class="panel mb-4">
        <canvas id="revenueChart" height="80"></canvas>
    </div>
    <?php endif; ?>
</div>

<?php if (count($trend_values) > 1): ?>
<script>
const ctx = document.getElementById('revenueChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode($trend_labels) ?>,
        datasets: [{
            label: 'Receipts ($)',
            data: <?= json_encode($trend_values) ?>,
            borderColor: '#2D1859',
            backgroundColor: 'rgba(45,24,89,0.1)',
            tension: 0.3,
            fill: true
        }]
    },
    options: { responsive: true, plugins: { legend: { display: false } } }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
