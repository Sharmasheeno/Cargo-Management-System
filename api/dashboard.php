<?php
require_once __DIR__ . '/db.php';

$data = get_input();

$user_id = $data['user_id'] ?? $_GET['user_id'] ?? null;
$customer_id = $data['customer_id'] ?? $_GET['customer_id'] ?? 0;

if (!$user_id && !$customer_id) {
    json_response(false, "user_id ama customer_id waa qasab");
}

try {
    // User
    $user = null;
    if ($user_id) {
        $user = db_get("
            SELECT id, full_name, email, phone, role_type, tenant_id, is_active
            FROM users
            WHERE id = ?
            LIMIT 1
        ", [$user_id]);
    }

    // Customer
    $customer = db_get("
        SELECT *
        FROM customers
        WHERE user_id = ? OR id = ?
        LIMIT 1
    ", [$user_id ?? 0, $customer_id]);

    if (!$customer) {
        json_response(false, "Customer lama helin");
    }

    $customer_id = $customer['id'];
    $tenant_id = $customer['tenant_id'] ?? ($user['tenant_id'] ?? 0);

    // Tenant
    $tenant = db_get("
        SELECT id, name
        FROM tenants
        WHERE id = ?
        LIMIT 1
    ", [$tenant_id]);

    // Invoice counts
    $totalInvoices = db_get("
        SELECT COUNT(*) AS total
        FROM invoices
        WHERE customer_id = ?
    ", [$customer_id]);

    $paidInvoices = db_get("
        SELECT COUNT(*) AS total
        FROM invoices
        WHERE customer_id = ? AND status = 'paid'
    ", [$customer_id]);

    $unpaidInvoices = db_get("
        SELECT COUNT(*) AS total
        FROM invoices
        WHERE customer_id = ? AND status IN ('unpaid', 'partial', 'overdue')
    ", [$customer_id]);

    // Invoice totals
    $invoiceTotals = db_get("
        SELECT 
            COALESCE(SUM(total_amount), 0) AS total,
            COALESCE(SUM(paid_amount), 0) AS paid,
            COALESCE(SUM(total_amount - paid_amount), 0) AS balance
        FROM invoices
        WHERE customer_id = ?
        AND status != 'cancelled'
    ", [$customer_id]);

    // Recent invoices
    $recentInvoices = db_get_all("
        SELECT 
            id,
            invoice_number,
            invoice_date,
            due_date,
            total_amount,
            paid_amount,
            status,
            (total_amount - paid_amount) AS due_amount
        FROM invoices
        WHERE customer_id = ?
        ORDER BY id DESC
        LIMIT 5
    ", [$customer_id]);

    // Recent payments
    $recentPayments = db_get_all("
        SELECT 
            r.id,
            r.receipt_number,
            r.amount,
            r.payment_date,
            r.payment_method,
            i.invoice_number
        FROM receipts r
        LEFT JOIN invoices i ON r.invoice_id = i.id
        WHERE r.customer_id = ?
        ORDER BY r.id DESC
        LIMIT 5
    ", [$customer_id]);

    // Warehouse stock
    $stockItems = db_get_all("
        SELECT 
            id,
            stock_name,
            quantity,
            volume_cbm,
            unit_price,
            location,
            (volume_cbm * unit_price) AS total_value,
            mogadishu_status,
            mogadishu_received_date
        FROM warehouse_stock
        WHERE customer_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ", [$customer_id]);

    // Recent shipments
    $recentShipments = db_get_all("
        SELECT DISTINCT
            tt.id,
            tt.trip_number,
            tt.total_cbm,
            tt.status,
            tt.created_at,
            c.container_number
        FROM trucking_trips tt
        LEFT JOIN containers c ON tt.container_id = c.id
        LEFT JOIN cargo_manifest_items cmi ON c.id = cmi.container_id
        LEFT JOIN warehouse_stock ws ON cmi.warehouse_stock_id = ws.id
        WHERE ws.customer_id = ?
        ORDER BY tt.created_at DESC
        LIMIT 5
    ", [$customer_id]);

    // Containers
    $containers = db_get_all("
        SELECT DISTINCT 
            c.id,
            c.container_number,
            c.container_type,
            c.size_cbm,
            c.status,
            c.origin,
            tt.trip_number,
            tt.status AS trip_status
        FROM containers c
        LEFT JOIN trucking_trips tt ON c.id = tt.container_id
        LEFT JOIN cargo_manifest_items cmi ON c.id = cmi.container_id
        LEFT JOIN warehouse_stock ws ON cmi.warehouse_stock_id = ws.id
        WHERE ws.customer_id = ? OR c.tenant_id = ?
        ORDER BY c.created_at DESC
        LIMIT 10
    ", [$customer_id, $tenant_id]);

    $credit_limit = (float)($customer['credit_limit'] ?? 0);
    $debt_amount = (float)($customer['debt_amount'] ?? 0);
    $credit_usage = $credit_limit > 0 ? ($debt_amount / $credit_limit) * 100 : 0;

    json_response(true, "Dashboard data loaded", [
        "user" => $user,
        "customer" => [
            "id" => (int)$customer['id'],
            "name" => $customer['customer_name'],
            "phone" => $customer['phone'],
            "email" => $customer['email'],
            "address" => $customer['address'],
            "debt_amount" => (float)$customer['debt_amount'],
            "total_spent" => (float)$customer['total_spent'],
            "loyalty_points" => (int)$customer['loyalty_points'],
            "credit_limit" => (float)$customer['credit_limit'],
            "payment_terms" => (int)$customer['payment_terms'],
            "is_active" => (int)$customer['is_active']
        ],
        "tenant" => $tenant,
        "stats" => [
            "total_invoices" => (int)($totalInvoices['total'] ?? 0),
            "paid_invoices" => (int)($paidInvoices['total'] ?? 0),
            "unpaid_invoices" => (int)($unpaidInvoices['total'] ?? 0),
            "invoice_totals" => [
                "total" => (float)($invoiceTotals['total'] ?? 0),
                "paid" => (float)($invoiceTotals['paid'] ?? 0),
                "balance" => (float)($invoiceTotals['balance'] ?? 0)
            ],
            "credit_usage" => round($credit_usage, 2)
        ],
        "recent_invoices" => $recentInvoices,
        "recent_payments" => $recentPayments,
        "stock_items" => $stockItems,
        "recent_shipments" => $recentShipments,
        "containers" => $containers
    ]);

} catch (Exception $e) {
    json_response(false, "Server error", [
        "error" => $e->getMessage()
    ]);
}