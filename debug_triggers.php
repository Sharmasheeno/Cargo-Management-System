<?php
require_once 'config/db_connect.php';

echo "Checking triggers...\n";
$stmt = $pdo->query("SHOW TRIGGERS");
$triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($triggers as $t) {
    echo "Trigger: {$t['Trigger']}, Event: {$t['Event']}, Table: {$t['Table']}, Statement: {$t['Statement']}\n";
}
?>
