<?php
require_once "db.php";

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ══════════════════════════════════════════════
//  POST  → redeem points
// ══════════════════════════════════════════════
if ($method === 'POST') {
    $input  = get_input();
    $action = $input['action'] ?? '';

    if ($action !== 'redeem_points') {
        response(false, "Unknown action: $action");
    }

    // Accept phone OR customer_id in the POST body
    $phone       = trim($input['phone'] ?? '');
    $customer_id = (int)($input['customer_id'] ?? 0);
    $points_to_redeem = (int)($input['points'] ?? 0);

    if ($points_to_redeem <= 0) {
        response(false, "Points waa inay ka badan yihiin 0");
    }

    if ($phone === '' && $customer_id <= 0) {
        response(false, "phone ama customer_id loo baahan yahay");
    }

    // Fetch the customer
    if ($customer_id > 0) {
        $stmt = $pdo->prepare("SELECT id, loyalty_points FROM customers WHERE id = ? LIMIT 1");
        $stmt->execute([$customer_id]);
    } else {
        $stmt = $pdo->prepare("SELECT id, loyalty_points FROM customers WHERE phone = ? LIMIT 1");
        $stmt->execute([$phone]);
    }
    $customer = $stmt->fetch();

    if (!$customer) {
        response(false, "Customer lama helin");
    }

    $current_points = (float)$customer['loyalty_points'];

    if ($current_points < $points_to_redeem) {
        response(false, "Dhibco ku filan ma haysatid. Waxaad haysataa: $current_points");
    }

    $new_points = $current_points - $points_to_redeem;

    // Deduct points from customers table
    $update = $pdo->prepare("UPDATE customers SET loyalty_points = ? WHERE id = ?");
    $update->execute([$new_points, $customer['id']]);

    // Log the redemption
    $log = $pdo->prepare("
        INSERT INTO loyalty_points_log
            (customer_id, points_earned, points_redeemed, reason, reference_type, created_by)
        VALUES (?, 0, ?, 'Points redeemed for 20% discount', 'redemption', ?)
    ");
    $log->execute([$customer['id'], $points_to_redeem, $customer['id']]);

    response(true, "Dhibcihii si guul leh ayaa loo sarrifay! Waxaad heshay 20% Discount.", [
        "remaining_points" => $new_points,
        "redeemed"         => $points_to_redeem,
        "discount"         => "20%",
    ]);
}

// ══════════════════════════════════════════════
//  GET  → fetch loyalty data
// ══════════════════════════════════════════════
$customer_id_raw = $_GET["customer_id"] ?? "";
$phone = trim($_GET["phone"] ?? "");

// Smart detection: long numeric string → treat as phone
if (is_numeric($customer_id_raw) && strlen((string)$customer_id_raw) >= 6) {
    $phone = $customer_id_raw;
    $customer_id = 0;
} else {
    $customer_id = (int)$customer_id_raw;
}

if ($customer_id <= 0 && $phone === "") {
    response(false, "customer_id ama phone required");
}

if ($customer_id > 0) {
    $stmt = $pdo->prepare("
        SELECT id, customer_name, phone, loyalty_points, debt_amount
        FROM customers WHERE id = ? LIMIT 1
    ");
    $stmt->execute([$customer_id]);
} else {
    $stmt = $pdo->prepare("
        SELECT id, customer_name, phone, loyalty_points, debt_amount
        FROM customers WHERE phone = ? LIMIT 1
    ");
    $stmt->execute([$phone]);
}

$customer = $stmt->fetch();

if (!$customer) {
    response(false, "Customer lama helin");
}

// Fetch transaction history logs
$logsStmt = $pdo->prepare(
    "SELECT * FROM loyalty_points_log WHERE customer_id = ? ORDER BY created_at DESC"
);
$logsStmt->execute([$customer['id']]);
$logs = $logsStmt->fetchAll();

response(true, "Customer loyalty loaded", [
    "customer_id"    => (int)$customer["id"],
    "customer_name"  => $customer["customer_name"],
    "phone"          => $customer["phone"],
    "points"         => (float)$customer["loyalty_points"],
    "loyalty_points" => (float)$customer["loyalty_points"],
    "debt_amount"    => (float)$customer["debt_amount"],
    "history"        => $logs,
    "logs"           => $logs,
]);