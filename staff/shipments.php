<?php
// ============================================================================
// staff/shipments.php — Shipment Operations Console
// ----------------------------------------------------------------------------
// ONE SHIPMENT identity for the full A→Z lifecycle. Branch-scoped, role-scoped:
//   reception_clerk       : view / register intake (via receptions.php) / print label
//   warehouse_supervisor  : receive into origin warehouse, storage location,
//                           report damage/hold, release pickup proof-of-collection
//   logistics_supervisor  : load into container / manifest
//   finance_manager       : close completed shipments
// Every action writes shipment_events and mirrors customer tracking state.
// ============================================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/rbac.php';
require_once __DIR__ . '/../includes/shipment_functions.php';

require_login_guard();
$tenant_id = require_tenant_context();
$current_role_type = current_role_type();

// Staff area: generic staff plus all operational sub-roles.
require_staff_subroles(['reception_clerk', 'warehouse_supervisor', 'logistics_supervisor', 'finance_manager', 'clerk']);

ensureShipmentSchema($pdo);
$assigned_branch_id = require_branch_context($pdo);

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function jsonOut(array $d): void { header('Content-Type: application/json'); echo json_encode($d); exit; }
function postInt(string $k, int $def = 0): int { $v = $_POST[$k] ?? $def; return is_numeric($v) ? (int)$v : $def; }
function postStr(string $k, string $def = ''): string { $v = $_POST[$k] ?? $def; return is_array($v) ? $def : trim((string)$v); }

