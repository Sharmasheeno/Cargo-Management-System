<?php
// branch_manager/receipts.php
// Receipts Browser & Reprint for Branch Manager (Finance section of the sidebar)
//
// NOTE ON NAMING: branch_manager/receptions.php (Branch Operations section) is,
// despite its filename, the full payment-receipt workflow: it creates/edits/deletes
// rows in the `receipts` table, manages bank accounts and loyalty points. This file
// is the separate "Receipts" sidebar link (Finance section) and is deliberately a
// lighter, read-only browse/print view of the same `receipts` table for this branch -
// it does not duplicate the create/edit/delete/bank-account/loyalty logic that
// already lives in receptions.php. receptions.php itself is untouched.

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

// Tenant info for the printable receipt header
function getTenantInfoLite(PDO $pdo, int $tenant_id): array {
    $stmt = $pdo->prepare("SELECT id, name, address, phone FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$tenant_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'Company', 'address' => '', 'phone' => ''];
}

// -----------------------------------------------------
// AJAX actions (read-only browse + print)
// -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    $action = postString('ajax_action');

    if ($action === 'list_receipts') {
        $search = postString('search');
        $date_from = postString('date_from');
        $date_to = postString('date_to');
        $page = max(1, postInt('page', 1));
        $limit = 15;
        $offset = ($page - 1) * $limit;

        $where = ["r.tenant_id = ?", "r.branch_id = ?"];
        $params = [$tenant_id, $assigned_branch_id];

        if ($search !== '') {
            $where[] = "(r.receipt_number LIKE ? OR c.customer_name LIKE ? OR r.reference_number LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        if ($date_from !== '') {
            $where[] = "r.payment_date >= ?";
            $params[] = $date_from;
        }
        if ($date_to !== '') {
            $where[] = "r.payment_date <= ?";
            $params[] = $date_to;
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) AS total FROM receipts r LEFT JOIN customers c ON r.customer_id = c.id $whereSql";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        $pages = max(1, (int)ceil($total / $limit));

        $sumSql = "SELECT COALESCE(SUM(r.amount),0) AS total_amount FROM receipts r LEFT JOIN customers c ON r.customer_id = c.id $whereSql";
        $stmt = $pdo->prepare($sumSql);
        $stmt->execute($params);
        $totalAmount = (float)($stmt->fetch(PDO::FETCH_ASSOC)['total_amount'] ?? 0);

        $sql = "
            SELECT r.*, c.customer_name, c.phone AS customer_phone, i.invoice_number
            FROM receipts r
            LEFT JOIN customers c ON r.customer_id = c.id
            LEFT JOIN invoices i ON r.invoice_id = i.id
            $whereSql
            ORDER BY r.created_at DESC, r.id DESC
            LIMIT $limit OFFSET $offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ob_start();
        ?>
        <div class="mb-3"><span class="badge badge-secondary" style="font-size:13px;">Total shown: $<?= money2($totalAmount) ?></span></div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle">
                <thead class="thead-light">
                    <tr><th>Receipt #</th><th>Date</th><th>Customer</th><th>Invoice</th><th>Amount</th><th>Method</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                    <tr>
                        <td><strong><?= h($r['receipt_number']) ?></strong></td>
                        <td><?= h($r['payment_date']) ?></td>
                        <td><?= h($r['customer_name'] ?? '-') ?><br><small class="text-muted"><?= h($r['customer_phone'] ?? '') ?></small></td>
                        <td><?= h($r['invoice_number'] ?? '-') ?></td>
                        <td>$<?= money2($r['amount']) ?></td>
                        <td class="text-capitalize"><?= h($r['payment_method'] ?? '-') ?></td>
                        <td>
                            <button class="btn btn-sm btn-info view-receipt" data-id="<?= (int)$r['id'] ?>" title="View / Print"><i class="fas fa-print"></i></button>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">No receipts found for your branch</td></tr>
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

    if ($action === 'view_receipt') {
        $id = postInt('id');
        $stmt = $pdo->prepare("
            SELECT r.*, c.customer_name, c.phone AS customer_phone, i.invoice_number, u.full_name AS created_by_name
            FROM receipts r
            LEFT JOIN customers c ON r.customer_id = c.id
            LEFT JOIN invoices i ON r.invoice_id = i.id
            LEFT JOIN users u ON r.created_by = u.id
            WHERE r.id = ? AND r.tenant_id = ? AND r.branch_id = ?
            LIMIT 1
        ");
        $stmt->execute([$id, $tenant_id, $assigned_branch_id]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$receipt) jsonOut(['success' => false, 'message' => 'Receipt not found in your branch.']);

        $tenant_info = getTenantInfoLite($pdo, $tenant_id);

        ob_start(); ?>
        <div class="receipt-print-area">
            <div class="text-center mb-3">
                <h5><?= h($tenant_info['name'] ?? 'Company') ?></h5>
                <p class="mb-1 text-muted"><?= h($tenant_info['address'] ?? '') ?> <?= h($tenant_info['phone'] ?? '') ?></p>
                <h6><i class="fas fa-receipt"></i> RECEIPT (Branch: <?= h($branch_name) ?>)</h6>
            </div>
            <table class="table table-sm table-borderless mb-0">
                <tr><td><strong>Receipt No:</strong></td><td><?= h($receipt['receipt_number']) ?></td></tr>
                <tr><td><strong>Date:</strong></td><td><?= h($receipt['payment_date']) ?></td></tr>
                <tr><td><strong>Customer:</strong></td><td><?= h($receipt['customer_name'] ?? '-') ?></td></tr>
                <tr><td><strong>Invoice:</strong></td><td><?= h($receipt['invoice_number'] ?? '-') ?></td></tr>
                <tr><td colspan="2"><hr></td></tr>
                <tr><td><strong>Original Amount:</strong></td><td>$<?= money2($receipt['original_amount']) ?></td></tr>
                <tr><td><strong>Discount:</strong></td><td>-$<?= money2($receipt['discount_applied']) ?></td></tr>
                <tr><td><strong>Final Paid:</strong></td><td><strong>$<?= money2($receipt['amount']) ?></strong></td></tr>
                <tr><td><strong>Payment Method:</strong></td><td class="text-capitalize"><?= h($receipt['payment_method'] ?? '-') ?></td></tr>
                <tr><td><strong>Reference:</strong></td><td><?= h($receipt['reference_number'] ?? '-') ?></td></tr>
                <tr><td><strong>Issued By:</strong></td><td><?= h($receipt['created_by_name'] ?? '-') ?></td></tr>
            </table>
            <?php if (!empty($receipt['notes'])): ?><p class="mt-2"><strong>Notes:</strong> <?= nl2br(h($receipt['notes'])) ?></p><?php endif; ?>
            <p class="text-center text-muted mt-3">Thank you for your payment.</p>
        </div>
        <?php
        jsonOut(['success' => true, 'html' => ob_get_clean()]);
    }

    jsonOut(['success' => false, 'message' => 'Unknown action.']);
}

// -----------------------------------------------------
// Stats
// -----------------------------------------------------
$stats = ['count' => 0, 'total_amount' => 0, 'today_amount' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS count, COALESCE(SUM(amount),0) AS total_amount,
               COALESCE(SUM(CASE WHEN payment_date = CURDATE() THEN amount ELSE 0 END),0) AS today_amount
        FROM receipts
        WHERE tenant_id = ? AND branch_id = ?
    ");
    $stmt->execute([$tenant_id, $assigned_branch_id]);
    $stats = array_merge($stats, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipts - <?= h($branch_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { background:#f4f6f9; }
        .page-wrap { padding: 20px; }
        .hero { background: linear-gradient(135deg,#2D1859,#4B2C85); color:#fff; border-radius:18px; padding:22px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
        .hero h3 { margin:0; font-weight:700; }
        .hero small { opacity:.9; }
        .stat-card { background:#fff; border-radius:16px; padding:18px; box-shadow:0 6px 18px rgba(0,0,0,.06); border:1px solid #eee; }
        .stat-card .num { font-size:24px; font-weight:800; color:#2D1859; }
        .panel { background:#fff; border-radius:16px; padding:18px; box-shadow:0 6px 18px rgba(0,0,0,.06); border:1px solid #eee; }
        .btn-main { background:#2D1859; color:#fff; border:0; }
        .btn-main:hover { background:#1F0F3D; color:#fff; }
        @media print {
            .no-print { display: none !important; }
            .page-wrap { padding: 0; }
        }
    </style>
</head>
<body>
<div class="page-wrap">
    <div class="hero no-print">
        <div>
            <h3><i class="fas fa-hand-holding-dollar"></i> Receipts</h3>
            <small>Branch: <?= h($branch_name) ?> · Browse &amp; reprint issued receipts</small>
        </div>
        <a href="receptions.php" class="btn btn-light"><i class="fas fa-plus-circle"></i> Issue New Receipt</a>
    </div>

    <div class="row mb-3 no-print">
        <div class="col-md-4 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$stats['count']) ?></div><div>Total Receipts</div></div></div>
        <div class="col-md-4 mb-3"><div class="stat-card"><div class="num">$<?= money2($stats['total_amount']) ?></div><div>All-Time Amount</div></div></div>
        <div class="col-md-4 mb-3"><div class="stat-card"><div class="num">$<?= money2($stats['today_amount']) ?></div><div>Today</div></div></div>
    </div>

    <div class="panel no-print">
        <div class="row mb-3">
            <div class="col-md-4"><input type="text" id="searchInput" class="form-control" placeholder="Search receipt #, customer, reference..."></div>
            <div class="col-md-3"><input type="date" id="dateFrom" class="form-control" placeholder="From"></div>
            <div class="col-md-3"><input type="date" id="dateTo" class="form-control" placeholder="To"></div>
            <div class="col-md-2"><button id="refreshBtn" class="btn btn-main btn-block"><i class="fas fa-sync"></i></button></div>
        </div>
        <div id="receiptsTable"><div class="text-center py-5"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>
        <div id="paginationBox"></div>
    </div>

    <p class="text-muted small no-print"><i class="fas fa-info-circle"></i> To issue, edit, or delete a receipt, use the <a href="receptions.php">Receptions</a> page. This page is a read-only browse &amp; print view.</p>
</div>

<!-- View / Print Modal -->
<div class="modal fade" id="viewReceiptModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header no-print">
                <h5 class="modal-title">Receipt</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="receiptDetails"><div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i></div></div>
            <div class="modal-footer no-print">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-main" id="printReceiptBtn"><i class="fas fa-print"></i> Print</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentPage = 1;

function loadReceipts(page) {
    page = page || 1;
    currentPage = page;
    $('#receiptsTable').html('<div class="text-center py-5"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
    $.post('', {
        ajax_action: 'list_receipts', page: page,
        search: $('#searchInput').val(), date_from: $('#dateFrom').val(), date_to: $('#dateTo').val()
    }, function(res) {
        if (res.success) {
            $('#receiptsTable').html(res.html);
            $('#paginationBox').html(res.pagination);
        } else {
            $('#receiptsTable').html('<div class="alert alert-danger">' + (res.message || 'Error') + '</div>');
        }
    }, 'json').fail(function() { $('#receiptsTable').html('<div class="alert alert-danger">Server error.</div>'); });
}

let searchTimer = null;
$(document).on('keyup', '#searchInput', function() { clearTimeout(searchTimer); searchTimer = setTimeout(() => loadReceipts(1), 350); });
$('#dateFrom, #dateTo, #refreshBtn').on('change click', function() { loadReceipts(1); });
$(document).on('click', '#paginationBox .page-link', function(e) { e.preventDefault(); loadReceipts(parseInt($(this).data('page'))); });

$(document).on('click', '.view-receipt', function() {
    $('#receiptDetails').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i></div>');
    $('#viewReceiptModal').modal('show');
    $.post('', { ajax_action: 'view_receipt', id: $(this).data('id') }, function(res) {
        $('#receiptDetails').html(res.success ? res.html : '<div class="alert alert-danger">' + res.message + '</div>');
    }, 'json');
});

$('#printReceiptBtn').on('click', function() { window.print(); });

$(function() { loadReceipts(); });
</script>
</body>
</html>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
