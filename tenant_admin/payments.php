<?php
// tenant_admin/payments.php - Clean version WITHOUT automatic loyalty & discount

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/audit_helper.php';

if (file_exists(__DIR__ . '/../includes/AccountingService.php')) {
    require_once __DIR__ . '/../includes/AccountingService.php';
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? '';
$user_tenant_id = $_SESSION['tenant_id'] ?? null;

if (!$user_tenant_id) {
    header("Location: ../login.php?error=no_tenant");
    exit;
}

$user_tenant = null;
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.role_type, u.tenant_id,
               t.id as tenant_id, t.name as tenant_name, t.code as tenant_code,
               t.logo_url, t.address as tenant_address, t.phone as tenant_phone
        FROM users u
        LEFT JOIN tenants t ON u.tenant_id = t.id
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
    $user_tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_tenant || !$user_tenant['tenant_id']) {
        header("Location: ../login.php?error=tenant_not_found");
        exit;
    }
    $user_tenant_id = $user_tenant['tenant_id'];
} catch (PDOException $e) {
    $user_tenant = null;
    header("Location: ../login.php?error=db_error");
    exit;
}

$is_superadmin = false;

// Get bank accounts
$bank_accounts = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, account_name, bank_name, account_number, currency, current_balance, tenant_id
        FROM bank_accounts
        WHERE is_active = 1 AND tenant_id = ?
        ORDER BY account_name
    ");
    $stmt->execute([$user_tenant_id]);
    $bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $bank_accounts = [];
}

// REMOVED: generatePaymentNumberForTenant remains (no loyalty)
function generatePaymentNumberForTenant(PDO $pdo, int $tenant_id): string
{
    $year = date('Y');
    $month = date('m');

    $seqStmt = $pdo->prepare("
        SELECT prefix, current_number, padding
        FROM tenant_sequences
        WHERE tenant_id = ? AND sequence_name = 'payment'
        LIMIT 1
    ");
    $seqStmt->execute([$tenant_id]);
    $sequence = $seqStmt->fetch(PDO::FETCH_ASSOC);

    if (!$sequence) {
        $tenantStmt = $pdo->prepare("SELECT code, name FROM tenants WHERE id = ? LIMIT 1");
        $tenantStmt->execute([$tenant_id]);
        $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);

        $cleanPrefix = '';
        if ($tenant && !empty($tenant['code'])) {
            $cleanPrefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $tenant['code']));
        }
        if (empty($cleanPrefix) && $tenant && !empty($tenant['name'])) {
            $cleanPrefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $tenant['name']), 0, 3));
        }
        if (empty($cleanPrefix)) {
            $cleanPrefix = 'PMT';
        }

        $insertSeq = $pdo->prepare("
            INSERT INTO tenant_sequences (tenant_id, sequence_name, prefix, current_number, padding)
            VALUES (?, 'payment', ?, 1, 5)
        ");
        $insertSeq->execute([$tenant_id, $cleanPrefix]);

        $sequence = [
            'prefix' => $cleanPrefix,
            'current_number' => 1,
            'padding' => 5
        ];
    }

    $prefix = !empty($sequence['prefix']) ? $sequence['prefix'] : 'PMT';
    $current = max(1, (int)($sequence['current_number'] ?? 1));
    $padding = max(3, (int)($sequence['padding'] ?? 5));

    $number = str_pad($current, $padding, '0', STR_PAD_LEFT);
    $payment_number = $prefix . $year . $month . '-' . $number;

    $updateStmt = $pdo->prepare("
        UPDATE tenant_sequences
        SET current_number = current_number + 1
        WHERE tenant_id = ? AND sequence_name = 'payment'
    ");
    $updateStmt->execute([$tenant_id]);

    return $payment_number;
}

// REMOVED: awardLoyaltyPointsForPayment function
// REMOVED: reverseLoyaltyPointsForPayment function
// REMOVED: getActiveRedemption function
// REMOVED: applyRedemptionToPayment function
// REMOVED: calculateDiscountFromRedemption function

function generateReceiptNumberForTenant(PDO $pdo, int $tenant_id): string
{
    $prefix = 'RCP';
    try {
        $tenantStmt = $pdo->prepare("SELECT code, name FROM tenants WHERE id = ? LIMIT 1");
        $tenantStmt->execute([$tenant_id]);
        $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);
        if ($tenant && !empty($tenant['code'])) {
            $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $tenant['code'])) . 'RCP';
        }
    } catch (Exception $e) {}

    do {
        $receipt_number = $prefix . '-' . date('YmdHis') . '-' . rand(1000, 9999);
        $check = $pdo->prepare("SELECT id FROM receipts WHERE receipt_number = ? AND tenant_id = ? LIMIT 1");
        $check->execute([$receipt_number, $tenant_id]);
    } while ($check->fetch());

    return $receipt_number;
}

