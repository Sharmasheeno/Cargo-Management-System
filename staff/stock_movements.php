<?php
// staff/stock_movements.php
// Read-only audit log of stock_movements for the staff member's assigned branch.
// Movements are created as a side effect of actions on staff/warehouse_stock.php
// (receiving, adjusting, moving, and deleting warehouse stock) — this page just
// lists them with simple filters, it does not let staff create movements directly.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// NOTE: login.php stores the sub-role (role_type) into $_SESSION['role'] as an alias, so a
// plain === 'staff' check only matches the generic staff account and locks out every staff
// sub-role (warehouse_supervisor, logistics_supervisor, finance_manager, clerk). Check
// against the known staff role_types instead, using role_type first, role as fallback.
$staff_role_types = ['staff', 'warehouse_supervisor', 'logistics_supervisor', 'finance_manager', 'clerk'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_type'] ?? $_SESSION['role'] ?? '', $staff_role_types, true)) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

$user_id = (int)$_SESSION['user_id'];
$tenant_id = (int)($_SESSION['tenant_id'] ?? 0);

if ($tenant_id <= 0) {
    header("Location: ../login.php?error=no_tenant");
    exit;
}

// ── Resolve the staff member's assigned branch ──────────────────────────────
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
            $_SESSION['can_manage_branch'] = $branchAssign['can_manage_branch'];
        }
    } catch (PDOException $e) {}
}

if (!$assigned_branch_id) {
    try {
        $stmt = $pdo->prepare("SELECT default_branch_id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $userBranch = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($userBranch && $userBranch['default_branch_id']) {
            $assigned_branch_id = $userBranch['default_branch_id'];
            $_SESSION['assigned_branch_id'] = $assigned_branch_id;
        }
    } catch (PDOException $e) {}
}

if (!$assigned_branch_id) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container-fluid"><div class="alert alert-danger m-4">You are not assigned to any branch. Please contact your administrator.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Branch display name
$branch_name = 'My Branch';
try {
    $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$assigned_branch_id, $tenant_id]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($b) $branch_name = $b['branch_name'];
} catch (PDOException $e) {}

$movement_labels = ['in' => 'Stock In', 'out' => 'Stock Out', 'move' => 'Moved', 'adjust' => 'Adjusted'];
$movement_colors = ['in' => '#10B981', 'out' => '#DC2626', 'move' => '#3B82F6', 'adjust' => '#F59E0B'];

// ── Filters (GET, simple page reload — no AJAX needed for a log view) ──────
$movement_type = $_GET['movement_type'] ?? '';
if (!array_key_exists($movement_type, $movement_labels)) $movement_type = '';
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$where = ["sm.tenant_id = ?", "ws.branch_id = ?"];
$params = [$tenant_id, $assigned_branch_id];

