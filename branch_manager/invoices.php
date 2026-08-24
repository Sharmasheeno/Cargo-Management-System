<?php
// branch_manager/invoices.php
// Invoices Management for Branch Manager
// The `invoices` table has no direct branch_id column. Scoping is done by joining
// through trip_id -> trucking_trips.branch_id = assigned branch (same approach as
// expenses.php for consistency). Only invoices attached to a trip belonging to this
// branch are visible/creatable here; branch-less invoices remain a tenant_admin concern.

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

function generateBranchInvoiceNumber(PDO $pdo, int $tenantId): string {
    $prefix = 'INV-' . date('Ymd') . '-';
    do {
        $number = $prefix . random_int(1000, 9999);
        $check = $pdo->prepare("SELECT id FROM invoices WHERE invoice_number = ? AND tenant_id = ? LIMIT 1");
        $check->execute([$number, $tenantId]);
    } while ($check->fetch());
    return $number;
}

function invoiceStatusBadge(string $status): string {
    return match ($status) {
        'draft' => 'secondary',
        'sent' => 'info',
        'paid' => 'success',
        'overdue' => 'danger',
        'cancelled' => 'dark',
        default => 'secondary'
    };
}

$invoice_statuses = ['draft', 'sent', 'paid', 'overdue', 'cancelled'];

