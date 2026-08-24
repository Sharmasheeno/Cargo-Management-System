<?php
// ============================================================================
// driver/index.php — Driver Portal: My Trips
// ----------------------------------------------------------------------------
// Authenticated Driver role (users.role_type = 'driver'). Drivers see ONLY
// trips explicitly assigned to them (trucking_trips.driver_id). They may view
// dispatch details and advance permitted statuses forward, plus report delays,
// breakdowns or incidents. No access to finance, warehouse or other drivers'
// work.
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

// Forward-only permitted transitions for the driver
$driver_next_map = [
    'loaded'     => 'in_transit',   // Start trip / depart origin
    'in_transit' => 'delivered',    // Confirm physical arrival at destination
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    $action = postStr('ajax_action');

    if ($action === 'my_trips') {
        $st = $pdo->prepare("
            SELECT t.id, t.trip_number, t.status, t.departed_at, t.arrived_at,
                   t.truck_plate, c.container_number,
                   ob.branch_name AS origin_name, db.branch_name AS destination_name,
                   (SELECT COALESCE(SUM(cmi.quantity),0) FROM cargo_manifest_items cmi WHERE cmi.container_id = t.container_id) AS cargo_qty,
                   (SELECT COUNT(DISTINCT cmi.master_shipment_id) FROM cargo_manifest_items cmi WHERE cmi.container_id = t.container_id AND cmi.master_shipment_id IS NOT NULL) AS shipment_count

            FROM trucking_trips t
            LEFT JOIN containers c ON c.id = t.container_id
            LEFT JOIN branches ob ON ob.id = t.from_branch_id
            LEFT JOIN branches db ON db.id = t.to_branch_id
            WHERE t.tenant_id = ? AND t.driver_id = ?
              AND t.status IN ('loading','loaded','in_transit','delivered','completed')
            ORDER BY FIELD(t.status,'loading','loaded','in_transit','delivered','completed'), t.created_at DESC");
        $st->execute([$tenant_id, $driver_user_id]);
        jsonOut(['success' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // Driver advances only their OWN trip, forward-only.
    if ($action === 'update_status') {
        $trip_id = postInt('trip_id');
        $new = postStr('status');
        $st = $pdo->prepare("SELECT COALESCE(approval_status,'not_required') AS approval_status, status FROM trucking_trips WHERE id = ? AND tenant_id = ? AND driver_id = ? FOR UPDATE");

        $st->execute([$trip_id, $tenant_id, $driver_user_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonOut(['success' => false, 'message' => 'Trip not found among your assignments.']);
        $cur = (string)$row['status'];
        if (($driver_next_map[$cur] ?? null) !== $new) {
            jsonOut(['success' => false, 'message' => "You cannot move this trip from {$cur} to {$new}."]);
        }
        // Dispatch approval gate (mirrors staff/branch_manager trips pages):
        // an unapproved or rejected trip has not legally departed.
        if ($new === 'in_transit' && in_array($row['approval_status'], ['pending_approval','rejected'], true)) {
            jsonOut(['success' => false, 'message' => 'This trip has not been approved by the Branch Manager yet.']);
        }
        $timeCol = $new === 'in_transit' ? 'departed_at' : ($new === 'delivered' ? 'arrived_at' : null);
        $sql = $timeCol
            ? "UPDATE trucking_trips SET status = ?, `{$timeCol}` = NOW(), updated_at = NOW() WHERE id = ?"
            : "UPDATE trucking_trips SET status = ?, updated_at = NOW() WHERE id = ?";
        $pdo->prepare($sql)->execute([$new, $trip_id]);

        // Automatic customer-facing shipment tracking from the trip event
        propagate_trip_status_to_shipments($trip_id, $new, ['tenant_id' => $tenant_id]);
        jsonOut(['success' => true, 'message' => 'Trip status updated. Customer tracking refreshed automatically.']);
    }

    // Report delay / breakdown / incident (own trips only)
    if ($action === 'report_issue') {
        $trip_id = postInt('trip_id');
        $type = postStr('issue_type', 'delay');
        $desc = postStr('description');
        if (!in_array($type, ['delay','breakdown','incident','other'], true)) jsonOut(['success' => false, 'message' => 'Invalid issue type.']);
        if ($desc === '') jsonOut(['success' => false, 'message' => 'Please describe the issue.']);
        $own = $pdo->prepare("SELECT id FROM trucking_trips WHERE id = ? AND tenant_id = ? AND driver_id = ?");
        $own->execute([$trip_id, $tenant_id, $driver_user_id]);
        if (!$own->fetch()) jsonOut(['success' => false, 'message' => 'Trip not among your assignments.']);
        $ins = $pdo->prepare("INSERT INTO trip_issue_reports (tenant_id, trip_id, reported_by, reporter_name, issue_type, description, created_at)
                              VALUES (?,?,?,?,?,?,NOW())");
        $ins->execute([$tenant_id, $trip_id, $driver_user_id, $_SESSION['user_name'] ?? null, $type, $desc]);
        jsonOut(['success' => true, 'message' => 'Issue reported. Dispatch has been notified in the system.']);
    }

    jsonOut(['success' => false, 'message' => 'Unknown action.']);
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><i class="fas fa-truck text-primary"></i> Driver Dashboard
            <small class="text-muted">— <?= h($_SESSION['user_name'] ?? '') ?></small></h2>
    </div>
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm" id="tripsTable">
                    <thead class="bg-light"><tr>
                        <th>Trip</th><th>Route</th><th>Truck</th><th>Container</th>
                        <th>Cargo</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Issue report modal -->
<div class="modal fade" id="issueModal" tabindex="-1"><div class="modal-dialog">
  <form class="modal-content" id="issueForm">
    <input type="hidden" name="ajax_action" value="report_issue">
    <input type="hidden" name="trip_id" id="issueTripId">
    <div class="modal-header"><h5 class="modal-title">Report Issue</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label>Type</label>
        <select name="issue_type" class="form-control">
          <option value="delay">Delay</option><option value="breakdown">Breakdown</option>
          <option value="incident">Incident</option><option value="other">Other</option>
        </select></div>
      <div class="form-group"><label>Description</label><textarea name="description" class="form-control" required></textarea></div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Submit Report</button></div>
  </form>
</div></div>

<script>
function esc(s){ const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }
function toast(m, ok=true){ alert((ok?'':'ERROR: ')+m); }
const nextMap = { loading:null, loaded:'in_transit', in_transit:'delivered', delivered:null, completed:null };

function loadTrips(){
    $.post('', { ajax_action:'my_trips' }, function(res){
        if(!res.success){ toast(res.message,false); return; }
        let html='';
        res.rows.forEach(t=>{
            const next = nextMap[t.status];
            html += `<tr>
              <td><strong>${esc(t.trip_number)}</strong></td>
              <td>${esc(t.origin_name||'')} → ${esc(t.destination_name||'')}</td>
              <td>${esc(t.truck_plate||'')}</td>
              <td>${esc(t.container_number||'')}</td>
              <td>${t.cargo_qty} pcs / ${t.shipment_count} shipment(s)</td>
              <td><span class="badge badge-info">${esc(t.status)}</span>
                  ${t.departed_at? '<br><small>Departed '+esc(t.departed_at)+'</small>':''}
                  ${t.arrived_at? '<br><small>Arrived '+esc(t.arrived_at)+'</small>':''}</td>
              <td class="text-nowrap">
                ${next ? `<button class="btn btn-sm btn-primary act-status" data-id="${t.id}" data-next="${next}">
                    ${next==='in_transit'?'Start Trip (In Transit)':'Confirm Arrival'} </button>` : ''}
                <button class="btn btn-sm btn-outline-danger act-issue" data-id="${t.id}"><i class="fas fa-exclamation-triangle"></i> Report</button>
              </td>
            </tr>`;
        });
        $('#tripsTable tbody').html(html || '<tr><td colspan="7" class="text-center text-muted">No trips assigned to you yet.</td></tr>');
    },'json').fail(()=>toast('Server error.',false));
}

$(function(){
    loadTrips();
    $(document).on('click','.act-status',function(){
        const id=$(this).data('id'), next=$(this).data('next');
        if(!confirm(`Update trip status to "${next}"?`)) return;
        $.post('', { ajax_action:'update_status', trip_id:id, status:next }, function(res){
            toast(res.message, !!res.success); loadTrips();
        },'json');
    });
    $(document).on('click','.act-issue',function(){
        $('#issueTripId').val($(this).data('id'));
        $('#issueModal').modal('show');
    });
    $('#issueForm').on('submit',function(e){
        e.preventDefault();
        $.post('', $(this).serialize(), function(res){
            toast(res.message, !!res.success);
            if(res.success){ $('#issueModal').modal('hide'); $('#issueForm')[0].reset(); }
        },'json');
    });
});
</script>
</body>
</html>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
