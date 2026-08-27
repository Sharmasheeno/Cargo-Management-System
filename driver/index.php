<?php
// ============================================================================
// driver/index.php — Driver Dashboard / My Trips
// Driver auth is users.id -> drivers.user_id -> trucking_trips.driver_id.
// A driver can only view/update trips assigned to their driver profile.
// ============================================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/rbac.php';
require_once __DIR__ . '/../includes/shipment_functions.php';

require_driver();
$tenant_id = require_tenant_context();
$driver_user_id = (int)$_SESSION['user_id'];

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function jsonOut(array $d): void { header('Content-Type: application/json'); echo json_encode($d); exit; }
function postInt(string $k, int $def = 0): int { $v = $_POST[$k] ?? $def; return is_numeric($v) ? (int)$v : $def; }
function postStr(string $k, string $def = ''): string { $v = $_POST[$k] ?? $def; return is_array($v) ? $def : trim((string)$v); }

ensureShipmentSchema($pdo);

$driverProfileStmt = $pdo->prepare("
    SELECT id, full_name, phone
    FROM drivers
    WHERE tenant_id = ? AND user_id = ? AND is_active = 1
    LIMIT 1
");
$driverProfileStmt->execute([$tenant_id, $driver_user_id]);
$driver_profile = $driverProfileStmt->fetch(PDO::FETCH_ASSOC);
$driver_profile_id = (int)($driver_profile['id'] ?? 0);

function driver_status_label(string $status): string {
    return [
        'pending' => 'Pending',
        'received' => 'Pending',
        'loading' => 'Ready for Dispatch',
        'loaded' => 'Ready for Dispatch',
        'in_transit' => 'In Transit',
        'delivered' => 'Arrived at Destination',
        'completed' => 'Completed',
    ][$status] ?? ucwords(str_replace('_', ' ', $status));
}

function driver_status_filter_sql(string $filter): array {
    $filter = strtolower(trim($filter));
    return match ($filter) {
        'pending' => ["t.status IN ('pending','received')", []],
        'ready' => ["t.status IN ('loading','loaded')", []],
        'in_transit' => ["t.status = 'in_transit'", []],
        'arrived' => ["t.status = 'delivered'", []],
        'completed' => ["t.status = 'completed'", []],
        default => ['1=1', []],
    };
}

function owned_trip(PDO $pdo, int $tenant_id, int $driver_profile_id, int $trip_id, bool $forUpdate = false): ?array {
    $sql = "
        SELECT t.*, c.container_number, c.status AS container_status,
               ob.branch_name AS origin_name, db.branch_name AS destination_name
        FROM trucking_trips t
        LEFT JOIN containers c ON c.id = t.container_id AND c.tenant_id = t.tenant_id
        LEFT JOIN branches ob ON ob.id = t.from_branch_id
        LEFT JOIN branches db ON db.id = t.to_branch_id
        WHERE t.id = ? AND t.tenant_id = ? AND t.driver_id = ?
        LIMIT 1" . ($forUpdate ? " FOR UPDATE" : "");
    $st = $pdo->prepare($sql);
    $st->execute([$trip_id, $tenant_id, $driver_profile_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function audit_driver_action(PDO $pdo, int $tenant_id, int $user_id, string $action, string $table, int $recordId, array $old, array $new): void {
    try {
        $st = $pdo->prepare("INSERT INTO audit_logs (tenant_id, user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at)
                             VALUES (?,?,?,?,?,?,?,?,?,NOW())");
        $st->execute([
            $tenant_id, $user_id, $action, $table, $recordId,
            json_encode($old), json_encode($new),
            $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Throwable $e) {}
}

function trip_manifest(PDO $pdo, int $tenant_id, int $container_id): array {
    if ($container_id <= 0) return [];
    $st = $pdo->prepare("
        SELECT s.id, s.shipment_number, s.tracking_number, s.cargo_description,
               s.quantity, s.quantity_unit, s.weight_kg, s.receiver_name, s.receiver_phone,
               s.destination_branch_id, b.branch_name AS destination_name
        FROM cargo_manifest_items cmi
        JOIN shipments s ON s.id = cmi.master_shipment_id
        LEFT JOIN branches b ON b.id = s.destination_branch_id
        WHERE cmi.tenant_id = ? AND cmi.container_id = ? AND s.is_active = 1
        ORDER BY s.shipment_number ASC
    ");
    $st->execute([$tenant_id, $container_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    if ($driver_profile_id <= 0) jsonOut(['success' => false, 'message' => 'No active driver profile is linked to this login.']);

    $action = postStr('ajax_action');

    if ($action === 'my_trips') {
        $filter = postStr('filter', 'all');
        $search = postStr('search');
        [$statusSql, $statusParams] = driver_status_filter_sql($filter);

        $params = [$tenant_id, $driver_profile_id, ...$statusParams];
        $searchSql = '';
        if ($search !== '') {
            $searchSql = " AND (t.trip_number LIKE ? OR c.container_number LIKE ? OR t.truck_plate LIKE ? OR ob.branch_name LIKE ? OR db.branch_name LIKE ? OR s.shipment_number LIKE ? OR s.tracking_number LIKE ? OR s.cargo_description LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        $st = $pdo->prepare("
            SELECT t.id, t.trip_number, t.status, t.approval_status, t.departed_at, t.arrived_at,
                   t.truck_plate, c.container_number,
                   ob.branch_name AS origin_name, db.branch_name AS destination_name,
                   COALESCE(SUM(s.quantity),0) AS cargo_qty,
                   COALESCE(MAX(s.quantity_unit),'') AS quantity_unit,
                   COALESCE(SUM(s.weight_kg),0) AS cargo_weight,
                   COUNT(DISTINCT s.id) AS shipment_count,
                   GROUP_CONCAT(DISTINCT s.shipment_number ORDER BY s.shipment_number SEPARATOR ', ') AS shipments,
                   GROUP_CONCAT(DISTINCT s.cargo_description ORDER BY s.shipment_number SEPARATOR ', ') AS cargo_summary
            FROM trucking_trips t
            LEFT JOIN containers c ON c.id = t.container_id AND c.tenant_id = t.tenant_id
            LEFT JOIN branches ob ON ob.id = t.from_branch_id
            LEFT JOIN branches db ON db.id = t.to_branch_id
            LEFT JOIN cargo_manifest_items cmi ON cmi.container_id = t.container_id AND cmi.tenant_id = t.tenant_id
            LEFT JOIN shipments s ON s.id = cmi.master_shipment_id AND s.is_active = 1
            WHERE t.tenant_id = ? AND t.driver_id = ? AND {$statusSql} {$searchSql}
            GROUP BY t.id
            ORDER BY FIELD(t.status,'in_transit','loading','loaded','pending','received','delivered','completed'), t.created_at DESC, t.id DESC
        ");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['status_label'] = driver_status_label((string)$r['status']);
            $r['can_start'] = in_array((string)$r['status'], ['loading','loaded'], true) && in_array((string)$r['approval_status'], ['approved','not_required'], true);
            $r['can_arrive'] = (string)$r['status'] === 'in_transit';
        }
        unset($r);
        jsonOut(['success' => true, 'rows' => $rows]);
    }

    if ($action === 'kpis') {
        $st = $pdo->prepare("
            SELECT
              COUNT(*) AS assigned_trips,
              SUM(CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END) AS active_trips,
              SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_trips,
              SUM(CASE WHEN status IN ('pending','received','loading','loaded') THEN 1 ELSE 0 END) AS pending_trips
            FROM trucking_trips
            WHERE tenant_id = ? AND driver_id = ?
        ");
        $st->execute([$tenant_id, $driver_profile_id]);
        jsonOut(['success' => true, 'kpis' => $st->fetch(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'trip_details') {
        $trip = owned_trip($pdo, $tenant_id, $driver_profile_id, postInt('trip_id'));
        if (!$trip) jsonOut(['success' => false, 'message' => 'Trip not found among your assignments.']);
        $trip['status_label'] = driver_status_label((string)$trip['status']);
        $trip['manifest'] = trip_manifest($pdo, $tenant_id, (int)$trip['container_id']);
        jsonOut(['success' => true, 'trip' => $trip]);
    }

    if ($action === 'start_trip') {
        $trip_id = postInt('trip_id');
        $pdo->beginTransaction();
        try {
            $trip = owned_trip($pdo, $tenant_id, $driver_profile_id, $trip_id, true);
            if (!$trip) throw new RuntimeException('Trip not found among your assignments.');
            if (!in_array((string)$trip['status'], ['loading','loaded'], true)) throw new RuntimeException('This trip is not ready for dispatch.');
            if (!in_array((string)$trip['approval_status'], ['approved','not_required'], true)) throw new RuntimeException('This trip is not approved for dispatch.');

            $pdo->prepare("UPDATE trucking_trips SET status='in_transit', departed_at=COALESCE(departed_at,NOW()), updated_at=NOW() WHERE id=? AND tenant_id=? AND driver_id=?")
                ->execute([$trip_id, $tenant_id, $driver_profile_id]);
            $pdo->prepare("UPDATE containers SET status='dispatched', departure_date=COALESCE(departure_date,CURDATE()), updated_at=NOW() WHERE id=? AND tenant_id=?")
                ->execute([(int)$trip['container_id'], $tenant_id]);
            propagate_trip_status_to_shipments($trip_id, 'in_transit', [
                'tenant_id' => $tenant_id,
                'performed_by' => $driver_user_id,
                'performer_name' => $_SESSION['user_name'] ?? null,
            ]);
            audit_driver_action($pdo, $tenant_id, $driver_user_id, 'DRIVER_START_TRIP', 'trucking_trips', $trip_id, ['status' => $trip['status']], ['status' => 'in_transit']);
            $pdo->commit();
            jsonOut(['success' => true, 'message' => 'Trip started.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonOut(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    if ($action === 'confirm_arrival') {
        $trip_id = postInt('trip_id');
        $pdo->beginTransaction();
        try {
            $trip = owned_trip($pdo, $tenant_id, $driver_profile_id, $trip_id, true);
            if (!$trip) throw new RuntimeException('Trip not found among your assignments.');
            if ((string)$trip['status'] !== 'in_transit') throw new RuntimeException('Only in-transit trips can be marked arrived.');

            $pdo->prepare("UPDATE trucking_trips SET status='delivered', arrived_at=COALESCE(arrived_at,NOW()), updated_at=NOW() WHERE id=? AND tenant_id=? AND driver_id=?")
                ->execute([$trip_id, $tenant_id, $driver_profile_id]);
            $pdo->prepare("UPDATE containers SET status='ready', arrival_date=COALESCE(arrival_date,CURDATE()), current_branch_id=?, updated_at=NOW() WHERE id=? AND tenant_id=?")
                ->execute([(int)$trip['to_branch_id'], (int)$trip['container_id'], $tenant_id]);
            propagate_trip_status_to_shipments($trip_id, 'delivered', [
                'tenant_id' => $tenant_id,
                'performed_by' => $driver_user_id,
                'performer_name' => $_SESSION['user_name'] ?? null,
            ]);
            audit_driver_action($pdo, $tenant_id, $driver_user_id, 'DRIVER_CONFIRM_ARRIVAL', 'trucking_trips', $trip_id, ['status' => $trip['status']], ['status' => 'delivered']);
            $pdo->commit();
            jsonOut(['success' => true, 'message' => 'Arrival confirmed. Shipments are marked Arrived at Destination, awaiting warehouse receiving.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonOut(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    if ($action === 'report_issue') {
        $trip_id = postInt('trip_id');
        $type = postStr('issue_type', 'delay');
        $desc = postStr('description');
        $location = postStr('location');
        if (!in_array($type, ['delay','breakdown','accident','road_closure','cargo_issue','other'], true)) jsonOut(['success' => false, 'message' => 'Invalid issue type.']);
        if ($desc === '') jsonOut(['success' => false, 'message' => 'Please describe the issue.']);
        $trip = owned_trip($pdo, $tenant_id, $driver_profile_id, $trip_id);
        if (!$trip) jsonOut(['success' => false, 'message' => 'Trip not found among your assignments.']);
        $storedType = in_array($type, ['delay','breakdown'], true) ? $type : ($type === 'other' ? 'other' : 'incident');
        $fullDesc = $desc . ($location !== '' ? "\nLocation: {$location}" : '');
        $ins = $pdo->prepare("INSERT INTO trip_issue_reports (tenant_id, trip_id, reported_by, reporter_name, issue_type, description, created_at)
                              VALUES (?,?,?,?,?,?,NOW())");
        $ins->execute([$tenant_id, $trip_id, $driver_user_id, $_SESSION['user_name'] ?? null, $storedType, $fullDesc]);
        audit_driver_action($pdo, $tenant_id, $driver_user_id, 'DRIVER_REPORT_ISSUE', 'trucking_trips', $trip_id, [], ['issue_type' => $type, 'location' => $location]);
        jsonOut(['success' => true, 'message' => 'Incident reported.']);
    }

    if ($action === 'update_location') {
        $trip_id = postInt('trip_id');
        $location = postStr('location');
        $note = postStr('note');
        if ($location === '') jsonOut(['success' => false, 'message' => 'Current location is required.']);
        $trip = owned_trip($pdo, $tenant_id, $driver_profile_id, $trip_id);
        if (!$trip) jsonOut(['success' => false, 'message' => 'Trip not found among your assignments.']);
        $append = "\n[" . date('Y-m-d H:i:s') . "] Driver location update: {$location}" . ($note !== '' ? " — {$note}" : '');
        $pdo->prepare("UPDATE trucking_trips SET notes = CONCAT(COALESCE(notes,''), ?), updated_at=NOW() WHERE id=? AND tenant_id=? AND driver_id=?")
            ->execute([$append, $trip_id, $tenant_id, $driver_profile_id]);
        audit_driver_action($pdo, $tenant_id, $driver_user_id, 'DRIVER_LOCATION_UPDATE', 'trucking_trips', $trip_id, [], ['location' => $location, 'note' => $note]);
        jsonOut(['success' => true, 'message' => 'Location update saved.']);
    }

    jsonOut(['success' => false, 'message' => 'Unknown action.']);
}

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.driver-kpi{background:#fff;border-radius:14px;padding:18px;box-shadow:0 8px 22px rgba(31,20,65,.08);border:1px solid #eee}
.driver-kpi .num{font-size:28px;font-weight:800;color:#32145f}
.trip-status{border-radius:999px;padding:6px 10px;font-weight:700;font-size:12px}
.status-in_transit{background:#e8f1ff;color:#1d4ed8}.status-loaded,.status-loading{background:#fff7db;color:#a16207}
.status-delivered{background:#e7f8ef;color:#047857}.status-completed{background:#eceff3;color:#374151}
</style>

<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2><i class="fas fa-truck text-primary"></i> Driver Dashboard <small class="text-muted">— <?= h($_SESSION['user_name'] ?? '') ?></small></h2>
  </div>

  <?php if ($driver_profile_id <= 0): ?>
    <div class="alert alert-danger">No active driver profile is linked to this login. Please contact the administrator.</div>
  <?php else: ?>
  <div class="row mb-3">
    <div class="col-md-3 mb-3"><div class="driver-kpi"><div class="num" id="kAssigned">0</div><div>Assigned Trips</div></div></div>
    <div class="col-md-3 mb-3"><div class="driver-kpi"><div class="num" id="kActive">0</div><div>Active Trips</div></div></div>
    <div class="col-md-3 mb-3"><div class="driver-kpi"><div class="num" id="kCompleted">0</div><div>Completed Trips</div></div></div>
    <div class="col-md-3 mb-3"><div class="driver-kpi"><div class="num" id="kPending">0</div><div>Pending Trips</div></div></div>
  </div>

  <div class="card shadow-sm border-0">
    <div class="card-body">
      <div class="row mb-3">
        <div class="col-md-4">
          <input id="searchInput" class="form-control" placeholder="Search trip, container, truck, route...">
        </div>
        <div class="col-md-3">
          <select id="filterStatus" class="form-control">
            <option value="all">All</option>
            <option value="pending">Pending</option>
            <option value="ready">Ready for Dispatch</option>
            <option value="in_transit">In Transit</option>
            <option value="arrived">Arrived</option>
            <option value="completed">Completed</option>
          </select>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-hover table-sm" id="tripsTable">
          <thead class="bg-light"><tr>
            <th>Trip #</th><th>Route</th><th>Truck</th><th>Container</th><th>Cargo</th><th>Status</th><th>Departure</th><th>Destination</th><th>Action</th>
          </tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="modal fade" id="detailsModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Trip Details</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
  <div class="modal-body" id="detailsBody"></div>
</div></div></div>

<div class="modal fade" id="arrivalModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Confirm Arrival</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
  <div class="modal-body" id="arrivalBody"></div>
  <div class="modal-footer"><button class="btn btn-secondary" data-dismiss="modal">Cancel</button><button class="btn btn-primary" id="confirmArrivalBtn">Confirm Arrival</button></div>
</div></div></div>

<div class="modal fade" id="issueModal" tabindex="-1"><div class="modal-dialog">
  <form class="modal-content" id="issueForm">
    <input type="hidden" name="ajax_action" value="report_issue">
    <input type="hidden" name="trip_id" id="issueTripId">
    <div class="modal-header"><h5 class="modal-title">Report Incident</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label>Type</label><select name="issue_type" class="form-control">
        <option value="delay">Delay</option><option value="breakdown">Breakdown</option><option value="accident">Accident</option>
        <option value="road_closure">Road closure</option><option value="cargo_issue">Cargo issue</option><option value="other">Other</option>
      </select></div>
      <div class="form-group"><label>Location</label><input name="location" class="form-control" placeholder="Burco, Hargeisa Entrance..."></div>
      <div class="form-group"><label>Description</label><textarea name="description" class="form-control" required></textarea></div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Submit Report</button></div>
  </form>
</div></div>

<div class="modal fade" id="locationModal" tabindex="-1"><div class="modal-dialog">
  <form class="modal-content" id="locationForm">
    <input type="hidden" name="ajax_action" value="update_location">
    <input type="hidden" name="trip_id" id="locationTripId">
    <div class="modal-header"><h5 class="modal-title">Update Location</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label>Current Location</label><input name="location" class="form-control" required placeholder="Burco"></div>
      <div class="form-group"><label>Note</label><textarea name="note" class="form-control"></textarea></div>
      <small class="text-muted">Saved as a text tracking/audit update. GPS is not fabricated.</small>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Save Location</button></div>
  </form>
</div></div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
// jQuery is loaded by includes/footer.php (above). This inline block MUST come
// after that include or `$(function(){...})` throws ReferenceError at parse
// time, silently aborting the dashboard's initial loadKpis()/loadTrips() call
// and leaving the KPI cards and trip table stuck at their static "0" state.
function esc(s){ const d=document.createElement('div'); d.textContent=s ?? ''; return d.innerHTML; }
function toast(m, ok=true){ alert((ok?'':'ERROR: ')+m); }
let tripsById = {};

function loadKpis(){
  $.post('', {ajax_action:'kpis'}, res => {
    if(!res.success) return;
    $('#kAssigned').text(res.kpis.assigned_trips || 0);
    $('#kActive').text(res.kpis.active_trips || 0);
    $('#kCompleted').text(res.kpis.completed_trips || 0);
    $('#kPending').text(res.kpis.pending_trips || 0);
  }, 'json');
}

function actionButtons(t){
  let html = `<button class="btn btn-sm btn-outline-primary act-view" data-id="${t.id}">View Trip</button> `;
  if(t.can_start == 1 || t.can_start === true) html += `<button class="btn btn-sm btn-primary act-start" data-id="${t.id}">Start Trip</button> `;
  if(t.can_arrive == 1 || t.can_arrive === true) html += `<button class="btn btn-sm btn-success act-arrive" data-id="${t.id}">Confirm Arrival</button> `;
  html += `<button class="btn btn-sm btn-outline-warning act-location" data-id="${t.id}">Location</button> `;
  html += `<button class="btn btn-sm btn-outline-danger act-issue" data-id="${t.id}">Incident</button>`;
  return html;
}

function loadTrips(){
  $.post('', {ajax_action:'my_trips', filter:$('#filterStatus').val(), search:$('#searchInput').val()}, res => {
    if(!res.success){ toast(res.message,false); return; }
    tripsById = {};
    let html = '';
    res.rows.forEach(t => {
      tripsById[t.id] = t;
      const qty = `${parseInt(t.cargo_qty || 0)} ${esc(t.quantity_unit || '')}`.trim();
      html += `<tr>
        <td><strong>${esc(t.trip_number)}</strong></td>
        <td>${esc(t.origin_name || '')} → ${esc(t.destination_name || '')}</td>
        <td>${esc(t.truck_plate || '-')}</td>
        <td>${esc(t.container_number || '-')}</td>
        <td>${esc(t.shipments || '-')}<br><small>${esc(t.cargo_summary || '')} · ${qty} · ${parseFloat(t.cargo_weight || 0)} KG</small></td>
        <td><span class="trip-status status-${esc(t.status)}">${esc(t.status_label)}</span></td>
        <td>${esc(t.departed_at || '-')}</td>
        <td>${esc(t.destination_name || '-')}</td>
        <td class="text-nowrap">${actionButtons(t)}</td>
      </tr>`;
    });
    $('#tripsTable tbody').html(html || '<tr><td colspan="9" class="text-center text-muted">No trips assigned to you.</td></tr>');
  }, 'json').fail(()=>toast('Server error.',false));
}

function showDetails(id){
  $.post('', {ajax_action:'trip_details', trip_id:id}, res => {
    if(!res.success){ toast(res.message,false); return; }
    const t = res.trip;
    let manifest = '';
    (t.manifest || []).forEach(s => {
      manifest += `<tr><td><strong>${esc(s.shipment_number)}</strong><br><small>${esc(s.tracking_number)}</small></td>
        <td>${esc(s.cargo_description)}</td><td>${parseInt(s.quantity || 0)} ${esc(s.quantity_unit || '')}</td>
        <td>${parseFloat(s.weight_kg || 0)} KG</td><td>${esc(s.receiver_name || '-')}<br><small>${esc(s.receiver_phone || '')}</small></td>
        <td>${esc(s.destination_name || '-')}</td></tr>`;
    });
    $('#detailsBody').html(`<div class="row">
      <div class="col-md-6"><p><strong>Trip:</strong> ${esc(t.trip_number)}</p><p><strong>Container:</strong> ${esc(t.container_number || '-')}</p><p><strong>Truck:</strong> ${esc(t.truck_plate || '-')}</p></div>
      <div class="col-md-6"><p><strong>Origin:</strong> ${esc(t.origin_name || '-')}</p><p><strong>Destination:</strong> ${esc(t.destination_name || '-')}</p><p><strong>Status:</strong> ${esc(t.status_label)}</p></div>
    </div><p><strong>Departure:</strong> ${esc(t.departed_at || '-')}</p>
    <h5>Manifest</h5><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Shipment</th><th>Cargo</th><th>Qty</th><th>Weight</th><th>Receiver</th><th>Destination</th></tr></thead><tbody>${manifest || '<tr><td colspan="6" class="text-muted">No manifest items.</td></tr>'}</tbody></table></div>`);
    $('#detailsModal').modal('show');
  }, 'json');
}

$(function(){
  loadKpis(); loadTrips();
  $('#filterStatus').on('change', loadTrips);
  $('#searchInput').on('input', function(){ clearTimeout(window.driverSearchTimer); window.driverSearchTimer=setTimeout(loadTrips, 250); });
  $(document).on('click','.act-view',function(){ showDetails($(this).data('id')); });
  $(document).on('click','.act-start',function(){
    if(!confirm('Start this approved trip and mark it In Transit?')) return;
    $.post('', {ajax_action:'start_trip', trip_id:$(this).data('id')}, res => { toast(res.message, !!res.success); loadKpis(); loadTrips(); }, 'json');
  });
  $(document).on('click','.act-arrive',function(){
    const id=$(this).data('id'), t=tripsById[id] || {};
    $('#arrivalBody').html(`<p><strong>Trip:</strong> ${esc(t.trip_number)}</p><p><strong>Destination:</strong> ${esc(t.destination_name)}</p><p><strong>Container:</strong> ${esc(t.container_number)}</p><p class="text-muted mb-0">This will mark transport arrival only. Warehouse receiving remains separate.</p>`);
    $('#confirmArrivalBtn').data('id', id);
    $('#arrivalModal').modal('show');
  });
  $('#confirmArrivalBtn').on('click',function(){
    $.post('', {ajax_action:'confirm_arrival', trip_id:$(this).data('id')}, res => {
      toast(res.message, !!res.success);
      if(res.success) $('#arrivalModal').modal('hide');
      loadKpis(); loadTrips();
    }, 'json');
  });
  $(document).on('click','.act-issue',function(){ $('#issueTripId').val($(this).data('id')); $('#issueModal').modal('show'); });
  $(document).on('click','.act-location',function(){ $('#locationTripId').val($(this).data('id')); $('#locationModal').modal('show'); });
  $('#issueForm').on('submit',function(e){ e.preventDefault(); $.post('', $(this).serialize(), res => { toast(res.message, !!res.success); if(res.success){ $('#issueModal').modal('hide'); this.reset(); } }, 'json'); });
  $('#locationForm').on('submit',function(e){ e.preventDefault(); $.post('', $(this).serialize(), res => { toast(res.message, !!res.success); if(res.success){ $('#locationModal').modal('hide'); this.reset(); } }, 'json'); });
});
</script>
