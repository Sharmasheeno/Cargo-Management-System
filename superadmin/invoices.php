<?php
// superadmin/invoices.php
// Invoices Management forfaras cargo - Super Admin
// WITH PAYMENT INTEGRATION - Lacagta waxaa laga bixin karaa biilka toos

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is superadmin or company_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['superadmin', 'company_admin'])) {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION['role'];
$session_tenant_id = $_SESSION['tenant_id'] ?? 0;

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/sa_scope.php';

// Load the real double-entry AccountingService before the inline fallback
// below. Without this require, the fallback class (which only writes to
// audit_logs — never to journal_entries) took over, silently skipping
// ledger posting from this page's invoice and payment flows.
if (file_exists(__DIR__ . '/../includes/AccountingService.php')) {
    require_once __DIR__ . '/../includes/AccountingService.php';
}

// Check if services exist, if not create fallbacks
if (!class_exists('AccountingService')) {
    class AccountingService {
        private $pdo;
        private $tenant_id;
        private $user_id;
        
        public function __construct($pdo, $tenant_id, $user_id) {
            $this->pdo = $pdo;
            $this->tenant_id = $tenant_id;
            $this->user_id = $user_id;
        }
        
        public function journalizeInvoice($invoice_id) {
            // Fallback - just log
            try {
                $stmt = $this->pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, created_at) VALUES (?, 'CREATE_INVOICE', 'invoices', ?, NOW())");
                $stmt->execute([$this->user_id, $invoice_id]);
            } catch (Exception $e) {}
            return true;
        }
        
        public function journalizeReceipt($receipt_id) {
            try {
                $stmt = $this->pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, created_at) VALUES (?, 'CREATE_RECEIPT', 'receipts', ?, NOW())");
                $stmt->execute([$this->user_id, $receipt_id]);
            } catch (Exception $e) {}
            return true;
        }
    }
}

if (!class_exists('MessagingService')) {
    class MessagingService {
        private $pdo;
        
        public function __construct($pdo) {
            $this->pdo = $pdo;
        }
        
        public function sendWhatsApp($phone, $message) {
            // Fallback - just return success for manual sending
            return ['success' => false, 'message' => 'Manual WhatsApp sending required'];
        }
    }
}

require_once __DIR__ . '/../includes/audit_helper.php';

/**
 * Generates a safe invoice number for a tenant.
 * Works even if tenant_sequences record is missing.
 */
function generateInvoiceNumberSafe(PDO $pdo, ?int $tenant_id): string
{
    $year = date('Y');
    $month = date('m');
    $prefix = 'INV';
    $current = 1;
    $padding = 5;

    if (!empty($tenant_id)) {
        $tenantStmt = $pdo->prepare("SELECT id, name, code FROM tenants WHERE id = ? LIMIT 1");
        $tenantStmt->execute([$tenant_id]);
        $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);

        if ($tenant) {
            $prefix = !empty($tenant['code'])
                ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $tenant['code']))
                : strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $tenant['name'] ?? 'INV'), 0, 3));

            if (empty($prefix)) {
                $prefix = 'INV';
            }

            $seqStmt = $pdo->prepare("SELECT prefix, current_number, padding FROM tenant_sequences WHERE tenant_id = ? AND sequence_name = 'invoice' LIMIT 1");
            $seqStmt->execute([$tenant_id]);
            $sequence = $seqStmt->fetch(PDO::FETCH_ASSOC);

            if (!$sequence) {
                $insertSeq = $pdo->prepare("INSERT INTO tenant_sequences (tenant_id, sequence_name, prefix, current_number, padding) VALUES (?, 'invoice', ?, 1, 5)");
                $insertSeq->execute([$tenant_id, $prefix]);
                $current = 1;
                $padding = 5;
            } else {
                $prefix = !empty($sequence['prefix']) ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $sequence['prefix'])) : $prefix;
                $current = max(1, (int)$sequence['current_number']);
                $padding = max(3, (int)$sequence['padding']);
            }

            $updateSeq = $pdo->prepare("UPDATE tenant_sequences SET current_number = current_number + 1 WHERE tenant_id = ? AND sequence_name = 'invoice'");
            $updateSeq->execute([$tenant_id]);

            $number = str_pad((string)$current, $padding, '0', STR_PAD_LEFT);
            return $prefix . $year . $month . '-' . $number;
        }
    }

    $pattern = $prefix . '-' . $year . $month . '-%';
    $stmt = $pdo->prepare("SELECT invoice_number FROM invoices WHERE invoice_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$pattern]);
    $last = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($last) {
        $parts = explode('-', $last['invoice_number']);
        $current = ((int)end($parts)) + 1;
    }

    return $prefix . '-' . $year . $month . '-' . str_pad((string)$current, 5, '0', STR_PAD_LEFT);
}


$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Super Admin';

