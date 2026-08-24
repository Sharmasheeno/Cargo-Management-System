<?php
// tenant_admin/loyalty_points.php
//Cargo Management System - Tenant Loyalty Points Management with Redemption Management

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = $_SESSION['user_name'] ?? 'Tenant Admin';
$user_role = $_SESSION['role'] ?? ($_SESSION['role_type'] ?? '');
$user_tenant_id = (int)($_SESSION['tenant_id'] ?? 0);

if ($user_tenant_id <= 0) {
    die("Tenant lama helin. Fadlan logout samee kadib mar kale login.");
}

$allowed_roles = ['tenant_admin', 'admin', 'manager'];
if (!in_array($user_role, $allowed_roles, true)) {
    die("Access denied. Page-kan waxaa isticmaali kara Tenant Admin kaliya.");
}

function ensureLoyaltySchema(PDO $pdo): void
{
    try {
        $checks = [
            ['customers', 'loyalty_points', 'DECIMAL(12,2) DEFAULT 0'],
            ['loyalty_points_log', 'points_earned', 'DECIMAL(12,2) DEFAULT 0'],
            ['loyalty_points_log', 'points_redeemed', 'DECIMAL(12,2) DEFAULT 0'],
        ];

        foreach ($checks as $item) {
            [$table, $column, $definition] = $item;
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            $col = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($col && stripos($col['Type'] ?? '', 'decimal') === false) {
                $pdo->exec("ALTER TABLE `$table` MODIFY `$column` $definition");
            }
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
                )
            ");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM tenants LIKE 'loyalty_amount_points'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE tenants ADD COLUMN loyalty_amount_points INT DEFAULT 5");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM tenants LIKE 'loyalty_cbm_points'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE tenants ADD COLUMN loyalty_cbm_points INT DEFAULT 10 AFTER loyalty_amount_points");
        }
    } catch (Exception $e) {
        error_log("Loyalty schema check failed: " . $e->getMessage());
    }
}

ensureLoyaltySchema($pdo);

