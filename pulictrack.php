<?php
// pulictrack.php (Public Shipment Tracking)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/db_connect.php';

// Get tracking ID from URL
$tracking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tracking_number = isset($_GET['tracking']) ? trim($_GET['tracking']) : '';
$error = null;
$shipment = null;

// Get shipment by ID or tracking number
if ($tracking_id > 0) {
    $stmt = $pdo->prepare("
        SELECT t.*, 
               c.container_number, c.container_type, c.origin, c.size_cbm, c.weight_kg, c.seal_number,
               c.status as container_status, c.current_location,
               c.estimated_arrival as eta, c.arrival_date, c.departure_date,
               c.shipping_line, c.vessel_name, c.voyage_number, c.bl_number,
               c.port_of_loading, c.port_of_discharge, c.customs_status,
               tn.name as tenant_name, tn.phone as tenant_phone, tn.email as tenant_email,
               tn.address as tenant_address
        FROM trucking_trips t
        LEFT JOIN containers c ON t.container_id = c.id
        LEFT JOIN tenants tn ON t.tenant_id = tn.id
        WHERE t.id = ?
    ");
    $stmt->execute([$tracking_id]);
    $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif (!empty($tracking_number)) {
    $stmt = $pdo->prepare("
        SELECT t.*, 
               c.container_number, c.container_type, c.origin, c.size_cbm, c.weight_kg, c.seal_number,
               c.status as container_status, c.current_location,
               c.estimated_arrival as eta, c.arrival_date, c.departure_date,
               c.shipping_line, c.vessel_name, c.voyage_number, c.bl_number,
               c.port_of_loading, c.port_of_discharge, c.customs_status,
               tn.name as tenant_name, tn.phone as tenant_phone, tn.email as tenant_email,
               tn.address as tenant_address
        FROM trucking_trips t
        LEFT JOIN containers c ON t.container_id = c.id
        LEFT JOIN tenants tn ON t.tenant_id = tn.id
        WHERE t.trip_number = ? OR c.container_number = ?
    ");
    $stmt->execute([$tracking_number, $tracking_number]);
    $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
}

// If no shipment found
if (!$shipment) {
    $error = "Safarka ama Lambarka Raadraaca lama helin. Fadlan hubi lambarka oo isku day mar kale.";
}

// Get cargo manifest items for this shipment
$cargo_items = [];
if ($shipment) {
    try {
        $stmt = $pdo->prepare("
            SELECT cmi.*, ws.stock_name as ws_stock_name, ws.unit_price as ws_unit_price
            FROM cargo_manifest_items cmi
            LEFT JOIN warehouse_stock ws ON cmi.warehouse_stock_id = ws.id
            WHERE cmi.shipment_id = ?
            ORDER BY cmi.added_at DESC
        ");
        $stmt->execute([$shipment['id']]);
        $cargo_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $cargo_items = [];
    }
}

// Status names and icons
$status_info = [
    'pending' => ['name' => 'La Sugayo', 'icon' => 'fa-hourglass-half', 'color' => '#6c757d', 'progress' => 0],
    'received' => ['name' => 'La Helay', 'icon' => 'fa-inbox', 'color' => '#17a2b8', 'progress' => 10],
    'loading' => ['name' => 'Raraynta', 'icon' => 'fa-truck-loading', 'color' => '#ffc107', 'progress' => 30],
    'loaded' => ['name' => 'La Raray', 'icon' => 'fa-check-circle', 'color' => '#28a745', 'progress' => 50],
    'in_transit' => ['name' => 'Jidka', 'icon' => 'fa-truck', 'color' => '#fd7e14', 'progress' => 70],
    'delivered' => ['name' => 'La Gaarsiiyay', 'icon' => 'fa-flag-checkered', 'color' => '#20c997', 'progress' => 100]
];

// Get current status progress
$current_status = $shipment['status'] ?? 'pending';
$current_progress = $status_info[$current_status]['progress'] ?? 0;
$status_keys = array_keys($status_info);
$current_index = array_search($current_status, $status_keys);

// Origin names mapping
$origin_names = [
    'china_yiwu' => 'Shiinaha (Yiwu) 🇨🇳',
    'china_guangzhou' => 'Shiinaha (Guangzhou) 🇨🇳',
    'dubai' => 'Dubay 🇦🇪',
    'local' => 'Maxalli 🇸🇴'
];

// Function to format date
function formatDate($date) {
    if (!$date || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return '-';
    return date('d/m/Y H:i', strtotime($date));
}

// Function to format date only
function formatDateOnly($date) {
    if (!$date || $date == '0000-00-00') return '-';
    return date('d/m/Y', strtotime($date));
}

// Function to get status badge
function getStatusBadge($status) {
    $status_info_local = [
        'pending' => ['name' => 'La Sugayo', 'color' => '#6c757d'],
        'received' => ['name' => 'La Helay', 'color' => '#17a2b8'],
        'loading' => ['name' => 'Raraynta', 'color' => '#ffc107'],
        'loaded' => ['name' => 'La Raray', 'color' => '#28a745'],
        'in_transit' => ['name' => 'Jidka', 'color' => '#fd7e14'],
        'delivered' => ['name' => 'La Gaarsiiyay', 'color' => '#20c997']
    ];
    $info = $status_info_local[$status] ?? ['name' => $status, 'color' => '#6c757d'];
    return '<span style="background: ' . $info['color'] . '20; color: ' . $info['color'] . '; padding: 6px 16px; border-radius: 30px; font-size: 13px; font-weight: 600;">' . $info['name'] . '</span>';
}

// Get customs status badge
function getCustomsBadge($status) {
    if ($status == 'cleared') {
        return '<span style="background: #0F7A3A20; color: #0F7A3A; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600;"><i class="fas fa-check-circle"></i> Cleared</span>';
    } elseif ($status == 'held') {
        return '<span style="background: #B4231820; color: #B42318; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600;"><i class="fas fa-exclamation-triangle"></i> Held</span>';
    }
    return '<span style="background: #ffc10720; color: #ffc107; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600;"><i class="fas fa-clock"></i> Pending</span>';
}
?>
<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raadraaca Safarka - Cargo Management System</title>
    <link rel="icon" type="image/png" href="assets/images/curdun-favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root {
            --curdun-violet: #2D1859;
            --curdun-yellow: #F5C410;
            --curdun-violet-light: #4B2C85;
            --curdun-yellow-dark: #D4A70C;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #F4F5F9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }
        
        .tracking-header {
            background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light));
            padding: 40px 20px;
            text-align: center;
            color: white;
        }
        .tracking-header h1 { font-size: 32px; margin: 0; }
        .tracking-header h1 i { margin-right: 12px; }
        .tracking-header p { opacity: 0.9; margin-top: 10px; font-size: 14px; }
        
        .search-card {
            background: white;
            border-radius: 20px;
            padding: 25px 30px;
            margin: -30px 20px 30px 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .search-form {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .search-form input {
            flex: 1;
            padding: 14px 24px;
            border: 2px solid #e0e0e0;
            border-radius: 50px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        .search-form input:focus {
            outline: none;
            border-color: var(--curdun-violet);
            box-shadow: 0 0 0 3px rgba(82,0,102,0.1);
        }
        .search-form button {
            background: var(--curdun-violet);
            color: white;
            border: none;
            padding: 14px 35px;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .search-form button:hover {
            background: var(--curdun-violet-light);
            transform: translateY(-2px);
        }
        
        .tracking-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px 40px;
        }
        
        .shipment-card {
            background: white;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .shipment-header {
            background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light));
            color: white;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .shipment-header h2 { margin: 0; font-size: 22px; }
        .shipment-header h2 i { margin-right: 10px; }
        
        .progress-section {
            padding: 30px;
            background: #f8f9fa;
            border-bottom: 1px solid #eee;
        }
        .progress-track {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin: 20px 0;
        }
        .progress-track::before {
            content: '';
            position: absolute;
            top: 28px;
            left: 0;
            right: 0;
            height: 4px;
            background: #e0e0e0;
            border-radius: 2px;
            z-index: 1;
        }
        .progress-step {
            text-align: center;
            flex: 1;
            position: relative;
            z-index: 2;
        }
        .progress-step .step-icon {
            width: 56px;
            height: 56px;
            background: white;
            border: 3px solid #e0e0e0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            transition: all 0.3s ease;
        }
        .progress-step .step-icon i { font-size: 22px; color: #999; }
        .progress-step .step-label { font-size: 11px; font-weight: 600; color: #999; }
        .progress-step.active .step-icon {
            border-color: var(--curdun-violet);
            background: var(--curdun-violet);
            transform: scale(1.05);
        }
        .progress-step.active .step-icon i { color: white; }
        .progress-step.active .step-label { color: var(--curdun-violet); }
        .progress-step.completed .step-icon {
            border-color: #0F7A3A;
            background: #0F7A3A;
        }
        .progress-step.completed .step-icon i { color: white; }
        .progress-step.completed .step-label { color: #0F7A3A; }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
            padding: 30px;
        }
        .info-card {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 20px;
            transition: transform 0.2s;
        }
        .info-card:hover { transform: translateY(-2px); }
        .info-card h4 {
            color: var(--curdun-violet);
            font-size: 14px;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--curdun-yellow);
            display: inline-block;
        }
        .info-card p { margin: 10px 0; font-size: 14px; }
        .info-card strong { color: #555; min-width: 120px; display: inline-block; }
        
        .cargo-table {
            margin: 0 30px 30px;
            overflow-x: auto;
        }
        .cargo-table table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .cargo-table th, .cargo-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .cargo-table th {
            background: #f8f6f9;
            font-weight: 600;
            color: var(--curdun-dark);
        }
        
        .timeline {
            padding: 0 30px 30px;
        }
        .timeline h4 { margin-bottom: 20px; color: var(--curdun-violet); }
        .timeline-item {
            display: flex;
            gap: 15px;
            padding: 15px 0;
            border-left: 2px solid #e0e0e0;
            margin-left: 30px;
            position: relative;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -9px;
            top: 22px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--curdun-violet);
            border: 3px solid white;
            box-shadow: 0 0 0 2px var(--curdun-violet);
        }
        .timeline-icon {
            width: 40px;
            height: 40px;
            background: #f0f0f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-left: -20px;
        }
        .timeline-content { flex: 1; }
        .timeline-date { font-size: 11px; color: #999; margin-top: 4px; }
        
        .alert-error {
            background: #FEF0EE;
            color: #B42318;
            border-left: 4px solid #B42318;
            padding: 25px;
            border-radius: 16px;
            text-align: center;
        }
        
        .footer {
            text-align: center;
            padding: 30px;
            color: #666;
            background: white;
            margin-top: 30px;
            border-top: 1px solid #eee;
        }
        
        @media (max-width: 768px) {
            .progress-track { flex-wrap: wrap; gap: 20px; }
            .progress-track::before { display: none; }
            .progress-step { min-width: 80px; }
            .info-grid { grid-template-columns: 1fr; padding: 20px; }
            .shipment-header { flex-direction: column; text-align: center; }
            .cargo-table { margin: 0 20px 20px; }
        }
    </style>
</head>
<body>

<div class="tracking-header">
    <h1><i class="fas fa-map-marked-alt"></i> Raadraaca Safarka</h1>
    <p>Ku raadraac safarkaaga adigoo isticmaalaya Lambarka Safarka ama Lambarka Kontaynerka</p>
</div>

<div class="tracking-container">
    <!-- Search Form -->
    <div class="search-card">
        <form method="GET" class="search-form">
            <input type="text" name="tracking" placeholder="Geli Lambarka Safarka ama Lambarka Kontaynerka (tusaale: TRP-001 ama MSKU1234567)" value="<?= htmlspecialchars($tracking_number) ?>">
            <button type="submit"><i class="fas fa-search"></i> Raadi</button>
        </form>
    </div>

    <?php if ($error): ?>
        <div class="alert-error">
            <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>
            <p style="font-size: 18px; font-weight: 600;"><?= htmlspecialchars($error) ?></p>
            <p class="mt-3">Fadlan hubi lambarka oo isku day mar kale.<br>
            Tusaale: TRP-001 ama MSKU1234567</p>
        </div>
    <?php elseif ($shipment): ?>
        <!-- Shipment Details -->
        <div class="shipment-card">
            <div class="shipment-header">
                <h2><i class="fas fa-truck"></i> <?= htmlspecialchars($shipment['trip_number']) ?></h2>
                <?= getStatusBadge($shipment['status']) ?>
            </div>
            
            <!-- Progress Tracker -->
            <div class="progress-section">
                <div class="progress-track">
                    <?php foreach ($status_info as $key => $info): 
                        $is_completed = array_search($key, $status_keys) <= $current_index;
                        $is_active = $key == $current_status;
                    ?>
                        <div class="progress-step <?= $is_completed ? 'completed' : '' ?> <?= $is_active ? 'active' : '' ?>">
                            <div class="step-icon">
                                <i class="fas <?= $info['icon'] ?>"></i>
                            </div>
                            <div class="step-label"><?= $info['name'] ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Information Grid -->
          
            <!-- Cargo Items Table -->
            <?php if (!empty($cargo_items)): ?>
            <div class="cargo-table">
                <h4 style="margin-bottom: 15px; color: var(--curdun-violet); padding: 0 0 0 20px;">
                    <i class="fas fa-boxes"></i> Alaabta Safarka Ku Jirta
                </h4>
                <table>
                    <thead>
                        <tr>
                            <th>Magaca Alaabta</th>
                            <th>Tirada</th>
                            <th>CBM</th>
                            <th>Qiimaha/Unit</th>
                            <th>Wadarta</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cargo_items as $item): 
                            $total = $item['cbm_used'] * $item['unit_price'];
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($item['stock_name'] ?? $item['ws_stock_name'] ?? '-') ?></strong></td>
                            <td><?= number_format($item['quantity']) ?> x1on</td>
                            <td><?= number_format($item['cbm_used'], 4) ?> CBM</td>
                            <td>$<?= number_format($item['unit_price'] ?? $item['ws_unit_price'] ?? 0, 2) ?></td>
                            <td>$<?= number_format($total, 2) ?></td>
                        </table>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Timeline / Timestamps -->
            <?php if ($shipment['created_at'] || $shipment['loaded_at'] || $shipment['delivered_at']): ?>
            <div class="timeline">
                <h4><i class="fas fa-clock"></i> Waqtiyada Muhiimka ah</h4>
                
                <?php if ($shipment['created_at']): ?>
                <div class="timeline-item">
                    <div class="timeline-icon"><i class="fas fa-plus-circle"></i></div>
                    <div class="timeline-content">
                        <strong>Safar la sameeyay</strong>
                        <div class="timeline-date"><?= formatDate($shipment['created_at']) ?></div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($shipment['loaded_at']): ?>
                <div class="timeline-item">
                    <div class="timeline-icon"><i class="fas fa-truck-loading"></i></div>
                    <div class="timeline-content">
                        <strong>Safar la raray (Loaded)</strong>
                        <div class="timeline-date"><?= formatDate($shipment['loaded_at']) ?></div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($shipment['delivered_at']): ?>
                <div class="timeline-item">
                    <div class="timeline-icon"><i class="fas fa-flag-checkered"></i></div>
                    <div class="timeline-content">
                        <strong>Safar la gaarsiiyay (Delivered)</strong>
                        <div class="timeline-date"><?= formatDate($shipment['delivered_at']) ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- QR Code for Sharing -->
        <div style="text-align: center; margin-top: 20px;">
            <div id="qrCodeContainer" style="display: inline-block; background: white; padding: 15px; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);"></div>
            <p style="margin-top: 12px; font-size: 12px; color: #666;">
                <i class="fas fa-qrcode"></i> Scan QR code si aad u hesho xogta safarka
            </p>
        </div>
    <?php endif; ?>
</div>

<div class="footer">
    <p>&copy; <?= date('Y') ?> Cargo Management System - Smart Logistics & Cargo Solutions</p>
    <p style="font-size: 12px; opacity: 0.7;">Haddii aad qabto su'aal, nagala soo xiriir: <a href="mailto:info@curduncargo.com" style="color: #2D1859;">info@curduncargo.com</a></p>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
<?php if ($shipment && !$error): ?>
// Generate QR Code for this shipment
const currentUrl = window.location.href;
const qrContainer = document.getElementById('qrCodeContainer');
if (qrContainer && typeof QRCode !== 'undefined') {
    new QRCode(qrContainer, {
        text: currentUrl,
        width: 140,
        height: 140,
        colorDark: '#2D1859',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.H
    });
}
<?php endif; ?>
</script>

</body>
</html>