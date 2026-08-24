<?php
// ============================================================================
// staff/incoming_trips.php — Incoming Trips / Destination Warehouse Receiving
// ----------------------------------------------------------------------------
// The missing A→Z link: a warehouse supervisor at the DESTINATION branch
// (e.g. Hargeisa) opens this page, sees trips inbound to their branch, opens
// the manifest and verifies each shipment (expected vs received) with explicit
// shortage/excess/damage handling. Received shipments are stored into the
// destination warehouse; stock ownership physically transfers here.
// Access: warehouse_supervisor / logistics_supervisor / clerk (staff area).
// ============================================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/rbac.php';
require_once __DIR__ . '/../includes/shipment_functions.php';

require_login_guard();
$tenant_id = require_tenant_context();
$current_role_type = current_role_type();
require_staff_subroles(['warehouse_supervisor', 'logistics_supervisor', 'clerk']);
ensureShipmentSchema($pdo);
$assigned_branch_id = require_branch_context($pdo);

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function jsonOut(array $d): void { header('Content-Type: application/json'); echo json_encode($d); exit; }
function postInt(string $k, int $def = 0): int { $v = $_POST[$k] ?? $def; return is_numeric($v) ? (int)$v : $def; }
function postStr(string $k, string $def = ''): string { $v = $_POST[$k] ?? $def; return is_array($v) ? $def : trim((string)$v); }
function ctxBase(): array {
    return ['performed_by' => $_SESSION['user_id'] ?? null, 'performer_name' => $_SESSION['user_name'] ?? null];
}

$branch_name = '';
try {
    $st = $pdo->prepare("SELECT branch_name FROM branches WHERE id = ? AND tenant_id = ?");
    $st->execute([$assigned_branch_id, $tenant_id]);
    $branch_name = (string)$st->fetchColumn();
} catch (Throwable $e) {}

