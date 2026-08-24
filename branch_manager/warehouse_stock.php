<?php
// branch_manager/warehouse_stock.php
// Warehouse Stock Management for Branch Manager

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db_connect.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    die('Database connection failed: $pdo lama helin. Hubi config/db_connect.php');
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
ensureColumn($pdo, 'warehouse_stock', 'origin_branch_id', 'INT(11) DEFAULT NULL');
ensureColumn($pdo, 'warehouse_stock', 'location', 'VARCHAR(255) DEFAULT NULL');
ensureColumn($pdo, 'warehouse_stock', 'bin_location', 'VARCHAR(100) DEFAULT NULL');
ensureColumn($pdo, 'warehouse_stock', 'zone', 'VARCHAR(50) DEFAULT NULL');
ensureColumn($pdo, 'warehouse_stock', 'minimum_stock', 'INT(11) DEFAULT 0');
ensureColumn($pdo, 'warehouse_stock', 'maximum_stock', 'INT(11) DEFAULT 0');
ensureColumn($pdo, 'warehouse_stock', 'updated_by', 'INT(11) DEFAULT NULL');
ensureColumn($pdo, 'warehouse_stock', 'last_updated', 'DATETIME DEFAULT NULL');
ensureColumn($pdo, 'customers', 'branch_id', 'INT(11) DEFAULT NULL');

// Check if user is logged in and is branch_manager
if (!isset($_SESSION['user_id']) || ($_SESSION['role_type'] ?? $_SESSION['role'] ?? '') !== 'branch_manager') {
    header("Location: ../login.php");
    exit;
}

$session_tenant_id = $_SESSION['tenant_id'] ?? 0;
$user_id = $_SESSION['user_id'];

if (!$session_tenant_id) {
    header("Location: ../dashboard.php?error=no_tenant");
    exit;
}

// Get branch manager's assigned branch
$assigned_branch_id = $_SESSION['assigned_branch_id'] ?? null;

if (!$assigned_branch_id) {
    // Try to get from user_branch_assignments
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

// If still no branch, try to get from users table default_branch_id
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
    echo '<div class="alert alert-danger m-4">You are not assigned to any branch. Please contact administrator.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Get branch name for display
$branch_name = '';
try {
    $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$assigned_branch_id, $session_tenant_id]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) {
        $branch_name = $branch['branch_name'];
    }
} catch (PDOException $e) {
    $branch_name = 'My Branch';
}

// Ensure warehouse_stock table has required columns
try {
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS branch_id INT(11) DEFAULT NULL");
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS origin_branch_id INT(11) DEFAULT NULL");
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS location VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS bin_location VARCHAR(100) DEFAULT NULL");
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS zone VARCHAR(50) DEFAULT NULL");
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS minimum_stock INT(11) DEFAULT 0");
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS maximum_stock INT(11) DEFAULT 0");
} catch (PDOException $e) {}

function branchIconByType($branchType) {
    $map = [
        'main' => '🏢',
        'warehouse' => '🏬',
        'office' => '🏢',
        'store' => '🏪',
        'customs' => '🛃',
        'port' => '⚓'
    ];
    return $map[$branchType] ?? '📍';
}

// Get origin branches - ONLY the branch manager's own branch
$origin_branches = [];
try {
    $stmt = $pdo->prepare("SELECT id, branch_name, branch_type, branch_code FROM branches WHERE tenant_id = ? AND id = ? AND (status = 'active' OR is_active = 1)");
    $stmt->execute([$session_tenant_id, $assigned_branch_id]);
    $origin_branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $origin_branches = [];
}

function legacyOriginFromBranchName($branchName, $branchCode = '') {
    $text = strtolower((string)$branchName . ' ' . (string)$branchCode);
    if (strpos($text, 'yiwu') !== false) return 'china_yiwu';
    if (strpos($text, 'guangzhou') !== false) return 'china_guangzhou';
    if (strpos($text, 'dubai') !== false || strpos($text, 'dubaai') !== false) return 'dubai';
    return 'local';
}

// Helper functions
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

