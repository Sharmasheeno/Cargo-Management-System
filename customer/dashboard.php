<?php
// customer/dashboard.php
// Customer Dashboard forfaras cargo - Customer Portal

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has customer role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION['role'];
$session_customer_id = $_SESSION['customer_id'] ?? 0;
$session_tenant_id = $_SESSION['tenant_id'] ?? 0;

require_once __DIR__ . '/../config/db_connect.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Customer';

// Get customer data from database
$customer_data = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE user_id = ? OR id = ?");
    $stmt->execute([$user_id, $session_customer_id]);
    $customer_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer_data && $session_customer_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$session_customer_id]);
        $customer_data = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($customer_data) {
        $session_customer_id = $customer_data['id'];
        $_SESSION['customer_id'] = $session_customer_id;
    }
} catch (PDOException $e) {
    $customer_data = [];
}

$customer_name = $customer_data['customer_name'] ?? $user_name;
$customer_phone = $customer_data['phone'] ?? '';
$customer_email = $customer_data['email'] ?? $_SESSION['email'] ?? '';
$customer_debt = $customer_data['debt_amount'] ?? 0;
$customer_credit_limit = $customer_data['credit_limit'] ?? 0;
$customer_loyalty_points = $customer_data['loyalty_points'] ?? 0;

// Get tenant name
$tenant_name = '';
if ($session_tenant_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
        $stmt->execute([$session_tenant_id]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        $tenant_name = $tenant['name'] ?? 'Cargo Management System';
    } catch (PDOException $e) {
        $tenant_name = 'Cargo Management System';
    }
} else {
    $tenant_name = 'Cargo Management System';
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
}

// Helper function for safe queries
function safeQuery($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Query Error: " . $e->getMessage());
        return false;
    }
}

// ============================================
// CUSTOMER DASHBOARD STATISTICS
// ============================================

// Get total invoices for this customer
$stats = [];
try {
    $stmt = safeQuery($pdo, "SELECT COUNT(*) as count FROM invoices WHERE customer_id = ?", [$session_customer_id]);
    $stats['total_invoices'] = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC)['count'] : 0;
} catch (PDOException $e) {
    $stats['total_invoices'] = 0;
}

// Get paid invoices count
try {
    $stmt = safeQuery($pdo, "SELECT COUNT(*) as count FROM invoices WHERE customer_id = ? AND status = 'paid'", [$session_customer_id]);
    $stats['paid_invoices'] = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC)['count'] : 0;
} catch (PDOException $e) {
    $stats['paid_invoices'] = 0;
}

// Get unpaid invoices count
try {
    $stmt = safeQuery($pdo, "SELECT COUNT(*) as count FROM invoices WHERE customer_id = ? AND status IN ('unpaid', 'partial', 'overdue')", [$session_customer_id]);
    $stats['unpaid_invoices'] = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC)['count'] : 0;
} catch (PDOException $e) {
    $stats['unpaid_invoices'] = 0;
}

