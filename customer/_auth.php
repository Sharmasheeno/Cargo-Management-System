<?php
// Shared customer portal authentication.
// Customer logins in this system are users.role='staff' with
// users.role_type='customer', linked to customers.user_id.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role_type'] ?? '') !== 'customer') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

$user_id = (int)$_SESSION['user_id'];
$session_tenant_id = (int)($_SESSION['tenant_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT c.*, t.name AS tenant_name, t.logo_url, t.address AS tenant_address,
           t.phone AS tenant_phone, t.email AS tenant_email
    FROM customers c
    LEFT JOIN tenants t ON t.id = c.tenant_id
    WHERE c.user_id = ?
      AND (? = 0 OR c.tenant_id = ?)
      AND c.is_active = 1
    LIMIT 1
");
$stmt->execute([$user_id, $session_tenant_id, $session_tenant_id]);
$customer_auth = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer_auth) {
    http_response_code(403);
    echo "Customer account not found or not authorized.";
    exit;
}

$_SESSION['customer_id'] = (int)$customer_auth['id'];
$_SESSION['tenant_id'] = (int)$customer_auth['tenant_id'];
$_SESSION['role_type'] = 'customer';

$customer_id = (int)$customer_auth['id'];
$session_customer_id = $customer_id;
$session_tenant_id = (int)$customer_auth['tenant_id'];
$role = 'customer';
$user_name = $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? $customer_auth['customer_name'] ?? 'Customer';
