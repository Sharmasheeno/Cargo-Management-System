<?php
// tenant_admin/loaders.php
// Loader Management for Cargo Management System - Tenant Admin

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is tenant_admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tenant_admin') {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION['role'];
$session_tenant_id = $_SESSION['tenant_id'] ?? 0;

// Security: If no tenant is assigned, redirect
if (!$session_tenant_id) {
    header("Location: ../dashboard.php?error=no_tenant");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Tenant Admin';

// Get tenant name
$tenant_name = '';
try {
    $stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
    $stmt->execute([$session_tenant_id]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_name = $tenant['name'] ?? 'My Company';
} catch (PDOException $e) {
    $tenant_name = 'My Company';
}

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
        case 'get_loaders':
            $sql = "SELECT l.*, 
                    u.full_name as user_name, u.email, u.phone,
                    (SELECT COUNT(*) FROM assignments WHERE assigned_to_loader_id = l.id AND status IN ('completed', 'done') AND tenant_id = ?) as completed_tasks,
                    (SELECT COUNT(*) FROM assignments WHERE assigned_to_loader_id = l.id AND status = 'pending' AND tenant_id = ?) as pending_tasks,
                    (SELECT AVG(rating) FROM staff_performance WHERE user_id = l.user_id) as avg_rating
                    FROM loaders l
                    LEFT JOIN users u ON l.user_id = u.id
                    WHERE l.tenant_id = ?
                    ORDER BY l.is_active DESC, l.full_name ASC";
            $stmt = safeQuery($pdo, $sql, [$session_tenant_id, $session_tenant_id, $session_tenant_id]);
            if ($stmt) {
                $loaders = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $response = ['success' => true, 'data' => $loaders];
            }
            break;
            
        case 'get_loader':
            $loader_id = (int)($_POST['loader_id'] ?? 0);
            if ($loader_id > 0) {
                $sql = "SELECT l.*, u.full_name as user_name, u.email, u.phone 
                        FROM loaders l
                        LEFT JOIN users u ON l.user_id = u.id
                        WHERE l.id = ? AND l.tenant_id = ?";
                $stmt = safeQuery($pdo, $sql, [$loader_id, $session_tenant_id]);
                if ($stmt && $row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    // Get current assignments
                    $assign_sql = "SELECT a.*, ws.stock_name, ws.origin,
                                  CASE 
                                      WHEN a.task_type = 'loading' THEN 'Loading Task'
                                      WHEN a.task_type = 'unloading' THEN 'Unloading Task'
                                      WHEN a.task_type = 'moving' THEN 'Moving Task'
                                      ELSE a.task_type
                                  END as task_display
                                  FROM assignments a
                                  LEFT JOIN warehouse_stock ws ON a.task_description LIKE CONCAT('%', ws.stock_name, '%')
                                  WHERE a.assigned_to_loader_id = ? AND a.status = 'in_progress' AND a.tenant_id = ?
                                  ORDER BY a.created_at DESC LIMIT 5";
                    $assign_stmt = safeQuery($pdo, $assign_sql, [$loader_id, $session_tenant_id]);
                    $row['current_assignments'] = $assign_stmt ? $assign_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                    
                    // Get performance stats
                    $perf_sql = "SELECT * FROM staff_performance WHERE user_id = ? ORDER BY period_end DESC LIMIT 6";
                    $perf_stmt = safeQuery($pdo, $perf_sql, [$row['user_id']]);
                    $row['performance'] = $perf_stmt ? $perf_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                    
                    $response = ['success' => true, 'data' => $row];
                } else {
                    $response = ['success' => false, 'message' => 'Loader not found'];
                }
            }
            break;
            
        case 'create_loader':
            $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
            $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
            $employee_id = isset($_POST['employee_id']) ? trim($_POST['employee_id']) : '';
            $hire_date = isset($_POST['hire_date']) ? $_POST['hire_date'] : date('Y-m-d');
            $salary_type = isset($_POST['salary_type']) ? $_POST['salary_type'] : 'daily';
            $salary_amount = isset($_POST['salary_amount']) ? (float)$_POST['salary_amount'] : 0;
            $user_id_assigned = isset($_POST['user_id']) && !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
            $specialization = isset($_POST['specialization']) ? trim($_POST['specialization']) : '';
            $max_load_weight = isset($_POST['max_load_weight']) ? (float)$_POST['max_load_weight'] : 0;
            $certifications = isset($_POST['certifications']) ? trim($_POST['certifications']) : '';
            
            if (empty($full_name)) {
                $response = ['success' => false, 'message' => 'Loader name is required'];
                break;
            }
            
            // Check if employee_id already exists
            if (!empty($employee_id)) {
                $check_sql = "SELECT id FROM loaders WHERE employee_id = ? AND tenant_id = ?";
                $check_stmt = safeQuery($pdo, $check_sql, [$employee_id, $session_tenant_id]);
                if ($check_stmt && $check_stmt->rowCount() > 0) {
                    $response = ['success' => false, 'message' => 'Loader with this Employee ID already exists'];
                    break;
                }
            }
            
            $sql = "INSERT INTO loaders (tenant_id, user_id, full_name, phone, employee_id, hire_date, 
                    salary_type, salary_amount, specialization, max_load_weight, certifications, is_active, created_at, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?)";
            $stmt = safeQuery($pdo, $sql, [$session_tenant_id, $user_id_assigned, $full_name, $phone, $employee_id, $hire_date, 
                    $salary_type, $salary_amount, $specialization, $max_load_weight, $certifications, $user_id]);
            
            if ($stmt) {
                $response = ['success' => true, 'message' => 'Loader created successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to create loader'];
            }
            break;
            
        case 'update_loader':
            $loader_id = (int)($_POST['loader_id'] ?? 0);
            $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
            $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
            $employee_id = isset($_POST['employee_id']) ? trim($_POST['employee_id']) : '';
            $salary_type = isset($_POST['salary_type']) ? $_POST['salary_type'] : 'daily';
            $salary_amount = isset($_POST['salary_amount']) ? (float)$_POST['salary_amount'] : 0;
            $user_id_assigned = isset($_POST['user_id']) && !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
            $specialization = isset($_POST['specialization']) ? trim($_POST['specialization']) : '';
            $max_load_weight = isset($_POST['max_load_weight']) ? (float)$_POST['max_load_weight'] : 0;
            $certifications = isset($_POST['certifications']) ? trim($_POST['certifications']) : '';
            
            if ($loader_id <= 0 || empty($full_name)) {
                $response = ['success' => false, 'message' => 'Invalid loader data'];
                break;
            }
            
            $sql = "UPDATE loaders SET full_name = ?, phone = ?, employee_id = ?, 
                    salary_type = ?, salary_amount = ?, user_id = ?, specialization = ?, 
                    max_load_weight = ?, certifications = ? 
                    WHERE id = ? AND tenant_id = ?";
            $stmt = safeQuery($pdo, $sql, [$full_name, $phone, $employee_id, $salary_type, $salary_amount, 
                      $user_id_assigned, $specialization, $max_load_weight, $certifications, $loader_id, $session_tenant_id]);
            
            if ($stmt) {
                $response = ['success' => true, 'message' => 'Loader updated successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to update loader'];
            }
            break;
            
        case 'delete_loader':
            $loader_id = (int)($_POST['loader_id'] ?? 0);
            
            if ($loader_id <= 0) {
                $response = ['success' => false, 'message' => 'Invalid loader ID'];
                break;
            }
            
            // Check if loader has active assignments
            $check_sql = "SELECT COUNT(*) as count FROM assignments WHERE assigned_to_loader_id = ? AND status IN ('pending', 'in_progress') AND tenant_id = ?";
            $check_stmt = safeQuery($pdo, $check_sql, [$loader_id, $session_tenant_id]);
            if ($check_stmt && $row = $check_stmt->fetch()) {
                if ($row['count'] > 0) {
                    $response = ['success' => false, 'message' => 'Cannot delete loader with active assignments'];
                    break;
                }
            }
            
            $sql = "DELETE FROM loaders WHERE id = ? AND tenant_id = ?";
            $stmt = safeQuery($pdo, $sql, [$loader_id, $session_tenant_id]);
            
            if ($stmt) {
                $response = ['success' => true, 'message' => 'Loader deleted successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to delete loader'];
            }
            break;
            
        case 'toggle_status':
            $loader_id = (int)($_POST['loader_id'] ?? 0);
            $is_active = (int)($_POST['is_active'] ?? 0);
            
            if ($loader_id <= 0) {
                $response = ['success' => false, 'message' => 'Invalid loader ID'];
                break;
            }
            
            $sql = "UPDATE loaders SET is_active = ? WHERE id = ? AND tenant_id = ?";
            $stmt = safeQuery($pdo, $sql, [$is_active, $loader_id, $session_tenant_id]);
            
            if ($stmt) {
                $status_text = $is_active ? 'activated' : 'deactivated';
                $response = ['success' => true, 'message' => "Loader $status_text successfully"];
            } else {
                $response = ['success' => false, 'message' => 'Failed to update loader status'];
            }
            break;
            
        case 'get_assignments':
            $loader_id = (int)($_GET['loader_id'] ?? 0);
            
            if ($loader_id <= 0) {
                $response = ['success' => false, 'message' => 'Invalid loader ID'];
                break;
            }
            
            $sql = "SELECT a.*, 
                    CASE 
                        WHEN a.task_type = 'loading' THEN 'Loading Task'
                        WHEN a.task_type = 'unloading' THEN 'Unloading Task'
                        WHEN a.task_type = 'moving' THEN 'Moving Task'
                        ELSE a.task_type
                    END as task_display,
                    ws.stock_name,
                    ws.origin
                    FROM assignments a
                    LEFT JOIN warehouse_stock ws ON a.task_description LIKE CONCAT('%', ws.stock_name, '%')
                    WHERE a.assigned_to_loader_id = ? AND a.tenant_id = ?
                    ORDER BY a.created_at DESC LIMIT 20";
            $stmt = safeQuery($pdo, $sql, [$loader_id, $session_tenant_id]);
            $assignments = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $response = ['success' => true, 'data' => $assignments];
            break;
            
        case 'get_performance':
            $loader_id = (int)($_GET['loader_id'] ?? 0);
            
            if ($loader_id <= 0) {
                $response = ['success' => false, 'message' => 'Invalid loader ID'];
                break;
            }
            
            // Get loader user_id
            $loader_sql = "SELECT user_id FROM loaders WHERE id = ? AND tenant_id = ?";
            $loader_stmt = safeQuery($pdo, $loader_sql, [$loader_id, $session_tenant_id]);
            $loader = $loader_stmt ? $loader_stmt->fetch(PDO::FETCH_ASSOC) : null;
            
            if ($loader && $loader['user_id']) {
                $sql = "SELECT * FROM staff_performance WHERE user_id = ? ORDER BY period_end DESC LIMIT 12";
                $stmt = safeQuery($pdo, $sql, [$loader['user_id']]);
                $performance = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                $response = ['success' => true, 'data' => $performance];
            } else {
                $response = ['success' => true, 'data' => []];
            }
            break;
            
        case 'get_users':
            $sql = "SELECT id, full_name, email, phone FROM users WHERE role IN ('loader', 'staff') AND tenant_id = ? ORDER BY full_name";
            $stmt = safeQuery($pdo, $sql, [$session_tenant_id]);
            $users = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $response = ['success' => true, 'data' => $users];
            break;
            
        case 'get_available_loaders':
            $date = $_GET['date'] ?? date('Y-m-d');
            $shift = $_GET['shift'] ?? 'any';
            
            $sql = "SELECT l.* 
                    FROM loaders l
                    WHERE l.is_active = 1 AND l.tenant_id = ?
                    AND l.id NOT IN (
                        SELECT DISTINCT assigned_to_loader_id FROM assignments 
                        WHERE DATE(created_at) = ? AND status IN ('pending', 'in_progress') AND tenant_id = ?
                    )
                    ORDER BY l.rating DESC, l.full_name";
            $stmt = safeQuery($pdo, $sql, [$session_tenant_id, $date, $session_tenant_id]);
            $loaders = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $response = ['success' => true, 'data' => $loaders];
            break;
            
        case 'get_statistics':
            $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
                        AVG(rating) as avg_rating,
                        SUM(total_tasks) as total_tasks,
                        COUNT(DISTINCT specialization) as specializations
                    FROM loaders
                    WHERE tenant_id = ?";
            $stmt = safeQuery($pdo, $sql, [$session_tenant_id]);
            $stats = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [];
            $response = ['success' => true, 'data' => $stats];
            break;
            
        case 'assign_task':
            $loader_id = (int)($_POST['loader_id'] ?? 0);
            $task_type = isset($_POST['task_type']) ? $_POST['task_type'] : 'loading';
            $task_description = isset($_POST['task_description']) ? trim($_POST['task_description']) : '';
            $priority = (int)($_POST['priority'] ?? 1);
            $due_date = isset($_POST['due_date']) ? $_POST['due_date'] : date('Y-m-d', strtotime('+1 day'));
            
            if ($loader_id <= 0 || empty($task_description)) {
                $response = ['success' => false, 'message' => 'Loader and task description are required'];
                break;
            }
            
            $sql = "INSERT INTO assignments (tenant_id, assigned_to_loader_id, assigned_by, task_type, task_description, 
                    priority, due_date, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
            $stmt = safeQuery($pdo, $sql, [$session_tenant_id, $loader_id, $user_id, $task_type, $task_description, $priority, $due_date]);
            
            if ($stmt) {
                $response = ['success' => true, 'message' => 'Task assigned successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to assign task'];
            }
            break;
    }
    
    echo json_encode($response);
    exit;
}

