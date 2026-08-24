<?php
// customer/tracking.php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';
$user_id = $_SESSION['user_id'];

// Get Customer ID
$stmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = ?");
$stmt->execute([$user_id]);
$customer_id = $stmt->fetchColumn();

if (!$customer_id) { echo "❌ Macamiil lama helin."; exit; }

// Get Packages
$stmt = $pdo->prepare("
    SELECT ws.*, c.container_number, c.status as container_status, c.current_location, c.estimated_arrival
    FROM warehouse_stock ws
    LEFT JOIN cargo_manifest_items cmi ON ws.id = cmi.warehouse_stock_id
    LEFT JOIN containers c ON cmi.container_id = c.id
    WHERE ws.customer_id = ?
    ORDER BY ws.last_updated DESC
");
$stmt->execute([$customer_id]);
$packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-box text-primary"></i> Alaabtayda (My Packages)</h2>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="bg-light">
                        <tr>
                            <th>Alaabta</th>
                            <th>Tirada</th>
                            <th>CBM</th>
                            <th>Xaaladda</th>
                            <th>Kontaynarka</th>
                            <th>Halka ay joogto</th>
                            <th>ETA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($packages as $p): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($p['stock_name']) ?></strong></td>
                                <td><?= $p['quantity'] ?></td>
                                <td><?= number_format($p['volume_cbm'], 2) ?></td>
                                <td>
                                    <?php
                                    $status = $p['mogadishu_status'];
                                    $badge = 'badge-secondary';
                                    if($status == 'delivered') $badge = 'badge-success';
                                    if($status == 'in_warehouse') $badge = 'badge-warning';
                                    ?>
                                    <span class="badge <?= $badge ?>"><?= strtoupper($status) ?></span>
                                </td>
                                <td><?= htmlspecialchars($p['container_number'] ?? 'Wali lama rurin') ?></td>
                                <td><?= htmlspecialchars($p['current_location'] ?? ($p['container_number'] ? 'Waddada' : 'Bakhaarka')) ?></td>
                                <td><?= $p['estimated_arrival'] ? date('d/m/Y', strtotime($p['estimated_arrival'])) : '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if(empty($packages)): ?>
                            <tr><td colspan="7" class="text-center">Ma jiro wax alaab ah oo kuu diiwangashan hadda.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
