<?php
// superadmin/dashboard.php
// Dashboard forfaras cargo - Super Admin

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is superadmin or company_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['superadmin', 'tenant_admin', 'company_admin'])) {
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
require_once __DIR__ . '/../includes/functions.php';

// Get user info from session
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'staff';

// Get user data from database
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);

// Profile photo path - read from uploads/profiles directory
$photo = '../uploads/profiles/default.png';
$profiles_dir = __DIR__ . '/../uploads/profiles/';

if (is_dir($profiles_dir)) {
    // Check for user-specific image
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    foreach ($allowed_extensions as $ext) {
        $user_image = $profiles_dir . 'user_' . $user_id . '.' . $ext;
        if (file_exists($user_image)) {
            $photo = '../uploads/profiles/user_' . $user_id . '.' . $ext;
            break;
        }
    }
    
    // Check database profile_image
    if ($photo == '../uploads/profiles/default.png' && !empty($user_data['profile_image'])) {
        $db_image = '../' . ltrim($user_data['profile_image'], './');
        if (file_exists(__DIR__ . '/../' . $db_image)) {
            $photo = $db_image;
        }
    }
}

// Get dashboard statistics
$where_clause = ($role === 'tenant_admin') ? "WHERE tenant_id = " . intval($session_tenant_id) : "";
$where_clause_user = ($role === 'tenant_admin') ? "WHERE tenant_id = " . intval($session_tenant_id) . " AND role_type != 'superadmin'" : "WHERE role_type != 'superadmin'";

// Total users
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
    FROM invoices";
    if ($where_clause) {
        $sql .= " $where_clause AND status != 'cancelled'";
    } else {
        $sql .= " WHERE status != 'cancelled'";
    }
    $stmt = $pdo->query($sql);
    $stats['invoice_totals'] = $stmt->fetch(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $stats['invoice_totals'] = ['total' => 0, 'paid' => 0, 'balance' => 0];
}

