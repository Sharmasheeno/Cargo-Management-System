<?php
// roles.php
//faras cargo - Complete Role Management System
// Professional role-based access control with full CRUD operations

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

// Only superadmin and company_admin can access this page
if (!in_array($role, ['superadmin', 'company_admin'])) {
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
        case 'get_roles':
            $sql = "SELECT r.*, 
                    (SELECT COUNT(*) FROM staff_assignments WHERE role_id = r.id AND status = 'active') as assigned_users 
                    FROM roles r";
            
            if ($role === 'company_admin') {
                $sql .= " WHERE r.tenant_id = ? OR r.tenant_id IS NULL";
                $params = [$session_tenant_id];
            } else {
                $params = [];
            }
            
            $sql .= " ORDER BY r.level DESC, r.display_name";
            
            $stmt = safeQuery($pdo, $sql, $params);
            if ($stmt) {
                $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $response = ['success' => true, 'data' => $roles];
            }
            break;
            
        case 'get_role':
            $role_id = (int)($_POST['role_id'] ?? 0);
            if ($role_id > 0) {
                $sql = "SELECT * FROM roles WHERE id = ?";
                $stmt = safeQuery($pdo, $sql, [$role_id]);
                if ($stmt && $row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $response = ['success' => true, 'data' => $row];
                } else {
                    $response = ['success' => false, 'message' => 'Role not found'];
                }
            }
            break;
            
        case 'create_role':
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            $display_name = isset($_POST['display_name']) ? trim($_POST['display_name']) : '';
            $description = isset($_POST['description']) ? trim($_POST['description']) : '';
            $level = (int)($_POST['level'] ?? 1);
            $tenant_id = ($role === 'superadmin') ? ((int)($_POST['tenant_id'] ?? 0) ?: null) : $session_tenant_id;
            
            if (empty($name) || empty($display_name)) {
                $response = ['success' => false, 'message' => 'Role name and display name are required'];
                break;
            }
            
            // Check if role already exists
            $check_sql = "SELECT id FROM roles WHERE name = ? AND (tenant_id = ? OR (tenant_id IS NULL AND ? IS NULL))";
            $check_stmt = safeQuery($pdo, $check_sql, [$name, $tenant_id, $tenant_id]);
            if ($check_stmt && $check_stmt->rowCount() > 0) {
                $response = ['success' => false, 'message' => 'Role with this name already exists'];
                break;
            }
            
            $sql = "INSERT INTO roles (tenant_id, name, display_name, description, level, is_system, created_at) 
                    VALUES (?, ?, ?, ?, ?, 0, NOW())";
            $stmt = safeQuery($pdo, $sql, [$tenant_id, $name, $display_name, $description, $level]);
            
            if ($stmt) {
                $response = ['success' => true, 'message' => 'Role created successfully', 'role_id' => $pdo->lastInsertId()];
            } else {
                $response = ['success' => false, 'message' => 'Failed to create role'];
            }
            break;
            
        case 'update_role':
            $role_id = (int)($_POST['role_id'] ?? 0);
            $display_name = isset($_POST['display_name']) ? trim($_POST['display_name']) : '';
            $description = isset($_POST['description']) ? trim($_POST['description']) : '';
            $level = (int)($_POST['level'] ?? 1);
            
            if ($role_id <= 0) {
                $response = ['success' => false, 'message' => 'Invalid role ID'];
                break;
            }
            
            // Check if system role
            $check_sql = "SELECT is_system FROM roles WHERE id = ?";
            $check_stmt = safeQuery($pdo, $check_sql, [$role_id]);
            if ($check_stmt && $row = $check_stmt->fetch()) {
                if ($row['is_system'] == 1) {
                    $response = ['success' => false, 'message' => 'System roles cannot be modified'];
                    break;
                }
            }
            
            $sql = "UPDATE roles SET display_name = ?, description = ?, level = ? WHERE id = ?";
            $stmt = safeQuery($pdo, $sql, [$display_name, $description, $level, $role_id]);
            
            if ($stmt) {
                $response = ['success' => true, 'message' => 'Role updated successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to update role'];
            }
            break;
            
        case 'delete_role':
            $role_id = (int)($_POST['role_id'] ?? 0);
            
            if ($role_id <= 0) {
                $response = ['success' => false, 'message' => 'Invalid role ID'];
                break;
            }
            
            // Check if system role
            $check_sql = "SELECT is_system FROM roles WHERE id = ?";
            $check_stmt = safeQuery($pdo, $check_sql, [$role_id]);
            if ($check_stmt && $row = $check_stmt->fetch()) {
                if ($row['is_system'] == 1) {
                    $response = ['success' => false, 'message' => 'System roles cannot be deleted'];
                    break;
                }
            }
            
            // Check if role is assigned to any users
            $assign_sql = "SELECT COUNT(*) as count FROM staff_assignments WHERE role_id = ? AND status = 'active'";
            $assign_stmt = safeQuery($pdo, $assign_sql, [$role_id]);
            if ($assign_stmt && $row = $assign_stmt->fetch()) {
                if ($row['count'] > 0) {
                    $response = ['success' => false, 'message' => 'Cannot delete role that is assigned to users'];
                    break;
                }
            }
            
            $sql = "DELETE FROM roles WHERE id = ?";
            $stmt = safeQuery($pdo, $sql, [$role_id]);
            
            if ($stmt) {
                $response = ['success' => true, 'message' => 'Role deleted successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to delete role'];
            }
            break;
            
        case 'get_permissions':
            $role_id = (int)($_GET['role_id'] ?? 0);
            if ($role_id > 0) {
                // Get all possible modules/permissions
                $modules = [
                    ['module' => 'dashboard', 'actions' => ['view'], 'label' => 'Dashboard'],
                    ['module' => 'customers', 'actions' => ['view', 'create', 'edit', 'delete'], 'label' => 'Customers'],
                    ['module' => 'containers', 'actions' => ['view', 'create', 'edit', 'delete'], 'label' => 'Containers'],
                    ['module' => 'trips', 'actions' => ['view', 'create', 'edit', 'delete'], 'label' => 'Trips'],
                    ['module' => 'warehouse', 'actions' => ['view', 'create', 'edit', 'delete'], 'label' => 'Warehouse'],
                    ['module' => 'invoices', 'actions' => ['view', 'create', 'edit', 'delete', 'pay'], 'label' => 'Invoices'],
                    ['module' => 'payments', 'actions' => ['view', 'create', 'delete'], 'label' => 'Payments'],
                    ['module' => 'reports', 'actions' => ['view', 'export'], 'label' => 'Reports'],
                    ['module' => 'users', 'actions' => ['view', 'create', 'edit', 'delete'], 'label' => 'Users'],
                    ['module' => 'roles', 'actions' => ['view', 'create', 'edit', 'delete'], 'label' => 'Roles'],
                    ['module' => 'branches', 'actions' => ['view', 'create', 'edit', 'delete'], 'label' => 'Branches'],
                    ['module' => 'settings', 'actions' => ['view', 'edit'], 'label' => 'Settings'],
                    ['module' => 'sms', 'actions' => ['view', 'send', 'templates'], 'label' => 'SMS'],
                    ['module' => 'tax', 'actions' => ['view', 'create', 'edit', 'delete'], 'label' => 'Tax'],
                ];
                
                // Get current permissions for role
                $perm_sql = "SELECT module, action FROM role_permissions WHERE role_id = ?";
                $perm_stmt = safeQuery($pdo, $perm_sql, [$role_id]);
                $existing_perms = [];
                if ($perm_stmt) {
                    while ($row = $perm_stmt->fetch(PDO::FETCH_ASSOC)) {
                        $existing_perms[] = $row['module'] . '_' . $row['action'];
                    }
                }
                
                $response = ['success' => true, 'modules' => $modules, 'permissions' => $existing_perms];
            } else {
                $response = ['success' => false, 'message' => 'Invalid role ID'];
            }
            break;
            
        case 'save_permissions':
            $role_id = (int)($_POST['role_id'] ?? 0);
            $permissions = $_POST['permissions'] ?? [];
            
            if ($role_id <= 0) {
                $response = ['success' => false, 'message' => 'Invalid role ID'];
                break;
            }
            
            // Start transaction
            $pdo->beginTransaction();
            try {
                // Delete existing permissions
                $delete_sql = "DELETE FROM role_permissions WHERE role_id = ?";
                safeQuery($pdo, $delete_sql, [$role_id]);
                
                // Insert new permissions
                $insert_sql = "INSERT INTO role_permissions (role_id, module, action) VALUES (?, ?, ?)";
                foreach ($permissions as $perm) {
                    $parts = explode('_', $perm, 2);
                    if (count($parts) == 2) {
                        list($module, $action) = $parts;
                        safeQuery($pdo, $insert_sql, [$role_id, $module, $action]);
                    }
                }
                
                $pdo->commit();
                $response = ['success' => true, 'message' => 'Permissions saved successfully'];
            } catch (Exception $e) {
                $pdo->rollBack();
                $response = ['success' => false, 'message' => 'Failed to save permissions: ' . $e->getMessage()];
            }
            break;
            
        case 'get_assignments':
            $role_id = (int)($_GET['role_id'] ?? 0);
            if ($role_id > 0) {
                $sql = "SELECT sa.*, u.full_name, u.email, u.phone 
                        FROM staff_assignments sa
                        INNER JOIN users u ON sa.user_id = u.id
                        WHERE sa.role_id = ? AND sa.status = 'active'";
                $params = [$role_id];
                
                if ($role === 'company_admin') {
                    $sql .= " AND u.tenant_id = ?";
                    $params[] = $session_tenant_id;
                }
                
                $sql .= " ORDER BY u.full_name";
                
                $stmt = safeQuery($pdo, $sql, $params);
                $assignments = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                $response = ['success' => true, 'data' => $assignments];
            }
            break;
            
        case 'get_users':
            $sql = "SELECT id, full_name, email, phone FROM users";
            $params = [];
            
            if ($role === 'company_admin') {
                $sql .= " WHERE tenant_id = ?";
                $params[] = $session_tenant_id;
            }
            
            $sql .= " ORDER BY full_name";
            
            $stmt = safeQuery($pdo, $sql, $params);
            $users = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $response = ['success' => true, 'data' => $users];
            break;
            
        case 'assign_role':
            $user_id = (int)($_POST['user_id'] ?? 0);
            $role_id = (int)($_POST['role_id'] ?? 0);
            $start_date = $_POST['start_date'] ?? date('Y-m-d');
            $end_date = $_POST['end_date'] ?? null;
            $salary = (float)($_POST['salary'] ?? 0);
            
            if ($user_id <= 0 || $role_id <= 0) {
                $response = ['success' => false, 'message' => 'User and Role are required'];
                break;
            }
            
            // Check if assignment already exists
            $check_sql = "SELECT id FROM staff_assignments WHERE user_id = ? AND role_id = ? AND status = 'active'";
            $check_stmt = safeQuery($pdo, $check_sql, [$user_id, $role_id]);
            if ($check_stmt && $check_stmt->rowCount() > 0) {
                $response = ['success' => false, 'message' => 'User already has this role assigned'];
                break;
            }
            
            $sql = "INSERT INTO staff_assignments (user_id, role_id, tenant_id, assigned_by, status, start_date, end_date, salary, created_at) 
                    VALUES (?, ?, ?, ?, 'active', ?, ?, ?, NOW())";
            $tenant_id = ($role === 'superadmin') ? ($_POST['tenant_id'] ?? null) : $session_tenant_id;
            $stmt = safeQuery($pdo, $sql, [$user_id, $role_id, $tenant_id, $user_id, $start_date, $end_date, $salary]);
            
            if ($stmt) {
                $response = ['success' => true, 'message' => 'Role assigned successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to assign role'];
            }
            break;
            
        case 'remove_assignment':
            $assignment_id = (int)($_POST['assignment_id'] ?? 0);
            
            if ($assignment_id <= 0) {
                $response = ['success' => false, 'message' => 'Invalid assignment ID'];
                break;
            }
            
            $sql = "UPDATE staff_assignments SET status = 'inactive', end_date = CURDATE() WHERE id = ?";
            $stmt = safeQuery($pdo, $sql, [$assignment_id]);
            
            if ($stmt) {
                $response = ['success' => true, 'message' => 'Role assignment removed successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to remove assignment'];
            }
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

// Get predefined role levels
$role_levels = [
    1 => 'Level 1 - Entry Level',
    2 => 'Level 2 - Junior Staff',
    3 => 'Level 3 - Staff',
    4 => 'Level 4 - Senior Staff',
    5 => 'Level 5 - Supervisor',
    6 => 'Level 6 - Manager',
    7 => 'Level 7 - Senior Manager',
    8 => 'Level 8 - Director',
    9 => 'Level 9 - Executive',
    10 => 'Level 10 - Owner/CEO'
];

include_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Role Management - Cargo Management System</title>
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

        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .role-system { background: #ff9800; color: white; }
        .role-custom { background: #4caf50; color: white; }

        .level-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            background: #e0e0e0;
            color: #333;
        }

        .permission-group {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .permission-group h6 {
            margin-bottom: 10px;
            color: var(--curdun-violet);
            font-weight: 600;
        }
        .permission-item {
            display: inline-flex;
            align-items: center;
            margin-right: 20px;
            margin-bottom: 8px;
        }
        .permission-item input {
            margin-right: 6px;
        }

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
        .action-btn.edit { color: var(--curdun-info); }
        .action-btn.edit:hover { background: rgba(23, 162, 184, 0.1); }
        .action-btn.permission { color: var(--curdun-warning); }
        .action-btn.permission:hover { background: rgba(255, 193, 7, 0.1); }
        .action-btn.delete { color: var(--curdun-danger); }
        .action-btn.delete:hover { background: rgba(198, 40, 40, 0.1); }
        .action-btn.assign { color: var(--curdun-success); }
        .action-btn.assign:hover { background: rgba(0, 166, 90, 0.1); }

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
            <h1><i class="fas fa-user-shield"></i> Role Management</h1>
            <p>Manage user roles, permissions, and assignments for <?= $role === 'superadmin' ? 'all companies' : 'your company' ?></p>
        </div>
        <div>
            <button class="btn-primary-custom" data-toggle="modal" data-target="#roleModal" onclick="openAddRoleModal()">
                <i class="fas fa-plus-circle"></i> Create New Role
            </button>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="stats-card">
                <h3 id="totalRoles">0</h3>
                <p><i class="fas fa-tag"></i> Total Roles</p>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stats-card">
                <h3 id="totalSystemRoles">0</h3>
                <p><i class="fas fa-crown"></i> System Roles</p>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stats-card">
                <h3 id="totalCustomRoles">0</h3>
                <p><i class="fas fa-user-plus"></i> Custom Roles</p>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stats-card">
                <h3 id="totalAssignments">0</h3>
                <p><i class="fas fa-users"></i> Active Assignments</p>
            </div>
        </div>
    </div>

    <!-- Main Card -->
    <div class="card">
        <div class="card-header">
            <h5><i class="fas fa-list"></i> All Roles</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-container">
                <table class="data-table table" id="rolesTable">
                    <thead>
                        <tr>
                            <th><i class="fas fa-hashtag"></i> ID</th>
                            <th><i class="fas fa-tag"></i> Role Name</th>
                            <th><i class="fas fa-heading"></i> Display Name</th>
                            <th><i class="fas fa-align-left"></i> Description</th>
                            <th><i class="fas fa-chart-line"></i> Level</th>
                            <th><i class="fas fa-building"></i> Company</th>
                            <th><i class="fas fa-info-circle"></i> Type</th>
                            <th><i class="fas fa-users"></i> Assigned Users</th>
                            <th><i class="fas fa-cogs"></i> Actions</th>
                        </tr>
                    </thead>
                    <tbody id="rolesTableBody">
                        <tr>
                            <td colspan="9" class="text-center">Loading roles...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Role Modal (Create/Edit) -->
<div class="modal fade" id="roleModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle"></i> <span id="modalTitle">Create New Role</span></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="roleForm">
                    <input type="hidden" id="role_id" name="role_id" value="0">
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Role Name (system identifier) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required placeholder="e.g., warehouse_manager">
                        <small class="form-text text-muted">Unique identifier, use lowercase and underscores</small>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-heading"></i> Display Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="display_name" name="display_name" required placeholder="e.g., Warehouse Manager">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-align-left"></i> Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" placeholder="Describe the role responsibilities..."></textarea>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-chart-line"></i> Level <span class="text-danger">*</span></label>
                        <select class="form-control" id="level" name="level">
                            <?php foreach ($role_levels as $val => $label): ?>
                                <option value="<?= $val ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">Higher level = more senior role</small>
                    </div>
                    <?php if ($role === 'superadmin'): ?>
                    <div class="form-group">
                        <label><i class="fas fa-building"></i> Company</label>
                        <select class="form-control" id="tenant_id" name="tenant_id">
                            <option value="">System-wide (All Companies)</option>
                            <?php foreach ($tenants as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">Leave empty for system-wide roles</small>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-violet" onclick="saveRole()"><i class="fas fa-save"></i> Save Role</button>
            </div>
        </div>
    </div>
</div>

<!-- Permissions Modal -->
<div class="modal fade" id="permissionsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-lock"></i> Manage Permissions for: <span id="permRoleName"></span></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" style="max-height: 500px; overflow-y: auto;">
                <input type="hidden" id="perm_role_id" value="0">
                <div id="permissionsContainer">
                    <div class="text-center">Loading permissions...</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-violet" onclick="savePermissions()"><i class="fas fa-save"></i> Save Permissions</button>
            </div>
        </div>
    </div>
</div>

<!-- Assign Users Modal -->
<div class="modal fade" id="assignModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus"></i> Assign Role to User</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="assign_role_id" value="0">
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Select User <span class="text-danger">*</span></label>
                    <select class="form-control" id="assign_user_id">
                        <option value="">-- Select User --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar"></i> Start Date</label>
                    <input type="date" class="form-control" id="assign_start_date" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar-times"></i> End Date (Optional)</label>
                    <input type="date" class="form-control" id="assign_end_date">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-dollar-sign"></i> Salary (Optional)</label>
                    <input type="number" step="0.01" class="form-control" id="assign_salary" placeholder="0.00">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-violet" onclick="assignRole()"><i class="fas fa-check-circle"></i> Assign Role</button>
            </div>
        </div>
    </div>
</div>

<!-- Users Assignment Modal -->
<div class="modal fade" id="usersModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-users"></i> Users with Role: <span id="usersRoleName"></span></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="users_role_id" value="0">
                <div class="table-container">
                    <table class="table" id="usersAssignmentsTable">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersAssignmentsBody">
                            <tr><td colspan="6" class="text-center">No users assigned</td></tr>
                        </tbody>
                    </table>
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
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
let rolesTable;

// Load all roles
function loadRoles() {
    $.ajax({
        url: window.location.href,
        method: 'GET',
        data: { action: 'get_roles' },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data) {
                renderRolesTable(response.data);
                updateStats(response.data);
            } else {
                $('#rolesTableBody').html('<tr><td colspan="9" class="text-center text-danger">Failed to load roles</td></tr>');
            }
        },
        error: function() {
            $('#rolesTableBody').html('<tr><td colspan="9" class="text-center text-danger">Error loading roles</td></tr>');
        }
    });
}

// Render roles table
function renderRolesTable(roles) {
    let html = '';
    
    roles.forEach(function(role) {
        html += `<tr>
            <td>${role.id}</td>
            <td><code>${escapeHtml(role.name)}</code></td>
            <td><strong>${escapeHtml(role.display_name)}</strong></td>
            <td>${escapeHtml(role.description || '-')}</td>
            <td><span class="level-badge">Level ${role.level}</span></td>
            <td>${role.tenant_id ? `ID: ${role.tenant_id}` : '<span class="badge badge-info">System-wide</span>'}</td>
            <td><span class="role-badge ${role.is_system == 1 ? 'role-system' : 'role-custom'}">${role.is_system == 1 ? 'System' : 'Custom'}</span></td>
            <td>${role.assigned_users || 0}</td>
            <td class="action-buttons">
                <button class="action-btn edit" onclick="openEditRoleModal(${role.id})" title="Edit Role"><i class="fas fa-edit"></i></button>
                <button class="action-btn permission" onclick="openPermissionsModal(${role.id}, '${escapeHtml(role.display_name)}')" title="Manage Permissions"><i class="fas fa-lock"></i></button>
                <button class="action-btn assign" onclick="openAssignModal(${role.id})" title="Assign to Users"><i class="fas fa-user-plus"></i></button>
                <button class="action-btn" onclick="viewAssignedUsers(${role.id}, '${escapeHtml(role.display_name)}')" title="View Assigned Users"><i class="fas fa-users"></i></button>
                ${role.is_system == 0 ? `<button class="action-btn delete" onclick="deleteRole(${role.id})" title="Delete Role"><i class="fas fa-trash"></i></button>` : ''}
            </td>
        </tr>`;
    });
    
    $('#rolesTableBody').html(html);
    
    // Initialize DataTable if not already
    if (!rolesTable) {
        rolesTable = $('#rolesTable').DataTable({
            pageLength: 25,
            responsive: true,
            order: [[4, 'desc'], [0, 'asc']],
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                paginate: { first: "First", last: "Last", next: "Next", previous: "Previous" }
            }
        });
    } else {
        rolesTable.clear();
        rolesTable.rows.add(rolesTable.rows().data());
        rolesTable.draw();
    }
}

// Update statistics
function updateStats(roles) {
    let total = roles.length;
    let system = roles.filter(r => r.is_system == 1).length;
    let custom = total - system;
    let totalAssignments = roles.reduce((sum, r) => sum + (parseInt(r.assigned_users) || 0), 0);
    
    $('#totalRoles').text(total);
    $('#totalSystemRoles').text(system);
    $('#totalCustomRoles').text(custom);
    $('#totalAssignments').text(totalAssignments);
}

// Open add role modal
function openAddRoleModal() {
    $('#modalTitle').text('Create New Role');
    $('#role_id').val('0');
    $('#name').val('').prop('disabled', false);
    $('#display_name').val('');
    $('#description').val('');
    $('#level').val('1');
    $('#roleForm')[0].reset();
    $('#roleModal').modal('show');
}

// Open edit role modal
function openEditRoleModal(roleId) {
    $.ajax({
        url: window.location.href,
        method: 'GET',
        data: { action: 'get_role', role_id: roleId },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data) {
                $('#modalTitle').text('Edit Role');
                $('#role_id').val(response.data.id);
                $('#name').val(response.data.name).prop('disabled', true);
                $('#display_name').val(response.data.display_name);
                $('#description').val(response.data.description || '');
                $('#level').val(response.data.level);
                <?php if ($role === 'superadmin'): ?>
                $('#tenant_id').val(response.data.tenant_id || '');
                <?php endif; ?>
                $('#roleModal').modal('show');
            } else {
                Swal.fire('Error', response.message || 'Failed to load role', 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Failed to load role details', 'error');
        }
    });
}

// Save role
function saveRole() {
    const roleId = $('#role_id').val();
    const name = $('#name').val();
    const displayName = $('#display_name').val();
    const description = $('#description').val();
    const level = $('#level').val();
    
    if (!name || !displayName) {
        Swal.fire('Warning', 'Role name and display name are required', 'warning');
        return;
    }
    
    const action = roleId == 0 ? 'create_role' : 'update_role';
    const data = {
        action: action,
        name: name,
        display_name: displayName,
        description: description,
        level: level
    };
    
    if (roleId > 0) data.role_id = roleId;
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
                $('#roleModal').modal('hide');
                loadRoles();
                Swal.fire('Success', response.message, 'success');
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Failed to save role', 'error');
        }
    });
}

