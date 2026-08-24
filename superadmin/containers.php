<?php
// superadmin/containers.php
// Maareynta Kontaynerada -faras cargo Super Admin

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

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Super Admin';

// ── Ensure warehouse columns exist before any query references them ──────────
$_col_patches = [
    "ALTER TABLE containers MODIFY COLUMN status
         ENUM('received','loading','loaded','shipped','dispatched','at_port','ready','delivered') DEFAULT 'received'",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS container_type ENUM('20ft','40ft','40hc','lcl') DEFAULT '20ft'",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS weight_kg DECIMAL(15,2) DEFAULT 0.00",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS current_location VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS arrival_date DATE DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS departure_date DATE DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS estimated_arrival DATE DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS tracking_number VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS seal_number VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS notes TEXT DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS shipping_line VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS bl_number VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS vessel_name VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS port_of_loading VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS port_of_discharge VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS eta_port DATE DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS etd_port DATE DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS customs_status ENUM('pending','cleared','held') DEFAULT 'pending'",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS created_by INT(11) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS current_branch_id INT(11) DEFAULT NULL",
    
    "ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS mogadishu_status
         ENUM('not_arrived','in_warehouse','taken','delivered') NOT NULL DEFAULT 'not_arrived'",
    "ALTER TABLE warehouse_stock MODIFY COLUMN mogadishu_status
         ENUM('not_arrived','in_warehouse','taken','delivered') NOT NULL DEFAULT 'not_arrived'",
    "ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS mogadishu_received_date DATETIME DEFAULT NULL",
    "ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS mogadishu_taken_date    DATETIME DEFAULT NULL",
    "ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS storage_fee             DECIMAL(15,2) DEFAULT 0.00",

    "ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS mogadishu_status
         ENUM('not_arrived','in_warehouse','taken','delivered') NOT NULL DEFAULT 'not_arrived'",
    "ALTER TABLE cargo_manifest_items MODIFY COLUMN mogadishu_status
         ENUM('not_arrived','in_warehouse','taken','delivered') NOT NULL DEFAULT 'not_arrived'",
    "ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS mogadishu_received_date DATETIME DEFAULT NULL",
    "ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS mogadishu_taken_date    DATETIME DEFAULT NULL",
    "ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS storage_fee             DECIMAL(15,2) DEFAULT 0.00",
    "ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS weight_kg               DECIMAL(15,2) DEFAULT 0.00",
    "ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS unit_price              DECIMAL(15,2) DEFAULT 0.00"
];
foreach ($_col_patches as $_cp) {
    try { $pdo->exec($_cp); } catch (PDOException $e) { /* ignore */ }
}
unset($_col_patches, $_cp);

// Get all tenants for filter dropdown
$tenants = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM tenants ORDER BY name");
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tenants = [];
}

