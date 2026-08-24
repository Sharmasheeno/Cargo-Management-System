<?php
require_once __DIR__ . '/config/db_connect.php';
try {
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN customer_id INT NULL AFTER tenant_id");
    echo "warehouse_stock altered successfully.\n";
} catch (Exception $e) {
    echo "warehouse_stock error: " . $e->getMessage() . "\n";
}
try {
    // Also add to packages table if it exists
    $pdo->exec("ALTER TABLE packages ADD COLUMN customer_id INT NULL AFTER tenant_id");
    echo "packages altered successfully.\n";
} catch (Exception $e) {
    echo "packages error: " . $e->getMessage() . "\n";
}
