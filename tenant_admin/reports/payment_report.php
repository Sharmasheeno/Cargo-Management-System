<?php
// reports/payment_report.php
// Payment Report - Complete payment analytics

// Get payment data
$total_payments = 0;
$cash_payments = 0;
$bank_payments = 0;
$mobile_payments = 0;
$pending_payments = 0;
$payment_list = [];

try {
    // Build WHERE clause
    $where = "tenant_id = ? AND payment_date BETWEEN ? AND ?";
    $params = [$session_tenant_id, $date_from, $date_to];
    
    if ($customer_id) {
        $where .= " AND customer_id = ?";
        $params[] = $customer_id;
    }
    if ($payment_method) {
        $where .= " AND payment_method = ?";
        $params[] = $payment_method;
    }
    
    // Totals by method
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(amount), 0) as total,
            COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END), 0) as cash,
            COALESCE(SUM(CASE WHEN payment_method = 'bank_transfer' THEN amount ELSE 0 END), 0) as bank,
            COALESCE(SUM(CASE WHEN payment_method = 'mobile_money' THEN amount ELSE 0 END), 0) as mobile,
            COUNT(*) as count
        FROM receipts 
        WHERE $where
    ");
    $stmt->execute($params);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_payments = $totals['total'];
    $cash_payments = $totals['cash'];
    $bank_payments = $totals['bank'];
    $mobile_payments = $totals['mobile'];
    $payment_count = $totals['count'];
    
    // Pending payments (unpaid invoices)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount - paid_amount), 0) as pending
        FROM invoices 
        WHERE tenant_id = ? AND status != 'paid'
    ");
    $stmt->execute([$session_tenant_id]);
    $pending_payments = $stmt->fetch(PDO::FETCH_ASSOC)['pending'];
    
    // Daily collections
    $stmt = $pdo->prepare("
        SELECT DATE(payment_date) as date, COALESCE(SUM(amount), 0) as total
        FROM receipts 
        WHERE tenant_id = ? AND payment_date BETWEEN ? AND ?
        GROUP BY DATE(payment_date)
        ORDER BY date ASC
    ");
    $stmt->execute([$session_tenant_id, $date_from, $date_to]);
    $daily_collections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Payment list
    $stmt = $pdo->prepare("
        SELECT r.id, r.receipt_number, r.payment_date, r.amount, r.payment_method,
               r.reference_number, c.customer_name, i.invoice_number
        FROM receipts r
        LEFT JOIN customers c ON r.customer_id = c.id
        LEFT JOIN invoices i ON r.invoice_id = i.id
        WHERE $where
        ORDER BY r.payment_date DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $payment_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $payment_list = [];
}
?>

<!-- Summary Cards -->
<div class="row">
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-money-bill-wave"></i> Total Payments</h4>
            <div class="amount"><?= formatMoney($total_payments) ?></div>
            <div class="subtext"><?= formatNumber($payment_count) ?> transactions</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-money-bill"></i> Cash Payments</h4>
            <div class="amount"><?= formatMoney($cash_payments) ?></div>
            <div class="subtext"><?= getPercentage($cash_payments, $total_payments) ?>% of total</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-university"></i> Bank Transfer</h4>
            <div class="amount"><?= formatMoney($bank_payments) ?></div>
            <div class="subtext"><?= getPercentage($bank_payments, $total_payments) ?>% of total</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-mobile-alt"></i> Mobile Money</h4>
            <div class="amount"><?= formatMoney($mobile_payments) ?></div>
            <div class="subtext"><?= getPercentage($mobile_payments, $total_payments) ?>% of total</div>
        </div>
    </div>
</div>

<div class="row mt-3">
    <div class="col-md-6">
        <div class="summary-card">
            <h4><i class="fas fa-hourglass-half"></i> Pending Collections</h4>
            <div class="amount" style="color: var(--curdun-danger);"><?= formatMoney($pending_payments) ?></div>
            <div class="subtext">Amount still to be collected</div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="summary-card">
            <h4><i class="fas fa-calendar-day"></i> Average Daily Collection</h4>
            <?php
            $days = max(1, (strtotime($date_to) - strtotime($date_from)) / 86400 + 1);
            $avg_daily = $total_payments / $days;
            ?>
            <div class="amount"><?= formatMoney($avg_daily) ?></div>
            <div class="subtext">Per day over period</div>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="row mt-4">
    <div class="col-md-6">
        <div class="chart-container">
            <div class="chart-title"><i class="fas fa-chart-line"></i> Daily Collection Trend</div>
            <canvas id="dailyChart" height="250"></canvas>
        </div>
    </div>
    <div class="col-md-6">
        <div class="chart-container">
            <div class="chart-title"><i class="fas fa-chart-pie"></i> Payment Methods</div>
            <canvas id="methodChart" height="250"></canvas>
        </div>
    </div>
</div>

<!-- Payment List -->
<div class="chart-container mt-3">
    <div class="chart-title"><i class="fas fa-list"></i> Payment Transactions</div>
    <div class="table-responsive">
        <table class="data-table" id="paymentTable">
            <thead>
                <tr>
                    <th>Receipt #</th><th>Customer</th><th>Date</th><th>Method</th>
                    <th class="text-end">Amount</th><th>Reference</th><th>Invoice</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payment_list as $payment): ?>
                <tr>
                    <td><?= htmlspecialchars($payment['receipt_number'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($payment['customer_name'] ?? '-') ?></td>
                    <td><?= date('d/m/Y', strtotime($payment['payment_date'])) ?></td>
                    <td><?= ucfirst(str_replace('_', ' ', $payment['payment_method'] ?? '-')) ?></td>
                    <td class="text-end"><?= formatMoney($payment['amount']) ?></td>
                    <td><?= htmlspecialchars($payment['reference_number'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($payment['invoice_number'] ?? '-') ?></td>
                    <td><button class="btn-action" onclick="viewReceipt(<?= $payment['id'] ?>)"><i class="fas fa-print"></i></button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Daily Collection Chart
const dailyCtx = document.getElementById('dailyChart')?.getContext('2d');
if (dailyCtx && <?= count($daily_collections) ?> > 0) {
    const dates = [<?php foreach ($daily_collections as $d) echo "'" . date('d/m', strtotime($d['date'])) . "',"; ?>];
    const amounts = [<?php foreach ($daily_collections as $d) echo $d['total'] . ","; ?>];
    
    new Chart(dailyCtx, {
        type: 'line',
        data: { labels: dates, datasets: [{ label: 'Collections', data: amounts, borderColor: '#0F7A3A', backgroundColor: 'rgba(15,122,58,0.1)', fill: true, tension: 0.4 }] },
        options: { responsive: true, scales: { y: { beginAtZero: true, title: { display: true, text: 'Amount ($)' } } } }
    });
}

// Payment Methods Chart
const methodCtx = document.getElementById('methodChart')?.getContext('2d');
if (methodCtx) {
    new Chart(methodCtx, {
        type: 'doughnut',
        data: { labels: ['Cash', 'Bank Transfer', 'Mobile Money'], datasets: [{ data: [<?= $cash_payments ?>, <?= $bank_payments ?>, <?= $mobile_payments ?>], backgroundColor: ['#28a745', '#17a2b8', '#ffc107'] }] },
        options: { plugins: { legend: { position: 'bottom' } } }
    });
}

$(document).ready(function() {
    $('#paymentTable').DataTable({ pageLength: 25, order: [[2, 'desc']] });
});

function viewReceipt(id) {
    window.open('print_receipt.php?id=' + id, '_blank');
}
</script>