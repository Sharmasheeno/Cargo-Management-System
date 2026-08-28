<?php
// staff/warehouse_stock.php
// Branch-scoped cargo warehouse view for CUSTOMER SHIPMENTS.
// Shipment-linked cargo enters here only through the operational lifecycle:
// Reception -> Master Shipment -> Receive into Warehouse.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/shipment_functions.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    die('Database connection failed.');
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    } catch (Throwable $e) {
        error_log("ensureColumn failed for {$table}.{$column}: " . $e->getMessage());
    }
}

ensureShipmentSchema($pdo);
ensureColumn($pdo, 'warehouse_stock', 'branch_id', 'INT(11) DEFAULT NULL');
ensureColumn($pdo, 'warehouse_stock', 'location', 'VARCHAR(255) DEFAULT NULL');
ensureColumn($pdo, 'warehouse_stock', 'bin_location', 'VARCHAR(100) DEFAULT NULL');
ensureColumn($pdo, 'warehouse_stock', 'zone', 'VARCHAR(50) DEFAULT NULL');
ensureColumn($pdo, 'warehouse_stock', 'shipment_id', 'INT DEFAULT NULL');
ensureColumn($pdo, 'warehouse_stock', 'updated_by', 'INT(11) DEFAULT NULL');
ensureColumn($pdo, 'warehouse_stock', 'last_updated', 'DATETIME DEFAULT NULL');
ensureColumn($pdo, 'shipments', 'quantity_unit', 'VARCHAR(30) DEFAULT NULL');

$staff_role_types = staffFamilyRoleTypes();
$current_role_type = $_SESSION['role_type'] ?? $_SESSION['role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($current_role_type, $staff_role_types, true)) {
    header("Location: ../login.php");
    exit;
}
if (!in_array($current_role_type, staffWarehouseRoleTypes(), true)) {
    $_SESSION['flash_message'] = 'You do not have permission to access Warehouse Stock.';
    $_SESSION['flash_type'] = 'error';
    header("Location: ../staff/dashboard.php?error=access_denied");
    exit;
}

$tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];
if ($tenant_id <= 0) {
    header("Location: ../login.php?error=no_tenant");
    exit;
}

