<?php
// staff/stock_movements.php
// Read-only audit log of stock_movements for the staff member's assigned branch.
// Movements are created as a side effect of actions on staff/warehouse_stock.php
// (receiving, adjusting, moving, and deleting warehouse stock) — this page just
// lists them with simple filters, it does not let staff create movements directly.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// See staffFamilyRoleTypes() in includes/functions.php.
require_once __DIR__ . '/../includes/functions.php';
$staff_role_types = staffFamilyRoleTypes();
$current_role_type = $_SESSION['role_type'] ?? $_SESSION['role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($current_role_type, $staff_role_types, true)) {
    header("Location: ../login.php");
    exit;
}
$stock_movement_viewer_roles = array_values(array_unique(array_merge(staffWarehouseRoleTypes(), staffLogisticsRoleTypes())));
if (!in_array($current_role_type, $stock_movement_viewer_roles, true)) {
    $_SESSION['flash_message'] = 'You do not have permission to access Stock Movements.';
    $_SESSION['flash_type'] = 'error';
    header("Location: ../staff/dashboard.php?error=access_denied");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/shipment_functions.php';
ensureStockMovementSemanticColumns($pdo);
try {
    // One safe semantic backfill for legacy rows. The raw movement_type enum is
    // only in/out/move/adjust, so the audit page filters by this canonical
    // physical event column instead of silently widening to "all".
    $pdo->exec("
        UPDATE stock_movements
        SET movement_event = CASE
            WHEN movement_type = 'in' AND (reference_type = 'trip_unload' OR notes LIKE '%Received from trip%' OR notes LIKE '%UNLOAD:%') THEN 'unloaded_destination'
            WHEN movement_type = 'in' THEN 'received_stored'
            WHEN movement_type = 'out' AND notes LIKE '%LOAD:%' THEN 'loaded_to_container'
            WHEN movement_type = 'out' AND notes LIKE '%courier%' THEN 'released_courier'
            WHEN movement_type = 'out' AND notes LIKE '%RELEASE:%' THEN 'released_pickup'
            WHEN movement_type = 'move' AND (reference_type = 'trip_unload' OR notes LIKE '%UNLOAD:%') THEN 'unloaded_destination'
            WHEN movement_type = 'move' THEN 'location_move'
            ELSE movement_type
        END
        WHERE movement_event IS NULL OR movement_event = ''
    ");
} catch (Throwable $e) {}

$user_id = (int)$_SESSION['user_id'];
$tenant_id = (int)($_SESSION['tenant_id'] ?? 0);

if ($tenant_id <= 0) {
    header("Location: ../login.php?error=no_tenant");
    exit;
}

// ── Resolve the staff member's assigned branch ──────────────────────────────
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
            $_SESSION['can_manage_branch'] = $branchAssign['can_manage_branch'];
        }
    } catch (PDOException $e) {}
}

if (!$assigned_branch_id) {
    try {
        $stmt = $pdo->prepare("SELECT default_branch_id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $userBranch = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($userBranch && $userBranch['default_branch_id']) {
            $assigned_branch_id = $userBranch['default_branch_id'];
            $_SESSION['assigned_branch_id'] = $assigned_branch_id;
        }
    } catch (PDOException $e) {}
}

if (!$assigned_branch_id) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container-fluid"><div class="alert alert-danger m-4">You are not assigned to any branch. Please contact your administrator.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Branch display name
$branch_name = 'My Branch';
try {
    $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$assigned_branch_id, $tenant_id]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($b) $branch_name = $b['branch_name'];
} catch (PDOException $e) {}

$movement_labels = [
    'received_stored' => 'Received / Stored',
    'loaded_to_container' => 'Loaded to Container',
    'unloaded_destination' => 'Unloaded at Destination',
    'released_pickup' => 'Released for Pickup',
    'released_courier' => 'Released to Courier',
    'delivered_final_release' => 'Delivered / Final Release',
    'location_move' => 'Location Move',
];
$movement_colors = [
    'received_stored' => '#10B981',
    'loaded_to_container' => '#DC2626',
    'unloaded_destination' => '#3B82F6',
    'released_pickup' => '#DC2626',
    'released_courier' => '#7C3AED',
    'delivered_final_release' => '#6B7280',
    'location_move' => '#F59E0B',
];

