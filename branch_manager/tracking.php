<?php
// branch_manager/tracking.php
// Live Tracking for Branch Manager - scoped to the manager's own branch
// Adapted from tenant_admin/tracking.php (READ ONLY MODE)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role_type'] ?? $_SESSION['role'] ?? '') !== 'branch_manager') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

$user_id = (int)$_SESSION['user_id'];
$tenant_id = (int)($_SESSION['tenant_id'] ?? 0);

if ($tenant_id <= 0) {
    header("Location: ../login.php?error=no_tenant");
    exit;
}

// Get branch manager's assigned branch
$assigned_branch_id = $_SESSION['assigned_branch_id'] ?? null;

if (!$assigned_branch_id) {
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
            $_SESSION['assigned_branch_id'] = $assigned_branch_id;
        }
    } catch (PDOException $e) {}
}

if (!$assigned_branch_id) {
    echo '<div class="alert alert-danger">You are not assigned to any branch. Please contact administrator.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$assigned_branch_id = (int)$assigned_branch_id;

// Get branch name for display
$branch_name = 'My Branch';
try {
    $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$assigned_branch_id, $tenant_id]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) {
        $branch_name = $branch['branch_name'];
    }
} catch (PDOException $e) {}

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Get tenant name
$tenant_name = '';
try {
    $stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
    $stmt->execute([$tenant_id]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_name = $tenant['name'] ?? 'My Company';
} catch (PDOException $e) {
    $tenant_name = 'My Company';
}

// ==================== AJAX ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    header('Content-Type: application/json');

    $action = $_POST['ajax_action'];

    if ($action === 'track_item') {
        $tracking_number = trim($_POST['tracking_number'] ?? '');

        if ($tracking_number === '') {
            echo json_encode(['success' => false, 'message' => 'Please enter a tracking number']);
            exit;
        }

        // Search in containers (scoped to this branch)
        $stmt = $pdo->prepare("
            SELECT c.*, b.branch_name
            FROM containers c
            LEFT JOIN branches b ON c.current_branch_id = b.id AND b.tenant_id = c.tenant_id
            WHERE (c.tracking_number = ? OR c.container_number = ?) AND c.tenant_id = ? AND c.current_branch_id = ?
            LIMIT 1
        ");
        $stmt->execute([$tracking_number, $tracking_number, $tenant_id, $assigned_branch_id]);
        $container = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($container) {
            echo json_encode(['success' => true, 'type' => 'container', 'data' => $container]);
            exit;
        }

        // Search in trips for this branch
        $stmt = $pdo->prepare("
            SELECT t.*, c.container_number,
                   fb.branch_name AS from_branch_name, tb.branch_name AS to_branch_name
            FROM trucking_trips t
            LEFT JOIN containers c ON t.container_id = c.id
            LEFT JOIN branches fb ON t.from_branch_id = fb.id
            LEFT JOIN branches tb ON t.to_branch_id = tb.id
            WHERE (t.trip_number = ? OR c.container_number = ?) AND t.tenant_id = ?
              AND (t.branch_id = ? OR t.from_branch_id = ? OR t.to_branch_id = ?)
            LIMIT 1
        ");
        $stmt->execute([$tracking_number, $tracking_number, $tenant_id, $assigned_branch_id, $assigned_branch_id, $assigned_branch_id]);
        $trip = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($trip) {
            echo json_encode(['success' => true, 'type' => 'trip', 'data' => $trip]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Tracking number not found in your branch']);
        }
        exit;
    }

    if ($action === 'get_recent_tracking') {
        $stmt = $pdo->prepare("
            SELECT c.*, b.branch_name
            FROM containers c
            LEFT JOIN branches b ON c.current_branch_id = b.id AND b.tenant_id = c.tenant_id
            WHERE c.tenant_id = ? AND c.current_branch_id = ?
            ORDER BY c.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$tenant_id, $assigned_branch_id]);
        $containers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt2 = $pdo->prepare("
            SELECT t.trip_number, t.status, t.created_at, c.container_number
            FROM trucking_trips t
            LEFT JOIN containers c ON t.container_id = c.id
            WHERE t.tenant_id = ? AND (t.branch_id = ? OR t.from_branch_id = ? OR t.to_branch_id = ?)
            ORDER BY t.created_at DESC
            LIMIT 10
        ");
        $stmt2->execute([$tenant_id, $assigned_branch_id, $assigned_branch_id, $assigned_branch_id]);
        $trips = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['containers' => $containers, 'trips' => $trips]);
        exit;
    }

    if ($action === 'get_stats') {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) as total_containers,
                SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) as received,
                SUM(CASE WHEN status = 'loading' THEN 1 ELSE 0 END) as loading,
                SUM(CASE WHEN status = 'loaded' THEN 1 ELSE 0 END) as loaded,
                SUM(CASE WHEN status = 'shipped' THEN 1 ELSE 0 END) as shipped,
                SUM(CASE WHEN status = 'dispatched' THEN 1 ELSE 0 END) as dispatched,
                SUM(CASE WHEN status = 'at_port' THEN 1 ELSE 0 END) as at_port,
                SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) as ready,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered
            FROM containers
            WHERE tenant_id = ? AND current_branch_id = ?
        ");
        $stmt->execute([$tenant_id, $assigned_branch_id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($stats ?: []);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Tracking - <?= h($branch_name) ?> | Cargo Management System</title>
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
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .page-header {
            background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light));
            border-radius: 16px; padding: 20px 25px; margin-bottom: 25px;
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;
        }
        .page-header h1 { color: white; font-size: 24px; margin: 0; }
        .page-header h1 i { margin-right: 10px; }
        .company-badge { background: rgba(255,255,255,0.2); color: #fff; padding: 8px 16px; border-radius: 20px; font-size: 14px; }
        .btn-primary-custom {
            background: var(--curdun-yellow); color: var(--curdun-violet); border: none;
            padding: 10px 20px; border-radius: 8px; font-weight: 600;
            display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
        }
        .btn-primary-custom:hover { background: var(--curdun-yellow-dark); }
        .tracking-card { background: white; border-radius: 16px; padding: 25px; margin-bottom: 25px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .tracking-title { font-size: 18px; font-weight: 600; color: var(--curdun-dark); margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid var(--curdun-violet); }
        .tracking-title i { color: var(--curdun-violet); margin-right: 8px; }
        .search-box { display: flex; gap: 15px; align-items: flex-end; }
        .search-box .form-group { flex: 1; margin-bottom: 0; }
        .search-box input { width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 14px; }
        .search-box input:focus { border-color: var(--curdun-violet); outline: none; }
        .result-card { background: #f8f6f9; border-radius: 12px; padding: 20px; margin-top: 20px; }
        .result-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #e0e0e0; flex-wrap: wrap; gap: 10px; }
        .result-header h3 { margin: 0; font-size: 18px; color: var(--curdun-violet); }
        .status-badge { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .info-row { display: flex; margin-bottom: 10px; flex-wrap: wrap; }
        .info-label { width: 180px; font-weight: 600; color: var(--curdun-gray); }
        .info-value { flex: 1; color: var(--curdun-dark); word-break: break-word; }
        .timeline { margin-top: 20px; position: relative; padding-left: 30px; }
        .timeline::before { content: ''; position: absolute; left: 10px; top: 0; bottom: 0; width: 2px; background: var(--curdun-violet); }
        .timeline-item { position: relative; margin-bottom: 20px; }
        .timeline-dot { position: absolute; left: -26px; top: 0; width: 14px; height: 14px; border-radius: 50%; background: #ccc; border: 2px solid white; box-shadow: 0 0 0 2px #ccc; }
        .timeline-dot.completed { background: var(--curdun-success); box-shadow: 0 0 0 2px var(--curdun-success); }
        .timeline-dot.current { background: var(--curdun-yellow); box-shadow: 0 0 0 2px var(--curdun-yellow); animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.2); opacity: 0.7; } 100% { transform: scale(1); opacity: 1; } }
        .timeline-title { font-weight: 600; margin-bottom: 5px; }
        .timeline-date { font-size: 12px; color: var(--curdun-gray); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card-sm { background: white; border-radius: 12px; padding: 12px 15px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 3px solid var(--curdun-violet); }
        .stat-card-sm .stat-info h4 { font-size: 10px; color: var(--curdun-gray); margin: 0 0 3px 0; text-transform: uppercase; }
        .stat-card-sm .stat-info .stat-number { font-size: 20px; font-weight: 700; color: var(--curdun-violet); }
        .stat-card-sm .stat-icon { width: 35px; height: 35px; background: rgba(45,24,89,0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center; }
        .stat-card-sm .stat-icon i { font-size: 16px; color: var(--curdun-violet); }
        .recent-table { width: 100%; border-collapse: collapse; }
        .recent-table th, .recent-table td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        .recent-table th { background: #f8f6f9; font-weight: 600; font-size: 12px; }
        .empty-state { text-align: center; padding: 50px; color: var(--curdun-gray); }
        .empty-state i { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }
        .loading-spinner { text-align: center; padding: 30px; }
        .loading-spinner i { font-size: 32px; color: var(--curdun-violet); animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .readonly-badge { background: #EEFBF3; color: #0F7A3A; padding: 4px 12px; border-radius: 20px; font-size: 11px; margin-left: 10px; }
        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: center; }
            .search-box { flex-direction: column; }
            .info-row { flex-direction: column; }
            .info-label { width: 100%; margin-bottom: 5px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-map-marker-alt"></i> Live Tracking</h1>
        <div class="d-flex gap-3 align-items-center">
            <span class="company-badge"><i class="fas fa-code-branch"></i> <?= h($branch_name) ?></span>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card-sm"><div class="stat-info"><h4>Total</h4><div class="stat-number" id="stat-total">0</div></div><div class="stat-icon"><i class="fas fa-box"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>Received</h4><div class="stat-number" id="stat-received">0</div></div><div class="stat-icon"><i class="fas fa-download"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>Loading</h4><div class="stat-number" id="stat-loading">0</div></div><div class="stat-icon"><i class="fas fa-spinner"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>Loaded</h4><div class="stat-number" id="stat-loaded">0</div></div><div class="stat-icon"><i class="fas fa-truck-loading"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>Dispatched</h4><div class="stat-number" id="stat-dispatched">0</div></div><div class="stat-icon"><i class="fas fa-paper-plane"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>At Port</h4><div class="stat-number" id="stat-at_port">0</div></div><div class="stat-icon"><i class="fas fa-ship"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>Ready</h4><div class="stat-number" id="stat-ready">0</div></div><div class="stat-icon"><i class="fas fa-check"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>Delivered</h4><div class="stat-number" id="stat-delivered">0</div></div><div class="stat-icon"><i class="fas fa-flag-checkered"></i></div></div>
    </div>

    <div class="tracking-card">
        <div class="tracking-title"><i class="fas fa-search"></i> Track Container or Trip (My Branch Only)</div>
        <div class="search-box">
            <div class="form-group">
                <input type="text" id="trackingNumber" class="form-control" placeholder="Enter Container Number, Trip Number, or Tracking Number..." autocomplete="off">
            </div>
            <button class="btn-primary-custom" id="trackBtn"><i class="fas fa-search"></i> Track</button>
        </div>
        <div id="trackResult"></div>
    </div>

    <div class="tracking-card">
        <div class="tracking-title"><i class="fas fa-clock"></i> Recent Activity</div>
        <div id="recentActivity"><div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {

    const statusNames = {
        'pending': 'Pending', 'received': 'Received', 'loading': 'Loading', 'loaded': 'Loaded', 'shipped': 'Shipped',
        'dispatched': 'Dispatched', 'at_port': 'At Port', 'ready': 'Ready', 'in_transit': 'In Transit',
        'delivered': 'Delivered', 'completed': 'Completed'
    };
    const statusColors = {
        'pending': '#6c757d', 'received': '#17a2b8', 'loading': '#ffc107', 'loaded': '#fd7e14', 'shipped': '#6f42c1',
        'dispatched': '#fd7e14', 'at_port': '#6f42c1', 'ready': '#28a745', 'in_transit': '#fd7e14',
        'delivered': '#20c997', 'completed': '#20c997'
    };
    const containerFlow = ['received', 'loading', 'loaded', 'shipped', 'dispatched', 'at_port', 'ready', 'delivered'];
    const tripFlow = ['pending', 'received', 'loading', 'loaded', 'in_transit', 'delivered', 'completed'];

    function escapeHtml(text) {
        if (!text) return '';
        return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function loadStats() {
        $.post(window.location.href, { ajax_action: 'get_stats' }, function(stats) {
            $('#stat-total').text(stats.total_containers || 0);
            $('#stat-received').text(stats.received || 0);
            $('#stat-loading').text(stats.loading || 0);
            $('#stat-loaded').text(stats.loaded || 0);
            $('#stat-dispatched').text(stats.dispatched || 0);
            $('#stat-at_port').text(stats.at_port || 0);
            $('#stat-ready').text(stats.ready || 0);
            $('#stat-delivered').text(stats.delivered || 0);
        }, 'json');
    }

    function loadRecentActivity() {
        $.post(window.location.href, { ajax_action: 'get_recent_tracking' }, function(data) {
            let html = '';
            if ((data.containers && data.containers.length) || (data.trips && data.trips.length)) {
                if (data.containers && data.containers.length) {
                    html += '<h6 class="mb-3"><i class="fas fa-box"></i> Recent Containers</h6>';
                    html += '<div class="table-responsive"><table class="recent-table"><thead><tr><th>Container</th><th>Tracking Number</th><th>Status</th><th>Date</th></tr></thead><tbody>';
                    data.containers.forEach(c => {
                        html += `<tr><td><strong>${escapeHtml(c.container_number)}</strong></td><td>${escapeHtml(c.tracking_number || '-')}</td>
                            <td><span class="status-badge" style="background:${statusColors[c.status]}20;color:${statusColors[c.status]}">${statusNames[c.status] || c.status}</span></td>
                            <td>${new Date(c.created_at).toLocaleDateString()}</td></tr>`;
                    });
                    html += '</tbody></table></div>';
                }
                if (data.trips && data.trips.length) {
                    html += '<h6 class="mt-4 mb-3"><i class="fas fa-truck"></i> Recent Trips</h6>';
                    html += '<div class="table-responsive"><table class="recent-table"><thead><tr><th>Trip Number</th><th>Container</th><th>Status</th><th>Date</th></tr></thead><tbody>';
                    data.trips.forEach(s => {
                        html += `<tr><td><strong>${escapeHtml(s.trip_number)}</strong></td><td>${escapeHtml(s.container_number || '-')}</td>
                            <td><span class="status-badge" style="background:${statusColors[s.status]}20;color:${statusColors[s.status]}">${statusNames[s.status] || s.status}</span></td>
                            <td>${new Date(s.created_at).toLocaleDateString()}</td></tr>`;
                    });
                    html += '</tbody></table></div>';
                }
            } else {
                html = '<div class="empty-state"><i class="fas fa-box-open"></i><p>No recent activity found for your branch</p></div>';
            }
            $('#recentActivity').html(html);
        }, 'json').fail(function() {
            $('#recentActivity').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading recent activity</p></div>');
        });
    }

    function renderTimeline(flow, currentStatus) {
        const currentIndex = flow.indexOf(currentStatus);
        let html = '<div class="timeline">';
        flow.forEach((status, i) => {
            let cls = '';
            if (i < currentIndex) cls = 'completed';
            else if (i === currentIndex) cls = 'current';
            html += `<div class="timeline-item"><div class="timeline-dot ${cls}"></div><div>
                <div class="timeline-title">${statusNames[status] || status}</div>
                ${i === currentIndex ? '<div class="timeline-date">Current Status</div>' : ''}
            </div></div>`;
        });
        html += '</div>';
        return html;
    }

    function displayContainerResult(container) {
        const html = `
            <div class="result-card">
                <div class="result-header">
                    <h3><i class="fas fa-box"></i> ${escapeHtml(container.container_number)}</h3>
                    <span class="status-badge" style="background:${statusColors[container.status]}20;color:${statusColors[container.status]};border:1px solid ${statusColors[container.status]}">${statusNames[container.status] || container.status}</span>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-row"><div class="info-label">Container Number:</div><div class="info-value"><strong>${escapeHtml(container.container_number)}</strong></div></div>
                        <div class="info-row"><div class="info-label">Tracking Number:</div><div class="info-value">${escapeHtml(container.tracking_number || '-')}</div></div>
                        <div class="info-row"><div class="info-label">Type:</div><div class="info-value">${escapeHtml(container.container_type || '-')}</div></div>
                        <div class="info-row"><div class="info-label">Size (CBM):</div><div class="info-value">${container.size_cbm || 0} CBM</div></div>
                        <div class="info-row"><div class="info-label">Weight (KG):</div><div class="info-value">${container.weight_kg || 0} kg</div></div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-row"><div class="info-label">Branch:</div><div class="info-value">${escapeHtml(container.branch_name || '-')}</div></div>
                        <div class="info-row"><div class="info-label">Current Location:</div><div class="info-value">${escapeHtml(container.current_location || '-')}</div></div>
                        <div class="info-row"><div class="info-label">Arrival Date:</div><div class="info-value">${container.arrival_date || '-'}</div></div>
                        <div class="info-row"><div class="info-label">Seal Number:</div><div class="info-value">${escapeHtml(container.seal_number || '-')}</div></div>
                        <div class="info-row"><div class="info-label">BL Number:</div><div class="info-value">${escapeHtml(container.bl_number || '-')}</div></div>
                    </div>
                </div>
                ${container.notes ? `<div class="info-row mt-2"><div class="info-label">Notes:</div><div class="info-value">${escapeHtml(container.notes)}</div></div>` : ''}
                <div class="mt-4"><h6><i class="fas fa-chart-line"></i> Journey Timeline</h6>${renderTimeline(containerFlow, container.status)}</div>
                <div class="mt-3 text-center"><small class="text-muted"><i class="fas fa-info-circle"></i> Read-only. Manage this container from the Containers page.</small></div>
            </div>`;
        $('#trackResult').html(html);
    }

    function displayTripResult(trip) {
        const html = `
            <div class="result-card">
                <div class="result-header">
                    <h3><i class="fas fa-truck"></i> ${escapeHtml(trip.trip_number)}</h3>
                    <span class="status-badge" style="background:${statusColors[trip.status]}20;color:${statusColors[trip.status]};border:1px solid ${statusColors[trip.status]}">${statusNames[trip.status] || trip.status}</span>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-row"><div class="info-label">Trip Number:</div><div class="info-value"><strong>${escapeHtml(trip.trip_number)}</strong></div></div>
                        <div class="info-row"><div class="info-label">Container:</div><div class="info-value">${escapeHtml(trip.container_number || '-')}</div></div>
                        <div class="info-row"><div class="info-label">Driver:</div><div class="info-value">${escapeHtml(trip.driver_name || '-')} ${trip.driver_phone ? '(' + escapeHtml(trip.driver_phone) + ')' : ''}</div></div>
                        <div class="info-row"><div class="info-label">Truck Plate:</div><div class="info-value">${escapeHtml(trip.truck_plate || '-')}</div></div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-row"><div class="info-label">From Branch:</div><div class="info-value">${escapeHtml(trip.from_branch_name || '-')}</div></div>
                        <div class="info-row"><div class="info-label">To Branch:</div><div class="info-value">${escapeHtml(trip.to_branch_name || '-')}</div></div>
                        <div class="info-row"><div class="info-label">Total CBM:</div><div class="info-value">${trip.total_cbm || 0} CBM</div></div>
                        <div class="info-row"><div class="info-label">Created:</div><div class="info-value">${trip.created_at ? new Date(trip.created_at).toLocaleString() : '-'}</div></div>
                    </div>
                </div>
                ${trip.notes ? `<div class="info-row mt-3"><div class="info-label">Notes:</div><div class="info-value">${escapeHtml(trip.notes)}</div></div>` : ''}
                <div class="mt-4"><h6><i class="fas fa-chart-line"></i> Journey Timeline</h6>${renderTimeline(tripFlow, trip.status)}</div>
                <div class="mt-3 text-center"><small class="text-muted"><i class="fas fa-info-circle"></i> Read-only. Manage this trip from the Trips page.</small></div>
            </div>`;
        $('#trackResult').html(html);
    }

    function trackItem() {
        const trackingNumber = $('#trackingNumber').val().trim();
        if (!trackingNumber) { showAlert('warning', 'Please enter a tracking number'); return; }
        $('#trackResult').html('<div class="text-center p-4"><i class="fas fa-spinner fa-spin"></i> Searching...</div>');
        $.post(window.location.href, { ajax_action: 'track_item', tracking_number: trackingNumber }, function(res) {
            if (res.success) {
                if (res.type === 'container') displayContainerResult(res.data);
                else displayTripResult(res.data);
            } else {
                $('#trackResult').html(`<div class="result-card"><div class="alert alert-danger mb-0">${escapeHtml(res.message)}</div></div>`);
            }
        }, 'json').fail(function() {
            $('#trackResult').html('<div class="result-card"><div class="alert alert-danger mb-0">Error occurred. Please try again.</div></div>');
        });
    }

    function showAlert(type, message) {
        const alertHtml = `<div class="alert alert-${type} alert-dismissible fade show">${escapeHtml(message)}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`;
        $('#alert-placeholder').html(alertHtml);
        setTimeout(() => $('.alert').fadeOut(3000, function() { $(this).remove(); }), 3000);
    }

    $('#trackBtn').click(trackItem);
    $('#trackingNumber').keypress(function(e) { if (e.which === 13) trackItem(); });

    loadStats();
    loadRecentActivity();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
