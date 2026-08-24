<?php
// staff/trips.php
// Trips Management for Staff (role_type: warehouse_supervisor / logistics_supervisor)
// Adapted from branch_manager/trips.php, scoped to the staff member's assigned branch.
// Staff can create trips, edit driver/truck details, and advance status forward --
// there is no delete action here (day-to-day operational work, not administrative).

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db_connect.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    die('Database connection failed: $pdo not found. Check config/db_connect.php');
}

// Only staff accounts may access this page.
// NOTE: login.php stores the sub-role (role_type) into $_SESSION['role'] as an alias, so a
// plain === 'staff' check only matches the generic staff account and would incorrectly lock
// out every staff sub-role. Check role_type (falling back to role) against the known staff
// role_types instead -- same pattern applied to the 4 existing base staff pages.
$staff_role_types = ['staff', 'warehouse_supervisor', 'logistics_supervisor', 'finance_manager', 'clerk'];
$current_role_type = $_SESSION['role_type'] ?? $_SESSION['role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($current_role_type, $staff_role_types, true)) {
    header("Location: ../login.php");
    exit;
}

// Only warehouse_supervisor / logistics_supervisor role_types are permitted on this page
if (!in_array($current_role_type, ['warehouse_supervisor', 'logistics_supervisor'], true)) {
    header("Location: ../staff/dashboard.php?error=access_denied");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
$user_name = $_SESSION['user_name'] ?? 'Staff';

if ($tenant_id <= 0) {
    header("Location: ../login.php?error=no_tenant");
    exit;
}

// -----------------------------------------------------
// Resolve the staff member's assigned branch
// -----------------------------------------------------
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
$assigned_branch_id = (int)$assigned_branch_id;

$stmt = $pdo->prepare("SELECT id, branch_name, branch_code FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1");
$stmt->execute([$assigned_branch_id, $tenant_id]);
$current_branch = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$current_branch) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container-fluid"><div class="alert alert-danger m-4">Assigned branch was not found for this tenant.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}
$branch_name = $current_branch['branch_name'];

