<?php
// customer/warehouse_stock.php
// Warehouse Stock View forfaras cargo - Customer Portal (Read Only)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is customer
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

// Get tenant info for support contact
$tenant_info = [];
$tenant_phone = '';
$tenant_email = '';
$tenant_address = '';
$whatsapp_number = '';

if ($session_tenant_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT name, phone, email, address, code FROM tenants WHERE id = ?");
        $stmt->execute([$session_tenant_id]);
        $tenant_info = $stmt->fetch(PDO::FETCH_ASSOC);
        $tenant_name = $tenant_info['name'] ?? 'Cargo Management System';
        $tenant_phone = $tenant_info['phone'] ?? '';
        $tenant_email = $tenant_info['email'] ?? 'info@curdun.com';
        $tenant_address = $tenant_info['address'] ?? 'Mogadishu, Somalia';
    } catch (PDOException $e) {
        $tenant_name = 'Cargo Management System';
        $tenant_phone = '';
        $tenant_email = 'info@curdun.com';
        $tenant_address = 'Mogadishu, Somalia';
    }
} else {
    $tenant_name = 'Cargo Management System';
    $tenant_phone = '';
    $tenant_email = 'info@curdun.com';
    $tenant_address = 'Mogadishu, Somalia';
}

// Get support settings from system_settings
$support_phone = $tenant_phone;
$support_email = $tenant_email;
$support_whatsapp = '';

try {
    // Try to get WhatsApp number from settings
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE tenant_id = ? AND setting_key = 'support_whatsapp'");
    $stmt->execute([$session_tenant_id]);
    $whatsapp = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($whatsapp) {
        $support_whatsapp = $whatsapp['setting_value'];
    } else {
        // Use tenant phone as fallback
        $support_whatsapp = $tenant_phone;
    }
    
    // Try to get support phone from settings
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE tenant_id = ? AND setting_key = 'support_phone'");
    $stmt->execute([$session_tenant_id]);
    $phone = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($phone) {
        $support_phone = $phone['setting_value'];
    }
    
    // Try to get support email from settings
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE tenant_id = ? AND setting_key = 'support_email'");
    $stmt->execute([$session_tenant_id]);
    $email = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($email) {
        $support_email = $email['setting_value'];
    }
} catch (PDOException $e) {
    // Use defaults
}

