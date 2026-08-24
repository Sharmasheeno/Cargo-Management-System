<?php
// includes/set_tenant.php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tenant_id'])) {
    $tenant_id = $_POST['tenant_id'];
    
    if ($tenant_id === 'all') {
        unset($_SESSION['selected_tenant_id']);
    } else {
        $_SESSION['selected_tenant_id'] = (int)$tenant_id;
    }
    
    echo json_encode(['success' => true]);
}
?>
