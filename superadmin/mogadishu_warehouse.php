<?php
// mogadishu_warehouse.php
// Warehouse Management System for Mogadishu Port -faras cargo

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['superadmin', 'company_admin', 'tenant_admin', 'warehouse_supervisor', 'staff'])) {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION['role'];
$session_tenant_id = $_SESSION['tenant_id'] ?? 0;
$current_user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'User';

// Convert company_admin to tenant_admin
if ($role === 'company_admin') {
    $role = 'tenant_admin';
    $_SESSION['role'] = 'tenant_admin';
}

require_once __DIR__ . '/../config/db_connect.php';

// ── Ensure warehouse tables have required columns ───────────────────────────────────────────
try {
    // warehouse_stock table
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS mogadishu_status ENUM('not_arrived','in_warehouse','taken','delivered') NOT NULL DEFAULT 'not_arrived'");
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS mogadishu_received_date DATETIME DEFAULT NULL");
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS mogadishu_taken_date DATETIME DEFAULT NULL");
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS storage_fee DECIMAL(15,2) DEFAULT 0.00");
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS location VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS bin_location VARCHAR(100) DEFAULT NULL");
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS zone VARCHAR(50) DEFAULT NULL");
    
    // cargo_manifest_items table
    $pdo->exec("ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS mogadishu_status ENUM('not_arrived','in_warehouse','taken','delivered') NOT NULL DEFAULT 'not_arrived'");
    $pdo->exec("ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS mogadishu_received_date DATETIME DEFAULT NULL");
    $pdo->exec("ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS mogadishu_taken_date DATETIME DEFAULT NULL");
    $pdo->exec("ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS storage_fee DECIMAL(15,2) DEFAULT 0.00");
    
    // containers table
    $pdo->exec("ALTER TABLE containers ADD COLUMN IF NOT EXISTS customs_status ENUM('pending','cleared','held') DEFAULT 'pending'");
    $pdo->exec("ALTER TABLE containers ADD COLUMN IF NOT EXISTS eta_port DATE DEFAULT NULL");
    $pdo->exec("ALTER TABLE containers ADD COLUMN IF NOT EXISTS etd_port DATE DEFAULT NULL");
    $pdo->exec("ALTER TABLE containers ADD COLUMN IF NOT EXISTS current_branch_id INT(11) DEFAULT NULL");
} catch (PDOException $e) {
    // Ignore errors if columns already exist
}

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

// Get customers for dropdown
$customers = [];
$stmt = $pdo->prepare("SELECT id, customer_name, phone FROM customers WHERE tenant_id = ? AND is_active = 1 ORDER BY customer_name");
$stmt->execute([$session_tenant_id]);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get containers for dropdown
$containers = [];
if ($role === 'superadmin') {
    $stmt = $pdo->query("SELECT id, container_number, container_type FROM containers ORDER BY id DESC");
    $containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT id, container_number, container_type FROM containers WHERE tenant_id = ? ORDER BY id DESC");
    $stmt->execute([$session_tenant_id]);
    $containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Status definitions
$mogadishu_statuses = [
    'not_arrived' => 'Aan Imaanin 🚫',
    'in_warehouse' => 'Bakhaarka 📦',
    'taken' => 'La Qaaday ✅',
    'delivered' => 'La Gaarsiiyay 🎯'
];

$status_colors = [
    'not_arrived' => '#EF4444',
    'in_warehouse' => '#F59E0B',
    'taken' => '#10B981',
    'delivered' => '#06B6D4'
];

$customs_statuses = [
    'pending' => 'Sugaya ⏳',
    'cleared' => 'La Fasaxay ✅',
    'held' => 'La Qabtay ❌'
];

$customs_colors = [
    'pending' => '#F59E0B',
    'cleared' => '#10B981',
    'held' => '#EF4444'
];

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];
    
    // Get warehouse statistics
    if ($action === 'get_stats') {
        $tenant_filter = ($role === 'superadmin') ? (isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0) : $session_tenant_id;
        $where = ($tenant_filter > 0) ? "AND tenant_id = $tenant_filter" : "";
        if ($role !== 'superadmin') {
            $where = "AND tenant_id = $session_tenant_id";
        }
        
        try {
            // Total items in warehouse
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM warehouse_stock WHERE mogadishu_status = 'in_warehouse' $where");
            $total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Total quantity
            $stmt = $pdo->query("SELECT COALESCE(SUM(quantity), 0) as total_qty FROM warehouse_stock WHERE mogadishu_status = 'in_warehouse' $where");
            $total_qty = $stmt->fetch(PDO::FETCH_ASSOC)['total_qty'];
            
            // Total storage fee
            $stmt = $pdo->query("SELECT COALESCE(SUM(storage_fee), 0) as total_fee FROM warehouse_stock WHERE mogadishu_status = 'in_warehouse' $where");
            $total_fee = $stmt->fetch(PDO::FETCH_ASSOC)['total_fee'];
            
            // Containers at port
            $stmt = $pdo->query("SELECT COUNT(*) as containers FROM containers WHERE customs_status = 'pending' $where");
            $pending_containers = $stmt->fetch(PDO::FETCH_ASSOC)['containers'];
            
            // Items waiting to arrive
            $stmt = $pdo->query("SELECT COUNT(*) as waiting FROM warehouse_stock WHERE mogadishu_status = 'not_arrived' $where");
            $waiting_items = $stmt->fetch(PDO::FETCH_ASSOC)['waiting'];
            
            // Items taken this month
            $stmt = $pdo->query("SELECT COUNT(*) as taken FROM warehouse_stock WHERE mogadishu_status = 'taken' AND MONTH(mogadishu_taken_date) = MONTH(CURDATE()) $where");
            $taken_items = $stmt->fetch(PDO::FETCH_ASSOC)['taken'];
            
            echo json_encode([
                'total_items' => $total_items,
                'total_quantity' => $total_qty,
                'total_storage_fee' => $total_fee,
                'pending_containers' => $pending_containers,
                'waiting_items' => $waiting_items,
                'taken_items' => $taken_items
            ]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
    
    // Get warehouse items
    elseif ($action === 'get_warehouse_items') {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        $search = $_POST['search'] ?? '';
        $status_filter = $_POST['status'] ?? '';
        $tenant_filter = ($role === 'superadmin') ? (isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0) : $session_tenant_id;
        
        $where_conditions = [];
        $params = [];
        
        if ($tenant_filter > 0) {
            $where_conditions[] = "ws.tenant_id = ?";
            $params[] = $tenant_filter;
        } elseif ($role !== 'superadmin') {
            $where_conditions[] = "ws.tenant_id = ?";
            $params[] = $session_tenant_id;
        }
        
        if (!empty($search)) {
            $where_conditions[] = "(ws.stock_name LIKE ? OR c.customer_name LIKE ? OR ws.location LIKE ? OR ws.bin_location LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if (!empty($status_filter)) {
            $where_conditions[] = "ws.mogadishu_status = ?";
            $params[] = $status_filter;
        }
        
        $where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);
        
        $count_sql = "SELECT COUNT(*) as total FROM warehouse_stock ws
                      LEFT JOIN customers c ON ws.customer_id = c.id
                      $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_items / $limit);
        
        $sql = "
            SELECT ws.*, 
                   c.customer_name, c.phone as customer_phone,
                   t.name as tenant_name,
                   DATEDIFF(NOW(), ws.mogadishu_received_date) as days_in_storage,
                   (DATEDIFF(NOW(), ws.mogadishu_received_date) * 0.50) as calculated_storage_fee
            FROM warehouse_stock ws
            LEFT JOIN customers c ON ws.customer_id = c.id
            LEFT JOIN tenants t ON ws.tenant_id = t.id
            $where_clause
            ORDER BY 
                CASE ws.mogadishu_status 
                    WHEN 'in_warehouse' THEN 1 
                    WHEN 'not_arrived' THEN 2 
                    ELSE 3 
                END,
                ws.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate table HTML
        ob_start(); ?>
        <div style="overflow-x: auto; width: 100%;">
            <table class="warehouse-table" style="min-width: 1200px; width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f6f9;">
                        <th style="padding: 12px;">ID</th>
                        <th style="padding: 12px;">Magaca Alaabta</th>
                        <th style="padding: 12px;">Macmiil</th>
                        <th style="padding: 12px;">Tiro & CBM</th>
                        <th style="padding: 12px;">Goobta Bakhaarka</th>
                        <th style="padding: 12px;">Xaaladda</th>
                        <th style="padding: 12px;">Maalinta Bakhaarka</th>
                        <th style="padding: 12px;">Kharashka Kaydinta</th>
                        <th style="padding: 12px;">Hawlaha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($items) > 0): ?>
                        <?php foreach ($items as $item):
                            $statusColor = $status_colors[$item['mogadishu_status']] ?? '#6c757d';
                            $statusName = $mogadishu_statuses[$item['mogadishu_status']] ?? ucfirst($item['mogadishu_status']);
                            $daysInStorage = $item['days_in_storage'] ?? 0;
                            $storageFee = $item['storage_fee'] > 0 ? $item['storage_fee'] : ($item['calculated_storage_fee'] ?? 0);
                        ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px;"><?= $item['id'] ?> </td>
                                <td style="padding: 12px;">
                                    <strong><?= htmlspecialchars($item['stock_name']) ?></strong>
                                    <div style="font-size: 11px; color: #6c757d;">
                                        <?= htmlspecialchars($item['origin'] ?? 'N/A') ?>
                                    </div>
                                </td>
                                <td style="padding: 12px;">
                                    <?= htmlspecialchars($item['customer_name'] ?? '-') ?>
                                    <div style="font-size: 11px; color: #6c757d;">
                                        <?= htmlspecialchars($item['customer_phone'] ?? '') ?>
                                    </div>
                                </td>
                                <td style="padding: 12px;">
                                    <div>Tiro: <strong><?= number_format($item['quantity']) ?></strong></div>
                                    <div style="font-size: 11px;">CBM: <?= number_format($item['volume_cbm'], 4) ?></div>
                                    <div style="font-size: 11px;">Qiimo: $<?= number_format($item['volume_cbm'] * $item['unit_price'], 2) ?></div>
                                </td>
                                <td style="padding: 12px;">
                                    <?php if ($item['location']): ?>
                                        <div><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($item['location']) ?></div>
                                        <div style="font-size: 11px;">
                                            <?php if ($item['zone']): ?>Zona: <?= htmlspecialchars($item['zone']) ?><?php endif; ?>
                                            <?php if ($item['bin_location']): ?> | Bin: <?= htmlspecialchars($item['bin_location']) ?><?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">Lama qorin</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px;">
                                    <span class="status-badge" style="background: <?= $statusColor ?>20; color: <?= $statusColor ?>; padding: 4px 10px; border-radius: 20px; font-size: 11px;">
                                        <?= $statusName ?>
                                    </span>
                                </td>
                                <td style="padding: 12px;">
                                    <?php if ($item['mogadishu_received_date']): ?>
                                        <div><?= date('d/m/Y', strtotime($item['mogadishu_received_date'])) ?></div>
                                        <div style="font-size: 11px; color: <?= $daysInStorage > 30 ? '#EF4444' : '#6c757d' ?>">
                                            <?= $daysInStorage ?> maalmood
                                        </div>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px;">
                                    <div>$<?= number_format($storageFee, 2) ?></div>
                                    <?php if ($daysInStorage > 0 && $item['mogadishu_status'] == 'in_warehouse'): ?>
                                        <div style="font-size: 10px; color: #6c757d;">$0.50/maalin</div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px;">
                                    <div class="action-buttons">
                                        <button class="action-btn btn-view view-item" data-id="<?= $item['id'] ?>" title="Faahfaahin"><i class="fas fa-eye"></i></button>
                                        <?php if ($item['mogadishu_status'] == 'not_arrived'): ?>
                                            <button class="action-btn btn-receive receive-item" data-id="<?= $item['id'] ?>" data-name="<?= htmlspecialchars($item['stock_name']) ?>" title="Soo Dhawo Bakhaarka"><i class="fas fa-arrow-down"></i></button>
                                        <?php endif; ?>
                                        <?php if ($item['mogadishu_status'] == 'in_warehouse'): ?>
                                            <button class="action-btn btn-release release-item" data-id="<?= $item['id'] ?>" data-name="<?= htmlspecialchars($item['stock_name']) ?>" title="Siidayn"><i class="fas fa-arrow-up"></i></button>
                                        <?php endif; ?>
                                        <button class="action-btn btn-edit edit-item" data-id="<?= $item['id'] ?>" title="Wax Ka Beddel"><i class="fas fa-edit"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 50px;">
                                <div class="empty-state">
                                    <i class="fas fa-warehouse" style="font-size: 48px; opacity: 0.5;"></i>
                                    <p>Ma jiraan wax alaab ah bakhaarka</p>
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
            <div class="pagination" style="display: flex; justify-content: center; gap: 8px; margin-top: 25px;">
                <?php if ($page > 1): ?>
                    <a data-page="<?= $page-1 ?>" class="pagination-link"><i class="fas fa-chevron-left"></i> Hore</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active-page"><?= $i ?></span>
                    <?php else: ?>
                        <a data-page="<?= $i ?>" class="pagination-link"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a data-page="<?= $page+1 ?>" class="pagination-link">Danbe <i class="fas fa-chevron-right"></i></a>
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
    
    // Get single item details
    elseif ($action === 'get_item') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("
            SELECT ws.*, c.customer_name, c.phone as customer_phone, t.name as tenant_name
            FROM warehouse_stock ws
            LEFT JOIN customers c ON ws.customer_id = c.id
            LEFT JOIN tenants t ON ws.tenant_id = t.id
            WHERE ws.id = ?
        ");
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get movement history
        $stmt2 = $pdo->prepare("
            SELECT * FROM stock_movements 
            WHERE warehouse_stock_id = ? 
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $stmt2->execute([$id]);
        $movements = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['item' => $item, 'movements' => $movements]);
        exit;
    }
    
    // Receive item to warehouse
    elseif ($action === 'receive_item') {
        $id = (int)($_POST['id'] ?? 0);
        $location = trim($_POST['location'] ?? '');
        $bin_location = trim($_POST['bin_location'] ?? '');
        $zone = trim($_POST['zone'] ?? '');
        
        try {
            $sql = "UPDATE warehouse_stock 
                    SET mogadishu_status = 'in_warehouse',
                        mogadishu_received_date = NOW(),
                        location = ?,
                        bin_location = ?,
                        zone = ?
                    WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$location, $bin_location, $zone, $id]);
            
            // Also update related cargo manifest items
            $stmt2 = $pdo->prepare("
                UPDATE cargo_manifest_items 
                SET mogadishu_status = 'in_warehouse',
                    mogadishu_received_date = NOW()
                WHERE warehouse_stock_id = ?
            ");
            $stmt2->execute([$id]);
            
            echo json_encode(['success' => true, 'message' => 'Alaabta waa la soo dhaweeyay bakhaarka!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Release item from warehouse (taken by customer)
    elseif ($action === 'release_item') {
        $id = (int)($_POST['id'] ?? 0);
        $storage_fee = (float)($_POST['storage_fee'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        try {
            $sql = "UPDATE warehouse_stock 
                    SET mogadishu_status = 'taken',
                        mogadishu_taken_date = NOW(),
                        storage_fee = ?
                    WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$storage_fee, $id]);
            
            // Update cargo manifest items
            $stmt2 = $pdo->prepare("
                UPDATE cargo_manifest_items 
                SET mogadishu_status = 'taken',
                    mogadishu_taken_date = NOW(),
                    storage_fee = ?
                WHERE warehouse_stock_id = ?
            ");
            $stmt2->execute([$storage_fee, $id]);
            
            echo json_encode(['success' => true, 'message' => 'Alaabta waa la sii daayay macmiilka!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Update item details
    elseif ($action === 'update_item') {
        $id = (int)($_POST['id'] ?? 0);
        $stock_name = trim($_POST['stock_name'] ?? '');
        $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $quantity = (int)($_POST['quantity'] ?? 0);
        $length_cm = (float)($_POST['length_cm'] ?? 0);
        $width_cm = (float)($_POST['width_cm'] ?? 0);
        $height_cm = (float)($_POST['height_cm'] ?? 0);
        $volume_cbm = (float)($_POST['volume_cbm'] ?? 0);
        $unit_price = (float)($_POST['unit_price'] ?? 0);
        $location = trim($_POST['location'] ?? '');
        $bin_location = trim($_POST['bin_location'] ?? '');
        $zone = trim($_POST['zone'] ?? '');
        $origin = $_POST['origin'] ?? 'china_yiwu';
        
        if (empty($stock_name)) {
            echo json_encode(['success' => false, 'message' => 'Fadlan geli magaca alaabta']);
            exit;
        }
        
        try {
            $sql = "UPDATE warehouse_stock 
                    SET stock_name = ?, customer_id = ?, quantity = ?,
                        length_cm = ?, width_cm = ?, height_cm = ?,
                        volume_cbm = ?, unit_price = ?, location = ?,
                        bin_location = ?, zone = ?, origin = ?,
                        updated_by = ?, last_updated = NOW()
                    WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$stock_name, $customer_id, $quantity, $length_cm, $width_cm, $height_cm,
                           $volume_cbm, $unit_price, $location, $bin_location, $zone, $origin,
                           $current_user_id, $id]);
            
            echo json_encode(['success' => true, 'message' => 'Alaabta waa la cusboonaysiiyay!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Get containers list
    elseif ($action === 'get_containers') {
        $tenant_filter = ($role === 'superadmin') ? (isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0) : $session_tenant_id;
        
        $where = "";
        $params = [];
        if ($tenant_filter > 0) {
            $where = "WHERE tenant_id = ?";
            $params[] = $tenant_filter;
        } elseif ($role !== 'superadmin') {
            $where = "WHERE tenant_id = ?";
            $params[] = $session_tenant_id;
        }
        
        $sql = "SELECT c.*, t.name as tenant_name 
                FROM containers c
                LEFT JOIN tenants t ON c.tenant_id = t.id
                $where
                ORDER BY c.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['containers' => $containers]);
        exit;
    }
    
    // Update container customs status
    elseif ($action === 'update_container_customs') {
        $id = (int)($_POST['id'] ?? 0);
        $customs_status = $_POST['customs_status'] ?? 'pending';
        
        try {
            $sql = "UPDATE containers SET customs_status = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$customs_status, $id]);
            
            echo json_encode(['success' => true, 'message' => 'Xaaladda Kastamka waa la cusboonaysiiyay!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Get pending shipments (items waiting to arrive)
    elseif ($action === 'get_pending_shipments') {
        $tenant_filter = ($role === 'superadmin') ? (isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0) : $session_tenant_id;
        
        $where = "";
        $params = [];
        if ($tenant_filter > 0) {
            $where = "AND ws.tenant_id = ?";
            $params[] = $tenant_filter;
        } elseif ($role !== 'superadmin') {
            $where = "AND ws.tenant_id = ?";
            $params[] = $session_tenant_id;
        }
        
        $sql = "
            SELECT ws.*, c.customer_name, cnt.container_number
            FROM warehouse_stock ws
            LEFT JOIN customers c ON ws.customer_id = c.id
            LEFT JOIN cargo_manifest_items cmi ON ws.id = cmi.warehouse_stock_id
            LEFT JOIN containers cnt ON cmi.container_id = cnt.id
            WHERE ws.mogadishu_status = 'not_arrived' $where
            ORDER BY ws.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_start(); ?>
        <div style="overflow-x: auto;">
            <table class="pending-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f6f9;">
                        <th style="padding: 12px;">Alaabta</th>
                        <th style="padding: 12px;">Macmiil</th>
                        <th style="padding: 12px;">Container</th>
                        <th style="padding: 12px;">Tiro</th>
                        <th style="padding: 12px;">Hawlaha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($items) > 0): ?>
                        <?php foreach ($items as $item): ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px;"><strong><?= htmlspecialchars($item['stock_name']) ?></strong></td>
                                <td style="padding: 12px;"><?= htmlspecialchars($item['customer_name'] ?? '-') ?></td>
                                <td style="padding: 12px;"><?= htmlspecialchars($item['container_number'] ?? '-') ?></td>
                                <td style="padding: 12px;"><?= number_format($item['quantity']) ?></td>
                                <td style="padding: 12px;">
                                    <button class="action-btn btn-receive-pending" data-id="<?= $item['id'] ?>" data-name="<?= htmlspecialchars($item['stock_name']) ?>">
                                        <i class="fas fa-arrow-down"></i> Soo Dhawo
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align: center; padding: 30px;">Ma jiraan wax alaab ah oo sugaya</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        $html = ob_get_clean();
        echo json_encode(['html' => $html]);
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
    <title>Maareynta Bakhaarka Muqdisho | Cargo Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root {
            --primary: #2D1859;
            --primary-light: #4B2C85;
            --secondary: #F5C410;
            --secondary-dark: #D4A70C;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: var(--gray-50); font-family: 'Inter', sans-serif; }
        
        .page-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border-radius: 20px;
            padding: 20px 30px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 10px 25px -5px rgba(82, 0, 102, 0.15);
        }
        .page-header h1 { color: white; font-size: 24px; margin: 0; font-weight: 600; }
        .page-header h1 i { margin-right: 10px; color: var(--secondary); }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid var(--gray-200);
            transition: all 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); }
        .stat-info h4 { font-size: 11px; color: var(--gray-500); margin: 0 0 5px 0; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-info .stat-number { font-size: 22px; font-weight: 700; color: var(--primary); }
        .stat-icon { width: 45px; height: 45px; background: rgba(82,0,102,0.08); border-radius: 12px; display: flex; align-items: center; justify-content: center; }
        .stat-icon i { font-size: 20px; color: var(--primary); }
        
        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 768px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        
        .filters-card {
            background: white;
            border-radius: 16px;
            padding: 15px 20px;
            margin-bottom: 25px;
            border: 1px solid var(--gray-200);
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        .filter-group { flex: 1; min-width: 180px; }
        .filter-group label { display: block; font-size: 12px; font-weight: 600; color: var(--gray-600); margin-bottom: 5px; }
        .filter-group input, .filter-group select {
            width: 100%; padding: 8px 12px; border: 1px solid var(--gray-300); border-radius: 10px;
            font-size: 13px; transition: all 0.2s;
        }
        .btn-filter, .btn-reset { padding: 8px 20px; border-radius: 10px; font-weight: 500; font-size: 13px; cursor: pointer; border: none; }
        .btn-filter { background: var(--primary); color: white; }
        .btn-reset { background: var(--gray-100); color: var(--gray-700); border: 1px solid var(--gray-200); }
        
        .warehouse-table-container {
            background: white;
            border-radius: 16px;
            border: 1px solid var(--gray-200);
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
        .action-btn { width: 30px; height: 30px; border-radius: 8px; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; }
        .btn-view { background: #eef2ff; color: #4f46e5; }
        .btn-view:hover { background: #4f46e5; color: white; }
        .btn-edit { background: #fff7ed; color: #ea580c; }
        .btn-edit:hover { background: #ea580c; color: white; }
        .btn-receive { background: #d1fae5; color: #10b981; }
        .btn-receive:hover { background: #10b981; color: white; }
        .btn-release { background: #fef3c7; color: #d97706; }
        .btn-release:hover { background: #d97706; color: white; }
        
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 25px; }
        .pagination-link, .active-page {
            padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 500;
            background: white; border: 1px solid var(--gray-200); cursor: pointer;
        }
        .active-page { background: var(--primary); color: white; border-color: var(--primary); }
        
        .modal-header { background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: white; border-bottom: none; }
        .modal-header .close { color: white; opacity: 0.8; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { font-size: 12px; font-weight: 600; color: var(--gray-700); margin-bottom: 5px; display: block; }
        .form-control { border-radius: 10px; border: 1px solid var(--gray-300); padding: 8px 12px; font-size: 13px; }
        
        .nav-tabs .nav-link { color: var(--gray-700); border: none; padding: 10px 20px; }
        .nav-tabs .nav-link.active { color: var(--primary); border-bottom: 2px solid var(--primary); background: transparent; }
        
        .alert { position: fixed; top: 85px; right: 20px; z-index: 9999; min-width: 320px; border-radius: 12px; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
        .loading-spinner { text-align: center; padding: 50px; }
        .loading-spinner i { font-size: 40px; color: var(--primary); animation: spin 1s linear infinite; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        
        .empty-state { text-align: center; padding: 60px; color: var(--gray-500); }
        .empty-state i { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }
        
        .text-success { color: var(--success); }
        .text-warning { color: var(--warning); }
        .text-danger { color: var(--danger); }
        
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
        .info-item { padding: 10px; background: var(--gray-50); border-radius: 10px; }
        .info-item label { font-size: 11px; color: var(--gray-500); display: block; margin-bottom: 3px; }
        .info-item .value { font-size: 14px; font-weight: 600; }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-warehouse"></i> Maareynta Bakhaarka Muqdisho</h1>
        <div>
            <button type="button" class="btn-primary-custom" id="refreshBtn" style="background: rgba(255,255,255,0.2); color: white; margin-right: 10px;">
                <i class="fas fa-sync-alt"></i> Cusboonaysii
            </button>
            <button type="button" class="btn-primary-custom" id="viewContainersBtn">
                <i class="fas fa-ship"></i> Kontaynerada
            </button>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-info"><h4>Alaabta Bakhaarka</h4><div class="stat-number" id="stat-total-items">0</div></div><div class="stat-icon"><i class="fas fa-boxes"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Wadarta Tirada</h4><div class="stat-number" id="stat-total-qty">0</div></div><div class="stat-icon"><i class="fas fa-cubes"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Kharashka Kaydinta</h4><div class="stat-number" id="stat-total-fee">$0</div></div><div class="stat-icon"><i class="fas fa-dollar-sign"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Kontayner Sugaya</h4><div class="stat-number" id="stat-pending-containers">0</div></div><div class="stat-icon"><i class="fas fa-clock"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Alaabta Sugaya</h4><div class="stat-number" id="stat-waiting">0</div></div><div class="stat-icon"><i class="fas fa-hourglass-half"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>La Qaaday Bishan</h4><div class="stat-number" id="stat-taken">0</div></div><div class="stat-icon"><i class="fas fa-check-circle"></i></div></div>
    </div>

    <!-- Filters -->
    <div class="filters-card">
        <div class="filter-group"><label><i class="fas fa-search"></i> Raadin</label><input type="text" id="searchInput" placeholder="Raadi alaabta, macmiilka..."></div>
        <?php if ($role === 'superadmin'): ?>
        <div class="filter-group"><label><i class="fas fa-building"></i> Shirkadda</label><select id="tenantFilter"><option value="0">Dhammaan</option><?php foreach ($tenants as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="filter-group"><label><i class="fas fa-filter"></i> Xaaladda</label><select id="statusFilter"><option value="">Dhammaan</option><option value="not_arrived">Aan Imaanin</option><option value="in_warehouse">Bakhaarka</option><option value="taken">La Qaaday</option><option value="delivered">La Gaarsiiyay</option></select></div>
        <div><button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Shaandheey</button></div>
        <div><button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Nadiifi</button></div>
    </div>

    <div id="warehouse-table-container"><div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i><p>Loading warehouse data...</p></div></div>
    <div id="pagination-container"></div>
</div>

<!-- View Item Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-eye"></i> Faahfaahinta Alaabta</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Xir</button>
            </div>
        </div>
    </div>
</div>

<!-- Receive Item Modal -->
<div class="modal fade" id="receiveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-arrow-down"></i> Soo Dhawo Alaabta</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="receiveForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="receiveId">
                    <p><strong>Alaabta:</strong> <span id="receiveItemName"></span></p>
                    <div class="form-group">
                        <label>Goobta Bakhaarka (Location)</label>
                        <input type="text" name="location" id="receiveLocation" class="form-control" placeholder="Tusaale: Shelf A-1">
                    </div>
                    <div class="form-group">
                        <label>Bin Location</label>
                        <input type="text" name="bin_location" id="receiveBinLocation" class="form-control" placeholder="Tusaale: BIN-001">
                    </div>
                    <div class="form-group">
                        <label>Zone</label>
                        <select name="zone" id="receiveZone" class="form-control">
                            <option value="">-- Dooro Zone --</option>
                            <option value="Zone A">Zone A (Electronics)</option>
                            <option value="Zone B">Zone B (Clothing)</option>
                            <option value="Zone C">Zone C (Food)</option>
                            <option value="Zone D">Zone D (Furniture)</option>
                            <option value="Zone E">Zone E (General)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Kaalay</button>
                    <button type="submit" class="btn btn-success">Soo Dhawo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Release Item Modal -->
<div class="modal fade" id="releaseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-arrow-up"></i> Siidayn Alaabta</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="releaseForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="releaseId">
                    <p><strong>Alaabta:</strong> <span id="releaseItemName"></span></p>
                    <div class="form-group">
                        <label>Kharashka Kaydinta (Storage Fee)</label>
                        <input type="number" step="0.01" name="storage_fee" id="releaseStorageFee" class="form-control" placeholder="0.00">
                        <small class="text-muted">Kharashka kaydinta maalin walba $0.50</small>
                    </div>
                    <div class="form-group">
                        <label>Qoraal (Notes)</label>
                        <textarea name="notes" id="releaseNotes" class="form-control" rows="2" placeholder="Tusaale: Macmiilka ayaa qaaday..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Kaalay</button>
                    <button type="submit" class="btn btn-warning">Siidayn</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Item Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Wax Ka Beddel Alaabta</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="editForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="editId">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Magaca Alaabta <span class="text-danger">*</span></label>
                                <input type="text" name="stock_name" id="editStockName" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Macmiilka</label>
                                <select name="customer_id" id="editCustomerId" class="form-control">
                                    <option value="">-- Dooro Macmiil --</option>
                                    <?php foreach ($customers as $c): ?>
                                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['customer_name']) ?> (<?= $c['phone'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Asalka (Origin)</label>
                                <select name="origin" id="editOrigin" class="form-control">
                                    <option value="china_yiwu">China Yiwu</option>
                                    <option value="china_guangzhou">China Guangzhou</option>
                                    <option value="dubai">Dubai</option>
                                    <option value="local">Local</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tirada (Quantity)</label>
                                <input type="number" name="quantity" id="editQuantity" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Length (cm)</label>
                                <input type="number" step="0.01" name="length_cm" id="editLength" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Width (cm)</label>
                                <input type="number" step="0.01" name="width_cm" id="editWidth" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Height (cm)</label>
                                <input type="number" step="0.01" name="height_cm" id="editHeight" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Volume (CBM)</label>
                                <input type="number" step="0.0001" name="volume_cbm" id="editVolume" class="form-control" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Qiimaha Cutubka (Unit Price)</label>
                                <input type="number" step="0.01" name="unit_price" id="editUnitPrice" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Location</label>
                                <input type="text" name="location" id="editLocation" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Bin Location</label>
                                <input type="text" name="bin_location" id="editBinLocation" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Zone</label>
                                <input type="text" name="zone" id="editZone" class="form-control">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Kaalay</button>
                    <button type="submit" class="btn btn-primary-custom">Kaydi Isbeddellada</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Containers Modal -->
<div class="modal fade" id="containersModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-ship"></i> Maareynta Kontaynerada</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" id="containerTabs" role="tablist">
                    <li class="nav-item"><a class="nav-link active" id="all-containers-tab" data-toggle="tab" href="#allContainers">Dhammaan Kontaynerada</a></li>
                    <li class="nav-item"><a class="nav-link" id="pending-customs-tab" data-toggle="tab" href="#pendingCustoms">Kastamka Sugaya</a></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="allContainers">
                        <div id="containersList" class="mt-3"><div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>
                    </div>
                    <div class="tab-pane fade" id="pendingCustoms">
                        <div id="pendingShipmentsList" class="mt-3"><div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Xir</button>
            </div>
        </div>
    </div>
</div>

<!-- Update Customs Modal -->
<div class="modal fade" id="customsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-passport"></i> Cusboonaysii Xaaladda Kastamka</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="customsForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="customsId">
                    <p><strong>Kontayner:</strong> <span id="customsContainerNumber"></span></p>
                    <div class="form-group">
                        <label>Xaaladda Kastamka</label>
                        <select name="customs_status" id="customsStatus" class="form-control">
                            <option value="pending">Sugaya ⏳</option>
                            <option value="cleared">La Fasaxay ✅</option>
                            <option value="held">La Qabtay ❌</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Kaalay</button>
                    <button type="submit" class="btn btn-primary-custom">Cusboonaysii</button>
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
    
    function loadWarehouseItems() {
        let data = {
            ajax_action: 'get_warehouse_items',
            page: currentPage,
            search: $('#searchInput').val(),
            status: $('#statusFilter').val()
        };
        <?php if ($role === 'superadmin'): ?>
        data.tenant = $('#tenantFilter').val();
        <?php endif; ?>
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(res) {
                $('#warehouse-table-container').html(res.table_html);
                $('#pagination-container').html(res.pagination_html);
                attachTableEvents();
            },
            error: function() {
                $('#warehouse-table-container').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Khalad ayaa dhacay</p></div>');
            }
        });
    }
    
    function loadStats() {
        let data = { ajax_action: 'get_stats' };
        <?php if ($role === 'superadmin'): ?>
        if ($('#tenantFilter').val() && $('#tenantFilter').val() != '0') data.tenant = $('#tenantFilter').val();
        <?php endif; ?>
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(s) {
                $('#stat-total-items').text(s.total_items || 0);
                $('#stat-total-qty').text(s.total_quantity || 0);
                $('#stat-total-fee').text('$' + (s.total_storage_fee || 0).toFixed(2));
                $('#stat-pending-containers').text(s.pending_containers || 0);
                $('#stat-waiting').text(s.waiting_items || 0);
                $('#stat-taken').text(s.taken_items || 0);
            }
        });
    }
    
    function loadContainers() {
        let data = { ajax_action: 'get_containers' };
        <?php if ($role === 'superadmin'): ?>
        if ($('#tenantFilter').val() && $('#tenantFilter').val() != '0') data.tenant = $('#tenantFilter').val();
        <?php endif; ?>
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(res) {
                if (res.containers && res.containers.length > 0) {
                    let html = '<table class="table table-bordered"><thead><tr><th>ID</th><th>Container Number</th><th>Nooca</th><th>Shirkadda</th><th>Xaaladda Kastamka</th><th>Hawlaha</th></tr></thead><tbody>';
                    for (let c of res.containers) {
                        let customsStatus = c.customs_status === 'pending' ? 'Sugaya' : (c.customs_status === 'cleared' ? 'La Fasaxay' : 'La Qabtay');
                        let customsClass = c.customs_status === 'pending' ? 'text-warning' : (c.customs_status === 'cleared' ? 'text-success' : 'text-danger');
                        html += `<tr>
                            <td>${c.id}</td>
                            <td><strong>${escapeHtml(c.container_number)}</strong></td>
                            <td>${c.container_type || '-'}</td>
                            <td>${escapeHtml(c.tenant_name || '-')}</td>
                            <td class="${customsClass}">${customsStatus}</td>
                            <td><button class="btn btn-sm btn-primary update-customs" data-id="${c.id}" data-number="${escapeHtml(c.container_number)}" data-status="${c.customs_status}"><i class="fas fa-edit"></i> Cusboonaysii</button></td>
                        </tr>`;
                    }
                    html += '</tbody></table>';
                    $('#containersList').html(html);
                    
                    $('.update-customs').off('click').on('click', function() {
                        $('#customsId').val($(this).data('id'));
                        $('#customsContainerNumber').text($(this).data('number'));
                        $('#customsStatus').val($(this).data('status'));
                        $('#customsModal').modal('show');
                    });
                } else {
                    $('#containersList').html('<div class="empty-state"><i class="fas fa-box"></i><p>Ma jiraan wax kontayner ah</p></div>');
                }
            }
        });
    }
    
    function loadPendingShipments() {
        let data = { ajax_action: 'get_pending_shipments' };
        <?php if ($role === 'superadmin'): ?>
        if ($('#tenantFilter').val() && $('#tenantFilter').val() != '0') data.tenant = $('#tenantFilter').val();
        <?php endif; ?>
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(res) {
                $('#pendingShipmentsList').html(res.html);
                $('.btn-receive-pending').off('click').on('click', function() {
                    let id = $(this).data('id');
                    let name = $(this).data('name');
                    $('#receiveId').val(id);
                    $('#receiveItemName').text(name);
                    $('#receiveLocation').val('');
                    $('#receiveBinLocation').val('');
                    $('#receiveZone').val('');
                    $('#receiveModal').modal('show');
                });
            }
        });
    }
    
    function attachTableEvents() {
        $('.view-item').off('click').on('click', function() {
            let id = $(this).data('id');
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_item', id: id },
                dataType: 'json',
                success: function(res) {
                    let item = res.item;
                    let statusName = item.mogadishu_status === 'not_arrived' ? 'Aan Imaanin' : (item.mogadishu_status === 'in_warehouse' ? 'Bakhaarka' : (item.mogadishu_status === 'taken' ? 'La Qaaday' : 'La Gaarsiiyay'));
                    let movementsHtml = '';
                    if (res.movements && res.movements.length > 0) {
                        movementsHtml = '<div class="info-grid mt-3"><div class="info-item"><label>Dhaqdhaqaaqa Bakhaarka</label><div style="max-height: 200px; overflow-y: auto;">';
                        for (let m of res.movements) {
                            let type = m.movement_type === 'in' ? 'Soo Galitaan' : (m.movement_type === 'out' ? 'Bixitaan' : (m.movement_type === 'move' ? 'Wareeji' : 'Hagaajin'));
                            movementsHtml += `<div style="padding: 5px 0; border-bottom: 1px solid #eee;">
                                <span class="badge badge-info">${type}</span> ${m.quantity_change} qayb - ${new Date(m.created_at).toLocaleString()}
                            </div>`;
                        }
                        movementsHtml += '</div></div></div>';
                    }
                    $('#viewModalBody').html(`
                        <div class="info-grid">
                            <div class="info-item"><label>Magaca Alaabta</label><div class="value">${escapeHtml(item.stock_name)}</div></div>
                            <div class="info-item"><label>Macmiilka</label><div class="value">${escapeHtml(item.customer_name || '-')} (${escapeHtml(item.customer_phone || '-')})</div></div>
                            <div class="info-item"><label>Tirada</label><div class="value">${item.quantity || 0}</div></div>
                            <div class="info-item"><label>Volume (CBM)</label><div class="value">${parseFloat(item.volume_cbm || 0).toFixed(4)}</div></div>
                            <div class="info-item"><label>Dimensions</label><div class="value">${item.length_cm || 0} x ${item.width_cm || 0} x ${item.height_cm || 0} cm</div></div>
                            <div class="info-item"><label>Qiimaha Unit-ka</label><div class="value">$${parseFloat(item.unit_price || 0).toFixed(2)}</div></div>
                            <div class="info-item"><label>Goobta Bakhaarka</label><div class="value">${escapeHtml(item.location || '-')} | Bin: ${escapeHtml(item.bin_location || '-')} | Zone: ${escapeHtml(item.zone || '-')}</div></div>
                            <div class="info-item"><label>Xaaladda</label><div class="value">${statusName}</div></div>
                            <div class="info-item"><label>Maalinta la Helay</label><div class="value">${item.mogadishu_received_date ? new Date(item.mogadishu_received_date).toLocaleString() : '-'}</div></div>
                            <div class="info-item"><label>Maalinta la Qaaday</label><div class="value">${item.mogadishu_taken_date ? new Date(item.mogadishu_taken_date).toLocaleString() : '-'}</div></div>
                            <div class="info-item"><label>Kharashka Kaydinta</label><div class="value">$${parseFloat(item.storage_fee || 0).toFixed(2)}</div></div>
                            <div class="info-item"><label>Shirkadda</label><div class="value">${escapeHtml(item.tenant_name || '-')}</div></div>
                        </div>
                        ${movementsHtml}
                    `);
                    $('#viewModal').modal('show');
                }
            });
        });
        
        $('.receive-item').off('click').on('click', function() {
            let id = $(this).data('id');
            let name = $(this).data('name');
            $('#receiveId').val(id);
            $('#receiveItemName').text(name);
            $('#receiveLocation').val('');
            $('#receiveBinLocation').val('');
            $('#receiveZone').val('');
            $('#receiveModal').modal('show');
        });
        
        $('.release-item').off('click').on('click', function() {
            let id = $(this).data('id');
            let name = $(this).data('name');
            $('#releaseId').val(id);
            $('#releaseItemName').text(name);
            $('#releaseStorageFee').val('');
            $('#releaseNotes').val('');
            $('#releaseModal').modal('show');
        });
        
        $('.edit-item').off('click').on('click', function() {
            let id = $(this).data('id');
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_item', id: id },
                dataType: 'json',
                success: function(res) {
                    let item = res.item;
                    $('#editId').val(item.id);
                    $('#editStockName').val(item.stock_name);
                    $('#editCustomerId').val(item.customer_id);
                    $('#editOrigin').val(item.origin || 'china_yiwu');
                    $('#editQuantity').val(item.quantity);
                    $('#editLength').val(item.length_cm);
                    $('#editWidth').val(item.width_cm);
                    $('#editHeight').val(item.height_cm);
                    $('#editVolume').val(item.volume_cbm);
                    $('#editUnitPrice').val(item.unit_price);
                    $('#editLocation').val(item.location);
                    $('#editBinLocation').val(item.bin_location);
                    $('#editZone').val(item.zone);
                    $('#editModal').modal('show');
                }
            });
        });
        
        $('.pagination a').off('click').on('click', function(e) {
            e.preventDefault();
            if ($(this).data('page')) {
                currentPage = $(this).data('page');
                loadWarehouseItems();
            }
        });
    }
    
    $('#receiveForm').submit(function(e) {
        e.preventDefault();
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $(this).serialize() + '&ajax_action=receive_item',
            dataType: 'json',
            success: function(r) {
                $('#receiveModal').modal('hide');
                if (r.success) {
                    showAlert('success', r.message);
                    loadWarehouseItems();
                    loadStats();
                } else {
                    showAlert('error', r.message);
                }
            }
        });
    });
    
    $('#releaseForm').submit(function(e) {
        e.preventDefault();
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $(this).serialize() + '&ajax_action=release_item',
            dataType: 'json',
            success: function(r) {
                $('#releaseModal').modal('hide');
                if (r.success) {
                    showAlert('success', r.message);
                    loadWarehouseItems();
                    loadStats();
                } else {
                    showAlert('error', r.message);
                }
            }
        });
    });
    
    $('#editForm').submit(function(e) {
        e.preventDefault();
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $(this).serialize() + '&ajax_action=update_item',
            dataType: 'json',
            success: function(r) {
                $('#editModal').modal('hide');
                if (r.success) {
                    showAlert('success', r.message);
                    loadWarehouseItems();
                    loadStats();
                } else {
                    showAlert('error', r.message);
                }
            }
        });
    });
    
    $('#customsForm').submit(function(e) {
        e.preventDefault();
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $(this).serialize() + '&ajax_action=update_container_customs',
            dataType: 'json',
            success: function(r) {
                $('#customsModal').modal('hide');
                if (r.success) {
                    showAlert('success', r.message);
                    loadContainers();
                    loadStats();
                } else {
                    showAlert('error', r.message);
                }
            }
        });
    });
    
    // Auto-calculate volume
    function calculateVolume() {
        let length = parseFloat($('#editLength').val()) || 0;
        let width = parseFloat($('#editWidth').val()) || 0;
        let height = parseFloat($('#editHeight').val()) || 0;
        let volume = (length * width * height) / 1000000;
        $('#editVolume').val(volume.toFixed(6));
    }
    
    $('#editLength, #editWidth, #editHeight').on('input', calculateVolume);
    
    $('#viewContainersBtn').click(function() {
        $('#containersModal').modal('show');
        loadContainers();
        loadPendingShipments();
    });
    
    $('#refreshBtn').click(function() {
        loadWarehouseItems();
        loadStats();
        showAlert('info', 'Xogta waa la cusboonaysiiyay!');
    });
    
    $('#applyFilters').click(function() { currentPage = 1; loadWarehouseItems(); loadStats(); });
    $('#resetFilters').click(function() {
        $('#searchInput').val('');
        <?php if ($role === 'superadmin'): ?>$('#tenantFilter').val('0');<?php endif; ?>
        $('#statusFilter').val('');
        currentPage = 1;
        loadWarehouseItems();
        loadStats();
    });
    
    function showAlert(t, m) {
        $('#alert-placeholder').html(`<div class="alert alert-${t} alert-dismissible fade show"><i class="fas ${t==='success'?'fa-check-circle':t==='info'?'fa-info-circle':'fa-exclamation-circle'}"></i> ${m}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
        setTimeout(() => $('.alert').fadeOut(3000, function() { $(this).remove(); }), 5000);
    }
    
    function escapeHtml(t) {
        if (!t) return '';
        return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    
    loadWarehouseItems();
    loadStats();
});
</script>
</body>
</html>