// Total shipments (using trucking_trips table)
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM trucking_trips $where_clause");
    $stats['total_shipments'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch(PDOException $e) {
    $stats['total_shipments'] = 0;
}

// --- PLATFORM GLOBAL REPORTS (Super Admin Logic) ---
if ($role === 'superadmin') {
    // Total tenants
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM tenants");
        $stats['total_tenants'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    } catch(PDOException $e) {
        $stats['total_tenants'] = 0;
    }

    // Active tenants
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM tenants WHERE is_active = 1");
        $stats['active_tenants'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    } catch(PDOException $e) {
        $stats['active_tenants'] = 0;
    }

    // Total receipts collected
    try {
        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM receipts");
        $stats['platform_total_collected'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    } catch(PDOException $e) {
        $stats['platform_total_collected'] = 0;
    }

    // Platform Monthly Revenue
    try {
        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM receipts WHERE MONTH(created_at) = MONTH(CURRENT_DATE())");
        $stats['platform_monthly_revenue'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    } catch(PDOException $e) {
        $stats['platform_monthly_revenue'] = 0;
    }
} else {
    $stats['total_tenants'] = 1;
    $stats['active_tenants'] = 1;
    $stats['platform_total_collected'] = $stats['invoice_totals']['paid'] ?? 0;
    $stats['platform_monthly_revenue'] = 0;
}

// Recent tenants - last 5 (Super Admin only)
$recent_tenants = [];
if ($role === 'superadmin') {
    try {
        $stmt = $pdo->query("
            SELECT id, name, email, phone, is_active as status, created_at 
            FROM tenants 
            ORDER BY id DESC 
            LIMIT 5
        ");
        $recent_tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        $recent_tenants = [];
    }
}

// Recent users - last 5
try {
    $stmt = $pdo->query("
        SELECT id, full_name, email, role_type, created_at 
        FROM users 
        $where_clause_user
        ORDER BY id DESC 
        LIMIT 5
    ");
    $recent_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $recent_users = [];
}

// Recent containers - last 5
try {
    $stmt = $pdo->query("
        SELECT id, container_number, size_cbm, status, created_at 
        FROM containers 
        $where_clause
        ORDER BY id DESC 
        LIMIT 5
    ");
    $recent_containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $recent_containers = [];
}

// Get recent shipments/trips
try {
    $stmt = $pdo->query("
        SELECT id, trip_number, total_cbm, status, created_at,
               'Walk-in Customer' as customer_name
        FROM trucking_trips 
        $where_clause
        ORDER BY id DESC 
        LIMIT 5
    ");
    $recent_shipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $recent_shipments = [];
}

// Function to get container status in Somali
function getContainerStatusSomali($status)
{
    $statuses = [
        'loading' => 'La Rarayay',
        'in_transit' => 'Socdaal',
        'arrived' => 'Yimid',
        'delivered' => 'La Gaarsiiyay',
        'received' => 'La Helay',
        'loaded' => 'La Raray',
        'dispatched' => 'La Diray',
        'at_port' => 'Dekedda',
        'ready' => 'Diyaar'
    ];
    return $statuses[$status] ?? ucfirst($status);
}

// Function to get status badge class
function getStatusBadgeClass($status)
{
    $classes = [
        'loading' => 'status-loading',
        'in_transit' => 'status-transit',
        'arrived' => 'status-arrived',
        'delivered' => 'status-delivered',
        'received' => 'status-received',
        'loaded' => 'status-loaded',
        'dispatched' => 'status-dispatched',
        'at_port' => 'status-at_port',
        'ready' => 'status-ready',
        'pending' => 'status-pending',
        'paid' => 'status-paid',
        'overdue' => 'status-overdue'
    ];
    return $classes[$status] ?? 'status-default';
}

include_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Cargo Management System</title>
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

        .welcome-section .datetime {
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
        .status-paid { background: #c8e6c9; color: #2e7d32; }
        .status-overdue { background: #ffcdd2; color: #c62828; }
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
            <h1><i class="fas fa-tachometer-alt"></i> Welcome back, <?= htmlspecialchars($user_name) ?>!</h1>
            <p><i class="fas fa-user-shield"></i> You are logged in as <strong><?= $role === 'superadmin' ? 'Super Administrator' : 'Tenant Administrator' ?></strong> |
                <i class="fas fa-globe"></i>Cargo Management System
            </p>
            <div class="datetime">
                <i class="fas fa-calendar-alt"></i> <?= date('l, F j, Y') ?> |
                <i class="fas fa-clock"></i> <?= date('h:i A') ?>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="dashboard-stats">
            <div class="stat-card">
                <div class="stat-info">
                    <h3><?= number_format($stats['total_tenants']) ?></h3>
                    <p><i class="fas fa-building"></i> Total Tenants</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-building"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3><?= number_format($stats['active_tenants']) ?></h3>
                    <p><i class="fas fa-check-circle"></i> Active Tenants</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3><?= number_format($stats['total_users']) ?></h3>
                    <p><i class="fas fa-users"></i> Total Users</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3><?= number_format($stats['total_customers']) ?></h3>
                    <p><i class="fas fa-user-friends"></i> Total Customers</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-user-friends"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3><?= number_format($stats['total_containers']) ?></h3>
                    <p><i class="fas fa-box"></i> Total Containers</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-box"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3><?= number_format($stats['total_shipments']) ?></h3>
                    <p><i class="fas fa-shipping-fast"></i> Total Shipments</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-shipping-fast"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3 class="financial-positive">$<?= number_format($stats['invoice_totals']['total'], 2) ?></h3>
                    <p><i class="fas fa-dollar-sign"></i> Cross-Tenant Cargo Revenue</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>

            <div class="stat-card" style="border-left-color: #20c997;">
                <div class="stat-info">
                    <h3 style="color: #20c997;">$<?= number_format($stats['platform_total_collected'], 2) ?></h3>
                    <p><i class="fas fa-globe"></i> Total Collected</p>
                </div>
                <div class="stat-icon" style="background: rgba(32, 201, 151, 0.1);">
                    <i class="fas fa-globe" style="color: #20c997;"></i>
                </div>
            </div>
        </div>

        <!-- Dashboard Row 1 -->
        <div class="dashboard-row">
            <!-- Containers by Status -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-pie"></i> Containers by Status</h3>
                    <i class="fas fa-box"></i>
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
                        <p class="text-muted" style="text-align: center; padding: 20px;">No containers found.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Financial Summary -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-line"></i> Financial Summary</h3>
                    <i class="fas fa-dollar-sign"></i>
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
                                <?php
                                $collection_rate = $stats['invoice_totals']['total'] > 0
                                    ? ($stats['invoice_totals']['paid'] / $stats['invoice_totals']['total']) * 100
                                    : 0;
                                ?>
                                <span class="status-badge" style="background: <?= $collection_rate >= 70 ? '#EEFBF3' : '#fff3cd' ?>; color: <?= $collection_rate >= 70 ? '#155724' : '#856404' ?>">
                                    <?= number_format($collection_rate, 1) ?>% Collected
                                </span>
                            </strong>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Dashboard Row 2 -->
        <div class="dashboard-row">
            <!-- Recent Tenants (Super Admin Only) -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-building"></i> Recently Added Tenants</h3>
                    <?php if ($role === 'superadmin'): ?>
                        <a href="tenants.php" class="view-all-link">View All →</a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th>ID</th><th>Tenant Name</th><th>Email</th><th>Status</th><th>Added</th></tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_tenants)): ?>
                                    <?php foreach ($recent_tenants as $company): ?>
                                        <tr>
                                            <td>#<?= $company['id'] ?></td>
                                            <td><strong><?= htmlspecialchars($company['name']) ?></strong></td>
                                            <td><?= htmlspecialchars($company['email'] ?? 'N/A') ?></td>
                                            <td><span class="badge-success"><i class="fas fa-check-circle"></i> Active</span></td>
                                            <td><?= date('M d, Y', strtotime($company['created_at'] ?? 'now')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" style="text-align: center;">No tenants found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Recent Users -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-users"></i> Recently Added Users</h3>
                    <a href="users.php" class="view-all-link">View All →</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Joined</th></tr></thead>
                            <tbody>
                                <?php if (!empty($recent_users)): ?>
                                    <?php foreach ($recent_users as $user): ?>
                                        <tr>
                                            <td>#<?= $user['id'] ?></td>
                                            <td><strong><?= htmlspecialchars($user['full_name']) ?></strong></td>
                                            <td><?= htmlspecialchars($user['email']) ?></td>
                                            <td><span class="badge-info"><?= ucfirst($user['role_type'] ?? 'Staff') ?></span></td>
                                            <td><?= date('M d, Y', strtotime($user['created_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" style="text-align: center;">No users found.</td></tr>
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
                    <a href="containers.php" class="view-all-link">View All →</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead><tr><th>Container No</th><th>Volume (CBM)</th><th>Status</th><th>Created</th></tr></thead>
                            <tbody>
                                <?php if (!empty($recent_containers)): ?>
                                    <?php foreach ($recent_containers as $container): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($container['container_number'] ?? 'N/A') ?></strong></td>
                                            <td><?= number_format($container['size_cbm'] ?? 0, 2) ?> CBM</td>
                                            <td><span class="status-badge <?= getStatusBadgeClass($container['status'] ?? 'received') ?>"><?= getContainerStatusSomali($container['status'] ?? 'received') ?></span></td>
                                            <td><?= date('M d, Y', strtotime($container['created_at'] ?? 'now')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" style="text-align: center;">No containers found.</td></tr>
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
                    <a href="tracking.php" class="view-all-link">View All →</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead><tr><th>Trip #</th><th>Customer</th><th>Volume</th><th>Status</th><th>Date</th></tr></thead>
                            <tbody>
                                <?php if (!empty($recent_shipments)): ?>
                                    <?php foreach ($recent_shipments as $shipment): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($shipment['trip_number'] ?? 'N/A') ?></strong></td>
                                            <td><?= htmlspecialchars($shipment['customer_name'] ?? 'Walk-in Customer') ?></td>
                                            <td><?= number_format($shipment['total_cbm'] ?? 0, 2) ?> CBM</td>
                                            <td><span class="status-badge <?= getStatusBadgeClass($shipment['status'] ?? 'received') ?>"><?= ucfirst($shipment['status'] ?? 'Received') ?></span></td>
                                            <td><?= date('M d, Y', strtotime($shipment['created_at'] ?? 'now')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" style="text-align: center;">No shipments found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dashboard Row 4 - Quick Actions -->
        <div class="dashboard-row">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                    <i class="fas fa-cog"></i>
                </div>
                <div class="card-body">
                    <div class="quick-actions">
                        <a href="users.php?new=1" class="quick-action-btn"><i class="fas fa-user-plus"></i><span>Create New User</span><i class="fas fa-chevron-right"></i></a>
                        <a href="tenants.php?new=1" class="quick-action-btn"><i class="fas fa-building"></i><span>Add New Tenant</span><i class="fas fa-chevron-right"></i></a>
                        <a href="reports.php" class="quick-action-btn"><i class="fas fa-chart-pie"></i><span>Generate Reports</span><i class="fas fa-chevron-right"></i></a>
                        <a href="settings.php" class="quick-action-btn"><i class="fas fa-cog"></i><span>System Settings</span><i class="fas fa-chevron-right"></i></a>
                    </div>
                </div>
            </div>

            <!-- System Info -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-info-circle"></i> System Information</h3>
                    <i class="fas fa-server"></i>
                </div>
                <div class="card-body">
                    <ul class="status-list">
                        <li><span><i class="fas fa-code-branch"></i> System Version</span><span class="badge-info">v2.0</span></li>
                        <li><span><i class="fas fa-chart-line"></i> MTD Revenue</span><span class="financial-positive">$<?= number_format($stats['platform_monthly_revenue'], 2) ?></span></li>
                        <li><span><i class="fas fa-chart-line"></i> Cross-Tenant Cargo Revenue (YTD)</span><span class="financial-positive">$<?= number_format($stats['invoice_totals']['total'] ?? 0, 2) ?></span></li>
                        <li><span><i class="fas fa-envelope"></i> PHP Version</span><span class="badge-info"><?= phpversion() ?></span></li>
                        <li><span><i class="fas fa-clock"></i> Server Time</span><span><?= date('Y-m-d H:i:s') ?></span></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>