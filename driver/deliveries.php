<?php
// driver/deliveries.php — Delivery Agent / Courier Portal: My Deliveries
// Ownership model: users.id (delivery_agent) -> delivery_assignments.assigned_to.
// Courier owns last-mile execution only: collect assigned cargo, start delivery,
// confirm POD, or report failure. No warehouse/logistics/finance/admin powers.
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/rbac.php';
require_once __DIR__ . '/../includes/shipment_functions.php';

require_login_guard();
if (!in_array(current_role_type(), ['delivery_agent'], true)) {
    access_denied_redirect();
}
$tenant_id = require_tenant_context();
$agent_user_id = (int)$_SESSION['user_id'];

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function jsonOut(array $d): void { header('Content-Type: application/json'); echo json_encode($d); exit; }
function postInt(string $k, int $def = 0): int { $v = $_POST[$k] ?? $def; return is_numeric($v) ? (int)$v : $def; }
function postStr(string $k, string $def = ''): string { $v = $_POST[$k] ?? $def; return is_array($v) ? $def : trim((string)$v); }
function ctxBase(): array { return ['performed_by' => $_SESSION['user_id'] ?? null, 'performer_name' => $_SESSION['user_name'] ?? null]; }

ensureShipmentSchema($pdo);

function load_own_delivery(PDO $pdo, int $tenant_id, int $agent_id, int $da_id, bool $forUpdate = false): ?array {
    $sql = "SELECT da.*, s.shipment_number, s.tracking_number, s.cargo_description,
                   s.quantity AS shipment_qty, s.quantity_unit, s.weight_kg,
                   s.current_status AS shipment_status, s.receiver_name AS shipment_receiver_name,
                   s.receiver_phone AS shipment_receiver_phone, s.receiver_address,
                   ob.branch_name AS origin_name, db.branch_name AS destination_name,
                   b.branch_name AS branch_name
            FROM delivery_assignments da
            JOIN shipments s ON s.id = da.shipment_id AND s.tenant_id = da.tenant_id
            LEFT JOIN branches ob ON ob.id = s.origin_branch_id
            LEFT JOIN branches db ON db.id = s.destination_branch_id
            LEFT JOIN branches b ON b.id = da.branch_id
            WHERE da.id = ? AND da.tenant_id = ? AND da.assigned_to = ?
            LIMIT 1" . ($forUpdate ? " FOR UPDATE" : "");
    $st = $pdo->prepare($sql);
    $st->execute([$da_id, $tenant_id, $agent_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    $action = postStr('ajax_action');

    if ($action === 'my_deliveries') {
        $st = $pdo->prepare("
            SELECT da.*, s.shipment_number, s.tracking_number, s.cargo_description,
                   s.quantity AS shipment_qty, s.quantity_unit
            FROM delivery_assignments da
            JOIN shipments s ON s.id = da.shipment_id AND s.tenant_id = da.tenant_id
            WHERE da.tenant_id = ? AND da.assigned_to = ?
              AND da.status IN ('assigned','collected_from_warehouse','out_for_delivery')
            ORDER BY FIELD(da.status,'out_for_delivery','collected_from_warehouse','assigned'), da.created_at DESC
        ");
        $st->execute([$tenant_id, $agent_user_id]);
        jsonOut(['success' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'get_delivery') {
        $d = load_own_delivery($pdo, $tenant_id, $agent_user_id, postInt('id'));
        if (!$d) jsonOut(['success' => false, 'message' => 'Delivery not found among your assignments.']);
        jsonOut(['success' => true, 'delivery' => $d]);
    }

    if ($action === 'collect') {
        $pdo->beginTransaction();
        try {
            $d = load_own_delivery($pdo, $tenant_id, $agent_user_id, postInt('id'), true);
            if (!$d || $d['status'] !== 'assigned') throw new RuntimeException('Delivery not available for collection.');
            if (!in_array($d['shipment_status'], ['IN_DESTINATION_WAREHOUSE', 'READY_FOR_PICKUP'], true)) {
                throw new RuntimeException("Shipment is {$d['shipment_status']}; warehouse has not released it yet.");
            }
            $stockStmt = $pdo->prepare("
                SELECT id, quantity, zone, bin_location
                FROM warehouse_stock
                WHERE shipment_id = ? AND tenant_id = ? AND branch_id = ? AND is_active = 1
                ORDER BY id DESC LIMIT 1 FOR UPDATE
            ");
            $stockStmt->execute([$d['shipment_id'], $tenant_id, $d['branch_id']]);
            $stock = $stockStmt->fetch(PDO::FETCH_ASSOC);
            if (!$stock || (int)$stock['quantity'] <= 0) throw new RuntimeException('Shipment is not available in destination warehouse stock.');

            record_stock_movement([
                'tenant_id' => $tenant_id,
                'warehouse_stock_id' => (int)$stock['id'],
                'quantity_change' => -(int)$stock['quantity'],
                'previous_quantity' => (int)$stock['quantity'],
                'new_quantity' => 0,
                'movement_type' => 'out',
                'movement_event' => 'released_courier',
                'from_location' => trim(($d['branch_name'] ?: 'Branch') . ' Warehouse · ' . (($stock['zone'] ?? '') ?: '-') . ' / ' . (($stock['bin_location'] ?? '') ?: '-')),
                'to_location' => $_SESSION['user_name'] ?? 'Courier',
                'reference_type' => 'delivery_assignment',
                'reference_id' => (int)$d['id'],
                'reference_label' => $d['assignment_number'],
                'notes' => "COURIER RELEASE: Warehouse to courier for {$d['assignment_number']}",
                'created_by' => $agent_user_id,
            ]);
            $pdo->prepare("UPDATE warehouse_stock
                           SET quantity = 0, is_active = 0, mogadishu_status = 'delivered',
                               notes = CONCAT(COALESCE(notes,''), ' [handed to courier]'), last_updated = NOW()
                           WHERE id = ? AND tenant_id = ?")
                ->execute([(int)$stock['id'], $tenant_id]);
            $pdo->prepare("UPDATE delivery_assignments
                           SET status = 'collected_from_warehouse', collected_at = NOW(), updated_at = NOW()
                           WHERE id = ? AND tenant_id = ? AND assigned_to = ? AND status = 'assigned'")
                ->execute([$d['id'], $tenant_id, $agent_user_id]);
            log_shipment_event(array_merge(ctxBase(), [
                'tenant_id' => $tenant_id,
                'shipment_id' => $d['shipment_id'],
                'event_type' => 'COLLECTED_BY_COURIER',
                'new_status' => $d['shipment_status'],
                'branch_id' => $d['branch_id'],
                'notes' => "Collected from {$d['branch_name']} warehouse by courier for {$d['assignment_number']}.",
            ]));
            $pdo->commit();
            jsonOut(['success' => true, 'message' => "Shipment {$d['shipment_number']} marked as collected."]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonOut(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    if ($action === 'start_delivery') {
        $pdo->beginTransaction();
        try {
            $d = load_own_delivery($pdo, $tenant_id, $agent_user_id, postInt('id'), true);
            if (!$d || $d['status'] !== 'collected_from_warehouse') throw new RuntimeException('Collect the shipment from the warehouse first.');
            $res = update_shipment_status((int)$d['shipment_id'], 'OUT_FOR_DELIVERY', array_merge(ctxBase(), [
                'tenant_id' => $tenant_id,
                'branch_id' => $d['branch_id'],
                'event_type' => 'OUT_FOR_DELIVERY',
                'notes' => "Out for delivery with courier ({$d['assignment_number']}).",
            ]));
            if (!$res['ok']) throw new RuntimeException($res['message'] ?? 'Could not start delivery.');
            $pdo->prepare("UPDATE delivery_assignments
                           SET status = 'out_for_delivery', out_at = NOW(), updated_at = NOW()
                           WHERE id = ? AND tenant_id = ? AND assigned_to = ? AND status = 'collected_from_warehouse'")
                ->execute([$d['id'], $tenant_id, $agent_user_id]);
            $pdo->commit();
            jsonOut(['success' => true, 'message' => 'Delivery started. Customer can now see: Out for Delivery.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonOut(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    if ($action === 'confirm_delivery') {
        $pdo->beginTransaction();
        try {
            $d = load_own_delivery($pdo, $tenant_id, $agent_user_id, postInt('id'), true);
            if (!$d || $d['status'] !== 'out_for_delivery') throw new RuntimeException('This delivery is not currently out for delivery.');
            $receiver_name = postStr('receiver_name');
            $receiver_phone = postStr('receiver_phone') ?: $d['receiver_phone'];
            $method = postStr('verification_method', 'authorized');
            if ($receiver_name === '') throw new RuntimeException('Receiver name is required for proof of delivery.');
            if (strcasecmp(trim((string)$d['receiver_name']), trim($receiver_name)) !== 0) {
                throw new RuntimeException('Receiver name does not match the delivery assignment.');
            }
            if (!in_array($method, ['otp','phone','id_reference','authorized'], true)) throw new RuntimeException('Invalid verification method.');
            if ($method === 'phone' && strcasecmp(trim((string)$d['receiver_phone']), trim($receiver_phone)) !== 0) {
                throw new RuntimeException('Receiver phone does not match the shipment record.');
            }
            $podCheck = $pdo->prepare("SELECT id FROM shipment_releases WHERE tenant_id = ? AND delivery_assignment_id = ? AND release_type = 'delivery' LIMIT 1 FOR UPDATE");
            $podCheck->execute([$tenant_id, $d['id']]);
            if ($podCheck->fetchColumn()) throw new RuntimeException('This delivery already has proof of delivery.');

            $res = update_shipment_status((int)$d['shipment_id'], 'DELIVERED', array_merge(ctxBase(), [
                'tenant_id' => $tenant_id,
                'branch_id' => $d['branch_id'],
                'event_type' => 'DELIVERY_CONFIRMED',
                'notes' => "Delivered by courier ({$d['assignment_number']}) to {$receiver_name}. Verification: {$method}.",
            ]));
            if (!$res['ok']) throw new RuntimeException($res['message'] ?? 'Could not confirm delivery.');

            $otp = postStr('otp_code');
            $ins = $pdo->prepare("INSERT INTO shipment_releases
                (tenant_id, shipment_id, release_type, delivery_assignment_id, receiver_name, receiver_phone,
                 verification_method, otp_code_hash, quantity_released, released_by, released_by_name, branch_id, notes, released_at)
                 VALUES (?,?,'delivery',?,?,?,?,?,?,?,?,?,?,NOW())");
            $ins->execute([
                $tenant_id, $d['shipment_id'], $d['id'], $receiver_name, $receiver_phone, $method,
                $otp !== '' ? password_hash($otp, PASSWORD_DEFAULT) : null,
                (int)$d['shipment_qty'], $agent_user_id, $_SESSION['user_name'] ?? null,
                $d['branch_id'], postStr('notes') ?: null
            ]);
            $pdo->prepare("UPDATE delivery_assignments
                           SET status = 'delivered', completed_at = NOW(), updated_at = NOW()
                           WHERE id = ? AND tenant_id = ? AND assigned_to = ? AND status = 'out_for_delivery'")
                ->execute([$d['id'], $tenant_id, $agent_user_id]);
            $pdo->commit();
            jsonOut(['success' => true, 'message' => "Delivery confirmed with proof of receipt. Shipment {$d['shipment_number']} = DELIVERED."]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonOut(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    if ($action === 'report_failed') {
        $d = load_own_delivery($pdo, $tenant_id, $agent_user_id, postInt('id'));
        if (!$d || !in_array($d['status'], ['out_for_delivery','collected_from_warehouse'], true)) {
            jsonOut(['success' => false, 'message' => 'This delivery cannot be failed in its current state.']);
        }
        $reason = postStr('fail_reason');
        if ($reason === '') jsonOut(['success' => false, 'message' => 'A failure reason is required.']);
        $pdo->prepare("UPDATE delivery_assignments SET status = 'failed', fail_reason = ?, attempts = attempts + 1, updated_at = NOW()
                       WHERE id = ? AND tenant_id = ? AND assigned_to = ?")
            ->execute([$reason, $d['id'], $tenant_id, $agent_user_id]);
        log_shipment_event(array_merge(ctxBase(), [
            'tenant_id' => $tenant_id,
            'shipment_id' => $d['shipment_id'],
            'event_type' => 'DELIVERY_FAILED_REPORT',
            'new_status' => $d['shipment_status'],
            'branch_id' => $d['branch_id'],
            'is_public' => 0,
            'notes' => "Courier reported: {$reason}",
        ]));
        jsonOut(['success' => true, 'message' => 'Failed delivery reported. Warehouse/dispatch will reschedule.']);
    }

    jsonOut(['success' => false, 'message' => 'Unknown action.']);
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2><i class="fas fa-motorcycle text-primary"></i> My Deliveries
      <small class="text-muted">— <?= h($_SESSION['user_name'] ?? '') ?></small></h2>
  </div>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover table-sm" id="delTable">
          <thead class="bg-light"><tr>
            <th>Assignment</th><th>Shipment</th><th>Cargo</th><th>Receiver</th>
            <th>Address</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="detailsModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Delivery Details</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
  <div class="modal-body" id="detailsBody"></div>
</div></div></div>

<div class="modal fade" id="podModal" tabindex="-1"><div class="modal-dialog">
  <form class="modal-content" id="podForm">
    <input type="hidden" name="ajax_action" value="confirm_delivery">
    <input type="hidden" name="id" id="podId">
    <div class="modal-header"><h5 class="modal-title">Proof of Delivery</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label>Receiver Name</label><input name="receiver_name" class="form-control" required></div>
      <div class="form-group"><label>Receiver Phone</label><input name="receiver_phone" class="form-control"></div>
      <div class="form-group"><label>Verification Method</label>
        <select name="verification_method" class="form-control">
          <option value="authorized">Authorized Receiver Confirmation</option>
          <option value="phone">Phone Verification</option>
          <option value="otp">OTP Code</option>
          <option value="id_reference">ID / Reference</option>
        </select></div>
      <div class="form-group"><label>OTP / Reference</label><input name="otp_code" class="form-control"></div>
      <div class="form-group"><label>Delivery Note</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
      <small class="text-muted">This record becomes the auditable proof of delivery.</small>
    </div>
    <div class="modal-footer"><button class="btn btn-success">Confirm Delivery</button></div>
  </form>
</div></div>

<div class="modal fade" id="failModal" tabindex="-1"><div class="modal-dialog">
  <form class="modal-content" id="failForm">
    <input type="hidden" name="ajax_action" value="report_failed">
    <input type="hidden" name="id" id="failId">
    <div class="modal-header"><h5 class="modal-title">Report Failed Delivery</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <div class="modal-body"><textarea name="fail_reason" class="form-control" rows="2" required></textarea></div>
    <div class="modal-footer"><button class="btn btn-danger">Submit Report</button></div>
  </form>
</div></div>

<script>
function esc(s){ const d=document.createElement('div'); d.textContent=s ?? ''; return d.innerHTML; }
function toast(m, ok=true){ alert((ok?'':'ERROR: ')+m); }

function loadDeliveries(){
  $.post('', {ajax_action:'my_deliveries'}, function(res){
    if(!res.success){ toast(res.message,false); return; }
    let html='';
    res.rows.forEach(d=>{
      const badge={assigned:'badge-secondary',collected_from_warehouse:'badge-info',out_for_delivery:'badge-warning'}[d.status]||'badge-secondary';
      let actions = `<button class="btn btn-sm btn-outline-info act-details" data-id="${d.id}">Details</button> `;
      if(d.status==='assigned') actions += `<button class="btn btn-sm btn-primary act-collect" data-id="${d.id}">Collect</button> `;
      if(d.status==='collected_from_warehouse') actions += `<button class="btn btn-sm btn-warning act-start" data-id="${d.id}">Start Delivery</button> `;
      if(d.status==='out_for_delivery') actions += `<button class="btn btn-sm btn-success act-pod" data-id="${d.id}" data-receiver="${esc(d.receiver_name||'')}" data-phone="${esc(d.receiver_phone||'')}">Confirm Delivery</button> `;
      actions += `<button class="btn btn-sm btn-outline-danger act-fail" data-id="${d.id}">Failed</button>`;
      const qtyUnit = `${parseInt(d.shipment_qty || 0)} ${esc(d.quantity_unit || 'Cartons')}`;
      html += `<tr><td><strong>${esc(d.assignment_number)}</strong></td>
        <td>${esc(d.shipment_number)}<br><small>${esc(d.tracking_number||'')}</small></td>
        <td>${esc(d.cargo_description||'')} — ${qtyUnit}</td>
        <td>${esc(d.receiver_name||'')}<br><small>${esc(d.receiver_phone||'')}</small></td>
        <td><small>${esc(d.delivery_address||'-')}</small></td>
        <td><span class="badge ${badge}">${esc((d.status||'').replace(/_/g,' '))}</span></td>
        <td class="text-nowrap">${actions}</td></tr>`;
    });
    $('#delTable tbody').html(html || '<tr><td colspan="7" class="text-center text-muted">No deliveries assigned to you right now.</td></tr>');
  }, 'json').fail(()=>toast('Server error.',false));
}

$(function(){
  loadDeliveries();
  $(document).on('click','.act-details',function(){
    $.post('', {ajax_action:'get_delivery', id:$(this).data('id')}, function(res){
      if(!res.success){ toast(res.message,false); return; }
      const d=res.delivery;
      $('#detailsBody').html(`<p><strong>Assignment:</strong> ${esc(d.assignment_number)}</p>
        <p><strong>Shipment:</strong> ${esc(d.shipment_number)} / ${esc(d.tracking_number)}</p>
        <p><strong>Cargo:</strong> ${esc(d.cargo_description)} — ${parseInt(d.shipment_qty||0)} ${esc(d.quantity_unit||'Cartons')} — ${parseFloat(d.weight_kg||0)} KG</p>
        <p><strong>Receiver:</strong> ${esc(d.receiver_name)} ${esc(d.receiver_phone||'')}</p>
        <p><strong>Address:</strong> ${esc(d.delivery_address || d.receiver_address || '-')}</p>
        <p><strong>Route:</strong> ${esc(d.origin_name||'-')} → ${esc(d.destination_name||'-')}</p>
        <p><strong>Warehouse:</strong> ${esc(d.branch_name||'-')}</p>
        <p><strong>Status:</strong> ${esc(d.status)} / Shipment ${esc(d.shipment_status)}</p>`);
      $('#detailsModal').modal('show');
    }, 'json');
  });
  $(document).on('click','.act-collect',function(){ $.post('', {ajax_action:'collect', id:$(this).data('id')}, r=>{toast(r.message,!!r.success); loadDeliveries();}, 'json'); });
  $(document).on('click','.act-start',function(){ $.post('', {ajax_action:'start_delivery', id:$(this).data('id')}, r=>{toast(r.message,!!r.success); loadDeliveries();}, 'json'); });
  $(document).on('click','.act-pod',function(){ $('#podId').val($(this).data('id')); $('input[name=receiver_name]').val($(this).data('receiver')); $('input[name=receiver_phone]').val($(this).data('phone')); $('#podModal').modal('show'); });
  $('#podForm').on('submit',function(e){ e.preventDefault(); $.post('', $(this).serialize(), r=>{toast(r.message,!!r.success); if(r.success){$('#podModal').modal('hide'); loadDeliveries();}}, 'json'); });
  $(document).on('click','.act-fail',function(){ $('#failId').val($(this).data('id')); $('#failModal').modal('show'); });
  $('#failForm').on('submit',function(e){ e.preventDefault(); $.post('', $(this).serialize(), r=>{toast(r.message,!!r.success); if(r.success){$('#failModal').modal('hide'); this.reset(); loadDeliveries();}}, 'json'); });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
