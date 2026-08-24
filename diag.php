<?php
require_once __DIR__ . '/config/db_connect.php';

// 1. cargo_manifest_items columns
echo "=== cargo_manifest_items columns ===\n";
try {
    $r = $pdo->query('DESCRIBE cargo_manifest_items');
    foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $c)
        echo $c['Field'] . ' (' . $c['Type'] . ") default:" . $c['Default'] . "\n";
} catch (Exception $e) { echo 'ERROR: ' . $e->getMessage() . "\n"; }

// 2. CMI row count
try {
    echo 'CMI total rows: ' . $pdo->query('SELECT COUNT(*) FROM cargo_manifest_items')->fetchColumn() . "\n";
} catch (Exception $e) { echo 'CMI count error: ' . $e->getMessage() . "\n"; }

// 3. warehouse_stock columns
echo "\n=== warehouse_stock columns ===\n";
try {
    $r = $pdo->query('DESCRIBE warehouse_stock');
    foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $c)
        echo $c['Field'] . ' (' . $c['Type'] . ") default:" . $c['Default'] . "\n";
} catch (Exception $e) { echo 'ERROR: ' . $e->getMessage() . "\n"; }

// 4. Last 5 containers
echo "\n=== containers (last 5) ===\n";
try {
    $rows = $pdo->query('SELECT id, container_number, status FROM containers ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $c) echo "id={$c['id']} num={$c['container_number']} status={$c['status']}\n";
} catch (Exception $e) { echo 'ERROR: ' . $e->getMessage() . "\n"; }

// 5. Sample CMI rows
echo "\n=== CMI sample (first 5 rows) ===\n";
try {
    $rows = $pdo->query('SELECT * FROM cargo_manifest_items LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) echo "(no rows)\n";
    foreach ($rows as $r) echo json_encode($r) . "\n";
} catch (Exception $e) { echo 'ERROR: ' . $e->getMessage() . "\n"; }

// 6. warehouse_stock with in_warehouse status
echo "\n=== warehouse_stock in_warehouse sample ===\n";
try {
    $rows = $pdo->query("SELECT id, stock_name, quantity, volume_cbm, mogadishu_status, customer_id, tenant_id FROM warehouse_stock WHERE mogadishu_status='in_warehouse' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) echo "(no in_warehouse rows)\n";
    foreach ($rows as $r) echo json_encode($r) . "\n";
} catch (Exception $e) { echo 'ERROR: ' . $e->getMessage() . "\n"; }
