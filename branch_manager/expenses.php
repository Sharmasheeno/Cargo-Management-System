<?php
// branch_manager/expenses.php
// Expenses Management for Branch Manager
// The `expenses` table has no direct branch_id column. Scoping is done by joining
// through trip_id -> trucking_trips.branch_id = assigned branch. This means only
// expenses attached to a trip belonging to this branch are visible/creatable here;
// tenant-wide / branch-less expenses remain a tenant_admin concern.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db_connect.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    die('Database connection failed: $pdo not found. Check config/db_connect.php');
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role_type'] ?? $_SESSION['role'] ?? '') !== 'branch_manager') {
    header("Location: ../login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
$user_name = $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'Branch Manager';

if ($tenant_id <= 0) {
    header("Location: ../login.php?error=no_tenant");
    exit;
}

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
        }
    } catch (PDOException $e) {}
}

if (!$assigned_branch_id) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container mt-4"><div class="alert alert-danger">You are not assigned to any branch. Please contact administrator.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}
$assigned_branch_id = (int)$assigned_branch_id;

$stmt = $pdo->prepare("SELECT id, branch_name, branch_code FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1");
$stmt->execute([$assigned_branch_id, $tenant_id]);
$current_branch = $stmt->fetch(PDO::FETCH_ASSOC);
$branch_name = $current_branch['branch_name'] ?? 'My Branch';

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

function money2($value): string {
    return number_format((float)$value, 2, '.', '');
}

function generateExpenseNumber(PDO $pdo, int $tenantId): string {
    $prefix = 'EXP-' . date('Ymd') . '-';
    do {
        $number = $prefix . random_int(1000, 9999);
        $check = $pdo->prepare("SELECT id FROM expenses WHERE expense_number = ? LIMIT 1");
        $check->execute([$number]);
    } while ($check->fetch());
    return $number;
}

$expense_categories = ['fuel', 'toll', 'loading', 'unloading', 'maintenance', 'parking', 'customs', 'port_fee', 'storage', 'misc'];

