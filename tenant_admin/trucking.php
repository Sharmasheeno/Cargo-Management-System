<?php
// tenant_admin/trucking.php
// Trucking Fleet Management for Cargo Management System - Tenant Admin

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

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];
    
    if ($action === 'get_trucks') {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $search = $_POST['search'] ?? '';
        
        $where_conditions = ["tr.tenant_id = ?"];
        $params = [$session_tenant_id];
        
        if (!empty($search)) {
            $where_conditions[] = "(tr.truck_number LIKE ? OR tr.plate_number LIKE ? OR tr.model LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
        
        $count_sql = "SELECT COUNT(*) as total FROM trucks tr $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_items / $limit);
        
        $sql = "
            SELECT tr.*, 
                   d.full_name as driver_name
            FROM trucks tr
            LEFT JOIN drivers d ON tr.current_driver_id = d.id
            $where_clause
            ORDER BY tr.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $trucks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_start(); ?>
        <div style="overflow-x: auto; width: 100%;">
            <table class="data-table" style="min-width: 1000px; width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f6f9;">
                        <th style="padding: 12px;">ID</th>
                        <th style="padding: 12px;">Truck Details</th>
                        <th style="padding: 12px;">Plate Number</th>
                        <th style="padding: 12px;">Current Driver</th>
                        <th style="padding: 12px;">Capacity (CBM/KG)</th>
                        <th style="padding: 12px;">Total Trips</th>
                        <th style="padding: 12px;">Status</th>
                        <th style="padding: 12px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($trucks) > 0): ?>
                        <?php foreach ($trucks as $truck): 
                            $statusColor = $truck['is_active'] ? '#0F7A3A' : '#B42318';
                            $statusName = $truck['is_active'] ? 'Active' : 'Inactive';
                        ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px;"><?= $truck['id'] ?></td>
                                <td style="padding: 12px;">
                                    <strong><?= htmlspecialchars($truck['truck_number']) ?></strong>
                                    <div style="font-size: 11px; color: #6c757d;">Model: <?= htmlspecialchars($truck['model'] ?? '-') ?></div>
                                </td>
                                <td style="padding: 12px;"><span class="badge badge-info text-dark" style="background:#e3f2fd; padding:5px 10px; border-radius:5px;"><?= htmlspecialchars($truck['plate_number'] ?? '-') ?></span></td>
                                <td style="padding: 12px;"><i class="fas fa-user-circle text-muted"></i> <?= htmlspecialchars($truck['driver_name'] ?? 'Not assigned') ?></td>
                                <td style="padding: 12px;"><?= number_format($truck['capacity_cbm'] ?? 0, 2) ?> CBM / <?= number_format($truck['capacity_kg'] ?? 0) ?> KG</td>
                                <td style="padding: 12px;"><strong><?= number_format($truck['total_trips']) ?></strong> Trips</td>
                                <td style="padding: 12px;">
                                    <span class="status-badge" style="background: <?= $statusColor ?>20; color: <?= $statusColor ?>; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold;">
                                        <?= $statusName ?>
                                    </span>
                                </td>
                                <td style="padding: 12px;">
                                    <div class="action-buttons" style="display: flex; gap: 5px;">
                                        <button class="action-btn btn-edit edit-item" data-id="<?= $truck['id'] ?>" title="Edit"><i class="fas fa-edit"></i></button>
                                        <button class="action-btn btn-delete delete-item" data-id="<?= $truck['id'] ?>" data-name="<?= htmlspecialchars($truck['truck_number']) ?>" title="Delete"><i class="fas fa-trash"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 50px;">
                                <div class="empty-state">
                                    <i class="fas fa-truck" style="font-size: 48px; opacity: 0.5;"></i>
                                    <p>No trucks registered</p>
                                    <button class="btn-primary-custom" id="addNewBtnEmpty" style="margin-top: 10px;">
                                        <i class="fas fa-plus-circle"></i> Add Truck
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
                    <a data-page="<?= $page-1 ?>" style="padding: 8px 14px; border-radius: 8px; background: white; border: 1px solid #ddd; cursor: pointer;"><i class="fas fa-chevron-left"></i> Previous</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <span class="<?= $i == $page ? 'active' : '' ?>" <?= $i != $page ? 'data-page="'.$i.'"' : '' ?> style="padding: 8px 14px; border-radius: 8px; cursor: pointer; border: 1px solid #ddd; background: <?= $i == $page ? '#2D1859' : 'white' ?>; color: <?= $i == $page ? 'white' : 'black' ?>;"><?= $i ?></span>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a data-page="<?= $page+1 ?>" style="padding: 8px 14px; border-radius: 8px; background: white; border: 1px solid #ddd; cursor: pointer;">Next <i class="fas fa-chevron-right"></i></a>
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
    
    elseif ($action === 'save_truck') {
        $id = $_POST['item_id'] ?? '';
        $tenant_id = $session_tenant_id; // Force tenant_admin's tenant
        $truck_number = trim($_POST['truck_number'] ?? '');
        $plate_number = trim($_POST['plate_number'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $capacity_cbm = (float)($_POST['capacity_cbm'] ?? 0);
        $capacity_kg = (float)($_POST['capacity_kg'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($truck_number)) {
            echo json_encode(['success' => false, 'message' => 'Truck number is required']);
            exit;
        }
        
        try {
            if (empty($id)) {
                // Check for duplicate truck number within this tenant
                $check = $pdo->prepare("SELECT id FROM trucks WHERE truck_number = ? AND tenant_id = ?");
                $check->execute([$truck_number, $tenant_id]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'message' => "Truck number '$truck_number' already exists for your company"]);
                    exit;
                }
                
                $sql = "INSERT INTO trucks (tenant_id, truck_number, plate_number, model, capacity_cbm, capacity_kg, is_active, created_by, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$tenant_id, $truck_number, $plate_number, $model, $capacity_cbm, $capacity_kg, $is_active, $_SESSION['user_id']]);
                echo json_encode(['success' => true, 'message' => "Truck '$truck_number' has been saved!"]);
            } else {
                // Verify truck belongs to this tenant
                $check = $pdo->prepare("SELECT id FROM trucks WHERE id = ? AND tenant_id = ?");
                $check->execute([$id, $tenant_id]);
                if (!$check->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Truck not found or you do not have permission']);
                    exit;
                }
                
                $sql = "UPDATE trucks SET truck_number=?, plate_number=?, model=?, capacity_cbm=?, capacity_kg=?, is_active=? WHERE id=? AND tenant_id=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$truck_number, $plate_number, $model, $capacity_cbm, $capacity_kg, $is_active, $id, $tenant_id]);
                echo json_encode(['success' => true, 'message' => "Truck '$truck_number' has been updated!"]);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'delete_truck') {
        $id = $_POST['id'] ?? 0;
        try {
            // Verify truck belongs to this tenant
            $check = $pdo->prepare("SELECT truck_number FROM trucks WHERE id = ? AND tenant_id = ?");
            $check->execute([$id, $session_tenant_id]);
            $truck = $check->fetch(PDO::FETCH_ASSOC);
            
            if (!$truck) {
                echo json_encode(['success' => false, 'message' => 'Truck not found']);
                exit;
            }
            
            $checkTrips = $pdo->prepare("SELECT COUNT(*) as count FROM trucking_trips WHERE truck_id = ? AND tenant_id = ?");
            $checkTrips->execute([$id, $session_tenant_id]);
            if ($checkTrips->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
                echo json_encode(['success' => false, 'message' => "This truck has active trips and cannot be deleted."]);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM trucks WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $session_tenant_id]);
            echo json_encode(['success' => true, 'message' => "Truck '{$truck['truck_number']}' has been deleted!"]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'get_truck') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM trucks WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $session_tenant_id]);
        $truck = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($truck);
        exit;
    }
    
    exit;
}

// Include header
require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trucking Management - <?= htmlspecialchars($tenant_name) ?> | Cargo Management System</title>
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
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .btn-primary-custom:hover {
            background: var(--curdun-yellow-dark);
            transform: translateY(-2px);
        }
        
        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        .filters-card input, .filters-card select {
            flex: 1;
            min-width: 200px;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        .filters-card button {
            padding: 8px 20px;
            border-radius: 8px;
            background: var(--curdun-violet);
            color: white;
            border: none;
            cursor: pointer;
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            overflow-x: auto;
        }
        
        .action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
        .action-btn { padding: 5px 8px; border-radius: 6px; font-size: 11px; cursor: pointer; border: none; transition: all 0.3s ease; }
        .btn-edit { background: #fff3e0; color: #e65100; }
        .btn-edit:hover { background: #ffe0b2; transform: scale(1.05); }
        .btn-delete { background: #FEF0EE; color: #B42318; }
        .btn-delete:hover { background: #FEF0EE; transform: scale(1.05); }
        
        .alert { padding: 12px 20px; border-radius: 8px; position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .alert-success { background: #EEFBF3; color: #0F7A3A; border-left: 4px solid #0F7A3A; }
        .alert-error { background: #FEF0EE; color: #B42318; border-left: 4px solid #B42318; }
        .alert-info { background: #e3f2fd; color: #1565c0; border-left: 4px solid #1565c0; }
        
        .modal-header { background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light)); color: white; }
        .modal-header .close { color: white; opacity: 1; }
        .modal-header .close:hover { color: var(--curdun-yellow); }
        
        .loading-spinner { text-align: center; padding: 50px; }
        .loading-spinner i { font-size: 48px; color: var(--curdun-violet); animation: spin 1s linear infinite; }
        .empty-state { text-align: center; padding: 50px; color: var(--curdun-gray); }
        .empty-state i { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }
        
        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: center; }
            .filters-card { flex-direction: column; }
            .filters-card input, .filters-card select { width: 100%; }
        }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-truck"></i> Trucking Fleet Management</h1>
        <div class="d-flex gap-3 align-items-center">
            <span class="company-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($tenant_name) ?></span>
            <button type="button" class="btn-primary-custom" id="addNewBtn">
                <i class="fas fa-plus-circle"></i> Add Truck
            </button>
        </div>
    </div>

    <div class="filters-card">
        <input type="text" id="searchInput" placeholder="Search truck number, plate, model...">
        <button id="applyFilters"><i class="fas fa-search"></i> Search</button>
        <button id="resetFilters" style="background: #f0f0f0; color: #333;"><i class="fas fa-undo"></i> Reset</button>
    </div>

    <div class="table-container" id="tableContainer">
        <div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i><p>Loading trucks...</p></div>
    </div>
    <div id="paginationContainer"></div>
</div>

<!-- Truck Modal -->
<div class="modal fade" id="itemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-truck"></i> Truck Information</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="itemForm">
                <div class="modal-body">
                    <input type="hidden" name="item_id" id="item_id">
                    <input type="hidden" name="ajax_action" value="save_truck">
                    
                    <div class="form-group">
                        <label>Truck Number / Name <span class="text-danger">*</span></label>
                        <input type="text" name="truck_number" id="modalTruckNumber" class="form-control" required placeholder="E.g., TRK-001, Volvo FH16">
                    </div>
                    
                    <div class="form-group">
                        <label>Plate Number</label>
                        <input type="text" name="plate_number" id="modalPlateNumber" class="form-control" placeholder="E.g., 6A-1234">
                    </div>
                    
                    <div class="form-group">
                        <label>Truck Model</label>
                        <input type="text" name="model" id="modalModel" class="form-control" placeholder="E.g., Volvo FH16, Mercedes Actros">
                    </div>
                    
                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label>Capacity (CBM)</label>
                                <input type="number" step="0.01" name="capacity_cbm" id="modalCbm" class="form-control" value="0" placeholder="Cubic meters">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label>Capacity (KG)</label>
                                <input type="number" step="0.01" name="capacity_kg" id="modalKg" class="form-control" value="0" placeholder="Kilograms">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="modalIsActive" name="is_active" checked>
                            <label class="custom-control-label" for="modalIsActive">Active (Available for assignments)</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Save Truck</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
let currentPage = 1;

function showAlert(type, msg) {
    $('#alert-placeholder').html(`<div class="alert alert-${type} alert-dismissible fade show"><i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
    setTimeout(() => $('.alert').fadeOut(5000, function() { $(this).remove(); }), 5000);
}

function loadData(page = 1) {
    currentPage = page;
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: {
            ajax_action: 'get_trucks',
            page: page,
            search: $('#searchInput').val()
        },
        dataType: 'json',
        success: function(res) {
            $('#tableContainer').html(res.table_html);
            $('#paginationContainer').html(res.pagination_html);
            attachTableEvents();
        },
        error: function() {
            $('#tableContainer').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading data</p></div>');
        }
    });
}

function attachTableEvents() {
    $('.edit-item').off('click').on('click', function() {
        const id = $(this).data('id');
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_truck', id: id },
            dataType: 'json',
            success: function(res) {
                if (res) {
                    $('#item_id').val(res.id);
                    $('#modalTruckNumber').val(res.truck_number);
                    $('#modalPlateNumber').val(res.plate_number);
                    $('#modalModel').val(res.model);
                    $('#modalCbm').val(res.capacity_cbm);
                    $('#modalKg').val(res.capacity_kg);
                    $('#modalIsActive').prop('checked', res.is_active == 1);
                    $('#itemModal').modal('show');
                } else {
                    showAlert('error', 'Truck data not found');
                }
            },
            error: function() {
                showAlert('error', 'Error loading truck data');
            }
        });
    });
    
    $('.delete-item').off('click').on('click', function() {
        const id = $(this).data('id');
        const name = $(this).data('name');
        if (confirm(`Are you sure you want to delete truck "${name}"?`)) {
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'delete_truck', id: id },
                dataType: 'json',
                success: function(res) {
                    showAlert(res.success ? 'success' : 'error', res.message);
                    if (res.success) loadData(currentPage);
                },
                error: function() {
                    showAlert('error', 'Error occurred');
                }
            });
        }
    });
    
    $('.pagination a, .pagination span[data-page]').off('click').on('click', function() {
        const page = $(this).data('page');
        if (page) loadData(page);
    });
}

$(document).ready(function() {
    $('#applyFilters').click(function() { loadData(1); });
    
    $('#resetFilters').click(function() { 
        $('#searchInput').val(''); 
        loadData(1); 
    });
    
    $('#searchInput').keypress(function(e) {
        if (e.which === 13) loadData(1);
    });
    
    $('#addNewBtn, #addNewBtnEmpty').click(function() {
        $('#itemForm')[0].reset();
        $('#item_id').val('');
        $('#modalIsActive').prop('checked', true);
        $('#modalCbm').val(0);
        $('#modalKg').val(0);
        $('#itemModal').modal('show');
    });
    
    $('#itemForm').submit(function(e) {
        e.preventDefault();
        
        if (!$('#modalTruckNumber').val().trim()) {
            showAlert('error', 'Truck number is required');
            return;
        }
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#itemModal').modal('hide');
                    loadData(currentPage);
                    showAlert('success', res.message);
                } else {
                    showAlert('error', res.message);
                }
            },
            error: function() {
                showAlert('error', 'Error occurred');
            }
        });
    });
    
    loadData(1);
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>