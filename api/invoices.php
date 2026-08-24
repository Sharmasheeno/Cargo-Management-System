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

    $invoices = db_get_all("
        SELECT 
            id,
            invoice_number,
            invoice_date,
            due_date,
            total_amount,
            paid_amount,
            (total_amount - paid_amount) AS balance,
            status,
            created_at
        FROM invoices
        WHERE customer_id = ?
        ORDER BY invoice_date DESC
    ", [$customer_id]);

    $summary = db_get("
        SELECT 
            COUNT(*) AS total_invoices,
            COALESCE(SUM(total_amount), 0) AS total_amount,
            COALESCE(SUM(paid_amount), 0) AS paid_amount,
            COALESCE(SUM(total_amount - paid_amount), 0) AS balance_amount
        FROM invoices
        WHERE customer_id = ?
    ", [$customer_id]);

    json_response(true, "Invoices loaded successfully", [
        "customer" => $customer,
        "summary" => [
            "total_invoices" => (int)($summary['total_invoices'] ?? 0),
            "total_amount" => (float)($summary['total_amount'] ?? 0),
            "paid_amount" => (float)($summary['paid_amount'] ?? 0),
            "balance_amount" => (float)($summary['balance_amount'] ?? 0)
        ],
        "invoices" => $invoices
    ]);

} catch (Exception $e) {
    json_response(false, "Server error", [
        "error" => $e->getMessage()
    ]);
}