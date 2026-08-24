<?php
// reports/warehouse_report.php
// Warehouse Report - Branch warehouse tracking

// Get warehouse data
$warehouse_capacity = 0;
$used_capacity = 0;
$available_space = 0;
$warehouse_list = [];

try {
    // Get warehouse stock by branch
    $stmt = $pdo->prepare("
        SELECT 
            b.id, b.branch_name, b.location,
            COALESCE(SUM(bs.volume_cbm), 0) as used_cbm,
            COUNT(DISTINCT bs.stock_id) as items_count
        FROM branches b
        LEFT JOIN branch_stock bs ON b.id = bs.branch_id
        WHERE b.tenant_id = ?
        GROUP BY b.id
        ORDER BY b.branch_name
    ");
    $stmt->execute([$session_tenant_id]);
    $warehouse_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_used = array_sum(array_column($warehouse_list, 'used_cbm'));
    $total_items = array_sum(array_column($warehouse_list, 'items_count'));
    
} catch (PDOException $e) {}
?>

<!-- Summary Cards -->
<div class="row">
    <div class="col-md-4">
        <div class="summary-card">
            <h4><i class="fas fa-warehouse"></i> Active Warehouses</h4>
            <div class="amount"><?= formatNumber(count($warehouse_list)) ?></div>
            <div class="subtext">Branch locations</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="summary-card">
            <h4><i class="fas fa-cube"></i> Total Occupied Space</h4>
            <div class="amount"><?= number_format($total_used ?? 0, 2) ?> CBM</div>
            <div class="subtext">Current storage usage</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="summary-card">
            <h4><i class="fas fa-box"></i> Total Stock Items</h4>
            <div class="amount"><?= formatNumber($total_items ?? 0) ?></div>
            <div class="subtext">Across all branches</div>
        </div>
    </div>
</div>

<!-- Warehouse List -->
<div class="chart-container mt-3">
    <div class="chart-title"><i class="fas fa-building"></i> Warehouse by Branch</div>
    <div class="table-responsive">
        <table class="data-table" id="warehouseTable">
            <thead>
                <tr>
                    <th>Branch Name</th><th>Location</th>
                    <th class="text-end">Items</th><th class="text-end">Used CBM</th>
                    <th class="text-end">Capacity</th><th>Utilization</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($warehouse_list as $wh): 
                    $capacity = 1000; // Default capacity, should come from branch settings
                    $utilization = $capacity > 0 ? ($wh['used_cbm'] / $capacity) * 100 : 0;
                    $util_class = $utilization > 80 ? 'bg-danger' : ($utilization > 60 ? 'bg-warning' : 'bg-success');
                ?>
                <tr>
                    <td><?= htmlspecialchars($wh['branch_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($wh['location'] ?? '-') ?></td>
                    <td class="text-end"><?= formatNumber($wh['items_count']) ?></td>
                    <td class="text-end"><?= number_format($wh['used_cbm'], 2) ?> CBM</td>
                    <td class="text-end"><?= number_format($capacity, 2) ?> CBM</td>
                    <td>
                        <div style="width: 100px;">
                            <div class="aging-progress">
                                <div class="aging-bar <?= $util_class ?>" style="width: <?= $utilization ?>%;"></div>
                            </div>
                            <small><?= number_format($utilization, 1) ?>%</small>
                        </div>
                    </td>
                    <td><button class="btn-action" onclick="viewWarehouse(<?= $wh['id'] ?>)"><i class="fas fa-eye"></i></button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#warehouseTable').DataTable({ pageLength: 25 });
});

function viewWarehouse(id) {
    window.open('view_warehouse.php?id=' + id, '_blank');
}
</script>