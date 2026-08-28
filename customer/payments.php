<?php
// customer/payments.php
// Payment Management forfaras cargo - Customer/Tenant Admin View
// Complete Refactored Version - All-in-One File

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../includes/audit_helper.php';

// Check if AccountingService exists
if (file_exists(__DIR__ . '/../includes/AccountingService.php')) {
    require_once __DIR__ . '/../includes/AccountingService.php';
}

// Get user's tenant/customer information
$user_tenant = null;
$tenant_name = '';

try {
    if ($role === 'customer' && $customer_id) {
        $stmt = $pdo->prepare("
            SELECT c.id, c.customer_name, c.phone, c.email, c.debt_amount, c.loyalty_points,
                   t.id as tenant_id, t.name as tenant_name
            FROM customers c
            LEFT JOIN tenants t ON c.tenant_id = t.id
            WHERE c.id = ?
        ");
        $stmt->execute([$customer_id]);
        $user_tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user_tenant) {
            $session_tenant_id = $user_tenant['tenant_id'];
            $tenant_name = $user_tenant['tenant_name'] ?? 'My Company';
        }
    } else {
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
        $tenant_name = $user_tenant['tenant_name'] ?? 'My Company';
    }
} catch (PDOException $e) {
    $user_tenant = null;
    $tenant_name = 'My Company';
}

// Get bank accounts for this tenant
$bank_accounts = [];
if ($role !== 'customer') {
    try {
        $stmt = $pdo->prepare("
            SELECT id, account_name, bank_name, account_number, currency, current_balance, tenant_id
            FROM bank_accounts
            WHERE is_active = 1 AND tenant_id = ?
            ORDER BY account_name
        ");
        $stmt->execute([$session_tenant_id]);
        $bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $bank_accounts = [];
    }
}

// ============================================
// AJAX HANDLERS
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    ob_clean();
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];
    
    try {
        switch ($action) {
            case 'get_payments':
                handleGetPayments($pdo, $role, $session_tenant_id, $customer_id);
                break;
            case 'get_payment':
                handleGetPayment($pdo, $role, $session_tenant_id, $customer_id);
                break;
            case 'save_payment':
                if ($role === 'customer') {
                    echo json_encode(['success' => false, 'message' => 'Payments are read-only in the customer portal.']);
                    break;
                }
                handleSavePayment($pdo, $role, $session_tenant_id, $customer_id, $user_id);
                break;
            case 'delete_payment':
                if ($role === 'customer') {
                    echo json_encode(['success' => false, 'message' => 'Customers cannot delete payments.']);
                    break;
                }
                handleDeletePayment($pdo, $role, $session_tenant_id, $customer_id);
                break;
            case 'get_stats':
                handleGetStats($pdo, $role, $session_tenant_id, $customer_id);
                break;
            case 'generate_payment_number':
                if ($role === 'customer') {
                    echo json_encode(['success' => false, 'message' => 'Customers cannot generate payment numbers.']);
                    break;
                }
                handleGeneratePaymentNumber($pdo, $session_tenant_id);
                break;
            case 'get_customers_by_tenant':
                if ($role === 'customer') {
                    echo json_encode(['success' => false, 'message' => 'Customers cannot list tenant customers.']);
                    break;
                }
                handleGetCustomersByTenant($pdo, $role, $session_tenant_id, $customer_id);
                break;
            case 'get_invoices_by_customer':
                if ($role === 'customer') {
                    $_POST['customer_id'] = (string)$customer_id;
                }
                handleGetInvoicesByCustomer($pdo, $session_tenant_id);
                break;
            case 'get_my_payments':
                handleGetMyPayments($pdo, $customer_id, $session_tenant_id);
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        error_log("Payment handler error: " . $e->getMessage());
    }
    exit;
}

// ============================================
// AJAX HANDLER FUNCTIONS
// ============================================

function handleGetPayments($pdo, $role, $session_tenant_id, $customer_id) {
    $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
    $limit = 15;
    $offset = ($page - 1) * $limit;
    
    $search = $_POST['search'] ?? '';
    $category_filter = $_POST['category'] ?? 'all';
    $payment_method_filter = $_POST['payment_method'] ?? 'all';
    $date_from = $_POST['date_from'] ?? '';
    $date_to = $_POST['date_to'] ?? '';
    
    if ($role === 'customer' && $customer_id) {
        $where_conditions = ["p.customer_id = ?", "p.tenant_id = ?"];
        $params = [$customer_id, $session_tenant_id];
    } else {
        $where_conditions = ["p.tenant_id = ?"];
        $params = [$session_tenant_id];
    }
    
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
    
    ob_start();
    renderPaymentsTable($payments, $role);
    $table_html = ob_get_clean();
    
    ob_start();
    renderPagination($page, $total_pages);
    $pagination_html = ob_get_clean();
    
    echo json_encode([
        'success' => true,
        'table_html' => $table_html,
        'pagination_html' => $pagination_html
    ]);
}

