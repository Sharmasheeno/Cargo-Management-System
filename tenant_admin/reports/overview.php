<?php
// reports/overview.php
// Business Overview Report - Complete Dashboard

// Get totals for current period
$total_revenue = 0;
$total_payments = 0;
$total_receivable = 0;
$total_customers = 0;
$total_containers = 0;
$warehouse_value = 0;
$total_sms = 0;

try {
    // Total Revenue (Invoices)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) as total FROM invoices WHERE tenant_id = ? AND invoice_date BETWEEN ? AND ?");
    $stmt->execute([$session_tenant_id, $date_from, $date_to]);
    $total_revenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Total Payments (Receipts)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM receipts WHERE tenant_id = ? AND payment_date BETWEEN ? AND ?");
    $stmt->execute([$session_tenant_id, $date_from, $date_to]);
    $total_payments = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Total Receivable
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount - paid_amount), 0) as total FROM invoices WHERE tenant_id = ? AND status != 'paid'");
    $stmt->execute([$session_tenant_id]);
    $total_receivable = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Total Customers
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM customers WHERE tenant_id = ?");
    $stmt->execute([$session_tenant_id]);
    $total_customers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Total Containers
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM containers WHERE tenant_id = ?");
    $stmt->execute([$session_tenant_id]);
    $total_containers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Warehouse Value
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(volume_cbm * unit_price), 0) as total FROM warehouse_stock WHERE tenant_id = ?");
    $stmt->execute([$session_tenant_id]);
    $warehouse_value = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Total SMS Sent
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM sms_messages WHERE tenant_id = ? AND created_at BETWEEN ? AND ?");
    $stmt->execute([$session_tenant_id, $date_from . ' 00:00:00', $date_to . ' 23:59:59']);
    $total_sms = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
} catch (PDOException $e) {}

$net_profit = $total_revenue - $total_payments;

