<?php
// public_invoice.php - Publicly viewable invoice for sharing via WhatsApp
require_once __DIR__ . '/config/db_connect.php';

$invoice_number = $_GET['number'] ?? '';

if (empty($invoice_number)) {
    die("<h1>Khalad: Lambarka biilka waa lagama maarmaan.</h1>");
}

try {
    $stmt = $pdo->prepare("
        SELECT i.*, 
               c.customer_name, c.phone as customer_phone, c.address as customer_address, c.email as customer_email,
               t.name as tenant_name, t.phone as tenant_phone, t.email as tenant_email, t.address as tenant_address, t.logo as tenant_logo,
               tr.trip_number, tr.scheduled_date as trip_date
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        LEFT JOIN tenants t ON i.tenant_id = t.id
        LEFT JOIN trucking_trips tr ON i.trip_id = tr.id
        WHERE i.invoice_number = ?
    ");
    $stmt->execute([$invoice_number]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$inv) {
        die("<h1>Khalad: Biilkan lama helin.</h1>");
    }
} catch (PDOException $e) {
    die("<h1>Khalad ayaa dhacay. Fadlan isku day mar kale.</h1>");
}

$dueAmount = $inv['total_amount'] - $inv['paid_amount'];
?>
<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Biilka #<?= htmlspecialchars($inv['invoice_number']) ?> - <?= htmlspecialchars($inv['tenant_name']) ?></title>
    <link rel="icon" type="image/png" href="assets/images/curdun-favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2D1859;
            --secondary: #F5C410;
            --text-dark: #333;
            --text-light: #666;
            --border: #E9E7F1;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #F4F5F9;
            color: var(--text-dark);
            line-height: 1.6;
        }
        .invoice-wrapper {
            max-width: 900px;
            margin: 40px auto;
            background: #white;
            background: white;
            padding: 50px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border-radius: 8px;
            position: relative;
        }
        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 40px;
            border-bottom: 3px solid var(--primary);
            padding-bottom: 20px;
        }
        .logo-section h1 {
            color: var(--primary);
            font-size: 32px;
            margin-bottom: 5px;
        }
        .invoice-title {
            text-align: right;
        }
        .invoice-title h2 {
            font-size: 36px;
            color: #999;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .invoice-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
        }
        .meta-box h4 {
            color: var(--primary);
            text-transform: uppercase;
            font-size: 14px;
            margin-bottom: 10px;
            border-bottom: 2px solid var(--secondary);
            display: inline-block;
        }
        .table-section {
            margin-bottom: 40px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            background: #F4F5F9;
            text-align: left;
            padding: 12px 15px;
            border-bottom: 2px solid var(--border);
            color: var(--primary);
            font-size: 14px;
        }
        td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }
        .summary-section {
            display: flex;
            justify-content: flex-end;
        }
        .summary-box {
            width: 300px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .summary-row.total {
            border-bottom: none;
            margin-top: 10px;
            background: var(--primary);
            color: white;
            padding: 10px 15px;
            border-radius: 4px;
        }
        .summary-row.balance {
            background: #fff3e0;
            color: #e65100;
            margin-top: 5px;
            padding: 10px 15px;
            border-radius: 4px;
            font-weight: bold;
        }
        .footer-note {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            text-align: center;
            font-size: 12px;
            color: #999;
        }
        .status-stamp {
            position: absolute;
            top: 150px;
            right: 50px;
            transform: rotate(-15deg);
            padding: 10px 20px;
            border: 5px solid;
            border-radius: 10px;
            font-size: 24px;
            font-weight: bold;
            text-transform: uppercase;
            opacity: 0.2;
            z-index: 0;
        }
        .status-paid { color: #0F7A3A; border-color: #0F7A3A; }
        .status-unpaid { color: #B42318; border-color: #B42318; }
        .status-partial { color: #ef6c00; border-color: #ef6c00; }

        @media print {
            body { background: white; }
            .invoice-wrapper { box-shadow: none; margin: 0; max-width: 100%; }
            .no-print { display: none; }
        }
        .no-print {
            text-align: center;
            margin-bottom: 20px;
        }
        .btn-print {
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 50px;
            cursor: pointer;
            font-weight: 600;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>

<div style="padding: 20px;">
    <div class="no-print">
        <button class="btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print as PDF</button>
    </div>

    <div class="invoice-wrapper">
        <div class="status-stamp status-<?= $inv['status'] ?>">
            <?= $inv['status'] == 'paid' ? 'Paid' : ($inv['status'] == 'unpaid' ? 'Unpaid' : 'Partial') ?>
        </div>

        <div class="invoice-header">
            <div class="logo-section">
                <h1><?= htmlspecialchars($inv['tenant_name']) ?></h1>
                <p><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($inv['tenant_address'] ?? 'Mogadishu, Somalia') ?></p>
                <p><i class="fas fa-phone"></i> <?= htmlspecialchars($inv['tenant_phone'] ?? '-') ?> | <i class="fas fa-envelope"></i> <?= htmlspecialchars($inv['tenant_email'] ?? '-') ?></p>
            </div>
            <div class="invoice-title">
                <h2>Invoice</h2>
                <p><strong>#<?= htmlspecialchars($inv['invoice_number']) ?></strong></p>
                <p>Date: <?= date('d/m/Y', strtotime($inv['invoice_date'])) ?></p>
                <p>Due: <?= date('d/m/Y', strtotime($inv['due_date'])) ?></p>
            </div>
        </div>

        <div class="invoice-meta">
            <div class="meta-box">
                <h4>Bill To:</h4>
                <p><strong><?= htmlspecialchars($inv['customer_name']) ?></strong></p>
                <p><?= htmlspecialchars($inv['customer_address'] ?? '-') ?></p>
                <p><?= htmlspecialchars($inv['customer_phone'] ?? '-') ?></p>
                <p><?= htmlspecialchars($inv['customer_email'] ?? '-') ?></p>
            </div>
            <div class="meta-box" style="text-align: right;">
                <h4>Shipment Info:</h4>
                <p>Trip: <strong><?= htmlspecialchars($inv['trip_number'] ?? 'N/A') ?></strong></p>
                <p>Date: <?= $inv['trip_date'] ? date('d/m/Y', strtotime($inv['trip_date'])) : 'N/A' ?></p>
                <p>CBM: <?= number_format($inv['total_cbm'], 2) ?> CBM</p>
            </div>
        </div>

        <div class="table-section">
            <table>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th style="text-align: center;">CBM</th>
                        <th style="text-align: right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <strong>Cargo Shipment Charges</strong><br>
                            <small>Shipping service from origin to destination for invoice #<?= htmlspecialchars($inv['invoice_number']) ?></small>
                        </td>
                        <td style="text-align: center;"><?= number_format($inv['total_cbm'], 2) ?></td>
                        <td style="text-align: right;">$<?= number_format($inv['subtotal'], 2) ?></td>
                    </tr>
                    <?php if ($inv['tax'] > 0): ?>
                    <tr>
                        <td colspan="2" style="text-align: right;">Tax (<?= $inv['tax_rate'] ?>%)</td>
                        <td style="text-align: right;">$<?= number_format($inv['tax'], 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($inv['discount'] > 0): ?>
                    <tr>
                        <td colspan="2" style="text-align: right;">Discount</td>
                        <td style="text-align: right;">-$<?= number_format($inv['discount'], 2) ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="summary-section">
            <div class="summary-box">
                <div class="summary-row">
                    <span>Subtotal</span>
                    <span>$<?= number_format($inv['subtotal'], 2) ?></span>
                </div>
                <div class="summary-row total">
                    <strong>Total Amount</strong>
                    <strong>$<?= number_format($inv['total_amount'], 2) ?></strong>
                </div>
                <div class="summary-row">
                    <span>Paid Amount</span>
                    <span>$<?= number_format($inv['paid_amount'], 2) ?></span>
                </div>
                <div class="summary-row balance">
                    <strong>Balance Due</strong>
                    <strong>$<?= number_format($dueAmount, 2) ?></strong>
                </div>
            </div>
        </div>

        <?php if ($inv['notes']): ?>
        <div style="margin-top: 30px;">
            <h4 style="color: var(--primary); font-size: 14px; margin-bottom: 5px;">Notes:</h4>
            <p style="font-size: 13px; color: #666;"><?= nl2br(htmlspecialchars($inv['notes'])) ?></p>
        </div>
        <?php endif; ?>

        <div class="footer-note">
            <p>Thank you for choosing <?= htmlspecialchars($inv['tenant_name']) ?> for your logistics needs.</p>
            <p>This is a computer generated invoice.</p>
        </div>
    </div>
</div>

</body>
</html>
