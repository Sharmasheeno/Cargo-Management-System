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
require_once __DIR__ . '/../includes/shipment_functions.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    die('Database connection failed: $pdo not found. Check config/db_connect.php');
}

// Only staff accounts may access this page.
// See staffFamilyRoleTypes() / staffLogisticsRoleTypes() in includes/functions.php.
require_once __DIR__ . '/../includes/functions.php';
$staff_role_types = staffFamilyRoleTypes();
$current_role_type = $_SESSION['role_type'] ?? $_SESSION['role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($current_role_type, $staff_role_types, true)) {
    header("Location: ../login.php");
    exit;
}

// Only warehouse_supervisor / logistics_supervisor role_types are permitted on this page
if (!in_array($current_role_type, staffLogisticsRoleTypes(), true)) {
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
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
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
                        <td>
                            <span class="badge" style="background:<?= h($color) ?>20;color:<?= h($color) ?>;border:1px solid <?= h($color) ?>"><?= h($label) ?></span>
                            <?php $appr = $r['approval_status'] ?? 'not_required';
                            if ($appr === 'pending_approval'): ?>
                                <br><small class="badge badge-warning mt-1"><i class="fas fa-hourglass-half"></i> Awaiting BM Approval</small>
                            <?php elseif ($appr === 'approved'): ?>
                                <br><small class="badge badge-success mt-1"><i class="fas fa-check-circle"></i> Approved</small>
                            <?php elseif ($appr === 'rejected'): ?>
                                <br><small class="badge badge-danger mt-1"><i class="fas fa-ban"></i> Rejected</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-info view-trip" data-id="<?= (int)$r['id'] ?>" title="View"><i class="fas fa-eye"></i></button>
                            <?php
                            // Custody: origin Logistics Supervisor only advances the trip up to
                            // in_transit on an inter-branch trip. From in_transit onward the
                            // driver (confirm_arrival) and destination Warehouse Supervisor
                            // (staff/incoming_trips.php receive_shipment) own the transitions.
                            $__interBranch = !empty($r['to_branch_id']) && (int)($r['from_branch_id'] ?? 0) !== (int)$r['to_branch_id'];
                            $__canAdvance = ($r['status'] !== 'completed')
                                && !($__interBranch && in_array($r['status'], ['in_transit','delivered'], true));
                            ?>
                            <?php if ($__canAdvance): ?>
                                <button class="btn btn-sm btn-primary advance-trip" data-id="<?= (int)$r['id'] ?>" data-status="<?= h($r['status']) ?>" title="Advance Status"><i class="fas fa-forward"></i></button>
                            <?php elseif ($__interBranch && $r['status'] === 'in_transit'): ?>
                                <small class="text-muted d-inline-block" title="Destination warehouse will receive"><i class="fas fa-lock"></i> Destination custody</small>
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
        // Active containers in this branch with manifest cargo and no active/final trip.
        $stmt = $pdo->prepare("
            SELECT c.id, c.container_number, c.status, COALESCE(mt.shipment_count,0) shipment_count, COALESCE(mt.used_cbm,0) used_cbm
            FROM containers c
            JOIN (
                SELECT tenant_id, container_id, COUNT(DISTINCT master_shipment_id) shipment_count, COALESCE(SUM(cbm_used),0) used_cbm
                FROM cargo_manifest_items
                WHERE master_shipment_id IS NOT NULL
                GROUP BY tenant_id, container_id
            ) mt ON mt.tenant_id = c.tenant_id AND mt.container_id = c.id
            WHERE c.tenant_id = ? AND c.current_branch_id = ?
              AND c.status IN ('loading','loaded')
              AND NOT EXISTS (
                  SELECT 1 FROM trucking_trips t
                  WHERE t.tenant_id = c.tenant_id AND t.container_id = c.id
                    AND t.status IN ('pending','received','loading','loaded','in_transit','delivered','completed')
              )
            ORDER BY c.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$tenant_id, $assigned_branch_id]);
        jsonOut(['success' => true, 'containers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'get_trip') {
        $id = postInt('id');
        // Approver/dispatcher joins are TENANT-scoped on the users side so a
        // trip in tenant 36 can never expose a name from another tenant even
        // if the approved_by/dispatched_by column somehow held a foreign id.
        $stmt = $pdo->prepare("
            SELECT t.*, c.container_number, c.status AS container_status, c.size_cbm,
                   fb.branch_name AS from_branch_name, tb.branch_name AS to_branch_name,
                   ua.full_name AS approver_name, ua.role_type AS approver_role,
                   ud.full_name AS dispatcher_name, ud.role_type AS dispatcher_role,
                   ur.full_name AS receiver_name, ur.role_type AS receiver_role
            FROM trucking_trips t
            LEFT JOIN containers c ON t.container_id = c.id
            LEFT JOIN branches fb ON t.from_branch_id = fb.id
            LEFT JOIN branches tb ON t.to_branch_id = tb.id
            LEFT JOIN users ua ON ua.id = t.approved_by AND ua.tenant_id = t.tenant_id
            LEFT JOIN users ud ON ud.id = t.dispatched_by AND ud.tenant_id = t.tenant_id
            LEFT JOIN users ur ON ur.id = t.received_by AND ur.tenant_id = t.tenant_id
            WHERE t.id = ? AND t.tenant_id = ? AND (t.branch_id = ? OR t.from_branch_id = ? OR t.to_branch_id = ?)
            LIMIT 1
        ");
        $stmt->execute([$id, $tenant_id, $assigned_branch_id, $assigned_branch_id, $assigned_branch_id]);
        $trip = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$trip) jsonOut(['success' => false, 'message' => 'Trip not found or not in your branch.']);
        // Human-readable role display shared with the approval/dispatch UI.
        $roleDisplay = function(?string $rt): string {
            $map = [
                'branch_manager' => 'Branch Manager',
                'logistics_supervisor' => 'Logistics Supervisor',
                'warehouse_supervisor' => 'Warehouse Supervisor',
                'reception_clerk' => 'Reception Clerk',
                'finance_manager' => 'Finance Manager',
                'delivery_agent' => 'Courier',
                'driver' => 'Driver',
                'clerk' => 'Assistant Worker',
                'tenant_admin' => 'Tenant Admin',
                'superadmin' => 'Super Admin',
            ];
            return $map[(string)$rt] ?? ucwords(str_replace('_', ' ', (string)$rt));
        };
        $totals = !empty($trip['container_id']) ? container_manifest_totals((int)$trip['container_id'], $tenant_id) : ['shipment_count'=>0,'total_quantity'=>0,'used_cbm'=>0,'weight_kg'=>0];
        $man = $pdo->prepare("
            SELECT cmi.quantity, cmi.cbm_used, cmi.weight_kg,
                   s.shipment_number, s.tracking_number, s.cargo_description, s.quantity_unit, s.current_status,
                   ob.branch_name AS origin_name, db.branch_name AS destination_name
            FROM cargo_manifest_items cmi
            JOIN shipments s ON s.id = cmi.master_shipment_id AND s.tenant_id = cmi.tenant_id
            LEFT JOIN branches ob ON ob.id = s.origin_branch_id
            LEFT JOIN branches db ON db.id = s.destination_branch_id
            WHERE cmi.container_id = ? AND cmi.tenant_id = ?
            ORDER BY cmi.id DESC
        ");
        $man->execute([(int)$trip['container_id'], $tenant_id]);
        $manifestRows = $man->fetchAll(PDO::FETCH_ASSOC);

        global $trip_status_labels;
        ob_start(); ?>
        <div class="mb-2"><strong>Trip:</strong> <?= h($trip['trip_number']) ?></div>
        <div class="mb-2"><strong>Container:</strong> <?= h($trip['container_number'] ?? '-') ?> <?= !empty($trip['container_status']) ? '(' . h($trip['container_status']) . ')' : '' ?></div>
        <div class="mb-2"><strong>Status:</strong> <?= h($trip_status_labels[$trip['status']] ?? $trip['status']) ?></div>
        <?php
        $__appr = (string)($trip['approval_status'] ?? 'not_required');
        $__approvalLabels = ['not_required'=>'Not required','pending_approval'=>'Awaiting Approval','approved'=>'Approved','rejected'=>'Rejected'];
        ?>
        <div class="mb-2"><strong>Approval:</strong> <?= h($__approvalLabels[$__appr] ?? $__appr) ?></div>
        <?php if ($__appr === 'approved' && !empty($trip['approved_by'])): ?>
            <div class="mb-2"><strong>Approved By:</strong> <?= h(($trip['approver_name'] ?? '(user removed)')) ?> &mdash; <?= h($roleDisplay($trip['approver_role'] ?? '')) ?></div>
            <div class="mb-2"><strong>Approved At:</strong> <?= h($trip['approved_at'] ?? '-') ?></div>
        <?php elseif ($__appr === 'rejected' && !empty($trip['approved_by'])): ?>
            <div class="mb-2"><strong>Rejected By:</strong> <?= h(($trip['approver_name'] ?? '(user removed)')) ?> &mdash; <?= h($roleDisplay($trip['approver_role'] ?? '')) ?></div>
            <div class="mb-2"><strong>Rejected At:</strong> <?= h($trip['approved_at'] ?? '-') ?></div>
        <?php endif; ?>
        <div class="mb-2"><strong>Route:</strong> <?= h($trip['from_branch_name'] ?? '-') ?> &rarr; <?= h($trip['to_branch_name'] ?? '-') ?></div>
        <div class="mb-2"><strong>Driver:</strong> <?= h($trip['driver_name'] ?? '-') ?> <?= h($trip['driver_phone'] ?? '') ?></div>
        <div class="mb-2"><strong>Truck Plate:</strong> <?= h($trip['truck_plate'] ?? '-') ?></div>
        <div class="mb-2"><strong>Manifest:</strong> <?= number_format($totals['shipment_count']) ?> shipments / <?= number_format($totals['total_quantity']) ?> qty / <?= number_format($totals['used_cbm'], 2) ?> CBM / <?= number_format($totals['weight_kg'], 2) ?> kg</div>
        <div class="mb-2"><strong>Loaded At:</strong> <?= h($trip['loaded_at'] ?? '-') ?></div>
        <div class="mb-2"><strong>Departed At:</strong> <?= h($trip['departed_at'] ?? '-') ?></div>
        <?php if (!empty($trip['dispatched_by'])): ?>
            <div class="mb-2"><strong>Dispatched By:</strong> <?= h(($trip['dispatcher_name'] ?? '(user removed)')) ?> &mdash; <?= h($roleDisplay($trip['dispatcher_role'] ?? '')) ?></div>
        <?php elseif (!empty($trip['departed_at'])): ?>
            <div class="mb-2 text-muted"><small><em>Dispatcher not recorded (departed before the audit column existed).</em></small></div>
        <?php endif; ?>
        <?php if (!empty($trip['arrived_at'])): ?>
            <div class="mb-2"><strong>Arrived At:</strong> <?= h($trip['arrived_at']) ?></div>
        <?php endif; ?>
        <?php if (!empty($trip['received_by'])): ?>
            <div class="mb-2"><strong>Received By:</strong> <?= h(($trip['receiver_name'] ?? '(user removed)')) ?> &mdash; <?= h($roleDisplay($trip['receiver_role'] ?? '')) ?></div>
            <div class="mb-2"><strong>Completed At:</strong> <?= h($trip['arrived_at'] ?? '-') ?></div>
        <?php endif; ?>
        <div class="mb-2"><strong>Delivered At:</strong> <?= h($trip['delivered_at'] ?? '-') ?></div>
        <div class="mb-2"><strong>Notes:</strong> <?= nl2br(h($trip['notes'] ?? '-')) ?></div>
        <hr><h6>Trip Manifest</h6>
        <div class="table-responsive"><table class="table table-sm table-bordered">
            <thead><tr><th>Shipment</th><th>Tracking</th><th>Cargo</th><th>Qty</th><th>CBM</th><th>Weight</th><th>Route</th><th>Status</th></tr></thead>
            <tbody>
            <?php if ($manifestRows): foreach ($manifestRows as $m): ?>
                <tr>
                    <td><?= h($m['shipment_number']) ?></td><td><?= h($m['tracking_number']) ?></td><td><?= h($m['cargo_description']) ?></td>
                    <td><?= (int)$m['quantity'] ?> <?= h($m['quantity_unit'] ?: 'Cartons') ?></td>
                    <td><?= number_format((float)$m['cbm_used'], 4) ?></td><td><?= number_format((float)$m['weight_kg'], 2) ?></td>
                    <td><?= h(($m['origin_name'] ?: '-') . ' → ' . ($m['destination_name'] ?: '-')) ?></td>
                    <td><?= h($m['current_status']) ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="8" class="text-center text-muted">No shipments on this trip container.</td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
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

        $check = $pdo->prepare("SELECT id, status, container_number FROM containers WHERE id = ? AND tenant_id = ? AND current_branch_id = ? LIMIT 1");
        $check->execute([$container_id, $tenant_id, $assigned_branch_id]);
        $container = $check->fetch(PDO::FETCH_ASSOC);
        if (!$container) {
            jsonOut(['success' => false, 'message' => 'Container not found in your branch.']);
        }
        if (!in_array((string)$container['status'], ['loading','loaded'], true)) {
            jsonOut(['success' => false, 'message' => 'Only loading/loaded containers with manifest cargo can be assigned to a trip.']);
        }
        $totals = container_manifest_totals($container_id, $tenant_id);
        if ($totals['shipment_count'] <= 0) {
            jsonOut(['success' => false, 'message' => 'Cannot create a trip for an empty container.']);
        }
        if (container_has_active_trip($container_id, $tenant_id) || container_has_final_trip($container_id, $tenant_id)) {
            jsonOut(['success' => false, 'message' => "Container {$container['container_number']} is already assigned to a trip or historical completed trip."]);
        }

        // trucking_trips.driver_id references drivers.id (not users.id).
        $driver_id = null;
        if ($driver_name !== '') {
            $du = $pdo->prepare("SELECT d.id FROM drivers d
                                 INNER JOIN users u ON u.id = d.user_id AND u.tenant_id = d.tenant_id
                                 WHERE d.tenant_id = ? AND d.is_active = 1
                                   AND u.is_active = 1 AND u.role_type = 'driver'
                                   AND (d.full_name LIKE ? OR u.full_name LIKE ?)
                                 LIMIT 1");
            $driverLike = "%{$driver_name}%";
            $du->execute([$tenant_id, $driverLike, $driverLike]);
            $found = $du->fetchColumn();
            if (!$found) jsonOut(['success' => false, 'message' => 'Select an active driver profile linked to a driver account.']);
            $driver_id = (int)$found;
        }

        try {
            $trip_number = 'TRP-' . date('ymd') . '-' . str_pad((string)random_int(1, 999), 3, '0', STR_PAD_LEFT);
            // Branch policy: inter-branch trips require Branch Manager dispatch
            // approval before they may depart. Intra-branch movements do not.
            $approval_status = $to_branch_id && (int)$to_branch_id !== $assigned_branch_id ? 'pending_approval' : 'not_required';
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO trucking_trips
                (tenant_id, container_id, trip_number, total_cbm, status, driver_id, driver_name, driver_phone, truck_plate, notes, from_branch_id, to_branch_id, branch_id, approval_status, created_at)
                VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $tenant_id, $container_id, $trip_number, $totals['used_cbm'],
                $driver_id, $driver_name ?: null, $driver_phone ?: null, $truck_plate ?: null, $notes ?: null,
                $assigned_branch_id, $to_branch_id, $assigned_branch_id, $approval_status
            ]);
            $tripId = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE containers SET status = 'loaded', updated_at = NOW() WHERE id = ? AND tenant_id = ?")
                ->execute([$container_id, $tenant_id]);
            // Trip creation LINKS shipments to the trip but must NOT dispatch
            // them: physical departure only happens after Branch Manager
            // approval and an explicit Logistics Supervisor dispatch.
            // Shipments therefore remain at LOADED here; current_trip_id and
            // current_container_id are wired so tracking shows the assignment.
            $shipStmt = $pdo->prepare("
                SELECT s.id FROM cargo_manifest_items cmi
                JOIN shipments s ON s.id = cmi.master_shipment_id AND s.tenant_id = cmi.tenant_id
                WHERE cmi.container_id = ? AND cmi.tenant_id = ? AND s.current_status = 'LOADED'
            ");
            $shipStmt->execute([$container_id, $tenant_id]);
            while ($sid = $shipStmt->fetchColumn()) {
                update_shipment_status((int)$sid, 'LOADED', [
                    'tenant_id' => $tenant_id,
                    'force' => true,
                    'current_trip_id' => $tripId,
                    'current_container_id' => $container_id,
                    'branch_id' => $assigned_branch_id,
                    'container_id' => $container_id,
                    'trip_id' => $tripId,
                    'event_type' => 'ASSIGNED_TO_TRIP',
                    'performed_by' => $user_id,
                    'performer_name' => $user_name,
                    'notes' => "Assigned to trip {$trip_number}.",
                ]);
            }
            $pdo->commit();
            $msg = "Trip {$trip_number} created.";
            if ($approval_status === 'pending_approval') {
                $msg .= ' Awaiting Branch Manager dispatch approval before departure.';
            }

            jsonOut(['success' => true, 'message' => $msg . ($driver_id ? " Assigned to driver account #{$driver_id}." : ''), 'id' => $tripId]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonOut(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }

    if ($action === 'update_trip_details') {
        $id = postInt('id');
        $driver_name = postString('driver_name');
        $driver_phone = postString('driver_phone');
        $truck_plate = postString('truck_plate');
        $notes = postString('notes');

        $check = $pdo->prepare("SELECT id, status, container_id FROM trucking_trips WHERE id = ? AND tenant_id = ? AND (branch_id = ? OR from_branch_id = ? OR to_branch_id = ?) LIMIT 1");
        $check->execute([$id, $tenant_id, $assigned_branch_id, $assigned_branch_id, $assigned_branch_id]);
        $existingTrip = $check->fetch(PDO::FETCH_ASSOC);
        if (!$existingTrip) jsonOut(['success' => false, 'message' => 'Trip not found in your branch.']);
        if ((string)$existingTrip['status'] === 'completed') jsonOut(['success' => false, 'message' => 'Completed trips are historical and cannot be edited.']);

        // Total CBM is derived from the container's authoritative manifest
        // totals, not from the browser payload. A POSTed total_cbm is ignored.
        $__cont = (int)($existingTrip['container_id'] ?? 0);
        $__totals = $__cont > 0
            ? container_manifest_totals($__cont, $tenant_id)
            : ['used_cbm' => 0];
        $total_cbm = (float)($__totals['used_cbm'] ?? 0);

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
            // Only the origin branch (or the trip's owning branch) may advance a trip's
            // lifecycle status. Destination-branch access is restricted to their own
            // warehouse receive/handover flows, not to mutating the trip record itself.
            $stmt = $pdo->prepare("SELECT id, status, container_id, approval_status, from_branch_id, to_branch_id FROM trucking_trips WHERE id = ? AND tenant_id = ? AND (branch_id = ? OR from_branch_id = ?) FOR UPDATE");
            $stmt->execute([$id, $tenant_id, $assigned_branch_id, $assigned_branch_id]);
            $trip = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$trip) { $pdo->rollBack(); jsonOut(['success' => false, 'message' => 'Trip not found in your branch.']); }
            if ((string)$trip['status'] === 'completed') {
                $pdo->rollBack();
                jsonOut(['success' => false, 'message' => 'Completed trips are historical and read-only.']);
            }
            $totals = !empty($trip['container_id']) ? container_manifest_totals((int)$trip['container_id'], $tenant_id) : ['shipment_count' => 0];
            if ($totals['shipment_count'] <= 0) {
                $pdo->rollBack();
                jsonOut(['success' => false, 'message' => 'Trip container has no manifest cargo.']);
            }

            if (!canMoveTripForward((string)$trip['status'], $new_status)) {
                $pdo->rollBack();
                jsonOut(['success' => false, 'message' => 'Cannot move status backward or repeat current status.']);
            }

            // Custody gate: on an INTER-branch trip the origin Logistics
            // Supervisor cannot attest destination arrival or completion --
            // the destination Warehouse Supervisor owns receive/close on
            // staff/incoming_trips.php, and the driver owns physical arrival
            // via driver/index.php confirm_arrival. Rejecting these here
            // prevents the previous bypass where an origin actor moved the
            // trip through 'delivered' / 'completed' without any real
            // destination custody being taken.
            $isInterBranch = !empty($trip['to_branch_id']) && (int)$trip['from_branch_id'] !== (int)$trip['to_branch_id'];
            if ($isInterBranch && in_array($new_status, ['delivered','completed'], true)) {
                $pdo->rollBack();
                jsonOut(['success' => false, 'message' => 'This trip must be received by the destination warehouse before completion.']);
            }

            // Dispatch approval gate: a trip awaiting (or refused) Branch
            // Manager approval may not depart the origin branch.
            if ($new_status === 'in_transit') {
                $approval = (string)($trip['approval_status'] ?? 'not_required');
                if ($approval === 'pending_approval') {
                    $pdo->rollBack();
                    jsonOut(['success' => false, 'message' => 'Dispatch not yet approved by the Branch Manager. Approval is required before departure.']);
                }
                if ($approval === 'rejected') {
                    $pdo->rollBack();
                    jsonOut(['success' => false, 'message' => 'Dispatch was rejected by the Branch Manager. The trip cannot depart.']);
                }
            }

            $timeCol = null;
            if ($new_status === 'loaded') $timeCol = 'loaded_at';
            elseif ($new_status === 'in_transit') $timeCol = 'departed_at';
            elseif ($new_status === 'delivered') $timeCol = 'delivered_at';
            elseif ($new_status === 'completed') $timeCol = 'arrived_at';

            if ($new_status === 'in_transit') {
                // Dispatch audit: authoritatively persist the actor who moved
                // the trip into IN_TRANSIT. This lets Trip Details show
                // "Dispatched By" separately from the Branch Manager approval
                // audit, so the separation of duties is visible.
                $pdo->prepare("UPDATE trucking_trips SET status = ?, `$timeCol` = NOW(), dispatched_by = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?")
                    ->execute([$new_status, $_SESSION['user_id'] ?? null, $id, $tenant_id]);
            } elseif ($timeCol) {
                $pdo->prepare("UPDATE trucking_trips SET status = ?, `$timeCol` = NOW(), updated_at = NOW() WHERE id = ? AND tenant_id = ?")
                    ->execute([$new_status, $id, $tenant_id]);
            } else {
                $pdo->prepare("UPDATE trucking_trips SET status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?")
                    ->execute([$new_status, $id, $tenant_id]);
            }
            if (!empty($trip['container_id'])) {
                $containerStatus = null;
                if ($new_status === 'loading') $containerStatus = 'loading';
                elseif ($new_status === 'loaded') $containerStatus = 'loaded';
                elseif ($new_status === 'in_transit') $containerStatus = 'dispatched';
                elseif ($new_status === 'delivered') $containerStatus = 'ready';
                elseif ($new_status === 'completed') $containerStatus = 'delivered';
                if ($containerStatus !== null) {
                    $pdo->prepare("UPDATE containers SET status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?")
                        ->execute([$containerStatus, (int)$trip['container_id'], $tenant_id]);
                }
            }

            // --- Connected A→Z workflow: propagate the trip event onto every
            // shipment actually loaded on this container so customer tracking
            // updates automatically. Arrival never equals DELIVERED: shipments
            // go to ARRIVED_AT_DESTINATION awaiting warehouse receipt.
            require_once __DIR__ . '/../includes/shipment_functions.php';
            propagate_trip_status_to_shipments($id, $new_status, ['tenant_id' => $tenant_id]);

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
                    <small id="noTripContainerHint" class="form-text text-muted" style="display:none;">No eligible containers are available. Create/load a new container before creating another trip.</small>
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
                        <label>Total CBM <small class="text-muted">(auto from selected container manifest)</small></label>
                        <input type="number" step="0.01" min="0" name="total_cbm" id="createTotalCbm" class="form-control" value="0" readonly>
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
            res.containers.forEach(c => {
                var used = Number(c.used_cbm || 0);
                html += `<option value="${c.id}" data-used-cbm="${used.toFixed(4)}">${$('<div>').text(c.container_number).html()} (${c.status}, ${used.toFixed(2)} CBM)</option>`;
            });
            $('#containerSelect').html(html);
            $('#createTotalCbm').val('0.00');
            $('#noTripContainerHint').toggle((res.containers || []).length === 0);
        }
    }, 'json');
}
// Populate Total CBM from the authoritative used_cbm of the selected container.
// Container capacity (size_cbm) is intentionally NOT used here -- the trip
// carries the actual loaded cargo CBM, not the container's maximum capacity.
$(document).on('change', '#containerSelect', function() {
    var used = parseFloat($(this).find(':selected').data('used-cbm')) || 0;
    $('#createTotalCbm').val(used.toFixed(2));
});

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
