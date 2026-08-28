<?php
// customer/tracking.php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../includes/shipment_functions.php';

// Get Customer ID
$stmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = ?");
$stmt->execute([$user_id]);
$customer_id = $stmt->fetchColumn();

if (!$customer_id) { echo "❌ Macamiil lama helin."; exit; }

// ---- Connected A→Z view: master shipments derived from operational truth ---
ensureShipmentSchema($pdo);
$shipStmt = $pdo->prepare("
    SELECT s.*, ob.branch_name AS origin_name, db.branch_name AS destination_name
    FROM shipments s
    LEFT JOIN branches ob ON ob.id = s.origin_branch_id
    LEFT JOIN branches db ON db.id = s.destination_branch_id
    WHERE s.customer_id = ? AND s.tenant_id = ? AND s.is_active = 1
    ORDER BY s.created_at DESC");
$shipStmt->execute([$customer_id, $session_tenant_id]);
$my_shipments = $shipStmt->fetchAll(PDO::FETCH_ASSOC);

// Latest public events per shipment (simplified customer timeline)
function customer_timeline(PDO $pdo, int $shipment_id): array {
    $ev = $pdo->prepare("SELECT event_type, new_status, location_label, notes, created_at
                         FROM shipment_events WHERE shipment_id = ? AND is_public = 1
                         ORDER BY created_at ASC, id ASC");
    $ev->execute([$shipment_id]);
    return $ev->fetchAll(PDO::FETCH_ASSOC);
}

// Get Packages (legacy warehouse view — kept for compatibility)
$stmt = $pdo->prepare("
    SELECT ws.*, c.container_number, c.status as container_status, c.current_location, c.estimated_arrival
    FROM warehouse_stock ws
    LEFT JOIN cargo_manifest_items cmi ON ws.id = cmi.warehouse_stock_id
    LEFT JOIN containers c ON cmi.container_id = c.id
    WHERE ws.customer_id = ? AND ws.tenant_id = ? AND ws.shipment_id IS NULL
    ORDER BY ws.last_updated DESC
");
$stmt->execute([$customer_id, $session_tenant_id]);
$packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-box text-primary"></i> Alaabtayda (My Shipments)</h2>
    </div>

    <?php if ($my_shipments): ?>
    <div class="row">
        <?php foreach ($my_shipments as $s):
            $timeline = customer_timeline($pdo, (int)$s['id']);
            $done = in_array($s['current_status'], ['DELIVERED','CLOSED'], true);
        ?>
        <div class="col-md-6 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong><?= htmlspecialchars($s['shipment_number']) ?>
                        <small class="text-muted"><?= htmlspecialchars($s['tracking_number'] ?? '') ?></small></strong>
                    <span class="badge <?= $done ? 'badge-success' : 'badge-warning' ?>">
                        <?= htmlspecialchars(customer_friendly_status($s['current_status'])) ?></span>
                </div>
                <div class="card-body">
                    <p class="mb-1"><strong><?= htmlspecialchars($s['origin_name'] ?? '') ?> → <?= htmlspecialchars($s['destination_name'] ?? '') ?></strong></p>
                    <p class="mb-2 text-muted">
                        <?= htmlspecialchars($s['cargo_description'] ?? '') ?> —
                        <?= (int)$s['quantity'] ?> pcs / <?= htmlspecialchars($s['weight_kg']) ?> kg
                    </p>
                    <ul class="list-unstyled mb-0" style="font-size:13px;">
                        <?php foreach ($timeline as $e): ?>
                            <li><i class="fas fa-check-circle text-success"></i>
                                <?= htmlspecialchars($e['created_at']) ?> —
                                <?= htmlspecialchars(!empty($e['new_status']) ? customer_friendly_status($e['new_status']) : $e['event_type']) ?>
                                <?= !empty($e['location_label']) ? ' @ ' . htmlspecialchars($e['location_label']) : '' ?>
                            </li>
                        <?php endforeach; ?>
                        <?php if (!$timeline): ?><li class="text-muted">No events yet.</li><?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

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
