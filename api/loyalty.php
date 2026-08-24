<?php
require_once "db.php";

$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {
    $tenant_id = $_GET["tenant_id"] ?? null;

    $where = "";
    $params = [];

    if ($tenant_id) {
        $where = "WHERE c.tenant_id = ?";
        $params[] = $tenant_id;
    }

    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.customer_name,
            c.phone,
            c.loyalty_points,
            c.debt_amount,
            t.name AS tenant_name
        FROM customers c
        LEFT JOIN tenants t ON t.id = c.tenant_id
        $where
        ORDER BY c.loyalty_points DESC
    ");
    $stmt->execute($params);

    response(true, "Customer loyalty list loaded", $stmt->fetchAll());
}

if ($method === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);

    $payment_id = (int)($input["payment_id"] ?? 0);
    $created_by = (int)($input["created_by"] ?? 0);

    if ($payment_id <= 0) {
        response(false, "payment_id required");
    }

    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.tenant_id,
            p.customer_id,
            p.amount,
            p.payment_number,
            COALESCE(t.loyalty_amount_points, 5) AS loyalty_amount_points
        FROM payments p
        LEFT JOIN tenants t ON t.id = p.tenant_id
        WHERE p.id = ?
        LIMIT 1
    ");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch();

    if (!$payment) {
        response(false, "Payment lama helin");
    }

    if (empty($payment["customer_id"])) {
        response(false, "Payment-kan customer kuma xirna");
    }

    $check = $pdo->prepare("
        SELECT id FROM loyalty_points_log
        WHERE reference_type = 'payment'
        AND reference_id = ?
        AND points_earned > 0
        LIMIT 1
    ");
    $check->execute([$payment_id]);

    if ($check->fetch()) {
        response(false, "Payment-kan hore ayaa points loogu daray");
    }

    $amount = (float)$payment["amount"];
    $rate = (float)$payment["loyalty_amount_points"];
    $points = round(($amount / 100) * $rate, 2);

    if ($points <= 0) {
        response(false, "Payment-kan points ma dhalin");
    }

    try {
        $pdo->beginTransaction();

        $update = $pdo->prepare("
            UPDATE customers
            SET loyalty_points = COALESCE(loyalty_points, 0) + ?
            WHERE id = ? AND tenant_id = ?
        ");
        $update->execute([
            $points,
            $payment["customer_id"],
            $payment["tenant_id"]
        ]);

        $log = $pdo->prepare("
            INSERT INTO loyalty_points_log
            (
                tenant_id,
                customer_id,
                points_earned,
                points_redeemed,
                amount_earned,
                reason,
                reference_type,
                reference_id,
                created_by,
                created_at
            )
            VALUES (?, ?, ?, 0, ?, ?, 'payment', ?, ?, NOW())
        ");
        $log->execute([
            $payment["tenant_id"],
            $payment["customer_id"],
            $points,
            $amount,
            "Automatic loyalty points from payment #" . $payment["payment_number"],
            $payment_id,
            $created_by
        ]);

        $pdo->commit();

        response(true, "Points waa lagu daray", [
            "payment_id" => $payment_id,
            "points" => $points
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        response(false, "Loyalty error", $e->getMessage());
    }
}

response(false, "Method not allowed");