<?php
// staff/dashboard.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/shipment_functions.php';

$staff_role_types = staffFamilyRoleTypes();
$current_role_type = $_SESSION['role_type'] ?? $_SESSION['role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($current_role_type, $staff_role_types, true)) {
    header("Location: ../login.php");
    exit;
}

ensureShipmentSchema($pdo);

$tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);
$assigned_branch_id = (int)($_SESSION['assigned_branch_id'] ?? 0);
if ($assigned_branch_id <= 0) {
    try {
        $st = $pdo->prepare("SELECT branch_id FROM user_branch_assignments WHERE user_id = ? AND is_primary = 1 LIMIT 1");
        $st->execute([$user_id]);
        $assigned_branch_id = (int)$st->fetchColumn();
    } catch (Throwable $e) {}
}
if ($assigned_branch_id <= 0) {
    try {
        $st = $pdo->prepare("SELECT default_branch_id FROM users WHERE id = ? AND tenant_id = ? LIMIT 1");
        $st->execute([$user_id, $tenant_id]);
        $assigned_branch_id = (int)$st->fetchColumn();
    } catch (Throwable $e) {}
}
if ($assigned_branch_id > 0) $_SESSION['assigned_branch_id'] = $assigned_branch_id;

$branch_name = 'Assigned Branch';
try {
    $st = $pdo->prepare("SELECT branch_name FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1");
    $st->execute([$assigned_branch_id, $tenant_id]);
    $branch_name = (string)($st->fetchColumn() ?: $branch_name);
} catch (Throwable $e) {}

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

$role_title = roleDisplayName($current_role_type);

