<?php
// superadmin/drivers.php
//faras cargo - Complete Driver Management System
// Professional driver management with assignments, performance tracking, and scheduling

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has appropriate permissions
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION['role'] ?? 'staff';
$session_tenant_id = $_SESSION['tenant_id'] ?? 0;
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';

// Only superadmin, company_admin, and logistics_supervisor can access this page
if (!in_array($role, ['superadmin', 'company_admin', 'logistics_supervisor'])) {
    header("Location: ../dashboard.php");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';

// Helper function for safe query execution
if (!function_exists('safeQuery')) {
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
}

// Handle AJAX requests
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    switch ($action) {
        case 'get_drivers':
            $sql = "SELECT d.*, 
                    u.full_name as user_name, u.email, u.phone,
                    (SELECT COUNT(*) FROM trucking_trips WHERE driver_id = d.id AND status IN ('completed', 'delivered')) as completed_trips,
                    (SELECT COUNT(*) FROM assignments WHERE assigned_to_driver_id = d.id AND status = 'pending') as pending_tasks
                    FROM drivers d
                    LEFT JOIN users u ON d.user_id = u.id";
            
            if ($role === 'company_admin') {
                $sql .= " WHERE d.tenant_id = ?";
                $params = [$session_tenant_id];
            } elseif ($role === 'logistics_supervisor') {
                $sql .= " WHERE d.tenant_id = ?";
                $params = [$session_tenant_id];
            } else {
                $params = [];
            }
            
            $sql .= " ORDER BY d.is_active DESC, d.full_name ASC";
            
            $stmt = safeQuery($pdo, $sql, $params);
            if ($stmt) {
                $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $response = ['success' => true, 'data' => $drivers];
            }
            break;
            
        case 'get_driver':
            $driver_id = (int)($_POST['driver_id'] ?? 0);
            if ($driver_id > 0) {
                $sql = "SELECT d.*, u.full_name as user_name, u.email, u.phone 
                        FROM drivers d
                        LEFT JOIN users u ON d.user_id = u.id
                        WHERE d.id = ?";
                $params = [$driver_id];
                
                if ($role !== 'superadmin') {
                    $sql .= " AND d.tenant_id = ?";
                    $params[] = $session_tenant_id;
                }
                
                $stmt = safeQuery($pdo, $sql, $params);
                if ($stmt && $row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    // Get current assignments
                    $assign_sql = "SELECT a.*, t.trip_number, t.container_id 
                                  FROM assignments a
                                  LEFT JOIN trucking_trips t ON a.trip_id = t.id
                                  WHERE a.assigned_to_driver_id = ? AND a.status = 'in_progress'
                                  ORDER BY a.created_at DESC LIMIT 5";
                    $assign_stmt = safeQuery($pdo, $assign_sql, [$driver_id]);
                    $row['current_assignments'] = $assign_stmt ? $assign_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                    
                    // Get performance stats
                    $perf_sql = "SELECT * FROM staff_performance WHERE user_id = ? ORDER BY period_end DESC LIMIT 6";
                    $perf_stmt = safeQuery($pdo, $perf_sql, [$row['user_id']]);
                    $row['performance'] = $perf_stmt ? $perf_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                    
                    $response = ['success' => true, 'data' => $row];
                } else {
                    $response = ['success' => false, 'message' => 'Driver not found'];
                }
            }
            break;
            
        case 'create_driver':
            $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
            $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
            $license_number = isset($_POST['license_number']) ? trim($_POST['license_number']) : '';
            $license_expiry = isset($_POST['license_expiry']) ? $_POST['license_expiry'] : null;
            $employee_id = isset($_POST['employee_id']) ? trim($_POST['employee_id']) : '';
            $hire_date = isset($_POST['hire_date']) ? $_POST['hire_date'] : date('Y-m-d');
            $salary_type = isset($_POST['salary_type']) ? $_POST['salary_type'] : 'fixed';
            $salary_amount = isset($_POST['salary_amount']) ? (float)$_POST['salary_amount'] : 0;
            $user_id_assigned = isset($_POST['user_id']) && !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
            
            if (empty($full_name)) {
                $response = ['success' => false, 'message' => 'Driver name is required'];
                break;
            }
            
            // Check if driver with same license number exists
            if (!empty($license_number)) {
                $check_sql = "SELECT id FROM drivers WHERE license_number = ?";
                $params = [$license_number];
                if ($role !== 'superadmin') {
                    $check_sql .= " AND tenant_id = ?";
                    $params[] = $session_tenant_id;
                }
                $check_stmt = safeQuery($pdo, $check_sql, $params);
                if ($check_stmt && $check_stmt->rowCount() > 0) {
                    $response = ['success' => false, 'message' => 'Driver with this license number already exists'];
                    break;
                }
            }
            
            $tenant_id = ($role === 'superadmin') ? (isset($_POST['tenant_id']) && !empty($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : null) : $session_tenant_id;
            
            $sql = "INSERT INTO drivers (tenant_id, user_id, full_name, phone, license_number, license_expiry, 
                    employee_id, hire_date, salary_type, salary_amount, is_active, created_at, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?)";
            $stmt = safeQuery($pdo, $sql, [$tenant_id, $user_id_assigned, $full_name, $phone, $license_number, $license_expiry, 
                    $employee_id, $hire_date, $salary_type, $salary_amount, $user_id]);
            
            if ($stmt) {
                $driver_id = $pdo->lastInsertId();
                
                // Log activity
                logActivity($user_id, 'CREATE_DRIVER', 'drivers', $driver_id, null, ['full_name' => $full_name]);
                
                $response = ['success' => true, 'message' => 'Driver created successfully', 'driver_id' => $driver_id];
            } else {
                $response = ['success' => false, 'message' => 'Failed to create driver'];
            }
            break;
            
        case 'update_driver':
            $driver_id = (int)($_POST['driver_id'] ?? 0);
            $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
            $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
            $license_number = isset($_POST['license_number']) ? trim($_POST['license_number']) : '';
            $license_expiry = isset($_POST['license_expiry']) ? $_POST['license_expiry'] : null;
            $employee_id = isset($_POST['employee_id']) ? trim($_POST['employee_id']) : '';
            $salary_type = isset($_POST['salary_type']) ? $_POST['salary_type'] : 'fixed';
            $salary_amount = isset($_POST['salary_amount']) ? (float)$_POST['salary_amount'] : 0;
            $user_id_assigned = isset($_POST['user_id']) && !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
            
            if ($driver_id <= 0 || empty($full_name)) {
                $response = ['success' => false, 'message' => 'Invalid driver data'];
                break;
            }
            
            $sql = "UPDATE drivers SET full_name = ?, phone = ?, license_number = ?, license_expiry = ?, 
                    employee_id = ?, salary_type = ?, salary_amount = ?, user_id = ? WHERE id = ?";
            $params = [$full_name, $phone, $license_number, $license_expiry, $employee_id, $salary_type, $salary_amount, $user_id_assigned, $driver_id];
            
            if ($role !== 'superadmin') {
                $sql .= " AND tenant_id = ?";
                $params[] = $session_tenant_id;
            }
            
            $stmt = safeQuery($pdo, $sql, $params);
            
            if ($stmt) {
                logActivity($user_id, 'UPDATE_DRIVER', 'drivers', $driver_id, null, ['full_name' => $full_name]);
                $response = ['success' => true, 'message' => 'Driver updated successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to update driver'];
            }
            break;
            
        case 'delete_driver':
            $driver_id = (int)($_POST['driver_id'] ?? 0);
            
            if ($driver_id <= 0) {
                $response = ['success' => false, 'message' => 'Invalid driver ID'];
                break;
            }
            
            // Check if driver has active trips
            $check_sql = "SELECT COUNT(*) as count FROM trucking_trips WHERE driver_id = ? AND status NOT IN ('completed', 'delivered', 'cancelled')";
            $check_stmt = safeQuery($pdo, $check_sql, [$driver_id]);
            if ($check_stmt && $row = $check_stmt->fetch()) {
                if ($row['count'] > 0) {
                    $response = ['success' => false, 'message' => 'Cannot delete driver with active trips'];
                    break;
                }
            }
            
            $sql = "DELETE FROM drivers WHERE id = ?";
            $params = [$driver_id];
            
            if ($role !== 'superadmin') {
                $sql .= " AND tenant_id = ?";
                $params[] = $session_tenant_id;
            }
            
            $stmt = safeQuery($pdo, $sql, $params);
            
            if ($stmt) {
                logActivity($user_id, 'DELETE_DRIVER', 'drivers', $driver_id, null, null);
                $response = ['success' => true, 'message' => 'Driver deleted successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to delete driver'];
            }
            break;
            
        case 'toggle_status':
            $driver_id = (int)($_POST['driver_id'] ?? 0);
            $is_active = (int)($_POST['is_active'] ?? 0);
            
            if ($driver_id <= 0) {
                $response = ['success' => false, 'message' => 'Invalid driver ID'];
                break;
            }
            
            $sql = "UPDATE drivers SET is_active = ? WHERE id = ?";
            $params = [$is_active, $driver_id];
            
            if ($role !== 'superadmin') {
                $sql .= " AND tenant_id = ?";
                $params[] = $session_tenant_id;
            }
            
            $stmt = safeQuery($pdo, $sql, $params);
            
            if ($stmt) {
                $status_text = $is_active ? 'activated' : 'deactivated';
                logActivity($user_id, 'TOGGLE_DRIVER_STATUS', 'drivers', $driver_id, null, ['status' => $status_text]);
                $response = ['success' => true, 'message' => "Driver $status_text successfully"];
            } else {
                $response = ['success' => false, 'message' => 'Failed to update driver status'];
            }
            break;
            
        case 'get_trips':
            $driver_id = (int)($_GET['driver_id'] ?? 0);
            $limit = (int)($_GET['limit'] ?? 20);
            
            if ($driver_id <= 0) {
                $response = ['success' => false, 'message' => 'Invalid driver ID'];
                break;
            }
            
            $sql = "SELECT t.*, c.container_number, c.origin 
                    FROM trucking_trips t
                    LEFT JOIN containers c ON t.container_id = c.id
                    WHERE t.driver_id = ?
                    ORDER BY t.created_at DESC LIMIT ?";
            $stmt = safeQuery($pdo, $sql, [$driver_id, $limit]);
            $trips = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $response = ['success' => true, 'data' => $trips];
            break;
            
        case 'get_assignments':
            $driver_id = (int)($_GET['driver_id'] ?? 0);
            
            if ($driver_id <= 0) {
                $response = ['success' => false, 'message' => 'Invalid driver ID'];
                break;
            }
            
            $sql = "SELECT a.*, t.trip_number, t.container_id, c.container_number 
                    FROM assignments a
                    LEFT JOIN trucking_trips t ON a.trip_id = t.id
                    LEFT JOIN containers c ON t.container_id = c.id
                    WHERE a.assigned_to_driver_id = ?
                    ORDER BY a.created_at DESC LIMIT 20";
            $stmt = safeQuery($pdo, $sql, [$driver_id]);
            $assignments = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $response = ['success' => true, 'data' => $assignments];
            break;
            
        case 'get_performance':
            $driver_id = (int)($_GET['driver_id'] ?? 0);
            $period = $_GET['period'] ?? 'month';
            
            if ($driver_id <= 0) {
                $response = ['success' => false, 'message' => 'Invalid driver ID'];
                break;
            }
            
            // Get driver user_id
            $driver_sql = "SELECT user_id FROM drivers WHERE id = ?";
            $driver_stmt = safeQuery($pdo, $driver_sql, [$driver_id]);
            $driver = $driver_stmt ? $driver_stmt->fetch(PDO::FETCH_ASSOC) : null;
            
            if ($driver && $driver['user_id']) {
                $sql = "SELECT * FROM staff_performance WHERE user_id = ? ORDER BY period_end DESC LIMIT 12";
                $stmt = safeQuery($pdo, $sql, [$driver['user_id']]);
                $performance = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                $response = ['success' => true, 'data' => $performance];
            } else {
                $response = ['success' => true, 'data' => []];
            }
            break;
            
        case 'get_users':
            $sql = "SELECT id, full_name, email, phone FROM users WHERE role IN ('driver', 'staff')";
            $params = [];
            
            if ($role === 'company_admin') {
                $sql .= " AND tenant_id = ?";
                $params[] = $session_tenant_id;
            }
            
            $sql .= " ORDER BY full_name";
            
            $stmt = safeQuery($pdo, $sql, $params);
            $users = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $response = ['success' => true, 'data' => $users];
            break;
            
        case 'get_available_drivers':
            $date = $_GET['date'] ?? date('Y-m-d');
            
            $sql = "SELECT d.* 
                    FROM drivers d
                    WHERE d.is_active = 1";
            
            if ($role !== 'superadmin') {
                $sql .= " AND d.tenant_id = ?";
                $params = [$session_tenant_id];
            } else {
                $params = [];
            }
            
            // Exclude drivers with trips on this date
            $sql .= " AND d.id NOT IN (
                        SELECT DISTINCT driver_id FROM trucking_trips 
                        WHERE DATE(departed_at) = ? OR DATE(arrived_at) = ?
                    )";
            $params[] = $date;
            $params[] = $date;
            
            $sql .= " ORDER BY d.rating DESC, d.full_name";
            
            $stmt = safeQuery($pdo, $sql, $params);
            $drivers = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $response = ['success' => true, 'data' => $drivers];
            break;
            
        case 'get_license_expiring':
            $days = (int)($_GET['days'] ?? 30);
            
            $sql = "SELECT d.* 
                    FROM drivers d
                    WHERE d.license_expiry IS NOT NULL 
                    AND d.license_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
                    AND d.is_active = 1";
            $params = [$days];
            
            if ($role !== 'superadmin') {
                $sql .= " AND d.tenant_id = ?";
                $params[] = $session_tenant_id;
            }
            
            $sql .= " ORDER BY d.license_expiry ASC";
            
            $stmt = safeQuery($pdo, $sql, $params);
            $drivers = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $response = ['success' => true, 'data' => $drivers];
            break;
            
        case 'get_statistics':
            $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
                        AVG(rating) as avg_rating,
                        SUM(total_trips) as total_trips
                    FROM drivers";
            $params = [];
            
            if ($role !== 'superadmin') {
                $sql .= " WHERE tenant_id = ?";
                $params[] = $session_tenant_id;
            }
            
            $stmt = safeQuery($pdo, $sql, $params);
            $stats = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [];
            $response = ['success' => true, 'data' => $stats];
            break;
    }
    
    echo json_encode($response);
    exit;
}

