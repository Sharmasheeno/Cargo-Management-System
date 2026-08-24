<?php
// reports/inventory_report.php
// Inventory/Warehouse Stock Report

// Get inventory data
$total_items = 0;
$total_quantity = 0;
$total_cbm = 0;
$total_value = 0;
$low_stock_items = 0;
$inventory_list = [];

try {
    // Summary totals
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as items,
            COALESCE(SUM(quantity), 0) as total_qty,
            COALESCE(SUM(volume_cbm), 0) as total_cbm,
            COALESCE(SUM(volume_cbm * unit_price), 0) as total_value,
            SUM(CASE WHEN quantity <= minimum_stock AND minimum_stock > 0 THEN 1 ELSE 0 END) as low_stock
        FROM warehouse_stock 
        WHERE tenant_id = ?
    ");
    $stmt->execute([$session_tenant_id]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_items = $summary['items'];
    $total_quantity = $summary['total_qty'];
    $total_cbm = $summary['total_cbm'];
    $total_value = $summary['total_value'];
    $low_stock_items = $summary['low_stock'];
    
    // Build WHERE clause
    $where = "ws.tenant_id = ?";
    $params = [$session_tenant_id];
    
    if ($customer_id) {
        $where .= " AND ws.customer_id = ?";
        $params[] = $customer_id;
    }
    
    // Inventory by origin
    $stmt = $pdo->prepare("
        SELECT origin, 
               COUNT(*) as items,
               COALESCE(SUM(quantity), 0) as qty,
               COALESCE(SUM(volume_cbm), 0) as cbm,
               COALESCE(SUM(volume_cbm * unit_price), 0) as value
        FROM warehouse_stock 
        WHERE tenant_id = ?
        GROUP BY origin
    ");
    $stmt->execute([$session_tenant_id]);
    $inventory_by_origin = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Low stock alerts
    $stmt = $pdo->prepare("
        SELECT ws.*, c.customer_name 
        FROM warehouse_stock ws
        LEFT JOIN customers c ON ws.customer_id = c.id
        WHERE ws.tenant_id = ? AND ws.quantity <= ws.minimum_stock AND ws.minimum_stock > 0
        ORDER BY (ws.quantity / ws.minimum_stock) ASC
        LIMIT 50
    ");
    $stmt->execute([$session_tenant_id]);
    $low_stock_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Stock movements (last 30 days)
    $stmt = $pdo->prepare("
        SELECT DATE(movement_date) as date,
               SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE 0 END) as stock_in,
               SUM(CASE WHEN movement_type = 'out' THEN quantity ELSE 0 END) as stock_out
        FROM stock_movements 
        WHERE tenant_id = ? AND movement_date BETWEEN DATE_SUB(NOW(), INTERVAL 30 DAY) AND NOW()
        GROUP BY DATE(movement_date)
        ORDER BY date ASC
    ");
    $stmt->execute([$session_tenant_id]);
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Inventory list
    $stmt = $pdo->prepare("
        SELECT ws.*, c.customer_name 
        FROM warehouse_stock ws
        LEFT JOIN customers c ON ws.customer_id = c.id
        WHERE $where
        ORDER BY ws.volume_cbm DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $inventory_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {}
?>

<!-- Summary Cards -->
<div class="row">
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-boxes"></i> Total Items</h4>
            <div class="amount"><?= formatNumber($total_items) ?></div>
            <div class="subtext">Unique SKUs</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-cubes"></i> Total Quantity</h4>
            <div class="amount"><?= formatNumber($total_quantity) ?></div>
            <div class="subtext">Units in stock</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-cube"></i> Total Volume</h4>
            <div class="amount"><?= number_format($total_cbm, 2) ?> CBM</div>
            <div class="subtext">Space occupied</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card">
            <h4><i class="fas fa-dollar-sign"></i> Inventory Value</h4>
            <div class="amount"><?= formatMoney($total_value) ?></div>
            <div class="subtext">Total asset value</div>
        </div>
    </div>
</div>

<div class="row mt-3">
    <div class="col-md-6">
        <div class="summary-card">
            <h4><i class="fas fa-exclamation-triangle"></i> Low Stock Alerts</h4>
            <div class="amount" style="color: var(--curdun-danger);"><?= formatNumber($low_stock_items) ?></div>
            <div class="subtext">Items below minimum stock level</div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="summary-card">
            <h4><i class="fas fa-chart-line"></i> Inventory Turnover</h4>
            <?php
            // Simple turnover calculation (movements / average stock)
            $avg_stock = $total_quantity > 0 ? $total_quantity / 2 : 0;
            $turnover = $avg_stock > 0 ? ($total_quantity / $avg_stock) : 0;
            ?>
            <div class="amount"><?= number_format($turnover, 2) ?>x</div>
            <div class="subtext">Turnover rate</div>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="row mt-4">
    <div class="col-md-6">
        <div class="chart-container">
            <div class="chart-title"><i class="fas fa-chart-pie"></i> Inventory by Origin</div>
            <canvas id="originChart" height="250"></canvas>
        </div>
    </div>
    <div class="col-md-6">
        <div class="chart-container">
            <div class="chart-title"><i class="fas fa-chart-line"></i> Stock Movement (Last 30 Days)</div>
            <canvas id="movementChart" height="250"></canvas>
        </div>
    </div>
</div>

<!-- Low Stock Alerts -->
<?php if (count($low_stock_alerts) > 0): ?>
<div class="chart-container mt-3" style="border-left: 4px solid var(--curdun-danger);">
    <div class="chart-title"><i class="fas fa-bell"></i> Low Stock Alerts</div>
    <div class="table-responsive">
        <table class="data-table">
            <thead><tr><th>Item</th><th>Customer</th><th>Current Qty</th><th>Min Stock</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($low_stock_alerts as $alert): ?>
                <tr style="background: #fff3f3;">
                    <td><?= htmlspecialchars($alert['item_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($alert['customer_name'] ?? '-') ?></td>
                    <td class="text-danger"><?= formatNumber($alert['quantity']) ?></td>
                    <td><?= formatNumber($alert['minimum_stock']) ?></td>
                    <td><span class="badge bg-danger">Reorder Needed</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Inventory List -->
<div class="chart-container mt-3">
    <div class="chart-title"><i class="fas fa-list"></i> Complete Inventory</div>
    <div class="table-responsive">
        <table class="data-table" id="inventoryTable">
            <thead>
                <tr>
                    <th>Item</th><th>Customer</th><th>Origin</th>
                    <th class="text-end">Qty</th><th class="text-end">CBM</th>
                    <th class="text-end">Unit Price</th><th class="text-end">Total Value</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inventory_list as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['item_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($item['customer_name'] ?? '-') ?></td>
                    <td><?= ucfirst($item['origin'] ?? '-') ?></td>
                    <td class="text-end"><?= formatNumber($item['quantity']) ?></td>
                    <td class="text-end"><?= number_format($item['volume_cbm'] ?? 0, 2) ?></td>
                    <td class="text-end"><?= formatMoney($item['unit_price'] ?? 0) ?></td>
                    <td class="text-end"><?= formatMoney(($item['volume_cbm'] ?? 0) * ($item['unit_price'] ?? 0)) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Origin Chart
const originCtx = document.getElementById('originChart')?.getContext('2d');
if (originCtx && <?= count($inventory_by_origin) ?> > 0) {
    const origins = [<?php foreach ($inventory_by_origin as $o) echo "'" . ucfirst($o['origin']) . "',"; ?>];
    const values = [<?php foreach ($inventory_by_origin as $o) echo $o['value'] . ","; ?>];
    
    new Chart(originCtx, {
        type: 'doughnut',
        data: { labels: origins, datasets: [{ data: values, backgroundColor: ['#1565c0', '#0d47a1', '#e65100', '#2e7d32', '#6f42c1'] }] },
        options: { plugins: { legend: { position: 'bottom' } } }
    });
}

// Movement Chart
const movementCtx = document.getElementById('movementChart')?.getContext('2d');
if (movementCtx && <?= count($movements) ?> > 0) {
    const dates = [<?php foreach ($movements as $m) echo "'" . date('d/m', strtotime($m['date'])) . "',"; ?>];
    const stockIn = [<?php foreach ($movements as $m) echo $m['stock_in'] . ","; ?>];
    const stockOut = [<?php foreach ($movements as $m) echo $m['stock_out'] . ","; ?>];
    
    new Chart(movementCtx, {
        type: 'line',
        data: { labels: dates, datasets: [
            { label: 'Stock In', data: stockIn, borderColor: '#0F7A3A', fill: false },
            { label: 'Stock Out', data: stockOut, borderColor: '#B42318', fill: false }
        ]},
        options: { responsive: true, scales: { y: { beginAtZero: true, title: { display: true, text: 'Quantity' } } } }
    });
}

$(document).ready(function() {
    $('#inventoryTable').DataTable({ pageLength: 25 });
});
</script>