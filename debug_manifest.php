<?php
require_once 'config/db_connect.php';

echo "Checking cargo_manifest_items...\n";
$stmt = $pdo->query("SELECT * FROM cargo_manifest_items ORDER BY id DESC LIMIT 10");
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($items as $item) {
    echo "ID: {$item['id']}, Container: {$item['container_id']}, Stock: {$item['warehouse_stock_id']}, Name: {$item['stock_name']}, Qty: {$item['quantity']}, Added: {$item['added_at']}\n";
}
?>
