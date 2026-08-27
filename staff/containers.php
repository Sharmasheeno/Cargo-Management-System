<?php
// staff/containers.php
// Containers Management for Staff (role_type: warehouse_supervisor / logistics_supervisor)
// Adapted from branch_manager/containers.php and tenant_admin/containers.php, but scoped
// down for the day-to-day staff role: create/edit containers and advance their status
// forward (received -> ... -> delivered). No delete, no bulk import/export, no WhatsApp
// broadcast -- those remain branch_manager/tenant_admin administrative functions.

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

function postString(string $key, string $default = ''): string {
    $v = $_POST[$key] ?? $default;
    return is_array($v) ? $default : trim((string)$v);
}

function postInt(string $key, int $default = 0): int {
    $v = $_POST[$key] ?? $default;
    return is_numeric($v) ? (int)$v : $default;
}

function postFloat(string $key, float $default = 0.0): float {
    $value = $_POST[$key] ?? $default;
    if (is_array($value)) return $default;
    $value = str_replace(',', '.', trim((string)$value));
    return is_numeric($value) ? (float)$value : $default;
}

function nullableDate($value): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    $time = strtotime($value);
    return $time ? date('Y-m-d', $time) : null;
}

$container_types = ['20ft', '40ft', '40hc', 'lcl'];
$container_cbm_map = ['20ft' => 33.2, '40ft' => 67.6, '40hc' => 76.3, 'lcl' => 0];

$container_statuses = ['received', 'loading', 'loaded', 'shipped', 'dispatched', 'at_port', 'ready', 'delivered'];
$container_status_labels = [
    'received' => 'Received',
    'loading' => 'Loading',
    'loaded' => 'Loaded',
    'shipped' => 'Shipped',
    'dispatched' => 'Dispatched',
    'at_port' => 'At Port',
    'ready' => 'Ready',
    'delivered' => 'Delivered',
];
$container_status_colors = [
    'received' => '#17a2b8',
    'loading' => '#ffc107',
    'loaded' => '#fd7e14',
    'shipped' => '#6f42c1',
    'dispatched' => '#20c997',
    'at_port' => '#0d6efd',
    'ready' => '#198754',
    'delivered' => '#28a745',
];

function containerStatusRank(string $status): int {
    $order = ['received' => 1, 'loading' => 2, 'loaded' => 3, 'shipped' => 4, 'dispatched' => 5, 'at_port' => 6, 'ready' => 7, 'delivered' => 8];
    return $order[$status] ?? 0;
}

function isContainerFinalLocked(string $status): bool {
    return $status === 'delivered';
}

function isContainerManifestLocked(string $status): bool {
    return containerStatusRank($status) >= containerStatusRank('shipped');
}

function canMoveContainerForward(string $current, string $new): bool {
    $c = containerStatusRank($current);
    $n = containerStatusRank($new);
    return $c > 0 && $n > 0 && $n > $c;
}