// Get all tenants for filter (superadmin only)
$tenants = [];
if ($role === 'superadmin') {
    $stmt = safeQuery($pdo, "SELECT id, name FROM tenants ORDER BY name");
    if ($stmt) $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Salary types
$salary_types = [
    'fixed' => 'Fixed Monthly Salary',
    'per_trip' => 'Per Trip',
    'per_km' => 'Per Kilometer',
    'commission' => 'Commission Based',
    'daily' => 'Daily Rate'
];

include_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Management - Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap4.min.css">
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
        }
        .page-header h1 { color: white; font-size: 24px; margin: 0; }
        .page-header p { color: rgba(255,255,255,0.9); margin: 5px 0 0; }

        .btn-primary-custom {
            background: var(--curdun-yellow);
            color: var(--curdun-violet);
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-primary-custom:hover {
            background: var(--curdun-yellow-dark);
            color: var(--curdun-violet);
            transform: translateY(-2px);
        }

        .btn-violet {
            background: var(--curdun-violet);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        .btn-violet:hover {
            background: var(--curdun-violet-light);
            color: white;
            transform: translateY(-2px);
        }

        .card {
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border: none;
            margin-bottom: 25px;
        }
        .card-header {
            background: white;
            border-bottom: 2px solid var(--curdun-violet);
            padding: 15px 20px;
            border-radius: 12px 12px 0 0 !important;
            font-weight: 600;
        }
        .card-header h5 { margin: 0; color: var(--curdun-dark); }
        .card-header h5 i { color: var(--curdun-violet); margin-right: 8px; }

        .table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
        }
        .data-table {
            width: 100%;
            margin: 0;
        }
        .data-table th {
            background: #f8f6f9;
            color: var(--curdun-dark);
            font-weight: 600;
            border-bottom: 2px solid var(--curdun-violet);
        }
        .data-table td {
            vertical-align: middle;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-active { background: #4caf50; color: white; }
        .status-inactive { background: #f44336; color: white; }

        .rating-stars {
            color: #ffc107;
            font-size: 14px;
        }

        .driver-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--curdun-violet);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
        }

        .stats-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            border-left: 3px solid var(--curdun-violet);
        }
        .stats-card h3 {
            font-size: 28px;
            font-weight: 700;
            color: var(--curdun-violet);
            margin: 0;
        }
        .stats-card p {
            margin: 5px 0 0;
            font-size: 12px;
            color: var(--curdun-gray);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }
        .action-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            padding: 4px 8px;
            border-radius: 6px;
            transition: all 0.2s;
        }
        .action-btn.view { color: var(--curdun-info); }
        .action-btn.view:hover { background: rgba(23, 162, 184, 0.1); }
        .action-btn.edit { color: var(--curdun-warning); }
        .action-btn.edit:hover { background: rgba(255, 193, 7, 0.1); }
        .action-btn.delete { color: var(--curdun-danger); }
        .action-btn.delete:hover { background: rgba(198, 40, 40, 0.1); }
        .action-btn.toggle { color: var(--curdun-success); }
        .action-btn.toggle:hover { background: rgba(0, 166, 90, 0.1); }

        .modal-content {
            border-radius: 16px;
        }
        .modal-header {
            background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light));
            color: white;
            border-radius: 16px 16px 0 0;
        }
        .modal-header .close {
            color: white;
        }
        .modal-title i {
            margin-right: 8px;
        }

        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #ddd;
            padding: 10px 12px;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--curdun-violet);
            box-shadow: 0 0 0 0.2rem rgba(82, 0, 102, 0.25);
        }

        .warning-badge {
            background: #ff9800;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            margin-left: 8px;
        }

        @media (max-width: 768px) {
            .action-buttons { flex-direction: column; gap: 4px; }
            .page-header h1 { font-size: 20px; }
        }
    </style>
