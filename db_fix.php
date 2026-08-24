<?php
require_once 'config/db_connect.php';

echo "<h2>Dayactirka Database-ka (Database Repair)</h2>";

try {
    // 1. Hagaajinta Warehouse Stock
    $pdo->exec("ALTER TABLE `warehouse_stock` MODIFY COLUMN `origin` VARCHAR(100) DEFAULT 'local'");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS `warehouse_stock` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `tenant_id` int(11) DEFAULT NULL,
        `customer_id` int(11) DEFAULT NULL,
        `origin` varchar(100) DEFAULT 'local',
        `stock_name` varchar(255) NOT NULL,
        `quantity` int(11) DEFAULT 0,
        `length_cm` decimal(10,2) DEFAULT 0.00,
        `width_cm` decimal(10,2) DEFAULT 0.00,
        `height_cm` decimal(10,2) DEFAULT 0.00,
        `volume_cbm` decimal(10,4) DEFAULT 0.0000,
        `location` varchar(100) DEFAULT NULL,
        `bin_location` varchar(50) DEFAULT NULL,
        `zone` varchar(50) DEFAULT NULL,
        `minimum_stock` int(11) DEFAULT 0,
        `maximum_stock` int(11) DEFAULT 0,
        `unit_price` decimal(15,2) DEFAULT 0.00,
        `updated_by` int(11) DEFAULT NULL,
        `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✅ Table-ka 'warehouse_stock' waa diyaar.<br>";

    // Khaanadaha maqan haddii table-ku horay u jiray
    $columns = [
        'customer_id' => "INT(11) NULL AFTER `tenant_id` ",
        'length_cm' => "DECIMAL(10,2) DEFAULT 0.00 AFTER `quantity` ",
        'width_cm' => "DECIMAL(10,2) DEFAULT 0.00 AFTER `length_cm` ",
        'height_cm' => "DECIMAL(10,2) DEFAULT 0.00 AFTER `width_cm` "
    ];
    foreach ($columns as $col => $def) {
        $check = $pdo->query("SHOW COLUMNS FROM `warehouse_stock` LIKE '$col'");
        if (!$check->fetch()) {
            $pdo->exec("ALTER TABLE `warehouse_stock` ADD COLUMN $col $def");
            echo "✅ Khaanadda '$col' waa lagu daray warehouse_stock.<br>";
        }
    }

    // 2. Abuurista Stock Movements
    $pdo->exec("CREATE TABLE IF NOT EXISTS `stock_movements` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `tenant_id` int(11) DEFAULT NULL,
        `warehouse_stock_id` int(11) NOT NULL,
        `quantity_change` int(11) NOT NULL,
        `previous_quantity` int(11) NOT NULL,
        `new_quantity` int(11) NOT NULL,
        `movement_type` enum('in','out','adjust','move') NOT NULL,
        `reference_type` varchar(50) DEFAULT NULL,
        `reference_id` int(11) DEFAULT NULL,
        `notes` text DEFAULT NULL,
        `created_by` int(11) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✅ Table-ka 'stock_movements' waa diyaar.<br>";

    // 3. Abuurista Cargo Manifest Items
    $pdo->exec("CREATE TABLE IF NOT EXISTS `cargo_manifest_items` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `tenant_id` int(11) NOT NULL,
        `container_id` int(11) NOT NULL,
        `shipment_id` int(11) DEFAULT NULL,
        `warehouse_stock_id` int(11) DEFAULT NULL,
        `stock_name` varchar(255) NOT NULL,
        `quantity` int(11) NOT NULL,
        `cbm_used` decimal(10,4) NOT NULL,
        `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✅ Table-ka 'cargo_manifest_items' waa diyaar.<br>";

    // 4. Abuurista Bank Reconciliations
    $pdo->exec("CREATE TABLE IF NOT EXISTS `bank_reconciliations` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `tenant_id` int(11) DEFAULT NULL,
        `bank_account_id` int(11) NOT NULL,
        `statement_date` date NOT NULL,
        `statement_balance` decimal(15,2) NOT NULL,
        `system_balance` decimal(15,2) NOT NULL,
        `difference` decimal(15,2) NOT NULL,
        `status` varchar(50) DEFAULT 'pending',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✅ Table-ka 'bank_reconciliations' waa diyaar.<br>";

    echo "<br><h3 style='color: green;'>Guul: Database-ka waa la hagaajiyey si dhammaystiran!</h3>";
    echo "<p><a href='superadmin/warehouse_stock.php'>Ku laabo Bakhaarka</a></p>";

} catch (PDOException $e) {
    echo "<h3 style='color: red;'>Khalad ayaa dhacay: " . $e->getMessage() . "</h3>";
}
?>