// Branch names lookup for this tenant
$branches_map = [];
try {
    $st = $pdo->prepare("SELECT id, branch_name FROM branches WHERE tenant_id = ?");
    $st->execute([$tenant_id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) { $branches_map[(int)$b['id']] = $b['branch_name']; }
} catch (Throwable $e) {}

// Role capability matrix (server-side enforced per action below)
$caps = [
    'view'            => true,
    'receive'         => in_array($current_role_type, ['warehouse_supervisor'], true),
    'mark_ready'      => in_array($current_role_type, ['warehouse_supervisor'], true),
    'load'            => in_array($current_role_type, ['logistics_supervisor'], true),
    'release_pickup'  => in_array($current_role_type, ['warehouse_supervisor'], true),
    'assign_delivery' => in_array($current_role_type, ['warehouse_supervisor'], true),

    'report_problem'  => in_array($current_role_type, ['warehouse_supervisor', 'logistics_supervisor'], true),
    'close'           => in_array($current_role_type, ['finance_manager'], true),
];

/** Common event context for the current performer. */
function ctxBase(): array {
    return ['performed_by' => $_SESSION['user_id'] ?? null, 'performer_name' => $_SESSION['user_name'] ?? null];
}

/** Normalize domain-service `ok` results to the AJAX/UI `success` contract. */
function jsonResult(array $result): void {
    if (!array_key_exists('success', $result) && array_key_exists('ok', $result)) {
        $result['success'] = (bool)$result['ok'];
    }
    jsonOut($result);
}

// ============================================================================
// AJAX ACTIONS (all server-side authorized + tenant/branch scoped)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    $action = postStr('ajax_action');
    $actor = ['performed_by' => $_SESSION['user_id'] ?? null, 'performer_name' => $_SESSION['user_name'] ?? null];

    if ($action === 'list_shipments') {
        $search = postStr('search');
        $status = postStr('status', 'all');
        $page = max(1, postInt('page', 1));
        $limit = 15; $offset = ($page - 1) * $limit;
        $where = ["s.tenant_id = ?", "(s.current_branch_id = ? OR s.origin_branch_id = ? OR s.destination_branch_id = ?)"];
        $params = [$tenant_id, $assigned_branch_id, $assigned_branch_id, $assigned_branch_id];
        if ($status !== '' && $status !== 'all') { $where[] = "s.current_status = ?"; $params[] = $status; }
        if ($search !== '') {
            $where[] = "(s.shipment_number LIKE ? OR s.tracking_number LIKE ? OR s.cargo_description LIKE ? OR s.receiver_name LIKE ? OR s.sender_name LIKE ?)";
            $like = "%{$search}%";
            array_push($params, $like, $like, $like, $like, $like);
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $cStmt = $pdo->prepare("SELECT COUNT(*) FROM shipments s {$whereSql}");
        $cStmt->execute($params);
        $total = (int)$cStmt->fetchColumn();
        $dStmt = $pdo->prepare("
            SELECT s.* , c.container_number, t.trip_number,
                   ob.branch_name AS origin_name, db.branch_name AS destination_name,
                   ws.zone AS active_zone, ws.bin_location AS active_storage_location
            FROM shipments s
            LEFT JOIN containers c ON c.id = s.current_container_id
            LEFT JOIN trucking_trips t ON t.id = s.current_trip_id
            LEFT JOIN branches ob ON ob.id = s.origin_branch_id
            LEFT JOIN branches db ON db.id = s.destination_branch_id
            LEFT JOIN warehouse_stock ws ON ws.id = s.current_warehouse_stock_id
                AND ws.tenant_id = s.tenant_id AND ws.quantity > 0 AND COALESCE(ws.is_active,1) = 1
            {$whereSql}
            ORDER BY s.created_at DESC, s.id DESC LIMIT {$limit} OFFSET {$offset}");
        $dStmt->execute($params);
        jsonOut(['success' => true, 'rows' => $dStmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total,
                 'page' => $page, 'pages' => (int)ceil($total / $limit)]);
    }

    if ($action === 'get_shipment') {
        $id = postInt('id');
        $st = $pdo->prepare("SELECT s.*, ob.branch_name AS origin_name, db.branch_name AS destination_name,
                                    c.container_number, c.status AS container_status,
                                    t.trip_number, t.status AS trip_status, t.driver_name, t.driver_phone, t.truck_plate,
                                    cu.customer_name, cu.phone AS customer_phone,
                                    ws.zone AS current_stock_zone, ws.bin_location AS current_stock_location,
                                    ws.quantity AS current_stock_qty, ws.is_active AS current_stock_active
                             FROM shipments s
                             LEFT JOIN branches ob ON ob.id = s.origin_branch_id
                             LEFT JOIN branches db ON db.id = s.destination_branch_id
                             LEFT JOIN containers c ON c.id = s.current_container_id
                             LEFT JOIN trucking_trips t ON t.id = s.current_trip_id
                             LEFT JOIN customers cu ON cu.id = s.customer_id
                             LEFT JOIN warehouse_stock ws ON ws.id = s.current_warehouse_stock_id AND ws.tenant_id = s.tenant_id
                             WHERE s.id = ? AND s.tenant_id = ?
                               AND (s.current_branch_id = ? OR s.origin_branch_id = ? OR s.destination_branch_id = ?)");
        $st->execute([$id, $tenant_id, $assigned_branch_id, $assigned_branch_id, $assigned_branch_id]);
        $ship = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ship) jsonOut(['success' => false, 'message' => 'Shipment not found in your branch scope.']);
        $ev = $pdo->prepare("SELECT e.*, b.branch_name FROM shipment_events e LEFT JOIN branches b ON b.id = e.branch_id WHERE e.shipment_id = ? ORDER BY e.created_at ASC, e.id ASC");
        $ev->execute([$id]);
        $ws = $pdo->prepare("SELECT * FROM warehouse_stock WHERE shipment_id = ? AND tenant_id = ? ORDER BY id DESC");
        $ws->execute([$id, $tenant_id]);
        $mi = $pdo->prepare("SELECT cmi.*, c.container_number FROM cargo_manifest_items cmi LEFT JOIN containers c ON c.id = cmi.container_id WHERE cmi.master_shipment_id = ?");

        $mi->execute([$id]);
        $rel = $pdo->prepare("SELECT * FROM shipment_releases WHERE shipment_id = ? AND tenant_id = ? ORDER BY released_at DESC");
        $rel->execute([$id, $tenant_id]);
        jsonOut(['success' => true, 'shipment' => $ship, 'events' => $ev->fetchAll(PDO::FETCH_ASSOC),
                 'stock_rows' => $ws->fetchAll(PDO::FETCH_ASSOC), 'manifest_items' => $mi->fetchAll(PDO::FETCH_ASSOC),
                 'releases' => $rel->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ---- Origin warehouse receiving (Yusuf) -------------------------------
    if ($action === 'receive_warehouse') {
        if (!$caps['receive']) access_denied_redirect();
        $id = postInt('id');
        $zone = postStr('zone'); $storage_location = postStr('storage_location');
        if ($zone === '' || $storage_location === '') jsonOut(['success' => false, 'message' => 'Zone and storage location are required.']);
        $st = $pdo->prepare("SELECT origin_branch_id FROM shipments WHERE id = ? AND tenant_id = ?");
        $st->execute([$id, $tenant_id]);
        $origin = (int)$st->fetchColumn();
        if ($origin !== $assigned_branch_id) jsonOut(['success' => false, 'message' => 'This shipment does not originate from your branch warehouse.']);
        $res = receive_shipment_into_warehouse($id, $assigned_branch_id, $zone, $storage_location,
            array_merge(ctxBase(), ['tenant_id' => $tenant_id, 'branch_name' => $branches_map[$assigned_branch_id] ?? '']));
        jsonResult($res);
    }

    // ---- Ready for loading (Yusuf) ----------------------------------------
    if ($action === 'mark_ready') {
        if (!$caps['mark_ready']) access_denied_redirect();
        $id = postInt('id');
        jsonResult(update_shipment_status($id, 'READY_FOR_LOADING', array_merge(ctxBase(), [
            'tenant_id' => $tenant_id, 'branch_id' => $assigned_branch_id,
            'event_type' => 'READY_FOR_LOADING', 'notes' => 'Marked ready for loading by warehouse.',
        ])));
    }

    // ---- Load into container / manifest (Hodan) ---------------------------
    if ($action === 'load_container') {
        if (!$caps['load']) access_denied_redirect();
        $id = postInt('id'); $container_id = postInt('container_id'); $qty = max(1, postInt('quantity', 1));
        $ct = $pdo->prepare("SELECT current_branch_id, container_number, status FROM containers WHERE id = ? AND tenant_id = ?");
        $ct->execute([$container_id, $tenant_id]);
        $c = $ct->fetch(PDO::FETCH_ASSOC);
        if (!$c || (int)$c['current_branch_id'] !== $assigned_branch_id) {
            jsonOut(['success' => false, 'message' => 'Container not available at your branch.']);
        }
        jsonResult(load_shipment_into_container($id, $container_id, $qty, array_merge(ctxBase(), ['tenant_id' => $tenant_id])));
    }

    // ---- Branch pickup release with proof of collection (Maxamed collects) --
    if ($action === 'release_pickup') {
        if (!$caps['release_pickup']) access_denied_redirect();
        $id = postInt('id');
        $receiver_name = postStr('receiver_name');
        $receiver_phone = postStr('receiver_phone');
        $method = postStr('verification_method', 'authorized');
        $otp_input = postStr('otp_code');
        if (!in_array($method, ['otp','phone','id_reference','authorized'], true)) jsonOut(['success' => false, 'message' => 'Invalid verification method.']);
        if ($receiver_name === '') jsonOut(['success' => false, 'message' => 'Receiver name is required for proof of collection.']);

        // Verify receiver identity against shipment record
        $st = $pdo->prepare("SELECT receiver_name, receiver_phone, current_status FROM shipments WHERE id = ? AND tenant_id = ? FOR UPDATE");
        $st->execute([$id, $tenant_id]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) jsonOut(['success' => false, 'message' => 'Shipment not found.']);
        if ($s['current_status'] !== 'READY_FOR_PICKUP') {
            jsonOut(['success' => false, 'message' => "Shipment is {$s['current_status']}; it must be READY_FOR_PICKUP before release."]);
        }
        if (strcasecmp(trim((string)$s['receiver_name']), $receiver_name) !== 0) {
            jsonOut(['success' => false, 'message' => 'Receiver name does not match shipment record.']);
        }
        if ($method === 'phone' && strcasecmp(trim((string)$s['receiver_phone']), $receiver_phone) !== 0) {
            jsonOut(['success' => false, 'message' => 'Receiver phone verification failed.']);
        }
        if ($method === 'otp' && ($otp_input === '' || strlen($otp_input) < 4)) {
            jsonOut(['success' => false, 'message' => 'A valid OTP code is required.']);
        }

        // Close out warehouse stock (no longer physically available) and keep
        // a cargo movement audit trail. This is shipment cargo leaving the
        // warehouse, not retail inventory becoming low/out of stock.
        $activeStockStmt = $pdo->prepare("SELECT id, quantity, zone, bin_location FROM warehouse_stock WHERE shipment_id = ? AND tenant_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1");
        $activeStockStmt->execute([$id, $tenant_id]);
        $activeStock = $activeStockStmt->fetch(PDO::FETCH_ASSOC);
        if ($activeStock && (int)$activeStock['quantity'] > 0) {
            record_stock_movement([
                'tenant_id' => $tenant_id,
                'warehouse_stock_id' => (int)$activeStock['id'],
                'quantity_change' => -(int)$activeStock['quantity'],
                'previous_quantity' => (int)$activeStock['quantity'],
                'new_quantity' => 0,
                'movement_type' => 'out',
                'movement_event' => 'released_pickup',
                'from_location' => trim(((string)($branches_map[$assigned_branch_id] ?? '')) . ' Warehouse · ' . (($activeStock['zone'] ?? '') ?: '-') . ' / ' . (($activeStock['bin_location'] ?? '') ?: '-')),
                'to_location' => $receiver_name,
                'reference_type' => 'shipment',
                'reference_id' => $id,
                'reference_label' => $s['shipment_number'] ?? null,
                'notes' => "RELEASE: Warehouse to receiver {$receiver_name}",
                'created_by' => $_SESSION['user_id'] ?? null,
            ]);
        }
        $pdo->prepare("UPDATE warehouse_stock SET is_active = 0, quantity = 0,
                        mogadishu_status = 'delivered',
                        notes = CONCAT(COALESCE(notes,''), ' [released to receiver]')
                        WHERE shipment_id = ? AND tenant_id = ? AND is_active = 1")
            ->execute([$id, $tenant_id]);

        // Proof-of-collection record (raw OTP never stored - only a hash)
        $qtyStmt = $pdo->prepare("SELECT quantity FROM shipments WHERE id = ?");
        $qtyStmt->execute([$id]);
        $relQty = max(1, (int)$qtyStmt->fetchColumn());
        $ins = $pdo->prepare("INSERT INTO shipment_releases
            (tenant_id, shipment_id, release_type, receiver_name, receiver_phone,
             verification_method, otp_code_hash, quantity_released, released_by,
             released_by_name, branch_id, notes, released_at)
             VALUES (?,?,'pickup',?,?,?,?,?,?,?,?,?,NOW())");
        $ins->execute([$tenant_id, $id, $receiver_name, $receiver_phone ?: null,
            $method, $otp_input !== '' ? password_hash($otp_input, PASSWORD_DEFAULT) : null,
            $relQty, $_SESSION['user_id'] ?? null, $_SESSION['user_name'] ?? null,
            $assigned_branch_id, postStr('notes') ?: null]);

        $res = update_shipment_status($id, 'DELIVERED', array_merge(ctxBase(), [
            'tenant_id' => $tenant_id, 'branch_id' => $assigned_branch_id,
            'event_type' => 'PICKUP_RELEASED',
            'notes' => "Collected by {$receiver_name}. Verification: {$method}. Quantity released: {$relQty}.",
        ]));
        jsonResult($res);
    }

    // ---- Report damage / hold ---------------------------------------------
    if ($action === 'report_problem') {
        if (!$caps['report_problem']) access_denied_redirect();
        $id = postInt('id'); $kind = postStr('kind', 'ON_HOLD'); $note = postStr('notes');
        if (!in_array($kind, ['ON_HOLD', 'DAMAGED'], true)) jsonOut(['success' => false, 'message' => 'Invalid report type.']);
        jsonResult(update_shipment_status($id, $kind, array_merge(ctxBase(), [
            'tenant_id' => $tenant_id, 'branch_id' => $assigned_branch_id, 'force' => true,
            'event_type' => 'REPORTED_' . $kind, 'notes' => $note ?: null,
        ])));
    }

    // ---- Finance closes the lifecycle --------------------------------------
    if ($action === 'close_shipment') {
        if (!$caps['close']) access_denied_redirect();
        $id = postInt('id');
        $st = $pdo->prepare("SELECT current_status, current_trip_id, customer_id FROM shipments WHERE id = ? AND tenant_id = ?");
        $st->execute([$id, $tenant_id]);
        $shipToClose = $st->fetch(PDO::FETCH_ASSOC);
        if (!$shipToClose || (string)$shipToClose['current_status'] !== 'DELIVERED') {
            jsonOut(['success' => false, 'message' => 'Only DELIVERED shipments can be closed.']);
        }
        // Payment timing remains flexible (origin, dispatch, destination, or
        // credit). At closure, however, any active invoice that actually
        // covers this shipment's trip/customer must be settled or cancelled.
        if (!empty($shipToClose['current_trip_id']) && !empty($shipToClose['customer_id'])) {
            $due = $pdo->prepare("SELECT COUNT(*) FROM invoices
                                  WHERE tenant_id = ? AND trip_id = ? AND customer_id = ?
                                    AND COALESCE(is_active,1) = 1 AND status <> 'cancelled'
                                    AND (status <> 'paid' OR COALESCE(paid_amount,0) + 0.005 < total_amount)");
            $due->execute([$tenant_id, $shipToClose['current_trip_id'], $shipToClose['customer_id']]);
            if ((int)$due->fetchColumn() > 0) {
                jsonOut(['success' => false, 'message' => 'Outstanding trip invoice must be settled before closing this shipment.']);
            }
        }
        jsonResult(update_shipment_status($id, 'CLOSED', array_merge(ctxBase(), [
            'tenant_id' => $tenant_id, 'branch_id' => $assigned_branch_id,
            'event_type' => 'CLOSED', 'notes' => 'Lifecycle closed after delivery and finance completion.',
        ])));
    }

    // ---- Ready for pickup at destination warehouse -------------------------
    if ($action === 'mark_ready_pickup') {
        if (!$caps['release_pickup']) access_denied_redirect();
        $id = postInt('id');
        $st = $pdo->prepare("SELECT current_status, destination_branch_id FROM shipments WHERE id = ? AND tenant_id = ?");
        $st->execute([$id, $tenant_id]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s || (int)$s['destination_branch_id'] !== $assigned_branch_id) {
            jsonOut(['success' => false, 'message' => 'Shipment is not destined for your branch.']);
        }
        if ($s['current_status'] !== 'IN_DESTINATION_WAREHOUSE') {
            jsonOut(['success' => false, 'message' => "Shipment is {$s['current_status']}; receive it into the destination warehouse first."]);
        }
        jsonResult(update_shipment_status($id, 'READY_FOR_PICKUP', array_merge(ctxBase(), [
            'tenant_id' => $tenant_id, 'branch_id' => $assigned_branch_id,
            'event_type' => 'READY_FOR_PICKUP', 'notes' => 'Marked ready for customer pickup.',
        ])));
    }

    // ---- Assign last-mile delivery to a courier (DEL-xxxx) -----------------
    if ($action === 'assign_delivery') {
        if (!$caps['assign_delivery']) access_denied_redirect();
        $id = postInt('id');
        $agent_id = postInt('agent_id');
        $address = postStr('delivery_address');
        if ($agent_id <= 0) jsonOut(['success' => false, 'message' => 'Select a delivery agent.']);

        $st = $pdo->prepare("SELECT * FROM shipments WHERE id = ? AND tenant_id = ? AND destination_branch_id = ? FOR UPDATE");
        $st->execute([$id, $tenant_id, $assigned_branch_id]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) jsonOut(['success' => false, 'message' => 'Shipment is not at your destination branch.']);
        if (!in_array($s['current_status'], ['IN_DESTINATION_WAREHOUSE', 'READY_FOR_PICKUP'], true)) {
            jsonOut(['success' => false, 'message' => "Shipment is {$s['current_status']}; it must be received into your warehouse first."]);
        }
        // Agent must be an active delivery agent of this tenant
        $ag = $pdo->prepare("SELECT id, full_name FROM users WHERE id = ? AND tenant_id = ? AND is_active = 1 AND role_type = 'delivery_agent'");
        $ag->execute([$agent_id, $tenant_id]);
        $agent = $ag->fetch(PDO::FETCH_ASSOC);
        if (!$agent) jsonOut(['success' => false, 'message' => 'Delivery agent not found for your company.']);

        require_once __DIR__ . '/../includes/shipment_functions.php';
        $num = generate_delivery_assignment_number($pdo, $tenant_id);
        $ins = $pdo->prepare("INSERT INTO delivery_assignments
            (tenant_id, assignment_number, shipment_id, branch_id, assigned_to,
             receiver_name, receiver_phone, delivery_address, status, assigned_by, created_at)
            VALUES (?,?,?,?,?,?,?,?, 'assigned', ?, NOW())");
        $ins->execute([$tenant_id, $num, $id, $assigned_branch_id, $agent_id,
            $s['receiver_name'], $s['receiver_phone'], $address ?: $s['receiver_address'],
            $_SESSION['user_id'] ?? null]);

        log_shipment_event(array_merge(ctxBase(), [
            'tenant_id' => $tenant_id, 'shipment_id' => $id,
            'event_type' => 'DELIVERY_ASSIGNED', 'new_status' => $s['current_status'],
            'branch_id' => $assigned_branch_id,
            'notes' => "Assigned to courier {$agent['full_name']} as {$num} for door delivery.",
        ]));
        jsonOut(['success' => true, 'message' => "Delivery {$num} assigned to {$agent['full_name']}."]);
    }

    // ---- Active couriers of this tenant -------------------------------------
    if ($action === 'get_agents') {
        if (!$caps['assign_delivery']) access_denied_redirect();
        $st = $pdo->prepare("SELECT id, full_name FROM users WHERE tenant_id = ? AND is_active = 1 AND role_type = 'delivery_agent' ORDER BY full_name");
        $st->execute([$tenant_id]);
        jsonOut(['success' => true, 'agents' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ---- Container options for the loading modal ---------------------------
    if ($action === 'get_branch_containers') {
        $st = $pdo->prepare("
            SELECT c.id, c.container_number, c.status, c.size_cbm,
                   COALESCE(SUM(cmi.cbm_used),0) AS used_cbm,
                   COALESCE(SUM(cmi.quantity),0) AS total_quantity
            FROM containers c
            LEFT JOIN cargo_manifest_items cmi ON cmi.container_id = c.id AND cmi.tenant_id = c.tenant_id
            WHERE c.tenant_id = ? AND c.current_branch_id = ? AND c.is_active = 1
              AND c.status IN ('received','loading','loaded')
              AND NOT EXISTS (
                  SELECT 1 FROM trucking_trips t
                  WHERE t.container_id = c.id AND t.tenant_id = c.tenant_id
                    AND t.status IN ('delivered','completed')
              )
            GROUP BY c.id
            ORDER BY c.created_at DESC");
        $st->execute([$tenant_id, $assigned_branch_id]);
        jsonOut(['success' => true, 'containers' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    jsonOut(['success' => false, 'message' => 'Unknown action.']);
}

// ============================================================================
// UI
// ============================================================================
require_once __DIR__ . '/../includes/header.php';
$status_labels = shipment_status_labels();
$flash = $_SESSION['flash_message'] ?? null;
unset($_SESSION['flash_message'], $_SESSION['flash_type']);
?>
<div class="container-fluid">
    <?php if (($_GET['error'] ?? '') === 'access_denied' || $flash): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-ban"></i> <?= h($flash ?: 'You do not have permission to access that page.') ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><i class="fas fa-boxes text-primary"></i> Shipments
            <small class="text-muted">— <?= h($branches_map[$assigned_branch_id] ?? 'Branch') ?></small></h2>
        <span class="badge badge-info"><?= h(ucfirst(str_replace('_', ' ', $current_role_type))) ?></span>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="row mb-2">
                <div class="col-md-5"><input type="text" id="searchInput" class="form-control" placeholder="Search SHP-xxxx / tracking / cargo / receiver..."></div>
                <div class="col-md-4">
                    <select id="statusFilter" class="form-control">
                        <option value="all">All statuses</option>
                        <?php foreach ($status_labels as $k => $lbl): ?>
                            <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 text-right">
                    <button class="btn btn-outline-primary" id="refreshBtn"><i class="fas fa-sync"></i> Refresh</button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-sm" id="shipmentsTable">
                    <thead class="bg-light">
                        <tr>
                            <th>Shipment</th><th>Tracking</th><th>Cargo</th><th>Qty</th>
                            <th>Route</th><th>Status</th><th>Container</th><th>Trip</th><th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div id="paginationBox" class="mt-2"></div>
        </div>
    </div>
</div>

<!-- Shipment details modal -->
<div class="modal fade" id="detailModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Shipment Lifecycle</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button></div>
      <div class="modal-body" id="detailBody"></div>
    </div>
  </div>
</div>

<!-- Receive into warehouse -->
<div class="modal fade" id="receiveModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="receiveForm">
      <input type="hidden" name="ajax_action" value="receive_warehouse">
      <input type="hidden" name="id" id="receiveId">
      <div class="modal-header"><h5 class="modal-title">Receive into Warehouse</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button></div>
      <div class="modal-body">
        <div class="form-group"><label>Zone</label><input name="zone" class="form-control" placeholder="e.g. B, Back, Left Side" required></div>
        <div class="form-group"><label>Storage Location</label><input name="storage_location" class="form-control" placeholder="e.g. B-05, Corner 1, Near Table 2" required>
          <small class="text-muted">Internal physical location inside this warehouse.</small></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button class="btn btn-primary">Receive &amp; Store</button>
      </div>
    </form>
  </div>
</div>

<!-- Load into container -->
<div class="modal fade" id="loadModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="loadForm">
      <input type="hidden" name="ajax_action" value="load_container">
      <input type="hidden" name="id" id="loadId">
      <div class="modal-header"><h5 class="modal-title">Load into Container / Manifest</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button></div>
      <div class="modal-body">
        <div class="form-group"><label>Container (at your branch)</label><select name="container_id" id="loadContainer" class="form-control" required></select></div>
        <small id="noContainerHint" class="form-text text-muted" style="display:none;">No eligible loading container is available. Create a new container first, then return here to load this shipment.</small>
        <div class="form-group"><label>Quantity to load</label><input type="number" min="1" name="quantity" id="loadQty" class="form-control" required></div>
      </div>
      <div class="modal-footer">
        <a class="btn btn-outline-primary mr-auto" href="containers.php"><i class="fas fa-plus-circle"></i> Create Container</a>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button class="btn btn-primary">Add to Manifest</button>
      </div>
    </form>
  </div>
</div>

<!-- Assign last-mile delivery -->
<div class="modal fade" id="deliverModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="deliverForm">
      <input type="hidden" name="ajax_action" value="assign_delivery">
      <input type="hidden" name="id" id="deliverId">
      <div class="modal-header"><h5 class="modal-title">Assign Door Delivery (DEL-xxxx)</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button></div>
      <div class="modal-body">
        <div class="form-group"><label>Delivery Agent / Courier</label><select name="agent_id" id="deliverAgent" class="form-control" required></select></div>
        <div class="form-group"><label>Delivery Address</label><textarea name="delivery_address" class="form-control" rows="2"></textarea></div>
        <small class="text-muted">The courier will collect the shipment from this warehouse and confirm delivery with proof of receipt.</small>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button class="btn btn-dark">Assign Delivery</button>
      </div>
    </form>
  </div>
</div>

<!-- Release for pickup -->
<div class="modal fade" id="releaseModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="releaseForm">
      <input type="hidden" name="ajax_action" value="release_pickup">
      <input type="hidden" name="id" id="releaseId">
      <div class="modal-header"><h5 class="modal-title">Release — Proof of Collection</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button></div>
      <div class="modal-body">
        <div class="form-group"><label>Receiver Name</label><input name="receiver_name" class="form-control" required></div>
        <div class="form-group"><label>Receiver Phone</label><input name="receiver_phone" class="form-control"></div>
        <div class="form-group"><label>Verification Method</label>
          <select name="verification_method" id="verMethod" class="form-control">
            <option value="otp">OTP Code</option>
            <option value="phone">Phone Verification</option>
            <option value="id_reference">ID / Reference</option>
            <option value="authorized">Authorized Receiver Confirmation</option>
          </select></div>
        <div class="form-group" id="otpGroup" style="display:none"><label>OTP Code</label><input name="otp_code" class="form-control"></div>
        <div class="form-group"><label>Notes</label><textarea name="notes" class="form-control"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button class="btn btn-success">Confirm Release</button>
      </div>
    </form>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script>
const statusLabels = <?= json_encode($status_labels) ?>;
let currentPage = 1;

function toast(msg, ok = true) {
    alert((ok ? '' : 'ERROR: ') + msg);
}
function esc(s){ const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

function loadShipments(page = 1) {
    currentPage = page;
    $.post('', { ajax_action: 'list_shipments', page: page,
        search: $('#searchInput').val(), status: $('#statusFilter').val() }, function(res) {
        if (!res.success) { toast(res.message, false); return; }
        let html = '';
        res.rows.forEach(r => {
            const storage = [r.active_zone, r.active_storage_location].filter(Boolean).join(' / ');
            html += `<tr>
                <td><strong>${esc(r.shipment_number)}</strong></td>
                <td>${esc(r.tracking_number || '')}</td>
                <td>${esc(r.cargo_description || '')}${storage ? `<br><small class="text-muted">${esc(storage)}</small>` : ''}</td>
                <td>${r.quantity}</td>
                <td>${esc(r.origin_name || '')} → ${esc(r.destination_name || '')}</td>
                <td><span class="badge badge-secondary">${esc(statusLabels[r.current_status] || r.current_status)}</span></td>
                <td>${esc(r.container_number || '')}</td>
                <td>${esc(r.trip_number || '')}</td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-primary view-shipment" data-id="${r.id}"><i class="fas fa-eye"></i></button>
                    <?php if ($caps['receive']): ?>
                      ${['RECEIVED','REGISTERED'].includes(r.current_status) ? `<button class="btn btn-sm btn-outline-success receive-btn" data-id="${r.id}"><i class="fas fa-warehouse"></i> Receive Into Warehouse</button>` : ''}
                    <?php endif; ?>
                    <?php if ($caps['mark_ready']): ?>
                      ${r.current_status === 'IN_ORIGIN_WAREHOUSE' ? `<button class="btn btn-sm btn-outline-warning ready-btn" data-id="${r.id}">Mark Ready for Loading</button>` : ''}
                    <?php endif; ?>
                    <?php if ($caps['load']): ?>
                      ${['IN_ORIGIN_WAREHOUSE','READY_FOR_LOADING'].includes(r.current_status) ? `<button class="btn btn-sm btn-outline-info load-btn" data-id="${r.id}"><i class="fas fa-truck-loading"></i></button>` : ''}
                    <?php endif; ?>
                    <?php if ($caps['release_pickup']): ?>
                      ${['IN_DESTINATION_WAREHOUSE'].includes(r.current_status) && r.destination_branch_id == <?= (int)$assigned_branch_id ?> ? `<button class="btn btn-sm btn-outline-secondary pickupready-btn" data-id="${r.id}">Ready for Pickup</button>` : ''}
                      ${r.current_status === 'READY_FOR_PICKUP' ? `<button class="btn btn-sm btn-success release-btn" data-id="${r.id}" data-receiver="${esc(r.receiver_name||'')}" data-phone="${esc(r.receiver_phone||'')}">Release</button>` : ''}
                    <?php endif; ?>

                    <?php if ($caps['assign_delivery']): ?>
                      ${['IN_DESTINATION_WAREHOUSE','READY_FOR_PICKUP'].includes(r.current_status) ? `<button class="btn btn-sm btn-outline-dark deliver-btn" data-id="${r.id}" title="Assign courier for door delivery"><i class="fas fa-motorcycle"></i></button>` : ''}
                    <?php endif; ?>

                    <?php if ($caps['close']): ?>
                      ${r.current_status === 'DELIVERED' ? `<button class="btn btn-sm btn-dark close-btn" data-id="${r.id}">Close</button>` : ''}
                    <?php endif; ?>
                </td>
            </tr>`;
        });
        $('#shipmentsTable tbody').html(html || '<tr><td colspan="9" class="text-center text-muted">No shipments found.</td></tr>');
        let pg = '';
        for (let i = 1; i <= res.pages; i++) {
            pg += `<li class="page-item ${i===res.page?'active':''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
        }
        $('#paginationBox').html(`<ul class="pagination pagination-sm">${pg}</ul>`);
    }, 'json').fail(() => toast('Server error.', false));
}

function renderDetail(d) {
    const s = d.shipment;
    const statusLabels2 = statusLabels;
    let evHtml = '';
    d.events.forEach(e => {
        evHtml += `<li class="list-group-item py-1">
            <small class="text-muted">${esc(e.created_at)}</small> —
            <strong>${esc(e.event_type)}</strong>
            ${e.new_status ? '(' + esc(statusLabels2[e.new_status] || e.new_status) + ')' : ''}
            ${e.location_label ? '@ ' + esc(e.location_label) : ''}
            ${e.performer_name ? ' by ' + esc(e.performer_name) : ''}
            ${e.notes ? '<br><small>' + esc(e.notes) + '</small>' : ''}
        </li>`;
    });
    let stockHtml = d.stock_rows.map(w =>
        `<span class="badge badge-light mr-1">#${w.id} qty ${w.quantity} ${esc(w.zone||'')} ${esc(w.bin_location||'')} ${w.is_active==1?'ACTIVE':'closed'}</span>`).join('');
    let manHtml = d.manifest_items.map(m =>
        `<span class="badge badge-light mr-1">${esc(m.container_number||('CONT#'+m.container_id))}: ${m.quantity}</span>`).join('');
    let relHtml = d.releases.map(r =>
        `<li class="list-group-item py-1"><small>${esc(r.released_at)}</small> — ${esc(r.release_type)} to
         <strong>${esc(r.receiver_name)}</strong>, verified via ${esc(r.verification_method)},
         qty ${r.quantity_released}, by ${esc(r.released_by_name||'')}</li>`).join('');
    $('#detailBody').html(`
      <h5>${esc(s.shipment_number)} <small class="text-muted">${esc(s.tracking_number||'')}</small></h5>
      <p class="mb-2">
        <strong>Customer:</strong> ${esc(s.customer_name||'')} (${esc(s.customer_phone||'')})<br>
        <strong>Sender:</strong> ${esc(s.sender_name||'')} &nbsp; <strong>Receiver:</strong> ${esc(s.receiver_name||'')} ${esc(s.receiver_phone||'')}<br>
        <strong>Cargo:</strong> ${esc(s.cargo_description||'')} — ${s.quantity} pcs / ${s.weight_kg} kg /
        ${(parseFloat(s.volume_cbm)||0).toFixed(2)} CBM<br>
        <strong>Route:</strong> ${esc(s.origin_name||'')} → ${esc(s.destination_name||'')}<br>
        <strong>Status:</strong> ${esc(statusLabels2[s.current_status]||s.current_status)}
      </p>
      <div class="mb-2">${stockHtml}<br>${manHtml}</div>
      ${relHtml ? '<h6>Proof of Collection / Delivery</h6><ul class="list-group mb-2">' + relHtml + '</ul>' : ''}
      <h6>Lifecycle History</h6>
      <ul class="list-group" style="max-height:300px;overflow:auto;">${evHtml}</ul>`);
}

function renderDetailV2(d) {
    const s = d.shipment;
    const eventLabels = {
        REPAIR_INVALID_COMPLETED_CONTAINER_LINK: 'Repair Invalid Completed Container Link',
        LOADED_INTO_CONTAINER: 'Loaded into Container',
        ASSIGNED_TO_TRIP: 'Assigned to Trip',
        DISPATCH_APPROVED: 'Dispatch Approved',
        TRIP_IN_TRANSIT: 'Trip In Transit',
        WAREHOUSE_RECEIVED: 'Warehouse Received',
        READY_FOR_LOADING: 'Ready for Loading',
        STATUS_READY_FOR_LOADING: 'Ready for Loading',
        RECEIVED_AT_DESTINATION_WAREHOUSE: 'Received at Destination Warehouse',
        ARRIVED_AT_DESTINATION: 'Arrived at Destination',
        PICKUP_RELEASED: 'Pickup Released',
        DELIVERY_ASSIGNED: 'Delivery Assigned'
    };
    const prettyEvent = code => eventLabels[code] || String(code || '').replaceAll('_', ' ').toLowerCase().replace(/\b\w/g, c => c.toUpperCase());
    const qtyUnit = s.quantity_unit || 'Cartons';
    const cbm = parseFloat(s.volume_cbm || 0);
    const cbmText = cbm > 0 ? cbm.toFixed(2) + ' CBM' : 'Not provided';
    const storageParts = [s.current_stock_zone || s.storage_zone, s.current_stock_location || s.storage_rack].filter(Boolean);
    const storageText = storageParts.length ? storageParts.join(' / ') : '-';
    const statusText = statusLabels[s.current_status] || s.current_status;
    const events = (d.events || []).map(e => `<li class="list-group-item py-1">
        <small class="text-muted">${esc(e.created_at)}</small> -
        <strong>${esc(prettyEvent(e.event_type))}</strong>
        ${e.new_status ? '(' + esc(statusLabels[e.new_status] || e.new_status) + ')' : ''}
        ${e.location_label ? '@ ' + esc(e.location_label) : ''}
        ${e.performer_name ? ' by ' + esc(e.performer_name) : ''}
        ${e.notes ? '<br><small>' + esc(e.notes) + '</small>' : ''}
    </li>`).join('');
    const releases = (d.releases || []).map(r => `<li class="list-group-item py-1">
        <small>${esc(r.released_at)}</small> - ${esc(r.release_type)} to
        <strong>${esc(r.receiver_name)}</strong>, verified via ${esc(r.verification_method)},
        qty ${r.quantity_released}, by ${esc(r.released_by_name || '')}
    </li>`).join('');
    $('#detailBody').html(`
      <div class="row">
        <div class="col-md-6 mb-2"><strong>Shipment</strong><br>${esc(s.shipment_number)}</div>
        <div class="col-md-6 mb-2"><strong>Tracking</strong><br>${esc(s.tracking_number || '-')}</div>
        <div class="col-md-6 mb-2"><strong>Customer</strong><br>${esc(s.customer_name || '-')}</div>
        <div class="col-md-6 mb-2"><strong>Receiver</strong><br>${esc(s.receiver_name || '-')} ${s.receiver_phone ? '&middot; ' + esc(s.receiver_phone) : ''}</div>
        <div class="col-md-6 mb-2"><strong>Cargo</strong><br>${esc(s.cargo_description || '-')}</div>
        <div class="col-md-6 mb-2"><strong>Quantity</strong><br>${esc(s.quantity)} ${esc(qtyUnit)}</div>
        <div class="col-md-6 mb-2"><strong>Weight</strong><br>${Number(s.weight_kg || 0).toFixed(2)} KG</div>
        <div class="col-md-6 mb-2"><strong>CBM</strong><br>${esc(cbmText)}</div>
        <div class="col-md-6 mb-2"><strong>Route</strong><br>${esc(s.origin_name || '-')} &rarr; ${esc(s.destination_name || '-')}</div>
        <div class="col-md-6 mb-2"><strong>Current Status</strong><br>${esc(statusText)}</div>
        <div class="col-md-6 mb-2"><strong>Origin Warehouse</strong><br>${esc(s.origin_name || '-')} Warehouse</div>
        <div class="col-md-6 mb-2"><strong>Origin Storage Location</strong><br>${esc(storageText)}</div>
        <div class="col-md-6 mb-2"><strong>Container</strong><br>${esc(s.container_number || '-')} ${s.container_status ? '(' + esc(s.container_status) + ')' : ''}</div>
        <div class="col-md-6 mb-2"><strong>Trip</strong><br>${esc(s.trip_number || '-')} ${s.trip_status ? '(' + esc(s.trip_status) + ')' : ''}</div>
        <div class="col-md-6 mb-2"><strong>Driver</strong><br>${esc(s.driver_name || '-')} ${s.driver_phone ? '&middot; ' + esc(s.driver_phone) : ''}</div>
        <div class="col-md-6 mb-2"><strong>Truck</strong><br>${esc(s.truck_plate || '-')}</div>
      </div>
      ${releases ? '<h6>Proof of Collection / Delivery</h6><ul class="list-group mb-2">' + releases + '</ul>' : ''}
      <h6>Lifecycle History</h6>
      <ul class="list-group" style="max-height:300px;overflow:auto;">${events}</ul>`);
}

$(function() {
    loadShipments();
    let timer = null;
    $('#searchInput').on('keyup', function(){ clearTimeout(timer); timer = setTimeout(()=>loadShipments(1), 350); });
    $('#statusFilter,#refreshBtn').on('change click', () => loadShipments(1));
    $(document).on('click', '#paginationBox .page-link', function(e){ e.preventDefault(); loadShipments($(this).data('page')); });

    $(document).on('click', '.view-shipment', function(){
        $.post('', { ajax_action:'get_shipment', id: $(this).data('id') }, function(res){
            if (!res.success) { toast(res.message, false); return; }
            renderDetailV2(res);
            $('#detailModal').modal('show');
        }, 'json');
    });
    $(document).on('click', '.receive-btn', function(){
        $('#receiveId').val($(this).data('id'));
        $('#receiveModal').modal('show');
    });
    $('#receiveForm').on('submit', function(e){
        e.preventDefault();
        $.post('', $(this).serialize(), function(res){
            toast(res.message || (res.success ? 'Stored.' : 'Error'), !!res.success);
            if (res.success) { $('#receiveModal').modal('hide'); loadShipments(currentPage); }
        }, 'json').fail(()=>toast('Server error.', false));
    });
    $(document).on('click', '.ready-btn', function(){
        if (!confirm('Mark this shipment READY FOR LOADING?')) return;
        $.post('', { ajax_action:'mark_ready', id: $(this).data('id') }, function(res){
            toast(res.message, !!res.success); if (res.success) loadShipments(currentPage);
        }, 'json');
    });
    $(document).on('click', '.load-btn', function(){
        $('#loadId').val($(this).data('id'));
        $.post('', { ajax_action:'get_branch_containers' }, function(res){
            if (!res.success) { toast(res.message, false); return; }
            let opts = '<option value="">Select container...</option>';
            res.containers.forEach(c => { opts += `<option value="${c.id}">${esc(c.container_number)} (${esc(c.status)})</option>`; });
            $('#loadContainer').html(opts);
            $('#noContainerHint').toggle((res.containers || []).length === 0);
            $('#loadModal').modal('show');
        }, 'json');
    });
    $(document).on('click', '.pickupready-btn', function(){
        $.post('', { ajax_action:'mark_ready_pickup', id: $(this).data('id') }, function(res){
            toast(res.message, !!res.success); if (res.success) loadShipments(currentPage);
        }, 'json');
    });
    $(document).on('click', '.release-btn', function(){

        $('#releaseId').val($(this).data('id'));
        $('input[name=receiver_name]').val($(this).data('receiver'));
        $('input[name=receiver_phone]').val($(this).data('phone'));
        $('#releaseModal').modal('show');
    });
    $('#verMethod').on('change', function(){ $('#otpGroup').toggle($(this).val() === 'otp'); }).change();
    $('#releaseForm').on('submit', function(e){
        e.preventDefault();
        $.post('', $(this).serialize(), function(res){
            toast(res.message, !!res.success);
            if (res.success) { $('#releaseModal').modal('hide'); loadShipments(currentPage); }
        }, 'json').fail(()=>toast('Server error.', false));
    });
    $(document).on('click', '.deliver-btn', function(){
        $('#deliverId').val($(this).data('id'));
        $.post('', { ajax_action:'get_agents' }, function(res){
            if (!res.success) { toast(res.message, false); return; }
            let opts = '<option value="">Select courier...</option>';
            res.agents.forEach(a => { opts += `<option value="${a.id}">${esc(a.full_name)}</option>`; });
            $('#deliverAgent').html(opts);
            $('#deliverModal').modal('show');
        }, 'json');
    });
    $('#deliverForm').on('submit', function(e){
        e.preventDefault();
        $.post('', $(this).serialize(), function(res){
            toast(res.message, !!res.success);
            if (res.success) { $('#deliverModal').modal('hide'); loadShipments(currentPage); }
        }, 'json').fail(()=>toast('Server error.', false));
    });
    $(document).on('click', '.close-btn', function(){
        if (!confirm('Close this shipment lifecycle?')) return;
        $.post('', { ajax_action:'close_shipment', id: $(this).data('id') }, function(res){
            toast(res.message, !!res.success); if (res.success) loadShipments(currentPage);
        }, 'json');
    });
});

</script>
</body>
</html>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