</head>
<body>
<div class="container-fluid" style="padding: 20px;">
    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h1><i class="fas fa-truck"></i> Driver Management</h1>
            <p>Manage drivers, track performance, and assign trips</p>
        </div>
        <div>
            <button class="btn-primary-custom" data-toggle="modal" data-target="#driverModal" onclick="openAddDriverModal()">
                <i class="fas fa-plus-circle"></i> Add New Driver
            </button>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="stats-card">
                <h3 id="totalDrivers">0</h3>
                <p><i class="fas fa-users"></i> Total Drivers</p>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stats-card">
                <h3 id="activeDrivers">0</h3>
                <p><i class="fas fa-check-circle"></i> Active Drivers</p>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stats-card">
                <h3 id="avgRating">0</h3>
                <p><i class="fas fa-star"></i> Average Rating</p>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stats-card">
                <h3 id="totalTrips">0</h3>
                <p><i class="fas fa-route"></i> Total Trips</p>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="fas fa-filter"></i> Filters</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <label>Status</label>
                    <select id="filterStatus" class="form-control">
                        <option value="all">All Drivers</option>
                        <option value="active">Active Only</option>
                        <option value="inactive">Inactive Only</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Search</label>
                    <input type="text" id="filterSearch" class="form-control" placeholder="Name, license, employee ID...">
                </div>
                <div class="col-md-3">
                    <label>License Expiring Within</label>
                    <select id="filterExpiring" class="form-control">
                        <option value="0">All</option>
                        <option value="30">30 Days</option>
                        <option value="60">60 Days</option>
                        <option value="90">90 Days</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>&nbsp;</label>
                    <button class="btn btn-violet btn-block" onclick="applyFilters()">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Card -->
    <div class="card">
        <div class="card-header">
            <h5><i class="fas fa-list"></i> All Drivers</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-container">
                <table class="data-table table" id="driversTable">
                    <thead>
                        <tr>
                            <th><i class="fas fa-user"></i> Driver</th>
                            <th><i class="fas fa-id-card"></i> License</th>
                            <th><i class="fas fa-phone"></i> Phone</th>
                            <th><i class="fas fa-tasks"></i> Trips</th>
                            <th><i class="fas fa-star"></i> Rating</th>
                            <th><i class="fas fa-chart-line"></i> Performance</th>
                            <th><i class="fas fa-toggle-on"></i> Status</th>
                            <th><i class="fas fa-cogs"></i> Actions</th>
                        </tr>
                    </thead>
                    <tbody id="driversTableBody">
                        <tr>
                            <td colspan="8" class="text-center">Loading drivers...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Driver Modal (Create/Edit) -->
