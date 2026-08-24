<?php
require 'config/db_connect.php';
try {
    print_r($pdo->query('DESCRIBE warehouse_stock')->fetchAll(PDO::FETCH_ASSOC));
    print_r($pdo->query('DESCRIBE cargo_manifest_items')->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
?>