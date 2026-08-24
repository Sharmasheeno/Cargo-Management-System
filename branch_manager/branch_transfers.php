<?php
// branch_manager/branch_transfers.php
// Branch Transfers Management for Branch Manager

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db_connect.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    die('Database connection failed: $pdo lama helin. Hubi config/db_connect.php');
}

// Allow branch_manager only
$current_role = $_SESSION['role_type'] ?? $_SESSION['role'] ?? '';
if (!isset($_SESSION['user_id']) || $current_role !== 'branch_manager') {
    header('Location: ../login.php');
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$session_tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
$user_name = $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'Branch Manager';

if (!$session_tenant_id) {
    header('Location: ../dashboard.php?error=no_tenant');
    exit;
}

// -----------------------------------------------------
// Helpers
// -----------------------------------------------------
function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function jsonOut(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function postInt(string $key, int $default = 0): int {
    $v = $_POST[$key] ?? $default;
    return is_numeric($v) ? (int)$v : $default;
}

function statusBadgeClass(string $status): string {
    return match ($status) {
        'pending' => 'warning',
        'approved' => 'info',
        'in_transit' => 'primary',
        'completed' => 'success',
        'cancelled', 'rejected' => 'danger',
        default => 'secondary'
    };
}

function transferStatusText(string $status): string {
    return match ($status) {
        'pending' => 'Sugaya',
        'approved' => 'La oggolaaday',
        'in_transit' => 'Waddada ku jira',
        'completed' => 'Dhamaaday',
        'cancelled' => 'La kansalay',
        'rejected' => 'La diiday',
        default => $status
    };
}

function generateTransferNumber(PDO $pdo, int $tenantId): string {
    $prefix = 'BTR-' . date('Ym') . '-';
    try {
        $stmt = $pdo->prepare("SELECT transfer_number FROM branch_transfers WHERE transfer_number LIKE ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($last && preg_match('/-(\d+)$/', $last['transfer_number'], $m)) {
            return $prefix . str_pad(((int)$m[1]) + 1, 5, '0', STR_PAD_LEFT);
        }
    } catch (Throwable $e) {}
    return $prefix . '00001';
}

// -----------------------------------------------------
// Ensure extra columns used by this page exist safely
// -----------------------------------------------------
try {
    $pdo->exec("ALTER TABLE branch_transfers ADD COLUMN IF NOT EXISTS tenant_id INT(11) DEFAULT NULL");
} catch (Throwable $e) {
    try {
        $chk = $pdo->prepare("SHOW COLUMNS FROM branch_transfers LIKE 'tenant_id'");
        $chk->execute();
        if (!$chk->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE branch_transfers ADD COLUMN tenant_id INT(11) DEFAULT NULL");
        }
    } catch (Throwable $ignore) {}
}

try {
    $pdo->exec("ALTER TABLE branch_transfer_items ADD COLUMN IF NOT EXISTS received_quantity INT(11) DEFAULT 0");
} catch (Throwable $e) {
    try {
        $chk = $pdo->prepare("SHOW COLUMNS FROM branch_transfer_items LIKE 'received_quantity'");
        $chk->execute();
        if (!$chk->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE branch_transfer_items ADD COLUMN received_quantity INT(11) DEFAULT 0");
        }
    } catch (Throwable $ignore) {}
}

// -----------------------------------------------------
// Get assigned branch
// -----------------------------------------------------
$assigned_branch_id = (int)($_SESSION['assigned_branch_id'] ?? 0);

if (!$assigned_branch_id) {
    try {
        $stmt = $pdo->prepare("SELECT branch_id, can_manage_branch FROM user_branch_assignments WHERE user_id = ? AND is_primary = 1 LIMIT 1");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $assigned_branch_id = (int)$row['branch_id'];
            $_SESSION['assigned_branch_id'] = $assigned_branch_id;
            $_SESSION['can_manage_branch'] = $row['can_manage_branch'] ?? 0;
        }
    } catch (Throwable $e) {}
}

if (!$assigned_branch_id) {
    try {
        $stmt = $pdo->prepare("SELECT default_branch_id FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['default_branch_id'])) {
            $assigned_branch_id = (int)$row['default_branch_id'];
            $_SESSION['assigned_branch_id'] = $assigned_branch_id;
        }
    } catch (Throwable $e) {}
}

if (!$assigned_branch_id) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container mt-4"><div class="alert alert-danger">You are not assigned to any branch. Please contact administrator.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$stmt = $pdo->prepare("SELECT id, branch_name, branch_code, branch_type FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1");
$stmt->execute([$assigned_branch_id, $session_tenant_id]);
$current_branch = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$current_branch) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container mt-4"><div class="alert alert-danger">Assigned branch was not found for this tenant.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Other active branches for destination/source selection
$branches = [];
try {
    $stmt = $pdo->prepare("SELECT id, branch_name, branch_code, branch_type FROM branches WHERE tenant_id = ? AND id <> ? AND (status = 'active' OR is_active = 1) ORDER BY branch_name ASC");
    $stmt->execute([$session_tenant_id, $assigned_branch_id]);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// -----------------------------------------------------
// AJAX actions
// -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    $action = $_POST['ajax_action'];

    if ($action === 'list_transfers') {
        $search = trim($_POST['search'] ?? '');
        $status = trim($_POST['status'] ?? 'all');
        $page = max(1, postInt('page', 1));
        $limit = 15;
        $offset = ($page - 1) * $limit;

        $where = ["bt.tenant_id = ?", "(bt.from_branch_id = ? OR bt.to_branch_id = ?)"];
        $params = [$session_tenant_id, $assigned_branch_id, $assigned_branch_id];

        if ($status !== '' && $status !== 'all') {
            $where[] = "bt.status = ?";
            $params[] = $status;
        }
        if ($search !== '') {
            $where[] = "(bt.transfer_number LIKE ? OR fb.branch_name LIKE ? OR tb.branch_name LIKE ? OR bt.notes LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) AS total FROM branch_transfers bt LEFT JOIN branches fb ON bt.from_branch_id = fb.id LEFT JOIN branches tb ON bt.to_branch_id = tb.id $whereSql";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        $pages = (int)ceil($total / $limit);

        $sql = "
            SELECT bt.*, fb.branch_name AS from_branch_name, fb.branch_code AS from_branch_code,
                   tb.branch_name AS to_branch_name, tb.branch_code AS to_branch_code,
                   u.full_name AS requested_by_name, au.full_name AS approved_by_name,
                   COALESCE(SUM(bti.quantity), 0) AS total_items_qty,
                   COUNT(bti.id) AS item_count
            FROM branch_transfers bt
            LEFT JOIN branches fb ON bt.from_branch_id = fb.id
            LEFT JOIN branches tb ON bt.to_branch_id = tb.id
            LEFT JOIN users u ON bt.requested_by = u.id
            LEFT JOIN users au ON bt.approved_by = au.id
            LEFT JOIN branch_transfer_items bti ON bt.id = bti.transfer_id
            $whereSql
            GROUP BY bt.id
            ORDER BY bt.id DESC
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
                    <tr>
                        <th>Transfer No</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Items</th>
                        <th>Status</th>
                        <th>Requested</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $r):
                    $direction = ((int)$r['from_branch_id'] === $assigned_branch_id) ? 'outgoing' : 'incoming';
                    $badge = statusBadgeClass((string)$r['status']);
                ?>
                    <tr>
                        <td>
                            <strong><?= h($r['transfer_number']) ?></strong><br>
                            <small class="text-muted"><?= $direction === 'outgoing' ? 'Outgoing' : 'Incoming' ?></small>
                        </td>
                        <td><?= h($r['from_branch_name'] ?? '-') ?><br><small><?= h($r['from_branch_code'] ?? '') ?></small></td>
                        <td><?= h($r['to_branch_name'] ?? '-') ?><br><small><?= h($r['to_branch_code'] ?? '') ?></small></td>
                        <td><?= (int)$r['item_count'] ?> item(s)<br><small>Qty: <?= number_format((int)$r['total_items_qty']) ?></small></td>
                        <td><span class="badge badge-<?= h($badge) ?>"><?= h(transferStatusText((string)$r['status'])) ?></span></td>
                        <td><?= h($r['requested_by_name'] ?? '-') ?><br><small><?= h($r['requested_at'] ?? '') ?></small></td>
                        <td>
                            <button class="btn btn-sm btn-info view-transfer" data-id="<?= (int)$r['id'] ?>"><i class="fas fa-eye"></i></button>
                            <?php if ($direction === 'incoming' && $r['status'] === 'pending'): ?>
                                <button class="btn btn-sm btn-success approve-transfer" data-id="<?= (int)$r['id'] ?>"><i class="fas fa-check"></i></button>
                                <button class="btn btn-sm btn-danger reject-transfer" data-id="<?= (int)$r['id'] ?>"><i class="fas fa-times"></i></button>
                            <?php endif; ?>
                            <?php if ($direction === 'outgoing' && $r['status'] === 'approved'): ?>
                                <button class="btn btn-sm btn-primary transit-transfer" data-id="<?= (int)$r['id'] ?>"><i class="fas fa-truck"></i></button>
                            <?php endif; ?>
                            <?php if ($direction === 'incoming' && $r['status'] === 'in_transit'): ?>
                                <button class="btn btn-sm btn-success complete-transfer" data-id="<?= (int)$r['id'] ?>"><i class="fas fa-flag-checkered"></i></button>
                            <?php endif; ?>
                            <?php if ($direction === 'outgoing' && $r['status'] === 'pending'): ?>
                                <button class="btn btn-sm btn-secondary cancel-transfer" data-id="<?= (int)$r['id'] ?>"><i class="fas fa-ban"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">Transfers lama helin</td></tr>
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

    if ($action === 'search_stock') {
        $q = trim($_POST['q'] ?? '');
        $results = [];
        if ($q !== '') {
            $like = '%' . $q . '%';
            $stmt = $pdo->prepare("SELECT id, stock_name, quantity, location, volume_cbm, unit_price FROM warehouse_stock WHERE tenant_id = ? AND branch_id = ? AND quantity > 0 AND (stock_name LIKE ? OR location LIKE ?) ORDER BY stock_name ASC LIMIT 20");
            $stmt->execute([$session_tenant_id, $assigned_branch_id, $like, $like]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        jsonOut(['success' => true, 'items' => $results]);
    }

    if ($action === 'create_transfer') {
        $to_branch_id = postInt('to_branch_id');
        $notes = trim($_POST['notes'] ?? '');
        $stock_ids = $_POST['stock_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];

        if (!$to_branch_id || $to_branch_id === $assigned_branch_id) {
            jsonOut(['success' => false, 'message' => 'Fadlan dooro laan kale oo loo dirayo.']);
        }
        if (!is_array($stock_ids) || count($stock_ids) === 0) {
            jsonOut(['success' => false, 'message' => 'Fadlan ku dar ugu yaraan hal alaab.']);
        }

        $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND tenant_id = ? AND (status = 'active' OR is_active = 1) LIMIT 1");
        $stmt->execute([$to_branch_id, $session_tenant_id]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            jsonOut(['success' => false, 'message' => 'Laanta aad dooratay lama helin ama ma shaqeyneyso.']);
        }

        try {
            $pdo->beginTransaction();
            $transfer_no = generateTransferNumber($pdo, $session_tenant_id);
            $stmt = $pdo->prepare("INSERT INTO branch_transfers (tenant_id, transfer_number, from_branch_id, to_branch_id, requested_by, transfer_type, status, notes, requested_at) VALUES (?, ?, ?, ?, ?, 'stock_transfer', 'pending', ?, NOW())");
            $stmt->execute([$session_tenant_id, $transfer_no, $assigned_branch_id, $to_branch_id, $user_id, $notes]);
            $transfer_id = (int)$pdo->lastInsertId();

            $inserted = 0;
            foreach ($stock_ids as $idx => $sid) {
                $stock_id = (int)$sid;
                $qty = isset($quantities[$idx]) && is_numeric($quantities[$idx]) ? (int)$quantities[$idx] : 0;
                if ($stock_id <= 0 || $qty <= 0) continue;

                $stockStmt = $pdo->prepare("SELECT id, quantity FROM warehouse_stock WHERE id = ? AND tenant_id = ? AND branch_id = ? FOR UPDATE");
                $stockStmt->execute([$stock_id, $session_tenant_id, $assigned_branch_id]);
                $stock = $stockStmt->fetch(PDO::FETCH_ASSOC);
                if (!$stock) {
                    throw new Exception("Stock ID {$stock_id} lama helin laantaada.");
                }
                if ((int)$stock['quantity'] < $qty) {
                    throw new Exception("Stock ID {$stock_id}: tirada ku jirta bakhaarka kuma filna.");
                }

                $itemStmt = $pdo->prepare("INSERT INTO branch_transfer_items (transfer_id, warehouse_stock_id, quantity, transferred_quantity, received_quantity, notes) VALUES (?, ?, ?, 0, 0, NULL)");
                $itemStmt->execute([$transfer_id, $stock_id, $qty]);
                $inserted++;
            }

            if ($inserted === 0) {
                throw new Exception('Alaab sax ah lama gelin transfer-ka.');
            }

            $pdo->commit();
            jsonOut(['success' => true, 'message' => "Transfer {$transfer_no} waa la abuuray.", 'transfer_id' => $transfer_id]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonOut(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
    }

    if ($action === 'get_transfer') {
        $id = postInt('id');
        $stmt = $pdo->prepare("SELECT bt.*, fb.branch_name AS from_branch_name, tb.branch_name AS to_branch_name, u.full_name AS requested_by_name, au.full_name AS approved_by_name FROM branch_transfers bt LEFT JOIN branches fb ON bt.from_branch_id = fb.id LEFT JOIN branches tb ON bt.to_branch_id = tb.id LEFT JOIN users u ON bt.requested_by = u.id LEFT JOIN users au ON bt.approved_by = au.id WHERE bt.id = ? AND bt.tenant_id = ? AND (bt.from_branch_id = ? OR bt.to_branch_id = ?) LIMIT 1");
        $stmt->execute([$id, $session_tenant_id, $assigned_branch_id, $assigned_branch_id]);
        $transfer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$transfer) jsonOut(['success' => false, 'message' => 'Transfer lama helin.']);

        $stmt = $pdo->prepare("SELECT bti.*, ws.stock_name, ws.location, ws.quantity AS current_quantity, ws.volume_cbm, ws.unit_price FROM branch_transfer_items bti LEFT JOIN warehouse_stock ws ON bti.warehouse_stock_id = ws.id WHERE bti.transfer_id = ? ORDER BY bti.id ASC");
        $stmt->execute([$id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ob_start(); ?>
        <div class="mb-3">
            <h5><?= h($transfer['transfer_number']) ?> <span class="badge badge-<?= h(statusBadgeClass($transfer['status'])) ?>"><?= h(transferStatusText($transfer['status'])) ?></span></h5>
            <p class="mb-1"><strong>From:</strong> <?= h($transfer['from_branch_name']) ?></p>
            <p class="mb-1"><strong>To:</strong> <?= h($transfer['to_branch_name']) ?></p>
            <p class="mb-1"><strong>Requested By:</strong> <?= h($transfer['requested_by_name'] ?? '-') ?></p>
            <p class="mb-1"><strong>Notes:</strong> <?= h($transfer['notes'] ?? '-') ?></p>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead><tr><th>Stock</th><th>Location</th><th>Request Qty</th><th>Transferred</th><th>Received</th></tr></thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= h($it['stock_name'] ?? '-') ?></td>
                        <td><?= h($it['location'] ?? '-') ?></td>
                        <td><?= number_format((int)$it['quantity']) ?></td>
                        <td><?= number_format((int)$it['transferred_quantity']) ?></td>
                        <td><?= number_format((int)($it['received_quantity'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        jsonOut(['success' => true, 'html' => ob_get_clean(), 'transfer' => $transfer, 'items' => $items]);
    }

    if (in_array($action, ['approve_transfer', 'reject_transfer', 'cancel_transfer', 'transit_transfer', 'complete_transfer'], true)) {
        $id = postInt('id');
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM branch_transfers WHERE id = ? AND tenant_id = ? AND (from_branch_id = ? OR to_branch_id = ?) FOR UPDATE");
            $stmt->execute([$id, $session_tenant_id, $assigned_branch_id, $assigned_branch_id]);
            $transfer = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$transfer) throw new Exception('Transfer lama helin.');

            $isOutgoing = ((int)$transfer['from_branch_id'] === $assigned_branch_id);
            $isIncoming = ((int)$transfer['to_branch_id'] === $assigned_branch_id);
            $status = (string)$transfer['status'];

            if ($action === 'approve_transfer') {
                if (!$isIncoming || $status !== 'pending') throw new Exception('Transfer-kan lama approve-gareyn karo.');
                $upd = $pdo->prepare("UPDATE branch_transfers SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
                $upd->execute([$user_id, $id]);
                $msg = 'Transfer waa la oggolaaday.';
            } elseif ($action === 'reject_transfer') {
                if (!$isIncoming || $status !== 'pending') throw new Exception('Transfer-kan lama reject-gareyn karo.');
                $upd = $pdo->prepare("UPDATE branch_transfers SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?");
                $upd->execute([$user_id, $id]);
                $msg = 'Transfer waa la diiday.';
            } elseif ($action === 'cancel_transfer') {
                if (!$isOutgoing || $status !== 'pending') throw new Exception('Transfer-kan lama cancel-gareyn karo.');
                $upd = $pdo->prepare("UPDATE branch_transfers SET status = 'cancelled' WHERE id = ?");
                $upd->execute([$id]);
                $msg = 'Transfer waa la kansalay.';
            } elseif ($action === 'transit_transfer') {
                if (!$isOutgoing || $status !== 'approved') throw new Exception('Transfer-kan lama dirikaro hadda.');

                $itemsStmt = $pdo->prepare("SELECT bti.*, ws.quantity AS stock_qty FROM branch_transfer_items bti JOIN warehouse_stock ws ON bti.warehouse_stock_id = ws.id WHERE bti.transfer_id = ? AND ws.tenant_id = ? AND ws.branch_id = ? FOR UPDATE");
                $itemsStmt->execute([$id, $session_tenant_id, $assigned_branch_id]);
                $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                if (!$items) throw new Exception('Transfer items lama helin.');

                foreach ($items as $it) {
                    $qty = (int)$it['quantity'];
                    if ((int)$it['stock_qty'] < $qty) throw new Exception('Stock kuma filna transfer-ka.');
                    $pdo->prepare("UPDATE warehouse_stock SET quantity = quantity - ?, last_updated = NOW(), updated_by = ? WHERE id = ? AND tenant_id = ? AND branch_id = ?")
                        ->execute([$qty, $user_id, (int)$it['warehouse_stock_id'], $session_tenant_id, $assigned_branch_id]);
                    $pdo->prepare("UPDATE branch_transfer_items SET transferred_quantity = ? WHERE id = ?")
                        ->execute([$qty, (int)$it['id']]);
                }

                $upd = $pdo->prepare("UPDATE branch_transfers SET status = 'in_transit' WHERE id = ?");
                $upd->execute([$id]);
                $msg = 'Transfer-ka waa la diray, stock-kana waa laga jaray laantaada.';
            } else { // complete_transfer
                if (!$isIncoming || $status !== 'in_transit') throw new Exception('Transfer-kan lama dhameystiri karo hadda.');

                $itemsStmt = $pdo->prepare("SELECT bti.*, ws.* FROM branch_transfer_items bti JOIN warehouse_stock ws ON bti.warehouse_stock_id = ws.id WHERE bti.transfer_id = ?");
                $itemsStmt->execute([$id]);
                $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                if (!$items) throw new Exception('Transfer items lama helin.');

                foreach ($items as $it) {
                    $qty = (int)$it['transferred_quantity'];
                    if ($qty <= 0) $qty = (int)$it['quantity'];

                    // Same stock in destination branch? merge by stock name + customer + location
                    $find = $pdo->prepare("SELECT id FROM warehouse_stock WHERE tenant_id = ? AND branch_id = ? AND LOWER(TRIM(stock_name)) = LOWER(TRIM(?)) AND COALESCE(customer_id,0) = COALESCE(?,0) AND LOWER(TRIM(COALESCE(location,''))) = LOWER(TRIM(COALESCE(?,''))) LIMIT 1");
                    $find->execute([$session_tenant_id, $assigned_branch_id, $it['stock_name'], $it['customer_id'], $it['location']]);
                    $existing = $find->fetch(PDO::FETCH_ASSOC);

                    if ($existing) {
                        $pdo->prepare("UPDATE warehouse_stock SET quantity = quantity + ?, last_updated = NOW(), updated_by = ? WHERE id = ? AND tenant_id = ? AND branch_id = ?")
                            ->execute([$qty, $user_id, (int)$existing['id'], $session_tenant_id, $assigned_branch_id]);
                    } else {
                        $ins = $pdo->prepare("INSERT INTO warehouse_stock (tenant_id, branch_id, customer_id, origin_branch_id, origin, stock_name, quantity, length_cm, width_cm, height_cm, volume_cbm, location, minimum_stock, maximum_stock, unit_price, updated_by, last_updated, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                        $ins->execute([
                            $session_tenant_id,
                            $assigned_branch_id,
                            $it['customer_id'] ?? null,
                            $it['origin_branch_id'] ?? null,
                            $it['origin'] ?? 'local',
                            $it['stock_name'],
                            $qty,
                            $it['length_cm'] ?? 0,
                            $it['width_cm'] ?? 0,
                            $it['height_cm'] ?? 0,
                            $it['volume_cbm'] ?? 0,
                            $it['location'] ?? '',
                            $it['minimum_stock'] ?? 0,
                            $it['maximum_stock'] ?? 0,
                            $it['unit_price'] ?? 0,
                            $user_id
                        ]);
                    }
                    $pdo->prepare("UPDATE branch_transfer_items SET received_quantity = ? WHERE id = ?")
                        ->execute([$qty, (int)$it['id']]);
                }

                $upd = $pdo->prepare("UPDATE branch_transfers SET status = 'completed', completed_at = NOW() WHERE id = ?");
                $upd->execute([$id]);
                $msg = 'Transfer waa la dhammeystiray, stock-kana waa lagu daray laantaada.';
            }

            $pdo->commit();
            jsonOut(['success' => true, 'message' => $msg]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonOut(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
    }

    jsonOut(['success' => false, 'message' => 'Action lama yaqaan.']);
}

// Stats
$stats = ['total' => 0, 'incoming' => 0, 'outgoing' => 0, 'pending' => 0];
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) total, SUM(CASE WHEN to_branch_id = ? THEN 1 ELSE 0 END) incoming, SUM(CASE WHEN from_branch_id = ? THEN 1 ELSE 0 END) outgoing, SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) pending FROM branch_transfers WHERE tenant_id = ? AND (from_branch_id = ? OR to_branch_id = ?)");
    $stmt->execute([$assigned_branch_id, $assigned_branch_id, $session_tenant_id, $assigned_branch_id, $assigned_branch_id]);
    $stats = array_merge($stats, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Transfers</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { background:#f4f6f9; }
        .page-wrap { padding: 20px; }
        .hero { background: linear-gradient(135deg,#2D1859,#4B2C85); color:#fff; border-radius:18px; padding:22px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
        .hero h3 { margin:0; font-weight:700; }
        .hero small { opacity:.9; }
        .stat-card { background:#fff; border-radius:16px; padding:18px; box-shadow:0 6px 18px rgba(0,0,0,.06); border:1px solid #eee; }
        .stat-card .num { font-size:28px; font-weight:800; color:#2D1859; }
        .panel { background:#fff; border-radius:16px; padding:18px; box-shadow:0 6px 18px rgba(0,0,0,.06); border:1px solid #eee; }
        .search-result { border:1px solid #ddd; border-radius:10px; max-height:220px; overflow:auto; display:none; background:#fff; position:absolute; z-index:1000; width:100%; }
        .search-result .item { padding:10px; cursor:pointer; border-bottom:1px solid #eee; }
        .search-result .item:hover { background:#f4f2f6; }
        .selected-item-row { background:#fafafa; border:1px solid #e2e2e2; border-radius:10px; padding:10px; margin-bottom:8px; display:grid; grid-template-columns: 1fr 120px 40px; gap:8px; align-items:center; }
        .btn-main { background:#2D1859; color:#fff; border:0; }
        .btn-main:hover { background:#1F0F3D; color:#fff; }
    </style>
</head>
<body>
<div class="page-wrap">
    <div class="hero">
        <div>
            <h3><i class="fas fa-exchange-alt"></i> Branch Transfers</h3>
            <small>Laantaada: <?= h($current_branch['branch_name']) ?> <?= !empty($current_branch['branch_code']) ? '(' . h($current_branch['branch_code']) . ')' : '' ?></small>
        </div>
        <button class="btn btn-light" data-toggle="modal" data-target="#createTransferModal"><i class="fas fa-plus-circle"></i> New Transfer</button>
    </div>

    <div class="row mb-3">
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$stats['total']) ?></div><div>Total Transfers</div></div></div>
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$stats['incoming']) ?></div><div>Incoming</div></div></div>
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$stats['outgoing']) ?></div><div>Outgoing</div></div></div>
        <div class="col-md-3 mb-3"><div class="stat-card"><div class="num"><?= number_format((int)$stats['pending']) ?></div><div>Pending</div></div></div>
    </div>

    <div class="panel">
        <div class="row mb-3">
            <div class="col-md-6"><input type="text" id="searchInput" class="form-control" placeholder="Search transfer number, branch, notes..."></div>
            <div class="col-md-3">
                <select id="statusFilter" class="form-control">
                    <option value="all">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="in_transit">In Transit</option>
                    <option value="completed">Completed</option>
                    <option value="rejected">Rejected</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <div class="col-md-3"><button id="refreshBtn" class="btn btn-main btn-block"><i class="fas fa-sync"></i> Refresh</button></div>
        </div>
        <div id="transfersTable"><div class="text-center py-5"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>
        <div id="paginationBox"></div>
    </div>
</div>

<!-- Create Transfer Modal -->
<div class="modal fade" id="createTransferModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="createTransferForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create Branch Transfer</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="ajax_action" value="create_transfer">
                <div class="form-group">
                    <label>From Branch</label>
                    <input type="text" class="form-control" value="<?= h($current_branch['branch_name']) ?>" readonly>
                </div>
                <div class="form-group">
                    <label>To Branch <span class="text-danger">*</span></label>
                    <select name="to_branch_id" class="form-control" required>
                        <option value="">Dooro laanta loo dirayo</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= h($b['branch_name']) ?> <?= !empty($b['branch_code']) ? '(' . h($b['branch_code']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group position-relative">
                    <label>Search Stock</label>
                    <input type="text" id="stockSearch" class="form-control" placeholder="Ku qor magaca alaabta ama location...">
                    <div id="stockResults" class="search-result"></div>
                </div>
                <div class="form-group">
                    <label>Selected Items</label>
                    <div id="selectedItems"><div class="text-muted">Alaab lama dooran.</div></div>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Faahfaahin haddii loo baahdo..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-main"><i class="fas fa-save"></i> Save Transfer</button>
            </div>
        </form>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewTransferModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Transfer Details</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="transferDetails"><div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i></div></div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentPage = 1;
let selectedStocks = new Map();
let searchTimer = null;

function toast(msg, ok = true) {
    alert(msg);
}

function loadTransfers(page = 1) {
    currentPage = page;
    $('#transfersTable').html('<div class="text-center py-5"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
    $.post('', {
        ajax_action: 'list_transfers',
        page: page,
        search: $('#searchInput').val(),
        status: $('#statusFilter').val()
    }, function(res) {
        if (res.success) {
            $('#transfersTable').html(res.html);
            $('#paginationBox').html(res.pagination);
        } else {
            $('#transfersTable').html('<div class="alert alert-danger">' + (res.message || 'Error') + '</div>');
        }
    }, 'json').fail(function() {
        $('#transfersTable').html('<div class="alert alert-danger">Server error.</div>');
    });
}

function renderSelectedItems() {
    const box = $('#selectedItems');
    if (selectedStocks.size === 0) {
        box.html('<div class="text-muted">Alaab lama dooran.</div>');
        return;
    }
    let html = '';
    selectedStocks.forEach((item, id) => {
        html += `<div class="selected-item-row" data-id="${id}">
            <div><strong>${item.name}</strong><br><small>Available: ${item.available} | ${item.location || '-'}</small><input type="hidden" name="stock_id[]" value="${id}"></div>
            <input type="number" name="quantity[]" class="form-control form-control-sm" min="1" max="${item.available}" value="${item.qty}">
            <button type="button" class="btn btn-sm btn-danger remove-selected" data-id="${id}"><i class="fas fa-trash"></i></button>
        </div>`;
    });
    box.html(html);
}

$(document).on('keyup', '#searchInput', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadTransfers(1), 350);
});
$('#statusFilter, #refreshBtn').on('change click', function() { loadTransfers(1); });
$(document).on('click', '#paginationBox .page-link', function(e) { e.preventDefault(); loadTransfers(parseInt($(this).data('page'))); });

$('#stockSearch').on('keyup', function() {
    const q = $(this).val().trim();
    clearTimeout(searchTimer);
    if (q.length < 1) { $('#stockResults').hide(); return; }
    searchTimer = setTimeout(() => {
        $.post('', { ajax_action: 'search_stock', q: q }, function(res) {
            if (!res.success || !res.items.length) {
                $('#stockResults').html('<div class="item text-muted">Alaab lama helin</div>').show();
                return;
            }
            let html = '';
            res.items.forEach(it => {
                html += `<div class="item pick-stock" data-id="${it.id}" data-name="${$('<div>').text(it.stock_name).html()}" data-qty="${it.quantity}" data-location="${$('<div>').text(it.location || '').html()}">
                    <strong>${it.stock_name}</strong><br><small>Qty: ${it.quantity} | ${it.location || '-'}</small>
                </div>`;
            });
            $('#stockResults').html(html).show();
        }, 'json');
    }, 250);
});

$(document).on('click', '.pick-stock', function() {
    const id = String($(this).data('id'));
    if (!selectedStocks.has(id)) {
        selectedStocks.set(id, {
            name: $(this).data('name'),
            available: parseInt($(this).data('qty')),
            location: $(this).data('location'),
            qty: 1
        });
    }
    $('#stockSearch').val('');
    $('#stockResults').hide();
    renderSelectedItems();
});

$(document).on('click', '.remove-selected', function() {
    selectedStocks.delete(String($(this).data('id')));
    renderSelectedItems();
});

$('#createTransferForm').on('submit', function(e) {
    e.preventDefault();
    if (selectedStocks.size === 0) { toast('Fadlan dooro alaab.', false); return; }
    $.post('', $(this).serialize(), function(res) {
        toast(res.message || (res.success ? 'Saved' : 'Error'), res.success);
        if (res.success) {
            $('#createTransferModal').modal('hide');
            $('#createTransferForm')[0].reset();
            selectedStocks.clear();
            renderSelectedItems();
            loadTransfers(1);
        }
    }, 'json').fail(function() { toast('Server error.', false); });
});

$(document).on('click', '.view-transfer', function() {
    $('#transferDetails').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i></div>');
    $('#viewTransferModal').modal('show');
    $.post('', { ajax_action: 'get_transfer', id: $(this).data('id') }, function(res) {
        $('#transferDetails').html(res.success ? res.html : '<div class="alert alert-danger">' + res.message + '</div>');
    }, 'json');
});

function actionTransfer(id, action, text) {
    if (!confirm(text)) return;
    $.post('', { ajax_action: action, id: id }, function(res) {
        toast(res.message || (res.success ? 'Done' : 'Error'), res.success);
        if (res.success) loadTransfers(currentPage);
    }, 'json').fail(function() { toast('Server error.', false); });
}

$(document).on('click', '.approve-transfer', function(){ actionTransfer($(this).data('id'), 'approve_transfer', 'Approve transfer-kan?'); });
$(document).on('click', '.reject-transfer', function(){ actionTransfer($(this).data('id'), 'reject_transfer', 'Reject transfer-kan?'); });
$(document).on('click', '.cancel-transfer', function(){ actionTransfer($(this).data('id'), 'cancel_transfer', 'Cancel transfer-kan?'); });
$(document).on('click', '.transit-transfer', function(){ actionTransfer($(this).data('id'), 'transit_transfer', 'Dir transfer-kan oo stock-ka ka jar laantaada?'); });
$(document).on('click', '.complete-transfer', function(){ actionTransfer($(this).data('id'), 'complete_transfer', 'Dhammeystir transfer-kan oo stock-ka ku dar laantaada?'); });

$(function(){ loadTransfers(); });
</script>
</body>
</html>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