<div class="modal fade" id="driverModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-truck"></i> <span id="modalTitle">Add New Driver</span></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="driverForm">
                    <input type="hidden" id="driver_id" name="driver_id" value="0">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-user"></i> Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="full_name" name="full_name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-phone"></i> Phone Number</label>
                                <input type="text" class="form-control" id="phone" name="phone">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-id-card"></i> License Number</label>
                                <input type="text" class="form-control" id="license_number" name="license_number">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-calendar-alt"></i> License Expiry</label>
                                <input type="date" class="form-control" id="license_expiry" name="license_expiry">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-id-badge"></i> Employee ID</label>
                                <input type="text" class="form-control" id="employee_id" name="employee_id">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-calendar"></i> Hire Date</label>
                                <input type="date" class="form-control" id="hire_date" name="hire_date" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-dollar-sign"></i> Salary Type</label>
                                <select class="form-control" id="salary_type" name="salary_type">
                                    <?php foreach ($salary_types as $key => $label): ?>
                                        <option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-money-bill"></i> Salary Amount</label>
                                <input type="number" step="0.01" class="form-control" id="salary_amount" name="salary_amount" value="0">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label><i class="fas fa-user-circle"></i> Link to User Account (Optional)</label>
                                <select class="form-control" id="user_id" name="user_id">
                                    <option value="">-- None --</option>
                                </select>
                                <small class="form-text text-muted">Link to existing user account for login access</small>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($role === 'superadmin'): ?>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label><i class="fas fa-building"></i> Company</label>
                                <select class="form-control" id="tenant_id" name="tenant_id">
                                    <option value="">-- Select Company --</option>
                                    <?php foreach ($tenants as $t): ?>
                                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-violet" onclick="saveDriver()"><i class="fas fa-save"></i> Save Driver</button>
            </div>
        </div>
    </div>
