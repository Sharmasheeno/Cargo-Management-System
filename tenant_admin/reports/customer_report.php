<?php
// reports/customer_report.php
// Customer Report - Customer analytics and history

// Get customer data
$total_customers = 0;
$active_customers = 0;
$customers_with_debt = 0;
$top_customers = [];

try {
    // Totals
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN debt_amount > 0 THEN 1 ELSE 0 END) as with_debt
        FROM customers 
        WHERE tenant_id = ?
    ");
    $stmt->execute([$session_tenant_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_customers = $stats['total'];
    $active_customers = $stats['active'];
    $customers_with_debt = $stats['with_debt'];
    
    // Top spending customers
    $stmt = $pdo->prepare("
        SELECT 
            c.id, c.customer_name, c.phone, c.email,
            COALESCE(SUM(i.total_amount), 0) as total_spent,
            COALESCE(SUM(i.paid_amount), 0) as total_paid,
            c.debt_amount,
            COUNT(DISTINCT i.id) as invoice_count
        FROM customers c
        LEFT JOIN invoices i ON c.id = i.customer_id
        WHERE c.tenant_id = ?
        GROUP BY c.id
        ORDER BY total_spent DESC
        LIMIT 20
    ");
    $stmt->execute([$session_tenant_id]);
    $top_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Customer list with details
    $stmt = $pdo->prepare("
        SELECT 
            c.*,
            (SELECT COUNT(*) FROM invoices WHERE customer_id = c.id) as invoice_count,
            (SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE customer_id = c.id) as total_purchases,
            (SELECT COALESCE(SUM(amount), 0) FROM receipts WHERE customer_id = c.id) as total_payments
        FROM customers c
        WHERE c.tenant_id = ?
        ORDER BY c.customer_name ASC
        LIMIT 500
    ");
    $stmt->execute([$session_tenant_id]);
    $customer_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {}
?>

<!-- Summary Cards -->
<div class="row">
    <div class="col-md-4">
        <div class="summary-card">
            <h4><i class="fas fa-users"></i> Total Customers</h4>
            <div class="amount"><?= formatNumber($total_customers) ?></div>
            <div class="subtext">Registered customers</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="summary-card">
            <h4><i class="fas fa-user-check"></i> Active Customers</h4>
            <div class="amount" style="color: var(--curdun-success);"><?= formatNumber($active_customers) ?></div>
            <div class="subtext"><?= getPercentage($active_customers, $total_customers) ?>% of total</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="summary-card">
            <h4><i class="fas fa-exclamation-circle"></i> Customers with Debt</h4>
            <div class="amount" style="color: var(--curdun-danger);"><?= formatNumber($customers_with_debt) ?></div>
            <div class="subtext">Require follow-up</div>
        </div>
    </div>
</div>

<!-- Top Customers -->
<div class="chart-container mt-3">
    <div class="chart-title"><i class="fas fa-trophy"></i> Top Spending Customers</div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Customer</th><th>Phone</th><th class="text-end">Invoices</th>
                    <th class="text-end">Total Spent</th><th class="text-end">Total Paid</th>
                    <th class="text-end">Balance</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($top_customers as $cust): ?>
                <tr>
                    <td><?= htmlspecialchars($cust['customer_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($cust['phone'] ?? '-') ?></td>
                    <td class="text-end"><?= formatNumber($cust['invoice_count']) ?></td>
                    <td class="text-end"><?= formatMoney($cust['total_spent']) ?></td>
                    <td class="text-end"><?= formatMoney($cust['total_paid']) ?></td>
                    <td class="text-end <?= ($cust['total_spent'] - $cust['total_paid']) > 0 ? 'text-danger' : '' ?>">
                        <?= formatMoney($cust['total_spent'] - $cust['total_paid']) ?>
                    </td>
                    <td><button class="btn-action" onclick="viewCustomer(<?= $cust['id'] ?>)"><i class="fas fa-eye"></i></button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Customer List -->
<div class="chart-container mt-3">
    <div class="chart-title"><i class="fas fa-list"></i> All Customers</div>
    <div class="table-responsive">
        <table class="data-table" id="customerTable">
            <thead>
                <tr>
                    <th>Customer</th><th>Phone</th><th>Email</th><th>Address</th>
                    <th class="text-end">Invoices</th><th class="text-end">Purchases</th>
                    <th class="text-end">Payments</th><th class="text-end">Balance</th>
                    <th>Status</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customer_list as $cust): ?>
                <?php $balance = ($cust['total_purchases'] ?? 0) - ($cust['total_payments'] ?? 0); ?>
                <tr>
                    <td><?= htmlspecialchars($cust['customer_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($cust['phone'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($cust['email'] ?? '-') ?></td>
                    <td><?= htmlspecialchars(substr($cust['address'] ?? '-', 0, 30)) ?>...</td>
                    <td class="text-end"><?= formatNumber($cust['invoice_count']) ?></td>
                    <td class="text-end"><?= formatMoney($cust['total_purchases'] ?? 0) ?></td>
                    <td class="text-end"><?= formatMoney($cust['total_payments'] ?? 0) ?></td>
                    <td class="text-end <?= $balance > 0 ? 'text-danger' : ($balance < 0 ? 'text-success' : '') ?>">
                        <?= formatMoney($balance) ?>
                    </td>
                    <td><?= getStatusBadge($cust['status'] ?? 'active') ?></td>
                    <td><button class="btn-action" onclick="viewCustomer(<?= $cust['id'] ?>)"><i class="fas fa-eye"></i></button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#customerTable').DataTable({ pageLength: 25 });
});

function viewCustomer(id) {
    window.open('view_customer.php?id=' + id, '_blank');
}
</script>