// Get monthly data for charts
$monthly_data = [];
try {
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(invoice_date, '%Y-%m') as month, 
               COALESCE(SUM(total_amount), 0) as revenue,
               COALESCE(SUM(paid_amount), 0) as collected
        FROM invoices 
        WHERE tenant_id = ? AND invoice_date BETWEEN DATE_SUB(?, INTERVAL 11 MONTH) AND ?
        GROUP BY DATE_FORMAT(invoice_date, '%Y-%m')
        ORDER BY month ASC
    ");
    $stmt->execute([$session_tenant_id, $date_to, $date_to]);
    $monthly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Get recent invoices
$recent_invoices = [];
try {
    $stmt = $pdo->prepare("
        SELECT i.*, c.customer_name 
        FROM invoices i 
        LEFT JOIN customers c ON i.customer_id = c.id 
        WHERE i.tenant_id = ? 
        ORDER BY i.invoice_date DESC 
        LIMIT 10
    ");
    $stmt->execute([$session_tenant_id]);
    $recent_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Get container status distribution
$container_status = [];
try {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM containers WHERE tenant_id = ? GROUP BY status");
    $stmt->execute([$session_tenant_id]);
    $container_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
?>

<!-- Summary Cards -->
<div class="row">
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-chart-line"></i> Total Sales</h4>
            <div class="amount"><?= formatMoney($total_revenue) ?></div>
            <div class="subtext">This period</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-money-bill-wave"></i> Total Payments</h4>
            <div class="amount"><?= formatMoney($total_payments) ?></div>
            <div class="subtext">Collection rate: <?= getPercentage($total_payments, $total_revenue) ?>%</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-receipt"></i> Receivables</h4>
            <div class="amount" style="color: var(--curdun-danger);"><?= formatMoney($total_receivable) ?></div>
            <div class="subtext">Outstanding balance</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-chart-simple"></i> Net Profit</h4>
            <div class="amount" style="color: <?= $net_profit >= 0 ? '#0F7A3A' : '#B42318' ?>;"><?= formatMoney($net_profit) ?></div>
            <div class="subtext">Revenue - Payments</div>
        </div>
    </div>
</div>

<div class="row mt-3">
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-users"></i> Total Customers</h4>
            <div class="amount"><?= formatNumber($total_customers) ?></div>
            <div class="subtext">Active accounts</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-boxes"></i> Warehouse Value</h4>
            <div class="amount"><?= formatMoney($warehouse_value) ?></div>
            <div class="subtext">Inventory valuation</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-ship"></i> Containers</h4>
            <div class="amount"><?= formatNumber($total_containers) ?></div>
            <div class="subtext">In system</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-envelope"></i> SMS Sent</h4>
            <div class="amount"><?= formatNumber($total_sms) ?></div>
            <div class="subtext">This period</div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row mt-4">
    <div class="col-md-8">
        <div class="chart-container">
            <div class="chart-title"><i class="fas fa-chart-line"></i> Revenue & Collection Trend (Last 12 Months)</div>
            <canvas id="revenueChart" height="250"></canvas>
        </div>
    </div>
    <div class="col-md-4">
        <div class="chart-container">
            <div class="chart-title"><i class="fas fa-chart-pie"></i> Container Status</div>
            <canvas id="containerChart" height="250"></canvas>
        </div>
    </div>
</div>

<!-- Recent Invoices Table -->
<div class="chart-container mt-3">
    <div class="chart-title"><i class="fas fa-file-invoice"></i> Recent Invoices</div>
    <div class="table-responsive">
        <table class="data-table" id="invoicesTable">
            <thead>
                <tr><th>Invoice #</th><th>Customer</th><th>Date</th><th class="text-end">Total</th><th class="text-end">Paid</th><th>Status</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php foreach ($recent_invoices as $inv): ?>
                <tr>
                    <td><?= htmlspecialchars($inv['invoice_number'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($inv['customer_name'] ?? '-') ?></td>
                    <td><?= date('d/m/Y', strtotime($inv['invoice_date'])) ?></td>
                    <td class="text-end"><?= formatMoney($inv['total_amount'] ?? 0) ?></td>
                    <td class="text-end"><?= formatMoney($inv['paid_amount'] ?? 0) ?></td>
                    <td><?= getStatusBadge($inv['status'] ?? 'pending') ?></td>
                    <td><button class="btn-action" onclick="viewInvoice(<?= $inv['id'] ?>)"><i class="fas fa-eye"></i></button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Revenue Chart
const revenueCtx = document.getElementById('revenueChart')?.getContext('2d');
if (revenueCtx) {
    const months = [<?php 
        foreach ($monthly_data as $m) {
            echo "'" . date('M Y', strtotime($m['month'] . '-01')) . "',";
        }
    ?>];
    const revenues = [<?php foreach ($monthly_data as $m) echo $m['revenue'] . ","; ?>];
    const collections = [<?php foreach ($monthly_data as $m) echo $m['collected'] . ","; ?>];
    
    new Chart(revenueCtx, {
        type: 'line',
        data: {
            labels: months,
            datasets: [
                { label: 'Revenue', data: revenues, borderColor: '#2D1859', backgroundColor: 'rgba(45,24,89,0.1)', fill: true, tension: 0.4 },
                { label: 'Collected', data: collections, borderColor: '#0F7A3A', backgroundColor: 'rgba(15,122,58,0.1)', fill: true, tension: 0.4 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: true, scales: { y: { beginAtZero: true, title: { display: true, text: 'Amount ($)' } } } }
    });
}

// Container Chart
const containerCtx = document.getElementById('containerChart')?.getContext('2d');
if (containerCtx) {
    const statusLabels = [<?php foreach ($container_status as $cs) echo "'" . ucfirst($cs['status']) . "',"; ?>];
    const statusCounts = [<?php foreach ($container_status as $cs) echo $cs['count'] . ","; ?>];
    
    new Chart(containerCtx, {
        type: 'doughnut',
        data: { labels: statusLabels, datasets: [{ data: statusCounts, backgroundColor: ['#17a2b8', '#ffc107', '#28a745', '#dc3545', '#6f42c1', '#fd7e14'] }] },
        options: { plugins: { legend: { position: 'bottom' } } }
    });
}

function viewInvoice(id) {
    window.open('view_invoice.php?id=' + id, '_blank');
}
</script>