function handleGetPayment($pdo, $role, $session_tenant_id, $customer_id) {
    $id = $_POST['id'] ?? 0;
    
    if ($role === 'customer' && $customer_id) {
        $stmt = $pdo->prepare("
            SELECT p.*, t.name as tenant_name, c.customer_name, i.invoice_number,
                   ba.account_name as bank_account_name, ba.bank_name, ba.account_number,
                   u.full_name as created_by_name
            FROM payments p
            LEFT JOIN tenants t ON p.tenant_id = t.id
            LEFT JOIN customers c ON p.customer_id = c.id
            LEFT JOIN invoices i ON p.invoice_id = i.id
            LEFT JOIN bank_accounts ba ON p.bank_account_id = ba.id
            LEFT JOIN users u ON p.created_by = u.id
            WHERE p.id = ? AND p.customer_id = ? AND p.tenant_id = ?
        ");
        $stmt->execute([$id, $customer_id, $session_tenant_id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT p.*, t.name as tenant_name, c.customer_name, i.invoice_number,
                   ba.account_name as bank_account_name, ba.bank_name, ba.account_number,
                   u.full_name as created_by_name
            FROM payments p
            LEFT JOIN tenants t ON p.tenant_id = t.id
            LEFT JOIN customers c ON p.customer_id = c.id
            LEFT JOIN invoices i ON p.invoice_id = i.id
            LEFT JOIN bank_accounts ba ON p.bank_account_id = ba.id
            LEFT JOIN users u ON p.created_by = u.id
            WHERE p.id = ? AND p.tenant_id = ?
        ");
        $stmt->execute([$id, $session_tenant_id]);
    }
    
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        echo json_encode(['success' => false, 'message' => 'Payment not found']);
        exit;
    }
    
    echo json_encode(['success' => true, 'data' => $payment]);
}

function handleSavePayment($pdo, $role, $session_tenant_id, $customer_id, $user_id) {
    $id = $_POST['payment_id'] ?? '';
    $tenant_id = $session_tenant_id;
    $payment_number = trim($_POST['payment_number'] ?? '');
    $payment_type = $_POST['payment_type'] ?? 'customer';
    
    // For customer role, force customer payment type and their own customer_id
    if ($role === 'customer' && $customer_id) {
        $payment_type = 'customer';
        $customer_id_param = $customer_id;
        $supplier_name = null;
    } else {
        $customer_id_param = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $supplier_name = trim($_POST['supplier_name'] ?? '');
    }
    
    $invoice_id = !empty($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null;
    $amount = (float)($_POST['amount'] ?? 0);
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $category = trim($_POST['category'] ?? '');
    $reference_number = trim($_POST['reference_number'] ?? '');
    $bank_account_id = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
    $notes = trim($_POST['notes'] ?? '');
    
    try {
        if (empty($payment_number)) {
            echo json_encode(['success' => false, 'message' => 'Payment number is required']);
            exit;
        }
        
        if ($amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Payment amount must be greater than 0']);
            exit;
        }
        
        if ($payment_type === 'customer') {
            if (empty($customer_id_param)) {
                echo json_encode(['success' => false, 'message' => 'Please select a customer']);
                exit;
            }
            
            // Verify customer belongs to this tenant
            $check = $pdo->prepare("SELECT id FROM customers WHERE id = ? AND tenant_id = ?");
            $check->execute([$customer_id_param, $tenant_id]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Customer not found or does not belong to your company']);
                exit;
            }
        } else {
            if (empty($supplier_name)) {
                echo json_encode(['success' => false, 'message' => 'Supplier name is required']);
                exit;
            }
        }
        
        if ($payment_method != 'cash' && empty($reference_number)) {
            $method_names = ['bank_transfer' => 'Bank Transfer', 'check' => 'Check', 'mobile_money' => 'Mobile Money'];
            echo json_encode(['success' => false, 'message' => 'Reference number is required for ' . ($method_names[$payment_method] ?? ucfirst(str_replace('_', ' ', $payment_method)))]);
            exit;
        }
        
        if ($payment_method === 'bank_transfer' && empty($bank_account_id) && $role !== 'customer') {
            echo json_encode(['success' => false, 'message' => 'Please select the bank account for payment']);
            exit;
        }
        
        if ($bank_account_id) {
            $check = $pdo->prepare("SELECT id FROM bank_accounts WHERE id = ? AND tenant_id = ?");
            $check->execute([$bank_account_id, $tenant_id]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Invalid bank account']);
                exit;
            }
        }
        
        $pdo->beginTransaction();
        
        if (empty($id)) {
            $check = $pdo->prepare("SELECT id FROM payments WHERE payment_number = ? AND tenant_id = ?");
            $check->execute([$payment_number, $tenant_id]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'message' => "Payment number '$payment_number' already exists"]);
                exit;
            }
            
            if ($payment_type === 'customer' && $invoice_id) {
                $invStmt = $pdo->prepare("SELECT total_amount, paid_amount FROM invoices WHERE id = ? AND tenant_id = ?");
                $invStmt->execute([$invoice_id, $tenant_id]);
                $invoice = $invStmt->fetch();
                
                if ($invoice) {
                    $invoice_total = $invoice['total_amount'];
                    $invoice_paid_before = $invoice['paid_amount'];
                    $invoice_due_before = $invoice_total - $invoice_paid_before;
                    
                    if ($amount > $invoice_due_before) {
                        echo json_encode(['success' => false, 'message' => "Payment amount ($$amount) exceeds invoice due amount ($$invoice_due_before)"]);
                        exit;
                    }
                    
                    $new_paid_amount = $invoice_paid_before + $amount;
                    $new_status = ($new_paid_amount >= $invoice_total) ? 'paid' : 'partial';
                    
                    $updateInv = $pdo->prepare("UPDATE invoices SET paid_amount = ?, status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
                    $updateInv->execute([$new_paid_amount, $new_status, $invoice_id, $tenant_id]);
                }
            }
            
            if ($payment_method === 'bank_transfer' && $bank_account_id && $role !== 'customer') {
                $stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$amount, $bank_account_id, $tenant_id]);
            }
            
            if ($payment_type === 'customer') {
                $sql = "INSERT INTO payments (tenant_id, payment_number, customer_id, invoice_id, amount, payment_date, payment_method, category, reference_number, bank_account_id, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$tenant_id, $payment_number, $customer_id_param, $invoice_id, $amount, $payment_date, $payment_method, $category, $reference_number, $bank_account_id, $notes, $user_id]);
            } else {
                $sql = "INSERT INTO payments (tenant_id, payment_number, supplier_name, amount, payment_date, payment_method, category, reference_number, bank_account_id, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$tenant_id, $payment_number, $supplier_name, $amount, $payment_date, $payment_method, $category, $reference_number, $bank_account_id, $notes, $user_id]);
            }
            
            $new_payment_id = $pdo->lastInsertId();
            
            if ($payment_type === 'customer' && $customer_id_param) {
                $updateDebt = $pdo->prepare("UPDATE customers SET debt_amount = debt_amount - ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
                $updateDebt->execute([$amount, $customer_id_param, $tenant_id]);
                
                $points_earned = floor($amount / 100) * 5;
                if ($points_earned > 0) {
                    try {
                        $loyaltyStmt = $pdo->prepare("INSERT INTO loyalty_points_log (tenant_id, customer_id, points_earned, amount_earned, reason, reference_type, reference_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, 'payment', ?, ?, NOW())");
                        $loyaltyStmt->execute([$tenant_id, $customer_id_param, $points_earned, $amount, "Payment received", $new_payment_id, $user_id]);
                        $updatePoints = $pdo->prepare("UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ? AND tenant_id = ?");
                        $updatePoints->execute([$points_earned, $customer_id_param, $tenant_id]);
                    } catch (PDOException $e) {
                        // Loyalty table might not exist
                    }
                }
            }
            
            if ($payment_type === 'customer') {
                $cashStmt = $pdo->prepare("INSERT INTO cash_flow (tenant_id, flow_date, inflow, description, created_at) VALUES (?, ?, ?, ?, NOW())");
                $cashStmt->execute([$tenant_id, $payment_date, $amount, "Customer payment: $payment_number"]);
            } else {
                $cashStmt = $pdo->prepare("INSERT INTO cash_flow (tenant_id, flow_date, outflow, description, created_at) VALUES (?, ?, ?, ?, NOW())");
                $cashStmt->execute([$tenant_id, $payment_date, $amount, "Supplier payment: $payment_number"]);
            }
            
            if (class_exists('AccountingService')) {
                try {
                    $accounting = new AccountingService($pdo, $tenant_id, $user_id);
                    $accounting->journalizePayment($new_payment_id);
                } catch (Exception $e) {
                    // Accounting service error, but payment was saved
                }
            }
            
            try {
                LogAudit($pdo, 'CREATE_PAYMENT', 'payments', $new_payment_id, null, ['amount' => $amount, 'number' => $payment_number]);
            } catch (Exception $e) {
                // Audit logging failed
            }
            
            $pdo->commit();
            
            $type_text = ($payment_type === 'customer') ? 'customer' : 'supplier';
            
            echo json_encode([
                'success' => true, 
                'message' => "Payment of $$amount for $type_text has been recorded!<br>Number: <strong>$payment_number</strong>",
                'payment_id' => $new_payment_id,
                'payment_number' => $payment_number
            ]);
        } else {
            // Update existing payment - simplified for security, customers cannot edit
            if ($role === 'customer') {
                echo json_encode(['success' => false, 'message' => 'Customers cannot edit payments']);
                exit;
            }
            
            $oldStmt = $pdo->prepare("SELECT customer_id, invoice_id, amount, payment_method, bank_account_id FROM payments WHERE id = ? AND tenant_id = ?");
            $oldStmt->execute([$id, $tenant_id]);
            $oldPayment = $oldStmt->fetch();
            
            if (!$oldPayment) {
                echo json_encode(['success' => false, 'message' => 'Payment not found']);
                exit;
            }
            
            if ($oldPayment && $oldPayment['customer_id']) {
                $revertDebt = $pdo->prepare("UPDATE customers SET debt_amount = debt_amount + ? WHERE id = ? AND tenant_id = ?");
                $revertDebt->execute([$oldPayment['amount'], $oldPayment['customer_id'], $tenant_id]);
                
                if ($oldPayment['invoice_id']) {
                    $revertInv = $pdo->prepare("UPDATE invoices SET paid_amount = paid_amount - ?, status = CASE WHEN (total_amount - (paid_amount - ?)) <= 0 THEN 'paid' ELSE 'unpaid' END, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
                    $revertInv->execute([$oldPayment['amount'], $oldPayment['amount'], $oldPayment['invoice_id'], $tenant_id]);
                }
            }
            
            if ($oldPayment && $oldPayment['payment_method'] === 'bank_transfer' && $oldPayment['bank_account_id']) {
                $revertStmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ? AND tenant_id = ?");
                $revertStmt->execute([$oldPayment['amount'], $oldPayment['bank_account_id'], $tenant_id]);
            }
            
            if ($payment_type === 'customer') {
                $sql = "UPDATE payments SET payment_number = ?, customer_id = ?, invoice_id = ?, amount = ?, payment_date = ?, payment_method = ?, category = ?, reference_number = ?, bank_account_id = ?, notes = ? WHERE id = ? AND tenant_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$payment_number, $customer_id_param, $invoice_id, $amount, $payment_date, $payment_method, $category, $reference_number, $bank_account_id, $notes, $id, $tenant_id]);
                
                if ($customer_id_param) {
                    $updateDebt = $pdo->prepare("UPDATE customers SET debt_amount = debt_amount - ? WHERE id = ? AND tenant_id = ?");
                    $updateDebt->execute([$amount, $customer_id_param, $tenant_id]);
                }
                
                if ($invoice_id) {
                    $updateInv = $pdo->prepare("UPDATE invoices SET paid_amount = paid_amount + ?, status = CASE WHEN (paid_amount + ?) >= total_amount THEN 'paid' ELSE 'partial' END, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
                    $updateInv->execute([$amount, $amount, $invoice_id, $tenant_id]);
                }
            } else {
                $sql = "UPDATE payments SET payment_number = ?, supplier_name = ?, amount = ?, payment_date = ?, payment_method = ?, category = ?, reference_number = ?, bank_account_id = ?, notes = ? WHERE id = ? AND tenant_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$payment_number, $supplier_name, $amount, $payment_date, $payment_method, $category, $reference_number, $bank_account_id, $notes, $id, $tenant_id]);
            }
            
            if ($payment_method === 'bank_transfer' && $bank_account_id) {
                $newStmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ? AND tenant_id = ?");
                $newStmt->execute([$amount, $bank_account_id, $tenant_id]);
            }
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => "Payment '$payment_number' has been updated!"]);
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

function handleDeletePayment($pdo, $role, $session_tenant_id, $customer_id) {
    $id = $_POST['id'] ?? 0;
    
    // Customers cannot delete payments
    if ($role === 'customer') {
        echo json_encode(['success' => false, 'message' => 'Customers cannot delete payments']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT payment_number, amount, payment_method, bank_account_id, tenant_id, payment_date, customer_id, invoice_id, supplier_name FROM payments WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $session_tenant_id]);
        $payment = $stmt->fetch();
        
        if (!$payment) {
            echo json_encode(['success' => false, 'message' => 'Payment not found']);
            exit;
        }
        
        if ($payment['customer_id']) {
            $revertDebt = $pdo->prepare("UPDATE customers SET debt_amount = debt_amount + ? WHERE id = ? AND tenant_id = ?");
            $revertDebt->execute([$payment['amount'], $payment['customer_id'], $session_tenant_id]);
            
            if ($payment['invoice_id']) {
                $revertInv = $pdo->prepare("UPDATE invoices SET paid_amount = paid_amount - ?, status = CASE WHEN (total_amount - (paid_amount - ?)) <= 0 THEN 'paid' ELSE 'unpaid' END, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
                $revertInv->execute([$payment['amount'], $payment['amount'], $payment['invoice_id'], $session_tenant_id]);
            }
        }
        
        if ($payment['payment_method'] === 'bank_transfer' && $payment['bank_account_id']) {
            $stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$payment['amount'], $payment['bank_account_id'], $session_tenant_id]);
        }
        
        $stmt = $pdo->prepare("DELETE FROM payments WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $session_tenant_id]);
        
        $cashStmt = $pdo->prepare("DELETE FROM cash_flow WHERE description LIKE ? AND (inflow = ? OR outflow = ?) AND flow_date = ? AND tenant_id = ?");
        $cashStmt->execute(["%{$payment['payment_number']}%", $payment['amount'], $payment['amount'], $payment['payment_date'], $session_tenant_id]);
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => "Payment '{$payment['payment_number']}' has been deleted!"]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

function handleGetStats($pdo, $role, $session_tenant_id, $customer_id) {
    if ($role === 'customer' && $customer_id) {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_payments,
                SUM(amount) as total_amount,
                SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END) as cash_total,
                SUM(CASE WHEN payment_method = 'bank_transfer' THEN amount ELSE 0 END) as bank_total,
                SUM(CASE WHEN payment_method = 'check' THEN amount ELSE 0 END) as check_total,
                SUM(CASE WHEN payment_method = 'mobile_money' THEN amount ELSE 0 END) as mobile_total,
                SUM(CASE WHEN DATE(payment_date) = CURDATE() THEN amount ELSE 0 END) as today_total,
                COUNT(CASE WHEN DATE(payment_date) = CURDATE() THEN 1 END) as today_count
            FROM payments
            WHERE customer_id = ? AND tenant_id = ?
        ");
        $stmt->execute([$customer_id, $session_tenant_id]);
    } else {
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
            WHERE tenant_id = ?
        ");
        $stmt->execute([$session_tenant_id]);
    }
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($role === 'customer' && $customer_id) {
        $stmt = $pdo->prepare("SELECT category, SUM(amount) as total, COUNT(*) as count FROM payments WHERE customer_id = ? AND tenant_id = ? GROUP BY category ORDER BY total DESC LIMIT 10");
        $stmt->execute([$customer_id, $session_tenant_id]);
    } else {
        $stmt = $pdo->prepare("SELECT category, SUM(amount) as total, COUNT(*) as count FROM payments WHERE tenant_id = ? GROUP BY category ORDER BY total DESC LIMIT 10");
        $stmt->execute([$session_tenant_id]);
    }
    $categoryStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($role === 'customer' && $customer_id) {
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, SUM(amount) as total, COUNT(*) as count FROM payments WHERE customer_id = ? AND tenant_id = ? GROUP BY DATE_FORMAT(payment_date, '%Y-%m') ORDER BY month DESC LIMIT 6");
        $stmt->execute([$customer_id, $session_tenant_id]);
    } else {
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, SUM(amount) as total, COUNT(*) as count FROM payments WHERE tenant_id = ? GROUP BY DATE_FORMAT(payment_date, '%Y-%m') ORDER BY month DESC LIMIT 6");
        $stmt->execute([$session_tenant_id]);
    }
    $monthly = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'category_stats' => $categoryStats,
        'monthly' => $monthly
    ]);
}

function handleGeneratePaymentNumber($pdo, $session_tenant_id) {
    $prefix = 'PMT';
    $year = date('Y');
    $month = date('m');
    
    $seqStmt = $pdo->prepare("SELECT prefix, current_number, padding FROM tenant_sequences WHERE tenant_id = ? AND sequence_name = 'payment'");
    $seqStmt->execute([$session_tenant_id]);
    $sequence = $seqStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($sequence) {
        $prefix = $sequence['prefix'] ?: 'PMT';
        $current = $sequence['current_number'];
        $padding = $sequence['padding'];
        
        $updateStmt = $pdo->prepare("UPDATE tenant_sequences SET current_number = current_number + 1 WHERE tenant_id = ? AND sequence_name = 'payment'");
        $updateStmt->execute([$session_tenant_id]);
        
        $number = str_pad($current, $padding, '0', STR_PAD_LEFT);
        $payment_number = $prefix . $year . $month . '-' . $number;
        
        echo json_encode(['success' => true, 'payment_number' => $payment_number]);
        exit;
    }
    
    $sql = "SELECT payment_number FROM payments WHERE payment_number LIKE ? AND tenant_id = ? ORDER BY id DESC LIMIT 1";
    $pattern = "$prefix-$year$month-%";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$pattern, $session_tenant_id]);
    $last = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($last) {
        $parts = explode('-', $last['payment_number']);
        $lastSeq = (int)end($parts);
        $newSeq = $lastSeq + 1;
    } else {
        $newSeq = 1;
    }
    
    $sequence = str_pad($newSeq, 5, '0', STR_PAD_LEFT);
    $payment_number = "$prefix-$year$month-$sequence";
    
    echo json_encode(['success' => true, 'payment_number' => $payment_number]);
}

