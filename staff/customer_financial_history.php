<?php
// staff/customer_financial_history.php — Finance Manager customer ledger view.
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';

$current_role_type = $_SESSION['role_type'] ?? $_SESSION['role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($current_role_type, staffFamilyRoleTypes(), true)) {
    header("Location: ../login.php");
    exit;
}
if (!in_array($current_role_type, staffFinanceRoleTypes(), true)) {
    header("Location: ../staff/dashboard.php?error=access_denied");
    exit;
}

$tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];
if ($tenant_id <= 0) {
    header("Location: ../login.php?error=no_tenant");
    exit;
}

$assigned_branch_id = (int)($_SESSION['assigned_branch_id'] ?? 0);
if ($assigned_branch_id <= 0) {
    try {
        $st = $pdo->prepare("SELECT branch_id FROM user_branch_assignments WHERE user_id = ? AND is_primary = 1 LIMIT 1");
        $st->execute([$user_id]);
        $assigned_branch_id = (int)$st->fetchColumn();
    } catch (Throwable $e) {}
}
if ($assigned_branch_id <= 0) {
    try {
        $st = $pdo->prepare("SELECT default_branch_id FROM users WHERE id = ? AND tenant_id = ? LIMIT 1");
        $st->execute([$user_id, $tenant_id]);
        $assigned_branch_id = (int)$st->fetchColumn();
    } catch (Throwable $e) {}
}
if ($assigned_branch_id > 0) $_SESSION['assigned_branch_id'] = $assigned_branch_id;

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function money2($v): string { return number_format((float)$v, 2, '.', ''); }
function postStr(string $k, string $d = ''): string { $v = $_POST[$k] ?? $d; return is_array($v) ? $d : trim((string)$v); }
function postInt(string $k, int $d = 0): int { $v = $_POST[$k] ?? $d; return is_numeric($v) ? (int)$v : $d; }
function jsonOut(array $d): void { header('Content-Type: application/json'); echo json_encode($d); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    $action = postStr('ajax_action');

    if ($action === 'list_customers') {
        $search = postStr('search');
        $where = ["c.tenant_id = ?", "c.is_active = 1"];
        $params = [$tenant_id];
        if ($search !== '') {
            $where[] = "(c.customer_name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
            $like = "%{$search}%";
            array_push($params, $like, $like, $like);
        }
        $sqlWhere = 'WHERE ' . implode(' AND ', $where);
        $st = $pdo->prepare("
            SELECT c.id, c.customer_name, c.phone, c.email, c.debt_amount, c.credit_limit,
                   c.loyalty_points, c.total_spent,
                   COALESCE(SUM(CASE WHEN i.id IS NOT NULL THEN i.total_amount ELSE 0 END),0) AS branch_invoiced,
                   COALESCE(SUM(CASE WHEN i.id IS NOT NULL THEN i.paid_amount ELSE 0 END),0) AS branch_paid,
                   COALESCE(SUM(CASE WHEN i.id IS NOT NULL THEN i.total_amount - i.paid_amount ELSE 0 END),0) AS branch_balance
            FROM customers c
            LEFT JOIN invoices i ON i.customer_id = c.id AND i.tenant_id = c.tenant_id AND COALESCE(i.is_active,1)=1
            LEFT JOIN trucking_trips t ON t.id = i.trip_id AND t.tenant_id = i.tenant_id
            {$sqlWhere}
              AND (i.id IS NULL OR t.branch_id = ?)
            GROUP BY c.id
            ORDER BY branch_balance DESC, c.customer_name ASC
            LIMIT 100
        ");
        $params2 = array_merge($params, [$assigned_branch_id]);
        $st->execute($params2);
        jsonOut(['success' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'customer_history') {
        $customer_id = postInt('customer_id');
        $cust = $pdo->prepare("SELECT id, customer_name, phone, email, debt_amount, credit_limit, loyalty_points, total_spent FROM customers WHERE id = ? AND tenant_id = ? AND is_active = 1 LIMIT 1");
        $cust->execute([$customer_id, $tenant_id]);
        $customer = $cust->fetch(PDO::FETCH_ASSOC);
        if (!$customer) jsonOut(['success' => false, 'message' => 'Customer not found.']);

        $scopeCheck = $pdo->prepare("
            SELECT 1
            FROM invoices i
            JOIN trucking_trips t ON t.id = i.trip_id AND t.tenant_id = i.tenant_id
            WHERE i.tenant_id = ? AND i.customer_id = ? AND COALESCE(i.is_active,1)=1 AND t.branch_id = ?
            LIMIT 1
        ");
        $scopeCheck->execute([$tenant_id, $customer_id, $assigned_branch_id]);
        $hasScopedInvoice = (bool)$scopeCheck->fetchColumn();
        $scopeReceipt = $pdo->prepare("SELECT 1 FROM receipts WHERE tenant_id = ? AND customer_id = ? AND branch_id = ? LIMIT 1");
        $scopeReceipt->execute([$tenant_id, $customer_id, $assigned_branch_id]);
        if (!$hasScopedInvoice && !$scopeReceipt->fetchColumn()) {
            jsonOut(['success' => false, 'message' => 'Customer not found.']);
        }

        $inv = $pdo->prepare("
            SELECT i.id, i.invoice_number, i.invoice_date, i.due_date, i.total_amount, i.paid_amount,
                   (i.total_amount - i.paid_amount) AS balance, i.status, t.trip_number
            FROM invoices i
            LEFT JOIN trucking_trips t ON t.id = i.trip_id AND t.tenant_id = i.tenant_id
            WHERE i.tenant_id = ? AND i.customer_id = ? AND COALESCE(i.is_active,1)=1
              AND t.branch_id = ?
            ORDER BY i.invoice_date DESC, i.id DESC
        ");
        $inv->execute([$tenant_id, $customer_id, $assigned_branch_id]);
        $invoices = $inv->fetchAll(PDO::FETCH_ASSOC);

        $rec = $pdo->prepare("
            SELECT r.id, r.receipt_number, r.payment_date, r.amount, r.payment_method,
                   r.reference_number, i.invoice_number
            FROM receipts r
            LEFT JOIN invoices i ON i.id = r.invoice_id AND i.tenant_id = r.tenant_id
            WHERE r.tenant_id = ? AND r.customer_id = ? AND r.branch_id = ?
            ORDER BY r.payment_date DESC, r.id DESC
        ");
        $rec->execute([$tenant_id, $customer_id, $assigned_branch_id]);
        $receipts = $rec->fetchAll(PDO::FETCH_ASSOC);

        jsonOut(['success' => true, 'customer' => $customer, 'invoices' => $invoices, 'receipts' => $receipts]);
    }

    jsonOut(['success' => false, 'message' => 'Unknown action.']);
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid" style="padding:20px;">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h2><i class="fas fa-users text-primary"></i> Customers</h2>
      <p class="text-muted mb-0">Branch customer accounts &mdash; total invoiced, total paid, outstanding balance, and loyalty. Open a customer for full financial history.</p>
    </div>
  </div>
  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <input id="searchInput" class="form-control mb-3" placeholder="Search customer name, phone, email...">
      <div class="table-responsive">
        <table class="table table-hover table-sm" id="customerTable">
          <thead class="thead-light"><tr><th>Customer</th><th>Phone</th><th>Total Invoiced</th><th>Total Paid</th><th>Outstanding</th><th>Credit Limit</th><th>Loyalty</th><th>Action</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="historyModal" tabindex="-1"><div class="modal-dialog modal-xl"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Customer Account &mdash; Summary, Invoices, Payments, Receipts</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
  <div class="modal-body" id="historyBody"></div>
</div></div></div>

<script>
function esc(s){ const d=document.createElement('div'); d.textContent=s ?? ''; return d.innerHTML; }
function money(n){ return '$' + (parseFloat(n || 0)).toFixed(2); }
function loadCustomers(){
  $.post('', {ajax_action:'list_customers', search:$('#searchInput').val()}, res => {
    if(!res.success){ $('#customerTable tbody').html('<tr><td colspan="8" class="text-danger">Unable to load customers.</td></tr>'); return; }
    let html = '';
    res.rows.forEach(c => {
      html += `<tr><td><strong>${esc(c.customer_name)}</strong><br><small>${esc(c.email || '')}</small></td><td>${esc(c.phone || '')}</td><td>${money(c.branch_invoiced)}</td><td>${money(c.branch_paid)}</td><td><strong>${money(c.branch_balance)}</strong></td><td>${money(c.credit_limit)}</td><td>${parseFloat(c.loyalty_points || 0).toFixed(2)}</td><td><button class="btn btn-sm btn-info view-history" data-id="${c.id}">View</button></td></tr>`;
    });
    $('#customerTable tbody').html(html || '<tr><td colspan="8" class="text-center text-muted">No customers found.</td></tr>');
  }, 'json');
}
function showHistory(id){
  $.post('', {ajax_action:'customer_history', customer_id:id}, res => {
    if(!res.success){ alert(res.message || 'Unable to load history.'); return; }
    const c = res.customer;
    let inv = '', rec = '';
    res.invoices.forEach(i => inv += `<tr><td>${esc(i.invoice_number)}</td><td>${esc(i.invoice_date)}</td><td>${esc(i.trip_number || '-')}</td><td>${money(i.total_amount)}</td><td>${money(i.paid_amount)}</td><td>${money(i.balance)}</td><td>${esc(i.status)}</td></tr>`);
    res.receipts.forEach(r => rec += `<tr><td>${esc(r.receipt_number)}</td><td>${esc(r.payment_date)}</td><td>${esc(r.invoice_number || '-')}</td><td>${money(r.amount)}</td><td>${esc(r.payment_method || '-')}</td><td>${esc(r.reference_number || '')}</td></tr>`);
    $('#historyBody').html(`<h4>${esc(c.customer_name)}</h4><p class="text-muted">${esc(c.phone || '')} ${esc(c.email || '')}</p>
      <div class="row mb-3"><div class="col-md-3"><strong>Total Spent:</strong><br>${money(c.total_spent)}</div><div class="col-md-3"><strong>Debt Amount:</strong><br>${money(c.debt_amount)}</div><div class="col-md-3"><strong>Credit Limit:</strong><br>${money(c.credit_limit)}</div><div class="col-md-3"><strong>Loyalty:</strong><br>${parseFloat(c.loyalty_points || 0).toFixed(2)}</div></div>
      <h5>Invoices</h5><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Invoice</th><th>Date</th><th>Trip</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th></tr></thead><tbody>${inv || '<tr><td colspan="7" class="text-muted">No invoices.</td></tr>'}</tbody></table></div>
      <h5>Receipts / Payments</h5><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Receipt</th><th>Date</th><th>Invoice</th><th>Amount</th><th>Method</th><th>Reference</th></tr></thead><tbody>${rec || '<tr><td colspan="6" class="text-muted">No receipts.</td></tr>'}</tbody></table></div>`);
    $('#historyModal').modal('show');
  }, 'json');
}
$(function(){ loadCustomers(); $('#searchInput').on('input', function(){ clearTimeout(window.cfhTimer); window.cfhTimer=setTimeout(loadCustomers,250); }); $(document).on('click','.view-history',function(){ showHistory($(this).data('id')); }); });
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
