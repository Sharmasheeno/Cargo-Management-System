<?php
// branch_manager/dashboard.php
// Dashboard for faras cargo - Branch Manager

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is branch_manager
if (!isset($_SESSION['user_id']) || ($_SESSION['role_type'] ?? $_SESSION['role'] ?? '') !== 'branch_manager') {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION['role_type'] ?? $_SESSION['role'] ?? 'branch_manager';
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'User';

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';

// Get branch manager's assigned branch
$assigned_branch_id = $_SESSION['assigned_branch_id'] ?? null;
$can_manage_branch = $_SESSION['can_manage_branch'] ?? false;

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
            $can_manage_branch = $branchAssign['can_manage_branch'];
            $_SESSION['assigned_branch_id'] = $assigned_branch_id;
            $_SESSION['can_manage_branch'] = $can_manage_branch;
        }
    } catch (PDOException $e) {}
}

// If still no branch, show error
if (!$assigned_branch_id) {
    echo '<div class="alert alert-danger">You are not assigned to any branch. Please contact administrator.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Get branch details
$branch_name = '';
$branch_code = '';
$branch_type = '';
$branch_address = '';
$branch_phone = '';
$branch_email = '';

try {
    $stmt = $pdo->prepare("
        SELECT branch_name, branch_code, branch_type, address, phone, email, manager_name 
        FROM branches 
        WHERE id = ? AND tenant_id = ? AND is_active = 1
    ");
    $stmt->execute([$assigned_branch_id, $_SESSION['tenant_id']]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) {
        $branch_name = $branch['branch_name'];
        $branch_code = $branch['branch_code'];
        $branch_type = $branch['branch_type'];
        $branch_address = $branch['address'] ?? '';
        $branch_phone = $branch['phone'] ?? '';
        $branch_email = $branch['email'] ?? '';
    }
} catch (PDOException $e) {}

// ==============================================
// DASHBOARD STATISTICS
// ==============================================

// 1. Today's Reception Count
$today_receptions = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM packages 
        WHERE current_branch_id = ? 
        AND DATE(created_at) = CURDATE()
    ");
    $stmt->execute([$assigned_branch_id]);
    $today_receptions = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
} catch (PDOException $e) {}

// 2. Total Stock in Branch
$total_stock_items = 0;
$total_stock_volume = 0;
try {
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(bs.quantity), 0) as total_items,
            COALESCE(SUM(ws.volume_cbm * bs.quantity), 0) as total_volume
        FROM branch_stock bs
        JOIN warehouse_stock ws ON bs.warehouse_stock_id = ws.id
        WHERE bs.branch_id = ?
    ");
    $stmt->execute([$assigned_branch_id]);
    $stockData = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_stock_items = $stockData['total_items'] ?? 0;
    $total_stock_volume = $stockData['total_volume'] ?? 0;
} catch (PDOException $e) {}

// 3. Pending Deliveries
$pending_deliveries = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM packages 
        WHERE current_branch_id = ? 
        AND status IN ('warehouse', 'out_for_delivery', 'pending')
    ");
    $stmt->execute([$assigned_branch_id]);
    $pending_deliveries = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
} catch (PDOException $e) {}

// 4. Today's Deliveries
$today_deliveries = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM packages 
        WHERE current_branch_id = ? 
        AND status = 'delivered'
        AND DATE(delivered_date) = CURDATE()
    ");
    $stmt->execute([$assigned_branch_id]);
    $today_deliveries = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
} catch (PDOException $e) {}

// 5. Active Containers at Branch
$active_containers = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM containers 
        WHERE current_branch_id = ? 
        AND status NOT IN ('delivered', 'completed')
    ");
    $stmt->execute([$assigned_branch_id]);
    $active_containers = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
} catch (PDOException $e) {}

// 6. Low Stock Alerts
$low_stock_count = 0;
$low_stock_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT ws.id, ws.stock_name, bs.quantity, ws.minimum_stock
        FROM branch_stock bs
        JOIN warehouse_stock ws ON bs.warehouse_stock_id = ws.id
        WHERE bs.branch_id = ? AND bs.quantity <= ws.minimum_stock AND ws.minimum_stock > 0
        LIMIT 5
    ");
    $stmt->execute([$assigned_branch_id]);
    $low_stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $low_stock_count = count($low_stock_items);
} catch (PDOException $e) {}