// Delete role
function deleteRole(roleId) {
    Swal.fire({
        title: 'Delete Role?',
        text: 'This action cannot be undone. Users with this role will lose access.',
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
                data: { action: 'delete_role', role_id: roleId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        loadRoles();
                        Swal.fire('Deleted', response.message, 'success');
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Failed to delete role', 'error');
                }
            });
        }
    });
}

// Open permissions modal
function openPermissionsModal(roleId, roleName) {
    $('#perm_role_id').val(roleId);
    $('#permRoleName').text(roleName);
    $('#permissionsContainer').html('<div class="text-center">Loading permissions...</div>');
    $('#permissionsModal').modal('show');
    
    $.ajax({
        url: window.location.href,
        method: 'GET',
        data: { action: 'get_permissions', role_id: roleId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                renderPermissions(response.modules, response.permissions);
            } else {
                $('#permissionsContainer').html('<div class="text-center text-danger">Failed to load permissions</div>');
            }
        },
        error: function() {
            $('#permissionsContainer').html('<div class="text-center text-danger">Error loading permissions</div>');
        }
    });
}

// Render permissions
function renderPermissions(modules, existingPerms) {
    let html = '';
    modules.forEach(function(module) {
        html += `<div class="permission-group">
            <h6><i class="fas fa-folder-open"></i> ${module.label}</h6>`;
        module.actions.forEach(function(action) {
            const permKey = `${module.module}_${action}`;
            const isChecked = existingPerms.includes(permKey);
            const actionLabels = {
                'view': '👁️ View', 'create': '➕ Create', 'edit': '✏️ Edit', 
                'delete': '🗑️ Delete', 'pay': '💰 Pay', 'send': '📤 Send',
                'templates': '📝 Templates', 'export': '📊 Export'
            };
            const actionLabel = actionLabels[action] || action;
            html += `<label class="permission-item">
                <input type="checkbox" value="${permKey}" ${isChecked ? 'checked' : ''}>
                <span>${actionLabel}</span>
            </label>`;
        });
        html += `</div>`;
    });
    $('#permissionsContainer').html(html);
}