</div>

<!-- View Driver Modal -->
<div class="modal fade" id="viewDriverModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle"></i> Driver Details</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="viewDriverContent">
                <div class="text-center">Loading...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
let driversTable;
let currentFilters = { status: 'all', search: '', expiring: 0 };

// Load all drivers
function loadDrivers() {
    $.ajax({
        url: window.location.href,
        method: 'GET',
        data: { action: 'get_drivers' },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data) {
                renderDriversTable(response.data);
                loadStatistics();
            } else {
                $('#driversTableBody').html('<tr><td colspan="8" class="text-center text-danger">Failed to load drivers</td></tr>');
            }
        },
        error: function() {
            $('#driversTableBody').html('<tr><td colspan="8" class="text-center text-danger">Error loading drivers</td></tr>');
        }
    });
}

// Render drivers table
function renderDriversTable(drivers) {
    let html = '';
    
    // Apply filters
    let filteredDrivers = drivers;
    if (currentFilters.status !== 'all') {
        filteredDrivers = filteredDrivers.filter(d => 
            currentFilters.status === 'active' ? d.is_active == 1 : d.is_active == 0
        );
    }
    if (currentFilters.search) {
        const search = currentFilters.search.toLowerCase();
        filteredDrivers = filteredDrivers.filter(d => 
            d.full_name.toLowerCase().includes(search) ||
            (d.license_number && d.license_number.toLowerCase().includes(search)) ||
            (d.employee_id && d.employee_id.toLowerCase().includes(search))
        );
    }
    if (currentFilters.expiring > 0) {
        filteredDrivers = filteredDrivers.filter(d => {
            if (!d.license_expiry) return false;
            const expiryDate = new Date(d.license_expiry);
            const today = new Date();
            const daysDiff = Math.ceil((expiryDate - today) / (1000 * 60 * 60 * 24));
            return daysDiff <= currentFilters.expiring && daysDiff >= 0;
        });
    }
    
    filteredDrivers.forEach(function(driver) {
        const rating = parseFloat(driver.rating) || 0;
        const fullStars = Math.floor(rating);
        const hasHalfStar = rating % 1 >= 0.5;
        
        let starsHtml = '';
        for (let i = 1; i <= 5; i++) {
            if (i <= fullStars) {
                starsHtml += '<i class="fas fa-star"></i>';
            } else if (i === fullStars + 1 && hasHalfStar) {
                starsHtml += '<i class="fas fa-star-half-alt"></i>';
            } else {
                starsHtml += '<i class="far fa-star"></i>';
            }
        }
        
        // Check license expiry warning
        let licenseWarning = '';
        if (driver.license_expiry) {
            const expiryDate = new Date(driver.license_expiry);
            const today = new Date();
            const daysDiff = Math.ceil((expiryDate - today) / (1000 * 60 * 60 * 24));
            if (daysDiff <= 30 && daysDiff >= 0) {
                licenseWarning = `<span class="warning-badge">Expires in ${daysDiff} days</span>`;
            } else if (daysDiff < 0) {
                licenseWarning = `<span class="warning-badge" style="background:#f44336;">Expired</span>`;
            }
        }
        
        const performance = driver.completed_trips || 0;
        let performanceClass = 'text-secondary';
        if (performance >= 50) performanceClass = 'text-success';
        else if (performance >= 20) performanceClass = 'text-info';
        else if (performance >= 5) performanceClass = 'text-warning';
        
        html += `<tr>
            <td>
                <div class="d-flex align-items-center">
                    <div class="driver-avatar mr-2">${driver.full_name.charAt(0)}</div>
                    <div>
                        <strong>${escapeHtml(driver.full_name)}</strong><br>
                        <small class="text-muted">ID: ${escapeHtml(driver.employee_id || 'N/A')}</small>
                        ${licenseWarning}
                    </div>
                </div>
            </td>
            <td>
                ${escapeHtml(driver.license_number || 'N/A')}<br>
                <small class="text-muted">Exp: ${driver.license_expiry || 'N/A'}</small>
            </td>
            <td>${escapeHtml(driver.phone || 'N/A')}</td>
            <td>
                <span class="badge badge-info">${driver.completed_trips || 0} Trips</span><br>
                <small>${driver.pending_tasks || 0} Pending</small>
            </td>
            <td>
                <div class="rating-stars">${starsHtml}</div>
                <small>${rating.toFixed(1)} / 5</small>
            </td>
            <td class="${performanceClass} font-weight-bold">${performance} Trips</td>
            <td>
                <span class="status-badge ${driver.is_active == 1 ? 'status-active' : 'status-inactive'}">
                    ${driver.is_active == 1 ? 'Active' : 'Inactive'}
                </span>
            </td>
            <td class="action-buttons">
                <button class="action-btn view" onclick="viewDriver(${driver.id})" title="View Details"><i class="fas fa-eye"></i></button>
                <button class="action-btn edit" onclick="openEditDriverModal(${driver.id})" title="Edit Driver"><i class="fas fa-edit"></i></button>
                <button class="action-btn toggle" onclick="toggleDriverStatus(${driver.id}, ${driver.is_active})" title="${driver.is_active == 1 ? 'Deactivate' : 'Activate'}">
                    <i class="fas ${driver.is_active == 1 ? 'fa-ban' : 'fa-check-circle'}"></i>
                </button>
                <button class="action-btn delete" onclick="deleteDriver(${driver.id})" title="Delete Driver"><i class="fas fa-trash"></i></button>
            </td>
        </tr>`;
    });
    
    $('#driversTableBody').html(html);
    
    // Initialize DataTable if not already
    if (!driversTable) {
        driversTable = $('#driversTable').DataTable({
            pageLength: 25,
            responsive: true,
            order: [[0, 'asc']],
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                paginate: { first: "First", last: "Last", next: "Next", previous: "Previous" }
            },
            dom: 'lfrtip'
        });
    } else {
        driversTable.clear();
        driversTable.destroy();
        driversTable = $('#driversTable').DataTable({
            pageLength: 25,
            responsive: true,
            order: [[0, 'asc']],
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                paginate: { first: "First", last: "Last", next: "Next", previous: "Previous" }
            }
        });
    }
}