function awardLoyaltyPointsForPayment(PDO $pdo, int $payment_id, int $tenant_id, int $created_by): array
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
            WHERE p.id = ? AND p.tenant_id = ?
            LIMIT 1
        ");
        $stmt->execute([$payment_id, $tenant_id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            return ['success' => false, 'points' => 0.00, 'message' => 'Payment lama helin ama tenant-kan kuma jiro.'];
        }

        if (empty($payment['customer_id'])) {
            return ['success' => false, 'points' => 0.00, 'message' => 'Payment-kan customer kuma xirna.'];
        }

        $amount = (float)$payment['amount'];
        $rate = max(0, (int)$payment['loyalty_amount_points']);
        $points = round(($amount / 100) * $rate, 2);

        if ($amount <= 0 || $rate <= 0 || $points <= 0) {
            return ['success' => false, 'points' => 0.00, 'message' => 'Payment-kan points ma dhalin.'];
        }

        $check = $pdo->prepare("
            SELECT id
            FROM loyalty_points_log
            WHERE tenant_id = ?
              AND reference_type = 'payment'
              AND reference_id = ?
              AND points_earned > 0
            LIMIT 1
        ");
        $check->execute([$tenant_id, $payment_id]);

        if ($check->fetch()) {
            return ['success' => false, 'points' => 0.00, 'message' => 'Payment-kan hore ayaa points loogu daray.'];
        }

        $updateCustomer = $pdo->prepare("
            UPDATE customers
            SET loyalty_points = COALESCE(loyalty_points, 0) + ?
            WHERE id = ? AND tenant_id = ?
        ");
        $updateCustomer->execute([
            $points,
            (int)$payment['customer_id'],
            $tenant_id
        ]);

        if ($updateCustomer->rowCount() < 1) {
            return ['success' => false, 'points' => 0.00, 'message' => 'Customer lama update-gareyn.'];
        }

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
            $tenant_id,
            (int)$payment['customer_id'],
            $points,
            $amount,
            'Automatic loyalty points from payment #' . $payment['payment_number'],
            $payment_id,
            $created_by
        ]);

        return [
            'success' => true,
            'points' => $points,
            'message' => number_format($points, 2) . ' points ayaa customer-ka loo daray.'
        ];
    } catch (PDOException $e) {
        error_log("Award loyalty points error: " . $e->getMessage());
        return ['success' => false, 'points' => 0.00, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

$message = '';
$error = '';

// Handle redemption actions (accept/decline)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Update loyalty settings
        if (isset($_POST['update_settings'])) {
            $loyalty_amount_points = max(0, (int)($_POST['loyalty_amount_points'] ?? 0));
            $loyalty_cbm_points = max(0, (int)($_POST['loyalty_cbm_points'] ?? 0));

            $stmt = $pdo->prepare("
                UPDATE tenants
                SET loyalty_amount_points = ?, loyalty_cbm_points = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$loyalty_amount_points, $loyalty_cbm_points, $user_tenant_id]);

            $message = "Loyalty settings waa la update gareeyay.";
        }

        // Award points for single payment
        if (isset($_POST['award_payment_id'])) {
            $payment_id = (int)$_POST['award_payment_id'];

            $pdo->beginTransaction();
            $result = awardLoyaltyPointsForPayment($pdo, $payment_id, $user_tenant_id, $user_id);

            if (!$result['success']) {
                throw new Exception($result['message']);
            }

            $pdo->commit();
            $message = $result['message'];
        }

        // Award all missing payments
        if (isset($_POST['award_all_missing'])) {
            $stmt = $pdo->prepare("
                SELECT p.id
                FROM payments p
                LEFT JOIN loyalty_points_log l
                    ON l.tenant_id = p.tenant_id
                   AND l.reference_type = 'payment'
                   AND l.reference_id = p.id
                   AND l.points_earned > 0
                WHERE p.tenant_id = ?
                  AND p.customer_id IS NOT NULL
                  AND p.customer_id > 0
                  AND l.id IS NULL
                ORDER BY p.id ASC
                LIMIT 500
            ");
            $stmt->execute([$user_tenant_id]);
            $paymentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($paymentIds)) {
                $message = "Ma jiraan payments aan points helin.";
            } else {
                $pdo->beginTransaction();

                $totalAwarded = 0.00;
                $totalPayments = 0;
                $errors = [];

                foreach ($paymentIds as $pid) {
                    $result = awardLoyaltyPointsForPayment($pdo, (int)$pid, $user_tenant_id, $user_id);

                    if ($result['success']) {
                        $totalAwarded += (float)$result['points'];
                        $totalPayments++;
                    } else {
                        $errors[] = "Payment $pid: " . $result['message'];
                    }
                }

                $pdo->commit();

                $message = "$totalPayments payments ayaa points loo daray. Wadarta points: " . number_format($totalAwarded, 2);

                if (!empty($errors)) {
                    $message .= "<br><small class='muted'>Errors: " . htmlspecialchars(implode(", ", array_slice($errors, 0, 5))) . "</small>";
                }
            }
        }

        // ACCEPT REDEMPTION - Apply redemption to a payment
        if (isset($_POST['accept_redemption'])) {
            $redemption_id = (int)$_POST['redemption_id'];
            $payment_id = (int)($_POST['payment_id'] ?? 0);
            
            $pdo->beginTransaction();
            
            // Get redemption details
            $stmt = $pdo->prepare("
                SELECT r.*, c.customer_name 
                FROM point_redemptions r
                LEFT JOIN customers c ON c.id = r.customer_id
                WHERE r.id = ? AND r.tenant_id = ? AND r.status = 'pending'
            ");
            $stmt->execute([$redemption_id, $user_tenant_id]);
            $redemption = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$redemption) {
                throw new Exception("Redemption not found or already processed.");
            }
            
            // If payment_id is provided, apply to specific payment
            if ($payment_id > 0) {
                // Update the payment with discount
                $stmt = $pdo->prepare("
                    UPDATE payments 
                    SET discount_applied = COALESCE(discount_applied, 0) + ?,
                        points_used = COALESCE(points_used, 0) + ?,
                        amount = amount - ?
                    WHERE id = ? AND tenant_id = ?
                ");
                $stmt->execute([
                    $redemption['discount_amount'],
                    $redemption['points_used'],
                    $redemption['discount_amount'],
                    $payment_id,
                    $user_tenant_id
                ]);
                
                // Update redemption status
                $stmt = $pdo->prepare("
                    UPDATE point_redemptions 
                    SET status = 'applied', 
                        applied_to_payment_id = ?,
                        applied_at = NOW(),
                        payment_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([$payment_id, $payment_id, $redemption_id]);
                
                // Update log to show applied
                $stmt = $pdo->prepare("
                    UPDATE loyalty_points_log 
                    SET reason = CONCAT(reason, ' - Applied to payment #', ?)
                    WHERE reference_type = 'redemption' AND reference_id = ?
                ");
                $stmt->execute([$payment_id, $redemption_id]);
                
                $message = "Redemption of $" . number_format($redemption['discount_amount'], 2) . " has been applied to payment.";
            } else {
                // Mark as applied without specific payment (will be used for future)
                $stmt = $pdo->prepare("
                    UPDATE point_redemptions 
                    SET status = 'applied', applied_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$redemption_id]);
                $message = "Redemption has been marked as applied.";
            }
            
            $pdo->commit();
        }
        
        // CANCEL/DECLINE REDEMPTION - Return points to customer
        if (isset($_POST['decline_redemption'])) {
            $redemption_id = (int)$_POST['redemption_id'];
            
            $pdo->beginTransaction();
            
            // Get redemption details
            $stmt = $pdo->prepare("
                SELECT r.* 
                FROM point_redemptions r
                WHERE r.id = ? AND r.tenant_id = ? AND r.status = 'pending'
            ");
            $stmt->execute([$redemption_id, $user_tenant_id]);
            $redemption = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$redemption) {
                throw new Exception("Redemption not found or already processed.");
            }
            
            // Return points to customer
            $stmt = $pdo->prepare("
                UPDATE customers 
                SET loyalty_points = loyalty_points + ?
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([
                $redemption['points_used'],
                $redemption['customer_id'],
                $user_tenant_id
            ]);
            
            // Update redemption status
            $stmt = $pdo->prepare("
                UPDATE point_redemptions 
                SET status = 'cancelled'
                WHERE id = ?
            ");
            $stmt->execute([$redemption_id]);
            
            // Update log
            $stmt = $pdo->prepare("
                UPDATE loyalty_points_log 
                SET reason = CONCAT(reason, ' - CANCELLED and points returned')
                WHERE reference_type = 'redemption' AND reference_id = ?
            ");
            $stmt->execute([$redemption_id]);
            
            // Add return log
            $stmt = $pdo->prepare("
                INSERT INTO loyalty_points_log
                (tenant_id, customer_id, points_earned, points_redeemed, amount_earned, reason, reference_type, reference_id, created_by, created_at)
                VALUES (?, ?, ?, 0, 0, 'Points returned from cancelled redemption', 'redemption_return', ?, ?, NOW())
            ");
            $stmt->execute([
                $user_tenant_id,
                $redemption['customer_id'],
                $redemption['points_used'],
                $redemption_id,
                $user_id
            ]);
            
            $pdo->commit();
            $message = "Redemption cancelled and " . number_format($redemption['points_used'], 2) . " points returned to customer.";
        }
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

/* Tenant */
$tenant = null;
try {
    $stmt = $pdo->prepare("
        SELECT id, name, loyalty_amount_points, loyalty_cbm_points
        FROM tenants
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_tenant_id]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tenant) {
        die("Tenant database-ka lagama helin.");
    }
} catch (Exception $e) {
    die("Tenant error: " . $e->getMessage());
}

/* Stats */
$stats = [
    'customers' => 0,
    'current_points' => 0,
    'earned_points' => 0,
    'redeemed_points' => 0,
    'pending_payments' => 0,
    'pending_redemptions' => 0,
    'pending_redemption_value' => 0
];

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE tenant_id = ?");
    $stmt->execute([$user_tenant_id]);
    $stats['customers'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(loyalty_points), 0) FROM customers WHERE tenant_id = ?");
    $stmt->execute([$user_tenant_id]);
    $stats['current_points'] = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(points_earned), 0) FROM loyalty_points_log WHERE tenant_id = ?");
    $stmt->execute([$user_tenant_id]);
    $stats['earned_points'] = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(points_redeemed), 0) FROM loyalty_points_log WHERE tenant_id = ?");
    $stmt->execute([$user_tenant_id]);
    $stats['redeemed_points'] = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM payments p
        LEFT JOIN loyalty_points_log l
            ON l.tenant_id = p.tenant_id
           AND l.reference_type = 'payment'
           AND l.reference_id = p.id
           AND l.points_earned > 0
        WHERE p.tenant_id = ?
          AND p.customer_id IS NOT NULL
          AND p.customer_id > 0
          AND l.id IS NULL
    ");
    $stmt->execute([$user_tenant_id]);
    $stats['pending_payments'] = (int)$stmt->fetchColumn();
    
    // Pending redemptions stats
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(discount_amount), 0) as total
        FROM point_redemptions
        WHERE tenant_id = ? AND status = 'pending'
    ");
    $stmt->execute([$user_tenant_id]);
    $redemptionStats = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['pending_redemptions'] = (int)($redemptionStats['count'] ?? 0);
    $stats['pending_redemption_value'] = (float)($redemptionStats['total'] ?? 0);
    
} catch (Exception $e) {
    $error = $error ?: $e->getMessage();
}

/* Pending payments */
$pendingPayments = [];
try {
    $stmt = $pdo->prepare("
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
        LEFT JOIN customers c ON c.id = p.customer_id AND c.tenant_id = p.tenant_id
        LEFT JOIN tenants t ON t.id = p.tenant_id
        LEFT JOIN loyalty_points_log l
            ON l.tenant_id = p.tenant_id
           AND l.reference_type = 'payment'
           AND l.reference_id = p.id
           AND l.points_earned > 0
        WHERE p.tenant_id = ?
          AND p.customer_id IS NOT NULL
          AND p.customer_id > 0
          AND l.id IS NULL
        ORDER BY p.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$user_tenant_id]);
    $pendingPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pendingPayments = [];
}

/* PENDING REDEMPTIONS - New section */
$pendingRedemptions = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            c.customer_name,
            c.phone,
            c.email
        FROM point_redemptions r
        LEFT JOIN customers c ON c.id = r.customer_id AND c.tenant_id = r.tenant_id
        WHERE r.tenant_id = ? AND r.status = 'pending'
        ORDER BY r.redemption_date ASC
    ");
    $stmt->execute([$user_tenant_id]);
    $pendingRedemptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pendingRedemptions = [];
}