// ── Filters (GET, simple page reload — no AJAX needed for a log view) ──────
$legacy_filter_map = [
    'in' => 'received_stored',
    'out' => 'loaded_to_container',
    'move' => 'location_move',
    'adjust' => '',
];
$movement_event = trim((string)($_GET['movement_event'] ?? ($_GET['movement_type'] ?? '')));
$movement_event = $legacy_filter_map[$movement_event] ?? $movement_event;
if (!array_key_exists($movement_event, $movement_labels)) $movement_event = '';
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$where = ["sm.tenant_id = ?", "ws.branch_id = ?"];
$params = [$tenant_id, $assigned_branch_id];
if ($movement_event !== '') {
    $where[] = "sm.movement_event = ?";
    $params[] = $movement_event;
}
if ($date_from !== '') {
    $where[] = "sm.created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
}
if ($date_to !== '') {
    $where[] = "sm.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
}
if ($search !== '') {
    $where[] = "(ws.stock_name LIKE ? OR sm.notes LIKE ? OR sm.reference_type LIKE ? OR sm.reference_label LIKE ? OR s.shipment_number LIKE ? OR s.tracking_number LIKE ? OR s.cargo_description LIKE ? OR s.sender_name LIKE ? OR s.receiver_name LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

$total = 0;
$movements = [];
try {
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM stock_movements sm
        INNER JOIN warehouse_stock ws ON sm.warehouse_stock_id = ws.id
        LEFT JOIN shipments s ON s.id = ws.shipment_id AND s.tenant_id = ws.tenant_id
        LEFT JOIN packages p ON p.id = s.source_package_id AND p.tenant_id = s.tenant_id
        $where_clause
    ");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = max(1, (int)ceil($total / $limit));
    if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $limit; }

    $stmt = $pdo->prepare("
        SELECT sm.*, ws.stock_name, ws.origin AS stock_origin, ws.location AS stock_location,
               ws.zone, ws.bin_location, ws.branch_id, s.shipment_number, s.tracking_number,
               s.cargo_description, s.sender_name, s.receiver_name, s.quantity_unit,
               sm.movement_event AS canonical_event,
               p.notes AS package_notes, u.full_name AS created_by_name
        FROM stock_movements sm
        INNER JOIN warehouse_stock ws ON sm.warehouse_stock_id = ws.id
        LEFT JOIN shipments s ON s.id = ws.shipment_id AND s.tenant_id = ws.tenant_id
        LEFT JOIN packages p ON p.id = s.source_package_id AND p.tenant_id = s.tenant_id
        LEFT JOIN users u ON sm.created_by = u.id
        $where_clause
        ORDER BY sm.created_at DESC, sm.id DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $total_pages = 1;
}

$total_pages = $total_pages ?? 1;

// Preserve current filters in pagination links
function buildQuery(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    unset($params['movement_type']);
    if (isset($GLOBALS['movement_event']) && $GLOBALS['movement_event'] !== '') {
        $params['movement_event'] = $GLOBALS['movement_event'];
    }
    return htmlspecialchars('?' . http_build_query($params), ENT_QUOTES, 'UTF-8');
}

function movementUnit(array $row): string {
    $unit = trim((string)($row['quantity_unit'] ?? ''));
    if ($unit === '' && !empty($row['package_notes']) && preg_match('/Quantity:\s*\d+\s+([A-Za-z]+)/i', (string)$row['package_notes'], $m)) {
        $unit = $m[1];
    }
    return $unit !== '' ? ucwords(str_replace('_', ' ', $unit)) : 'Units';
}

function movementDisplay(array $row, string $branchName): array {
    $type = (string)($row['movement_type'] ?? '');
    $event = (string)($row['canonical_event'] ?? $row['movement_event'] ?? '');
    $notes = (string)($row['notes'] ?? '');
    $zoneLoc = trim((string)($row['zone'] ?? '')) !== '' || trim((string)($row['bin_location'] ?? '')) !== ''
        ? trim((string)($row['zone'] ?: '-') . ' / ' . (string)($row['bin_location'] ?: '-'))
        : '';
    $warehouse = $branchName . ' Warehouse' . ($zoneLoc !== '' ? ' · ' . $zoneLoc : '');
    $label = $GLOBALS['movement_labels'][$event] ?? ucwords(str_replace('_', ' ', ($event ?: $type)));
    $from = trim((string)($row['from_location'] ?? ''));
    $to = trim((string)($row['to_location'] ?? ''));
    if ($from !== '' || $to !== '') {
        return ['event' => $event, 'label' => $label, 'from' => $from !== '' ? $from : '-', 'to' => $to !== '' ? $to : '-'];
    }
    $from = '-';
    $to = '-';

    if ($event === 'received_stored') {
        $label = 'RECEIVED / STORED';
        $from = 'Reception';
        $to = $warehouse;
    } elseif ($event === 'loaded_to_container') {
        $label = 'LOADED TO CONTAINER';
        $from = $warehouse;
        $to = extractContainerOrTrip($notes);
    } elseif ($event === 'unloaded_destination') {
        $label = 'UNLOADED AT DESTINATION';
        $from = extractContainerOrTrip($notes);
        $to = $warehouse;
    } elseif ($event === 'released_pickup') {
        $label = 'RELEASED FOR PICKUP';
        $from = $warehouse;
        $to = trim(preg_replace('/^.*receiver\s+/i', '', $notes)) ?: 'Receiver';
    } elseif ($event === 'released_courier') {
        $label = 'RELEASED TO COURIER';
        $from = $warehouse;
        $to = trim((string)($row['reference_label'] ?? 'Courier')) ?: 'Courier';
    } elseif ($event === 'location_move') {
        $label = 'LOCATION MOVED';
        if (preg_match('/moved from (.+?) to (.+)$/i', $notes, $m)) {
            $from = $branchName . ' Warehouse · ' . trim($m[1]);
            $to = $branchName . ' Warehouse · ' . trim($m[2]);
        } else {
            $to = $warehouse;
        }
    } elseif ($type === 'in') {
        if (stripos($notes, 'Received from trip') !== false || stripos($notes, 'UNLOAD') !== false) {
            $label = 'UNLOADED AT DESTINATION';
            $from = extractContainerOrTrip($notes);
            $to = $warehouse;
        } else {
            $label = 'RECEIVED / STORED';
            $from = 'Reception';
            $to = $warehouse;
        }
    } elseif ($type === 'out') {
        if (preg_match('/LOAD:.*Container\s+([A-Za-z0-9-]+)/i', $notes, $m)) {
            $label = 'LOADED TO CONTAINER';
            $from = $warehouse;
            $to = $m[1];
        } elseif (stripos($notes, 'RELEASE:') !== false) {
            $label = stripos($notes, 'courier') !== false ? 'RELEASED TO COURIER' : 'RELEASED FOR PICKUP';
            $from = $warehouse;
            $to = trim(preg_replace('/^.*receiver\s+/i', '', $notes)) ?: 'Receiver';
        }
    } elseif ($type === 'move') {
        if (stripos($notes, 'UNLOAD') !== false || (string)($row['reference_type'] ?? '') === 'trip_unload') {
            $label = 'UNLOADED AT DESTINATION';
            $from = extractContainerOrTrip($notes);
            $to = $warehouse;
        } elseif (preg_match('/moved from (.+?) to (.+)$/i', $notes, $m)) {
            $label = 'LOCATION MOVED';
            $from = $branchName . ' Warehouse · ' . trim($m[1]);
            $to = $branchName . ' Warehouse · ' . trim($m[2]);
        } else {
            $label = 'LOCATION MOVED';
            $to = $warehouse;
        }
    }

    return ['event' => $event, 'label' => $label, 'from' => $from, 'to' => $to];
}

function extractContainerOrTrip(string $notes): string {
    if (preg_match('/Container\s+([A-Za-z0-9-]+)/i', $notes, $m)) return $m[1];
    if (preg_match('/Trip\s+([A-Za-z0-9-]+)/i', $notes, $m)) return $m[1];
    return 'Container / Trip';
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid" style="padding: 20px;">
<style>
    :root { --curdun-violet: #2D1859; --curdun-yellow: #F5C410; --curdun-violet-light: #4B2C85; --curdun-yellow-dark: #D4A70C; }
    .movements-page .page-header { background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light)); border-radius: 16px; padding: 20px 25px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
    .movements-page .page-header h1 { color: #fff; font-size: 22px; margin: 0; font-weight: 700; }
    .movements-page .page-header h1 i { margin-right: 10px; }
    .movements-page .branch-badge { background: rgba(255,255,255,0.18); color: #fff; padding: 8px 16px; border-radius: 999px; font-size: 13px; }
    .movements-page .filters-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px; margin-bottom: 22px; }
    .movements-page .filter-form { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; }
    .movements-page .filter-group { flex: 1; min-width: 160px; }
    .movements-page .filter-group label { display: block; font-size: 12px; font-weight: 700; color: #4b5563; margin-bottom: 5px; }
    .movements-page .filter-group input, .movements-page .filter-group select { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 13px; }
    .movements-page .btn-filter { background: var(--curdun-violet); color: #fff; border: none; padding: 9px 20px; border-radius: 10px; cursor: pointer; }
    .movements-page .btn-reset { background: #f3f4f6; border: 1px solid #e5e7eb; padding: 9px 20px; border-radius: 10px; cursor: pointer; margin-left: 8px; color: #374151; text-decoration: none; display: inline-block; }
    .movements-page .btn-refresh { background: #fff; border: 1px solid rgba(255,255,255,.45); color: #fff; padding: 8px 14px; border-radius: 999px; text-decoration: none; font-size: 13px; }
    .movements-page .table-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; overflow: hidden; }
    .movements-page table th { background: #f9fafb; font-weight: 700; font-size: 12.5px; padding: 12px; white-space: nowrap; }
    .movements-page table td { padding: 12px; font-size: 13px; vertical-align: middle; }
    .movements-page .qty-positive { color: #10B981; font-weight: 700; }
    .movements-page .qty-negative { color: #DC2626; font-weight: 700; }
    .movements-page .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; flex-wrap: wrap; }
    .movements-page .pagination-link, .movements-page .active-page { min-width: 40px; height: 40px; padding: 0 12px; border-radius: 10px; display: flex; align-items: center; justify-content: center; text-decoration: none; font-size: 13px; font-weight: 600; }
    .movements-page .pagination-link { background: #fff; color: #374151; border: 1px solid #d1d5db; }
    .movements-page .pagination-link:hover { background: var(--curdun-violet); color: #fff; border-color: var(--curdun-violet); }
    .movements-page .active-page { background: var(--curdun-violet); color: #fff; border: 1px solid var(--curdun-violet); }
    @media (max-width: 768px) { .movements-page .filter-form { flex-direction: column; } }
</style>

<div class="movements-page">
    <div class="page-header">
        <h1><i class="fas fa-right-left"></i> Stock Movements</h1>
        <div>
            <a class="btn-refresh" href="<?= h($_SERVER['REQUEST_URI'] ?? 'stock_movements.php') ?>"><i class="fas fa-sync-alt"></i> Refresh</a>
            <span class="branch-badge"><i class="fas fa-code-branch"></i> <?= h($branch_name) ?></span>
        </div>
    </div>

    <div class="filters-card">
        <form class="filter-form" method="get" id="movementFilterForm" autocomplete="off">
            <div class="filter-group"><label><i class="fas fa-search"></i> Search</label><input type="text" name="search" value="<?= h($search) ?>" placeholder="Shipment, tracking, cargo, note..."></div>
            <div class="filter-group">
                <label>Movement Type</label>
                <select name="movement_event" id="movementEventFilter" autocomplete="off">
                    <option value="">All types</option>
                    <?php foreach ($movement_labels as $k => $v): ?>
                        <option value="<?= h($k) ?>" <?= $movement_event === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group"><label>From</label><input type="date" name="date_from" value="<?= h($date_from) ?>"></div>
            <div class="filter-group"><label>To</label><input type="date" name="date_to" value="<?= h($date_to) ?>"></div>
            <div>
                <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Filter</button>
                <a href="stock_movements.php" class="btn-reset"><i class="fas fa-undo"></i> Reset</a>
            </div>
        </form>
    </div>

    <div class="table-card">
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>Shipment</th>
                        <th>Tracking</th>
                        <th>Cargo</th>
                        <th>Type</th>
                        <th>Change</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Before &rarr; After</th>
                        <th>Reference</th>
                        <th>Notes</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($movements): foreach ($movements as $m):
                    $unit = movementUnit($m);
                    $display = movementDisplay($m, $branch_name);
                ?>
                    <tr>
                        <td><?= h(date('Y-m-d H:i', strtotime($m['created_at']))) ?></td>
                        <td><strong><?= h($m['shipment_number'] ?: '-') ?></strong></td>
                        <td><?= h($m['tracking_number'] ?: '-') ?></td>
                        <td>
                            <strong><?= h($m['stock_name'] ?? '-') ?></strong>
                            <?php if (!empty($m['zone']) || !empty($m['bin_location'])): ?><br><small class="text-muted"><?= h(($m['zone'] ?: '-') . ' / ' . ($m['bin_location'] ?: '-')) ?></small><?php endif; ?>
                        </td>
                        <td><span class="badge" style="background:<?= $movement_colors[$display['event']] ?? '#6b7280' ?>22;color:<?= $movement_colors[$display['event']] ?? '#6b7280' ?>;padding:5px 10px;border-radius:999px;font-weight:600;"><?= h(strtoupper($display['label'])) ?></span></td>
                        <td class="<?= (int)$m['quantity_change'] > 0 ? 'qty-positive' : ((int)$m['quantity_change'] < 0 ? 'qty-negative' : '') ?>"><?= (int)$m['quantity_change'] > 0 ? '+' : '' ?><?= (int)$m['quantity_change'] ?> <?= h($unit) ?></td>
                        <td><?= h($display['from']) ?></td>
                        <td><?= h($display['to']) ?></td>
                        <td><?= (int)$m['previous_quantity'] ?> &rarr; <?= (int)$m['new_quantity'] ?> <?= h($unit) ?></td>
                        <td><?= h($m['reference_label'] ?: ($m['reference_type'] ? $m['reference_type'] . ($m['reference_id'] ? ' #' . (int)$m['reference_id'] : '') : '-')) ?></td>
                        <td><?= h($m['notes'] ?: '-') ?></td>
                        <td><?= h($m['created_by_name'] ?? 'System') ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="12" class="text-center py-5">
                        <i class="fas fa-right-left fa-3x text-muted mb-3"></i>
                        <p class="mb-0">No stock movements recorded yet.</p>
                        <small class="text-muted">Movements are logged automatically when stock is received, adjusted, or moved on the Warehouse Stock page.</small>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?><a class="pagination-link" href="<?= buildQuery(['page' => $page - 1]) ?>"><i class="fas fa-chevron-left"></i> Prev</a><?php endif; ?>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php if ($i == $page): ?><span class="active-page"><?= $i ?></span>
                <?php elseif ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?><a class="pagination-link" href="<?= buildQuery(['page' => $i]) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?><a class="pagination-link" href="<?= buildQuery(['page' => $page + 1]) ?>">Next <i class="fas fa-chevron-right"></i></a><?php endif; ?>
        </div>
    <?php endif; ?>
    <p class="text-muted text-center mt-2" style="font-size:13px;"><?= (int)$total ?> movement<?= $total === 1 ? '' : 's' ?> found</p>
</div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const movementFilter = document.getElementById('movementEventFilter');
    const filterForm = document.getElementById('movementFilterForm');
    if (!movementFilter || !filterForm) return;

    // Keep the visible dropdown honest: it must reflect the filter that was
    // actually used by the server query, not a browser-restored stale value.
    movementFilter.value = <?= json_encode($movement_event) ?>;

    movementFilter.addEventListener('change', function() {
        const pageInput = filterForm.querySelector('input[name="page"]');
        if (pageInput) pageInput.remove();
        filterForm.submit();
    });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
