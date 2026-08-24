<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

$customer_id = 0;

if (isset($_SESSION['customer_id'])) {
    $customer_id = (int)$_SESSION['customer_id'];
}

if (!$customer_id && isset($_GET['customer_id'])) {
    $customer_id = (int)$_GET['customer_id'];
}

if (!$customer_id && isset($_POST['customer_id'])) {
    $customer_id = (int)$_POST['customer_id'];
}

if (!$customer_id) {
    echo json_encode([
        'success' => false,
        'message' => 'customer_id lama helin',
        'points' => 0
    ]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT 
        id,
        customer_name,
        phone,
        COALESCE(loyalty_points, 0) AS loyalty_points
    FROM customers
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    echo json_encode([
        'success' => false,
        'message' => 'Customer lama helin',
        'points' => 0
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'customer_id' => (int)$customer['id'],
    'customer_name' => $customer['customer_name'],
    'phone' => $customer['phone'],
    'points' => (float)$customer['loyalty_points']
]);