// -----------------------------------------------------
// Helpers
// -----------------------------------------------------
function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function jsonOut(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function postInt(string $key, int $default = 0): int {
    $v = $_POST[$key] ?? $default;
    return is_numeric($v) ? (int)$v : $default;
}

function postString(string $key, string $default = ''): string {
    $v = $_POST[$key] ?? $default;
    return is_array($v) ? $default : trim((string)$v);
}

$trip_statuses = ['pending', 'received', 'loading', 'loaded', 'in_transit', 'delivered', 'completed'];

$trip_status_labels = [
    'pending' => 'Pending',
    'received' => 'Received',
    'loading' => 'Loading',
    'loaded' => 'Loaded',
    'in_transit' => 'In Transit',
    'delivered' => 'Delivered',
    'completed' => 'Completed',
];

$trip_status_colors = [
    'pending' => '#6c757d',
    'received' => '#17a2b8',
    'loading' => '#ffc107',
    'loaded' => '#fd7e14',
    'in_transit' => '#6f42c1',
    'delivered' => '#28a745',
    'completed' => '#20c997',
];

function tripStatusRank(string $status): int {
    $order = ['pending' => 1, 'received' => 2, 'loading' => 3, 'loaded' => 4, 'in_transit' => 5, 'delivered' => 6, 'completed' => 7];
    return $order[$status] ?? 0;
}

function canMoveTripForward(string $current, string $new): bool {
    $c = tripStatusRank($current);
    $n = tripStatusRank($new);
    return $c > 0 && $n > 0 && $n > $c;
}

// -----------------------------------------------------
// AJAX actions
// -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    $action = postString('ajax_action');

    if ($action === 'list_trips') {
        $search = postString('search');
        $status = postString('status', 'all');
        $page = max(1, postInt('page', 1));
        $limit = 15;
        $offset = ($page - 1) * $limit;

        $where = ["t.tenant_id = ?", "(t.branch_id = ? OR t.from_branch_id = ? OR t.to_branch_id = ?)"];
        $params = [$tenant_id, $assigned_branch_id, $assigned_branch_id, $assigned_branch_id];

        if ($status !== '' && $status !== 'all') {
            $where[] = "t.status = ?";
            $params[] = $status;
        }
        if ($search !== '') {
            $where[] = "(t.trip_number LIKE ? OR t.driver_name LIKE ? OR t.truck_plate LIKE ? OR c.container_number LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) AS total FROM trucking_trips t LEFT JOIN containers c ON t.container_id = c.id $whereSql";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        $pages = max(1, (int)ceil($total / $limit));

        $sql = "
            SELECT t.*, c.container_number, c.status AS container_status,
                   fb.branch_name AS from_branch_name, tb.branch_name AS to_branch_name,
                   d.full_name AS driver_full_name, d.phone AS driver_table_phone
            FROM trucking_trips t
            LEFT JOIN containers c ON t.container_id = c.id
            LEFT JOIN branches fb ON t.from_branch_id = fb.id
            LEFT JOIN branches tb ON t.to_branch_id = tb.id
            LEFT JOIN drivers d ON t.driver_id = d.id
            $whereSql
            ORDER BY t.created_at DESC, t.id DESC
            LIMIT $limit OFFSET $offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        global $trip_status_labels, $trip_status_colors;

        ob_start();
        ?>
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle">
                <thead class="thead-light">
                    <tr>
                        <th>Trip #</th>
                        <th>Container</th>
                        <th>Driver / Truck</th>
                        <th>Route</th>
                        <th>CBM</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $r):
                    $color = $trip_status_colors[$r['status']] ?? '#6c757d';
                    $label = $trip_status_labels[$r['status']] ?? ucfirst((string)$r['status']);
                    $driverName = $r['driver_name'] ?: ($r['driver_full_name'] ?? '');
                    $driverPhone = $r['driver_phone'] ?: ($r['driver_table_phone'] ?? '');
                ?>
                    <tr>
                        <td>
                            <strong><?= h($r['trip_number']) ?></strong>
                            <div class="tiny text-muted"><?= h(date('d/m/Y', strtotime($r['created_at'] ?? 'now'))) ?></div>
                        </td>
                        <td><?= h($r['container_number'] ?? '-') ?></td>
                        <td>
                            <?= h($driverName ?: '-') ?><br>
                            <small class="text-muted"><?= h($driverPhone ?: '') ?> <?= !empty($r['truck_plate']) ? '&middot; ' . h($r['truck_plate']) : '' ?></small>
                        </td>
                        <td><small><?= h($r['from_branch_name'] ?? '-') ?> &rarr; <?= h($r['to_branch_name'] ?? '-') ?></small></td>
                        <td><?= number_format((float)($r['total_cbm'] ?? 0), 2) ?></td>
                        <td><span class="badge" style="background:<?= h($color) ?>20;color:<?= h($color) ?>;border:1px solid <?= h($color) ?>"><?= h($label) ?></span></td>
                        <td>
                            <button class="btn btn-sm btn-info view-trip" data-id="<?= (int)$r['id'] ?>" title="View"><i class="fas fa-eye"></i></button>
                            <?php if ($r['status'] !== 'completed'): ?>
                                <button class="btn btn-sm btn-primary advance-trip" data-id="<?= (int)$r['id'] ?>" data-status="<?= h($r['status']) ?>" title="Advance Status"><i class="fas fa-forward"></i></button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-secondary edit-trip" data-id="<?= (int)$r['id'] ?>" title="Edit Driver/Truck"><i class="fas fa-edit"></i></button>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">No trips found for your branch</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        $html = ob_get_clean();

        ob_start();
        if ($pages > 1): ?>
            <nav><ul class="pagination justify-content-center">
                <?php for ($i = 1; $i <= $pages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a></li>
                <?php endfor; ?>
            </ul></nav>
        <?php endif;
        $pagination = ob_get_clean();

        jsonOut(['success' => true, 'html' => $html, 'pagination' => $pagination, 'total' => $total]);
    }

    if ($action === 'get_branch_containers') {
        // Containers in this branch, without a trip yet, or with a pending trip
        $stmt = $pdo->prepare("
            SELECT c.id, c.container_number, c.status
            FROM containers c
            WHERE c.tenant_id = ? AND c.current_branch_id = ?
            ORDER BY c.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$tenant_id, $assigned_branch_id]);
        jsonOut(['success' => true, 'containers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'get_trip') {
        $id = postInt('id');
        $stmt = $pdo->prepare("
            SELECT t.*, c.container_number, fb.branch_name AS from_branch_name, tb.branch_name AS to_branch_name
            FROM trucking_trips t
            LEFT JOIN containers c ON t.container_id = c.id
            LEFT JOIN branches fb ON t.from_branch_id = fb.id
            LEFT JOIN branches tb ON t.to_branch_id = tb.id
            WHERE t.id = ? AND t.tenant_id = ? AND (t.branch_id = ? OR t.from_branch_id = ? OR t.to_branch_id = ?)
            LIMIT 1
        ");
        $stmt->execute([$id, $tenant_id, $assigned_branch_id, $assigned_branch_id, $assigned_branch_id]);
        $trip = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$trip) jsonOut(['success' => false, 'message' => 'Trip not found or not in your branch.']);

        global $trip_status_labels;
        ob_start(); ?>
        <div class="mb-2"><strong>Trip:</strong> <?= h($trip['trip_number']) ?></div>
        <div class="mb-2"><strong>Container:</strong> <?= h($trip['container_number'] ?? '-') ?></div>
        <div class="mb-2"><strong>Status:</strong> <?= h($trip_status_labels[$trip['status']] ?? $trip['status']) ?></div>
        <div class="mb-2"><strong>Route:</strong> <?= h($trip['from_branch_name'] ?? '-') ?> &rarr; <?= h($trip['to_branch_name'] ?? '-') ?></div>
        <div class="mb-2"><strong>Driver:</strong> <?= h($trip['driver_name'] ?? '-') ?> <?= h($trip['driver_phone'] ?? '') ?></div>
        <div class="mb-2"><strong>Truck Plate:</strong> <?= h($trip['truck_plate'] ?? '-') ?></div>
        <div class="mb-2"><strong>Total CBM:</strong> <?= number_format((float)($trip['total_cbm'] ?? 0), 2) ?></div>
        <div class="mb-2"><strong>Loaded At:</strong> <?= h($trip['loaded_at'] ?? '-') ?></div>
        <div class="mb-2"><strong>Departed At:</strong> <?= h($trip['departed_at'] ?? '-') ?></div>
        <div class="mb-2"><strong>Delivered At:</strong> <?= h($trip['delivered_at'] ?? '-') ?></div>
        <div class="mb-2"><strong>Notes:</strong> <?= nl2br(h($trip['notes'] ?? '-')) ?></div>
        <?php
        jsonOut(['success' => true, 'html' => ob_get_clean(), 'trip' => $trip]);
    }

    if ($action === 'create_trip') {
        $container_id = postInt('container_id');
        $driver_name = postString('driver_name');
        $driver_phone = postString('driver_phone');
        $truck_plate = postString('truck_plate');
        $total_cbm = (float)str_replace(',', '.', postString('total_cbm', '0'));
        $to_branch_id = postInt('to_branch_id') ?: null;
        $notes = postString('notes');

        if ($container_id <= 0) jsonOut(['success' => false, 'message' => 'Please select a container.']);

        $check = $pdo->prepare("SELECT id, status FROM containers WHERE id = ? AND tenant_id = ? AND current_branch_id = ? LIMIT 1");
        $check->execute([$container_id, $tenant_id, $assigned_branch_id]);
        if (!$check->fetch(PDO::FETCH_ASSOC)) {
            jsonOut(['success' => false, 'message' => 'Container not found in your branch.']);
        }

        try {
            $trip_number = 'TRP-' . date('ymd') . '-' . str_pad((string)random_int(1, 999), 3, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare("
                INSERT INTO trucking_trips
                (tenant_id, container_id, trip_number, total_cbm, status, driver_name, driver_phone, truck_plate, notes, from_branch_id, to_branch_id, branch_id, created_at)
                VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $tenant_id, $container_id, $trip_number, $total_cbm,
                $driver_name ?: null, $driver_phone ?: null, $truck_plate ?: null, $notes ?: null,
                $assigned_branch_id, $to_branch_id, $assigned_branch_id
            ]);
            jsonOut(['success' => true, 'message' => "Trip {$trip_number} created.", 'id' => (int)$pdo->lastInsertId()]);
        } catch (Throwable $e) {
            jsonOut(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }

    if ($action === 'update_trip_details') {
        $id = postInt('id');
        $driver_name = postString('driver_name');
        $driver_phone = postString('driver_phone');
        $truck_plate = postString('truck_plate');
        $total_cbm = (float)str_replace(',', '.', postString('total_cbm', '0'));
        $notes = postString('notes');

        $check = $pdo->prepare("SELECT id FROM trucking_trips WHERE id = ? AND tenant_id = ? AND (branch_id = ? OR from_branch_id = ? OR to_branch_id = ?) LIMIT 1");
        $check->execute([$id, $tenant_id, $assigned_branch_id, $assigned_branch_id, $assigned_branch_id]);
        if (!$check->fetch(PDO::FETCH_ASSOC)) jsonOut(['success' => false, 'message' => 'Trip not found in your branch.']);

        try {
            $stmt = $pdo->prepare("
                UPDATE trucking_trips
                SET driver_name = ?, driver_phone = ?, truck_plate = ?, total_cbm = ?, notes = ?, updated_at = NOW()
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$driver_name ?: null, $driver_phone ?: null, $truck_plate ?: null, $total_cbm, $notes ?: null, $id, $tenant_id]);
            jsonOut(['success' => true, 'message' => 'Trip updated.']);
        } catch (Throwable $e) {
            jsonOut(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }

    if ($action === 'advance_trip_status') {
        $id = postInt('id');
        $new_status = postString('status');
        global $trip_statuses;

        if (!in_array($new_status, $trip_statuses, true)) jsonOut(['success' => false, 'message' => 'Invalid status.']);

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT status FROM trucking_trips WHERE id = ? AND tenant_id = ? AND (branch_id = ? OR from_branch_id = ? OR to_branch_id = ?) FOR UPDATE");
            $stmt->execute([$id, $tenant_id, $assigned_branch_id, $assigned_branch_id, $assigned_branch_id]);
            $trip = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$trip) { $pdo->rollBack(); jsonOut(['success' => false, 'message' => 'Trip not found in your branch.']); }

            if (!canMoveTripForward((string)$trip['status'], $new_status)) {
                $pdo->rollBack();
                jsonOut(['success' => false, 'message' => 'Cannot move status backward or repeat current status.']);
            }

            $timeCol = null;
            if ($new_status === 'loaded') $timeCol = 'loaded_at';
            elseif ($new_status === 'in_transit') $timeCol = 'departed_at';
            elseif ($new_status === 'delivered') $timeCol = 'delivered_at';
            elseif ($new_status === 'completed') $timeCol = 'arrived_at';

            if ($timeCol) {
                $pdo->prepare("UPDATE trucking_trips SET status = ?, `$timeCol` = NOW(), updated_at = NOW() WHERE id = ? AND tenant_id = ?")
                    ->execute([$new_status, $id, $tenant_id]);
            } else {
                $pdo->prepare("UPDATE trucking_trips SET status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?")
                    ->execute([$new_status, $id, $tenant_id]);
            }

            $pdo->commit();
            jsonOut(['success' => true, 'message' => 'Trip status updated.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonOut(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }

    jsonOut(['success' => false, 'message' => 'Unknown action.']);
}

// -----------------------------------------------------
// Stats + other branches (for destination selection)
// -----------------------------------------------------
$stats = ['total' => 0, 'pending' => 0, 'in_transit' => 0, 'completed' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) total,
               SUM(CASE WHEN status IN ('pending','received') THEN 1 ELSE 0 END) pending,
               SUM(CASE WHEN status IN ('loading','loaded','in_transit') THEN 1 ELSE 0 END) in_transit,
               SUM(CASE WHEN status IN ('delivered','completed') THEN 1 ELSE 0 END) completed
        FROM trucking_trips
        WHERE tenant_id = ? AND (branch_id = ? OR from_branch_id = ? OR to_branch_id = ?)
    ");
    $stmt->execute([$tenant_id, $assigned_branch_id, $assigned_branch_id, $assigned_branch_id]);
    $stats = array_merge($stats, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {}

$other_branches = [];
try {
    $stmt = $pdo->prepare("SELECT id, branch_name FROM branches WHERE tenant_id = ? AND id <> ? AND status = 'active' ORDER BY branch_name ASC");
    $stmt->execute([$tenant_id, $assigned_branch_id]);
    $other_branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trips - <?= h($branch_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { background:#f4f6f9; }
        .page-wrap { padding: 20px; }
        .hero { background: linear-gradient(135deg,#2D1859,#4B2C85); color:#fff; border-radius:18px; padding:22px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
        .hero h3 { margin:0; font-weight:700; }
        .hero small { opacity:.9; }
        .stat-card { background:#fff; border-radius:16px; padding:18px; box-shadow:0 6px 18px rgba(0,0,0,.06); border:1px solid #eee; }
        .stat-card .num { font-size:28px; font-weight:800; color:#2D1859; }
        .panel { background:#fff; border-radius:16px; padding:18px; box-shadow:0 6px 18px rgba(0,0,0,.06); border:1px solid #eee; }
        .btn-main { background:#2D1859; color:#fff; border:0; }
        .btn-main:hover { background:#1F0F3D; color:#fff; }
        .tiny { font-size: 11px; }
    </style>
</head>
<body>
<div class="page-wrap">
    <div class="hero">
        <div>
            <h3><i class="fas fa-road"></i> Trips</h3>
            <small>Branch: <?= h($branch_name) ?> <?= !empty($current_branch['branch_code']) ? '(' . h($current_branch['branch_code']) . ')' : '' ?></small>
        </div>
        <button class="btn btn-light" data-toggle="modal" data-target="#createTripModal"><i class="fas fa-plus-circle"></i> New Trip</button>
    </div>

    <div class="row mb-3">
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$stats['total']) ?></div><div>Total Trips</div></div></div>
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$stats['pending']) ?></div><div>Pending / Received</div></div></div>
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$stats['in_transit']) ?></div><div>Loading / In Transit</div></div></div>
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$stats['completed']) ?></div><div>Delivered / Completed</div></div></div>
    </div>

    <div class="panel">
        <div class="row mb-3">
            <div class="col-md-6"><input type="text" id="searchInput" class="form-control" placeholder="Search trip number, driver, truck plate, container..."></div>
            <div class="col-md-3">
                <select id="statusFilter" class="form-control">
                    <option value="all">All Status</option>
                    <?php foreach ($trip_status_labels as $key => $label): ?>
                        <option value="<?= h($key) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3"><button id="refreshBtn" class="btn btn-main btn-block"><i class="fas fa-sync"></i> Refresh</button></div>
        </div>
        <div id="tripsTable"><div class="text-center py-5"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>
        <div id="paginationBox"></div>
    </div>
</div>

<!-- Create Trip Modal -->
<div class="modal fade" id="createTripModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="createTripForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create Trip</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="ajax_action" value="create_trip">
                <div class="form-group">
                    <label>Container <span class="text-danger">*</span></label>
                    <select name="container_id" id="containerSelect" class="form-control" required>
                        <option value="">Loading containers...</option>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Driver Name</label>
                        <input type="text" name="driver_name" class="form-control">
                    </div>
                    <div class="form-group col-md-6">
                        <label>Driver Phone</label>
                        <input type="text" name="driver_phone" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Truck Plate</label>
                        <input type="text" name="truck_plate" class="form-control">
                    </div>
                    <div class="form-group col-md-6">
                        <label>Total CBM</label>
                        <input type="number" step="0.01" min="0" name="total_cbm" class="form-control" value="0">
                    </div>
                </div>
                <div class="form-group">
                    <label>Destination Branch (optional)</label>
                    <select name="to_branch_id" class="form-control">
                        <option value="">Same branch / not applicable</option>
                        <?php foreach ($other_branches as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= h($b['branch_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-main"><i class="fas fa-save"></i> Save Trip</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Trip Modal -->
<div class="modal fade" id="editTripModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="editTripForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Trip</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="ajax_action" value="update_trip_details">
                <input type="hidden" name="id" id="editTripId">
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Driver Name</label>
                        <input type="text" name="driver_name" id="editDriverName" class="form-control">
                    </div>
                    <div class="form-group col-md-6">
                        <label>Driver Phone</label>
                        <input type="text" name="driver_phone" id="editDriverPhone" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Truck Plate</label>
                        <input type="text" name="truck_plate" id="editTruckPlate" class="form-control">
                    </div>
                    <div class="form-group col-md-6">
                        <label>Total CBM</label>
                        <input type="number" step="0.01" min="0" name="total_cbm" id="editTotalCbm" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" id="editNotes" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-main"><i class="fas fa-save"></i> Update</button>
            </div>
        </form>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewTripModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Trip Details</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="tripDetails"><div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i></div></div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentPage = 1;

function toast(msg, ok) { alert(msg); }

function loadTrips(page) {
    page = page || 1;
    currentPage = page;
    $('#tripsTable').html('<div class="text-center py-5"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
    $.post('', { ajax_action: 'list_trips', page: page, search: $('#searchInput').val(), status: $('#statusFilter').val() }, function(res) {
        if (res.success) {
            $('#tripsTable').html(res.html);
            $('#paginationBox').html(res.pagination);
        } else {
            $('#tripsTable').html('<div class="alert alert-danger">' + (res.message || 'Error') + '</div>');
        }
    }, 'json').fail(function() { $('#tripsTable').html('<div class="alert alert-danger">Server error.</div>'); });
}

function loadContainerOptions() {
    $.post('', { ajax_action: 'get_branch_containers' }, function(res) {
        if (res.success) {
            let html = '<option value="">Select a container</option>';
            res.containers.forEach(c => { html += `<option value="${c.id}">${$('<div>').text(c.container_number).html()} (${c.status})</option>`; });
            $('#containerSelect').html(html);
        }
    }, 'json');
}

let searchTimer = null;
$(document).on('keyup', '#searchInput', function() { clearTimeout(searchTimer); searchTimer = setTimeout(() => loadTrips(1), 350); });
$('#statusFilter, #refreshBtn').on('change click', function() { loadTrips(1); });
$(document).on('click', '#paginationBox .page-link', function(e) { e.preventDefault(); loadTrips(parseInt($(this).data('page'))); });

$('#createTripModal').on('show.bs.modal', loadContainerOptions);

$('#createTripForm').on('submit', function(e) {
    e.preventDefault();
    $.post('', $(this).serialize(), function(res) {
        toast(res.message || (res.success ? 'Saved' : 'Error'));
        if (res.success) {
            $('#createTripModal').modal('hide');
            $('#createTripForm')[0].reset();
            loadTrips(1);
        }
    }, 'json').fail(function() { toast('Server error.'); });
});

$(document).on('click', '.view-trip', function() {
    $('#tripDetails').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i></div>');
    $('#viewTripModal').modal('show');
    $.post('', { ajax_action: 'get_trip', id: $(this).data('id') }, function(res) {
        $('#tripDetails').html(res.success ? res.html : '<div class="alert alert-danger">' + res.message + '</div>');
    }, 'json');
});

$(document).on('click', '.edit-trip', function() {
    const id = $(this).data('id');
    $.post('', { ajax_action: 'get_trip', id: id }, function(res) {
        if (!res.success) { toast(res.message); return; }
        const t = res.trip;
        $('#editTripId').val(t.id);
        $('#editDriverName').val(t.driver_name || '');
        $('#editDriverPhone').val(t.driver_phone || '');
        $('#editTruckPlate').val(t.truck_plate || '');
        $('#editTotalCbm').val(t.total_cbm || 0);
        $('#editNotes').val(t.notes || '');
        $('#editTripModal').modal('show');
    }, 'json');
});

$('#editTripForm').on('submit', function(e) {
    e.preventDefault();
    $.post('', $(this).serialize(), function(res) {
        toast(res.message || (res.success ? 'Saved' : 'Error'));
        if (res.success) { $('#editTripModal').modal('hide'); loadTrips(currentPage); }
    }, 'json').fail(function() { toast('Server error.'); });
});

$(document).on('click', '.advance-trip', function() {
    const id = $(this).data('id');
    const nextMap = { pending: 'received', received: 'loading', loading: 'loaded', loaded: 'in_transit', in_transit: 'delivered', delivered: 'completed' };
    const current = $(this).data('status');
    const next = nextMap[current];
    if (!next) { toast('This trip is already completed.'); return; }
    if (!confirm(`Advance trip to "${next}"?`)) return;
    $.post('', { ajax_action: 'advance_trip_status', id: id, status: next }, function(res) {
        toast(res.message || (res.success ? 'Done' : 'Error'));
        if (res.success) loadTrips(currentPage);
    }, 'json').fail(function() { toast('Server error.'); });
});

$(function() { loadTrips(); });
</script>
</body>
</html>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