// Get all tenants for filter dropdown (Super Admin only)
$tenants = [];
if ($role === 'superadmin') {
    try {
        $stmt = $pdo->query("
            SELECT id, name, code, email, phone, address, is_active, 
                   subscription_plan, loyalty_cbm_points, loyalty_amount_points
            FROM tenants 
            WHERE is_active = 1 
            ORDER BY name
        ");
        $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $tenants = [];
    }
}

// Get all customers (filtered by tenant if company_admin)
$customers = [];
try {
    $cust_where = ($role === 'company_admin') ? "AND c.tenant_id = $session_tenant_id" : "";
    $stmt = $pdo->query("
        SELECT c.id, c.customer_name, c.phone, c.email, c.debt_amount,
               t.name as tenant_name, t.id as tenant_id
        FROM customers c
        LEFT JOIN tenants t ON c.tenant_id = t.id
        WHERE c.is_active = 1 $cust_where
        ORDER BY c.customer_name
    ");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customers = [];
}

// Get all trips for dropdown
$trips = [];
try {
    $stmt = $pdo->query("
        SELECT tt.id, tt.trip_number, tt.total_cbm,
               c.container_number, t.name as tenant_name, t.id as tenant_id
        FROM trucking_trips tt
        LEFT JOIN containers c ON tt.container_id = c.id
        LEFT JOIN tenants t ON tt.tenant_id = t.id
        ORDER BY tt.created_at DESC
        LIMIT 500
    ");
    $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $trips = [];
}

// Handle Export Actions (GET)
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action === 'export_invoices') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=invoices_export_'.date('Y-m-d').'.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['ID', 'Invoice Number', 'Date', 'Customer', 'Tenant', 'Trip', 'Subtotal', 'Tax', 'Discount', 'Total', 'Paid', 'Balance', 'Status']);
        
        $where_conditions = [];
        $params = [];
        
        $search = $_GET['search'] ?? '';
        $tenant_filter = $_GET['tenant'] ?? '';
        $status_filter = $_GET['status'] ?? 'all';
        
        if (!empty($search)) {
            $where_conditions[] = "(i.invoice_number LIKE ? OR c.customer_name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        if (!empty($tenant_filter)) {
            $where_conditions[] = "i.tenant_id = ?";
            $params[] = $tenant_filter;
        }
        if ($status_filter !== 'all') {
            $where_conditions[] = "i.status = ?";
            $params[] = $status_filter;
        }
        
        $where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);
        
        $sql = "SELECT i.*, c.customer_name, t.name as tenant_name, tt.trip_number 
                FROM invoices i 
                LEFT JOIN customers c ON i.customer_id = c.id 
                LEFT JOIN tenants t ON i.tenant_id = t.id 
                LEFT JOIN trucking_trips tt ON i.trip_id = tt.id 
                $where_clause 
                ORDER BY i.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['id'],
                $row['invoice_number'],
                $row['invoice_date'],
                $row['customer_name'],
                $row['tenant_name'],
                $row['trip_number'],
                $row['subtotal'],
                $row['tax'],
                $row['discount'],
                $row['total_amount'],
                $row['paid_amount'],
                $row['total_amount'] - $row['paid_amount'],
                $row['status']
            ]);
        }
        fclose($output);
        exit;
    }
    
    if ($action === 'download_sample') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=invoices_sample.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, ['Tenant Name', 'Customer Name', 'Invoice Number', 'Invoice Date (YYYY-MM-DD)', 'Due Date (YYYY-MM-DD)', 'Subtotal', 'Tax Rate (%)', 'Discount', 'Notes']);
        fputcsv($output, ['Example Logistics', 'John Doe', 'INV-5001', date('Y-m-d'), date('Y-m-d', strtotime('+30 days')), '1000.00', '5', '0', 'Initial invoice']);
        fclose($output);
        exit;
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];
    
    if ($action === 'quick_add_customer') {
        $name = $_POST['customer_name'];
        $phone = $_POST['phone'];
        $email = $_POST['email'];
        $address = $_POST['address'];
        $t_id = $_POST['tenant_id'];
        
        if (!$t_id) { echo json_encode(['success' => false, 'message' => 'Fadlan dooro shirkad marka hore']); exit; }
        
        $stmt = $pdo->prepare("INSERT INTO customers (tenant_id, customer_name, phone, email, address, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        if ($stmt->execute([$t_id, $name, $phone, $email, $address])) {
            $new_id = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'id' => $new_id, 'name' => $name, 'phone' => $phone]);
        } else { echo json_encode(['success' => false, 'message' => 'Lama badbaadin karo macaamilka']); }
        exit;
    }

    if ($action === 'quick_add_tenant') {
        $name = $_POST['name'];
        $addr = $_POST['address'];
        $cap = $_POST['warehouse_capacity'];
        
        $stmt = $pdo->prepare("INSERT INTO tenants (name, address, warehouse_capacity, is_active, created_at) VALUES (?, ?, ?, 1, NOW())");
        if ($stmt->execute([$name, $addr, $cap])) {
            $new_id = $pdo->lastInsertId();
            // Also need to create a sequence for the new tenant
            $pdo->prepare("INSERT INTO tenant_sequences (tenant_id, sequence_name, prefix, current_number, padding) VALUES (?, 'invoice', ?, 1, 5)")->execute([$new_id, substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 3)]);
            echo json_encode(['success' => true, 'id' => $new_id, 'name' => $name]);
        } else { echo json_encode(['success' => false, 'message' => 'Lama badbaadin karo shirkadda']); }
        exit;
    }
    
    if ($action === 'get_invoices') {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $search = $_POST['search'] ?? '';
        $tenant_filter = ($role === 'superadmin') ? (isset($_POST['tenant']) ? (int)$_POST['tenant'] : sa_selected_tenant_id_int()) : $session_tenant_id;
        $customer_filter = isset($_POST['customer']) ? (int)$_POST['customer'] : 0;
        $status_filter = $_POST['status'] ?? 'all';
        $date_from = $_POST['date_from'] ?? '';
        $date_to = $_POST['date_to'] ?? '';
        
        $where_conditions = [];
        $params = [];
        
        if (!empty($search)) {
            $where_conditions[] = "(i.invoice_number LIKE ? OR c.customer_name LIKE ? OR tt.trip_number LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($tenant_filter > 0) {
            $where_conditions[] = "i.tenant_id = ?";
            $params[] = $tenant_filter;
        } elseif ($role === 'company_admin') {
            $where_conditions[] = "i.tenant_id = ?";
            $params[] = $session_tenant_id;
        }
        
        if ($customer_filter > 0) {
            $where_conditions[] = "i.customer_id = ?";
            $params[] = $customer_filter;
        }
        
        if ($status_filter !== 'all') {
            $where_conditions[] = "i.status = ?";
            $params[] = $status_filter;
        }
        
        if (!empty($date_from)) {
            $where_conditions[] = "DATE(i.invoice_date) >= ?";
            $params[] = $date_from;
        }
        
        if (!empty($date_to)) {
            $where_conditions[] = "DATE(i.invoice_date) <= ?";
            $params[] = $date_to;
        }
        
        $where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);
        
        $count_sql = "SELECT COUNT(*) as total FROM invoices i
                      LEFT JOIN customers c ON i.customer_id = c.id
                      LEFT JOIN trucking_trips tt ON i.trip_id = tt.id
                      $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_invoices = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_invoices / $limit);
        
        $sql = "
            SELECT i.*, 
                   c.customer_name, c.phone as customer_phone, c.email as customer_email, c.debt_amount,
                   (SELECT SUM(total_amount) FROM invoices WHERE customer_id = c.id) as total_invoiced_all,
                   (SELECT SUM(amount) FROM receipts WHERE customer_id = c.id) as total_paid_all,
                   tt.trip_number,
                   t.name as tenant_name,
                   u.full_name as created_by_name
            FROM invoices i
            LEFT JOIN customers c ON i.customer_id = c.id
            LEFT JOIN trucking_trips tt ON i.trip_id = tt.id
            LEFT JOIN tenants t ON i.tenant_id = t.id
            LEFT JOIN users u ON i.created_by = u.id
            $where_clause
            ORDER BY i.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_start(); ?>
        <div style="overflow-x: auto; width: 100%;">
            <table class="invoices-table" style="min-width: 1300px; width: 100%;">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Lambarka Biilka</th>
                        <th>Taariikhda</th>
                        <th>Macaamilka</th>
                        <th>Safarka</th>
                        <th>Wadarta</th>
                        <th>La Bixiyay</th>
                        <th>Deynta</th>
                        <th>Xaaladda</th>
                        <th>Shirkadda</th>
                        <th>Falalka</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($invoices) > 0): ?>
                        <?php foreach ($invoices as $invoice): 
                            $dueAmount = $invoice['total_amount'] - $invoice['paid_amount'];
                            $statusClass = '';
                            $statusText = '';
                            switch($invoice['status']) {
                                case 'paid': $statusClass = 'status-paid'; $statusText = 'La Bixiyay'; break;
                                case 'unpaid': $statusClass = 'status-unpaid'; $statusText = 'Aan La Bixin'; break;
                                case 'partial': $statusClass = 'status-partial'; $statusText = 'Qayb ahaan'; break;
                                case 'overdue': $statusClass = 'status-overdue'; $statusText = 'Ka Dambay'; break;
                                default: $statusClass = 'status-other'; $statusText = ucfirst($invoice['status']);
                            }
                            $isOverdue = $invoice['due_date'] && $invoice['due_date'] < date('Y-m-d') && $invoice['status'] != 'paid';
                        ?>
                            <tr class="<?= $isOverdue ? 'overdue-row' : '' ?>">
                                <td><?= $invoice['id'] ?></td>
                                <td><strong><?= htmlspecialchars($invoice['invoice_number']) ?></strong><div style="font-size: 10px;">Due: <?= date('d/m/Y', strtotime($invoice['due_date'])) ?></div></td>
                                <td><?= date('d/m/Y', strtotime($invoice['invoice_date'])) ?> </td>
                                <td><strong><?= htmlspecialchars($invoice['customer_name'] ?? '-') ?></strong><div style="font-size: 11px;"><?= htmlspecialchars($invoice['customer_phone'] ?? '-') ?></div> </td>
                                <td><?= htmlspecialchars($invoice['trip_number'] ?? '-') ?> </td>
                                <td><strong>$<?= number_format($invoice['total_amount'], 2) ?></strong> </td>
                                <td>$<?= number_format($invoice['paid_amount'], 2) ?> </td>
                                <td><strong class="<?= $dueAmount > 0 ? 'text-danger' : 'text-success' ?>">$<?= number_format($dueAmount, 2) ?></strong> </td>
                                <td><span class="status-badge <?= $statusClass ?>"><?= $statusText ?></span><?= $isOverdue ? '<div style="font-size: 10px; color: #B42318;"><i class="fas fa-exclamation-triangle"></i> Overdue</div>' : '' ?> </td>
                                <td><?= htmlspecialchars($invoice['tenant_name'] ?? '-') ?> </td>
                                <td><div class="action-buttons">
                                    <button class="action-btn btn-view view-invoice" data-id="<?= $invoice['id'] ?>"><i class="fas fa-eye"></i></button>
                                    <button class="action-btn btn-edit edit-invoice" data-id="<?= $invoice['id'] ?>"><i class="fas fa-edit"></i></button>
                                    <button class="action-btn btn-payment add-payment" data-id="<?= $invoice['id'] ?>" data-number="<?= htmlspecialchars($invoice['invoice_number']) ?>" data-due="<?= $dueAmount ?>"><i class="fas fa-money-bill-wave"></i></button>
                                    <button class="action-btn btn-whatsapp whatsapp-invoice" 
                                        data-id="<?= $invoice['id'] ?>"
                                        data-phone="<?= htmlspecialchars($invoice['customer_phone'] ?? '') ?>" 
                                        data-number="<?= htmlspecialchars($invoice['invoice_number']) ?>" 
                                        data-amount="<?= number_format((float)($invoice['total_amount'] ?? 0), 2) ?>" 
                                        data-due="<?= number_format((float)$dueAmount, 2) ?>"
                                        data-total-debt="<?= number_format((float)($invoice['debt_amount'] ?? 0), 2) ?>"
                                        data-total-invoiced="<?= number_format((float)($invoice['total_invoiced_all'] ?? 0), 2) ?>"
                                        data-total-paid-all="<?= number_format((float)($invoice['total_paid_all'] ?? 0), 2) ?>"
                                        data-tenant="<?= htmlspecialchars($invoice['tenant_name'] ?? 'Smart Cargo') ?>"
                                        data-name="<?= htmlspecialchars($invoice['customer_name'] ?? 'Macaamil') ?>"><i class="fa-brands fa-whatsapp"></i></button>
                                    <button class="action-btn btn-print print-invoice" data-id="<?= $invoice['id'] ?>"><i class="fas fa-print"></i></button>
                                    <button class="action-btn btn-delete delete-invoice" data-id="<?= $invoice['id'] ?>" data-name="<?= htmlspecialchars($invoice['invoice_number']) ?>"><i class="fas fa-trash"></i></button>
                                </div> </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="11" style="text-align: center; padding: 50px;"><div class="empty-state"><i class="fas fa-file-invoice"></i><p>Ma jiraan wax biil ah</p><button class="btn-primary-custom" id="addInvoiceBtnEmpty"><i class="fas fa-plus-circle"></i> Biil Cusub</button></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        $table_html = ob_get_clean();
        
        ob_start();
        if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?><a data-page="<?= $page-1 ?>">Hore</a><?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?><span class="active"><?= $i ?></span><?php else: ?><a data-page="<?= $i ?>"><?= $i ?></a><?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?><a data-page="<?= $page+1 ?>">Danbe</a><?php endif; ?>
            </div>
        <?php endif;
        $pagination_html = ob_get_clean();
        
        ob_clean();
        echo json_encode(['table_html' => $table_html, 'pagination_html' => $pagination_html]);
        exit;
    }
    
    elseif ($action === 'get_invoice') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("
            SELECT i.*, 
                   c.customer_name, c.phone as customer_phone, c.email as customer_email, c.debt_amount,
                   tt.trip_number, tt.total_cbm,
                   t.name as tenant_name, t.code as tenant_code,
                   u.full_name as created_by_name
            FROM invoices i
            LEFT JOIN customers c ON i.customer_id = c.id
            LEFT JOIN trucking_trips tt ON i.trip_id = tt.id
            LEFT JOIN tenants t ON i.tenant_id = t.id
            LEFT JOIN users u ON i.created_by = u.id
            WHERE i.id = ?
        ");
        $stmt->execute([$id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($invoice) {
            $stmtItems = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
            $stmtItems->execute([$id]);
            $invoice['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode($invoice);
        exit;
    }
    
    // ADD PAYMENT TO INVOICE - FIXED ACTION NAME
    elseif ($action === 'add_payment') {
        $invoice_id = !empty($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0;
        $amount = (float)($_POST['amount'] ?? 0);
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $reference_number = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if (!$invoice_id) {
            echo json_encode(['success' => false, 'message' => 'Biilka lama helin']);
            exit;
        }
        
        if ($amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Fadlan geli qadarka bixinta']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Get invoice details
            $invStmt = $pdo->prepare("
                SELECT i.*, c.id as customer_id, c.debt_amount, c.customer_name,
                       i.tenant_id, i.invoice_number, i.total_amount, i.paid_amount
                FROM invoices i
                LEFT JOIN customers c ON i.customer_id = c.id
                WHERE i.id = ?
            ");
            $invStmt->execute([$invoice_id]);
            $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invoice) {
                echo json_encode(['success' => false, 'message' => 'Biilka lama helin']);
                exit;
            }

            // Super Admin tenant-scope guard: when operating under a selected
            // tenant, refuse invoices that belong to a different tenant.
            if (function_exists('sa_selected_tenant_id_int')) {
                $sa_scope = sa_selected_tenant_id_int();
                if ($sa_scope > 0 && (int)$invoice['tenant_id'] !== $sa_scope) {
                    echo json_encode(['success' => false, 'message' => 'Invoice does not belong to the selected tenant.']);
                    exit;
                }
            }

            $due_amount = $invoice['total_amount'] - $invoice['paid_amount'];
            
            if ($amount > $due_amount) {
                echo json_encode(['success' => false, 'message' => "Qadarka bixinta ($$amount) wuu ka badan yahay deynta biilka ($$due_amount)"]);
                exit;
            }
            
            // Generate payment number
            $payment_number = 'PMT-' . date('Ymd') . '-' . rand(1000, 9999);
            $check = $pdo->prepare("SELECT id FROM payments WHERE payment_number = ?");
            $check->execute([$payment_number]);
            while ($check->fetch()) {
                $payment_number = 'PMT-' . date('Ymd') . '-' . rand(1000, 9999);
                $check->execute([$payment_number]);
            }
            
            // Insert payment record
            $payStmt = $pdo->prepare("
                INSERT INTO payments (tenant_id, payment_number, customer_id, invoice_id, amount, payment_date, 
                payment_method, reference_number, notes, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $payStmt->execute([
                $invoice['tenant_id'], $payment_number, $invoice['customer_id'], $invoice_id, 
                $amount, $payment_date, $payment_method, $reference_number, $notes, $_SESSION['user_id']
            ]);
            
            $new_payment_id = $pdo->lastInsertId();
            
            // Update invoice paid amount
            $new_paid_amount = $invoice['paid_amount'] + $amount;
            $new_status = ($new_paid_amount >= $invoice['total_amount']) ? 'paid' : 'partial';
            $updateInv = $pdo->prepare("UPDATE invoices SET paid_amount = ?, status = ?, updated_at = NOW() WHERE id = ?");
            $updateInv->execute([$new_paid_amount, $new_status, $invoice_id]);
            
            // Customer debt is decremented by the DB trigger `trigger_update_debt`
            // AFTER INSERT ON receipts (see the INSERT below). Doing it here as
            // well caused a documented double-decrement — a paid customer would
            // end at -total instead of 0. The trigger is authoritative because
            // it also catches any other insert path (receipt_management, direct
            // SQL, etc.). Do NOT re-add the manual decrement.

            // Add to cash_flow as inflow
            $cashStmt = $pdo->prepare("INSERT INTO cash_flow (tenant_id, flow_date, inflow, description, created_at) VALUES (?, ?, ?, ?, NOW())");
            $cashStmt->execute([$invoice['tenant_id'], $payment_date, $amount, "Bixin biilka: {$invoice['invoice_number']} - {$invoice['customer_name']}"]);
            
            // Add to receipts
            $receipt_number = 'RCP-' . date('Ymd') . '-' . rand(1000, 9999);
            $rcpStmt = $pdo->prepare("
                INSERT INTO receipts (tenant_id, receipt_number, invoice_id, customer_id, amount, payment_date, payment_method, reference_number, notes, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $rcpStmt->execute([
                $invoice['tenant_id'], $receipt_number, $invoice_id, $invoice['customer_id'],
                $amount, $payment_date, $payment_method, $reference_number, $notes, $_SESSION['user_id']
            ]);
            $new_receipt_id = $pdo->lastInsertId();
            
            // Add to debt_collection_log
            $logStmt = $pdo->prepare("
                INSERT INTO debt_collection_log (tenant_id, customer_id, invoice_id, action_type, amount_collected, notes, collected_by, created_at) 
                VALUES (?, ?, ?, 'payment_received', ?, ?, ?, NOW())
            ");
            $logStmt->execute([
                $invoice['tenant_id'], $invoice['customer_id'], $invoice_id,
                $amount, "Bixin laga qaaday biilka {$invoice['invoice_number']} - Qadarka: $$amount", $_SESSION['user_id']
            ]);

            // ERP INTEGRATION: POST TO LEDGER (if class exists)
            if (class_exists('AccountingService')) {
                $accounting = new AccountingService($pdo, $invoice['tenant_id'], $_SESSION['user_id']);
                $accounting->journalizeReceipt($new_receipt_id);
            }

            // Loyalty award — same formula api/loyalty.php uses:
            //   points = round((amount / 100) * loyalty_amount_points, 2)
            // Idempotent per payment via a WHERE loyalty_points_log check on
            // (reference_type='payment', reference_id=$new_payment_id).
            // Runs inside the outer transaction so a rolled-back payment
            // never awards, and never awards twice.
            try {
                $rateStmt = $pdo->prepare("SELECT COALESCE(loyalty_amount_points, 0) AS rate FROM tenants WHERE id = ?");
                $rateStmt->execute([$invoice['tenant_id']]);
                $rate = (float)($rateStmt->fetchColumn() ?: 0);
                if ($rate > 0 && !empty($invoice['customer_id'])) {
                    $dupStmt = $pdo->prepare("SELECT id FROM loyalty_points_log WHERE reference_type='payment' AND reference_id = ? LIMIT 1");
                    $dupStmt->execute([$new_payment_id]);
                    if (!$dupStmt->fetchColumn()) {
                        $points = round(((float)$amount / 100.0) * $rate, 2);
                        if ($points > 0) {
                            $pdo->prepare("UPDATE customers SET loyalty_points = COALESCE(loyalty_points, 0) + ? WHERE id = ? AND tenant_id = ?")
                                ->execute([$points, $invoice['customer_id'], $invoice['tenant_id']]);
                            $reason = "Payment #{$payment_number} - {$amount} / 100 x {$rate} = {$points} points";
                            $pdo->prepare("INSERT INTO loyalty_points_log
                                (tenant_id, customer_id, points_earned, points_redeemed, amount_earned, reason, reference_type, reference_id, created_by, created_at)
                                VALUES (?, ?, ?, 0, ?, ?, 'payment', ?, ?, NOW())")
                                ->execute([$invoice['tenant_id'], $invoice['customer_id'], $points, $amount, $reason, $new_payment_id, $_SESSION['user_id']]);
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('[invoices add_payment loyalty] ' . $e->getMessage());
                // Non-fatal — do NOT roll back the payment because of a
                // loyalty write failure. The main receipt is already
                // atomic; loyalty is a bonus.
            }
            
            LogAudit($pdo, 'ADD_PAYMENT', 'payments', $new_payment_id, null, ['amount' => $amount, 'invoice' => $invoice['invoice_number']]);

            $pdo->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => "Bixinta $$amount waa lagu guulaystay biilka {$invoice['invoice_number']}!",
                'new_paid' => $new_paid_amount,
                'new_status' => $new_status,
                'due_remaining' => $invoice['total_amount'] - $new_paid_amount
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'check_tenant_complete') {
        $tenant_id = !empty($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : 0;
        
        if (!$tenant_id) {
            echo json_encode(['success' => false, 'complete' => false, 'message' => 'Fadlan dooro shirkad']);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? AND is_active = 1");
        $stmt->execute([$tenant_id]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tenant) {
            echo json_encode(['success' => false, 'complete' => false, 'message' => 'Shirkaddan ma jirto ama maaha mid firfircoon']);
            exit;
        }
        
        // Auto-fix missing code
        if (empty($tenant['code'])) {
            $newCode = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $tenant['name']), 0, 3));
            if (empty($newCode)) $newCode = 'INV';
            $upd = $pdo->prepare("UPDATE tenants SET code = ? WHERE id = ?");
            $upd->execute([$newCode, $tenant_id]);
            $tenant['code'] = $newCode;
        }

        // Auto-fix missing address (minimal)
        if (empty($tenant['address'])) {
            $upd = $pdo->prepare("UPDATE tenants SET address = 'Mogadishu, Somalia' WHERE id = ?");
            $upd->execute([$tenant_id]);
            $tenant['address'] = 'Mogadishu, Somalia';
        }

        // Auto-initialize sequence if missing
        $seqStmt = $pdo->prepare("SELECT * FROM tenant_sequences WHERE tenant_id = ? AND sequence_name = 'invoice'");
        $seqStmt->execute([$tenant_id]);
        $sequence = $seqStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sequence) {
            $prefix = $tenant['code'] ?: 'INV';
            $ins = $pdo->prepare("INSERT INTO tenant_sequences (tenant_id, sequence_name, prefix, current_number, padding) VALUES (?, 'invoice', ?, 1, 5)");
            $ins->execute([$tenant_id, $prefix]);
            
            // Re-fetch
            $seqStmt->execute([$tenant_id]);
            $sequence = $seqStmt->fetch(PDO::FETCH_ASSOC);
        }
        
        echo json_encode([
            'success' => true, 
            'complete' => true, 
            'message' => 'Shirkaddu waa diyaar', 
            'tenant' => $tenant, 
            'sequence' => $sequence
        ]);
        exit;
    }
    
    elseif ($action === 'save_invoice') {
        $id = $_POST['invoice_id'] ?? '';
        $tenant_id = !empty($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : null;
        $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $trip_id = !empty($_POST['trip_id']) ? (int)$_POST['trip_id'] : null;
        $invoice_number = trim($_POST['invoice_number'] ?? '');
        $invoice_date = $_POST['invoice_date'] ?? date('Y-m-d');
        $due_date = $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
        
        $subtotal = (float)($_POST['subtotal'] ?? 0);
        $commission_amount = (float)($_POST['commission_amount'] ?? 0);
        $trucking_cost = (float)($_POST['trucking_cost'] ?? 0);
        $handling_cost = (float)($_POST['handling_cost'] ?? 0);
        $tax_rate = (float)($_POST['tax_rate'] ?? 0);
        $discount = (float)($_POST['discount'] ?? 0);
        $discount_type = $_POST['discount_type'] ?? 'fixed';
        $notes = trim($_POST['notes'] ?? '');
        $total_cbm = (float)($_POST['total_cbm'] ?? 0);

        // Sum up line items for true subtotal
        $items_total = 0;
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $itemQtys = $_POST['qtys'] ?? [];
            $itemRates = $_POST['rates'] ?? [];
            for ($i = 0; $i < count($_POST['items']); $i++) {
                $items_total += (float)($itemQtys[$i] ?? 0) * (float)($itemRates[$i] ?? 0);
            }
        }
        
        $base_total = $items_total + $subtotal + $commission_amount + $trucking_cost + $handling_cost;
        $tax_amount = $base_total * ($tax_rate / 100);
        $discount_amount = ($discount_type === 'percentage') ? $base_total * ($discount / 100) : $discount;
        $total_amount = $base_total + $tax_amount - $discount_amount;
        
        if (empty($tenant_id)) {
            echo json_encode(['success' => false, 'message' => 'Fadlan dooro shirkad']);
            exit;
        }

        if (empty($customer_id)) {
            echo json_encode(['success' => false, 'message' => 'Fadlan dooro macaamil']);
            exit;
        }

        // Super Admin tenant-scope guard: the invoice's tenant must match the
        // selected scope, and the customer must belong to that tenant.
        $sa_scope = function_exists('sa_selected_tenant_id_int') ? sa_selected_tenant_id_int() : 0;
        if ($sa_scope > 0 && (int)$tenant_id !== $sa_scope) {
            echo json_encode(['success' => false, 'message' => 'Selected tenant does not match the active scope.']);
            exit;
        }
        $custStmt = $pdo->prepare("SELECT tenant_id FROM customers WHERE id = ?");
        $custStmt->execute([$customer_id]);
        $custTenant = $custStmt->fetchColumn();
        if (!$custTenant || (int)$custTenant !== (int)$tenant_id) {
            echo json_encode(['success' => false, 'message' => 'Customer does not belong to the selected tenant.']);
            exit;
        }

        if (empty($invoice_number)) {
            $invoice_number = generateInvoiceNumberSafe($pdo, $tenant_id);
        }

        try {
            $pdo->beginTransaction();
            if (empty($id)) {
                // Check if number already exists
                $check = $pdo->prepare("SELECT id FROM invoices WHERE invoice_number = ? AND tenant_id = ?");
                $check->execute([$invoice_number, $tenant_id]);
                if ($check->fetch()) {
                    $invoice_number .= '-' . rand(10, 99);
                }

                $sql = "INSERT INTO invoices (tenant_id, customer_id, trip_id, invoice_number, invoice_date, due_date, 
                        subtotal, commission_amount, trucking_cost, handling_cost, tax, tax_rate, discount, discount_type, total_amount, paid_amount, total_cbm, notes, status, created_by, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 'unpaid', ?, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$tenant_id, $customer_id, $trip_id, $invoice_number, $invoice_date, $due_date,
                               $base_total, $commission_amount, $trucking_cost, $handling_cost, $tax_amount, $tax_rate, $discount, $discount_type, $total_amount, $total_cbm, $notes, $_SESSION['user_id']]);
                
                $id = $pdo->lastInsertId();

                // ERP Integration
                if (class_exists('AccountingService')) {
                    $accounting = new AccountingService($pdo, $tenant_id, $_SESSION['user_id']);
                    $accounting->journalizeInvoice($id);
                }

                // Update customer debt
                $updateDebt = $pdo->prepare("UPDATE customers SET debt_amount = debt_amount + ?, updated_at = NOW() WHERE id = ?");
                $updateDebt->execute([$total_amount, $customer_id]);
                
                $message = "Biilka '$invoice_number' waa la sameeyay!";
            } else {
                // Fetch old amount to calculate difference
                $oldInvStmt = $pdo->prepare("SELECT total_amount FROM invoices WHERE id = ?");
                $oldInvStmt->execute([$id]);
                $oldInv = $oldInvStmt->fetch();
                $diff = $total_amount - ($oldInv['total_amount'] ?? 0);
                
                $sql = "UPDATE invoices SET tenant_id = ?, customer_id = ?, trip_id = ?, invoice_number = ?, invoice_date = ?, due_date = ?,
                        subtotal = ?, commission_amount = ?, trucking_cost = ?, handling_cost = ?, tax = ?, tax_rate = ?, discount = ?, discount_type = ?, total_amount = ?, total_cbm = ?, notes = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$tenant_id, $customer_id, $trip_id, $invoice_number, $invoice_date, $due_date,
                               $base_total, $commission_amount, $trucking_cost, $handling_cost, $tax_amount, $tax_rate, $discount, $discount_type, $total_amount, $total_cbm, $notes, $id]);
                
                // Update customer debt with difference
                $updateDebt = $pdo->prepare("UPDATE customers SET debt_amount = debt_amount + ?, updated_at = NOW() WHERE id = ?");
                $updateDebt->execute([$diff, $customer_id]);

                // Clear old items for this invoice
                $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$id]);
                
                $message = "Biilka '$invoice_number' waa la cusboonaysiiyay!";
            }

            // Save line items (Shared for both insert and update)
            if (isset($_POST['items']) && is_array($_POST['items'])) {
                $itemNames = $_POST['items'];
                $itemDescs = $_POST['descriptions'] ?? [];
                $itemQtys = $_POST['qtys'] ?? [];
                $itemRates = $_POST['rates'] ?? [];

                $itemStmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_name, description, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
                for ($i = 0; $i < count($itemNames); $i++) {
                    $name = trim($itemNames[$i]);
                    if (empty($name)) continue;
                    $qty = (float)($itemQtys[$i] ?? 0);
                    $rate = (float)($itemRates[$i] ?? 0);
                    $itemStmt->execute([$id, $name, ($itemDescs[$i] ?? ''), $qty, $rate, ($qty * $rate)]);
                }
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => $message]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'delete_invoice') {
        $id = $_POST['id'] ?? 0;
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT invoice_number, customer_id, tenant_id, total_amount, paid_amount FROM invoices WHERE id = ?");
            $stmt->execute([$id]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invoice) {
                echo json_encode(['success' => false, 'message' => 'Biilka lama helin']);
                exit;
            }

            // Update customer debt (Decrease by remaining due amount)
            $debtToReduce = $invoice['total_amount'] - $invoice['paid_amount'];
            $updateDebt = $pdo->prepare("UPDATE customers SET debt_amount = debt_amount - ?, updated_at = NOW() WHERE id = ?");
            $updateDebt->execute([$debtToReduce, $invoice['customer_id']]);
            
            $deleteLogs = $pdo->prepare("DELETE FROM debt_collection_log WHERE invoice_id = ?");
            $deleteLogs->execute([$id]);
            
            $deleteFollowups = $pdo->prepare("DELETE FROM debt_follow_ups WHERE invoice_id = ?");
            $deleteFollowups->execute([$id]);
            
            $deleteAlerts = $pdo->prepare("DELETE FROM overdue_alerts WHERE invoice_id = ?");
            $deleteAlerts->execute([$id]);
            
            $deleteReceipts = $pdo->prepare("DELETE FROM receipts WHERE invoice_id = ?");
            $deleteReceipts->execute([$id]);
            
            $deletePayments = $pdo->prepare("DELETE FROM payments WHERE invoice_id = ?");
            $deletePayments->execute([$id]);
            
            $deleteItems = $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
            $deleteItems->execute([$id]);
            
            $stmt = $pdo->prepare("DELETE FROM invoices WHERE id = ?");
            $stmt->execute([$id]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => "Biilka '{$invoice['invoice_number']}' waa la tirtiray!"]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'get_stats') {
        $tenant_filter = isset($_POST['tenant']) ? (int)$_POST['tenant'] : sa_selected_tenant_id_int();
        $where = $tenant_filter > 0 ? "WHERE tenant_id = $tenant_filter" : "";
        
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_invoices,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END) as unpaid_count,
                SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial_count,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_count,
                SUM(total_amount) as total_amount,
                SUM(paid_amount) as total_paid,
                SUM(total_amount - paid_amount) as total_due
            FROM invoices
            $where
        ");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $monthly = $pdo->query("
            SELECT DATE_FORMAT(invoice_date, '%Y-%m') as month, COUNT(*) as count, SUM(total_amount) as total
            FROM invoices $where GROUP BY DATE_FORMAT(invoice_date, '%Y-%m') ORDER BY month DESC LIMIT 6
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        ob_clean();
        echo json_encode(['stats' => $stats, 'monthly' => $monthly]);
        exit;
    }
    
    elseif ($action === 'generate_invoice_number') {
        $tenant_id = !empty($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : null;

        try {
            $invoice_number = generateInvoiceNumberSafe($pdo, $tenant_id);
            echo json_encode(['success' => true, 'invoice_number' => $invoice_number]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Invoice number lama generate-gareyn: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'get_trip_details') {
        $trip_id = (int)($_POST['trip_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, trip_number, total_cbm FROM trucking_trips WHERE id = ?");
        $stmt->execute([$trip_id]);
        $trip = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($trip);
        exit;
    }
    
    elseif ($action === 'get_customer_stock_total') {
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        if ($customer_id > 0) {
            $stmt = $pdo->prepare("SELECT SUM(quantity * unit_price) as total_stock_value, SUM(volume_cbm) as total_cbm FROM warehouse_stock WHERE customer_id = ?");
            $stmt->execute([$customer_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode([
                'success' => true, 
                'total_value' => (float)$result['total_stock_value'],
                'total_cbm' => (float)$result['total_cbm']
            ]);
        } else {
            echo json_encode(['success' => false, 'total_value' => 0, 'total_cbm' => 0]);
        }
        exit;
    }

    elseif ($action === 'get_tenant_customers') {
        $tenant_id = (int)($_POST['tenant_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, customer_name, phone FROM customers WHERE tenant_id = ? AND is_active = 1 ORDER BY customer_name");
        $stmt->execute([$tenant_id]);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($customers);
        exit;
    }
    
    elseif ($action === 'get_tenant_trips') {
        $tenant_id = (int)($_POST['tenant_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, trip_number FROM trucking_trips WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 100");
        $stmt->execute([$tenant_id]);
        $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($trips);
        exit;
    }

    elseif ($action === 'get_invoices_by_customer') {
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $tenant_check = ($role === 'company_admin') ? "AND tenant_id = $session_tenant_id" : "";
        $stmt = $pdo->prepare("SELECT id, invoice_number, (total_amount - paid_amount) as due_amount FROM invoices WHERE customer_id = ? $tenant_check AND status IN ('unpaid', 'partial') ORDER BY created_at DESC");
        $stmt->execute([$customer_id]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['invoices' => $invoices]);
        exit;
    }
    
    elseif ($action === 'send_whatsapp_api') {
        $phone = $_POST['phone'] ?? '';
        $message = $_POST['message'] ?? '';
        
        if (empty($phone) || empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Lambar iyo fariin waa loo baahan yahay!']);
            exit;
        }

        $messaging = new MessagingService($pdo);
        $result = $messaging->sendWhatsApp($phone, $message);
        echo json_encode($result);
        exit;
    }
    
    elseif ($action === 'import_invoices') {
        if (!isset($_FILES['excel_file'])) {
            echo json_encode(['success' => false, 'message' => 'Fayl lama dooran!']);
            exit;
        }
        
        $file = $_FILES['excel_file']['tmp_name'];
        $handle = fopen($file, "r");
        fgetcsv($handle); // Skip header
        
        $imported = 0;
        $errors = [];
        $line = 1;
        
        try {
            $pdo->beginTransaction();
            
            // Pre-fetch tenants
            $tenants_map = [];
            $stmt = $pdo->query("SELECT id, name FROM tenants");
            while ($t = $stmt->fetch()) {
                $tenants_map[strtolower($t['name'])] = $t['id'];
            }
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $line++;
                // Columns: Tenant Name, Customer Name, Invoice Number, Invoice Date, Due Date, Subtotal, Tax Rate, Discount, Notes
                $tenant_name = trim($data[0] ?? '');
                $customer_name = trim($data[1] ?? '');
                $invoice_number = trim($data[2] ?? '');
                $invoice_date = trim($data[3] ?? date('Y-m-d'));
                $due_date = trim($data[4] ?? date('Y-m-d', strtotime('+30 days')));
                $subtotal = (float)(str_replace(['$', ','], '', $data[5] ?? 0));
                $tax_rate = (float)(str_replace(['%', ','], '', $data[6] ?? 0));
                $discount = (float)(str_replace(['$', ','], '', $data[7] ?? 0));
                $notes = trim($data[8] ?? '');
                
                if (empty($tenant_name) || empty($customer_name) || empty($invoice_number)) continue;
                
                $t_id = $tenants_map[strtolower($tenant_name)] ?? null;
                if (!$t_id) {
                    $errors[] = "Line $line: Tenant '$tenant_name' not found.";
                    continue;
                }
                
                // Find or create customer for this tenant
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE tenant_id = ? AND LOWER(customer_name) = ?");
                $stmt->execute([$t_id, strtolower($customer_name)]);
                $customer_id = $stmt->fetchColumn();
                
                if (!$customer_id) {
                    $stmt = $pdo->prepare("INSERT INTO customers (tenant_id, customer_name, is_active, created_at) VALUES (?, ?, 1, NOW())");
                    $stmt->execute([$t_id, $customer_name]);
                    $customer_id = $pdo->lastInsertId();
                }
                
                $tax_amount = $subtotal * ($tax_rate / 100);
                $total_amount = $subtotal + $tax_amount - $discount;
                
                // Check for duplicate
                $stmt = $pdo->prepare("SELECT id FROM invoices WHERE tenant_id = ? AND invoice_number = ?");
                $stmt->execute([$t_id, $invoice_number]);
                if ($stmt->fetch()) {
                    $errors[] = "Line $line: Invoice #$invoice_number already exists for tenant '$tenant_name'.";
                    continue;
                }
                
                $stmt = $pdo->prepare("INSERT INTO invoices (tenant_id, customer_id, invoice_number, invoice_date, due_date, subtotal, tax_rate, tax, discount, total_amount, notes, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,'unpaid',?,NOW())");
                $stmt->execute([$t_id, $customer_id, $invoice_number, $invoice_date, $due_date, $subtotal, $tax_rate, $tax_amount, $discount, $total_amount, $notes, $user_id]);
                
                // Update customer debt
                $stmt = $pdo->prepare("UPDATE customers SET debt_amount = debt_amount + ? WHERE id = ?");
                $stmt->execute([$total_amount, $customer_id]);
                
                $imported++;
            }
            
            $pdo->commit();
            $msg = "Import-ka waa lagu guulaystay! ($imported biil).";
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

$templateStmt = $pdo->prepare("SELECT message_content FROM message_templates WHERE template_key = 'invoice_new'");
$templateStmt->execute();
$invoiceTemplate = $templateStmt->fetchColumn();
if (!$invoiceTemplate) {
    $invoiceTemplate = 'Macaamiil {customer_name},

Halkani waa xasuusin ku saabsan xisaabtaada shirkadda *{tenant}*.

*Faahfaahinta Biilka Hadda:*
- Lambarka Biilka: #{invoice_number}
- Lacagta Harsan: *${due}*
- Wadarta Biilka: ${amount}

*Soo-koobidda Xisaabtaada (Guud ahaan):*
- Wadarta Biilasha: ${total_invoiced}
- Wadarta Lacagta aad bixisay: ${total_paid}
- Haraaga laguugu leeyahay (Balance): *${total_debt}*

Fadlan waxaan kaa codsanaynaa inaad bixiso lacagta laguugu leeyahay sida ugu dhakhsaha badan. Mahadsanid.

*Link-ga Biilka:* {link}

Salaan sharaf leh,
*{tenant}*';
}

require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maareynta Biilasha - Super Admin | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --curdun-violet: #2D1859;
            --curdun-yellow: #F5C410;
            --curdun-violet-light: #4B2C85;
            --curdun-yellow-dark: #e0e1e6;
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
        .btn-primary-custom { background: var(--curdun-violet); color: white; border: none; padding: 10px 20px; border-radius: 20px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s ease; cursor: pointer; }
        .btn-primary-custom:hover { background: var(--curdun-violet-light); color: white; transform: translateY(-1px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: white; border: 1px solid #e0e1e6; border-radius: 8px; padding: 20px; display: flex; flex-direction: column; box-shadow: 0 1px 3px rgba(0,0,0,0.02); transition: box-shadow 0.2s; }
        .stat-card:hover { box-shadow: 0 4px 8px rgba(0,0,0,0.05); }
        .stat-card .stat-info h4 { font-size: 13px; color: var(--curdun-gray); margin: 0 0 10px 0; font-weight: 600; text-transform: uppercase; }
        .stat-card .stat-info .stat-number { font-size: 28px; font-weight: 700; color: var(--curdun-dark); margin-bottom: 10px; }
        .stat-card .stat-icon { display: none; }
        .stat-card-danger { border-top: 4px solid #B42318; }
        .stat-card-success { border-top: 4px solid #2ca01c; }
        .filters-card { background: white; border: 1px solid #e0e1e6; border-radius: 8px; padding: 20px; margin-bottom: 25px; }
        .filter-form { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 150px; }
        .filter-group label { display: block; font-size: 13px; font-weight: 600; color: var(--curdun-dark); margin-bottom: 6px; }
        .filter-group input, .filter-group select { width: 100%; padding: 10px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; transition: border-color 0.2s; }
        .filter-group input:focus, .filter-group select:focus { border-color: var(--curdun-violet); outline: none; }
        .btn-filter { background: white; color: var(--curdun-dark); border: 1px solid #ccc; padding: 10px 20px; border-radius: 20px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-filter:hover { background: #f4f5f8; }
        .btn-reset { background: white; color: var(--curdun-info); border: none; padding: 10px 20px; font-weight: 600; cursor: pointer; }
        .btn-reset:hover { text-decoration: underline; }
        .invoices-table-container { background: white; border: 1px solid #e0e1e6; border-radius: 8px; overflow-x: auto; width: 100%; }
        .invoices-table { width: 100%; border-collapse: collapse; min-width: 1300px; }
        .invoices-table th, .invoices-table td { padding: 15px 20px; text-align: left; border-bottom: 1px solid #e0e1e6; vertical-align: middle; }
        .invoices-table th { background: white; font-weight: 600; color: var(--curdun-gray); font-size: 13px; white-space: nowrap; }
        .invoices-table tr:hover { background: #f9f9fb; }
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .status-paid { background: #EEFBF3; color: #0F7A3A; }
        .status-unpaid { background: #f4f5f8; color: #393a3d; }
        .status-partial { background: #e3f2fd; color: #0077c5; }
        .status-overdue { background: #fce8e6; color: #B42318; }
        .action-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        .action-btn { padding: 5px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; border: none; transition: 0.3s; margin-right: 2px; }
        .btn-view { background: #e3f2fd; color: #1565c0; }
        .btn-edit { background: #fff8e1; color: #f57c00; }
        .btn-payment { background: #EEFBF3; color: #0F7A3A; }
        .btn-print { background: #f5f5f5; color: #424242; }
        .btn-delete { background: #FEF0EE; color: #B42318; }
        .btn-whatsapp { background: #EEFBF3; color: #25D366; }
        .btn-view:hover, .btn-edit:hover, .btn-payment:hover, .btn-print:hover, .btn-delete:hover, .btn-whatsapp:hover { transform: translateY(-2px); box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .alert { padding: 15px 20px; border-radius: 4px; position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideIn 0.3s ease; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .alert-success { background: #EEFBF3; color: #0F7A3A; border-left: 4px solid #0F7A3A; }
        .alert-error { background: #fce8e6; color: #B42318; border-left: 4px solid #B42318; }
        .modal-header { background: var(--curdun-violet); color: white; border-bottom: none; border-radius: 8px 8px 0 0; }
        .modal-header .close { color: white; opacity: 0.8; text-shadow: none; }
        .modal-header .close:hover { opacity: 1; }
        .modal-body { padding: 30px; }
        .form-control:focus { border-color: var(--curdun-violet); box-shadow: 0 0 0 0.2rem rgba(45, 24, 89, 0.1); }
        .btn-save-invoice { background: var(--curdun-yellow); color: #000; border: none; font-weight: 700; padding: 12px 30px; border-radius: 8px; transition: 0.3s; }
        .btn-save-invoice:hover { background: #e5cf07; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .line-item-table th { background: #f8f9fc; color: var(--curdun-violet); font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        .line-item-table td { padding: 8px; }
        .remove-line { color: var(--curdun-danger); cursor: pointer; transition: 0.2s; }
        .remove-line:hover { color: #b71c1c; transform: scale(1.1); }
        .add-line-btn { background: #f8f9fc; color: var(--curdun-violet); border: 1px dashed var(--curdun-violet); border-radius: 8px; padding: 8px 15px; font-weight: 600; font-size: 13px; margin-top: 10px; transition: 0.3s; }
        .add-line-btn:hover { background: var(--curdun-violet); color: white; border-style: solid; }
        .invoice-summary-box { background: #f8f9fc; border-radius: 12px; padding: 20px; border: 1px solid #e3e6f0; }
        .invoice-total-row { font-size: 24px; font-weight: 800; color: var(--curdun-violet); margin-top: 10px; }
        .loading-spinner { text-align: center; padding: 50px; }
        .loading-spinner i { font-size: 48px; color: var(--curdun-violet); animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 25px; flex-wrap: wrap; }
        .pagination a, .pagination span { padding: 8px 12px; border-radius: 4px; text-decoration: none; color: var(--curdun-dark); background: white; border: 1px solid #ccc; cursor: pointer; font-size: 14px; }
        .pagination .active { background: var(--curdun-info); color: white; border-color: var(--curdun-info); }
        .chart-container { background: white; border: 1px solid #e0e1e6; border-radius: 8px; padding: 20px; margin-bottom: 25px; }
        .chart-title { font-size: 16px; font-weight: 600; color: var(--curdun-dark); margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #e0e1e6; }
        .auto-number-badge { background: #f4f5f8; color: var(--curdun-dark); padding: 8px 12px; border-radius: 4px; font-size: 14px; display: inline-block; margin-bottom: 15px; border: 1px solid #e0e1e6; }
        .tenant-warning { background: #fce8e6; border-left: 4px solid #B42318; padding: 12px 15px; border-radius: 4px; margin-bottom: 15px; color: #B42318; }
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
        <h1><i class="fas fa-file-invoice"></i> Maareynta Biilasha</h1>
        <div class="d-flex gap-3 align-items-center">
            <button type="button" class="btn-primary-custom" id="addInvoiceBtn"><i class="fas fa-plus-circle"></i> Biil Cusub</button>
            <div class="dropdown ml-2">
                <button class="btn btn-light dropdown-toggle" type="button" data-toggle="dropdown" style="border-radius: 20px; padding: 10px 15px; font-weight: 600; border: 1px solid #babec5;">
                    <i class="fas fa-file-csv"></i> CSV
                </button>
                <div class="dropdown-menu dropdown-menu-right" style="border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.1);">
                    <a class="dropdown-item" href="?action=export_invoices" id="exportInvoicesBtn"><i class="fas fa-download mr-2"></i> Export Invoices</a>
                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#importModal"><i class="fas fa-upload mr-2"></i> Import Invoices</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="?action=download_sample"><i class="fas fa-file-download mr-2"></i> Download Sample</a>
                </div>
            </div>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-info"><h4>Wadarta Biilasha</h4><div class="stat-number" id="stat-total">0</div></div><div class="stat-icon"><i class="fas fa-file-invoice"></i></div></div>
        <div class="stat-card stat-card-success"><div class="stat-info"><h4>La Bixiyay</h4><div class="stat-number" id="stat-paid">0</div></div><div class="stat-icon"><i class="fas fa-check-circle"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Aan La Bixin</h4><div class="stat-number" id="stat-unpaid">0</div></div><div class="stat-icon"><i class="fas fa-clock"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Qayb ahaan</h4><div class="stat-number" id="stat-partial">0</div></div><div class="stat-icon"><i class="fas fa-chart-pie"></i></div></div>
        <div class="stat-card stat-card-danger"><div class="stat-info"><h4>Ka Dambay</h4><div class="stat-number" id="stat-overdue">0</div></div><div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Wadarta Lacagta</h4><div class="stat-number" id="stat-total-amount">$0</div></div><div class="stat-icon"><i class="fas fa-dollar-sign"></i></div></div>
    </div>

    <div class="chart-container"><div class="chart-title"><i class="fas fa-chart-bar"></i> Biilasha Bil kasta</div><canvas id="monthlyChart" height="200"></canvas></div>

    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group"><label><i class="fas fa-search"></i> Raadin</label><input type="text" id="searchInput" placeholder="Lambarka Biilka, Macaamilka..."></div>
            <div class="filter-group"><label><i class="fas fa-building"></i> Shirkadda</label><select id="tenantFilter"><option value="0">Dhammaan</option><?php foreach ($tenants as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?></select></div>
            <div class="filter-group"><label><i class="fas fa-user"></i> Macaamilka</label><select id="customerFilter"><option value="0">Dhammaan</option><?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['customer_name']) ?></option><?php endforeach; ?></select></div>
            <div class="filter-group"><label><i class="fas fa-tag"></i> Xaaladda</label><select id="statusFilter"><option value="all">Dhammaan</option><option value="paid">La Bixiyay</option><option value="unpaid">Aan La Bixin</option><option value="partial">Qayb ahaan</option><option value="overdue">Ka Dambay</option></select></div>
            <div class="filter-group"><label><i class="fas fa-calendar"></i> Laga bilaabo</label><input type="date" id="dateFrom"></div>
            <div class="filter-group"><label><i class="fas fa-calendar"></i> Ila</label><input type="date" id="dateTo"></div>
            <div class="filter-group"><button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Shaandheey</button><button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Nadiifi</button></div>
        </div>
    </div>

    <div id="invoices-table-container"><div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i><p>Loading invoices...</p></div></div>
    <div id="pagination-container"></div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 8px;">
            <div class="modal-header">
                <h5 class="modal-title" style="color: white;"><i class="fas fa-file-import"></i> Soo geli Biilal (CSV)</h5>
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
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button>
                    <button type="submit" class="btn" style="background: var(--curdun-violet); color: white;">Soo geli (Import)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Invoice Modal -->
<div class="modal fade" id="invoiceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="invoiceModalLabel"><i class="fas fa-file-invoice"></i> Biil Cusub</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
            <form id="invoiceForm"><div class="modal-body">
                <input type="hidden" name="invoice_id" id="invoice_id">
                <div id="tenantValidationWarning" class="tenant-warning" style="display: none;"><i class="fas fa-exclamation-triangle"></i> <span id="tenantWarningMessage"></span></div>
                <div class="alert alert-info auto-number-badge"><i class="fas fa-magic"></i> Lambarka Biilka: <strong id="autoInvoiceNumber">-</strong><input type="hidden" name="invoice_number" id="modalInvoiceNumber" value=""></div>
                <div class="row">
                    <div class="col-md-6"><div class="form-group"><label>Shirkadda <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <select name="tenant_id" id="modalTenantId" class="form-control" required><option value="">-- Dooro Shirkad --</option><?php foreach ($tenants as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?></select>
                            <div class="input-group-append"><button type="button" class="btn btn-outline-secondary" id="quickAddTenantBtn" title="Add New Company">+</button></div>
                        </div>
                    </div></div>
                    <div class="col-md-6"><div class="form-group"><label>Macaamilka <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <select name="customer_id" id="modalCustomerId" class="form-control" required><option value="">-- Dooro Macaamil --</option><?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>" data-tenant-id="<?= $c['tenant_id'] ?>"><?= htmlspecialchars($c['customer_name']) ?> (<?= htmlspecialchars($c['phone']) ?>) - <?= htmlspecialchars($c['tenant_name'] ?? '') ?></option><?php endforeach; ?></select>
                            <div class="input-group-append"><button type="button" class="btn btn-outline-secondary" id="quickAddCustomerBtn" title="Add New Customer">+</button></div>
                        </div>
                    </div></div>
                    <div class="col-md-6"><div class="form-group"><label>Safarka (Ikhtiyaari)</label><select name="trip_id" id="modalTripId" class="form-control"><option value="">Dooro Safar...</option><?php foreach ($trips as $tr): ?><option value="<?= $tr['id'] ?>" data-tenant-id="<?= $tr['tenant_id'] ?>"><?= htmlspecialchars($tr['trip_number']) ?> (<?= $tr['total_cbm'] ?> CBM) - <?= htmlspecialchars($tr['tenant_name'] ?? '') ?></option><?php endforeach; ?></select></div></div>
                    <div class="col-md-6"><div class="form-group"><label>Taariikhda Biilka</label><input type="date" name="invoice_date" id="modalInvoiceDate" class="form-control" value="<?= date('Y-m-d') ?>"></div></div>
                    <div class="col-md-6"><div class="form-group"><label>Taariikhda Bixinta</label><input type="date" name="due_date" id="modalDueDate" class="form-control" value="<?= date('Y-m-d', strtotime('+30 days')) ?>"></div></div>
                    
                    <div class="col-md-12 mt-3">
                        <div class="section-title mb-2" style="font-size: 14px; font-weight: 700; color: var(--curdun-violet);">
                            <i class="fas fa-list"></i> Alaabta Biilka (Invoice Items)
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered line-item-table" id="lineItemTable">
                                <thead>
                                    <tr>
                                        <th style="width: 25%;">Alaabta (Item)</th>
                                        <th style="width: 35%;">Faahfaahin (Desc)</th>
                                        <th style="width: 10%;">Tirada (Qty)</th>
                                        <th style="width: 15%;">Qiimaha (Rate)</th>
                                        <th style="width: 15%;">Wadarta (Total)</th>
                                        <th style="width: 50px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="lineItemBody">
                                    <tr>
                                        <td><input type="text" name="items[]" class="form-control form-control-sm" placeholder="Macaamiilka alaabtiisa"></td>
                                        <td><input type="text" name="descriptions[]" class="form-control form-control-sm" placeholder="Description..."></td>
                                        <td><input type="number" name="qtys[]" class="form-control form-control-sm item-qty" value="1"></td>
                                        <td><input type="number" step="0.01" name="rates[]" class="form-control form-control-sm item-rate" value="0.00"></td>
                                        <td><input type="number" step="0.01" class="form-control form-control-sm item-amount" value="0.00"></td>
                                        <td class="text-center"><i class="fas fa-times remove-line"></i></td>
                                    </tr>
                                </tbody>
                            </table>
                            <button type="button" class="add-line-btn" id="addLineBtn"><i class="fas fa-plus"></i> Ku dar Saf Cusub (Add Item)</button>
                        </div>
                    </div>

                    <div class="col-md-12 mt-3">
                        <hr>
                        <div class="section-title mb-2" style="font-size: 14px; font-weight: 700; color: var(--curdun-violet);">
                            <i class="fas fa-plus-circle"></i> Khidmadaha Kale (Other Fees)
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group"><label>Lacagta CBM-ka (Freight)</label><input type="number" step="0.01" name="subtotal" id="modalSubtotal" class="form-control fee-input" value="0.00"></div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group"><label>Commision-ka Shirkada</label><input type="number" step="0.01" name="commission_amount" id="modalCommission" class="form-control fee-input" value="0.00"></div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group"><label>Qarashka Baabuurka</label><input type="number" step="0.01" name="trucking_cost" id="modalTrucking" class="form-control fee-input" value="0.00"></div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group"><label>Lacagta Xamaaliga</label><input type="number" step="0.01" name="handling_cost" id="modalHandling" class="form-control fee-input" value="0.00"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-7 mt-4">
                        <div class="form-group"><label>Memo (Xusuusin Gudaha Ah)</label><textarea name="notes" id="modalNotes" class="form-control" rows="3" placeholder="Tusaale: Multi-line invoice generated by System."></textarea></div>
                    </div>
                    
                    <div class="col-md-5 mt-4">
                        <div class="invoice-summary-box">
                            <div class="d-flex justify-content-between mb-2"><span>Subtotal (All Fees):</span><strong id="displaySubtotal">$0.00</strong></div>
                            <div class="d-flex justify-content-between mb-2"><span>Canshuur Tax (%):</span><input type="number" step="0.01" name="tax_rate" id="modalTaxRate" class="form-control form-control-sm text-right fee-input" style="width: 80px;" value="0"></div>
                            <div class="d-flex justify-content-between mb-2"><span>Discount:</span>
                                <div class="input-group input-group-sm" style="width: 150px;">
                                    <input type="number" step="0.01" name="discount" id="modalDiscount" class="form-control text-right fee-input" value="0">
                                    <select name="discount_type" id="modalDiscountType" class="form-control fee-input"><option value="fixed">$</option><option value="percentage">%</option></select>
                                </div>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between invoice-total-row"><span>Total ($):</span><span id="totalAmountDisplay">0.00</span></div>
                            <input type="hidden" name="total_amount" id="modalTotalAmount" value="0">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="submit" class="btn-save-invoice" id="saveInvoiceBtn">Save Invoice</button>
            </div>
        </form>
        </div>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-file-invoice"></i> Faahfaahinta Biilka</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body" id="viewModalBody"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button><button type="button" class="btn btn-primary" id="printInvoiceBtn"><i class="fas fa-print"></i> Daabac</button></div></div></div></div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="fas fa-money-bill-wave"></i> Ku Dar Bixin</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <form id="paymentForm"><div class="modal-body">
                <input type="hidden" name="invoice_id" id="paymentInvoiceId">
                <p>Biilka: <strong id="paymentInvoiceNumber"></strong></p>
                <p>Deynta: <strong id="paymentDueAmount">$0.00</strong></p>
                <div class="form-group"><label>Qadarka Bixinta <span class="text-danger">*</span></label><input type="number" step="0.01" name="amount" id="paymentAmount" class="form-control" required></div>
                <div class="form-group"><label>Taariikhda Bixinta</label><input type="date" name="payment_date" id="paymentDate" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                <div class="form-group"><label>Habka Bixinta</label><select name="payment_method" id="paymentMethod" class="form-control"><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option><option value="check">Check</option><option value="mobile_money">Mobile Money</option></select></div>
                <div class="form-group"><label>Lambarka Tixraaca</label><input type="text" name="reference_number" id="paymentReference" class="form-control" placeholder="Transaction ID, Check No."></div>
                <div class="form-group"><label>Qoraal</label><textarea name="notes" id="paymentNotes" class="form-control" rows="2"></textarea></div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success">Kaydi Bixinta</button></div></form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="fas fa-trash"></i> Xaqiiji Tirtirka</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <div class="modal-body">
                <p>Ma hubtaa inaad rabto inaad tirtirto biilka <strong id="deleteInvoiceName"></strong>?</p>
                <p class="text-danger">Fal-kan lama soo celin karo! Dhammaan xogta la xiriirta (bixinta, qaadashada) waa la tirtiri doonaa.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" id="confirmDeleteBtn">Haa, Tirtir</button></div>
        </div>
    </div>
</div>

<!-- Quick Add Customer Modal -->
<div class="modal fade" id="quickAddCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="fas fa-user-plus"></i> Macaamil Cusub (Quick Add)</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <form id="quickAddCustomerForm"><div class="modal-body">
                <input type="hidden" name="tenant_id" id="quickCustomerTenantId">
                <div class="form-group"><label>Magaca Macaamilka <span class="text-danger">*</span></label><input type="text" name="customer_name" class="form-control" required></div>
                <div class="form-group"><label>Telefoonka <span class="text-danger">*</span></label><input type="text" name="phone" class="form-control" required></div>
                <div class="form-group"><label>Emailka (Ikhtiyaari)</label><input type="email" name="email" class="form-control"></div>
                <div class="form-group"><label>Cinwaanka</label><input type="text" name="address" class="form-control" value="Mogadishu"></div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button><button type="submit" class="btn btn-success">Badbaadi</button></div></form>
        </div>
    </div>
</div>

<!-- Quick Add Tenant Modal -->
<div class="modal fade" id="quickAddTenantModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-building"></i> Shirkad Cusub (Quick Add)</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <form id="quickAddTenantForm"><div class="modal-body">
                <div class="form-group"><label>Magaca Shirkadda <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" required></div>
                <div class="form-group"><label>Cinwaanka</label><input type="text" name="address" class="form-control" value="Mogadishu"></div>
                <div class="form-group"><label>Capacity (CBM)</label><input type="number" name="warehouse_capacity" class="form-control" value="1000"></div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button><button type="submit" class="btn btn-primary">Badbaadi</button></div></form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
// [csrf-shim] inline jQuery pages need the same ajaxSetup guard that
// includes/footer.php installs. Attach X-CSRF-Token to every same-origin
// mutation from this page.
(function () {
    var m = document.querySelector('meta[name="csrf-token"]');
    if (!m || !window.jQuery) return;
    var token = m.getAttribute('content') || '';
    jQuery.ajaxSetup({
        beforeSend: function (xhr, settings) {
            var method = (settings.type || 'GET').toUpperCase();
            if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') return;
            if (settings.crossDomain) return;
            xhr.setRequestHeader('X-CSRF-Token', token);
            if (settings.data instanceof FormData && !settings.data.has('csrf_token')) {
                settings.data.append('csrf_token', token);
            }
        }
    });

// [async-error-shim] Standardize AJAX failure handling so every finance
// page shows a controlled error instead of a permanent spinner. This
// runs after the jQuery.ajaxSetup shim above, so both live on the same
// jQuery instance.
(function () {
    if (!window.jQuery) return;
    if (window.__FIN_ASYNC_SHIM__) return;
    window.__FIN_ASYNC_SHIM__ = true;
    // Install an ajaxSend handler that marks the click-source button
    // with data-finance-pending. Fires once per shim install.
    if (!window.__FIN_SEND_MARK__) {
        window.__FIN_SEND_MARK__ = true;
        jQuery(document).on('ajaxSend', function (event, xhr, settings) {
            try {
                if (!settings || settings.crossDomain) return;
                var el = document.activeElement;
                if (!el) return;
                var tag = (el.tagName || '').toUpperCase();
                if (tag !== 'BUTTON' && !(tag === 'INPUT' && (el.type || '').toLowerCase() === 'submit')) return;
                var $el = jQuery(el);
                // If it isn't disabled at the moment ajax fires, the
                // caller isn't gating this button on the request, so
                // don't mark it.
                if (!$el.prop('disabled')) return;
                if ($el.attr('data-finance-pending') === '1') return;
                if ($el.attr('data-original-html') === undefined) {
                    $el.attr('data-original-html', $el.html());
                }
                $el.attr('data-finance-pending', '1');
            } catch (e) {}
        });
    }
    jQuery(document).ajaxError(function (event, xhr, settings, thrownError) {
        // Skip cross-domain or explicitly-suppressed calls.
        if (settings && settings.crossDomain) return;
        if (settings && settings.suppressGlobalError) return;
        var msg;
        try {
            var body = xhr && xhr.responseText ? xhr.responseText : '';
            var parsed = null;
            try { parsed = body ? JSON.parse(body) : null; } catch (e) {}
            if (parsed && parsed.message) msg = parsed.message;
            else if (xhr && xhr.status === 0) msg = 'Network error — request could not complete';
            else if (xhr && xhr.status === 403) msg = 'Not authorized (403)';
            else if (xhr && xhr.status === 404) msg = 'Endpoint not found (404)';
            else if (xhr && xhr.status >= 500) msg = 'Server error (' + xhr.status + ')';
            else msg = 'Request failed' + (xhr && xhr.status ? ' (' + xhr.status + ')' : '');
        } catch (e) {
            msg = 'Request failed';
        }
        // Try Bootstrap toast first if present; fall back to alert.
        try {
            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.toast) {
                var $c = jQuery('#toast-container');
                if (!$c.length) {
                    $c = jQuery('<div id="toast-container" style="position:fixed;top:20px;right:20px;z-index:99999;"></div>').appendTo('body');
                }
                var $t = jQuery('<div class="alert alert-danger" role="alert" style="min-width:280px;box-shadow:0 2px 8px rgba(0,0,0,.15);">' + jQuery('<div/>').text(msg).html() + '</div>');
                $c.append($t);
                setTimeout(function () { $t.fadeOut(400, function(){ jQuery(this).remove(); }); }, 5000);
                return;
            }
        } catch (e) {}
                // [async-error-shim-v3] Targeted UI-state recovery. Only restores
        // buttons that were explicitly marked at ajaxSend time with
        // data-finance-pending="1" and whose original HTML was captured
        // in data-original-html. Never touches other disabled controls —
        // tenant-validation locks, RBAC locks, workflow gates, and
        // missing-required-selection blockers all stay locked as
        // intended.
        try {
            jQuery('[data-finance-pending="1"]').each(function () {
                var $b = jQuery(this);
                var orig = $b.attr('data-original-html');
                if (orig !== undefined && orig !== null) $b.html(orig);
                $b.prop('disabled', false);
                $b.removeAttr('data-finance-pending');
                $b.removeAttr('data-original-html');
            });
        } catch (e) {}
        // Only alert once per 5-second window to prevent alert-storms.
        if (!window.__FIN_ALERT_LOCK__) {
            window.__FIN_ALERT_LOCK__ = true;
            try { window.alert(msg); } catch (e) {}
            setTimeout(function () { window.__FIN_ALERT_LOCK__ = false; }, 5000);
        }
    });
})();
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const invoiceTemplateStr = <?= json_encode($invoiceTemplate) ?>;

$(document).ready(function() {
    let currentPage = 1;
    let deleteId = null;
    let monthlyChart;
    let tenantValid = false;

    function calculateTotal() {
        let itemsSubtotal = 0;
        
        $('.item-amount').each(function() {
            itemsSubtotal += parseFloat($(this).val()) || 0;
        });

        const cbmFreight = parseFloat($('#modalSubtotal').val()) || 0;
        const comm = parseFloat($('#modalCommission').val()) || 0;
        const truck = parseFloat($('#modalTrucking').val()) || 0;
        const handle = parseFloat($('#modalHandling').val()) || 0;
        
        let subtotal = itemsSubtotal + cbmFreight + comm + truck + handle;

        const taxRate = parseFloat($('#modalTaxRate').val()) || 0;
        const discount = parseFloat($('#modalDiscount').val()) || 0;
        const discountType = $('#modalDiscountType').val();
        
        const tax = subtotal * (taxRate / 100);
        let discountAmount = discountType === 'percentage' ? subtotal * (discount / 100) : discount;
        const total = subtotal + tax - discountAmount;

        $('#displaySubtotal').text('$' + subtotal.toFixed(2));
        $('#totalAmountDisplay').text(total.toFixed(2));
        $('#modalTotalAmount').val(total.toFixed(2));
    }

    $(document).on('input', '.fee-input', calculateTotal);
    $(document).on('change', '.fee-input', calculateTotal);
    
    $('#addLineBtn').click(function() {
        const newRow = `
        <tr>
            <td><input type="text" name="items[]" class="form-control form-control-sm" placeholder="e.g. MRSU123456"></td>
            <td><input type="text" name="descriptions[]" class="form-control form-control-sm" placeholder="Description..."></td>
            <td><input type="number" name="qtys[]" class="form-control form-control-sm item-qty" value="1"></td>
            <td><input type="number" step="0.01" name="rates[]" class="form-control form-control-sm item-rate" value="0.00"></td>
            <td><input type="number" step="0.01" class="form-control form-control-sm item-amount" value="0.00"></td>
            <td class="text-center"><i class="fas fa-times remove-line"></i></td>
        </tr>`;
        $('#lineItemBody').append(newRow);
    });

    $(document).on('click', '.remove-line', function() {
        if ($('#lineItemBody tr').length > 1) {
            $(this).closest('tr').remove();
            calculateTotal();
        }
    });

    $(document).on('input', '.item-qty, .item-rate', function() {
        const row = $(this).closest('tr');
        const qty = parseFloat(row.find('.item-qty').val()) || 0;
        const rate = parseFloat(row.find('.item-rate').val()) || 0;
        const amount = qty * rate;
        row.find('.item-amount').val(amount.toFixed(2));
        calculateTotal();
    });

    $('#modalTaxRate, #modalDiscount, #modalDiscountType').on('input change', calculateTotal);
    
    function checkTenantComplete(tenantId) {
        if (!tenantId) { $('#tenantValidationWarning').hide(); $('#saveInvoiceBtn').prop('disabled', true); tenantValid = false; $('#autoInvoiceNumber').text('Fadlan dooro shirkad'); return; }
        $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'check_tenant_complete', tenant_id: tenantId }, dataType: 'json',
            success: function(res) {
                if (res.complete === true) { $('#tenantValidationWarning').hide(); $('#saveInvoiceBtn').prop('disabled', false); tenantValid = true; generateInvoiceNumber(tenantId); }
                else { $('#tenantWarningMessage').html(res.message); $('#tenantValidationWarning').show(); $('#saveInvoiceBtn').prop('disabled', true); tenantValid = false; $('#autoInvoiceNumber').text('Aan la samayn karin'); }
            },
            error: function() { $('#tenantWarningMessage').html('Khalad ayaa dhacay'); $('#tenantValidationWarning').show(); $('#saveInvoiceBtn').prop('disabled', true); tenantValid = false; }
        });
    }
    
    function generateInvoiceNumber(tenantId) {
        return new Promise(function(resolve, reject) {
            if (!tenantId) {
                reject('Fadlan dooro shirkad');
                return;
            }

            $('#autoInvoiceNumber').html('<i class="fas fa-spinner fa-spin"></i> Generating...');

            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    ajax_action: 'generate_invoice_number',
                    tenant_id: tenantId
                },
                dataType: 'json',
                success: function(res) {
                    if (res && res.success && res.invoice_number) {
                        $('#autoInvoiceNumber').text(res.invoice_number);
                        $('#modalInvoiceNumber').val(res.invoice_number);
                        resolve(res.invoice_number);
                    } else {
                        $('#autoInvoiceNumber').text('Error');
                        $('#modalInvoiceNumber').val('');
                        reject((res && res.message) ? res.message : 'Invoice number lama sameyn karo');
                    }
                },
                error: function(xhr) {
                    $('#autoInvoiceNumber').text('Error');
                    $('#modalInvoiceNumber').val('');
                    reject('Server error: invoice number lama generate-gareyn karo');
                }
            });
        });
    }
    
    $('#modalTenantId').on('change', function() {
        const tenantId = $(this).val();
        if (tenantId) {
            $('#modalCustomerId').html('<option value="">Raraya...</option>');
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_tenant_customers', tenant_id: tenantId },
                dataType: 'json',
                success: function(data) {
                    let html = '<option value="">-- Dooro Macaamil --</option>';
                    data.forEach(c => {
                        html += `<option value="${c.id}">${c.customer_name} (${c.phone})</option>`;
                    });
                    $('#modalCustomerId').html(html);
                }
            });

            $('#modalTripId').html('<option value="">Raraya...</option>');
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_tenant_trips', tenant_id: tenantId },
                dataType: 'json',
                success: function(data) {
                    let html = '<option value="">Dooro Safar...</option>';
                    data.forEach(t => {
                        html += `<option value="${t.id}">${t.trip_number}</option>`;
                    });
                    $('#modalTripId').html(html);
                }
            });

            checkTenantComplete(tenantId);
        } else { 
            $('#tenantValidationWarning').hide(); 
            $('#saveInvoiceBtn').prop('disabled', true); 
            tenantValid = false; 
            $('#autoInvoiceNumber').text('Fadlan dooro shirkad'); 
        }
    });
    
    $('#modalTripId').on('change', function() {
        const tripId = $(this).val();
        if (tripId) { $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'get_trip_details', trip_id: tripId }, dataType: 'json', success: function(trip) { if (trip) $('#modalTotalCbm').val(trip.total_cbm || 0); } }); }
    });

    function loadInvoices() {
        $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'get_invoices', page: currentPage, search: $('#searchInput').val(), tenant: $('#tenantFilter').val(), customer: $('#customerFilter').val(), status: $('#statusFilter').val(), date_from: $('#dateFrom').val(), date_to: $('#dateTo').val() }, dataType: 'json',
            success: function(response) { 
                $('#invoices-table-container').html(response.table_html); 
                $('#pagination-container').html(response.pagination_html); 
                attachTableEvents(); 
                
                // Update export link with current filters
                let search = $('#searchInput').val();
                let tenant = $('#tenantFilter').val();
                let status = $('#statusFilter').val();
                $('#exportInvoicesBtn').attr('href', `?action=export_invoices&search=${encodeURIComponent(search)}&tenant=${tenant}&status=${status}`);
            },
            error: function() { $('#invoices-table-container').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading data</p></div>'); }
        });
    }

    $('#importForm').submit(function(e) {
        e.preventDefault();
        let formData = new FormData(this);
        formData.append('ajax_action', 'import_invoices');
        
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
                    loadInvoices();
                    loadStats();
                    showAlert('success', res.message);
                    $('#importForm')[0].reset();
                } else {
                    showAlert('error', res.message);
                }
            },
            error: function() {
                showAlert('error', 'Khalad ayaa dhacay intii lagu guda jiray soo gelinta.');
            }
        });
    });

    function loadStats() {
        $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'get_stats', tenant: $('#tenantFilter').val() }, dataType: 'json',
            success: function(data) {
                const stats = data.stats;
                $('#stat-total').text(stats.total_invoices || 0);
                $('#stat-paid').text(stats.paid_count || 0);
                $('#stat-unpaid').text(stats.unpaid_count || 0);
                $('#stat-partial').text(stats.partial_count || 0);
                $('#stat-overdue').text(stats.overdue_count || 0);
                $('#stat-total-amount').text('$' + (parseFloat(stats.total_amount || 0).toFixed(2)));
                const monthly = data.monthly;
                if (monthlyChart) monthlyChart.destroy();
                monthlyChart = new Chart(document.getElementById('monthlyChart'), { type: 'bar', data: { labels: monthly.map(m => m.month), datasets: [{ label: 'Wadarta Biilasha ($)', data: monthly.map(m => parseFloat(m.total)), backgroundColor: '#2D1859', borderRadius: 8 }] }, options: { responsive: true, scales: { y: { beginAtZero: true } } } });
            }
        });
    }

    function getInvoiceHTML(inv) {
        const due = inv.total_amount - inv.paid_amount;
        const statusText = { 'paid': 'La Bixiyay', 'unpaid': 'Aan La Bixin', 'partial': 'Qayb ahaan', 'overdue': 'Ka Dambay' }[inv.status] || inv.status;
        const statusColor = inv.status === 'paid' ? '#0F7A3A' : (inv.status === 'unpaid' ? '#B42318' : '#0077c5');
        
        return `
        <div style="font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #393a3d; max-width: 800px; margin: 0 auto; background: white; border: 1px solid #e0e1e6; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden;">
            <div style="background: #2D1859; color: white; padding: 15px 20px;">
                <h3 style="margin: 0;">Cargo Management System</h3>
                <small>Invoice Details</small>
            </div>
            <div style="padding: 30px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 30px;">
                    <div><strong>Bill To:</strong><br>${escapeHtml(inv.customer_name || '-')}<br>${escapeHtml(inv.customer_phone || '')}</div>
                    <div style="text-align: right;"><strong>Invoice #:</strong> ${escapeHtml(inv.invoice_number)}<br><strong>Date:</strong> ${inv.invoice_date}<br><strong>Due Date:</strong> ${inv.due_date}</div>
                </div>
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
                    <thead><tr style="background: #f0f0f0;"><th style="padding: 10px; text-align: left;">Description</th><th style="padding: 10px; text-align: right;">Qty</th><th style="padding: 10px; text-align: right;">Rate</th><th style="padding: 10px; text-align: right;">Amount</th></tr></thead>
                    <tbody>
                        ${(inv.items && inv.items.length > 0) ? inv.items.map(item => `
                        <tr><td style="padding: 10px;">${escapeHtml(item.item_name)}<br><small>${escapeHtml(item.description || '')}</small></td><td style="padding: 10px; text-align: right;">${item.quantity}</td><td style="padding: 10px; text-align: right;">$${parseFloat(item.unit_price).toFixed(2)}</td><td style="padding: 10px; text-align: right;">$${parseFloat(item.total_price).toFixed(2)}</td></tr>
                        `).join('') : ''}
                        ${parseFloat(inv.subtotal || 0) > 0 ? `<tr><td colspan="3" style="padding: 10px; text-align: right;">Freight (CBM):</td><td style="padding: 10px; text-align: right;">$${parseFloat(inv.subtotal).toFixed(2)}</td></tr>` : ''}
                        ${parseFloat(inv.commission_amount || 0) > 0 ? `<tr><td colspan="3" style="padding: 10px; text-align: right;">Commission:</td><td style="padding: 10px; text-align: right;">$${parseFloat(inv.commission_amount).toFixed(2)}</td></tr>` : ''}
                        ${parseFloat(inv.trucking_cost || 0) > 0 ? `<tr><td colspan="3" style="padding: 10px; text-align: right;">Trucking:</td><td style="padding: 10px; text-align: right;">$${parseFloat(inv.trucking_cost).toFixed(2)}</td></tr>` : ''}
                        ${parseFloat(inv.handling_cost || 0) > 0 ? `<tr><td colspan="3" style="padding: 10px; text-align: right;">Handling:</td><td style="padding: 10px; text-align: right;">$${parseFloat(inv.handling_cost).toFixed(2)}</td></tr>` : ''}
                        ${parseFloat(inv.tax || 0) > 0 ? `<tr><td colspan="3" style="padding: 10px; text-align: right;">Tax (${inv.tax_rate}%):</td><td style="padding: 10px; text-align: right;">$${parseFloat(inv.tax).toFixed(2)}</td></tr>` : ''}
                        ${parseFloat(inv.discount || 0) > 0 ? `<tr><td colspan="3" style="padding: 10px; text-align: right;">Discount:</td><td style="padding: 10px; text-align: right;">-$${parseFloat(inv.discount).toFixed(2)}</td></tr>` : ''}
                        <tr style="border-top: 2px solid #ddd;"><td colspan="3" style="padding: 10px; text-align: right; font-weight: bold;">Total:</td><td style="padding: 10px; text-align: right; font-weight: bold;">$${parseFloat(inv.total_amount || 0).toFixed(2)}</td></tr>
                        <tr><td colspan="3" style="padding: 10px; text-align: right;">Paid:</td><td style="padding: 10px; text-align: right;">$${parseFloat(inv.paid_amount || 0).toFixed(2)}</td></tr>
                        <tr style="background: #EEFBF3;"><td colspan="3" style="padding: 10px; text-align: right; font-weight: bold;">Balance Due:</td><td style="padding: 10px; text-align: right; font-weight: bold;">$${due.toFixed(2)}</td></tr>
                    </tbody>
                </table>
                <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 12px;">
                    Thank you for your business! Generated by Cargo Management System System.
                </div>
            </div>
        </div>
        `;
    }

    function attachTableEvents() {
        $('.view-invoice').click(function() { const id = $(this).data('id'); $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'get_invoice', id: id }, dataType: 'json', success: function(inv) { $('#viewModalBody').html(getInvoiceHTML(inv)); $('#viewModal').modal('show'); } }); });
        
        $('.edit-invoice').click(function() { const id = $(this).data('id'); $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'get_invoice', id: id }, dataType: 'json', success: function(inv) { $('#invoiceModalLabel').text('Wax Ka Beddel Biilka'); $('#invoice_id').val(inv.id); $('#modalTenantId').val(inv.tenant_id).trigger('change'); setTimeout(() => { $('#modalCustomerId').val(inv.customer_id); $('#modalTripId').val(inv.trip_id); }, 500); $('#modalInvoiceDate').val(inv.invoice_date); $('#modalDueDate').val(inv.due_date); $('#modalSubtotal').val(inv.subtotal); $('#modalCommission').val(inv.commission_amount); $('#modalTrucking').val(inv.trucking_cost); $('#modalHandling').val(inv.handling_cost); $('#modalTaxRate').val(inv.tax_rate); $('#modalDiscount').val(inv.discount); $('#modalDiscountType').val(inv.discount_type); $('#modalNotes').val(inv.notes); $('#autoInvoiceNumber').text(inv.invoice_number); $('#modalInvoiceNumber').val(inv.invoice_number); calculateTotal(); $('#invoiceModal').modal('show'); } }); });
        
        $('.add-payment').click(function() { const id = $(this).data('id'); const number = $(this).data('number'); const dueAmount = $(this).data('due'); $('#paymentInvoiceId').val(id); $('#paymentInvoiceNumber').text(number); $('#paymentDueAmount').text('$' + dueAmount.toFixed(2)); $('#paymentAmount').val(''); $('#paymentAmount').attr('max', dueAmount); $('#paymentModal').modal('show'); });
        
        $('.whatsapp-invoice').click(function() {
            const btn = $(this);
            const number = btn.data('number');
            const phone = btn.data('phone');
            const amount = btn.data('amount');
            const due = btn.data('due');
            const totalDebt = btn.data('total-debt');
            const totalInvoiced = btn.data('total-invoiced');
            const totalPaidAll = btn.data('total-paid-all');
            const name = btn.data('name');
            const tenant = btn.data('tenant');
            const link = `${window.location.origin}${window.location.pathname.split('/superadmin/')[0]}/public_invoice.php?number=${number}`;
            
            let message = invoiceTemplateStr
                .replace(/{customer_name}/g, name)
                .replace(/{invoice_number}/g, number)
                .replace(/{amount}/g, amount)
                .replace(/{due}/g, due)
                .replace(/{total_invoiced}/g, totalInvoiced)
                .replace(/{total_paid}/g, totalPaidAll)
                .replace(/{total_debt}/g, totalDebt)
                .replace(/{tenant}/g, tenant)
                .replace(/{link}/g, link);
            
            let formattedPhone = phone.toString().replace(/\D/g, '');
            if (formattedPhone.length === 9 && (formattedPhone.startsWith('6') || formattedPhone.startsWith('7'))) {
                formattedPhone = '252' + formattedPhone;
            }
            
            if (!formattedPhone) {
                alert('Macaamilkan ma lahan lambar telefoon oo sax ah!');
                return;
            }
            
            const url = `https://api.whatsapp.com/send?phone=${formattedPhone}&text=${encodeURIComponent(message)}`;
            window.open(url, '_blank');
        });

        $('.print-invoice').click(function() { const id = $(this).data('id'); $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'get_invoice', id: id }, dataType: 'json', success: function(inv) { const w = window.open('', '_blank'); w.document.write('<html><head><title>Biilka ' + escapeHtml(inv.invoice_number) + '</title></head><body style="margin:0;padding:20px;">' + getInvoiceHTML(inv) + '</body></html>'); w.document.close(); setTimeout(function() { w.print(); }, 300); } }); });
        
        $('.delete-invoice').click(function() { deleteId = $(this).data('id'); $('#deleteInvoiceName').text($(this).data('name')); $('#deleteModal').modal('show'); });
        
        $('.pagination a').click(function(e) { e.preventDefault(); const page = $(this).data('page'); if (page) { currentPage = page; loadInvoices(); } });
    }
    
    function escapeHtml(text) { if (!text) return ''; return text.replace(/[&<>]/g, function(m) { if (m === '&') return '&amp;'; if (m === '<') return '&lt;'; if (m === '>') return '&gt;'; return m; }); }
    
    function showAlert(type, msg) { $('#alert-placeholder').html(`<div class="alert alert-${type} alert-dismissible fade show"><i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`); setTimeout(() => $('.alert').fadeOut(5000, function() { $(this).remove(); }), 5000); }

   $('#invoiceForm').submit(async function(e) {
        e.preventDefault();

        if (!$('#modalTenantId').val()) {
            showAlert('error', 'Fadlan dooro shirkad');
            return;
        }

        if (!$('#modalCustomerId').val()) {
            showAlert('error', 'Fadlan dooro macaamil');
            return;
        }

        const btn = $('#saveInvoiceBtn');
        const originalText = btn.html();

        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

        try {
            let invoiceNumber = $('#modalInvoiceNumber').val();
            if (!invoiceNumber || invoiceNumber === '-') {
                invoiceNumber = await generateInvoiceNumber($('#modalTenantId').val());
            }

            $('#modalInvoiceNumber').val(invoiceNumber);

            const formData = new FormData(this);
            formData.set('invoice_number', invoiceNumber);
            formData.set('tenant_id', $('#modalTenantId').val());
            formData.set('customer_id', $('#modalCustomerId').val());
            formData.append('ajax_action', 'save_invoice');

            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(res) {
                    if (res && res.success) {
                        $('#invoiceModal').modal('hide');
                        loadInvoices();
                        loadStats();
                        showAlert('success', res.message);
                        $('#invoiceForm')[0].reset();
                        $('#autoInvoiceNumber').text('-');
                        $('#modalInvoiceNumber').val('');
                        calculateTotal();
                    } else {
                        showAlert('error', (res && res.message) ? res.message : 'Biilka lama save-gareyn');
                    }
                    btn.prop('disabled', false).html(originalText);
                },
                error: function(xhr) {
                    showAlert('error', 'Khalad ayaa dhacay: server response sax ma ahan');
                    btn.prop('disabled', false).html(originalText);
                }
            });

        } catch (err) {
            showAlert('error', err);
            btn.prop('disabled', false).html(originalText);
        }
    });
    
    // PAYMENT FORM SUBMIT - FIXED: uses add_payment action
    $('#paymentForm').submit(function(e) {
        e.preventDefault();
        const amount = parseFloat($('#paymentAmount').val());
        const dueAmount = parseFloat($('#paymentDueAmount').text().replace('$', ''));
        if (isNaN(amount) || amount <= 0) { showAlert('error', 'Fadlan geli qadarka bixinta'); return; }
        if (amount > dueAmount) { showAlert('error', `Qadarka bixinta wuu ka badan yahay deynta ($${dueAmount.toFixed(2)})`); return; }
        
        const btn = $(this).find('button[type="submit"]');
        const originalText = btn.html();
        btn.html('<i class="fas fa-spinner fa-spin"></i> Processing...').prop('disabled', true);
        
        $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'add_payment', invoice_id: $('#paymentInvoiceId').val(), amount: amount, payment_date: $('#paymentDate').val(), payment_method: $('#paymentMethod').val(), reference_number: $('#paymentReference').val(), notes: $('#paymentNotes').val() }, dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#paymentModal').modal('hide');
                    loadInvoices();
                    loadStats();
                    showAlert('success', res.message);
                    $('#paymentForm')[0].reset();
                } else { showAlert('error', res.message); }
                btn.html(originalText).prop('disabled', false);
            },
            error: function() { showAlert('error', 'Khalad ayaa dhacay'); btn.html(originalText).prop('disabled', false); }
        });
    });

    $('#confirmDeleteBtn').click(function() {
        if (deleteId) { $.ajax({ url: window.location.href, type: 'POST', data: { ajax_action: 'delete_invoice', id: deleteId }, dataType: 'json', success: function(res) { if (res.success) { $('#deleteModal').modal('hide'); loadInvoices(); loadStats(); showAlert('success', res.message); } else { showAlert('error', res.message); } deleteId = null; } }); }
    });

    $('#addInvoiceBtn, #addInvoiceBtnEmpty').click(function() {
        $('#invoiceModalLabel').text('Biil Cusub');
        $('#invoiceForm')[0].reset();
        $('#invoice_id').val('');
        $('#modalInvoiceDate').val(new Date().toISOString().split('T')[0]);
        $('#modalDueDate').val(new Date(new Date().setDate(new Date().getDate() + 30)).toISOString().split('T')[0]);
        $('#modalSubtotal').val(0); $('#modalTaxRate').val(0); $('#modalDiscount').val(0);
        $('#tenantValidationWarning').hide(); tenantValid = false; $('#saveInvoiceBtn').prop('disabled', true);
        $('#autoInvoiceNumber').text('Fadlan dooro shirkad'); $('#modalInvoiceNumber').val('');
        calculateTotal();
        $('#invoiceModal').modal('show');
    });

    $('#quickAddTenantBtn').click(function() { $('#quickAddTenantModal').modal('show'); });
    $('#quickAddTenantForm').submit(function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('ajax_action', 'quick_add_tenant');
        $.ajax({ url: window.location.href, type: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
            success: function(res) {
                if (res.success) {
                    const newOption = new Option(res.name, res.id, true, true);
                    $('#modalTenantId').append(newOption).trigger('change');
                    $('#quickAddTenantModal').modal('hide');
                    $('#quickAddTenantForm')[0].reset();
                    showAlert('success', 'Shirkad cusub waa lagu daray!');
                } else { showAlert('error', res.message); }
            }
        });
    });

    $('#quickAddCustomerBtn').click(function() {
        let tid = $('#modalTenantId').val();
        if (!tid || tid === "") { 
            showAlert('error', 'Fadlan dooro shirkad marka hore (Please select a company first)'); 
            return; 
        }
        $('#quickCustomerTenantId').val(tid);
        $('#quickAddCustomerModal').modal('show');
    });
    
    $('#quickAddCustomerForm').submit(function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('ajax_action', 'quick_add_customer');
        $.ajax({ url: window.location.href, type: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
            success: function(res) {
                if (res.success) {
                    const newOption = new Option(res.name + ' (' + res.phone + ')', res.id, true, true);
                    $('#modalCustomerId').append(newOption);
                    $('#quickAddCustomerModal').modal('hide');
                    $('#quickAddCustomerForm')[0].reset();
                    showAlert('success', 'Macaamil cusub waa lagu daray!');
                } else { showAlert('error', res.message); }
            }
        });
    });

    $('#printInvoiceBtn').click(function() { const printContent = $('#viewModalBody').html(); const w = window.open('', '_blank'); w.document.write('<html><head><title>Print Invoice</title></head><body style="margin:0;padding:20px;">' + printContent + '</body></html>'); w.document.close(); setTimeout(function() { w.print(); }, 300); });

    $('#applyFilters').click(function() { currentPage = 1; loadInvoices(); loadStats(); });
    $('#resetFilters').click(function() { $('#searchInput').val(''); $('#tenantFilter').val('0'); $('#customerFilter').val('0'); $('#statusFilter').val('all'); $('#dateFrom').val(''); $('#dateTo').val(''); currentPage = 1; loadInvoices(); loadStats(); });
    $('#searchInput').keypress(function(e) { if (e.which === 13) { currentPage = 1; loadInvoices(); } });

    calculateTotal();
    
    $('#modalCustomerId').change(function() {
        const customerId = $(this).val();
        if (customerId) {
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_customer_stock_total', customer_id: customerId },
                dataType: 'json',
                success: function(res) {
                    if (res.success && res.total_value > 0) {
                        if ($('#modalSubtotal').val() == 0 || $('#modalSubtotal').val() == '') {
                            $('#modalSubtotal').val(res.total_value);
                            showAlert('info', 'Lacagta macaamilka ee bakhaarka ayaa si toos ah loo soo qaaday.');
                            calculateTotal();
                        }
                    }
                }
            });
        }
    });

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('search')) {
        $('#searchInput').val(urlParams.get('search'));
    }

    loadInvoices();
    loadStats();

    if (urlParams.has('convert_stock_id')) {
        const customerId = urlParams.get('customer_id');
        const tenantId = urlParams.get('tenant_id');
        const stockName = urlParams.get('stock_name');
        const qty = urlParams.get('qty');
        const cbm = urlParams.get('cbm');

        $('#addInvoiceBtn').click();
        
        setTimeout(() => {
            if (tenantId) {
                $('#modalTenantId').val(tenantId).trigger('change');
                setTimeout(() => {
                    if (customerId) $('#modalCustomerId').val(customerId);
                    if (cbm) $('#modalSubtotal').val(cbm);
                    if (stockName) $('#modalNotes').val(`Invoice for: ${stockName} (${qty} units)`);
                    showAlert('info', 'Xogta alaabta iyo macmiilka si otomaatik ah ayaa loo soo buuxiyey. Fadlan geli qiimaha (Freight).');
                }, 500);
            }
        }, 500);
    }
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>