// Load statistics
function loadStatistics() {
    $.ajax({
        url: window.location.href,
        method: 'GET',
        data: { action: 'get_statistics' },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data) {
                $('#totalDrivers').text(response.data.total || 0);
                $('#activeDrivers').text(response.data.active || 0);
                $('#avgRating').text((parseFloat(response.data.avg_rating) || 0).toFixed(1));
                $('#totalTrips').text(response.data.total_trips || 0);
            }
        }
    });
}

// Apply filters
function applyFilters() {
    currentFilters.status = $('#filterStatus').val();
    currentFilters.search = $('#filterSearch').val();
    currentFilters.expiring = parseInt($('#filterExpiring').val()) || 0;
    loadDrivers();
}

// Open add driver modal
function openAddDriverModal() {
    $('#modalTitle').text('Add New Driver');
    $('#driver_id').val('0');
    $('#driverForm')[0].reset();
    $('#hire_date').val(new Date().toISOString().split('T')[0]);
    $('#salary_type').val('fixed');
    $('#salary_amount').val('0');
    $('#license_expiry').val('');
    <?php if ($role === 'superadmin'): ?>
    $('#tenant_id').val('');
    <?php endif; ?>
    loadUserSelect();
    $('#driverModal').modal('show');
}

// Open edit driver modal
function openEditDriverModal(driverId) {
    $.ajax({
        url: window.location.href,
        method: 'GET',
        data: { action: 'get_driver', driver_id: driverId },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data) {
                $('#modalTitle').text('Edit Driver');
                $('#driver_id').val(response.data.id);
                $('#full_name').val(response.data.full_name);
                $('#phone').val(response.data.phone || '');
                $('#license_number').val(response.data.license_number || '');
                $('#license_expiry').val(response.data.license_expiry || '');
                $('#employee_id').val(response.data.employee_id || '');
                $('#hire_date').val(response.data.hire_date || new Date().toISOString().split('T')[0]);
                $('#salary_type').val(response.data.salary_type || 'fixed');
                $('#salary_amount').val(response.data.salary_amount || 0);
                if (response.data.user_id) $('#user_id').val(response.data.user_id);
                <?php if ($role === 'superadmin'): ?>
                $('#tenant_id').val(response.data.tenant_id || '');
                <?php endif; ?>
                loadUserSelect(response.data.user_id);
                $('#driverModal').modal('show');
            } else {
                Swal.fire('Error', response.message || 'Failed to load driver', 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Failed to load driver details', 'error');
        }
    });
}