// Save permissions
function savePermissions() {
    const roleId = $('#perm_role_id').val();
    const permissions = [];
    $('#permissionsContainer input[type="checkbox"]:checked').each(function() {
        permissions.push($(this).val());
    });
    
    $.ajax({
        url: window.location.href,
        method: 'POST',
        data: { action: 'save_permissions', role_id: roleId, permissions: permissions },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#permissionsModal').modal('hide');
                Swal.fire('Success', response.message, 'success');
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Failed to save permissions', 'error');
        }
    });
}

// Open assign modal
function openAssignModal(roleId) {
    $('#assign_role_id').val(roleId);
    $('#assign_user_id').html('<option value="">Loading users...</option>');
    $('#assignModal').modal('show');
    
    // Load users
    $.ajax({
        url: window.location.href,
        method: 'GET',
        data: { action: 'get_users' },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data) {
                let options = '<option value="">-- Select User --</option>';
                response.data.forEach(function(user) {
                    options += `<option value="${user.id}">${escapeHtml(user.full_name)} (${escapeHtml(user.email || user.phone || 'No contact')})</option>`;
                });
                $('#assign_user_id').html(options);
            } else {
                $('#assign_user_id').html('<option value="">No users available</option>');
            }
        },
        error: function() {
            $('#assign_user_id').html('<option value="">Error loading users</option>');
        }
    });
}

