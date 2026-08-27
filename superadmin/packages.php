<?php
// stock_management.php
// Warehouse Stock Management - Responsive Modern UI
// Primary: Violet #2D1859, Secondary: Yellow #F5C410

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is superadmin or company_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['superadmin', 'company_admin'])) {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION['role'];
$session_tenant_id = $_SESSION['tenant_id'] ?? 0;

require_once __DIR__ . '/../config/db_connect.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Super Admin';

// Get all tenants for filter dropdown
$tenants = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM tenants ORDER BY name");
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tenants = [];
}

// Get all customers
try {
    $stmt = $pdo->query("SELECT id, customer_name FROM customers ORDER BY customer_name");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customers = [];
}

// Handle Export Actions (GET)
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action === 'export_packages') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=packages_'.date('Y-m-d').'.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['ID', 'Package Name', 'Origin', 'Quantity', 'CBM', 'Location', 'Unit Price', 'Tenant', 'Customer']);
        
        $where_conditions = [];
        $params = [];
        
        $search = $_GET['search'] ?? '';
        $tenant_filter = $_GET['tenant'] ?? '';
        $origin_filter = $_GET['origin'] ?? 'all';
        
        if ($role === 'company_admin') {
            $where_conditions[] = "ws.tenant_id = ?";
            $params[] = $session_tenant_id;
        } elseif (!empty($tenant_filter)) {
            $where_conditions[] = "ws.tenant_id = ?";
            $params[] = $tenant_filter;
        }
        
        if (!empty($search)) {
            $where_conditions[] = "(ws.stock_name LIKE ? OR ws.location LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($origin_filter !== 'all') {
            $where_conditions[] = "ws.origin = ?";
            $params[] = $origin_filter;
        }
        
        $where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);
        
        $sql = "SELECT ws.*, t.name as tenant_name, c.customer_name 
                FROM warehouse_stock ws 
                LEFT JOIN tenants t ON ws.tenant_id = t.id 
                LEFT JOIN customers c ON ws.customer_id = c.id 
                $where_clause 
                ORDER BY ws.stock_name ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['id'],
                $row['stock_name'],
                $row['origin'],
                $row['quantity'],
                $row['volume_cbm'],
                $row['location'],
                $row['unit_price'],
                $row['tenant_name'],
                $row['customer_name']
            ]);
        }
        fclose($output);
        exit;
    }
    
    if ($action === 'download_sample') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=packages_sample.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, ['Tenant Name', 'Customer Name', 'Package Name', 'Origin (china_yiwu/china_guangzhou/dubai)', 'Quantity', 'Volume (CBM/FT)', 'Unit Price', 'Location', 'Bin Location', 'Zone']);
        fputcsv($output, ['Example Logistics', 'John Doe', 'Solar Panels', 'china_yiwu', '100', '5.5', '120.00', 'A-1', 'B-12', 'North']);
        fclose($output);
        exit;
    }
}