function syncReceiptForPayment(PDO $pdo, int $payment_id, int $tenant_id, int $created_by = 0): void
{
    $stmt = $pdo->prepare("
        SELECT p.*, c.customer_name, i.invoice_number
        FROM payments p
        LEFT JOIN customers c ON c.id = p.customer_id
        LEFT JOIN invoices i ON i.id = p.invoice_id
        WHERE p.id = ? AND p.tenant_id = ?
        LIMIT 1
    ");
    $stmt->execute([$payment_id, $tenant_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment || empty($payment['customer_id'])) {
        return;
    }

    $existing = $pdo->prepare("SELECT id, receipt_number FROM receipts WHERE payment_id = ? AND tenant_id = ? LIMIT 1");
    $existing->execute([$payment_id, $tenant_id]);
    $receipt = $existing->fetch(PDO::FETCH_ASSOC);

    $receipt_number = $receipt['receipt_number'] ?? generateReceiptNumberForTenant($pdo, $tenant_id);
    $amount = (float)($payment['amount'] ?? 0);

    $notes = trim((string)($payment['notes'] ?? ''));
    $autoNote = "Auto receipt from payment #{$payment['payment_number']}";
    $notes = $notes ? ($notes . "\n" . $autoNote) : $autoNote;

    if ($receipt) {
        $upd = $pdo->prepare("
            UPDATE receipts
            SET invoice_id = ?, customer_id = ?, amount = ?,
                payment_date = ?, payment_method = ?, reference_number = ?, notes = ?
            WHERE id = ? AND tenant_id = ?
        ");
        $upd->execute([
            $payment['invoice_id'] ?: null,
            $payment['customer_id'] ?: null,
            $amount,
            $payment['payment_date'] ?: date('Y-m-d'),
            $payment['payment_method'] ?: 'cash',
            $payment['reference_number'] ?? '',
            $notes,
            $receipt['id'],
            $tenant_id
        ]);
    } else {
        $ins = $pdo->prepare("
            INSERT INTO receipts
            (tenant_id, receipt_number, invoice_id, payment_id, customer_id, amount,
             payment_date, payment_method, reference_number, notes, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $ins->execute([
            $tenant_id,
            $receipt_number,
            $payment['invoice_id'] ?: null,
            $payment_id,
            $payment['customer_id'] ?: null,
            $amount,
            $payment['payment_date'] ?: date('Y-m-d'),
            $payment['payment_method'] ?: 'cash',
            $payment['reference_number'] ?? '',
            $notes,
            $created_by
        ]);
    }
}

function deleteReceiptForPayment(PDO $pdo, int $payment_id, int $tenant_id): void
{
    try {
        $stmt = $pdo->prepare("DELETE FROM receipts WHERE payment_id = ? AND tenant_id = ?");
        $stmt->execute([$payment_id, $tenant_id]);
    } catch (Exception $e) {}
}

// Handle GET exports
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action === 'export_payments') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=payments_export_'.date('Y-m-d').'.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['ID', 'Payment Number', 'Date', 'Entity', 'Invoice', 'Amount', 'Category', 'Method', 'Reference']);
        
        $where_conditions = ["p.tenant_id = ?"];
        $params = [$user_tenant_id];
        
        $search = $_GET['search'] ?? '';
        
        if (!empty($search)) {
            $where_conditions[] = "(p.payment_number LIKE ? OR p.supplier_name LIKE ? OR c.customer_name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
        
        $sql = "SELECT p.*, c.customer_name, i.invoice_number 
                FROM payments p 
                LEFT JOIN customers c ON p.customer_id = c.id 
                LEFT JOIN invoices i ON p.invoice_id = i.id 
                $where_clause 
                ORDER BY p.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['id'],
                $row['payment_number'],
                $row['payment_date'],
                $row['customer_name'] ?? $row['supplier_name'] ?? '-',
                $row['invoice_number'] ?? '-',
                $row['amount'],
                $row['category'],
                $row['payment_method'],
                $row['reference_number']
            ]);
        }
        fclose($output);
        exit;
    }
    
    if ($action === 'download_sample') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=payments_sample.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, ['Payment Number', 'Payment Date (YYYY-MM-DD)', 'Amount', 'Type (customer/supplier)', 'Customer/Supplier Name', 'Invoice Number', 'Method (cash/bank_transfer/check/mobile_money)', 'Reference', 'Category']);
        fputcsv($output, ['PMT-001', date('Y-m-d'), '500.00', 'customer', 'John Doe', 'INV-5001', 'cash', '', 'Service Payment']);
        fclose($output);
        exit;
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];
    
    if ($action === 'get_payments') {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $search = $_POST['search'] ?? '';
        $category_filter = $_POST['category'] ?? 'all';
        $payment_method_filter = $_POST['payment_method'] ?? 'all';
        $date_from = $_POST['date_from'] ?? '';
        $date_to = $_POST['date_to'] ?? '';
        
        $where_conditions = ["p.tenant_id = ?"];
        $params = [$user_tenant_id];
        
        if (!empty($search)) {
            $where_conditions[] = "(p.payment_number LIKE ? OR p.supplier_name LIKE ? OR p.reference_number LIKE ? OR c.customer_name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($category_filter !== 'all') {
            $where_conditions[] = "p.category = ?";
            $params[] = $category_filter;
        }
        
        if ($payment_method_filter !== 'all') {
            $where_conditions[] = "p.payment_method = ?";
            $params[] = $payment_method_filter;
        }
        
        if (!empty($date_from)) {
            $where_conditions[] = "p.payment_date >= ?";
            $params[] = $date_from;
        }
        
        if (!empty($date_to)) {
            $where_conditions[] = "p.payment_date <= ?";
            $params[] = $date_to;
        }
        
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
        
        $count_sql = "SELECT COUNT(*) as total FROM payments p
                      LEFT JOIN customers c ON p.customer_id = c.id
                      $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_payments = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_payments / $limit);
        
        $sql = "
            SELECT p.*, 
                   c.customer_name,
                   c.id as customer_id,
                   i.invoice_number,
                   ba.account_name as bank_account_name, ba.bank_name,
                   u.full_name as created_by_name
            FROM payments p
            LEFT JOIN customers c ON p.customer_id = c.id
            LEFT JOIN invoices i ON p.invoice_id = i.id
            LEFT JOIN bank_accounts ba ON p.bank_account_id = ba.id
            LEFT JOIN users u ON p.created_by = u.id
            $where_clause
            ORDER BY p.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_start(); ?>
        <div style="overflow-x: auto; width: 100%;">
            <table class="payments-table" style="min-width: 1200px; width: 100%;">
                <thead>
                    <tr>
                        <th style="padding: 12px 8px;">ID</th>
                        <th style="padding: 12px 8px;">Lambarka Bixinta</th>
                        <th style="padding: 12px 8px;">Taariikhda</th>
                        <th style="padding: 12px 8px;">Macaamilka / Alaab-qeybiyaha</th>
                        <th style="padding: 12px 8px;">Biilka</th>
                        <th style="padding: 12px 8px;">Qadarka</th>
                        <th style="padding: 12px 8px;">Nooca</th>
                        <th style="padding: 12px 8px;">Habka Bixinta</th>
                        <th style="padding: 12px 8px;">Lambarka Tixraaca</th>
                        <th style="padding: 12px 8px;">Xisaabta Bangiga</th>
                        <th style="padding: 12px 8px;">Sameeyay</th>
                        <th style="padding: 12px 8px;">Rasiidka</th>
                        <th style="padding: 12px 8px;">Falalka</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($payments) > 0): ?>
                        <?php foreach ($payments as $payment): 
                            $methodNames = [
                                'cash' => 'Cash',
                                'bank_transfer' => 'Bank Transfer',
                                'check' => 'Check',
                                'mobile_money' => 'Mobile Money'
                            ];
                            $methodClass = 'method-' . str_replace('_', '-', $payment['payment_method']);
                            $methodIcon = $payment['payment_method'] == 'cash' ? 'fa-money-bill' : ($payment['payment_method'] == 'bank_transfer' ? 'fa-university' : ($payment['payment_method'] == 'check' ? 'fa-money-check' : 'fa-mobile-alt'));
                        ?>
                            <tr>
                                <td style="padding: 10px 8px;"><?= htmlspecialchars($payment['id']) ?></td>
                                <td style="padding: 10px 8px;">
                                    <strong><?= htmlspecialchars($payment['payment_number']) ?></strong>
                                    <div style="font-size: 10px; color: #6c757d;"><i class="fas fa-clock"></i> <?= date('H:i', strtotime($payment['created_at'])) ?></div>
                                 </td>
                                <td style="padding: 10px 8px;"><?= date('d/m/Y', strtotime($payment['payment_date'])) ?> </td>
                                <td style="padding: 10px 8px;">
                                    <strong><?= htmlspecialchars($payment['customer_name'] ?? $payment['supplier_name'] ?? '-') ?></strong>
                                    <?php if ($payment['customer_id']): ?>
                                        <div style="font-size: 10px; color: #0F7A3A;">Macaamil</div>
                                    <?php endif; ?>
                                 </td>
                                <td style="padding: 10px 8px;"><?php if ($payment['invoice_number']): ?><span class="invoice-link"><?= htmlspecialchars($payment['invoice_number']) ?></span><?php else: ?>-<?php endif; ?> </td>
                                <td style="padding: 10px 8px;"><strong class="text-danger">$<?= number_format($payment['amount'], 2) ?></strong> </td>
                                <td style="padding: 10px 8px;"><span class="category-badge"><?= htmlspecialchars($payment['category'] ?? '-') ?></span> </td>
                                <td style="padding: 10px 8px;"><span class="payment-method-badge <?= $methodClass ?>"><i class="fas <?= $methodIcon ?>"></i> <?= $methodNames[$payment['payment_method']] ?? ucfirst($payment['payment_method']) ?></span> </td>
                                <td style="padding: 10px 8px;">
                                    <?php if ($payment['reference_number'] && $payment['payment_method'] != 'cash'): ?>
                                        <code><?= htmlspecialchars($payment['reference_number']) ?></code>
                                    <?php else: ?>
                                        <span style="color: #ccc;">-</span>
                                    <?php endif; ?>
                                 </td>
                                <td style="padding: 10px 8px;">
                                    <?php if ($payment['bank_account_name'] && $payment['payment_method'] == 'bank_transfer'): ?>
                                        <small><?= htmlspecialchars($payment['bank_account_name']) ?></small>
                                    <?php else: ?>
                                        <span style="color: #ccc;">-</span>
                                    <?php endif; ?>
                                 </td>
                                <td style="padding: 10px 8px;"><?= htmlspecialchars($payment['created_by_name'] ?? '-') ?> </td>
                                <td style="padding: 10px 8px;">
                                    <button onclick="openReceiptPopup(<?= $payment['id'] ?>)" class="action-btn btn-receipt" style="background: #EEFBF3; color: #0F7A3A; padding: 5px 10px; border-radius: 4px; border: none; cursor: pointer; font-size: 12px; display: inline-block;">
                                        <i class="fas fa-receipt"></i> Rasiid
                                    </button>
                                 </td>
                                <td style="padding: 10px 8px;">
                                    <div class="action-buttons" style="display: flex; gap: 5px;">
                                        <button class="action-btn btn-view view-payment" data-id="<?= $payment['id'] ?>" style="background: none; border: none; cursor: pointer; color: #0077c5;"><i class="fas fa-eye"></i></button>
                                        <button class="action-btn btn-edit edit-payment" data-id="<?= $payment['id'] ?>" style="background: none; border: none; cursor: pointer; color: #393a3d;"><i class="fas fa-edit"></i></button>
                                        <button class="action-btn btn-delete delete-payment" data-id="<?= $payment['id'] ?>" data-name="<?= htmlspecialchars($payment['payment_number']) ?>" style="background: none; border: none; cursor: pointer; color: #B42318;"><i class="fas fa-trash"></i></button>
                                    </div>
                                 </td>
                              </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="13" style="text-align: center; padding: 50px;">
                            <div class="empty-state">
                                <i class="fas fa-money-bill-wave" style="font-size: 48px; color: #ccc;"></i>
                                <p style="margin-top: 15px;">Ma jiraan wax bixin ah</p>
                                <button class="btn-primary-custom" id="addPaymentBtnEmpty" style="margin-top: 10px;"><i class="fas fa-plus-circle"></i> Bixin Cusub</button>
                            </div>
                        </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        $table_html = ob_get_clean();
        
        ob_start();
        if ($total_pages > 1): ?>
            <div class="pagination" style="display: flex; justify-content: center; gap: 5px; margin-top: 20px;">
                <?php if ($page > 1): ?><a data-page="<?= $page-1 ?>" style="padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; cursor: pointer;"><i class="fas fa-chevron-left"></i> Hore</a><?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?><span class="active" style="padding: 8px 12px; background: #0077c5; color: white; border-radius: 4px;"><?= $i ?></span><?php else: ?><a data-page="<?= $i ?>" style="padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; cursor: pointer;"><?= $i ?></a><?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?><a data-page="<?= $page+1 ?>" style="padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; cursor: pointer;">Danbe <i class="fas fa-chevron-right"></i></a><?php endif; ?>
            </div>
        <?php endif;
        $pagination_html = ob_get_clean();
        
        echo json_encode([
            'success' => true,
            'table_html' => $table_html,
            'pagination_html' => $pagination_html
        ]);
        exit;
    }
    
    elseif ($action === 'get_payment') {
        $id = $_POST['id'] ?? 0;
        
        try {
            $stmt = $pdo->prepare("
                SELECT p.*, c.customer_name, i.invoice_number,
                       ba.account_name as bank_account_name, ba.bank_name, ba.account_number,
                       u.full_name as created_by_name
                FROM payments p
                LEFT JOIN customers c ON p.customer_id = c.id
                LEFT JOIN invoices i ON p.invoice_id = i.id
                LEFT JOIN bank_accounts ba ON p.bank_account_id = ba.id
                LEFT JOIN users u ON p.created_by = u.id
                WHERE p.id = ? AND p.tenant_id = ?
            ");
            $stmt->execute([$id, $user_tenant_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$payment) {
                echo json_encode(['success' => false, 'message' => 'Payment not found or unauthorized']);
                exit;
            }
            echo json_encode(['success' => true, 'data' => $payment]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'save_payment') {
        $id = $_POST['payment_id'] ?? '';
        $payment_number = trim($_POST['payment_number'] ?? '');
        $payment_type = $_POST['payment_type'] ?? 'customer';
        $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $supplier_name = trim($_POST['supplier_name'] ?? '');
        $invoice_id = !empty($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null;
        $amount = (float)($_POST['amount'] ?? 0);
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $category = trim($_POST['category'] ?? '');
        $reference_number = trim($_POST['reference_number'] ?? '');
        $bank_account_id = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
        $notes = trim($_POST['notes'] ?? '');
        $tenant_id = $user_tenant_id;
        
        try {
            if (empty($payment_number)) {
                echo json_encode(['success' => false, 'message' => 'Lambarka bixinta waa lagama maarmaan']);
                exit;
            }
            
            if ($amount <= 0) {
                echo json_encode(['success' => false, 'message' => 'Qadarka bixinta waa inuu ka weyn yahay 0']);
                exit;
            }
            
            if ($payment_type === 'customer') {
                if (empty($customer_id)) {
                    echo json_encode(['success' => false, 'message' => 'Fadlan dooro macaamilka']);
                    exit;
                }
            } else {
                if (empty($supplier_name)) {
                    echo json_encode(['success' => false, 'message' => 'Magaca alaab-qeybiyaha waa lagama maarmaan']);
                    exit;
                }
            }
            
            if ($payment_method != 'cash' && empty($reference_number)) {
                $method_names = ['bank_transfer' => 'Bank Transfer', 'check' => 'Check', 'mobile_money' => 'Mobile Money'];
                echo json_encode(['success' => false, 'message' => 'Lambarka tixraaca waa lagama maarmaan markaad doorato ' . ($method_names[$payment_method] ?? ucfirst(str_replace('_', ' ', $payment_method)))]);
                exit;
            }
            
            if ($payment_method === 'bank_transfer' && empty($bank_account_id)) {
                echo json_encode(['success' => false, 'message' => 'Fadlan dooro xisaabta bangiga ee lagu bixiyay lacagta']);
                exit;
            }
            
            if ($bank_account_id) {
                $check = $pdo->prepare("SELECT id FROM bank_accounts WHERE id = ? AND tenant_id = ?");
                $check->execute([$bank_account_id, $tenant_id]);
                if (!$check->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Xisaabta bangiga aan la aqbalin']);
                    exit;
                }
            }
            
            $pdo->beginTransaction();
            
            if (empty($id)) {
                // Check for existing payment number
                $check = $pdo->prepare("SELECT id FROM payments WHERE payment_number = ? AND tenant_id = ?");
                $check->execute([$payment_number, $tenant_id]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'message' => "Lambarka bixinta '$payment_number' waxaa horay loo isticmaalay"]);
                    exit;
                }
                
                // Check invoice if selected
                if ($payment_type === 'customer' && $invoice_id) {
                    $invStmt = $pdo->prepare("SELECT total_amount, paid_amount FROM invoices WHERE id = ? AND tenant_id = ?");
                    $invStmt->execute([$invoice_id, $tenant_id]);
                    $invoice = $invStmt->fetch();
                    
                    if ($invoice) {
                        $invoice_total = $invoice['total_amount'];
                        $invoice_paid_before = $invoice['paid_amount'];
                        $invoice_due_before = $invoice_total - $invoice_paid_before;
                        
                        if ($amount > $invoice_due_before) {
                            echo json_encode(['success' => false, 'message' => "Qadarka bixinta ($$amount) wuu ka badan yahay deynta biilka ($$invoice_due_before)"]);
                            exit;
                        }
                    }
                }
                
                // NO DISCOUNT CALCULATION - Just use the amount as is
                $final_amount = $amount;
                
                // Update invoice with amount
                if ($payment_type === 'customer' && $invoice_id) {
                    $invStmt = $pdo->prepare("SELECT total_amount, paid_amount FROM invoices WHERE id = ? AND tenant_id = ?");
                    $invStmt->execute([$invoice_id, $tenant_id]);
                    $invoice = $invStmt->fetch();
                    
                    if ($invoice) {
                        $new_paid_amount = $invoice['paid_amount'] + $final_amount;
                        $new_status = ($new_paid_amount >= $invoice['total_amount']) ? 'paid' : 'partial';
                        
                        $updateInv = $pdo->prepare("UPDATE invoices SET paid_amount = ?, status = ?, updated_at = NOW() WHERE id = ?");
                        $updateInv->execute([$new_paid_amount, $new_status, $invoice_id]);
                    }
                }
                
                // Update bank account balance
                if ($payment_method === 'bank_transfer' && $bank_account_id) {
                    if ($payment_type === 'customer') {
                        // Customer payment: money IN to bank
                        $stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ? AND tenant_id = ?");
                        $stmt->execute([$final_amount, $bank_account_id, $tenant_id]);
                    } else {
                        // Supplier payment: money OUT from bank
                        $stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ? AND tenant_id = ?");
                        $stmt->execute([$final_amount, $bank_account_id, $tenant_id]);
                    }
                }
                
                // Insert payment (NO discount fields needed)
                if ($payment_type === 'customer') {
                    $sql = "INSERT INTO payments (tenant_id, payment_number, customer_id, invoice_id, amount, payment_date, payment_method, category, reference_number, bank_account_id, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$tenant_id, $payment_number, $customer_id, $invoice_id, $final_amount, $payment_date, $payment_method, $category, $reference_number, $bank_account_id, $notes, $_SESSION['user_id']]);
                } else {
                    $sql = "INSERT INTO payments (tenant_id, payment_number, supplier_name, amount, payment_date, payment_method, category, reference_number, bank_account_id, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$tenant_id, $payment_number, $supplier_name, $final_amount, $payment_date, $payment_method, $category, $reference_number, $bank_account_id, $notes, $_SESSION['user_id']]);
                }
                
                $new_payment_id = $pdo->lastInsertId();
                
                // NO LOYALTY POINTS AWARDED
                
                // Update customer debt
                if ($payment_type === 'customer' && $customer_id) {
                    $updateDebt = $pdo->prepare("UPDATE customers SET debt_amount = debt_amount - ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
                    $updateDebt->execute([$final_amount, $customer_id, $tenant_id]);
                }
                
                // Create receipt
                if ($payment_type === 'customer' && $customer_id) {
                    syncReceiptForPayment($pdo, (int)$new_payment_id, $tenant_id, (int)($_SESSION['user_id'] ?? 0));
                }

                // Cash flow entry
                if ($payment_type === 'customer') {
                    $cashStmt = $pdo->prepare("INSERT INTO cash_flow (tenant_id, flow_date, inflow, description, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $cashStmt->execute([$tenant_id, $payment_date, $final_amount, "Bixin macaamil: $payment_number"]);
                } else {
                    $cashStmt = $pdo->prepare("INSERT INTO cash_flow (tenant_id, flow_date, outflow, description, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $cashStmt->execute([$tenant_id, $payment_date, $final_amount, "Bixin alaab-qeybiye: $payment_number"]);
                }
                
                if (class_exists('AccountingService')) {
                    try {
                        $accounting = new AccountingService($pdo, $tenant_id, $_SESSION['user_id']);
                        $accounting->journalizePayment($new_payment_id);
                    } catch (Exception $e) {}
                }
                
                try {
                    LogAudit($pdo, 'CREATE_PAYMENT', 'payments', $new_payment_id, null, ['amount' => $final_amount, 'number' => $payment_number]);
                } catch (Exception $e) {}
                
                $pdo->commit();
                
                $type_text = ($payment_type === 'customer') ? 'macaamil' : 'alaab-qeybiyaha';
                $message = "Bixinta oo ah $$final_amount oo loogu talagalay $type_text waa la diiwaangeliyay!<br>Lambarka: <strong>$payment_number</strong>";
                
                echo json_encode([
                    'success' => true, 
                    'message' => $message,
                    'payment_id' => $new_payment_id,
                    'payment_number' => $payment_number,
                    'amount' => $final_amount
                ]);
            } else {
                // UPDATE existing payment
                $oldStmt = $pdo->prepare("SELECT customer_id, invoice_id, supplier_name, amount, payment_method, bank_account_id FROM payments WHERE id = ? AND tenant_id = ?");
                $oldStmt->execute([$id, $tenant_id]);
                $oldPayment = $oldStmt->fetch();
                
                if (!$oldPayment) {
                    echo json_encode(['success' => false, 'message' => 'Payment not found or unauthorized']);
                    exit;
                }
                
                // NO LOYALTY REVERSAL

                // Revert old payment effects
                if ($oldPayment && $oldPayment['customer_id']) {
                    $revertDebt = $pdo->prepare("UPDATE customers SET debt_amount = debt_amount + ? WHERE id = ? AND tenant_id = ?");
                    $revertDebt->execute([$oldPayment['amount'], $oldPayment['customer_id'], $tenant_id]);
                    
                    if ($oldPayment['invoice_id']) {
                        $revertInv = $pdo->prepare("UPDATE invoices SET paid_amount = paid_amount - ?, status = CASE WHEN (total_amount - (paid_amount - ?)) <= 0 THEN 'paid' ELSE 'unpaid' END, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
                        $revertInv->execute([$oldPayment['amount'], $oldPayment['amount'], $oldPayment['invoice_id'], $tenant_id]);
                    }
                }
                
                if ($oldPayment && $oldPayment['payment_method'] === 'bank_transfer' && $oldPayment['bank_account_id']) {
                    $oldWasCustomer = !empty($oldPayment['customer_id']);
                    $bankRevertDelta = $oldWasCustomer ? -((float)$oldPayment['amount']) : (float)$oldPayment['amount'];
                    $revertStmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ? AND tenant_id = ?");
                    $revertStmt->execute([$bankRevertDelta, $oldPayment['bank_account_id'], $tenant_id]);
                }
                
                // NO DISCOUNT CALCULATION - just use amount as is
                $final_amount = $amount;
                
                // Update payment
                if ($payment_type === 'customer') {
                    $sql = "UPDATE payments SET payment_number = ?, customer_id = ?, invoice_id = ?, amount = ?, payment_date = ?, payment_method = ?, category = ?, reference_number = ?, bank_account_id = ?, notes = ? WHERE id = ? AND tenant_id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$payment_number, $customer_id, $invoice_id, $final_amount, $payment_date, $payment_method, $category, $reference_number, $bank_account_id, $notes, $id, $tenant_id]);
                    
                    if ($customer_id) {
                        $updateDebt = $pdo->prepare("UPDATE customers SET debt_amount = debt_amount - ? WHERE id = ? AND tenant_id = ?");
                        $updateDebt->execute([$final_amount, $customer_id, $tenant_id]);
                    }
                    
                    if ($invoice_id) {
                        $updateInv = $pdo->prepare("UPDATE invoices SET paid_amount = paid_amount + ?, status = CASE WHEN (paid_amount + ?) >= total_amount THEN 'paid' ELSE 'partial' END, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
                        $updateInv->execute([$final_amount, $final_amount, $invoice_id, $tenant_id]);
                    }
                } else {
                    $sql = "UPDATE payments SET payment_number = ?, supplier_name = ?, amount = ?, payment_date = ?, payment_method = ?, category = ?, reference_number = ?, bank_account_id = ?, notes = ? WHERE id = ? AND tenant_id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$payment_number, $supplier_name, $final_amount, $payment_date, $payment_method, $category, $reference_number, $bank_account_id, $notes, $id, $tenant_id]);
                }
                
                // NO LOYALTY POINTS AWARDED
                
                // Update bank account with new amount
                if ($payment_method === 'bank_transfer' && $bank_account_id) {
                    if ($payment_type === 'customer') {
                        $bankDelta = $final_amount;
                    } else {
                        $bankDelta = -$final_amount;
                    }
                    $newStmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ? AND tenant_id = ?");
                    $newStmt->execute([$bankDelta, $bank_account_id, $tenant_id]);
                }
                
                // Update receipt
                if ($payment_type === 'customer' && $customer_id) {
                    syncReceiptForPayment($pdo, (int)$id, $tenant_id, (int)($_SESSION['user_id'] ?? 0));
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => "Bixinta '$payment_number' waa la cusboonaysiiyay!"]);
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'delete_payment') {
        $id = $_POST['id'] ?? 0;
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT payment_number, amount, payment_method, bank_account_id, tenant_id, payment_date, customer_id, invoice_id, supplier_name FROM payments WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $user_tenant_id]);
            $payment = $stmt->fetch();
            
            if (!$payment) {
                echo json_encode(['success' => false, 'message' => 'Bixinta lama helin']);
                exit;
            }
            
            // NO LOYALTY REVERSAL

            if ($payment['customer_id']) {
                $revertDebt = $pdo->prepare("UPDATE customers SET debt_amount = debt_amount + ? WHERE id = ? AND tenant_id = ?");
                $revertDebt->execute([$payment['amount'], $payment['customer_id'], $user_tenant_id]);
                
                if ($payment['invoice_id']) {
                    $revertInv = $pdo->prepare("UPDATE invoices SET paid_amount = paid_amount - ?, status = CASE WHEN (total_amount - (paid_amount - ?)) <= 0 THEN 'paid' ELSE 'unpaid' END, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
                    $revertInv->execute([$payment['amount'], $payment['amount'], $payment['invoice_id'], $user_tenant_id]);
                }
            }
            
            if ($payment['payment_method'] === 'bank_transfer' && $payment['bank_account_id']) {
                $paymentWasCustomer = !empty($payment['customer_id']);
                $bankRevertDelta = $paymentWasCustomer ? -((float)$payment['amount']) : (float)$payment['amount'];
                $stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$bankRevertDelta, $payment['bank_account_id'], $user_tenant_id]);
            }
            
            deleteReceiptForPayment($pdo, (int)$id, $user_tenant_id);

            $stmt = $pdo->prepare("DELETE FROM payments WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $user_tenant_id]);
            
            $cashStmt = $pdo->prepare("DELETE FROM cash_flow WHERE tenant_id = ? AND description LIKE ? AND (inflow = ? OR outflow = ?) AND flow_date = ?");
            $cashStmt->execute([$user_tenant_id, "%{$payment['payment_number']}%", $payment['amount'], $payment['amount'], $payment['payment_date']]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => "Bixinta '{$payment['payment_number']}' waa la tirtiray!"]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'get_stats') {
        $where = "WHERE tenant_id = ?";
        $params = [$user_tenant_id];
        
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_payments,
                SUM(amount) as total_amount,
                SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END) as cash_total,
                SUM(CASE WHEN payment_method = 'bank_transfer' THEN amount ELSE 0 END) as bank_total,
                SUM(CASE WHEN payment_method = 'check' THEN amount ELSE 0 END) as check_total,
                SUM(CASE WHEN payment_method = 'mobile_money' THEN amount ELSE 0 END) as mobile_total,
                SUM(CASE WHEN DATE(payment_date) = CURDATE() THEN amount ELSE 0 END) as today_total,
                COUNT(CASE WHEN DATE(payment_date) = CURDATE() THEN 1 END) as today_count,
                SUM(CASE WHEN customer_id IS NOT NULL THEN amount ELSE 0 END) as customer_payments_total,
                SUM(CASE WHEN supplier_name IS NOT NULL AND customer_id IS NULL THEN amount ELSE 0 END) as supplier_payments_total
            FROM payments
            $where
        ");
        $stmt->execute($params);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $categoryStats = $pdo->prepare("SELECT category, SUM(amount) as total, COUNT(*) as count FROM payments $where GROUP BY category ORDER BY total DESC LIMIT 10");
        $categoryStats->execute($params);
        $categoryStats = $categoryStats->fetchAll(PDO::FETCH_ASSOC);
        
        $monthlyStmt = $pdo->prepare("SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, SUM(amount) as total, COUNT(*) as count FROM payments $where GROUP BY DATE_FORMAT(payment_date, '%Y-%m') ORDER BY month DESC LIMIT 6");
        $monthlyStmt->execute($params);
        $monthly = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'stats' => $stats,
            'category_stats' => $categoryStats,
            'monthly' => $monthly
        ]);
        exit;
    }
    
    elseif ($action === 'generate_payment_number') {
        try {
            $payment_number = generatePaymentNumberForTenant($pdo, $user_tenant_id);
            echo json_encode(['success' => true, 'payment_number' => $payment_number]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Payment number lama sameyn karo: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'get_customers_by_tenant') {
        $stmt = $pdo->prepare("SELECT c.id, c.customer_name, c.phone, c.email, c.debt_amount FROM customers c WHERE c.is_active = 1 AND c.tenant_id = ? ORDER BY c.customer_name");
        $stmt->execute([$user_tenant_id]);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'customers' => $customers,
            'empty' => count($customers) === 0
        ]);
        exit;
    }
    
    elseif ($action === 'get_invoices_by_customer') {
        $customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        
        if (!$customer_id) {
            echo json_encode(['success' => false, 'invoices' => [], 'empty' => true]);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT i.id, i.invoice_number, i.total_amount, i.paid_amount, (i.total_amount - i.paid_amount) as due_amount, i.status FROM invoices i WHERE i.customer_id = ? AND i.tenant_id = ? AND i.status != 'paid' AND (i.total_amount - i.paid_amount) > 0 ORDER BY i.invoice_number DESC");
        $stmt->execute([$customer_id, $user_tenant_id]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'invoices' => $invoices,
            'empty' => count($invoices) === 0
        ]);
        exit;
    }
    
    elseif ($action === 'import_payments') {
        if (!isset($_FILES['excel_file'])) {
            echo json_encode(['success' => false, 'message' => 'Fayl lama dooran!']);
            exit;
        }
        
        $file = $_FILES['excel_file']['tmp_name'];
        $handle = fopen($file, "r");
        fgetcsv($handle);
        
        $imported = 0;
        $errors = [];
        $line = 1;
        
        try {
            $pdo->beginTransaction();
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $line++;
                $payment_number = trim($data[0] ?? '');
                $payment_date = trim($data[1] ?? date('Y-m-d'));
                $amount = (float)(str_replace(['$', ','], '', $data[2] ?? 0));
                $type = strtolower(trim($data[3] ?? 'customer'));
                $entity_name = trim($data[4] ?? '');
                $invoice_number = trim($data[5] ?? '');
                $method = strtolower(trim($data[6] ?? 'cash'));
                $reference = trim($data[7] ?? '');
                $category = trim($data[8] ?? '');
                
                if (empty($payment_number) || empty($entity_name)) continue;
                
                $stmt = $pdo->prepare("SELECT id FROM payments WHERE tenant_id = ? AND payment_number = ?");
                $stmt->execute([$user_tenant_id, $payment_number]);
                if ($stmt->fetch()) {
                    $errors[] = "Line $line: Payment #$payment_number already exists.";
                    continue;
                }
                
                $customer_id = null;
                $supplier_name = null;
                $invoice_id = null;
                
                if ($type === 'customer') {
                    $stmt = $pdo->prepare("SELECT id FROM customers WHERE tenant_id = ? AND LOWER(customer_name) = ?");
                    $stmt->execute([$user_tenant_id, strtolower($entity_name)]);
                    $customer_id = $stmt->fetchColumn();
                    if (!$customer_id) {
                        $stmt = $pdo->prepare("INSERT INTO customers (tenant_id, customer_name, is_active, created_at) VALUES (?, ?, 1, NOW())");
                        $stmt->execute([$user_tenant_id, $entity_name]);
                        $customer_id = $pdo->lastInsertId();
                    }
                    
                    if (!empty($invoice_number)) {
                        $stmt = $pdo->prepare("SELECT id FROM invoices WHERE tenant_id = ? AND invoice_number = ?");
                        $stmt->execute([$user_tenant_id, $invoice_number]);
                        $invoice_id = $stmt->fetchColumn();
                    }
                    
                    // NO DISCOUNT - just insert the amount
                    $stmt = $pdo->prepare("INSERT INTO payments (tenant_id, payment_number, customer_id, invoice_id, amount, payment_date, payment_method, category, reference_number, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
                    $stmt->execute([$user_tenant_id, $payment_number, $customer_id, $invoice_id, $amount, $payment_date, $method, $category, $reference, $user_id]);
                    $new_pay_id = $pdo->lastInsertId();
                    
                    // NO LOYALTY POINTS
                    
                    $stmt = $pdo->prepare("UPDATE customers SET debt_amount = debt_amount - ? WHERE id = ?");
                    $stmt->execute([$amount, $customer_id]);
                    
                    if ($invoice_id) {
                        $stmt = $pdo->prepare("UPDATE invoices SET paid_amount = paid_amount + ?, status = CASE WHEN (paid_amount + ?) >= total_amount THEN 'paid' ELSE 'partial' END WHERE id = ?");
                        $stmt->execute([$amount, $amount, $invoice_id]);
                    }
                } else {
                    $supplier_name = $entity_name;
                    $stmt = $pdo->prepare("INSERT INTO payments (tenant_id, payment_number, supplier_name, amount, payment_date, payment_method, category, reference_number, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
                    $stmt->execute([$user_tenant_id, $payment_number, $supplier_name, $amount, $payment_date, $method, $category, $reference, $user_id]);
                }
                
                $imported++;
            }
            
            $pdo->commit();
            $msg = "Import-ka waa lagu guulaystay! ($imported bixin).";
            if (count($errors) > 0) $msg .= "<br>Digniin: " . count($errors) . " saf ayaa laga booday.";
            echo json_encode(['success' => true, 'message' => $msg]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        fclose($handle);
        exit;
    }
    exit;
}

// Include header and display page
require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maareynta Bixinta - <?= htmlspecialchars($user_tenant['tenant_name'] ?? '') ?> | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f5f8; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: var(--curdun-dark); }
        .page-header { background: #fff; border-bottom: 1px solid var(--border); padding: 20px 25px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; border-radius: 8px; }
        .page-header h1 { color: var(--curdun-dark); font-size: 24px; font-weight: 700; margin: 0; }
        .page-header h1 i { color: var(--curdun-violet); margin-right: 10px; }
        .btn-primary-custom { background: var(--curdun-violet); color: white; border: none; padding: 10px 20px; border-radius: 20px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s ease; cursor: pointer; }
        .btn-primary-custom:hover { background: var(--curdun-violet-light); color: white; transform: translateY(-1px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: white; border: 1px solid var(--border); border-radius: 8px; padding: 15px; display: flex; justify-content: space-between; align-items: center; }
        .stat-card .stat-info h4 { font-size: 13px; color: var(--curdun-gray); margin: 0 0 5px 0; font-weight: 600; text-transform: uppercase; }
        .stat-card .stat-info .stat-number { font-size: 28px; font-weight: 700; color: var(--curdun-dark); }
        .stat-card .stat-icon { font-size: 32px; color: var(--curdun-violet-light); opacity: 0.6; }
        .filters-card { background: white; border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 25px; }
        .filter-form { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 150px; }
        .filter-group label { display: block; font-size: 13px; font-weight: 600; color: var(--curdun-dark); margin-bottom: 6px; }
        .filter-group input, .filter-group select { width: 100%; padding: 10px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        .filter-group input:focus, .filter-group select:focus { border-color: var(--curdun-violet); outline: none; }
        .btn-filter { background: white; color: var(--curdun-dark); border: 1px solid #ccc; padding: 10px 20px; border-radius: 20px; font-weight: 600; cursor: pointer; }
        .btn-filter:hover { background: #f4f5f8; }
        .btn-reset { background: white; color: var(--curdun-info); border: none; padding: 10px 20px; font-weight: 600; cursor: pointer; }
        .payments-table-container { background: white; border: 1px solid var(--border); border-radius: 8px; overflow-x: auto; width: 100%; }
        .payments-table { width: 100%; border-collapse: collapse; }
        .payments-table th, .payments-table td { padding: 12px 10px; text-align: left; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .payments-table th { background: #f9f9fb; font-weight: 600; color: var(--curdun-gray); font-size: 13px; white-space: nowrap; }
        .payments-table tr:hover { background: #f9f9fb; }
        .payment-method-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .method-cash { background: #EEFBF3; color: #0F7A3A; }
        .method-bank-transfer { background: #e3f2fd; color: #0077c5; }
        .method-check { background: #fff8e1; color: #f57f17; }
        .method-mobile-money { background: #f3e5f5; color: #7b1fa2; }
        .category-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; background: #f4f5f8; color: var(--curdun-dark); border: 1px solid var(--border); white-space: nowrap; }
        .action-buttons { display: flex; gap: 8px; white-space: nowrap; }
        .action-btn { background: none; border: none; cursor: pointer; font-size: 16px; padding: 5px; border-radius: 4px; transition: all 0.2s; }
        .btn-view { color: var(--curdun-info); }
        .btn-edit { color: var(--curdun-dark); }
        .btn-delete { color: var(--curdun-danger); }
        .btn-receipt { color: var(--curdun-success); text-decoration: none; display: inline-block; }
        .alert { padding: 15px 20px; border-radius: 4px; position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideIn 0.3s ease; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .alert-success { background: #EEFBF3; color: #0F7A3A; border-left: 4px solid #0F7A3A; }
        .alert-error { background: #fce8e6; color: #B42318; border-left: 4px solid #B42318; }
        .empty-state { text-align: center; padding: 50px; color: var(--curdun-gray); }
        .modal-header { background: #f4f5f8; border-bottom: 1px solid var(--border); }
        .loading-spinner { text-align: center; padding: 50px; }
        .loading-spinner i { font-size: 48px; color: var(--curdun-violet); animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 25px; flex-wrap: wrap; }
        .pagination a, .pagination span { padding: 8px 12px; border-radius: 4px; text-decoration: none; color: var(--curdun-dark); background: white; border: 1px solid #ccc; cursor: pointer; font-size: 14px; }
        .pagination .active { background: var(--curdun-info); color: white; border-color: var(--curdun-info); }
        .chart-container { background: white; border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 25px; }
        .chart-title { font-size: 16px; font-weight: 600; color: var(--curdun-dark); margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid var(--border); }
        .auto-number-badge { background: #EEFBF3; color: #0F7A3A; padding: 8px 15px; border-radius: 20px; font-size: 14px; display: inline-block; margin-bottom: 15px; }
        .payment-type-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid var(--border); }
        .payment-type-tab { padding: 10px 20px; cursor: pointer; color: var(--curdun-gray); font-weight: 600; transition: all 0.2s; border-bottom: 3px solid transparent; }
        .payment-type-tab:hover { color: var(--curdun-violet); }
        .payment-type-tab.active { color: var(--curdun-violet); border-bottom-color: var(--curdun-violet); }
        .tenant-badge { display: inline-flex; align-items: center; background: #e0e1e6; color: var(--curdun-dark); padding: 5px 12px; border-radius: 20px; font-size: 14px; font-weight: 600; gap: 6px; }
        .invoice-link { color: var(--curdun-info); text-decoration: none; font-size: 13px; }
        code { background: #f4f5f8; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
        
        @media (max-width: 768px) { 
            .page-header { flex-direction: column; text-align: left; align-items: flex-start; } 
            .filter-form { flex-direction: column; } 
            .filter-group { width: 100%; } 
            .stats-grid { grid-template-columns: 1fr 1fr; } 
        }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-money-bill-wave"></i> Maareynta Bixinta</h1>
        <div class="d-flex align-items-center">
            <span class="tenant-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($user_tenant['tenant_name']) ?></span>
            <button type="button" class="btn-primary-custom ml-2" id="addPaymentBtn"><i class="fas fa-plus-circle"></i> Bixin Cusub</button>
            <div class="dropdown ml-2">
                <button class="btn btn-light dropdown-toggle" type="button" data-toggle="dropdown" style="border-radius: 20px; padding: 10px 15px; font-weight: 600; border: 1px solid #babec5;">
                    <i class="fas fa-file-csv"></i> CSV
                </button>
                <div class="dropdown-menu dropdown-menu-right" style="border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.1);">
                    <a class="dropdown-item" href="#" id="exportPaymentsBtn"><i class="fas fa-download mr-2"></i> Export Payments</a>
                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#importModal"><i class="fas fa-upload mr-2"></i> Import Payments</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="?action=download_sample"><i class="fas fa-file-download mr-2"></i> Download Sample</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid" id="stats-container">
        <div class="stat-card"><div class="stat-info"><h4>Tirada Bixinta</h4><div class="stat-number" id="stat-total">0</div></div><div class="stat-icon"><i class="fas fa-receipt"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Wadarta Lacagta</h4><div class="stat-number" id="stat-total-amount">$0</div></div><div class="stat-icon"><i class="fas fa-dollar-sign"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Bixinta Macaamiisha</h4><div class="stat-number" id="stat-customer-payments">$0</div></div><div class="stat-icon"><i class="fas fa-users"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Bixinta Alaab-qeybiyeyaasha</h4><div class="stat-number" id="stat-supplier-payments">$0</div></div><div class="stat-icon"><i class="fas fa-truck"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Bixinta Maanta</h4><div class="stat-number" id="stat-today">$0</div></div><div class="stat-icon"><i class="fas fa-calendar-day"></i></div></div>
    </div>

    <!-- Charts -->
    <div class="chart-container">
        <div class="chart-title"><i class="fas fa-chart-bar"></i> Bixinta Bil kasta</div>
        <canvas id="monthlyChart" height="200"></canvas>
    </div>
    
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="chart-container">
                <div class="chart-title"><i class="fas fa-chart-pie"></i> Qaybinta Noocyada</div>
                <canvas id="categoryChart" height="200"></canvas>
            </div>
        </div>
        <div class="col-md-6">
            <div class="chart-container">
                <div class="chart-title"><i class="fas fa-chart-pie"></i> Qaybinta Hababka Bixinta</div>
                <canvas id="methodChart" height="200"></canvas>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Raadin</label>
                <input type="text" id="searchInput" placeholder="Lambarka, Macaamilka...">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-tag"></i> Nooca</label>
                <select id="categoryFilter">
                    <option value="all">Dhammaan</option>
                    <option value="Rental">Rental</option>
                    <option value="Fuel">Fuel</option>
                    <option value="Maintenance">Maintenance</option>
                    <option value="Salary">Salary</option>
                    <option value="Supplier">Supplier</option>
                    <option value="Customer Payment">Customer Payment</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-credit-card"></i> Habka Bixinta</label>
                <select id="methodFilter">
                    <option value="all">Dhammaan</option>
                    <option value="cash">Cash</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="check">Check</option>
                    <option value="mobile_money">Mobile Money</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-calendar"></i> Laga bilaabo</label>
                <input type="date" id="dateFrom">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-calendar"></i> Ila</label>
                <input type="date" id="dateTo">
            </div>
            <div class="filter-group">
                <button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Shaandheey</button>
                <button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Nadiifi</button>
            </div>
        </div>
    </div>

    <!-- Payments Table -->
    <div id="payments-table-container">
        <div class="loading-spinner">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading payments...</p>
        </div>
    </div>
    <div id="pagination-container"></div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 8px;">
            <div class="modal-header" style="background: var(--curdun-violet); color: white;">
                <h5 class="modal-title" style="color: white;"><i class="fas fa-file-import"></i> Soo geli Bixin (CSV)</h5>
                <button type="button" class="close" data-dismiss="modal" style="color: white;">&times;</button>
            </div>
            <form id="importForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="info-box" style="background: #e3f2fd; padding: 15px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid #0077c5;">
                        <i class="fas fa-info-circle"></i> Fadlan soo geli faylka CSV oo kaliya. 
                        <a href="?action=download_sample" class="alert-link">Halkan ka soo deji sample-ka</a>.
                    </div>
                    <div class="form-group">
                        <label>Dooro Faylka (CSV)</label>
                        <input type="file" name="excel_file" class="form-control" accept=".csv" required>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> CSV-ku waa inuu ka kooban yahay tiirarkan: Payment Number, Payment Date, Amount, Type (customer/supplier), Customer/Supplier Name, Invoice Number, Method, Reference, Category
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button>
                    <button type="submit" class="btn" style="background: var(--curdun-violet); color: white;">Soo geli (Import)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--curdun-violet); color: white;">
                <h5 class="modal-title" id="paymentModalLabel" style="color: white;"><i class="fas fa-money-bill-wave"></i> Bixin Cusub</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form id="paymentForm">
                <div class="modal-body">
                    <input type="hidden" name="payment_id" id="payment_id">
                    
                    <div class="auto-number-badge">
                        <i class="fas fa-magic"></i> Lambarka Bixinta: <strong id="autoPaymentNumber">-</strong>
                        <input type="hidden" name="payment_number" id="modalPaymentNumber">
                    </div>
                    
                    <div class="payment-type-tabs">
                        <div class="payment-type-tab active" data-type="customer">
                            <i class="fas fa-user"></i> Bixinta Macaamilka
                        </div>
                        <div class="payment-type-tab" data-type="supplier">
                            <i class="fas fa-truck"></i> Bixinta Alaab-qeybiyaha
                        </div>
                    </div>
                    <input type="hidden" name="payment_type" id="paymentType" value="customer">
                    
                    <div id="customerPaymentSection">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Macaamilka <span class="text-danger">*</span></label>
                                    <select name="customer_id" id="modalCustomerId" class="form-control">
                                        <option value="">-- Dooro Macaamilka --</option>
                                    </select>
                                    <div id="customerLoading" class="text-muted small mt-1" style="display: none;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Biilka (Ikhtiyaari)</label>
                                    <select name="invoice_id" id="modalInvoiceId" class="form-control">
                                        <option value="">-- Dooro Biil --</option>
                                    </select>
                                    <div id="invoiceLoading" class="text-muted small mt-1" style="display: none;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div id="supplierPaymentSection" style="display: none;">
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label>Alaab-qeybiyaha <span class="text-danger">*</span></label>
                                    <input type="text" name="supplier_name" id="modalSupplierName" class="form-control" placeholder="Magaca alaab-qeybiyaha">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Nooca Bixinta</label>
                                <select name="category" id="modalCategory" class="form-control">
                                    <option value="">Dooro Nooca...</option>
                                    <option value="Rental">Rental</option>
                                    <option value="Fuel">Fuel</option>
                                    <option value="Maintenance">Maintenance</option>
                                    <option value="Salary">Salary</option>
                                    <option value="Supplier">Supplier</option>
                                    <option value="Customer Payment">Customer Payment</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Taariikhda</label>
                                <input type="date" name="payment_date" id="modalPaymentDate" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Qadarka <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" name="amount" id="modalAmount" class="form-control" placeholder="0.00" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Habka Bixinta</label>
                                <select name="payment_method" id="modalPaymentMethod" class="form-control" required>
                                    <option value="cash">Cash</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="check">Check</option>
                                    <option value="mobile_money">Mobile Money</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6" id="bankAccountField" style="display: none;">
                            <div class="form-group">
                                <label>Xisaabta Bangiga</label>
                                <select name="bank_account_id" id="modalBankAccountId" class="form-control">
                                    <option value="">Dooro Xisaabta...</option>
                                    <?php foreach ($bank_accounts as $ba): ?>
                                    <option value="<?= $ba['id'] ?>"><?= htmlspecialchars($ba['account_name']) ?> - <?= htmlspecialchars($ba['bank_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-12" id="referenceField">
                            <div class="form-group">
                                <label id="referenceLabel">Lambarka Tixraaca <span class="text-danger">*</span></label>
                                <input type="text" name="reference_number" id="modalReferenceNumber" class="form-control" placeholder="Transaction ID, Check No.">
                                <small class="form-text text-muted"><i class="fas fa-info-circle"></i> Fadlan geli lambarka tixraaca</small>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Qoraal</label>
                                <textarea name="notes" id="modalNotes" class="form-control" rows="2" placeholder="Faahfaahin..."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div id="invoiceInfo" style="display: none;" class="alert alert-info">
                        <i class="fas fa-info-circle"></i> <strong>Biilka:</strong> <span id="invoiceInfoText"></span><br>
                        <strong>Deynta Biilka:</strong> <span id="invoiceDueAmount" class="text-danger">$0.00</span>
                        <hr>
                        <i class="fas fa-magic"></i> Qadarka bixinta ayaa si otomatik ah loo qaaday
                    </div>
                    <div id="customerDebtInfo" style="display: none;" class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Deynta Macaamilka:</strong> <span id="customerDebtAmount">$0.00</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Kaydi Bixinta</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--curdun-violet); color: white;">
                <h5 class="modal-title" style="color: white;"><i class="fas fa-money-bill-wave"></i> Faahfaahinta Bixinta</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Xidh</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Tirtir Bixinta</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                Ma hubtaa inaad tirtirto bixinta <strong id="deletePaymentName"></strong>?<br><br>
                <span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Digniin: Tirtirista waa joogto!</span>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Tirtir</button>
            </div>
        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div class="modal fade" id="receiptModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background: #f8f9fa; border-bottom: 2px solid #2D1859;">
                <h5 class="modal-title" id="receiptModalLabel">
                    <i class="fas fa-receipt" style="color: #2D1859;"></i> Rasiidka Bixinta
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="receiptModalBody" style="max-height: 70vh; overflow-y: auto;">
                <div class="text-center p-5">
                    <i class="fas fa-spinner fa-spin fa-3x" style="color: #2D1859;"></i>
                    <p class="mt-3">Loading receipt...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="fas fa-times"></i> Xidh
                </button>
                <button type="button" class="btn btn-primary-custom" id="printReceiptBtn">
                    <i class="fas fa-print"></i> Daabac
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
$(document).ready(function() {
    let currentPage = 1;
    let deleteId = null;
    let monthlyChart, categoryChart, methodChart;
    let currentReceiptPaymentId = null;

    window.openReceiptPopup = function(paymentId) {
        if (!paymentId) {
            showAlert('error', 'Payment ID is required');
            return;
        }
        currentReceiptPaymentId = paymentId;
        $('#receiptModalBody').html('<div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-3x"></i><p class="mt-3">Loading receipt...</p></div>');
        $.ajax({
            url: 'receipts.php',
            type: 'GET',
            data: { id: paymentId, modal: 1, t: Date.now() },
            dataType: 'html',
            success: function(html) {
                $('#receiptModalBody').html(html);
                $('#receiptModal').modal('show');
            },
            error: function() {
                $('#receiptModalBody').html('<div class="alert alert-danger text-center"><i class="fas fa-exclamation-triangle fa-2x"></i><h5 class="mt-3">Khalad ayaa dhacay</h5><p>Lamaan soo dejinta rasiidka.</p></div>');
            }
        });
    };
    
    $('#printReceiptBtn').click(function() {
        var printContents = $('#receiptModalBody').html();
        var printWindow = window.open('', '_blank', 'width=800,height=600,toolbar=yes,scrollbars=yes');
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Rasiidka Bixinta</title>
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
                <style>
                    * { margin: 0; padding: 0; }
                    body { font-family: 'Segoe UI', sans-serif; padding: 20px; background: white; }
                    @media print { body { padding: 0; } button, .btn, .no-print { display: none !important; } }
                    .receipt-header { background: linear-gradient(135deg, #2D1859 0%, #4B2C85 100%); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                    .amount-number { font-size: 36px; font-weight: bold; color: #0F7A3A; }
                    .info-row { display: flex; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #f0f0f0; }
                    .info-label { width: 35%; font-weight: 600; color: #6c757d; }
                    .info-value { width: 65%; color: #2c3e50; }
                </style>
            </head>
            <body>${printContents}<script>window.onload = function() { setTimeout(function() { window.print(); window.close(); }, 500); }<\/script></body>
            </html>
        `);
        printWindow.document.close();
    });

    function togglePaymentType(type) {
        if (type === 'customer') {
            $('#customerPaymentSection').show();
            $('#supplierPaymentSection').hide();
            $('#modalCustomerId').prop('required', true);
            $('#modalSupplierName').prop('required', false);
            $('#paymentType').val('customer');
            $('#modalCategory option[value="Customer Payment"]').prop('selected', true);
        } else {
            $('#customerPaymentSection').hide();
            $('#supplierPaymentSection').show();
            $('#modalCustomerId').prop('required', false);
            $('#modalSupplierName').prop('required', true);
            $('#paymentType').val('supplier');
            $('#invoiceInfo').hide();
            $('#customerDebtInfo').hide();
            $('#modalAmount').val('');
            $('#modalCategory option[value="Supplier"]').prop('selected', true);
        }
    }
    
    $('.payment-type-tab').click(function() {
        $('.payment-type-tab').removeClass('active');
        $(this).addClass('active');
        togglePaymentType($(this).data('type'));
    });
    
    function toggleReferenceField() {
        const method = $('#modalPaymentMethod').val();
        if (method === 'cash') {
            $('#referenceField').hide();
            $('#modalReferenceNumber').prop('required', false);
            $('#bankAccountField').hide();
            $('#modalBankAccountId').prop('required', false);
        } else if (method === 'bank_transfer') {
            $('#referenceField').show();
            $('#modalReferenceNumber').prop('required', true);
            $('#bankAccountField').show();
            $('#modalBankAccountId').prop('required', true);
        } else {
            $('#referenceField').show();
            $('#modalReferenceNumber').prop('required', true);
            $('#bankAccountField').hide();
            $('#modalBankAccountId').prop('required', false);
        }
    }

    function generatePaymentNumber() {
        return new Promise(function(resolve, reject) {
            $('#autoPaymentNumber').html('<i class="fas fa-spinner fa-spin"></i>');
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'generate_payment_number' },
                dataType: 'json',
                success: function(res) {
                    if (res.success && res.payment_number) {
                        $('#autoPaymentNumber').text(res.payment_number);
                        $('#modalPaymentNumber').val(res.payment_number);
                        resolve(res.payment_number);
                    } else {
                        $('#autoPaymentNumber').text('Error');
                        reject(res.message || 'Error');
                    }
                },
                error: function() {
                    $('#autoPaymentNumber').text('Error');
                    reject('Server error');
                }
            });
        });
    }
    
    function loadCustomers(selectedCustomerId = null, done = null) {
        const select = $('#modalCustomerId');
        $('#customerLoading').show();
        select.prop('disabled', true).html('<option value="">Loading...</option>');
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_customers_by_tenant' },
            dataType: 'json',
            success: function(res) {
                select.empty();
                if (res.success && res.customers && res.customers.length > 0) {
                    select.append('<option value="">-- Dooro Macaamilka --</option>');
                    $.each(res.customers, function(i, c) {
                        select.append('<option value="' + c.id + '" data-debt="' + (c.debt_amount || 0) + '">' + escapeHtml(c.customer_name) + ' - Deynta: $' + parseFloat(c.debt_amount || 0).toFixed(2) + '</option>');
                    });
                    if (selectedCustomerId) select.val(String(selectedCustomerId));
                } else {
                    select.html('<option value="">-- Ma jiraan macaamiilo --</option>');
                }
                select.prop('disabled', false);
                $('#customerLoading').hide();
                if (typeof done === 'function') done(true);
            },
            error: function() {
                select.html('<option value="">Error</option>').prop('disabled', false);
                $('#customerLoading').hide();
                if (typeof done === 'function') done(false);
            }
        });
    }
    
    function loadInvoicesByCustomer(customerId, selectedInvoiceId = null, done = null) {
        const select = $('#modalInvoiceId');
        if (!customerId) {
            select.html('<option value="">-- Dooro Biil --</option>');
            $('#invoiceInfo').hide();
            if (typeof done === 'function') done(false);
            return;
        }
        $('#invoiceLoading').show();
        select.prop('disabled', true).html('<option value="">Loading...</option>');
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_invoices_by_customer', customer_id: customerId },
            dataType: 'json',
            success: function(res) {
                select.empty();
                if (res.success && res.invoices && res.invoices.length > 0) {
                    select.append('<option value="">-- Dooro Biil --</option>');
                    $.each(res.invoices, function(i, inv) {
                        select.append('<option value="' + inv.id + '" data-due="' + inv.due_amount + '">' + escapeHtml(inv.invoice_number) + ' (Deyn: $' + parseFloat(inv.due_amount).toFixed(2) + ')</option>');
                    });
                } else {
                    select.html('<option value="">-- Ma jiraan biilalo --</option>');
                }
                if (selectedInvoiceId) select.val(String(selectedInvoiceId));
                select.prop('disabled', false);
                $('#invoiceLoading').hide();
                if (typeof done === 'function') done(true);
            },
            error: function() {
                select.html('<option value="">Error</option>').prop('disabled', false);
                $('#invoiceLoading').hide();
                if (typeof done === 'function') done(false);
            }
        });
    }

    $('#modalInvoiceId').on('change', function() {
        const invoiceId = $(this).val();
        if (invoiceId) {
            const dueAmount = $(this).find('option:selected').data('due');
            $('#invoiceInfoText').html($(this).find('option:selected').text());
            $('#invoiceDueAmount').text('$' + parseFloat(dueAmount).toFixed(2));
            $('#invoiceInfo').show();
            $('#modalAmount').val(dueAmount);
            $('#modalAmount').attr('max', dueAmount);
        } else {
            $('#invoiceInfo').hide();
            $('#modalAmount').val('');
            $('#modalAmount').removeAttr('max');
        }
    });
    
    $('#modalCustomerId').on('change', function() {
        const customerId = $(this).val();
        if (customerId) {
            loadInvoicesByCustomer(customerId);
            const debtAmount = $(this).find('option:selected').data('debt');
            if (debtAmount > 0) {
                $('#customerDebtAmount').text('$' + parseFloat(debtAmount).toFixed(2));
                $('#customerDebtInfo').show();
            } else {
                $('#customerDebtInfo').hide();
            }
        } else {
            $('#modalInvoiceId').html('<option value="">-- Dooro Biil --</option>');
            $('#invoiceInfo').hide();
            $('#customerDebtInfo').hide();
        }
    });

    loadCustomers();

    function loadPayments() {
        $('#payments-table-container').html('<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i><p>Loading payments...</p></div>');
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'get_payments',
                page: currentPage,
                search: $('#searchInput').val(),
                category: $('#categoryFilter').val(),
                payment_method: $('#methodFilter').val(),
                date_from: $('#dateFrom').val(),
                date_to: $('#dateTo').val()
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#payments-table-container').html(response.table_html);
                    $('#pagination-container').html(response.pagination_html);
                    attachTableEvents();
                    $('#exportPaymentsBtn').attr('href', `?action=export_payments&search=${encodeURIComponent($('#searchInput').val())}`);
                } else {
                    $('#payments-table-container').html('<div class="alert alert-error">Error loading payments</div>');
                }
            },
            error: function() {
                $('#payments-table-container').html('<div class="alert alert-error">Error loading payments</div>');
            }
        });
    }

    $('#importForm').submit(function(e) {
        e.preventDefault();
        let formData = new FormData(this);
        formData.append('ajax_action', 'import_payments');
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#importModal').modal('hide');
                    loadPayments();
                    loadStats();
                    showAlert('success', res.message);
                    $('#importForm')[0].reset();
                } else {
                    showAlert('error', res.message);
                }
            },
            error: function() { showAlert('error', 'Khalad ayaa dhacay'); }
        });
    });

    function loadStats() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_stats' },
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    const stats = data.stats;
                    $('#stat-total').text(stats.total_payments || 0);
                    $('#stat-total-amount').text('$' + (parseFloat(stats.total_amount || 0).toFixed(2)));
                    $('#stat-customer-payments').text('$' + (parseFloat(stats.customer_payments_total || 0).toFixed(2)));
                    $('#stat-supplier-payments').text('$' + (parseFloat(stats.supplier_payments_total || 0).toFixed(2)));
                    $('#stat-today').text('$' + (parseFloat(stats.today_total || 0).toFixed(2)));
                    $('#stat-cash').text('$' + (parseFloat(stats.cash_total || 0).toFixed(2)));
                    
                    const monthly = data.monthly;
                    if (monthlyChart) monthlyChart.destroy();
                    monthlyChart = new Chart(document.getElementById('monthlyChart'), {
                        type: 'bar',
                        data: { labels: monthly.map(m => m.month), datasets: [{ label: 'Bixinta ($)', data: monthly.map(m => parseFloat(m.total)), backgroundColor: '#2D1859' }] },
                        options: { responsive: true, scales: { y: { beginAtZero: true } } }
                    });
                    
                    const categoryStats = data.category_stats;
                    if (categoryChart) categoryChart.destroy();
                    categoryChart = new Chart(document.getElementById('categoryChart'), {
                        type: 'pie',
                        data: { labels: categoryStats.map(c => c.category || 'Other'), datasets: [{ data: categoryStats.map(c => parseFloat(c.total)), backgroundColor: ['#1565c0', '#e65100', '#2e7d32', '#7b1fa2', '#c62828', '#f4b400', '#6c757d'] }] },
                        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
                    });
                    
                    if (methodChart) methodChart.destroy();
                    methodChart = new Chart(document.getElementById('methodChart'), {
                        type: 'pie',
                        data: { labels: ['Cash', 'Bank Transfer', 'Check', 'Mobile Money'], datasets: [{ data: [parseFloat(stats.cash_total || 0), parseFloat(stats.bank_total || 0), parseFloat(stats.check_total || 0), parseFloat(stats.mobile_total || 0)], backgroundColor: ['#2e7d32', '#1565c0', '#e65100', '#7b1fa2'] }] },
                        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
                    });
                }
            }
        });
    }

    function attachTableEvents() {
        $('.view-payment').off('click').on('click', function() {
            const id = $(this).data('id');
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_payment', id: id },
                dataType: 'json',
                success: function(res) {
                    if (res.success && res.data) {
                        const p = res.data;
                        $('#viewModalBody').html(`
                            <div class="row">
                                <div class="col-12 mb-3"><div class="alert alert-info"><strong><i class="fas fa-receipt"></i> Lambarka Bixinta:</strong> ${escapeHtml(p.payment_number)}</div></div>
                                <div class="col-6"><strong>Taariikhda:</strong></div><div class="col-6">${p.payment_date}</div>
                                <div class="col-6"><strong>Macaamilka:</strong></div><div class="col-6">${escapeHtml(p.customer_name || p.supplier_name || '-')}</div>
                                <div class="col-6"><strong>Qadarka:</strong></div><div class="col-6"><strong class="text-danger">$${parseFloat(p.amount).toFixed(2)}</strong></div>
                                <div class="col-6"><strong>Habka Bixinta:</strong></div><div class="col-6">${p.payment_method}</div>
                                <div class="col-6"><strong>Nooca:</strong></div><div class="col-6">${escapeHtml(p.category || '-')}</div>
                                <div class="col-12 mt-3"><strong>Qoraal:</strong></div><div class="col-12"><div class="alert alert-info mt-2">${escapeHtml(p.notes || '-')}</div></div>
                            </div>
                        `);
                        $('#viewModal').modal('show');
                    } else {
                        showAlert('error', 'Error loading payment details');
                    }
                }
            });
        });
        
        $('.edit-payment').off('click').on('click', function() {
            const id = $(this).data('id');
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_payment', id: id },
                dataType: 'json',
                success: function(res) {
                    if (res.success && res.data) {
                        const p = res.data;
                        $('#paymentModalLabel').text('Wax Ka Beddel Bixinta');
                        $('#paymentForm')[0].reset();
                        $('#payment_id').val(p.id);
                        $('#modalCategory').val(p.category);
                        $('#modalPaymentDate').val(p.payment_date);
                        $('#modalAmount').val(p.amount);
                        $('#modalPaymentMethod').val(p.payment_method);
                        $('#modalReferenceNumber').val(p.reference_number || '');
                        $('#modalBankAccountId').val(p.bank_account_id || '');
                        $('#modalNotes').val(p.notes || '');
                        $('#autoPaymentNumber').text(p.payment_number);
                        $('#modalPaymentNumber').val(p.payment_number);
                        if (p.customer_id) {
                            togglePaymentType('customer');
                            loadCustomers(p.customer_id, function() {
                                $('#modalCustomerId').val(String(p.customer_id)).trigger('change');
                                loadInvoicesByCustomer(p.customer_id, p.invoice_id, function() {
                                    if (p.invoice_id) $('#modalInvoiceId').val(String(p.invoice_id));
                                    $('#modalAmount').val(p.amount);
                                });
                            });
                        } else {
                            togglePaymentType('supplier');
                            $('#modalSupplierName').val(p.supplier_name || '');
                        }
                        toggleReferenceField();
                        $('#paymentModal').modal('show');
                    } else {
                        showAlert('error', 'Error loading payment');
                    }
                }
            });
        });
        
        $('.delete-payment').off('click').on('click', function() {
            deleteId = $(this).data('id');
            $('#deletePaymentName').text($(this).data('name'));
            $('#deleteModal').modal('show');
        });
        
        $('.pagination a').off('click').on('click', function(e) {
            e.preventDefault();
            const page = $(this).data('page');
            if (page) { currentPage = page; loadPayments(); }
        });
    }

    function showAlert(type, msg) {
        $('#alert-placeholder').html(`<div class="alert alert-${type} alert-dismissible fade show"><i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
        setTimeout(() => { $('.alert').fadeOut(3000, function() { $(this).remove(); }); }, 5000);
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        return String(text).replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }

    $('#paymentForm').submit(async function(e) {
        e.preventDefault();
        const amount = parseFloat($('#modalAmount').val());
        if (isNaN(amount) || amount <= 0) { showAlert('error', 'Fadlan geli qadarka bixinta'); return; }
        
        const paymentType = $('#paymentType').val();
        let customerId = null, supplierName = null;
        
        if (paymentType === 'customer') {
            customerId = $('#modalCustomerId').val();
            if (!customerId) { showAlert('error', 'Fadlan dooro macaamilka'); return; }
            const invoiceId = $('#modalInvoiceId').val();
            if (invoiceId) {
                const dueAmount = parseFloat($('#modalInvoiceId').find('option:selected').data('due') || 0);
                if (dueAmount > 0 && amount > dueAmount) { showAlert('error', 'Qadarka bixinta wuu ka badan yahay deynta biilka'); return; }
            }
        } else {
            supplierName = $('#modalSupplierName').val().trim();
            if (!supplierName) { showAlert('error', 'Fadlan geli magaca alaab-qeybiyaha'); return; }
        }
        
        const method = $('#modalPaymentMethod').val();
        if (method !== 'cash' && $('#modalReferenceNumber').val().trim() === '') { showAlert('error', 'Lambarka tixraaca waa lagama maarmaan'); return; }
        if (method === 'bank_transfer' && !$('#modalBankAccountId').val()) { showAlert('error', 'Fadlan dooro xisaabta bangiga'); return; }
        
        const submitBtn = $(this).find('button[type="submit"]');
        const originalText = submitBtn.html();
        submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Saving...').prop('disabled', true);
        
        try { if (!$('#modalPaymentNumber').val()) await generatePaymentNumber(); } 
        catch (err) { showAlert('error', err); submitBtn.html(originalText).prop('disabled', false); return; }

        let data = {
            ajax_action: 'save_payment',
            payment_id: $('#payment_id').val(),
            payment_number: $('#modalPaymentNumber').val(),
            payment_type: paymentType,
            amount: amount,
            payment_date: $('#modalPaymentDate').val(),
            payment_method: method,
            category: $('#modalCategory').val(),
            reference_number: $('#modalReferenceNumber').val(),
            bank_account_id: $('#modalBankAccountId').val(),
            notes: $('#modalNotes').val()
        };
        if (paymentType === 'customer') { data.customer_id = customerId; data.invoice_id = $('#modalInvoiceId').val(); } 
        else { data.supplier_name = supplierName; }
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#paymentModal').modal('hide');
                    loadPayments();
                    loadStats();
                    showAlert('success', res.message);
                    $('#paymentForm')[0].reset();
                    $('#invoiceInfo, #customerDebtInfo').hide();
                    generatePaymentNumber();
                    togglePaymentType('customer');
                    loadCustomers();
                    if (res.payment_id) setTimeout(() => openReceiptPopup(res.payment_id), 500);
                } else { showAlert('error', res.message); }
                submitBtn.html(originalText).prop('disabled', false);
            },
            error: function(xhr) {
                if (xhr.status === 200) {
                    showAlert('success', 'Bixinta waa la kaydiyay!');
                    $('#paymentModal').modal('hide');
                    loadPayments();
                    loadStats();
                } else { showAlert('error', 'Khalad ayaa dhacay'); }
                submitBtn.html(originalText).prop('disabled', false);
            }
        });
    });

    $('#confirmDeleteBtn').click(function() {
        if (deleteId) {
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'delete_payment', id: deleteId },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        $('#deleteModal').modal('hide');
                        loadPayments();
                        loadStats();
                        showAlert('success', res.message);
                    } else { showAlert('error', res.message); }
                    deleteId = null;
                },
                error: function() { showAlert('error', 'Error deleting payment'); deleteId = null; }
            });
        }
    });

    $('#addPaymentBtn, #addPaymentBtnEmpty').click(function() {
        $('#paymentModalLabel').text('Bixin Cusub');
        $('#paymentForm')[0].reset();
        $('#payment_id').val('');
        $('#modalPaymentDate').val(new Date().toISOString().split('T')[0]);
        $('#invoiceInfo, #customerDebtInfo').hide();
        $('#modalAmount').removeAttr('max');
        togglePaymentType('customer');
        generatePaymentNumber();
        loadCustomers();
        toggleReferenceField();
        $('#paymentModal').modal('show');
    });

    $('#modalPaymentMethod').on('change', toggleReferenceField);
    $('#applyFilters').click(function() { currentPage = 1; loadPayments(); loadStats(); });
    $('#resetFilters').click(function() {
        $('#searchInput, #dateFrom, #dateTo').val('');
        $('#categoryFilter, #methodFilter').val('all');
        currentPage = 1;
        loadPayments();
        loadStats();
    });
    $('#searchInput').keypress(function(e) { if (e.which === 13) { currentPage = 1; loadPayments(); } });

    toggleReferenceField();
    togglePaymentType('customer');
    loadPayments();
    loadStats();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>