if ($movement_type !== '') {
    $where[] = "sm.movement_type = ?";
    $params[] = $movement_type;
}
if ($date_from !== '') {
    $where[] = "DATE(sm.created_at) >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where[] = "DATE(sm.created_at) <= ?";
    $params[] = $date_to;
}
if ($search !== '') {
    $where[] = "(ws.stock_name LIKE ? OR sm.notes LIKE ? OR sm.reference_type LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like);
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

$total = 0;
$movements = [];
try {
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM stock_movements sm
        INNER JOIN warehouse_stock ws ON sm.warehouse_stock_id = ws.id
        $where_clause
    ");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = max(1, (int)ceil($total / $limit));
    if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $limit; }

    $stmt = $pdo->prepare("
        SELECT sm.*, ws.stock_name, ws.origin AS stock_origin, ws.location AS stock_location, u.full_name AS created_by_name
        FROM stock_movements sm
        INNER JOIN warehouse_stock ws ON sm.warehouse_stock_id = ws.id
        LEFT JOIN users u ON sm.created_by = u.id
        $where_clause
        ORDER BY sm.created_at DESC, sm.id DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $total_pages = 1;
}

$total_pages = $total_pages ?? 1;

// Preserve current filters in pagination links
function buildQuery(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    return htmlspecialchars('?' . http_build_query($params), ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid" style="padding: 20px;">
<style>
    :root { --curdun-violet: #2D1859; --curdun-yellow: #F5C410; --curdun-violet-light: #4B2C85; --curdun-yellow-dark: #D4A70C; }
    .movements-page .page-header { background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light)); border-radius: 16px; padding: 20px 25px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
    .movements-page .page-header h1 { color: #fff; font-size: 22px; margin: 0; font-weight: 700; }
    .movements-page .page-header h1 i { margin-right: 10px; }
    .movements-page .branch-badge { background: rgba(255,255,255,0.18); color: #fff; padding: 8px 16px; border-radius: 999px; font-size: 13px; }
    .movements-page .filters-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px; margin-bottom: 22px; }
    .movements-page .filter-form { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; }
    .movements-page .filter-group { flex: 1; min-width: 160px; }
    .movements-page .filter-group label { display: block; font-size: 12px; font-weight: 700; color: #4b5563; margin-bottom: 5px; }
    .movements-page .filter-group input, .movements-page .filter-group select { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 13px; }
    .movements-page .btn-filter { background: var(--curdun-violet); color: #fff; border: none; padding: 9px 20px; border-radius: 10px; cursor: pointer; }
    .movements-page .btn-reset { background: #f3f4f6; border: 1px solid #e5e7eb; padding: 9px 20px; border-radius: 10px; cursor: pointer; margin-left: 8px; color: #374151; text-decoration: none; display: inline-block; }
    .movements-page .table-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; overflow: hidden; }
    .movements-page table th { background: #f9fafb; font-weight: 700; font-size: 12.5px; padding: 12px; white-space: nowrap; }
    .movements-page table td { padding: 12px; font-size: 13px; vertical-align: middle; }
    .movements-page .qty-positive { color: #10B981; font-weight: 700; }
    .movements-page .qty-negative { color: #DC2626; font-weight: 700; }
    .movements-page .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; flex-wrap: wrap; }
    .movements-page .pagination-link, .movements-page .active-page { min-width: 40px; height: 40px; padding: 0 12px; border-radius: 10px; display: flex; align-items: center; justify-content: center; text-decoration: none; font-size: 13px; font-weight: 600; }
    .movements-page .pagination-link { background: #fff; color: #374151; border: 1px solid #d1d5db; }
    .movements-page .pagination-link:hover { background: var(--curdun-violet); color: #fff; border-color: var(--curdun-violet); }
    .movements-page .active-page { background: var(--curdun-violet); color: #fff; border: 1px solid var(--curdun-violet); }
    @media (max-width: 768px) { .movements-page .filter-form { flex-direction: column; } }
</style>

<div class="movements-page">
    <div class="page-header">
        <h1><i class="fas fa-right-left"></i> Stock Movements</h1>
        <span class="branch-badge"><i class="fas fa-code-branch"></i> <?= h($branch_name) ?></span>
    </div>

    <div class="filters-card">
        <form class="filter-form" method="get">
            <div class="filter-group"><label><i class="fas fa-search"></i> Search</label><input type="text" name="search" value="<?= h($search) ?>" placeholder="Item name, note, reference..."></div>
            <div class="filter-group">
                <label>Movement Type</label>
                <select name="movement_type">
                    <option value="">All types</option>
                    <?php foreach ($movement_labels as $k => $v): ?>
                        <option value="<?= h($k) ?>" <?= $movement_type === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group"><label>From</label><input type="date" name="date_from" value="<?= h($date_from) ?>"></div>
            <div class="filter-group"><label>To</label><input type="date" name="date_to" value="<?= h($date_to) ?>"></div>
            <div>
                <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Filter</button>
                <a href="stock_movements.php" class="btn-reset"><i class="fas fa-undo"></i> Reset</a>
            </div>
        </form>
    </div>

    <div class="table-card">
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>Item</th>
                        <th>Type</th>
                        <th>Change</th>
                        <th>Before &rarr; After</th>
                        <th>Reference</th>
                        <th>Notes</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($movements): foreach ($movements as $m): ?>
                    <tr>
                        <td><?= h(date('Y-m-d H:i', strtotime($m['created_at']))) ?></td>
                        <td>
                            <strong><?= h($m['stock_name'] ?? '-') ?></strong>
                            <?php if (!empty($m['stock_location'])): ?><br><small class="text-muted"><?= h($m['stock_location']) ?></small><?php endif; ?>
                        </td>
                        <td><span class="badge" style="background:<?= $movement_colors[$m['movement_type']] ?? '#6b7280' ?>22;color:<?= $movement_colors[$m['movement_type']] ?? '#6b7280' ?>;padding:5px 10px;border-radius:999px;font-weight:600;"><?= h($movement_labels[$m['movement_type']] ?? ucfirst($m['movement_type'])) ?></span></td>
                        <td class="<?= (int)$m['quantity_change'] > 0 ? 'qty-positive' : ((int)$m['quantity_change'] < 0 ? 'qty-negative' : '') ?>"><?= (int)$m['quantity_change'] > 0 ? '+' : '' ?><?= (int)$m['quantity_change'] ?></td>
                        <td><?= (int)$m['previous_quantity'] ?> &rarr; <?= (int)$m['new_quantity'] ?></td>
                        <td><?= $m['reference_type'] ? h($m['reference_type']) . ($m['reference_id'] ? ' #' . (int)$m['reference_id'] : '') : '-' ?></td>
                        <td><?= h($m['notes'] ?: '-') ?></td>
                        <td><?= h($m['created_by_name'] ?? 'System') ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="8" class="text-center py-5">
                        <i class="fas fa-right-left fa-3x text-muted mb-3"></i>
                        <p class="mb-0">No stock movements recorded yet.</p>
                        <small class="text-muted">Movements are logged automatically when stock is received, adjusted, or moved on the Warehouse Stock page.</small>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?><a class="pagination-link" href="<?= buildQuery(['page' => $page - 1]) ?>"><i class="fas fa-chevron-left"></i> Prev</a><?php endif; ?>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php if ($i == $page): ?><span class="active-page"><?= $i ?></span>
                <?php elseif ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?><a class="pagination-link" href="<?= buildQuery(['page' => $i]) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?><a class="pagination-link" href="<?= buildQuery(['page' => $page + 1]) ?>">Next <i class="fas fa-chevron-right"></i></a><?php endif; ?>
        </div>
    <?php endif; ?>
    <p class="text-muted text-center mt-2" style="font-size:13px;"><?= (int)$total ?> movement<?= $total === 1 ? '' : 's' ?> found</p>
</div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
