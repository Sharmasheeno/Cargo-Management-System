<?php
// ============================================================================
// driver/deliveries.php — Delivery Agent / Courier Portal: My Deliveries
// ----------------------------------------------------------------------------
// Authenticated courier role (users.role_type = 'delivery_agent'). The agent
// sees ONLY deliveries assigned to them. Flow per requirement #21/#22:
//   assigned -> collected_from_warehouse -> out_for_delivery -> delivered
//   (or failed / returned with reason)
// Final DELIVERED requires proof of delivery: receiver name + verification.
// No access to warehouse management, invoices or other agents' work.
// ============================================================================
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
function ctxBase(): array {
    return ['performed_by' => $_SESSION['user_id'] ?? null, 'performer_name' => $_SESSION['user_name'] ?? null];
}

ensureShipmentSchema($pdo);

/** Load one assignment owned by THIS agent only. */
function load_own_delivery(PDO $pdo, int $tenant_id, int $agent_id, int $da_id): ?array {
    $st = $pdo->prepare("SELECT da.*, s.shipment_number, s.tracking_number, s.cargo_description,
                                s.quantity AS shipment_qty, s.current_status AS shipment_status,
                                b.branch_name
                         FROM delivery_assignments da
                         JOIN shipments s ON s.id = da.shipment_id
                         LEFT JOIN branches b ON b.id = da.branch_id
                         WHERE da.id = ? AND da.tenant_id = ? AND da.assigned_to = ?
                         FOR UPDATE");
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
            SELECT da.*, s.shipment_number, s.tracking_number, s.cargo_description, s.quantity AS shipment_qty
            FROM delivery_assignments da
            JOIN shipments s ON s.id = da.shipment_id
            WHERE da.tenant_id = ? AND da.assigned_to = ?
              AND da.status IN ('assigned','collected_from_warehouse','out_for_delivery')
            ORDER BY FIELD(da.status,'out_for_delivery','collected_from_warehouse','assigned'), da.created_at DESC");
        $st->execute([$tenant_id, $agent_user_id]);
        jsonOut(['success' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // Collect from warehouse: agent physically picks up the shipment
    if ($action === 'collect') {
        $d = load_own_delivery($pdo, $tenant_id, $agent_user_id, postInt('id'));
        if (!$d || $d['status'] !== 'assigned') jsonOut(['success' => false, 'message' => 'Delivery not available for collection.']);
        if (!in_array($d['shipment_status'], ['IN_DESTINATION_WAREHOUSE', 'READY_FOR_PICKUP'], true)) {
            jsonOut(['success' => false, 'message' => "Shipment is {$d['shipment_status']}; warehouse has not released it yet."]);
        }
        // Close out active stock rows: the shipment leaves the warehouse
        $pdo->prepare("UPDATE warehouse_stock SET is_active = 0, quantity = 0,
                        mogadishu_status = 'delivered',
                        notes = CONCAT(COALESCE(notes,''), ' [handed to courier]')
                        WHERE shipment_id = ? AND tenant_id = ? AND is_active = 1")
            ->execute([$d['shipment_id'], $tenant_id]);
        $pdo->prepare("UPDATE delivery_assignments SET status = 'collected_from_warehouse', collected_at = NOW(), updated_at = NOW()
                       WHERE id = ?")->execute([$d['id']]);
        log_shipment_event(array_merge(ctxBase(), [
            'tenant_id' => $tenant_id, 'shipment_id' => $d['shipment_id'],
            'event_type' => 'COLLECTED_BY_COURIER', 'new_status' => $d['shipment_status'],
            'branch_id' => $d['branch_id'],
            'notes' => "Collected from {$d['branch_name']} warehouse by courier for {$d['assignment_number']}.",
        ]));
        jsonOut(['success' => true, 'message' => "Shipment {$d['shipment_number']} marked as collected."]);
    }

    // Start delivery run: customer tracking flips to OUT_FOR_DELIVERY
    if ($action === 'start_delivery') {
        $d = load_own_delivery($pdo, $tenant_id, $agent_user_id, postInt('id'));
        if (!$d || $d['status'] !== 'collected_from_warehouse') jsonOut(['success' => false, 'message' => 'Collect the shipment from the warehouse first.']);
        $pdo->prepare("UPDATE delivery_assignments SET status = 'out_for_delivery', out_at = NOW(), updated_at = NOW() WHERE id = ?")
            ->execute([$d['id']]);
        $res = update_shipment_status((int)$d['shipment_id'], 'OUT_FOR_DELIVERY', array_merge(ctxBase(), [
            'tenant_id' => $tenant_id, 'branch_id' => $d['branch_id'],
            'event_type' => 'OUT_FOR_DELIVERY',
            'notes' => "Out for delivery with courier ({$d['assignment_number']}).",
        ]));
        jsonOut($res['ok'] ? ['success' => true, 'message' => 'Delivery started. Customer can now see: Out for Delivery.'] : $res);
    }

    // Confirm delivery WITH proof of delivery
    if ($action === 'confirm_delivery') {
        $d = load_own_delivery($pdo, $tenant_id, $agent_user_id, postInt('id'));
        if (!$d || $d['status'] !== 'out_for_delivery') jsonOut(['success' => false, 'message' => 'This delivery is not currently out for delivery.']);
        $receiver_name = postStr('receiver_name');
        $method = postStr('verification_method', 'authorized');
        if ($receiver_name === '') jsonOut(['success' => false, 'message' => 'Receiver name is required for proof of delivery.']);
        if (!in_array($method, ['otp','phone','id_reference','authorized'], true)) jsonOut(['success' => false, 'message' => 'Invalid verification method.']);
        if ($method === 'phone' && strcasecmp(trim((string)$d['receiver_phone']), postStr('receiver_phone')) !== 0) {
            jsonOut(['success' => false, 'message' => 'Receiver phone does not match the shipment record.']);
        }

        // Proof-of-delivery record (auditable; raw OTP never stored)
        $ins = $pdo->prepare("INSERT INTO shipment_releases
            (tenant_id, shipment_id, release_type, delivery_assignment_id, receiver_name, receiver_phone,
             verification_method, otp_code_hash, quantity_released, released_by, released_by_name, branch_id, notes, released_at)
             VALUES (?,?,'delivery',?,?,?,?,?,?,?,?,?,?,NOW())");
        $otp = postStr('otp_code');
        $ins->execute([$tenant_id, $d['shipment_id'], $d['id'], $receiver_name,
            postStr('receiver_phone') ?: $d['receiver_phone'], $method,
            $otp !== '' ? password_hash($otp, PASSWORD_DEFAULT) : null,
            (int)$d['shipment_qty'], $agent_user_id, $_SESSION['user_name'] ?? null,
            $d['branch_id'], postStr('notes') ?: null]);

        $res = update_shipment_status((int)$d['shipment_id'], 'DELIVERED', array_merge(ctxBase(), [
            'tenant_id' => $tenant_id, 'branch_id' => $d['branch_id'],
            'event_type' => 'DELIVERY_CONFIRMED',
            'notes' => "Delivered by courier ({$d['assignment_number']}) to {$receiver_name}. Verification: {$method}.",
        ]));
        if (!$res['ok']) jsonOut($res);
        $pdo->prepare("UPDATE delivery_assignments SET status = 'delivered', completed_at = NOW(), updated_at = NOW() WHERE id = ?")
            ->execute([$d['id']]);
        jsonOut(['success' => true, 'message' => "Delivery confirmed with proof of receipt. Shipment {$d['shipment_number']} = DELIVERED."]);
    }

    // Report failed delivery / unreachable receiver
    if ($action === 'report_failed') {
        $d = load_own_delivery($pdo, $tenant_id, $agent_user_id, postInt('id'));
        if (!$d || !in_array($d['status'], ['out_for_delivery','collected_from_warehouse'], true)) {
            jsonOut(['success' => false, 'message' => 'This delivery cannot be failed in its current state.']);
        }
        $reason = postStr('fail_reason');
        if ($reason === '') jsonOut(['success' => false, 'message' => 'A failure reason is required.']);
        $pdo->prepare("UPDATE delivery_assignments SET status = 'failed', fail_reason = ?, attempts = attempts + 1, updated_at = NOW()
                       WHERE id = ?")->execute([$reason, $d['id']]);
        log_shipment_event(array_merge(ctxBase(), [
            'tenant_id' => $tenant_id, 'shipment_id' => $d['shipment_id'],
            'event_type' => 'DELIVERY_FAILED_REPORT', 'new_status' => $d['shipment_status'],
            'branch_id' => $d['branch_id'], 'is_public' => 0,
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

<!-- Proof of delivery modal -->
<div class="modal fade" id="podModal" tabindex="-1"><div class="modal-dialog">
  <form class="modal-content" id="podForm">
    <input type="hidden" name="ajax_action" value="confirm_delivery">
    <input type="hidden" name="id" id="podId">
    <div class="modal-header"><h5 class="modal-title">Proof of Delivery</h5>
      <button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label>Receiver Name (actual person)</label><input name="receiver_name" class="form-control" required></div>
      <div class="form-group"><label>Receiver Phone</label><input name="receiver_phone" class="form-control"></div>
      <div class="form-group"><label>Verification Method</label>
        <select name="verification_method" class="form-control">
          <option value="otp">OTP Code</option>
          <option value="phone">Phone Verification</option>
          <option value="id_reference">ID / Reference</option>
          <option value="authorized">Authorized Receiver Confirmation</option>
        </select></div>
      <div class="form-group"><label>OTP / Reference (optional)</label><input name="otp_code" class="form-control"></div>
      <div class="form-group"><label>Delivery Note</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
      <small class="text-muted">This record becomes the auditable proof of delivery for this shipment.</small>
    </div>
    <div class="modal-footer"><button class="btn btn-success">Confirm Delivery</button></div>
  </form>
</div></div>

<!-- Failure report modal -->
<div class="modal fade" id="failModal" tabindex="-1"><div class="modal-dialog">
  <form class="modal-content" id="failForm">
    <input type="hidden" name="ajax_action" value="report_failed">
    <input type="hidden" name="id" id="failId">
    <div class="modal-header"><h5 class="modal-title">Report Failed Delivery</h5>
      <button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label>Reason</label>
        <textarea name="fail_reason" class="form-control" rows="2" required placeholder="e.g. Receiver unreachable at address"></textarea></div>
    </div>
    <div class="modal-footer"><button class="btn btn-danger">Submit Report</button></div>
  </form>
</div></div>

<script>
function esc(s){ const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }
function toast(m, ok=true){ alert((ok?'':'ERROR: ')+m); }

function loadDeliveries(){
    $.post('', { ajax_action:'my_deliveries' }, function(res){
        if(!res.success){ toast(res.message,false); return; }
        let html='';
        res.rows.forEach(d=>{
            const badge = { assigned:'badge-secondary', collected_from_warehouse:'badge-info', out_for_delivery:'badge-warning' }[d.status] || 'badge-secondary';
            let actions = '';
            if (d.status === 'assigned') {
                actions = `<button class="btn btn-sm btn-primary act-collect" data-id="${d.id}"><i class="fas fa-box"></i> Collect</button>`;
            } else if (d.status === 'collected_from_warehouse') {
                actions = `<button class="btn btn-sm btn-warning act-start" data-id="${d.id}"><i class="fas fa-play"></i> Start Delivery</button>`;
            } else if (d.status === 'out_for_delivery') {
                actions = `<button class="btn btn-sm btn-success act-pod" data-id="${d.id}" data-receiver="${esc(d.receiver_name||'')}" data-phone="${esc(d.receiver_phone||'')}"><i class="fas fa-check-circle"></i> Confirm Delivery</button>`;
            }
            actions += ` <button class="btn btn-sm btn-outline-danger act-fail" data-id="${d.id}">Failed</button>`;
            html += `<tr>
              <td><strong>${esc(d.assignment_number)}</strong></td>
              <td>${esc(d.shipment_number)}<br><small class="text-muted">${esc(d.tracking_number||'')}</small></td>
              <td>${esc(d.cargo_description||'')} — ${d.shipment_qty} pcs</td>
              <td>${esc(d.receiver_name||'')}<br><small>${esc(d.receiver_phone||'')}</small></td>
              <td><small>${esc(d.delivery_address||'-')}</small></td>
              <td><span class="badge ${badge}">${esc(d.status.replace(/_/g,' '))}</span></td>
              <td class="text-nowrap">${actions}</td>
            </tr>`;
        });
        $('#delTable tbody').html(html || '<tr><td colspan="7" class="text-center text-muted">No deliveries assigned to you right now.</td></tr>');
    },'json').fail(()=>toast('Server error.',false));
}

$(function(){
    loadDeliveries();
    $(document).on('click','.act-collect',function(){
        $.post('', { ajax_action:'collect', id:$(this).data('id') }, function(res){ toast(res.message, !!res.success); loadDeliveries(); },'json');
    });
    $(document).on('click','.act-start',function(){
        $.post('', { ajax_action:'start_delivery', id:$(this).data('id') }, function(res){ toast(res.message, !!res.success); loadDeliveries(); },'json');
    });
    $(document).on('click','.act-pod',function(){
        $('#podId').val($(this).data('id'));
        $('input[name=receiver_name]').val($(this).data('receiver'));
        $('input[name=receiver_phone]').val($(this).data('phone'));
        $('#podModal').modal('show');
    });
    $('#podForm').on('submit',function(e){
        e.preventDefault();
        $.post('', $(this).serialize(), function(res){
            toast(res.message, !!res.success);
            if(res.success){ $('#podModal').modal('hide'); loadDeliveries(); }
        },'json');
    });
    $(document).on('click','.act-fail',function(){
        $('#failId').val($(this).data('id'));
        $('#failModal').modal('show');
    });
    $('#failForm').on('submit',function(e){
        e.preventDefault();
        $.post('', $(this).serialize(), function(res){
            toast(res.message, !!res.success);
            if(res.success){ $('#failModal').modal('hide'); $('#failForm')[0].reset(); loadDeliveries(); }
        },'json');
    });
});
</script>
</body>
</html>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
