<?php
// reports/sms_report.php
// SMS Report - SMS campaign analytics

// Get SMS data
$total_sent = 0;
$delivered = 0;
$failed = 0;
$pending = 0;
$sms_list = [];

try {
    // SMS totals
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
        FROM sms_messages 
        WHERE tenant_id = ? AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$session_tenant_id, $date_from . ' 00:00:00', $date_to . ' 23:59:59']);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_sent = $stats['total'];
    $delivered = $stats['delivered'];
    $failed = $stats['failed'];
    $pending = $stats['pending'];
    
    // SMS list
    $stmt = $pdo->prepare("
        SELECT s.*, c.customer_name, c.phone
        FROM sms_messages s
        LEFT JOIN customers c ON s.customer_id = c.id
        WHERE s.tenant_id = ? AND s.created_at BETWEEN ? AND ?
        ORDER BY s.created_at DESC
        LIMIT 500
    ");
    $stmt->execute([$session_tenant_id, $date_from . ' 00:00:00', $date_to . ' 23:59:59']);
    $sms_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Daily SMS trend
    $stmt = $pdo->prepare("
        SELECT DATE(created_at) as date, COUNT(*) as count
        FROM sms_messages 
        WHERE tenant_id = ? AND created_at BETWEEN DATE_SUB(NOW(), INTERVAL 30 DAY) AND NOW()
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");
    $stmt->execute([$session_tenant_id]);
    $daily_sms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {}
?>

<!-- Summary Cards -->
<div class="row">
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-envelope"></i> Total SMS Sent</h4>
            <div class="amount"><?= formatNumber($total_sent) ?></div>
            <div class="subtext">This period</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-check-circle"></i> Delivered</h4>
            <div class="amount" style="color: var(--curdun-success);"><?= formatNumber($delivered) ?></div>
            <div class="subtext"><?= getPercentage($delivered, $total_sent) ?>% delivery rate</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-times-circle"></i> Failed</h4>
            <div class="amount" style="color: var(--curdun-danger);"><?= formatNumber($failed) ?></div>
            <div class="subtext"><?= getPercentage($failed, $total_sent) ?>% failure rate</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-hourglass-half"></i> Pending</h4>
            <div class="amount"><?= formatNumber($pending) ?></div>
            <div class="subtext">Awaiting delivery</div>
        </div>
    </div>
</div>

<!-- SMS Trend Chart -->
<div class="row mt-4">
    <div class="col-md-12">
        <div class="chart-container">
            <div class="chart-title"><i class="fas fa-chart-line"></i> SMS Usage Trend (Last 30 Days)</div>
            <canvas id="smsTrendChart" height="250"></canvas>
        </div>
    </div>
</div>

<!-- SMS List -->
<div class="chart-container mt-3">
    <div class="chart-title"><i class="fas fa-list"></i> SMS Message Log</div>
    <div class="table-responsive">
        <table class="data-table" id="smsTable">
            <thead>
                <tr>
                    <th>Date</th><th>Customer</th><th>Phone</th>
                    <th>Message</th><th>Status</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sms_list as $sms): ?>
                <tr>
                    <td><?= date('d/m/Y H:i', strtotime($sms['created_at'])) ?></td>
                    <td><?= htmlspecialchars($sms['customer_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($sms['phone'] ?? '-') ?></td>
                    <td style="max-width: 300px;"><?= htmlspecialchars(substr($sms['message'] ?? '', 0, 50)) ?>...</td>
                    <td>
                        <?php if ($sms['status'] == 'delivered'): ?>
                            <span class="badge-paid">Delivered</span>
                        <?php elseif ($sms['status'] == 'failed'): ?>
                            <span class="badge-unpaid">Failed</span>
                        <?php else: ?>
                            <span class="badge-pending">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td><button class="btn-action" onclick="viewSMS(<?= $sms['id'] ?>)"><i class="fas fa-eye"></i></button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// SMS Trend Chart
const trendCtx = document.getElementById('smsTrendChart')?.getContext('2d');
if (trendCtx && <?= count($daily_sms) ?> > 0) {
    const dates = [<?php foreach ($daily_sms as $d) echo "'" . date('d/m', strtotime($d['date'])) . "',"; ?>];
    const counts = [<?php foreach ($daily_sms as $d) echo $d['count'] . ","; ?>];
    
    new Chart(trendCtx, {
        type: 'line',
        data: { labels: dates, datasets: [{ label: 'SMS Sent', data: counts, borderColor: '#17a2b8', backgroundColor: 'rgba(23,162,184,0.1)', fill: true, tension: 0.4 }] },
        options: { responsive: true, scales: { y: { beginAtZero: true, title: { display: true, text: 'Number of SMS' } } } }
    });
}

$(document).ready(function() {
    $('#smsTable').DataTable({ pageLength: 25, order: [[0, 'desc']] });
});

function viewSMS(id) {
    Swal.fire({
        title: 'SMS Details',
        html: 'Loading...',
        width: '600px'
    });
    
    $.get('ajax/get_sms.php', { id: id }, function(response) {
        Swal.fire({
            title: 'SMS Details',
            html: response,
            width: '600px'
        });
    });
}
</script>