// -----------------------------------------------------
// AJAX actions
// -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    $action = postString('ajax_action');

    if ($action === 'list_invoices') {
        $search = postString('search');
        $status = postString('status', 'all');
        $page = max(1, postInt('page', 1));
        $limit = 15;
        $offset = ($page - 1) * $limit;

        $where = ["i.tenant_id = ?", "i.trip_id IN (SELECT id FROM trucking_trips WHERE tenant_id = ? AND branch_id = ?)"];
        $params = [$tenant_id, $tenant_id, $assigned_branch_id];

        if ($status !== '' && $status !== 'all') {
            $where[] = "i.status = ?";
            $params[] = $status;
        }
        if ($search !== '') {
            $where[] = "(i.invoice_number LIKE ? OR c.customer_name LIKE ? OR t.trip_number LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) AS total FROM invoices i LEFT JOIN customers c ON i.customer_id = c.id LEFT JOIN trucking_trips t ON i.trip_id = t.id $whereSql";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        $pages = max(1, (int)ceil($total / $limit));

        $sql = "
            SELECT i.*, c.customer_name, c.phone AS customer_phone, t.trip_number
            FROM invoices i
            LEFT JOIN customers c ON i.customer_id = c.id
            LEFT JOIN trucking_trips t ON i.trip_id = t.id
            $whereSql
            ORDER BY i.invoice_date DESC, i.id DESC
            LIMIT $limit OFFSET $offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ob_start();
        ?>
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle">
                <thead class="thead-light">
                    <tr><th>Invoice #</th><th>Customer</th><th>Trip</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Date</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $r):
                    $balance = (float)$r['total_amount'] - (float)$r['paid_amount'];
                    $badge = invoiceStatusBadge((string)$r['status']);
                ?>
                    <tr>
                        <td><strong><?= h($r['invoice_number']) ?></strong></td>
                        <td><?= h($r['customer_name'] ?? '-') ?><br><small class="text-muted"><?= h($r['customer_phone'] ?? '') ?></small></td>
                        <td><?= h($r['trip_number'] ?? '-') ?></td>
                        <td>$<?= money2($r['total_amount']) ?></td>
                        <td>$<?= money2($r['paid_amount']) ?></td>
                        <td>$<?= money2($balance) ?></td>
                        <td><span class="badge badge-<?= h($badge) ?> text-capitalize"><?= h($r['status']) ?></span></td>
                        <td><?= h($r['invoice_date']) ?></td>
                        <td>
                            <button class="btn btn-sm btn-info view-invoice" data-id="<?= (int)$r['id'] ?>" title="View"><i class="fas fa-eye"></i></button>
                            <?php if (!in_array($r['status'], ['paid', 'cancelled'], true)): ?>
                                <button class="btn btn-sm btn-success mark-status" data-id="<?= (int)$r['id'] ?>" data-status="sent" title="Mark Sent"><i class="fas fa-paper-plane"></i></button>
                                <button class="btn btn-sm btn-danger mark-status" data-id="<?= (int)$r['id'] ?>" data-status="cancelled" title="Cancel"><i class="fas fa-ban"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="9" class="text-center py-4 text-muted">No invoices found for your branch trips</td></tr>
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

        jsonOut(['success' => true, 'html' => $html, 'pagination' => $pagination, 'total' => $total]);
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

    if ($action === 'get_customers') {
        $q = postString('q');
        $where = "tenant_id = ? AND is_active = 1";
        $params = [$tenant_id];
        if ($q !== '') {
            $where .= " AND (customer_name LIKE ? OR phone LIKE ?)";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        $stmt = $pdo->prepare("SELECT id, customer_name, phone FROM customers WHERE $where ORDER BY customer_name LIMIT 25");
        $stmt->execute($params);
        jsonOut(['success' => true, 'customers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'get_invoice') {
        $id = postInt('id');
        $stmt = $pdo->prepare("
            SELECT i.*, c.customer_name, c.phone AS customer_phone, c.email AS customer_email, t.trip_number
            FROM invoices i
            LEFT JOIN customers c ON i.customer_id = c.id
            LEFT JOIN trucking_trips t ON i.trip_id = t.id
            WHERE i.id = ? AND i.tenant_id = ? AND i.trip_id IN (SELECT id FROM trucking_trips WHERE tenant_id = ? AND branch_id = ?)
            LIMIT 1
        ");
        $stmt->execute([$id, $tenant_id, $tenant_id, $assigned_branch_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) jsonOut(['success' => false, 'message' => 'Invoice not found or not linked to your branch.']);

        $items = [];
        try {
            $itemStmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
            $itemStmt->execute([$id]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        $badge = invoiceStatusBadge((string)$invoice['status']);
        ob_start(); ?>
        <div class="mb-2"><strong>Invoice:</strong> <?= h($invoice['invoice_number']) ?> <span class="badge badge-<?= h($badge) ?> text-capitalize"><?= h($invoice['status']) ?></span></div>
        <div class="mb-2"><strong>Customer:</strong> <?= h($invoice['customer_name'] ?? '-') ?> <?= h($invoice['customer_phone'] ?? '') ?></div>
        <div class="mb-2"><strong>Trip:</strong> <?= h($invoice['trip_number'] ?? '-') ?></div>
        <div class="mb-2"><strong>Invoice Date:</strong> <?= h($invoice['invoice_date']) ?> &nbsp; <strong>Due:</strong> <?= h($invoice['due_date'] ?? '-') ?></div>
        <hr>
        <?php if ($items): ?>
        <table class="table table-sm table-bordered">
            <thead><tr><th>Description</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr><td><?= h($it['description'] ?? $it['item_name'] ?? '-') ?></td><td><?= (int)$it['quantity'] ?></td><td>$<?= money2($it['unit_price']) ?></td><td>$<?= money2($it['total_price']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <hr>
        <?php endif; ?>
        <div class="mb-1"><strong>Subtotal:</strong> $<?= money2($invoice['subtotal']) ?></div>
        <div class="mb-1"><strong>Tax:</strong> $<?= money2($invoice['tax_amount']) ?></div>
        <div class="mb-1"><strong>Discount:</strong> $<?= money2($invoice['discount']) ?></div>
        <div class="mb-1"><strong>Total:</strong> $<?= money2($invoice['total_amount']) ?></div>
        <div class="mb-1"><strong>Paid:</strong> $<?= money2($invoice['paid_amount']) ?></div>
        <div class="mb-1"><strong>Balance:</strong> $<?= money2((float)$invoice['total_amount'] - (float)$invoice['paid_amount']) ?></div>
        <?php if (!empty($invoice['notes'])): ?><div class="mt-2"><strong>Notes:</strong> <?= nl2br(h($invoice['notes'])) ?></div><?php endif; ?>
        <?php
        jsonOut(['success' => true, 'html' => ob_get_clean(), 'invoice' => $invoice]);
    }

    if ($action === 'create_invoice') {
        $trip_id = postInt('trip_id');
        $customer_id = postInt('customer_id');
        $subtotal = (float)str_replace(',', '.', postString('subtotal', '0'));
        $tax_rate = (float)str_replace(',', '.', postString('tax_rate', '0'));
        $discount = (float)str_replace(',', '.', postString('discount', '0'));
        $invoice_date = postString('invoice_date', date('Y-m-d'));
        $due_date = postString('due_date');
        $notes = postString('notes');

        if ($trip_id <= 0) jsonOut(['success' => false, 'message' => 'Please select a trip from your branch.']);
        if ($customer_id <= 0) jsonOut(['success' => false, 'message' => 'Please select a customer.']);
        if ($subtotal <= 0) jsonOut(['success' => false, 'message' => 'Subtotal must be greater than 0.']);

        $check = $pdo->prepare("SELECT id FROM trucking_trips WHERE id = ? AND tenant_id = ? AND branch_id = ? LIMIT 1");
        $check->execute([$trip_id, $tenant_id, $assigned_branch_id]);
        if (!$check->fetch(PDO::FETCH_ASSOC)) jsonOut(['success' => false, 'message' => 'Selected trip does not belong to your branch.']);

        $checkCust = $pdo->prepare("SELECT id FROM customers WHERE id = ? AND tenant_id = ? LIMIT 1");
        $checkCust->execute([$customer_id, $tenant_id]);
        if (!$checkCust->fetch(PDO::FETCH_ASSOC)) jsonOut(['success' => false, 'message' => 'Customer not found.']);

        $tax_amount = round($subtotal * ($tax_rate / 100), 2);
        $total_amount = max(0, round($subtotal + $tax_amount - $discount, 2));

        try {
            $number = generateBranchInvoiceNumber($pdo, $tenant_id);
            $stmt = $pdo->prepare("
                INSERT INTO invoices
                (tenant_id, customer_id, trip_id, invoice_number, invoice_date, due_date, subtotal, tax_rate, tax_amount, tax, discount, total_amount, paid_amount, status, notes, created_by, created_at, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'draft', ?, ?, NOW(), 1)
            ");
            $stmt->execute([
                $tenant_id, $customer_id, $trip_id, $number, $invoice_date ?: date('Y-m-d'), $due_date ?: null,
                $subtotal, $tax_rate, $tax_amount, $tax_amount, $discount, $total_amount, $notes ?: null, $user_id
            ]);
            jsonOut(['success' => true, 'message' => "Invoice {$number} created.", 'id' => (int)$pdo->lastInsertId()]);
        } catch (Throwable $e) {
            jsonOut(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }

    if ($action === 'update_status') {
        $id = postInt('id');
        $status = postString('status');
        global $invoice_statuses;
        if (!in_array($status, $invoice_statuses, true)) jsonOut(['success' => false, 'message' => 'Invalid status.']);

        $check = $pdo->prepare("SELECT id FROM invoices WHERE id = ? AND tenant_id = ? AND trip_id IN (SELECT id FROM trucking_trips WHERE tenant_id = ? AND branch_id = ?) LIMIT 1");
        $check->execute([$id, $tenant_id, $tenant_id, $assigned_branch_id]);
        if (!$check->fetch(PDO::FETCH_ASSOC)) jsonOut(['success' => false, 'message' => 'Invoice not found or not linked to your branch.']);

        try {
            $pdo->prepare("UPDATE invoices SET status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?")->execute([$status, $id, $tenant_id]);
            jsonOut(['success' => true, 'message' => 'Invoice status updated.']);
        } catch (Throwable $e) {
            jsonOut(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }

    jsonOut(['success' => false, 'message' => 'Unknown action.']);
}

// -----------------------------------------------------
// Stats
// -----------------------------------------------------
$stats = ['count' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'outstanding' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS count, COALESCE(SUM(i.total_amount),0) AS total_amount,
               COALESCE(SUM(i.paid_amount),0) AS paid_amount,
               COALESCE(SUM(i.total_amount - i.paid_amount),0) AS outstanding
        FROM invoices i
        WHERE i.tenant_id = ? AND i.trip_id IN (SELECT id FROM trucking_trips WHERE tenant_id = ? AND branch_id = ?)
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
    <title>Invoices - <?= h($branch_name) ?></title>
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
        .search-result { border:1px solid #ddd; border-radius:10px; max-height:220px; overflow:auto; display:none; background:#fff; position:absolute; z-index:1000; width:100%; }
        .search-result .item { padding:10px; cursor:pointer; border-bottom:1px solid #eee; }
        .search-result .item:hover { background:#f4f2f6; }
    </style>
</head>
<body>
<div class="page-wrap">
    <div class="hero">
        <div>
            <h3><i class="fas fa-file-invoice-dollar"></i> Invoices</h3>
            <small>Branch: <?= h($branch_name) ?> · Trip-linked invoices only</small>
        </div>
        <button class="btn btn-light" data-toggle="modal" data-target="#createInvoiceModal"><i class="fas fa-plus-circle"></i> New Invoice</button>
    </div>

    <div class="row mb-3">
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$stats['count']) ?></div><div>Total Invoices</div></div></div>
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num">$<?= money2($stats['total_amount']) ?></div><div>Total Billed</div></div></div>
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num">$<?= money2($stats['paid_amount']) ?></div><div>Paid</div></div></div>
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num">$<?= money2($stats['outstanding']) ?></div><div>Outstanding</div></div></div>
    </div>

    <div class="panel">
        <div class="row mb-3">
            <div class="col-md-6"><input type="text" id="searchInput" class="form-control" placeholder="Search invoice #, customer, trip..."></div>
            <div class="col-md-3">
                <select id="statusFilter" class="form-control">
                    <option value="all">All Status</option>
                    <?php foreach ($invoice_statuses as $s): ?>
                        <option value="<?= h($s) ?>" class="text-capitalize"><?= h(ucfirst($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3"><button id="refreshBtn" class="btn btn-main btn-block"><i class="fas fa-sync"></i> Refresh</button></div>
        </div>
        <div id="invoicesTable"><div class="text-center py-5"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>
        <div id="paginationBox"></div>
    </div>
</div>

<!-- Create Invoice Modal -->
<div class="modal fade" id="createInvoiceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="createInvoiceForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create Invoice</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="ajax_action" value="create_invoice">
                <div class="form-group">
                    <label>Trip <span class="text-danger">*</span></label>
                    <select name="trip_id" id="tripSelect" class="form-control" required>
                        <option value="">Loading trips...</option>
                    </select>
                    <small class="form-text text-muted">Only trips belonging to your branch are shown.</small>
                </div>
                <div class="form-group position-relative">
                    <label>Customer <span class="text-danger">*</span></label>
                    <input type="text" id="customerSearch" class="form-control" placeholder="Search customer by name or phone..." autocomplete="off">
                    <input type="hidden" name="customer_id" id="customerIdInput" required>
                    <div id="customerResults" class="search-result"></div>
                    <div id="customerSelected" class="mt-2 text-muted"></div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Subtotal <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="subtotal" id="subtotalInput" class="form-control" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Tax Rate (%)</label>
                        <input type="number" step="0.01" min="0" name="tax_rate" id="taxRateInput" class="form-control" value="0">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Discount ($)</label>
                        <input type="number" step="0.01" min="0" name="discount" id="discountInput" class="form-control" value="0">
                    </div>
                </div>
                <div class="alert alert-light border">Estimated Total: <strong id="estimatedTotal">$0.00</strong></div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Invoice Date</label>
                        <input type="date" name="invoice_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group col-md-6">
                        <label>Due Date</label>
                        <input type="date" name="due_date" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-main"><i class="fas fa-save"></i> Save Invoice</button>
            </div>
        </form>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewInvoiceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Invoice Details</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="invoiceDetails"><div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i></div></div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentPage = 1;
function toast(msg) { alert(msg); }

function loadInvoices(page) {
    page = page || 1;
    currentPage = page;
    $('#invoicesTable').html('<div class="text-center py-5"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
    $.post('', { ajax_action: 'list_invoices', page: page, search: $('#searchInput').val(), status: $('#statusFilter').val() }, function(res) {
        if (res.success) {
            $('#invoicesTable').html(res.html);
            $('#paginationBox').html(res.pagination);
        } else {
            $('#invoicesTable').html('<div class="alert alert-danger">' + (res.message || 'Error') + '</div>');
        }
    }, 'json').fail(function() { $('#invoicesTable').html('<div class="alert alert-danger">Server error.</div>'); });
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

let custTimer = null;
$('#customerSearch').on('keyup', function() {
    const q = $(this).val().trim();
    clearTimeout(custTimer);
    if (q.length < 1) { $('#customerResults').hide(); return; }
    custTimer = setTimeout(() => {
        $.post('', { ajax_action: 'get_customers', q: q }, function(res) {
            if (!res.success || !res.customers.length) { $('#customerResults').html('<div class="item text-muted">No customers found</div>').show(); return; }
            let html = '';
            res.customers.forEach(c => {
                html += `<div class="item pick-customer" data-id="${c.id}" data-name="${$('<div>').text(c.customer_name).html()}"><strong>${$('<div>').text(c.customer_name).html()}</strong><br><small>${$('<div>').text(c.phone || '').html()}</small></div>`;
            });
            $('#customerResults').html(html).show();
        }, 'json');
    }, 250);
});
$(document).on('click', '.pick-customer', function() {
    $('#customerIdInput').val($(this).data('id'));
    $('#customerSelected').html('Selected: <strong>' + $(this).data('name') + '</strong>');
    $('#customerSearch').val($(this).data('name'));
    $('#customerResults').hide();
});

function recalcTotal() {
    const subtotal = parseFloat($('#subtotalInput').val()) || 0;
    const taxRate = parseFloat($('#taxRateInput').val()) || 0;
    const discount = parseFloat($('#discountInput').val()) || 0;
    const tax = subtotal * (taxRate / 100);
    const total = Math.max(0, subtotal + tax - discount);
    $('#estimatedTotal').text('$' + total.toFixed(2));
}
$('#subtotalInput, #taxRateInput, #discountInput').on('input', recalcTotal);

let searchTimer = null;
$(document).on('keyup', '#searchInput', function() { clearTimeout(searchTimer); searchTimer = setTimeout(() => loadInvoices(1), 350); });
$('#statusFilter, #refreshBtn').on('change click', function() { loadInvoices(1); });
$(document).on('click', '#paginationBox .page-link', function(e) { e.preventDefault(); loadInvoices(parseInt($(this).data('page'))); });

$('#createInvoiceModal').on('show.bs.modal', function() {
    loadTripOptions();
    $('#customerIdInput').val('');
    $('#customerSelected').html('');
    recalcTotal();
});

$('#createInvoiceForm').on('submit', function(e) {
    e.preventDefault();
    if (!$('#customerIdInput').val()) { toast('Please select a customer.'); return; }
    $.post('', $(this).serialize(), function(res) {
        toast(res.message || (res.success ? 'Saved' : 'Error'));
        if (res.success) {
            $('#createInvoiceModal').modal('hide');
            $('#createInvoiceForm')[0].reset();
            loadInvoices(1);
        }
    }, 'json').fail(function() { toast('Server error.'); });
});

$(document).on('click', '.view-invoice', function() {
    $('#invoiceDetails').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i></div>');
    $('#viewInvoiceModal').modal('show');
    $.post('', { ajax_action: 'get_invoice', id: $(this).data('id') }, function(res) {
        $('#invoiceDetails').html(res.success ? res.html : '<div class="alert alert-danger">' + res.message + '</div>');
    }, 'json');
});

$(document).on('click', '.mark-status', function() {
    const id = $(this).data('id');
    const status = $(this).data('status');
    if (!confirm(`Set invoice status to "${status}"?`)) return;
    $.post('', { ajax_action: 'update_status', id: id, status: status }, function(res) {
        toast(res.message || (res.success ? 'Done' : 'Error'));
        if (res.success) loadInvoices(currentPage);
    }, 'json').fail(function() { toast('Server error.'); });
});

$(function() { loadInvoices(); });
</script>
</body>
</html>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
