<?php
// superadmin/tracking.php
// Tracking forfaras cargo - Super Admin
// Track Containers and Shipments with Live Status - READ ONLY MODE

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

// Get all tenants for filter dropdown (Super Admin only)
$tenants = [];
if ($role === 'superadmin') {
    try {
        $stmt = $pdo->query("SELECT id, name FROM tenants ORDER BY name");
        $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $tenants = [];
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];
    
    if ($action === 'track_container') {
        $tracking_number = trim($_POST['tracking_number'] ?? '');
        
        if (empty($tracking_number)) {
            echo json_encode(['success' => false, 'message' => 'Fadlan geli lambarka raadraaca']);
            exit;
        }
        
        // Search in containers
        $tenant_where = ($role === 'company_admin') ? "AND c.tenant_id = $session_tenant_id" : "";
        $stmt = $pdo->prepare("
            SELECT c.*, t.name as tenant_name 
            FROM containers c
            LEFT JOIN tenants t ON c.tenant_id = t.id
            WHERE (c.tracking_number = ? OR c.container_number = ?) $tenant_where
            LIMIT 1
        ");
        $stmt->execute([$tracking_number, $tracking_number]);
        $container = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($container) {
            echo json_encode(['success' => true, 'type' => 'container', 'data' => $container]);
        } else {
            // Search in shipments
            $stmt = $pdo->prepare("
                SELECT t.*, 
                       c.container_number,
                       tr.truck_number,
                       d.full_name as driver_name,
                       l.full_name as loader_name,
                       tn.name as tenant_name
                FROM trucking_trips t
                LEFT JOIN containers c ON t.container_id = c.id
                LEFT JOIN trucks tr ON t.truck_id = tr.id
                LEFT JOIN drivers d ON t.driver_id = d.id
                LEFT JOIN loaders l ON t.loader_id = l.id
                LEFT JOIN tenants tn ON t.tenant_id = tn.id
                WHERE (t.trip_number = ? OR c.container_number = ?) " . (($role === 'company_admin') ? "AND t.tenant_id = $session_tenant_id" : "") . "
                LIMIT 1
            ");
            $stmt->execute([$tracking_number, $tracking_number]);
            $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($shipment) {
                echo json_encode(['success' => true, 'type' => 'shipment', 'data' => $shipment]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Lambarka raadraaca lama helin']);
            }
        }
        exit;
    }
    
    elseif ($action === 'get_recent_tracking') {
        // Get recent containers
        $recent_where = ($role === 'company_admin') ? "AND c.tenant_id = $session_tenant_id" : "";
        $stmt = $pdo->query("
            SELECT c.*, t.name as tenant_name 
            FROM containers c
            LEFT JOIN tenants t ON c.tenant_id = t.id
            WHERE c.tracking_number IS NOT NULL AND c.tracking_number != '' $recent_where
            ORDER BY c.created_at DESC
            LIMIT 10
        ");
        $containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get recent shipments
        $stmt2 = $pdo->query("
            SELECT t.trip_number, t.status, t.created_at,
                   c.container_number, tn.name as tenant_name
            FROM trucking_trips t
            LEFT JOIN containers c ON t.container_id = c.id
            LEFT JOIN tenants tn ON t.tenant_id = tn.id
            " . (($role === 'company_admin') ? "WHERE t.tenant_id = $session_tenant_id" : "") . "
            ORDER BY t.created_at DESC
            LIMIT 10
        ");
        $shipments = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['containers' => $containers, 'shipments' => $shipments]);
        exit;
    }
    
    elseif ($action === 'get_stats') {
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_containers,
                SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) as received,
                SUM(CASE WHEN status = 'loaded' THEN 1 ELSE 0 END) as loaded,
                SUM(CASE WHEN status = 'dispatched' THEN 1 ELSE 0 END) as dispatched,
                SUM(CASE WHEN status = 'at_port' THEN 1 ELSE 0 END) as at_port,
                SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) as ready,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered
            FROM containers
            " . (($role === 'company_admin') ? "WHERE tenant_id = $session_tenant_id" : "") . "
        ");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($stats);
        exit;
    }
    exit;
}

// Include header
require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raadraaca - Super Admin | Cargo Management System</title>
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
            color: var(--curdun-violet);
            transform: translateY(-2px);
        }

        .tracking-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .tracking-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--curdun-dark);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--curdun-violet);
        }
        .tracking-title i { color: var(--curdun-violet); margin-right: 8px; }

        .search-box {
            display: flex;
            gap: 15px;
            align-items: flex-end;
        }
        .search-box .form-group { flex: 1; margin-bottom: 0; }
        .search-box input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
        }
        .search-box input:focus { border-color: var(--curdun-violet); outline: none; }

        .result-card {
            background: #f8f6f9;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }
        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e0e0e0;
        }
        .result-header h3 { margin: 0; font-size: 18px; color: var(--curdun-violet); }
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .info-row {
            display: flex;
            margin-bottom: 10px;
        }
        .info-label {
            width: 180px;
            font-weight: 600;
            color: var(--curdun-gray);
        }
        .info-value { flex: 1; color: var(--curdun-dark); }

        .timeline {
            margin-top: 20px;
            position: relative;
            padding-left: 30px;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--curdun-violet);
        }
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }
        .timeline-dot {
            position: absolute;
            left: -26px;
            top: 0;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: var(--curdun-violet);
            border: 2px solid white;
            box-shadow: 0 0 0 2px var(--curdun-violet);
        }
        .timeline-dot.completed { background: var(--curdun-success); box-shadow: 0 0 0 2px var(--curdun-success); }
        .timeline-dot.current { background: var(--curdun-warning); box-shadow: 0 0 0 2px var(--curdun-warning); animation: pulse 1.5s infinite; }
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }
        .timeline-content { padding-bottom: 10px; }
        .timeline-title { font-weight: 600; margin-bottom: 5px; }
        .timeline-date { font-size: 12px; color: var(--curdun-gray); }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card-sm {
            background: white;
            border-radius: 12px;
            padding: 12px 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border-left: 3px solid var(--curdun-violet);
            transition: transform 0.3s ease;
        }
        .stat-card-sm:hover { transform: translateY(-2px); }
        .stat-card-sm .stat-info h4 { font-size: 10px; color: var(--curdun-gray); margin: 0 0 3px 0; }
        .stat-card-sm .stat-info .stat-number { font-size: 20px; font-weight: 700; color: var(--curdun-violet); }
        .stat-card-sm .stat-icon { width: 35px; height: 35px; background: rgba(82,0,102,0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center; }
        .stat-card-sm .stat-icon i { font-size: 16px; color: var(--curdun-violet); }

        .recent-table {
            width: 100%;
            border-collapse: collapse;
        }
        .recent-table th, .recent-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .recent-table th { background: #f8f6f9; font-weight: 600; font-size: 12px; }

        .alert { padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #EEFBF3; color: #0F7A3A; border-left: 4px solid #0F7A3A; }
        .alert-error { background: #FEF0EE; color: #B42318; border-left: 4px solid #B42318; }
        .alert-info { background: #e3f2fd; color: #1565c0; border-left: 4px solid #1565c0; }

        .empty-state { text-align: center; padding: 50px; color: var(--curdun-gray); }
        .empty-state i { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }

        .loading-spinner { text-align: center; padding: 30px; }
        .loading-spinner i { font-size: 32px; color: var(--curdun-violet); animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* READ-ONLY STYLES */
        .readonly-badge {
            background: #EEFBF3;
            color: #0F7A3A;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            margin-left: 10px;
        }
        
        .no-edit-cursor {
            cursor: default;
        }
        
        .result-card .info-value {
            word-break: break-word;
        }
        
        .btn-edit-mode {
            background: #e3f2fd;
            color: #1565c0;
            border: none;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            cursor: not-allowed;
            opacity: 0.6;
        }

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
        <h1><i class="fas fa-map-marker-alt"></i> Raadraaca</h1>
        <div>
            <span class="readonly-badge"><i class="fas fa-eye"></i> Aragti Keliya (Read Only)</span>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card-sm">
            <div class="stat-info"><h4>Wadarta Kontaynerada</h4><div class="stat-number" id="stat-total">0</div></div>
            <div class="stat-icon"><i class="fas fa-box"></i></div>
        </div>
        <div class="stat-card-sm">
            <div class="stat-info"><h4>La Helay</h4><div class="stat-number" id="stat-received">0</div></div>
            <div class="stat-icon"><i class="fas fa-download"></i></div>
        </div>
        <div class="stat-card-sm">
            <div class="stat-info"><h4>La Raray</h4><div class="stat-number" id="stat-loaded">0</div></div>
            <div class="stat-icon"><i class="fas fa-truck-loading"></i></div>
        </div>
        <div class="stat-card-sm">
            <div class="stat-info"><h4>La Diray</h4><div class="stat-number" id="stat-dispatched">0</div></div>
            <div class="stat-icon"><i class="fas fa-paper-plane"></i></div>
        </div>
        <div class="stat-card-sm">
            <div class="stat-info"><h4>Dekedda</h4><div class="stat-number" id="stat-at_port">0</div></div>
            <div class="stat-icon"><i class="fas fa-ship"></i></div>
        </div>
        <div class="stat-card-sm">
            <div class="stat-info"><h4>Diyaar</h4><div class="stat-number" id="stat-ready">0</div></div>
            <div class="stat-icon"><i class="fas fa-check"></i></div>
        </div>
        <div class="stat-card-sm">
            <div class="stat-info"><h4>La Gaarsiiyay</h4><div class="stat-number" id="stat-delivered">0</div></div>
            <div class="stat-icon"><i class="fas fa-flag-checkered"></i></div>
        </div>
    </div>

    <!-- Search Card -->
    <div class="tracking-card">
        <div class="tracking-title">
            <i class="fas fa-search"></i> Raadi Kontayner ama Safar
        </div>
        <div class="search-box">
            <div class="form-group">
                <input type="text" id="trackingNumber" class="form-control" placeholder="Geli Lambarka Kontaynerka, Lambarka Safarka ama Tracking Number..." autocomplete="off">
            </div>
            <button class="btn-primary-custom" id="trackBtn">
                <i class="fas fa-search"></i> Raadi
            </button>
        </div>
        <div id="trackResult"></div>
    </div>

    <!-- Recent Activity -->
    <div class="tracking-card">
        <div class="tracking-title">
            <i class="fas fa-clock"></i> Dhaqdhaqaaqa Ugu Dambeeyay
        </div>
        <div id="recentActivity">
            <div class="loading-spinner text-center p-4">
                <i class="fas fa-spinner fa-spin"></i> Loading...
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    
    // Load stats
    function loadStats() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_stats' },
            dataType: 'json',
            success: function(stats) {
                $('#stat-total').text(stats.total_containers || 0);
                $('#stat-received').text(stats.received || 0);
                $('#stat-loaded').text(stats.loaded || 0);
                $('#stat-dispatched').text(stats.dispatched || 0);
                $('#stat-at_port').text(stats.at_port || 0);
                $('#stat-ready').text(stats.ready || 0);
                $('#stat-delivered').text(stats.delivered || 0);
            },
            error: function() {
                console.log('Error loading stats');
            }
        });
    }
    
    // Load recent activity
    function loadRecentActivity() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_recent_tracking' },
            dataType: 'json',
            success: function(data) {
                let html = '';
                
                if (data.containers.length > 0 || data.shipments.length > 0) {
                    if (data.containers.length > 0) {
                        html += '<h6 class="mb-3"><i class="fas fa-box"></i> Kontaynerada Ugu Dambeeyay</h6>';
                        html += '<table class="recent-table"><thead><tr><th>Kontaynerka</th><th>Tracking Number</th><th>Xaaladda</th><th>Shirkadda</th><th>Taariikhda</th></tr></thead><tbody>';
                        for (let c of data.containers) {
                            const statusNames = {
                                'received': 'La Helay', 'loaded': 'La Raray', 'dispatched': 'La Diray',
                                'at_port': 'Dekedda', 'ready': 'Diyaar', 'delivered': 'La Gaarsiiyay'
                            };
                            const statusColors = {
                                'received': '#17a2b8', 'loaded': '#ffc107', 'dispatched': '#fd7e14',
                                'at_port': '#6f42c1', 'ready': '#28a745', 'delivered': '#20c997'
                            };
                            html += `<tr>
                                <td><strong>${escapeHtml(c.container_number)}</strong></td>
                                <td>${escapeHtml(c.tracking_number || '-')}</td>
                                <td><span class="status-badge" style="background: ${statusColors[c.status]}20; color: ${statusColors[c.status]}">${statusNames[c.status]}</span></td>
                                <td>${escapeHtml(c.tenant_name || '-')}</td>
                                <td>${new Date(c.created_at).toLocaleDateString()}</td>
                            </tr>`;
                        }
                        html += '</tbody></table>';
                    }
                    
                    if (data.shipments.length > 0) {
                        html += '<h6 class="mt-4 mb-3"><i class="fas fa-truck"></i> Safarada Ugu Dambeeyay</h6>';
                        html += '<table class="recent-table"><thead><tr><th>Lambarka Safarka</th><th>Kontaynerka</th><th>Xaaladda</th><th>Shirkadda</th><th>Taariikhda</th></tr></thead><tbody>';
                        for (let s of data.shipments) {
                            const statusNames = {
                                'received': 'La Helay', 'loaded': 'La Raray', 'dispatched': 'La Diray',
                                'at_port': 'Dekedda', 'ready': 'Diyaar', 'delivered': 'La Gaarsiiyay'
                            };
                            const statusColors = {
                                'received': '#17a2b8', 'loaded': '#ffc107', 'dispatched': '#fd7e14',
                                'at_port': '#6f42c1', 'ready': '#28a745', 'delivered': '#20c997'
                            };
                            html += `<tr>
                                <td><strong>${escapeHtml(s.trip_number)}</strong></td>
                                <td>${escapeHtml(s.container_number || '-')}</td>
                                <td><span class="status-badge" style="background: ${statusColors[s.status]}20; color: ${statusColors[s.status]}">${statusNames[s.status]}</span></td>
                                <td>${escapeHtml(s.tenant_name || '-')}</td>
                                <td>${new Date(s.created_at).toLocaleDateString()}</td>
                            </tr>`;
                        }
                        html += '</tbody></table>';
                    }
                } else {
                    html = '<div class="empty-state"><i class="fas fa-box-open"></i><p>Ma jiraan wax dhaqdhaqaaq ah</p></div>';
                }
                $('#recentActivity').html(html);
            },
            error: function() {
                $('#recentActivity').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading recent activity</p></div>');
            }
        });
    }
    
    // Escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    
    // Track function
    function trackItem() {
        const trackingNumber = $('#trackingNumber').val().trim();
        if (!trackingNumber) {
            showAlert('error', 'Fadlan geli lambarka raadraaca');
            return;
        }
        
        $('#trackResult').html('<div class="text-center p-4"><i class="fas fa-spinner fa-spin"></i> Raadinaysa...</div>');
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'track_container', tracking_number: trackingNumber },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    if (res.type === 'container') {
                        displayContainerResult(res.data);
                    } else {
                        displayShipmentResult(res.data);
                    }
                } else {
                    $('#trackResult').html(`<div class="result-card"><div class="alert alert-error mb-0">${escapeHtml(res.message)}</div></div>`);
                }
            },
            error: function() {
                $('#trackResult').html('<div class="result-card"><div class="alert alert-error mb-0">Khalad ayaa dhacay. Fadlan isku day mar kale.</div></div>');
            }
        });
    }
    
    // Display container result (READ ONLY - NO EDIT)
    function displayContainerResult(container) {
        const statusNames = {
            'received': 'La Helay', 'loaded': 'La Raray', 'dispatched': 'La Diray',
            'at_port': 'Dekedda', 'ready': 'Diyaar', 'delivered': 'La Gaarsiiyay'
        };
        const statusColors = {
            'received': '#17a2b8', 'loaded': '#ffc107', 'dispatched': '#fd7e14',
            'at_port': '#6f42c1', 'ready': '#28a745', 'delivered': '#20c997'
        };
        const originNames = { 'china_yiwu': 'Shiinaha (Yiwu) 🇨🇳', 'china_guangzhou': 'Shiinaha (Guangzhou) 🇨🇳', 'dubai': 'Dubay 🇦🇪' };
        
        const statusOrder = ['received', 'loaded', 'dispatched', 'at_port', 'ready', 'delivered'];
        const currentStatus = container.status;
        const currentIndex = statusOrder.indexOf(currentStatus);
        
        let timelineHtml = '<div class="timeline">';
        for (let i = 0; i < statusOrder.length; i++) {
            const status = statusOrder[i];
            let statusClass = '';
            if (i < currentIndex) statusClass = 'completed';
            else if (i === currentIndex) statusClass = 'current';
            
            timelineHtml += `
                <div class="timeline-item">
                    <div class="timeline-dot ${statusClass}"></div>
                    <div class="timeline-content">
                        <div class="timeline-title">${statusNames[status]}</div>
                        ${i === currentIndex ? '<div class="timeline-date">Xaaladda hadda</div>' : ''}
                    </div>
                </div>
            `;
        }
        timelineHtml += '</div>';
        
        const html = `
            <div class="result-card">
                <div class="result-header">
                    <h3><i class="fas fa-box"></i> ${escapeHtml(container.container_number)}</h3>
                    <div>
                        <span class="status-badge" style="background: ${statusColors[currentStatus]}20; color: ${statusColors[currentStatus]}; border: 1px solid ${statusColors[currentStatus]}">
                            ${statusNames[currentStatus]}
                        </span>
                        <span class="readonly-badge" style="margin-left: 10px;"><i class="fas fa-eye"></i> Aragti Keliya</span>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-row"><div class="info-label">Lambarka Kontaynerka:</div><div class="info-value"><strong>${escapeHtml(container.container_number)}</strong></div></div>
                        <div class="info-row"><div class="info-label">Lambarka Raadraaca:</div><div class="info-value">${escapeHtml(container.tracking_number || '-')}</div></div>
                        <div class="info-row"><div class="info-label">Asalka:</div><div class="info-value">${originNames[container.origin] || container.origin}</div></div>
                        <div class="info-row"><div class="info-label">Cabirka (CBM):</div><div class="info-value">${container.size_cbm} CBM</div></div>
                        <div class="info-row"><div class="info-label">Culmis (KG):</div><div class="info-value">${container.weight_kg} kg</div></div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-row"><div class="info-label">Goobta Hadda:</div><div class="info-value">${escapeHtml(container.current_location || '-')}</div></div>
                        <div class="info-row"><div class="info-label">Shirkadda:</div><div class="info-value">${escapeHtml(container.tenant_name || '-')}</div></div>
                        <div class="info-row"><div class="info-label">Taariikhda Imid:</div><div class="info-value">${container.arrival_date || '-'}</div></div>
                        <div class="info-row"><div class="info-label">Taariikhda Bixitaan:</div><div class="info-value">${container.departure_date || '-'}</div></div>
                        <div class="info-row"><div class="info-label">Lambarka Seal:</div><div class="info-value">${escapeHtml(container.seal_number || '-')}</div></div>
                    </div>
                </div>
                ${container.notes ? `<div class="info-row mt-2"><div class="info-label">Qoraal:</div><div class="info-value">${escapeHtml(container.notes)}</div></div>` : ''}
                
                <div class="mt-4">
                    <h6><i class="fas fa-chart-line"></i> Socodka Safarka</h6>
                    ${timelineHtml}
                </div>
                
                <div class="mt-3 text-center">
                    <small class="text-muted"><i class="fas fa-info-circle"></i> Xogtan waa aragti keliya. Wax ka beddel ayaa laga sameyn karaa bogga maareynta.</small>
                </div>
            </div>
        `;
        $('#trackResult').html(html);
    }
    
    // Display shipment result (READ ONLY - NO EDIT)
    function displayShipmentResult(shipment) {
        const statusNames = {
            'received': 'La Helay', 'loaded': 'La Raray', 'dispatched': 'La Diray',
            'at_port': 'Dekedda', 'ready': 'Diyaar', 'delivered': 'La Gaarsiiyay'
        };
        const statusColors = {
            'received': '#17a2b8', 'loaded': '#ffc107', 'dispatched': '#fd7e14',
            'at_port': '#6f42c1', 'ready': '#28a745', 'delivered': '#20c997'
        };
        
        const statusOrder = ['received', 'loaded', 'dispatched', 'at_port', 'ready', 'delivered'];
        const currentStatus = shipment.status;
        const currentIndex = statusOrder.indexOf(currentStatus);
        
        let timelineHtml = '<div class="timeline">';
        for (let i = 0; i < statusOrder.length; i++) {
            const status = statusOrder[i];
            let statusClass = '';
            if (i < currentIndex) statusClass = 'completed';
            else if (i === currentIndex) statusClass = 'current';
            
            timelineHtml += `
                <div class="timeline-item">
                    <div class="timeline-dot ${statusClass}"></div>
                    <div class="timeline-content">
                        <div class="timeline-title">${statusNames[status]}</div>
                        ${i === currentIndex ? '<div class="timeline-date">Xaaladda hadda</div>' : ''}
                    </div>
                </div>
            `;
        }
        timelineHtml += '</div>';
        
        
        const html = `
            <div class="result-card">
                <div class="result-header">
                    <h3><i class="fas fa-truck"></i> ${escapeHtml(shipment.trip_number)}</h3>
                    <div>
                        <span class="status-badge" style="background: ${statusColors[currentStatus]}20; color: ${statusColors[currentStatus]}; border: 1px solid ${statusColors[currentStatus]}">
                            ${statusNames[currentStatus]}
                        </span>
                        <span class="readonly-badge" style="margin-left: 10px;"><i class="fas fa-eye"></i> Aragti Keliya</span>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-row"><div class="info-label">Lambarka Safarka:</div><div class="info-value"><strong>${escapeHtml(shipment.trip_number)}</strong></div></div>
                        <div class="info-row"><div class="info-label">Kontaynerka:</div><div class="info-value">${escapeHtml(shipment.container_number || '-')}</div></div>
                        <div class="info-row"><div class="info-label">Darawalka:</div><div class="info-value">${escapeHtml(shipment.driver_name || '-')}</div></div>
                        <div class="info-row"><div class="info-label">Raraha:</div><div class="info-value">${escapeHtml(shipment.loader_name || '-')}</div></div>
                        <div class="info-row"><div class="info-label">Gaadhiga:</div><div class="info-value">${escapeHtml(shipment.truck_number || '-')}</div></div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-row"><div class="info-label">Goobta Qaadista:</div><div class="info-value">${escapeHtml(shipment.pickup_location || '-')}</div></div>
                        <div class="info-row"><div class="info-label">Goobta Dhiibista:</div><div class="info-value">${escapeHtml(shipment.dropoff_location || '-')}</div></div>
                        <div class="info-row"><div class="info-label">Shirkadda:</div><div class="info-value">${escapeHtml(shipment.tenant_name || '-')}</div></div>
                        <div class="info-row"><div class="info-label">CBM:</div><div class="info-value">${shipment.total_cbm} CBM</div></div>
                        <div class="info-row"><div class="info-label">Culmis:</div><div class="info-value">${shipment.total_weight_kg} kg</div></div>
                    </div>
                </div>
                
                <!-- Financials section removed due to missing DB columns -->
                
                ${shipment.notes ? `<div class="info-row mt-3"><div class="info-label">Qoraal:</div><div class="info-value">${escapeHtml(shipment.notes)}</div></div>` : ''}
                
                <div class="mt-4">
                    <h6><i class="fas fa-chart-line"></i> Socodka Safarka</h6>
                    ${timelineHtml}
                </div>
                
                <div class="mt-3 text-center">
                    <small class="text-muted"><i class="fas fa-info-circle"></i> Xogtan waa aragti keliya. Wax ka beddel ayaa laga sameyn karaa bogga maareynta.</small>
                </div>
            </div>
        `;
        $('#trackResult').html(html);
    }
    
    function showAlert(type, message) {
        const alertHtml = `<div class="alert alert-${type} alert-dismissible fade show">${message}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`;
        $('#alert-placeholder').html(alertHtml);
        setTimeout(() => $('.alert').fadeOut(3000, function() { $(this).remove(); }), 3000);
    }
    
    // Event listeners
    $('#trackBtn').click(trackItem);
    $('#trackingNumber').keypress(function(e) {
        if (e.which === 13) trackItem();
    });
    
    // Initial load
    loadStats();
    loadRecentActivity();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>