<?php
// superadmin/receipts.php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    if (isset($_GET['ajax']) || isset($_GET['modal'])) {
        echo '<div class="alert alert-danger">Please login to view receipt</div>';
        exit;
    }
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$user_tenant_id = $_SESSION['tenant_id'] ?? null;

// Get payment ID
$payment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$auto_print = isset($_GET['auto_print']) && $_GET['auto_print'] == 1;
$is_modal = isset($_GET['modal']) || isset($_GET['ajax']);
$paper_size = isset($_GET['paper']) ? $_GET['paper'] : 'A4'; // A4, A5, A3

if (!$payment_id) {
    $error_msg = "Lambar bixinta lama helin.";
    if ($is_modal) {
        echo '<div class="alert alert-danger">' . $error_msg . '</div>';
        exit;
    }
    die($error_msg);
}

// Fetch payment details
try {
    if ($user_role === 'superadmin') {
        $stmt = $pdo->prepare("
            SELECT p.*, 
                   t.name as tenant_name, t.address as tenant_address, t.phone as tenant_phone, t.email as tenant_email, t.logo_url,
                   c.customer_name, c.phone as customer_phone, c.email as customer_email, c.address as customer_address,
                   i.invoice_number, i.total_amount as invoice_total,
                   ba.account_name, ba.bank_name, ba.account_number,
                   u.full_name as created_by_name
            FROM payments p
            LEFT JOIN tenants t ON p.tenant_id = t.id
            LEFT JOIN customers c ON p.customer_id = c.id
            LEFT JOIN invoices i ON p.invoice_id = i.id
            LEFT JOIN bank_accounts ba ON p.bank_account_id = ba.id
            LEFT JOIN users u ON p.created_by = u.id
            WHERE p.id = ?
        ");
        $stmt->execute([$payment_id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT p.*, 
                   t.name as tenant_name, t.address as tenant_address, t.phone as tenant_phone, t.email as tenant_email, t.logo_url,
                   c.customer_name, c.phone as customer_phone, c.email as customer_email, c.address as customer_address,
                   i.invoice_number, i.total_amount as invoice_total,
                   ba.account_name, ba.bank_name, ba.account_number,
                   u.full_name as created_by_name
            FROM payments p
            LEFT JOIN tenants t ON p.tenant_id = t.id
            LEFT JOIN customers c ON p.customer_id = c.id
            LEFT JOIN invoices i ON p.invoice_id = i.id
            LEFT JOIN bank_accounts ba ON p.bank_account_id = ba.id
            LEFT JOIN users u ON p.created_by = u.id
            WHERE p.id = ? AND p.tenant_id = ?
        ");
        $stmt->execute([$payment_id, $user_tenant_id]);
    }
    
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        $error_msg = "Bixinta lama helin.";
        if ($is_modal) {
            echo '<div class="alert alert-danger">' . $error_msg . '</div>';
            exit;
        }
        die($error_msg);
    }
    
} catch (PDOException $e) {
    $error_msg = "Khalad xogta: " . $e->getMessage();
    if ($is_modal) {
        echo '<div class="alert alert-danger">' . $error_msg . '</div>';
        exit;
    }
    die($error_msg);
}

// Helper function to convert number to words
function numberToWords($number) {
    $hyphen = '-';
    $conjunction = ' and ';
    $separator = ', ';
    $negative = 'negative ';
    $decimal = ' point ';
    $dictionary = array(
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen',
        20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety',
        100 => 'Hundred', 1000 => 'Thousand', 1000000 => 'Million', 1000000000 => 'Billion'
    );
    
    if (!is_numeric($number)) {
        return false;
    }
    
    $number = floatval($number);
    $dollars = floor($number);
    $cents = round(($number - $dollars) * 100);
    
    $string = '';
    
    if ($dollars < 0) {
        $string .= $negative;
        $dollars = abs($dollars);
    }
    
    $string .= convertNumber($dollars, $dictionary, $hyphen, $conjunction, $separator);
    $string .= ' Dollars';
    
    if ($cents > 0) {
        $string .= ' and ' . convertNumber($cents, $dictionary, $hyphen, $conjunction, $separator) . ' Cents';
    }
    
    return ucfirst($string);
}

function convertNumber($number, $dictionary, $hyphen, $conjunction, $separator) {
    $string = '';
    
    switch (true) {
        case $number < 21:
            $string = $dictionary[$number];
            break;
        case $number < 100:
            $tens = floor($number / 10) * 10;
            $units = $number % 10;
            $string = $dictionary[$tens];
            if ($units) {
                $string .= $hyphen . $dictionary[$units];
            }
            break;
        case $number < 1000:
            $hundreds = floor($number / 100);
            $remainder = $number % 100;
            $string = $dictionary[$hundreds] . ' ' . $dictionary[100];
            if ($remainder) {
                $string .= $conjunction . convertNumber($remainder, $dictionary, $hyphen, $conjunction, $separator);
            }
            break;
        default:
            $baseUnit = pow(1000, floor(log($number, 1000)));
            $numBaseUnits = floor($number / $baseUnit);
            $remainder = $number % $baseUnit;
            $string = convertNumber($numBaseUnits, $dictionary, $hyphen, $conjunction, $separator) . ' ' . $dictionary[$baseUnit];
            if ($remainder) {
                $string .= $remainder < 100 ? $conjunction : $separator;
                $string .= convertNumber($remainder, $dictionary, $hyphen, $conjunction, $separator);
            }
            break;
    }
    
    return $string;
}

// Company information
$company_name = $payment['tenant_name'] ?? 'Cargo Management System';
$currency = '$';
$logo_url = $payment['logo_url'] ?? '';

// Colors
$primary_color = '#2D1859';
$secondary_color = '#F5C410';
$primary_light = '#4B2C85';
$secondary_dark = '#D4A70C';

// Payment method name and icon
$methodNames = [
    'cash' => 'Cash',
    'bank_transfer' => 'Bank Transfer',
    'check' => 'Check',
    'mobile_money' => 'Mobile Money'
];
$methodIcons = [
    'cash' => 'fa-money-bill-wave',
    'bank_transfer' => 'fa-university',
    'check' => 'fa-money-check',
    'mobile_money' => 'fa-mobile-alt'
];
$payment_method_display = $methodNames[$payment['payment_method']] ?? ucfirst($payment['payment_method']);
$method_icon = $methodIcons[$payment['payment_method']] ?? 'fa-credit-card';

// Customer/Supplier name
$party_name = $payment['customer_name'] ?? $payment['supplier_name'] ?? '-';
$party_type = $payment['customer_id'] ? 'Macaamil ' : 'Alaab-qeybiye';
$party_phone = $payment['customer_phone'] ?? '';
$party_email = $payment['customer_email'] ?? '';

// Amount in words
$amount_words = numberToWords($payment['amount']);

// Show reference number and bank account only if not cash
$show_reference = ($payment['payment_method'] != 'cash');
$show_bank = ($payment['payment_method'] == 'bank_transfer');

// Generate QR code data
$qr_data = urlencode(json_encode([
    'payment_number' => $payment['payment_number'],
    'amount' => $payment['amount'],
    'date' => $payment['payment_date'],
    'party' => $party_name,
    'company' => $company_name
]));

// Paper size specific classes
$paper_class = '';
switch($paper_size) {
    case 'A5':
        $paper_class = 'paper-a5';
        break;
    case 'A3':
        $paper_class = 'paper-a3';
        break;
    default:
        $paper_class = 'paper-a4';
}
?>
<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, print-scale: 1.0">
    <title>Rasiidka Bixinta - <?= htmlspecialchars($payment['payment_number']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', 'Tahoma', 'Geneva', 'Verdana', sans-serif;
            background: #eef2f5;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        /* Paper Size Styles - All fit on ONE PAGE */
        .paper-a4 {
            max-width: 800px;
            width: 100%;
        }
        
        .paper-a5 {
            max-width: 550px;
            width: 100%;
        }
        
        .paper-a3 {
            max-width: 1100px;
            width: 100%;
        }
        
        /* Responsive Container */
        .receipt-wrapper {
            margin: 0 auto;
        }
        
        .paper-a4 .receipt-wrapper { max-width: 800px; }
        .paper-a5 .receipt-wrapper { max-width: 550px; }
        .paper-a3 .receipt-wrapper { max-width: 1100px; }
        
        .receipt-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            transition: all 0.3s ease;
            position: relative;
        }
        
        /* Decorative corner accents */
        .receipt-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, <?= $primary_color ?> 0%, transparent 100%);
            opacity: 0.1;
            pointer-events: none;
        }
        
        .receipt-container::after {
            content: '';
            position: absolute;
            bottom: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: linear-gradient(315deg, <?= $secondary_color ?> 0%, transparent 100%);
            opacity: 0.1;
            pointer-events: none;
        }
        
        /* Header Styles */
        .receipt-header {
            background: linear-gradient(135deg, <?= $primary_color ?> 0%, <?= $primary_light ?> 100%);
            color: white;
            padding: 25px 30px;
            text-align: center;
            position: relative;
        }
        
        .receipt-header::after {
            content: '';
            position: absolute;
            bottom: -15px;
            left: 0;
            right: 0;
            height: 30px;
            background: linear-gradient(135deg, transparent 50%, white 50%);
        }
        
        .logo {
            max-height: 60px;
            margin-bottom: 15px;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
        }
        
        .company-name {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 8px;
            letter-spacing: 1px;
        }
        
        .company-details {
            font-size: 12px;
            opacity: 0.9;
            line-height: 1.5;
        }
        
        .company-details i {
            margin-right: 5px;
        }
        
        /* Title Section */
        .receipt-title-section {
            background: white;
            padding: 20px 30px 15px 30px;
            text-align: center;
            border-bottom: 2px dashed <?= $secondary_color ?>;
        }
        
        .receipt-badge {
            display: inline-block;
            background: linear-gradient(135deg, <?= $primary_color ?>, <?= $primary_light ?>);
            color: white;
            padding: 6px 20px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        
        .receipt-title {
            font-size: 28px;
            font-weight: 800;
            color: <?= $primary_color ?>;
            letter-spacing: 2px;
        }
        
        .receipt-number {
            background: linear-gradient(135deg, <?= $secondary_color ?>, <?= $secondary_dark ?>);
            display: inline-block;
            padding: 6px 20px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            color: <?= $primary_color ?>;
            margin-top: 12px;
            font-family: monospace;
        }
        
        /* Body Content */
        .receipt-body {
            padding: 25px 30px;
        }
        
        /* Info Grid - Responsive */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px 25px;
            margin-bottom: 20px;
        }
        
        .paper-a3 .info-grid {
            grid-template-columns: repeat(3, 1fr);
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .info-label {
            font-weight: 600;
            color: <?= $primary_color ?>;
            font-size: 13px;
            min-width: 110px;
        }
        
        .info-label i {
            width: 20px;
            color: <?= $primary_color ?>;
        }
        
        .info-value {
            font-weight: 500;
            color: #2c3e50;
            font-size: 14px;
            text-align: right;
            word-break: break-word;
        }
        
        .info-value code {
            background: #f5f5f5;
            padding: 3px 8px;
            border-radius: 5px;
            font-size: 12px;
        }
        
        /* Divider */
        .divider {
            height: 2px;
            background: linear-gradient(90deg, transparent, <?= $secondary_color ?>, <?= $primary_color ?>, <?= $secondary_color ?>, transparent);
            margin: 15px 0;
        }
        
        /* Amount Box */
        .amount-box {
            background: linear-gradient(135deg, <?= $secondary_color ?>20, <?= $secondary_color ?>10);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            margin: 20px 0;
            border: 2px solid <?= $secondary_color ?>;
            position: relative;
            overflow: hidden;
        }
        
        .amount-box::before {
            content: '💰';
            position: absolute;
            top: -20px;
            right: -20px;
            font-size: 80px;
            opacity: 0.1;
        }
        
        .amount-label {
            font-size: 13px;
            color: <?= $primary_color ?>;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-weight: 700;
        }
        
        .amount-number {
            font-size: 48px;
            font-weight: 800;
            color: <?= $primary_color ?>;
            line-height: 1.2;
        }
        
        .paper-a5 .amount-number { font-size: 36px; }
        .paper-a3 .amount-number { font-size: 56px; }
        
        .amount-words {
            font-size: 11px;
            color: #6c757d;
            margin-top: 8px;
            font-style: italic;
        }
        
        /* Payment Method Badge */
        .payment-method-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .method-cash { background: #EEFBF3; color: #0F7A3A; }
        .method-bank-transfer { background: #e3f2fd; color: #1565c0; }
        .method-check { background: #fff3e0; color: #e65100; }
        .method-mobile-money { background: #f3e5f5; color: #6a1b9a; }
        
        /* Signature Section */
        .signature-section {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px dashed <?= $secondary_color ?>;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .paper-a3 .signature-section {
            justify-content: space-around;
        }
        
        .signature-item {
            text-align: center;
            flex: 1;
            min-width: 150px;
        }
        
        .signature-line {
            border-top: 1px solid <?= $primary_color ?>;
            width: 100%;
            margin-top: 30px;
            margin-bottom: 5px;
        }
        
        .signature-text {
            font-size: 11px;
            color: #6c757d;
        }
        
        /* QR Code */
        .qr-section {
            text-align: center;
            margin: 20px 0 15px 0;
            padding: 10px;
            background: #f9f9f9;
            border-radius: 12px;
        }
        
        .qr-code {
            display: inline-block;
        }
        
        .qr-code img {
            width: 80px;
            height: 80px;
        }
        
        .paper-a5 .qr-code img { width: 60px; height: 60px; }
        .paper-a3 .qr-code img { width: 100px; height: 100px; }
        
        .qr-text {
            font-size: 10px;
            color: #6c757d;
            margin-top: 5px;
        }
        
        /* Footer */
        .receipt-footer {
            background: linear-gradient(135deg, <?= $primary_color ?>08, <?= $secondary_color ?>08);
            padding: 15px 30px;
            text-align: center;
            border-top: 1px solid <?= $secondary_color ?>;
        }
        
        .thank-you {
            font-size: 14px;
            font-weight: 700;
            color: <?= $primary_color ?>;
            margin-bottom: 8px;
        }
        
        .thank-you i {
            color: #e91e63;
        }
        
        .footer-note {
            font-size: 11px;
            color: #6c757d;
            line-height: 1.4;
        }
        
        /* Buttons - for standalone view */
        .action-buttons {
            position: fixed;
            bottom: 20px;
            right: 20px;
            display: flex;
            gap: 10px;
            z-index: 1000;
        }
        
        .btn-action {
            background: <?= $primary_color ?>;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .btn-action:hover {
            transform: translateY(-2px);
            background: <?= $primary_light ?>;
        }
        
        .btn-paper {
            background: <?= $secondary_color ?>;
            color: <?= $primary_color ?>;
        }
        
        .btn-paper:hover {
            background: <?= $secondary_dark ?>;
        }
        
        .btn-close {
            background: #6c757d;
            position: fixed;
            bottom: 20px;
            left: 20px;
        }
        
        .btn-close:hover {
            background: #5a6268;
        }
        
        /* Paper size dropdown */
        .paper-selector {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            background: white;
            padding: 8px 15px;
            border-radius: 30px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .paper-selector label {
            font-size: 12px;
            font-weight: 600;
            color: <?= $primary_color ?>;
        }
        
        .paper-selector select {
            padding: 5px 10px;
            border-radius: 20px;
            border: 1px solid <?= $primary_color ?>;
            background: white;
            font-size: 12px;
            cursor: pointer;
        }
        
        /* Print Styles - ONE PAGE ONLY */
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            
            .action-buttons, .btn-action, .btn-close, .paper-selector {
                display: none !important;
            }
            
            .receipt-wrapper {
                max-width: 100%;
                margin: 0;
                padding: 0;
            }
            
            .receipt-container {
                box-shadow: none;
                border-radius: 0;
                page-break-after: avoid;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            .receipt-header {
                background: <?= $primary_color ?>;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .amount-box {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .payment-method-badge {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .receipt-badge, .receipt-number {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            /* Force one page */
            @page {
                size: <?= $paper_size == 'A5' ? 'A5' : ($paper_size == 'A3' ? 'A3' : 'A4') ?>;
                margin: 10mm;
            }
            
            body, .receipt-container, .receipt-wrapper {
                height: auto;
                overflow: visible;
            }
            
            .receipt-body {
                overflow: visible;
            }
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .receipt-header {
                padding: 20px;
            }
            
            .company-name {
                font-size: 20px;
            }
            
            .receipt-title-section {
                padding: 15px 20px;
            }
            
            .receipt-title {
                font-size: 22px;
            }
            
            .receipt-body {
                padding: 15px 20px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
                gap: 5px;
            }
            
            .paper-a3 .info-grid {
                grid-template-columns: 1fr;
            }
            
            .info-item {
                flex-direction: column;
                gap: 5px;
            }
            
            .info-label {
                min-width: auto;
            }
            
            .info-value {
                text-align: left;
            }
            
            .amount-number {
                font-size: 32px;
            }
            
            .paper-a5 .amount-number { font-size: 28px; }
            .paper-a3 .amount-number { font-size: 36px; }
            
            .signature-section {
                flex-direction: column;
                align-items: center;
                gap: 15px;
            }
            
            .signature-item {
                width: 100%;
            }
            
            .paper-selector {
                top: 10px;
                right: 10px;
                padding: 5px 10px;
            }
            
            .action-buttons {
                bottom: 10px;
                right: 10px;
            }
            
            .btn-action {
                padding: 8px 15px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
<div class="<?= $paper_class ?>">
    <!-- Paper Size Selector -->
    <?php if (!$is_modal): ?>
    <div class="paper-selector">
        <label><i class="fas fa-print"></i> Cabbirka Warqadda:</label>
        <select id="paperSize" onchange="changePaperSize()">
            <option value="A4" <?= $paper_size == 'A4' ? 'selected' : '' ?>>A4 (Standard)</option>
            <option value="A5" <?= $paper_size == 'A5' ? 'selected' : '' ?>>A5 (Yar)</option>
            <option value="A3" <?= $paper_size == 'A3' ? 'selected' : '' ?>>A3 (Weyn)</option>
        </select>
    </div>
    <?php endif; ?>
    
    <div class="receipt-wrapper">
        <div class="receipt-container" id="receiptContent">
            <!-- Header -->
            <div class="receipt-header">
                <?php if ($logo_url): ?>
                    <img src="<?= htmlspecialchars($logo_url) ?>" class="logo" alt="Logo">
                <?php else: ?>
                    <i class="fas fa-receipt" style="font-size: 50px; margin-bottom: 10px;"></i>
                <?php endif; ?>
                <div class="company-name"><?= htmlspecialchars($company_name) ?></div>
                <div class="company-details">
                    <?php if ($payment['tenant_address']): ?>
                        <div><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($payment['tenant_address']) ?></div>
                    <?php endif; ?>
                    <?php if ($payment['tenant_phone']): ?>
                        <div><i class="fas fa-phone"></i> <?= htmlspecialchars($payment['tenant_phone']) ?></div>
                    <?php endif; ?>
                    <?php if ($payment['tenant_email']): ?>
                        <div><i class="fas fa-envelope"></i> <?= htmlspecialchars($payment['tenant_email']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Title -->
            <div class="receipt-title-section">
                <div class="receipt-badge">
                    <i class="fas fa-check-circle"></i> OFFICIAL PAYMENT RECEIPT
                </div>
                <div class="receipt-title">RASIIDKA BIXINTA</div>
                <div class="receipt-number">
                    <i class="fas fa-hashtag"></i> <?= htmlspecialchars($payment['payment_number']) ?>
                </div>
            </div>
            
            <!-- Body -->
            <div class="receipt-body">
                <!-- Info Grid -->
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-calendar"></i> Taariikhda</span>
                        <span class="info-value"><?= date('d/m/Y', strtotime($payment['payment_date'])) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-clock"></i> Waqtiga</span>
                        <span class="info-value"><?= date('h:i A', strtotime($payment['created_at'] ?? $payment['payment_date'])) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-user"></i> Magaca </span>
                        <span class="info-value"><?= htmlspecialchars($party_name) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-tag"></i> Nooca</span>
                        <span class="info-value"><?= htmlspecialchars($party_type) ?></span>
                    </div>
                    <?php if ($party_phone): ?>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-phone"></i> Telefoon</span>
                        <span class="info-value"><?= htmlspecialchars($party_phone) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($payment['invoice_number']): ?>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-file-invoice"></i> Lambarka Biilka </span>
                        <span class="info-value"><?= htmlspecialchars($payment['invoice_number']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-credit-card"></i> Habka Bixinta</span>
                        <span class="info-value">
                            <span class="payment-method-badge method-<?= str_replace('_', '-', $payment['payment_method']) ?>">
                                <i class="fas <?= $method_icon ?>"></i> <?= htmlspecialchars($payment_method_display) ?>
                            </span>
                        </span>
                    </div>
                    <?php if ($show_reference && $payment['reference_number']): ?>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-qrcode"></i> Lambarka Tixraaca</span>
                        <span class="info-value"><code><?= htmlspecialchars($payment['reference_number']) ?></code></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($show_bank && $payment['bank_name']): ?>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-university"></i> Xisaabta Bangiga</span>
                        <span class="info-value"><?= htmlspecialchars($payment['bank_name']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-folder"></i> Qaybta </span>
                        <span class="info-value"><?= htmlspecialchars($payment['category'] ?? '-') ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-user-check"></i> Sameeyay</span>
                        <span class="info-value"><?= htmlspecialchars($payment['created_by_name'] ?? $_SESSION['user_name'] ?? 'Admin') ?></span>
                    </div>
                </div>
                
                <div class="divider"></div>
                
                <!-- Amount Box -->
                <div class="amount-box">
                    <div class="amount-label">
                        <i class="fas fa-dollar-sign"></i> QADARKA BIXINTA / PAYMENT AMOUNT
                    </div>
                    <div class="amount-number">
                        <?= $currency ?><?= number_format($payment['amount'], 2) ?>
                    </div>
                    <div class="amount-words">
                        <i class="fas fa-language"></i> <?= $amount_words ?>
                    </div>
                </div>
                
                <!-- Notes -->
                <?php if ($payment['notes']): ?>
                <div class="info-item" style="flex-direction: column; align-items: flex-start; gap: 8px; margin-bottom: 15px;">
                    <span class="info-label"><i class="fas fa-comment-dots"></i> Qoraal / Notes</span>
                    <span class="info-value" style="font-weight: normal; background: #f8f9fa; padding: 10px; border-radius: 8px; width: 100%;">
                        <?= nl2br(htmlspecialchars($payment['notes'])) ?>
                    </span>
                </div>
                <?php endif; ?>
                
                <!-- QR Code Section -->
                <div class="qr-section">
                    <div class="qr-code">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?= $qr_data ?>" alt="QR Code">
                    </div>
                    <div class="qr-text">Scan to verify payment | Qaado si aad u xaqiijiso</div>
                </div>
                
                <!-- Signature Section -->
                <div class="signature-section">
                    <div class="signature-item">
                        <div class="signature-line"></div>
                        <div class="signature-text">Saxiixa Bixiyaha / Customer Signature</div>
                    </div>
                    <div class="signature-item">
                        <div class="signature-line"></div>
                        <div class="signature-text">Saxiixa Qaatay / Received By</div>
                    </div>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="receipt-footer">
                <div class="thank-you">
                    <i class="fas fa-heart"></i> Mahadsanid! Thank You for Your Payment!
                </div>
                <div class="footer-note">
                    This is a computer generated receipt. No signature required.<br>
                    Rasiidkan waa rasiid rasmi ah oo ansax ah. / This is an official valid receipt.
                </div>
                <div class="footer-note" style="margin-top: 5px; font-size: 9px;">
                    <i class="fas fa-check-circle" style="color: <?= $primary_color ?>"></i> <?= htmlspecialchars($payment['payment_number']) ?> | Generated: <?= date('Y-m-d H:i:s') ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Action Buttons (only for standalone view) -->
<?php if (!$is_modal): ?>
<div class="action-buttons">
    <button class="btn-action" onclick="downloadPDF()">
        <i class="fas fa-file-pdf"></i> Kaydi PDF
    </button>
    <button class="btn-action" onclick="printReceipt()">
        <i class="fas fa-print"></i> Daabac
    </button>
</div>
<button class="btn-action btn-close" onclick="window.close()">
    <i class="fas fa-times"></i> Xidh
</button>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
    function printReceipt() {
        const originalTitle = document.title;
        document.title = "Rasiidka_<?= htmlspecialchars($payment['payment_number']) ?>";
        window.print();
        document.title = originalTitle;
    }
    
    function downloadPDF() {
        const element = document.getElementById('receiptContent');
        const paperSize = document.getElementById('paperSize')?.value || '<?= $paper_size ?>';
        let format = 'a4';
        if (paperSize === 'A5') format = 'a5';
        if (paperSize === 'A3') format = 'a3';
        
        const opt = {
            margin: [0.3, 0.3, 0.3, 0.3],
            filename: 'Rasiidka_<?= htmlspecialchars($payment['payment_number']) ?>.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, letterRendering: true, useCORS: true },
            jsPDF: { unit: 'in', format: format, orientation: 'portrait' }
        };
        html2pdf().set(opt).from(element).save();
    }
    
    function changePaperSize() {
        const paperSize = document.getElementById('paperSize').value;
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('paper', paperSize);
        window.location.href = currentUrl.toString();
    }
    
    <?php if ($auto_print): ?>
    window.onload = function() {
        setTimeout(function() {
            window.print();
        }, 500);
    }
    <?php endif; ?>
</script>
</body>
</html>