// Get tenant name
$tenant_name = '';
try {
    $stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
    $stmt->execute([$session_tenant_id]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_name = $tenant['name'] ?? 'Shirkadeyda';
} catch (PDOException $e) {
    $tenant_name = 'Shirkadeyda';
}

// ==============================================
// Handle AJAX requests
// ==============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];

    // Search customers for modal
    if ($action === 'search_customers') {
        $q = trim($_POST['q'] ?? '');
        $results = [];

        if ($q !== '') {
            try {
                $like = '%' . $q . '%';
                $stmt = $pdo->prepare("
                    SELECT id, customer_name, phone, COALESCE(debt_amount, 0) AS balance, COALESCE(loyalty_points, 0) AS loyalty_points
                    FROM customers
                    WHERE tenant_id = ? AND is_active = 1
                      AND (customer_name LIKE ? OR phone LIKE ?)
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

    // Quick add customer
    if ($action === 'quick_add_customer') {
        $name = trim($_POST['customer_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Magaca macaamiilka waa lagama maarmaan']);
            exit;
        }
        if (empty($phone)) {
            echo json_encode(['success' => false, 'message' => 'Telefoonka waa lagama maarmaan']);
            exit;
        }

        try {
            $chk = $pdo->prepare("SELECT id FROM customers WHERE tenant_id = ? AND phone = ?");
            $chk->execute([$session_tenant_id, $phone]);
            if ($chk->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Macaamiil leh telefoonkan horay ayuu u jiraa']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO customers (tenant_id, branch_id, customer_name, phone, email, address, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
            $stmt->execute([$session_tenant_id, $assigned_branch_id, $name, $phone, $email, $address]);
            $new_id = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'id' => $new_id, 'name' => $name, 'phone' => $phone]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }

    // Get stock items for this branch
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
            SELECT ws.*, 
                   c.customer_name,
                   c.phone
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
                        <th style="padding: 12px;">Magaca Alaabta</th>
                        <th style="padding: 12px;">Tirada</th>
                        <th style="padding: 12px;">Mugga (CBM)</th>
                        <th style="padding: 12px;">Bakhaar</th>
                        <th style="padding: 12px;">Xaalad</th>
                        <th style="padding: 12px;">Qiimaha</th>
                        <th style="padding: 12px;">Macaamiil</th>
                        <th style="padding: 12px;">Ficillo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($stock_items) > 0): ?>
                        <?php foreach ($stock_items as $item): 
                            $isLowStock = $item['quantity'] <= $item['minimum_stock'];
                            $stockStatusText = $isLowStock ? 'Tirada yaraatay' : 'Wanaagsan';
                            $statusColor = $isLowStock ? '#dc3545' : '#0F7A3A';
                        ?>
                            <tr style="<?= $isLowStock ? 'background: #fff3cd;' : '' ?>">
                                <td style="padding: 12px;"><?= $item['id'] ?></td>
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
                                        <button class="btn btn-primary adjust-stock" data-id="<?= $item['id'] ?>" data-name="<?= escapeHtml($item['stock_name'] ?? '') ?>" data-qty="<?= $item['quantity'] ?>" title="Adjust"><i class="fas fa-sliders-h"></i></button>
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
                                    <p>Alaab laguma hayo bakhaarka</p>
                                    <button class="btn btn-primary" id="addStockBtnEmpty"><i class="fas fa-plus-circle"></i> Ku dar Alaab</button>
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
                    <a data-page="<?= $page-1 ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-left"></i> Hore</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="btn btn-sm btn-primary"><?= $i ?></span>
                    <?php else: ?>
                        <a data-page="<?= $i ?>" class="btn btn-sm btn-outline-secondary"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a data-page="<?= $page+1 ?>" class="btn btn-sm btn-outline-secondary">Danbe <i class="fas fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        <?php endif;
        $pagination_html = ob_get_clean();
        
        echo json_encode(['table_html' => $table_html, 'pagination_html' => $pagination_html]);
        exit;
    }
    
    // Get single stock item
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
    
    // Save stock item (create or update)
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
            echo json_encode(['success' => false, 'message' => 'Magaca alaabta waa lagama maarmaan']);
            exit;
        }

        try {
            if ($volume_cbm <= 0 && $length_cm > 0 && $width_cm > 0 && $height_cm > 0) {
                $volume_cbm = ($length_cm * $width_cm * $height_cm) / 1000000;
            }

            if ($id === '') {
                // Check for duplicate
                $stmt = $pdo->prepare("
                    SELECT id FROM warehouse_stock 
                    WHERE tenant_id = ? AND branch_id = ? AND LOWER(stock_name) = LOWER(?) AND location = ?
                    LIMIT 1
                ");
                $stmt->execute([$session_tenant_id, $assigned_branch_id, $stock_name, $location]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    $stmt = $pdo->prepare("
                        UPDATE warehouse_stock 
                        SET quantity = quantity + ?, updated_by = ?, last_updated = NOW()
                        WHERE id = ? AND tenant_id = ? AND branch_id = ?
                    ");
                    $stmt->execute([$quantity, $user_id, $existing['id'], $session_tenant_id, $assigned_branch_id]);
                    echo json_encode(['success' => true, 'message' => "Alaabta '$stock_name' tiradii hore ayaa la kordhiyay!"]);
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
                echo json_encode(['success' => true, 'message' => "Alaabta '$stock_name' waa la kaydiyay!"]);
            } else {
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
                echo json_encode(['success' => true, 'message' => "Alaabta '$stock_name' waa la cusboonaysiiyay!"]);
            }
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Delete stock item
    if ($action === 'delete_stock_item') {
        $id = $_POST['id'] ?? 0;
        try {
            $stmt = $pdo->prepare("SELECT stock_name FROM warehouse_stock WHERE id = ? AND tenant_id = ? AND branch_id = ?");
            $stmt->execute([$id, $session_tenant_id, $assigned_branch_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                echo json_encode(['success' => false, 'message' => 'Alaabta lama helin']);
                exit;
            }
            $stmt = $pdo->prepare("DELETE FROM warehouse_stock WHERE id = ? AND tenant_id = ? AND branch_id = ?");
            $stmt->execute([$id, $session_tenant_id, $assigned_branch_id]);
            echo json_encode(['success' => true, 'message' => "Alaabta '{$item['stock_name']}' waa la tirtiray!"]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Move stock to new location
    if ($action === 'move_stock') {
        $id = $_POST['id'] ?? 0;
        $new_location = trim($_POST['new_location'] ?? '');
        
        if (empty($new_location)) {
            echo json_encode(['success' => false, 'message' => 'Fadlan geli bakhaarka cusub']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT stock_name FROM warehouse_stock WHERE id = ? AND tenant_id = ? AND branch_id = ?");
            $stmt->execute([$id, $session_tenant_id, $assigned_branch_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                echo json_encode(['success' => false, 'message' => 'Alaabta lama helin']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE warehouse_stock SET location = ?, updated_by = ?, last_updated = NOW() WHERE id = ? AND tenant_id = ? AND branch_id = ?");
            $stmt->execute([$new_location, $user_id, $id, $session_tenant_id, $assigned_branch_id]);
            
            echo json_encode(['success' => true, 'message' => "Alaabta '{$item['stock_name']}' waa loo dhaqaajiyay '$new_location'!"]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Adjust stock quantity
    if ($action === 'adjust_stock') {
        $id = $_POST['id'] ?? 0;
        $adjustment = (int)$_POST['adjustment'] ?? 0;
        $reason = trim($_POST['reason'] ?? '');
        
        if ($adjustment == 0) {
            echo json_encode(['success' => false, 'message' => 'Fadlan geli tirada beddelka']);
            exit;
        }
        if (empty($reason)) {
            echo json_encode(['success' => false, 'message' => 'Fadlan qor sababta']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT stock_name, quantity FROM warehouse_stock WHERE id = ? AND tenant_id = ? AND branch_id = ?");
            $stmt->execute([$id, $session_tenant_id, $assigned_branch_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                echo json_encode(['success' => false, 'message' => 'Alaabta lama helin']);
                exit;
            }
            
            $new_quantity = $item['quantity'] + $adjustment;
            if ($new_quantity < 0) {
                echo json_encode(['success' => false, 'message' => 'Tirada ma noqon karto mid ka yar eber']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE warehouse_stock SET quantity = ?, updated_by = ?, last_updated = NOW() WHERE id = ? AND tenant_id = ? AND branch_id = ?");
            $stmt->execute([$new_quantity, $user_id, $id, $session_tenant_id, $assigned_branch_id]);
            
            $action_text = $adjustment > 0 ? "ku dartay +$adjustment" : "kaga saaray " . abs($adjustment);
            echo json_encode(['success' => true, 'message' => "Alaabta '{$item['stock_name']}' waxaan $action_text! Tirada cusub: $new_quantity"]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Get stats for dashboard
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
    
    exit;
}

// Include header
require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maareynta Bakhaarka - <?= escapeHtml($branch_name) ?> | <?= escapeHtml($tenant_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root {
            --curdun-violet: #2D1859;
            --curdun-yellow: #F5C410;
            --curdun-violet-light: #4B2C85;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .page-header {
            background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light));
            border-radius: 16px;
            padding: 20px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .page-header h1 { color: white; font-size: 24px; margin: 0; }
        .page-header h1 i { margin-right: 10px; }
        .branch-badge {
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 13px;
            color: white;
        }
        .btn-primary-custom { background: var(--curdun-yellow); color: var(--curdun-violet); border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .btn-primary-custom:hover { background: #D4A70C; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: white; border-radius: 12px; padding: 15px 20px; display: flex; align-items: center; justify-content: space-between; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .stat-card .stat-info h4 { font-size: 11px; color: #6c757d; margin: 0; text-transform: uppercase; }
        .stat-card .stat-info .stat-number { font-size: 22px; font-weight: 700; color: var(--curdun-violet); }
        .stat-icon { width: 45px; height: 45px; background: rgba(82,0,102,0.08); border-radius: 12px; display: flex; align-items: center; justify-content: center; }
        .stat-icon i { font-size: 20px; color: var(--curdun-violet); }
        
        .filters-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 25px; border: 1px solid #e5e7eb; }
        .filter-form { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 150px; }
        .filter-group label { display: block; font-size: 12px; font-weight: 600; color: #4b5563; margin-bottom: 5px; }
        .filter-group input, .filter-group select { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 13px; }
        .btn-filter { background: var(--curdun-violet); color: white; border: none; padding: 8px 20px; border-radius: 10px; cursor: pointer; }
        .btn-reset { background: #f3f4f6; border: 1px solid #e5e7eb; padding: 8px 20px; border-radius: 10px; cursor: pointer; margin-left: 10px; }
        
        .stock-table-container { background: white; border-radius: 16px; border: 1px solid #e5e7eb; overflow: hidden; }
        .stock-table th { background: #f9fafb; font-weight: 600; font-size: 13px; padding: 12px; }
        .stock-table td { padding: 12px; font-size: 13px; border-bottom: 1px solid #e5e7eb; }
        .action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
        
        .modal-header { background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light)); color: white; border-bottom: none; }
        .modal-header .close { color: white; opacity: 0.8; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 5px; display: block; }
        .form-control { border-radius: 10px; border: 1px solid #d1d5db; padding: 8px 12px; font-size: 13px; }
        
        .customer-search-box { position: relative; }
        .customer-search-results {
            display: none;
            position: absolute;
            left: 0;
            right: 0;
            top: 100%;
            z-index: 1060;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            max-height: 260px;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
        }
        .customer-result-item { padding: 10px 12px; cursor: pointer; border-bottom: 1px solid #f0f0f0; }
        .customer-result-item:hover { background: #f7f5ff; }
        
        .alert { position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; border-radius: 12px; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 14px; border-radius: 8px; background: white; border: 1px solid #ddd; cursor: pointer; }
        .pagination .active { background: var(--curdun-violet); color: white; border-color: var(--curdun-violet); }
        
        @media (max-width: 768px) { .filter-form { flex-direction: column; } .stats-grid { grid-template-columns: repeat(2, 1fr); } }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-warehouse"></i> Maareynta Bakhaarka</h1>
        <div class="d-flex flex-wrap align-items-center" style="gap:8px;">
            <span class="branch-badge"><i class="fas fa-code-branch"></i> <?= escapeHtml($branch_name) ?></span>
            <button class="btn-primary-custom" id="addStockBtn"><i class="fas fa-plus-circle"></i> Ku Dar Alaab</button>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-info"><h4>Tirada Alaabta</h4><div class="stat-number" id="stat-total-items">0</div></div><div class="stat-icon"><i class="fas fa-boxes"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Tirada Guud</h4><div class="stat-number" id="stat-total-quantity">0</div></div><div class="stat-icon"><i class="fas fa-cubes"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Mugga Guud (CBM)</h4><div class="stat-number" id="stat-total-volume">0</div></div><div class="stat-icon"><i class="fas fa-cube"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Qiimaha Guud</h4><div class="stat-number" id="stat-total-value">$0</div></div><div class="stat-icon"><i class="fas fa-dollar-sign"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Tirada Daciifsan</h4><div class="stat-number" id="stat-low-stock">0</div></div><div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div></div>
    </div>

    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group"><label><i class="fas fa-search"></i> Raadin</label><input type="text" id="searchInput" placeholder="Magaca alabta, bakhaarka..."></div>
            <div class="filter-group"><label><input type="checkbox" id="lowStockOnly"> <i class="fas fa-exclamation-triangle"></i> Muuji kuwa daciifsan</label></div>
            <div><button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Shaandhayn</button><button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Nadiifi</button></div>
        </div>
    </div>

    <div id="stock-table-container"><div class="loading-spinner" style="text-align:center;padding:50px;"><i class="fas fa-spinner fa-spin"></i><p>Alaabta waa la soo rarayaa...</p></div></div>
    <div id="pagination-container"></div>
</div>

<!-- Stock Modal (Add/Edit) -->
<div class="modal fade" id="stockModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="stockModalLabel"><i class="fas fa-box"></i> Ku Dar Alaab</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
            <form id="stockForm">
                <div class="modal-body">
                    <input type="hidden" name="stock_id" id="stock_id">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group customer-search-box">
                                <label>Macaamiil <button type="button" id="quickAddCustomerBtn" class="btn btn-sm btn-primary">+ Ku dar</button></label>
                                <input type="hidden" name="customer_id" id="modalCustomerId">
                                <input type="text" id="modalCustomerSearch" class="form-control" autocomplete="off" placeholder="Raadi magaca ama telefoonka macaamiilka...">
                                <div id="modalCustomerResults" class="customer-search-results"></div>
                                <small id="modalCustomerInfo" class="text-muted d-block mt-1">Macaamiil lama dooran.</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Magaca Alaabta <span class="text-danger">*</span></label>
                                <input type="text" name="stock_name" id="modalStockName" class="form-control" placeholder="Tusaale: Dharka, Kabaha...">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Tirada</label>
                                <input type="number" name="quantity" id="modalQuantity" class="form-control" value="1" min="1">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Qiimaha Halbeeg ($)</label>
                                <input type="number" step="0.01" name="unit_price" id="modalUnitPrice" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Bakhaarka</label>
                                <input type="text" name="location" id="modalLocation" class="form-control" placeholder="Qaybta bakhaarka">
                            </div>
                        </div>
                    </div>
                    
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                        <label><i class="fas fa-ruler-combined"></i> Cabirrada Alaabta</label>
                        <div class="row">
                            <div class="col-md-4"><label style="font-size: 11px;">Dherer (cm)</label><input type="number" step="0.1" name="length_cm" id="modalLength" class="form-control" value="0"></div>
                            <div class="col-md-4"><label style="font-size: 11px;">Ballac (cm)</label><input type="number" step="0.1" name="width_cm" id="modalWidth" class="form-control" value="0"></div>
                            <div class="col-md-4"><label style="font-size: 11px;">Sare (cm)</label><input type="number" step="0.1" name="height_cm" id="modalHeight" class="form-control" value="0"></div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6"><label>Mugga (CBM)</label><input type="number" step="0.000001" name="volume_cbm" id="modalVolume" class="form-control" value="0" placeholder="Otomatik ama gacanta"></div>
                            <div class="col-md-3"><label>Ugu Yar (Digniin)</label><input type="number" name="minimum_stock" id="modalMinStock" class="form-control" value="0"></div>
                            <div class="col-md-3"><label>Ugu Badan</label><input type="number" name="maximum_stock" id="modalMaxStock" class="form-control" value="0"></div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info"><i class="fas fa-calculator"></i> <strong>Qiimaha Guud:</strong> $<span id="totalValuePreview">0.00</span> (Mugga × Qiimaha Halbeeg)</div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button><button type="submit" class="btn btn-primary-custom">Kaydi Alaabta</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Move Stock Modal -->
<div class="modal fade" id="moveModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-exchange-alt"></i> U Dhaqaaji Bakhaar Cusub</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <form id="moveForm"><div class="modal-body"><input type="hidden" name="stock_id" id="moveStockId"><p>Alaabta: <strong id="moveStockName"></strong></p><div class="form-group"><label>Bakhaarka Cusub</label><input type="text" name="new_location" id="moveLocation" class="form-control" required></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button><button type="submit" class="btn btn-info">Dhaqaaji</button></div></form></div></div></div>

<!-- Adjust Stock Modal -->
<div class="modal fade" id="adjustModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header" style="background: #f59e0b;"><h5 class="modal-title"><i class="fas fa-sliders-h"></i> Beddel Tirada</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <form id="adjustForm"><div class="modal-body"><input type="hidden" name="stock_id" id="adjustStockId"><p>Alaabta: <strong id="adjustStockName"></strong></p><p>Tirada Hadda: <strong id="adjustCurrentQty">0</strong></p><div class="form-group"><label>Beddel (+ ama -)</label><input type="number" name="adjustment" id="adjustmentQty" class="form-control" placeholder="Tusaale: +10 ama -5" required></div><div class="form-group"><label>Sababta</label><textarea name="reason" id="adjustReason" class="form-control" rows="2" required></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button><button type="submit" class="btn btn-warning">Beddel</button></div></form></div></div></div>

<!-- View Stock Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-eye"></i> Faahfaahinta Alaabta</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body" id="viewModalBody"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Xir</button></div></div></div></div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header" style="background: #dc2626;"><h5 class="modal-title">Tirtir Alaabta</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body">Ma hubtaa inaad tirtirto <strong id="deleteStockName"></strong>?</div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button><button type="button" class="btn btn-danger" id="confirmDeleteBtn">Tirtir</button></div></div></div></div>

<!-- Quick Add Customer Modal -->
<div class="modal fade" id="quickCustomerModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Ku Dar Macaamiil Cusub</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><form id="quickCustomerForm"><div class="modal-body"><div class="form-group"><label>Magaca Macaamiilka <span class="text-danger">*</span></label><input type="text" name="customer_name" id="qcName" class="form-control" required></div><div class="form-group"><label>Telefoonka <span class="text-danger">*</span></label><input type="text" name="phone" id="qcPhone" class="form-control" required placeholder="+252..."></div><div class="form-group"><label>Email</label><input type="email" name="email" id="qcEmail" class="form-control"></div><div class="form-group"><label>Cinwaanka</label><input type="text" name="address" id="qcAddress" class="form-control"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button><button type="submit" class="btn btn-primary-custom">Kaydi</button></div></form></div></div></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    let currentPage = 1;
    let deleteId = null;
    let moveId = null;
    let adjustId = null;
    let customerSearchTimer = null;

    // ==============================================
    // CUSTOMER SEARCH FUNCTIONALITY
    // ==============================================
    function selectCustomerForStock(customer) {
        $('#modalCustomerId').val(customer.id || '');
        $('#modalCustomerSearch').val(customer.customer_name ? customer.customer_name + (customer.phone ? ' (' + customer.phone + ')' : '') : '');
        $('#modalCustomerInfo').text(customer.id ? 'La doortay: ' + customer.customer_name : 'Macaamiil lama dooran.');
        $('#modalCustomerResults').hide().empty();
    }

    function renderCustomerResults(customers) {
        const box = $('#modalCustomerResults');
        box.empty();
        if (!customers || customers.length === 0) {
            box.html('<div class="customer-result-item">Macaamiil lama helin.<br><button type="button" class="btn btn-sm btn-primary mt-1" id="openQuickAddFromSearch">Ku dar Macaamiil</button></div>');
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
        if (q.length < 2) {
            $('#modalCustomerResults').hide().empty();
            return;
        }
        customerSearchTimer = setTimeout(function() {
            $.ajax({
                url: window.location.href,
                type: 'POST',
                dataType: 'json',
                data: { ajax_action: 'search_customers', q: q },
                success: function(res) {
                    renderCustomerResults(res.customers || []);
                }
            });
        }, 300);
    });

    $(document).on('click', '.customer-result-item', function() {
        selectCustomerForStock({
            id: $(this).data('id'),
            customer_name: $(this).data('name'),
            phone: $(this).data('phone')
        });
    });

    $(document).on('click', '#openQuickAddFromSearch', function() {
        $('#quickCustomerModal').modal('show');
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('.customer-search-box').length) {
            $('#modalCustomerResults').hide();
        }
    });

    // ==============================================
    // CBM CALCULATION
    // ==============================================
    function calculateCBM() {
        let l = parseFloat($('#modalLength').val()) || 0;
        let w = parseFloat($('#modalWidth').val()) || 0;
        let h = parseFloat($('#modalHeight').val()) || 0;
        if (l > 0 && w > 0 && h > 0) {
            let volume = (l * w * h) / 1000000;
            $('#modalVolume').val(volume.toFixed(6));
        }
        updateTotalPreview();
    }

    function updateTotalPreview() {
        const volume = parseFloat($('#modalVolume').val()) || 0;
        const unitPrice = parseFloat($('#modalUnitPrice').val()) || 0;
        const quantity = parseFloat($('#modalQuantity').val()) || 1;
        const total = (volume * unitPrice) * quantity;
        $('#totalValuePreview').text(total.toFixed(2));
    }

    $('.dimension-input').on('input', calculateCBM);
    $('#modalVolume, #modalUnitPrice, #modalQuantity').on('input', updateTotalPreview);

    // ==============================================
    // LOAD STOCK ITEMS
    // ==============================================
    function loadStockItems() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'get_stock_items',
                page: currentPage,
                search: $('#searchInput').val(),
                low_stock_only: $('#lowStockOnly').is(':checked') ? 1 : 0
            },
            dataType: 'json',
            success: function(response) {
                $('#stock-table-container').html(response.table_html);
                $('#pagination-container').html(response.pagination_html);
                attachTableEvents();
            },
            error: function() { 
                $('#stock-table-container').html('<div class="text-center p-5"><i class="fas fa-exclamation-triangle"></i><p>Khalad ayaa dhacay</p></div>');
            }
        });
    }

    function loadStats() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_stats' },
            dataType: 'json',
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
            $.ajax({
                url: window.location.href, type: 'POST', data: { ajax_action: 'get_stock_item', id: id },
                success: function(item) {
                    $('#viewModalBody').html('<div class="row"><div class="col-5"><strong>Magaca:</strong></div><div class="col-7">' + escapeHtml(item.stock_name) + '</div><div class="col-5"><strong>Tirada:</strong></div><div class="col-7">' + Number(item.quantity).toLocaleString() + '</div><div class="col-5"><strong>Mugga (CBM):</strong></div><div class="col-7">' + parseFloat(item.volume_cbm).toFixed(6) + '</div><div class="col-5"><strong>Bakhaarka:</strong></div><div class="col-7">' + escapeHtml(item.location || '-') + '</div><div class="col-5"><strong>Qiimaha:</strong></div><div class="col-7">$' + parseFloat(item.unit_price).toFixed(2) + '</div><div class="col-5"><strong>Macaamiil:</strong></div><div class="col-7">' + escapeHtml(item.customer_name || '-') + '</div></div>');
                    $('#viewModal').modal('show');
                }
            });
        });

        $('.edit-stock').off('click').on('click', function() {
            const id = $(this).data('id');
            $.ajax({
                url: window.location.href, type: 'POST', data: { ajax_action: 'get_stock_item', id: id },
                success: function(item) {
                    $('#stockModalLabel').text('Wax Ka Beddel Alaabta');
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

        $('.move-stock').off('click').on('click', function() {
            moveId = $(this).data('id');
            $('#moveStockName').text($(this).data('name'));
            $('#moveModal').modal('show');
        });

        $('.adjust-stock').off('click').on('click', function() {
            adjustId = $(this).data('id');
            $('#adjustStockName').text($(this).data('name'));
            $('#adjustCurrentQty').text($(this).data('qty'));
            $('#adjustModal').modal('show');
        });

        $('.delete-stock').off('click').on('click', function() {
            deleteId = $(this).data('id');
            $('#deleteStockName').text($(this).data('name'));
            $('#deleteModal').modal('show');
        });

        $('.pagination-link').off('click').on('click', function(e) {
            e.preventDefault();
            const page = $(this).data('page');
            if (page) { currentPage = page; loadStockItems(); }
        });
    }

    // ==============================================
    // FORM SUBMISSIONS
    // ==============================================
    
    $('#stockForm').submit(function(e) {
        e.preventDefault();
        if (!$('#modalStockName').val()) { showAlert('error', 'Magaca alabta waa lagama maarmaan'); return; }
        
        $.ajax({
            url: window.location.href, type: 'POST',
            data: {
                ajax_action: 'save_stock_item',
                stock_id: $('#stock_id').val(),
                customer_id: $('#modalCustomerId').val(),
                stock_name: $('#modalStockName').val(),
                quantity: $('#modalQuantity').val(),
                volume_cbm: $('#modalVolume').val(),
                location: $('#modalLocation').val(),
                unit_price: $('#modalUnitPrice').val(),
                length_cm: $('#modalLength').val(),
                width_cm: $('#modalWidth').val(),
                height_cm: $('#modalHeight').val(),
                minimum_stock: $('#modalMinStock').val(),
                maximum_stock: $('#modalMaxStock').val()
            },
            success: function(res) {
                if (res.success) {
                    $('#stockModal').modal('hide');
                    loadStockItems(); loadStats();
                    showAlert('success', res.message);
                    $('#stockForm')[0].reset();
                    $('#stock_id').val('');
                } else { showAlert('error', res.message); }
            },
            error: function() { showAlert('error', 'Khalad ayaa dhacay'); }
        });
    });

    $('#moveForm').submit(function(e) {
        e.preventDefault();
        $.ajax({
            url: window.location.href, type: 'POST',
            data: { ajax_action: 'move_stock', id: moveId, new_location: $('#moveLocation').val() },
            success: function(res) {
                if (res.success) { $('#moveModal').modal('hide'); loadStockItems(); showAlert('success', res.message); }
                else { showAlert('error', res.message); }
            }
        });
    });

    $('#adjustForm').submit(function(e) {
        e.preventDefault();
        const adjustment = parseInt($('#adjustmentQty').val());
        if (isNaN(adjustment) || adjustment === 0) { showAlert('error', 'Fadlan geli tirada beddelka'); return; }
        if (!$('#adjustReason').val()) { showAlert('error', 'Fadlan qor sababta'); return; }
        
        $.ajax({
            url: window.location.href, type: 'POST',
            data: { ajax_action: 'adjust_stock', id: adjustId, adjustment: adjustment, reason: $('#adjustReason').val() },
            success: function(res) {
                if (res.success) { $('#adjustModal').modal('hide'); loadStockItems(); loadStats(); showAlert('success', res.message); }
                else { showAlert('error', res.message); }
            }
        });
    });

    $('#confirmDeleteBtn').click(function() {
        if (deleteId) {
            $.ajax({
                url: window.location.href, type: 'POST', data: { ajax_action: 'delete_stock_item', id: deleteId },
                success: function(res) {
                    if (res.success) { $('#deleteModal').modal('hide'); loadStockItems(); loadStats(); showAlert('success', res.message); }
                    else { showAlert('error', res.message); }
                    deleteId = null;
                }
            });
        }
    });

    $('#quickAddCustomerBtn').click(function() { 
        $('#quickCustomerForm')[0].reset();
        $('#quickCustomerModal').modal('show'); 
    });
    
    $('#quickCustomerForm').submit(function(e) {
        e.preventDefault();
        $.ajax({
            url: window.location.href, type: 'POST',
            data: { ajax_action: 'quick_add_customer', customer_name: $('#qcName').val(), phone: $('#qcPhone').val(), email: $('#qcEmail').val(), address: $('#qcAddress').val() },
            success: function(res) {
                if (res.success) {
                    selectCustomerForStock({ id: res.id, customer_name: res.name, phone: res.phone });
                    $('#quickCustomerModal').modal('hide');
                    $('#quickCustomerForm')[0].reset();
                    showAlert('success', 'Macaamiil waa la daray!');
                } else { showAlert('error', res.message); }
            }
        });
    });

    $('#addStockBtn, #addStockBtnEmpty').click(function() {
        $('#stockModalLabel').text('Ku Dar Alaabta');
        $('#stockForm')[0].reset();
        $('#stock_id').val('');
        selectCustomerForStock({});
        $('#modalQuantity').val(1);
        $('#modalVolume').val(0);
        $('#modalUnitPrice').val(0);
        $('#modalLength').val(0);
        $('#modalWidth').val(0);
        $('#modalHeight').val(0);
        $('#modalMinStock').val(0);
        $('#modalMaxStock').val(0);
        $('#totalValuePreview').text('0.00');
        $('#stockModal').modal('show');
    });

    $('#applyFilters').click(function() { currentPage = 1; loadStockItems(); });
    $('#resetFilters').click(function() { $('#searchInput').val(''); $('#lowStockOnly').prop('checked', false); currentPage = 1; loadStockItems(); });
    $('#searchInput').keypress(function(e) { if (e.which === 13) { currentPage = 1; loadStockItems(); } });

    function escapeHtml(text) {
        if (!text) return '';
        return String(text).replace(/[&<>"']/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            if (m === '"') return '&quot;';
            return '&#39;';
        });
    }
    
    function showAlert(type, msg) {
        const alertClass = type === 'success' ? 'alert-success' : (type === 'error' ? 'alert-danger' : 'alert-info');
        const icon = type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle');
        $('#alert-placeholder').html('<div class="alert ' + alertClass + ' alert-dismissible fade show"><i class="fas ' + icon + '"></i> ' + escapeHtml(msg) + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
        setTimeout(function() { 
            $('.alert').fadeOut(500, function() { $(this).remove(); });
        }, 5000);
    }

    // Initial load
    loadStockItems();
    loadStats();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>