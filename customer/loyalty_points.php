<?php
// customer/loyalty_points.php
//faras cargo - Customer Loyalty Points View with Redemption to point_redemptions table

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = $_SESSION['user_name'] ?? 'Customer';
$user_role = $_SESSION['role'] ?? ($_SESSION['role_type'] ?? '');
$user_tenant_id = (int)($_SESSION['tenant_id'] ?? 0);

// Get customer record for this user
$customer = null;
try {
    $stmt = $pdo->prepare("
        SELECT c.*, t.name as tenant_name, t.loyalty_amount_points, t.loyalty_cbm_points
        FROM customers c
        LEFT JOIN tenants t ON c.tenant_id = t.id
        WHERE c.user_id = ? OR c.email = (SELECT email FROM users WHERE id = ?)
        LIMIT 1
    ");
    $stmt->execute([$user_id, $user_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        $stmt = $pdo->prepare("
            SELECT c.*, t.name as tenant_name, t.loyalty_amount_points, t.loyalty_cbm_points
            FROM customers c
            LEFT JOIN tenants t ON c.tenant_id = t.id
            WHERE c.email = (SELECT email FROM users WHERE id = ?)
            LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$customer) {
        die("Customer account not found. Please contact support.");
    }
    
    $user_tenant_id = $customer['tenant_id'];
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Ensure loyalty columns and point_redemptions table exist
function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!columnExists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        return;
    }

    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    $col = $stmt->fetch(PDO::FETCH_ASSOC);

    // Convert integer loyalty fields to DECIMAL so points like 1.25 show correctly.
    if ($col && stripos($definition, 'DECIMAL') !== false && stripos($col['Type'] ?? '', 'decimal') === false) {
        $pdo->exec("ALTER TABLE `$table` MODIFY `$column` $definition");
    }
}

function ensureLoyaltySchema(PDO $pdo): void
{
    try {
        ensureColumn($pdo, 'customers', 'loyalty_points', 'DECIMAL(12,2) DEFAULT 0');
        ensureColumn($pdo, 'loyalty_points_log', 'points_earned', 'DECIMAL(12,2) DEFAULT 0');
        ensureColumn($pdo, 'loyalty_points_log', 'points_redeemed', 'DECIMAL(12,2) DEFAULT 0');
        ensureColumn($pdo, 'loyalty_points_log', 'amount_earned', 'DECIMAL(12,2) DEFAULT 0');
        ensureColumn($pdo, 'loyalty_points_log', 'reason', 'VARCHAR(255) NULL');
        ensureColumn($pdo, 'loyalty_points_log', 'reference_type', 'VARCHAR(50) NULL');
        ensureColumn($pdo, 'loyalty_points_log', 'reference_id', 'INT NULL');
        ensureColumn($pdo, 'loyalty_points_log', 'created_by', 'INT NULL');
        ensureColumn($pdo, 'loyalty_points_log', 'created_at', 'DATETIME DEFAULT CURRENT_TIMESTAMP');

        if (columnExists($pdo, 'payments', 'original_amount')) {
            ensureColumn($pdo, 'payments', 'discount_applied', 'DECIMAL(12,2) DEFAULT 0');
            ensureColumn($pdo, 'payments', 'points_used', 'DECIMAL(12,2) DEFAULT 0');
        }
        
        $stmt = $pdo->query("SHOW TABLES LIKE 'point_redemptions'");
        if (!$stmt->fetch()) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS point_redemptions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tenant_id INT NOT NULL,
                    customer_id INT NOT NULL,
                    points_used DECIMAL(12,2) NOT NULL,
                    discount_amount DECIMAL(12,2) NOT NULL,
                    redemption_date DATETIME NOT NULL,
                    invoice_id INT DEFAULT NULL,
                    payment_id INT DEFAULT NULL,
                    status ENUM('pending', 'applied', 'cancelled', 'partial') DEFAULT 'pending',
                    applied_to_payment_id INT DEFAULT NULL,
                    applied_at DATETIME DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_customer (customer_id),
                    KEY idx_tenant (tenant_id),
                    KEY idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
    } catch (Exception $e) {
        error_log("Loyalty schema check failed: " . $e->getMessage());
    }
}

function money_amount($value): float
{
    $value = str_replace(',', '.', (string)($value ?? 0));
    return is_numeric($value) ? (float)$value : 0.0;
}

function fmt_points($value, int $decimals = 2): string
{
    return number_format((float)$value, $decimals);
}

function syncMissingPaymentLoyalty(PDO $pdo, int $tenant_id, int $customer_id, int $created_by): void
{
    try {
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.payment_number,
                p.amount,
                COALESCE(NULLIF(p.original_amount, 0), p.amount) AS base_amount,
                COALESCE(t.loyalty_amount_points, 5) AS rate
            FROM payments p
            LEFT JOIN tenants t ON t.id = p.tenant_id
            WHERE p.customer_id = ? AND p.tenant_id = ?
            ORDER BY p.created_at ASC
        ");
        $stmt->execute([$customer_id, $tenant_id]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($payments as $payment) {
            $check = $pdo->prepare("
                SELECT id FROM loyalty_points_log
                WHERE tenant_id = ? AND customer_id = ? AND reference_type = 'payment' AND reference_id = ?
                LIMIT 1
            ");
            $check->execute([$tenant_id, $customer_id, $payment['id']]);
            if ($check->fetch()) {
                continue;
            }

            $baseAmount = money_amount($payment['base_amount']);
            $rate = money_amount($payment['rate']);
            $points = round(($baseAmount / 100) * $rate, 2);

            if ($points <= 0) {
                continue;
            }

            $pdo->beginTransaction();

            $upd = $pdo->prepare("
                UPDATE customers
                SET loyalty_points = COALESCE(loyalty_points, 0) + ?, updated_at = NOW()
                WHERE id = ? AND tenant_id = ?
            ");
            $upd->execute([$points, $customer_id, $tenant_id]);

            $log = $pdo->prepare("
                INSERT INTO loyalty_points_log
                (tenant_id, customer_id, points_earned, points_redeemed, amount_earned, reason, reference_type, reference_id, created_by, created_at)
                VALUES (?, ?, ?, 0, ?, ?, 'payment', ?, ?, NOW())
            ");
            $log->execute([
                $tenant_id,
                $customer_id,
                $points,
                $baseAmount,
                'Automatic loyalty points from payment #' . ($payment['payment_number'] ?? $payment['id']),
                $payment['id'],
                $created_by
            ]);

            $pdo->commit();
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Payment loyalty sync failed: ' . $e->getMessage());
    }
}

ensureLoyaltySchema($pdo);
syncMissingPaymentLoyalty($pdo, (int)$user_tenant_id, (int)$customer['id'], (int)$user_id);

// Refresh customer after schema checks and automatic payment-point sync.
try {
    $stmt = $pdo->prepare("
        SELECT c.*, t.name as tenant_name, t.loyalty_amount_points, t.loyalty_cbm_points
        FROM customers c
        LEFT JOIN tenants t ON c.tenant_id = t.id
        WHERE c.id = ? AND c.tenant_id = ?
        LIMIT 1
    ");
    $stmt->execute([$customer['id'], $user_tenant_id]);
    $freshCustomer = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($freshCustomer) {
        $customer = $freshCustomer;
    }
} catch (Exception $e) {
    error_log('Customer refresh failed: ' . $e->getMessage());
}

$message = '';
$error = '';
$redemption_amount = 0;
$show_redemption_form = false;
$points_to_redeem = 0;

// Handle redemption request - Create entry in point_redemptions table
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_points'])) {
    $points_to_redeem = (float)($_POST['points_to_redeem'] ?? 0);
    $current_points = (float)($customer['loyalty_points'] ?? 0);
    
    if ($points_to_redeem <= 0) {
        $error = "Fadlan geli qadarka points ee aad rabto inaad isticmaasho.";
    } elseif ($points_to_redeem > $current_points) {
        $error = "Ma lihid points ku filan. Points-kaaga waa: " . number_format($current_points, 2);
    } elseif (fmod($points_to_redeem, 100) != 0) {
        $error = "Points waa inay ahaadaan kuwa 100-ku dhigma (100, 200, 300, iwm). 100 points = $1 discount.";
    } else {
        // Calculate redemption value (100 points = $1)
        $redemption_value = $points_to_redeem / 100;
        $redemption_amount = $redemption_value;
        $show_redemption_form = true;
    }
}

// Process redemption confirmation - Save to point_redemptions table
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_redemption'])) {
    $points_to_redeem = (float)($_POST['points_to_redeem'] ?? 0);
    $redemption_value = (float)($_POST['redemption_value'] ?? 0);
    $current_points = (float)($customer['loyalty_points'] ?? 0);
    
    if ($points_to_redeem <= 0 || $points_to_redeem > $current_points) {
        $error = "Points aan sax ahayn ama ku filan ma lihid.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // 1. Update customer points (subtract redeemed points)
            $stmt = $pdo->prepare("
                UPDATE customers 
                SET loyalty_points = loyalty_points - ?, updated_at = NOW()
                WHERE id = ? AND tenant_id = ? AND loyalty_points >= ?
            ");
            $stmt->execute([$points_to_redeem, $customer['id'], $user_tenant_id, $points_to_redeem]);
            
            if ($stmt->rowCount() == 0) {
                throw new Exception("Failed to update customer points.");
            }
            
            // 2. Insert into point_redemptions table (pending status)
            $stmt = $pdo->prepare("
                INSERT INTO point_redemptions
                (
                    tenant_id,
                    customer_id,
                    points_used,
                    discount_amount,
                    redemption_date,
                    status,
                    created_at
                )
                VALUES (?, ?, ?, ?, NOW(), 'pending', NOW())
            ");
            $stmt->execute([
                $user_tenant_id,
                $customer['id'],
                $points_to_redeem,
                $redemption_value
            ]);
            
            $redemption_id = $pdo->lastInsertId();
            
            // 3. Log redemption in loyalty_points_log
            $stmt = $pdo->prepare("
                INSERT INTO loyalty_points_log
                (
                    tenant_id,
                    customer_id,
                    points_earned,
                    points_redeemed,
                    amount_earned,
                    reason,
                    reference_type,
                    reference_id,
                    created_by,
                    created_at
                )
                VALUES (?, ?, 0, ?, ?, 'Points redeemed for discount - pending application', 'redemption', ?, ?, NOW())
            ");
            $stmt->execute([
                $user_tenant_id,
                $customer['id'],
                $points_to_redeem,
                $redemption_value,
                $redemption_id,
                $customer['id']
            ]);
            
            $pdo->commit();
            
            $message = "Waxaad ku guulaysatay inaad sarifto " . number_format($points_to_redeem, 2) . " points. Waxaad heshay $$redemption_value dhimis qiimo ah!<br>Dhimista waxaa lagu dabaqi doonaa bixintaada xiga.";
            
            // Refresh customer data
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $stmt->execute([$customer['id']]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            $show_redemption_form = false;
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Khalad ayaa dhacay: " . $e->getMessage();
        }
    }
}

// Get active redemptions (pending) for this customer
$active_redemptions = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM point_redemptions
        WHERE customer_id = ? AND tenant_id = ? AND status = 'pending'
        ORDER BY redemption_date ASC
    ");
    $stmt->execute([$customer['id'], $user_tenant_id]);
    $active_redemptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $active_redemptions = [];
}

// Calculate total pending discount
$pending_discount_total = 0;
$pending_points_total = 0;
foreach ($active_redemptions as $redemption) {
    $pending_discount_total += (float)$redemption['discount_amount'];
    $pending_points_total += (float)$redemption['points_used'];
}

// Get applied redemptions (already used)
$applied_redemptions = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, p.payment_number, p.amount as payment_amount, p.payment_date
        FROM point_redemptions r
        LEFT JOIN payments p ON r.applied_to_payment_id = p.id
        WHERE r.customer_id = ? AND r.tenant_id = ? AND r.status IN ('applied', 'partial')
        ORDER BY r.applied_at DESC
        LIMIT 20
    ");
    $stmt->execute([$customer['id'], $user_tenant_id]);
    $applied_redemptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $applied_redemptions = [];
}

// Get points history for this customer
$points_history = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            l.*,
            t.name as tenant_name
        FROM loyalty_points_log l
        LEFT JOIN tenants t ON t.id = l.tenant_id
        WHERE l.customer_id = ? AND l.tenant_id = ?
        ORDER BY l.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$customer['id'], $user_tenant_id]);
    $points_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $points_history = [];
}

// Get payment history for this customer
$payment_history = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.payment_number,
            p.amount,
            COALESCE(NULLIF(p.original_amount, 0), p.amount) AS original_amount,
            COALESCE(p.discount_applied, 0) AS discount_applied,
            COALESCE(p.points_used, 0) AS points_used,
            p.payment_date,
            p.created_at,
            ROUND((COALESCE(NULLIF(p.original_amount, 0), p.amount) / 100) * COALESCE(t.loyalty_amount_points, 5), 2) AS points_earned
        FROM payments p
        LEFT JOIN tenants t ON t.id = p.tenant_id
        WHERE p.customer_id = ? AND p.tenant_id = ?
        ORDER BY p.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$customer['id'], $user_tenant_id]);
    $payment_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $payment_history = [];
}

// Calculate total points earned and redeemed
$total_earned = 0;
$total_redeemed = 0;
foreach ($points_history as $log) {
    $total_earned += (float)($log['points_earned'] ?? 0);
    $total_redeemed += (float)($log['points_redeemed'] ?? 0);
}

require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <title>My Loyalty Points - <?= htmlspecialchars($customer['customer_name'] ?? 'Customer') ?> | Cargo Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">

    <style>
        :root {
            --curdun-violet: #2D1859;
            --curdun-yellow: #F5C410;
            --curdun-violet-light: #4B2C85;
            --curdun-gray: #6b6c72;
            --curdun-dark: #393a3d;
            --curdun-success: #2ca01c;
            --curdun-danger: #B42318;
            --border: #e0e1e6;
        }

        * { box-sizing: border-box; }

        body {
            background: #f4f5f8;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--curdun-dark);
            margin: 0;
        }

        .container-fluid { padding: 20px; }

        .page-header {
            background: #fff;
            border-bottom: 1px solid var(--border);
            padding: 20px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            border-radius: 8px;
        }

        .page-header h1 {
            color: var(--curdun-dark);
            font-size: 24px;
            font-weight: 700;
            margin: 0;
        }

        .page-header h1 i {
            color: var(--curdun-violet);
            margin-right: 10px;
        }

        .customer-badge {
            background: #EEFBF3;
            color: #0F7A3A;
            padding: 8px 14px;
            border-radius: 20px;
            font-weight: 700;
            display: inline-block;
        }

        .btn-primary-custom {
            background: var(--curdun-violet);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 20px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s ease;
        }

        .btn-primary-custom:hover {
            background: var(--curdun-violet-light);
            color: white;
            text-decoration: none;
            transform: translateY(-1px);
        }

        .btn-success-custom {
            background: #0F7A3A;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 20px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-success-custom:hover {
            background: #1b5e20;
            color: white;
        }

        .btn-outline-custom {
            background: transparent;
            color: var(--curdun-violet);
            border: 1px solid var(--curdun-violet);
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-outline-custom:hover {
            background: var(--curdun-violet);
            color: white;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }

        .stat-card h4 {
            font-size: 13px;
            color: var(--curdun-gray);
            margin: 0 0 8px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .stat-card .number {
            font-size: 36px;
            font-weight: 700;
            color: var(--curdun-violet);
        }

        .stat-card .small-number {
            font-size: 24px;
        }

        .section {
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .section h2 {
            font-size: 18px;
            margin: 0 0 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
            color: var(--curdun-dark);
        }

        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .alert-success {
            background: #EEFBF3;
            color: #0F7A3A;
            border-left: 4px solid #0F7A3A;
        }

        .alert-error {
            background: #fce8e6;
            color: #B42318;
            border-left: 4px solid #B42318;
        }

        .alert-info {
            background: #e3f2fd;
            color: #0d47a1;
            border-left: 4px solid #0d47a1;
        }

        .alert-warning {
            background: #fff3e0;
            color: #e65100;
            border-left: 4px solid #e65100;
        }

        .redemption-box {
            background: linear-gradient(135deg, #f5f0ff 0%, #e8d5f5 100%);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            text-align: center;
        }

        .points-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .points-earned {
            background: #EEFBF3;
            color: #0F7A3A;
        }

        .points-redeemed {
            background: #fce8e6;
            color: #B42318;
        }

        .points-pending {
            background: #fff3e0;
            color: #e65100;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 11px 10px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        th {
            background: #f9f9fb;
            font-weight: 600;
            color: var(--curdun-gray);
            font-size: 13px;
            white-space: nowrap;
        }

        tr:hover { background: #f9f9fb; }

        .table-wrap { overflow-x: auto; }

        .info-icon {
            color: var(--curdun-violet);
            margin-right: 5px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--curdun-dark);
        }

        input, select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
        }

        input:focus, select:focus {
            border-color: var(--curdun-violet);
            outline: none;
        }

        .redemption-preview {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            border: 1px solid var(--border);
        }

        .pending-redemption {
            background: #fff8e1;
            border-left: 4px solid #ffc107;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 10px;
        }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>

<body>
<div class="container-fluid">

    <div class="page-header">
        <div>
            <h1><i class="fas fa-star"></i> My Loyalty Points</h1>
            <div class="customer-badge mt-2">
                <i class="fas fa-user-circle"></i>
                <?= htmlspecialchars($customer['customer_name'] ?? $user_name) ?>
            </div>
        </div>

        <div>
            <a href="dashboard.php" class="btn-outline-custom">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?= $message ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error alert-dismissible fade show">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Active Redemptions Alert -->
    <?php if (!empty($active_redemptions) && $pending_discount_total > 0): ?>
        <div class="alert alert-warning">
            <i class="fas fa-clock"></i> <strong>Waxaad haysataa discount sugaya!</strong><br>
            Waxaad sariftay <?= number_format($pending_points_total, 0) ?> dhibcood oo ah $<?= number_format($pending_discount_total, 2) ?> dhimis.<br>
            Markaad bixin cusub sameysato, discount-gan waa otomatik lagu dabaqi doonaa.
        </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <h4><i class="fas fa-coins"></i> My Current Points</h4>
            <div class="number"><?= fmt_points($customer['loyalty_points'] ?? 0) ?></div>
            <small class="text-muted">1 point = $0.01 discount</small>
        </div>

        <div class="stat-card">
            <h4><i class="fas fa-chart-line"></i> Total Points Earned</h4>
            <div class="number small-number"><?= fmt_points($total_earned) ?></div>
        </div>

        <div class="stat-card">
            <h4><i class="fas fa-gift"></i> Total Points Redeemed</h4>
            <div class="number small-number"><?= fmt_points($total_redeemed) ?></div>
        </div>

        <div class="stat-card">
            <h4><i class="fas fa-clock"></i> Pending Discount</h4>
            <div class="number small-number">$<?= number_format($pending_discount_total, 2) ?></div>
            <small class="text-muted">Will apply on next payment</small>
        </div>
    </div>

    <!-- Pending Redemptions List -->
    <?php if (!empty($active_redemptions)): ?>
    <div class="section">
        <h2><i class="fas fa-hourglass-half"></i> Pending Redemptions (Sugaya Discount)</h2>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Points Used</th>
                        <th>Discount Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($active_redemptions as $redemption): ?>
                    <tr>
                        <td><?= date('d/m/Y H:i', strtotime($redemption['redemption_date'])) ?></td>
                        <td><?= number_format((float)$redemption['points_used'], 0) ?></td>
                        <td class="text-success">$<?= number_format((float)$redemption['discount_amount'], 2) ?></td>
                        <td><span class="points-badge points-pending"><i class="fas fa-clock"></i> Pending</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Applied Redemptions List -->
    <?php if (!empty($applied_redemptions)): ?>
    <div class="section">
        <h2><i class="fas fa-check-circle"></i> Applied Discounts</h2>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Points Used</th>
                        <th>Discount Amount</th>
                        <th>Applied to Payment</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($applied_redemptions as $redemption): ?>
                    <tr>
                        <td><?= date('d/m/Y H:i', strtotime($redemption['applied_at'] ?? $redemption['redemption_date'])) ?></td>
                        <td><?= number_format((float)$redemption['points_used'], 0) ?></td>
                        <td class="text-success">$<?= number_format((float)$redemption['discount_amount'], 2) ?></td>
                        <td>
                            <?php if ($redemption['payment_number']): ?>
                                <?= htmlspecialchars($redemption['payment_number']) ?>
                            <?php else: ?>
                                Payment #<?= $redemption['applied_to_payment_id'] ?>
                            <?php endif; ?>
                        </td>
                        <td><span class="points-badge points-earned"><i class="fas fa-check"></i> Applied</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Redemption Section -->
    <div class="redemption-box">
        <h3><i class="fas fa-exchange-alt"></i> Redeem Your Points</h3>
        <p>Use your loyalty points to get discounts on future shipments!</p>
        <p class="text-muted"><strong>Rate:</strong> 100 points = $1.00 discount (must be in multiples of 100)</p>
        
        <?php if (!$show_redemption_form): ?>
        <form method="POST" class="row justify-content-center">
            <div class="col-md-4">
                <div class="form-group">
                    <label>Points to Redeem (multiples of 100):</label>
                    <input type="number" step="100" name="points_to_redeem" class="form-control" 
                           placeholder="100, 200, 300..." min="100" max="<?= floor((float)($customer['loyalty_points'] ?? 0) / 100) * 100 ?>" required>
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button type="submit" name="redeem_points" class="btn-primary-custom w-100">
                        <i class="fas fa-calculator"></i> Calculate
                    </button>
                </div>
            </div>
        </form>
        <?php else: ?>
        <div class="redemption-preview">
            <h5><i class="fas fa-info-circle info-icon"></i> Redemption Summary</h5>
            <table class="table table-sm">
                <tr>
                    <td><strong>Points to Redeem:</strong></th>
                    <td><?= number_format($points_to_redeem, 0) ?></td>
                </tr>
                <tr>
                    <td><strong>Discount Value:</strong></th>
                    <td class="text-success"><strong>$<?= number_format($redemption_amount, 2) ?></strong></td>
                </tr>
                <tr>
                    <td><strong>Points Remaining After:</strong></th>
                    <td><?= number_format(((float)($customer['loyalty_points'] ?? 0) - $points_to_redeem), 2) ?></td>
                </tr>
            </table>
            <form method="POST" class="mt-3">
                <input type="hidden" name="points_to_redeem" value="<?= $points_to_redeem ?>">
                <input type="hidden" name="redemption_value" value="<?= $redemption_amount ?>">
                <button type="submit" name="confirm_redemption" class="btn-success-custom" onclick="return confirm('Ma hubtaa inaad rabto inaad sarifto <?= number_format($points_to_redeem, 0) ?> points si aad u hesho $$redemption_amount dhimis? Dhimista waxaa lagu dabaqi doonaa bixintaada xiga.')">
                    <i class="fas fa-check-circle"></i> Confirm Redemption
                </button>
                <a href="loyalty_points.php" class="btn-outline-custom ml-2">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- How Points Work Section -->
    <div class="section">
        <h2><i class="fas fa-info-circle"></i> How Loyalty Points Work</h2>
        <div class="row">
            <div class="col-md-6">
                <div class="alert alert-info">
                    <i class="fas fa-star info-icon"></i> <strong>Earning Points:</strong><br>
                    You earn <?= htmlspecialchars($customer['loyalty_amount_points'] ?? 5) ?> points for every $100 you spend on shipments.<br>
                    <small class="text-muted">Example: $250 payment = <?= round((250 / 100) * ($customer['loyalty_amount_points'] ?? 5), 2) ?> points</small>
                </div>
            </div>
            <div class="col-md-6">
                <div class="alert alert-info">
                    <i class="fas fa-gift info-icon"></i> <strong>Redeeming Points:</strong><br>
                    100 points = $1.00 discount on your next shipment.<br>
                    <small class="text-muted">Points must be redeemed in multiples of 100. Discount applies automatically to your next payment!</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Points History -->
    <div class="section">
        <h2><i class="fas fa-history"></i> My Points History</h2>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Points Earned</th>
                        <th>Points Redeemed</th>
                        <th>Amount</th>
                        <th>Reason</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($points_history)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding:40px;">
                                <i class="fas fa-history" style="font-size: 40px; color: #ccc;"></i>
                                <p class="mt-2">Ma jiraan wax dhaqdhaqaaq ah oo ku saabsan points-kaaga.</p>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($points_history as $log): ?>
                        <tr>
                            <td><small><?= date('d/m/Y H:i', strtotime($log['created_at'] ?? 'now')) ?></small></td>
                            <td>
                                <?php if (($log['points_earned'] ?? 0) > 0): ?>
                                    <span class="points-badge points-earned">
                                        <i class="fas fa-plus-circle"></i> +<?= number_format((float)$log['points_earned'], 2) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (($log['points_redeemed'] ?? 0) > 0): ?>
                                    <span class="points-badge points-redeemed">
                                        <i class="fas fa-minus-circle"></i> -<?= number_format((float)$log['points_redeemed'], 2) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (($log['amount_earned'] ?? 0) > 0): ?>
                                    $<?= number_format((float)$log['amount_earned'], 2) ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><small><?= htmlspecialchars($log['reason'] ?? '-') ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>

            </table>
        </div>
    </div>

    <!-- Payment History (Where points came from) -->
    <div class="section">
        <h2><i class="fas fa-receipt"></i> Recent Payments</h2>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Payment Number</th>
                        <th>Date</th>
                        <th>Original Amount</th>
                        <th>Discount</th>
                        <th>Final Amount</th>
                        <th>Points Earned</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($payment_history)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding:40px;">
                                <i class="fas fa-receipt" style="font-size: 40px; color: #ccc;"></i>
                                <p class="mt-2">Ma jiraan wax bixin ah oo la diiwaangeliyay.</p>
                              </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($payment_history as $payment): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($payment['payment_number'] ?? '-') ?></strong></td>
                            <td><?= date('d/m/Y', strtotime($payment['payment_date'] ?? $payment['created_at'] ?? 'now')) ?></td>
                            <td>$<?= number_format((float)($payment['original_amount'] ?? $payment['amount']), 2) ?></td>
                            <td>
                                <?php if (($payment['discount_applied'] ?? 0) > 0): ?>
                                    <span class="text-success">-$<?= number_format((float)$payment['discount_applied'], 2) ?></span>
                                    <div><small>(<?= number_format((float)$payment['points_used'], 0) ?> pts)</small></div>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>$<?= number_format((float)($payment['amount'] ?? 0), 2) ?></td>
                            <td>
                                <span class="points-badge points-earned">
                                    +<?= number_format((float)($payment['points_earned'] ?? 0), 2) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>

            </table>
        </div>
    </div>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>