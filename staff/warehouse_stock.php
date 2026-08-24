<?php
// staff/warehouse_stock.php
// Warehouse Stock management for Staff — scoped to the staff member's assigned branch.
// Adapted from branch_manager/warehouse_stock.php; every quantity/location change is
// logged into stock_movements for the audit trail shown on staff/stock_movements.php.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db_connect.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    die('Database connection failed.');
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    } catch (Throwable $e) {
        error_log("ensureColumn failed for {$table}.{$column}: " . $e->getMessage());
    }
}

ensureColumn($pdo, 'warehouse_stock', 'branch_id', 'INT(11) DEFAULT NULL');
ensureColumn($pdo, 'warehouse_stock', 'location', 'VARCHAR(255) DEFAULT NULL');
ensureColumn($pdo, 'warehouse_stock', 'minimum_stock', 'INT(11) DEFAULT 0');
ensureColumn($pdo, 'warehouse_stock', 'maximum_stock', 'INT(11) DEFAULT 0');
ensureColumn($pdo, 'warehouse_stock', 'updated_by', 'INT(11) DEFAULT NULL');
ensureColumn($pdo, 'warehouse_stock', 'last_updated', 'DATETIME DEFAULT NULL');
ensureColumn($pdo, 'customers', 'branch_id', 'INT(11) DEFAULT NULL');

// Only staff accounts may access this page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header("Location: ../login.php");
    exit;
}

$session_tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];

if (!$session_tenant_id) {
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

// Branch / tenant display names
$branch_name = 'My Branch';
try {
    $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$assigned_branch_id, $session_tenant_id]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) $branch_name = $branch['branch_name'];
} catch (PDOException $e) {}

$tenant_name = 'Company';
try {
    $stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
    $stmt->execute([$session_tenant_id]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tenant) $tenant_name = $tenant['name'];
} catch (PDOException $e) {}

// ── Helpers ───────────────────────────────────────────────────────────────
function escapeHtml($text) {
    if ($text === null) return '';
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function decimalPostValue(string $key, float $default = 0.0): float {
    $value = $_POST[$key] ?? $default;
    if (is_array($value)) return $default;
    $value = str_replace(',', '.', trim((string)$value));
    return is_numeric($value) ? (float)$value : $default;
}

function intPostValue(string $key, int $default = 0): int {
    $value = $_POST[$key] ?? $default;
    if (is_array($value)) return $default;
    return is_numeric($value) ? (int)$value : $default;
}

// Records a row in stock_movements any time a warehouse_stock quantity or
// location changes, so staff/stock_movements.php has a full audit trail.
function logStockMovement(PDO $pdo, int $tenant_id, int $warehouse_stock_id, string $movement_type, int $quantity_change, int $previous_quantity, int $new_quantity, ?string $reference_type, ?int $reference_id, ?string $notes, ?int $created_by): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO stock_movements
            (tenant_id, warehouse_stock_id, quantity_change, previous_quantity, new_quantity, movement_type, reference_type, reference_id, notes, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$tenant_id, $warehouse_stock_id, $quantity_change, $previous_quantity, $new_quantity, $movement_type, $reference_type, $reference_id, $notes, $created_by]);
    } catch (Throwable $e) {
        error_log('logStockMovement failed: ' . $e->getMessage());
    }
}