// Load user select dropdown
function loadUserSelect(selectedId = null) {
    $.ajax({
        url: window.location.href,
        method: 'GET',
        data: { action: 'get_users' },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data) {
                let options = '<option value="">-- None --</option>';
                response.data.forEach(function(user) {
                    options += `<option value="${user.id}" ${selectedId == user.id ? 'selected' : ''}>${escapeHtml(user.full_name)} (${escapeHtml(user.email || user.phone || 'No contact')})</option>`;
                });
                $('#user_id').html(options);
            }
        }
    });
}

// Save driver
function saveDriver() {
    const driverId = $('#driver_id').val();
    const fullName = $('#full_name').val();
    
    if (!fullName) {
        Swal.fire('Warning', 'Driver name is required', 'warning');
        return;
    }
    
    const action = driverId == 0 ? 'create_driver' : 'update_driver';
    const data = {
        action: action,
        full_name: fullName,
        phone: $('#phone').val(),
        license_number: $('#license_number').val(),
        license_expiry: $('#license_expiry').val(),
        employee_id: $('#employee_id').val(),
        hire_date: $('#hire_date').val(),
        salary_type: $('#salary_type').val(),
        salary_amount: $('#salary_amount').val(),
        user_id: $('#user_id').val()
    };
    
    if (driverId > 0) data.driver_id = driverId;
    <?php if ($role === 'superadmin'): ?>
    data.tenant_id = $('#tenant_id').val() || '';
    <?php endif; ?>
    
    $.ajax({
        url: window.location.href,
        method: 'POST',
        data: data,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#driverModal').modal('hide');
                loadDrivers();
                Swal.fire('Success', response.message, 'success');
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Failed to save driver', 'error');
        }
    });
}

// View driver details
function viewDriver(driverId) {
    $('#viewDriverContent').html('<div class="text-center">Loading driver details...</div>');
    $('#viewDriverModal').modal('show');
    
    $.ajax({
        url: window.location.href,
        method: 'GET',
        data: { action: 'get_driver', driver_id: driverId },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data) {
                renderDriverDetails(response.data);
            } else {
                $('#viewDriverContent').html('<div class="text-center text-danger">Failed to load driver details</div>');
            }
        },
        error: function() {
            $('#viewDriverContent').html('<div class="text-center text-danger">Error loading driver details</div>');
        }
    });
}