function handleGetCustomersByTenant($pdo, $role, $session_tenant_id, $customer_id) {
    if ($role === 'customer' && $customer_id) {
        $stmt = $pdo->prepare("SELECT c.id, c.customer_name, c.phone, c.email, c.debt_amount FROM customers c WHERE c.is_active = 1 AND c.id = ?");
        $stmt->execute([$customer_id]);
    } else {
        $stmt = $pdo->prepare("SELECT c.id, c.customer_name, c.phone, c.email, c.debt_amount FROM customers c WHERE c.is_active = 1 AND c.tenant_id = ? ORDER BY c.customer_name");
        $stmt->execute([$session_tenant_id]);
    }
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $empty = count($customers) === 0;
    $message = $empty ? "No customers found for this company" : "";
    
    echo json_encode([
        'success' => true,
        'customers' => $customers,
        'empty' => $empty,
        'message' => $message
    ]);
}

function handleGetInvoicesByCustomer($pdo, $session_tenant_id) {
    $customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    
    if (!$customer_id) {
        echo json_encode(['success' => false, 'invoices' => [], 'empty' => true, 'message' => 'Please select customer first']);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT i.id, i.invoice_number, i.total_amount, i.paid_amount, (i.total_amount - i.paid_amount) as due_amount, i.status FROM invoices i WHERE i.customer_id = ? AND i.tenant_id = ? AND i.status != 'paid' AND (i.total_amount - i.paid_amount) > 0 ORDER BY i.invoice_number DESC");
    $stmt->execute([$customer_id, $session_tenant_id]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $empty = count($invoices) === 0;
    $message = $empty ? "No unpaid invoices found for this customer" : "";
    
    echo json_encode([
        'success' => true,
        'invoices' => $invoices,
        'empty' => $empty,
        'message' => $message
    ]);
}

function handleGetMyPayments($pdo, $customer_id, $session_tenant_id) {
    if (!$customer_id) {
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
        exit;
    }
    
    $stmt = $pdo->prepare("
        SELECT p.*, i.invoice_number
        FROM payments p
        LEFT JOIN invoices i ON p.invoice_id = i.id
        WHERE p.customer_id = ? AND p.tenant_id = ?
        ORDER BY p.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$customer_id, $session_tenant_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'payments' => $payments
    ]);
}

// ============================================
// RENDER COMPONENTS
// ============================================

function renderPaymentsTable($payments, $role) {
    $methodNames = [
        'cash' => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'check' => 'Check',
        'mobile_money' => 'Mobile Money'
    ];
    ?>
    <div style="overflow-x: auto; width: 100%;">
        <table class="payments-table" style="min-width: 1000px; width: 100%;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Payment #</th>
                    <th>Date</th>
                    <th>Customer / Supplier</th>
                    <th>Invoice</th>
                    <th>Amount</th>
                    <th>Category</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <?php if ($role !== 'customer'): ?>
                    <th>Created By</th>
                    <?php endif; ?>
                    <th>Receipt</th>
                    <?php if ($role !== 'customer'): ?>
                    <th>Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (count($payments) > 0): ?>
                    <?php foreach ($payments as $payment): 
                        $methodClass = 'method-' . str_replace('_', '-', $payment['payment_method']);
                        $methodIcon = $payment['payment_method'] == 'cash' ? 'fa-money-bill' : ($payment['payment_method'] == 'bank_transfer' ? 'fa-university' : ($payment['payment_method'] == 'check' ? 'fa-money-check' : 'fa-mobile-alt'));
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($payment['id']) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($payment['payment_number']) ?></strong>
                                <div style="font-size: 10px; color: #6c757d;"><i class="fas fa-clock"></i> <?= date('H:i', strtotime($payment['created_at'])) ?></div>
                            </td>
                            <td><?= date('d/m/Y', strtotime($payment['payment_date'])) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($payment['customer_name'] ?? $payment['supplier_name'] ?? '-') ?></strong>
                                <?php if ($payment['customer_id']): ?>
                                    <div style="font-size: 10px; color: #0F7A3A;">Customer</div>
                                <?php elseif ($payment['supplier_name'] && !$payment['customer_id']): ?>
                                    <div style="font-size: 10px; color: #e65100;">Supplier</div>
                                <?php endif; ?>
                            </td>
                            <td><?php if ($payment['invoice_number']): ?><span class="invoice-link"><?= htmlspecialchars($payment['invoice_number']) ?></span><?php else: ?>-<?php endif; ?></td>
                            <td><strong class="text-danger">$<?= number_format($payment['amount'], 2) ?></strong></td>
                            <td><span class="category-badge"><?= htmlspecialchars($payment['category'] ?? '-') ?></span></td>
                            <td><span class="payment-method-badge <?= $methodClass ?>"><i class="fas <?= $methodIcon ?>"></i> <?= $methodNames[$payment['payment_method']] ?? ucfirst($payment['payment_method']) ?></span></td>
                            <td>
                                <?php if ($payment['reference_number'] && $payment['payment_method'] != 'cash'): ?>
                                    <code><?= htmlspecialchars($payment['reference_number']) ?></code>
                                <?php else: ?>
                                    <span style="color: #ccc;">-</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($role !== 'customer'): ?>
                            <td><?= htmlspecialchars($payment['created_by_name'] ?? '-') ?></td>
                            <?php endif; ?>
                            <td>
                                <button onclick="openReceiptPopup(<?= $payment['id'] ?>)" class="action-btn btn-receipt">
                                    <i class="fas fa-receipt"></i> Receipt
                                </button>
                            </td>
                            <?php if ($role !== 'customer'): ?>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn btn-view view-payment" data-id="<?= $payment['id'] ?>"><i class="fas fa-eye"></i></button>
                                    <button class="action-btn btn-edit edit-payment" data-id="<?= $payment['id'] ?>"><i class="fas fa-edit"></i></button>
                                    <button class="action-btn btn-delete delete-payment" data-id="<?= $payment['id'] ?>" data-name="<?= htmlspecialchars($payment['payment_number']) ?>"><i class="fas fa-trash"></i></button>
                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="<?= $role !== 'customer' ? '12' : '10' ?>" style="text-align: center; padding: 50px;">
                        <div class="empty-state">
                            <i class="fas fa-money-bill-wave" style="font-size: 48px; color: #ccc;"></i>
                            <p style="margin-top: 15px;">No payments found</p>
                            <?php if ($role !== 'customer'): ?>
                            <button class="btn-primary-custom" id="addPaymentBtnEmpty" style="margin-top: 10px;"><i class="fas fa-plus-circle"></i> New Payment</button>
                            <?php endif; ?>
                        </div>
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function renderPagination($page, $total_pages) {
    if ($total_pages <= 1) return;
    ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a data-page="<?= $page-1 ?>"><i class="fas fa-chevron-left"></i> Previous</a>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <?php if ($i == $page): ?>
                <span class="active"><?= $i ?></span>
            <?php else: ?>
                <a data-page="<?= $i ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $total_pages): ?>
            <a data-page="<?= $page+1 ?>">Next <i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php
}

function renderStatsCards($role) {
    ?>
    <div class="stats-grid" id="stats-container">
        <div class="stat-card"><div class="stat-info"><h4>Total Payments</h4><div class="stat-number" id="stat-total">0</div></div><div class="stat-icon"><i class="fas fa-receipt"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Total Amount</h4><div class="stat-number" id="stat-total-amount">$0</div></div><div class="stat-icon"><i class="fas fa-dollar-sign"></i></div></div>
        <?php if ($role !== 'customer'): ?>
        <div class="stat-card"><div class="stat-info"><h4>Customer Payments</h4><div class="stat-number" id="stat-customer-payments">$0</div></div><div class="stat-icon"><i class="fas fa-users"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Supplier Payments</h4><div class="stat-number" id="stat-supplier-payments">$0</div></div><div class="stat-icon"><i class="fas fa-truck"></i></div></div>
        <?php endif; ?>
        <div class="stat-card"><div class="stat-info"><h4>Today's Payments</h4><div class="stat-number" id="stat-today">$0</div></div><div class="stat-icon"><i class="fas fa-calendar-day"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Cash Total</h4><div class="stat-number" id="stat-cash">$0</div></div><div class="stat-icon"><i class="fas fa-money-bill"></i></div></div>
    </div>
    <?php
}

function renderFilters($role) {
    ?>
    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Search</label>
                <input type="text" id="searchInput" placeholder="Payment #, Customer...">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-tag"></i> Category</label>
                <select id="categoryFilter">
                    <option value="all">All</option>
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
                <label><i class="fas fa-credit-card"></i> Payment Method</label>
                <select id="methodFilter">
                    <option value="all">All</option>
                    <option value="cash">Cash</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="check">Check</option>
                    <option value="mobile_money">Mobile Money</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-calendar"></i> From Date</label>
                <input type="date" id="dateFrom">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-calendar"></i> To Date</label>
                <input type="date" id="dateTo">
            </div>
            <div class="filter-group">
                <button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Filter</button>
                <button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Reset</button>
            </div>
        </div>
    </div>
    <?php
}

// ============================================
// MODALS
// ============================================

function renderModals($role, $bank_accounts) {
    ?>
    <!-- Payment Modal -->
    <div class="modal fade" id="paymentModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px;">
                <div class="modal-header">
                    <h5 class="modal-title" id="paymentModalLabel"><i class="fas fa-money-bill-wave"></i> New Payment</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <form id="paymentForm">
                    <div class="modal-body">
                        <input type="hidden" name="payment_id" id="payment_id">
                        
                        <div class="auto-number-badge">
                            <i class="fas fa-magic"></i> Payment Number: <strong id="autoPaymentNumber">-</strong>
                            <input type="hidden" name="payment_number" id="modalPaymentNumber">
                        </div>
                        
                        <?php if ($role !== 'customer'): ?>
                        <div class="payment-type-tabs">
                            <div class="payment-type-tab active" data-type="customer">
                                <i class="fas fa-user"></i> Customer Payment
                            </div>
                            <div class="payment-type-tab" data-type="supplier">
                                <i class="fas fa-truck"></i> Supplier Payment
                            </div>
                        </div>
                        <input type="hidden" name="payment_type" id="paymentType" value="customer">
                        <?php else: ?>
                        <input type="hidden" name="payment_type" id="paymentType" value="customer">
                        <?php endif; ?>
                        
                        <div id="customerPaymentSection">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Customer <span class="text-danger">*</span></label>
                                        <select name="customer_id" id="modalCustomerId" class="form-control" <?= $role === 'customer' ? 'disabled' : '' ?>>
                                            <option value="">-- Select Customer --</option>
                                        </select>
                                        <div id="customerLoading" class="text-muted small mt-1" style="display: none;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Invoice (Optional)</label>
                                        <select name="invoice_id" id="modalInvoiceId" class="form-control">
                                            <option value="">-- Select Invoice --</option>
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
                                        <label>Supplier Name <span class="text-danger">*</span></label>
                                        <input type="text" name="supplier_name" id="modalSupplierName" class="form-control" placeholder="Enter supplier name">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Payment Category</label>
                                    <select name="category" id="modalCategory" class="form-control">
                                        <option value="">Select Category...</option>
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
                                    <label>Payment Date</label>
                                    <input type="date" name="payment_date" id="modalPaymentDate" class="form-control" value="<?= date('Y-m-d') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Amount <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" name="amount" id="modalAmount" class="form-control" placeholder="0.00" required>
                                    <small id="amountHint" class="form-text text-muted"></small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Payment Method</label>
                                    <select name="payment_method" id="modalPaymentMethod" class="form-control" required>
                                        <option value="cash">Cash</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                        <option value="check">Check</option>
                                        <option value="mobile_money">Mobile Money</option>
                                    </select>
                                </div>
                            </div>
                            <?php if ($role !== 'customer'): ?>
                            <div class="col-md-6" id="bankAccountField" style="display: none;">
                                <div class="form-group">
                                    <label>Bank Account</label>
                                    <select name="bank_account_id" id="modalBankAccountId" class="form-control">
                                        <option value="">Select Account...</option>
                                        <?php foreach ($bank_accounts as $ba): ?>
                                        <option value="<?= $ba['id'] ?>"><?= htmlspecialchars($ba['account_name']) ?> - <?= htmlspecialchars($ba['bank_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="col-md-12" id="referenceField">
                                <div class="form-group">
                                    <label id="referenceLabel">Reference Number <span class="text-danger">*</span></label>
                                    <input type="text" name="reference_number" id="modalReferenceNumber" class="form-control" placeholder="Transaction ID, Check No.">
                                    <small class="form-text text-muted"><i class="fas fa-info-circle"></i> Required for non-cash payments</small>
                                </div>
                            </div>
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label>Notes</label>
                                    <textarea name="notes" id="modalNotes" class="form-control" rows="2" placeholder="Additional notes..."></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div id="invoiceInfo" style="display: none;" class="alert alert-info">
                            <i class="fas fa-info-circle"></i> <strong>Invoice:</strong> <span id="invoiceInfoText"></span><br>
                            <strong>Invoice Due Amount:</strong> <span id="invoiceDueAmount" class="text-danger">$0.00</span>
                            <hr>
                            <i class="fas fa-magic"></i> Payment amount will be auto-filled
                        </div>
                        <div id="customerDebtInfo" style="display: none;" class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> <strong>Customer Debt:</strong> <span id="customerDebtAmount">$0.00</span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary-custom">Save Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Modal -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px;">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-money-bill-wave"></i> Payment Details</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body" id="viewModalBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px;">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Delete Payment</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    Are you sure you want to delete payment <strong id="deletePaymentName"></strong>?<br><br>
                    <span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Warning: This action is permanent!</span>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Receipt Modal -->
    <div class="modal fade" id="receiptModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content" style="border-radius: 16px;">
                <div class="modal-header" style="background: #f8f9fa; border-bottom: 2px solid #2D1859; border-radius: 16px 16px 0 0;">
                    <h5 class="modal-title" id="receiptModalLabel">
                        <i class="fas fa-receipt" style="color: #2D1859;"></i> Payment Receipt
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
                        <i class="fas fa-times"></i> Close
                    </button>
                    <button type="button" class="btn btn-primary-custom" id="printReceiptBtn">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// Include header
require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Management - <?= htmlspecialchars($tenant_name) ?> | Cargo Management System</title>
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
            --curdun-warning: #f4b400;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f5f8; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: var(--curdun-dark); }
        
        .page-header { background: #fff; border-bottom: 1px solid #e0e1e6; padding: 20px 25px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .page-header h1 { color: var(--curdun-dark); font-size: 24px; font-weight: 700; margin: 0; }
        .page-header h1 i { color: var(--curdun-violet); margin-right: 10px; }
        .page-header .company-badge { background: rgba(82,0,102,0.1); padding: 8px 16px; border-radius: 20px; font-size: 14px; color: var(--curdun-violet); }
        
        .btn-primary-custom { background: var(--curdun-violet); color: white; border: none; padding: 10px 20px; border-radius: 20px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s ease; cursor: pointer; }
        .btn-primary-custom:hover { background: var(--curdun-violet-light); transform: translateY(-1px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: white; border: 1px solid #e0e1e6; border-radius: 8px; padding: 15px; display: flex; justify-content: space-between; align-items: center; }
        .stat-card .stat-info h4 { font-size: 13px; color: var(--curdun-gray); margin: 0 0 5px 0; font-weight: 600; text-transform: uppercase; }
        .stat-card .stat-info .stat-number { font-size: 28px; font-weight: 700; color: var(--curdun-dark); }
        .stat-card .stat-icon { font-size: 32px; color: var(--curdun-violet-light); opacity: 0.6; }
        
        .filters-card { background: white; border: 1px solid #e0e1e6; border-radius: 8px; padding: 20px; margin-bottom: 25px; }
        .filter-form { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 150px; }
        .filter-group label { display: block; font-size: 13px; font-weight: 600; color: var(--curdun-dark); margin-bottom: 6px; }
        .filter-group input, .filter-group select { width: 100%; padding: 10px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        .btn-filter { background: white; color: var(--curdun-dark); border: 1px solid #ccc; padding: 10px 20px; border-radius: 20px; font-weight: 600; cursor: pointer; }
        .btn-reset { background: white; color: var(--curdun-info); border: none; padding: 10px 20px; font-weight: 600; cursor: pointer; }
        
        .payments-table-container { background: white; border: 1px solid #e0e1e6; border-radius: 8px; overflow-x: auto; width: 100%; }
        .payments-table { width: 100%; border-collapse: collapse; }
        .payments-table th, .payments-table td { padding: 12px 10px; text-align: left; border-bottom: 1px solid #e0e1e6; vertical-align: middle; }
        .payments-table th { background: #f9f9fb; font-weight: 600; color: var(--curdun-gray); font-size: 13px; white-space: nowrap; }
        .payments-table tr:hover { background: #f9f9fb; }
        
        .payment-method-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .method-cash { background: #EEFBF3; color: #0F7A3A; }
        .method-bank-transfer { background: #e3f2fd; color: #0077c5; }
        .method-check { background: #fff8e1; color: #f57f17; }
        .method-mobile-money { background: #f3e5f5; color: #7b1fa2; }
        .category-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; background: #f4f5f8; color: var(--curdun-dark); border: 1px solid #e0e1e6; white-space: nowrap; }
        
        .action-buttons { display: flex; gap: 8px; white-space: nowrap; }
        .action-btn { background: none; border: none; cursor: pointer; font-size: 16px; padding: 5px; border-radius: 4px; transition: all 0.2s; }
        .btn-view { color: var(--curdun-info); }
        .btn-edit { color: var(--curdun-dark); }
        .btn-delete { color: var(--curdun-danger); }
        .btn-receipt { background: #EEFBF3; color: #0F7A3A; padding: 5px 10px; border-radius: 4px; font-size: 12px; cursor: pointer; }
        
        .alert-custom { position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideIn 0.3s ease; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .alert-success { background: #EEFBF3; color: #0F7A3A; border-left: 4px solid #0F7A3A; }
        .alert-error { background: #fce8e6; color: #B42318; border-left: 4px solid #B42318; }
        
        .modal-header { background: #f4f5f8; border-bottom: 1px solid #e0e1e6; }
        .loading-spinner { text-align: center; padding: 50px; }
        .loading-spinner i { font-size: 48px; color: var(--curdun-violet); animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 25px; flex-wrap: wrap; }
        .pagination a, .pagination span { padding: 8px 12px; border-radius: 4px; text-decoration: none; color: var(--curdun-dark); background: white; border: 1px solid #ccc; cursor: pointer; font-size: 14px; }
        .pagination .active { background: var(--curdun-info); color: white; border-color: var(--curdun-info); }
        
        .chart-container { background: white; border: 1px solid #e0e1e6; border-radius: 8px; padding: 20px; margin-bottom: 25px; }
        .chart-title { font-size: 16px; font-weight: 600; color: var(--curdun-dark); margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #e0e1e6; }
        .auto-number-badge { background: #EEFBF3; color: #0F7A3A; padding: 8px 15px; border-radius: 20px; font-size: 14px; display: inline-block; margin-bottom: 15px; }
        
        .payment-type-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid #e0e1e6; }
        .payment-type-tab { padding: 10px 20px; cursor: pointer; color: var(--curdun-gray); font-weight: 600; transition: all 0.2s; border-bottom: 3px solid transparent; }
        .payment-type-tab:hover { color: var(--curdun-violet); }
        .payment-type-tab.active { color: var(--curdun-violet); border-bottom-color: var(--curdun-violet); }
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
        <h1><i class="fas fa-money-bill-wave"></i> Payment Management</h1>
        <div class="d-flex gap-3 align-items-center">
            <span class="company-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($tenant_name) ?></span>
            <?php if ($role !== 'customer'): ?>
            <button type="button" class="btn-primary-custom" id="addPaymentBtn" style="margin-left: 15px;"><i class="fas fa-plus-circle"></i> New Payment</button>
            <?php endif; ?>
        </div>
    </div>

    <?php renderStatsCards($role); ?>

    <!-- Charts Row -->
    <div class="chart-container">
        <div class="chart-title"><i class="fas fa-chart-bar"></i> Monthly Payments</div>
        <canvas id="monthlyChart" height="200"></canvas>
    </div>
    
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="chart-container">
                <div class="chart-title"><i class="fas fa-chart-pie"></i> Category Distribution</div>
                <canvas id="categoryChart" height="200"></canvas>
            </div>
        </div>
        <div class="col-md-6">
            <div class="chart-container">
                <div class="chart-title"><i class="fas fa-chart-pie"></i> Payment Method Distribution</div>
                <canvas id="methodChart" height="200"></canvas>
            </div>
        </div>
    </div>

    <?php if ($role !== 'customer'): ?>
    <?php renderFilters($role); ?>
    <?php endif; ?>

    <!-- Payments Table Container -->
    <div id="payments-table-container">
        <div class="loading-spinner">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading payments...</p>
        </div>
    </div>
    <div id="pagination-container"></div>
</div>

<?php renderModals($role, $bank_accounts); ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
$(document).ready(function() {
    let currentPage = 1;
    let deleteId = null;
    let monthlyChart, categoryChart, methodChart;
    let currentReceiptPaymentId = null;
    let role = '<?= $role ?>';
    let isCustomer = role === 'customer';

    window.openReceiptPopup = function(paymentId) {
        if (!paymentId) {
            showAlert('error', 'Payment ID is required');
            return;
        }
        
        currentReceiptPaymentId = paymentId;
        
        $('#receiptModalBody').html(`
            <div class="text-center p-5">
                <i class="fas fa-spinner fa-spin fa-3x" style="color: #2D1859;"></i>
                <p class="mt-3">Loading receipt...</p>
            </div>
        `);
        
        $.ajax({
            url: 'receipts.php',
            type: 'GET',
            data: { id: paymentId, modal: 1, t: Date.now() },
            dataType: 'html',
            success: function(html) {
                $('#receiptModalBody').html(html);
                $('#receiptModal').modal('show');
            },
            error: function(xhr, status, error) {
                console.error('Error loading receipt:', error);
                $('#receiptModalBody').html(`
                    <div class="alert alert-danger text-center">
                        <i class="fas fa-exclamation-triangle fa-2x"></i>
                        <h5 class="mt-3">Error Occurred</h5>
                        <p>Could not load receipt. Please try again.</p>
                    </div>
                `);
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
                <title>Payment Receipt - ${currentReceiptPaymentId}</title>
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
                <style>
                    * { margin: 0; padding: 0; box-sizing: border-box; }
                    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; background: white; }
                    @media print {
                        body { padding: 0; }
                        button, .btn, .no-print { display: none !important; }
                    }
                </style>
            </head>
            <body>
                ${printContents}
                <script>
                    window.onload = function() { 
                        setTimeout(function() {
                            window.print();
                            window.close();
                        }, 500);
                    }
                <\/script>
            </body>
            </html>
        `);
        printWindow.document.close();
    });

    function togglePaymentType(type) {
        if (isCustomer) return;
        
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
    
    if (!isCustomer) {
        $('.payment-type-tab').click(function() {
            $('.payment-type-tab').removeClass('active');
            $(this).addClass('active');
            togglePaymentType($(this).data('type'));
        });
    }
    
    function toggleReferenceField() {
        const method = $('#modalPaymentMethod').val();
        if (method === 'cash') {
            $('#referenceField').hide();
            $('#modalReferenceNumber').prop('required', false);
            if (!isCustomer) {
                $('#bankAccountField').hide();
                $('#modalBankAccountId').prop('required', false);
            }
            $('#referenceLabel .text-danger').hide();
        } else if (method === 'bank_transfer' && !isCustomer) {
            $('#referenceField').show();
            $('#modalReferenceNumber').prop('required', true);
            $('#bankAccountField').show();
            $('#modalBankAccountId').prop('required', true);
            $('#referenceLabel .text-danger').show();
        } else {
            $('#referenceField').show();
            $('#modalReferenceNumber').prop('required', true);
            if (!isCustomer) {
                $('#bankAccountField').hide();
                $('#modalBankAccountId').prop('required', false);
            }
            $('#referenceLabel .text-danger').show();
        }
    }

    function generatePaymentNumber() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'generate_payment_number' },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#autoPaymentNumber').text(res.payment_number);
                    $('#modalPaymentNumber').val(res.payment_number);
                } else {
                    $('#autoPaymentNumber').text('Error');
                }
            },
            error: function(xhr) {
                console.error('Error generating payment number:', xhr);
                $('#autoPaymentNumber').text('Error');
            }
        });
    }
    
    function loadCustomers() {
        if (isCustomer) {
            // For customers, just load their own info
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_customers_by_tenant' },
                dataType: 'json',
                success: function(res) {
                    const select = $('#modalCustomerId');
                    select.empty();
                    if (res.success && res.customers && res.customers.length > 0) {
                        $.each(res.customers, function(i, c) {
                            select.append('<option value="' + c.id + '" data-debt="' + (c.debt_amount || 0) + '" selected>' + escapeHtml(c.customer_name) + ' - Debt: $' + parseFloat(c.debt_amount || 0).toFixed(2) + '</option>');
                        });
                        select.trigger('change');
                    } else {
                        select.html('<option value="">-- No customers --</option>');
                    }
                }
            });
            return;
        }
        
        $('#customerLoading').show();
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_customers_by_tenant' },
            dataType: 'json',
            success: function(res) {
                const select = $('#modalCustomerId');
                select.empty();
                if (res.success && res.customers && res.customers.length > 0) {
                    select.append('<option value="">-- Select Customer --</option>');
                    $.each(res.customers, function(i, c) {
                        select.append('<option value="' + c.id + '" data-debt="' + (c.debt_amount || 0) + '">' + escapeHtml(c.customer_name) + ' - Debt: $' + parseFloat(c.debt_amount || 0).toFixed(2) + '</option>');
                    });
                } else {
                    select.html('<option value="">-- No customers --</option>');
                }
                $('#customerLoading').hide();
            },
            error: function(xhr) {
                console.error('Error loading customers:', xhr);
                $('#modalCustomerId').html('<option value="">Error loading customers</option>');
                $('#customerLoading').hide();
            }
        });
    }
    
    function loadInvoicesByCustomer(customerId) {
        if (!customerId || isCustomer) {
            if (isCustomer && customerId) {
                // For customers, still try to load invoices
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: { ajax_action: 'get_invoices_by_customer', customer_id: customerId },
                    dataType: 'json',
                    success: function(res) {
                        const select = $('#modalInvoiceId');
                        select.empty();
                        if (res.success && res.invoices && res.invoices.length > 0) {
                            select.append('<option value="">Select Invoice...</option>');
                            $.each(res.invoices, function(i, inv) {
                                select.append('<option value="' + inv.id + '" data-due="' + inv.due_amount + '">' + escapeHtml(inv.invoice_number) + ' (Due: $' + parseFloat(inv.due_amount).toFixed(2) + ')</option>');
                            });
                        } else {
                            select.html('<option value="">-- No unpaid invoices --</option>');
                        }
                    }
                });
            } else {
                $('#modalInvoiceId').html('<option value="">-- Select Invoice --</option>');
                $('#invoiceInfo').hide();
            }
            return;
        }
        
        $('#invoiceLoading').show();
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_invoices_by_customer', customer_id: customerId },
            dataType: 'json',
            success: function(res) {
                const select = $('#modalInvoiceId');
                select.empty();
                if (res.success && res.invoices && res.invoices.length > 0) {
                    select.append('<option value="">Select Invoice...</option>');
                    $.each(res.invoices, function(i, inv) {
                        select.append('<option value="' + inv.id + '" data-due="' + inv.due_amount + '">' + escapeHtml(inv.invoice_number) + ' (Due: $' + parseFloat(inv.due_amount).toFixed(2) + ')</option>');
                    });
                } else {
                    select.html('<option value="">-- No unpaid invoices --</option>');
                }
                $('#invoiceLoading').hide();
            },
            error: function(xhr) {
                console.error('Error loading invoices:', xhr);
                $('#modalInvoiceId').html('<option value="">Error loading invoices</option>');
                $('#invoiceLoading').hide();
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
            $('#amountHint').html('<span class="text-success"><i class="fas fa-check-circle"></i> Payment amount auto-filled for this invoice ($' + parseFloat(dueAmount).toFixed(2) + ')</span>');
            $('#modalAmount').attr('max', dueAmount);
        } else {
            $('#invoiceInfo').hide();
            $('#modalAmount').val('');
            $('#amountHint').html('');
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
            $('#modalInvoiceId').html('<option value="">-- Select Invoice --</option>');
            $('#invoiceInfo').hide();
            $('#customerDebtInfo').hide();
        }
    });

    function loadPayments() {
        $('#payments-table-container').html('<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i><p>Loading payments...</p></div>');
        
        let data = {
            ajax_action: 'get_payments',
            page: currentPage
        };
        
        if (!isCustomer) {
            data.search = $('#searchInput').val();
            data.category = $('#categoryFilter').val();
            data.payment_method = $('#methodFilter').val();
            data.date_from = $('#dateFrom').val();
            data.date_to = $('#dateTo').val();
        }
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#payments-table-container').html(response.table_html);
                    $('#pagination-container').html(response.pagination_html);
                    attachTableEvents();
                } else {
                    $('#payments-table-container').html('<div class="alert alert-error">Error loading payments: ' + (response.message || 'Unknown error') + '</div>');
                }
            },
            error: function(xhr) {
                console.error('Error loading payments:', xhr);
                $('#payments-table-container').html('<div class="alert alert-error">Error loading payments. Please refresh the page.</div>');
            }
        });
    }

    function loadStats() {
        let data = { ajax_action: 'get_stats' };
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    const stats = data.stats;
                    $('#stat-total').text(stats.total_payments || 0);
                    $('#stat-total-amount').text('$' + (parseFloat(stats.total_amount || 0).toFixed(2)));
                    if (!isCustomer) {
                        $('#stat-customer-payments').text('$' + (parseFloat(stats.customer_payments_total || 0).toFixed(2)));
                        $('#stat-supplier-payments').text('$' + (parseFloat(stats.supplier_payments_total || 0).toFixed(2)));
                    }
                    $('#stat-today').text('$' + (parseFloat(stats.today_total || 0).toFixed(2)));
                    $('#stat-cash').text('$' + (parseFloat(stats.cash_total || 0).toFixed(2)));
                    
                    const monthly = data.monthly;
                    if (monthlyChart) monthlyChart.destroy();
                    monthlyChart = new Chart(document.getElementById('monthlyChart'), {
                        type: 'bar',
                        data: {
                            labels: monthly.map(m => m.month),
                            datasets: [{
                                label: 'Payments ($)',
                                data: monthly.map(m => parseFloat(m.total)),
                                backgroundColor: '#2D1859'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: true,
                            scales: { y: { beginAtZero: true, title: { display: true, text: 'Amount ($)' } } }
                        }
                    });
                    
                    const categoryStats = data.category_stats;
                    if (categoryChart) categoryChart.destroy();
                    categoryChart = new Chart(document.getElementById('categoryChart'), {
                        type: 'pie',
                        data: {
                            labels: categoryStats.map(c => c.category || 'Other'),
                            datasets: [{
                                data: categoryStats.map(c => parseFloat(c.total)),
                                backgroundColor: ['#1565c0', '#e65100', '#2e7d32', '#7b1fa2', '#c62828', '#f4b400', '#6c757d']
                            }]
                        },
                        options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom' } } }
                    });
                    
                    if (methodChart) methodChart.destroy();
                    methodChart = new Chart(document.getElementById('methodChart'), {
                        type: 'pie',
                        data: {
                            labels: ['Cash', 'Bank Transfer', 'Check', 'Mobile Money'],
                            datasets: [{
                                data: [
                                    parseFloat(stats.cash_total || 0),
                                    parseFloat(stats.bank_total || 0),
                                    parseFloat(stats.check_total || 0),
                                    parseFloat(stats.mobile_total || 0)
                                ],
                                backgroundColor: ['#2e7d32', '#1565c0', '#e65100', '#7b1fa2']
                            }]
                        },
                        options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom' } } }
                    });
                }
            },
            error: function(xhr) {
                console.error('Error loading stats:', xhr);
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
                                <div class="col-12 mb-3">
                                    <div class="alert alert-info">
                                        <strong><i class="fas fa-receipt"></i> Payment Number:</strong> ${escapeHtml(p.payment_number)}
                                    </div>
                                </div>
                                <div class="col-6"><strong>Date:</strong></div>
                                <div class="col-6">${p.payment_date}</div>
                                <div class="col-6"><strong>Customer / Supplier:</strong></div>
                                <div class="col-6">${escapeHtml(p.customer_name || p.supplier_name || '-')}</div>
                                <div class="col-6"><strong>Amount:</strong></div>
                                <div class="col-6"><strong class="text-danger">$${parseFloat(p.amount).toFixed(2)}</strong></div>
                                <div class="col-6"><strong>Payment Method:</strong></div>
                                <div class="col-6">${p.payment_method}</div>
                                <div class="col-6"><strong>Reference Number:</strong></div>
                                <div class="col-6">${escapeHtml(p.reference_number || '-')}</div>
                                <div class="col-6"><strong>Category:</strong></div>
                                <div class="col-6">${escapeHtml(p.category || '-')}</div>
                                <div class="col-12 mt-3"><strong>Notes:</strong></div>
                                <div class="col-12"><div class="alert alert-info mt-2">${escapeHtml(p.notes || '-')}</div></div>
                            </div>
                        `);
                        $('#viewModal').modal('show');
                    } else {
                        showAlert('error', res.message || 'Error loading payment details');
                    }
                },
                error: function() {
                    showAlert('error', 'Error loading payment details');
                }
            });
        });
        
        if (!isCustomer) {
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
                            $('#paymentModalLabel').text('Edit Payment');
                            $('#payment_id').val(p.id);
                            if(p.customer_id) {
                                togglePaymentType('customer');
                                $('#modalCustomerId').val(p.customer_id);
                                $('#modalInvoiceId').val(p.invoice_id);
                            } else {
                                togglePaymentType('supplier');
                                $('#modalSupplierName').val(p.supplier_name);
                            }
                            $('#modalCategory').val(p.category);
                            $('#modalPaymentDate').val(p.payment_date);
                            $('#modalAmount').val(p.amount);
                            $('#modalPaymentMethod').val(p.payment_method);
                            $('#modalReferenceNumber').val(p.reference_number);
                            $('#modalBankAccountId').val(p.bank_account_id);
                            $('#modalNotes').val(p.notes);
                            $('#autoPaymentNumber').text(p.payment_number);
                            $('#modalPaymentNumber').val(p.payment_number);
                            toggleReferenceField();
                            $('#paymentModal').modal('show');
                        } else {
                            showAlert('error', res.message || 'Error loading payment for edit');
                        }
                    },
                    error: function() {
                        showAlert('error', 'Error loading payment for edit');
                    }
                });
            });
            
            $('.delete-payment').off('click').on('click', function() {
                deleteId = $(this).data('id');
                $('#deletePaymentName').text($(this).data('name'));
                $('#deleteModal').modal('show');
            });
        }
        
        $('.pagination a').off('click').on('click', function(e) {
            e.preventDefault();
            const page = $(this).data('page');
            if (page) {
                currentPage = page;
                loadPayments();
            }
        });
    }

    function showAlert(type, msg) {
        const alertClass = type === 'success' ? 'alert-success' : 'alert-error';
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        $('#alert-placeholder').html(`
            <div class="alert alert-custom ${alertClass} alert-dismissible fade show">
                <i class="fas ${icon}"></i> ${msg}
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        `);
        setTimeout(function() {
            $('.alert-custom').fadeOut(3000, function() { $(this).remove(); });
        }, 5000);
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

    $('#paymentForm').submit(function(e) {
        e.preventDefault();
        
        const amount = parseFloat($('#modalAmount').val());
        if (isNaN(amount) || amount <= 0) {
            showAlert('error', 'Please enter the payment amount');
            return;
        }
        
        const paymentType = $('#paymentType').val();
        let customerId = null, supplierName = null;
        
        if (paymentType === 'customer') {
            customerId = $('#modalCustomerId').val();
            if (!customerId) {
                showAlert('error', 'Please select a customer');
                return;
            }
            const invoiceId = $('#modalInvoiceId').val();
            if (invoiceId) {
                const dueAmount = $('#modalInvoiceId').find('option:selected').data('due');
                if (amount > dueAmount) {
                    showAlert('error', 'Payment amount ($' + amount.toFixed(2) + ') exceeds invoice due amount ($' + dueAmount.toFixed(2) + ')');
                    return;
                }
            }
        } else {
            supplierName = $('#modalSupplierName').val().trim();
            if (!supplierName) {
                showAlert('error', 'Please enter the supplier name');
                return;
            }
        }
        
        const method = $('#modalPaymentMethod').val();
        const referenceNumber = $('#modalReferenceNumber').val().trim();
        if (method !== 'cash' && referenceNumber === '') {
            showAlert('error', 'Reference number is required for this payment method');
            return;
        }
        if (method === 'bank_transfer' && !isCustomer && !$('#modalBankAccountId').val()) {
            showAlert('error', 'Please select the bank account');
            return;
        }
        
        const submitBtn = $(this).find('button[type="submit"]');
        const originalText = submitBtn.html();
        submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Saving...').prop('disabled', true);
        
        let data = {
            ajax_action: 'save_payment',
            payment_id: $('#payment_id').val(),
            payment_number: $('#modalPaymentNumber').val(),
            payment_type: paymentType,
            amount: amount,
            payment_date: $('#modalPaymentDate').val(),
            payment_method: method,
            category: $('#modalCategory').val(),
            reference_number: referenceNumber,
            notes: $('#modalNotes').val()
        };
        
        if (!isCustomer) {
            data.bank_account_id = $('#modalBankAccountId').val();
        }
        
        if (paymentType === 'customer') {
            data.customer_id = customerId;
            data.invoice_id = $('#modalInvoiceId').val();
        } else {
            data.supplier_name = supplierName;
        }
        
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
                    $('#payment_id').val('');
                    $('#invoiceInfo').hide();
                    $('#customerDebtInfo').hide();
                    $('#amountHint').html('');
                    generatePaymentNumber();
                    toggleReferenceField();
                    if (!isCustomer) {
                        togglePaymentType('customer');
                        $('.payment-type-tab[data-type="customer"]').addClass('active');
                        $('.payment-type-tab[data-type="supplier"]').removeClass('active');
                    }
                    
                    if (res.payment_id) {
                        setTimeout(function() {
                            openReceiptPopup(res.payment_id);
                        }, 500);
                    }
                } else {
                    showAlert('error', res.message);
                }
                submitBtn.html(originalText).prop('disabled', false);
            },
            error: function(xhr) {
                let errorMsg = 'An error occurred';
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.message) errorMsg = response.message;
                } catch(e) {
                    errorMsg = 'Server error occurred';
                }
                showAlert('error', errorMsg);
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
                    } else {
                        showAlert('error', res.message);
                    }
                    deleteId = null;
                },
                error: function() {
                    showAlert('error', 'Error deleting payment');
                    deleteId = null;
                }
            });
        }
    });

    $('#addPaymentBtn, #addPaymentBtnEmpty').click(function() {
        $('#paymentModalLabel').text('New Payment');
        $('#paymentForm')[0].reset();
        $('#payment_id').val('');
        $('#modalPaymentDate').val(new Date().toISOString().split('T')[0]);
        $('#invoiceInfo').hide();
        $('#customerDebtInfo').hide();
        $('#amountHint').html('');
        $('#modalAmount').removeAttr('max');
        if (!isCustomer) {
            togglePaymentType('customer');
            $('.payment-type-tab[data-type="customer"]').addClass('active');
            $('.payment-type-tab[data-type="supplier"]').removeClass('active');
        }
        generatePaymentNumber();
        loadCustomers();
        toggleReferenceField();
        $('#paymentModal').modal('show');
    });

    $('#modalPaymentMethod').on('change', toggleReferenceField);
    
    if (!isCustomer) {
        $('#applyFilters').click(function() {
            currentPage = 1;
            loadPayments();
            loadStats();
        });
        $('#resetFilters').click(function() {
            $('#searchInput').val('');
            $('#categoryFilter').val('all');
            $('#methodFilter').val('all');
            $('#dateFrom').val('');
            $('#dateTo').val('');
            currentPage = 1;
            loadPayments();
            loadStats();
        });
        $('#searchInput').keypress(function(e) {
            if (e.which === 13) {
                currentPage = 1;
                loadPayments();
            }
        });
    }

    toggleReferenceField();
    if (!isCustomer) {
        togglePaymentType('customer');
    }

    loadPayments();
    loadStats();
    loadCustomers();
    generatePaymentNumber();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
