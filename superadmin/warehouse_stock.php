<?php
// superadmin/warehouse_stock.php
// Warehouse Stock Management forfaras cargo - Super Admin

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

// Get all tenants for filter dropdown (Super Admin only)
$tenants = [];
if ($role === 'superadmin') {
    try {
        $stmt = $pdo->query("SELECT id, name FROM tenants ORDER BY name");
        $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $tenants = [];
    }
}

// Get all customers (filtered by tenant if company_admin)
try {
    $cust_where = ($role === 'company_admin') ? "WHERE tenant_id = $session_tenant_id" : "";
    $stmt = $pdo->query("SELECT id, customer_name FROM customers $cust_where ORDER BY customer_name");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customers = [];
}

// Handle Export Actions (GET)
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action === 'export_stock') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=warehouse_stock_'.date('Y-m-d').'.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['ID', 'Stock Name', 'Origin', 'Quantity', 'CBM', 'Location', 'Unit Price', 'Tenant', 'Customer']);
        
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
        header('Content-Disposition: attachment; filename=warehouse_stock_sample.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, ['Tenant Name', 'Customer Name', 'Stock Name', 'Origin (china_yiwu/china_guangzhou/dubai)', 'Quantity', 'Volume (CBM/FT)', 'Unit Price', 'Location', 'Bin Location', 'Zone']);
        fputcsv($output, ['Example Logistics', 'John Doe', 'Solar Panels', 'china_yiwu', '100', '5.5', '120.00', 'A-1', 'B-12', 'North']);
        fclose($output);
        exit;
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];

    if ($action === 'quick_add_customer') {
        $tenant_id = ($role === 'superadmin') ? (int)($_POST['tenant_id'] ?? 0) : $session_tenant_id;
        $name         = trim($_POST['customer_name'] ?? '');
        $phone        = trim($_POST['phone'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $address      = trim($_POST['address'] ?? '');

        if (!$tenant_id) {
            echo json_encode(['success' => false, 'message' => 'Fadlan dooro shirkad']);
            exit;
        }
        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Magaca macaamilka waa lagama maarmaan']);
            exit;
        }
        if (empty($phone)) {
            echo json_encode(['success' => false, 'message' => 'Telefoonka waa lagama maarmaan']);
            exit;
        }

        try {
            // Check for duplicate phone in this tenant
            $chk = $pdo->prepare("SELECT id FROM customers WHERE tenant_id = ? AND phone = ?");
            $chk->execute([$tenant_id, $phone]);
            if ($chk->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Macaamil leh telefoonkan horay ayuu u jiraa']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO customers (tenant_id, customer_name, phone, email, address, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
            $stmt->execute([$tenant_id, $name, $phone, $email, $address]);
            $new_id = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'id' => $new_id, 'name' => $name, 'phone' => $phone]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'quick_add_trip') {
        $tenant_id        = (int)($_POST['tenant_id'] ?? 0);
        $container_number = trim($_POST['container_number'] ?? '');
        $container_type   = trim($_POST['container_type'] ?? '20ft');
        $trip_number      = trim($_POST['trip_number'] ?? '');
        $origin           = trim($_POST['origin'] ?? 'local');

        if (!$tenant_id || empty($container_number)) {
            echo json_encode(['success' => false, 'message' => 'Fadlan geli xogta loo baahan yahay']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // 1. Create or Get Container
            $stmt = $pdo->prepare("INSERT INTO containers (tenant_id, container_number, container_type, origin, status, created_at) VALUES (?, ?, ?, ?, 'received', NOW())");
            $stmt->execute([$tenant_id, $container_number, $container_type, $origin]);
            $container_id = $pdo->lastInsertId();

            // 2. Create Trip
            if (empty($trip_number)) {
                $trip_number = 'TRP-' . time();
            }
            $stmt = $pdo->prepare("INSERT INTO trucking_trips (tenant_id, container_id, trip_number, status, created_at) VALUES (?, ?, ?, 'received', NOW())");
            $stmt->execute([$tenant_id, $container_id, $trip_number]);
            $trip_id = $pdo->lastInsertId();

            $pdo->commit();
            echo json_encode(['success' => true, 'id' => $trip_id, 'name' => "$trip_number ($container_number)"]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_trip_container_info') {
        $trip_id = (int)($_POST['trip_id'] ?? 0);
        
        try {
            // Get container capacity and total CBM used
            $stmt = $pdo->prepare("
                SELECT c.size_cbm as capacity, 
                       (SELECT SUM(cbm_used) FROM cargo_manifest_items WHERE container_id = c.id) as used_cbm
                FROM trucking_trips t
                JOIN containers c ON t.container_id = c.id
                WHERE t.id = ?
            ");
            $stmt->execute([$trip_id]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get items list
            $stmt = $pdo->prepare("
                SELECT stock_name, quantity, cbm_used 
                FROM cargo_manifest_items 
                WHERE container_id = (SELECT container_id FROM trucking_trips WHERE id = ?)
                ORDER BY added_at DESC
            ");
            $stmt->execute([$trip_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'stats' => $stats,
                'items' => $items
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_stock_items') {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $search = $_POST['search'] ?? '';
        $tenant_filter = ($role === 'superadmin') ? (isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0) : $session_tenant_id;
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
        
        if ($tenant_filter > 0) {
            $where_conditions[] = "ws.tenant_id = ?";
            $params[] = $tenant_filter;
        } elseif ($role === 'company_admin') {
            $where_conditions[] = "ws.tenant_id = ?";
            $params[] = $session_tenant_id;
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
        <div style="overflow-x: auto; width: 100%;">
            <table class="stock-table" style="min-width: 1300px; width: 100%;">
                <thead>
                    <tr>
                        <th style="min-width: 60px;">ID</th>
                        <th style="min-width: 180px;">Faahfaahinta Alaabta</th>
                        <th style="min-width: 100px;">Asalka</th>
                        <th style="min-width: 100px;">Tirada</th>
                        <th style="min-width: 100px;">Cabirka (CBM/FT)</th>
                        <th style="min-width: 120px;">Goobta</th>
                        <th style="min-width: 100px;">Xaaladda</th>
                        <th style="min-width: 120px;">Qiimaha/Unit</th>
                        <th style="min-width: 130px;">Shirkadda</th>
                        <th style="min-width: 130px;">Macaamilka</th>
                        <th style="min-width: 120px">Falalka</th>
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
                        ?>
                            <tr class="<?= $isLowStock ? 'low-stock-row' : '' ?>">
                                <td><?= $item['id'] ?></td>
                                <td>
                                    <div>
                                        <strong><?= htmlspecialchars($item['stock_name'] ?? '-') ?></strong>
                                        <div style="font-size: 11px; color: #6c757d;">
                                            <i class="fas fa-box"></i> SKU: STK-<?= str_pad($item['id'], 5, '0', STR_PAD_LEFT) ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="origin-badge origin-<?= $item['origin'] ?>">
                                        <?= $originIcon ?> <?= $originText ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="quantity-cell">
                                        <strong class="<?= $isLowStock ? 'text-danger' : 'text-success' ?>">
                                            <?= number_format($item['quantity']) ?>
                                        </strong>
                                        <div class="progress-bar-container" style="margin-top: 5px;">
                                            <div class="progress-bar" style="width: <?= $quantityPercent ?>%; background: <?= $isLowStock ? '#B42318' : '#0F7A3A' ?>;"></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                    $unit = ($item['origin'] === 'dubai') ? 'FT' : 'CBM';
                                    echo number_format($item['volume_cbm'], 2) . ' ' . $unit;
                                    ?>
                                </td>
                                <td><?= htmlspecialchars($item['location'] ?? '-') ?></td>
                                <td>
                                    <span class="stock-badge <?= $stockStatusClass ?>">
                                        <?= $stockStatusText ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($item['unit_price'] > 0): ?>
                                        $<?= number_format($item['unit_price'], 2) ?>/<?= $unit ?>
                                        <div style="font-size: 10px; color: #6c757d;">
                                            Total: $<?= number_format($item['volume_cbm'] * $item['unit_price'], 2) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($item['tenant_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($item['customer_name'] ?? '-') ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="action-btn btn-view view-stock" data-id="<?= $item['id'] ?>" title="Fiiri">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="action-btn btn-edit edit-stock" data-id="<?= $item['id'] ?>" title="Wax ka beddel">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="action-btn btn-load load-stock" data-id="<?= $item['id'] ?>" data-name="<?= htmlspecialchars($item['stock_name']) ?>" data-tenant="<?= $item['tenant_id'] ?>" data-qty="<?= $item['quantity'] ?>" data-origin="<?= $item['origin'] ?>" title="Rar Kontayner">
                                            <i class="fas fa-truck-loading"></i>
                                        </button>
                                        <button class="action-btn btn-payment bill-stock" data-id="<?= $item['id'] ?>" data-name="<?= htmlspecialchars($item['stock_name']) ?>" title="Biil ka sameey">
                                            <i class="fas fa-file-invoice-dollar"></i>
                                        </button>
                                        <button class="action-btn btn-move move-stock" data-id="<?= $item['id'] ?>" data-name="<?= htmlspecialchars($item['stock_name']) ?>" title="Meel kale u rar">
                                            <i class="fas fa-exchange-alt"></i>
                                        </button>
                                        <button class="action-btn btn-track whatsapp-package" 
                                                data-phone="<?= htmlspecialchars($item['phone'] ?? '') ?>"
                                                data-name="<?= htmlspecialchars($item['customer_name'] ?? 'Macaamil') ?>"
                                                data-item="<?= htmlspecialchars($item['stock_name'] ?? 'Alaab') ?>"
                                                data-qty="<?= $item['quantity'] ?>"
                                                data-cbm="<?= number_format($item['volume_cbm'], 2) ?>"
                                                data-rate="<?= number_format($item['unit_price'], 2) ?>"
                                                data-invoice="<?= htmlspecialchars($item['latest_invoice_number'] ?? '') ?>"
                                                title="U dir WhatsApp"><i class="fab fa-whatsapp"></i></button>
                                        <button class="action-btn btn-delete delete-stock" data-id="<?= $item['id'] ?>" data-name="<?= htmlspecialchars($item['stock_name']) ?>" title="Tirtir">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="12" style="text-align: center; padding: 50px;">
                                <div class="empty-state">
                                    <i class="fas fa-warehouse"></i>
                                    <p>Ma jiraan wax alaab ah oo bakhaarka ku jira</p>
                                    <button class="btn-primary-custom" id="addStockBtnEmpty" style="margin-top: 10px;">
                                        <i class="fas fa-plus-circle"></i> Ku Dar Alaaab
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
        
        $length_cm = (float)($_POST['length_cm'] ?? 0);
        $width_cm = (float)($_POST['width_cm'] ?? 0);
        $height_cm = (float)($_POST['height_cm'] ?? 0);
        
        if (!$tenant_id) {
            echo json_encode(['success' => false, 'message' => 'Fadlan dooro Shirkadda (Company)']);
            exit;
        }
        
        try {
            if (empty($id)) {
                $sql = "INSERT INTO warehouse_stock (tenant_id, customer_id, origin, stock_name, quantity, 
                        length_cm, width_cm, height_cm, volume_cbm, 
                        location, bin_location, zone, minimum_stock, maximum_stock, unit_price, updated_by, last_updated) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$tenant_id, $customer_id, $origin, $stock_name, $quantity, 
                               $length_cm, $width_cm, $height_cm, $volume_cbm, $location, 
                               $bin_location, $zone, $minimum_stock, $maximum_stock, $unit_price, $_SESSION['user_id']]);
                
                // Add to stock movements log
                $new_id = $pdo->lastInsertId();
                $movementSql = "INSERT INTO stock_movements (tenant_id, warehouse_stock_id, quantity_change, 
                                previous_quantity, new_quantity, movement_type, notes, created_by, created_at) 
                                VALUES (?, ?, ?, ?, ?, 'in', 'Initial stock creation', ?, NOW())";
                $movementStmt = $pdo->prepare($movementSql);
                $movementStmt->execute([$tenant_id, $new_id, $quantity, 0, $quantity, $_SESSION['user_id']]);
                
                echo json_encode(['success' => true, 'message' => "Alaabta '$stock_name' waa la kaydiyay!"]);
            } else {
                // Get current quantity for movement log
                $stmt = $pdo->prepare("SELECT quantity FROM warehouse_stock WHERE id = ?");
                $stmt->execute([$id]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);
                $old_quantity = $current['quantity'];
                
                // Update existing stock item
                $sql = "UPDATE warehouse_stock 
                        SET tenant_id = ?, customer_id = ?, origin = ?, stock_name = ?, quantity = ?, 
                            length_cm = ?, width_cm = ?, height_cm = ?, volume_cbm = ?,
                            location = ?, bin_location = ?, zone = ?, minimum_stock = ?, maximum_stock = ?, 
                            unit_price = ?, updated_by = ?, last_updated = NOW()
                        WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$tenant_id, $customer_id, $origin, $stock_name, $quantity, 
                               $length_cm, $width_cm, $height_cm, $volume_cbm, $location, 
                               $bin_location, $zone, $minimum_stock, $maximum_stock, $unit_price, $_SESSION['user_id'], $id]);
                
                // Add to stock movements log if quantity changed
                if ($quantity != $old_quantity) {
                    $change = $quantity - $old_quantity;
                    $movement_type = $change > 0 ? 'in' : 'out';
                    $movementSql = "INSERT INTO stock_movements (tenant_id, warehouse_stock_id, quantity_change, 
                                    previous_quantity, new_quantity, movement_type, notes, created_by, created_at) 
                                    VALUES (?, ?, ?, ?, ?, ?, 'Stock update', ?, NOW())";
                    $movementStmt = $pdo->prepare($movementSql);
                    $movementStmt->execute([$tenant_id, $id, abs($change), $old_quantity, $quantity, $movement_type, $_SESSION['user_id']]);
                }
                
                echo json_encode(['success' => true, 'message' => "Alaabta '$stock_name' waa la cusboonaysiiyay!"]);
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
                echo json_encode(['success' => false, 'message' => 'Alaabta lama helin']);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM warehouse_stock WHERE id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['success' => true, 'message' => "Alaabta '{$item['stock_name']}' waa la tirtiray!"]);
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
                echo json_encode(['success' => false, 'message' => 'Alaabta lama helin']);
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
            
            // Add to stock movements log
            $movementSql = "INSERT INTO stock_movements (tenant_id, warehouse_stock_id, quantity_change, 
                            previous_quantity, new_quantity, movement_type, notes, created_by, created_at) 
                            VALUES ((SELECT tenant_id FROM warehouse_stock WHERE id = ?), ?, 0, 
                            (SELECT quantity FROM warehouse_stock WHERE id = ?), 
                            (SELECT quantity FROM warehouse_stock WHERE id = ?), 
                            'move', ?, ?, NOW())";
            $movementStmt = $pdo->prepare($movementSql);
            $movementStmt->execute([$id, $id, $id, $id, "Moved from '$old_location' ($old_bin) to '$new_location' ($new_bin). $notes", $_SESSION['user_id']]);
            
            echo json_encode(['success' => true, 'message' => "Alaabta '{$item['stock_name']}' waa loo raray meesha cusub!"]);
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
                echo json_encode(['success' => false, 'message' => 'Alaabta lama helin']);
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
            echo json_encode(['success' => true, 'message' => "Alaabta '{$item['stock_name']}' $action_text! Tirada cusub: $new_quantity"]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'get_available_trips') {
        $tenant_id = ($role === 'superadmin') ? (int)$_POST['tenant_id'] : $session_tenant_id;
        $origin = $_POST['origin'] ?? '';
        
        $sql = "
            SELECT tt.id, tt.trip_number, c.container_number, c.id as container_id, tt.status, c.status as container_status, c.origin
            FROM trucking_trips tt
            LEFT JOIN containers c ON tt.container_id = c.id
            WHERE tt.tenant_id = ? AND c.status IN ('received', 'loading', 'loaded')
        ";
        $params = [$tenant_id];
        
        if (!empty($origin)) {
            $sql .= " AND c.origin = ?";
            $params[] = $origin;
        }
        
        $sql .= " ORDER BY tt.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($trips);
        exit;
    }
    
    elseif ($action === 'load_to_container') {
        $stock_id = (int)$_POST['stock_id'];
        $trip_id = (int)$_POST['trip_id'];
        $qty_to_load = (int)$_POST['quantity'];
        
        if ($qty_to_load <= 0) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Fadlan geli tirada la rarayo']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Get stock item
            $stmt = $pdo->prepare("SELECT * FROM warehouse_stock WHERE id = ? FOR UPDATE");
            $stmt->execute([$stock_id]);
            $stock = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$stock || $stock['quantity'] < $qty_to_load) {
                $avail = $stock['quantity'] ?? 0;
                ob_clean();
                echo json_encode(['success' => false, 'message' => "Tirada bakhaarka ku jirta kuma filna (Waxaa haray: $avail)"]);
                exit;
            }
            
            // Get trip and container details
            $stmt = $pdo->prepare("
                SELECT tt.container_id, tt.tenant_id, c.status as container_status, c.origin 
                FROM trucking_trips tt 
                JOIN containers c ON tt.container_id = c.id 
                WHERE tt.id = ?
            ");
            $stmt->execute([$trip_id]);
            $trip = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$trip) {
                ob_clean();
                echo json_encode(['success' => false, 'message' => 'Safarka lama helin']);
                exit;
            }

            // Origin Validation
            if ($stock['origin'] !== $trip['origin']) {
                $originText = $stock['origin'] === 'china_yiwu' ? 'Yiwu' : ($stock['origin'] === 'china_guangzhou' ? 'Guangzhou' : 'Dubai');
                $targetText = $trip['origin'] === 'china_yiwu' ? 'Yiwu' : ($trip['origin'] === 'china_guangzhou' ? 'Guangzhou' : 'Dubai');
                ob_clean();
                echo json_encode(['success' => false, 'message' => "Khalad: Alaabtan waa $originText, laakiin kontaynerka la doortay waa $targetText. Isku origin kaliya ayaa la rari karaa."]);
                exit;
            }

            $blocked_statuses = ['ready', 'dispatched', 'at_port', 'delivered'];
            if (in_array($trip['container_status'], $blocked_statuses)) {
                ob_clean();
                echo json_encode(['success' => false, 'message' => 'Kontaynerkan lama rari karo (Xaaladdiisu waa: ' . $trip['container_status'] . ')']);
                exit;
            }
            
            // Decrease warehouse stock
            $new_qty = $stock['quantity'] - $qty_to_load;
            $stmt = $pdo->prepare("UPDATE warehouse_stock SET quantity = ? WHERE id = ?");
            $stmt->execute([$new_qty, $stock_id]);
            
            // Add to stock movements
            $stmt = $pdo->prepare("
                INSERT INTO stock_movements (tenant_id, warehouse_stock_id, quantity_change, previous_quantity, new_quantity, movement_type, reference_type, reference_id, notes, created_by)
                VALUES (?, ?, ?, ?, ?, 'out', 'trucking_trip', ?, ?, ?)
            ");
            $stmt->execute([
                $stock['tenant_id'], $stock_id, $qty_to_load, $stock['quantity'], $new_qty, 
                $trip_id, "La raray loona diray Safarka: " . $trip_id, $_SESSION['user_id']
            ]);
            
            // Add to cargo_manifest_items
            // CBM calculation 
            $cbm_per_unit = $stock['quantity'] > 0 ? ($stock['volume_cbm'] / $stock['quantity']) : 0;
            $cbm_used = $cbm_per_unit * $qty_to_load;
            
            // Weight calculation
            $weight_per_unit = $stock['quantity'] > 0 ? ($stock['weight_kg'] / $stock['quantity']) : 0;
            $weight_used = $weight_per_unit * $qty_to_load;
            
            $stmt = $pdo->prepare("
                INSERT INTO cargo_manifest_items (tenant_id, container_id, shipment_id, warehouse_stock_id, stock_name, quantity, cbm_used, weight_kg, unit_price)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $stock['tenant_id'], $trip['container_id'], $trip_id, $stock_id, $stock['stock_name'], $qty_to_load, $cbm_used, $weight_used, $stock['unit_price']
            ]);
            
            // Update trip totals
            $stmt = $pdo->prepare("UPDATE trucking_trips SET total_cbm = total_cbm + ?, status = 'loading', loaded_at = NOW() WHERE id = ?");
            $stmt->execute([$cbm_used, $trip_id]);
            
            $pdo->commit();
            ob_clean();
            echo json_encode(['success' => true, 'message' => "Alaabta waa lagu guulaystay in lagu raro kontaynerka!"]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'create_invoice_from_stock') {
        $stock_id = (int)$_POST['stock_id'];
        
        try {
            // This is a simplified version. Usually, it would redirect to invoices.php with parameters
            // or open the invoice modal. For now, let's just return the stock details.
            $stmt = $pdo->prepare("
                SELECT ws.*, c.customer_name, t.name as tenant_name
                FROM warehouse_stock ws
                LEFT JOIN customers c ON ws.customer_id = c.id
                LEFT JOIN tenants t ON ws.tenant_id = t.id
                WHERE ws.id = ?
            ");
            $stmt->execute([$stock_id]);
            $stock = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$stock) {
                echo json_encode(['success' => false, 'message' => 'Alaabta lama helin']);
                exit;
            }
            
            echo json_encode(['success' => true, 'stock' => $stock]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'import_stock') {
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
                // Columns: Tenant Name, Customer Name, Stock Name, Origin, Quantity, Volume, Unit Price, Location, Bin, Zone
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
            $msg = "Import-ka waa lagu guulaystay! ($imported alaab).";
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
        $tenant_filter = ($role === 'superadmin') ? (isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0) : $session_tenant_id;
        $where = $tenant_filter > 0 ? "WHERE tenant_id = $tenant_filter" : "";
        if ($role === 'company_admin') {
            $where = "WHERE tenant_id = $session_tenant_id";
        }
        
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_items,
                SUM(quantity) as total_quantity,
                SUM(volume_cbm) as total_volume,
                SUM(volume_cbm * unit_price) as total_value,
                COUNT(CASE WHEN quantity <= minimum_stock THEN 1 END) as low_stock_items,
                COUNT(CASE WHEN origin = 'china_yiwu' THEN 1 END) as yiwu_items,
                COUNT(CASE WHEN origin = 'china_guangzhou' THEN 1 END) as guangzhou_items,
                COUNT(CASE WHEN origin = 'dubai' THEN 1 END) as dubai_items
            FROM warehouse_stock
            $where
        ");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Stock by origin
        $origin_stats = $pdo->query("
            SELECT origin, SUM(quantity) as total_quantity, SUM(volume_cbm) as total_volume
            FROM warehouse_stock
            $where
            GROUP BY origin
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        // Recent stock movements
        $movements = $pdo->query("
            SELECT sm.*, ws.stock_name, u.full_name as created_by_name
            FROM stock_movements sm
            LEFT JOIN warehouse_stock ws ON sm.warehouse_stock_id = ws.id
            LEFT JOIN users u ON sm.created_by = u.id
            " . (($tenant_filter > 0 || $role === 'company_admin') ? "WHERE ws.tenant_id = " . ($role === 'company_admin' ? $session_tenant_id : $tenant_filter) : "") . "
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maareynta Bakhaarka - Super Admin | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root {
            --curdun-violet: #2D1859;
            --curdun-yellow: #F5C410;
            --curdun-violet-light: #4B2C85;
            --curdun-yellow-dark: #D4A70C;
            --curdun-gray: #6c757d;
            --curdun-dark: #2D2D2D;
            --curdun-success: #0F7A3A;
            --curdun-danger: #B42318;
            --curdun-info: #17a2b8;
            --curdun-warning: #ffc107;
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
            gap: 15px;
        }
        .page-header h1 { color: white; font-size: 24px; margin: 0; }
        .page-header h1 i { margin-right: 10px; }

        .btn-primary-custom {
            background: var(--curdun-yellow);
            color: var(--curdun-violet);
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .btn-primary-custom:hover {
            background: var(--curdun-yellow-dark);
            color: var(--curdun-violet);
            transform: translateY(-2px);
        }
        
        .btn-outline-custom {
            background: transparent;
            border: 2px solid var(--curdun-yellow);
            color: var(--curdun-yellow);
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .btn-outline-custom:hover {
            background: var(--curdun-yellow);
            color: var(--curdun-violet);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: transform 0.3s ease;
            cursor: pointer;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-card .stat-info h4 { font-size: 11px; color: var(--curdun-gray); margin: 0 0 5px 0; text-transform: uppercase; }
        .stat-card .stat-info .stat-number { font-size: 22px; font-weight: 700; color: var(--curdun-violet); }
        .stat-card .stat-icon { width: 45px; height: 45px; background: rgba(82,0,102,0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center; }
        .stat-card .stat-icon i { font-size: 22px; color: var(--curdun-violet); }
        
        .stat-card-danger .stat-info .stat-number { color: #B42318; }
        .stat-card-danger .stat-icon { background: rgba(198,40,40,0.1); }
        .stat-card-danger .stat-icon i { color: #B42318; }

        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .filter-form { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 150px; }
        .filter-group label { display: block; font-size: 12px; font-weight: 600; color: var(--curdun-gray); margin-bottom: 5px; }
        .filter-group input, .filter-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; }
        .checkbox-group { display: flex; align-items: center; gap: 10px; margin-top: 28px; }
        .checkbox-group label { margin: 0; cursor: pointer; }
        .btn-filter { background: var(--curdun-violet); color: white; border: none; padding: 8px 20px; border-radius: 8px; cursor: pointer; }
        .btn-reset { background: #f0f0f0; color: var(--curdun-dark); border: none; padding: 8px 20px; border-radius: 8px; margin-left: 10px; cursor: pointer; }

        .stock-table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            overflow-x: auto;
            width: 100%;
        }
        
        .stock-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1300px;
        }
        
        .stock-table th, .stock-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }
        .stock-table th {
            background: #f8f6f9;
            font-weight: 600;
            color: var(--curdun-dark);
            font-size: 12px;
            white-space: nowrap;
        }
        .stock-table tr:hover { background: #faf8fb; }
        .low-stock-row { background: rgba(198,40,40,0.05); }

        .origin-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .origin-china { background: #e3f2fd; color: #1565c0; }
        .origin-dubai { background: #fff3e0; color: #e65100; }
        .origin-local { background: #EEFBF3; color: #0F7A3A; }

        .stock-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-good { background: #EEFBF3; color: #0F7A3A; }
        .status-low { background: #FEF0EE; color: #B42318; }

        .progress-bar-container {
            width: 80px;
            height: 4px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
        }
        .progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
        .action-btn { padding: 5px 8px; border-radius: 6px; font-size: 11px; cursor: pointer; border: none; transition: all 0.3s ease; }
        .btn-view { background: #e8eaf6; color: #3949ab; }
        .btn-view:hover { background: #c5cae9; transform: scale(1.05); }
        .btn-edit { background: #fff3e0; color: #e65100; }
        .btn-edit:hover { background: #ffe0b2; transform: scale(1.05); }
        .btn-move { background: #e3f2fd; color: #1565c0; }
        .btn-move:hover { background: #bbdef5; transform: scale(1.05); }
        .btn-delete { background: #FEF0EE; color: #B42318; }
        .btn-delete:hover { background: #FEF0EE; transform: scale(1.05); }

        .alert { padding: 12px 20px; border-radius: 8px; position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .alert-success { background: #EEFBF3; color: #0F7A3A; border-left: 4px solid #0F7A3A; }
        .alert-error { background: #FEF0EE; color: #B42318; border-left: 4px solid #B42318; }
        .alert-info { background: #e3f2fd; color: #1565c0; border-left: 4px solid #1565c0; }

        .empty-state { text-align: center; padding: 50px; color: var(--curdun-gray); }
        .empty-state i { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }

        .modal-header { background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light)); color: white; }
        .modal-header .close { color: white; opacity: 1; }
        .modal-header .close:hover { color: var(--curdun-yellow); }

        .loading-spinner { text-align: center; padding: 50px; }
        .loading-spinner i { font-size: 48px; color: var(--curdun-violet); animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 25px; flex-wrap: wrap; }
        .pagination a, .pagination span { padding: 8px 14px; border-radius: 8px; text-decoration: none; color: var(--curdun-dark); background: white; border: 1px solid #ddd; cursor: pointer; transition: all 0.3s ease; }
        .pagination .active { background: var(--curdun-violet); color: white; border-color: var(--curdun-violet); }
        .pagination a:hover { background: var(--curdun-violet-light); color: white; transform: translateY(-2px); }

        .movements-list {
            background: white;
            border-radius: 12px;
            padding: 15px;
            margin-top: 25px;
        }
        .movements-list h4 { font-size: 14px; margin-bottom: 15px; color: var(--curdun-violet); }
        .movement-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
            font-size: 12px;
        }
        .movement-item:last-child { border-bottom: none; }

        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: center; }
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            .checkbox-group { margin-top: 0; }
            .alert { left: 20px; right: 20px; min-width: auto; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-warehouse"></i> Maareynta Bakhaarka</h1>
        <div class="d-flex align-items-center">
            <button type="button" class="btn-primary-custom" id="addStockBtn">
                <i class="fas fa-plus-circle"></i> Alaab Cusub
            </button>
            <div class="dropdown ml-2">
                <button class="btn btn-light dropdown-toggle" type="button" data-toggle="dropdown" style="border-radius: 20px; padding: 10px 15px; font-weight: 600; border: 1px solid #babec5;">
                    <i class="fas fa-file-csv"></i> CSV
                </button>
                <div class="dropdown-menu dropdown-menu-right" style="border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.1);">
                    <a class="dropdown-item" href="?action=export_stock" id="exportStockBtn"><i class="fas fa-download mr-2"></i> Export Stock</a>
                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#importModal"><i class="fas fa-upload mr-2"></i> Import Stock</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="?action=download_sample"><i class="fas fa-file-download mr-2"></i> Download Sample</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card" data-filter="reset" title="Tusi dhammaan alaabta">
            <div class="stat-info"><h4>Wadarta Alaabta</h4><div class="stat-number" id="stat-total-items">0</div></div>
            <div class="stat-icon"><i class="fas fa-boxes"></i></div>
        </div>
        <div class="stat-card" data-filter="reset">
            <div class="stat-info"><h4>Wadarta Tirada</h4><div class="stat-number" id="stat-total-quantity">0</div></div>
            <div class="stat-icon"><i class="fas fa-cubes"></i></div>
        </div>
        <div class="stat-card" data-filter="reset">
            <div class="stat-info"><h4>Wadarta Mugga (CBM/FT)</h4><div class="stat-number" id="stat-total-volume">0</div></div>
            <div class="stat-icon"><i class="fas fa-cube"></i></div>
        </div>
        <div class="stat-card" data-filter="reset">
            <div class="stat-info"><h4>Wadarta Qiimaha</h4><div class="stat-number" id="stat-total-value">$0</div></div>
            <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
        </div>
        <div class="stat-card stat-card-danger" data-filter="low-stock" title="Tusi alaabta dhamaanaysa">
            <div class="stat-info"><h4>Alaabta Digniin Ku Jirta</h4><div class="stat-number" id="stat-low-stock">0</div></div>
            <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
        </div>

        <div class="stat-card" data-filter="origin-china_yiwu" title="Tusi alaabta Yiwu">
            <div class="stat-info"><h4>China Yiwu</h4><div class="stat-number" id="stat-yiwu">0</div></div>
            <div class="stat-icon"><i class="fas fa-city"></i></div>
        </div>
        <div class="stat-card" data-filter="origin-china_guangzhou" title="Tusi alaabta Guangzhou">
            <div class="stat-info"><h4>China Guangzhou</h4><div class="stat-number" id="stat-guangzhou">0</div></div>
            <div class="stat-icon"><i class="fas fa-building"></i></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group"><label><i class="fas fa-search"></i> Raadin</label><input type="text" id="searchInput" placeholder="Magaca alaabta, goobta..."></div>
            <div class="filter-group"><label><i class="fas fa-building"></i> Shirkadda</label><select id="tenantFilter"><option value="0">Dhammaan</option><?php foreach ($tenants as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?></select></div>
            <div class="filter-group"><label><i class="fas fa-map-marker-alt"></i> Asalka</label><select id="originFilter"><option value="all">Dhammaan</option><option value="china_yiwu">China Yiwu 🇨🇳</option><option value="china_guangzhou">China Guangzhou 🇨🇳</option><option value="dubai">Dubay 🇦🇪</option></select></div>
            <div class="filter-group checkbox-group"><label><input type="checkbox" id="lowStockOnly"> Digniin (Hoos) oo keliya</label></div>
            <div class="filter-group"><button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Shaandheey</button><button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Nadiifi</button></div>
        </div>
    </div>

    <div id="stock-table-container"><div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i><p>Loading stock items...</p></div></div>
    <div id="pagination-container"></div>

    <!-- Recent Movements -->
    <div class="movements-list">
        <h4><i class="fas fa-history"></i> Dhaqdhaqaaqii Ugu Dambeeyay</h4>
        <div id="movementsList">
            <div class="loading-spinner" style="padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 8px;">
            <div class="modal-header">
                <h5 class="modal-title" style="color: white;"><i class="fas fa-file-import"></i> Soo geli Alaab (CSV)</h5>
                <button type="button" class="close" data-dismiss="modal" style="color: white;">&times;</button>
            </div>
            <form id="importForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="info-box" style="background: #e3f2fd; padding: 15px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid #0077c5;">
                        <i class="fas fa-info-circle"></i> Fadlan soo geli faylka CSV oo kaliya. 
                        <a href="?action=download_sample" class="alert-link">Halkan ka soo deji sample-ka</a>.
                    </div>
                    <div class="form-group">
                        <label>Dooro Faylka (CSV)</label>
                        <input type="file" name="excel_file" class="form-control" accept=".csv" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button>
                    <button type="submit" class="btn" style="background: var(--curdun-violet); color: white;">Soo geli (Import)</button>
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
                                <label>Macaamilka
                                    <button type="button" id="quickAddCustomerBtn" title="Macaamil Cusub" style="background:var(--curdun-violet);color:white;border:none;border-radius:50%;width:22px;height:22px;font-size:14px;line-height:1;cursor:pointer;margin-left:6px;vertical-align:middle;">+</button>
                                </label>
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
                                <label>Faahfaahinta Alaabta</label>
                                <input type="text" name="stock_name" id="modalStockName" class="form-control" placeholder="Tusaale: Dhar, Kabo, Bagaash...">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tirada <span class="text-danger">*</span></label>
                                <input type="number" name="quantity" id="modalQuantity" class="form-control" value="0" required min="0">
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div style="background:#f8f9fa; padding:10px; border-radius:8px; margin-bottom:15px; border:1px solid #eee;">
                                <label style="font-weight:600; font-size:13px; color:var(--curdun-violet);" id="dimensionLabel"><i class="fas fa-ruler-combined"></i> Cabbirka Xidhmada (Dimensions in CM)</label>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group mb-0">
                                            <label style="font-size:11px;">Length (L) <span class="dim-unit">CM</span></label>
                                            <input type="number" step="0.1" name="length_cm" id="modalLength" class="form-control form-control-sm dimension-input" value="0">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group mb-0">
                                            <label style="font-size:11px;">Width (W) <span class="dim-unit">CM</span></label>
                                            <input type="number" step="0.1" name="width_cm" id="modalWidth" class="form-control form-control-sm dimension-input" value="0">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group mb-0">
                                            <label style="font-size:11px;">Height (H) <span class="dim-unit">CM</span></label>
                                            <input type="number" step="0.1" name="height_cm" id="modalHeight" class="form-control form-control-sm dimension-input" value="0">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label id="volLabel">CBM (Volume) <small class="text-info">(Autofill)</small></label>
                                <input type="number" step="0.0001" name="volume_cbm" id="modalVolume" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label id="priceLabel">Qiimaha CBM kasta ($/<span style="font-weight:700;">CBM</span>)</label>
                                <input type="number" step="0.01" name="unit_price" id="modalUnitPrice" class="form-control" value="0">
                                <small class="text-muted">Wadarta: <strong id="totalValuePreview">$0.00</strong> <span id="calcPreviewText">(CBM × Qiimaha)</span></small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Goobta</label>
                                <input type="text" name="location" id="modalLocation" class="form-control" placeholder="Tusaale: Bakhaarka A">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Kaydi Alaabta</button>
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
                    <p>Alaabta: <strong id="moveStockName"></strong></p>
                    <div class="form-group">
                        <label>Goobta Cusub</label>
                        <input type="text" name="new_location" id="moveLocation" class="form-control" placeholder="Tusaale: Bakhaarka B">
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
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-sliders-h"></i> Wax Ka Beddel Tirada</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="adjustForm">
                <div class="modal-body">
                    <input type="hidden" name="stock_id" id="adjustStockId">
                    <p>Alaabta: <strong id="adjustStockName"></strong></p>
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
                <h5 class="modal-title"><i class="fas fa-box"></i> Faahfaahinta Alaabta</h5>
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
            <div class="modal-header bg-danger text-white"><h5 class="modal-title">Tirtir Alaabta</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <div class="modal-body">Ma hubtaa inaad tirtirto <strong id="deleteStockName"></strong>?<br><br><span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Digniin: Tirtirista waa joogto!</span></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" id="confirmDeleteBtn">Tirtir</button></div>
        </div>
    </div>
</div>

<!-- Load to Container Modal -->
<div class="modal fade" id="loadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-truck-loading"></i> Rar Kontayner</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form id="loadForm">
                <div class="modal-body">
                    <input type="hidden" name="stock_id" id="loadStockId">
                    <p>Alaabta: <strong id="loadStockName"></strong></p>
                    <p>Tirada Bakhaarka: <strong id="loadStockQty">0</strong></p>
                    
                    <div class="form-group">
                        <label>Dooro Safarka/Kontaynerka <span class="text-danger">*</span>
                            <button type="button" id="quickAddTripBtn" title="Rar Cusub" style="background:var(--curdun-violet);color:white;border:none;border-radius:50%;width:22px;height:22px;font-size:14px;line-height:1;cursor:pointer;margin-left:6px;vertical-align:middle;">+</button>
                        </label>
                        <select name="trip_id" id="loadTripId" class="form-control" required>
                            <option value="">Loading trips...</option>
                        </select>
                    </div>

                    <!-- Container Info & Loaded Items -->
                    <div id="containerInfoSection" style="display:none; background:#f8f9fa; padding:12px; border-radius:8px; margin-bottom:15px; border:1px solid #dee2e6;">
                        <h6 style="font-size:12px; font-weight:700; color:var(--curdun-violet); margin-bottom:8px;">
                            <i class="fas fa-info-circle"></i> Xogta Kontaynerka
                        </h6>
                        <div class="row text-center mb-2">
                            <div class="col-6">
                                <small class="text-muted d-block">Mugga la isticmaalay</small>
                                <strong id="contCbmUsed">0.00</strong> <small>CBM</small>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block">Mugga dhiman</small>
                                <strong id="contCbmLeft" class="text-success">0.00</strong> <small>CBM</small>
                            </div>
                        </div>
                        <div class="progress" style="height: 10px; margin-bottom: 12px; background: #e9ecef;">
                            <div id="contProgressBar" class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        
                        <div id="loadedItemsSection">
                            <h6 style="font-size:11px; font-weight:700; border-top: 1px solid #eee; padding-top:8px;">Alaabta horay ugu jirta:</h6>
                            <div id="loadedItemsList" style="max-height:120px; overflow-y:auto; font-size:11px;">
                                <p class="text-muted">Loading items...</p>
                            </div>
                        </div>
                    </div>

                    
                    <div class="form-group">
                        <label>Tirada la Rarayo <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" id="loadQuantity" class="form-control" required min="1">
                        <small class="text-muted">Geli inta aad rabto inaad rarato.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Haqaji</button>
                    <button type="submit" class="btn btn-primary">Rar Hadda</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Add Customer Modal -->
<div class="modal fade" id="quickCustomerModal" tabindex="-1" style="z-index:1060;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus"></i> Macaamil Cusub</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="quickCustomerForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Shirkadda <span class="text-danger">*</span></label>
                        <select name="tenant_id" id="qcTenantId" class="form-control" required>
                            <option value="">Dooro Shirkad...</option>
                            <?php foreach ($tenants as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Magaca Macaamilka <span class="text-danger">*</span></label>
                        <input type="text" name="customer_name" id="qcName" class="form-control" required placeholder="Magaca buuxa...">
                    </div>
                    <div class="form-group">
                        <label>Telefoonka <span class="text-danger">*</span></label>
                        <input type="text" name="phone" id="qcPhone" class="form-control" required placeholder="+252...">
                    </div>
                    <div class="form-group">
                        <label>Email (Ikhtiyaari)</label>
                        <input type="email" name="email" id="qcEmail" class="form-control" placeholder="email@example.com">
                    </div>
                    <div class="form-group">
                        <label>Cinwaan (Ikhtiyaari)</label>
                        <input type="text" name="address" id="qcAddress" class="form-control" placeholder="Mogadishu...">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Haqabi</button>
                    <button type="submit" class="btn btn-primary-custom">Kaydi Macaamilka</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Add Trip Modal -->
<div class="modal fade" id="quickTripModal" tabindex="-1" style="z-index:1070;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Safar/Rar Cusub</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form id="quickTripForm">
                <div class="modal-body">
                    <input type="hidden" name="tenant_id" id="qtTenantId">
                    <div class="form-group">
                        <label>Lambarka Kontaynerka <span class="text-danger">*</span></label>
                        <input type="text" name="container_number" id="qtContainerNumber" class="form-control" required placeholder="Tusaale: MSKU1234567">
                    </div>
                    <div class="form-group">
                        <label>Asalka (Wadanka laga keenayo) <span class="text-danger">*</span></label>
                        <select name="origin" id="qtOrigin" class="form-control" required>
                            <option value="china_yiwu">Shiinaha (Yiwu) 🇨🇳</option>
                            <option value="china_guangzhou">Shiinaha (Guangzhou) 🇨🇳</option>
                            <option value="dubai">Dubay 🇦🇪</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nooca Kontaynerka</label>
                        <select name="container_type" id="qtContainerType" class="form-control">
                            <option value="20ft">20ft Container</option>
                            <option value="40ft">40ft Container</option>
                            <option value="40hc">40ft High Cube</option>
                            <option value="lcl">LCL (Shared)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Lambarka Safarka (Trip #)</label>
                        <input type="text" name="trip_number" id="qtTripNumber" class="form-control" placeholder="Tusaale: TRP-101">
                        <small class="text-muted">Haddii aad faaruq kaga tagto, system-ka ayaa siinaya.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Haqabi</button>
                    <button type="submit" class="btn btn-success">Kaydi & Dooro</button>
                </div>
            </form>
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

    // Capture container_id from URL if present
    const urlParams = new URLSearchParams(window.location.search);
    const preSelectedContainerId = urlParams.get('container_id');
    let adjustId = null;

    // Dimension & CBM Calculation
    function calculateCBM() {
        const origin = $('#modalOrigin').val();
        const l = parseFloat($('#modalLength').val()) || 0;
        const w = parseFloat($('#modalWidth').val()) || 0;
        const h = parseFloat($('#modalHeight').val()) || 0;
        
        if (l > 0 && w > 0 && h > 0) {
            let cbm = 0;
            if (origin === 'dubai') {
                // If origin is Dubai, dimensions are in Feet. 1 Cubic Foot = 0.028317 CBM
                cbm = (l * w * h) * 0.028317;
            } else {
                // If origin is China, dimensions are in CM. (L*W*H)/1,000,000 = CBM
                cbm = (l * w * h) / 1000000;
            }
            $('#modalVolume').val(cbm.toFixed(4));
            updateTotalValuePreview();
        }
    }

    $('.dimension-input').on('input', calculateCBM);

    $('#modalOrigin').on('change', function() {
        const origin = $(this).val();
        if (origin === 'dubai') {
            $('#dimensionLabel').html('<i class="fas fa-ruler-combined"></i> Cabbirka Xidhmada (Dimensions in Feet)');
            $('.dim-unit').text('Feet');
            $('#volLabel').html('FT (Volume) <small class="text-info">(Autofill)</small>');
            $('#priceLabel').html('Qiimaha FT kasta ($/<span style="font-weight:700;">FT</span>)');
            $('#calcPreviewText').text('(FT × Qiimaha)');
        } else {
            $('#dimensionLabel').html('<i class="fas fa-ruler-combined"></i> Cabbirka Xidhmada (Dimensions in CM)');
            $('.dim-unit').text('CM');
            $('#volLabel').html('CBM (Volume) <small class="text-info">(Autofill)</small>');
            $('#priceLabel').html('Qiimaha CBM kasta ($/<span style="font-weight:700;">CBM</span>)');
            $('#calcPreviewText').text('(CBM × Qiimaha)');
        }
        calculateCBM();
    });

    // Stats Card Click Filters
    $('.stat-card').click(function() {
        const filter = $(this).data('filter');
        if (!filter) return;

        // Reset all first
        $('#originFilter').val('all');
        $('#lowStockOnly').prop('checked', false);
        $('#tenantFilter').val('0');
        $('#searchInput').val('');

        if (filter === 'low-stock') {
            $('#lowStockOnly').prop('checked', true);
        } else if (filter.startsWith('origin-')) {
            const origin = filter.replace('origin-', '');
            $('#originFilter').val(origin);
        }

        currentPage = 1;
        loadStockItems();
        
        // Scroll to table
        $('html, body').animate({
            scrollTop: $("#stock-table-container").offset().top - 100
        }, 500);
    });

   // Live calculation: Quantity × Volume × Price
$('#modalQuantity, #modalVolume, #modalUnitPrice').on('input', function() {
    updateTotalValuePreview();
});

    function updateTotalValuePreview() {
    const quantity = parseFloat($('#modalQuantity').val()) || 0;
    const volume = parseFloat($('#modalVolume').val()) || 0;
    const price = parseFloat($('#modalUnitPrice').val()) || 0;
    
    // Calculate: Volume × Price = Total Value
    const totalValue = volume * price;
    $('#totalValuePreview').text('$' + totalValue.toFixed(2));
}

    // Quick Add Customer Button
    $('#quickAddCustomerBtn').click(function(e) {
        e.preventDefault();
        // Pre-select tenant if already chosen in main form
        const tid = $('#modalTenantId').val();
        if (tid) $('#qcTenantId').val(tid);
        $('#quickCustomerModal').modal('show');
    });

    // Quick Customer Form Submit
    $('#quickCustomerForm').submit(function(e) {
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        btn.html('<i class="fas fa-spinner fa-spin"></i> Kaydinaya...').prop('disabled', true);
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'quick_add_customer',
                tenant_id: $('#qcTenantId').val(),
                customer_name: $('#qcName').val(),
                phone: $('#qcPhone').val(),
                email: $('#qcEmail').val(),
                address: $('#qcAddress').val()
            },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    // Add to dropdown and select it
                    $('#modalCustomerId').append(`<option value="${res.id}" selected>${res.name} (${res.phone})</option>`);
                    $('#quickCustomerModal').modal('hide');
                    $('#quickCustomerForm')[0].reset();
                    showAlert('success', 'Macaamilka \''+res.name+'\' waa lagu daray!');
                } else {
                    showAlert('error', res.message || 'Khalad ayaa dhacay');
                }
                btn.html('Kaydi Macaamilka').prop('disabled', false);
            },
            error: function() {
                showAlert('error', 'Khalad ayaa dhacay. Isku day mar kale.');
                btn.html('Kaydi Macaamilka').prop('disabled', false);
            }
        });
    });

    // Quick Add Trip Button
    $('#quickAddTripBtn').click(function(e) {
        e.preventDefault();
        const tid = $('#loadModal').data('tenant'); // Get tenant from modal data
        if (!tid || tid == '0' || tid == '') {
            showAlert('error', 'Fadlan alaabtaan u qoondee shirkad (tenant) ka hor intaadan rarka diwaangelin.');
            return;
        }
        
        const stockOrigin = $('#loadModal').data('origin');
        if (stockOrigin) {
            $('#qtOrigin').val(stockOrigin);
            $('#qtOrigin').css({'pointer-events': 'none', 'background-color': '#e9ecef'});
        } else {
            $('#qtOrigin').css({'pointer-events': 'auto', 'background-color': '#fff'});
        }

        $('#qtTenantId').val(tid);
        $('#quickTripModal').modal('show');
    });

    // Quick Trip Form Submit
    $('#quickTripForm').submit(function(e) {
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        btn.html('<i class="fas fa-spinner fa-spin"></i> Kaydinaya...').prop('disabled', true);
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'quick_add_trip',
                tenant_id: $('#qtTenantId').val(),
                container_number: $('#qtContainerNumber').val(),
                container_type: $('#qtContainerType').val(),
                trip_number: $('#qtTripNumber').val(),
                origin: $('#qtOrigin').val()
            },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    // Add to dropdown and select it
                    $('#loadTripId').append(`<option value="${res.id}" selected>${res.name}</option>`);
                    $('#quickTripModal').modal('hide');
                    $('#quickTripForm')[0].reset();
                    showAlert('success', 'Safarka \''+res.name+'\' waa la abuuray!');
                } else {
                    showAlert('error', res.message || 'Khalad ayaa dhacay');
                }
                btn.html('Kaydi & Dooro').prop('disabled', false);
            },
            error: function() {
                showAlert('error', 'Khalad ayaa dhacay. Isku day mar kale.');
                btn.html('Kaydi & Dooro').prop('disabled', false);
            }
        });
    });

    function loadStockItems() {
        $.ajax({
            url: 'warehouse_stock.php',
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
                $('#exportStockBtn').attr('href', `?action=export_stock&search=${encodeURIComponent(search)}&tenant=${tenant}&origin=${origin}`);
            },
            error: function() {
                $('#stock-table-container').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading data</p></div>');
            }
        });
    }

    $('#importForm').submit(function(e) {
        e.preventDefault();
        let formData = new FormData(this);
        formData.append('ajax_action', 'import_stock');
        
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
            url: 'warehouse_stock.php',
            type: 'POST',
            data: { 
                ajax_action: 'get_stats',
                tenant: $('#tenantFilter').val()
            },
            dataType: 'json',
            success: function(data) {
                const stats = data.stats;
                const movements = data.movements;
                const originStats = data.origin_stats;
                
                $('#stat-total-items').text(stats.total_items || 0);
                $('#stat-total-quantity').text(Number(stats.total_quantity || 0).toLocaleString());
                $('#stat-total-volume').text(parseFloat(stats.total_volume || 0).toFixed(2));
                $('#stat-total-value').text('$' + parseFloat(stats.total_value || 0).toFixed(2));
                $('#stat-low-stock').text(stats.low_stock_items || 0);
                $('#stat-yiwu').text(stats.yiwu_items || 0);
                $('#stat-guangzhou').text(stats.guangzhou_items || 0);
                $('#stat-dubai').text(stats.dubai_items || 0);
                
                // Display movements
                let movementsHtml = '';
                if (movements.length > 0) {
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
            },
            error: function() {
                console.error("Stats loading failed");
            }
        });
    }

    function attachTableEvents() {
        $('.view-stock').off('click').on('click', function() {
            const id = $(this).data('id');
            $.ajax({
                url: 'warehouse_stock.php',
                type: 'POST',
                data: { ajax_action: 'get_stock_item', id: id },
                dataType: 'json',
                success: function(item) {
                    const originText = item.origin === 'china_yiwu' ? 'Shiinaha (Yiwu)' : (item.origin === 'china_guangzhou' ? 'Shiinaha (Guangzhou)' : (item.origin === 'dubai' ? 'Dubay' : 'Gudaha'));
                    const isLowStock = item.quantity <= item.minimum_stock;
                    const unit = item.origin === 'dubai' ? 'FT' : 'CBM';
                    $('#viewModalBody').html(`
                        <div class="row">
    <div class="col-5"><strong>Faahfaahinta:</strong></div>
    <div class="col-7"><strong>${escapeHtml(item.stock_name || '-')}</strong></div>
    
    <div class="col-5"><strong>Asalka:</strong></div>
    <div class="col-7">${originText}</div>
    
    <div class="col-5"><strong>Tirada (Qty):</strong></div>
    <div class="col-7"><strong class="${isLowStock ? 'text-danger' : 'text-success'}">${Number(item.quantity).toLocaleString()}</strong></div>
    
    <div class="col-5"><strong>Cabirka Hal Xirmo (${unit}):</strong></div>
    <div class="col-7">${parseFloat(item.volume_cbm).toFixed(4)} ${unit}</div>
    
    <div class="col-5"><strong>Wadarta Cabirka Guud (${unit}):</strong></div>
    <div class="col-7"><strong>${(parseFloat(item.quantity) * parseFloat(item.volume_cbm)).toFixed(2)} ${unit}</strong></div>
    
    <div class="col-5"><strong>Goobta:</strong></div>
    <div class="col-7">${escapeHtml(item.location || '-')}</div>
    
    <div class="col-5"><strong>Qiimaha/${unit}:</strong></div>
    <div class="col-7">$${parseFloat(item.unit_price).toFixed(2)}</div>
    
    <div class="col-5"><strong>Wadarta Qiimaha Guud:</strong></div>
    <div class="col-7"><strong style="color: #0F7A3A; font-size: 16px;">$${(parseFloat(item.quantity) * parseFloat(item.volume_cbm) * parseFloat(item.unit_price)).toFixed(2)}</strong> 
    <small class="text-muted">(Tirada × ${unit} × Qiimaha)</small></div>
    
    <div class="col-5"><strong>Shirkadda:</strong></div>
    <div class="col-7">${escapeHtml(item.tenant_name || '-')}</div>
    
    <div class="col-5"><strong>Macaamilka:</strong></div>
    <div class="col-7">${escapeHtml(item.customer_name || '-')}</div>
    
    <div class="col-5"><strong>Cusboonaysiiyay:</strong></div>
    <div class="col-7">${item.last_updated || '-'}</div>
</div>
                    `);
                    $('#viewModal').modal('show');
                },
                error: function() {
                    showAlert('error', 'Ma suuragalin in la soo xiro xogta alaabta.');
                }
            });
        });

        $('.edit-stock').off('click').on('click', function() {
            const id = $(this).data('id');
            $.ajax({
                url: 'warehouse_stock.php',
                type: 'POST',
                data: { ajax_action: 'get_stock_item', id: id },
                dataType: 'json',
                success: function(item) {
                    $('#stockModalLabel').text('Wax Ka Beddel Alaabta');
                    $('#stock_id').val(item.id);
                    $('#modalTenantId').val(item.tenant_id);
                    $('#modalCustomerId').val(item.customer_id);
                    $('#modalOrigin').val(item.origin);
                    $('#modalStockName').val(item.stock_name);
                    $('#modalQuantity').val(item.quantity);
                    $('#modalVolume').val(item.volume_cbm);
                    $('#modalLength').val(item.length_cm);
                    $('#modalWidth').val(item.width_cm);
                    $('#modalHeight').val(item.height_cm);
                    $('#modalUnitPrice').val(item.unit_price);
                    $('#modalLocation').val(item.location);
                    $('#modalOrigin').trigger('change');
                    $('#stockModal').modal('show');
                },
                error: function() {
                    showAlert('error', 'Ma suuragalin in la soo xiro xogta alaabta.');
                }
            });
        });

        $('.move-stock').off('click').on('click', function() {
            moveId = $(this).data('id');
            $('#moveStockName').text($(this).data('name'));
            $('#moveLocation').val('');
            $('#moveNotes').val('');
            $('#moveModal').modal('show');
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
        $('#alert-placeholder').html(`<div class="alert alert-${type} alert-dismissible fade show"><i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
        setTimeout(() => $('.alert').fadeOut(5000, function() { $(this).remove(); }), 5000);
    }

    // Stock Form Submit
    $('#stockForm').submit(function(e) {
        e.preventDefault();
        
        if (!$('#modalStockName').val()) {
            showAlert('error', 'Magaca alaabta waa lagama maarmaan');
            return;
        }
        
        $.ajax({
            url: 'warehouse_stock.php',
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
                unit_price: $('#modalUnitPrice').val(),
                length_cm: $('#modalLength').val(),
                width_cm: $('#modalWidth').val(),
                height_cm: $('#modalHeight').val()
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
            url: 'warehouse_stock.php',
            type: 'POST',
            data: {
                ajax_action: 'move_stock',
                id: moveId,
                new_location: $('#moveLocation').val(),
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

    $('#confirmDeleteBtn').click(function() {
        if (deleteId) {
            $.ajax({
                url: 'warehouse_stock.php',
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

    // Load to Container Event
    $(document).on('click', '.load-stock', function() {
        const id = $(this).attr('data-id');
        const name = $(this).attr('data-name');
        const tenantId = $(this).attr('data-tenant');
        const qty = $(this).attr('data-qty');
        const origin = $(this).attr('data-origin');
        
        // Reset modal fields first
        $('#loadStockId').val('');
        $('#loadStockName').text('');
        $('#loadStockQty').text('0');
        $('#loadQuantity').val('');
        $('#loadTripId').html('<option value="">Loading trips...</option>');
        $('#containerInfoSection').hide();
        
        // Set new values
        $('#loadStockId').val(id);
        $('#loadStockName').text(name);
        $('#loadStockQty').text(qty);
        $('#loadQuantity').val(qty).attr('max', qty);
        $('#loadModal').data('tenant', tenantId);
        $('#loadModal').data('origin', origin);
        $('#loadModal').modal('show');
        
        // Fetch available trips for this tenant AND this origin
        $('#loadTripId').html('<option value="">Loading trips...</option>');
        $.ajax({
            url: 'warehouse_stock.php',
            type: 'POST',
            data: { ajax_action: 'get_available_trips', tenant_id: tenantId, origin: origin },
            dataType: 'json',
            success: function(trips) {
                if (trips.length > 0) {
                    let options = '<option value="">Dooro Safar...</option>';
                    trips.forEach(t => {
                        options += `<option value="${t.id}">${t.trip_number} - ${t.container_number || 'No Container'} (${t.status})</option>`;
                    });
                    $('#loadTripId').html(options);
                    
                    // Pre-select trip if container_id was in URL
                    if (preSelectedContainerId) {
                        // Check if any trip in the options has the preSelectedContainerId
                        // This requires us to have stored the container_id in the trip objects
                        // I'll update get_available_trips to return it, or I can just try to find a trip that matches.
                        // Actually, I can just find the trip where container_number matches if I had that, 
                        // but better to match by container_id. I'll update the PHP to include container_id.
                        
                        $.each(trips, function(i, t) {
                            if (t.container_id == preSelectedContainerId) {
                                $('#loadTripId').val(t.id).trigger('change');
                                return false; // break
                            }
                        });
                    }
                } else {
                    $('#loadTripId').html('<option value="">Ma jiraan safaro furan...</option>');
                }
            },
            error: function(xhr) {
                if (xhr.status !== 200) {
                    showAlert('error', 'Ma suuragalin in la soo xiro safarada furan.');
                }
            }
        });
    });

    // Handle Trip Selection in Load Modal
    $('#loadTripId').change(function() {
        const tripId = $(this).val();
        if (!tripId) {
            $('#containerInfoSection').fadeOut();
            return;
        }

        $('#loadedItemsList').html('<p class="text-muted"><i class="fas fa-spinner fa-spin"></i> Loading...</p>');
        $('#containerInfoSection').fadeIn();

        $.ajax({
            url: 'warehouse_stock.php',
            type: 'POST',
            data: { ajax_action: 'get_trip_container_info', trip_id: tripId },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    const stats = res.stats;
                    const items = res.items;
                    
                    const capacity = parseFloat(stats.capacity || 0);
                    const used = parseFloat(stats.used_cbm || 0);
                    const left = Math.max(0, capacity - used);
                    const percent = capacity > 0 ? (used / capacity * 100) : 0;
                    
                    $('#contCbmUsed').text(used.toFixed(2));
                    $('#contCbmLeft').text(left.toFixed(2));
                    $('#contProgressBar').css('width', percent + '%');
                    
                    if (percent > 90) {
                        $('#contProgressBar').removeClass('bg-success bg-info').addClass('bg-danger');
                        $('#contCbmLeft').removeClass('text-success').addClass('text-danger');
                    } else if (percent > 70) {
                        $('#contProgressBar').removeClass('bg-success bg-danger').addClass('bg-warning');
                        $('#contCbmLeft').removeClass('text-success').addClass('text-warning');
                    } else {
                        $('#contProgressBar').removeClass('bg-warning bg-danger').addClass('bg-success');
                        $('#contCbmLeft').removeClass('text-danger text-warning').addClass('text-success');
                    }

                    // Render Items
                    if (items.length > 0) {
                        let html = '<ul class="list-unstyled mb-0">';
                        items.forEach(it => {
                            html += `<li style="border-bottom: 1px dashed #eee; padding: 4px 0;">
                                <i class="fas fa-check-circle text-success"></i> ${it.stock_name} 
                                <span class="float-right"><b>${it.quantity}</b> pkgs | <b>${parseFloat(it.cbm_used).toFixed(2)}</b>CBM</span>
                            </li>`;
                        });
                        html += '</ul>';
                        $('#loadedItemsList').html(html);
                    } else {
                        $('#loadedItemsList').html('<p class="text-muted italic">Kontaynerkan weli wax alaab ah kuma jiraan.</p>');
                    }
                } else {
                    $('#containerInfoSection').hide();
                }
            },
            error: function(xhr) {
                $('#containerInfoSection').hide();
                if (xhr.status !== 200) {
                    showAlert('error', 'Ma suuragalin in la soo xiro xogta kontaynerka.');
                }
            }
        });
    });

    $('#loadForm').submit(function(e) {
        e.preventDefault();
        const $btn = $(this).find('button[type="submit"]');
        const qty = parseInt($('#loadQuantity').val());
        const max = parseInt($('#loadStockQty').text());
        
        if (qty > max) {
            showAlert('error', 'Tirada la rarayo kama badan karto inta bakhaarka ku jirta');
            return;
        }
        
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Loading...');
        
        $.ajax({
            url: 'warehouse_stock.php',
            type: 'POST',
            data: {
                ajax_action: 'load_to_container',
                stock_id: $('#loadStockId').val(),
                trip_id: $('#loadTripId').val(),
                quantity: qty,
                _debug_name: $('#loadStockName').text() // Send for debugging
            },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#loadModal').modal('hide');
                    loadStockItems();
                    loadStats();
                    showAlert('success', res.message);
                } else {
                    showAlert('error', res.message);
                }
            },
            error: function(xhr) {
                // If status is 200 (OK), it means the server succeeded but returned invalid JSON
                // We should only show an error alert if the status is NOT 200.
                if (xhr.status !== 200) {
                    showAlert('error', 'Khalad ayaa dhacay xiliga rarka: ' + xhr.statusText);
                }
            },
            complete: function() {
                $btn.prop('disabled', false).html('Rar Hadda');
            }
        });
    });

    // Bill Customer Event
    $(document).on('click', '.bill-stock', function() {
        const id = $(this).data('id');
        if (confirm('Ma hubtaa inaad rabto inaad biil ka sameeyso alaabtan? Waxaad u gudbi doontaa bogga biilasha.')) {
            $.ajax({
                url: 'warehouse_stock.php',
                type: 'POST',
                data: { ajax_action: 'create_invoice_from_stock', stock_id: id },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        // Redirect to invoices.php with pre-filled customer and tenant
                        window.location.href = `invoices.php?customer_id=${res.stock.customer_id}&tenant_id=${res.stock.tenant_id}&stock_id=${id}`;
                    } else {
                        showAlert('error', res.message);
                    }
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
        $('#modalLength').val(0);
        $('#modalWidth').val(0);
        $('#modalHeight').val(0);
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
    
    // WhatsApp Package Notification
    $(document).on('click', '.whatsapp-package', function() {
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
            alert('Macaamilkan ma lahan lambar telefoon oo sax ah!');
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
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>