// Format phone number for WhatsApp
$whatsapp_link = '';
if (!empty($support_whatsapp)) {
    $whatsapp_link = preg_replace('/[^0-9]/', '', $support_whatsapp);
    if (strlen($whatsapp_link) === 9 && ($whatsapp_link[0] === '6' || $whatsapp_link[0] === '7')) {
        $whatsapp_link = '252' . $whatsapp_link;
    }
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
// GET STOCK ITEMS FOR THIS CUSTOMER
// ============================================

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;
$search = $_GET['search'] ?? '';
$origin_filter = $_GET['origin'] ?? 'all';

$where_conditions = ["ws.customer_id = ?"];
$params = [$session_customer_id];

if (!empty($search)) {
    $where_conditions[] = "(ws.stock_name LIKE ? OR ws.location LIKE ?)";
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
$stmt = safeQuery($pdo, $count_sql, $params);
$total_items = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC)['total'] : 0;
$total_pages = ceil($total_items / $limit);

// Get stock items
$sql = "
    SELECT ws.*, 
           (SELECT invoice_number FROM invoices WHERE customer_id = ws.customer_id ORDER BY created_at DESC LIMIT 1) as latest_invoice_number
    FROM warehouse_stock ws
    $where_clause
    ORDER BY ws.created_at DESC
    LIMIT $limit OFFSET $offset
";

$stmt = safeQuery($pdo, $sql, $params);
$stock_items = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Get summary statistics
$summary_sql = "
    SELECT 
        COUNT(*) as total_items,
        SUM(quantity) as total_quantity,
        SUM(volume_cbm) as total_volume,
        SUM(volume_cbm * unit_price) as total_value,
        COUNT(CASE WHEN origin = 'china_yiwu' THEN 1 END) as yiwu_items,
        COUNT(CASE WHEN origin = 'china_guangzhou' THEN 1 END) as guangzhou_items,
        COUNT(CASE WHEN origin = 'dubai' THEN 1 END) as dubai_items
    FROM warehouse_stock
    WHERE customer_id = ?
";
$stmt = safeQuery($pdo, $summary_sql, [$session_customer_id]);
$stats = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [
    'total_items' => 0, 'total_quantity' => 0, 'total_volume' => 0, 
    'total_value' => 0, 'yiwu_items' => 0, 'guangzhou_items' => 0, 'dubai_items' => 0
];

// Get low stock items count
$low_stock_sql = "SELECT COUNT(*) as count FROM warehouse_stock WHERE customer_id = ? AND quantity <= minimum_stock AND minimum_stock > 0";
$stmt = safeQuery($pdo, $low_stock_sql, [$session_customer_id]);
$low_stock_count = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC)['count'] : 0;

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
    <title>My Warehouse Stock - <?= htmlspecialchars($customer_name) ?> | Cargo Management System</title>
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
        .page-header .customer-badge {
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
        }
        .page-header .company-badge {
            background: rgba(255,255,255,0.15);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
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
            border-left: 3px solid var(--curdun-violet);
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-card .stat-info h4 { font-size: 11px; color: var(--curdun-gray); margin: 0 0 5px 0; text-transform: uppercase; }
        .stat-card .stat-info .stat-number { font-size: 22px; font-weight: 700; color: var(--curdun-violet); }
        .stat-card .stat-icon { width: 45px; height: 45px; background: rgba(82,0,102,0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center; }
        .stat-card .stat-icon i { font-size: 22px; color: var(--curdun-violet); }
        
        .stat-card-danger .stat-info .stat-number { color: #B42318; }
        .stat-card-danger .stat-icon { background: rgba(180,35,24,0.1); }
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
            min-width: 1000px;
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
        .origin-china_yiwu, .origin-china_guangzhou { background: #e3f2fd; color: #1565c0; }
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

        /* READ-ONLY STYLES */
        .readonly-badge {
            background: #EEFBF3;
            color: #0F7A3A;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            margin-left: 10px;
        }

        .alert { padding: 12px 20px; border-radius: 8px; position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .alert-success { background: #EEFBF3; color: #0F7A3A; border-left: 4px solid #0F7A3A; }
        .alert-error { background: #FEF0EE; color: #B42318; border-left: 4px solid #B42318; }
        .alert-info { background: #e3f2fd; color: #1565c0; border-left: 4px solid #1565c0; }

        .empty-state { text-align: center; padding: 50px; color: var(--curdun-gray); }
        .empty-state i { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }

        .loading-spinner { text-align: center; padding: 50px; }
        .loading-spinner i { font-size: 48px; color: var(--curdun-violet); animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 25px; flex-wrap: wrap; }
        .pagination a, .pagination span { padding: 8px 14px; border-radius: 8px; text-decoration: none; color: var(--curdun-dark); background: white; border: 1px solid #ddd; cursor: pointer; transition: all 0.3s ease; }
        .pagination .active { background: var(--curdun-violet); color: white; border-color: var(--curdun-violet); }
        .pagination a:hover { background: var(--curdun-violet-light); color: white; transform: translateY(-2px); }

        /* Support Contact Styles */
        .support-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            padding: 20px;
            margin-top: 30px;
            color: white;
        }
        .support-card h4 {
            font-size: 18px;
            margin-bottom: 15px;
            font-weight: 600;
        }
        .support-card h4 i {
            margin-right: 10px;
        }
        .support-contact-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        .support-contact-item:last-child {
            border-bottom: none;
        }
        .support-contact-item i {
            width: 35px;
            font-size: 18px;
            text-align: center;
        }
        .support-contact-item .contact-details {
            flex: 1;
        }
        .support-contact-item .contact-label {
            font-size: 11px;
            opacity: 0.8;
        }
        .support-contact-item .contact-value {
            font-size: 14px;
            font-weight: 500;
        }
        .support-contact-item .contact-value a {
            color: white;
            text-decoration: none;
        }
        .support-contact-item .contact-value a:hover {
            text-decoration: underline;
        }
        .btn-support-whatsapp {
            background: #25D366;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-support-whatsapp:hover {
            background: #128C7E;
            transform: translateY(-2px);
        }
        .btn-support-call {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-support-call:hover {
            background: #138496;
            transform: translateY(-2px);
        }
        .btn-support-email {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-support-email:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        .whatsapp-btn {
            background: #25D366;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .whatsapp-btn:hover {
            background: #128C7E;
            transform: scale(1.05);
        }

        .info-note {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: center; }
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .stock-table th:nth-child(4), .stock-table td:nth-child(4),
            .stock-table th:nth-child(5), .stock-table td:nth-child(5) {
                display: none;
            }
            .support-card { text-align: center; }
            .support-contact-item { flex-direction: column; text-align: center; gap: 5px; }
            .support-contact-item i { width: auto; }
        }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-warehouse"></i> My Warehouse Stock</h1>
        <div class="d-flex gap-3 align-items-center flex-wrap">
            <span class="customer-badge"><i class="fas fa-user"></i> <?= htmlspecialchars($customer_name) ?></span>
            <span class="company-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($tenant_name) ?></span>
            <span class="readonly-badge"><i class="fas fa-eye"></i> Read Only Mode</span>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-info"><h4>Total Items</h4><div class="stat-number" id="stat-total-items"><?= number_format($stats['total_items']) ?></div></div>
            <div class="stat-icon"><i class="fas fa-boxes"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info"><h4>Total Quantity</h4><div class="stat-number" id="stat-total-quantity"><?= number_format($stats['total_quantity']) ?></div></div>
            <div class="stat-icon"><i class="fas fa-cubes"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info"><h4>Total Volume</h4><div class="stat-number" id="stat-total-volume"><?= number_format($stats['total_volume'], 2) ?> CBM</div></div>
            <div class="stat-icon"><i class="fas fa-cube"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info"><h4>Total Value</h4><div class="stat-number" id="stat-total-value">$<?= number_format($stats['total_value'], 2) ?></div></div>
            <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
        </div>
        <?php if ($low_stock_count > 0): ?>
        <div class="stat-card stat-card-danger">
            <div class="stat-info"><h4>Low Stock Alert</h4><div class="stat-number"><?= number_format($low_stock_count) ?></div></div>
            <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Info Note -->
   

    <!-- Filters -->
    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group"><label><i class="fas fa-search"></i> Search</label><input type="text" id="searchInput" placeholder="Package name, location..." value="<?= htmlspecialchars($search) ?>"></div>
            <div class="filter-group"><label><i class="fas fa-map-marker-alt"></i> Origin</label><select id="originFilter">
                <option value="all" <?= $origin_filter == 'all' ? 'selected' : '' ?>>All Origins</option>
                <option value="china_yiwu" <?= $origin_filter == 'china_yiwu' ? 'selected' : '' ?>>China Yiwu 🇨🇳</option>
                <option value="china_guangzhou" <?= $origin_filter == 'china_guangzhou' ? 'selected' : '' ?>>China Guangzhou 🇨🇳</option>
                <option value="dubai" <?= $origin_filter == 'dubai' ? 'selected' : '' ?>>Dubai 🇦🇪</option>
            </select></div>
            <div class="filter-group"><button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Filter</button><button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Reset</button></div>
        </div>
    </div>

    <div id="stock-table-container">
        <?php if (count($stock_items) > 0): ?>
        <div class="stock-table-container">
            <table class="stock-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Package Details</th>
                        <th>Origin</th>
                        <th>Quantity</th>
                        <th>Size (CBM/FT)</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Price/Unit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stock_items as $item): 
                        $originText = $origin_names[$item['origin']] ?? ucfirst($item['origin']);
                        $originIcon = strpos($item['origin'], 'china') !== false ? '🇨🇳' : '🇦🇪';
                        $isLowStock = $item['quantity'] <= $item['minimum_stock'];
                        $stockStatusClass = $isLowStock ? 'status-low' : 'status-good';
                        $stockStatusText = $isLowStock ? 'Low Stock Alert' : 'Good';
                        $quantityPercent = $item['maximum_stock'] > 0 ? min(100, ($item['quantity'] / $item['maximum_stock']) * 100) : 0;
                        $unit = ($item['origin'] === 'dubai') ? 'FT' : 'CBM';
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
                                    <?php if ($item['maximum_stock'] > 0): ?>
                                    <div class="progress-bar-container" style="margin-top: 5px;">
                                        <div class="progress-bar" style="width: <?= $quantityPercent ?>%; background: <?= $isLowStock ? '#B42318' : '#0F7A3A' ?>;"</div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?= number_format($item['volume_cbm'], 2) ?> <?= $unit ?>
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
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&origin=<?= urlencode($origin_filter) ?>"><i class="fas fa-chevron-left"></i> Previous</a>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php if ($i == $page): ?>
                    <span class="active"><?= $i ?></span>
                <?php else: ?>
                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&origin=<?= urlencode($origin_filter) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
                <a href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&origin=<?= urlencode($origin_filter) ?>">Next <i class="fas fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-warehouse"></i>
            <p>No packages found in your warehouse stock.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Support Section - Contact Company -->
    
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    function applyFilters() {
        let url = new URL(window.location.href);
        url.searchParams.set('page', 1);
        url.searchParams.set('search', $('#searchInput').val());
        url.searchParams.set('origin', $('#originFilter').val());
        window.location.href = url.toString();
    }

    $('#applyFilters').click(function() { applyFilters(); });
    $('#resetFilters').click(function() { 
        $('#searchInput').val(''); 
        $('#originFilter').val('all');
        window.location.href = window.location.pathname;
    });
    $('#searchInput').keypress(function(e) { if (e.which === 13) { applyFilters(); } });
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>