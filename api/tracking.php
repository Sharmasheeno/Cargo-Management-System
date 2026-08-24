<?php
require_once __DIR__ . '/db.php';

$data = get_input();

$user_id = $data['user_id'] ?? $_GET['user_id'] ?? null;
$customer_id = $data['customer_id'] ?? $_GET['customer_id'] ?? null;

if (!$user_id && !$customer_id) {
    json_response(false, "user_id ama customer_id waa qasab");
}

try {
    if (!$customer_id) {
        $customer = db_get("
            SELECT id, customer_name, phone, email
            FROM customers
            WHERE user_id = ?
            LIMIT 1
        ", [$user_id]);

        if (!$customer) {
            json_response(false, "Macamiil lama helin");
        }

        $customer_id = $customer['id'];
    } else {
        $customer = db_get("
            SELECT id, customer_name, phone, email
            FROM customers
            WHERE id = ?
            LIMIT 1
        ", [$customer_id]);
    }

    $packages = db_get_all("
        SELECT 
            ws.id,
            ws.stock_name,
            ws.quantity,
            ws.volume_cbm,
            ws.unit_price,
            ws.location,
            ws.mogadishu_status,
            ws.mogadishu_received_date,
            ws.last_updated,

            c.id AS container_id,
            c.container_number,
            c.status AS container_status,
            c.current_location,
            c.estimated_arrival

        FROM warehouse_stock ws
        LEFT JOIN cargo_manifest_items cmi 
            ON ws.id = cmi.warehouse_stock_id
        LEFT JOIN containers c 
            ON cmi.container_id = c.id
        WHERE ws.customer_id = ?
        ORDER BY ws.last_updated DESC
    ", [$customer_id]);

    json_response(true, "Tracking packages loaded", [
        "customer" => $customer,
        "total_packages" => count($packages),
        "packages" => $packages
    ]);

} catch (Exception $e) {
    json_response(false, "Server error", [
        "error" => $e->getMessage()
    ]);
}