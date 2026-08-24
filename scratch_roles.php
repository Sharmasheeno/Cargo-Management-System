<?php
require_once __DIR__ . '/config/db_connect.php';
$stmt = $pdo->query("SELECT DISTINCT role_type FROM users");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['role_type'] . "\n";
}