// 7. Today's Revenue (from receipts)
$today_revenue = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total FROM receipts 
        WHERE branch_id = ? 
        AND DATE(created_at) = CURDATE()
    ");
    $stmt->execute([$assigned_branch_id]);
    $today_revenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
} catch (PDOException $e) {}

// 8. Monthly Revenue
$month_revenue = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total FROM receipts 
        WHERE branch_id = ? 
        AND MONTH(created_at) = MONTH(CURDATE())
        AND YEAR(created_at) = YEAR(CURDATE())
    ");
    $stmt->execute([$assigned_branch_id]);
    $month_revenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
} catch (PDOException $e) {}

// 9. Total Customers for this branch
$total_customers = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT c.id) as count 
        FROM customers c
        JOIN packages p ON p.customer_id = c.id
        WHERE p.current_branch_id = ?
    ");
    $stmt->execute([$assigned_branch_id]);
    $total_customers = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
} catch (PDOException $e) {}

// 10. Total Shipments (Trips) from/to this branch
$total_shipments = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM trucking_trips 
        WHERE from_branch_id = ? OR to_branch_id = ?
    ");
    $stmt->execute([$assigned_branch_id, $assigned_branch_id]);
    $total_shipments = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
} catch (PDOException $e) {}

// ==============================================
// RECENT ACTIVITIES
// ==============================================

// Recent Receptions
$recent_receptions = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.*, c.customer_name 
        FROM packages p
        LEFT JOIN customers c ON p.customer_id = c.id
        WHERE p.current_branch_id = ?
        ORDER BY p.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$assigned_branch_id]);
    $recent_receptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Recent Deliveries
$recent_deliveries = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.*, c.customer_name 
        FROM packages p
        LEFT JOIN customers c ON p.customer_id = c.id
        WHERE p.current_branch_id = ? 
        AND p.status = 'delivered'
        ORDER BY p.delivered_date DESC
        LIMIT 10
    ");
    $stmt->execute([$assigned_branch_id]);
    $recent_deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Active Trips from this branch
$active_trips = [];
try {
    $stmt = $pdo->prepare("
        SELECT tt.*, 
               c.container_number,
               c.origin,
               fb.branch_name as from_branch,
               tb.branch_name as to_branch,
               d.full_name as driver_name,
               d.phone as driver_phone
        FROM trucking_trips tt
        JOIN containers c ON tt.container_id = c.id
        LEFT JOIN branches fb ON tt.from_branch_id = fb.id
        LEFT JOIN branches tb ON tt.to_branch_id = tb.id
        LEFT JOIN drivers d ON tt.driver_id = d.id
        WHERE (tt.from_branch_id = ? OR tt.to_branch_id = ?)
        AND tt.status NOT IN ('completed', 'delivered')
        ORDER BY tt.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$assigned_branch_id, $assigned_branch_id]);
    $active_trips = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Stock by Category/Origin
$stock_by_origin = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            ws.origin,
            COALESCE(SUM(bs.quantity), 0) as total_quantity,
            COALESCE(SUM(ws.volume_cbm * bs.quantity), 0) as total_volume
        FROM branch_stock bs
        JOIN warehouse_stock ws ON bs.warehouse_stock_id = ws.id
        WHERE bs.branch_id = ?
        GROUP BY ws.origin
    ");
    $stmt->execute([$assigned_branch_id]);
    $stock_by_origin = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Recent Customers
$recent_customers = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.customer_name, c.phone, c.email, c.created_at
        FROM customers c
        JOIN packages p ON p.customer_id = c.id
        WHERE p.current_branch_id = ?
        ORDER BY c.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$assigned_branch_id]);
    $recent_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Chart data for last 7 days
$weekly_receptions = [];
$weekly_deliveries = [];
$days_labels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $day_name = date('D', strtotime($date));
    
    try {
        // Receptions
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM packages 
            WHERE current_branch_id = ? AND DATE(created_at) = ?
        ");
        $stmt->execute([$assigned_branch_id, $date]);
        $weekly_receptions[$day_name] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        
        // Deliveries
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM packages 
            WHERE current_branch_id = ? AND status = 'delivered' AND DATE(delivered_date) = ?
        ");
        $stmt->execute([$assigned_branch_id, $date]);
        $weekly_deliveries[$day_name] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    } catch (PDOException $e) {
        $weekly_receptions[$day_name] = 0;
        $weekly_deliveries[$day_name] = 0;
    }
}

