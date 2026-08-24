<?php
// reports/container_report.php
// Container Report - Container tracking and status

// Get container data
$total_containers = 0;
$in_transit = 0;
$delivered = 0;
$loading = 0;
$at_port = 0;
$container_list = [];

try {
    // Build WHERE clause
    $where = "tenant_id = ?";
    $params = [$session_tenant_id];
    
    if ($container_id) {
        $where .= " AND id = ?";
        $params[] = $container_id;
    }
    if ($status) {
        $where .= " AND status = ?";
        $params[] = $status;
    }
    
    // Status counts
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status IN ('transit', 'shipped') THEN 1 ELSE 0 END) as in_transit,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN status = 'loading' THEN 1 ELSE 0 END) as loading,
            SUM(CASE WHEN status = 'at_port' THEN 1 ELSE 0 END) as at_port
        FROM containers 
        WHERE tenant_id = ?
    ");
    $stmt->execute([$session_tenant_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_containers = $stats['total'];
    $in_transit = $stats['in_transit'];
    $delivered = $stats['delivered'];
    $loading = $stats['loading'];
    $at_port = $stats['at_port'];
    
    // Container list
    $stmt = $pdo->prepare("
        SELECT c.*, 
               (SELECT COUNT(*) FROM cargo_manifest_items WHERE container_id = c.id) as items_count,
               (SELECT COUNT(*) FROM trucking_trips WHERE container_id = c.id) as trips_count
        FROM containers c
        WHERE $where
        ORDER BY c.created_at DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $container_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {}
?>

<!-- Summary Cards -->
<div class="row">
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-ship"></i> Total Containers</h4>
            <div class="amount"><?= formatNumber($total_containers) ?></div>
            <div class="subtext">All containers</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-truck"></i> In Transit</h4>
            <div class="amount" style="color: var(--curdun-info);"><?= formatNumber($in_transit) ?></div>
            <div class="subtext">On the way</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-check-circle"></i> Delivered</h4>
            <div class="amount" style="color: var(--curdun-success);"><?= formatNumber($delivered) ?></div>
            <div class="subtext">Completed</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-anchor"></i> At Port</h4>
            <div class="amount" style="color: var(--curdun-warning);"><?= formatNumber($at_port) ?></div>
            <div class="subtext">Awaiting clearance</div>
        </div>
    </div>
</div>

<!-- Status Chart -->
<div class="row mt-4">
    <div class="col-md-6">
        <div class="chart-container">
            <div class="chart-title"><i class="fas fa-chart-pie"></i> Container Status Distribution</div>
            <canvas id="containerStatusChart" height="250"></canvas>
        </div>
    </div>
    <div class="col-md-6">
        <div class="chart-container">
            <div class="chart-title"><i class="fas fa-chart-bar"></i> Containers by Origin</div>
            <canvas id="containerOriginChart" height="250"></canvas>
        </div>
    </div>
</div>

<!-- Container List -->
<div class="chart-container mt-3">
    <div class="chart-title"><i class="fas fa-list"></i> Container Details</div>
    <div class="table-responsive">
        <table class="data-table" id="containerTable">
            <thead>
                <tr>
                    <th>Container #</th><th>Origin</th><th>Status</th>
                    <th>ETA</th><th class="text-end">CBM</th>
                    <th class="text-end">Items</th><th class="text-end">Trips</th>
                    <th>Location</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($container_list as $cont): ?>
                <tr>
                    <td><?= htmlspecialchars($cont['container_number'] ?? 'N/A') ?></td>
                    <td><?= ucfirst($cont['origin'] ?? '-') ?></td>
                    <td><?= getStatusBadge($cont['status'] ?? 'pending') ?></td>
                    <td class="<?= (strtotime($cont['eta'] ?? 'now') < time()) ? 'text-danger' : '' ?>">
                        <?= isset($cont['eta']) ? date('d/m/Y', strtotime($cont['eta'])) : '-' ?>
                    </td>
                    <td class="text-end"><?= number_format($cont['cbm'] ?? 0, 2) ?></td>
                    <td class="text-end"><?= formatNumber($cont['items_count'] ?? 0) ?></td>
                    <td class="text-end"><?= formatNumber($cont['trips_count'] ?? 0) ?></td>
                    <td><?= htmlspecialchars($cont['current_location'] ?? '-') ?></td>
                    <td><button class="btn-action" onclick="viewContainer(<?= $cont['id'] ?>)"><i class="fas fa-eye"></i></button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Container Status Chart
const statusCtx = document.getElementById('containerStatusChart')?.getContext('2d');
if (statusCtx) {
    new Chart(statusCtx, {
        type: 'doughnut',
        data: { labels: ['In Transit', 'Delivered', 'Loading', 'At Port'], datasets: [{ data: [<?= $in_transit ?>, <?= $delivered ?>, <?= $loading ?>, <?= $at_port ?>], backgroundColor: ['#17a2b8', '#28a745', '#ffc107', '#6f42c1'] }] },
        options: { plugins: { legend: { position: 'bottom' } } }
    });
}

// Origin Chart
const originCtx = document.getElementById('containerOriginChart')?.getContext('2d');
if (originCtx && <?= count($origin_counts_data ?? []) ?> > 0) {
    const origins = [<?php foreach ($origin_counts_data ?? [] as $o) echo "'" . ucfirst($o['origin']) . "',"; ?>];
    const counts = [<?php foreach ($origin_counts_data ?? [] as $o) echo $o['count'] . ","; ?>];
    
    new Chart(originCtx, {
        type: 'bar',
        data: { labels: origins, datasets: [{ label: 'Containers', data: counts, backgroundColor: '#2D1859' }] },
        options: { responsive: true, scales: { y: { beginAtZero: true } } }
    });
}

$(document).ready(function() {
    $('#containerTable').DataTable({ pageLength: 25 });
});

function viewContainer(id) {
    window.open('view_container.php?id=' + id, '_blank');
}
</script>