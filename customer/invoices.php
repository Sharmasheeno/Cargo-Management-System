<?php
// customer/invoices.php
require_once __DIR__ . '/_auth.php';

// Get Customer ID
$stmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = ?");
$stmt->execute([$user_id]);
$customer_id = $stmt->fetchColumn();

if (!$customer_id) { echo "❌ Macamiil lama helin."; exit; }

// Get Invoices
$stmt = $pdo->prepare("
    SELECT * FROM invoices 
    WHERE customer_id = ? AND tenant_id = ?
    ORDER BY invoice_date DESC
");
$stmt->execute([$customer_id, $session_tenant_id]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-file-invoice-dollar text-primary"></i> Biilashayda (My Invoices)</h2>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="bg-light">
                        <tr>
                            <th>Invoice #</th>
                            <th>Taariikhda</th>
                            <th>Wadarta ($)</th>
                            <th>La bixiyay ($)</th>
                            <th>Haraaga ($)</th>
                            <th>Xaaladda</th>
                            <th>Falalka</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($invoices as $i): ?>
                            <tr>
                                <td><strong>#<?= htmlspecialchars($i['invoice_number']) ?></strong></td>
                                <td><?= date('d/m/Y', strtotime($i['invoice_date'])) ?></td>
                                <td>$<?= number_format($i['total_amount'], 2) ?></td>
                                <td>$<?= number_format($i['paid_amount'], 2) ?></td>
                                <td class="text-danger"><strong>$<?= number_format($i['total_amount'] - $i['paid_amount'], 2) ?></strong></td>
                                <td>
                                    <?php
                                    $status = $i['status'];
                                    $badge = 'badge-danger';
                                    if($status == 'paid') $badge = 'badge-success';
                                    if($status == 'partial') $badge = 'badge-warning';
                                    ?>
                                    <span class="badge <?= $badge ?>"><?= strtoupper($status) ?></span>
                                </td>
                                <td>
                                    <a href="../public_invoice.php?number=<?= urlencode($i['invoice_number']) ?>&token=<?= hash('sha256', $i['invoice_number'] . '|' . $i['id'] . '|' . $i['tenant_id'] . '|curdun-public-invoice-v1') ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-print"></i> Daabaco
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if(empty($invoices)): ?>
                            <tr><td colspan="7" class="text-center">Ma jiraan biilal kuu diiwangashan hadda.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
