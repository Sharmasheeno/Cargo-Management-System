<?php
require_once __DIR__ . '/config/db_connect.php';

$container_no = isset($_GET['container_no']) ? trim($_GET['container_no']) : '';

if (empty($container_no)) {
    echo "<p style='color:var(--curdun-danger);font-weight:600;'>Fadlan geli lambarka kontaynerka!</p>";
    exit;
}

$stmt = $pdo->prepare("
    SELECT t.*, 
           c.container_number, c.origin, c.size_cbm, c.weight_kg, c.seal_number,
           tn.name as tenant_name
    FROM trucking_trips t
    LEFT JOIN containers c ON t.container_id = c.id
    LEFT JOIN tenants tn ON t.tenant_id = tn.id
    WHERE t.trip_number = ? OR c.container_number = ?
");
$stmt->execute([$container_no, $container_no]);
$shipment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shipment) {
    echo "<div style='background: #FEF0EE; color: #B42318; padding: 15px; border-radius: 12px; text-align: center; margin-top: 15px;'>
            <i class='fas fa-exclamation-triangle' style='font-size: 24px; margin-bottom: 10px;'></i>
            <p style='margin:0;font-weight:600;'>Lama helin Kontayner/Safar leh lambarkaas.</p>
          </div>";
    exit;
}

$status_info = [
    'received' => ['name' => 'La Helay', 'icon' => 'fa-inbox', 'color' => '#17a2b8'],
    'loaded' => ['name' => 'La Raray', 'icon' => 'fa-truck-loading', 'color' => '#ffc107'],
    'dispatched' => ['name' => 'La Diray', 'icon' => 'fa-paper-plane', 'color' => '#fd7e14'],
    'at_port' => ['name' => 'Dekedda', 'icon' => 'fa-ship', 'color' => '#6f42c1'],
    'ready' => ['name' => 'Diyaar', 'icon' => 'fa-check-circle', 'color' => '#28a745'],
    'delivered' => ['name' => 'La Gaarsiiyay', 'icon' => 'fa-flag-checkered', 'color' => '#20c997']
];

$status = $shipment['status'] ?? 'received';
$info = $status_info[$status] ?? ['name' => $status, 'icon' => 'fa-box', 'color' => '#6c757d'];

?>
<div style='background: #f8f9fa; border-radius: 16px; padding: 20px; text-align: left; margin-top: 15px; border-left: 5px solid <?= $info['color'] ?>'>
    <div style='display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #ddd; padding-bottom:10px; margin-bottom:10px;'>
        <h4 style='margin:0; color:var(--curdun-violet); font-size: 16px;'>
            <i class='fas fa-truck'></i> <?= htmlspecialchars($shipment['trip_number']) ?>
        </h4>
        <span style='background: <?= $info['color'] ?>20; color: <?= $info['color'] ?>; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: bold;'>
            <i class='fas <?= $info['icon'] ?>'></i> <?= $info['name'] ?>
        </span>
    </div>
    
    <div style='font-size: 13px; color: #444;'>
        <p style='margin:5px 0;'><strong>Kontayner:</strong> <?= htmlspecialchars($shipment['container_number'] ?? '-') ?></p>
        <?php 
        $origin_names = [
            'china_yiwu' => 'Shiinaha (Yiwu) 🇨🇳',
            'china_guangzhou' => 'Shiinaha (Guangzhou) 🇨🇳',
            'dubai' => 'Dubay 🇦🇪'
        ];
        $origin_display = $origin_names[$shipment['origin']] ?? $shipment['origin'];
        ?>
        <p style='margin:5px 0;'><strong>Asalka:</strong> <?= htmlspecialchars($origin_display ?? '-') ?></p>
        <p style='margin:5px 0;'><strong>CBM/Miisaan:</strong> <?= number_format($shipment['size_cbm'] ?? $shipment['total_cbm'] ?? 0, 2) ?> CBM / <?= number_format($shipment['weight_kg'] ?? $shipment['total_weight_kg'] ?? 0, 0) ?> kg</p>
        <p style='margin:5px 0;'><strong>Shirkadda:</strong> <?= htmlspecialchars($shipment['tenant_name'] ?? '-') ?></p>
    </div>
    
    <div style='margin-top: 15px; text-align: center;'>
        <a href='pulictrack.php?tracking=<?= urlencode($container_no) ?>' target='_blank' style='display:inline-block; background: var(--curdun-violet); color: white; padding: 8px 15px; border-radius: 8px; text-decoration: none; font-size: 13px; font-weight: 600;'>
            Faahfaahin Dheeraad ah
        </a>
    </div>
</div>
