<?php
require_once "db.php";

$tenant_id = $_GET["tenant_id"] ?? null;

$where = "";
$params = [];

if ($tenant_id) {
    $where = "AND p.tenant_id = ?";
    $params[] = $tenant_id;
}

$stmt = $pdo->prepare("
    SELECT 
        p.id,
        p.payment_number,
        p.amount,
        p.payment_date,
        c.customer_name,
        c.phone,
        t.name AS tenant_name,
        COALESCE(t.loyalty_amount_points, 5) AS loyalty_amount_points,
        ROUND((p.amount / 100) * COALESCE(t.loyalty_amount_points, 5), 2) AS expected_points
    FROM payments p
    LEFT JOIN customers c ON c.id = p.customer_id
    LEFT JOIN tenants t ON t.id = p.tenant_id
    LEFT JOIN loyalty_points_log l
        ON l.reference_type = 'payment'
        AND l.reference_id = p.id
        AND l.points_earned > 0
    WHERE p.customer_id IS NOT NULL
    AND p.customer_id > 0
    AND l.id IS NULL
    $where
    ORDER BY p.created_at DESC
");
$stmt->execute($params);

response(true, "Pending loyalty payments loaded", $stmt->fetchAll());