// Salary types
$salary_types = [
    'daily' => 'Daily Rate',
    'hourly' => 'Hourly Rate',
    'per_task' => 'Per Task',
    'monthly' => 'Monthly Salary',
    'contract' => 'Contract Basis'
];

// Task types
$task_types = [
    'loading' => 'Loading',
    'unloading' => 'Unloading',
    'moving' => 'Moving Stock',
    'packing' => 'Packing',
    'sorting' => 'Sorting',
    'inspection' => 'Inspection'
];

// Specializations
$specializations = [
    'general' => 'General Loader',
    'heavy_lifting' => 'Heavy Lifting',
    'forklift' => 'Forklift Operator',
    'pallet_jack' => 'Pallet Jack Operator',
    'cargo_sorting' => 'Cargo Sorting',
    'container_loading' => 'Container Loading',
    'dangerous_goods' => 'Dangerous Goods Handler'
];

include_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loader Management - <?= htmlspecialchars($tenant_name) ?> | Cargo Management System</title>
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .page-header h1 { color: white; font-size: 24px; margin: 0; }
        .page-header h1 i { margin-right: 10px; }
        .page-header p { color: rgba(255,255,255,0.9); margin: 5px 0 0; }
        .page-header .company-badge {
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
        }

        .btn-primary-custom {
            background: var(--curdun-yellow);
            color: var(--curdun-violet);
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .btn-primary-custom:hover {
            background: var(--curdun-yellow-dark);
            transform: translateY(-2px);
        }

        .btn-violet {
            background: var(--curdun-violet);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
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

        .loader-avatar {
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
        .action-btn.assign { color: var(--curdun-info); }
        .action-btn.assign:hover { background: rgba(23, 162, 184, 0.1); }

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

        .specialization-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            background: #e0e0e0;
            color: #333;
        }

        @media (max-width: 768px) {
            .action-buttons { flex-direction: column; gap: 4px; }
            .page-header { flex-direction: column; text-align: center; }
            .page-header h1 { font-size: 20px; }
        }
    </style>
</head>
<body>
<div class="container-fluid" style="padding: 20px;">
    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h1><i class="fas fa-people-carry"></i> Loader Management</h1>
            <p>Manage loaders, track performance, and assign tasks</p>
        </div>
        <div class="d-flex gap-3 align-items-center">
            <span class="company-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($tenant_name) ?></span>
            <button class="btn-primary-custom" data-toggle="modal" data-target="#loaderModal" onclick="openAddLoaderModal()">
                <i class="fas fa-plus-circle"></i> Add New Loader
            </button>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="stats-card">
                <h3 id="totalLoaders">0</h3>
                <p><i class="fas fa-users"></i> Total Loaders</p>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stats-card">
                <h3 id="activeLoaders">0</h3>
                <p><i class="fas fa-check-circle"></i> Active Loaders</p>
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
                <h3 id="totalTasks">0</h3>
                <p><i class="fas fa-tasks"></i> Total Tasks</p>
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
                        <option value="all">All Loaders</option>
                        <option value="active">Active Only</option>
                        <option value="inactive">Inactive Only</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Specialization</label>
                    <select id="filterSpecialization" class="form-control">
                        <option value="all">All Specializations</option>
                        <?php foreach ($specializations as $key => $label): ?>
                            <option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Search</label>
                    <input type="text" id="filterSearch" class="form-control" placeholder="Name, employee ID...">
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
            <h5><i class="fas fa-list"></i> All Loaders</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-container">
                <table class="data-table table" id="loadersTable">
                    <thead>
                        <tr>
                            <th><i class="fas fa-user"></i> Loader</th>
                            <th><i class="fas fa-id-badge"></i> Employee ID</th>
                            <th><i class="fas fa-phone"></i> Phone</th>
                            <th><i class="fas fa-tools"></i> Specialization</th>
                            <th><i class="fas fa-star"></i> Rating</th>
                            <th><i class="fas fa-tasks"></i> Tasks</th>
                            <th><i class="fas fa-toggle-on"></i> Status</th>
                            <th><i class="fas fa-cogs"></i> Actions</th>
                        </tr>
                    </thead>
                    <tbody id="loadersTableBody">
                        <tr>
                            <td colspan="8" class="text-center">Loading loaders...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Loader Modal (Create/Edit) -->
<div class="modal fade" id="loaderModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-people-carry"></i> <span id="modalTitle">Add New Loader</span></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="loaderForm">
                    <input type="hidden" id="loader_id" name="loader_id" value="0">
                    
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
                                <label><i class="fas fa-tools"></i> Specialization</label>
                                <select class="form-control" id="specialization" name="specialization">
                                    <?php foreach ($specializations as $key => $label): ?>
                                        <option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-weight-hanging"></i> Max Load Weight (kg)</label>
                                <input type="number" class="form-control" id="max_load_weight" name="max_load_weight" value="50">
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
                    
                    <div class="form-group">
                        <label><i class="fas fa-certificate"></i> Certifications</label>
                        <textarea class="form-control" id="certifications" name="certifications" rows="2" placeholder="Forklift certified, Dangerous goods trained, etc."></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label><i class="fas fa-user-circle"></i> Link to User Account (Optional)</label>
                                <select class="form-control" id="user_id" name="user_id">
                                    <option value="">-- None --</option>
                                </select>
                                <small class="form-text text-muted">Link to existing user account for system access</small>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-violet" onclick="saveLoader()"><i class="fas fa-save"></i> Save Loader</button>
            </div>
        </div>
    </div>
</div>

<!-- View Loader Modal -->
<div class="modal fade" id="viewLoaderModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle"></i> Loader Details</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="viewLoaderContent">
                <div class="text-center">Loading...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Assign Task Modal -->
<div class="modal fade" id="assignTaskModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-tasks"></i> Assign Task to Loader</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="assign_loader_id" value="0">
                <div class="form-group">
                    <label><i class="fas fa-tag"></i> Task Type</label>
                    <select class="form-control" id="task_type">
                        <?php foreach ($task_types as $key => $label): ?>
                            <option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-align-left"></i> Task Description</label>
                    <textarea class="form-control" id="task_description" rows="3" required placeholder="Describe the task..."></textarea>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label><i class="fas fa-flag"></i> Priority</label>
                            <select class="form-control" id="priority">
                                <option value="1">Low</option>
                                <option value="2">Medium</option>
                                <option value="3" selected>High</option>
                                <option value="4">Urgent</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Due Date</label>
                            <input type="date" class="form-control" id="due_date" value="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-violet" onclick="assignTask()"><i class="fas fa-check-circle"></i> Assign Task</button>
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
let loadersTable;
let currentFilters = { status: 'all', specialization: 'all', search: '' };

// Load all loaders
function loadLoaders() {
    $.ajax({
        url: window.location.href,
        method: 'GET',
        data: { action: 'get_loaders' },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data) {
                renderLoadersTable(response.data);
                loadStatistics();
            } else {
                $('#loadersTableBody').html('<tr><td colspan="8" class="text-center text-danger">Failed to load loaders</td></tr>');
            }
        },
        error: function() {
            $('#loadersTableBody').html('<tr><td colspan="8" class="text-center text-danger">Error loading loaders</td></tr>');
        }
    });
}

