<?php
require_once __DIR__ . '/config/db_connect.php';

// containers.status column
echo "=== containers.status column ===\n";
foreach ($pdo->query('DESCRIBE containers')->fetchAll(PDO::FETCH_ASSOC) as $c)
    if ($c['Field'] === 'status') echo json_encode($c) . "\n";

// CMI for container 3
echo "\n=== CMI for container_id=3 ===\n";
$rows = $pdo->query('SELECT cmi.id, cmi.warehouse_stock_id, cmi.stock_name, cmi.quantity, cmi.cbm_used, ws.customer_id FROM cargo_manifest_items cmi LEFT JOIN warehouse_stock ws ON ws.id=cmi.warehouse_stock_id WHERE cmi.container_id=3')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) echo json_encode($r) . "\n";

// in_warehouse items
echo "\n=== in_warehouse items ===\n";
$rows = $pdo->query("SELECT ws.id, ws.stock_name, ws.quantity, ws.volume_cbm, ws.mogadishu_status, ws.mogadishu_received_date, ws.customer_id, ws.tenant_id FROM warehouse_stock ws WHERE ws.mogadishu_status='in_warehouse'")->fetchAll(PDO::FETCH_ASSOC);
echo count($rows) . " rows\n";
foreach ($rows as $r) echo json_encode($r) . "\n";