// Get invoice totals
$stats['invoice_totals'] = ['total' => 0, 'paid' => 0, 'balance' => 0];
try {
    $sql = "SELECT 
        COALESCE(SUM(total_amount), 0) as total,
        COALESCE(SUM(paid_amount), 0) as paid,
        COALESCE(SUM(total_amount - paid_amount), 0) as balance
        FROM invoices WHERE customer_id = ? AND status != 'cancelled'";
    $stmt = safeQuery($pdo, $sql, [$session_customer_id]);
    if ($stmt) {
        $stats['invoice_totals'] = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $stats['invoice_totals'] = ['total' => 0, 'paid' => 0, 'balance' => 0];
}

// Get recent invoices
$recent_invoices = [];
try {
    $sql = "SELECT i.id, i.invoice_number, i.invoice_date, i.due_date, i.total_amount, i.paid_amount, i.status,
                   (i.total_amount - i.paid_amount) as due_amount
            FROM invoices i
            WHERE i.customer_id = ?
            ORDER BY i.id DESC 
            LIMIT 5";
    $stmt = safeQuery($pdo, $sql, [$session_customer_id]);
    $recent_invoices = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    $recent_invoices = [];
}

// Get recent payments/receipts
$recent_payments = [];
try {
    $sql = "SELECT r.id, r.receipt_number, r.amount, r.payment_date, r.payment_method,
                   i.invoice_number
            FROM receipts r
            LEFT JOIN invoices i ON r.invoice_id = i.id
            WHERE r.customer_id = ?
            ORDER BY r.id DESC 
            LIMIT 5";
    $stmt = safeQuery($pdo, $sql, [$session_customer_id]);
    $recent_payments = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    $recent_payments = [];
}

// Get warehouse stock items for this customer
$stock_items = [];
try {
    $sql = "SELECT ws.id, ws.stock_name, ws.quantity, ws.volume_cbm, ws.unit_price, ws.location,
                   (ws.volume_cbm * ws.unit_price) as total_value,
                   ws.mogadishu_status, ws.mogadishu_received_date
            FROM warehouse_stock ws
            WHERE ws.customer_id = ?
            ORDER BY ws.created_at DESC
            LIMIT 10";
    $stmt = safeQuery($pdo, $sql, [$session_customer_id]);
    $stock_items = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    $stock_items = [];
}

// Get containers for this customer (through trips)
$containers = [];
try {
    $sql = "SELECT DISTINCT c.id, c.container_number, c.container_type, c.size_cbm, c.status, c.origin,
                   tt.trip_number, tt.status as trip_status
            FROM containers c
            LEFT JOIN trucking_trips tt ON c.id = tt.container_id
            LEFT JOIN cargo_manifest_items cmi ON c.id = cmi.container_id
            LEFT JOIN warehouse_stock ws ON cmi.warehouse_stock_id = ws.id
            WHERE ws.customer_id = ? OR c.tenant_id = ?
            ORDER BY c.created_at DESC
            LIMIT 10";
    $stmt = safeQuery($pdo, $sql, [$session_customer_id, $session_tenant_id]);
    $containers = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    $containers = [];
}

// Get trips for this customer
$trips = [];
try {
    $sql = "SELECT DISTINCT tt.id, tt.trip_number, tt.total_cbm, tt.status, tt.created_at, tt.loaded_at,
                   c.container_number
            FROM trucking_trips tt
            LEFT JOIN containers c ON tt.container_id = c.id
            LEFT JOIN cargo_manifest_items cmi ON c.id = cmi.container_id
            LEFT JOIN warehouse_stock ws ON cmi.warehouse_stock_id = ws.id
            WHERE ws.customer_id = ?
            ORDER BY tt.created_at DESC
            LIMIT 10";
    $stmt = safeQuery($pdo, $sql, [$session_customer_id]);
    $trips = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    $trips = [];
}

// Get recent shipments for this customer
$recent_shipments = [];
try {
    $sql = "SELECT tt.id, tt.trip_number, tt.total_cbm, tt.status, tt.created_at,
                   c.container_number
            FROM trucking_trips tt
            LEFT JOIN containers c ON tt.container_id = c.id
            LEFT JOIN cargo_manifest_items cmi ON c.id = cmi.container_id
            LEFT JOIN warehouse_stock ws ON cmi.warehouse_stock_id = ws.id
            WHERE ws.customer_id = ?
            ORDER BY tt.created_at DESC
            LIMIT 5";
    $stmt = safeQuery($pdo, $sql, [$session_customer_id]);
    $recent_shipments = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    $recent_shipments = [];
}

// Calculate credit usage percentage
$credit_usage = $customer_credit_limit > 0 ? ($customer_debt / $customer_credit_limit) * 100 : 0;

// Function to get container status in English
function getContainerStatus($status) {
    $statuses = [
        'received' => 'Received',
        'loading' => 'Loading',
        'loaded' => 'Loaded',
        'shipped' => 'Shipped',
        'in_transit' => 'In Transit',
        'at_port' => 'At Port',
        'ready' => 'Ready',
        'delivered' => 'Delivered',
        'pending' => 'Pending'
    ];
    return $statuses[$status] ?? ucfirst($status);
}

// Function to get stock status
function getStockStatus($status) {
    $statuses = [
        'not_arrived' => 'Not Arrived',
        'in_warehouse' => 'In Warehouse',
        'taken' => 'Taken',
        'delivered' => 'Delivered'
    ];
    return $statuses[$status] ?? ucfirst($status);
}

// Function to get status badge class
function getStatusBadgeClass($status) {
    $classes = [
        'paid' => 'status-paid',
        'unpaid' => 'status-unpaid',
        'partial' => 'status-partial',
        'overdue' => 'status-overdue',
        'received' => 'status-received',
        'loading' => 'status-loading',
        'loaded' => 'status-loaded',
        'shipped' => 'status-shipped',
        'in_transit' => 'status-transit',
        'at_port' => 'status-at_port',
        'ready' => 'status-ready',
        'delivered' => 'status-delivered',
        'not_arrived' => 'status-pending',
        'in_warehouse' => 'status-ready',
        'taken' => 'status-delivered'
    ];
    return $classes[$status] ?? 'status-default';
}

// Origin names
$origin_names = [
    'china_yiwu' => 'China Yiwu',
    'china_guangzhou' => 'China Guangzhou',
    'dubai' => 'Dubai',
    'local' => 'Local'
];

include_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Customer Dashboard - <?= htmlspecialchars($customer_name) ?> | Cargo Management System</title>
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
            --curdun-warning: #ffc107;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
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

        .welcome-content h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .welcome-content h1 i {
            margin-right: 10px;
        }

        .welcome-content p {
            opacity: 0.9;
            font-size: 14px;
            margin-top: 5px;
        }

        .customer-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            margin-top: 10px;
        }

        .datetime {
            margin-top: 15px;
            font-size: 13px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            padding-top: 15px;
        }

        .profile-card {
            display: flex;
            align-items: center;
            gap: 15px;
            background: rgba(255,255,255,0.15);
            padding: 10px 20px;
            border-radius: 50px;
            backdrop-filter: blur(10px);
        }

        .profile-card img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--curdun-yellow);
        }

        .profile-card .profile-name {
            font-weight: 600;
        }

        .profile-card .profile-role {
            font-size: 11px;
            opacity: 0.8;
        }

        /* Statistics Cards */
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        .status-unpaid { background: #f8d7da; color: #721c24; }
        .status-partial { background: #fff3cd; color: #856404; }
        .status-overdue { background: #f8d7da; color: #721c24; }
        .status-default { background: #e0e0e0; color: #616161; }

        /* Debt Card */
        .debt-card {
            background: linear-gradient(135deg, #ff6b6b, #ee5a24);
            color: white;
        }
        
        .debt-card .stat-info h3 {
            color: white;
        }
        
        .debt-card .stat-icon {
            background: rgba(255,255,255,0.2);
        }
        
        .debt-card .stat-icon i {
            color: white;
        }

        .credit-card {
            background: linear-gradient(135deg, #20c997, #0d9488);
            color: white;
        }
        
        .credit-card .stat-info h3 {
            color: white;
        }
        
        .credit-card .stat-icon {
            background: rgba(255,255,255,0.2);
        }
        
        .credit-card .stat-icon i {
            color: white;
        }

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

        /* Progress Bar */
        .progress {
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 10px;
        }
        
        .progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .progress-bar-danger {
            background: #dc3545;
        }
        
        .progress-bar-warning {
            background: #ffc107;
        }
        
        .progress-bar-success {
            background: #0F7A3A;
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

        .stock-status {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .dashboard-stats {
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
                gap: 15px;
            }
            
            .dashboard-row {
                grid-template-columns: 1fr;
            }
            
            .welcome-section {
                flex-direction: column;
                text-align: center;
                padding: 20px;
            }
            
            .welcome-content h1 {
                font-size: 20px;
            }
            
            .dashboard-container {
                padding: 15px;
            }
            
            .profile-card {
                justify-content: center;
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
            <div class="welcome-content">
                <h1><i class="fas fa-tachometer-alt"></i> Welcome back, <?= htmlspecialchars($customer_name) ?>!</h1>
                <p><i class="fas fa-user-circle"></i> Customer Portal - Track your shipments, invoices, and warehouse stock</p>
                <div class="customer-badge">
                    <i class="fas fa-building"></i> <?= htmlspecialchars($tenant_name) ?> | 
                    <i class="fas fa-phone"></i> <?= htmlspecialchars($customer_phone) ?>
                </div>
                <div class="datetime">
                    <i class="fas fa-calendar-alt"></i> <?= date('l, F j, Y') ?> |
                    <i class="fas fa-clock"></i> <?= date('h:i A') ?>
                </div>
            </div>
           
        </div>

        <!-- Statistics Cards -->
        <div class="dashboard-stats">
            <div class="stat-card" onclick="window.location.href='invoices.php'">
                <div class="stat-info">
                    <h3><?= number_format($stats['total_invoices']) ?></h3>
                    <p><i class="fas fa-file-invoice"></i> Total Invoices</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-file-invoice"></i>
                </div>
            </div>

            <div class="stat-card" onclick="window.location.href='invoices.php?status=paid'">
                <div class="stat-info">
                    <h3><?= number_format($stats['paid_invoices']) ?></h3>
                    <p><i class="fas fa-check-circle"></i> Paid Invoices</p>
                </div>
                <div class="stat-icon" style="background: rgba(15, 122, 58, 0.1);">
                    <i class="fas fa-check-circle" style="color: #0F7A3A;"></i>
                </div>
            </div>

            <div class="stat-card" onclick="window.location.href='invoices.php?status=unpaid'">
                <div class="stat-info">
                    <h3><?= number_format($stats['unpaid_invoices']) ?></h3>
                    <p><i class="fas fa-clock"></i> Unpaid Invoices</p>
                </div>
                <div class="stat-icon" style="background: rgba(220, 53, 69, 0.1);">
                    <i class="fas fa-clock" style="color: #dc3545;"></i>
                </div>
            </div>

            <div class="stat-card credit-card" onclick="window.location.href='payments.php'">
                <div class="stat-info">
                    <h3>$<?= number_format($stats['invoice_totals']['paid'], 2) ?></h3>
                    <p><i class="fas fa-dollar-sign"></i> Total Paid</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-dollar-sign"></i>
                </div>
            </div>
        </div>

        <!-- Debt & Credit Row -->
        <div class="dashboard-row">
            <!-- Debt Summary -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-credit-card"></i> Account Summary</h3>
                </div>
                <div class="card-body">
                    <ul class="status-list">
                        <li>
                            <span><strong>Total Invoiced Amount</strong></span>
                            <strong class="financial-positive">$<?= number_format($stats['invoice_totals']['total'], 2) ?></strong>
                        </li>
                        <li>
                            <span><strong>Total Paid Amount</strong></span>
                            <strong class="financial-positive">$<?= number_format($stats['invoice_totals']['paid'], 2) ?></strong>
                        </li>
                        <li>
                            <span><strong>Outstanding Balance</strong></span>
                            <strong class="<?= $stats['invoice_totals']['balance'] > 0 ? 'financial-negative' : 'financial-positive' ?>">
                                $<?= number_format($stats['invoice_totals']['balance'], 2) ?>
                            </strong>
                        </li>
                        <li>
                            <span><strong>Credit Limit</strong></span>
                            <strong>$<?= number_format($customer_credit_limit, 2) ?></strong>
                        </li>
                        <li>
                            <span><strong>Credit Usage</strong></span>
                            <strong>
                                <span class="status-badge" style="background: <?= $credit_usage >= 80 ? '#f8d7da' : ($credit_usage >= 50 ? '#fff3cd' : '#EEFBF3') ?>; color: <?= $credit_usage >= 80 ? '#721c24' : ($credit_usage >= 50 ? '#856404' : '#155724') ?>">
                                    <?= number_format($credit_usage, 1) ?>% Used
                                </span>
                            </strong>
                        </li>
                    </ul>
                    <div class="progress">
                        <div class="progress-bar <?= $credit_usage >= 80 ? 'progress-bar-danger' : ($credit_usage >= 50 ? 'progress-bar-warning' : 'progress-bar-success') ?>" 
                             style="width: <?= min(100, $credit_usage) ?>%"></div>
                    </div>
                    <?php if ($customer_loyalty_points > 0): ?>
                    <div class="mt-3 text-center">
                        <small><i class="fas fa-star" style="color: #ffc107;"></i> Loyalty Points: <strong><?= number_format($customer_loyalty_points) ?></strong></small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                </div>
                <div class="card-body">
                    <div class="quick-actions">
                        <a href="invoices.php" class="quick-action-btn"><i class="fas fa-file-invoice"></i><span>View My Invoices</span><i class="fas fa-chevron-right"></i></a>
                        <a href="payments.php" class="quick-action-btn"><i class="fas fa-credit-card"></i><span>Make a Payment</span><i class="fas fa-chevron-right"></i></a>
                        <a href="tracking.php" class="quick-action-btn"><i class="fas fa-map-marker-alt"></i><span>Track My Shipments</span><i class="fas fa-chevron-right"></i></a>
                        <a href="warehouse_stock.php" class="quick-action-btn"><i class="fas fa-warehouse"></i><span>My Warehouse Stock</span><i class="fas fa-chevron-right"></i></a>
                        <a href="support.php" class="quick-action-btn"><i class="fas fa-life-ring"></i><span>Support</span><i class="fas fa-chevron-right"></i></a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dashboard Row - Recent Invoices -->
        <div class="dashboard-row">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-file-invoice-dollar"></i> Recent Invoices</h3>
                    <a href="invoices.php" class="view-all-link">View All →</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th>Invoice #</th><th>Date</th><th>Due Date</th><th>Amount</th><th>Status</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_invoices)): ?>
                                    <?php foreach ($recent_invoices as $invoice): ?>
                                        <?php $due_amount = $invoice['due_amount'] ?? ($invoice['total_amount'] - $invoice['paid_amount']); ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($invoice['invoice_number']) ?></strong></td>
                                            <td><?= date('M d, Y', strtotime($invoice['invoice_date'])) ?></td>
                                            <td><?= date('M d, Y', strtotime($invoice['due_date'])) ?></td>
                                            <td>$<?= number_format($invoice['total_amount'], 2) ?></td>
                                            <td><span class="status-badge <?= getStatusBadgeClass($invoice['status']) ?>"><?= ucfirst($invoice['status']) ?></span></td>
                                            <td>
                                                <?php if ($invoice['status'] != 'paid'): ?>
                                                    <a href="make_payment.php?invoice_id=<?= $invoice['id'] ?>" class="btn btn-sm" style="background: #0F7A3A; color: white; padding: 4px 12px; border-radius: 20px; text-decoration: none; font-size: 11px;">Pay Now</a>
                                                <?php else: ?>
                                                    <a href="invoices.php?id=<?= $invoice['id'] ?>" class="btn btn-sm" style="background: #2D1859; color: white; padding: 4px 12px; border-radius: 20px; text-decoration: none; font-size: 11px;">View</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" style="text-align: center;">No invoices found</a></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Recent Payments -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-receipt"></i> Recent Payments</h3>
                    <a href="payments.php" class="view-all-link">View All →</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th>Receipt #</th><th>Date</th><th>Invoice</th><th>Amount</th><th>Method</th></tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_payments)): ?>
                                    <?php foreach ($recent_payments as $payment): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($payment['receipt_number']) ?></strong></td>
                                            <td><?= date('M d, Y', strtotime($payment['payment_date'])) ?></td>
                                            <td><?= htmlspecialchars($payment['invoice_number'] ?? 'N/A') ?></td>
                                            <td>$<?= number_format($payment['amount'], 2) ?></td>
                                            <td><?= ucfirst($payment['payment_method'] ?? 'Cash') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" style="text-align: center;">No payments recorded</a></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dashboard Row - Warehouse Stock & Shipments -->
        <div class="dashboard-row">
            <!-- My Warehouse Stock -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-warehouse"></i> My Warehouse Stock</h3>
                    <a href="warehouse_stock.php" class="view-all-link">View All →</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th>Item Name</th><th>Quantity</th><th>Volume (CBM)</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($stock_items)): ?>
                                    <?php foreach ($stock_items as $item): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($item['stock_name']) ?></strong></td>
                                            <td><?= number_format($item['quantity']) ?></td>
                                            <td><?= number_format($item['volume_cbm'], 2) ?></td>
                                            <td><span class="stock-status status-badge <?= getStatusBadgeClass($item['mogadishu_status']) ?>"><?= getStockStatus($item['mogadishu_status']) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" style="text-align: center;">No stock items found</a></td></tr>
                                <?php endif; ?>
                            </tbody>
                        <tr>
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
                                    <tr><td colspan="5" style="text-align: center;">No shipments found</a></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Containers Section -->
        <div class="dashboard-card" style="margin-bottom: 30px;">
            <div class="card-header">
                <h3><i class="fas fa-box"></i> My Containers</h3>
                <a href="tracking.php" class="view-all-link">View All →</a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Container No</th>
                                <th>Type</th>
                                <th>Origin</th>
                                <th>Size (CBM)</th>
                                <th>Status</th>
                                <th>Trip Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($containers)): ?>
                                <?php foreach ($containers as $container): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($container['container_number'] ?? 'N/A') ?></strong></td>
                                        <td><?= strtoupper($container['container_type'] ?? '20ft') ?></td>
                                        <td><?= $origin_names[$container['origin']] ?? ($container['origin'] ?? 'N/A') ?></td>
                                        <td><?= number_format($container['size_cbm'] ?? 0, 2) ?> CBM</td>
                                        <td><span class="status-badge <?= getStatusBadgeClass($container['status'] ?? 'received') ?>"><?= getContainerStatus($container['status'] ?? 'received') ?></span></td>
                                        <td><span class="status-badge <?= getStatusBadgeClass($container['trip_status'] ?? 'pending') ?>"><?= ucfirst($container['trip_status'] ?? 'Pending') ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" style="text-align: center;">No containers found</a></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>