/* APPLIED REDEMPTIONS */
$appliedRedemptions = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            c.customer_name,
            c.phone,
            p.payment_number,
            p.amount as payment_amount
        FROM point_redemptions r
        LEFT JOIN customers c ON c.id = r.customer_id AND c.tenant_id = r.tenant_id
        LEFT JOIN payments p ON p.id = r.applied_to_payment_id
        WHERE r.tenant_id = ? AND r.status IN ('applied', 'partial')
        ORDER BY r.applied_at DESC
        LIMIT 100
    ");
    $stmt->execute([$user_tenant_id]);
    $appliedRedemptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $appliedRedemptions = [];
}

/* CANCELLED REDEMPTIONS */
$cancelledRedemptions = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            c.customer_name,
            c.phone
        FROM point_redemptions r
        LEFT JOIN customers c ON c.id = r.customer_id AND c.tenant_id = r.tenant_id
        WHERE r.tenant_id = ? AND r.status = 'cancelled'
        ORDER BY r.redemption_date DESC
        LIMIT 50
    ");
    $stmt->execute([$user_tenant_id]);
    $cancelledRedemptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $cancelledRedemptions = [];
}

/* Customers */
$customers = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.customer_name,
            c.phone,
            c.loyalty_points,
            c.debt_amount,
            t.name AS tenant_name
        FROM customers c
        LEFT JOIN tenants t ON t.id = c.tenant_id
        WHERE c.tenant_id = ?
        ORDER BY c.loyalty_points DESC, c.customer_name ASC
        LIMIT 200
    ");
    $stmt->execute([$user_tenant_id]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $customers = [];
}