// ==============================================
// Handle AJAX requests
// ==============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];

    if ($action === 'search_customers') {
        $q = trim($_POST['q'] ?? '');
        $results = [];
        if ($q !== '') {
            try {
                $like = '%' . $q . '%';
                $stmt = $pdo->prepare("
                    SELECT id, customer_name, phone, COALESCE(debt_amount, 0) AS balance, COALESCE(loyalty_points, 0) AS loyalty_points
                    FROM customers
                    WHERE tenant_id = ? AND is_active = 1 AND (customer_name LIKE ? OR phone LIKE ?)
                    ORDER BY customer_name ASC
                    LIMIT 20
                ");
                $stmt->execute([$session_tenant_id, $like, $like]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $results = [];
            }
        }
        echo json_encode(['success' => true, 'customers' => $results]);
        exit;
    }

    if ($action === 'quick_add_customer') {
        $name = trim($_POST['customer_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if (empty($name)) { echo json_encode(['success' => false, 'message' => 'Customer name is required']); exit; }
        if (empty($phone)) { echo json_encode(['success' => false, 'message' => 'Phone number is required']); exit; }

        try {
            $chk = $pdo->prepare("SELECT id FROM customers WHERE tenant_id = ? AND phone = ?");
            $chk->execute([$session_tenant_id, $phone]);
            if ($chk->fetch()) { echo json_encode(['success' => false, 'message' => 'A customer with this phone already exists']); exit; }

            $stmt = $pdo->prepare("INSERT INTO customers (tenant_id, branch_id, customer_name, phone, email, address, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
            $stmt->execute([$session_tenant_id, $assigned_branch_id, $name, $phone, $email, $address]);
            $new_id = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'id' => $new_id, 'name' => $name, 'phone' => $phone]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_stock_items') {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;

        $search = $_POST['search'] ?? '';
        $low_stock_only = isset($_POST['low_stock_only']) ? (int)$_POST['low_stock_only'] : 0;

        $where_conditions = ["ws.tenant_id = ?", "ws.branch_id = ?"];
        $params = [$session_tenant_id, $assigned_branch_id];

        if (!empty($search)) {
            $where_conditions[] = "(ws.stock_name LIKE ? OR ws.location LIKE ? OR c.customer_name LIKE ?)";
            $like = "%$search%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($low_stock_only == 1) {
            $where_conditions[] = "ws.quantity <= ws.minimum_stock";
        }

        $where_clause = "WHERE " . implode(" AND ", $where_conditions);

        $count_sql = "SELECT COUNT(*) as total FROM warehouse_stock ws LEFT JOIN customers c ON ws.customer_id = c.id $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_items / $limit);

        $sql = "
            SELECT ws.*, c.customer_name, c.phone
            FROM warehouse_stock ws
            LEFT JOIN customers c ON ws.customer_id = c.id
            $where_clause
            ORDER BY
                CASE WHEN ws.quantity <= ws.minimum_stock THEN 1 ELSE 2 END,
                ws.stock_name ASC
            LIMIT $limit OFFSET $offset
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ob_start(); ?>
        <div style="overflow-x: auto; width: 100%;">
            <table class="table table-bordered table-hover" style="min-width: 1000px;">
                <thead style="background: #f8f6f9;">
                    <tr>
                        <th style="padding: 12px;">ID</th>
                        <th style="padding: 12px;">Item Name</th>
                        <th style="padding: 12px;">Quantity</th>
                        <th style="padding: 12px;">Volume (CBM)</th>
                        <th style="padding: 12px;">Location</th>
                        <th style="padding: 12px;">Status</th>
                        <th style="padding: 12px;">Unit Price</th>
                        <th style="padding: 12px;">Customer</th>
                        <th style="padding: 12px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($stock_items) > 0): ?>
                        <?php foreach ($stock_items as $item):
                            $isLowStock = $item['quantity'] <= $item['minimum_stock'];
                            $stockStatusText = $isLowStock ? 'Low Stock' : 'OK';
                            $statusColor = $isLowStock ? '#dc3545' : '#0F7A3A';
                        ?>
                            <tr style="<?= $isLowStock ? 'background: #fff3cd;' : '' ?>">
                                <td style="padding: 12px;"><?= (int)$item['id'] ?></td>
                                <td style="padding: 12px;">
                                    <strong><?= escapeHtml($item['stock_name'] ?? '-') ?></strong>
                                    <br><small class="text-muted">SKU: STK-<?= str_pad($item['id'], 5, '0', STR_PAD_LEFT) ?></small>
                                </td>
                                <td style="padding: 12px; font-weight: bold; color: <?= $statusColor ?>;"><?= number_format($item['quantity']) ?></td>
                                <td style="padding: 12px;"><?= number_format($item['volume_cbm'], 6) ?> CBM</td>
                                <td style="padding: 12px;"><?= escapeHtml($item['location'] ?? '-') ?></td>
                                <td style="padding: 12px;">
                                    <span class="badge" style="background: <?= $statusColor ?>20; color: <?= $statusColor ?>; padding: 4px 10px; border-radius: 20px;">
                                        <?= $stockStatusText ?>
                                    </span>
                                </td>
                                <td style="padding: 12px;">
                                    $<?= number_format($item['unit_price'], 2) ?>
                                    <br><small>Total: $<?= number_format($item['volume_cbm'] * $item['unit_price'], 2) ?></small>
                                </td>
                                <td style="padding: 12px;">
                                    <?= escapeHtml($item['customer_name'] ?? '-') ?>
                                    <br><small><?= escapeHtml($item['phone'] ?? '') ?></small>
                                </td>
                                <td style="padding: 12px;">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-info view-stock" data-id="<?= $item['id'] ?>" title="View"><i class="fas fa-eye"></i></button>
                                        <button class="btn btn-warning edit-stock" data-id="<?= $item['id'] ?>" title="Edit"><i class="fas fa-edit"></i></button>
                                        <button class="btn btn-secondary move-stock" data-id="<?= $item['id'] ?>" data-name="<?= escapeHtml($item['stock_name'] ?? '') ?>" title="Move"><i class="fas fa-exchange-alt"></i></button>
                                        <button class="btn btn-primary adjust-stock" data-id="<?= $item['id'] ?>" data-name="<?= escapeHtml($item['stock_name'] ?? '') ?>" data-qty="<?= $item['quantity'] ?>" title="Adjust Quantity"><i class="fas fa-sliders-h"></i></button>
                                        <button class="btn btn-danger delete-stock" data-id="<?= $item['id'] ?>" data-name="<?= escapeHtml($item['stock_name'] ?? '') ?>" title="Delete"><i class="fas fa-trash"></i></button>
                                    </div>
                                </td>
                             </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center py-5">
                                <div class="empty-state">
                                    <i class="fas fa-warehouse fa-3x text-muted mb-3"></i>
                                    <p>No stock items in the warehouse yet.</p>
                                    <button class="btn btn-primary" id="addStockBtnEmpty"><i class="fas fa-plus-circle"></i> Add Stock</button>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        $table_html = ob_get_clean();

        ob_start();
        if ($total_pages > 1): ?>
            <div class="pagination" style="display: flex; justify-content: center; gap: 8px; margin-top: 20px;">
                <?php if ($page > 1): ?>
                    <a data-page="<?= $page-1 ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-left"></i> Prev</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="btn btn-sm btn-primary"><?= $i ?></span>
                    <?php else: ?>
                        <a data-page="<?= $i ?>" class="btn btn-sm btn-outline-secondary"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a data-page="<?= $page+1 ?>" class="btn btn-sm btn-outline-secondary">Next <i class="fas fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        <?php endif;
        $pagination_html = ob_get_clean();

        echo json_encode(['table_html' => $table_html, 'pagination_html' => $pagination_html]);
        exit;
    }

    if ($action === 'get_stock_item') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("
            SELECT ws.*, c.customer_name, c.phone
            FROM warehouse_stock ws
            LEFT JOIN customers c ON ws.customer_id = c.id
            WHERE ws.id = ? AND ws.tenant_id = ? AND ws.branch_id = ?
        ");
        $stmt->execute([$id, $session_tenant_id, $assigned_branch_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($item);
        exit;
    }

    if ($action === 'save_stock_item') {
        $id = trim((string)($_POST['stock_id'] ?? ''));
        $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $stock_name = trim((string)($_POST['stock_name'] ?? ''));
        $quantity = intPostValue('quantity', 0);
        $location = trim((string)($_POST['location'] ?? ''));
        $minimum_stock = intPostValue('minimum_stock', 0);
        $maximum_stock = intPostValue('maximum_stock', 0);
        $unit_price = decimalPostValue('unit_price', 0);
        $length_cm = decimalPostValue('length_cm', 0);
        $width_cm = decimalPostValue('width_cm', 0);
        $height_cm = decimalPostValue('height_cm', 0);
        $volume_cbm = decimalPostValue('volume_cbm', 0);

        if ($stock_name === '') {
            echo json_encode(['success' => false, 'message' => 'Item name is required']);
            exit;
        }

        try {
            if ($volume_cbm <= 0 && $length_cm > 0 && $width_cm > 0 && $height_cm > 0) {
                $volume_cbm = ($length_cm * $width_cm * $height_cm) / 1000000;
            }

            if ($id === '') {
                // Merge into an existing identical item (same name + location) rather than duplicating
                $stmt = $pdo->prepare("
                    SELECT id, quantity FROM warehouse_stock
                    WHERE tenant_id = ? AND branch_id = ? AND LOWER(stock_name) = LOWER(?) AND location = ?
                    LIMIT 1
                ");
                $stmt->execute([$session_tenant_id, $assigned_branch_id, $stock_name, $location]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $prev_qty = (int)$existing['quantity'];
                    $new_qty = $prev_qty + $quantity;
                    $stmt = $pdo->prepare("
                        UPDATE warehouse_stock
                        SET quantity = ?, updated_by = ?, last_updated = NOW()
                        WHERE id = ? AND tenant_id = ? AND branch_id = ?
                    ");
                    $stmt->execute([$new_qty, $user_id, $existing['id'], $session_tenant_id, $assigned_branch_id]);
                    logStockMovement($pdo, $session_tenant_id, (int)$existing['id'], 'in', $quantity, $prev_qty, $new_qty, 'stock_receive', null, "Added to existing item '$stock_name'", $user_id);
                    echo json_encode(['success' => true, 'message' => "'$stock_name' quantity increased by $quantity (existing item)"]);
                    exit;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO warehouse_stock
                    (tenant_id, branch_id, customer_id, stock_name, quantity,
                     length_cm, width_cm, height_cm, volume_cbm, location,
                     minimum_stock, maximum_stock, unit_price, updated_by, last_updated, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $session_tenant_id, $assigned_branch_id, $customer_id, $stock_name, $quantity,
                    $length_cm, $width_cm, $height_cm, $volume_cbm, $location,
                    $minimum_stock, $maximum_stock, $unit_price, $user_id
                ]);
                $new_id = (int)$pdo->lastInsertId();
                logStockMovement($pdo, $session_tenant_id, $new_id, 'in', $quantity, 0, $quantity, 'stock_receive', null, "New item '$stock_name' added to stock", $user_id);
                echo json_encode(['success' => true, 'message' => "'$stock_name' saved to warehouse stock"]);
            } else {
                $prevStmt = $pdo->prepare("SELECT quantity FROM warehouse_stock WHERE id = ? AND tenant_id = ? AND branch_id = ?");
                $prevStmt->execute([$id, $session_tenant_id, $assigned_branch_id]);
                $prevRow = $prevStmt->fetch(PDO::FETCH_ASSOC);
                if (!$prevRow) {
                    echo json_encode(['success' => false, 'message' => 'Item not found']);
                    exit;
                }
                $prev_qty = (int)$prevRow['quantity'];

                $stmt = $pdo->prepare("
                    UPDATE warehouse_stock
                    SET customer_id = ?, stock_name = ?, quantity = ?,
                        length_cm = ?, width_cm = ?, height_cm = ?, volume_cbm = ?,
                        location = ?, minimum_stock = ?, maximum_stock = ?, unit_price = ?,
                        updated_by = ?, last_updated = NOW()
                    WHERE id = ? AND tenant_id = ? AND branch_id = ?
                ");
                $stmt->execute([
                    $customer_id, $stock_name, $quantity,
                    $length_cm, $width_cm, $height_cm, $volume_cbm, $location,
                    $minimum_stock, $maximum_stock, $unit_price, $user_id,
                    $id, $session_tenant_id, $assigned_branch_id
                ]);

                if ($quantity !== $prev_qty) {
                    logStockMovement($pdo, $session_tenant_id, (int)$id, 'adjust', $quantity - $prev_qty, $prev_qty, $quantity, 'stock_edit', null, "Quantity changed while editing '$stock_name'", $user_id);
                }
                echo json_encode(['success' => true, 'message' => "'$stock_name' updated"]);
            }
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete_stock_item') {
        $id = $_POST['id'] ?? 0;
        try {
            $stmt = $pdo->prepare("SELECT stock_name, quantity FROM warehouse_stock WHERE id = ? AND tenant_id = ? AND branch_id = ?");
            $stmt->execute([$id, $session_tenant_id, $assigned_branch_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                echo json_encode(['success' => false, 'message' => 'Item not found']);
                exit;
            }
            $qty = (int)$item['quantity'];
            $stmt = $pdo->prepare("DELETE FROM warehouse_stock WHERE id = ? AND tenant_id = ? AND branch_id = ?");
            $stmt->execute([$id, $session_tenant_id, $assigned_branch_id]);
            if ($qty > 0) {
                logStockMovement($pdo, $session_tenant_id, (int)$id, 'out', -$qty, $qty, 0, 'stock_delete', null, "Item '{$item['stock_name']}' removed from stock", $user_id);
            }
            echo json_encode(['success' => true, 'message' => "'{$item['stock_name']}' deleted"]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'move_stock') {
        $id = $_POST['id'] ?? 0;
        $new_location = trim($_POST['new_location'] ?? '');

        if (empty($new_location)) {
            echo json_encode(['success' => false, 'message' => 'Please enter the new location']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT stock_name, quantity, location FROM warehouse_stock WHERE id = ? AND tenant_id = ? AND branch_id = ?");
            $stmt->execute([$id, $session_tenant_id, $assigned_branch_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                echo json_encode(['success' => false, 'message' => 'Item not found']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE warehouse_stock SET location = ?, updated_by = ?, last_updated = NOW() WHERE id = ? AND tenant_id = ? AND branch_id = ?");
            $stmt->execute([$new_location, $user_id, $id, $session_tenant_id, $assigned_branch_id]);

            $qty = (int)$item['quantity'];
            logStockMovement($pdo, $session_tenant_id, (int)$id, 'move', 0, $qty, $qty, 'stock_move', null, "Moved from '{$item['location']}' to '$new_location'", $user_id);

            echo json_encode(['success' => true, 'message' => "'{$item['stock_name']}' moved to '$new_location'"]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'adjust_stock') {
        $id = $_POST['id'] ?? 0;
        $adjustment = (int)($_POST['adjustment'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if ($adjustment == 0) {
            echo json_encode(['success' => false, 'message' => 'Please enter a non-zero adjustment']);
            exit;
        }
        if (empty($reason)) {
            echo json_encode(['success' => false, 'message' => 'Please provide a reason for the adjustment']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT stock_name, quantity FROM warehouse_stock WHERE id = ? AND tenant_id = ? AND branch_id = ?");
            $stmt->execute([$id, $session_tenant_id, $assigned_branch_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                echo json_encode(['success' => false, 'message' => 'Item not found']);
                exit;
            }

            $prev_qty = (int)$item['quantity'];
            $new_quantity = $prev_qty + $adjustment;
            if ($new_quantity < 0) {
                echo json_encode(['success' => false, 'message' => 'Quantity cannot go below zero']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE warehouse_stock SET quantity = ?, updated_by = ?, last_updated = NOW() WHERE id = ? AND tenant_id = ? AND branch_id = ?");
            $stmt->execute([$new_quantity, $user_id, $id, $session_tenant_id, $assigned_branch_id]);

            logStockMovement($pdo, $session_tenant_id, (int)$id, 'adjust', $adjustment, $prev_qty, $new_quantity, 'stock_adjust', null, $reason, $user_id);

            $action_text = $adjustment > 0 ? "increased by $adjustment" : "decreased by " . abs($adjustment);
            echo json_encode(['success' => true, 'message' => "'{$item['stock_name']}' $action_text. New quantity: $new_quantity"]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_stats') {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) as total_items,
                COALESCE(SUM(quantity), 0) as total_quantity,
                COALESCE(SUM(volume_cbm), 0) as total_volume,
                COALESCE(SUM(volume_cbm * unit_price), 0) as total_value,
                COUNT(CASE WHEN quantity <= minimum_stock THEN 1 END) as low_stock_items
            FROM warehouse_stock
            WHERE tenant_id = ? AND branch_id = ?
        ");
        $stmt->execute([$session_tenant_id, $assigned_branch_id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['stats' => $stats]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid" style="padding: 20px;">
<style>
    :root { --curdun-violet: #2D1859; --curdun-yellow: #F5C410; --curdun-violet-light: #4B2C85; --curdun-yellow-dark: #D4A70C; }
    .stock-page .page-header { background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light)); border-radius: 16px; padding: 20px 25px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
    .stock-page .page-header h1 { color: white; font-size: 24px; margin: 0; }
    .stock-page .page-header h1 i { margin-right: 10px; }
    .stock-page .branch-badge { background: rgba(255,255,255,0.2); padding: 8px 16px; border-radius: 30px; font-size: 13px; color: white; }
    .stock-page .btn-primary-custom { background: var(--curdun-yellow); color: var(--curdun-violet); border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; }
    .stock-page .btn-primary-custom:hover { background: var(--curdun-yellow-dark); }
    .stock-page .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
    .stock-page .stat-card { background: white; border-radius: 12px; padding: 15px 20px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .stock-page .stat-card .stat-info h4 { font-size: 11px; color: #6c757d; margin: 0; text-transform: uppercase; }
    .stock-page .stat-card .stat-info .stat-number { font-size: 22px; font-weight: 700; color: var(--curdun-violet); }
    .stock-page .stat-icon { width: 45px; height: 45px; background: rgba(45,24,89,0.08); border-radius: 12px; display: flex; align-items: center; justify-content: center; }
    .stock-page .stat-icon i { font-size: 20px; color: var(--curdun-violet); }
    .stock-page .filters-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 25px; border: 1px solid #e5e7eb; }
    .stock-page .filter-form { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
    .stock-page .filter-group { flex: 1; min-width: 150px; }
    .stock-page .filter-group label { display: block; font-size: 12px; font-weight: 600; color: #4b5563; margin-bottom: 5px; }
    .stock-page .filter-group input, .stock-page .filter-group select { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 13px; }
    .stock-page .btn-filter { background: var(--curdun-violet); color: white; border: none; padding: 8px 20px; border-radius: 10px; cursor: pointer; }
    .stock-page .btn-reset { background: #f3f4f6; border: 1px solid #e5e7eb; padding: 8px 20px; border-radius: 10px; cursor: pointer; margin-left: 10px; }
    .stock-page .stock-table-container { background: white; border-radius: 16px; border: 1px solid #e5e7eb; overflow: hidden; }
    .stock-page .action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
    .stock-page .modal-header { background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light)); color: white; border-bottom: none; }
    .stock-page .modal-header .close { color: white; opacity: 0.8; }
    .stock-page .form-group label { font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 5px; display: block; }
    .stock-page .form-control { border-radius: 10px; border: 1px solid #d1d5db; padding: 8px 12px; font-size: 13px; }
    .stock-page .customer-search-box { position: relative; }
    .stock-page .customer-search-results { display: none; position: absolute; left: 0; right: 0; top: 100%; z-index: 1060; background: #fff; border: 1px solid #ddd; border-radius: 8px; max-height: 260px; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.12); }
    .stock-page .customer-result-item { padding: 10px 12px; cursor: pointer; border-bottom: 1px solid #f0f0f0; }
    .stock-page .customer-result-item:hover { background: #f7f5ff; }
    .stock-page .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
    .stock-page .pagination a, .stock-page .pagination span { padding: 8px 14px; border-radius: 8px; background: white; border: 1px solid #ddd; cursor: pointer; }
    .stock-page .pagination .active { background: var(--curdun-violet); color: white; border-color: var(--curdun-violet); }
    @media (max-width: 768px) { .stock-page .filter-form { flex-direction: column; } .stock-page .stats-grid { grid-template-columns: repeat(2, 1fr); } }
</style>

<div class="stock-page">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-warehouse"></i> Warehouse Stock</h1>
        <div class="d-flex flex-wrap align-items-center" style="gap:8px;">
            <span class="branch-badge"><i class="fas fa-code-branch"></i> <?= escapeHtml($branch_name) ?></span>
            <button class="btn-primary-custom" id="addStockBtn"><i class="fas fa-plus-circle"></i> Add Stock</button>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-info"><h4>Total Items</h4><div class="stat-number" id="stat-total-items">0</div></div><div class="stat-icon"><i class="fas fa-boxes"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Total Quantity</h4><div class="stat-number" id="stat-total-quantity">0</div></div><div class="stat-icon"><i class="fas fa-cubes"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Total Volume (CBM)</h4><div class="stat-number" id="stat-total-volume">0</div></div><div class="stat-icon"><i class="fas fa-cube"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Total Value</h4><div class="stat-number" id="stat-total-value">$0</div></div><div class="stat-icon"><i class="fas fa-dollar-sign"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Low Stock</h4><div class="stat-number" id="stat-low-stock">0</div></div><div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div></div>
    </div>

    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group"><label><i class="fas fa-search"></i> Search</label><input type="text" id="searchInput" placeholder="Item name, location, customer..."></div>
            <div class="filter-group"><label><input type="checkbox" id="lowStockOnly"> <i class="fas fa-exclamation-triangle"></i> Low stock only</label></div>
            <div><button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Filter</button><button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Reset</button></div>
        </div>
    </div>

    <div class="stock-table-container">
        <div id="stock-table-container"><div class="loading-spinner" style="text-align:center;padding:50px;"><i class="fas fa-spinner fa-spin"></i><p>Loading stock...</p></div></div>
    </div>
    <div id="pagination-container"></div>
</div>

<!-- Stock Modal (Add/Edit) -->
<div class="modal fade" id="stockModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="stockModalLabel"><i class="fas fa-box"></i> Add Stock</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
            <form id="stockForm">
                <div class="modal-body">
                    <input type="hidden" name="stock_id" id="stock_id">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group customer-search-box">
                                <label>Customer <button type="button" id="quickAddCustomerBtn" class="btn btn-sm btn-primary">+ Add</button></label>
                                <input type="hidden" name="customer_id" id="modalCustomerId">
                                <input type="text" id="modalCustomerSearch" class="form-control" autocomplete="off" placeholder="Search customer by name or phone...">
                                <div id="modalCustomerResults" class="customer-search-results"></div>
                                <small id="modalCustomerInfo" class="text-muted d-block mt-1">No customer selected.</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Item Name <span class="text-danger">*</span></label>
                                <input type="text" name="stock_name" id="modalStockName" class="form-control" placeholder="e.g. Clothing, Shoes...">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Quantity</label>
                                <input type="number" name="quantity" id="modalQuantity" class="form-control" value="1" min="1">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Unit Price ($)</label>
                                <input type="number" step="0.01" name="unit_price" id="modalUnitPrice" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Location</label>
                                <input type="text" name="location" id="modalLocation" class="form-control" placeholder="Warehouse zone/shelf">
                            </div>
                        </div>
                    </div>

                    <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                        <label><i class="fas fa-ruler-combined"></i> Item Dimensions</label>
                        <div class="row">
                            <div class="col-md-4"><label style="font-size: 11px;">Length (cm)</label><input type="number" step="0.1" name="length_cm" id="modalLength" class="form-control dimension-input" value="0"></div>
                            <div class="col-md-4"><label style="font-size: 11px;">Width (cm)</label><input type="number" step="0.1" name="width_cm" id="modalWidth" class="form-control dimension-input" value="0"></div>
                            <div class="col-md-4"><label style="font-size: 11px;">Height (cm)</label><input type="number" step="0.1" name="height_cm" id="modalHeight" class="form-control dimension-input" value="0"></div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6"><label>Volume (CBM)</label><input type="number" step="0.000001" name="volume_cbm" id="modalVolume" class="form-control" value="0" placeholder="Auto or manual"></div>
                            <div class="col-md-3"><label>Minimum (alert)</label><input type="number" name="minimum_stock" id="modalMinStock" class="form-control" value="0"></div>
                            <div class="col-md-3"><label>Maximum</label><input type="number" name="maximum_stock" id="modalMaxStock" class="form-control" value="0"></div>
                        </div>
                    </div>

                    <div class="alert alert-info"><i class="fas fa-calculator"></i> <strong>Total Value:</strong> $<span id="totalValuePreview">0.00</span> (Volume &times; Unit Price)</div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary-custom">Save Item</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Move Stock Modal -->
<div class="modal fade" id="moveModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-exchange-alt"></i> Move to New Location</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <form id="moveForm"><div class="modal-body"><input type="hidden" name="stock_id" id="moveStockId"><p>Item: <strong id="moveStockName"></strong></p><div class="form-group"><label>New Location</label><input type="text" name="new_location" id="moveLocation" class="form-control" required></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-info">Move</button></div></form></div></div>
</div>

<!-- Adjust Stock Modal -->
<div class="modal fade" id="adjustModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header" style="background: #f59e0b;"><h5 class="modal-title"><i class="fas fa-sliders-h"></i> Adjust Quantity</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <form id="adjustForm"><div class="modal-body"><input type="hidden" name="stock_id" id="adjustStockId"><p>Item: <strong id="adjustStockName"></strong></p><p>Current Quantity: <strong id="adjustCurrentQty">0</strong></p><div class="form-group"><label>Adjustment (+ or -)</label><input type="number" name="adjustment" id="adjustmentQty" class="form-control" placeholder="e.g. +10 or -5" required></div><div class="form-group"><label>Reason</label><textarea name="reason" id="adjustReason" class="form-control" rows="2" required></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning">Adjust</button></div></form></div></div>
</div>

<!-- View Stock Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-eye"></i> Item Details</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body" id="viewModalBody"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button></div></div></div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header" style="background: #dc2626;"><h5 class="modal-title">Delete Item</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body">Are you sure you want to delete <strong id="deleteStockName"></strong>?</div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button></div></div></div>
</div>

<!-- Quick Add Customer Modal -->
<div class="modal fade" id="quickCustomerModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Add New Customer</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><form id="quickCustomerForm"><div class="modal-body"><div class="form-group"><label>Customer Name <span class="text-danger">*</span></label><input type="text" name="customer_name" id="qcName" class="form-control" required></div><div class="form-group"><label>Phone <span class="text-danger">*</span></label><input type="text" name="phone" id="qcPhone" class="form-control" required placeholder="+252..."></div><div class="form-group"><label>Email</label><input type="email" name="email" id="qcEmail" class="form-control"></div><div class="form-group"><label>Address</label><input type="text" name="address" id="qcAddress" class="form-control"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary-custom">Save Customer</button></div></form></div></div></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    let currentPage = 1;
    let deleteId = null;
    let moveId = null;
    let adjustId = null;
    let customerSearchTimer = null;

    function selectCustomerForStock(customer) {
        $('#modalCustomerId').val(customer.id || '');
        $('#modalCustomerSearch').val(customer.customer_name ? customer.customer_name + (customer.phone ? ' (' + customer.phone + ')' : '') : '');
        $('#modalCustomerInfo').text(customer.id ? 'Selected: ' + customer.customer_name : 'No customer selected.');
        $('#modalCustomerResults').hide().empty();
    }

    function renderCustomerResults(customers) {
        const box = $('#modalCustomerResults');
        box.empty();
        if (!customers || customers.length === 0) {
            box.html('<div class="customer-result-item">No customer found.<br><button type="button" class="btn btn-sm btn-primary mt-1" id="openQuickAddFromSearch">+ Add Customer</button></div>');
            box.show();
            return;
        }
        customers.forEach(c => {
            box.append('<div class="customer-result-item" data-id="' + c.id + '" data-name="' + escapeHtml(c.customer_name || '') + '" data-phone="' + escapeHtml(c.phone || '') + '"><strong>' + escapeHtml(c.customer_name || '-') + '</strong><br><small>' + escapeHtml(c.phone || '-') + '</small></div>');
        });
        box.show();
    }

    $('#modalCustomerSearch').on('input', function() {
        const q = $(this).val().trim();
        $('#modalCustomerId').val('');
        clearTimeout(customerSearchTimer);
        if (q.length < 2) { $('#modalCustomerResults').hide().empty(); return; }
        customerSearchTimer = setTimeout(function() {
            $.ajax({ url: window.location.href, type: 'POST', dataType: 'json', data: { ajax_action: 'search_customers', q: q },
                success: function(res) { renderCustomerResults(res.customers || []); } });
        }, 300);
    });

    $(document).on('click', '.customer-result-item', function() {
        selectCustomerForStock({ id: $(this).data('id'), customer_name: $(this).data('name'), phone: $(this).data('phone') });
    });
    $(document).on('click', '#openQuickAddFromSearch', function() { $('#quickCustomerModal').modal('show'); });
    $(document).on('click', function(e) { if (!$(e.target).closest('.customer-search-box').length) $('#modalCustomerResults').hide(); });

    function calculateCBM() {
        let l = parseFloat($('#modalLength').val()) || 0;
        let w = parseFloat($('#modalWidth').val()) || 0;
        let h = parseFloat($('#modalHeight').val()) || 0;
        if (l > 0 && w > 0 && h > 0) $('#modalVolume').val(((l * w * h) / 1000000).toFixed(6));
        updateTotalPreview();
    }

    function updateTotalPreview() {
        const volume = parseFloat($('#modalVolume').val()) || 0;
        const unitPrice = parseFloat($('#modalUnitPrice').val()) || 0;
        $('#totalValuePreview').text((volume * unitPrice).toFixed(2));
    }

    $('.dimension-input').on('input', calculateCBM);
    $('#modalVolume, #modalUnitPrice, #modalQuantity').on('input', updateTotalPreview);

    function loadStockItems() {
        $.ajax({
            url: window.location.href, type: 'POST',
            data: { ajax_action: 'get_stock_items', page: currentPage, search: $('#searchInput').val(), low_stock_only: $('#lowStockOnly').is(':checked') ? 1 : 0 },
            dataType: 'json',
            success: function(response) { $('#stock-table-container').html(response.table_html); $('#pagination-container').html(response.pagination_html); attachTableEvents(); },
            error: function() { $('#stock-table-container').html('<div class="text-center p-5"><i class="fas fa-exclamation-triangle"></i><p>Failed to load stock.</p></div>'); }
        });
    }

    function loadStats() {
        $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'get_stats' }, dataType: 'json',
            success: function(data) {
                $('#stat-total-items').text(data.stats.total_items || 0);
                $('#stat-total-quantity').text(Number(data.stats.total_quantity || 0).toLocaleString());
                $('#stat-total-volume').text(parseFloat(data.stats.total_volume || 0).toFixed(2));
                $('#stat-total-value').text('$' + parseFloat(data.stats.total_value || 0).toFixed(2));
                $('#stat-low-stock').text(data.stats.low_stock_items || 0);
            }
        });
    }

    function attachTableEvents() {
        $('.view-stock').off('click').on('click', function() {
            const id = $(this).data('id');
            $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'get_stock_item', id: id },
                success: function(item) {
                    $('#viewModalBody').html('<div class="row"><div class="col-5"><strong>Name:</strong></div><div class="col-7">' + escapeHtml(item.stock_name) + '</div><div class="col-5"><strong>Quantity:</strong></div><div class="col-7">' + Number(item.quantity).toLocaleString() + '</div><div class="col-5"><strong>Volume (CBM):</strong></div><div class="col-7">' + parseFloat(item.volume_cbm).toFixed(6) + '</div><div class="col-5"><strong>Location:</strong></div><div class="col-7">' + escapeHtml(item.location || '-') + '</div><div class="col-5"><strong>Unit Price:</strong></div><div class="col-7">$' + parseFloat(item.unit_price).toFixed(2) + '</div><div class="col-5"><strong>Customer:</strong></div><div class="col-7">' + escapeHtml(item.customer_name || '-') + '</div></div>');
                    $('#viewModal').modal('show');
                }
            });
        });

        $('.edit-stock').off('click').on('click', function() {
            const id = $(this).data('id');
            $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'get_stock_item', id: id },
                success: function(item) {
                    $('#stockModalLabel').text('Edit Stock Item');
                    $('#stock_id').val(item.id);
                    selectCustomerForStock({ id: item.customer_id || '', customer_name: item.customer_name || '', phone: item.phone || '' });
                    $('#modalStockName').val(item.stock_name);
                    $('#modalQuantity').val(item.quantity);
                    $('#modalLength').val(item.length_cm);
                    $('#modalWidth').val(item.width_cm);
                    $('#modalHeight').val(item.height_cm);
                    $('#modalUnitPrice').val(item.unit_price);
                    $('#modalLocation').val(item.location);
                    $('#modalMinStock').val(item.minimum_stock);
                    $('#modalMaxStock').val(item.maximum_stock);
                    $('#modalVolume').val(item.volume_cbm);
                    updateTotalPreview();
                    $('#stockModal').modal('show');
                }
            });
        });

        $('.move-stock').off('click').on('click', function() { moveId = $(this).data('id'); $('#moveStockName').text($(this).data('name')); $('#moveModal').modal('show'); });
        $('.adjust-stock').off('click').on('click', function() { adjustId = $(this).data('id'); $('#adjustStockName').text($(this).data('name')); $('#adjustCurrentQty').text($(this).data('qty')); $('#adjustModal').modal('show'); });
        $('.delete-stock').off('click').on('click', function() { deleteId = $(this).data('id'); $('#deleteStockName').text($(this).data('name')); $('#deleteModal').modal('show'); });
        $('.pagination-link').off('click').on('click', function(e) { e.preventDefault(); const page = $(this).data('page'); if (page) { currentPage = page; loadStockItems(); } });
    }

    $('#stock-table-container').on('click', '[data-page]', function() { currentPage = $(this).data('page'); loadStockItems(); });

    $('#stockForm').submit(function(e) {
        e.preventDefault();
        if (!$('#modalStockName').val()) { showAlert('error', 'Item name is required'); return; }
        $.ajax({
            url: window.location.href, type: 'POST',
            data: {
                ajax_action: 'save_stock_item', stock_id: $('#stock_id').val(), customer_id: $('#modalCustomerId').val(),
                stock_name: $('#modalStockName').val(), quantity: $('#modalQuantity').val(), volume_cbm: $('#modalVolume').val(),
                location: $('#modalLocation').val(), unit_price: $('#modalUnitPrice').val(), length_cm: $('#modalLength').val(),
                width_cm: $('#modalWidth').val(), height_cm: $('#modalHeight').val(), minimum_stock: $('#modalMinStock').val(), maximum_stock: $('#modalMaxStock').val()
            },
            success: function(res) {
                if (res.success) { $('#stockModal').modal('hide'); loadStockItems(); loadStats(); showAlert('success', res.message); $('#stockForm')[0].reset(); $('#stock_id').val(''); }
                else { showAlert('error', res.message); }
            },
            error: function() { showAlert('error', 'An error occurred'); }
        });
    });

    $('#moveForm').submit(function(e) {
        e.preventDefault();
        $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'move_stock', id: moveId, new_location: $('#moveLocation').val() },
            success: function(res) { if (res.success) { $('#moveModal').modal('hide'); loadStockItems(); showAlert('success', res.message); } else { showAlert('error', res.message); } }
        });
    });

    $('#adjustForm').submit(function(e) {
        e.preventDefault();
        const adjustment = parseInt($('#adjustmentQty').val());
        if (isNaN(adjustment) || adjustment === 0) { showAlert('error', 'Please enter the quantity change'); return; }
        if (!$('#adjustReason').val()) { showAlert('error', 'Please provide a reason'); return; }
        $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'adjust_stock', id: adjustId, adjustment: adjustment, reason: $('#adjustReason').val() },
            success: function(res) { if (res.success) { $('#adjustModal').modal('hide'); loadStockItems(); loadStats(); showAlert('success', res.message); } else { showAlert('error', res.message); } }
        });
    });

    $('#confirmDeleteBtn').click(function() {
        if (deleteId) {
            $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'delete_stock_item', id: deleteId },
                success: function(res) { if (res.success) { $('#deleteModal').modal('hide'); loadStockItems(); loadStats(); showAlert('success', res.message); } else { showAlert('error', res.message); } deleteId = null; }
            });
        }
    });

    $('#quickAddCustomerBtn').click(function() { $('#quickCustomerForm')[0].reset(); $('#quickCustomerModal').modal('show'); });

    $('#quickCustomerForm').submit(function(e) {
        e.preventDefault();
        $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'quick_add_customer', customer_name: $('#qcName').val(), phone: $('#qcPhone').val(), email: $('#qcEmail').val(), address: $('#qcAddress').val() },
            success: function(res) {
                if (res.success) { selectCustomerForStock({ id: res.id, customer_name: res.name, phone: res.phone }); $('#quickCustomerModal').modal('hide'); $('#quickCustomerForm')[0].reset(); showAlert('success', 'Customer added'); }
                else { showAlert('error', res.message); }
            }
        });
    });

    $('#addStockBtn, #addStockBtnEmpty').click(function() {
        $('#stockModalLabel').text('Add Stock Item');
        $('#stockForm')[0].reset();
        $('#stock_id').val('');
        selectCustomerForStock({});
        $('#modalQuantity').val(1);
        $('#modalVolume, #modalUnitPrice, #modalLength, #modalWidth, #modalHeight, #modalMinStock, #modalMaxStock').val(0);
        $('#totalValuePreview').text('0.00');
        $('#stockModal').modal('show');
    });
    $(document).on('click', '#addStockBtnEmpty', function() { $('#addStockBtn').click(); });

    $('#applyFilters').click(function() { currentPage = 1; loadStockItems(); });
    $('#resetFilters').click(function() { $('#searchInput').val(''); $('#lowStockOnly').prop('checked', false); currentPage = 1; loadStockItems(); });
    $('#searchInput').keypress(function(e) { if (e.which === 13) { currentPage = 1; loadStockItems(); } });

    function escapeHtml(text) {
        if (!text) return '';
        return String(text).replace(/[&<>"']/g, function(m) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m]; });
    }

    function showAlert(type, msg) {
        const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        $('#alert-placeholder').html('<div class="alert ' + alertClass + ' alert-dismissible fade show" style="position:fixed;top:20px;right:20px;z-index:9999;min-width:300px;border-radius:12px;"><i class="fas ' + icon + '"></i> ' + escapeHtml(msg) + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
        setTimeout(function() { $('.alert').fadeOut(500, function() { $(this).remove(); }); }, 5000);
    }

    loadStockItems();
    loadStats();
});
</script>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
