<?php
// customer/packages.php - Warehouse Stock Management for Customer View
// Allows customers to view their own packages in stock
// Primary: Violet #520066, Secondary: Yellow #f4dd08

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is customer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION['role'];
$customer_id = $_SESSION['customer_id'] ?? 0;
$session_tenant_id = $_SESSION['tenant_id'] ?? 0;

// Security: If no customer ID is assigned, redirect
if (!$customer_id) {
    header("Location: ../dashboard.php?error=no_customer");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Customer';

// Get customer information
$customer_info = null;
try {
    $stmt = $pdo->prepare("
        SELECT c.*, t.name as tenant_name, t.logo_url, t.address as tenant_address, t.phone as tenant_phone
        FROM customers c
        LEFT JOIN tenants t ON c.tenant_id = t.id
        WHERE c.id = ?
    ");
    $stmt->execute([$customer_id]);
    $customer_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customer_info = null;
}

$tenant_name = $customer_info['tenant_name'] ?? 'Cargo Management System';
$customer_name = $customer_info['customer_name'] ?? 'Customer';

// ============================================
// AJAX HANDLERS
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];
    
    try {
        switch ($action) {
            case 'get_stock_items':
                handleGetCustomerStockItems($pdo, $customer_id, $session_tenant_id);
                break;
            case 'get_stock_item':
                handleGetCustomerStockItem($pdo, $customer_id, $session_tenant_id);
                break;
            case 'get_stats':
                handleGetCustomerStats($pdo, $customer_id, $session_tenant_id);
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        error_log("Customer packages error: " . $e->getMessage());
    }
    exit;
}

// ============================================
// AJAX HANDLER FUNCTIONS
// ============================================

function handleGetCustomerStockItems($pdo, $customer_id, $session_tenant_id) {
    $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
    $limit = 15;
    $offset = ($page - 1) * $limit;
    
    $search = $_POST['search'] ?? '';
    $origin_filter = $_POST['origin'] ?? 'all';
    
    $where_conditions = ["ws.customer_id = ?", "ws.tenant_id = ?"];
    $params = [$customer_id, $session_tenant_id];
    
    if (!empty($search)) {
        $where_conditions[] = "(ws.stock_name LIKE ? OR ws.location LIKE ? OR ws.bin_location LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if ($origin_filter !== 'all') {
        $where_conditions[] = "ws.origin = ?";
        $params[] = $origin_filter;
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    // Get total count
    $count_sql = "SELECT COUNT(*) as total FROM warehouse_stock ws $where_clause";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_items / $limit);
    
    // Get stock items for this customer only
    $sql = "
        SELECT ws.*, 
               t.name as tenant_name,
               c.customer_name,
               c.phone,
               c.email,
               c.debt_amount,
               c.loyalty_points,
               (SELECT invoice_number FROM invoices WHERE customer_id = c.id ORDER BY created_at DESC LIMIT 1) as latest_invoice_number,
               (SELECT total_amount FROM invoices WHERE customer_id = c.id ORDER BY created_at DESC LIMIT 1) as latest_inv_total,
               (SELECT paid_amount FROM invoices WHERE customer_id = c.id ORDER BY created_at DESC LIMIT 1) as latest_inv_paid,
               u.full_name as updated_by_name
        FROM warehouse_stock ws
        LEFT JOIN tenants t ON ws.tenant_id = t.id
        LEFT JOIN customers c ON ws.customer_id = c.id
        LEFT JOIN users u ON ws.updated_by = u.id
        $where_clause
        ORDER BY ws.created_at DESC, ws.stock_name ASC
        LIMIT $limit OFFSET $offset
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ob_start();
    renderCustomerPackageTable($stock_items);
    $table_html = ob_get_clean();
    
    ob_start();
    renderPagination($page, $total_pages);
    $pagination_html = ob_get_clean();
    
    echo json_encode([
        'success' => true,
        'table_html' => $table_html,
        'pagination_html' => $pagination_html
    ]);
}

function handleGetCustomerStockItem($pdo, $customer_id, $session_tenant_id) {
    $id = $_POST['id'] ?? 0;
    
    $stmt = $pdo->prepare("
        SELECT ws.*, 
               t.name as tenant_name,
               c.customer_name,
               c.phone,
               c.email,
               c.debt_amount,
               c.loyalty_points,
               u.full_name as updated_by_name,
               (SELECT COUNT(*) FROM stock_movements WHERE warehouse_stock_id = ws.id) as movement_count
        FROM warehouse_stock ws
        LEFT JOIN tenants t ON ws.tenant_id = t.id
        LEFT JOIN customers c ON ws.customer_id = c.id
        LEFT JOIN users u ON ws.updated_by = u.id
        WHERE ws.id = ? AND ws.customer_id = ? AND ws.tenant_id = ?
    ");
    $stmt->execute([$id, $customer_id, $session_tenant_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        echo json_encode(['success' => false, 'message' => 'Package not found']);
        exit;
    }
    
    echo json_encode(['success' => true, 'data' => $item]);
}

function handleGetCustomerStats($pdo, $customer_id, $session_tenant_id) {
    // Get package stats for this customer
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_packages,
            SUM(quantity) as total_quantity,
            SUM(volume_cbm) as total_volume,
            SUM(quantity * unit_price) as total_value,
            COUNT(CASE WHEN quantity <= minimum_stock THEN 1 END) as low_stock_items,
            COUNT(CASE WHEN origin = 'china_yiwu' THEN 1 END) as yiwu_items,
            COUNT(CASE WHEN origin = 'china_guangzhou' THEN 1 END) as guangzhou_items,
            COUNT(CASE WHEN origin = 'dubai' THEN 1 END) as dubai_items,
            SUM(CASE WHEN quantity > 0 THEN 1 ELSE 0 END) as active_packages
        FROM warehouse_stock
        WHERE customer_id = ? AND tenant_id = ?
    ");
    $stmt->execute([$customer_id, $session_tenant_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get recent movements for this customer's packages
    $movements = $pdo->prepare("
        SELECT sm.*, ws.stock_name, ws.origin, u.full_name as created_by_name
        FROM stock_movements sm
        LEFT JOIN warehouse_stock ws ON sm.warehouse_stock_id = ws.id
        LEFT JOIN users u ON sm.created_by = u.id
        WHERE ws.customer_id = ? AND ws.tenant_id = ?
        ORDER BY sm.created_at DESC
        LIMIT 15
    ");
    $movements->execute([$customer_id, $session_tenant_id]);
    $recent_movements = $movements->fetchAll(PDO::FETCH_ASSOC);
    
    // Get origin breakdown for charts
    $origin_breakdown = $pdo->prepare("
        SELECT origin, SUM(quantity) as total_quantity, COUNT(*) as package_count
        FROM warehouse_stock
        WHERE customer_id = ? AND tenant_id = ?
        GROUP BY origin
    ");
    $origin_breakdown->execute([$customer_id, $session_tenant_id]);
    $origins = $origin_breakdown->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'recent_movements' => $recent_movements,
        'origins' => $origins
    ]);
}

// ============================================
// RENDER COMPONENTS
// ============================================

function renderCustomerPackageTable($stock_items) {
    if (count($stock_items) === 0): ?>
        <div class="empty-state">
            <i class="fas fa-box-open"></i>
            <h3>No Packages Found</h3>
            <p>You don't have any packages in stock at the moment.</p>
            <p class="text-muted small">If you believe this is an error, please contact our customer support.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="stock-table">
                <thead>
                    <tr>
                        <th>Package ID</th>
                        <th>Package Details</th>
                        <th>Origin</th>
                        <th>Quantity</th>
                        <th>Volume</th>
                        <th>Status</th>
                        <th>Value</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stock_items as $item): 
                        $isLowStock = $item['quantity'] <= $item['minimum_stock'];
                        $quantityPercent = $item['maximum_stock'] > 0 ? min(100, ($item['quantity'] / $item['maximum_stock']) * 100) : 0;
                        $unit = ($item['origin'] === 'dubai') ? 'FT' : 'CBM';
                        $originMap = [
                            'china_yiwu' => ['text' => 'China (Yiwu)', 'icon' => '🇨🇳', 'class' => 'origin-china_yiwu'],
                            'china_guangzhou' => ['text' => 'China (Guangzhou)', 'icon' => '🇨🇳', 'class' => 'origin-china_guangzhou'],
                            'dubai' => ['text' => 'Dubai', 'icon' => '🇦🇪', 'class' => 'origin-dubai']
                        ];
                        $origin = $originMap[$item['origin']] ?? ['text' => ucfirst($item['origin']), 'icon' => '📦', 'class' => 'origin-local'];
                    ?>
                        <tr class="<?= $isLowStock ? 'low-stock-row' : '' ?>">
                            <td>
                                <span class="package-id">#<?= $item['id'] ?></span>
                                <div class="stock-sku">SKU: STK-<?= str_pad($item['id'], 5, '0', STR_PAD_LEFT) ?></div>
                            </td>
                            <td>
                                <div class="package-name"><?= htmlspecialchars($item['stock_name'] ?? 'Unnamed Package') ?></div>
                                <?php if ($item['location']): ?>
                                    <div class="package-location">
                                        <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($item['location']) ?>
                                        <?php if ($item['bin_location']): ?> / <?= htmlspecialchars($item['bin_location']) ?><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            </td>
                            <td><span class="origin-badge <?= $origin['class'] ?>"><?= $origin['icon'] ?> <?= $origin['text'] ?></span></td>
                            <td>
                                <div class="quantity-value <?= $isLowStock ? 'text-warning' : 'text-success' ?>">
                                    <?= number_format($item['quantity']) ?> units
                                </div>
                                <?php if ($item['minimum_stock'] > 0): ?>
                                    <div class="stock-level">
                                        <div class="progress-bar-container">
                                            <div class="progress-bar" style="width: <?= $quantityPercent ?>%; background: <?= $isLowStock ? '#f4b400' : '#0F7A3A' ?>;"></div>
                                        </div>
                                        <small>Min: <?= $item['minimum_stock'] ?></small>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format($item['volume_cbm'], 2) ?> <?= $unit ?></td>
                            <td>
                                <span class="stock-badge <?= $isLowStock ? 'status-low' : 'status-good' ?>">
                                    <?= $isLowStock ? 'Running Low' : 'In Stock' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($item['unit_price'] > 0): ?>
                                    <div class="unit-price">$<?= number_format($item['unit_price'], 2) ?>/<?= $unit ?></div>
                                    <div class="total-value"><strong>$<?= number_format($item['volume_cbm'] * $item['unit_price'], 2) ?></strong></div>
                                <?php else: ?>
                                    <span class="text-muted">Price on request</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn btn-view view-stock" data-id="<?= $item['id'] ?>" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="action-btn btn-whatsapp whatsapp-package" 
                                            data-phone="<?= htmlspecialchars($item['phone'] ?? '') ?>"
                                            data-name="<?= htmlspecialchars($customer_name ?? 'Customer') ?>"
                                            data-item="<?= htmlspecialchars($item['stock_name'] ?? 'Package') ?>"
                                            data-id="<?= $item['id'] ?>"
                                            data-qty="<?= $item['quantity'] ?>"
                                            data-cbm="<?= number_format($item['volume_cbm'], 2) ?>"
                                            data-unit="<?= $unit ?>"
                                            data-location="<?= htmlspecialchars($item['location'] ?? 'warehouse') ?>"
                                            title="Inquire via WhatsApp">
                                        <i class="fab fa-whatsapp"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif;
}

function renderPagination($page, $total_pages) {
    if ($total_pages <= 1) return;
    ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a data-page="<?= $page-1 ?>"><i class="fas fa-chevron-left"></i> Previous</a>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <?php if ($i == $page): ?>
                <span class="active"><?= $i ?></span>
            <?php else: ?>
                <a data-page="<?= $i ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $total_pages): ?>
            <a data-page="<?= $page+1 ?>">Next <i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php
}

function renderCustomerStatsCards() {
    ?>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-info">
                <h4>Total Packages</h4>
                <div class="stat-number" id="stat-total-packages">0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-boxes"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h4>Total Quantity</h4>
                <div class="stat-number" id="stat-total-quantity">0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-cubes"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h4>Total Volume</h4>
                <div class="stat-number" id="stat-total-volume">0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-cube"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h4>Total Value</h4>
                <div class="stat-number" id="stat-total-value">$0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
        </div>
        <div class="stat-card stat-card-warning">
            <div class="stat-info">
                <h4>Low Stock Alert</h4>
                <div class="stat-number" id="stat-low-stock">0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h4>Active Packages</h4>
                <div class="stat-number" id="stat-active">0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
        </div>
    </div>
    <?php
}

function renderCustomerFilters() {
    ?>
    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group search-group">
                <label><i class="fas fa-search"></i> Search Package</label>
                <input type="text" id="searchInput" placeholder="Package name or location...">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-globe"></i> Origin</label>
                <select id="originFilter">
                    <option value="all">All Origins</option>
                    <option value="china_yiwu">China Yiwu 🇨🇳</option>
                    <option value="china_guangzhou">China Guangzhou 🇨🇳</option>
                    <option value="dubai">Dubai 🇦🇪</option>
                </select>
            </div>
            <div class="filter-group filter-actions">
                <button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Filter</button>
                <button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Reset</button>
            </div>
        </div>
    </div>
    <?php
}

function renderMovementsSection() {
    ?>
    <div class="movements-card">
        <div class="card-header">
            <h4><i class="fas fa-history"></i> Recent Package Activity</h4>
            <p class="text-muted small">Track the status of your packages</p>
        </div>
        <div id="movementsList">
            <div class="loading-spinner" style="padding: 1.5rem;">
                <i class="fas fa-spinner fa-spin"></i> Loading activity...
            </div>
        </div>
    </div>
    <?php
}

// Include header
require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>My Packages - <?= htmlspecialchars($tenant_name) ?> | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #f0f2f5;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #1a1a2e;
            line-height: 1.5;
        }
        
        :root {
            --violet: #2D1859;
            --violet-dark: #1F0F3D;
            --violet-light: #4B2C85;
            --violet-soft: #f3e8f7;
            --yellow: #F5C410;
            --yellow-dark: #D4A70C;
            --success: #10b981;
            --success-light: #d1fae5;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --info: #3b82f6;
            --info-light: #dbeafe;
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
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
            --radius-sm: 0.375rem;
            --radius: 0.5rem;
            --radius-md: 0.75rem;
            --radius-lg: 1rem;
        }
        
        /* Container */
        .packages-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }
        
        @media (min-width: 768px) {
            .packages-container {
                padding: 1.5rem;
            }
        }
        
        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--violet) 0%, var(--violet-light) 100%);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            pointer-events: none;
        }
        
        .welcome-banner h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .welcome-banner h1 i {
            font-size: 2rem;
        }
        
        .welcome-banner p {
            opacity: 0.9;
            font-size: 0.875rem;
        }
        
        .customer-info {
            margin-top: 1rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .info-chip {
            background: rgba(255, 255, 255, 0.15);
            padding: 0.375rem 0.875rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
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
        
        .stat-card-warning .stat-info .stat-number {
            color: var(--warning);
        }
        
        .stat-card-warning .stat-icon {
            background: var(--warning-light);
        }
        
        .stat-card-warning .stat-icon i {
            color: var(--warning);
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
        
        @media (min-width: 640px) {
            .filter-form {
                flex-direction: row;
                flex-wrap: wrap;
                align-items: flex-end;
                gap: 1rem;
            }
        }
        
        .filter-group {
            flex: 1;
            min-width: 160px;
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
        
        .filter-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
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
        .table-responsive {
            overflow-x: auto;
            background: white;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-100);
        }
        
        .stock-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
        }
        
        .stock-table th,
        .stock-table td {
            padding: 0.875rem 1rem;
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
            background: var(--warning-light);
        }
        
        .low-stock-row:hover {
            background: #fef0db !important;
        }
        
        /* Package Components */
        .package-id {
            font-weight: 700;
            color: var(--violet);
            font-size: 0.875rem;
        }
        
        .stock-sku {
            font-size: 0.6875rem;
            color: var(--gray-400);
            margin-top: 0.125rem;
        }
        
        .package-name {
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 0.25rem;
        }
        
        .package-location {
            font-size: 0.6875rem;
            color: var(--gray-500);
        }
        
        .package-location i {
            font-size: 0.625rem;
        }
        
        /* Origin Badges */
        .origin-badge {
            display: inline-block;
            padding: 0.25rem 0.625rem;
            border-radius: 2rem;
            font-size: 0.6875rem;
            font-weight: 600;
        }
        
        .origin-china_yiwu,
        .origin-china_guangzhou {
            background: #e3f2fd;
            color: #1565c0;
        }
        
        .origin-dubai {
            background: #fff3e0;
            color: #e65100;
        }
        
        .origin-local {
            background: #EEFBF3;
            color: #0F7A3A;
        }
        
        /* Quantity */
        .quantity-value {
            font-weight: 700;
            font-size: 0.875rem;
        }
        
        .text-success {
            color: var(--success);
        }
        
        .text-warning {
            color: var(--warning);
        }
        
        .stock-level {
            margin-top: 0.25rem;
        }
        
        .progress-bar-container {
            width: 80px;
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
        
        /* Status Badges */
        .stock-badge {
            display: inline-block;
            padding: 0.25rem 0.625rem;
            border-radius: 2rem;
            font-size: 0.6875rem;
            font-weight: 600;
        }
        
        .status-good {
            background: var(--success-light);
            color: var(--success);
        }
        
        .status-low {
            background: var(--warning-light);
            color: var(--warning);
        }
        
        /* Price */
        .unit-price {
            font-size: 0.75rem;
            color: var(--gray-600);
        }
        
        .total-value {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--violet);
            margin-top: 0.125rem;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
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
        
        .btn-whatsapp {
            background: #dcf8c5;
            color: #25d366;
        }
        
        .btn-whatsapp:hover {
            background: #25d366;
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
        
        /* Movements Card */
        .movements-card {
            background: white;
            border-radius: var(--radius-md);
            margin-top: 1.5rem;
            border: 1px solid var(--gray-100);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        
        .movements-card .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--gray-100);
            background: var(--gray-50);
        }
        
        .movements-card .card-header h4 {
            font-size: 0.875rem;
            margin: 0;
            color: var(--violet);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .movements-card .card-header p {
            margin-top: 0.25rem;
            font-size: 0.6875rem;
        }
        
        #movementsList {
            padding: 0.5rem 1.25rem;
            max-height: 300px;
            overflow-y: auto;
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
        
        .movement-badge {
            display: inline-block;
            padding: 0.1875rem 0.5rem;
            border-radius: 2rem;
            font-size: 0.625rem;
            font-weight: 600;
        }
        
        .movement-in {
            background: var(--success-light);
            color: var(--success);
        }
        
        .movement-out {
            background: var(--danger-light);
            color: var(--danger);
        }
        
        .movement-move {
            background: var(--info-light);
            color: var(--info);
        }
        
        .movement-adjust {
            background: var(--warning-light);
            color: var(--warning);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--gray-400);
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            font-size: 1.125rem;
            margin-bottom: 0.5rem;
            color: var(--gray-600);
        }
        
        .empty-state p {
            font-size: 0.875rem;
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
        
        .detail-row {
            display: flex;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .detail-label {
            width: 120px;
            font-weight: 600;
            color: var(--gray-600);
            font-size: 0.75rem;
        }
        
        .detail-value {
            flex: 1;
            font-size: 0.875rem;
            color: var(--gray-800);
        }
        
        .loading-spinner {
            text-align: center;
            padding: 2rem;
        }
        
        .loading-spinner i {
            font-size: 1.5rem;
            color: var(--violet);
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .text-muted {
            color: var(--gray-400);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stock-table th:nth-child(5),
            .stock-table td:nth-child(5),
            .stock-table th:nth-child(6),
            .stock-table td:nth-child(6) {
                display: none;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-btn {
                width: 36px;
                height: 36px;
            }
        }
        
        @media (max-width: 480px) {
            .stock-table th:nth-child(3),
            .stock-table td:nth-child(3),
            .stock-table th:nth-child(4),
            .stock-table td:nth-child(4) {
                display: none;
            }
        }
    </style>
</head>
<body>

<div class="packages-container">
    <div id="alert-placeholder"></div>
    
    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <h1>
            <i class="fas fa-box-open"></i>
            My Packages
        </h1>
        <p>Track and manage your packages stored in our warehouse</p>
        <div class="customer-info">
            <span class="info-chip"><i class="fas fa-user"></i> <?= htmlspecialchars($customer_name) ?></span>
            <?php if ($customer_info && $customer_info['phone']): ?>
            <span class="info-chip"><i class="fas fa-phone"></i> <?= htmlspecialchars($customer_info['phone']) ?></span>
            <?php endif; ?>
            <?php if ($customer_info && $customer_info['email']): ?>
            <span class="info-chip"><i class="fas fa-envelope"></i> <?= htmlspecialchars($customer_info['email']) ?></span>
            <?php endif; ?>
            <?php if ($customer_info && $customer_info['debt_amount'] > 0): ?>
            <span class="info-chip" style="background: rgba(239, 68, 68, 0.2);"><i class="fas fa-credit-card"></i> Outstanding: $<?= number_format($customer_info['debt_amount'], 2) ?></span>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Stats Cards -->
    <?php renderCustomerStatsCards(); ?>
    
    <!-- Filters -->
    <?php renderCustomerFilters(); ?>
    
    <!-- Stock Table Container -->
    <div id="stock-table-container">
        <div class="loading-spinner">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading your packages...</p>
        </div>
    </div>
    <div id="pagination-container"></div>
    
    <!-- Recent Movements -->
    <?php renderMovementsSection(); ?>
</div>

<!-- View Package Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: var(--radius-md);">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-box"></i> Package Details</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <div class="text-center p-4">
                    <i class="fas fa-spinner fa-spin"></i> Loading...
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    let currentPage = 1;
    let customerName = '<?= htmlspecialchars($customer_name) ?>';
    let customerPhone = '<?= htmlspecialchars($customer_info['phone'] ?? '') ?>';
    
    // Load stock items
    function loadStockItems() {
        const postData = {
            ajax_action: 'get_stock_items',
            page: currentPage,
            search: $('#searchInput').val(),
            origin: $('#originFilter').val()
        };
        
        $('#stock-table-container').html('<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i><p>Loading your packages...</p></div>');
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: postData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#stock-table-container').html(response.table_html);
                    $('#pagination-container').html(response.pagination_html);
                    attachTableEvents();
                } else {
                    $('#stock-table-container').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading packages</p></div>');
                }
            },
            error: function() {
                $('#stock-table-container').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading packages. Please try again.</p></div>');
            }
        });
    }
    
    // Load stats
    function loadStats() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_stats' },
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    const stats = data.stats;
                    const movements = data.recent_movements;
                    const origins = data.origins;
                    
                    $('#stat-total-packages').text(stats.total_packages || 0);
                    $('#stat-total-quantity').text((stats.total_quantity || 0).toLocaleString());
                    $('#stat-total-volume').text(parseFloat(stats.total_volume || 0).toFixed(2) + ' CBM');
                    $('#stat-total-value').text('$' + parseFloat(stats.total_value || 0).toFixed(2));
                    $('#stat-low-stock').text(stats.low_stock_items || 0);
                    $('#stat-active').text(stats.active_packages || 0);
                    
                    // Render movements
                    let movementsHtml = '';
                    if (movements && movements.length > 0) {
                        movements.forEach(function(m) {
                            const movementType = m.movement_type;
                            let typeClass = 'movement-adjust';
                            let typeText = 'Adjusted';
                            
                            if (movementType === 'in') {
                                typeClass = 'movement-in';
                                typeText = 'Stock In';
                            } else if (movementType === 'out') {
                                typeClass = 'movement-out';
                                typeText = 'Stock Out';
                            } else if (movementType === 'move') {
                                typeClass = 'movement-move';
                                typeText = 'Moved';
                            }
                            
                            const changeIcon = m.quantity_change > 0 ? '↑' : (m.quantity_change < 0 ? '↓' : '↔');
                            const changeColor = m.quantity_change > 0 ? 'text-success' : (m.quantity_change < 0 ? 'text-danger' : 'text-info');
                            
                            movementsHtml += `
                                <div class="movement-item">
                                    <div>
                                        <strong>${escapeHtml(m.stock_name)}</strong>
                                        <div>
                                            <span class="movement-badge ${typeClass}">${typeText}</span>
                                            <small>${m.created_at}</small>
                                        </div>
                                    </div>
                                    <div class="${changeColor}">
                                        ${changeIcon} ${Math.abs(m.quantity_change)} units
                                    </div>
                                </div>
                            `;
                        });
                    } else {
                        movementsHtml = '<div class="text-muted text-center py-3">No recent activity for your packages</div>';
                    }
                    $('#movementsList').html(movementsHtml);
                }
            },
            error: function() {
                $('#movementsList').html('<div class="text-muted text-center py-3">Unable to load activity</div>');
            }
        });
    }
    
    function attachTableEvents() {
        // View button
        $('.view-stock').off('click').on('click', function() {
            const id = $(this).data('id');
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_stock_item', id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data) {
                        const item = response.data;
                        const originMap = {
                            'china_yiwu': 'China Yiwu 🇨🇳',
                            'china_guangzhou': 'China Guangzhou 🇨🇳',
                            'dubai': 'Dubai 🇦🇪'
                        };
                        const originText = originMap[item.origin] || item.origin;
                        const unit = item.origin === 'dubai' ? 'FT' : 'CBM';
                        const isLowStock = item.quantity <= item.minimum_stock;
                        
                        $('#viewModalBody').html(`
                            <div class="detail-row">
                                <div class="detail-label">Package Name</div>
                                <div class="detail-value"><strong>${escapeHtml(item.stock_name || 'Unnamed Package')}</strong></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Package ID</div>
                                <div class="detail-value">#${item.id}</div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">SKU</div>
                                <div class="detail-value">STK-${String(item.id).padStart(5, '0')}</div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Origin</div>
                                <div class="detail-value">${originText}</div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Quantity</div>
                                <div class="detail-value ${isLowStock ? 'text-warning' : 'text-success'}">
                                    ${Number(item.quantity).toLocaleString()} units
                                    ${item.minimum_stock > 0 ? `<small class="text-muted"> (Min: ${item.minimum_stock})</small>` : ''}
                                </div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Volume</div>
                                <div class="detail-value">${parseFloat(item.volume_cbm).toFixed(2)} ${unit}</div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Location</div>
                                <div class="detail-value">${escapeHtml(item.location || 'Not specified')}</div>
                            </div>
                            ${item.bin_location ? `
                            <div class="detail-row">
                                <div class="detail-label">Bin Location</div>
                                <div class="detail-value">${escapeHtml(item.bin_location)}</div>
                            </div>
                            ` : ''}
                            ${item.zone ? `
                            <div class="detail-row">
                                <div class="detail-label">Zone</div>
                                <div class="detail-value">${escapeHtml(item.zone)}</div>
                            </div>
                            ` : ''}
                            <div class="detail-row">
                                <div class="detail-label">Unit Price</div>
                                <div class="detail-value">${item.unit_price > 0 ? '$' + parseFloat(item.unit_price).toFixed(2) + '/' + unit : 'Price on request'}</div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Total Value</div>
                                <div class="detail-value"><strong>$${(parseFloat(item.volume_cbm) * parseFloat(item.unit_price)).toFixed(2)}</strong></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Last Updated</div>
                                <div class="detail-value">${item.last_updated || 'Not available'}</div>
                            </div>
                            ${item.movement_count ? `
                            <div class="detail-row">
                                <div class="detail-label">Movement History</div>
                                <div class="detail-value">${item.movement_count} records</div>
                            </div>
                            ` : ''}
                        `);
                        $('#viewModal').modal('show');
                    } else {
                        showAlert('error', response.message || 'Could not load package details');
                    }
                },
                error: function() {
                    showAlert('error', 'Error loading package details');
                }
            });
        });
        
        // WhatsApp inquiry button
        $('.whatsapp-package').off('click').on('click', function() {
            let phone = $(this).data('phone');
            const name = $(this).data('name');
            const item = $(this).data('item');
            const packageId = $(this).data('id');
            const qty = $(this).data('qty');
            const cbm = $(this).data('cbm');
            const unit = $(this).data('unit');
            const location = $(this).data('location');
            
            if (phone) {
                phone = phone.toString().replace(/\D/g, '');
                if (phone.length === 9 && (phone.startsWith('6') || phone.startsWith('7'))) {
                    phone = '252' + phone;
                }
            }
            
            // If no phone from customer record, use customer's phone from session
            if (!phone && customerPhone) {
                phone = customerPhone.toString().replace(/\D/g, '');
                if (phone.length === 9 && (phone.startsWith('6') || phone.startsWith('7'))) {
                    phone = '252' + phone;
                }
            }
            
            if (!phone) {
                showAlert('error', 'No phone number available. Please contact support directly.');
                return;
            }
            
            const message = `Hello ${name},\n\nI would like to inquire about my package:\n\n` +
                `📦 Package: *${item}*\n` +
                `🆔 ID: #${packageId}\n` +
                `📊 Quantity: ${qty} units\n` +
                `📏 Volume: ${cbm} ${unit}\n` +
                `📍 Location: ${location}\n\n` +
                `Please provide me with more information about this package. Thank you!`;
            
            const url = `https://api.whatsapp.com/send?phone=${phone}&text=${encodeURIComponent(message)}`;
            window.open(url, '_blank');
        });
        
        // Pagination
        $('.pagination a').off('click').on('click', function(e) {
            e.preventDefault();
            const page = $(this).data('page');
            if (page) {
                currentPage = page;
                loadStockItems();
                $('html, body').animate({ scrollTop: $('#stock-table-container').offset().top - 100 }, 300);
            }
        });
    }
    
    function showAlert(type, message) {
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        const alertHtml = `
            <div class="alert alert-${type} alert-dismissible fade show">
                <i class="fas ${icon}"></i> ${message}
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        `;
        $('#alert-placeholder').html(alertHtml);
        setTimeout(function() {
            $('.alert').fadeOut(3000, function() { $(this).remove(); });
        }, 5000);
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    
    // Event handlers
    $('#applyFilters').click(function() {
        currentPage = 1;
        loadStockItems();
        loadStats();
    });
    
    $('#resetFilters').click(function() {
        $('#searchInput').val('');
        $('#originFilter').val('all');
        currentPage = 1;
        loadStockItems();
        loadStats();
    });
    
    $('#searchInput').keypress(function(e) {
        if (e.which === 13) {
            currentPage = 1;
            loadStockItems();
        }
    });
    
    // Initialize
    loadStockItems();
    loadStats();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>