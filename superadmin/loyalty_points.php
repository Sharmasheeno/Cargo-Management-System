<?php
// superadmin/loyalty_points.php
//faras cargo - Loyalty Points Management

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = $_SESSION['user_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? ($_SESSION['role_type'] ?? '');
$user_tenant_id = $_SESSION['tenant_id'] ?? null;
$is_superadmin = ($user_role === 'superadmin');

/**
 * Only superadmin can view all companies.
 * Normal tenant users only see their own company loyalty data.
 */
if (!$is_superadmin && empty($user_tenant_id)) {
    die("Tenant lama helin. Fadlan mar kale login samee.");
}


/**
 * Ensure loyalty columns can store decimal points.
 * Required for formula: (payment_amount / 100) * loyalty_amount_points.
 * Example: $50 with rate 5 = 2.50 points.
 */
function ensureLoyaltyDecimalColumns(PDO $pdo): void
{
    try {
        $columns = [
            ['customers', 'loyalty_points', 'DECIMAL(12,2) DEFAULT 0'],
            ['loyalty_points_log', 'points_earned', 'DECIMAL(12,2) DEFAULT 0'],
            ['loyalty_points_log', 'points_redeemed', 'DECIMAL(12,2) DEFAULT 0'],
        ];

        foreach ($columns as $col) {
            [$table, $column, $definition] = $col;
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            $info = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($info && stripos($info['Type'] ?? '', 'decimal') === false) {
                $pdo->exec("ALTER TABLE `$table` MODIFY `$column` $definition");
            }
        }
    } catch (Exception $e) {
        // Do not break the page if ALTER permission is missing.
        error_log('Loyalty decimal schema check failed: ' . $e->getMessage());
    }
}

ensureLoyaltyDecimalColumns($pdo);

/**
 * Award loyalty points for one payment.
 * Rule: tenant.loyalty_amount_points points for every $100 customer payment.
 * Example: amount=250, loyalty_amount_points=5 => floor(250/100)*5 = 10 points.
 */
function awardLoyaltyPointsForPayment(PDO $pdo, int $payment_id, int $created_by = 0): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.tenant_id,
                p.customer_id,
                p.amount,
                p.payment_number,
                COALESCE(t.loyalty_amount_points, 5) AS loyalty_amount_points
            FROM payments p
            LEFT JOIN tenants t ON t.id = p.tenant_id
            WHERE p.id = ?
            LIMIT 1
        ");
        $stmt->execute([$payment_id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            return ['success' => false, 'points' => 0.00, 'message' => 'Payment lama helin'];
        }

        if (empty($payment['customer_id'])) {
            return ['success' => false, 'points' => 0.00, 'message' => 'Payment-kan customer kuma xirna'];
        }

        $amount = (float)$payment['amount'];
        $rate = max(0, (int)$payment['loyalty_amount_points']);
        $points = round(($amount / 100) * $rate, 2);

        if ($amount <= 0 || $rate <= 0 || $points <= 0) {
            return ['success' => false, 'points' => 0.00, 'message' => 'Payment-kan points ma dhalin'];
        }

        $check = $pdo->prepare("
            SELECT id
            FROM loyalty_points_log
            WHERE reference_type = 'payment'
              AND reference_id = ?
              AND points_earned > 0
            LIMIT 1
        ");
        $check->execute([$payment_id]);

        if ($check->fetch()) {
            return ['success' => false, 'points' => 0.00, 'message' => 'Payment-kan hore ayaa points loogu daray'];
        }

        // Update customer loyalty points
        $updateCustomer = $pdo->prepare("
            UPDATE customers
            SET loyalty_points = COALESCE(loyalty_points, 0) + ?
            WHERE id = ? AND tenant_id = ?
        ");
        $updateCustomer->execute([
            $points,
            (int)$payment['customer_id'],
            (int)$payment['tenant_id']
        ]);

        // Insert log entry (without cbm_earned if column doesn't exist)
        $insertLog = $pdo->prepare("
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
            VALUES (?, ?, ?, 0, ?, ?, 'payment', ?, ?, NOW())
        ");
        $insertLog->execute([
            (int)$payment['tenant_id'],
            (int)$payment['customer_id'],
            $points,
            $amount,
            'Automatic loyalty points from payment #' . $payment['payment_number'],
            $payment_id,
            $created_by
        ]);

        return ['success' => true, 'points' => $points, 'message' => number_format($points, 2) . ' points ayaa customer-ka loo daray'];
    } catch (PDOException $e) {
        error_log("Award loyalty points error: " . $e->getMessage());
        return ['success' => false, 'points' => 0.00, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    try {
        if (isset($_POST['update_settings'])) {
            $tenant_id = (int)($_POST['tenant_id'] ?? 0);
            $loyalty_amount_points = max(0, (int)($_POST['loyalty_amount_points'] ?? 0));
            $loyalty_cbm_points = max(0, (int)($_POST['loyalty_cbm_points'] ?? 0));

            if (!$is_superadmin) {
                $tenant_id = (int)$user_tenant_id;
            }

            if ($tenant_id <= 0) {
                throw new Exception("Fadlan dooro shirkad sax ah");
            }

            // Check if columns exist, if not add them
            $checkColumns = $pdo->query("SHOW COLUMNS FROM tenants LIKE 'loyalty_cbm_points'");
            if (!$checkColumns->fetch()) {
                $pdo->exec("ALTER TABLE tenants ADD COLUMN loyalty_cbm_points INT DEFAULT 0 AFTER loyalty_amount_points");
            }

            $stmt = $pdo->prepare("
                UPDATE tenants
                SET loyalty_amount_points = ?, loyalty_cbm_points = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$loyalty_amount_points, $loyalty_cbm_points, $tenant_id]);

            $message = "Loyalty settings waa la update gareeyay.";
        }

        if (isset($_POST['award_payment_id'])) {
            $payment_id = (int)$_POST['award_payment_id'];

            if (!$is_superadmin) {
                $check = $pdo->prepare("SELECT id FROM payments WHERE id = ? AND tenant_id = ?");
                $check->execute([$payment_id, $user_tenant_id]);
                if (!$check->fetch()) {
                    throw new Exception("Unauthorized payment");
                }
            }

            $pdo->beginTransaction();
            $result = awardLoyaltyPointsForPayment($pdo, $payment_id, $user_id);
            if (!$result['success']) {
                throw new Exception($result['message']);
            }
            $pdo->commit();

            $message = $result['message'];
        }

        if (isset($_POST['award_all_missing'])) {
            $tenant_filter = (int)($_POST['tenant_id'] ?? 0);

            $where = "WHERE p.customer_id IS NOT NULL AND p.customer_id > 0 AND l.id IS NULL";
            $params = [];

            if (!$is_superadmin) {
                $where .= " AND p.tenant_id = ?";
                $params[] = $user_tenant_id;
            } elseif ($tenant_filter > 0) {
                $where .= " AND p.tenant_id = ?";
                $params[] = $tenant_filter;
            }

            $stmt = $pdo->prepare("
                SELECT p.id
                FROM payments p
                LEFT JOIN loyalty_points_log l
                    ON l.reference_type = 'payment'
                   AND l.reference_id = p.id
                   AND l.points_earned > 0
                $where
                ORDER BY p.id ASC
                LIMIT 500
            ");
            $stmt->execute($params);
            $paymentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($paymentIds)) {
                $message = "Ma jiraan payments aan points helin.";
            } else {
                $pdo->beginTransaction();
                $totalAwarded = 0;
                $totalPayments = 0;
                $errors = [];

                foreach ($paymentIds as $pid) {
                    $result = awardLoyaltyPointsForPayment($pdo, (int)$pid, $user_id);
                    if ($result['success']) {
                        $totalAwarded += (int)$result['points'];
                        $totalPayments++;
                    } else {
                        $errors[] = "Payment $pid: " . $result['message'];
                    }
                }

                $pdo->commit();

                $message = "$totalPayments payments ayaa points loo daray. Wadarta points: $totalAwarded";
                if (!empty($errors)) {
                    $message .= "<br><small class='muted'>Errors: " . implode(", ", array_slice($errors, 0, 5)) . "</small>";
                }
            }
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

// Get tenants
$tenants = [];
try {
    if ($is_superadmin) {
        $stmt = $pdo->query("SELECT id, name, loyalty_amount_points, loyalty_cbm_points FROM tenants ORDER BY name ASC");
    } else {
        $stmt = $pdo->prepare("SELECT id, name, loyalty_amount_points, loyalty_cbm_points FROM tenants WHERE id = ?");
        $stmt->execute([$user_tenant_id]);
    }
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $tenants = [];
    $error = $error ?: "Error loading tenants: " . $e->getMessage();
}

// Get stats
$statsWhere = "";
$statsParams = [];
if (!$is_superadmin) {
    $statsWhere = "WHERE tenant_id = ?";
    $statsParams[] = $user_tenant_id;
}

$stats = [
    'customers' => 0,
    'current_points' => 0,
    'earned_points' => 0,
    'redeemed_points' => 0,
    'pending_payments' => 0
];

try {
    // Customers count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers $statsWhere");
    $stmt->execute($statsParams);
    $stats['customers'] = (int)$stmt->fetchColumn();

    // Current points total
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(loyalty_points), 0) FROM customers $statsWhere");
    $stmt->execute($statsParams);
    $stats['current_points'] = (int)$stmt->fetchColumn();

    // Earned points total
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(points_earned), 0) FROM loyalty_points_log $statsWhere");
    $stmt->execute($statsParams);
    $stats['earned_points'] = (int)$stmt->fetchColumn();

    // Redeemed points total
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(points_redeemed), 0) FROM loyalty_points_log $statsWhere");
    $stmt->execute($statsParams);
    $stats['redeemed_points'] = (int)$stmt->fetchColumn();

    // Pending payments count
    $pendingSql = "
        SELECT COUNT(*)
        FROM payments p
        LEFT JOIN loyalty_points_log l
            ON l.reference_type = 'payment'
           AND l.reference_id = p.id
           AND l.points_earned > 0
        WHERE p.customer_id IS NOT NULL
          AND p.customer_id > 0
          AND l.id IS NULL
    ";
    $pendingParams = [];
    if (!$is_superadmin) {
        $pendingSql .= " AND p.tenant_id = ?";
        $pendingParams[] = $user_tenant_id;
    }
    $stmt = $pdo->prepare($pendingSql);
    $stmt->execute($pendingParams);
    $stats['pending_payments'] = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    $error = $error ?: $e->getMessage();
}

// Get pending payments
$pendingPayments = [];
try {
    $sql = "
        SELECT 
            p.id,
            p.payment_number,
            p.amount,
            p.payment_date,
            p.created_at,
            c.customer_name,
            c.phone,
            t.name AS tenant_name,
            COALESCE(t.loyalty_amount_points, 5) AS loyalty_amount_points,
            ROUND((p.amount / 100) * COALESCE(t.loyalty_amount_points, 5), 2) AS expected_points
        FROM payments p
        LEFT JOIN customers c ON c.id = p.customer_id
        LEFT JOIN tenants t ON t.id = p.tenant_id
        LEFT JOIN loyalty_points_log l
            ON l.reference_type = 'payment'
           AND l.reference_id = p.id
           AND l.points_earned > 0
        WHERE p.customer_id IS NOT NULL
          AND p.customer_id > 0
          AND l.id IS NULL
    ";
    $params = [];
    if (!$is_superadmin) {
        $sql .= " AND p.tenant_id = ?";
        $params[] = $user_tenant_id;
    }
    $sql .= " ORDER BY p.created_at DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pendingPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pendingPayments = [];
}

// Get customers
$customers = [];
try {
    $sql = "
        SELECT c.id, c.customer_name, c.phone, c.loyalty_points, c.debt_amount, t.name AS tenant_name
        FROM customers c
        LEFT JOIN tenants t ON t.id = c.tenant_id
    ";
    $params = [];
    if (!$is_superadmin) {
        $sql .= " WHERE c.tenant_id = ?";
        $params[] = $user_tenant_id;
    }
    $sql .= " ORDER BY c.loyalty_points DESC, c.customer_name ASC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $customers = [];
}

// Get logs
$logs = [];
try {
    $sql = "
        SELECT l.*, c.customer_name, c.phone, t.name AS tenant_name
        FROM loyalty_points_log l
        LEFT JOIN customers c ON c.id = l.customer_id
        LEFT JOIN tenants t ON t.id = l.tenant_id
    ";
    $params = [];
    if (!$is_superadmin) {
        $sql .= " WHERE l.tenant_id = ?";
        $params[] = $user_tenant_id;
    }
    $sql .= " ORDER BY l.created_at DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $logs = [];
}

require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loyalty Points | Cargo Management System</title>
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
            --curdun-info: #0077c5;
            --border: #e0e1e6;
        }
        * { box-sizing: border-box; }
        body { background: #f4f5f8; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: var(--curdun-dark); margin: 0; }
        .container-fluid { padding: 20px; }
        .page-header { background: #fff; border-bottom: 1px solid var(--border); padding: 20px 25px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; border-radius: 8px; }
        .page-header h1 { color: var(--curdun-dark); font-size: 24px; font-weight: 700; margin: 0; }
        .page-header h1 i { color: var(--curdun-violet); margin-right: 10px; }
        .btn-primary-custom { background: var(--curdun-violet); color: white; border: none; padding: 10px 18px; border-radius: 20px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; transition: all 0.2s ease; }
        .btn-primary-custom:hover { background: var(--curdun-violet-light); color: white; text-decoration: none; transform: translateY(-1px); }
        .btn-light-custom { background: #fff; color: var(--curdun-dark); border: 1px solid #ccc; padding: 9px 14px; border-radius: 20px; font-weight: 600; cursor: pointer; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: white; border: 1px solid var(--border); border-radius: 8px; padding: 18px; transition: all 0.2s; }
        .stat-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .stat-card h4 { font-size: 13px; color: var(--curdun-gray); margin: 0 0 8px; font-weight: 600; text-transform: uppercase; }
        .stat-card .number { font-size: 30px; font-weight: 700; color: var(--curdun-violet); }
        .section { background: white; border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 25px; }
        .section h2 { font-size: 18px; margin: 0 0 15px; padding-bottom: 10px; border-bottom: 1px solid var(--border); color: var(--curdun-dark); }
        .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 18px; }
        .alert-success { background: #EEFBF3; color: #0F7A3A; border-left: 4px solid #0F7A3A; }
        .alert-error { background: #fce8e6; color: #B42318; border-left: 4px solid #B42318; }
        .form-grid { display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 12px; align-items: end; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: var(--curdun-dark); }
        input, select { width: 100%; padding: 10px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        input:focus, select:focus { border-color: var(--curdun-violet); outline: none; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 11px 10px; text-align: left; border-bottom: 1px solid var(--border); vertical-align: middle; }
        th { background: #f9f9fb; font-weight: 600; color: var(--curdun-gray); font-size: 13px; white-space: nowrap; }
        tr:hover { background: #f9f9fb; }
        .table-wrap { overflow-x: auto; }
        .badge { display: inline-block; padding: 5px 10px; border-radius: 14px; font-size: 12px; font-weight: 700; background: #f3e5f5; color: #7b1fa2; }
        .badge-green { background: #EEFBF3; color: #0F7A3A; }
        .muted { color: #777; font-size: 12px; }
        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="page-header">
        <h1><i class="fas fa-star"></i> Loyalty Points</h1>
        <div>
            <a href="payments.php" class="btn-light-custom"><i class="fas fa-arrow-left"></i> Back to Payments</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error alert-dismissible fade show">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <h4><i class="fas fa-users"></i> Customers</h4>
            <div class="number"><?= number_format($stats['customers']) ?></div>
        </div>
        <div class="stat-card">
            <h4><i class="fas fa-coins"></i> Current Points</h4>
            <div class="number"><?= number_format((float)$stats['current_points'], 2) ?></div>
        </div>
        <div class="stat-card">
            <h4><i class="fas fa-chart-line"></i> Earned Points</h4>
            <div class="number"><?= number_format((float)$stats['earned_points'], 2) ?></div>
        </div>
        <div class="stat-card">
            <h4><i class="fas fa-gift"></i> Redeemed Points</h4>
            <div class="number"><?= number_format((float)$stats['redeemed_points'], 2) ?></div>
        </div>
        <div class="stat-card">
            <h4><i class="fas fa-clock"></i> Pending Payments</h4>
            <div class="number"><?= number_format($stats['pending_payments']) ?></div>
        </div>
    </div>

    <!-- Loyalty Settings -->
    <div class="section">
        <h2><i class="fas fa-cog"></i> Loyalty Settings</h2>
        <p class="muted"><i class="fas fa-info-circle"></i> Formula: (payment amount / 100) × Points per $100 Payment</p>

        <form method="POST">
            <input type="hidden" name="update_settings" value="1">
            <div class="form-grid">
                <div>
                    <label>Company / Tenant</label>
                    <select name="tenant_id" required>
                        <?php foreach ($tenants as $tenant): ?>
                            <option value="<?= (int)$tenant['id'] ?>">
                                <?= htmlspecialchars($tenant['name']) ?> — $100 Points: <?= (int)($tenant['loyalty_amount_points'] ?? 5) ?>, CBM Points: <?= (int)($tenant['loyalty_cbm_points'] ?? 10) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Points per $100 Payment</label>
                    <input type="number" name="loyalty_amount_points" min="0" value="<?= htmlspecialchars((string)($tenants[0]['loyalty_amount_points'] ?? 5)) ?>" required>
                </div>
                <div>
                    <label>CBM Points (per CBM)</label>
                    <input type="number" name="loyalty_cbm_points" min="0" value="<?= htmlspecialchars((string)($tenants[0]['loyalty_cbm_points'] ?? 10)) ?>" required>
                </div>
                <div>
                    <button type="submit" class="btn-primary-custom"><i class="fas fa-save"></i> Save Settings</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Pending Payments -->
    <div class="section">
        <h2><i class="fas fa-hourglass-half"></i> Payments aan wali points helin</h2>
        
        <form method="POST" style="margin-bottom: 20px;">
            <input type="hidden" name="award_all_missing" value="1">
            <div class="form-row align-items-end">
                <?php if ($is_superadmin): ?>
                    <div class="col-md-3">
                        <label>Filter by Company</label>
                        <select name="tenant_id" class="form-control">
                            <option value="0">All Companies</option>
                            <?php foreach ($tenants as $tenant): ?>
                                <option value="<?= (int)$tenant['id'] ?>"><?= htmlspecialchars($tenant['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn-primary-custom" onclick="return confirm('Ma rabtaa in dhammaan pending payments points loo daro?')">
                            <i class="fas fa-trophy"></i> Award All Missing
                        </button>
                    </div>
                <?php else: ?>
                    <div class="col-md-3">
                        <button type="submit" class="btn-primary-custom" onclick="return confirm('Ma rabtaa in dhammaan pending payments points loo daro?')">
                            <i class="fas fa-trophy"></i> Award All Missing
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </form>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Payment No</th>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Company</th>
                        <th>Amount</th>
                        <th>Expected Points</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pendingPayments)): ?>
                        <tr>
                            <td colspan="8" style="text-align:center; padding:40px;">
                                <i class="fas fa-check-circle" style="font-size: 40px; color: #0F7A3A;"></i>
                                <p class="mt-2">Ma jiraan pending payments. Dhammaan payments waa la shaqeeyay!</p>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($pendingPayments as $payment): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($payment['payment_number']) ?></strong></td>
                            <td><?= htmlspecialchars($payment['customer_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($payment['phone'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($payment['tenant_name'] ?? '-') ?></td>
                            <td>$<?= number_format((float)$payment['amount'], 2) ?></td>
                            <td><span class="badge badge-green"><?= number_format((float)$payment['expected_points'], 2) ?></span></td>
                            <td><?= htmlspecialchars($payment['payment_date']) ?></td>
                            <td>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="award_payment_id" value="<?= (int)$payment['id'] ?>">
                                    <button type="submit" class="btn-primary-custom" style="padding: 5px 12px; font-size: 12px;">
                                        <i class="fas fa-gift"></i> Give Points
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Customers Points -->
    <div class="section">
        <h2><i class="fas fa-trophy"></i> Customers Points</h2>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Company</th>
                        <th>Debt</th>
                        <th>Points</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                        <tr><td colspan="5" style="text-align:center; padding:40px;">Customers lama helin.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($customers as $customer): ?>
                        <tr>
                            <td><?= htmlspecialchars($customer['customer_name']) ?></td>
                            <td><?= htmlspecialchars($customer['phone'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($customer['tenant_name'] ?? '-') ?></td>
                            <td>$<?= number_format((float)($customer['debt_amount'] ?? 0), 2) ?></td>
                            <td><span class="badge"><?= number_format((float)($customer['loyalty_points'] ?? 0), 2) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Logs -->
    <div class="section">
        <h2><i class="fas fa-history"></i> Recent Loyalty Logs</h2>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Company</th>
                        <th>Earned</th>
                        <th>Redeemed</th>
                        <th>Amount</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="8" style="text-align:center; padding:40px;">Logs lama helin.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><small><?= htmlspecialchars($log['created_at']) ?></small></td>
                            <td><?= htmlspecialchars($log['customer_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($log['phone'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($log['tenant_name'] ?? '-') ?></td>
                            <td><span class="badge badge-green"><?= number_format((float)($log['points_earned'] ?? 0), 2) ?></span></td>
                            <td><?= number_format((float)($log['points_redeemed'] ?? 0), 2) ?></td>
                            <td>$<?= number_format((float)($log['amount_earned'] ?? 0), 2) ?></td>
                            <td><small><?= htmlspecialchars($log['reason'] ?? '-') ?></small></td>
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