// Render loaders table
function renderLoadersTable(loaders) {
    let html = '';
    
    // Apply filters
    let filteredLoaders = loaders;
    if (currentFilters.status !== 'all') {
        filteredLoaders = filteredLoaders.filter(l => 
            currentFilters.status === 'active' ? l.is_active == 1 : l.is_active == 0
        );
    }
    if (currentFilters.specialization !== 'all') {
        filteredLoaders = filteredLoaders.filter(l => l.specialization === currentFilters.specialization);
    }
    if (currentFilters.search) {
        const search = currentFilters.search.toLowerCase();
        filteredLoaders = filteredLoaders.filter(l => 
            l.full_name.toLowerCase().includes(search) ||
            (l.employee_id && l.employee_id.toLowerCase().includes(search)) ||
            (l.phone && l.phone.toLowerCase().includes(search))
        );
    }
    
    filteredLoaders.forEach(function(loader) {
        const rating = parseFloat(loader.avg_rating) || parseFloat(loader.rating) || 0;
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
        
        const specializationLabel = {
            'general': 'General Loader',
            'heavy_lifting': 'Heavy Lifting',
            'forklift': 'Forklift Operator',
            'pallet_jack': 'Pallet Jack Operator',
            'cargo_sorting': 'Cargo Sorting',
            'container_loading': 'Container Loading',
            'dangerous_goods': 'Dangerous Goods Handler'
        }[loader.specialization] || loader.specialization;
        
        const completedTasks = loader.completed_tasks || 0;
        let performanceClass = 'text-secondary';
        if (completedTasks >= 100) performanceClass = 'text-success';
        else if (completedTasks >= 50) performanceClass = 'text-info';
        else if (completedTasks >= 20) performanceClass = 'text-warning';
        
        html += `<tr>
            <td>
                <div class="d-flex align-items-center">
                    <div class="loader-avatar mr-2">${loader.full_name.charAt(0)}</div>
                    <div>
                        <strong>${escapeHtml(loader.full_name)}</strong><br>
                        <small class="text-muted">${escapeHtml(loader.employee_id || 'No ID')}</small>
                    </div>
                </div>
            </td>
            <td>${escapeHtml(loader.employee_id || 'N/A')}</td>
            <td>${escapeHtml(loader.phone || 'N/A')}</td>
            <td><span class="specialization-badge">${escapeHtml(specializationLabel)}</span></td>
            <td>
                <div class="rating-stars">${starsHtml}</div>
                <small>${rating.toFixed(1)} / 5</small>
            </td>
            <td class="${performanceClass} font-weight-bold">${completedTasks} Tasks</td>
            <td>
                <span class="status-badge ${loader.is_active == 1 ? 'status-active' : 'status-inactive'}">
                    ${loader.is_active == 1 ? 'Active' : 'Inactive'}
                </span>
            </td>
            <td class="action-buttons">
                <button class="action-btn view" onclick="viewLoader(${loader.id})" title="View Details"><i class="fas fa-eye"></i></button>
                <button class="action-btn edit" onclick="openEditLoaderModal(${loader.id})" title="Edit Loader"><i class="fas fa-edit"></i></button>
                <button class="action-btn assign" onclick="openAssignTaskModal(${loader.id})" title="Assign Task"><i class="fas fa-tasks"></i></button>
                <button class="action-btn toggle" onclick="toggleLoaderStatus(${loader.id}, ${loader.is_active})" title="${loader.is_active == 1 ? 'Deactivate' : 'Activate'}">
                    <i class="fas ${loader.is_active == 1 ? 'fa-ban' : 'fa-check-circle'}"></i>
                </button>
                <button class="action-btn delete" onclick="deleteLoader(${loader.id})" title="Delete Loader"><i class="fas fa-trash"></i></button>
            </td>
        </tr>`;
    });
    
    $('#loadersTableBody').html(html);
    
    // Initialize DataTable if not already
    if (!loadersTable) {
        loadersTable = $('#loadersTable').DataTable({
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
        loadersTable.clear();
        loadersTable.destroy();
        loadersTable = $('#loadersTable').DataTable({
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
                $('#totalLoaders').text(response.data.total || 0);
                $('#activeLoaders').text(response.data.active || 0);
                $('#avgRating').text((parseFloat(response.data.avg_rating) || 0).toFixed(1));
                $('#totalTasks').text(response.data.total_tasks || 0);
            }
        }
    });
}

// Apply filters
function applyFilters() {
    currentFilters.status = $('#filterStatus').val();
    currentFilters.specialization = $('#filterSpecialization').val();
    currentFilters.search = $('#filterSearch').val();
    loadLoaders();
}

// Open add loader modal
function openAddLoaderModal() {
    $('#modalTitle').text('Add New Loader');
    $('#loader_id').val('0');
    $('#loaderForm')[0].reset();
    $('#hire_date').val(new Date().toISOString().split('T')[0]);
    $('#salary_type').val('daily');
    $('#salary_amount').val('0');
    $('#max_load_weight').val('50');
    $('#specialization').val('general');
    loadUserSelect();
    $('#loaderModal').modal('show');
}

// Open edit loader modal
function openEditLoaderModal(loaderId) {
    $.ajax({
        url: window.location.href,
        method: 'GET',
        data: { action: 'get_loader', loader_id: loaderId },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data) {
                $('#modalTitle').text('Edit Loader');
                $('#loader_id').val(response.data.id);
                $('#full_name').val(response.data.full_name);
                $('#phone').val(response.data.phone || '');
                $('#employee_id').val(response.data.employee_id || '');
                $('#hire_date').val(response.data.hire_date || new Date().toISOString().split('T')[0]);
                $('#salary_type').val(response.data.salary_type || 'daily');
                $('#salary_amount').val(response.data.salary_amount || 0);
                $('#specialization').val(response.data.specialization || 'general');
                $('#max_load_weight').val(response.data.max_load_weight || 50);
                $('#certifications').val(response.data.certifications || '');
                if (response.data.user_id) $('#user_id').val(response.data.user_id);
                loadUserSelect(response.data.user_id);
                $('#loaderModal').modal('show');
            } else {
                Swal.fire('Error', response.message || 'Failed to load loader', 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Failed to load loader details', 'error');
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

// Save loader
function saveLoader() {
    const loaderId = $('#loader_id').val();
    const fullName = $('#full_name').val();
    
    if (!fullName) {
        Swal.fire('Warning', 'Loader name is required', 'warning');
        return;
    }
    
    const action = loaderId == 0 ? 'create_loader' : 'update_loader';
    const data = {
        action: action,
        full_name: fullName,
        phone: $('#phone').val(),
        employee_id: $('#employee_id').val(),
        hire_date: $('#hire_date').val(),
        salary_type: $('#salary_type').val(),
        salary_amount: $('#salary_amount').val(),
        specialization: $('#specialization').val(),
        max_load_weight: $('#max_load_weight').val(),
        certifications: $('#certifications').val(),
        user_id: $('#user_id').val()
    };
    
    if (loaderId > 0) data.loader_id = loaderId;
    
    $.ajax({
        url: window.location.href,
        method: 'POST',
        data: data,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#loaderModal').modal('hide');
                loadLoaders();
                Swal.fire('Success', response.message, 'success');
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Failed to save loader', 'error');
        }
    });
}

// View loader details
function viewLoader(loaderId) {
    $('#viewLoaderContent').html('<div class="text-center">Loading loader details...</div>');
    $('#viewLoaderModal').modal('show');
    
    $.ajax({
        url: window.location.href,
        method: 'GET',
        data: { action: 'get_loader', loader_id: loaderId },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data) {
                renderLoaderDetails(response.data);
            } else {
                $('#viewLoaderContent').html('<div class="text-center text-danger">Failed to load loader details</div>');
            }
        },
        error: function() {
            $('#viewLoaderContent').html('<div class="text-center text-danger">Error loading loader details</div>');
        }
    });
}

// Render loader details
function renderLoaderDetails(loader) {
    const rating = parseFloat(loader.avg_rating) || parseFloat(loader.rating) || 0;
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
    
    const specializationLabel = {
        'general': 'General Loader',
        'heavy_lifting': 'Heavy Lifting',
        'forklift': 'Forklift Operator',
        'pallet_jack': 'Pallet Jack Operator',
        'cargo_sorting': 'Cargo Sorting',
        'container_loading': 'Container Loading',
        'dangerous_goods': 'Dangerous Goods Handler'
    }[loader.specialization] || loader.specialization;
    
    let assignmentsHtml = '<div class="text-muted">No active assignments</div>';
    if (loader.current_assignments && loader.current_assignments.length > 0) {
        assignmentsHtml = '<ul class="list-group">';
        loader.current_assignments.forEach(function(assign) {
            assignmentsHtml += `<li class="list-group-item">${escapeHtml(assign.task_display || assign.task_type)} - ${escapeHtml(assign.task_description)}<br><small class="text-muted">Priority: ${assign.priority}</small></li>`;
        });
        assignmentsHtml += '</ul>';
    }
    
    let performanceHtml = '<div class="text-muted">No performance data available</div>';
    if (loader.performance && loader.performance.length > 0) {
        performanceHtml = '<canvas id="performanceChart" height="200"></canvas>';
    }
    
    const html = `
        <div class="row">
            <div class="col-md-4 text-center">
                <div class="loader-avatar" style="width: 80px; height: 80px; font-size: 36px; margin: 0 auto 15px;">${loader.full_name.charAt(0)}</div>
                <h4>${escapeHtml(loader.full_name)}</h4>
                <div class="rating-stars">${starsHtml}</div>
                <p>${rating.toFixed(1)} / 5</p>
                <span class="status-badge ${loader.is_active == 1 ? 'status-active' : 'status-inactive'}">
                    ${loader.is_active == 1 ? 'Active' : 'Inactive'}
                </span>
            </div>
            <div class="col-md-8">
                <div class="row">
                    <div class="col-sm-6">
                        <p><strong><i class="fas fa-id-badge"></i> Employee ID:</strong><br>${escapeHtml(loader.employee_id || 'N/A')}</p>
                        <p><strong><i class="fas fa-phone"></i> Phone:</strong><br>${escapeHtml(loader.phone || 'N/A')}</p>
                        <p><strong><i class="fas fa-tools"></i> Specialization:</strong><br>${escapeHtml(specializationLabel)}</p>
                        <p><strong><i class="fas fa-weight-hanging"></i> Max Load Weight:</strong><br>${loader.max_load_weight || 50} kg</p>
                    </div>
                    <div class="col-sm-6">
                        <p><strong><i class="fas fa-dollar-sign"></i> Salary:</strong><br>${escapeHtml(loader.salary_type)} - $${parseFloat(loader.salary_amount || 0).toFixed(2)}</p>
                        <p><strong><i class="fas fa-calendar"></i> Hire Date:</strong><br>${loader.hire_date || 'N/A'}</p>
                        <p><strong><i class="fas fa-tasks"></i> Total Tasks:</strong><br>${loader.total_tasks || 0}</p>
                    </div>
                </div>
                <hr>
                <h6><i class="fas fa-certificate"></i> Certifications</h6>
                <p>${escapeHtml(loader.certifications) || 'None'}</p>
                <hr>
                <h6><i class="fas fa-tasks"></i> Current Assignments</h6>
                ${assignmentsHtml}
                <hr>
                <h6><i class="fas fa-chart-line"></i> Performance History</h6>
                <div id="performanceChartContainer">${performanceHtml}</div>
            </div>
        </div>
    `;
    
    $('#viewLoaderContent').html(html);
    
    // Render performance chart if data exists
    if (loader.performance && loader.performance.length > 0) {
        const ctx = document.getElementById('performanceChart');
        if (ctx) {
            const periods = loader.performance.map(p => p.period_end).reverse();
            const ratings = loader.performance.map(p => p.rating).reverse();
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

// Toggle loader status
function toggleLoaderStatus(loaderId, currentStatus) {
    const newStatus = currentStatus == 1 ? 0 : 1;
    const actionText = newStatus == 1 ? 'activate' : 'deactivate';
    
    Swal.fire({
        title: `${actionText.charAt(0).toUpperCase() + actionText.slice(1)} Loader?`,
        text: `Are you sure you want to ${actionText} this loader?`,
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
                data: { action: 'toggle_status', loader_id: loaderId, is_active: newStatus },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        loadLoaders();
                        Swal.fire('Success', response.message, 'success');
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Failed to update loader status', 'error');
                }
            });
        }
    });
}

// Open assign task modal
function openAssignTaskModal(loaderId) {
    $('#assign_loader_id').val(loaderId);
    $('#task_type').val('loading');
    $('#task_description').val('');
    $('#priority').val('3');
    $('#due_date').val(new Date(Date.now() + 86400000).toISOString().split('T')[0]);
    $('#assignTaskModal').modal('show');
}

// Assign task to loader
function assignTask() {
    const loaderId = $('#assign_loader_id').val();
    const taskType = $('#task_type').val();
    const taskDescription = $('#task_description').val();
    const priority = $('#priority').val();
    const dueDate = $('#due_date').val();
    
    if (!taskDescription) {
        Swal.fire('Warning', 'Task description is required', 'warning');
        return;
    }
    
    $.ajax({
        url: window.location.href,
        method: 'POST',
        data: {
            action: 'assign_task',
            loader_id: loaderId,
            task_type: taskType,
            task_description: taskDescription,
            priority: priority,
            due_date: dueDate
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#assignTaskModal').modal('hide');
                Swal.fire('Success', response.message, 'success');
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Failed to assign task', 'error');
        }
    });
}

// Delete loader
function deleteLoader(loaderId) {
    Swal.fire({
        title: 'Delete Loader?',
        text: 'This action cannot be undone. The loader will be permanently removed.',
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
                data: { action: 'delete_loader', loader_id: loaderId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        loadLoaders();
                        Swal.fire('Deleted', response.message, 'success');
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Failed to delete loader', 'error');
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
    loadLoaders();
    
    // Filter on enter key
    $('#filterSearch').on('keypress', function(e) {
        if (e.which === 13) applyFilters();
    });
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>