// Render driver details
function renderDriverDetails(driver) {
    const rating = parseFloat(driver.rating) || 0;
    let starsHtml = '';
    for (let i = 1; i <= 5; i++) {
        if (i <= rating) {
            starsHtml += '<i class="fas fa-star"></i>';
        } else if (i - 0.5 <= rating) {
            starsHtml += '<i class="fas fa-star-half-alt"></i>';
        } else {
            starsHtml += '<i class="far fa-star"></i>';
        }
    }
    
    let assignmentsHtml = '<div class="text-muted">No active assignments</div>';
    if (driver.current_assignments && driver.current_assignments.length > 0) {
        assignmentsHtml = '<ul class="list-group">';
        driver.current_assignments.forEach(function(assign) {
            assignmentsHtml += `<li class="list-group-item">Trip: ${escapeHtml(assign.trip_number || 'N/A')} - Status: ${assign.status}</li>`;
        });
        assignmentsHtml += '</ul>';
    }
    
    let performanceHtml = '<div class="text-muted">No performance data available</div>';
    if (driver.performance && driver.performance.length > 0) {
        performanceHtml = '<canvas id="performanceChart" height="200"></canvas>';
    }
    
    const html = `
        <div class="row">
            <div class="col-md-4 text-center">
                <div class="driver-avatar" style="width: 80px; height: 80px; font-size: 36px; margin: 0 auto 15px;">${driver.full_name.charAt(0)}</div>
                <h4>${escapeHtml(driver.full_name)}</h4>
                <div class="rating-stars">${starsHtml}</div>
                <p>${rating.toFixed(1)} / 5</p>
                <span class="status-badge ${driver.is_active == 1 ? 'status-active' : 'status-inactive'}">
                    ${driver.is_active == 1 ? 'Active' : 'Inactive'}
                </span>
            </div>
            <div class="col-md-8">
                <div class="row">
                    <div class="col-sm-6">
                        <p><strong><i class="fas fa-id-card"></i> Employee ID:</strong><br>${escapeHtml(driver.employee_id || 'N/A')}</p>
                        <p><strong><i class="fas fa-phone"></i> Phone:</strong><br>${escapeHtml(driver.phone || 'N/A')}</p>
                        <p><strong><i class="fas fa-dollar-sign"></i> Salary:</strong><br>${escapeHtml(driver.salary_type)} - $${parseFloat(driver.salary_amount || 0).toFixed(2)}</p>
                    </div>
                    <div class="col-sm-6">
                        <p><strong><i class="fas fa-id-card"></i> License:</strong><br>${escapeHtml(driver.license_number || 'N/A')}</p>
                        <p><strong><i class="fas fa-calendar-alt"></i> License Expiry:</strong><br>${driver.license_expiry || 'N/A'}</p>
                        <p><strong><i class="fas fa-calendar"></i> Hire Date:</strong><br>${driver.hire_date || 'N/A'}</p>
                    </div>
                </div>
                <hr>
                <h6><i class="fas fa-tasks"></i> Current Assignments</h6>
                ${assignmentsHtml}
                <hr>
                <h6><i class="fas fa-chart-line"></i> Performance History</h6>
                <div id="performanceChartContainer">${performanceHtml}</div>
            </div>
        </div>
    `;
    
    $('#viewDriverContent').html(html);
    
    // Render performance chart if data exists
    if (driver.performance && driver.performance.length > 0) {
        const ctx = document.getElementById('performanceChart');
        if (ctx) {
            const periods = driver.performance.map(p => p.period_end).reverse();
            const ratings = driver.performance.map(p => p.rating).reverse();
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: periods,
                    datasets: [{
                        label: 'Performance Rating',
                        data: ratings,
                        borderColor: '#2D1859',
                        backgroundColor: 'rgba(45, 24, 89, 0.1)',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    scales: { y: { min: 0, max: 5 } }
                }
            });
        }
    }
}

// Toggle driver status
function toggleDriverStatus(driverId, currentStatus) {
    const newStatus = currentStatus == 1 ? 0 : 1;
    const actionText = newStatus == 1 ? 'activate' : 'deactivate';
    
    Swal.fire({
        title: `${actionText.charAt(0).toUpperCase() + actionText.slice(1)} Driver?`,
        text: `Are you sure you want to ${actionText} this driver?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: newStatus == 1 ? '#4caf50' : '#f44336',
        confirmButtonText: `Yes, ${actionText}`,
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: { action: 'toggle_status', driver_id: driverId, is_active: newStatus },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        loadDrivers();
                        Swal.fire('Success', response.message, 'success');
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Failed to update driver status', 'error');
                }
            });
        }
    });
}

// Delete driver
function deleteDriver(driverId) {
    Swal.fire({
        title: 'Delete Driver?',
        text: 'This action cannot be undone. The driver will be permanently removed.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#B42318',
        confirmButtonText: 'Yes, delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: { action: 'delete_driver', driver_id: driverId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        loadDrivers();
                        Swal.fire('Deleted', response.message, 'success');
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Failed to delete driver', 'error');
                }
            });
        }
    });
}

// Helper function to escape HTML
function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// Load on page ready
$(document).ready(function() {
    loadDrivers();
    
    // Filter on enter key
    $('#filterSearch').on('keypress', function(e) {
        if (e.which === 13) applyFilters();
    });
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>