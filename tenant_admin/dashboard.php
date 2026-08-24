<?php
// tenant_admin/dashboard.php
// Dashboard for Cargo Management System - Tenant Admin

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has tenant_admin or company_admin role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['tenant_admin', 'company_admin', 'superadmin'])) {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION['role'];
// Normalize company_admin to tenant_admin for this dashboard
if ($role === 'company_admin') {
    $role = 'tenant_admin';
    $_SESSION['role'] = 'tenant_admin';
}

// Ensure tenant_id is set for tenant_admin
$session_tenant_id = $_SESSION['tenant_id'] ?? 0;
if ($role === 'tenant_admin' && $session_tenant_id == 0) {
    // Fallback: get tenant_id from user record
    require_once __DIR__ . '/../config/db_connect.php';
    $stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userTenant = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($userTenant && $userTenant['tenant_id']) {
        $session_tenant_id = $userTenant['tenant_id'];
        $_SESSION['tenant_id'] = $session_tenant_id;
    } else {
        // No tenant assigned - redirect to selection or error
        header("Location: ../select_tenant.php");
        exit;
    }
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';

// Get user info from session
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'staff';

// Get user data from database
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);

// Get tenant info for this admin
$tenant_info = [];
if ($session_tenant_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmt->execute([$session_tenant_id]);
    $tenant_info = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Profile photo path
$photo = '../uploads/profiles/default.png';
$profiles_dir = __DIR__ . '/../uploads/profiles/';
if (is_dir($profiles_dir)) {
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    foreach ($allowed_extensions as $ext) {
        $user_image = $profiles_dir . 'user_' . $user_id . '.' . $ext;
        if (file_exists($user_image)) {
            $photo = '../uploads/profiles/user_' . $user_id . '.' . $ext;
            break;
        }
    }
    if ($photo == '../uploads/profiles/default.png' && !empty($user_data['profile_image'])) {
        $db_image = '../' . ltrim($user_data['profile_image'], './');
        if (file_exists(__DIR__ . '/../' . $db_image)) {
            $photo = $db_image;
        }
    }
}

// WHERE clause for tenant filtering
$where_clause = "WHERE tenant_id = " . intval($session_tenant_id);
$where_clause_user = "WHERE tenant_id = " . intval($session_tenant_id) . " AND role_type != 'superadmin'";

// Get dashboard statistics for tenant
$stats = [];

// Total users in tenant
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users $where_clause_user");
    $stats['total_users'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch(PDOException $e) {
    $stats['total_users'] = 0;
}

// Total customers
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM customers $where_clause");
    $stats['total_customers'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch(PDOException $e) {
    $stats['total_customers'] = 0;
}

// Total containers
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM containers $where_clause");
    $stats['total_containers'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch(PDOException $e) {
    $stats['total_containers'] = 0;
}

// Containers by status
try {
    $stmt = $pdo->query("
        SELECT status, COUNT(*) as count 
        FROM containers 
        $where_clause
        GROUP BY status
    ");
    $stats['containers_by_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $stats['containers_by_status'] = [];
}

// Total invoices amount
try {
    $sql = "SELECT 
        COALESCE(SUM(total_amount), 0) as total,
        COALESCE(SUM(paid_amount), 0) as paid,
        COALESCE(SUM(total_amount - paid_amount), 0) as balance
    FROM invoices
    WHERE tenant_id = ? AND status != 'cancelled'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$session_tenant_id]);
    $stats['invoice_totals'] = $stmt->fetch(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $stats['invoice_totals'] = ['total' => 0, 'paid' => 0, 'balance' => 0];
}

// Total shipments (trucking_trips)
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM trucking_trips WHERE tenant_id = ?");
    $stmt->execute([$session_tenant_id]);
    $stats['total_shipments'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch(PDOException $e) {
    $stats['total_shipments'] = 0;
}

// Stock summary - total items in warehouse
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total_items, SUM(quantity) as total_quantity, SUM(volume_cbm) as total_volume
        FROM warehouse_stock 
        WHERE tenant_id = ?
    ");
    $stmt->execute([$session_tenant_id]);
    $stock_summary = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_stock_items'] = $stock_summary['total_items'] ?? 0;
    $stats['total_stock_quantity'] = $stock_summary['total_quantity'] ?? 0;
    $stats['total_stock_volume'] = $stock_summary['total_volume'] ?? 0;
} catch(PDOException $e) {
    $stats['total_stock_items'] = 0;
    $stats['total_stock_quantity'] = 0;
    $stats['total_stock_volume'] = 0;
}

// Low stock items count
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM warehouse_stock 
        WHERE tenant_id = ? AND quantity <= minimum_stock AND minimum_stock > 0
    ");
    $stmt->execute([$session_tenant_id]);
    $stats['low_stock_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch(PDOException $e) {
    $stats['low_stock_count'] = 0;
}

// Recent activities / stock movements (last 5)
$recent_movements = [];
try {
    $stmt = $pdo->prepare("
        SELECT sm.*, ws.stock_name, u.full_name as created_by_name
        FROM stock_movements sm
        LEFT JOIN warehouse_stock ws ON sm.warehouse_stock_id = ws.id
        LEFT JOIN users u ON sm.created_by = u.id
        WHERE sm.tenant_id = ?
        ORDER BY sm.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$session_tenant_id]);
    $recent_movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $recent_movements = [];
}

// Recent customers - last 5
$recent_customers = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, customer_name, phone, email, debt_amount, created_at 
        FROM customers 
        WHERE tenant_id = ?
        ORDER BY id DESC 
        LIMIT 5
    ");
    $stmt->execute([$session_tenant_id]);
    $recent_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $recent_customers = [];
}

// Recent containers - last 5
$recent_containers = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, container_number, size_cbm, status, origin, created_at 
        FROM containers 
        WHERE tenant_id = ?
        ORDER BY id DESC 
        LIMIT 5
    ");
    $stmt->execute([$session_tenant_id]);
    $recent_containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $recent_containers = [];
}

// Recent shipments/trips
$recent_shipments = [];
try {
    $stmt = $pdo->prepare("
        SELECT tt.id, tt.trip_number, tt.total_cbm, tt.status, tt.created_at,
               c.container_number
        FROM trucking_trips tt
        LEFT JOIN containers c ON tt.container_id = c.id
        WHERE tt.tenant_id = ?
        ORDER BY tt.id DESC 
        LIMIT 5
    ");
    $stmt->execute([$session_tenant_id]);
    $recent_shipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $recent_shipments = [];
}

// Recent invoices - last 5
$recent_invoices = [];
try {
    $stmt = $pdo->prepare("
        SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.paid_amount, i.status,
               c.customer_name
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        WHERE i.tenant_id = ?
        ORDER BY i.id DESC 
        LIMIT 5
    ");
    $stmt->execute([$session_tenant_id]);
    $recent_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $recent_invoices = [];
}

// Calculate collection rate
$collection_rate = $stats['invoice_totals']['total'] > 0
    ? ($stats['invoice_totals']['paid'] / $stats['invoice_totals']['total']) * 100
    : 0;

// Get branches for this tenant
$branches = [];
try {
    $stmt = $pdo->prepare("SELECT id, branch_name, branch_code, branch_type, status FROM branches WHERE tenant_id = ? AND status = 'active' LIMIT 5");
    $stmt->execute([$session_tenant_id]);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $branches = [];
}

// Function to get container status in Somali
function getContainerStatusSomali($status)
{
    $statuses = [
        'received' => 'La Helay',
        'loading' => 'La Rarayay',
        'loaded' => 'La Raray',
        'shipped' => 'La Direy',
        'in_transit' => 'Socdaal',
        'at_port' => 'Dekedda',
        'ready' => 'Diyaar',
        'delivered' => 'La Gaarsiiyay',
        'pending' => 'Sugaya',
        'dispatched' => 'La Diray'
    ];
    return $statuses[$status] ?? ucfirst($status);
}

// Function to get status badge class
function getStatusBadgeClass($status)
{
    $classes = [
        'received' => 'status-received',
        'loading' => 'status-loading',
        'loaded' => 'status-loaded',
        'shipped' => 'status-shipped',
        'in_transit' => 'status-transit',
        'at_port' => 'status-at_port',
        'ready' => 'status-ready',
        'delivered' => 'status-delivered',
        'pending' => 'status-pending',
        'paid' => 'status-paid',
        'overdue' => 'status-overdue',
        'draft' => 'status-draft',
        'sent' => 'status-sent',
        'cancelled' => 'status-cancelled'
    ];
    return $classes[$status] ?? 'status-default';
}

// Get movement type badge
function getMovementTypeBadge($type)
{
    $types = [
        'in' => 'badge-success',
        'out' => 'badge-danger',
        'move' => 'badge-info',
        'adjust' => 'badge-warning'
    ];
    return $types[$type] ?? 'badge-secondary';
}

include_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant Dashboard - <?= htmlspecialchars($tenant_info['name'] ?? 'CURDUN CARGO') ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            position: relative;
            overflow: hidden;
        }
        
        .welcome-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%);
            pointer-events: none;
        }

        .welcome-section h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .welcome-section h1 i {
            margin-right: 10px;
        }

        .welcome-section p {
            opacity: 0.9;
            font-size: 14px;
            margin-top: 5px;
        }

        .tenant-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            margin-top: 10px;
        }

        .welcome-section .datetime {
            margin-top: 15px;
            font-size: 13px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            padding-top: 15px;
        }

        /* Statistics Cards */
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
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
            font-size: 28px;
            font-weight: 700;
            color: var(--curdun-violet);
            margin-bottom: 5px;
        }

        .stat-info p {
            font-size: 13px;
            color: var(--curdun-gray);
            margin: 0;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            background: rgba(82, 0, 102, 0.1);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .stat-icon i {
            font-size: 24px;
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
        .status-shipped { background: #e8eaf6; color: #3949ab; }
        .status-at_port { background: #fce4ec; color: #c2185b; }
        .status-ready { background: #e8f5e9; color: #388e3c; }
        .status-delivered { background: #e0f2f1; color: #00796b; }
        .status-loading { background: #fff3e0; color: #f57c00; }
        .status-transit { background: #e8eaf6; color: #3949ab; }
        .status-pending { background: #fff3e0; color: #f57c00; }
        .status-paid { background: #d4edda; color: #155724; }
        .status-overdue { background: #f8d7da; color: #721c24; }
        .status-draft { background: #e2e3e5; color: #383d41; }
        .status-sent { background: #cce5ff; color: #004085; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
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

        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
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
        
        .badge-secondary {
            background: #e2e3e5;
            color: #383d41;
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

        /* Financial Numbers */
        .financial-positive {
            color: var(--curdun-success);
        }

        .financial-negative {
            color: var(--curdun-danger);
        }
        
        .text-muted {
            color: var(--curdun-gray);
            text-align: center;
            padding: 20px;
        }

        @media (max-width: 768px) {
            .dashboard-stats {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
            
            .stat-info h3 {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Welcome Section -->
        <div class="welcome-section">
            <h1><i class="fas fa-tachometer-alt"></i> Welcome back, <?= htmlspecialchars($user_name) ?>!</h1>
            <p><i class="fas fa-user-shield"></i> You are logged in as <strong>Tenant Administrator</strong></p>
            <div class="tenant-badge">
                <i class="fas fa-building"></i> <?= htmlspecialchars($tenant_info['name'] ?? 'Your Company') ?>
                <?php if (!empty($tenant_info['code'])): ?>
                    | Code: <?= htmlspecialchars($tenant_info['code']) ?>
                <?php endif; ?>
            </div>
            <div class="datetime">
                <i class="fas fa-calendar-alt"></i> <?= date('l, F j, Y') ?> |
                <i class="fas fa-clock"></i> <?= date('h:i A') ?>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="dashboard-stats">
            <div class="stat-card" onclick="window.location.href='users.php'">
                <div class="stat-info">
                    <h3><?= number_format($stats['total_users']) ?></h3>
                    <p><i class="fas fa-users"></i> Total Users</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
            </div>

            <div class="stat-card" onclick="window.location.href='customers.php'">
                <div class="stat-info">
                    <h3><?= number_format($stats['total_customers']) ?></h3>
                    <p><i class="fas fa-user-friends"></i> Total Customers</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-user-friends"></i>
                </div>
            </div>

            <div class="stat-card" onclick="window.location.href='containers.php'">
                <div class="stat-info">
                    <h3><?= number_format($stats['total_containers']) ?></h3>
                    <p><i class="fas fa-box"></i> Containers</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-box"></i>
                </div>
            </div>

            <div class="stat-card" onclick="window.location.href='shipments.php'">
                <div class="stat-info">
                    <h3><?= number_format($stats['total_shipments']) ?></h3>
                    <p><i class="fas fa-shipping-fast"></i> Shipments</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-shipping-fast"></i>
                </div>
            </div>

            <div class="stat-card" onclick="window.location.href='warehouse_stock.php'">
                <div class="stat-info">
                    <h3><?= number_format($stats['total_stock_items']) ?></h3>
                    <p><i class="fas fa-warehouse"></i> Stock Items</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-warehouse"></i>
                </div>
            </div>

            <div class="stat-card" style="border-left-color: #0F7A3A;" onclick="window.location.href='invoices.php'">
                <div class="stat-info">
                    <h3 class="financial-positive">$<?= number_format($stats['invoice_totals']['total'], 2) ?></h3>
                    <p><i class="fas fa-dollar-sign"></i> Total Revenue</p>
                </div>
                <div class="stat-icon" style="background: rgba(40, 167, 69, 0.1);">
                    <i class="fas fa-chart-line" style="color: #0F7A3A;"></i>
                </div>
            </div>
        </div>

        <!-- Dashboard Row 1 -->
        <div class="dashboard-row">
            <!-- Containers by Status -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-pie"></i> Containers by Status</h3>
                    <a href="../containers/index.php" class="view-all-link">View All →</a>
                </div>
                <div class="card-body">
                    <?php if (!empty($stats['containers_by_status'])): ?>
                        <ul class="status-list">
                            <?php foreach ($stats['containers_by_status'] as $status): ?>
                                <li>
                                    <span><i class="fas fa-circle" style="font-size: 10px; color: var(--curdun-violet);"></i>
                                        <?= getContainerStatusSomali($status['status']) ?></span>
                                    <span class="status-badge <?= getStatusBadgeClass($status['status']) ?>">
                                        <?= $status['count'] ?> <?= $status['count'] == 1 ? 'Container' : 'Containers' ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted">No containers found. <a href="../containers/create.php">Create your first container</a></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Financial Summary -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-line"></i> Financial Summary</h3>
                    <a href="../invoices/index.php" class="view-all-link">View All →</a>
                </div>
                <div class="card-body">
                    <ul class="status-list">
                        <li>
                            <span><strong>Total Invoiced</strong></span>
                            <strong class="financial-positive">$<?= number_format($stats['invoice_totals']['total'], 2) ?></strong>
                        </li>
                        <li>
                            <span><strong>Total Paid</strong></span>
                            <strong class="financial-positive">$<?= number_format($stats['invoice_totals']['paid'], 2) ?></strong>
                        </li>
                        <li>
                            <span><strong>Outstanding Balance</strong></span>
                            <strong class="<?= $stats['invoice_totals']['balance'] > 0 ? 'financial-negative' : 'financial-positive' ?>">
                                $<?= number_format($stats['invoice_totals']['balance'], 2) ?>
                            </strong>
                        </li>
                        <li>
                            <span><strong>Collection Rate</strong></span>
                            <strong>
                                <span class="status-badge" style="background: <?= $collection_rate >= 70 ? '#EEFBF3' : '#fff3cd' ?>; color: <?= $collection_rate >= 70 ? '#155724' : '#856404' ?>">
                                    <?= number_format($collection_rate, 1) ?>% Collected
                                </span>
                            </strong>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Dashboard Row 2 - Recent Items -->
        <div class="dashboard-row">
            <!-- Recent Customers -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-user-friends"></i> Recent Customers</h3>
                    <a href="../customers/index.php" class="view-all-link">View All →</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th>Name</th><th>Phone</th><th>Debt</th><th>Registered</th></tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_customers)): ?>
                                    <?php foreach ($recent_customers as $customer): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($customer['customer_name']) ?></strong></td>
                                            <td><?= htmlspecialchars($customer['phone']) ?></td>
                                            <td class="<?= ($customer['debt_amount'] ?? 0) > 0 ? 'financial-negative' : '' ?>">
                                                $<?= number_format($customer['debt_amount'] ?? 0, 2) ?>
                                            </td>
                                            <td><?= date('M d, Y', strtotime($customer['created_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" style="text-align: center;">No customers found. <a href="../customers/create.php">Add customer</a></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Recent Invoices -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-file-invoice-dollar"></i> Recent Invoices</h3>
                    <a href="../invoices/index.php" class="view-all-link">View All →</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th>Invoice #</th><th>Customer</th><th>Amount</th><th>Status</th><th>Date</th></tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_invoices)): ?>
                                    <?php foreach ($recent_invoices as $invoice): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($invoice['invoice_number']) ?></strong></td>
                                            <td><?= htmlspecialchars($invoice['customer_name'] ?? 'N/A') ?></td>
                                            <td>$<?= number_format($invoice['total_amount'] ?? 0, 2) ?></td>
                                            <td><span class="status-badge <?= getStatusBadgeClass($invoice['status'] ?? 'draft') ?>"><?= ucfirst($invoice['status'] ?? 'Draft') ?></span></td>
                                            <td><?= date('M d, Y', strtotime($invoice['invoice_date'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" style="text-align: center;">No invoices found. <a href="../invoices/create.php">Create invoice</a></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dashboard Row 3 -->
        <div class="dashboard-row">
            <!-- Recent Containers -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-box"></i> Recent Containers</h3>
                    <a href="../containers/index.php" class="view-all-link">View All →</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th>Container No</th><th>Type/Origin</th><th>Size (CBM)</th><th>Status</th><th>Created</th></tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_containers)): ?>
                                    <?php foreach ($recent_containers as $container): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($container['container_number'] ?? 'N/A') ?></strong></td>
                                            <td>
                                                <span class="badge-info" style="background:#e3f2fd;">
                                                    <?= strtoupper(str_replace('_', ' ', $container['origin'] ?? 'N/A')) ?>
                                                </span>
                                             </td>
                                            <td><?= number_format($container['size_cbm'] ?? 0, 2) ?> CBM</td>
                                            <td><span class="status-badge <?= getStatusBadgeClass($container['status'] ?? 'received') ?>"><?= getContainerStatusSomali($container['status'] ?? 'received') ?></span></td>
                                            <td><?= date('M d, Y', strtotime($container['created_at'] ?? 'now')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" style="text-align: center;">No containers found. <a href="../containers/create.php">Add container</a></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Recent Shipments -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-shipping-fast"></i> Recent Shipments</h3>
                    <a href="../shipments/index.php" class="view-all-link">View All →</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th>Trip #</th><th>Container</th><th>Volume</th><th>Status</th><th>Date</th></tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_shipments)): ?>
                                    <?php foreach ($recent_shipments as $shipment): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($shipment['trip_number'] ?? 'N/A') ?></strong></td>
                                            <td><?= htmlspecialchars($shipment['container_number'] ?? 'N/A') ?></td>
                                            <td><?= number_format($shipment['total_cbm'] ?? 0, 2) ?> CBM</td>
                                            <td><span class="status-badge <?= getStatusBadgeClass($shipment['status'] ?? 'pending') ?>"><?= ucfirst($shipment['status'] ?? 'Pending') ?></span></td>
                                            <td><?= date('M d, Y', strtotime($shipment['created_at'] ?? 'now')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" style="text-align: center;">No shipments found. <a href="../shipments/create.php">Create shipment</a></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dashboard Row 4 - Stock Movements & Quick Actions -->
        <div class="dashboard-row">
            <!-- Recent Stock Movements -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-exchange-alt"></i> Recent Stock Movements</h3>
                    <a href="../warehouse/movements.php" class="view-all-link">View All →</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th>Item</th><th>Change</th><th>Type</th><th>New Qty</th><th>Date</th></tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_movements)): ?>
                                    <?php foreach ($recent_movements as $movement): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($movement['stock_name'] ?? 'N/A') ?></strong></td>
                                            <td class="<?= $movement['quantity_change'] < 0 ? 'financial-negative' : 'financial-positive' ?>">
                                                <?= $movement['quantity_change'] > 0 ? '+' : '' ?><?= $movement['quantity_change'] ?>
                                            </td>
                                            <td><span class="<?= getMovementTypeBadge($movement['movement_type']) ?>"><?= ucfirst($movement['movement_type']) ?></span></td>
                                            <td><?= number_format($movement['new_quantity']) ?></td>
                                            <td><?= date('M d, H:i', strtotime($movement['created_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" style="text-align: center;">No stock movements recorded.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Quick Actions & System Info -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                    <i class="fas fa-cog"></i>
                </div>
                <div class="card-body">
                    <div class="quick-actions">
                        <a href="users.php" class="quick-action-btn"><i class="fas fa-user-plus"></i><span>Create New User</span><i class="fas fa-chevron-right"></i></a>
                        <a href="customers.php" class="quick-action-btn"><i class="fas fa-user-plus"></i><span>Add New Customer</span><i class="fas fa-chevron-right"></i></a>
                        <a href="containers.php" class="quick-action-btn"><i class="fas fa-box"></i><span>Register Container</span><i class="fas fa-chevron-right"></i></a>
                        <a href="shipments.php" class="quick-action-btn"><i class="fas fa-shipping-fast"></i><span>Create Shipment</span><i class="fas fa-chevron-right"></i></a>
                        <a href="invoices.php" class="quick-action-btn"><i class="fas fa-file-invoice"></i><span>Generate Invoice</span><i class="fas fa-chevron-right"></i></a>
                        <a href="warehouse.php" class="quick-action-btn"><i class="fas fa-warehouse"></i><span>Add Stock Item</span><i class="fas fa-chevron-right"></i></a>
                        <a href="reports.php" class="quick-action-btn"><i class="fas fa-chart-pie"></i><span>Generate Reports</span><i class="fas fa-chevron-right"></i></a>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Information Row -->
      
    </div>

    <?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>