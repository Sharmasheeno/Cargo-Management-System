<?php
// superadmin/receptions.php
// Maareynta Qaabilaadda -faras cargo Super Admin

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is superadmin or company_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['superadmin', 'company_admin', 'tenant_admin'])) {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION['role'];
$session_tenant_id = $_SESSION['tenant_id'] ?? 0;

// Convert company_admin to tenant_admin
if ($role === 'company_admin') {
    $role = 'tenant_admin';
    $_SESSION['role'] = 'tenant_admin';
}

require_once __DIR__ . '/../config/db_connect.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'Super Admin';

// ── Ensure columns exist ─────────────────────────────────────────────────────
$_col_patches = [
    "ALTER TABLE containers MODIFY COLUMN status
         ENUM('received','loading','loaded','shipped','dispatched','at_port','ready','delivered') DEFAULT 'received'",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS weight_kg DECIMAL(15,2) DEFAULT 0.00",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS current_location VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS arrival_date DATE DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS departure_date DATE DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS estimated_arrival DATE DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS tracking_number VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS seal_number VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS notes TEXT DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS shipping_line VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS shipping_line_code VARCHAR(50) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS bl_number VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS vessel_name VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS voyage_number VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS port_of_loading VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS port_of_discharge VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS eta_port DATE DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS etd_port DATE DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS customs_status ENUM('pending','cleared','held','inspected') DEFAULT 'pending'",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS created_by INT(11) DEFAULT NULL",
    "ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS weight_kg DECIMAL(15,2) DEFAULT 0.00",
    "ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS unit_price DECIMAL(15,2) DEFAULT 0.00"
];
foreach ($_col_patches as $_cp) {
    try { $pdo->exec($_cp); } catch (PDOException $e) { /* ignore */ }
}
unset($_col_patches, $_cp);

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

// Status definitions
$status_names = [
    'received' => 'La Helay',
    'loaded' => 'La Raray',
    'dispatched' => 'La Diray',
    'at_port' => 'Dekedda',
    'ready' => 'Diyaar',
    'delivered' => 'La Gaarsiiyay'
];

$status_colors = [
    'received' => '#17a2b8',
    'loaded' => '#ffc107',
    'dispatched' => '#fd7e14',
    'at_port' => '#6f42c1',
    'ready' => '#28a745',
    'delivered' => '#20c997'
];

$origin_names = [
    'china_yiwu' => 'Shiinaha (Yiwu) 🇨🇳',
    'china_guangzhou' => 'Shiinaha (Guangzhou) 🇨🇳',
    'dubai' => 'Dubay 🇦🇪'
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
        $tenant_filter = ($role === 'superadmin') ? (isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0) : $session_tenant_id;
        $origin_filter = $_POST['origin'] ?? '';
        $status_filter = $_POST['status'] ?? '';
        
        $where_conditions = [];
        $params = [];
        
        if (!empty($search)) {
            $where_conditions[] = "(c.container_number LIKE ? OR c.bl_number LIKE ? OR c.vessel_name LIKE ? OR c.shipping_line LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($tenant_filter > 0) {
            $where_conditions[] = "c.tenant_id = ?";
            $params[] = $tenant_filter;
        } elseif ($role === 'tenant_admin') {
            $where_conditions[] = "c.tenant_id = ?";
            $params[] = $session_tenant_id;
        }
        
        if (!empty($origin_filter)) {
            $where_conditions[] = "c.origin = ?";
            $params[] = $origin_filter;
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
                   t.name as tenant_name
            FROM containers c
            LEFT JOIN tenants t ON c.tenant_id = t.id
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
                        <th style="padding: 12px;">Xogta Markabka & BL</th>
                        <th style="padding: 12px;">Asalka</th>
                        <th style="padding: 12px;">CBM</th>
                        <th style="padding: 12px;">Xaaladda</th>
                        <th style="padding: 12px;">Safarkii Ugu Dambeeyay</th>
                        <th style="padding: 12px;">Shirkadda Iska Leh</th>
                        <th style="padding: 12px;">Raadraac</th>
                        <th style="padding: 12px;">Hawlaha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($containers) > 0): ?>
                        <?php foreach ($containers as $container): 
                            $statusColor = $status_colors[$container['status']] ?? '#6c757d';
                            $statusName = $status_names[$container['status']] ?? ucfirst($container['status']);
                            $originName = $origin_names[$container['origin']] ?? $container['origin'];
                            // Get last trip for this container
                            $tripStmt = $pdo->prepare("SELECT trip_number, status FROM trucking_trips WHERE container_id = ? ORDER BY created_at DESC LIMIT 1");
                            $tripStmt->execute([$container['id']]);
                            $lastTrip = $tripStmt->fetch(PDO::FETCH_ASSOC);
                        ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px;"><?= $container['id'] ?> </td>
                                <td style="padding: 12px;">
                                    <strong><?= htmlspecialchars($container['container_number']) ?></strong>
                                    <div style="font-size: 11px; color: #6c757d;">
                                        <i class="fas fa-calendar-alt"></i> <?= date('d/m/Y', strtotime($container['created_at'])) ?>
                                    </div>
                                </td>
                                <td style="padding: 12px;">
                                    <?php if (!empty($container['bl_number']) || !empty($container['vessel_name']) || !empty($container['shipping_line'])): ?>
                                        <div><strong>BL:</strong> <?= htmlspecialchars($container['bl_number'] ?? '-') ?></div>
                                        <div><strong>Markab:</strong> <?= htmlspecialchars($container['vessel_name'] ?? '-') ?></div>
                                        <div><strong>Shirkadda:</strong> <?= htmlspecialchars($container['shipping_line'] ?? '-') ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px;"><?= $originName ?> </td>
                                <td style="padding: 12px;"><?= number_format($container['size_cbm'], 2) ?> CBM </td>
                                <td style="padding: 12px;">
                                    <span class="status-badge" style="background: <?= $statusColor ?>20; color: <?= $statusColor ?>; padding: 4px 10px; border-radius: 20px; font-size: 11px;">
                                        <?= $statusName ?>
                                    </span>
                                </td>
                                <td style="padding: 12px;">
                                    <?php if ($lastTrip && $lastTrip['trip_number']): ?>
                                        <a href="shipments.php?search=<?= urlencode($lastTrip['trip_number']) ?>" class="shipment-link" style="color: #1565c0; text-decoration: none;">
                                            <?= htmlspecialchars($lastTrip['trip_number']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">Aan la qoondeyn</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px;"><?= htmlspecialchars($container['tenant_name'] ?? '-') ?> </td>
                                <td style="padding: 12px;">
                                    <?php if ($container['tracking_number']): ?>
                                        <button class="action-btn btn-tracking" data-tracking="<?= htmlspecialchars($container['tracking_number']) ?>" data-number="<?= htmlspecialchars($container['container_number']) ?>">
                                            <i class="fas fa-map-marker-alt"></i>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px;">
                                    <div class="action-buttons">
                                        <button class="action-btn btn-view view-container" data-id="<?= $container['id'] ?>" title="Faahfaahin"><i class="fas fa-eye"></i></button>
                                        <button class="action-btn btn-unload unload-container" data-id="<?= $container['id'] ?>" data-number="<?= htmlspecialchars($container['container_number']) ?>" title="Alaabta Daji"><i class="fas fa-box-open"></i></button>
                                        <button class="action-btn btn-edit edit-container" data-id="<?= $container['id'] ?>" title="Wax Ka Beddel"><i class="fas fa-edit"></i></button>
                                        <button class="action-btn btn-status update-status" data-id="<?= $container['id'] ?>" data-status="<?= $container['status'] ?>" title="Cusboonaysii Xaaladda"><i class="fas fa-exchange-alt"></i></button>
                                        <button class="action-btn btn-delete delete-container" data-id="<?= $container['id'] ?>" data-name="<?= htmlspecialchars($container['container_number']) ?>" title="Tirtir"><i class="fas fa-trash"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 50px;">
                                <div class="empty-state">
                                    <i class="fas fa-box" style="font-size: 48px; opacity: 0.5;"></i>
                                    <p>Ma jiraan wax kontayner ah</p>
                                    <button class="btn-primary-custom" id="addContainerBtnEmpty">
                                        <i class="fas fa-plus-circle"></i> Qaabilaad Cusub
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
    
    elseif ($action === 'get_container') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("
            SELECT c.*, t.name as tenant_name
            FROM containers c
            LEFT JOIN tenants t ON c.tenant_id = t.id
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
        $container = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($container);
        exit;
    }
    
    elseif ($action === 'save_container') {
        $id = $_POST['container_id'] ?? '';
        $tenant_id = ($role === 'superadmin') ? (!empty($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : null) : $session_tenant_id;
        $container_number = trim($_POST['container_number'] ?? '');
        $origin = $_POST['origin'] ?? 'china_yiwu';
        $size_cbm = (float)($_POST['size_cbm'] ?? 0);
        $weight_kg = (float)($_POST['weight_kg'] ?? 0);
        $status = $_POST['status'] ?? 'received';
        $current_location = trim($_POST['current_location'] ?? '');
        $arrival_date = !empty($_POST['arrival_date']) ? $_POST['arrival_date'] : null;
        $tracking_number = trim($_POST['tracking_number'] ?? '');
        $seal_number = trim($_POST['seal_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        // Shipping fields
        $shipping_line = trim($_POST['shipping_line'] ?? '');
        $shipping_line_code = trim($_POST['shipping_line_code'] ?? '');
        $bl_number = trim($_POST['bl_number'] ?? '');
        $vessel_name = trim($_POST['vessel_name'] ?? '');
        $voyage_number = trim($_POST['voyage_number'] ?? '');
        $port_of_loading = trim($_POST['port_of_loading'] ?? '');
        $port_of_discharge = trim($_POST['port_of_discharge'] ?? '');
        $eta_port = !empty($_POST['eta_port']) ? $_POST['eta_port'] : null;
        $etd_port = !empty($_POST['etd_port']) ? $_POST['etd_port'] : null;
        $customs_status = $_POST['customs_status'] ?? 'pending';
        
        // REMOVED: departure_date and estimated_arrival from form (duplicate)
        
        if (empty($container_number)) {
            echo json_encode(['success' => false, 'message' => 'Fadlan geli lambarka kontaynerka']);
            exit;
        }
        
        try {
            if (empty($id)) {
                $check = $pdo->prepare("SELECT id FROM containers WHERE container_number = ?");
                $check->execute([$container_number]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'message' => "Kontaynerka '$container_number' waxaa horay loo isticmaalay"]);
                    exit;
                }
                
                if (empty($tracking_number)) {
                    $tracking_number = 'TRK-' . date('Ymd') . '-' . rand(1000, 9999);
                }
                
                $sql = "INSERT INTO containers (tenant_id, container_number, origin, size_cbm, weight_kg, status,
                        current_location, arrival_date, tracking_number, 
                        seal_number, notes, shipping_line, shipping_line_code, bl_number, vessel_name, 
                        voyage_number, port_of_loading, port_of_discharge, eta_port, etd_port, customs_status,
                        created_by, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$tenant_id, $container_number, $origin, $size_cbm, $weight_kg, $status,
                               $current_location, $arrival_date, $tracking_number,
                               $seal_number, $notes, $shipping_line, $shipping_line_code, $bl_number, $vessel_name,
                               $voyage_number, $port_of_loading, $port_of_discharge, $eta_port, $etd_port, $customs_status,
                               $_SESSION['user_id']]);
                
                echo json_encode(['success' => true, 'message' => "Kontaynerka '$container_number' waa la kaydiyay!"]);
            } else {
                $sql = "UPDATE containers 
                        SET tenant_id=?, container_number=?, origin=?, size_cbm=?, weight_kg=?, status=?,
                            current_location=?, arrival_date=?, tracking_number=?, 
                            seal_number=?, notes=?, shipping_line=?, shipping_line_code=?, bl_number=?, vessel_name=?, 
                            voyage_number=?, port_of_loading=?, port_of_discharge=?, eta_port=?, etd_port=?, customs_status=?
                        WHERE id=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$tenant_id, $container_number, $origin, $size_cbm, $weight_kg, $status,
                               $current_location, $arrival_date, $tracking_number,
                               $seal_number, $notes, $shipping_line, $shipping_line_code, $bl_number, $vessel_name,
                               $voyage_number, $port_of_loading, $port_of_discharge, $eta_port, $etd_port, $customs_status, $id]);
                
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
            $check = $pdo->prepare("SELECT COUNT(*) as count FROM trucking_trips WHERE container_id = ?");
            $check->execute([$id]);
            $shipment_count = $check->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($shipment_count > 0) {
                echo json_encode(['success' => false, 'message' => "Kontaynerkan waxaa ku xiran $shipment_count safar. Marka hore tirtir safarada."]);
                exit;
            }
            
            $stmt = $pdo->prepare("SELECT container_number FROM containers WHERE id = ?");
            $stmt->execute([$id]);
            $container = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$container) {
                echo json_encode(['success' => false, 'message' => 'Kontaynerka lama helin']);
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
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        
        $allowed_statuses = ['received', 'loaded', 'dispatched', 'at_port', 'ready', 'delivered'];
        if (!in_array($status, $allowed_statuses)) {
            echo json_encode(['success' => false, 'message' => 'Xaalad aan la aqbalin']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();

            // 1. Update container status
            $sql = "UPDATE containers SET status = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$status, $id]);
            
            // 2. When status becomes 'ready', push ALL manifest items to Mogadishu warehouse tracking
            if ($status === 'ready') {
                $pushSql = "
                    UPDATE cargo_manifest_items
                    SET mogadishu_status        = 'in_warehouse',
                        mogadishu_received_date = NOW()
                    WHERE container_id = ?
                ";
                $pdo->prepare($pushSql)->execute([$id]);

                // Also update global warehouse_stock status
                $wsPushSql = "
                    UPDATE warehouse_stock ws
                    JOIN cargo_manifest_items cmi ON cmi.warehouse_stock_id = ws.id
                    SET ws.mogadishu_status        = 'in_warehouse',
                        ws.mogadishu_received_date = NOW()
                    WHERE cmi.container_id = ?
                ";
                $pdo->prepare($wsPushSql)->execute([$id]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Xaaladda kontaynerka waa la cusboonaysiiyay!']);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'get_container_items') {
        $container_id = $_POST['container_id'] ?? 0;
        try {
            $stmt = $pdo->prepare("
                SELECT cmi.*, cmi.mogadishu_status, cmi.mogadishu_received_date, ws.id as ws_id,
                       c.customer_name, c.phone as customer_phone
                FROM cargo_manifest_items cmi
                LEFT JOIN warehouse_stock ws ON cmi.warehouse_stock_id = ws.id
                LEFT JOIN customers c ON ws.customer_id = c.id
                WHERE cmi.container_id = ?
            ");
            $stmt->execute([$container_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'items' => $items]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'update_item_delivery_status') {
        $stock_id = $_POST['stock_id'] ?? 0;
        $status = $_POST['status'] ?? '';
        
        if (!in_array($status, ['delivered', 'in_warehouse'])) {
            echo json_encode(['success' => false, 'message' => 'Xaalad aan la aqbalin']);
            exit;
        }
        
        try {
            $received_date = ($status === 'in_warehouse') ? date('Y-m-d H:i:s') : null;
            $stmt = $pdo->prepare("UPDATE warehouse_stock SET mogadishu_status = ?, mogadishu_received_date = ? WHERE id = ?");
            $stmt->execute([$status, $received_date, $stock_id]);
            
            $msg = ($status === 'delivered') ? "Alaabta waa la siiyay macmiilka" : "Alaabta waxaa la geliyay Bakhaarka Xamar";
            echo json_encode(['success' => true, 'message' => $msg]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'get_stats') {
        $tenant_filter = isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0;
        $where = $tenant_filter > 0 ? "WHERE tenant_id = $tenant_filter" : "";
        
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) as received,
                SUM(CASE WHEN status = 'loaded' THEN 1 ELSE 0 END) as loaded,
                SUM(CASE WHEN status = 'dispatched' THEN 1 ELSE 0 END) as dispatched,
                SUM(CASE WHEN status = 'at_port' THEN 1 ELSE 0 END) as at_port,
                SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) as ready,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(size_cbm) as total_cbm,
                SUM(weight_kg) as total_weight
            FROM containers
            $where
        ");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($stats);
        exit;
    }
    
    exit;
}

$templateStmt = $pdo->prepare("SELECT message_content FROM message_templates WHERE template_key = 'cargo_arrived'");
$templateStmt->execute();
$cargoArrivedTemplate = $templateStmt->fetchColumn();
if (!$cargoArrivedTemplate) {
    $cargoArrivedTemplate = "Salaamu Calaykum {customer_name}, Alaabtaada ({item_name}) waxay timid xafiiskayaga. Fadlan kala wareeg.";
}

// Include header
require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maareynta Qaabilaadda | Cargo Management System</title>
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
        
        /* Page Header */
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
        
        /* Buttons */
        .btn-primary-custom {
            background: var(--secondary);
            color: var(--primary);
            border: none;
            padding: 10px 24px;
            border-radius: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            cursor: pointer;
            font-size: 14px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .btn-primary-custom:hover {
            background: var(--secondary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(244, 221, 8, 0.3);
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
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
        
        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(4, 1fr); } }
        @media (max-width: 768px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        
        /* Filters Card */
        .filters-card {
            background: white;
            border-radius: 16px;
            padding: 20px 25px;
            margin-bottom: 25px;
            border: 1px solid var(--gray-200);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .filter-form { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 160px; }
        .filter-group label { display: block; font-size: 12px; font-weight: 600; color: var(--gray-600); margin-bottom: 6px; }
        .filter-group input, .filter-group select {
            width: 100%; padding: 10px 14px; border: 1px solid var(--gray-300); border-radius: 10px;
            font-size: 13px; transition: all 0.2s;
        }
        .filter-group input:focus, .filter-group select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(82,0,102,0.1); }
        .btn-filter, .btn-reset { padding: 10px 22px; border-radius: 10px; font-weight: 500; font-size: 13px; cursor: pointer; transition: all 0.2s; border: none; }
        .btn-filter { background: var(--primary); color: white; }
        .btn-filter:hover { background: var(--primary-light); }
        .btn-reset { background: var(--gray-100); color: var(--gray-700); border: 1px solid var(--gray-200); }
        .btn-reset:hover { background: var(--gray-200); }
        
        /* Table Container */
        .containers-table-container {
            background: white;
            border-radius: 16px;
            border: 1px solid var(--gray-200);
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .containers-table { width: 100%; border-collapse: collapse; }
        .containers-table th { padding: 14px 16px; background: var(--gray-50); color: var(--gray-700); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--gray-200); }
        .containers-table td { padding: 14px 16px; border-bottom: 1px solid var(--gray-100); font-size: 13px; vertical-align: middle; }
        .containers-table tr:hover { background: var(--gray-50); }
        
        /* Action Buttons */
        .action-buttons { display: flex; gap: 6px; flex-wrap: wrap; }
        .action-btn { width: 30px; height: 30px; border-radius: 8px; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; }
        .btn-view { background: #eef2ff; color: #4f46e5; }
        .btn-view:hover { background: #4f46e5; color: white; }
        .btn-edit { background: #fff7ed; color: #ea580c; }
        .btn-edit:hover { background: #ea580c; color: white; }
        .btn-status { background: #fef3c7; color: #d97706; }
        .btn-status:hover { background: #d97706; color: white; }
        .btn-unload { background: #e0f2fe; color: #0284c7; }
        .btn-unload:hover { background: #0284c7; color: white; }
        .btn-tracking { background: #dcfce7; color: #16a34a; }
        .btn-tracking:hover { background: #16a34a; color: white; }
        .btn-delete { background: #fef2f2; color: #dc2626; }
        .btn-delete:hover { background: #dc2626; color: white; }
        
        /* Status Badge */
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        
        /* Pagination */
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 25px; }
        .pagination-link, .active-page {
            padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 500;
            background: white; border: 1px solid var(--gray-200); cursor: pointer; transition: all 0.2s;
        }
        .pagination-link:hover { background: var(--gray-100); border-color: var(--gray-300); }
        .active-page { background: var(--primary); color: white; border-color: var(--primary); cursor: default; }
        
        /* Modal Styles */
        .modal-header { background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: white; border-bottom: none; }
        .modal-header .close { color: white; opacity: 0.8; }
        .modal-header .close:hover { opacity: 1; }
        .modal-title i { margin-right: 8px; color: var(--secondary); }
        .form-group { margin-bottom: 1rem; }
        .form-group label { font-size: 12px; font-weight: 600; color: var(--gray-700); margin-bottom: 5px; display: block; }
        .form-control { border-radius: 10px; border: 1px solid var(--gray-300); padding: 10px 14px; font-size: 13px; }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(82,0,102,0.1); }
        
        /* Section Divider */
        .section-divider { margin: 20px 0 15px; padding-top: 10px; border-top: 2px solid var(--gray-200); }
        .section-title { font-size: 15px; font-weight: 600; color: var(--primary); margin-bottom: 15px; }
        .section-title i { margin-right: 8px; color: var(--secondary); }
        
        /* Alert */
        .alert { position: fixed; top: 85px; right: 20px; z-index: 9999; min-width: 320px; border-radius: 12px; border-left: 4px solid; animation: slideIn 0.3s ease; }
        .alert-success { background: #ecfdf5; color: #065f46; border-left-color: #10b981; }
        .alert-error { background: #fef2f2; color: #991b1b; border-left-color: #ef4444; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
        .loading-spinner { text-align: center; padding: 50px; }
        .loading-spinner i { font-size: 40px; color: var(--primary); animation: spin 1s linear infinite; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        
        .empty-state { text-align: center; padding: 60px; color: var(--gray-500); }
        .empty-state i { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }
        
        .text-muted { color: var(--gray-500); }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-box"></i> Maareynta Qaabilaadda</h1>
        <button type="button" class="btn-primary-custom" id="addContainerBtn">
            <i class="fas fa-plus-circle"></i> Qaabilaad Cusub
        </button>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-info"><h4>Wadarta Guud</h4><div class="stat-number" id="stat-total">0</div></div><div class="stat-icon"><i class="fas fa-box"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>La Helay</h4><div class="stat-number" id="stat-received">0</div></div><div class="stat-icon"><i class="fas fa-download"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>La Raray</h4><div class="stat-number" id="stat-loaded">0</div></div><div class="stat-icon"><i class="fas fa-truck-loading"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>La Diray</h4><div class="stat-number" id="stat-dispatched">0</div></div><div class="stat-icon"><i class="fas fa-paper-plane"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Dekedda</h4><div class="stat-number" id="stat-at_port">0</div></div><div class="stat-icon"><i class="fas fa-ship"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Diyaar</h4><div class="stat-number" id="stat-ready">0</div></div><div class="stat-icon"><i class="fas fa-check"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>La Gaarsiiyay</h4><div class="stat-number" id="stat-delivered">0</div></div><div class="stat-icon"><i class="fas fa-flag-checkered"></i></div></div>
    </div>

    <!-- Filters -->
    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group"><label><i class="fas fa-search"></i> Raadin</label><input type="text" id="searchInput" placeholder="Raadi kontaynerka, BL, Markabka..."></div>
            <div class="filter-group"><label><i class="fas fa-building"></i> Shirkadda</label><select id="tenantFilter"><option value="0">Dhammaan Shirkadaha</option><?php foreach ($tenants as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?></select></div>
            <div class="filter-group"><label><i class="fas fa-globe"></i> Asalka</label><select id="originFilter"><option value="">Dhammaan</option><option value="china_yiwu">China Yiwu 🇨🇳</option><option value="china_guangzhou">China Guangzhou 🇨🇳</option><option value="dubai">Dubay 🇦🇪</option></select></div>
            <div class="filter-group"><label><i class="fas fa-tag"></i> Xaaladda</label><select id="statusFilter"><option value="">Dhammaan</option><option value="received">La Helay</option><option value="loaded">La Raray</option><option value="dispatched">La Diray</option><option value="at_port">Dekedda</option><option value="ready">Diyaar</option><option value="delivered">La Gaarsiiyay</option></select></div>
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
                <h5 class="modal-title" id="containerModalLabel"><i class="fas fa-plus-circle"></i> Qaabilaad Cusub</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="containerForm">
                <div class="modal-body">
                    <input type="hidden" name="container_id" id="container_id">
                    
                    <div class="row">
                        <div class="col-md-4">
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
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Lambarka Kontaynerka <span class="text-danger">*</span></label>
                                <input type="text" name="container_number" id="modalContainerNumber" class="form-control" required placeholder="MSCU1234567">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Asalka <span class="text-danger">*</span></label>
                                <select name="origin" id="modalOrigin" class="form-control">
                                    <option value="china_yiwu">Shiinaha (Yiwu) 🇨🇳</option>
                                    <option value="china_guangzhou">Shiinaha (Guangzhou) 🇨🇳</option>
                                    <option value="dubai">Dubay 🇦🇪</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Cabirka (CBM)</label>
                                <input type="number" step="0.01" name="size_cbm" id="modalSizeCbm" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Culmis (KG)</label>
                                <input type="number" step="1" name="weight_kg" id="modalWeightKg" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Xaaladda <span class="text-danger">*</span></label>
                                <select name="status" id="modalStatus" class="form-control">
                                    <option value="received">La Helay</option>
                                    <option value="loaded">La Raray</option>
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
                                    <option value="pending">Sugaya</option>
                                    <option value="cleared">La Soo Geliyay</option>
                                    <option value="held">La Haysto</option>
                                    <option value="inspected">La Kormeeray</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Xogta Markabka & BL Section -->
                    <div class="section-divider"></div>
                    <div class="section-title"><i class="fas fa-ship"></i> Xogta Markabka & Bill of Lading (BL)</div>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Lambarka BL</label>
                                <input type="text" name="bl_number" id="modalBlNumber" class="form-control" placeholder="BL123456789">
                                <small class="text-muted">Bill of Lading number</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Magaca Markabka</label>
                                <input type="text" name="vessel_name" id="modalVesselName" class="form-control" placeholder="MSC Vessel">
                                <small class="text-muted">Vessel / Ship name</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Shirkadda Markabka</label>
                                <input type="text" name="shipping_line" id="modalShippingLine" class="form-control" placeholder="MSC, MAERSK, CMA CGM">
                                <small class="text-muted">Shipping line company</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Koodka Shirkadda</label>
                                <input type="text" name="shipping_line_code" id="modalShippingLineCode" class="form-control" placeholder="MSC, MAEU">
                                <small class="text-muted">SCAC code</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Lambarka Voyage</label>
                                <input type="text" name="voyage_number" id="modalVoyageNumber" class="form-control" placeholder="VOY123">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Dekedda Laga Soo Raray</label>
                                <input type="text" name="port_of_loading" id="modalPortOfLoading" class="form-control" placeholder="Shanghai, Ningbo">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Dekedda Lagu Soo Dejiyay</label>
                                <input type="text" name="port_of_discharge" id="modalPortOfDischarge" class="form-control" placeholder="Mogadishu Port">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Xogta Raadraaca & Goobta -->
                    <div class="section-divider"></div>
                    <div class="section-title"><i class="fas fa-map-marker-alt"></i> Xogta Raadraaca & Goobta</div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Taariikhda La Helay (Arrival Date)</label>
                                <input type="date" name="arrival_date" id="modalArrivalDate" class="form-control">
                                <small class="text-muted">Markii kontaynerka la helay</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>ETA (Taariikhda La Filayo ee Dekedda)</label>
                                <input type="date" name="eta_port" id="modalEtaPort" class="form-control">
                                <small class="text-muted">Estimated Time of Arrival at Port</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>ETD (Taariikhda Bixitaanka)</label>
                                <input type="date" name="etd_port" id="modalEtdPort" class="form-control">
                                <small class="text-muted">Estimated Time of Departure</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Lambarka Raadraaca</label>
                                <input type="text" name="tracking_number" id="modalTrackingNumber" class="form-control" placeholder="Si otomatik ah ayaa loo sameeyaa">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Lambarka Seal</label>
                                <input type="text" name="seal_number" id="modalSealNumber" class="form-control" placeholder="SEAL123456">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Goobta Hadda</label>
                                <input type="text" name="current_location" id="modalCurrentLocation" class="form-control" placeholder="Dekedda Mogadishu">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Faahfaahin Dheeraad ah / Qoraal</label>
                                <textarea name="notes" id="modalNotes" class="form-control" rows="3" placeholder="Halkan ku qor faahfaahinta kontaynerka..."></textarea>
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
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-eye"></i> Faahfaahinta Kontaynerka</h5>
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
                    <input type="hidden" name="container_id" id="statusContainerId">
                    <div class="form-group">
                        <label>Xaaladda Cusub</label>
                        <select name="status" id="statusNewStatus" class="form-control">
                            <option value="received">La Helay</option>
                            <option value="loaded">La Raray</option>
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

<!-- Unload Container Modal -->
<div class="modal fade" id="unloadModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-box-open"></i> Dajinta Kontaynerka: <span id="unloadContainerNumber"></span></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body"><div id="unloadItemsContent"><div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading items...</p></div></div></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Xir</button></div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="fas fa-trash"></i> Tirtir Kontaynerka</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <div class="modal-body"><p>Ma hubtaa inaad rabto inaad si joogto ah u tirtirto kontaynerka</p><p><strong id="deleteContainerName"></strong>?</p><div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> <strong>Digniin!</strong> Haddii kontaynerkan uu leeyahay safaro ku xiran, kama tirtiri kartid.</div></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Kaalay</button><button type="button" class="btn btn-danger" id="confirmDeleteBtn">Haa, Tirtir</button></div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    let currentPage = 1;
    let deleteId = null;
    
    function loadContainers() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'get_containers',
                page: currentPage,
                search: $('#searchInput').val(),
                tenant: $('#tenantFilter').val(),
                origin: $('#originFilter').val(),
                status: $('#statusFilter').val()
            },
            dataType: 'json',
            success: function(res) {
                $('#containers-table-container').html(res.table_html);
                $('#pagination-container').html(res.pagination_html);
                attachTableEvents();
            },
            error: function() { $('#containers-table-container').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Khalad ayaa dhacay</p></div>'); }
        });
    }
    
    function loadStats() {
        let data = { ajax_action: 'get_stats' };
        if ($('#tenantFilter').val() && $('#tenantFilter').val() != '0') data.tenant = $('#tenantFilter').val();
        $.ajax({ url: window.location.href, type: 'POST', data: data, dataType: 'json',
            success: function(s) {
                $('#stat-total').text(s.total||0); $('#stat-received').text(s.received||0); $('#stat-loaded').text(s.loaded||0);
                $('#stat-dispatched').text(s.dispatched||0); $('#stat-at_port').text(s.at_port||0);
                $('#stat-ready').text(s.ready||0); $('#stat-delivered').text(s.delivered||0);
            }
        });
    }
    
    function attachTableEvents() {
        $('.view-container').off('click').on('click', function() {
            $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'get_container', id: $(this).data('id') }, dataType: 'json',
                success: function(c) {
                    $('#viewModalBody').html(`
                        <div class="row"><div class="col-4"><strong>Kontaynerka:</strong></div><div class="col-8"><strong>${escapeHtml(c.container_number)}</strong></div>
                        <div class="col-4"><strong>BL Number:</strong></div><div class="col-8">${escapeHtml(c.bl_number||'-')}</div>
                        <div class="col-4"><strong>Vessel:</strong></div><div class="col-8">${escapeHtml(c.vessel_name||'-')}</div>
                        <div class="col-4"><strong>Shipping Line:</strong></div><div class="col-8">${escapeHtml(c.shipping_line||'-')}</div>
                        <div class="col-4"><strong>Origin:</strong></div><div class="col-8">${c.origin}</div>
                        <div class="col-4"><strong>CBM:</strong></div><div class="col-8">${parseFloat(c.size_cbm||0).toFixed(2)}</div>
                        <div class="col-4"><strong>Status:</strong></div><div class="col-8">${c.status}</div>
                        <div class="col-4"><strong>Location:</strong></div><div class="col-8">${escapeHtml(c.current_location||'-')}</div>
                        <div class="col-12 mt-3"><strong>Notes:</strong><div class="alert alert-info mt-1">${escapeHtml(c.notes||'-')}</div></div></div>
                    `);
                    $('#viewModal').modal('show');
                }
            });
        });
        
        $('.edit-container').off('click').on('click', function() {
            $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'get_container', id: $(this).data('id') }, dataType: 'json',
                success: function(c) {
                    $('#containerModalLabel').text('Wax Ka Beddel Kontaynerka');
                    $('#container_id').val(c.id); $('#modalTenantId').val(c.tenant_id); $('#modalContainerNumber').val(c.container_number);
                    $('#modalOrigin').val(c.origin); $('#modalSizeCbm').val(c.size_cbm); $('#modalWeightKg').val(c.weight_kg);
                    $('#modalStatus').val(c.status); $('#modalCurrentLocation').val(c.current_location);
                    $('#modalArrivalDate').val(c.arrival_date); $('#modalTrackingNumber').val(c.tracking_number);
                    $('#modalSealNumber').val(c.seal_number); $('#modalNotes').val(c.notes); $('#modalShippingLine').val(c.shipping_line);
                    $('#modalShippingLineCode').val(c.shipping_line_code); $('#modalBlNumber').val(c.bl_number);
                    $('#modalVesselName').val(c.vessel_name); $('#modalVoyageNumber').val(c.voyage_number);
                    $('#modalPortOfLoading').val(c.port_of_loading); $('#modalPortOfDischarge').val(c.port_of_discharge);
                    $('#modalEtaPort').val(c.eta_port); $('#modalEtdPort').val(c.etd_port); $('#modalCustomsStatus').val(c.customs_status);
                    $('#containerModal').modal('show');
                }
            });
        });
        
        $('.update-status').off('click').on('click', function() {
            $('#statusContainerId').val($(this).data('id'));
            $('#statusModal').modal('show');
        });
        
        $('.delete-container').off('click').on('click', function() { deleteId = $(this).data('id'); $('#deleteContainerName').text($(this).data('name')); $('#deleteModal').modal('show'); });
        
        $('#confirmDeleteBtn').off('click').on('click', function() {
            if(deleteId) $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'delete_container', id: deleteId }, dataType: 'json',
                success: function(r) { if(r.success) { $('#deleteModal').modal('hide'); loadContainers(); loadStats(); showAlert('success', r.message); } else showAlert('error', r.message); }
            });
        });
        
        $('#statusForm').off('submit').on('submit', function(e) {
            e.preventDefault();
            $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'update_status', id: $('#statusContainerId').val(), status: $('#statusNewStatus').val() }, dataType: 'json',
                success: function(r) { if(r.success) { $('#statusModal').modal('hide'); loadContainers(); loadStats(); showAlert('success', r.message); } else showAlert('error', r.message); }
            });
        });
        
        $('.unload-container').off('click').on('click', function() {
            const id = $(this).data('id');
            const number = $(this).data('number');
            $('#unloadContainerNumber').text(number);
            $('#unloadModal').modal('show');
            loadUnloadItems(id);
        });
        
        function loadUnloadItems(containerId) {
            $('#unloadItemsContent').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading items...</p></div>');
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_container_items', container_id: containerId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        let html = '<table class="table table-bordered"><thead><tr><th>Alaabta</th><th>Macaamilka</th><th>Tirada</th><th>CBM</th><th>Xaaladda</th><th>Hawlaha</th></tr></thead><tbody>';
                        response.items.forEach(item => {
                            let statusBadge = item.mogadishu_status === 'delivered' ? '<span class="badge badge-success">La Gaarsiiyay</span>' : (item.mogadishu_status === 'in_warehouse' ? '<span class="badge badge-warning">Bakhaarka Xamar</span>' : '<span class="badge badge-secondary">Wali Kunteenarka</span>');
                            html += `<tr><td>${escapeHtml(item.stock_name)}</td><td>${escapeHtml(item.customer_name)}</td><td>${item.quantity}</td><td>${parseFloat(item.cbm_used).toFixed(2)}</td><td>${statusBadge}</td><td><button class="btn btn-sm btn-success update-item-status" data-id="${item.warehouse_stock_id}" data-status="delivered">Sii Macmiilka</button> <button class="btn btn-sm btn-warning update-item-status" data-id="${item.warehouse_stock_id}" data-status="in_warehouse">Bakhaarka</button></td></tr>`;
                        });
                        html += '</tbody></table>';
                        $('#unloadItemsContent').html(html);
                        $('.update-item-status').on('click', function() { updateItemStatus($(this).data('id'), $(this).data('status'), containerId); });
                    } else { $('#unloadItemsContent').html('<div class="alert alert-danger">' + response.message + '</div>'); }
                }
            });
        }
        
        function updateItemStatus(stockId, status, containerId) {
            $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'update_item_delivery_status', stock_id: stockId, status: status }, dataType: 'json',
                success: function(r) { if(r.success) { loadUnloadItems(containerId); showAlert('success', r.message); } else showAlert('error', r.message); }
            });
        }
        
        $('.pagination a').off('click').on('click', function(e) { e.preventDefault(); if($(this).data('page')) { currentPage = $(this).data('page'); loadContainers(); } });
    }
    
    function showAlert(t,m) { $('#alert-placeholder').html(`<div class="alert alert-${t} alert-dismissible fade show"><i class="fas ${t==='success'?'fa-check-circle':'fa-exclamation-circle'}"></i> ${m}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`); setTimeout(()=>$('.alert').fadeOut(3000, function(){$(this).remove();}), 5000); }
    function escapeHtml(t) { if(!t) return ''; return t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
    
    $('#containerForm').submit(function(e) {
        e.preventDefault();
        $.ajax({ url: window.location.href, type: 'POST', data: $(this).serialize() + '&ajax_action=save_container', dataType: 'json',
            success: function(r) { if(r.success) { $('#containerModal').modal('hide'); loadContainers(); loadStats(); showAlert('success', r.message); $('#containerForm')[0].reset(); } else showAlert('error', r.message); }
        });
    });
    
    $('#addContainerBtn, #addContainerBtnEmpty').click(function() { $('#containerModalLabel').text('Qaabilaad Cusub'); $('#containerForm')[0].reset(); $('#container_id').val(''); $('#modalStatus').val('received'); $('#modalCustomsStatus').val('pending'); $('#containerModal').modal('show'); });
    $('#applyFilters').click(function() { currentPage = 1; loadContainers(); loadStats(); });
    $('#resetFilters').click(function() { $('#searchInput').val(''); $('#tenantFilter').val('0'); $('#originFilter').val(''); $('#statusFilter').val(''); currentPage = 1; loadContainers(); loadStats(); });
    
    loadContainers(); loadStats();
});
</script>
</body>
</html>