// -----------------------------------------------------
// AJAX actions
// -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    $action = postString('ajax_action');

    if ($action === 'list_expenses') {
        $search = postString('search');
        $category = postString('category', 'all');
        $page = max(1, postInt('page', 1));
        $limit = 15;
        $offset = ($page - 1) * $limit;

        $where = ["e.tenant_id = ?", "e.trip_id IN (SELECT id FROM trucking_trips WHERE tenant_id = ? AND branch_id = ?)"];
        $params = [$tenant_id, $tenant_id, $assigned_branch_id];

        if ($category !== '' && $category !== 'all') {
            $where[] = "e.expense_category = ?";
            $params[] = $category;
        }
        if ($search !== '') {
            $where[] = "(e.expense_number LIKE ? OR e.vendor_name LIKE ? OR e.notes LIKE ? OR t.trip_number LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) AS total FROM expenses e LEFT JOIN trucking_trips t ON e.trip_id = t.id $whereSql";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        $pages = max(1, (int)ceil($total / $limit));

        $sumSql = "SELECT COALESCE(SUM(e.amount),0) AS total_amount FROM expenses e LEFT JOIN trucking_trips t ON e.trip_id = t.id $whereSql";
        $stmt = $pdo->prepare($sumSql);
        $stmt->execute($params);
        $totalAmount = (float)($stmt->fetch(PDO::FETCH_ASSOC)['total_amount'] ?? 0);

        $sql = "
            SELECT e.*, t.trip_number, c.container_number
            FROM expenses e
            LEFT JOIN trucking_trips t ON e.trip_id = t.id
            LEFT JOIN containers c ON t.container_id = c.id
            $whereSql
            ORDER BY e.expense_date DESC, e.id DESC
            LIMIT $limit OFFSET $offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ob_start();
        ?>
        <div class="mb-3"><span class="badge badge-secondary" style="font-size:13px;">Total shown period: $<?= money2($totalAmount) ?></span></div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle">
                <thead class="thead-light">
                    <tr><th>Expense #</th><th>Category</th><th>Trip</th><th>Vendor</th><th>Amount</th><th>Date</th><th>Notes</th></tr>
                </thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                    <tr>
                        <td><strong><?= h($r['expense_number']) ?></strong></td>
                        <td><span class="badge badge-info text-capitalize"><?= h(str_replace('_', ' ', $r['expense_category'])) ?></span></td>
                        <td><?= h($r['trip_number'] ?? '-') ?><?= !empty($r['container_number']) ? '<br><small class="text-muted">' . h($r['container_number']) . '</small>' : '' ?></td>
                        <td><?= h($r['vendor_name'] ?? '-') ?></td>
                        <td>$<?= money2($r['amount']) ?></td>
                        <td><?= h($r['expense_date'] ?? '-') ?></td>
                        <td><small><?= h($r['notes'] ?? '') ?></small></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">No expenses found for your branch trips</td></tr>
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

        jsonOut(['success' => true, 'html' => $html, 'pagination' => $pagination, 'total' => $total, 'total_amount' => money2($totalAmount)]);
    }

    if ($action === 'get_branch_trips') {
        $stmt = $pdo->prepare("
            SELECT t.id, t.trip_number, c.container_number
            FROM trucking_trips t
            LEFT JOIN containers c ON t.container_id = c.id
            WHERE t.tenant_id = ? AND t.branch_id = ?
            ORDER BY t.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$tenant_id, $assigned_branch_id]);
        jsonOut(['success' => true, 'trips' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'create_expense') {
        $trip_id = postInt('trip_id');
        $category = postString('expense_category');
        $amount = (float)str_replace(',', '.', postString('amount', '0'));
        $expense_date = postString('expense_date', date('Y-m-d'));
        $vendor_name = postString('vendor_name');
        $notes = postString('notes');

        if ($trip_id <= 0) jsonOut(['success' => false, 'message' => 'Please select a trip from your branch.']);
        if ($category === '') jsonOut(['success' => false, 'message' => 'Please select an expense category.']);
        if ($amount <= 0) jsonOut(['success' => false, 'message' => 'Amount must be greater than 0.']);

        $check = $pdo->prepare("SELECT id FROM trucking_trips WHERE id = ? AND tenant_id = ? AND branch_id = ? LIMIT 1");
        $check->execute([$trip_id, $tenant_id, $assigned_branch_id]);
        if (!$check->fetch(PDO::FETCH_ASSOC)) jsonOut(['success' => false, 'message' => 'Selected trip does not belong to your branch.']);

        try {
            $number = generateExpenseNumber($pdo, $tenant_id);
            $stmt = $pdo->prepare("
                INSERT INTO expenses (tenant_id, expense_number, expense_category, amount, expense_date, vendor_name, trip_id, notes, created_by, created_at, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)
            ");
            $stmt->execute([$tenant_id, $number, $category, $amount, $expense_date ?: date('Y-m-d'), $vendor_name ?: null, $trip_id, $notes ?: null, $user_id]);
            jsonOut(['success' => true, 'message' => "Expense {$number} recorded.", 'id' => (int)$pdo->lastInsertId()]);
        } catch (Throwable $e) {
            jsonOut(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }

    jsonOut(['success' => false, 'message' => 'Unknown action.']);
}

// -----------------------------------------------------
// Stats
// -----------------------------------------------------
$stats = ['count' => 0, 'total_amount' => 0, 'this_month' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS count, COALESCE(SUM(e.amount),0) AS total_amount,
               COALESCE(SUM(CASE WHEN MONTH(e.expense_date) = MONTH(CURDATE()) AND YEAR(e.expense_date) = YEAR(CURDATE()) THEN e.amount ELSE 0 END),0) AS this_month
        FROM expenses e
        WHERE e.tenant_id = ? AND e.trip_id IN (SELECT id FROM trucking_trips WHERE tenant_id = ? AND branch_id = ?)
    ");
    $stmt->execute([$tenant_id, $tenant_id, $assigned_branch_id]);
    $stats = array_merge($stats, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses - <?= h($branch_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { background:#f4f6f9; }
        .page-wrap { padding: 20px; }
        .hero { background: linear-gradient(135deg,#2D1859,#4B2C85); color:#fff; border-radius:18px; padding:22px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
        .hero h3 { margin:0; font-weight:700; }
        .hero small { opacity:.9; }
        .stat-card { background:#fff; border-radius:16px; padding:18px; box-shadow:0 6px 18px rgba(0,0,0,.06); border:1px solid #eee; }
        .stat-card .num { font-size:26px; font-weight:800; color:#2D1859; }
        .panel { background:#fff; border-radius:16px; padding:18px; box-shadow:0 6px 18px rgba(0,0,0,.06); border:1px solid #eee; }
        .btn-main { background:#2D1859; color:#fff; border:0; }
        .btn-main:hover { background:#1F0F3D; color:#fff; }
    </style>
</head>
<body>
<div class="page-wrap">
    <div class="hero">
        <div>
            <h3><i class="fas fa-receipt"></i> Expenses</h3>
            <small>Branch: <?= h($branch_name) ?> · Trip-linked expenses only</small>
        </div>
        <button class="btn btn-light" data-toggle="modal" data-target="#createExpenseModal"><i class="fas fa-plus-circle"></i> New Expense</button>
    </div>

    <div class="row mb-3">
        <div class="col-md-4 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$stats['count']) ?></div><div>Total Expenses</div></div></div>
        <div class="col-md-4 mb-3"><div class="stat-card"><div class="num">$<?= money2($stats['total_amount']) ?></div><div>All-Time Amount</div></div></div>
        <div class="col-md-4 mb-3"><div class="stat-card"><div class="num">$<?= money2($stats['this_month']) ?></div><div>This Month</div></div></div>
    </div>

    <div class="panel">
        <div class="row mb-3">
            <div class="col-md-6"><input type="text" id="searchInput" class="form-control" placeholder="Search expense #, vendor, trip, notes..."></div>
            <div class="col-md-3">
                <select id="categoryFilter" class="form-control">
                    <option value="all">All Categories</option>
                    <?php foreach ($expense_categories as $c): ?>
                        <option value="<?= h($c) ?>" class="text-capitalize"><?= h(ucfirst(str_replace('_', ' ', $c))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3"><button id="refreshBtn" class="btn btn-main btn-block"><i class="fas fa-sync"></i> Refresh</button></div>
        </div>
        <div id="expensesTable"><div class="text-center py-5"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>
        <div id="paginationBox"></div>
    </div>
</div>

<!-- Create Expense Modal -->
<div class="modal fade" id="createExpenseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="createExpenseForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Record Expense</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="ajax_action" value="create_expense">
                <div class="form-group">
                    <label>Trip <span class="text-danger">*</span></label>
                    <select name="trip_id" id="tripSelect" class="form-control" required>
                        <option value="">Loading trips...</option>
                    </select>
                    <small class="form-text text-muted">Only trips belonging to your branch are shown.</small>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Category <span class="text-danger">*</span></label>
                        <select name="expense_category" class="form-control" required>
                            <option value="">Select category</option>
                            <?php foreach ($expense_categories as $c): ?>
                                <option value="<?= h($c) ?>"><?= h(ucfirst(str_replace('_', ' ', $c))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Amount <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Date</label>
                        <input type="date" name="expense_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group col-md-6">
                        <label>Vendor</label>
                        <input type="text" name="vendor_name" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-main"><i class="fas fa-save"></i> Save Expense</button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentPage = 1;
function toast(msg) { alert(msg); }

function loadExpenses(page) {
    page = page || 1;
    currentPage = page;
    $('#expensesTable').html('<div class="text-center py-5"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
    $.post('', { ajax_action: 'list_expenses', page: page, search: $('#searchInput').val(), category: $('#categoryFilter').val() }, function(res) {
        if (res.success) {
            $('#expensesTable').html(res.html);
            $('#paginationBox').html(res.pagination);
        } else {
            $('#expensesTable').html('<div class="alert alert-danger">' + (res.message || 'Error') + '</div>');
        }
    }, 'json').fail(function() { $('#expensesTable').html('<div class="alert alert-danger">Server error.</div>'); });
}

function loadTripOptions() {
    $.post('', { ajax_action: 'get_branch_trips' }, function(res) {
        if (res.success) {
            let html = '<option value="">Select a trip</option>';
            if (!res.trips.length) html = '<option value="">No trips found in your branch - create a trip first</option>';
            res.trips.forEach(t => { html += `<option value="${t.id}">${$('<div>').text(t.trip_number).html()}${t.container_number ? ' - ' + $('<div>').text(t.container_number).html() : ''}</option>`; });
            $('#tripSelect').html(html);
        }
    }, 'json');
}

let searchTimer = null;
$(document).on('keyup', '#searchInput', function() { clearTimeout(searchTimer); searchTimer = setTimeout(() => loadExpenses(1), 350); });
$('#categoryFilter, #refreshBtn').on('change click', function() { loadExpenses(1); });
$(document).on('click', '#paginationBox .page-link', function(e) { e.preventDefault(); loadExpenses(parseInt($(this).data('page'))); });

$('#createExpenseModal').on('show.bs.modal', loadTripOptions);

$('#createExpenseForm').on('submit', function(e) {
    e.preventDefault();
    $.post('', $(this).serialize(), function(res) {
        toast(res.message || (res.success ? 'Saved' : 'Error'));
        if (res.success) {
            $('#createExpenseModal').modal('hide');
            $('#createExpenseForm')[0].reset();
            loadExpenses(1);
        }
    }, 'json').fail(function() { toast('Server error.'); });
});

$(function() { loadExpenses(); });
</script>
</body>
</html>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