// -----------------------------------------------------
// AJAX actions
// -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    $action = postString('ajax_action');

    if ($action === 'list_containers') {
        $search = postString('search');
        $status = postString('status', 'all');
        $page = max(1, postInt('page', 1));
        $limit = 15;
        $offset = ($page - 1) * $limit;

        $where = ["c.tenant_id = ?", "c.current_branch_id = ?"];
        $params = [$tenant_id, $assigned_branch_id];

        if ($status !== '' && $status !== 'all') {
            $where[] = "c.status = ?";
            $params[] = $status;
        }
        if ($search !== '') {
            $where[] = "(c.container_number LIKE ? OR c.tracking_number LIKE ? OR c.bl_number LIKE ? OR c.seal_number LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) AS total FROM containers c $whereSql";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        $pages = max(1, (int)ceil($total / $limit));

        $sql = "SELECT c.* FROM containers c $whereSql ORDER BY c.created_at DESC, c.id DESC LIMIT $limit OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        global $container_status_labels, $container_status_colors;

        ob_start();
        ?>
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle">
                <thead class="thead-light">
                    <tr>
                        <th>Container #</th>
                        <th>Type</th>
                        <th>CBM (used/total)</th>
                        <th>Weight (kg)</th>
                        <th>Seal / BL</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $r):
                    $color = $container_status_colors[$r['status']] ?? '#6c757d';
                    $label = $container_status_labels[$r['status']] ?? ucfirst((string)$r['status']);
                ?>
                    <tr>
                        <td>
                            <strong><?= h($r['container_number']) ?></strong>
                            <div class="tiny text-muted"><?= h(date('d/m/Y', strtotime($r['created_at'] ?? 'now'))) ?></div>
                        </td>
                        <td class="text-uppercase"><?= h($r['container_type']) ?></td>
                        <td><?= number_format((float)($r['size_used_cbm'] ?? 0), 2) ?> / <?= number_format((float)($r['size_cbm'] ?? 0), 2) ?></td>
                        <td><?= number_format((float)($r['weight_kg'] ?? 0), 2) ?></td>
                        <td><small><?= h($r['seal_number'] ?: '-') ?><br><?= h($r['bl_number'] ?: '-') ?></small></td>
                        <td><span class="badge" style="background:<?= h($color) ?>20;color:<?= h($color) ?>;border:1px solid <?= h($color) ?>"><?= h($label) ?></span></td>
                        <td>
                            <button class="btn btn-sm btn-info view-container" data-id="<?= (int)$r['id'] ?>" title="View"><i class="fas fa-eye"></i></button>
                            <?php if (!isContainerFinalLocked($r['status'])): ?>
                                <button class="btn btn-sm btn-primary advance-container" data-id="<?= (int)$r['id'] ?>" data-status="<?= h($r['status']) ?>" title="Advance Status"><i class="fas fa-forward"></i></button>
                            <?php endif; ?>
                            <?php if (!isContainerManifestLocked($r['status'])): ?>
                                <button class="btn btn-sm btn-secondary edit-container" data-id="<?= (int)$r['id'] ?>" title="Edit"><i class="fas fa-edit"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">No containers found for your branch</td></tr>
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

    if ($action === 'get_container') {
        $id = postInt('id');
        $stmt = $pdo->prepare("SELECT c.* FROM containers c WHERE c.id = ? AND c.tenant_id = ? AND c.current_branch_id = ? LIMIT 1");
        $stmt->execute([$id, $tenant_id, $assigned_branch_id]);
        $container = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$container) jsonOut(['success' => false, 'message' => 'Container not found or not in your branch.']);

        global $container_status_labels;
        ob_start(); ?>
        <div class="mb-2"><strong>Container:</strong> <?= h($container['container_number']) ?></div>
        <div class="mb-2"><strong>Type:</strong> <span class="text-uppercase"><?= h($container['container_type']) ?></span></div>
        <div class="mb-2"><strong>Status:</strong> <?= h($container_status_labels[$container['status']] ?? $container['status']) ?></div>
        <div class="mb-2"><strong>CBM:</strong> <?= number_format((float)($container['size_used_cbm'] ?? 0), 2) ?> / <?= number_format((float)($container['size_cbm'] ?? 0), 2) ?></div>
        <div class="mb-2"><strong>Weight:</strong> <?= number_format((float)($container['weight_kg'] ?? 0), 2) ?> kg</div>
        <div class="mb-2"><strong>Tracking #:</strong> <?= h($container['tracking_number'] ?? '-') ?></div>
        <div class="mb-2"><strong>Seal #:</strong> <?= h($container['seal_number'] ?? '-') ?></div>
        <div class="mb-2"><strong>BL #:</strong> <?= h($container['bl_number'] ?? '-') ?></div>
        <div class="mb-2"><strong>Shipping Line:</strong> <?= h($container['shipping_line'] ?? '-') ?></div>
        <div class="mb-2"><strong>Vessel:</strong> <?= h($container['vessel_name'] ?? '-') ?></div>
        <div class="mb-2"><strong>Port of Loading / Discharge:</strong> <?= h($container['port_of_loading'] ?? '-') ?> &rarr; <?= h($container['port_of_discharge'] ?? '-') ?></div>
        <div class="mb-2"><strong>ETD / ETA Port:</strong> <?= h($container['etd_port'] ?? '-') ?> / <?= h($container['eta_port'] ?? '-') ?></div>
        <div class="mb-2"><strong>Customs Status:</strong> <?= h(ucfirst($container['customs_status'] ?? 'pending')) ?></div>
        <div class="mb-2"><strong>Notes:</strong> <?= nl2br(h($container['notes'] ?? '-')) ?></div>
        <?php
        jsonOut(['success' => true, 'html' => ob_get_clean(), 'container' => $container]);
    }

    if ($action === 'save_container') {
        global $container_types, $container_statuses, $container_cbm_map;

        $id = postString('container_id');
        $container_number = postString('container_number');
        $container_type = postString('container_type', '20ft');
        if (!in_array($container_type, $container_types, true)) $container_type = '20ft';
        $weight_kg = postFloat('weight_kg', 0);
        $tracking_number = postString('tracking_number');
        $seal_number = postString('seal_number');
        $notes = postString('notes');
        $shipping_line = postString('shipping_line');
        $bl_number = postString('bl_number');
        $vessel_name = postString('vessel_name');
        $port_of_loading = postString('port_of_loading');
        $port_of_discharge = postString('port_of_discharge');
        $eta_port = nullableDate($_POST['eta_port'] ?? '');
        $etd_port = nullableDate($_POST['etd_port'] ?? '');
        $customs_status = postString('customs_status', 'pending');
        if (!in_array($customs_status, ['pending', 'cleared', 'held'], true)) $customs_status = 'pending';

        $size_cbm = postFloat('size_cbm', $container_cbm_map[$container_type] ?? 0);

        if ($container_number === '') {
            jsonOut(['success' => false, 'message' => 'Please enter the container number.']);
        }

        try {
            if ($id === '') {
                $check = $pdo->prepare("SELECT id FROM containers WHERE container_number = ? AND tenant_id = ? LIMIT 1");
                $check->execute([$container_number, $tenant_id]);
                if ($check->fetch(PDO::FETCH_ASSOC)) {
                    jsonOut(['success' => false, 'message' => "Container number '$container_number' already exists."]);
                }

                if ($tracking_number === '') {
                    $tracking_number = 'TRK-' . date('Ymd') . '-' . random_int(1000, 9999);
                }

                $stmt = $pdo->prepare("
                    INSERT INTO containers (
                        tenant_id, container_number, container_type, size_cbm, weight_kg, status,
                        current_location, current_branch_id, tracking_number, seal_number, notes,
                        shipping_line, bl_number, vessel_name, port_of_loading, port_of_discharge,
                        eta_port, etd_port, customs_status, created_by, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, 'received', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                    )
                ");
                $stmt->execute([
                    $tenant_id, $container_number, $container_type, $size_cbm, $weight_kg,
                    $branch_name, $assigned_branch_id, $tracking_number, $seal_number, $notes,
                    $shipping_line, $bl_number, $vessel_name, $port_of_loading, $port_of_discharge,
                    $eta_port, $etd_port, $customs_status, $user_id
                ]);
                jsonOut(['success' => true, 'message' => "Container '$container_number' created.", 'id' => (int)$pdo->lastInsertId()]);
            }

            $container_id = (int)$id;
            $checkLock = $pdo->prepare("SELECT status FROM containers WHERE id = ? AND tenant_id = ? AND current_branch_id = ? LIMIT 1");
            $checkLock->execute([$container_id, $tenant_id, $assigned_branch_id]);
            $currentContainer = $checkLock->fetch(PDO::FETCH_ASSOC);
            if (!$currentContainer) jsonOut(['success' => false, 'message' => 'Container not found or not in your branch.']);
            if (isContainerManifestLocked((string)$currentContainer['status'])) {
                jsonOut(['success' => false, 'message' => 'This container can no longer be edited (already shipped or delivered).']);
            }

            $dupCheck = $pdo->prepare("SELECT id FROM containers WHERE container_number = ? AND tenant_id = ? AND id <> ? LIMIT 1");
            $dupCheck->execute([$container_number, $tenant_id, $container_id]);
            if ($dupCheck->fetch(PDO::FETCH_ASSOC)) {
                jsonOut(['success' => false, 'message' => "Container number '$container_number' already exists."]);
            }

            $stmt = $pdo->prepare("
                UPDATE containers
                SET container_number = ?, container_type = ?, size_cbm = ?, weight_kg = ?,
                    tracking_number = ?, seal_number = ?, notes = ?, shipping_line = ?, bl_number = ?,
                    vessel_name = ?, port_of_loading = ?, port_of_discharge = ?, eta_port = ?, etd_port = ?,
                    customs_status = ?, updated_at = NOW()
                WHERE id = ? AND tenant_id = ? AND current_branch_id = ?
            ");
            $stmt->execute([
                $container_number, $container_type, $size_cbm, $weight_kg,
                $tracking_number, $seal_number, $notes, $shipping_line, $bl_number,
                $vessel_name, $port_of_loading, $port_of_discharge, $eta_port, $etd_port,
                $customs_status, $container_id, $tenant_id, $assigned_branch_id
            ]);
            jsonOut(['success' => true, 'message' => "Container '$container_number' updated."]);
        } catch (Throwable $e) {
            jsonOut(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }

    if ($action === 'advance_container_status') {
        $id = postInt('id');
        $new_status = postString('status');
        global $container_statuses;

        if (!in_array($new_status, $container_statuses, true)) jsonOut(['success' => false, 'message' => 'Invalid status.']);

        try {
            $pdo->beginTransaction();
            $check = $pdo->prepare("SELECT status FROM containers WHERE id = ? AND tenant_id = ? AND current_branch_id = ? FOR UPDATE");
            $check->execute([$id, $tenant_id, $assigned_branch_id]);
            $current = $check->fetch(PDO::FETCH_ASSOC);
            if (!$current) { $pdo->rollBack(); jsonOut(['success' => false, 'message' => 'Container not found or not in your branch.']); }

            if (isContainerFinalLocked((string)$current['status'])) {
                $pdo->rollBack();
                jsonOut(['success' => false, 'message' => 'This container has already been delivered; status can no longer change.']);
            }
            if (!canMoveContainerForward((string)$current['status'], $new_status)) {
                $pdo->rollBack();
                jsonOut(['success' => false, 'message' => 'Cannot move status backward or repeat the current status.']);
            }

            $stmt = $pdo->prepare("UPDATE containers SET status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ? AND current_branch_id = ?");
            $stmt->execute([$new_status, $id, $tenant_id, $assigned_branch_id]);

            // Keep any linked trucking trip roughly in sync
            $syncedTripStatus = null;
            try {
                if ($new_status === 'loaded') {
                    $pdo->prepare("UPDATE trucking_trips SET status = 'loaded', loaded_at = NOW() WHERE container_id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
                    $syncedTripStatus = 'loaded';
                } elseif ($new_status === 'shipped' || $new_status === 'dispatched') {
                    $pdo->prepare("UPDATE trucking_trips SET status = 'in_transit', departed_at = NOW() WHERE container_id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
                    $syncedTripStatus = 'in_transit';
                } elseif ($new_status === 'delivered') {
                    $pdo->prepare("UPDATE trucking_trips SET status = 'completed', delivered_at = NOW() WHERE container_id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
                    $syncedTripStatus = 'completed';
                }
            } catch (Throwable $e) {}

            // --- Connected A→Z workflow: propagate onto loaded shipments so the
            // customer tracking reflects container/trip movement automatically.
            try {
                require_once __DIR__ . '/../includes/shipment_functions.php';
                if ($syncedTripStatus !== null) {
                    $tid = $pdo->prepare("SELECT id FROM trucking_trips WHERE container_id = ? AND tenant_id = ? ORDER BY id DESC LIMIT 1");
                    $tid->execute([$id, $tenant_id]);
                    $tripId = (int)$tid->fetchColumn();
                    if ($tripId > 0) {
                        propagate_trip_status_to_shipments($tripId, $syncedTripStatus, ['tenant_id' => $tenant_id]);
                    }
                }
            } catch (Throwable $e) {}

            $pdo->commit();

            jsonOut(['success' => true, 'message' => 'Container status updated.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonOut(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }

    jsonOut(['success' => false, 'message' => 'Unknown action.']);
}

// -----------------------------------------------------
// Stats
// -----------------------------------------------------
$stats = ['total' => 0, 'in_progress' => 0, 'at_sea' => 0, 'delivered' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) total,
               SUM(CASE WHEN status IN ('received','loading','loaded') THEN 1 ELSE 0 END) in_progress,
               SUM(CASE WHEN status IN ('shipped','dispatched','at_port','ready') THEN 1 ELSE 0 END) at_sea,
               SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) delivered
        FROM containers
        WHERE tenant_id = ? AND current_branch_id = ?
    ");
    $stmt->execute([$tenant_id, $assigned_branch_id]);
    $stats = array_merge($stats, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Containers - <?= h($branch_name) ?></title>
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
            <h3><i class="fas fa-truck-loading"></i> Containers</h3>
            <small>Branch: <?= h($branch_name) ?> <?= !empty($current_branch['branch_code']) ? '(' . h($current_branch['branch_code']) . ')' : '' ?></small>
        </div>
        <button class="btn btn-light" data-toggle="modal" data-target="#createContainerModal" id="newContainerBtn"><i class="fas fa-plus-circle"></i> New Container</button>
    </div>

    <div class="row mb-3">
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$stats['total']) ?></div><div>Total Containers</div></div></div>
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$stats['in_progress']) ?></div><div>Received / Loading / Loaded</div></div></div>
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$stats['at_sea']) ?></div><div>Shipped / At Port / Ready</div></div></div>
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$stats['delivered']) ?></div><div>Delivered</div></div></div>
    </div>

    <div class="panel">
        <div class="row mb-3">
            <div class="col-md-6"><input type="text" id="searchInput" class="form-control" placeholder="Search container #, tracking #, BL #, seal #..."></div>
            <div class="col-md-3">
                <select id="statusFilter" class="form-control">
                    <option value="all">All Status</option>
                    <?php foreach ($container_status_labels as $key => $label): ?>
                        <option value="<?= h($key) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3"><button id="refreshBtn" class="btn btn-main btn-block"><i class="fas fa-sync"></i> Refresh</button></div>
        </div>
        <div id="containersTable"><div class="text-center py-5"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>
        <div id="paginationBox"></div>
    </div>
</div>

<!-- Create/Edit Container Modal -->
<div class="modal fade" id="createContainerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="containerForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="containerModalTitle">New Container</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="container_id" id="container_id">
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Container # <span class="text-danger">*</span></label>
                        <input type="text" name="container_number" id="container_number" class="form-control" required>
                    </div>
                    <div class="form-group col-md-3">
                        <label>Type</label>
                        <select name="container_type" id="container_type" class="form-control">
                            <?php foreach ($container_types as $t): ?>
                                <option value="<?= h($t) ?>" class="text-uppercase"><?= h(strtoupper($t)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label>Size (CBM)</label>
                        <input type="number" step="0.01" min="0" name="size_cbm" id="size_cbm" class="form-control" placeholder="Auto from type">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Weight (kg)</label>
                        <input type="number" step="0.01" min="0" name="weight_kg" id="weight_kg" class="form-control" value="0">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Seal #</label>
                        <input type="text" name="seal_number" id="seal_number" class="form-control">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Tracking #</label>
                        <input type="text" name="tracking_number" id="tracking_number" class="form-control" placeholder="Auto-generated if blank">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Shipping Line</label>
                        <input type="text" name="shipping_line" id="shipping_line" class="form-control">
                    </div>
                    <div class="form-group col-md-6">
                        <label>BL #</label>
                        <input type="text" name="bl_number" id="bl_number" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Vessel Name</label>
                        <input type="text" name="vessel_name" id="vessel_name" class="form-control">
                    </div>
                    <div class="form-group col-md-3">
                        <label>ETD Port</label>
                        <input type="date" name="etd_port" id="etd_port" class="form-control">
                    </div>
                    <div class="form-group col-md-3">
                        <label>ETA Port</label>
                        <input type="date" name="eta_port" id="eta_port" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Port of Loading</label>
                        <input type="text" name="port_of_loading" id="port_of_loading" class="form-control">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Port of Discharge</label>
                        <input type="text" name="port_of_discharge" id="port_of_discharge" class="form-control">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Customs Status</label>
                        <select name="customs_status" id="customs_status" class="form-control">
                            <option value="pending">Pending</option>
                            <option value="cleared">Cleared</option>
                            <option value="held">Held</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" id="notes" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-main"><i class="fas fa-save"></i> Save Container</button>
            </div>
        </form>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewContainerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Container Details</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="containerDetails"><div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i></div></div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CBM_MAP = <?= json_encode($container_cbm_map) ?>;
let currentPage = 1;

function toast(msg) { alert(msg); }

function loadContainers(page) {
    page = page || 1;
    currentPage = page;
    $('#containersTable').html('<div class="text-center py-5"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
    $.post('', { ajax_action: 'list_containers', page: page, search: $('#searchInput').val(), status: $('#statusFilter').val() }, function(res) {
        if (res.success) {
            $('#containersTable').html(res.html);
            $('#paginationBox').html(res.pagination);
        } else {
            $('#containersTable').html('<div class="alert alert-danger">' + (res.message || 'Error') + '</div>');
        }
    }, 'json').fail(function() { $('#containersTable').html('<div class="alert alert-danger">Server error.</div>'); });
}

function resetContainerForm() {
    $('#containerForm')[0].reset();
    $('#container_id').val('');
    $('#containerModalTitle').text('New Container');
    $('#customs_status').val('pending');
}

$('#newContainerBtn').on('click', resetContainerForm);

$('#container_type').on('change', function() {
    if (!$('#size_cbm').val()) $('#size_cbm').val(CBM_MAP[$(this).val()] || 0);
});

let searchTimer = null;
$(document).on('keyup', '#searchInput', function() { clearTimeout(searchTimer); searchTimer = setTimeout(() => loadContainers(1), 350); });
$('#statusFilter, #refreshBtn').on('change click', function() { loadContainers(1); });
$(document).on('click', '#paginationBox .page-link', function(e) { e.preventDefault(); loadContainers(parseInt($(this).data('page'))); });

$('#containerForm').on('submit', function(e) {
    e.preventDefault();
    const fd = $(this).serialize() + '&ajax_action=save_container';
    $.post('', fd, function(res) {
        toast(res.message || (res.success ? 'Saved' : 'Error'));
        if (res.success) {
            $('#createContainerModal').modal('hide');
            loadContainers(currentPage);
        }
    }, 'json').fail(function() { toast('Server error.'); });
});

$(document).on('click', '.view-container', function() {
    $('#containerDetails').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i></div>');
    $('#viewContainerModal').modal('show');
    $.post('', { ajax_action: 'get_container', id: $(this).data('id') }, function(res) {
        $('#containerDetails').html(res.success ? res.html : '<div class="alert alert-danger">' + res.message + '</div>');
    }, 'json');
});

$(document).on('click', '.edit-container', function() {
    const id = $(this).data('id');
    $.post('', { ajax_action: 'get_container', id: id }, function(res) {
        if (!res.success) { toast(res.message); return; }
        const c = res.container;
        resetContainerForm();
        $('#containerModalTitle').text('Edit Container');
        $('#container_id').val(c.id);
        $('#container_number').val(c.container_number || '');
        $('#container_type').val(c.container_type || '20ft');
        $('#size_cbm').val(c.size_cbm || 0);
        $('#weight_kg').val(c.weight_kg || 0);
        $('#seal_number').val(c.seal_number || '');
        $('#tracking_number').val(c.tracking_number || '');
        $('#shipping_line').val(c.shipping_line || '');
        $('#bl_number').val(c.bl_number || '');
        $('#vessel_name').val(c.vessel_name || '');
        $('#etd_port').val(c.etd_port || '');
        $('#eta_port').val(c.eta_port || '');
        $('#port_of_loading').val(c.port_of_loading || '');
        $('#port_of_discharge').val(c.port_of_discharge || '');
        $('#customs_status').val(c.customs_status || 'pending');
        $('#notes').val(c.notes || '');
        $('#createContainerModal').modal('show');
    }, 'json');
});

$(document).on('click', '.advance-container', function() {
    const id = $(this).data('id');
    const nextMap = { received: 'loading', loading: 'loaded', loaded: 'shipped', shipped: 'dispatched', dispatched: 'at_port', at_port: 'ready', ready: 'delivered' };
    const current = $(this).data('status');
    const next = nextMap[current];
    if (!next) { toast('This container is already delivered.'); return; }
    if (!confirm(`Advance container to "${next}"?`)) return;
    $.post('', { ajax_action: 'advance_container_status', id: id, status: next }, function(res) {
        toast(res.message || (res.success ? 'Done' : 'Error'));
        if (res.success) loadContainers(currentPage);
    }, 'json').fail(function() { toast('Server error.'); });
});

$(function() { loadContainers(); });
</script>
</body>
</html>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