// Function to get status badge class
function getStatusBadgeClass($status)
{
    $classes = [
        'pending' => 'status-pending',
        'received' => 'status-received',
        'in_transit' => 'status-transit',
        'warehouse' => 'status-warehouse',
        'out_for_delivery' => 'status-out-delivery',
        'delivered' => 'status-delivered',
        'cancelled' => 'status-cancelled',
        'loading' => 'status-loading',
        'loaded' => 'status-loaded',
        'dispatched' => 'status-dispatched',
        'at_port' => 'status-at_port',
        'ready' => 'status-ready',
        'arrived' => 'status-arrived'
    ];
    return $classes[$status] ?? 'status-default';
}

// Function to get origin label
function getOriginLabel($origin)
{
    $origins = [
        'china_yiwu' => 'China (Yiwu)',
        'china_guangzhou' => 'China (Guangzhou)',
        'dubai' => 'Dubai',
        'local' => 'Local'
    ];
    return $origins[$origin] ?? ucfirst($origin);
}

include_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Manager Dashboard | <?= htmlspecialchars($system_name ?? 'Cargo Management System') ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --curdun-violet: #2D1859;
            --curdun-yellow: #F5C410;
            --curdun-violet-light: #4B2C85;
            --curdun-yellow-dark: #D4A70C;
            --curdun-white: #FFFFFF;
            --curdun-dark: #2D2D2D;
            --curdun-gray: #6c757d;
            --curdun-danger: #B42318;
            --curdun-success: #0F7A3A;
            --curdun-blue: #1976d2;
            --curdun-orange: #f57c00;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fb;
        }

        .dashboard-container {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Welcome Section */
        .welcome-section {
            background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light));
            border-radius: 16px;
            padding: 25px 30px;
            color: white;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .welcome-section h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .welcome-section h1 i {
            margin-right: 10px;
        }

        .welcome-section .branch-info {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.2);
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            font-size: 13px;
        }

        .welcome-section .branch-info i {
            margin-right: 5px;
            color: var(--curdun-yellow);
        }

        .datetime {
            margin-top: 15px;
            font-size: 13px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            padding-top: 15px;
        }

        /* Statistics Cards */
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border-left: 4px solid var(--curdun-violet);
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(82, 0, 102, 0.15);
        }

        .stat-info h3 {
            font-size: 32px;
            font-weight: 700;
            color: var(--curdun-violet);
            margin-bottom: 5px;
        }

        .stat-info p {
            font-size: 14px;
            color: var(--curdun-gray);
            margin: 0;
        }

        .stat-icon {
            width: 55px;
            height: 55px;
            background: rgba(82, 0, 102, 0.1);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .stat-icon i {
            font-size: 28px;
            color: var(--curdun-violet);
        }

        /* Dashboard Rows */
        .dashboard-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .dashboard-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .dashboard-card:hover {
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            padding: 15px 20px;
            background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light));
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-header h3 {
            font-size: 16px;
            font-weight: 600;
            margin: 0;
        }

        .card-header h3 i {
            margin-right: 8px;
        }

        .view-all-link {
            color: white;
            font-size: 12px;
            text-decoration: none;
            opacity: 0.9;
        }

        .view-all-link:hover {
            opacity: 1;
            text-decoration: underline;
        }

        .card-body {
            padding: 20px;
        }

        /* Status Lists */
        .status-list {
            list-style: none;
        }

        .status-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }

        .status-list li:last-child {
            border-bottom: none;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        /* Status Colors */
        .status-received { background: #e3f2fd; color: #1976d2; }
        .status-loaded { background: #fff3e0; color: #f57c00; }
        .status-dispatched { background: #e8eaf6; color: #3949ab; }
        .status-at_port { background: #fce4ec; color: #c2185b; }
        .status-ready { background: #e8f5e9; color: #388e3c; }
        .status-delivered { background: #e0f2f1; color: #00796b; }
        .status-loading { background: #fff3e0; color: #f57c00; }
        .status-transit { background: #e8eaf6; color: #3949ab; }
        .status-arrived { background: #c8e6c9; color: #2e7d32; }
        .status-pending { background: #fff3e0; color: #f57c00; }
        .status-warehouse { background: #d1ecf1; color: #0c5460; }
        .status-out-delivery { background: #fff3e0; color: #f57c00; }
        .status-cancelled { background: #f8d7da; color: #c62828; }
        .status-default { background: #e0e0e0; color: #616161; }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .data-table th {
            font-weight: 600;
            color: var(--curdun-dark);
            background: #f8f6f9;
            font-size: 13px;
        }

        .data-table td {
            font-size: 13px;
        }

        .data-table tr:hover {
            background: #f8f6f9;
        }

        /* Badges */
        .badge-success {
            background: #EEFBF3;
            color: #155724;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-danger {
            background: #f8d7da;
            color: #721c24;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-warning {
            background: #fff3cd;
            color: #856404;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .quick-action-btn {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            background: #f8f6f9;
            border-radius: 10px;
            text-decoration: none;
            color: var(--curdun-dark);
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
        }

        .quick-action-btn i {
            width: 30px;
            color: var(--curdun-violet);
            font-size: 18px;
        }

        .quick-action-btn span {
            flex: 1;
            font-size: 14px;
        }

        .quick-action-btn:hover {
            background: var(--curdun-violet);
            color: white;
            transform: translateX(5px);
        }

        .quick-action-btn:hover i {
            color: white;
        }

        /* Stock Items */
        .stock-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .stock-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .stock-name {
            font-weight: 500;
            font-size: 13px;
        }

        .stock-quantity {
            background: var(--curdun-violet);
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
        }

        .low-stock {
            background: #ffc107;
            color: #333;
        }

        .chart-container {
            height: 280px;
            position: relative;
        }

        .financial-positive {
            color: var(--curdun-success);
        }

        .financial-negative {
            color: var(--curdun-danger);
        }

        @media (max-width: 768px) {
            .dashboard-stats {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
            }
            
            .dashboard-row {
                grid-template-columns: 1fr;
            }
            
            .welcome-section {
                padding: 20px;
            }
            
            .welcome-section h1 {
                font-size: 20px;
            }
            
            .dashboard-container {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Welcome Section -->
        <div class="welcome-section">
            <h1><i class="fas fa-store"></i> Welcome back, <?= htmlspecialchars($user_name) ?>!</h1>
            <p><i class="fas fa-user-tie"></i> You are logged in as <strong>Branch Manager</strong></p>
            
            <div class="branch-info">
                <div><i class="fas fa-building"></i> Branch: <strong><?= htmlspecialchars($branch_name) ?></strong> (<?= htmlspecialchars($branch_code) ?>)</div>
                <div><i class="fas fa-tag"></i> Type: <?= ucfirst(str_replace('_', ' ', $branch_type)) ?></div>
                <?php if ($branch_phone): ?>
                    <div><i class="fas fa-phone"></i> <?= htmlspecialchars($branch_phone) ?></div>
                <?php endif; ?>
                <?php if ($branch_address): ?>
                    <div><i class="fas fa-location-dot"></i> <?= htmlspecialchars($branch_address) ?></div>
                <?php endif; ?>
            </div>
            
            <div class="datetime">
                <i class="fas fa-calendar-alt"></i> <?= date('l, F j, Y') ?> |
                <i class="fas fa-clock"></i> <?= date('h:i A') ?>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="dashboard-stats">
            <div class="stat-card">
                <div class="stat-info">
                    <h3><?= number_format($total_stock_items) ?></h3>
                    <p><i class="fas fa-boxes"></i> Total Stock Items</p>
                    <small><?= number_format($total_stock_volume, 2) ?> CBM</small>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-boxes"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3><?= number_format($today_receptions) ?></h3>
                    <p><i class="fas fa-clipboard-list"></i> Today's Receptions</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-clipboard-list"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3><?= number_format($pending_deliveries) ?></h3>
                    <p><i class="fas fa-truck"></i> Pending Deliveries</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-truck"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3><?= number_format($today_deliveries) ?></h3>
                    <p><i class="fas fa-check-circle"></i> Today's Deliveries</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3><?= number_format($active_containers) ?></h3>
                    <p><i class="fas fa-ship"></i> Active Containers</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-ship"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3 class="<?= $low_stock_count > 0 ? 'financial-negative' : '' ?>"><?= number_format($low_stock_count) ?></h3>
                    <p><i class="fas fa-exclamation-triangle"></i> Low Stock Alerts</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3 class="financial-positive">$<?= number_format($today_revenue, 2) ?></h3>
                    <p><i class="fas fa-dollar-sign"></i> Today's Revenue</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-dollar-sign"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3 class="financial-positive">$<?= number_format($month_revenue, 2) ?></h3>
                    <p><i class="fas fa-chart-line"></i> Monthly Revenue</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="dashboard-row">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-line"></i> Weekly Activity</h3>
                    <span style="font-size: 12px;">Last 7 Days</span>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="weeklyChart"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-pie"></i> Stock by Origin</h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="originChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activities Row -->
        <div class="dashboard-row">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-clipboard-list"></i> Recent Receptions</h3>
                    <a href="receptions.php" class="view-all-link">View All <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th>Tracking #</th><th>Customer</th><th>Package</th><th>Status</th><th>Date</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_receptions)): ?>
                                    <tr><td colspan="5" style="text-align: center;">No recent receptions</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recent_receptions as $reception): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($reception['tracking_number'] ?? 'N/A') ?></strong></td>
                                            <td><?= htmlspecialchars($reception['customer_name'] ?? 'Unknown') ?></td>
                                            <td><?= htmlspecialchars($reception['package_name'] ?? 'Package') ?></td>
                                            <td><span class="status-badge <?= getStatusBadgeClass($reception['status'] ?? 'pending') ?>"><?= ucfirst(str_replace('_', ' ', $reception['status'] ?? 'Pending')) ?></span></td>
                                            <td><?= date('M d, H:i', strtotime($reception['created_at'] ?? 'now')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-truck"></i> Recent Deliveries</h3>
                    <a href="tracking.php?status=delivered" class="view-all-link">View All <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th>Tracking #</th><th>Customer</th><th>Package</th><th>Status</th><th>Delivered</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_deliveries)): ?>
                                    <tr><td colspan="5" style="text-align: center;">No recent deliveries</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recent_deliveries as $delivery): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($delivery['tracking_number'] ?? 'N/A') ?></strong></td>
                                            <td><?= htmlspecialchars($delivery['customer_name'] ?? 'Unknown') ?></td>
                                            <td><?= htmlspecialchars($delivery['package_name'] ?? 'Package') ?></td>
                                            <td><span class="status-badge status-delivered">Delivered</span></td>
                                            <td><?= date('M d, H:i', strtotime($delivery['delivered_date'] ?? 'now')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Trips & Stock Alerts Row -->
        <div class="dashboard-row">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-truck-moving"></i> Active Trips</h3>
                    <a href="trips.php" class="view-all-link">View All <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th>Trip #</th><th>Container</th><th>Route</th><th>Driver</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($active_trips)): ?>
                                    <tr><td colspan="5" style="text-align: center;">No active trips</td></tr>
                                <?php else: ?>
                                    <?php foreach ($active_trips as $trip): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($trip['trip_number'] ?? 'N/A') ?></strong></td>
                                            <td><?= htmlspecialchars($trip['container_number'] ?? 'N/A') ?></td>
                                            <td><small><?= htmlspecialchars($trip['from_branch'] ?? '?') ?> <i class="fas fa-arrow-right"></i> <?= htmlspecialchars($trip['to_branch'] ?? '?') ?></small></td>
                                            <td><?= htmlspecialchars($trip['driver_name'] ?? 'Not assigned') ?></td>
                                            <td><span class="status-badge <?= getStatusBadgeClass($trip['status'] ?? 'pending') ?>"><?= ucfirst($trip['status'] ?? 'Pending') ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-boxes"></i> Stock by Origin</h3>
                </div>
                <div class="card-body">
                    <div class="stock-list">
                        <?php if (empty($stock_by_origin)): ?>
                            <p class="text-muted text-center">No stock data available</p>
                        <?php else: ?>
                            <?php foreach ($stock_by_origin as $stock): ?>
                            <div class="stock-item">
                                <span class="stock-name">
                                    <i class="fas fa-globe"></i> <?= getOriginLabel($stock['origin']) ?>
                                </span>
                                <div>
                                    <span class="stock-quantity"><?= number_format($stock['total_quantity']) ?> items</span>
                                    <span class="stock-quantity" style="background:#6c757d"><?= number_format($stock['total_volume'], 2) ?> CBM</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($low_stock_count > 0): ?>
                    <div class="alert alert-warning mt-3 mb-0" style="font-size:13px; padding:12px; border-radius:10px;">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong><?= $low_stock_count ?> item(s)</strong> are running low on stock. Please review.
                        <?php if (!empty($low_stock_items)): ?>
                            <ul style="margin-top: 8px; margin-bottom: 0; padding-left: 20px;">
                                <?php foreach ($low_stock_items as $item): ?>
                                    <li><?= htmlspecialchars($item['stock_name']) ?>: <?= $item['quantity'] ?> left (Min: <?= $item['minimum_stock'] ?>)</li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Customers & Quick Actions -->
        <div class="dashboard-row">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-users"></i> Recent Customers</h3>
                    <a href="invoices.php" class="view-all-link">View All <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th>Name</th><th>Phone</th><th>Email</th><th>Since</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_customers)): ?>
                                    <tr><td colspan="4" style="text-align: center;">No customers found</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recent_customers as $customer): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($customer['customer_name'] ?? 'N/A') ?></strong></td>
                                            <td><?= htmlspecialchars($customer['phone'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($customer['email'] ?? 'N/A') ?></td>
                                            <td><?= date('M d, Y', strtotime($customer['created_at'] ?? 'now')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                    <i class="fas fa-cog"></i>
                </div>
                <div class="card-body">
                    <div class="quick-actions">
                        <a href="receptions.php" class="quick-action-btn"><i class="fas fa-clipboard-list"></i><span>New Reception</span><i class="fas fa-chevron-right"></i></a>
                        <a href="warehouse_stock.php" class="quick-action-btn"><i class="fas fa-box"></i><span>Manage Stock</span><i class="fas fa-chevron-right"></i></a>
                        <a href="trips.php" class="quick-action-btn"><i class="fas fa-truck"></i><span>Create Trip</span><i class="fas fa-chevron-right"></i></a>
                        <a href="branch_report.php" class="quick-action-btn"><i class="fas fa-chart-bar"></i><span>Generate Reports</span><i class="fas fa-chevron-right"></i></a>
                        <a href="branch_settings.php" class="quick-action-btn"><i class="fas fa-cog"></i><span>Branch Settings</span><i class="fas fa-chevron-right"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Weekly Activity Chart
    const ctx1 = document.getElementById('weeklyChart').getContext('2d');
    const weeklyLabels = <?= json_encode(array_keys($weekly_receptions)) ?>;
    const receptionsData = <?= json_encode(array_values($weekly_receptions)) ?>;
    const deliveriesData = <?= json_encode(array_values($weekly_deliveries)) ?>;
    
    new Chart(ctx1, {
        type: 'line',
        data: {
            labels: weeklyLabels,
            datasets: [
                {
                    label: 'Receptions',
                    data: receptionsData,
                    borderColor: '#2D1859',
                    backgroundColor: 'rgba(45, 24, 89, 0.1)',
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Deliveries',
                    data: deliveriesData,
                    borderColor: '#F5C410',
                    backgroundColor: 'rgba(245, 196, 16, 0.1)',
                    tension: 0.4,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                }
            }
        }
    });

    // Stock by Origin Chart
    const ctx2 = document.getElementById('originChart').getContext('2d');
    const originLabels = <?php 
        $labels = array_map(function($item) {
            switch($item['origin']) {
                case 'china_yiwu': return 'China Yiwu';
                case 'china_guangzhou': return 'China Guangzhou';
                case 'dubai': return 'Dubai';
                case 'local': return 'Local';
                default: return ucfirst($item['origin']);
            }
        }, $stock_by_origin);
        echo json_encode($labels);
    ?>;
    const originData = <?php echo json_encode(array_column($stock_by_origin, 'total_quantity')); ?>;
    const originColors = ['#2D1859', '#4B2C85', '#F5C410', '#6c757d'];
    
    if (originLabels.length > 0 && originData.length > 0) {
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: originLabels,
                datasets: [{
                    data: originData,
                    backgroundColor: originColors.slice(0, originLabels.length),
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    }
                }
            }
        });
    }
    </script>

    <?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>