// Handle AJAX requests (same backend logic)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];
    
    if ($action === 'get_stock_items') {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $search = $_POST['search'] ?? '';
        $tenant_filter = isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0;
        $origin_filter = $_POST['origin'] ?? 'all';
        $low_stock_only = isset($_POST['low_stock_only']) ? (int)$_POST['low_stock_only'] : 0;
        
        $where_conditions = [];
        $params = [];
        
        if (!empty($search)) {
            $where_conditions[] = "(ws.stock_name LIKE ? OR ws.location LIKE ? OR ws.bin_location LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($role === 'company_admin') {
            $where_conditions[] = "ws.tenant_id = ?";
            $params[] = $session_tenant_id;
        } elseif ($tenant_filter > 0) {
            $where_conditions[] = "ws.tenant_id = ?";
            $params[] = $tenant_filter;
        }
        
        if ($origin_filter !== 'all') {
            $where_conditions[] = "ws.origin = ?";
            $params[] = $origin_filter;
        }
        
        if ($low_stock_only == 1) {
            $where_conditions[] = "ws.quantity <= ws.minimum_stock";
        }
        
        $where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);
        
        // Get total count
        $count_sql = "SELECT COUNT(*) as total FROM warehouse_stock ws
                      LEFT JOIN tenants t ON ws.tenant_id = t.id
                      $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_items / $limit);
        
        // Get stock items
        $sql = "
            SELECT ws.*, 
                   t.name as tenant_name,
                   c.customer_name,
                   c.phone,
                   (SELECT invoice_number FROM invoices WHERE customer_id = c.id ORDER BY created_at DESC LIMIT 1) as latest_invoice_number,
                   (SELECT total_amount FROM invoices WHERE customer_id = c.id ORDER BY created_at DESC LIMIT 1) as latest_inv_total,
                   (SELECT paid_amount FROM invoices WHERE customer_id = c.id ORDER BY created_at DESC LIMIT 1) as latest_inv_paid,
                   u.full_name as updated_by_name
            FROM warehouse_stock ws
            LEFT JOIN tenants t ON ws.tenant_id = t.id
            LEFT JOIN customers c ON ws.customer_id = c.id
            LEFT JOIN users u ON ws.updated_by = u.id
            $where_clause
            ORDER BY 
                CASE WHEN ws.quantity <= ws.minimum_stock THEN 1 ELSE 2 END,
                ws.origin ASC,
                ws.stock_name ASC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate table HTML
        ob_start(); ?>
        <div class="table-responsive">
            <table class="stock-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Faahfaahinta Alaabta</th>
                        <th>Asalka</th>
                        <th>Tirada</th>
                        <th>Cabirka</th>
                        <th>Goobta</th>
                        <th>Xaaladda</th>
                        <th>Qiimaha</th>
                        <th>Shirkadda</th>
                        <th>Macaamilka</th>
                        <th>Falalka</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($stock_items) > 0): ?>
                        <?php foreach ($stock_items as $item): 
                            $originText = $item['origin'] === 'china_yiwu' ? 'Shiinaha (Yiwu)' : ($item['origin'] === 'china_guangzhou' ? 'Shiinaha (Guangzhou)' : 'Dubay');
                            $originIcon = strpos($item['origin'], 'china') !== false ? '🇨🇳' : '🇦🇪';
                            $isLowStock = $item['quantity'] <= $item['minimum_stock'];
                            $stockStatusClass = $isLowStock ? 'status-low' : 'status-good';
                            $stockStatusText = $isLowStock ? 'Digniin (Hoos)' : 'Fiican';
                            $quantityPercent = $item['maximum_stock'] > 0 ? min(100, ($item['quantity'] / $item['maximum_stock']) * 100) : 0;
                            $unit = ($item['origin'] === 'dubai') ? 'FT' : 'CBM';
                        ?>
                            <tr class="<?= $isLowStock ? 'low-stock-row' : '' ?>">
                                <td><span class="stock-id">#<?= $item['id'] ?></span></td>
                                <td>
                                    <div class="stock-name"><?= htmlspecialchars($item['stock_name'] ?? '-') ?></div>
                                    <div class="stock-sku">SKU: STK-<?= str_pad($item['id'], 5, '0', STR_PAD_LEFT) ?></div>
                                </td>
                                <td><span class="origin-badge origin-<?= $item['origin'] ?>"><?= $originIcon ?> <?= $originText ?></span></td>
                                <td>
                                    <div class="quantity-value <?= $isLowStock ? 'text-danger' : 'text-success' ?>">
                                        <?= number_format($item['quantity']) ?>
                                    </div>
                                    <div class="progress-bar-container">
                                        <div class="progress-bar" style="width: <?= $quantityPercent ?>%; background: <?= $isLowStock ? '#B42318' : '#0F7A3A' ?>;"></div>
                                    </div>
                                </td>
                                <td><?= number_format($item['volume_cbm'], 2) ?> <?= $unit ?></td>
                                <td><?= htmlspecialchars($item['location'] ?? '-') ?></td>
                                <td><span class="stock-badge <?= $stockStatusClass ?>"><?= $stockStatusText ?></span></td>
                                <td>
                                    <?php if ($item['unit_price'] > 0): ?>
                                        <div>$<?= number_format($item['unit_price'], 2) ?>/<?= $unit ?></div>
                                        <div class="total-value">$<?= number_format($item['volume_cbm'] * $item['unit_price'], 2) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($item['tenant_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($item['customer_name'] ?? '-') ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="action-btn btn-view view-stock" data-id="<?= $item['id'] ?>" title="Faahfaahin"><i class="fas fa-eye"></i></button>
                                        <button class="action-btn btn-edit edit-stock" data-id="<?= $item['id'] ?>" title="Wax Ka Badal"><i class="fas fa-edit"></i></button>
                                        <button class="action-btn btn-move move-stock" data-id="<?= $item['id'] ?>" data-name="<?= htmlspecialchars($item['stock_name']) ?>" title="U Rar"><i class="fas fa-exchange-alt"></i></button>
                                        <button class="action-btn btn-adjust adjust-stock" data-id="<?= $item['id'] ?>" data-name="<?= htmlspecialchars($item['stock_name']) ?>" data-qty="<?= $item['quantity'] ?>" title="Wax Ka Beddel Tirada"><i class="fas fa-sliders-h"></i></button>
                                        <button class="action-btn btn-whatsapp whatsapp-package" 
                                                data-phone="<?= htmlspecialchars($item['phone'] ?? '') ?>"
                                                data-name="<?= htmlspecialchars($item['customer_name'] ?? 'Macaamil') ?>"
                                                data-item="<?= htmlspecialchars($item['stock_name'] ?? 'Alaab') ?>"
                                                data-qty="<?= $item['quantity'] ?>"
                                                data-cbm="<?= number_format($item['volume_cbm'], 2) ?>"
                                                data-rate="<?= number_format($item['unit_price'], 2) ?>"
                                                data-invoice="<?= htmlspecialchars($item['latest_invoice_number'] ?? '') ?>"
                                                title="U dir WhatsApp"><i class="fab fa-whatsapp"></i></button>
                                        <button class="action-btn btn-delete delete-stock" data-id="<?= $item['id'] ?>" data-name="<?= htmlspecialchars($item['stock_name']) ?>" title="Tirtir"><i class="fas fa-trash"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" class="empty-state-cell">
                                <div class="empty-state">
                                    <i class="fas fa-warehouse"></i>
                                    <p>Ma jiraan wax alaab ah oo kaydka ku jira</p>
                                    <button class="btn-primary-custom" id="addStockBtnEmpty">
                                        <i class="fas fa-plus-circle"></i> Ku Dar Alaab
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        $table_html = ob_get_clean();
        
        // Generate pagination HTML
        ob_start();
        if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a data-page="<?= $page-1 ?>"><i class="fas fa-chevron-left"></i> Hore</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active"><?= $i ?></span>
                    <?php else: ?>
                        <a data-page="<?= $i ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a data-page="<?= $page+1 ?>">Danbe <i class="fas fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        <?php endif;
        $pagination_html = ob_get_clean();
        
        echo json_encode([
            'table_html' => $table_html,
            'pagination_html' => $pagination_html
        ]);
        exit;
    }
    
    elseif ($action === 'get_stock_item') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("
            SELECT ws.*, 
                   t.name as tenant_name,
                   c.customer_name,
                   u.full_name as updated_by_name
            FROM warehouse_stock ws
            LEFT JOIN tenants t ON ws.tenant_id = t.id
            LEFT JOIN customers c ON ws.customer_id = c.id
            LEFT JOIN users u ON ws.updated_by = u.id
            WHERE ws.id = ?
        ");
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($item);
        exit;
    }
    
    elseif ($action === 'save_stock_item') {
        $id = $_POST['stock_id'] ?? '';
        $tenant_id = !empty($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : null;
        $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $origin = $_POST['origin'] ?? 'local';
        $stock_name = trim($_POST['stock_name'] ?? '');
        $quantity = (int)($_POST['quantity'] ?? 0);
        $volume_cbm = (float)($_POST['volume_cbm'] ?? 0);
        $location = trim($_POST['location'] ?? '');
        $bin_location = trim($_POST['bin_location'] ?? '');
        $zone = trim($_POST['zone'] ?? '');
        $minimum_stock = (int)($_POST['minimum_stock'] ?? 0);
        $maximum_stock = (int)($_POST['maximum_stock'] ?? 0);
        $unit_price = (float)($_POST['unit_price'] ?? 0);
        
        try {
            if (empty($id)) {
                $sql = "INSERT INTO warehouse_stock (tenant_id, customer_id, origin, stock_name, quantity, volume_cbm, 
                        location, bin_location, zone, minimum_stock, maximum_stock, unit_price, updated_by, last_updated) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$tenant_id, $customer_id, $origin, $stock_name, $quantity, $volume_cbm, $location, 
                               $bin_location, $zone, $minimum_stock, $maximum_stock, $unit_price, $_SESSION['user_id']]);
                
                $new_id = $pdo->lastInsertId();
                $movementSql = "INSERT INTO stock_movements (tenant_id, warehouse_stock_id, quantity_change, 
                                previous_quantity, new_quantity, movement_type, notes, created_by, created_at) 
                                VALUES (?, ?, ?, ?, ?, 'in', 'Initial stock creation', ?, NOW())";
                $movementStmt = $pdo->prepare($movementSql);
                $movementStmt->execute([$tenant_id, $new_id, $quantity, 0, $quantity, $_SESSION['user_id']]);
                
                echo json_encode(['success' => true, 'message' => "Xirmada '$stock_name' waa la kaydiyay!"]);
            } else {
                $stmt = $pdo->prepare("SELECT quantity FROM warehouse_stock WHERE id = ?");
                $stmt->execute([$id]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);
                $old_quantity = $current['quantity'];
                
                $sql = "UPDATE warehouse_stock 
                        SET tenant_id = ?, customer_id = ?, origin = ?, stock_name = ?, quantity = ?, volume_cbm = ?,
                            location = ?, bin_location = ?, zone = ?, minimum_stock = ?, maximum_stock = ?, 
                            unit_price = ?, updated_by = ?, last_updated = NOW()
                        WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$tenant_id, $customer_id, $origin, $stock_name, $quantity, $volume_cbm, $location, 
                               $bin_location, $zone, $minimum_stock, $maximum_stock, $unit_price, $_SESSION['user_id'], $id]);
                
                if ($quantity != $old_quantity) {
                    $change = $quantity - $old_quantity;
                    $movement_type = $change > 0 ? 'in' : 'out';
                    $movementSql = "INSERT INTO stock_movements (tenant_id, warehouse_stock_id, quantity_change, 
                                    previous_quantity, new_quantity, movement_type, notes, created_by, created_at) 
                                    VALUES (?, ?, ?, ?, ?, ?, 'Stock update', ?, NOW())";
                    $movementStmt = $pdo->prepare($movementSql);
                    $movementStmt->execute([$tenant_id, $id, abs($change), $old_quantity, $quantity, $movement_type, $_SESSION['user_id']]);
                }
                
                echo json_encode(['success' => true, 'message' => "Xirmada '$stock_name' waa la cusboonaysiiyay!"]);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'delete_stock_item') {
        $id = $_POST['id'] ?? 0;
        
        try {
            $stmt = $pdo->prepare("SELECT stock_name FROM warehouse_stock WHERE id = ?");
            $stmt->execute([$id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$item) {
                echo json_encode(['success' => false, 'message' => 'Xirmada lama helin']);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM warehouse_stock WHERE id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['success' => true, 'message' => "Xirmada '{$item['stock_name']}' waa la tirtiray!"]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'move_stock') {
        $id = $_POST['id'] ?? 0;
        $new_location = trim($_POST['new_location'] ?? '');
        $new_bin = trim($_POST['new_bin'] ?? '');
        $new_zone = trim($_POST['new_zone'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if (empty($new_location) && empty($new_bin) && empty($new_zone)) {
            echo json_encode(['success' => false, 'message' => 'Fadlan geli meesha cusub']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT stock_name, location, bin_location, zone FROM warehouse_stock WHERE id = ?");
            $stmt->execute([$id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$item) {
                echo json_encode(['success' => false, 'message' => 'Xirmada lama helin']);
                exit;
            }
            
            $old_location = $item['location'];
            $old_bin = $item['bin_location'];
            $old_zone = $item['zone'];
            
            $sql = "UPDATE warehouse_stock 
                    SET location = COALESCE(NULLIF(?, ''), location),
                        bin_location = COALESCE(NULLIF(?, ''), bin_location),
                        zone = COALESCE(NULLIF(?, ''), zone),
                        updated_by = ?, last_updated = NOW()
                    WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$new_location, $new_bin, $new_zone, $_SESSION['user_id'], $id]);
            
            $movementSql = "INSERT INTO stock_movements (tenant_id, warehouse_stock_id, quantity_change, 
                            previous_quantity, new_quantity, movement_type, notes, created_by, created_at) 
                            VALUES ((SELECT tenant_id FROM warehouse_stock WHERE id = ?), ?, 0, 
                            (SELECT quantity FROM warehouse_stock WHERE id = ?), 
                            (SELECT quantity FROM warehouse_stock WHERE id = ?), 
                            'move', ?, ?, NOW())";
            $movementStmt = $pdo->prepare($movementSql);
            $movementStmt->execute([$id, $id, $id, $id, "Moved from '$old_location' ($old_bin) to '$new_location' ($new_bin). $notes", $_SESSION['user_id']]);
            
            echo json_encode(['success' => true, 'message' => "Xirmada '{$item['stock_name']}' waa loo raray meesha cusub!"]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'adjust_stock') {
        $id = $_POST['id'] ?? 0;
        $adjustment = (int)$_POST['adjustment'] ?? 0;
        $reason = trim($_POST['reason'] ?? '');
        
        if ($adjustment == 0) {
            echo json_encode(['success' => false, 'message' => 'Fadlan geli tirada wax ka beddelka']);
            exit;
        }
        
        if (empty($reason)) {
            echo json_encode(['success' => false, 'message' => 'Fadlan qor sababta wax ka beddelka']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT stock_name, quantity FROM warehouse_stock WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$item) {
                echo json_encode(['success' => false, 'message' => 'Xirmada lama helin']);
                exit;
            }
            
            $new_quantity = $item['quantity'] + $adjustment;
            
            if ($new_quantity < 0) {
                echo json_encode(['success' => false, 'message' => 'Tirada kama yaraan karto eber']);
                exit;
            }
            
            $sql = "UPDATE warehouse_stock SET quantity = ?, updated_by = ?, last_updated = NOW() WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$new_quantity, $_SESSION['user_id'], $id]);
            
            $movement_type = $adjustment > 0 ? 'in' : 'out';
            $movementSql = "INSERT INTO stock_movements (tenant_id, warehouse_stock_id, quantity_change, 
                            previous_quantity, new_quantity, movement_type, notes, created_by, created_at) 
                            VALUES ((SELECT tenant_id FROM warehouse_stock WHERE id = ?), ?, ?, ?, ?, 'adjust', ?, ?, NOW())";
            $movementStmt = $pdo->prepare($movementSql);
            $movementStmt->execute([$id, $id, abs($adjustment), $item['quantity'], $new_quantity, $reason, $_SESSION['user_id']]);
            
            $pdo->commit();
            
            $action_text = $adjustment > 0 ? "waxaa lagu daray $adjustment" : "waxaa laga jaray " . abs($adjustment);
            echo json_encode(['success' => true, 'message' => "Xirmada '{$item['stock_name']}' $action_text! Tirada cusub: $new_quantity"]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'import_packages') {
        if (!isset($_FILES['excel_file'])) {
            echo json_encode(['success' => false, 'message' => 'Fayl lama dooran!']);
            exit;
        }
        
        $file = $_FILES['excel_file']['tmp_name'];
        $handle = fopen($file, "r");
        fgetcsv($handle); // Skip header
        
        $imported = 0;
        $errors = [];
        $line = 1;
        
        try {
            $pdo->beginTransaction();
            
            // Pre-fetch tenants
            $tenants_map = [];
            $stmt = $pdo->query("SELECT id, name FROM tenants");
            while ($t = $stmt->fetch()) {
                $tenants_map[strtolower($t['name'])] = $t['id'];
            }
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $line++;
                // Columns: Tenant Name, Customer Name, Package Name, Origin, Quantity, Volume, Unit Price, Location, Bin, Zone
                $tenant_name = trim($data[0] ?? '');
                $customer_name = trim($data[1] ?? '');
                $stock_name = trim($data[2] ?? '');
                $origin = strtolower(trim($data[3] ?? 'local'));
                $quantity = (int)($data[4] ?? 0);
                $volume = (float)($data[5] ?? 0);
                $unit_price = (float)($data[6] ?? 0);
                $location = trim($data[7] ?? '');
                $bin = trim($data[8] ?? '');
                $zone = trim($data[9] ?? '');
                
                if (empty($tenant_name) || empty($stock_name)) continue;
                
                $t_id = $tenants_map[strtolower($tenant_name)] ?? null;
                if (!$t_id) {
                    $errors[] = "Line $line: Tenant '$tenant_name' not found.";
                    continue;
                }
                
                // Find/Create Customer
                $customer_id = null;
                if (!empty($customer_name)) {
                    $stmt = $pdo->prepare("SELECT id FROM customers WHERE tenant_id = ? AND LOWER(customer_name) = ?");
                    $stmt->execute([$t_id, strtolower($customer_name)]);
                    $customer_id = $stmt->fetchColumn();
                    if (!$customer_id) {
                        $stmt = $pdo->prepare("INSERT INTO customers (tenant_id, customer_name, is_active, created_at) VALUES (?, ?, 1, NOW())");
                        $stmt->execute([$t_id, $customer_name]);
                        $customer_id = $pdo->lastInsertId();
                    }
                }
                
                $stmt = $pdo->prepare("INSERT INTO warehouse_stock (tenant_id, customer_id, stock_name, origin, quantity, volume_cbm, unit_price, location, bin_location, zone, updated_by, last_updated) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())");
                $stmt->execute([$t_id, $customer_id, $stock_name, $origin, $quantity, $volume, $unit_price, $location, $bin, $zone, $_SESSION['user_id']]);
                
                $imported++;
            }
            
            $pdo->commit();
            $msg = "Import-ka waa lagu guulaystay! ($imported xirmo).";
            if (count($errors) > 0) $msg .= "<br>Digniin: " . count($errors) . " saf ayaa laga booday.";
            echo json_encode(['success' => true, 'message' => $msg]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        fclose($handle);
        exit;
    }
    
    elseif ($action === 'get_stats') {
        $tenant_filter = isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0;
        $where = $tenant_filter > 0 ? "WHERE tenant_id = $tenant_filter" : "";
        
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_items,
                SUM(quantity) as total_quantity,
                SUM(volume_cbm) as total_volume,
                SUM(quantity * unit_price) as total_value,
                COUNT(CASE WHEN quantity <= minimum_stock THEN 1 END) as low_stock_items,
                COUNT(CASE WHEN origin = 'china_yiwu' THEN 1 END) as yiwu_items,
                COUNT(CASE WHEN origin = 'china_guangzhou' THEN 1 END) as guangzhou_items,
                COUNT(CASE WHEN origin = 'dubai' THEN 1 END) as dubai_items
            FROM warehouse_stock
            $where
        ");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $origin_stats = $pdo->query("
            SELECT origin, SUM(quantity) as total_quantity, SUM(volume_cbm) as total_volume
            FROM warehouse_stock
            $where
            GROUP BY origin
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        $movements = $pdo->query("
            SELECT sm.*, ws.stock_name, u.full_name as created_by_name
            FROM stock_movements sm
            LEFT JOIN warehouse_stock ws ON sm.warehouse_stock_id = ws.id
            LEFT JOIN users u ON sm.created_by = u.id
            " . ($tenant_filter > 0 ? "WHERE ws.tenant_id = $tenant_filter" : "") . "
            ORDER BY sm.created_at DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'stats' => $stats,
            'origin_stats' => $origin_stats,
            'movements' => $movements
        ]);
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Maareynta Xirmooyinka - Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #F8FAFC;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #1E293B;
            line-height: 1.5;
        }
        
        :root {
            --violet: #2D1859;
            --violet-dark: #1F0F3D;
            --violet-light: #4B2C85;
            --violet-soft: #f3e8f7;
            --yellow: #F5C410;
            --yellow-dark: #D4A70C;
            --yellow-soft: #fef8e0;
            --gray-50: #F8FAFC;
            --gray-100: #F1F5F9;
            --gray-200: #E2E8F0;
            --gray-300: #CBD5E1;
            --gray-400: #94A3B8;
            --gray-500: #64748B;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1E293B;
            --gray-900: #0F172A;
            --success: #10B981;
            --success-light: #D1FAE5;
            --danger: #EF4444;
            --danger-light: #FEE2E2;
            --warning: #F59E0B;
            --warning-light: #FEF3C7;
            --info: #3B82F6;
            --info-light: #DBEAFE;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            --radius-sm: 0.375rem;
            --radius: 0.5rem;
            --radius-md: 0.75rem;
            --radius-lg: 1rem;
            --radius-xl: 1.5rem;
        }
        
        /* Container */
        .stock-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 1rem;
        }
        
        @media (min-width: 768px) {
            .stock-container {
                padding: 1.5rem;
            }
        }
        
        @media (min-width: 1200px) {
            .stock-container {
                padding: 2rem;
            }
        }
        
        /* Header */
        .page-header {
            background: linear-gradient(135deg, var(--violet) 0%, var(--violet-light) 100%);
            border-radius: var(--radius-lg);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: var(--shadow-md);
        }
        
        .page-header h1 {
            color: white;
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .page-header h1 i {
            font-size: 1.5rem;
        }
        
        @media (min-width: 768px) {
            .page-header h1 {
                font-size: 1.5rem;
            }
        }
        
        /* Buttons */
        .btn-primary-custom {
            background: var(--yellow);
            color: var(--violet);
            border: none;
            padding: 0.625rem 1.25rem;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            cursor: pointer;
            box-shadow: var(--shadow-sm);
        }
        
        .btn-primary-custom:hover {
            background: var(--yellow-dark);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-outline-custom {
            background: transparent;
            border: 2px solid var(--yellow);
            color: var(--yellow);
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .btn-outline-custom:hover {
            background: var(--yellow);
            color: var(--violet);
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        
        @media (min-width: 480px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (min-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(6, 1fr);
                gap: 1rem;
            }
        }
        
        .stat-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 0.875rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow-sm);
            transition: all 0.2s ease;
            border: 1px solid var(--gray-100);
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .stat-card .stat-info h4 {
            font-size: 0.65rem;
            color: var(--gray-500);
            margin: 0 0 0.25rem 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .stat-card .stat-info .stat-number {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--violet);
        }
        
        @media (min-width: 768px) {
            .stat-card .stat-info .stat-number {
                font-size: 1.5rem;
            }
        }
        
        .stat-card .stat-icon {
            width: 2rem;
            height: 2rem;
            background: var(--violet-soft);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .stat-card .stat-icon i {
            font-size: 1rem;
            color: var(--violet);
        }
        
        .stat-card-danger .stat-info .stat-number {
            color: var(--danger);
        }
        
        .stat-card-danger .stat-icon {
            background: var(--danger-light);
        }
        
        .stat-card-danger .stat-icon i {
            color: var(--danger);
        }
        
        /* Filters Card */
        .filters-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 1rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-100);
        }
        
        .filter-form {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        @media (min-width: 768px) {
            .filter-form {
                flex-direction: row;
                flex-wrap: wrap;
                align-items: flex-end;
                gap: 1rem;
            }
        }
        
        .filter-group {
            flex: 1;
            min-width: 140px;
        }
        
        .filter-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--gray-500);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-family: inherit;
            transition: all 0.2s ease;
            background: white;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--violet);
            box-shadow: 0 0 0 3px rgba(82, 0, 102, 0.1);
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1.25rem;
        }
        
        .checkbox-group label {
            margin: 0;
            cursor: pointer;
        }
        
        .btn-filter {
            background: var(--violet);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .btn-filter:hover {
            background: var(--violet-dark);
        }
        
        .btn-reset {
            background: var(--gray-100);
            color: var(--gray-600);
            border: none;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .btn-reset:hover {
            background: var(--gray-200);
        }
        
        /* Table Styles */
        .stock-table-container {
            background: white;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            overflow-x: auto;
            border: 1px solid var(--gray-100);
        }
        
        .stock-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
        }
        
        .stock-table th,
        .stock-table td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
        }
        
        .stock-table th {
            background: var(--gray-50);
            font-weight: 600;
            color: var(--gray-600);
            font-size: 0.75rem;
            white-space: nowrap;
        }
        
        .stock-table tr:hover {
            background: var(--gray-50);
        }
        
        .low-stock-row {
            background: var(--danger-light);
        }
        
        .low-stock-row:hover {
            background: #fdd8d8 !important;
        }
        
        /* Stock Components */
        .stock-id {
            font-weight: 600;
            color: var(--violet);
            font-size: 0.75rem;
        }
        
        .stock-name {
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 0.125rem;
        }
        
        .stock-sku {
            font-size: 0.6875rem;
            color: var(--gray-400);
        }
        
        .origin-badge {
            display: inline-block;
            padding: 0.25rem 0.625rem;
            border-radius: 2rem;
            font-size: 0.6875rem;
            font-weight: 600;
        }
        
        .origin-china_yiwu {
            background: #E3F2FD;
            color: #1565C0;
        }
        
        .origin-china_guangzhou {
            background: #E3F2FD;
            color: #1565C0;
        }
        
        .origin-dubai {
            background: #FFF3E0;
            color: #E65100;
        }
        
        .origin-local {
            background: #EEFBF3;
            color: #0F7A3A;
        }
        
        .quantity-value {
            font-weight: 700;
            font-size: 0.875rem;
        }
        
        .text-success {
            color: var(--success);
        }
        
        .text-danger {
            color: var(--danger);
        }
        
        .progress-bar-container {
            width: 60px;
            height: 3px;
            background: var(--gray-200);
            border-radius: 1.5px;
            overflow: hidden;
            margin-top: 4px;
        }
        
        .progress-bar {
            height: 100%;
            border-radius: 1.5px;
            transition: width 0.3s ease;
        }
        
        .stock-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 2rem;
            font-size: 0.6875rem;
            font-weight: 600;
        }
        
        .status-good {
            background: var(--success-light);
            color: var(--success);
        }
        
        .status-low {
            background: var(--danger-light);
            color: var(--danger);
        }
        
        .total-value {
            font-size: 0.6875rem;
            color: var(--gray-500);
            margin-top: 0.125rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }
        
        .action-btn {
            width: 28px;
            height: 28px;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-view {
            background: var(--info-light);
            color: var(--info);
        }
        
        .btn-view:hover {
            background: var(--info);
            color: white;
            transform: scale(1.05);
        }
        
        .btn-edit {
            background: var(--warning-light);
            color: var(--warning);
        }
        
        .btn-edit:hover {
            background: var(--warning);
            color: white;
            transform: scale(1.05);
        }
        
        .btn-move {
            background: #E3F2FD;
            color: #1565C0;
        }
        
        .btn-move:hover {
            background: #1565C0;
            color: white;
            transform: scale(1.05);
        }
        
        .btn-adjust {
            background: #F3E5F5;
            color: #7B1FA2;
        }
        
        .btn-adjust:hover {
            background: #7B1FA2;
            color: white;
            transform: scale(1.05);
        }
        
        .btn-whatsapp {
            background: #DCF8C5;
            color: #25D366;
        }
        
        .btn-whatsapp:hover {
            background: #25D366;
            color: white;
            transform: scale(1.05);
        }
        
        .btn-delete {
            background: var(--danger-light);
            color: var(--danger);
        }
        
        .btn-delete:hover {
            background: var(--danger);
            color: white;
            transform: scale(1.05);
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }
        
        .pagination a,
        .pagination span {
            padding: 0.5rem 0.875rem;
            border-radius: var(--radius-sm);
            text-decoration: none;
            color: var(--gray-600);
            background: white;
            border: 1px solid var(--gray-200);
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.8125rem;
            font-weight: 500;
        }
        
        .pagination .active {
            background: var(--violet);
            color: white;
            border-color: var(--violet);
        }
        
        .pagination a:hover {
            background: var(--violet-light);
            color: white;
            border-color: var(--violet-light);
            transform: translateY(-1px);
        }
        
        /* Movements List */
        .movements-list {
            background: white;
            border-radius: var(--radius-md);
            padding: 1rem;
            margin-top: 1.5rem;
            border: 1px solid var(--gray-100);
            box-shadow: var(--shadow-sm);
        }
        
        .movements-list h4 {
            font-size: 0.875rem;
            margin-bottom: 1rem;
            color: var(--violet);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .movement-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-100);
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .movement-item:last-child {
            border-bottom: none;
        }
        
        .movement-item strong {
            font-size: 0.8125rem;
        }
        
        .movement-item small {
            font-size: 0.6875rem;
            color: var(--gray-400);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray-400);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .empty-state p {
            font-size: 0.875rem;
        }
        
        .empty-state-cell {
            text-align: center !important;
        }
        
        /* Alert */
        .alert {
            position: fixed;
            top: 1rem;
            right: 1rem;
            left: 1rem;
            z-index: 9999;
            padding: 0.875rem 1rem;
            border-radius: var(--radius);
            animation: slideIn 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            box-shadow: var(--shadow-lg);
        }
        
        @media (min-width: 768px) {
            .alert {
                left: auto;
                min-width: 320px;
            }
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .alert-success {
            background: var(--success-light);
            color: var(--success);
            border-left: 4px solid var(--success);
        }
        
        .alert-error {
            background: var(--danger-light);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }
        
        .alert-info {
            background: var(--info-light);
            color: var(--info);
            border-left: 4px solid var(--info);
        }
        
        .alert .close {
            margin-left: auto;
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            opacity: 0.6;
        }
        
        /* Modal Styles */
        .modal-header {
            background: linear-gradient(135deg, var(--violet), var(--violet-light));
            color: white;
            border-radius: var(--radius-md) var(--radius-md) 0 0;
            padding: 1rem 1.25rem;
        }
        
        .modal-header .close {
            color: white;
            opacity: 1;
            text-shadow: none;
        }
        
        .modal-header .close:hover {
            color: var(--yellow);
        }
        
        .modal-title {
            font-size: 1rem;
            font-weight: 600;
        }
        
        .modal-body {
            padding: 1.25rem;
        }
        
        .modal-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--gray-200);
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray-600);
            margin-bottom: 0.25rem;
            display: block;
        }
        
        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-family: inherit;
            transition: all 0.2s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--violet);
            box-shadow: 0 0 0 3px rgba(82, 0, 102, 0.1);
        }
        
        .text-danger {
            color: var(--danger);
        }
        
        .text-muted {
            color: var(--gray-400);
        }
        
        .loading-spinner {
            text-align: center;
            padding: 3rem;
        }
        
        .loading-spinner i {
            font-size: 2rem;
            color: var(--violet);
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Responsive Table */
        @media (max-width: 768px) {
            .stock-table th:nth-child(6),
            .stock-table td:nth-child(6),
            .stock-table th:nth-child(7),
            .stock-table td:nth-child(7),
            .stock-table th:nth-child(9),
            .stock-table td:nth-child(9) {
                display: none;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 0.25rem;
            }
            
            .action-btn {
                width: 32px;
                height: 32px;
            }
        }
        
        @media (max-width: 480px) {
            .stock-table th:nth-child(3),
            .stock-table td:nth-child(3),
            .stock-table th:nth-child(5),
            .stock-table td:nth-child(5) {
                display: none;
            }
        }
    </style>
</head>
<body>

<div class="stock-container">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-warehouse"></i> Maareynta Xirmooyinka</h1>
        <div class="d-flex align-items-center">
            <button type="button" class="btn-primary-custom" id="addStockBtn">
                <i class="fas fa-plus-circle"></i> Alaab Cusub
            </button>
            <div class="dropdown ml-2">
                <button class="btn btn-light dropdown-toggle" type="button" data-toggle="dropdown" style="border-radius: var(--radius); padding: 0.625rem 1.25rem; font-weight: 600; border: none; background: rgba(255,255,255,0.2); color: white;">
                    <i class="fas fa-file-csv"></i> CSV
                </button>
                <div class="dropdown-menu dropdown-menu-right" style="border-radius: var(--radius-md); box-shadow: var(--shadow-lg);">
                    <a class="dropdown-item" href="?action=export_packages" id="exportPackagesBtn"><i class="fas fa-download mr-2"></i> Export Packages</a>
                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#importModal"><i class="fas fa-upload mr-2"></i> Import Packages</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="?action=download_sample"><i class="fas fa-file-download mr-2"></i> Download Sample</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-info">
                <h4>Wadarta Xirmada</h4>
                <div class="stat-number" id="stat-total-items">0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-boxes"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h4>Wadarta Tirada</h4>
                <div class="stat-number" id="stat-total-quantity">0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-cubes"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h4>Wadarta CBM</h4>
                <div class="stat-number" id="stat-total-volume">0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-cube"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h4>Wadarta Qiimaha</h4>
                <div class="stat-number" id="stat-total-value">$0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
        </div>
        <div class="stat-card stat-card-danger">
            <div class="stat-info">
                <h4>Digniin Ku Jirta</h4>
                <div class="stat-number" id="stat-low-stock">0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h4>Shiinaha (Guud)</h4>
                <div class="stat-number" id="stat-china">0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-globe-asia"></i></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Raadin</label>
                <input type="text" id="searchInput" placeholder="Faahfaahinta, goobta...">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-building"></i> Shirkadda</label>
                <select id="tenantFilter">
                    <option value="0">Dhammaan</option>
                    <?php foreach ($tenants as $t): ?>
                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-map-marker-alt"></i> Asalka</label>
                <select id="originFilter">
                    <option value="all">Dhammaan</option>
                    <option value="china_yiwu">China Yiwu 🇨🇳</option>
                    <option value="china_guangzhou">China Guangzhou 🇨🇳</option>
                    <option value="dubai">Dubay 🇦🇪</option>
                </select>
            </div>
            <div class="checkbox-group">
                <label><input type="checkbox" id="lowStockOnly"> Digniin (Hoos) oo keliya</label>
            </div>
            <div class="filter-group">
                <button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Shaandheey</button>
                <button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Nadiifi</button>
            </div>
        </div>
    </div>

    <div id="stock-table-container">
        <div class="loading-spinner">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading stock items...</p>
        </div>
    </div>
    <div id="pagination-container"></div>

    <!-- Recent Movements -->
    <div class="movements-list">
        <h4><i class="fas fa-history"></i> Dhaqdhaqaaqii Ugu Dambeeyay</h4>
        <div id="movementsList">
            <div class="loading-spinner" style="padding: 1.5rem;">
                <i class="fas fa-spinner fa-spin"></i>
            </div>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: var(--radius-md);">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-import"></i> Soo geli Xirmooyin (CSV)</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="importForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="info-box" style="background: var(--info-light); padding: 1rem; border-radius: var(--radius); margin-bottom: 1rem; border-left: 4px solid var(--info); font-size: 0.875rem;">
                        <i class="fas fa-info-circle"></i> Fadlan soo geli faylka CSV oo kaliya. 
                        <a href="?action=download_sample" class="alert-link" style="color: var(--info); text-decoration: underline;">Halkan ka soo deji sample-ka</a>.
                    </div>
                    <div class="form-group">
                        <label>Dooro Faylka (CSV)</label>
                        <input type="file" name="excel_file" class="form-control" accept=".csv" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" style="padding: 0.5rem 1rem; border-radius: var(--radius);">Jooji</button>
                    <button type="submit" class="btn" style="background: var(--violet); color: white; padding: 0.5rem 1.5rem; border-radius: var(--radius); border: none;">Soo geli (Import)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create/Edit Stock Modal -->
<div class="modal fade" id="stockModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="stockModalLabel"><i class="fas fa-box"></i> Alaab Cusub</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="stockForm">
                <div class="modal-body">
                    <input type="hidden" name="stock_id" id="stock_id">
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Shirkadda</label>
                                <select name="tenant_id" id="modalTenantId" class="form-control">
                                    <option value="">Dooro Shirkad...</option>
                                    <?php foreach ($tenants as $t): ?>
                                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Macaamilka</label>
                                <select name="customer_id" id="modalCustomerId" class="form-control">
                                    <option value="">Dooro Macaamil...</option>
                                    <?php foreach ($customers as $c): ?>
                                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['customer_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Asalka <span class="text-danger">*</span></label>
                                <select name="origin" id="modalOrigin" class="form-control" required>
                                    <option value="china_yiwu">China Yiwu 🇨🇳</option>
                                    <option value="china_guangzhou">China Guangzhou 🇨🇳</option>
                                    <option value="dubai">Dubay 🇦🇪</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Faahfaahinta Xirmada (Ikhtiyaari)</label>
                                <input type="text" name="stock_name" id="modalStockName" class="form-control" placeholder="Tusaale: Dhar, Kabo, Bagaash...">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tirada <span class="text-danger">*</span></label>
                                <input type="number" name="quantity" id="modalQuantity" class="form-control" value="0" required min="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label id="volLabel">CBM (Volume)</label>
                                <input type="number" step="0.01" name="volume_cbm" id="modalVolume" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label id="priceLabel">Qiimaha ($/CBM)</label>
                                <input type="number" step="0.01" name="unit_price" id="modalUnitPrice" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Goobta</label>
                                <input type="text" name="location" id="modalLocation" class="form-control" placeholder="Tusaale: Xirmooyinka A">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Bin Location</label>
                                <input type="text" name="bin_location" id="modalBinLocation" class="form-control" placeholder="Tusaale: A-01">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Zone</label>
                                <input type="text" name="zone" id="modalZone" class="form-control" placeholder="Tusaale: North">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Minimum Stock</label>
                                <input type="number" name="minimum_stock" id="modalMinStock" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Maximum Stock</label>
                                <input type="number" name="maximum_stock" id="modalMaxStock" class="form-control" value="0">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-custom">Kaydi Xirmada</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Move Stock Modal -->
<div class="modal fade" id="moveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exchange-alt"></i> U Rar Goob Cusub</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="moveForm">
                <div class="modal-body">
                    <input type="hidden" name="stock_id" id="moveStockId">
                    <p>Xirmada: <strong id="moveStockName"></strong></p>
                    <div class="form-group">
                        <label>Goobta Cusub</label>
                        <input type="text" name="new_location" id="moveLocation" class="form-control" placeholder="Tusaale: Xirmooyinka B">
                    </div>
                    <div class="form-group">
                        <label>Bin Cusub</label>
                        <input type="text" name="new_bin" id="moveBin" class="form-control" placeholder="Tusaale: B-02">
                    </div>
                    <div class="form-group">
                        <label>Zone Cusub</label>
                        <input type="text" name="new_zone" id="moveZone" class="form-control" placeholder="Tusaale: South">
                    </div>
                    <div class="form-group">
                        <label>Qoraal</label>
                        <textarea name="notes" id="moveNotes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">U Rar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Adjust Stock Modal -->
<div class="modal fade" id="adjustModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--warning);">
                <h5 class="modal-title"><i class="fas fa-sliders-h"></i> Wax Ka Beddel Tirada</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="adjustForm">
                <div class="modal-body">
                    <input type="hidden" name="stock_id" id="adjustStockId">
                    <p>Xirmada: <strong id="adjustStockName"></strong></p>
                    <p>Tirada hadda: <strong id="adjustCurrentQty">0</strong></p>
                    <div class="form-group">
                        <label>Wax Ka Beddelka (+ ama -)</label>
                        <input type="number" name="adjustment" id="adjustmentQty" class="form-control" placeholder="Tusaale: +10 ama -5" required>
                        <small class="text-muted">Ku dar: +10, Ka jar: -5</small>
                    </div>
                    <div class="form-group">
                        <label>Sababta <span class="text-danger">*</span></label>
                        <textarea name="reason" id="adjustReason" class="form-control" rows="2" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Wax Ka Beddel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Stock Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-box"></i> Faahfaahinta Xirmada</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--danger);">
                <h5 class="modal-title">Tirtir Xirmada</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                Ma hubtaa inaad tirtirto <strong id="deleteStockName"></strong>?
                <br><br>
                <span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Digniin: Tirtirista waa joogto!</span>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Tirtir</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    let currentPage = 1;
    let deleteId = null;
    let moveId = null;
    let adjustId = null;
    
    $('#modalOrigin').on('change', function() {
        const origin = $(this).val();
        if (origin === 'dubai') {
            $('#volLabel').html('FT (Volume)');
            $('#priceLabel').html('Qiimaha ($/FT)');
        } else {
            $('#volLabel').html('CBM (Volume)');
            $('#priceLabel').html('Qiimaha ($/CBM)');
        }
    });

    // Load stock items
    function loadStockItems() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'get_stock_items',
                page: currentPage,
                search: $('#searchInput').val(),
                tenant: $('#tenantFilter').val(),
                origin: $('#originFilter').val(),
                low_stock_only: $('#lowStockOnly').is(':checked') ? 1 : 0
            },
            dataType: 'json',
            success: function(response) {
                $('#stock-table-container').html(response.table_html);
                $('#pagination-container').html(response.pagination_html);
                attachTableEvents();
                
                // Update export link
                let search = $('#searchInput').val();
                let tenant = $('#tenantFilter').val();
                let origin = $('#originFilter').val();
                $('#exportPackagesBtn').attr('href', `?action=export_packages&search=${encodeURIComponent(search)}&tenant=${tenant}&origin=${origin}`);
            },
            error: function() {
                $('#stock-table-container').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading data</p></div>');
            }
        });
    }

    $('#importForm').submit(function(e) {
        e.preventDefault();
        let formData = new FormData(this);
        formData.append('ajax_action', 'import_packages');
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#importModal').modal('hide');
                    loadStockItems();
                    loadStats();
                    showAlert('success', res.message);
                    $('#importForm')[0].reset();
                } else {
                    showAlert('error', res.message);
                }
            },
            error: function() {
                showAlert('error', 'Khalad ayaa dhacay intii lagu guda jiray soo gelinta.');
            }
        });
    });

    // Load stats
    function loadStats() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { 
                ajax_action: 'get_stats',
                tenant: $('#tenantFilter').val()
            },
            dataType: 'json',
            success: function(data) {
                const stats = data.stats;
                const movements = data.movements;
                
                $('#stat-total-items').text(stats.total_items || 0);
                $('#stat-total-quantity').text(Number(stats.total_quantity || 0).toLocaleString());
                $('#stat-total-volume').text(parseFloat(stats.total_volume || 0).toFixed(2));
                $('#stat-total-value').text('$' + parseFloat(stats.total_value || 0).toFixed(2));
                $('#stat-low-stock').text(stats.low_stock_items || 0);
                $('#stat-china').text((Number(stats.yiwu_items) || 0) + (Number(stats.guangzhou_items) || 0));
                
                // Display movements
                let movementsHtml = '';
                if (movements && movements.length > 0) {
                    movements.forEach(m => {
                        const changeClass = m.quantity_change > 0 ? 'text-success' : (m.quantity_change < 0 ? 'text-danger' : 'text-info');
                        const changeIcon = m.quantity_change > 0 ? '↑' : (m.quantity_change < 0 ? '↓' : '↔');
                        const typeText = m.movement_type === 'in' ? 'Soo Galay' : (m.movement_type === 'out' ? 'Baxay' : (m.movement_type === 'move' ? 'La Raray' : 'Wax Laga Beddelay'));
                        movementsHtml += `
                            <div class="movement-item">
                                <div>
                                    <strong>${escapeHtml(m.stock_name)}</strong><br>
                                    <small>${typeText} | ${m.created_at}</small>
                                </div>
                                <div class="${changeClass}">
                                    ${changeIcon} ${Math.abs(m.quantity_change)} units<br>
                                    <small>${escapeHtml(m.created_by_name || 'System')}</small>
                                </div>
                            </div>
                        `;
                    });
                } else {
                    movementsHtml = '<div class="text-muted text-center">Ma jiraan dhaqdhaqaaqyo</div>';
                }
                $('#movementsList').html(movementsHtml);
            }
        });
    }

    function attachTableEvents() {
        $('.view-stock').off('click').on('click', function() {
            const id = $(this).data('id');
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_stock_item', id: id },
                dataType: 'json',
                success: function(item) {
                    const originMap = { 'china_yiwu': 'China Yiwu 🇨🇳', 'china_guangzhou': 'China Guangzhou 🇨🇳', 'dubai': 'Dubay 🇦🇪' };
                    const originText = originMap[item.origin] || item.origin;
                    const isLowStock = item.quantity <= item.minimum_stock;
                    const unit = item.origin === 'dubai' ? 'FT' : 'CBM';
                    $('#viewModalBody').html(`
                        <div class="row" style="gap: 0.75rem;">
                            <div class="col-12"><strong>Faahfaahinta:</strong> <strong>${escapeHtml(item.stock_name || '-')}</strong></div>
                            <div class="col-12"><strong>Asalka:</strong> ${originText}</div>
                            <div class="col-12"><strong>Tirada:</strong> <strong class="${isLowStock ? 'text-danger' : 'text-success'}">${Number(item.quantity).toLocaleString()}</strong></div>
                            <div class="col-12"><strong>${unit}:</strong> ${parseFloat(item.volume_cbm).toFixed(2)} ${unit}</div>
                            <div class="col-12"><strong>Goobta:</strong> ${escapeHtml(item.location || '-')}</div>
                            <div class="col-12"><strong>Bin Location:</strong> ${escapeHtml(item.bin_location || '-')}</div>
                            <div class="col-12"><strong>Qiimaha/${unit}:</strong> $${parseFloat(item.unit_price).toFixed(2)}</div>
                            <div class="col-12"><strong>Wadarta Qiimaha:</strong> <strong>$${(parseFloat(item.volume_cbm) * parseFloat(item.unit_price)).toFixed(2)}</strong></div>
                            <div class="col-12"><strong>Shirkadda:</strong> ${escapeHtml(item.tenant_name || '-')}</div>
                            <div class="col-12"><strong>Macaamilka:</strong> ${escapeHtml(item.customer_name || '-')}</div>
                            <div class="col-12"><strong>Cusboonaysiiyay:</strong> ${item.last_updated || '-'}</div>
                        </div>
                    `);
                    $('#viewModal').modal('show');
                }
            });
        });

        $('.edit-stock').off('click').on('click', function() {
            const id = $(this).data('id');
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_stock_item', id: id },
                dataType: 'json',
                success: function(item) {
                    $('#stockModalLabel').text('Wax Ka Beddel Xirmada');
                    $('#stock_id').val(item.id);
                    $('#modalTenantId').val(item.tenant_id);
                    $('#modalCustomerId').val(item.customer_id);
                    $('#modalOrigin').val(item.origin);
                    $('#modalStockName').val(item.stock_name);
                    $('#modalQuantity').val(item.quantity);
                    $('#modalVolume').val(item.volume_cbm);
                    $('#modalUnitPrice').val(item.unit_price);
                    $('#modalLocation').val(item.location);
                    $('#modalBinLocation').val(item.bin_location);
                    $('#modalZone').val(item.zone);
                    $('#modalMinStock').val(item.minimum_stock);
                    $('#modalMaxStock').val(item.maximum_stock);
                    $('#modalOrigin').trigger('change');
                    $('#stockModal').modal('show');
                }
            });
        });

        $('.move-stock').off('click').on('click', function() {
            moveId = $(this).data('id');
            $('#moveStockName').text($(this).data('name'));
            $('#moveLocation').val('');
            $('#moveBin').val('');
            $('#moveZone').val('');
            $('#moveNotes').val('');
            $('#moveModal').modal('show');
        });
        
        $('.adjust-stock').off('click').on('click', function() {
            adjustId = $(this).data('id');
            const stockName = $(this).data('name');
            const currentQty = $(this).data('qty');
            $('#adjustStockName').text(stockName);
            $('#adjustCurrentQty').text(currentQty);
            $('#adjustmentQty').val('');
            $('#adjustReason').val('');
            $('#adjustModal').modal('show');
        });

        $('.delete-stock').off('click').on('click', function() {
            deleteId = $(this).data('id');
            $('#deleteStockName').text($(this).data('name'));
            $('#deleteModal').modal('show');
        });

        $('.pagination a').off('click').on('click', function(e) {
            e.preventDefault();
            const page = $(this).data('page');
            if (page) { currentPage = page; loadStockItems(); }
        });
        
        // WhatsApp
        $('.whatsapp-package').off('click').on('click', function() {
            let phone = $(this).data('phone').toString().replace(/\D/g, '');
            const name = $(this).data('name');
            const item = $(this).data('item');
            const qty = $(this).data('qty');
            const cbm = $(this).data('cbm');
            const rate = $(this).data('rate');
            const inv = $(this).data('invoice');
            
            if (phone.length === 9 && (phone.startsWith('6') || phone.startsWith('7'))) {
                phone = '252' + phone;
            }
            
            if (!phone) {
                showAlert('error', 'Macaamilkan ma lahan lambar telefoon oo sax ah!');
                return;
            }
            
            let message = `Macaamiil ${name},\n\nAlaabtaadii *${item}* oo tiradeedu tahay *${qty}* ayaa hadda laga qabtay xaruntayada. Waxaan diyaar u nahay inaan kuu soo rarno.`;
            
            message += `\n\n*Xogta Cabirka & Qiimaha:*`;
            message += `\n- Cabirka Guud: *${cbm} CBM*`;
            message += `\n- Qiimaha (Rate): *$${rate}*`;
            
            if (inv) {
                const invLink = `${window.location.origin}/curdub_smart_cargo/curdub_smart_cargo/public_invoice.php?number=${inv}`;
                message += `\n\nBiilkaaga halkan kala soco:\n${invLink}`;
            }
            
            message += `\n\nWaad ku mahadsantahay doorashadaada.\n\n*Cargo Management System*`;
            
            const url = `https://api.whatsapp.com/send?phone=${phone}&text=${encodeURIComponent(message)}`;
            window.open(url, '_blank');
        });
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function showAlert(type, msg) {
        $('#alert-placeholder').html(`<div class="alert alert-${type}"><i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
        setTimeout(() => $('.alert').fadeOut(5000, function() { $(this).remove(); }), 5000);
    }

    // Stock Form Submit
    $('#stockForm').submit(function(e) {
        e.preventDefault();
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'save_stock_item',
                stock_id: $('#stock_id').val(),
                tenant_id: $('#modalTenantId').val(),
                customer_id: $('#modalCustomerId').val(),
                origin: $('#modalOrigin').val(),
                stock_name: $('#modalStockName').val(),
                quantity: $('#modalQuantity').val(),
                volume_cbm: $('#modalVolume').val(),
                location: $('#modalLocation').val(),
                bin_location: $('#modalBinLocation').val(),
                zone: $('#modalZone').val(),
                minimum_stock: $('#modalMinStock').val(),
                maximum_stock: $('#modalMaxStock').val(),
                unit_price: $('#modalUnitPrice').val()
            },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#stockModal').modal('hide');
                    loadStockItems();
                    loadStats();
                    showAlert('success', res.message);
                    $('#stockForm')[0].reset();
                    $('#stock_id').val('');
                } else {
                    showAlert('error', res.message);
                }
            },
            error: function() {
                showAlert('error', 'Khalad ayaa dhacay');
            }
        });
    });
    
    // Move Form Submit
    $('#moveForm').submit(function(e) {
        e.preventDefault();
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'move_stock',
                id: moveId,
                new_location: $('#moveLocation').val(),
                new_bin: $('#moveBin').val(),
                new_zone: $('#moveZone').val(),
                notes: $('#moveNotes').val()
            },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#moveModal').modal('hide');
                    loadStockItems();
                    loadStats();
                    showAlert('success', res.message);
                } else {
                    showAlert('error', res.message);
                }
            },
            error: function() {
                showAlert('error', 'Khalad ayaa dhacay');
            }
        });
    });
    
    // Adjust Form Submit
    $('#adjustForm').submit(function(e) {
        e.preventDefault();
        
        const adjustment = parseInt($('#adjustmentQty').val());
        if (isNaN(adjustment) || adjustment === 0) {
            showAlert('error', 'Fadlan geli wax ka beddelka');
            return;
        }
        
        if (!$('#adjustReason').val()) {
            showAlert('error', 'Fadlan qor sababta');
            return;
        }
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'adjust_stock',
                id: adjustId,
                adjustment: adjustment,
                reason: $('#adjustReason').val()
            },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#adjustModal').modal('hide');
                    loadStockItems();
                    loadStats();
                    showAlert('success', res.message);
                } else {
                    showAlert('error', res.message);
                }
            },
            error: function() {
                showAlert('error', 'Khalad ayaa dhacay');
            }
        });
    });

    $('#confirmDeleteBtn').click(function() {
        if (deleteId) {
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'delete_stock_item', id: deleteId },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        $('#deleteModal').modal('hide');
                        loadStockItems();
                        loadStats();
                        showAlert('success', res.message);
                    } else {
                        showAlert('error', res.message);
                    }
                    deleteId = null;
                }
            });
        }
    });

    $('#addStockBtn, #addStockBtnEmpty').click(function() {
        $('#stockModalLabel').text('Alaab Cusub');
        $('#stockForm')[0].reset();
        $('#stock_id').val('');
        $('#modalQuantity').val(0);
        $('#modalVolume').val(0);
        $('#modalUnitPrice').val(0);
        $('#modalMinStock').val(0);
        $('#modalMaxStock').val(0);
        $('#modalOrigin').trigger('change');
        $('#stockModal').modal('show');
    });

    $('#applyFilters').click(function() { currentPage = 1; loadStockItems(); loadStats(); });
    $('#resetFilters').click(function() { 
        $('#searchInput').val(''); 
        $('#tenantFilter').val('0'); 
        $('#originFilter').val('all');
        $('#lowStockOnly').prop('checked', false);
        currentPage = 1; 
        loadStockItems();
        loadStats();
    });
    $('#searchInput').keypress(function(e) { if (e.which === 13) { currentPage = 1; loadStockItems(); } });
    $('#lowStockOnly').change(function() { currentPage = 1; loadStockItems(); });

    // Initialize
    loadStockItems();
    loadStats();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>