<?php
/**
 * audit_helper.php
 * Enhanced security logging forfaras cargo
 */

function LogAudit($pdo, $action, $table, $record_id = null, $old_values = null, $new_values = null) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    
    $tenant_id = $_SESSION['tenant_id'] ?? null;
    $user_id = $_SESSION['user_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $stmt = $pdo->prepare("INSERT INTO audit_logs 
        (tenant_id, user_id, action, table_name, record_id, old_values, new_values, ip_address) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    return $stmt->execute([
        $tenant_id,
        $user_id,
        $action,
        $table,
        $record_id,
        $old_values ? json_encode($old_values) : null,
        $new_values ? json_encode($new_values) : null,
        $ip
    ]);
}