// Get branches for selected tenant (AJAX)
if (isset($_GET['get_branches']) && isset($_GET['tenant_id'])) {
    header('Content-Type: application/json');
    $tenant_id = (int)$_GET['tenant_id'];
    try {
        $stmt = $pdo->prepare("SELECT id, branch_name, branch_type, branch_code FROM branches WHERE tenant_id = ? AND status = 'active' ORDER BY branch_name");
        $stmt->execute([$tenant_id]);
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'branches' => $branches]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Container type to CBM mapping
$container_cbm_map = [
    '20ft' => 33.2,
    '40ft' => 67.6,
    '40hc' => 76.3,
    'lcl' => 0
];

// Status definitions
$status_names = [
    'received' => 'La Helay',
    'loading' => 'La Rarayaa',
    'loaded' => 'La Raray',
    'shipped' => 'La Soo Raray',
    'dispatched' => 'La Diray',
    'at_port' => 'Dekedda',
    'ready' => 'Diyaar',
    'delivered' => 'La Gaarsiiyay'
];

$status_colors = [
    'received' => '#17a2b8',
    'loading' => '#ffc107',
    'loaded' => '#28a745',
    'shipped' => '#6f42c1',
    'dispatched' => '#fd7e14',
    'at_port' => '#6f42c1',
    'ready' => '#28a745',
    'delivered' => '#20c997'
];

$customs_status_names = [
    'pending' => 'La Sugayo',
    'cleared' => 'La Safay',
    'held' => 'La Qabtay'
];

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];
    
    if ($action === 'get_containers') {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $search = $_POST['search'] ?? '';
        $tenant_filter = isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0;
        $branch_filter = isset($_POST['branch']) ? (int)$_POST['branch'] : 0;
        $status_filter = $_POST['status'] ?? '';
        
        $where_conditions = [];
        $params = [];
        
        if (!empty($search)) {
            $where_conditions[] = "(c.container_number LIKE ? OR c.tracking_number LIKE ? OR c.bl_number LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($tenant_filter > 0) {
            $where_conditions[] = "c.tenant_id = ?";
            $params[] = $tenant_filter;
        }
        
        if ($branch_filter > 0) {
            $where_conditions[] = "c.current_branch_id = ?";
            $params[] = $branch_filter;
        }
        
        if (!empty($status_filter)) {
            $where_conditions[] = "c.status = ?";
            $params[] = $status_filter;
        }
        
        $where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);
        
        $count_sql = "SELECT COUNT(*) as total FROM containers c
                      LEFT JOIN tenants t ON c.tenant_id = t.id
                      $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_containers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_containers / $limit);
        
        $sql = "
            SELECT c.*, 
                   t.name as tenant_name,
                   b.branch_name as branch_name
            FROM containers c
            LEFT JOIN tenants t ON c.tenant_id = t.id
            LEFT JOIN branches b ON c.current_branch_id = b.id
            $where_clause
            ORDER BY c.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate table HTML
        ob_start(); ?>
        <div style="overflow-x: auto; width: 100%;">
            <table class="containers-table" style="min-width: 1200px; width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f6f9;">
                        <th style="padding: 12px;">ID</th>
                        <th style="padding: 12px;">Lambarka Kontaynerka</th>
                        <th style="padding: 12px;">Nooca</th>
                        <th style="padding: 12px;">CBM</th>
                        <th style="padding: 12px;">Xaaladda</th>
                        <th style="padding: 12px;">Laanta</th>
                        <th style="padding: 12px;">Safarkii Ugu Dambeeyay</th>
                        <th style="padding: 12px;">Shirkadda</th>
                        <th style="padding: 12px;">Lambarka BL</th>
                        <th style="padding: 12px;">Hawlaha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($containers) > 0): ?>
                        <?php foreach ($containers as $container): 
                            $statusColor = $status_colors[$container['status']] ?? '#6c757d';
                            $statusName = $status_names[$container['status']] ?? ucfirst($container['status']);
                            $containerType = $container['container_type'] ?? '20ft';
                            // Get last trip for this container
                            $tripStmt = $pdo->prepare("SELECT trip_number, status FROM trucking_trips WHERE container_id = ? ORDER BY created_at DESC LIMIT 1");
                            $tripStmt->execute([$container['id']]);
                            $lastTrip = $tripStmt->fetch(PDO::FETCH_ASSOC);
                            $trackingNumber = $container['tracking_number'] ?? '';
                            $isLocked = in_array($container['status'], ['ready', 'delivered']);
                        ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px;"><?= $container['id'] ?></td>
                                <td style="padding: 12px;">
                                    <strong><?= htmlspecialchars($container['container_number']) ?></strong>
                                    <div style="font-size: 11px; color: #6c757d;">
                                        <i class="fas fa-calendar-alt"></i> La sameeyay: <?= date('d/m/Y', strtotime($container['created_at'])) ?>
                                    </div>
                                </td>
                                <td style="padding: 12px;"><?= $containerType ?></td>
                                <td style="padding: 12px;"><?= number_format((float)($container['size_cbm'] ?? 0), 2) ?> CBM</td>
                                <td style="padding: 12px;">
                                    <span class="status-badge" style="background: <?= $statusColor ?>20; color: <?= $statusColor ?>; padding: 4px 10px; border-radius: 20px; font-size: 11px;">
                                        <?= $statusName ?>
                                    </span>
                                    <?php if ($container['status'] === 'ready'): ?>
                                        <div style="font-size:10px; color:#28a745; font-weight:600; margin-top:3px;">
                                            <i class="fas fa-check-circle"></i> La diray Bakhaarka
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($container['customs_status'] === 'cleared'): ?>
                                        <div style="font-size:9px; color:#17a2b8;">🛃 Kastamka waa la safay</div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px;">
                                    <?php if (!empty($container['branch_name'])): ?>
                                        <span style="font-size: 12px;">
                                            <i class="fas fa-store"></i> <?= htmlspecialchars($container['branch_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px;">
                                    <?php if ($lastTrip && $lastTrip['trip_number']): ?>
                                        <a href="shipments.php?search=<?= urlencode($lastTrip['trip_number']) ?>" class="shipment-link" style="color: #1565c0; text-decoration: none;">
                                            <?= htmlspecialchars($lastTrip['trip_number']) ?>
                                        </a>
                                        <div style="font-size: 10px;"><?= $status_names[$lastTrip['status']] ?? $lastTrip['status'] ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">Aan la qoondeyn</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px;"><?= htmlspecialchars($container['tenant_name'] ?? '-') ?></td>
                                <td style="padding: 12px;">
                                    <?php if (!empty($container['bl_number'])): ?>
                                        <code style="font-size: 11px;"><?= htmlspecialchars($container['bl_number']) ?></code>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px;">
                                    <div class="action-buttons" style="display: flex; gap: 5px; flex-wrap: wrap;">
                                        <button class="action-btn btn-view view-container" data-id="<?= $container['id'] ?>" title="Faahfaahin"><i class="fas fa-eye"></i></button>
                                        <?php if (!empty($trackingNumber)): ?>
                                            <button class="action-btn btn-tracking" data-tracking="<?= htmlspecialchars($trackingNumber) ?>" data-number="<?= htmlspecialchars($container['container_number']) ?>" title="Raadraac">
                                                <i class="fas fa-map-marker-alt"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button class="action-btn btn-track" onclick="window.sendWhatsAppToAll(<?= $container['id'] ?>)" title="WhatsApp Notify All"><i class="fab fa-whatsapp"></i></button>
                                        <?php if (!$isLocked): ?>
                                            <button class="action-btn btn-edit edit-container" data-id="<?= $container['id'] ?>" title="Wax Ka Beddel"><i class="fas fa-edit"></i></button>
                                            <button class="action-btn btn-status update-status" data-id="<?= $container['id'] ?>" data-status="<?= $container['status'] ?>" title="Cusboonaysii Xaaladda"><i class="fas fa-exchange-alt"></i></button>
                                            <button class="action-btn btn-delete delete-container" data-id="<?= $container['id'] ?>" data-name="<?= htmlspecialchars($container['container_number']) ?>" title="Tirtir"><i class="fas fa-trash"></i></button>
                                        <?php else: ?>
                                            <button class="action-btn btn-view" disabled style="opacity:0.5;" title="Ma bedeli karo"><i class="fas fa-lock"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 50px;">
                                <div class="empty-state" style="text-align: center;">
                                    <i class="fas fa-box" style="font-size: 48px; opacity: 0.5;"></i>
                                    <p>Ma jiraan wax kontayner ah</p>
                                    <button class="btn-primary-custom" id="addContainerBtnEmpty" style="margin-top: 10px;">
                                        <i class="fas fa-plus-circle"></i> Kontayner Cusub
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
        
        ob_start();
        if ($total_pages > 1): ?>
            <div class="pagination" style="display: flex; justify-content: center; gap: 8px; margin-top: 25px;">
                <?php if ($page > 1): ?>
                    <a data-page="<?= $page-1 ?>" style="padding: 8px 14px; border-radius: 8px; background: white; border: 1px solid #ddd; cursor: pointer;"><i class="fas fa-chevron-left"></i> Hore</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active" style="padding: 8px 14px; border-radius: 8px; background: #2D1859; color: white; border-color: #2D1859;"><?= $i ?></span>
                    <?php else: ?>
                        <a data-page="<?= $i ?>" style="padding: 8px 14px; border-radius: 8px; background: white; border: 1px solid #ddd; cursor: pointer;"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a data-page="<?= $page+1 ?>" style="padding: 8px 14px; border-radius: 8px; background: white; border: 1px solid #ddd; cursor: pointer;">Danbe <i class="fas fa-chevron-right"></i></a>
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
    
    elseif ($action === 'get_container') {
        $id = $_POST['id'] ?? 0;
        
        try {
            $stmt = $pdo->prepare("
                SELECT c.*, t.name as tenant_name, b.branch_name as branch_name
                FROM containers c
                LEFT JOIN tenants t ON c.tenant_id = t.id
                LEFT JOIN branches b ON c.current_branch_id = b.id
                WHERE c.id = ?
            ");
            $stmt->execute([$id]);
            $container = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $manifestStmt = $pdo->prepare("
                SELECT 
                    cmi.id,
                    cust.customer_name,
                    cust.phone,
                    cmi.quantity as total_packages,
                    cmi.cbm_used as total_cbm,
                    COALESCE(cmi.unit_price, ws.unit_price) as cbm_price,
                    (cmi.cbm_used * COALESCE(cmi.unit_price, ws.unit_price)) as total_price,
                    cmi.stock_name as items_list,
                    cmi.added_at,
                    cmi.weight_kg,
                    cmi.storage_fee,
                    ws.id as stock_id
                FROM cargo_manifest_items cmi
                LEFT JOIN warehouse_stock ws ON cmi.warehouse_stock_id = ws.id
                LEFT JOIN customers cust ON ws.customer_id = cust.id
                WHERE cmi.container_id = ?
                ORDER BY cmi.added_at DESC
            ");
            $manifestStmt->execute([$id]);
            $manifest = $manifestStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'container' => $container,
                'manifest' => $manifest
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'success' => false, 
                'message' => 'Khalad: ' . $e->getMessage()
            ]);
        }
        exit;
    }

    elseif ($action === 'remove_manifest_item') {
        $id = $_POST['id'] ?? 0;
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT warehouse_stock_id, quantity, container_id FROM cargo_manifest_items WHERE id = ?");
            $stmt->execute([$id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($item) {
                $upd = $pdo->prepare("UPDATE warehouse_stock SET quantity = quantity + ? WHERE id = ?");
                $upd->execute([$item['quantity'], $item['warehouse_stock_id']]);
                
                $del = $pdo->prepare("DELETE FROM cargo_manifest_items WHERE id = ?");
                $del->execute([$id]);
                
                // Update container CBM total
                $cbmStmt = $pdo->prepare("SELECT COALESCE(SUM(cbm_used), 0) as total_cbm FROM cargo_manifest_items WHERE container_id = ?");
                $cbmStmt->execute([$item['container_id']]);
                $totalCbm = $cbmStmt->fetch(PDO::FETCH_ASSOC)['total_cbm'];
                
                $updateContainer = $pdo->prepare("UPDATE containers SET size_used_cbm = ? WHERE id = ?");
                $updateContainer->execute([$totalCbm, $item['container_id']]);
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Alaabta waa laga saaray kontaynerka, waxaana lagu celiyay bakhaarka.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Alaabta lama helin.']);
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }

    elseif ($action === 'delete_manifest_item') {
        $id = $_POST['id'] ?? 0;
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT container_id FROM cargo_manifest_items WHERE id = ?");
            $stmt->execute([$id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $del = $pdo->prepare("DELETE FROM cargo_manifest_items WHERE id = ?");
            $del->execute([$id]);
            
            if ($item) {
                $cbmStmt = $pdo->prepare("SELECT COALESCE(SUM(cbm_used), 0) as total_cbm FROM cargo_manifest_items WHERE container_id = ?");
                $cbmStmt->execute([$item['container_id']]);
                $totalCbm = $cbmStmt->fetch(PDO::FETCH_ASSOC)['total_cbm'];
                
                $updateContainer = $pdo->prepare("UPDATE containers SET size_used_cbm = ? WHERE id = ?");
                $updateContainer->execute([$totalCbm, $item['container_id']]);
            }
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Alaabta waa laga masaxay kontaynerka si joogto ah.']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }

    elseif ($action === 'set_container_full') {
        $id = $_POST['id'] ?? 0;
        try {
            $pdo->beginTransaction();
            
            $upd = $pdo->prepare("UPDATE containers SET status = 'ready', updated_at = NOW() WHERE id = ?");
            $upd->execute([$id]);
            
            $pushSql = "
                UPDATE cargo_manifest_items
                SET mogadishu_status        = 'in_warehouse',
                    mogadishu_received_date = NOW()
                WHERE container_id = ?
            ";
            $pdo->prepare($pushSql)->execute([$id]);

            $wsPushSql = "
                UPDATE warehouse_stock ws
                JOIN cargo_manifest_items cmi ON cmi.warehouse_stock_id = ws.id
                SET ws.mogadishu_status        = 'in_warehouse',
                    ws.mogadishu_received_date = NOW()
                WHERE cmi.container_id = ?
            ";
            $pdo->prepare($wsPushSql)->execute([$id]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Kontaynerka waa la BUUXIYAY, dhammaan alaabtiisana waxaa loo diray Bakhaarka Muqdisho.']);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }

    elseif ($action === 'set_container_open') {
        $id = $_POST['id'] ?? 0;
        try {
            $pdo->beginTransaction();
            
            $upd = $pdo->prepare("UPDATE containers SET status = 'received', updated_at = NOW() WHERE id = ?");
            $upd->execute([$id]);
            
            $resetSql = "
                UPDATE cargo_manifest_items
                SET mogadishu_status        = 'not_arrived',
                    mogadishu_received_date = NULL
                WHERE container_id = ? AND mogadishu_status != 'taken'
            ";
            $pdo->prepare($resetSql)->execute([$id]);

            $wsResetSql = "
                UPDATE warehouse_stock ws
                JOIN cargo_manifest_items cmi ON cmi.warehouse_stock_id = ws.id
                SET ws.mogadishu_status        = 'not_arrived',
                    ws.mogadishu_received_date = NULL
                WHERE cmi.container_id = ? AND ws.mogadishu_status != 'taken'
            ";
            $pdo->prepare($wsResetSql)->execute([$id]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Kontaynerka dib ayaa loo furay, alaabtiisana waa laga saaray Bakhaarka Muqdisho.']);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'save_container') {
        $id = $_POST['container_id'] ?? '';
        $tenant_id = !empty($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : null;
        $container_number = trim($_POST['container_number'] ?? '');
        $container_type = $_POST['container_type'] ?? '20ft';
        $size_cbm = !empty($_POST['size_cbm']) ? (float)$_POST['size_cbm'] : ($container_cbm_map[$container_type] ?? 0);
        $weight_kg = (float)($_POST['weight_kg'] ?? 0);
        $status = $_POST['status'] ?? 'received';
        $current_location = trim($_POST['current_location'] ?? '');
        $current_branch_id = !empty($_POST['current_branch_id']) ? (int)$_POST['current_branch_id'] : null;
        $arrival_date = !empty($_POST['arrival_date']) ? $_POST['arrival_date'] : null;
        $departure_date = !empty($_POST['departure_date']) ? $_POST['departure_date'] : null;
        $estimated_arrival = !empty($_POST['estimated_arrival']) ? $_POST['estimated_arrival'] : null;
        $tracking_number = trim($_POST['tracking_number'] ?? '');
        $seal_number = trim($_POST['seal_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $shipping_line = trim($_POST['shipping_line'] ?? '');
        $bl_number = trim($_POST['bl_number'] ?? '');
        $vessel_name = trim($_POST['vessel_name'] ?? '');
        $port_of_loading = trim($_POST['port_of_loading'] ?? '');
        $port_of_discharge = trim($_POST['port_of_discharge'] ?? '');
        $eta_port = !empty($_POST['eta_port']) ? $_POST['eta_port'] : null;
        $etd_port = !empty($_POST['etd_port']) ? $_POST['etd_port'] : null;
        $customs_status = $_POST['customs_status'] ?? 'pending';
        
        if (empty($container_number)) {
            echo json_encode(['success' => false, 'message' => 'Fadlan geli lambarka kontaynerka']);
            exit;
        }
        
        if (empty($tenant_id)) {
            echo json_encode(['success' => false, 'message' => 'Fadlan dooro shirkadda iska leh']);
            exit;
        }
        
        try {
            if (empty($id)) {
                $check = $pdo->prepare("SELECT id FROM containers WHERE container_number = ? AND tenant_id = ?");
                $check->execute([$container_number, $tenant_id]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'message' => "Lambarka kontaynerka '$container_number' waxaa horay loo isticmaalay shirkaddan"]);
                    exit;
                }
                
                if (empty($tracking_number)) {
                    $tracking_number = 'TRK-' . date('Ymd') . '-' . rand(1000, 9999);
                }
                
                $sql = "INSERT INTO containers (
                    tenant_id, container_number, container_type, size_cbm, weight_kg, status,
                    current_location, current_branch_id, arrival_date, departure_date, estimated_arrival, tracking_number, 
                    seal_number, notes, shipping_line, bl_number, vessel_name, port_of_loading,
                    port_of_discharge, eta_port, etd_port, customs_status, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                )";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $tenant_id, $container_number, $container_type, $size_cbm, $weight_kg, $status,
                    $current_location, $current_branch_id, $arrival_date, $departure_date, $estimated_arrival, $tracking_number,
                    $seal_number, $notes, $shipping_line, $bl_number, $vessel_name, $port_of_loading,
                    $port_of_discharge, $eta_port, $etd_port, $customs_status, $_SESSION['user_id']
                ]);
                $container_id = $pdo->lastInsertId();
                
                $trip_number = 'TRP-' . date('ymd') . '-' . str_pad($container_id, 3, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare("INSERT INTO trucking_trips (tenant_id, container_id, trip_number, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
                $stmt->execute([$tenant_id, $container_id, $trip_number]);
                
                echo json_encode(['success' => true, 'message' => "Kontaynerka '$container_number' waa la kaydiyay!"]);
            } else {
                // Check if container is locked
                $checkLock = $pdo->prepare("SELECT status FROM containers WHERE id = ?");
                $checkLock->execute([$id]);
                $currentStatus = $checkLock->fetch(PDO::FETCH_ASSOC);
                
                if (in_array($currentStatus['status'], ['ready', 'delivered'])) {
                    echo json_encode(['success' => false, 'message' => 'Kontaynerkan lama bedeli karo sababtoo ah waa la diray ama la gaarsiiyay.']);
                    exit;
                }
                
                $sql = "UPDATE containers 
                        SET tenant_id=?, container_number=?, container_type=?, size_cbm=?, weight_kg=?, status=?,
                            current_location=?, current_branch_id=?, arrival_date=?, departure_date=?, estimated_arrival=?, tracking_number=?, 
                            seal_number=?, notes=?, shipping_line=?, bl_number=?, vessel_name=?, port_of_loading=?,
                            port_of_discharge=?, eta_port=?, etd_port=?, customs_status=?, updated_at=NOW()
                        WHERE id=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $tenant_id, $container_number, $container_type, $size_cbm, $weight_kg, $status,
                    $current_location, $current_branch_id, $arrival_date, $departure_date, $estimated_arrival, $tracking_number,
                    $seal_number, $notes, $shipping_line, $bl_number, $vessel_name, $port_of_loading,
                    $port_of_discharge, $eta_port, $etd_port, $customs_status, $id
                ]);
                
                echo json_encode(['success' => true, 'message' => "Kontaynerka '$container_number' waa la cusboonaysiiyay!"]);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'delete_container') {
        $id = $_POST['id'] ?? 0;
        try {
            // Check if container is locked
            $checkLock = $pdo->prepare("SELECT status, container_number FROM containers WHERE id = ?");
            $checkLock->execute([$id]);
            $container = $checkLock->fetch(PDO::FETCH_ASSOC);
            
            if (!$container) {
                echo json_encode(['success' => false, 'message' => 'Kontaynerka lama helin']);
                exit;
            }
            
            if (in_array($container['status'], ['ready', 'delivered'])) {
                echo json_encode(['success' => false, 'message' => "Kontaynerka '{$container['container_number']}' lama tirtiri karo sababtoo ah waa la diray ama la gaarsiiyay."]);
                exit;
            }
            
            $check = $pdo->prepare("SELECT COUNT(*) as count FROM trucking_trips WHERE container_id = ?");
            $check->execute([$id]);
            $shipment_count = $check->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($shipment_count > 0) {
                echo json_encode(['success' => false, 'message' => "Kontaynerkan waxaa ku xiran $shipment_count safar. Marka hore tirtir safarada."]);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM containers WHERE id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['success' => true, 'message' => "Kontaynerka '{$container['container_number']}' waa la tirtiray!"]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'update_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';

        $allowed_statuses = ['received', 'loading', 'loaded', 'shipped', 'dispatched', 'at_port', 'ready', 'delivered'];
        if (!in_array($status, $allowed_statuses)) {
            echo json_encode(['success' => false, 'message' => 'Xaalad aan la aqbalin']);
            exit;
        }

        try {
            $pdo->beginTransaction();
            
            // Check if container is locked
            $checkLock = $pdo->prepare("SELECT status FROM containers WHERE id = ?");
            $checkLock->execute([$id]);
            $currentStatus = $checkLock->fetch(PDO::FETCH_ASSOC);
            
            if (in_array($currentStatus['status'], ['ready', 'delivered'])) {
                echo json_encode(['success' => false, 'message' => 'Kontaynerkan lama bedeli karo xaaladdiisa sababtoo ah waa la diray ama la gaarsiiyay.']);
                $pdo->rollBack();
                exit;
            }

            $stmt = $pdo->prepare("UPDATE containers SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $id]);

            // Update trip status if needed
            if ($status === 'loaded') {
                $updateTrip = $pdo->prepare("UPDATE trucking_trips SET status = 'loaded', loaded_at = NOW() WHERE container_id = ? AND status = 'loading'");
                $updateTrip->execute([$id]);
            } elseif ($status === 'shipped') {
                $updateTrip = $pdo->prepare("UPDATE trucking_trips SET status = 'in_transit', departed_at = NOW() WHERE container_id = ?");
                $updateTrip->execute([$id]);
            } elseif ($status === 'delivered') {
                $updateTrip = $pdo->prepare("UPDATE trucking_trips SET status = 'delivered', delivered_at = NOW() WHERE container_id = ?");
                $updateTrip->execute([$id]);
            }

            if ($status === 'ready') {
                $pushSql = "
                    UPDATE cargo_manifest_items
                    SET mogadishu_status        = 'in_warehouse',
                        mogadishu_received_date = NOW()
                    WHERE container_id = ?
                ";
                $pushStmt = $pdo->prepare($pushSql);
                $pushStmt->execute([$id]);
                $pushed = $pushStmt->rowCount();

                $wsPushSql = "
                    UPDATE warehouse_stock ws
                    JOIN cargo_manifest_items cmi ON cmi.warehouse_stock_id = ws.id
                    SET ws.mogadishu_status        = 'in_warehouse',
                        ws.mogadishu_received_date = NOW()
                    WHERE cmi.container_id = ?
                ";
                $pdo->prepare($wsPushSql)->execute([$id]);

                $pdo->commit();
                echo json_encode([
                    'success' => true,
                    'message' => "Xaaladda kontaynerka waa la cusboonaysiiyay! $pushed alaab ayaa loo diyaariyay Bakhaarka Muqdisho.",
                    'pushed'  => $pushed
                ]);
            } else {
                $resetSql = "
                    UPDATE cargo_manifest_items
                    SET mogadishu_status        = 'not_arrived',
                        mogadishu_received_date = NULL
                    WHERE container_id = ? AND mogadishu_status != 'taken'
                ";
                $pdo->prepare($resetSql)->execute([$id]);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Xaaladda kontaynerka waa la cusboonaysiiyay!']);
            }

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'send_whatsapp_to_container') {
        $id = $_POST['id'] ?? 0;
        
        try {
            $stmt = $pdo->prepare("
                SELECT DISTINCT cust.phone, cust.customer_name, cust.id as customer_id
                FROM cargo_manifest_items cmi
                JOIN warehouse_stock ws ON cmi.warehouse_stock_id = ws.id
                JOIN customers cust ON ws.customer_id = cust.id
                WHERE cmi.container_id = ? AND cust.phone IS NOT NULL AND cust.phone != ''
            ");
            $stmt->execute([$id]);
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $containerStmt = $pdo->prepare("SELECT container_number, status, bl_number FROM containers WHERE id = ?");
            $containerStmt->execute([$id]);
            $container = $containerStmt->fetch(PDO::FETCH_ASSOC);
            
            $statusName = $status_names[$container['status']] ?? $container['status'];
            $message = "Assalaamu Calaykum,\n\nKontaynerka *{$container['container_number']}* xaaladeedu hadda waa: *{$statusName}*.\n";
            if (!empty($container['bl_number'])) {
                $message .= "Lambarka BL: *{$container['bl_number']}*\n";
            }
            $message .= "\nFadlan la soco xogta rarkaaga ama nagala soo xiriir.\n\nMahadsanid,\n*Cargo Management System*";
            
            echo json_encode([
                'success' => true,
                'customers' => $customers,
                'message' => $message,
                'container_number' => $container['container_number']
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'get_stats') {
        $tenant_filter = isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0;
        $branch_filter = isset($_POST['branch']) ? (int)$_POST['branch'] : 0;
        
        $where = "";
        $params = [];
        
        if ($tenant_filter > 0) {
            $where = "WHERE tenant_id = ?";
            $params[] = $tenant_filter;
            
            if ($branch_filter > 0) {
                $where .= " AND current_branch_id = ?";
                $params[] = $branch_filter;
            }
        } elseif ($branch_filter > 0) {
            $where = "WHERE current_branch_id = ?";
            $params[] = $branch_filter;
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) as received,
                SUM(CASE WHEN status = 'loading' THEN 1 ELSE 0 END) as loading,
                SUM(CASE WHEN status = 'loaded' THEN 1 ELSE 0 END) as loaded,
                SUM(CASE WHEN status = 'shipped' THEN 1 ELSE 0 END) as shipped,
                SUM(CASE WHEN status = 'dispatched' THEN 1 ELSE 0 END) as dispatched,
                SUM(CASE WHEN status = 'at_port' THEN 1 ELSE 0 END) as at_port,
                SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) as ready,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(size_cbm) as total_cbm,
                SUM(weight_kg) as total_weight
            FROM containers
            $where
        ");
        $stmt->execute($params);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($stats);
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
    <title>Maareynta Kontaynerada - Super Admin | Cargo Management System</title>
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
            --curdun-info: #1565c0;
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
            transform: translateY(-2px);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
            gap: 12px;
            margin-bottom: 25px;
        }
        .stat-card-sm {
            background: white;
            border-radius: 12px;
            padding: 10px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border-left: 3px solid var(--curdun-violet);
        }
        .stat-card-sm .stat-info h4 { font-size: 10px; color: var(--curdun-gray); margin: 0 0 3px 0; text-transform: uppercase; }
        .stat-card-sm .stat-info .stat-number { font-size: 18px; font-weight: 700; color: var(--curdun-violet); }
        .stat-card-sm .stat-icon { width: 32px; height: 32px; background: rgba(82,0,102,0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center; }
        .stat-card-sm .stat-icon i { font-size: 14px; color: var(--curdun-violet); }
        
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
        .btn-filter { background: var(--curdun-violet); color: white; border: none; padding: 8px 20px; border-radius: 8px; cursor: pointer; }
        .btn-reset { background: #f0f0f0; color: var(--curdun-dark); border: none; padding: 8px 20px; border-radius: 8px; margin-left: 10px; cursor: pointer; }
        
        .containers-table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            overflow-x: auto;
            width: 100%;
        }
        
        .action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
        .action-btn { padding: 5px 8px; border-radius: 6px; font-size: 11px; cursor: pointer; border: none; transition: all 0.3s ease; }
        .btn-view { background: #e8eaf6; color: #3949ab; }
        .btn-edit { background: #fff3e0; color: #e65100; }
        .btn-track { background: #e3f2fd; color: #1565c0; }
        .btn-status { background: #fff8e1; color: #ff8f00; }
        .btn-tracking { background: #EEFBF3; color: #0F7A3A; }
        .btn-delete { background: #FEF0EE; color: #B42318; }
        
        .alert { padding: 12px 20px; border-radius: 8px; position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .alert-success { background: #EEFBF3; color: #0F7A3A; border-left: 4px solid #0F7A3A; }
        .alert-error { background: #FEF0EE; color: #B42318; border-left: 4px solid #B42318; }
        
        .modal-header { background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light)); color: white; }
        .modal-header .close { color: white; opacity: 1; }
        
        .loading-spinner { text-align: center; padding: 50px; }
        .loading-spinner i { font-size: 48px; color: var(--curdun-violet); animation: spin 1s linear infinite; }
        .empty-state { text-align: center; padding: 50px; color: var(--curdun-gray); }
        .empty-state i { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }
        
        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: center; }
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        
        @media print {
            body * { visibility: hidden; }
            #viewModalBody, #viewModalBody * { visibility: visible; }
            #viewModalBody { position: absolute; left: 0; top: 0; width: 100%; }
            .modal-header, .modal-footer, .btn, .close, .action-btn { display: none !important; }
        }
        
        .form-group label { font-weight: 600; font-size: 13px; }
        .table th { font-size: 12px; }
        .table td { font-size: 12px; }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-box"></i> Maareynta Kontaynerada</h1>
        <button type="button" class="btn-primary-custom" id="addContainerBtn">
            <i class="fas fa-plus-circle"></i> Kontayner Cusub
        </button>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card-sm"><div class="stat-info"><h4>Wadarta Guud</h4><div class="stat-number" id="stat-total">0</div></div><div class="stat-icon"><i class="fas fa-box"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>La Helay</h4><div class="stat-number" id="stat-received">0</div></div><div class="stat-icon"><i class="fas fa-download"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>La Rarayaa</h4><div class="stat-number" id="stat-loading">0</div></div><div class="stat-icon"><i class="fas fa-spinner"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>La Raray</h4><div class="stat-number" id="stat-loaded">0</div></div><div class="stat-icon"><i class="fas fa-truck-loading"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>La Diray</h4><div class="stat-number" id="stat-dispatched">0</div></div><div class="stat-icon"><i class="fas fa-paper-plane"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>Dekedda</h4><div class="stat-number" id="stat-at_port">0</div></div><div class="stat-icon"><i class="fas fa-ship"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>Diyaar</h4><div class="stat-number" id="stat-ready">0</div></div><div class="stat-icon"><i class="fas fa-check"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>La Gaarsiiyay</h4><div class="stat-number" id="stat-delivered">0</div></div><div class="stat-icon"><i class="fas fa-flag-checkered"></i></div></div>
    </div>

    <!-- Filters -->
    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group"><label><i class="fas fa-search"></i> Raadin</label><input type="text" id="searchInput" placeholder="Raadi kontaynerka, Lambarka Raadraaca, BL..."></div>
            <div class="filter-group"><label><i class="fas fa-building"></i> Shirkadda</label><select id="tenantFilter"><option value="0">Dhammaan Shirkadaha</option><?php foreach ($tenants as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?></select></div>
            <div class="filter-group"><label><i class="fas fa-store"></i> Laanta / Goobta</label><select id="branchFilter"><option value="0">Marka hore dooro shirkad</option></select></div>
            <div class="filter-group"><label><i class="fas fa-tag"></i> Xaaladda</label><select id="statusFilter"><option value="">Dhammaan</option><option value="received">La Helay</option><option value="loading">La Rarayaa</option><option value="loaded">La Raray</option><option value="shipped">La Soo Raray</option><option value="dispatched">La Diray</option><option value="at_port">Dekedda</option><option value="ready">Diyaar</option><option value="delivered">La Gaarsiiyay</option></select></div>
            <div class="filter-group"><button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Shaandheey</button><button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Nadiifi</button></div>
        </div>
    </div>

    <div id="containers-table-container"><div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i><p>Loading containers...</p></div></div>
    <div id="pagination-container"></div>
</div>

<!-- Create/Edit Container Modal -->
<div class="modal fade" id="containerModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="containerModalLabel"><i class="fas fa-box"></i> Kontayner Cusub</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="containerForm">
                <div class="modal-body">
                    <input type="hidden" name="container_id" id="container_id">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Shirkadda Iska Leh <span class="text-danger">*</span></label>
                                <select name="tenant_id" id="modalTenantId" class="form-control" required>
                                    <option value="">-- Dooro Shirkad --</option>
                                    <?php foreach ($tenants as $t): ?>
                                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Lambarka Kontaynerka <span class="text-danger">*</span></label>
                                <input type="text" name="container_number" id="modalContainerNumber" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Nooca Kontaynerka</label>
                                <select name="container_type" id="modalContainerType" class="form-control">
                                    <option value="20ft">20 FT (33.2 CBM)</option>
                                    <option value="40ft">40 FT (67.6 CBM)</option>
                                    <option value="40hc">40 HC (76.3 CBM)</option>
                                    <option value="lcl">LCL (Mug gaar ah)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Cabirka (CBM)</label>
                                <input type="number" step="0.01" name="size_cbm" id="modalSizeCbm" class="form-control" value="0">
                                <small class="text-muted">Haddii LCL ah, qor mugga saxda ah</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Culmis (KG)</label>
                                <input type="number" step="1" name="weight_kg" id="modalWeightKg" class="form-control" value="0">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Xaaladda</label>
                                <select name="status" id="modalStatus" class="form-control">
                                    <option value="received">La Helay</option>
                                    <option value="loading">La Rarayaa</option>
                                    <option value="loaded">La Raray</option>
                                    <option value="shipped">La Soo Raray</option>
                                    <option value="dispatched">La Diray</option>
                                    <option value="at_port">Dekedda</option>
                                    <option value="ready">Diyaar</option>
                                    <option value="delivered">La Gaarsiiyay</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Xaaladda Kastamka</label>
                                <select name="customs_status" id="modalCustomsStatus" class="form-control">
                                    <option value="pending">La Sugayo</option>
                                    <option value="cleared">La Safay</option>
                                    <option value="held">La Qabtay</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Laanta / Goobta</label>
                                <select name="current_branch_id" id="modalCurrentBranchId" class="form-control">
                                    <option value="">-- Dooro Laanta --</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Goobta Hadda Joogo (Text)</label>
                                <input type="text" name="current_location" id="modalCurrentLocation" class="form-control" placeholder="Mogadishu, Hargeisa...">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Taariikhda La Helay</label>
                                <input type="date" name="arrival_date" id="modalArrivalDate" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Taariikhda La Diray</label>
                                <input type="date" name="departure_date" id="modalDepartureDate" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Taariikhda La Filayo</label>
                                <input type="date" name="estimated_arrival" id="modalEstimatedArrival" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Lambarka Raadraaca (Tracking)</label>
                                <input type="text" name="tracking_number" id="modalTrackingNumber" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>ETA Dekedda</label>
                                <input type="date" name="eta_port" id="modalEtaPort" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>ETD Dekedda</label>
                                <input type="date" name="etd_port" id="modalEtdPort" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Lambarka Seal</label>
                                <input type="text" name="seal_number" id="modalSealNumber" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Lambarka BL</label>
                                <input type="text" name="bl_number" id="modalBlNumber" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Magaca Markabka (Vessel)</label>
                                <input type="text" name="vessel_name" id="modalVesselName" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Shirkadda Markabka (Shipping Line)</label>
                                <input type="text" name="shipping_line" id="modalShippingLine" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Dekedda Laga Soo Raray (POL)</label>
                                <input type="text" name="port_of_loading" id="modalPortOfLoading" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Dekedda Lagu Soo Dejinayo (POD)</label>
                                <input type="text" name="port_of_discharge" id="modalPortOfDischarge" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Faahfaahin Dheeraad ah / Qoraal</label>
                                <textarea name="notes" id="modalNotes" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Kaalay / Xir</button>
                    <button type="submit" class="btn btn-primary-custom">Kaydi Kontaynerka</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Container Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-box"></i> Faahfaahinta Kontaynerka</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Xir</button>
            </div>
        </div>
    </div>
</div>

<!-- Status Update Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exchange-alt"></i> Cusboonaysii Xaaladda</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="statusForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="statusContainerId">
                    <div class="form-group">
                        <label>Xaaladda Cusub</label>
                        <select name="status" id="statusNewStatus" class="form-control">
                            <option value="received">La Helay</option>
                            <option value="loading">La Rarayaa</option>
                            <option value="loaded">La Raray</option>
                            <option value="shipped">La Soo Raray</option>
                            <option value="dispatched">La Diray</option>
                            <option value="at_port">Dekedda</option>
                            <option value="ready">Diyaar</option>
                            <option value="delivered">La Gaarsiiyay</option>
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

<!-- WhatsApp Customer List Modal -->
<div class="modal fade" id="whatsappModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fab fa-whatsapp"></i> Ku dir WhatsApp Macaamiisha</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="whatsappModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Xir</button>
            </div>
        </div>
    </div>
</div>

<!-- Generic Confirmation Modal -->
<div class="modal fade" id="confirmActionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Hubi Hawshan</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <p id="confirmActionMessage"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Maya</button>
                <button type="button" class="btn btn-primary" id="confirmActionBtn">Haa, Samee</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
let currentPage = 1;
let deleteId = null;

function escapeHtml(text) {
    if (!text) return '';
    return text.toString().replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function formatDate(dateString) {
    if (!dateString) return '';
    let date = new Date(dateString);
    let year = date.getFullYear();
    let month = String(date.getMonth() + 1).padStart(2, '0');
    let day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function showAlert(type, msg) {
    const placeholder = $('#alert-placeholder');
    if (placeholder.length) {
        placeholder.html(`<div class="alert alert-${type} alert-dismissible fade show"><i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
        setTimeout(() => $('.alert').fadeOut(5000, function() { $(this).remove(); }), 5000);
    } else {
        alert(msg);
    }
}

function loadBranches(tenantId, targetSelectId, selectedValue = null) {
    if (!tenantId || tenantId == 0) {
        $(`#${targetSelectId}`).html('<option value="">-- Marka hore dooro shirkad --</option>').prop('disabled', true);
        return;
    }
    
    $(`#${targetSelectId}`).html('<option value="">Loading...</option>').prop('disabled', false);
    
    $.ajax({
        url: window.location.href,
        type: 'GET',
        data: { get_branches: 1, tenant_id: tenantId },
        dataType: 'json',
        success: function(res) {
            if (res.success && res.branches && res.branches.length > 0) {
                let options = '<option value="">-- Dooro Laanta --</option>';
                res.branches.forEach(branch => {
                    let selected = (selectedValue && selectedValue == branch.id) ? 'selected' : '';
                    options += `<option value="${branch.id}" ${selected}>${escapeHtml(branch.branch_name)} (${branch.branch_type})</option>`;
                });
                $(`#${targetSelectId}`).html(options);
            } else {
                $(`#${targetSelectId}`).html('<option value="">Ma jiraan laamo</option>');
            }
        },
        error: function() {
            $(`#${targetSelectId}`).html('<option value="">Khalad ayaa dhacay</option>');
        }
    });
}

function loadContainers() {
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: {
            ajax_action: 'get_containers',
            page: currentPage,
            search: $('#searchInput').val(),
            tenant: $('#tenantFilter').val(),
            branch: $('#branchFilter').val(),
            status: $('#statusFilter').val()
        },
        dataType: 'json',
        success: function(response) {
            $('#containers-table-container').html(response.table_html);
            $('#pagination-container').html(response.pagination_html);
            attachTableEvents();
        },
        error: function() {
            $('#containers-table-container').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Khalad ayaa dhacay markii xogta la soo dejinayay</p></div>');
        }
    });
}

function loadStats() {
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: { 
            ajax_action: 'get_stats', 
            tenant: $('#tenantFilter').val(),
            branch: $('#branchFilter').val()
        },
        dataType: 'json',
        success: function(stats) {
            $('#stat-total').text(stats.total || 0);
            $('#stat-received').text(stats.received || 0);
            $('#stat-loading').text(stats.loading || 0);
            $('#stat-loaded').text(stats.loaded || 0);
            $('#stat-dispatched').text(stats.dispatched || 0);
            $('#stat-at_port').text(stats.at_port || 0);
            $('#stat-ready').text(stats.ready || 0);
            $('#stat-delivered').text(stats.delivered || 0);
        }
    });
}

function openContainerView(id) {
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: { ajax_action: 'get_container', id: id },
        dataType: 'json',
        success: function(res) {
            if (!res || res.success === false) {
                showAlert('error', (res ? res.message : 'Xogta lama helin'));
                return;
            }
            
            const c = res.container;
            if (!c) {
                showAlert('error', 'Xogta kontaynerka lama helin.');
                return;
            }
            
            const manifest = res.manifest || [];
            const statusNames = { 'received':'La Helay','loading':'La Rarayaa','loaded':'La Raray','shipped':'La Soo Raray','dispatched':'La Diray','at_port':'Dekedda','ready':'Diyaar','delivered':'La Gaarsiiyay' };
            const customsStatusNames = { 'pending': 'La Sugayo', 'cleared': 'La Safay', 'held': 'La Qabtay' };
            
            let totalCbm = 0, totalPkgs = 0, grandTotal = 0, totalWeight = 0, totalStorage = 0;
            let manifestHtml = '';
            
            if (manifest && manifest.length > 0) {
                manifestHtml = `
                    <div class="col-12 mt-4">
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap">
                            <h6 class="mb-0"><i class="fas fa-users"></i> Macaamiisha Saaran Kontaynerkan</h6>
                            <div class="mt-2 mt-sm-0">
                                <button class="btn btn-sm btn-dark mr-1" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                                <button class="btn btn-sm btn-success mr-1" onclick="exportToExcel('manifestTable', '${escapeHtml(c.container_number)}')"><i class="fas fa-file-excel"></i> Excel</button>
                                ${c.status !== 'ready' && c.status !== 'loaded' && c.status !== 'delivered' ? `<button class="btn btn-sm btn-primary mr-1" onclick="window.location.href='warehouse_stock.php?container_id=${c.id}'"><i class="fas fa-plus"></i> Ku dar Alaab</button>` : ''}
                                ${c.status !== 'ready' && c.status !== 'loaded' && c.status !== 'delivered' ? 
                                    `<button class="btn btn-sm btn-info mr-1" onclick="confirmAction('set_container_full', ${c.id}, ${c.id})"><i class="fas fa-check-double"></i> Mark Full</button>` : 
                                    (c.status === 'ready' ? `<button class="btn btn-sm btn-warning mr-1" onclick="confirmAction('set_container_open', ${c.id}, ${c.id})"><i class="fas fa-lock-open"></i> Open Container</button>` : '')
                                }
                                <button class="btn btn-sm btn-success" onclick="sendWhatsAppToAll(${c.id})"><i class="fab fa-whatsapp"></i> Notify All</button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table id="manifestTable" class="table table-bordered table-striped" style="font-size: 12px;">
                                <thead style="background-color: #9bc2e6;">
                                    <tr>
                                        <th>Macaamilka</th>
                                        <th>Telefoonka</th>
                                        <th>Xirmooyinka</th>
                                        <th>Wadarta CBM</th>
                                        <th>Culmis (KG)</th>
                                        <th>Qiimaha CBM</th>
                                        <th>Wadarta Lacagta</th>
                                        <th>Kaydinta (Fee)</th>
                                        <th>Alaabta</th>
                                        <th>Hawlaha</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;
                manifest.forEach(m => {
                    totalCbm += parseFloat(m.total_cbm || 0);
                    totalPkgs += parseInt(m.total_packages || 0);
                    grandTotal += parseFloat(m.total_price || 0);
                    totalWeight += parseFloat(m.weight_kg || 0);
                    totalStorage += parseFloat(m.storage_fee || 0);
                    manifestHtml += `
                        <tr>
                            <td><strong>${escapeHtml(m.customer_name)}</strong></td>
                            <td>${escapeHtml(m.phone || '-')}</td>
                            <td>${m.total_packages}</td>
                            <td>${parseFloat(m.total_cbm).toFixed(3)} CBM</td>
                            <td>${parseFloat(m.weight_kg || 0).toFixed(2)} kg</td>
                            <td>$${parseFloat(m.cbm_price || 0).toFixed(2)}</td>
                            <td><strong>$${parseFloat(m.total_price || 0).toFixed(2)}</strong></td>
                            <td>$${parseFloat(m.storage_fee || 0).toFixed(2)}</td>
                            <td>${escapeHtml(m.items_list)}</td>
                            <td class="text-center">
                                <button class="btn btn-xs btn-success mb-1" onclick="sendWhatsAppToCustomer('${escapeHtml(m.phone)}', '${escapeHtml(m.customer_name)}', '${escapeHtml(c.container_number)}', '${statusNames[c.status]}')"><i class="fab fa-whatsapp"></i></button>
                                ${c.status !== 'ready' && c.status !== 'delivered' ? `<button class="btn btn-xs btn-warning mb-1" onclick="confirmAction('remove_manifest_item', ${m.id}, ${c.id})"><i class="fas fa-undo"></i></button>
                                <button class="btn btn-xs btn-danger" onclick="confirmAction('delete_manifest_item', ${m.id}, ${c.id})"><i class="fas fa-trash"></i></button>` : ''}
                            </td>
                        </tr>
                    `;
                });
                manifestHtml += `
                                </tbody>
                                <tfoot style="background-color: #ffff00; font-weight: bold;">
                                    <tr>
                                        <td colspan="2" class="text-right">WADARTA GUUD:</td>
                                        <td>${totalPkgs}</td>
                                        <td>${totalCbm.toFixed(3)} CBM</td>
                                        <td>${totalWeight.toFixed(2)} kg</td>
                                        <td></td>
                                        <td><strong>$${grandTotal.toFixed(2)}</strong></td>
                                        <td><strong>$${totalStorage.toFixed(2)}</strong></td>
                                        <td></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                `;
            } else {
                manifestHtml = `
                    <div class="col-12 mt-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0"><i class="fas fa-users"></i> Macaamiisha Saaran</h6>
                            <div>
                                ${c.status !== 'ready' && c.status !== 'loaded' && c.status !== 'delivered' ? `<button class="btn btn-sm btn-primary" onclick="window.location.href='warehouse_stock.php?container_id=${c.id}'"><i class="fas fa-plus"></i> Ku dar Alaab</button>` : ''}
                                ${c.status !== 'ready' && c.status !== 'loaded' && c.status !== 'delivered' ? 
                                    `<button class="btn btn-sm btn-info ml-1" onclick="confirmAction('set_container_full', ${c.id}, ${c.id})"><i class="fas fa-check-double"></i> Mark Full</button>` : 
                                    (c.status === 'ready' ? `<button class="btn btn-sm btn-warning ml-1" onclick="confirmAction('set_container_open', ${c.id}, ${c.id})"><i class="fas fa-lock-open"></i> Open Container</button>` : '')
                                }
                            </div>
                        </div>
                        <div class="alert alert-warning py-2 mt-2">Weli wax alaab ah laguma rarin.</div>
                    </div>
                `;
            }

            const capacity = parseFloat(c.size_cbm || 0);
            const percent = capacity > 0 ? Math.min(100, (totalCbm / capacity) * 100).toFixed(1) : 0;
            const remaining = Math.max(0, capacity - totalCbm).toFixed(2);
            const progressColor = percent > 90 ? 'bg-danger' : (percent > 70 ? 'bg-warning' : 'bg-success');

            $('#viewModalBody').html(`
                <div class="row">
                    <div class="col-12 mb-3">
                        <div class="d-flex justify-content-between mb-1"><small><strong>Mugga Kontaynerka (CBM)</strong></small><small><strong>${percent}%</strong></small></div>
                        <div class="progress" style="height: 12px;"><div class="progress-bar ${progressColor}" style="width: ${percent}%"></div></div>
                        <div class="d-flex justify-content-between mt-1" style="font-size: 11px;">
                            <span>Isticmaalay: <strong>${totalCbm.toFixed(2)}</strong></span>
                            <span>Mugga: <strong>${capacity.toFixed(2)}</strong></span>
                            <span class="text-info">Dhiman: <strong>${remaining}</strong></span>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr><td width="40%"><strong>Lambarka:</strong></td><td><strong>${escapeHtml(c.container_number)}</strong></td></tr>
                            <tr><td width="40%"><strong>Nooca:</strong></td><td>${c.container_type || '20ft'}</td></tr>
                            <tr><td width="40%"><strong>Xaaladda:</strong></td><td>${statusNames[c.status]}</td></tr>
                            <tr><td width="40%"><strong>Mugga (CBM):</strong></td><td>${parseFloat(c.size_cbm).toFixed(2)} CBM</td></tr>
                            <tr><td width="40%"><strong>Culmis:</strong></td><td>${parseFloat(c.weight_kg || 0).toFixed(2)} KG</td></tr>
                            <tr><td width="40%"><strong>Xaaladda Kastamka:</strong></td><td>${customsStatusNames[c.customs_status] || 'La Sugayo'}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr><td width="40%"><strong>Shirkadda Iska Leh:</strong></td><td>${escapeHtml(c.tenant_name || '-')}</td></tr>
                            <tr><td width="40%"><strong>Laanta/Goobta:</strong></td><td>${escapeHtml(c.branch_name || '-')}</td></tr>
                            <tr><td width="40%"><strong>Lambarka Raadraaca:</strong></td><td>${escapeHtml(c.tracking_number || '-')}</td></tr>
                            <tr><td width="40%"><strong>Lambarka Seal:</strong></td><td>${escapeHtml(c.seal_number || '-')}</td></tr>
                            <tr><td width="40%"><strong>Lambarka BL:</strong></td><td><code>${escapeHtml(c.bl_number || '-')}</code></td></tr>
                            <tr><td width="40%"><strong>Magaca Markabka:</strong></td><td>${escapeHtml(c.vessel_name || '-')}</td></tr>
                            <tr><td width="40%"><strong>Dekedda Laga Soo Raray:</strong></td><td>${escapeHtml(c.port_of_loading || '-')}</td></tr>
                            <tr><td width="40%"><strong>Dekedda Lagu Soo Dejinayo:</strong></td><td>${escapeHtml(c.port_of_discharge || '-')}</td></tr>
                        </table>
                    </div>
                    
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr><td width="40%"><strong>Taariikhda La Helay:</strong></td><td>${c.arrival_date ? formatDate(c.arrival_date) : '-'}</td></tr>
                            <tr><td width="40%"><strong>Taariikhda La Diray:</strong></td><td>${c.departure_date ? formatDate(c.departure_date) : '-'}</td></tr>
                            <tr><td width="40%"><strong>Taariikhda La Filayo:</strong></td><td>${c.estimated_arrival ? formatDate(c.estimated_arrival) : '-'}</td></tr>
                            <tr><td width="40%"><strong>ETA Dekedda:</strong></td><td>${c.eta_port ? formatDate(c.eta_port) : '-'}</td></tr>
                            <tr><td width="40%"><strong>ETD Dekedda:</strong></td><td>${c.etd_port ? formatDate(c.etd_port) : '-'}</td></tr>
                            <tr><td width="40%"><strong>Goobta Hadda Joogo:</strong></td><td>${escapeHtml(c.current_location || '-')}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-12">
                        ${c.notes ? `<div class="alert alert-info mt-2"><strong>Qoraal:</strong> ${escapeHtml(c.notes)}</div>` : ''}
                    </div>
                    ${manifestHtml}
                </div>
            `);
            $('#viewModal').modal('show');
        },
        error: function() {
            showAlert('error', 'Khalad ayaa dhacay markii xogta kontaynerka la soo dejinayay.');
        }
    });
}

function confirmAction(action, id, containerId = null) {
    let message = '';
    let actionData = { ajax_action: action, id: id };
    
    if (action === 'set_container_full') {
        message = 'Ma hubtaa inaad kontaynerkan u calaamadaynayso inuu BUUXO (Ready for Warehouse)? Alaabtan waxay si toos ah uga muuqan doontaa Bakhaarka Muqdisho.';
    } else if (action === 'set_container_open') {
        message = 'Ma hubtaa inaad rabto inaad dib u FURTO kontaynerkan si aad alaab ugu darto?';
    } else if (action === 'remove_manifest_item') {
        message = 'Ma hubtaa inaad alaabtan ka saarayso kontaynerka oo aad u celinayso bakhaarka?';
    } else if (action === 'delete_manifest_item') {
        message = 'Ma hubtaa inaad alaabtan si joogto ah uga masaxayso kontaynerka? (Bakhaarka dib uguma laabanayso)';
    } else if (action === 'delete_container') {
        message = 'Ma hubtaa inaad si joogto ah u tirtirto kontaynerkan?<br><br><div class="alert alert-warning py-1 mb-0"><i class="fas fa-exclamation-triangle"></i> <strong>Digniin:</strong> Haddii kontaynerkan uu leeyahay safaro ku xiran, kama tirtiri kartid.</div>';
        actionData = { ajax_action: 'delete_container', id: id };
    }
    
    $('#confirmActionMessage').html(message);
    $('#confirmActionBtn').off('click').on('click', function() {
        $('#confirmActionModal').modal('hide');
        
        if (action === 'delete_container') {
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: actionData,
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        loadContainers();
                        loadStats();
                        showAlert('success', res.message);
                    } else {
                        showAlert('error', res.message);
                    }
                }
            });
        } else {
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: actionData,
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        if (containerId) {
                            openContainerView(containerId);
                        }
                        loadContainers();
                        loadStats();
                        showAlert('success', res.message);
                    } else {
                        showAlert('error', res.message);
                    }
                }
            });
        }
    });
    $('#confirmActionModal').modal('show');
}

function sendWhatsAppToAll(containerId) {
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: { ajax_action: 'send_whatsapp_to_container', id: containerId },
        dataType: 'json',
        success: function(res) {
            if (res.success && res.customers && res.customers.length > 0) {
                let html = `<p><strong>Kontaynerka:</strong> ${escapeHtml(res.container_number)}</p>
                            <p><strong>Fariinta:</strong> ${escapeHtml(res.message)}</p>
                            <hr>
                            <p><strong>Macaamiisha (${res.customers.length})</strong></p>
                            <div class="list-group">`;
                
                res.customers.forEach(c => {
                    let phone = c.phone.toString().replace(/\D/g, '');
                    if (phone.length === 9 && (phone.startsWith('6') || phone.startsWith('7'))) {
                        phone = '252' + phone;
                    }
                    const whatsappUrl = `https://wa.me/${phone}?text=${encodeURIComponent(res.message)}`;
                    html += `<a href="${whatsappUrl}" target="_blank" class="list-group-item list-group-item-action">
                                <i class="fab fa-whatsapp text-success"></i> ${escapeHtml(c.customer_name)} - ${c.phone}
                            </a>`;
                });
                
                html += `</div><p class="mt-3 text-muted small">Fariimuhu waxay ku furmi doonaan tabo cusub. Ogolow popup-ka haddii lagu weydiiyo.</p>`;
                $('#whatsappModalBody').html(html);
                $('#whatsappModal').modal('show');
            } else {
                showAlert('info', 'Ma jiraan macaamiil fariin loo diro.');
            }
        },
        error: function() {
            showAlert('error', 'Khalad ayaa dhacay.');
        }
    });
}

function sendWhatsAppToCustomer(phone, name, containerNo, status) {
    let cleanPhone = phone.toString().replace(/\D/g, '');
    if (cleanPhone.length === 9 && (cleanPhone.startsWith('6') || cleanPhone.startsWith('7'))) {
        cleanPhone = '252' + cleanPhone;
    }
    
    if (!cleanPhone) {
        showAlert('error', 'Macaamilkan ma lahan lambar telefoon oo sax ah!');
        return;
    }
    
    const message = `Assalaamu Calaykum ${name},\n\nKontaynerka *${containerNo}* xaaladeeda hadda waa: *${status}*.\n\nWaxaad ku mahadsantahay doorashadaada.\n\n*Cargo Management System*`;
    const url = `https://wa.me/${cleanPhone}?text=${encodeURIComponent(message)}`;
    window.open(url, '_blank');
}

function exportToExcel(tableID, filename = '') {
    let downloadLink;
    let dataType = 'application/vnd.ms-excel';
    let tableSelect = document.getElementById(tableID);
    
    if (!tableSelect) return;

    let clonedTable = tableSelect.cloneNode(true);
    let rows = clonedTable.rows;
    for (let i = 0; i < rows.length; i++) {
        if(rows[i].cells.length > 0) {
            rows[i].deleteCell(-1);
        }
    }
    
    let tableHTML = clonedTable.outerHTML.replace(/ /g, '%20');
    filename = filename ? filename + '.xls' : 'excel_data.xls';
    downloadLink = document.createElement("a");
    document.body.appendChild(downloadLink);
    
    if (navigator.msSaveOrOpenBlob) {
        let blob = new Blob(['\ufeff', tableHTML], { type: dataType });
        navigator.msSaveOrOpenBlob(blob, filename);
    } else {
        downloadLink.href = 'data:' + dataType + ', ' + tableHTML;
        downloadLink.download = filename;
        downloadLink.click();
    }
}

function attachTableEvents() {
    $('.view-container').off('click').on('click', function() {
        openContainerView($(this).data('id'));
    });
    
    $('.edit-container').off('click').on('click', function() {
        const id = $(this).data('id');
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_container', id: id },
            dataType: 'json',
            success: function(res) {
                if (!res || !res.success) {
                    showAlert('error', res?.message || 'Xogta lama helin');
                    return;
                }
                const c = res.container;
                $('#containerModalLabel').text('Wax Ka Beddel Kontaynerka');
                $('#container_id').val(c.id);
                $('#modalTenantId').val(c.tenant_id);
                $('#modalContainerNumber').val(c.container_number);
                $('#modalContainerType').val(c.container_type || '20ft');
                $('#modalSizeCbm').val(c.size_cbm);
                $('#modalWeightKg').val(c.weight_kg);
                $('#modalStatus').val(c.status);
                $('#modalCustomsStatus').val(c.customs_status || 'pending');
                $('#modalCurrentLocation').val(c.current_location || '');
                $('#modalArrivalDate').val(c.arrival_date ? formatDate(c.arrival_date) : '');
                $('#modalDepartureDate').val(c.departure_date ? formatDate(c.departure_date) : '');
                $('#modalEstimatedArrival').val(c.estimated_arrival ? formatDate(c.estimated_arrival) : '');
                $('#modalEtaPort').val(c.eta_port ? formatDate(c.eta_port) : '');
                $('#modalEtdPort').val(c.etd_port ? formatDate(c.etd_port) : '');
                $('#modalTrackingNumber').val(c.tracking_number || '');
                $('#modalSealNumber').val(c.seal_number || '');
                $('#modalBlNumber').val(c.bl_number || '');
                $('#modalVesselName').val(c.vessel_name || '');
                $('#modalShippingLine').val(c.shipping_line || '');
                $('#modalPortOfLoading').val(c.port_of_loading || '');
                $('#modalPortOfDischarge').val(c.port_of_discharge || '');
                $('#modalNotes').val(c.notes || '');
                
                // Load branches for the selected tenant
                if (c.tenant_id) {
                    loadBranches(c.tenant_id, 'modalCurrentBranchId', c.current_branch_id);
                } else {
                    $('#modalCurrentBranchId').html('<option value="">-- Marka hore dooro shirkad --</option>');
                }
                
                // Auto calculate CBM when container type changes
                if (c.container_type && c.container_type !== 'lcl' && (!c.size_cbm || c.size_cbm == 0)) {
                    const cbmMap = { '20ft': 33.2, '40ft': 67.6, '40hc': 76.3 };
                    if (cbmMap[c.container_type]) {
                        $('#modalSizeCbm').val(cbmMap[c.container_type]);
                    }
                }
                
                $('#containerModal').modal('show');
            },
            error: function() {
                showAlert('error', 'Khalad ayaa dhacay.');
            }
        });
    });
    
    $('.update-status').off('click').on('click', function() {
        const $btn = $(this);
        $('#statusContainerId').val($btn.data('id'));
        $('#statusNewStatus').val($btn.data('status'));
        $('#statusForm').data('triggering-btn', $btn);
        $('#statusModal').modal('show');
    });
    
    $('.btn-tracking').off('click').on('click', function() {
        const tracking = $(this).data('tracking');
        const number = $(this).data('number');
        showAlert('info', `Raadraaca ${tracking} wali lama helin xogtiisa. Fadlan dib u tijaabi hadhow.`);
    });
    
    $('.delete-container').off('click').on('click', function() {
        const id = $(this).data('id');
        const name = $(this).data('name');
        confirmAction('delete_container', id);
    });
    
    $('.pagination a').off('click').on('click', function(e) {
        e.preventDefault();
        currentPage = $(this).data('page');
        loadContainers();
    });
}

// Auto calculate CBM when container type changes
$('#modalContainerType').on('change', function() {
    const type = $(this).val();
    const cbmMap = { '20ft': 33.2, '40ft': 67.6, '40hc': 76.3 };
    if (cbmMap[type]) {
        $('#modalSizeCbm').val(cbmMap[type]);
        $('#modalSizeCbm').prop('readonly', true);
    } else {
        $('#modalSizeCbm').prop('readonly', false);
        $('#modalSizeCbm').val('');
    }
});

$(document).ready(function() {
    // Initialize CBM auto-calculation
    $('#modalContainerType').trigger('change');
    
    // Load branches when tenant is selected in modal
    $('#modalTenantId').on('change', function() {
        const tenantId = $(this).val();
        if (tenantId && tenantId != '') {
            loadBranches(tenantId, 'modalCurrentBranchId');
        } else {
            $('#modalCurrentBranchId').html('<option value="">-- Dooro Laanta --</option>');
        }
    });
    
    // Load branches for filter when tenant changes
    $('#tenantFilter').on('change', function() {
        const tenantId = $(this).val();
        if (tenantId && tenantId != '0') {
            loadBranches(tenantId, 'branchFilter');
            $('#branchFilter').prop('disabled', false);
        } else {
            $('#branchFilter').html('<option value="0">Marka hore dooro shirkad</option>').prop('disabled', true);
        }
        currentPage = 1;
        loadContainers();
        loadStats();
    });
    
    // Initialize branch filter
    if ($('#tenantFilter').val() != '0') {
        loadBranches($('#tenantFilter').val(), 'branchFilter');
        $('#branchFilter').prop('disabled', false);
    } else {
        $('#branchFilter').prop('disabled', true);
    }
    
    $('#containerForm').submit(function(e) {
        e.preventDefault();
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $(this).serialize() + '&ajax_action=save_container',
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#containerModal').modal('hide');
                    loadContainers();
                    loadStats();
                    showAlert('success', res.message);
                } else {
                    showAlert('error', res.message);
                }
            },
            error: function() {
                showAlert('error', 'Khalad ayaa dhacay.');
            }
        });
    });
    
    const statusColors = {
        'received':   '#17a2b8',
        'loading':    '#ffc107',
        'loaded':     '#28a745',
        'shipped':    '#6f42c1',
        'dispatched': '#fd7e14',
        'at_port':    '#6f42c1',
        'ready':      '#28a745',
        'delivered':  '#20c997'
    };
    const statusLabels = {
        'received':   'La Helay',
        'loading':    'La Rarayaa',
        'loaded':     'La Raray',
        'shipped':    'La Soo Raray',
        'dispatched': 'La Diray',
        'at_port':    'Dekedda',
        'ready':      'Diyaar',
        'delivered':  'La Gaarsiiyay'
    };

    $('#statusForm').submit(function(e) {
        e.preventDefault();
        const $form     = $(this);
        const newStatus = $('#statusNewStatus').val();
        const $trigBtn  = $form.data('triggering-btn');

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $form.serialize() + '&ajax_action=update_status',
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#statusModal').modal('hide');

                    if ($trigBtn) {
                        const $row  = $trigBtn.closest('tr');
                        const color = statusColors[newStatus] || '#6c757d';
                        const label = statusLabels[newStatus] || newStatus;

                        $row.find('.status-badge')
                            .css({ 'background': color + '20', 'color': color })
                            .text(label);

                        if (newStatus === 'ready') {
                            if (!$row.find('.finished-label').length) {
                                $row.find('.status-badge').after(
                                    '<div class="finished-label" style="font-size:10px;color:#28a745;font-weight:600;margin-top:3px;">'
                                    + '<i class="fas fa-check-circle"></i> La diray Bakhaarka</div>'
                                );
                            }
                            $trigBtn.replaceWith(
                                '<button class="action-btn btn-status" disabled '
                                + 'title="La diray Bakhaarka — xaaladda ma bedeli kartid" '
                                + 'style="opacity:0.4;cursor:not-allowed;">'
                                + '<i class="fas fa-check-double"></i></button>'
                            );
                        } else {
                            $row.find('.finished-label').remove();
                            $trigBtn.data('status', newStatus);
                        }
                    }
                    loadStats();
                    showAlert('success', res.message);
                } else {
                    showAlert('error', res.message);
                }
            },
            error: function() {
                showAlert('error', 'Khalad ayaa dhacay.');
            }
        });
    });
    
    $('#applyFilters').click(function() { 
        currentPage = 1; 
        loadContainers(); 
        loadStats(); 
    });
    
    $('#resetFilters').click(function() { 
        $('#searchInput').val(''); 
        $('#tenantFilter').val('0'); 
        $('#branchFilter').html('<option value="0">Marka hore dooro shirkad</option>').prop('disabled', true);
        $('#statusFilter').val('');
        currentPage = 1; 
        loadContainers(); 
        loadStats(); 
    });
    
    $('#addContainerBtn, #addContainerBtnEmpty').click(function() {
        $('#containerForm')[0].reset();
        $('#container_id').val('');
        $('#modalTenantId').val('');
        $('#modalCurrentBranchId').html('<option value="">-- Dooro Laanta --</option>');
        $('#modalContainerType').val('20ft').trigger('change');
        $('#modalStatus').val('received');
        $('#modalCustomsStatus').val('pending');
        $('#containerModalLabel').text('Kontayner Cusub');
        $('#containerModal').modal('show');
    });
    
    $('#searchInput').keypress(function(e) {
        if (e.which === 13) {
            currentPage = 1;
            loadContainers();
        }
    });
    
    loadContainers();
    loadStats();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>