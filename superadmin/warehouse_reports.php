<?php
// superadmin/warehouse_reports.php
if (session_status() === PHP_SESSION_NONE)
    session_start();
// Check if user is logged in and is superadmin or company_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['superadmin', 'company_admin'])) {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION['role'];
$session_tenant_id = $_SESSION['tenant_id'] ?? 0;
require_once __DIR__ . '/../config/db_connect.php';

$user_id = $_SESSION['user_id'];
$tenants = $pdo->query("SELECT id, name FROM tenants ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$tenant_filter = ($role === 'company_admin') ? $session_tenant_id : (isset($_GET['tenant']) ? (int) $_GET['tenant'] : 0);
$origin_filter = $_GET['origin'] ?? 'all';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');

// WHERE clause
$where = [];
$params = [];
if ($tenant_filter > 0) {
    $where[] = "ws.tenant_id = ?";
    $params[] = $tenant_filter;
}
if ($origin_filter !== 'all') {
    $where[] = "ws.origin = ?";
    $params[] = $origin_filter;
}
$wc = $where ? "WHERE " . implode(" AND ", $where) : "";

// Summary Stats
$stmt = $pdo->prepare("SELECT COUNT(*) as items, COALESCE(SUM(quantity),0) as total_qty, COALESCE(SUM(volume_cbm),0) as total_cbm, COALESCE(SUM(volume_cbm*unit_price),0) as total_value, SUM(CASE WHEN quantity <= minimum_stock AND minimum_stock > 0 THEN 1 ELSE 0 END) as low_stock FROM warehouse_stock ws $wc");
$stmt->execute($params);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

// By Origin
$stmt2 = $pdo->prepare("SELECT origin, COUNT(*) as items, COALESCE(SUM(quantity),0) as qty, COALESCE(SUM(volume_cbm),0) as cbm, COALESCE(SUM(volume_cbm*unit_price),0) as value FROM warehouse_stock ws $wc GROUP BY origin");
$stmt2->execute($params);
$by_origin = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// By Tenant
$stmt3 = $pdo->query("SELECT t.name, COUNT(ws.id) as items, COALESCE(SUM(ws.quantity),0) as qty, COALESCE(SUM(ws.volume_cbm),0) as cbm, COALESCE(SUM(ws.volume_cbm*ws.unit_price),0) as value FROM warehouse_stock ws LEFT JOIN tenants t ON ws.tenant_id = t.id GROUP BY ws.tenant_id, t.name ORDER BY value DESC");
$by_tenant = $stmt3->fetchAll(PDO::FETCH_ASSOC);

// All stock items
$stmt4 = $pdo->prepare("SELECT ws.*, t.name as tenant_name, c.customer_name FROM warehouse_stock ws LEFT JOIN tenants t ON ws.tenant_id = t.id LEFT JOIN customers c ON ws.customer_id = c.id $wc ORDER BY ws.volume_cbm DESC");
$stmt4->execute($params);
$all_items = $stmt4->fetchAll(PDO::FETCH_ASSOC);

// Recent movements
$mv_where = $tenant_filter > 0 ? "WHERE sm.tenant_id = $tenant_filter" : "";
$movements = $pdo->query("SELECT sm.*, ws.stock_name, u.full_name as by_name FROM stock_movements sm LEFT JOIN warehouse_stock ws ON sm.warehouse_stock_id = ws.id LEFT JOIN users u ON sm.created_by = u.id $mv_where ORDER BY sm.created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="warehouse_report_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Cargo Management System - WAREHOUSE REPORT', date('Y-m-d H:i:s')]);
    fputcsv($out, []);
    fputcsv($out, ['#', 'Stock Name', 'Origin', 'Qty', 'CBM', 'Unit Price', 'Total Value', 'Location', 'Customer', 'Company']);
    foreach ($all_items as $i => $item) {
        fputcsv($out, [$i + 1, $item['stock_name'], $item['origin'], $item['quantity'], $item['volume_cbm'], $item['unit_price'], $item['volume_cbm'] * $item['unit_price'], $item['location'], $item['customer_name'] ?? '', $item['tenant_name'] ?? '']);
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>
<!DOCTYPE html>
<html lang="so">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse Reports | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --cv: #2D1859;
            --cvl: #4B2C85;
            --cy: #F5C410;
            --cg: #6c757d;
            --cd: #2D2D2D;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f4f6f9;
            font-family: 'Segoe UI', sans-serif;
        }

        .page-header {
            background: linear-gradient(135deg, var(--cv), var(--cvl));
            border-radius: 16px;
            padding: 20px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-header h1 {
            color: white;
            font-size: 22px;
            margin: 0;
        }

        .btn-export {
            background: #F5C410;
            color: #2D1859;
            border: none;
            padding: 9px 18px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-export:hover {
            background: #e0c900;
        }

        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 22px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 140px;
        }

        .filter-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--cg);
            margin-bottom: 5px;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }

        .btn-filter {
            background: var(--cv);
            color: white;
            border: none;
            padding: 9px 20px;
            border-radius: 8px;
            cursor: pointer;
        }

        .btn-reset {
            background: #f0f0f0;
            color: var(--cd);
            border: none;
            padding: 9px 16px;
            border-radius: 8px;
            margin-left: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 15px;
            margin-bottom: 22px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 18px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            border-left: 4px solid var(--cv);
        }

        .stat-card h4 {
            font-size: 11px;
            color: var(--cg);
            text-transform: uppercase;
            margin: 0 0 8px;
        }

        .stat-card .num {
            font-size: 26px;
            font-weight: 700;
            color: var(--cv);
        }

        .stat-card.danger {
            border-left-color: #B42318;
        }

        .stat-card.danger .num {
            color: #B42318;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 22px;
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, var(--cv), var(--cvl));
            color: white;
            padding: 14px 18px;
            font-size: 15px;
            font-weight: 600;
        }

        .card-header i {
            margin-right: 8px;
        }

        .card-body {
            padding: 18px;
        }

        table.rt {
            width: 100%;
            border-collapse: collapse;
        }

        table.rt th,
        table.rt td {
            padding: 10px 14px;
            border-bottom: 1px solid #eee;
            font-size: 13px;
            text-align: left;
        }

        table.rt th {
            background: #f8f6f9;
            font-weight: 600;
            color: var(--cd);
        }

        table.rt tr:hover {
            background: #faf8fb;
        }

        .badge-origin {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        .origin-china {
            background: #e3f2fd;
            color: #1565c0;
        }

        .origin-china_yiwu {
            background: #e3f2fd;
            color: #1565c0;
        }

        .origin-china_guangzhou {
            background: #dceefb;
            color: #0d47a1;
        }

        .origin-dubai {
            background: #fff3e0;
            color: #e65100;
        }

        .origin-local {
            background: #EEFBF3;
            color: #0F7A3A;
        }

        .low-row {
            background: rgba(198, 40, 40, 0.05) !important;
        }

        .mv-in {
            color: #0F7A3A;
            font-weight: 600;
        }

        .mv-out {
            color: #B42318;
            font-weight: 600;
        }

        .mv-adjust {
            color: #0288d1;
            font-weight: 600;
        }

        @media print {

            .no-print,
            .page-header,
            .filters-card {
                display: none !important;
            }

            body {
                background: white;
            }

            .curdun-header,
            .curdun-sidebar,
            footer {
                display: none !important;
            }

            .curdun-main-content {
                margin: 0 !important;
            }
        }

        @media (max-width: 768px) {
            .filter-form {
                flex-direction: column;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid" style="padding: 20px;">

        <!-- Header -->
        <div class="page-header no-print">
            <h1><i class="fas fa-warehouse"></i> Warehouse Reports</h1>
            <div class="d-flex gap-2" style="gap: 10px;">
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn-export">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
                <button onclick="window.print()" class="btn-export" style="background: #17a2b8; color: white;">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card no-print">
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <label><i class="fas fa-building"></i> Shirkadda</label>
                    <select name="tenant">
                        <option value="0">Dhammaan</option>
                        <?php foreach ($tenants as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= $tenant_filter == $t['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-map-marker-alt"></i> Asalka</label>
                    <select name="origin">
                        <option value="all">Dhammaan</option>
                        <option value="china_yiwu" <?= $origin_filter == 'china_yiwu' ? 'selected' : '' ?>>China Yiwu 🇨🇳
                        </option>
                        <option value="china_guangzhou" <?= $origin_filter == 'china_guangzhou' ? 'selected' : '' ?>>China
                            Guangzhou 🇨🇳</option>
                        <option value="dubai" <?= $origin_filter == 'dubai' ? 'selected' : '' ?>>Dubay 🇦🇪</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Shaandheey</button>
                    <a href="warehouse_reports.php" class="btn-reset"><i class="fas fa-undo"></i> Nadiifi</a>
                </div>
            </form>
        </div>

        <!-- Print Header -->
        <div style="text-align:center; margin-bottom:20px; display:none;" class="print-only">
            <h2 style="color:#2D1859;">Cargo Management System</h2>
            <h3>WAREHOUSE INVENTORY REPORT</h3>
            <p style="color:#6c757d; font-size:12px;">Generated: <?= date('d/m/Y H:i:s') ?></p>
        </div>

        <!-- Summary Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <h4><i class="fas fa-boxes"></i> Wadarta Noocyada</h4>
                <div class="num"><?= number_format($summary['items']) ?></div>
            </div>
            <div class="stat-card">
                <h4><i class="fas fa-cubes"></i> Wadarta Tirada</h4>
                <div class="num"><?= number_format($summary['total_qty']) ?></div>
            </div>
            <div class="stat-card">
                <h4><i class="fas fa-cube"></i> Wadarta Mugga</h4>
                <div class="num"><?= number_format($summary['total_cbm'], 2) ?> <small>CBM/FT</small></div>
            </div>
            <div class="stat-card">
                <h4><i class="fas fa-dollar-sign"></i> Wadarta Qiimaha</h4>
                <div class="num">$<?= number_format($summary['total_value'], 2) ?></div>
            </div>
            <div class="stat-card danger">
                <h4><i class="fas fa-exclamation-triangle"></i> Digniin (Hoos)</h4>
                <div class="num"><?= number_format($summary['low_stock']) ?></div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="row no-print">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-pie"></i> Alaabta Asalkooda</div>
                    <div class="card-body"
                        style="height:260px; display:flex; align-items:center; justify-content:center;">
                        <canvas id="originChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-bar"></i> Qiimaha Shirkad kasta</div>
                    <div class="card-body"
                        style="height:260px; display:flex; align-items:center; justify-content:center;">
                        <canvas id="tenantChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Origin Breakdown Table -->
        <div class="card">
            <div class="card-header"><i class="fas fa-globe"></i> Asalka Alaabta</div>
            <div class="card-body p-0">
                <table class="rt">
                    <thead>
                        <tr>
                            <th>Asalka</th>
                            <th>Noocyada</th>
                            <th>Wadarta Tirada</th>
                            <th>Mugga (CBM/FT)</th>
                            <th>Qiimaha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($by_origin as $o):
                            $ol = $o['origin'];
                            $on_map = [
                                'china_yiwu' => 'China Yiwu',
                                'china_guangzhou' => 'China Guangzhou',
                                'dubai' => 'Dubay'
                            ];
                            $on = $on_map[$ol] ?? $ol;
                            $flag = strpos($ol, 'china') !== false ? '🇨🇳' : ($ol === 'dubai' ? '🇦🇪' : '📦');
                            ?>
                            <tr>
                                <td><span class="badge-origin origin-<?= $ol ?>"><?= $flag ?>     <?= $on ?></span></td>
                                <td><?= number_format($o['items']) ?></td>
                                <td><?= number_format($o['qty']) ?></td>
                                <td><?= number_format($o['cbm'], 2) ?>     <?= ($o['origin'] === 'dubai' ? 'FT' : 'CBM') ?></td>
                                <td><strong>$<?= number_format($o['value'], 2) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- All Stock Items -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Dhammaan Alaabta Bakhaarka (<?= count($all_items) ?>)
            </div>
            <div class="card-body p-0" style="overflow-x:auto;">
                <table class="rt" style="min-width:900px;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Magaca Alaabta</th>
                            <th>Asalka</th>
                            <th>Tirada</th>
                            <th>Cabbirka</th>
                            <th>Qiimaha/Unit</th>
                            <th>Wadarta</th>
                            <th>Goobta</th>
                            <th>Macaamilka</th>
                            <th>Shirkadda</th>
                            <th>Xaaladda</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($all_items) > 0): ?>
                            <?php foreach ($all_items as $i => $item):
                                $low = $item['minimum_stock'] > 0 && $item['quantity'] <= $item['minimum_stock'];
                                $ol = $item['origin'];
                                $on_map = [
                                    'china_yiwu' => 'China Yiwu',
                                    'china_guangzhou' => 'China Guangzhou',
                                    'dubai' => 'Dubay'
                                ];
                                $on = $on_map[$ol] ?? $ol;
                                $flag = strpos($ol, 'china') !== false ? '🇨🇳' : ($ol === 'dubai' ? '🇦🇪' : '📦');
                                ?>
                                <tr class="<?= $low ? 'low-row' : '' ?>">
                                    <td><?= $i + 1 ?></td>
                                    <td><strong><?= htmlspecialchars($item['stock_name']) ?></strong><br>
                                        <small style="color:#6c757d;">SKU:
                                            STK-<?= str_pad($item['id'], 5, '0', STR_PAD_LEFT) ?></small>
                                    </td>
                                    <td><span class="badge-origin origin-<?= $ol ?>"><?= $flag ?>         <?= $on ?></span></td>
                                    <td><strong
                                            class="<?= $low ? 'text-danger' : 'text-success' ?>"><?= number_format($item['quantity']) ?></strong>
                                    </td>
                                    <td><?= number_format($item['volume_cbm'], 2) ?>
                                        <?= ($item['origin'] === 'dubai' ? 'FT' : 'CBM') ?></td>
                                    <td>$<?= number_format($item['unit_price'], 2) ?></td>
                                    <td><strong>$<?= number_format($item['volume_cbm'] * $item['unit_price'], 2) ?></strong>
                                    </td>
                                    <td><?= htmlspecialchars($item['location'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($item['customer_name'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($item['tenant_name'] ?? '-') ?></td>
                                    <td>
                                        <?php if ($low): ?>
                                            <span
                                                style="background:#FEF0EE; color:#B42318; padding:3px 8px; border-radius:10px; font-size:11px; font-weight:600;"><i
                                                    class="fas fa-exclamation-triangle"></i> Digniin</span>
                                        <?php else: ?>
                                            <span
                                                style="background:#EEFBF3; color:#0F7A3A; padding:3px 8px; border-radius:10px; font-size:11px; font-weight:600;"><i
                                                    class="fas fa-check"></i> Fiican</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" style="text-align:center; padding:40px; color:#6c757d;"><i
                                        class="fas fa-warehouse"
                                        style="font-size:40px; display:block; margin-bottom:10px; opacity:0.4;"></i>Ma
                                    jiraan wax alaab ah</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Movements -->
        <div class="card">
            <div class="card-header"><i class="fas fa-history"></i> Dhaqdhaqaaqii Ugu Dambeeyay (20)</div>
            <div class="card-body p-0" style="overflow-x:auto;">
                <table class="rt" style="min-width:700px;">
                    <thead>
                        <tr>
                            <th>Taariikhda</th>
                            <th>Alaabta</th>
                            <th>Nooca</th>
                            <th>Isbedelka</th>
                            <th>Hore</th>
                            <th>Cusub</th>
                            <th>Qoraal</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($movements) > 0): ?>
                            <?php foreach ($movements as $m):
                                $tc = $m['movement_type'] === 'in' ? 'mv-in' : ($m['movement_type'] === 'out' ? 'mv-out' : 'mv-adjust');
                                $ti = $m['movement_type'] === 'in' ? '↑ Soo Galay' : ($m['movement_type'] === 'out' ? '↓ Baxay' : ($m['movement_type'] === 'move' ? '↔ La Raray' : '⇄ Beddelid'));
                                ?>
                                <tr>
                                    <td><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></td>
                                    <td><strong><?= htmlspecialchars($m['stock_name'] ?? '-') ?></strong></td>
                                    <td><span class="<?= $tc ?>"><?= $ti ?></span></td>
                                    <td class="<?= $tc ?>">
                                        <?= $m['movement_type'] === 'in' ? '+' : ($m['movement_type'] === 'out' ? '-' : '±') ?>        <?= abs($m['quantity_change']) ?>
                                    </td>
                                    <td><?= number_format($m['previous_quantity']) ?></td>
                                    <td><?= number_format($m['new_quantity']) ?></td>
                                    <td style="max-width:200px; font-size:12px;">
                                        <?= htmlspecialchars(substr($m['notes'] ?? '-', 0, 60)) ?></td>
                                    <td><?= htmlspecialchars($m['by_name'] ?? 'System') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align:center; padding:30px; color:#6c757d;">Ma jiraan
                                    dhaqdhaqaaqyo</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /container -->

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Origin Pie Chart
        const originData = <?= json_encode(array_map(fn($o) => [
            'label' => ($o['origin'] === 'china_yiwu' ? 'China Yiwu' : ($o['origin'] === 'china_guangzhou' ? 'China Guangzhou' : ($o['origin'] === 'dubai' ? 'Dubay' : $o['origin']))),
            'value' => (float) $o['qty']
        ], $by_origin)) ?>;

        if (originData.length > 0) {
            new Chart(document.getElementById('originChart'), {
                type: 'doughnut',
                data: {
                    labels: originData.map(d => d.label),
                    datasets: [{ data: originData.map(d => d.value), backgroundColor: ['#1565c0', '#0d47a1', '#e65100', '#2e7d32', '#7b1fa2'], borderWidth: 2 }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });
        }

        // Tenant Bar Chart
        const tenantData = <?= json_encode(array_map(fn($t) => ['label' => $t['name'] ?? 'N/A', 'value' => round((float) $t['value'], 2)], $by_tenant)) ?>;
        if (tenantData.length > 0) {
            new Chart(document.getElementById('tenantChart'), {
                type: 'bar',
                data: {
                    labels: tenantData.map(d => d.label),
                    datasets: [{ label: 'Qiimaha ($)', data: tenantData.map(d => d.value), backgroundColor: '#2D1859', borderRadius: 6 }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
            });
        }
    </script>

    <?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>

</html>