$warehouse = [
    'awaiting_receipt' => 0,
    'in_warehouse' => 0,
    'ready_for_loading' => 0,
    'incoming_trips' => 0,
    'recent_movements' => 0,
];
$logistics = [
    'ready_for_loading' => 0,
    'containers_loading' => 0,
    'containers_ready' => 0,
    'trips_pending_approval' => 0,
    'trips_in_transit' => 0,
    'arrivals_completed' => 0,
    'recent_activity' => 0,
    'ready_shipments' => [],
    'active_containers' => [],
    'active_trips' => [],
    'recent_events' => [],
];
$finance = [
    'invoice_count' => 0,
    'total_invoiced' => 0.0,
    'total_paid' => 0.0,
    'outstanding' => 0.0,
    'receipts_count' => 0,
    'receipts_total' => 0.0,
    'expenses_total' => 0.0,
    'open_invoices' => [],
    'recent_receipts' => [],
];
if ($current_role_type === 'warehouse_supervisor' && $tenant_id > 0 && $assigned_branch_id > 0) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM shipments WHERE tenant_id = ? AND origin_branch_id = ? AND current_status = 'RECEIVED'");
        $st->execute([$tenant_id, $assigned_branch_id]);
        $warehouse['awaiting_receipt'] = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(DISTINCT shipment_id) FROM warehouse_stock WHERE tenant_id = ? AND branch_id = ? AND shipment_id IS NOT NULL AND quantity > 0 AND COALESCE(is_active,1)=1");
        $st->execute([$tenant_id, $assigned_branch_id]);
        $warehouse['in_warehouse'] = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) FROM shipments WHERE tenant_id = ? AND origin_branch_id = ? AND current_status = 'READY_FOR_LOADING'");
        $st->execute([$tenant_id, $assigned_branch_id]);
        $warehouse['ready_for_loading'] = (int)$st->fetchColumn();

        $st = $pdo->prepare("
            SELECT COUNT(DISTINCT t.id)
            FROM trucking_trips t
            JOIN cargo_manifest_items cmi ON cmi.container_id = t.container_id AND cmi.tenant_id = t.tenant_id
            JOIN shipments s ON s.id = cmi.master_shipment_id AND s.tenant_id = t.tenant_id
            WHERE t.tenant_id = ? AND t.to_branch_id = ?
              AND t.status IN ('delivered','completed')
              AND s.current_status IN ('ARRIVED_AT_DESTINATION','PARTIALLY_RECEIVED')
        ");
        $st->execute([$tenant_id, $assigned_branch_id]);
        $warehouse['incoming_trips'] = (int)$st->fetchColumn();

        $st = $pdo->prepare("
            SELECT COUNT(*)
            FROM stock_movements sm
            JOIN warehouse_stock ws ON ws.id = sm.warehouse_stock_id
            WHERE sm.tenant_id = ? AND ws.branch_id = ?
              AND sm.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $st->execute([$tenant_id, $assigned_branch_id]);
        $warehouse['recent_movements'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {}
}
if ($current_role_type === 'logistics_supervisor' && $tenant_id > 0 && $assigned_branch_id > 0) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM shipments WHERE tenant_id = ? AND origin_branch_id = ? AND current_status = 'READY_FOR_LOADING'");
        $st->execute([$tenant_id, $assigned_branch_id]);
        $logistics['ready_for_loading'] = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) FROM containers WHERE tenant_id = ? AND current_branch_id = ? AND status = 'loading'");
        $st->execute([$tenant_id, $assigned_branch_id]);
        $logistics['containers_loading'] = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) FROM containers WHERE tenant_id = ? AND current_branch_id = ? AND status = 'loaded'");
        $st->execute([$tenant_id, $assigned_branch_id]);
        $logistics['containers_ready'] = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) FROM trucking_trips WHERE tenant_id = ? AND branch_id = ? AND approval_status = 'pending_approval'");
        $st->execute([$tenant_id, $assigned_branch_id]);
        $logistics['trips_pending_approval'] = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) FROM trucking_trips WHERE tenant_id = ? AND from_branch_id = ? AND status = 'in_transit'");
        $st->execute([$tenant_id, $assigned_branch_id]);
        $logistics['trips_in_transit'] = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) FROM trucking_trips WHERE tenant_id = ? AND (from_branch_id = ? OR to_branch_id = ?) AND status IN ('delivered','completed')");
        $st->execute([$tenant_id, $assigned_branch_id, $assigned_branch_id]);
        $logistics['arrivals_completed'] = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT shipment_number, tracking_number, cargo_description, quantity, quantity_unit, current_status FROM shipments WHERE tenant_id = ? AND origin_branch_id = ? AND current_status = 'READY_FOR_LOADING' ORDER BY updated_at DESC LIMIT 5");
        $st->execute([$tenant_id, $assigned_branch_id]);
        $logistics['ready_shipments'] = $st->fetchAll(PDO::FETCH_ASSOC);

        $st = $pdo->prepare("
            SELECT c.container_number, c.status, COALESCE(mt.shipment_count,0) shipment_count, COALESCE(mt.used_cbm,0) used_cbm, c.size_cbm
            FROM containers c
            LEFT JOIN (
                SELECT tenant_id, container_id, COUNT(DISTINCT master_shipment_id) shipment_count, COALESCE(SUM(cbm_used),0) used_cbm
                FROM cargo_manifest_items WHERE master_shipment_id IS NOT NULL GROUP BY tenant_id, container_id
            ) mt ON mt.tenant_id = c.tenant_id AND mt.container_id = c.id
            WHERE c.tenant_id = ? AND c.current_branch_id = ? AND c.status IN ('received','loading','loaded')
            ORDER BY c.updated_at DESC, c.id DESC LIMIT 5");
        $st->execute([$tenant_id, $assigned_branch_id]);
        $logistics['active_containers'] = $st->fetchAll(PDO::FETCH_ASSOC);

        $st = $pdo->prepare("SELECT t.trip_number, t.status, t.approval_status, c.container_number, t.driver_name, t.truck_plate FROM trucking_trips t LEFT JOIN containers c ON c.id=t.container_id WHERE t.tenant_id = ? AND t.from_branch_id = ? AND t.status NOT IN ('completed') ORDER BY t.updated_at DESC, t.id DESC LIMIT 5");
        $st->execute([$tenant_id, $assigned_branch_id]);
        $logistics['active_trips'] = $st->fetchAll(PDO::FETCH_ASSOC);

        $st = $pdo->prepare("SELECT event_type, new_status, notes, created_at FROM shipment_events WHERE tenant_id = ? AND branch_id = ? AND event_type IN ('LOADED_INTO_CONTAINER','ASSIGNED_TO_TRIP','TRIP_IN_TRANSIT','ARRIVED_AT_DESTINATION','DISPATCH_APPROVED') ORDER BY created_at DESC LIMIT 6");
        $st->execute([$tenant_id, $assigned_branch_id]);
        $logistics['recent_events'] = $st->fetchAll(PDO::FETCH_ASSOC);
        $logistics['recent_activity'] = count($logistics['recent_events']);
    } catch (Throwable $e) {}
}
if ($current_role_type === 'finance_manager' && $tenant_id > 0 && $assigned_branch_id > 0) {
    try {
        $st = $pdo->prepare("
            SELECT COUNT(i.id) invoice_count,
                   COALESCE(SUM(i.total_amount),0) total_invoiced,
                   COALESCE(SUM(i.paid_amount),0) total_paid,
                   COALESCE(SUM(i.total_amount - i.paid_amount),0) outstanding
            FROM invoices i
            LEFT JOIN trucking_trips t ON t.id = i.trip_id AND t.tenant_id = i.tenant_id
            WHERE i.tenant_id = ? AND COALESCE(i.is_active,1)=1
              AND t.branch_id = ?
        ");
        $st->execute([$tenant_id, $assigned_branch_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $finance['invoice_count'] = (int)($row['invoice_count'] ?? 0);
        $finance['total_invoiced'] = (float)($row['total_invoiced'] ?? 0);
        $finance['total_paid'] = (float)($row['total_paid'] ?? 0);
        $finance['outstanding'] = (float)($row['outstanding'] ?? 0);

        $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount),0) FROM receipts WHERE tenant_id = ? AND branch_id = ?");
        $st->execute([$tenant_id, $assigned_branch_id]);
        $receiptRow = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
        $finance['receipts_count'] = (int)$receiptRow[0];
        $finance['receipts_total'] = (float)$receiptRow[1];

        $st = $pdo->prepare("
            SELECT COALESCE(SUM(e.amount),0)
            FROM expenses e
            LEFT JOIN trucking_trips t ON t.id = e.trip_id AND t.tenant_id = e.tenant_id
            WHERE e.tenant_id = ? AND COALESCE(e.is_active,1)=1
              AND t.branch_id = ?
        ");
        $st->execute([$tenant_id, $assigned_branch_id]);
        $finance['expenses_total'] = (float)$st->fetchColumn();

        $st = $pdo->prepare("
            SELECT i.invoice_number, i.total_amount, i.paid_amount, i.status, c.customer_name
            FROM invoices i
            LEFT JOIN customers c ON c.id = i.customer_id
            LEFT JOIN trucking_trips t ON t.id = i.trip_id AND t.tenant_id = i.tenant_id
            WHERE i.tenant_id = ? AND COALESCE(i.is_active,1)=1
              AND i.status NOT IN ('paid','cancelled')
              AND t.branch_id = ?
            ORDER BY (i.total_amount - i.paid_amount) DESC, i.invoice_date DESC
            LIMIT 5
        ");
        $st->execute([$tenant_id, $assigned_branch_id]);
        $finance['open_invoices'] = $st->fetchAll(PDO::FETCH_ASSOC);

        $st = $pdo->prepare("
            SELECT r.receipt_number, r.amount, r.payment_date, c.customer_name
            FROM receipts r
            LEFT JOIN customers c ON c.id = r.customer_id
            WHERE r.tenant_id = ? AND r.branch_id = ?
            ORDER BY r.payment_date DESC, r.id DESC
            LIMIT 5
        ");
        $st->execute([$tenant_id, $assigned_branch_id]);
        $finance['recent_receipts'] = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid staff-dashboard" style="padding:20px;">
    <?php if (($_GET['error'] ?? '') === 'access_denied'): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-ban"></i> <?= h($_SESSION['flash_message'] ?? 'You do not have permission to access that page.') ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
        <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <style>
        .staff-dashboard .hero { background:linear-gradient(135deg,#2D1859,#4B2C85); color:#fff; border-radius:16px; padding:24px; margin-bottom:22px; display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; }
        .staff-dashboard .hero h2 { margin:0; font-weight:800; }
        .staff-dashboard .hero p { margin:.35rem 0 0; opacity:.9; }
        .staff-dashboard .branch-pill { background:rgba(255,255,255,.15); padding:8px 14px; border-radius:999px; align-self:flex-start; }
        .staff-dashboard .stat-card { background:#fff; border:1px solid #E9E7F1; border-radius:14px; padding:18px; box-shadow:0 4px 16px rgba(45,24,89,.06); min-height:104px; }
        .staff-dashboard .stat-card small { color:#6B7280; text-transform:uppercase; font-weight:700; }
        .staff-dashboard .stat-card .num { color:#2D1859; font-size:28px; font-weight:800; margin-top:8px; }
        .staff-dashboard .quick-card { display:block; background:#fff; border:1px solid #E9E7F1; border-radius:14px; padding:18px; color:#2D1859; text-decoration:none; font-weight:700; }
        .staff-dashboard .quick-card:hover { color:#2D1859; box-shadow:0 8px 22px rgba(45,24,89,.10); text-decoration:none; }
    </style>

    <div class="hero">
        <div>
            <h2>Welcome, <?= h($_SESSION['user_name'] ?? 'Staff') ?></h2>
            <p><?= h($role_title) ?></p>
            <p><?= h($branch_name) ?></p>
        </div>
        <span class="branch-pill"><i class="fas fa-code-branch"></i> <?= h($branch_name) ?></span>
    </div>

    <?php if ($current_role_type === 'warehouse_supervisor'): ?>
        <div class="row mb-3">
            <div class="col-md-2 col-sm-6 mb-3"><div class="stat-card"><small>Awaiting Warehouse Receipt</small><div class="num"><?= number_format($warehouse['awaiting_receipt']) ?></div></div></div>
            <div class="col-md-2 col-sm-6 mb-3"><div class="stat-card"><small>Shipments In Warehouse</small><div class="num"><?= number_format($warehouse['in_warehouse']) ?></div></div></div>
            <div class="col-md-2 col-sm-6 mb-3"><div class="stat-card"><small>Ready For Loading</small><div class="num"><?= number_format($warehouse['ready_for_loading']) ?></div></div></div>
            <div class="col-md-2 col-sm-6 mb-3"><div class="stat-card"><small>Incoming Trips</small><div class="num"><?= number_format($warehouse['incoming_trips']) ?></div></div></div>
            <div class="col-md-2 col-sm-6 mb-3"><div class="stat-card"><small>Recent Stock Movements</small><div class="num"><?= number_format($warehouse['recent_movements']) ?></div></div></div>
        </div>
        <div class="row">
            <div class="col-md-3 mb-3"><a class="quick-card" href="shipments.php"><i class="fas fa-boxes-stacked"></i> Shipments</a></div>
            <div class="col-md-3 mb-3"><a class="quick-card" href="warehouse_stock.php"><i class="fas fa-warehouse"></i> Warehouse Cargo</a></div>
            <div class="col-md-3 mb-3"><a class="quick-card" href="stock_movements.php"><i class="fas fa-right-left"></i> Stock Movements</a></div>
            <div class="col-md-3 mb-3"><a class="quick-card" href="incoming_trips.php"><i class="fas fa-truck-ramp-box"></i> Incoming Trips</a></div>
        </div>
    <?php elseif ($current_role_type === 'logistics_supervisor'): ?>
        <div class="row mb-3">
            <div class="col-md-2 col-sm-6 mb-3"><div class="stat-card"><small>Ready for Loading</small><div class="num"><?= number_format($logistics['ready_for_loading']) ?></div></div></div>
            <div class="col-md-2 col-sm-6 mb-3"><div class="stat-card"><small>Containers Loading</small><div class="num"><?= number_format($logistics['containers_loading']) ?></div></div></div>
            <div class="col-md-2 col-sm-6 mb-3"><div class="stat-card"><small>Containers Ready</small><div class="num"><?= number_format($logistics['containers_ready']) ?></div></div></div>
            <div class="col-md-2 col-sm-6 mb-3"><div class="stat-card"><small>Trips Pending Approval</small><div class="num"><?= number_format($logistics['trips_pending_approval']) ?></div></div></div>
            <div class="col-md-2 col-sm-6 mb-3"><div class="stat-card"><small>Trips In Transit</small><div class="num"><?= number_format($logistics['trips_in_transit']) ?></div></div></div>
            <div class="col-md-2 col-sm-6 mb-3"><div class="stat-card"><small>Arrivals / Completed</small><div class="num"><?= number_format($logistics['arrivals_completed']) ?></div></div></div>
        </div>
        <div class="row">
            <div class="col-md-4 mb-3"><div class="stat-card"><small>Shipments Ready for Loading</small><?php foreach ($logistics['ready_shipments'] as $r): ?><div class="mt-2"><strong><?= h($r['shipment_number']) ?></strong> <?= h($r['quantity']) ?> <?= h($r['quantity_unit'] ?: 'Cartons') ?><br><span class="text-muted"><?= h($r['cargo_description']) ?></span></div><?php endforeach; if (!$logistics['ready_shipments']): ?><p class="text-muted mb-0 mt-2">None right now.</p><?php endif; ?></div></div>
            <div class="col-md-4 mb-3"><div class="stat-card"><small>Active Containers</small><?php foreach ($logistics['active_containers'] as $c): ?><div class="mt-2"><strong><?= h($c['container_number']) ?></strong> <?= h($c['status']) ?><br><span class="text-muted"><?= (int)$c['shipment_count'] ?> shipments · <?= number_format((float)$c['used_cbm'], 2) ?>/<?= number_format((float)$c['size_cbm'], 2) ?> CBM</span></div><?php endforeach; if (!$logistics['active_containers']): ?><p class="text-muted mb-0 mt-2">No active containers.</p><?php endif; ?></div></div>
            <div class="col-md-4 mb-3"><div class="stat-card"><small>Active Trips</small><?php foreach ($logistics['active_trips'] as $t): ?><div class="mt-2"><strong><?= h($t['trip_number']) ?></strong> <?= h($t['status']) ?><br><span class="text-muted"><?= h($t['container_number'] ?: '-') ?> · <?= h($t['driver_name'] ?: '-') ?> <?= h($t['truck_plate'] ?: '') ?></span></div><?php endforeach; if (!$logistics['active_trips']): ?><p class="text-muted mb-0 mt-2">No active trips.</p><?php endif; ?></div></div>
        </div>
        <div class="row mb-3">
            <div class="col-md-3 mb-3"><a class="quick-card" href="shipments.php"><i class="fas fa-boxes-stacked"></i> Shipments</a></div>
            <div class="col-md-3 mb-3"><a class="quick-card" href="containers.php"><i class="fas fa-truck-loading"></i> Containers</a></div>
            <div class="col-md-3 mb-3"><a class="quick-card" href="trips.php"><i class="fas fa-road"></i> Trips</a></div>
            <div class="col-md-3 mb-3"><a class="quick-card" href="stock_movements.php"><i class="fas fa-right-left"></i> Stock Movements</a></div>
        </div>
    <?php elseif ($current_role_type === 'finance_manager'): ?>
        <div class="row mb-3">
            <div class="col-md-2 col-sm-6 mb-3"><div class="stat-card"><small>Invoices</small><div class="num"><?= number_format($finance['invoice_count']) ?></div></div></div>
            <div class="col-md-2 col-sm-6 mb-3"><div class="stat-card"><small>Total Billed</small><div class="num">$<?= number_format($finance['total_invoiced'], 2) ?></div></div></div>
            <div class="col-md-2 col-sm-6 mb-3"><div class="stat-card"><small>Invoice Paid</small><div class="num">$<?= number_format($finance['total_paid'], 2) ?></div></div></div>
            <div class="col-md-2 col-sm-6 mb-3"><div class="stat-card"><small>Outstanding</small><div class="num">$<?= number_format($finance['outstanding'], 2) ?></div></div></div>
            <div class="col-md-2 col-sm-6 mb-3"><div class="stat-card"><small>Recorded Payments</small><div class="num">$<?= number_format($finance['receipts_total'], 2) ?></div></div></div>
            <div class="col-md-2 col-sm-6 mb-3"><div class="stat-card"><small>Trip Expenses</small><div class="num">$<?= number_format($finance['expenses_total'], 2) ?></div></div></div>
        </div>
        <div class="row mb-3">
            <div class="col-md-3 mb-3"><a class="quick-card" href="invoices.php"><i class="fas fa-file-invoice-dollar"></i> Invoices</a></div>
            <div class="col-md-3 mb-3"><a class="quick-card" href="payments.php"><i class="fas fa-credit-card"></i> Payments</a></div>
            <div class="col-md-3 mb-3"><a class="quick-card" href="customer_financial_history.php"><i class="fas fa-users"></i> Customers</a></div>
            <div class="col-md-3 mb-3"><a class="quick-card" href="expenses.php"><i class="fas fa-receipt"></i> Trip Expenses</a></div>
        </div>
        <p class="text-muted mb-3" style="font-size:13px;">Invoice Paid reflects amounts recorded on invoices. Recorded Payments reflects payment transactions currently available.</p>
        <div class="row">
            <div class="col-md-6 mb-3"><div class="stat-card"><small>Open Invoices / Outstanding Balances</small><?php foreach ($finance['open_invoices'] as $i): ?><div class="mt-2"><strong><?= h($i['invoice_number']) ?></strong> <?= h($i['customer_name'] ?: '-') ?><br><span class="text-muted">$<?= number_format((float)$i['total_amount'] - (float)$i['paid_amount'], 2) ?> outstanding · <?= h($i['status']) ?></span></div><?php endforeach; if (!$finance['open_invoices']): ?><p class="text-muted mb-0 mt-2">No open invoices.</p><?php endif; ?></div></div>
            <div class="col-md-6 mb-3"><div class="stat-card"><small>Recent Payments &amp; Receipts</small><?php foreach ($finance['recent_receipts'] as $r): ?><div class="mt-2"><strong><?= h($r['receipt_number']) ?></strong> <?= h($r['customer_name'] ?: '-') ?><br><span class="text-muted">$<?= number_format((float)$r['amount'], 2) ?> · <?= h($r['payment_date']) ?></span></div><?php endforeach; if (!$finance['recent_receipts']): ?><p class="text-muted mb-0 mt-2">No recent payments recorded.</p><?php endif; ?></div></div>
        </div>
    <?php else: ?>
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h4 class="mb-2"><?= h($role_title) ?> Workspace</h4>
                <p class="text-muted mb-0">Use the sidebar for the actions assigned to your role. Shipments remains the shared operational backbone.</p>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
