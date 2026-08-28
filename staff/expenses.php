<?php
// staff/expenses.php — Finance Manager read-only expense visibility.
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
function jsonOut(array $d): void { header('Content-Type: application/json'); echo json_encode($d); exit; }

$branch_name = 'Assigned Branch';
try {
    $st = $pdo->prepare("SELECT branch_name FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1");
    $st->execute([$assigned_branch_id, $tenant_id]);
    $branch_name = (string)($st->fetchColumn() ?: $branch_name);
} catch (Throwable $e) {}

// Compute initial summary server-side so first paint matches the Dashboard KPI.
// The AJAX summary endpoint below uses the SAME query and keeps filter/refresh in sync.
$initial_total = 0.0;
$initial_count = 0;
try {
    $st = $pdo->prepare("
        SELECT COUNT(*) AS n, COALESCE(SUM(e.amount),0) AS total
        FROM expenses e
        LEFT JOIN trucking_trips t ON t.id = e.trip_id AND t.tenant_id = e.tenant_id
        WHERE e.tenant_id = ? AND COALESCE(e.is_active,1)=1
          AND t.branch_id = ?
    ");
    $st->execute([$tenant_id, $assigned_branch_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'total' => 0];
    $initial_count = (int)$row['n'];
    $initial_total = (float)$row['total'];
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    $action = postStr('ajax_action');
    if ($action === 'list_expenses') {
        $search = postStr('search');
        $category = postStr('category', 'all');
        $where = [
            "e.tenant_id = ?",
            "COALESCE(e.is_active,1) = 1",
            "t.branch_id = ?"
        ];
        $params = [$tenant_id, $assigned_branch_id];
        if ($category !== '' && $category !== 'all') {
            $where[] = "e.expense_category = ?";
            $params[] = $category;
        }
        if ($search !== '') {
            $where[] = "(e.expense_number LIKE ? OR e.vendor_name LIKE ? OR e.notes LIKE ? OR t.trip_number LIKE ?)";
            $like = "%{$search}%";
            array_push($params, $like, $like, $like, $like);
        }
        $sqlWhere = 'WHERE ' . implode(' AND ', $where);
        $st = $pdo->prepare("
            SELECT e.id, e.expense_number, e.expense_category, e.amount, e.expense_date,
                   e.vendor_name, e.notes, t.trip_number, u.full_name AS created_by_name
            FROM expenses e
            LEFT JOIN trucking_trips t ON t.id = e.trip_id AND t.tenant_id = e.tenant_id
            LEFT JOIN users u ON u.id = e.created_by
            {$sqlWhere}
            ORDER BY e.expense_date DESC, e.id DESC
            LIMIT 100
        ");
        $st->execute($params);
        jsonOut(['success' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($action === 'summary') {
        $st = $pdo->prepare("
            SELECT COUNT(*) AS expense_count, COALESCE(SUM(e.amount),0) AS total_amount
            FROM expenses e
            LEFT JOIN trucking_trips t ON t.id = e.trip_id AND t.tenant_id = e.tenant_id
            WHERE e.tenant_id = ? AND COALESCE(e.is_active,1)=1
              AND t.branch_id = ?
        ");
        $st->execute([$tenant_id, $assigned_branch_id]);
        jsonOut(['success' => true, 'summary' => $st->fetch(PDO::FETCH_ASSOC)]);
    }
    jsonOut(['success' => false, 'message' => 'Unknown action.']);
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid" style="padding:20px;">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h2><i class="fas fa-receipt text-primary"></i> Trip Operational Expenses</h2>
      <p class="text-muted mb-0">Operational costs associated with trips for <?= h($branch_name) ?>.</p>
      <p class="text-muted mb-0" style="font-size:13px;">These expenses are managed by the Branch Manager and are visible here for financial review.</p>
    </div>
  </div>
  <div class="row mb-3">
    <div class="col-md-4 mb-2"><div class="card border-0 shadow-sm"><div class="card-body"><small>Total Expenses</small><h3 id="sumAmount">$<?= money2($initial_total) ?></h3></div></div></div>
    <div class="col-md-4 mb-2"><div class="card border-0 shadow-sm"><div class="card-body"><small>Expense Records</small><h3 id="sumCount"><?= number_format($initial_count) ?></h3></div></div></div>
  </div>
  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <div class="row mb-3">
        <div class="col-md-5"><input id="searchInput" class="form-control" placeholder="Search expense #, vendor, trip, notes..."></div>
        <div class="col-md-3"><input id="categoryInput" class="form-control" placeholder="Category or all"></div>
      </div>
      <div class="table-responsive">
        <table class="table table-hover table-sm" id="expenseTable">
          <thead class="thead-light"><tr><th>Date</th><th>Expense #</th><th>Category</th><th>Vendor</th><th>Trip</th><th>Amount</th><th>Notes</th><th>By</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script>
function esc(s){ const d=document.createElement('div'); d.textContent=s ?? ''; return d.innerHTML; }
function money(n){ return '$' + (parseFloat(n || 0)).toFixed(2); }
function loadSummary(){
  $.post('', {ajax_action:'summary'}, res => {
    if(!res.success) return;
    $('#sumAmount').text(money(res.summary.total_amount));
    $('#sumCount').text(res.summary.expense_count || 0);
  }, 'json');
}
function loadExpenses(){
  $.post('', {ajax_action:'list_expenses', search:$('#searchInput').val(), category:$('#categoryInput').val() || 'all'}, res => {
    if(!res.success){ $('#expenseTable tbody').html('<tr><td colspan="8" class="text-danger">Unable to load expenses.</td></tr>'); return; }
    let html = '';
    res.rows.forEach(r => {
      html += `<tr><td>${esc(r.expense_date)}</td><td><strong>${esc(r.expense_number)}</strong></td><td>${esc(r.expense_category)}</td><td>${esc(r.vendor_name || '-')}</td><td>${esc(r.trip_number || '-')}</td><td>${money(r.amount)}</td><td>${esc(r.notes || '')}</td><td>${esc(r.created_by_name || '-')}</td></tr>`;
    });
    $('#expenseTable tbody').html(html || '<tr><td colspan="8" class="text-center text-muted">No trip expenses found for this branch.</td></tr>');
  }, 'json');
}
$(function(){ loadSummary(); loadExpenses(); $('#searchInput,#categoryInput').on('input', function(){ clearTimeout(window.expTimer); window.expTimer=setTimeout(loadExpenses,250); }); });
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
