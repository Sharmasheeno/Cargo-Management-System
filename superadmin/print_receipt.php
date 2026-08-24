<?php
require_once '../config/db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    die("Fadlan soo gal nidaamka marka hore.");
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
    die("ID-ga rasiidka lama helin.");
}

// Fetch receipt details
$receipt = db_get("
    SELECT r.*, 
           c.customer_name, c.phone as customer_phone, c.address as customer_address,
           i.invoice_number, i.total_amount as invoice_total,
           ba.account_name as bank_name, ba.bank_name as bank_provider,
           t.name as tenant_name, t.address as tenant_address, t.email as tenant_email
    FROM receipts r 
    JOIN customers c ON r.customer_id = c.id 
    LEFT JOIN invoices i ON r.invoice_id = i.id 
    LEFT JOIN bank_accounts ba ON r.bank_account_id = ba.id
    LEFT JOIN tenants t ON r.tenant_id = t.id
    WHERE r.id = ?
", [$id]);

if (!$receipt) {
    die("Rasiidkan ma jiro.");
}
?>
<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?= $receipt['receipt_number'] ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');
        
        :root {
            --primary: #2D1859;
            --secondary: #F5C410;
            --text-dark: #1a1a1a;
            --text-gray: #666;
            --border: #eee;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: #f9f9f9; 
            color: var(--text-dark);
            line-height: 1.5;
            padding: 40px 20px;
        }

        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            padding: 50px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
        }

        .receipt-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 40px;
            padding-bottom: 30px;
            border-bottom: 2px solid var(--border);
        }

        .logo-section h1 {
            color: var(--primary);
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 5px;
            letter-spacing: -1px;
        }

        .logo-section p {
            color: var(--text-gray);
            font-size: 13px;
        }

        .receipt-status {
            background: #EEFBF3;
            color: #0F7A3A;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-bottom: 40px;
        }

        .detail-block h3 {
            font-size: 11px;
            color: var(--text-gray);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 12px;
        }

        .detail-block p {
            font-size: 15px;
            font-weight: 600;
        }

        .receipt-summary {
            background: #fdfdfd;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 30px;
            margin-bottom: 40px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px dashed var(--border);
        }

        .summary-row:last-child {
            border-bottom: none;
            padding-top: 20px;
            margin-top: 10px;
        }

        .summary-row span {
            color: var(--text-gray);
            font-size: 14px;
        }

        .summary-row strong {
            font-size: 16px;
        }

        .total-row {
            font-size: 24px !important;
            color: var(--primary);
            font-weight: 800 !important;
        }

        .footer {
            text-align: center;
            margin-top: 50px;
            padding-top: 30px;
            border-top: 1px solid var(--border);
            color: var(--text-gray);
            font-size: 12px;
        }

        .print-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: var(--primary);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 30px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 10px 20px rgba(82, 0, 102, 0.3);
            display: flex;
            align-items: center;
            gap: 10px;
            transition: transform 0.2s;
        }

        .print-btn:hover {
            transform: translateY(-2px);
        }

        @media print {
            body { background: white; padding: 0; }
            .receipt-container { box-shadow: none; border: 1px solid #eee; }
            .print-btn { display: none; }
        }
    </style>
</head>
<body>

<div class="receipt-container">
    <div class="receipt-header">
        <div class="logo-section">
            <h1><?= htmlspecialchars($receipt['tenant_name'] ?: 'CURDUB SMART CARGO') ?></h1>
            <p><?= htmlspecialchars($receipt['tenant_address'] ?: 'Mogadishu, Somalia') ?></p>
            <p><?= htmlspecialchars($receipt['tenant_email'] ?: 'contact@curdub.com') ?></p>
        </div>
        <div>
            <span class="receipt-status">Payment Received</span>
        </div>
    </div>

    <div class="details-grid">
        <div class="detail-block">
            <h3>Bill To</h3>
            <p><?= htmlspecialchars($receipt['customer_name']) ?></p>
            <p style="font-weight: 400; color: #666; font-size: 13px;"><?= htmlspecialchars($receipt['customer_phone']) ?></p>
            <p style="font-weight: 400; color: #666; font-size: 13px;"><?= htmlspecialchars($receipt['customer_address'] ?: '-') ?></p>
        </div>
        <div class="detail-block" style="text-align: right;">
            <h3>Receipt Info</h3>
            <p>Number: <?= $receipt['receipt_number'] ?></p>
            <p style="font-weight: 400; color: #666; font-size: 13px;">Date: <?= date('d M, Y', strtotime($receipt['payment_date'])) ?></p>
            <p style="font-weight: 400; color: #666; font-size: 13px;">Reference: <?= $receipt['invoice_number'] ?: 'On Account' ?></p>
        </div>
    </div>

    <div class="receipt-summary">
        <div class="summary-row">
            <span>Description</span>
            <strong>Amount</strong>
        </div>
        <div class="summary-row" style="padding: 20px 0;">
            <span>Payment for <?= $receipt['invoice_number'] ? "Invoice #".$receipt['invoice_number'] : "Customer Account" ?></span>
            <strong>$<?= number_format($receipt['amount'], 2) ?></strong>
        </div>
        <div class="summary-row">
            <span>Payment Method</span>
            <strong><?= htmlspecialchars($receipt['bank_name'] ?: 'Cash') ?></strong>
        </div>
        <div class="summary-row">
            <span>Total Paid</span>
            <strong class="total-row">$<?= number_format($receipt['amount'], 2) ?></strong>
        </div>
    </div>

    <div class="footer">
        <p>Thank you for choosing <?= htmlspecialchars($receipt['tenant_name'] ?: 'CURDUB SMART CARGO') ?>.</p>
        <p>This is a computer generated receipt and does not require a signature.</p>
    </div>
</div>

<button class="print-btn" onclick="window.print()">
    <span>Print Receipt</span>
</button>

</body>
</html>
