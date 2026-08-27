<?php
// superadmin/trucking.php
// Maareynta Gaadiidka (Trucking Fleet Management) -faras cargo

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is superadmin or company_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['superadmin', 'company_admin'])) {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION['role'];
$session_tenant_id = $_SESSION['tenant_id'] ?? 0;

require_once __DIR__ . '/../config/db_connect.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Super Admin';

// Get all tenants for filter dropdown
$tenants = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM tenants ORDER BY name");
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tenants = [];
}

// Handle Export Actions (GET)
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action === 'export_trucks') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=trucks_'.date('Y-m-d').'.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['ID', 'Truck Number', 'Plate Number', 'Model', 'Capacity CBM', 'Capacity KG', 'Tenant', 'Status']);
        
        $where_conditions = [];
        $params = [];
        
        $search = $_GET['search'] ?? '';
        $tenant_filter = $_GET['tenant'] ?? '';
        
        if ($role === 'company_admin') {
            $where_conditions[] = "tr.tenant_id = ?";
            $params[] = $session_tenant_id;
        } elseif (!empty($tenant_filter)) {
            $where_conditions[] = "tr.tenant_id = ?";
            $params[] = $tenant_filter;
        }
        
        if (!empty($search)) {
            $where_conditions[] = "(tr.truck_number LIKE ? OR tr.plate_number LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        $where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);
        
        $sql = "SELECT tr.*, t.name as tenant_name 
                FROM trucks tr 
                LEFT JOIN tenants t ON tr.tenant_id = t.id 
                $where_clause 
                ORDER BY tr.truck_number ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['id'],
                $row['truck_number'],
                $row['plate_number'],
                $row['model'],
                $row['capacity_cbm'],
                $row['capacity_kg'],
                $row['tenant_name'],
                $row['is_active'] ? 'Active' : 'Inactive'
            ]);
        }
        fclose($output);
        exit;
    }
    
    if ($action === 'download_sample') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=trucks_sample.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, ['Tenant Name', 'Truck Number', 'Plate Number', 'Model', 'Capacity CBM', 'Capacity KG', 'Active (1/0)']);
        fputcsv($output, ['Example Logistics', 'TRK-001', 'TA-1234', 'Volvo FH16', '60.00', '25000', '1']);
        fclose($output);
        exit;
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];
    
    if ($action === 'get_trucks') {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $search = $_POST['search'] ?? '';
        $tenant_filter = isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0;
        
        $where_conditions = [];
        $params = [];
        
        if (!empty($search)) {
            $where_conditions[] = "(tr.truck_number LIKE ? OR tr.plate_number LIKE ? OR tr.model LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($role === 'company_admin') {
            $where_conditions[] = "tr.tenant_id = ?";
            $params[] = $session_tenant_id;
        } elseif ($tenant_filter > 0) {
            $where_conditions[] = "tr.tenant_id = ?";
            $params[] = $tenant_filter;
        }
        
        $where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);
        
        $count_sql = "SELECT COUNT(*) as total FROM trucks tr
                      LEFT JOIN tenants t ON tr.tenant_id = t.id
                      $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_items / $limit);
        
        $sql = "
            SELECT tr.*, 
                   t.name as tenant_name,
                   d.full_name as driver_name
            FROM trucks tr
            LEFT JOIN tenants t ON tr.tenant_id = t.id
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
            <table class="data-table" style="min-width: 1200px; width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f6f9;">
                        <th style="padding: 12px;">ID</th>
                        <th style="padding: 12px;">Gaariga</th>
                        <th style="padding: 12px;">Taariko</th>
                        <th style="padding: 12px;">Darawalka Hadda</th>
                        <th style="padding: 12px;">Awoodda (CBM/KG)</th>
                        <th style="padding: 12px;">Safarada</th>
                        <th style="padding: 12px;">Shirkadda</th>
                        <th style="padding: 12px;">Xaaladda</th>
                        <th style="padding: 12px;">Hawlaha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($trucks) > 0): ?>
                        <?php foreach ($trucks as $truck): 
                            $statusColor = $truck['is_active'] ? '#0F7A3A' : '#B42318';
                            $statusName = $truck['is_active'] ? 'Shaqaynaya' : 'Xiran';
                        ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px;"><?= $truck['id'] ?></td>
                                <td style="padding: 12px;">
                                    <strong><?= htmlspecialchars($truck['truck_number']) ?></strong>
                                    <div style="font-size: 11px; color: #6c757d;">Model: <?= htmlspecialchars($truck['model'] ?? '-') ?></div>
                                </td>
                                <td style="padding: 12px;"><span class="badge badge-info text-dark" style="background:#e3f2fd; padding:5px 10px; border-radius:5px;"><?= htmlspecialchars($truck['plate_number'] ?? '-') ?></span></td>
                                <td style="padding: 12px;"><i class="fas fa-user-circle text-muted"></i> <?= htmlspecialchars($truck['driver_name'] ?? 'Aan la qoondeyn') ?></td>
                                <td style="padding: 12px;"><?= number_format($truck['capacity_cbm'] ?? 0, 2) ?> CBM / <?= number_format($truck['capacity_kg'] ?? 0) ?> KG</td>
                                <td style="padding: 12px;"><strong><?= number_format($truck['total_trips']) ?></strong> Safar</td>
                                <td style="padding: 12px;"><?= htmlspecialchars($truck['tenant_name'] ?? '-') ?></td>
                                <td style="padding: 12px;">
                                    <span class="status-badge" style="background: <?= $statusColor ?>20; color: <?= $statusColor ?>; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold;">
                                        <?= $statusName ?>
                                    </span>
                                </td>
                                <td style="padding: 12px;">
                                    <div class="action-buttons" style="display: flex; gap: 5px;">
                                        <button class="action-btn btn-edit edit-item" data-id="<?= $truck['id'] ?>" title="Wax Ka Beddel"><i class="fas fa-edit"></i></button>
                                        <button class="action-btn btn-delete delete-item" data-id="<?= $truck['id'] ?>" data-name="<?= htmlspecialchars($truck['truck_number']) ?>" title="Tirtir"><i class="fas fa-trash"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 50px;">
                                <div class="empty-state">
                                    <i class="fas fa-truck" style="font-size: 48px; opacity: 0.5;"></i>
                                    <p>Ma jiraan gaadiid diiwaangashan</p>
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
                    <a data-page="<?= $page-1 ?>" style="padding: 8px 14px; border-radius: 8px; background: white; border: 1px solid #ddd; cursor: pointer;">Hore</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <span class="<?= $i == $page ? 'active' : '' ?>" <?= $i != $page ? 'data-page="'.$i.'"' : '' ?> style="padding: 8px 14px; border-radius: 8px; cursor: pointer; border: 1px solid #ddd; background: <?= $i == $page ? 'var(--curdun-violet)' : 'white' ?>; color: <?= $i == $page ? 'white' : 'black' ?>;"><?= $i ?></span>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a data-page="<?= $page+1 ?>" style="padding: 8px 14px; border-radius: 8px; background: white; border: 1px solid #ddd; cursor: pointer;">Danbe</a>
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
    
    elseif ($action === 'import_trucks') {
        if (!isset($_FILES['excel_file'])) {
            echo json_encode(['success' => false, 'message' => 'Fayl lama dooran!']);
            exit;
        }
        
        $file = $_FILES['excel_file']['tmp_name'];
        $handle = fopen($file, "r");
        fgetcsv($handle); // Skip header
        
        $imported = 0;
        $errors = [];
        $line = 1;
        
        try {
            $pdo->beginTransaction();
            
            // Pre-fetch tenants
            $tenants_map = [];
            $stmt = $pdo->query("SELECT id, name FROM tenants");
            while ($t = $stmt->fetch()) {
                $tenants_map[strtolower($t['name'])] = $t['id'];
            }
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $line++;
                // Columns: Tenant Name, Truck Number, Plate Number, Model, Capacity CBM, Capacity KG, Active
                $tenant_name = trim($data[0] ?? '');
                $truck_no = trim($data[1] ?? '');
                $plate_no = trim($data[2] ?? '');
                $model = trim($data[3] ?? '');
                $cbm = (float)($data[4] ?? 0);
                $kg = (float)($data[5] ?? 0);
                $active = (int)($data[6] ?? 1);
                
                if (empty($tenant_name) || empty($truck_no)) continue;
                
                $t_id = $tenants_map[strtolower($tenant_name)] ?? null;
                if (!$t_id) {
                    $errors[] = "Line $line: Tenant '$tenant_name' not found.";
                    continue;
                }
                
                $stmt = $pdo->prepare("INSERT INTO trucks (tenant_id, truck_number, plate_number, model, capacity_cbm, capacity_kg, is_active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$t_id, $truck_no, $plate_no, $model, $cbm, $kg, $active, $_SESSION['user_id']]);
                
                $imported++;
            }
            
            $pdo->commit();
            $msg = "Import-ka waa lagu guulaystay! ($imported baabuur).";
            if (count($errors) > 0) $msg .= "<br>Digniin: " . count($errors) . " saf ayaa laga booday.";
            echo json_encode(['success' => true, 'message' => $msg]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        fclose($handle);
        exit;
    }
    
    elseif ($action === 'save_truck') {
        $id = $_POST['item_id'] ?? '';
        $tenant_id = !empty($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : null;
        $truck_number = trim($_POST['truck_number'] ?? '');
        $plate_number = trim($_POST['plate_number'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $capacity_cbm = (float)($_POST['capacity_cbm'] ?? 0);
        $capacity_kg = (float)($_POST['capacity_kg'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($truck_number)) {
            echo json_encode(['success' => false, 'message' => 'Magaca ama lambarka gaariga waa qasab']);
            exit;
        }
        
        try {
            if (empty($id)) {
                $sql = "INSERT INTO trucks (tenant_id, truck_number, plate_number, model, capacity_cbm, capacity_kg, is_active, created_by, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$tenant_id, $truck_number, $plate_number, $model, $capacity_cbm, $capacity_kg, $is_active, $_SESSION['user_id']]);
                echo json_encode(['success' => true, 'message' => "Gaariga waa la kaydiyay!"]);
            } else {
                $sql = "UPDATE trucks SET tenant_id=?, truck_number=?, plate_number=?, model=?, capacity_cbm=?, capacity_kg=?, is_active=? WHERE id=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$tenant_id, $truck_number, $plate_number, $model, $capacity_cbm, $capacity_kg, $is_active, $id]);
                echo json_encode(['success' => true, 'message' => "Gaariga waa la cusboonaysiiyay!"]);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'delete_truck') {
        $id = $_POST['id'] ?? 0;
        try {
            $check = $pdo->prepare("SELECT COUNT(*) as count FROM trucking_trips WHERE truck_id = ?");
            $check->execute([$id]);
            if ($check->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
                echo json_encode(['success' => false, 'message' => "Gaarigan safaro ayuu ku jiraa, lama tirtiri karo."]);
                exit;
            }
            $stmt = $pdo->prepare("DELETE FROM trucks WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => "Waa la tirtiray!"]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'get_truck') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM trucks WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit;
    }
}

// Include header
require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maareynta Gaadiidka | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root {
            --curdun-violet: #2D1859;
            --curdun-yellow: #F5C410;
            --curdun-violet-light: #4B2C85;
            --curdun-dark: #2D2D2D;
        }
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        
        .page-header {
            background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light));
            border-radius: 16px;
            padding: 20px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .page-header h1 { color: white; font-size: 24px; margin: 0; }
        
        .btn-primary-custom {
            background: var(--curdun-yellow);
            color: var(--curdun-violet);
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }
        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            display: flex; gap: 15px;
        }
        .filters-card input, .filters-card select {
            padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px; flex: 1;
        }
        .action-btn { padding: 5px 10px; border-radius: 5px; cursor: pointer; border: none; }
        .btn-edit { background: #fff3e0; color: #e65100; }
        .btn-delete { background: #FEF0EE; color: #B42318; }
        
        .table-container { background: white; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow: hidden; }
        .modal-header { background: var(--curdun-violet); color: white; }
        .modal-header .close { color: white; }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-truck"></i> Maareynta Gaadiidka (Trucking Fleet)</h1>
        <div class="d-flex align-items-center">
            <button type="button" class="btn-primary-custom" id="addNewBtn">
                <i class="fas fa-plus"></i> Gaari Cusub
            </button>
            <div class="dropdown ml-2">
                <button class="btn btn-light dropdown-toggle" type="button" data-toggle="dropdown" style="border-radius: 8px; padding: 10px 15px; font-weight: 600; border: none; background: rgba(255,255,255,0.2); color: white;">
                    <i class="fas fa-file-csv"></i> CSV
                </button>
                <div class="dropdown-menu dropdown-menu-right">
                    <a class="dropdown-item" href="?action=export_trucks" id="exportTrucksBtn"><i class="fas fa-download mr-2"></i> Export Trucks</a>
                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#importModal"><i class="fas fa-upload mr-2"></i> Import Trucks</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="?action=download_sample"><i class="fas fa-file-download mr-2"></i> Download Sample</a>
                </div>
            </div>
        </div>
    </div>

    <div class="filters-card">
        <input type="text" id="searchInput" placeholder="Raadi gaari...">
        <select id="tenantFilter">
            <option value="0">Dhammaan Shirkadaha</option>
            <?php foreach ($tenants as $t): ?>
                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-dark" id="applyFilters"><i class="fas fa-search"></i></button>
    </div>

    <div class="table-container" id="tableContainer">
        <div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
    </div>
    <div id="paginationContainer"></div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 12px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-import"></i> Soo geli Gaadiid (CSV)</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="importForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="alert alert-info" style="font-size: 13px;">
                        <i class="fas fa-info-circle"></i> Fadlan soo geli faylka CSV oo kaliya. 
                        <a href="?action=download_sample" class="alert-link">Halkan ka soo deji sample-ka</a>.
                    </div>
                    <div class="form-group">
                        <label>Dooro Faylka (CSV)</label>
                        <input type="file" name="excel_file" class="form-control" accept=".csv" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button>
                    <button type="submit" class="btn" style="background: var(--curdun-violet); color: white;">Soo geli (Import)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="itemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-truck"></i> Xogta Gaariga</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="itemForm">
                <div class="modal-body">
                    <input type="hidden" name="item_id" id="item_id">
                    <input type="hidden" name="ajax_action" value="save_truck">
                    
                    <div class="form-group">
                        <label>Shirkadda <span class="text-danger">*</span></label>
                        <select name="tenant_id" id="modalTenantId" class="form-control" required>
                            <option value="">-- Dooro Shirkad --</option>
                            <?php foreach ($tenants as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Lambarka Gaariga <span class="text-danger">*</span></label>
                        <input type="text" name="truck_number" id="modalTruckNumber" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Taariko (Plate Number)</label>
                        <input type="text" name="plate_number" id="modalPlateNumber" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label>Modelka Gaariga</label>
                        <input type="text" name="model" id="modalModel" class="form-control">
                    </div>
                    
                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label>Awoodda (CBM)</label>
                                <input type="number" step="0.01" name="capacity_cbm" id="modalCbm" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label>Awoodda (KG)</label>
                                <input type="number" step="0.01" name="capacity_kg" id="modalKg" class="form-control" value="0">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="modalIsActive" name="is_active" checked>
                            <label class="custom-control-label" for="modalIsActive">Shaqaynaya (Active)</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button>
                    <button type="submit" class="btn" style="background:var(--curdun-violet);color:white;">Kaydi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    function loadData(page = 1) {
        $.post(window.location.href, {
            ajax_action: 'get_trucks',
            page: page,
            search: $('#searchInput').val(),
            tenant: $('#tenantFilter').val()
        }, function(res) {
            $('#tableContainer').html(res.table_html);
            $('#paginationContainer').html(res.pagination_html);
            
            // Update export link
            let search = $('#searchInput').val();
            let tenant = $('#tenantFilter').val();
            $('#exportTrucksBtn').attr('href', `?action=export_trucks&search=${encodeURIComponent(search)}&tenant=${tenant}`);
        }, 'json');
    }

    $('#importForm').submit(function(e) {
        e.preventDefault();
        let formData = new FormData(this);
        formData.append('ajax_action', 'import_trucks');
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#importModal').modal('hide');
                    loadData(1);
                    alert(res.message);
                    $('#importForm')[0].reset();
                } else {
                    alert(res.message);
                }
            },
            error: function() {
                alert('Khalad ayaa dhacay intii lagu guda jiray soo gelinta.');
            }
        });
    });
    
    $('#applyFilters').click(() => loadData(1));
    $(document).on('click', '.pagination a, .pagination span[data-page]', function() {
        if($(this).data('page')) loadData($(this).data('page'));
    });
    
    $('#addNewBtn').click(function() {
        $('#itemForm')[0].reset();
        $('#item_id').val('');
        $('#itemModal').modal('show');
    });
    
    $(document).on('click', '.edit-item', function() {
        $.post(window.location.href, {ajax_action: 'get_truck', id: $(this).data('id')}, function(res) {
            $('#item_id').val(res.id);
            $('#modalTenantId').val(res.tenant_id);
            $('#modalTruckNumber').val(res.truck_number);
            $('#modalPlateNumber').val(res.plate_number);
            $('#modalModel').val(res.model);
            $('#modalCbm').val(res.capacity_cbm);
            $('#modalKg').val(res.capacity_kg);
            $('#modalIsActive').prop('checked', res.is_active == 1);
            $('#itemModal').modal('show');
        }, 'json');
    });
    
    $(document).on('click', '.delete-item', function() {
        if(confirm('Ma hubtaa inaad tirtirto ' + $(this).data('name') + '?')) {
            $.post(window.location.href, {ajax_action: 'delete_truck', id: $(this).data('id')}, function(res) {
                alert(res.message);
                if(res.success) loadData(1);
            }, 'json');
        }
    });
    
    $('#itemForm').submit(function(e) {
        e.preventDefault();
        $.post(window.location.href, $(this).serialize(), function(res) {
            if(res.success) {
                $('#itemModal').modal('hide');
                loadData(1);
            } else {
                alert(res.message);
            }
        }, 'json');
    });
    
    loadData(1);
});
</script>
</body>
</html>