/* Logs */
$logs = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            l.*,
            c.customer_name,
            c.phone,
            t.name AS tenant_name
        FROM loyalty_points_log l
        LEFT JOIN customers c ON c.id = l.customer_id AND c.tenant_id = l.tenant_id
        LEFT JOIN tenants t ON t.id = l.tenant_id
        WHERE l.tenant_id = ?
        ORDER BY l.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$user_tenant_id]);
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
    <title>Loyalty Points | Tenant Admin</title>
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

        .tenant-badge {
            background: #f3e5f5;
            color: #6a1b9a;
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
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
            cursor: pointer;
            font-size: 12px;
        }

        .btn-success-custom:hover {
            background: #1b5e20;
        }

        .btn-danger-custom {
            background: #B42318;
            color: white;
            border: none;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
            cursor: pointer;
            font-size: 12px;
        }

        .btn-danger-custom:hover {
            background: #b71c1c;
        }

        .btn-light-custom {
            background: #fff;
            color: var(--curdun-dark);
            border: 1px solid #ccc;
            padding: 9px 14px;
            border-radius: 20px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn-light-custom:hover {
            color: var(--curdun-violet);
            text-decoration: none;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 18px;
        }

        .stat-card h4 {
            font-size: 13px;
            color: var(--curdun-gray);
            margin: 0 0 8px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .stat-card .number {
            font-size: 30px;
            font-weight: 700;
            color: var(--curdun-violet);
        }

        .stat-card .small-number {
            font-size: 20px;
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

        .alert-warning {
            background: #fff3e0;
            color: #e65100;
            border-left: 4px solid #e65100;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
            gap: 12px;
            align-items: end;
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

        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 14px;
            font-size: 12px;
            font-weight: 700;
        }

        .badge-pending {
            background: #fff3e0;
            color: #e65100;
        }

        .badge-applied {
            background: #EEFBF3;
            color: #0F7A3A;
        }

        .badge-cancelled {
            background: #fce8e6;
            color: #B42318;
        }

        .badge-green {
            background: #EEFBF3;
            color: #0F7A3A;
        }

        .muted {
            color: #777;
            font-size: 12px;
        }

        .redemption-row {
            border-left: 3px solid #ffc107;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .action-buttons { flex-direction: column; }
        }
    </style>
</head>

<body>
<div class="container-fluid">

    <div class="page-header">
        <div>
            <h1><i class="fas fa-star"></i> Loyalty Points Management</h1>
            <div class="tenant-badge mt-2">
                <i class="fas fa-building"></i>
                <?= htmlspecialchars($tenant['name'] ?? 'Tenant') ?>
            </div>
        </div>

        <div>
            <a href="payments.php" class="btn-light-custom">
                <i class="fas fa-arrow-left"></i> Back to Payments
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

    <!-- Pending Redemptions Alert -->
    <?php if ($stats['pending_redemptions'] > 0): ?>
        <div class="alert alert-warning">
            <i class="fas fa-clock"></i> <strong>Waxaad haysataa <?= $stats['pending_redemptions'] ?> redemption(s) sugaya!</strong><br>
            Wadarta dhimista sugaya: <strong>$<?= number_format($stats['pending_redemption_value'], 2) ?></strong><br>
            Fadlan ka eeg qaybta "Pending Redemptions" si aad u aqbasho ama u diido.
        </div>
    <?php endif; ?>

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
            <div class="number small-number"><?= number_format((float)$stats['earned_points'], 2) ?></div>
        </div>

        <div class="stat-card">
            <h4><i class="fas fa-gift"></i> Redeemed Points</h4>
            <div class="number small-number"><?= number_format((float)$stats['redeemed_points'], 2) ?></div>
        </div>

        <div class="stat-card">
            <h4><i class="fas fa-hourglass-half"></i> Pending Redemptions</h4>
            <div class="number small-number"><?= number_format($stats['pending_redemptions']) ?></div>
            <small class="muted">$<?= number_format($stats['pending_redemption_value'], 2) ?> total</small>
        </div>

        <div class="stat-card">
            <h4><i class="fas fa-clock"></i> Pending Payments</h4>
            <div class="number small-number"><?= number_format($stats['pending_payments']) ?></div>
        </div>
    </div>

    <div class="section">
        <h2><i class="fas fa-cog"></i> Loyalty Settings</h2>
        <p class="muted">
            <i class="fas fa-info-circle"></i>
            Formula: (payment amount / 100) × Points per $100 Payment
        </p>

        <form method="POST">
            <input type="hidden" name="update_settings" value="1">

            <div class="form-grid">
                <div>
                    <label>Company / Tenant</label>
                    <input type="text" value="<?= htmlspecialchars($tenant['name']) ?>" readonly>
                </div>

                <div>
                    <label>Points per $100 Payment</label>
                    <input 
                        type="number" 
                        name="loyalty_amount_points" 
                        min="0" 
                        value="<?= htmlspecialchars((string)($tenant['loyalty_amount_points'] ?? 5)) ?>" 
                        required
                    >
                </div>

                <div>
                    <label>CBM Points per CBM</label>
                    <input 
                        type="number" 
                        name="loyalty_cbm_points" 
                        min="0" 
                        value="<?= htmlspecialchars((string)($tenant['loyalty_cbm_points'] ?? 10)) ?>" 
                        required
                    >
                </div>

                <div>
                    <button type="submit" class="btn-primary-custom">
                        <i class="fas fa-save"></i> Save
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- PENDING REDEMPTIONS SECTION - NEW -->
    <div class="section">
        <h2><i class="fas fa-hourglass-half"></i> Pending Redemptions (Sugaya Aqbal/Diyar)</h2>
        <p class="muted">Marka customer uu sarifto points, waa inaad aqbasho ama diido si loogu dabaqo dhimista ama loogu celiyo points-ka.</p>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Points Used</th>
                        <th>Discount Amount</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pendingRedemptions)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:40px;">
                                <i class="fas fa-check-circle" style="font-size: 40px; color: #0F7A3A;"></i>
                                <p class="mt-2">Ma jiraan wax redemptions ah oo sugaya.</p>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($pendingRedemptions as $redemption): ?>
                        <tr class="redemption-row">
                            <td><?= date('d/m/Y H:i', strtotime($redemption['redemption_date'])) ?></td>
                            <td><strong><?= htmlspecialchars($redemption['customer_name'] ?? 'Unknown') ?></strong></td>
                            <td><?= htmlspecialchars($redemption['phone'] ?? '-') ?></td>
                            <td><?= number_format((float)$redemption['points_used'], 0) ?> pts</td>
                            <td class="text-success"><strong>$<?= number_format((float)$redemption['discount_amount'], 2) ?></strong></td>
                            <td>
                                <span class="badge badge-pending">
                                    <i class="fas fa-clock"></i> Pending
                                </span>
                            </td>
                            <td class="action-buttons">
                                <!-- Accept Form -->
                                <form method="POST" style="display: inline-block;" onsubmit="return confirm('Ma hubtaa inaad aqbasho dhimista $<?= number_format((float)$redemption['discount_amount'], 2) ?>? Waxaad dooran kartaa payment-ka ay ku dabaqmayso.')">
                                    <input type="hidden" name="redemption_id" value="<?= $redemption['id'] ?>">
                                    <input type="hidden" name="payment_id" value="0">
                                    <button type="submit" name="accept_redemption" class="btn-success-custom">
                                        <i class="fas fa-check-circle"></i> Accept
                                    </button>
                                </form>
                                
                                <!-- Decline Form -->
                                <form method="POST" style="display: inline-block;" onsubmit="return confirm('Ma hubtaa inaad diido dhimista? Points-ka waxaa loogu celin doonaa customer-ka.')">
                                    <input type="hidden" name="redemption_id" value="<?= $redemption['id'] ?>">
                                    <button type="submit" name="decline_redemption" class="btn-danger-custom">
                                        <i class="fas fa-times-circle"></i> Decline
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- APPLIED REDEMPTIONS SECTION -->
    <div class="section">
        <h2><i class="fas fa-check-circle"></i> Applied Redemptions</h2>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date Applied</th>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Points Used</th>
                        <th>Discount</th>
                        <th>Applied To</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($appliedRedemptions)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:40px;">
                                <i class="fas fa-history" style="font-size: 40px; color: #ccc;"></i>
                                <p class="mt-2">Ma jiraan wax redemptions ah oo la dabaqay.</p>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($appliedRedemptions as $redemption): ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($redemption['applied_at'] ?? $redemption['redemption_date'])) ?></td>
                            <td><?= htmlspecialchars($redemption['customer_name'] ?? 'Unknown') ?></td>
                            <td><?= htmlspecialchars($redemption['phone'] ?? '-') ?></td>
                            <td><?= number_format((float)$redemption['points_used'], 0) ?> pts</td>
                            <td class="text-success">$<?= number_format((float)$redemption['discount_amount'], 2) ?></td>
                            <td>
                                <?php if ($redemption['payment_number']): ?>
                                    <?= htmlspecialchars($redemption['payment_number']) ?>
                                <?php else: ?>
                                    Payment #<?= $redemption['applied_to_payment_id'] ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-applied"><i class="fas fa-check"></i> Applied</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- CANCELLED REDEMPTIONS SECTION -->
    <div class="section">
        <h2><i class="fas fa-ban"></i> Cancelled Redemptions</h2>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date Requested</th>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Points Used</th>
                        <th>Discount Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cancelledRedemptions)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding:40px;">
                                <i class="fas fa-history" style="font-size: 40px; color: #ccc;"></i>
                                <p class="mt-2">Ma jiraan wax redemptions ah oo la joojiyay.</p>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($cancelledRedemptions as $redemption): ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($redemption['redemption_date'])) ?></td>
                            <td><?= htmlspecialchars($redemption['customer_name'] ?? 'Unknown') ?></td>
                            <td><?= htmlspecialchars($redemption['phone'] ?? '-') ?></td>
                            <td><?= number_format((float)$redemption['points_used'], 0) ?> pts</td>
                            <td>$<?= number_format((float)$redemption['discount_amount'], 2) ?></td>
                            <td><span class="badge badge-cancelled"><i class="fas fa-ban"></i> Cancelled</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="section">
        <h2><i class="fas fa-hourglass-half"></i> Payments aan wali points helin</h2>

        <form method="POST" style="margin-bottom: 20px;">
            <input type="hidden" name="award_all_missing" value="1">
            <button 
                type="submit" 
                class="btn-primary-custom"
                onclick="return confirm('Ma rabtaa in dhammaan pending payments points loo daro?')"
            >
                <i class="fas fa-trophy"></i> Award All Missing
            </button>
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
                                <p class="mt-2">Ma jiraan pending payments. Dhammaan payments waa la shaqeeyay.</p>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($pendingPayments as $payment): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($payment['payment_number'] ?? '-') ?></strong></td>
                            <td><?= htmlspecialchars($payment['customer_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($payment['phone'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($payment['tenant_name'] ?? '-') ?></td>
                            <td>$<?= number_format((float)$payment['amount'], 2) ?></td>
                            <td>
                                <span class="badge badge-green">
                                    <?= number_format((float)$payment['expected_points'], 2) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($payment['payment_date'] ?? $payment['created_at'] ?? '-') ?></td>
                            <td>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="award_payment_id" value="<?= (int)$payment['id'] ?>">
                                    <button 
                                        type="submit" 
                                        class="btn-primary-custom" 
                                        style="padding: 5px 12px; font-size: 12px;"
                                        onclick="return confirm('Payment-kan points ma siinaysaa?')"
                                    >
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
                        <tr>
                            <td colspan="5" style="text-align:center; padding:40px;">
                                Customers lama helin.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($customers as $customer): ?>
                        <tr>
                            <td><?= htmlspecialchars($customer['customer_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($customer['phone'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($customer['tenant_name'] ?? '-') ?></td>
                            <td>$<?= number_format((float)($customer['debt_amount'] ?? 0), 2) ?></td>
                            <td>
                                <span class="badge badge-green">
                                    <?= number_format((float)($customer['loyalty_points'] ?? 0), 2) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>

            </table>
        </div>
    </div>

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
                        <tr>
                            <td colspan="8" style="text-align:center; padding:40px;">
                                Logs lama helin.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><small><?= htmlspecialchars($log['created_at'] ?? '-') ?></small></td>
                            <td><?= htmlspecialchars($log['customer_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($log['phone'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($log['tenant_name'] ?? '-') ?></td>
                            <td>
                                <span class="badge badge-green">
                                    <?= number_format((float)($log['points_earned'] ?? 0), 2) ?>
                                </span>
                            </td>
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