$assigned_branch_id = (int)($_SESSION['assigned_branch_id'] ?? 0);
if ($assigned_branch_id <= 0) {
    try {
        $stmt = $pdo->prepare("SELECT branch_id FROM user_branch_assignments WHERE user_id = ? AND is_primary = 1 LIMIT 1");
        $stmt->execute([$user_id]);
        $assigned_branch_id = (int)$stmt->fetchColumn();
        if ($assigned_branch_id > 0) $_SESSION['assigned_branch_id'] = $assigned_branch_id;
    } catch (Throwable $e) {}
}
if ($assigned_branch_id <= 0) {
    try {
        $stmt = $pdo->prepare("SELECT default_branch_id FROM users WHERE id = ? AND tenant_id = ? LIMIT 1");
        $stmt->execute([$user_id, $tenant_id]);
        $assigned_branch_id = (int)$stmt->fetchColumn();
        if ($assigned_branch_id > 0) $_SESSION['assigned_branch_id'] = $assigned_branch_id;
    } catch (Throwable $e) {}
}
if ($assigned_branch_id <= 0) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container-fluid"><div class="alert alert-danger m-4">You are not assigned to any branch. Please contact your administrator.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$branch_name = 'My Branch';
try {
    $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1");
    $stmt->execute([$assigned_branch_id, $tenant_id]);
    $branch_name = (string)($stmt->fetchColumn() ?: $branch_name);
} catch (Throwable $e) {}

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function jsonResponse(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function postString(string $key, string $default = ''): string {
    $value = $_POST[$key] ?? $default;
    return is_array($value) ? $default : trim((string)$value);
}

function cargoQuantityUnit(array $row): string {
    $unit = trim((string)($row['quantity_unit'] ?? ''));
    if ($unit === '' && !empty($row['package_notes']) && preg_match('/Quantity:\s*\d+\s+([A-Za-z]+)/i', (string)$row['package_notes'], $m)) {
        $unit = $m[1];
    }
    if ($unit === '') $unit = 'Units';
    return ucwords(str_replace('_', ' ', $unit));
}

function cargoStatusLabel(string $status, int $availableQty, int $branchId, ?int $currentBranchId): string {
    if ($availableQty <= 0) {
        if (in_array($status, ['LOADED', 'DISPATCHED', 'IN_TRANSIT', 'ARRIVED_AT_DESTINATION'], true)) return 'Transferred Out';
        if (in_array($status, ['READY_FOR_PICKUP', 'OUT_FOR_DELIVERY', 'DELIVERED', 'CLOSED'], true)) return 'Released / Delivered';
        return 'Moved Out';
    }
    $labels = shipment_status_labels();
    return $labels[$status] ?? ucwords(strtolower(str_replace('_', ' ', $status)));
}

function cargoStatusColor(string $status, int $availableQty): string {
    if ($availableQty <= 0) return '#6B7280';
    $map = [
        'IN_ORIGIN_WAREHOUSE' => '#0F7A3A',
        'IN_DESTINATION_WAREHOUSE' => '#0F7A3A',
        'READY_FOR_LOADING' => '#B7791F',
        'READY_FOR_PICKUP' => '#2563EB',
        'OUT_FOR_DELIVERY' => '#7C3AED',
        'DELIVERED' => '#111827',
    ];
    return $map[$status] ?? '#4B5563';
}

function warehouseCargoWhere(string $view, string $search, array &$params, int $tenantId, int $branchId): string {
    $where = [
        'ws.tenant_id = ?',
        'ws.branch_id = ?',
        'ws.shipment_id IS NOT NULL',
        's.id IS NOT NULL',
    ];
    $params = [$tenantId, $branchId];

    if ($view === 'active') {
        $where[] = 'ws.quantity > 0';
        $where[] = 'COALESCE(ws.is_active,1) = 1';
    } elseif ($view === 'moved_out') {
        $where[] = '(ws.quantity <= 0 OR COALESCE(ws.is_active,1) = 0)';
    }

    if ($search !== '') {
        $like = "%{$search}%";
        $where[] = '(s.shipment_number LIKE ? OR s.tracking_number LIKE ? OR s.cargo_description LIKE ? OR c.customer_name LIKE ? OR ws.zone LIKE ? OR ws.bin_location LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    return 'WHERE ' . implode(' AND ', $where);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    $action = postString('ajax_action');

    if ($action === 'get_cargo') {
        $page = max(1, (int)($_POST['page'] ?? 1));
        $limit = 15;
        $offset = ($page - 1) * $limit;
        $search = postString('search');
        $view = postString('view', 'active');
        if (!in_array($view, ['active', 'moved_out', 'all'], true)) $view = 'active';

        $params = [];
        $whereSql = warehouseCargoWhere($view, $search, $params, $tenant_id, $assigned_branch_id);

        $count = $pdo->prepare("
            SELECT COUNT(*)
            FROM warehouse_stock ws
            INNER JOIN shipments s ON s.id = ws.shipment_id AND s.tenant_id = ws.tenant_id
            LEFT JOIN customers c ON c.id = s.customer_id AND c.tenant_id = s.tenant_id
            LEFT JOIN packages p ON p.id = s.source_package_id AND p.tenant_id = s.tenant_id
            {$whereSql}
        ");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / $limit));
        if ($page > $pages) {
            $page = $pages;
            $offset = ($page - 1) * $limit;
        }

        $stmt = $pdo->prepare("
            SELECT ws.*, s.shipment_number, s.tracking_number, s.cargo_description,
                   s.quantity AS shipment_quantity, s.quantity_unit, s.volume_cbm AS shipment_volume_cbm,
                   s.current_status, s.current_branch_id, s.origin_branch_id, s.destination_branch_id,
                   c.customer_name, c.phone AS customer_phone, p.notes AS package_notes
            FROM warehouse_stock ws
            INNER JOIN shipments s ON s.id = ws.shipment_id AND s.tenant_id = ws.tenant_id
            LEFT JOIN customers c ON c.id = s.customer_id AND c.tenant_id = s.tenant_id
            LEFT JOIN packages p ON p.id = s.source_package_id AND p.tenant_id = s.tenant_id
            {$whereSql}
            ORDER BY
                CASE WHEN ws.quantity > 0 AND COALESCE(ws.is_active,1) = 1 THEN 0 ELSE 1 END,
                ws.last_updated DESC, ws.created_at DESC, ws.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ob_start();
        ?>
        <div class="table-responsive">
            <table class="table table-bordered table-hover cargo-table">
                <thead>
                    <tr>
                        <th>Shipment</th>
                        <th>Tracking</th>
                        <th>Cargo</th>
                        <th>Qty</th>
                        <th>Volume</th>
                        <th>Zone / Location</th>
                        <th>Status</th>
                        <th>Customer</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $row):
                    $qty = (int)$row['quantity'];
                    $unit = cargoQuantityUnit($row);
                    $statusLabel = cargoStatusLabel((string)$row['current_status'], $qty, $assigned_branch_id, isset($row['current_branch_id']) ? (int)$row['current_branch_id'] : null);
                    $statusColor = cargoStatusColor((string)$row['current_status'], $qty);
                    $zone = trim((string)($row['zone'] ?? ''));
                    $storage = trim((string)($row['bin_location'] ?? ''));
                    $physicallyHere = $qty > 0 && (int)($row['is_active'] ?? 1) === 1;
                ?>
                    <tr>
                        <td><strong><?= h($row['shipment_number']) ?></strong></td>
                        <td><?= h($row['tracking_number']) ?></td>
                        <td><?= h($row['cargo_description'] ?: $row['stock_name']) ?></td>
                        <td><strong><?= number_format($qty) ?> <?= h($unit) ?></strong></td>
                        <td><?= number_format((float)($row['shipment_volume_cbm'] ?? $row['volume_cbm']), 3) ?> CBM</td>
                        <td><?= h($zone !== '' ? $zone : '-') ?> / <?= h($storage !== '' ? $storage : '-') ?></td>
                        <td><span class="cargo-status" style="background:<?= h($statusColor) ?>22;color:<?= h($statusColor) ?>;"><?= h($statusLabel) ?></span></td>
                        <td><?= h($row['customer_name'] ?: '-') ?><?php if (!empty($row['customer_phone'])): ?><br><small class="text-muted"><?= h($row['customer_phone']) ?></small><?php endif; ?></td>
                        <td class="text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-primary view-cargo" data-id="<?= (int)$row['id'] ?>"><i class="fas fa-eye"></i></button>
                            <?php if ($physicallyHere): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary move-cargo"
                                        data-id="<?= (int)$row['id'] ?>"
                                        data-name="<?= h($row['shipment_number']) ?>"
                                        data-zone="<?= h($zone) ?>"
                                        data-location="<?= h($storage) ?>">
                                    <i class="fas fa-location-dot"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="9" class="text-center py-5">
                        <i class="fas fa-warehouse fa-3x text-muted mb-3"></i>
                        <p class="mb-1">No shipment cargo found for this view.</p>
                        <small class="text-muted">Customer cargo appears here after a shipment is received into this branch warehouse.</small>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        $table = ob_get_clean();

        ob_start();
        if ($pages > 1): ?>
            <div class="pagination justify-content-center mt-3">
                <?php for ($i = 1; $i <= $pages; $i++): ?>
                    <button type="button" class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-outline-secondary' ?> page-link-btn" data-page="<?= $i ?>"><?= $i ?></button>
                <?php endfor; ?>
            </div>
        <?php endif;
        $pagination = ob_get_clean();

        jsonResponse(['success' => true, 'table_html' => $table, 'pagination_html' => $pagination, 'total' => $total, 'page' => $page, 'pages' => $pages]);
    }

    if ($action === 'get_cargo_item') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT ws.*, s.shipment_number, s.tracking_number, s.cargo_description,
                   s.quantity AS shipment_quantity, s.quantity_unit, s.volume_cbm AS shipment_volume_cbm,
                   s.current_status, c.customer_name, c.phone AS customer_phone, p.notes AS package_notes
            FROM warehouse_stock ws
            INNER JOIN shipments s ON s.id = ws.shipment_id AND s.tenant_id = ws.tenant_id
            LEFT JOIN customers c ON c.id = s.customer_id AND c.tenant_id = s.tenant_id
            LEFT JOIN packages p ON p.id = s.source_package_id AND p.tenant_id = s.tenant_id
            WHERE ws.id = ? AND ws.tenant_id = ? AND ws.branch_id = ? AND ws.shipment_id IS NOT NULL
            LIMIT 1
        ");
        $stmt->execute([$id, $tenant_id, $assigned_branch_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonResponse(['success' => false, 'message' => 'Cargo was not found in your branch warehouse.']);
        $row['quantity_label'] = number_format((int)$row['quantity']) . ' ' . cargoQuantityUnit($row);
        $row['status_label'] = cargoStatusLabel((string)$row['current_status'], (int)$row['quantity'], $assigned_branch_id, null);
        jsonResponse(['success' => true, 'cargo' => $row]);
    }

    if ($action === 'move_cargo') {
        $id = (int)($_POST['id'] ?? 0);
        $zone = postString('zone');
        $storage = postString('storage_location');
        if ($zone === '' || $storage === '') {
            jsonResponse(['success' => false, 'message' => 'Zone and storage location are required.']);
        }

        $stmt = $pdo->prepare("
            SELECT ws.*, s.shipment_number, s.current_status
            FROM warehouse_stock ws
            INNER JOIN shipments s ON s.id = ws.shipment_id AND s.tenant_id = ws.tenant_id
            WHERE ws.id = ? AND ws.tenant_id = ? AND ws.branch_id = ? AND ws.shipment_id IS NOT NULL
            LIMIT 1
        ");
        $stmt->execute([$id, $tenant_id, $assigned_branch_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonResponse(['success' => false, 'message' => 'Cargo was not found in your branch warehouse.']);
        if ((int)$row['quantity'] <= 0 || (int)($row['is_active'] ?? 1) !== 1) {
            jsonResponse(['success' => false, 'message' => 'Moved-out historical cargo cannot be relocated.']);
        }

        $oldZone = trim((string)($row['zone'] ?? ''));
        $oldStorage = trim((string)($row['bin_location'] ?? ''));
        $previous = trim(($oldZone !== '' ? $oldZone : '-') . ' / ' . ($oldStorage !== '' ? $oldStorage : '-'));
        $next = "{$zone} / {$storage}";

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("UPDATE warehouse_stock SET zone = ?, bin_location = ?, location = 'Warehouse', updated_by = ?, last_updated = NOW() WHERE id = ? AND tenant_id = ? AND branch_id = ?");
            $upd->execute([$zone, $storage, $user_id, $id, $tenant_id, $assigned_branch_id]);

            record_stock_movement([
                'tenant_id' => $tenant_id,
                'warehouse_stock_id' => $id,
                'quantity_change' => 0,
                'previous_quantity' => (int)$row['quantity'],
                'new_quantity' => (int)$row['quantity'],
                'movement_type' => 'move',
                'movement_event' => 'location_move',
                'from_location' => $branch_name . ' Warehouse · ' . $previous,
                'to_location' => $branch_name . ' Warehouse · ' . $next,
                'reference_type' => 'shipment',
                'reference_id' => (int)$row['shipment_id'],
                'reference_label' => $row['shipment_number'],
                'notes' => "STORE: {$row['shipment_number']} moved from {$previous} to {$next}",
                'created_by' => $user_id,
            ]);

            $pdo->prepare("UPDATE shipments SET storage_zone = ?, storage_rack = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?")
                ->execute([$zone, $storage, (int)$row['shipment_id'], $tenant_id]);

            log_shipment_event([
                'tenant_id' => $tenant_id,
                'shipment_id' => (int)$row['shipment_id'],
                'event_type' => 'WAREHOUSE_LOCATION_UPDATED',
                'new_status' => $row['current_status'],
                'branch_id' => $assigned_branch_id,
                'warehouse_stock_id' => $id,
                'location_label' => "{$branch_name} Warehouse / Zone {$zone} / {$storage}",
                'performed_by' => $user_id,
                'performer_name' => $_SESSION['user_name'] ?? null,
                'notes' => "Storage location updated from {$previous} to {$next}.",
            ]);

            $pdo->commit();
            jsonResponse(['success' => true, 'message' => "Storage location updated to {$next}."]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Could not update storage location: ' . $e->getMessage()]);
        }
    }

    if (in_array($action, ['save_stock_item', 'delete_stock_item', 'adjust_stock'], true)) {
        jsonResponse(['success' => false, 'message' => 'Manual retail stock actions are disabled for shipment cargo. Use Reception -> Shipment -> Receive into Warehouse.']);
    }

    jsonResponse(['success' => false, 'message' => 'Unknown action.']);
}

$activeParams = [];
$activeWhere = warehouseCargoWhere('active', '', $activeParams, $tenant_id, $assigned_branch_id);
$activeStats = ['shipments_in_warehouse' => 0, 'total_units' => 0, 'total_volume' => 0, 'ready_for_loading' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT ws.shipment_id) AS shipments_in_warehouse,
               COALESCE(SUM(ws.quantity),0) AS total_units,
               COALESCE(SUM(s.volume_cbm),0) AS total_volume,
               COUNT(DISTINCT CASE WHEN s.current_status = 'READY_FOR_LOADING' THEN ws.shipment_id END) AS ready_for_loading
        FROM warehouse_stock ws
        INNER JOIN shipments s ON s.id = ws.shipment_id AND s.tenant_id = ws.tenant_id
        LEFT JOIN customers c ON c.id = s.customer_id AND c.tenant_id = s.tenant_id
        LEFT JOIN packages p ON p.id = s.source_package_id AND p.tenant_id = s.tenant_id
        {$activeWhere}
    ");
    $stmt->execute($activeParams);
    $activeStats = array_merge($activeStats, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {}

$incomingCount = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT s.id)
        FROM shipments s
        WHERE s.tenant_id = ? AND s.destination_branch_id = ?
          AND s.current_status IN ('ARRIVED_AT_DESTINATION','PARTIALLY_RECEIVED')
    ");
    $stmt->execute([$tenant_id, $assigned_branch_id]);
    $incomingCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid warehouse-cargo-page" style="padding: 20px;">
<style>
    .warehouse-cargo-page .page-header { background: linear-gradient(135deg, #2D1859, #4B2C85); color: #fff; border-radius: 16px; padding: 20px 24px; margin-bottom: 22px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
    .warehouse-cargo-page .page-header h1 { font-size: 22px; margin: 0; font-weight: 700; }
    .warehouse-cargo-page .branch-pill { background: rgba(255,255,255,.16); border-radius: 999px; padding: 8px 14px; font-size: 13px; }
    .warehouse-cargo-page .stat-card { background:#fff; border:1px solid #E9E7F1; border-radius:14px; padding:18px; min-height:100px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 4px 16px rgba(45,24,89,.06); }
    .warehouse-cargo-page .stat-card h4 { color:#6B7280; font-size:12px; text-transform:uppercase; letter-spacing:.04em; margin:0 0 8px; }
    .warehouse-cargo-page .stat-number { color:#2D1859; font-size:26px; font-weight:800; }
    .warehouse-cargo-page .stat-icon { width:44px; height:44px; border-radius:12px; background:#F5C41022; color:#2D1859; display:flex; align-items:center; justify-content:center; font-size:20px; }
    .warehouse-cargo-page .filters-card, .warehouse-cargo-page .table-card { background:#fff; border:1px solid #E9E7F1; border-radius:14px; padding:16px; margin-bottom:18px; }
    .warehouse-cargo-page .filter-grid { display:grid; grid-template-columns: minmax(220px, 1fr) 180px auto auto; gap:12px; align-items:end; }
    .warehouse-cargo-page label { font-size:12px; font-weight:700; color:#4B5563; margin-bottom:5px; }
    .warehouse-cargo-page .cargo-table th { background:#F8F6F9; color:#374151; font-size:12px; text-transform:uppercase; white-space:nowrap; }
    .warehouse-cargo-page .cargo-table td { vertical-align:middle; font-size:13px; }
    .warehouse-cargo-page .cargo-status { display:inline-block; padding:5px 10px; border-radius:999px; font-weight:700; font-size:12px; }
    .warehouse-cargo-page .page-link-btn { margin:0 3px; min-width:34px; }
    @media (max-width: 900px) { .warehouse-cargo-page .filter-grid { grid-template-columns: 1fr; } }
</style>

<div class="page-header">
    <h1><i class="fas fa-warehouse"></i> Warehouse Cargo</h1>
    <span class="branch-pill"><i class="fas fa-location-dot"></i> <?= h($branch_name) ?></span>
</div>

<div id="alert-placeholder"></div>

<div class="row mb-3">
    <div class="col-md-3 mb-3"><div class="stat-card"><div><h4>Shipments In Warehouse</h4><div class="stat-number"><?= number_format((int)$activeStats['shipments_in_warehouse']) ?></div></div><div class="stat-icon"><i class="fas fa-boxes-stacked"></i></div></div></div>
    <div class="col-md-3 mb-3"><div class="stat-card"><div><h4>Total Units</h4><div class="stat-number"><?= number_format((int)$activeStats['total_units']) ?></div></div><div class="stat-icon"><i class="fas fa-cubes"></i></div></div></div>
    <div class="col-md-3 mb-3"><div class="stat-card"><div><h4>Total Volume (CBM)</h4><div class="stat-number"><?= number_format((float)$activeStats['total_volume'], 2) ?></div></div><div class="stat-icon"><i class="fas fa-ruler-combined"></i></div></div></div>
    <div class="col-md-3 mb-3"><div class="stat-card"><div><h4>Ready / Incoming</h4><div class="stat-number"><?= number_format((int)$activeStats['ready_for_loading']) ?> / <?= number_format($incomingCount) ?></div></div><div class="stat-icon"><i class="fas fa-truck-ramp-box"></i></div></div></div>
</div>

<div class="filters-card">
    <div class="filter-grid">
        <div><label>Search</label><input type="text" id="searchInput" class="form-control" placeholder="Shipment, tracking, cargo, customer, zone..."></div>
        <div><label>View</label><select id="viewFilter" class="form-control"><option value="active">Active Cargo</option><option value="moved_out">Moved Out</option><option value="all">All History</option></select></div>
        <button type="button" class="btn btn-primary" id="applyFilters"><i class="fas fa-filter"></i> Apply</button>
        <button type="button" class="btn btn-outline-secondary" id="resetFilters"><i class="fas fa-undo"></i> Reset</button>
    </div>
</div>

<div class="table-card">
    <div id="cargo-table-container"><div class="text-center p-5"><i class="fas fa-spinner fa-spin"></i> Loading cargo...</div></div>
    <div id="pagination-container"></div>
</div>

<div class="modal fade" id="moveModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="moveForm">
      <div class="modal-header"><h5 class="modal-title">Update Storage Location</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
      <div class="modal-body">
        <input type="hidden" id="moveCargoId">
        <p class="mb-2">Shipment: <strong id="moveCargoName"></strong></p>
        <div class="form-group"><label>Zone</label><input type="text" id="moveZone" class="form-control" required placeholder="e.g. B, Back, Left Side"></div>
        <div class="form-group"><label>Storage Location</label><input type="text" id="moveStorageLocation" class="form-control" required placeholder="e.g. B-05, Corner 1, Near Table 2"><small class="text-muted">Internal physical location inside this warehouse.</small></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button class="btn btn-primary">Save Location</button></div>
    </form>
  </div>
</div>

<div class="modal fade" id="viewModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Cargo Details</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
      <div class="modal-body" id="viewModalBody"></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(function() {
    let currentPage = 1;
    let searchTimer = null;

    function esc(text) {
        if (text === null || text === undefined) return '';
        return String(text).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }

    function showAlert(type, msg) {
        const cls = type === 'success' ? 'alert-success' : 'alert-danger';
        $('#alert-placeholder').html('<div class="alert ' + cls + ' alert-dismissible fade show" style="position:fixed;top:20px;right:20px;z-index:9999;min-width:320px;border-radius:12px;"><i class="fas fa-info-circle"></i> ' + esc(msg) + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
        setTimeout(() => $('.alert').fadeOut(400, function(){ $(this).remove(); }), 4500);
    }

    function loadCargo(page = 1) {
        currentPage = page;
        $.post(window.location.href, {
            ajax_action: 'get_cargo',
            page: currentPage,
            search: $('#searchInput').val(),
            view: $('#viewFilter').val()
        }, function(res) {
            if (!res.success) { showAlert('error', res.message || 'Failed to load cargo.'); return; }
            $('#cargo-table-container').html(res.table_html);
            $('#pagination-container').html(res.pagination_html);
        }, 'json').fail(function() {
            $('#cargo-table-container').html('<div class="text-center p-5 text-danger"><i class="fas fa-exclamation-triangle"></i><p>Failed to load warehouse cargo.</p></div>');
        });
    }

    $(document).on('click', '.page-link-btn', function() { loadCargo(Number($(this).data('page')) || 1); });
    $('#applyFilters').on('click', function() { loadCargo(1); });
    $('#resetFilters').on('click', function() { $('#searchInput').val(''); $('#viewFilter').val('active'); loadCargo(1); });
    $('#viewFilter').on('change', function() { loadCargo(1); });
    $('#searchInput').on('keyup', function(e) {
        if (e.key === 'Enter') { loadCargo(1); return; }
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => loadCargo(1), 350);
    });

    $(document).on('click', '.move-cargo', function() {
        $('#moveCargoId').val($(this).data('id'));
        $('#moveCargoName').text($(this).data('name'));
        $('#moveZone').val($(this).data('zone') || '');
        $('#moveStorageLocation').val($(this).data('location') || '');
        $('#moveModal').modal('show');
    });

    $('#moveForm').on('submit', function(e) {
        e.preventDefault();
        $.post(window.location.href, {
            ajax_action: 'move_cargo',
            id: $('#moveCargoId').val(),
            zone: $('#moveZone').val(),
            storage_location: $('#moveStorageLocation').val()
        }, function(res) {
            showAlert(res.success ? 'success' : 'error', res.message || 'Done.');
            if (res.success) { $('#moveModal').modal('hide'); loadCargo(currentPage); }
        }, 'json').fail(function() { showAlert('error', 'Server error while updating location.'); });
    });

    $(document).on('click', '.view-cargo', function() {
        $.post(window.location.href, { ajax_action: 'get_cargo_item', id: $(this).data('id') }, function(res) {
            if (!res.success) { showAlert('error', res.message || 'Cargo not found.'); return; }
            const c = res.cargo;
            $('#viewModalBody').html(
                '<div class="row">' +
                '<div class="col-md-4 font-weight-bold">Shipment</div><div class="col-md-8">' + esc(c.shipment_number) + '</div>' +
                '<div class="col-md-4 font-weight-bold">Tracking</div><div class="col-md-8">' + esc(c.tracking_number) + '</div>' +
                '<div class="col-md-4 font-weight-bold">Cargo</div><div class="col-md-8">' + esc(c.cargo_description || c.stock_name) + '</div>' +
                '<div class="col-md-4 font-weight-bold">Quantity</div><div class="col-md-8">' + esc(c.quantity_label) + '</div>' +
                '<div class="col-md-4 font-weight-bold">Volume</div><div class="col-md-8">' + Number(c.shipment_volume_cbm || c.volume_cbm || 0).toFixed(3) + ' CBM</div>' +
                '<div class="col-md-4 font-weight-bold">Zone / Location</div><div class="col-md-8">' + esc(c.zone || '-') + ' / ' + esc(c.bin_location || '-') + '</div>' +
                '<div class="col-md-4 font-weight-bold">Status</div><div class="col-md-8">' + esc(c.status_label) + '</div>' +
                '<div class="col-md-4 font-weight-bold">Customer</div><div class="col-md-8">' + esc(c.customer_name || '-') + (c.customer_phone ? ' (' + esc(c.customer_phone) + ')' : '') + '</div>' +
                '</div>'
            );
            $('#viewModal').modal('show');
        }, 'json');
    });

    loadCargo();
});
</script>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
