<?php
// reports/sales_report.php
// Sales Report - Complete sales analytics

// Get sales data
$total_sales = 0;
$paid_sales = 0;
$unpaid_sales = 0;
$overdue_sales = 0;
$invoice_count = 0;
$sales_data = [];

try {
    // Totals
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(total_amount), 0) as total_sales,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END), 0) as paid_sales,
            COALESCE(SUM(CASE WHEN status = 'unpaid' THEN total_amount - paid_amount ELSE 0 END), 0) as unpaid_sales,
            COALESCE(SUM(CASE WHEN status = 'overdue' THEN total_amount - paid_amount ELSE 0 END), 0) as overdue_sales,
            COUNT(*) as invoice_count
        FROM invoices 
        WHERE tenant_id = ? AND invoice_date BETWEEN ? AND ?
    ");
    $stmt->execute([$session_tenant_id, $date_from, $date_to]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_sales = $totals['total_sales'];
    $paid_sales = $totals['paid_sales'];
    $unpaid_sales = $totals['unpaid_sales'];
    $overdue_sales = $totals['overdue_sales'];
    $invoice_count = $totals['invoice_count'];
    
    // Build WHERE clause
    $where = "i.tenant_id = ? AND i.invoice_date BETWEEN ? AND ?";
    $params = [$session_tenant_id, $date_from, $date_to];
    
    if ($customer_id) {
        $where .= " AND i.customer_id = ?";
        $params[] = $customer_id;
    }
    if ($container_id) {
        $where .= " AND i.container_id = ?";
        $params[] = $container_id;
    }
    if ($status) {
        $where .= " AND i.status = ?";
        $params[] = $status;
    }
    
    // Sales by customer
    $stmt = $pdo->prepare("
        SELECT c.customer_name, 
               COUNT(i.id) as invoice_count,
               COALESCE(SUM(i.total_amount), 0) as total_sales,
               COALESCE(SUM(i.paid_amount), 0) as paid_amount,
               COALESCE(SUM(i.total_amount - i.paid_amount), 0) as balance
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        WHERE $where
        GROUP BY i.customer_id
        ORDER BY total_sales DESC
        LIMIT 20
    ");
    $stmt->execute($params);
    $sales_by_customer = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Sales by month
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(i.invoice_date, '%Y-%m') as month,
               COALESCE(SUM(i.total_amount), 0) as total_sales,
               COALESCE(SUM(i.paid_amount), 0) as collected
        FROM invoices i
        WHERE i.tenant_id = ? AND i.invoice_date BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(i.invoice_date, '%Y-%m')
        ORDER BY month ASC
    ");
    $stmt->execute([$session_tenant_id, $date_from, $date_to]);
    $sales_by_month = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Detailed sales list
    $stmt = $pdo->prepare("
        SELECT i.id, i.invoice_number, i.invoice_date, i.due_date,
               c.customer_name, i.total_amount, i.paid_amount,
               (i.total_amount - i.paid_amount) as balance, i.status
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        WHERE $where
        ORDER BY i.invoice_date DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $sales_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $sales_list = [];
}
?>

<!-- Summary Cards -->
<div class="row">
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-chart-line"></i> Total Sales</h4>
            <div class="amount"><?= formatMoney($total_sales) ?></div>
            <div class="subtext"><?= $invoice_count ?> invoices</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-check-circle"></i> Paid Sales</h4>
            <div class="amount" style="color: var(--curdun-success);"><?= formatMoney($paid_sales) ?></div>
            <div class="subtext"><?= getPercentage($paid_sales, $total_sales) ?>% collected</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-clock"></i> Unpaid Sales</h4>
            <div class="amount" style="color: var(--curdun-warning);"><?= formatMoney($unpaid_sales) ?></div>
            <div class="subtext">Pending collection</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-exclamation-triangle"></i> Overdue Sales</h4>
            <div class="amount" style="color: var(--curdun-danger);"><?= formatMoney($overdue_sales) ?></div>
            <div class="subtext">Requires attention</div>
        </div>
    </div>
</div>

<!-- Sales Chart -->
<div class="row mt-4">
    <div class="col-md-6">
        <div class="chart-container">
            <div class="chart-title"><i class="fas fa-chart-bar"></i> Sales by Month</div>
            <canvas id="monthlySalesChart" height="250"></canvas>
        </div>
    </div>
    <div class="col-md-6">
        <div class="chart-container">
            <div class="chart-title"><i class="fas fa-chart-pie"></i> Sales Status</div>
            <canvas id="salesStatusChart" height="250"></canvas>
        </div>
    </div>
</div>

<!-- Top Customers -->
<div class="chart-container mt-3">
    <div class="chart-title"><i class="fas fa-trophy"></i> Top Customers by Sales</div>
    <div class="table-responsive">
        <table class="data-table">
            <thead><tr><th>Customer</th><th class="text-end">Invoices</th><th class="text-end">Total Sales</th><th class="text-end">Paid</th><th class="text-end">Balance</th></tr></thead>
            <tbody>
                <?php foreach ($sales_by_customer as $cust): ?>
                <tr>
                    <td><?= htmlspecialchars($cust['customer_name'] ?? '-') ?></td>
                    <td class="text-end"><?= formatNumber($cust['invoice_count']) ?></td>
                    <td class="text-end"><?= formatMoney($cust['total_sales']) ?></td>
                    <td class="text-end"><?= formatMoney($cust['paid_amount']) ?></td>
                    <td class="text-end <?= $cust['balance'] > 0 ? 'text-danger' : '' ?>"><?= formatMoney($cust['balance']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Detailed Sales List -->
<div class="chart-container mt-3">
    <div class="chart-title"><i class="fas fa-list"></i> Sales Details</div>
    <div class="table-responsive">
        <table class="data-table" id="salesDetailsTable">
            <thead>
                <tr>
                    <th>Invoice #</th><th>Customer</th><th>Date</th><th>Due Date</th>
                    <th class="text-end">Total</th><th class="text-end">Paid</th>
                    <th class="text-end">Balance</th><th>Status</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sales_list as $sale): ?>
                <tr>
                    <td><?= htmlspecialchars($sale['invoice_number'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($sale['customer_name'] ?? '-') ?></td>
                    <td><?= date('d/m/Y', strtotime($sale['invoice_date'])) ?></td>
                    <td><?= date('d/m/Y', strtotime($sale['due_date'])) ?></td>
                    <td class="text-end"><?= formatMoney($sale['total_amount']) ?></td>
                    <td class="text-end"><?= formatMoney($sale['paid_amount']) ?></td>
                    <td class="text-end <?= $sale['balance'] > 0 ? 'text-danger' : '' ?>"><?= formatMoney($sale['balance']) ?></td>
                    <td><?= getStatusBadge($sale['status']) ?></td>
                    <td><button class="btn-action" onclick="viewInvoice(<?= $sale['id'] ?>)"><i class="fas fa-eye"></i></button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Monthly Sales Chart
const monthlyCtx = document.getElementById('monthlySalesChart')?.getContext('2d');
if (monthlyCtx && <?= count($sales_by_month) ?> > 0) {
    const months = [<?php foreach ($sales_by_month as $m) echo "'" . date('M Y', strtotime($m['month'] . '-01')) . "',"; ?>];
    const revenues = [<?php foreach ($sales_by_month as $m) echo $m['total_sales'] . ","; ?>];
    const collections = [<?php foreach ($sales_by_month as $m) echo $m['collected'] . ","; ?>];
    
    new Chart(monthlyCtx, {
        type: 'bar',
        data: { labels: months, datasets: [
            { label: 'Sales', data: revenues, backgroundColor: '#2D1859' },
            { label: 'Collected', data: collections, backgroundColor: '#0F7A3A' }
        ]},
        options: { responsive: true, scales: { y: { beginAtZero: true, title: { display: true, text: 'Amount ($)' } } } }
    });
}

// Sales Status Chart
const statusCtx = document.getElementById('salesStatusChart')?.getContext('2d');
if (statusCtx) {
    new Chart(statusCtx, {
        type: 'doughnut',
        data: { labels: ['Paid', 'Unpaid', 'Overdue'], datasets: [{ data: [<?= $paid_sales ?>, <?= $unpaid_sales ?>, <?= $overdue_sales ?>], backgroundColor: ['#28a745', '#ffc107', '#dc3545'] }] },
        options: { plugins: { legend: { position: 'bottom' } } }
    });
}

// DataTable
$(document).ready(function() {
    $('#salesDetailsTable').DataTable({
        pageLength: 25,
        order: [[2, 'desc']],
        language: { search: "Search:", lengthMenu: "Show _MENU_ entries" }
    });
});

function viewInvoice(id) {
    window.open('view_invoice.php?id=' + id, '_blank');
}
</script>