// ============================================================================
// AJAX
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    $action = postStr('ajax_action');

    // Inbound trips to MY branch that have departed but not yet been receipted.
    if ($action === 'list_incoming') {
        $st = $pdo->prepare("
            SELECT t.id, t.trip_number, t.status, t.departed_at, t.arrived_at,
                   t.truck_plate, t.driver_name, c.container_number, c.id AS container_id,
                   ob.branch_name AS origin_name,
                   (SELECT COALESCE(SUM(cmi.quantity),0) FROM cargo_manifest_items cmi WHERE cmi.container_id = t.container_id) AS expected_total,
                   (SELECT COUNT(DISTINCT cmi.master_shipment_id) FROM cargo_manifest_items cmi
                      JOIN shipments s2 ON s2.id = cmi.master_shipment_id
                     WHERE cmi.container_id = t.container_id AND s2.current_branch_id = t.to_branch_id
                       AND s2.current_status IN ('IN_DESTINATION_WAREHOUSE','READY_FOR_PICKUP','OUT_FOR_DELIVERY','DELIVERED','CLOSED','PARTIALLY_RECEIVED','DAMAGED')) AS received_count,
                   (SELECT COUNT(DISTINCT cmi.master_shipment_id) FROM cargo_manifest_items cmi WHERE cmi.container_id = t.container_id AND cmi.master_shipment_id IS NOT NULL) AS shipment_count
            FROM trucking_trips t
            LEFT JOIN containers c ON c.id = t.container_id
            LEFT JOIN branches ob ON ob.id = t.from_branch_id
            WHERE t.tenant_id = ? AND t.to_branch_id = ?
              AND t.status IN ('in_transit','delivered','completed')
            ORDER BY FIELD(t.status,'in_transit','delivered','completed'), t.departed_at DESC");
        $st->execute([$tenant_id, $assigned_branch_id]);
        jsonOut(['success' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // Manifest verification view: expected vs received per shipment.
    if ($action === 'get_manifest') {
        $trip_id = postInt('trip_id');
        $t = $pdo->prepare("SELECT * FROM trucking_trips WHERE id = ? AND tenant_id = ? AND to_branch_id = ?");
        $t->execute([$trip_id, $tenant_id, $assigned_branch_id]);
        $trip = $t->fetch(PDO::FETCH_ASSOC);
        if (!$trip) jsonOut(['success' => false, 'message' => 'Trip not found for your branch.']);
        $items = $pdo->prepare("
            SELECT cmi.id AS cmi_id, cmi.quantity AS expected_qty, cmi.weight_kg,
                   s.id AS shipment_id, s.shipment_number, s.tracking_number,
                   s.cargo_description, s.receiver_name, s.current_status
            FROM cargo_manifest_items cmi
            JOIN shipments s ON s.id = cmi.master_shipment_id
            WHERE cmi.tenant_id = ? AND cmi.container_id = ?
            ORDER BY s.shipment_number");
        $items->execute([$tenant_id, $trip['container_id']]);
        jsonOut(['success' => true, 'trip' => $trip, 'items' => $items->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // Receive ONE shipment off the inbound container with discrepancy handling.
    if ($action === 'receive_shipment') {
        if (!in_array($current_role_type, ['warehouse_supervisor', 'clerk'], true)) access_denied_redirect();
        $trip_id = postInt('trip_id');
        $shipment_id = postInt('shipment_id');
        $received_qty = postInt('received_qty', 0);
        $zone = postStr('zone'); $rack = postStr('rack');
        $discrepancy = postStr('discrepancy', 'none'); // none|shortage|excess|damage
        $note = postStr('notes');

        $t = $pdo->prepare("SELECT * FROM trucking_trips WHERE id = ? AND tenant_id = ? AND to_branch_id = ? FOR UPDATE");
        $t->execute([$trip_id, $tenant_id, $assigned_branch_id]);
        $trip = $t->fetch(PDO::FETCH_ASSOC);
        if (!$trip) jsonOut(['success' => false, 'message' => 'Trip not found for your branch.']);
        if (!in_array($trip['status'], ['in_transit','delivered','completed'], true)) {
            jsonOut(['success' => false, 'message' => 'Trip has not arrived at your branch yet.']);
        }

        $sStmt = $pdo->prepare("SELECT * FROM shipments WHERE id = ? AND tenant_id = ? AND destination_branch_id = ? FOR UPDATE");
        $sStmt->execute([$shipment_id, $tenant_id, $assigned_branch_id]);
        $ship = $sStmt->fetch(PDO::FETCH_ASSOC);
        if (!$ship) jsonOut(['success' => false, 'message' => 'Shipment is not destined for your branch.']);

        if (in_array($ship['current_status'], ['IN_DESTINATION_WAREHOUSE','READY_FOR_PICKUP','OUT_FOR_DELIVERY','DELIVERED','CLOSED'], true)) {
            jsonOut(['success' => false, 'message' => "Shipment already received ({$ship['current_status']})."]);
        }
        $cmi = $pdo->prepare("SELECT quantity FROM cargo_manifest_items WHERE master_shipment_id = ? AND container_id = ? LIMIT 1");

        $cmi->execute([$shipment_id, $trip['container_id']]);
        $expected = (int)$cmi->fetchColumn();
        if ($expected <= 0) jsonOut(['success' => false, 'message' => 'Shipment is not on this trip manifest.']);
        if ($discrepancy === 'none' && $received_qty !== $expected) {
            jsonOut(['success' => false, 'message' => "Expected {$expected}. Record a discrepancy (shortage/excess/damage) or receive the full quantity."]);
        }
        receive_shipment_action($pdo, $tenant_id, $assigned_branch_id, $branch_name,
            $trip, $ship, $expected, $received_qty, $discrepancy, $note);
    }

    function receive_shipment_action(PDO $pdo, int $tenant_id, int $branchId, string $branchName,
                                     array $trip, array $ship, int $expected, int $receivedQty,
                                     string $discrepancy, string $note): void {
        $trip_id = (int)$trip['id']; $shipment_id = (int)$ship['id'];
        $pdo->beginTransaction();
        try {
            // Physical stock transfer: store into DESTINATION warehouse
            $res = receive_shipment_into_warehouse($shipment_id, $branchId, postStr('zone'), postStr('rack'), array_merge(ctxBase(), [
                'tenant_id' => $tenant_id,
                'branch_name' => $branchName,
                'is_destination' => true,
                'trip_id' => $trip_id,
                'notes' => "Received from trip {$trip['trip_number']}. Expected {$expected}, received {$receivedQty}."
                    . ($discrepancy !== 'none' ? " Discrepancy: {$discrepancy}." : '')
                    . ($note ? " Note: {$note}" : ''),
            ]));
            if (!$res['ok']) throw new RuntimeException($res['message']);

            // UNLOAD movement reference on the destination stock row
            record_stock_movement([
                'tenant_id' => $tenant_id, 'warehouse_stock_id' => (int)$res['warehouse_stock_id'],
                'quantity_change' => $receivedQty,
                'previous_quantity' => 0, 'new_quantity' => $receivedQty,
                'movement_type' => 'move',
                'reference_type' => 'trip_unload', 'reference_id' => $trip_id,
                'notes' => "UNLOAD: Trip {$trip['trip_number']} / Container → {$branchName} Warehouse",
                'created_by' => $_SESSION['user_id'] ?? null,
            ]);

            // Manifest line taken off the container
            $pdo->prepare("UPDATE cargo_manifest_items SET mogadishu_status = 'delivered', mogadishu_taken_date = NOW()
                           WHERE master_shipment_id = ? AND container_id = ?")
                ->execute([$shipment_id, $trip['container_id']]);


            // Explicit discrepancy states — never silently mark everything OK.
            if ($discrepancy === 'damage') {
                update_shipment_status($shipment_id, 'DAMAGED', array_merge(ctxBase(), [
                    'tenant_id' => $tenant_id, 'force' => true, 'trip_id' => $trip_id,
                    'event_type' => 'DISCREPANCY_DAMAGE',
                    'notes' => "Damaged in transit. Expected {$expected}, usable {$receivedQty}. {$note}",
                ]));
            } elseif ($discrepancy === 'shortage' && $receivedQty < $expected) {
                update_shipment_status($shipment_id, 'PARTIALLY_RECEIVED', array_merge(ctxBase(), [
                    'tenant_id' => $tenant_id, 'force' => true, 'trip_id' => $trip_id,
                    'event_type' => 'DISCREPANCY_SHORTAGE',
                    'notes' => "Shortage: expected {$expected}, received {$receivedQty}. {$note}",
                ]));
            } elseif ($receivedQty > $expected) {
                log_shipment_event(array_merge(ctxBase(), [
                    'tenant_id' => $tenant_id, 'shipment_id' => $shipment_id,
                    'event_type' => 'DISCREPANCY_EXCESS', 'new_status' => (string)$ship['current_status'],
                    'trip_id' => $trip_id, 'branch_id' => $branchId,
                    'notes' => "Excess: expected {$expected}, received {$receivedQty}. {$note}",
                ]));
            }

            // All manifest shipments receipted? Close the loop on container+trip.
            $pend = $pdo->prepare("
                SELECT COUNT(*) FROM cargo_manifest_items cmi
                JOIN shipments s ON s.id = cmi.master_shipment_id
                WHERE cmi.container_id = ? AND cmi.tenant_id = ?
                  AND s.current_status NOT IN ('IN_DESTINATION_WAREHOUSE','PARTIALLY_RECEIVED','DAMAGED','READY_FOR_PICKUP','OUT_FOR_DELIVERY','DELIVERED','CLOSED')");
            $pend->execute([$trip['container_id'], $tenant_id]);
            $remaining = (int)$pend->fetchColumn();
            if ($remaining === 0) {
                $pdo->prepare("UPDATE containers SET status = 'delivered', delivered_date = COALESCE(delivered_date, CURDATE()), updated_at = NOW()
                               WHERE id = ? AND tenant_id = ?")->execute([$trip['container_id'], $tenant_id]);
                $pdo->prepare("UPDATE trucking_trips SET status = 'completed', arrived_at = COALESCE(arrived_at, NOW()), updated_at = NOW()
                               WHERE id = ? AND tenant_id = ?")->execute([$trip_id, $tenant_id]);
            }

            $pdo->commit();
            jsonOut(['success' => true,
                     'message' => ($remaining === 0)
                        ? "Shipment received and stored. All manifest shipments receipted - trip {$trip['trip_number']} completed."
                        : "Shipment received and stored. {$remaining} shipment(s) still pending on this manifest."]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonOut(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    jsonOut(['success' => false, 'message' => 'Unknown action.']);
}

// ============================================================================
// UI
// ============================================================================
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <?php if (($_GET['error'] ?? '') === 'access_denied'): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-ban"></i> You do not have permission to access that page.
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><i class="fas fa-truck-ramp-box text-primary"></i> Incoming Trips
            <small class="text-muted">— <?= h($branch_name ?: 'Branch') ?> receiving</small></h2>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm" id="incomingTable">
                    <thead class="bg-light">
                        <tr><th>Trip</th><th>From</th><th>Truck</th><th>Driver</th>
                            <th>Container</th><th>Status</th><th>Manifest Progress</th><th></th></tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Manifest verification modal -->
<div class="modal fade" id="manifestModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Manifest Verification &amp; Receiving</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button></div>
      <div class="modal-body" id="manifestBody"></div>
    </div>
  </div>
</div>

<script>
function esc(s){ const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }
function toast(m, ok=true){ alert((ok?'':'ERROR: ')+m); }
const doneStatuses = ['IN_DESTINATION_WAREHOUSE','READY_FOR_PICKUP','OUT_FOR_DELIVERY','DELIVERED','CLOSED','PARTIALLY_RECEIVED','DAMAGED'];

function loadIncoming(){
    $.post('', { ajax_action:'list_incoming' }, function(res){
        if(!res.success){ toast(res.message,false); return; }
        let html='';
        res.rows.forEach(t=>{
            const pct = t.shipment_count>0 ? Math.round(100*t.received_count/t.shipment_count) : 0;
            html += `<tr>
                <td><strong>${esc(t.trip_number)}</strong></td>
                <td>${esc(t.origin_name||'')}</td>
                <td>${esc(t.truck_plate||'')}</td><td>${esc(t.driver_name||'')}</td>
                <td>${esc(t.container_number||'')}</td>
                <td><span class="badge badge-info">${esc(t.status)}</span></td>
                <td style="min-width:140px">
                  <div class="progress" style="height:16px">
                    <div class="progress-bar bg-success" style="width:${pct}%">${t.received_count}/${t.shipment_count}</div>
                  </div></td>
                <td><button class="btn btn-sm btn-primary open-manifest" data-id="${t.id}">
                    <i class="fas fa-clipboard-check"></i> Verify Manifest</button></td>
            </tr>`;
        });
        $('#incomingTable tbody').html(html || '<tr><td colspan="8" class="text-center text-muted">No inbound trips right now.</td></tr>');
    },'json').fail(()=>toast('Server error.',false));
}

function loadManifest(tripId){
    $.post('', { ajax_action:'get_manifest', trip_id: tripId }, function(res){
        if(!res.success){ toast(res.message,false); return; }
        const t = res.trip;
        let rows='';
        res.items.forEach(it=>{
            const done = doneStatuses.includes(it.current_status);
            rows += `<tr>
              <td><strong>${esc(it.shipment_number)}</strong><br><small>${esc(it.tracking_number||'')}</small></td>
              <td>${esc(it.cargo_description||'')}</td>
              <td>${esc(it.receiver_name||'')}</td>
              <td class="text-center"><strong>${it.expected_qty}</strong></td>
              <td><span class="badge ${done?'badge-success':'badge-warning'}">${esc(it.current_status)}</span></td>
              <td>`;
            if(!done){
              rows += `<form class="form-inline recv-form" data-trip="${t.id}" data-ship="${it.shipment_id}" data-expected="${it.expected_qty}">
                <input type="number" name="received_qty" class="form-control form-control-sm mr-1" style="width:80px" value="${it.expected_qty}" min="0">
                <select name="discrepancy" class="form-control form-control-sm mr-1">
                  <option value="none">Full OK</option><option value="shortage">Shortage</option>
                  <option value="excess">Excess</option><option value="damage">Damage</option>
                </select>
                <input type="text" name="zone" class="form-control form-control-sm mr-1" placeholder="Zone" style="width:70px" required>
                <input type="text" name="rack" class="form-control form-control-sm mr-1" placeholder="Rack" style="width:80px" required>
                <input type="text" name="notes" class="form-control form-control-sm mr-1" placeholder="Note (optional)">
                <button class="btn btn-sm btn-success">Receive</button>
              </form>`;
            } else { rows += '<em class="text-muted">Receipted</em>'; }
            rows += `</td></tr>`;
        });
        $('#manifestBody').html(`
          <p><strong>Trip:</strong> ${esc(t.trip_number)} — <strong>Status:</strong> ${esc(t.status)}<br>
             Expected total on manifest: <strong>${esc(String(res.items.reduce((a,b)=>a+Number(b.expected_qty),0)))}</strong></p>
          <div class="table-responsive"><table class="table table-sm table-hover">
            <thead class="bg-light"><tr><th>Shipment</th><th>Cargo</th><th>Receiver</th>
              <th class="text-center">Expected Qty</th><th>Status</th><th>Receive / Verify</th></tr></thead>
            <tbody>${rows}</tbody></table></div>`);
        $('#manifestModal').modal('show');
    },'json');
}

$(function(){
    loadIncoming();
    $(document).on('click','.open-manifest',function(){ loadManifest($(this).data('id')); });
    $(document).on('submit','.recv-form',function(e){
        e.preventDefault();
        const f = $(this);
        const expected = Number(f.data('expected'));
        const qty = Number(f.find('[name=received_qty]').val());
        const disc = f.find('[name=discrepancy]').val();
        if(disc==='none' && qty!==expected && !confirm(`Received ${qty} differs from expected ${expected}. Record as discrepancy instead?`)) return;
        $.post('', f.serialize() + '&ajax_action=receive_shipment&trip_id='+f.data('trip')+'&shipment_id='+f.data('ship'), function(res){
            toast(res.message, !!res.success);
            if(res.success) loadManifest(f.data('trip'));
        },'json').fail(()=>toast('Server error.',false));
    });
});
</script>
</body>
</html>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