// Assign role to user
function assignRole() {
    const roleId = $('#assign_role_id').val();
    const userId = $('#assign_user_id').val();
    const startDate = $('#assign_start_date').val();
    const endDate = $('#assign_end_date').val();
    const salary = $('#assign_salary').val();
    
    if (!userId) {
        Swal.fire('Warning', 'Please select a user', 'warning');
        return;
    }
    
    $.ajax({
        url: window.location.href,
        method: 'POST',
        data: { action: 'assign_role', role_id: roleId, user_id: userId, start_date: startDate, end_date: endDate, salary: salary },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#assignModal').modal('hide');
                loadRoles();
                Swal.fire('Success', response.message, 'success');
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Failed to assign role', 'error');
        }
    });
}

// View assigned users
function viewAssignedUsers(roleId, roleName) {
    $('#users_role_id').val(roleId);
    $('#usersRoleName').text(roleName);
    $('#usersAssignmentsBody').html('<tr><td colspan="6" class="text-center">Loading...</td></tr>');
    $('#usersModal').modal('show');
    
    $.ajax({
        url: window.location.href,
        method: 'GET',
        data: { action: 'get_assignments', role_id: roleId },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data) {
                renderAssignments(response.data);
            } else {
                $('#usersAssignmentsBody').html('<tr><td colspan="6" class="text-center">No users assigned to this role</td></tr>');
            }
        },
        error: function() {
            $('#usersAssignmentsBody').html('<tr><td colspan="6" class="text-center text-danger">Error loading assignments</td></tr>');
        }
    });
}

// Render assignments table
function renderAssignments(assignments) {
    let html = '';
    assignments.forEach(function(assign) {
        html += `<tr>
            <td><strong>${escapeHtml(assign.full_name)}</strong></td>
            <td>${escapeHtml(assign.email || '-')}</td>
            <td>${escapeHtml(assign.phone || '-')}</td>
            <td>${assign.start_date || '-'}</td>
            <td>${assign.end_date || 'Ongoing'}</td>
            <td>
                <button class="action-btn delete" onclick="removeAssignment(${assign.id})" title="Remove Assignment">
                    <i class="fas fa-user-minus"></i>
                </button>
            </td>
        </tr>`;
    });
    $('#usersAssignmentsBody').html(html);
}

// Remove assignment
function removeAssignment(assignmentId) {
    Swal.fire({
        title: 'Remove Assignment?',
        text: 'This user will lose access to this role.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#B42318',
        confirmButtonText: 'Yes, remove',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: { action: 'remove_assignment', assignment_id: assignmentId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        loadRoles();
                        // Refresh the assignments modal if open
                        const roleId = $('#users_role_id').val();
                        const roleName = $('#usersRoleName').text();
                        viewAssignedUsers(roleId, roleName);
                        Swal.fire('Removed', response.message, 'success');
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Failed to remove assignment', 'error');
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
    loadRoles();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>