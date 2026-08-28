<?php
require_once '../config/db_connect.php';
require_once '../includes/AccountingService.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['superadmin', 'company_admin'])) {
    header("Location: ../login.php");
    exit();
}

$role = $_SESSION['role'];
$session_tenant_id = $_SESSION['tenant_id'] ?? 0;

$db = $pdo;

// Handle Export Actions (GET)
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action === 'export_receipts') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipts_export_'.date('Y-m-d').'.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['ID', 'Receipt Number', 'Customer', 'Invoice', 'Account', 'Amount', 'Date', 'Tenant']);
        
        $tenant_id = ($_SESSION['role'] === 'superadmin') ? ($_SESSION['selected_tenant_id'] ?? 'all') : ($_SESSION['tenant_id'] ?? 0);
        $where_r = ($tenant_id === 'all') ? "1=1" : "r.tenant_id = ?";
        $params_r = ($tenant_id === 'all') ? [] : [$tenant_id];
        
        $sql = "SELECT r.*, c.customer_name, i.invoice_number, ba.account_name as bank_name, t.name as tenant_name
                FROM receipts r 
                JOIN customers c ON r.customer_id = c.id 
                LEFT JOIN invoices i ON r.invoice_id = i.id 
                LEFT JOIN bank_accounts ba ON r.bank_account_id = ba.id
                LEFT JOIN tenants t ON r.tenant_id = t.id
                WHERE $where_r 
                ORDER BY r.created_at DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params_r);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['id'],
                $row['receipt_number'],
                $row['customer_name'],
                $row['invoice_number'] ?: 'On Account',
                $row['bank_name'] ?: 'N/A',
                $row['amount'],
                $row['payment_date'],
                $row['tenant_name']
            ]);
        }
        fclose($output);
        exit;
    }
    
    if ($action === 'download_sample') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipts_sample.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, ['Tenant Name', 'Customer Name', 'Invoice Number', 'Amount', 'Date (YYYY-MM-DD)', 'Bank/Account Name']);
        fputcsv($output, ['Example Logistics', 'John Doe', 'INV-5001', '1000.00', date('Y-m-d'), 'Main Cash']);
        fclose($output);
        exit;
    }
}

// Fetch Bank Accounts (filtered by tenant if company_admin)
$bank_where = ($role === 'company_admin') ? "WHERE tenant_id = $session_tenant_id" : "";
$bank_accounts = db_get_all("SELECT id, account_name, bank_name FROM bank_accounts $bank_where ORDER BY account_name ASC");

// Handle Super Admin vs Tenant Admin
$tenant_id = ($role === 'superadmin') ? ($_SESSION['selected_tenant_id'] ?? 'all') : $session_tenant_id;

// Handle Receipt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['save_receipt'] ?? '0') === '1') {
    $customer_id = (int)$_POST['customer_id'];
    $invoice_id = !empty($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null;
    $amount = (float)$_POST['amount'];
    $bank_id = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
    $date = $_POST['payment_date'] ?: date('Y-m-d');
    
    // Get the tenant_id of the customer to ensure we record it for the right shirkad
    $cust_stmt = $db->prepare("SELECT tenant_id FROM customers WHERE id = ?");
    $cust_stmt->execute([$customer_id]);
    $cust_data = $cust_stmt->fetch();
    $target_tenant_id = $cust_data['tenant_id'] ?? $tenant_id;

    $receipt_no = 'RCP-' . time();
    
    // 1. Record Receipt
    $stmt = $db->prepare("INSERT INTO receipts (tenant_id, receipt_number, invoice_id, customer_id, amount, payment_date, bank_account_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$target_tenant_id, $receipt_no, $invoice_id, $customer_id, $amount, $date, $bank_id, $_SESSION['user_id']]);
    $receipt_id = $db->lastInsertId();
    
    // 2. Update Invoice (If selected)
    if ($invoice_id) {
        $db->prepare("UPDATE invoices SET paid_amount = paid_amount + ?, status = IF(paid_amount >= total_amount, 'paid', 'partial') WHERE id = ?")
           ->execute([$amount, $invoice_id]);
    }
       
    // 3. Customer Debt is updated automatically by DB Trigger on receipts table
    // $db->prepare("UPDATE customers SET debt_amount = debt_amount - ?, updated_at = NOW() WHERE id = ?")->execute([$amount, $customer_id]);
    
    // 4. Update Bank Balance (If bank selected)
    if ($bank_id) {
        $db->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$amount, $bank_id]);
    }
    
    // 5. Auto-Journalize (Double Entry)
    $accounting = new AccountingService($db, $target_tenant_id, $_SESSION['user_id']);
    $accounting->journalizeReceipt($receipt_id);
    
    $success = "Lacag qabashada waa lagu guuleystay!";
}

// Handle AJAX to get receipt details for editing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_receipt_details') {
    header('Content-Type: application/json');
    $id = (int)$_POST['id'];
    $r = db_get("SELECT * FROM receipts WHERE id = ?", [$id]);
    echo json_encode($r);
    exit;
}

// Handle Update Receipt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['update_receipt'] ?? '0') === '1') {
    $receipt_id = (int)$_POST['receipt_id'];
    $customer_id = (int)$_POST['customer_id'];
    $invoice_id = !empty($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null;
    $new_amount = (float)$_POST['amount'];
    $bank_id = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
    $date = $_POST['payment_date'];

    try {
        $pdo->beginTransaction();
        
        // 1. Get old data
        $old = db_get("SELECT * FROM receipts WHERE id = ?", [$receipt_id]);
        $old_amount = (float)$old['amount'];
        $diff = $new_amount - $old_amount;
        
        // 2. Update Receipt
        $stmt = $pdo->prepare("UPDATE receipts SET customer_id = ?, invoice_id = ?, amount = ?, payment_date = ?, bank_account_id = ? WHERE id = ?");
        $stmt->execute([$customer_id, $invoice_id, $new_amount, $date, $bank_id, $receipt_id]);
        
        // 3. Update Customer Debt
        $pdo->prepare("UPDATE customers SET debt_amount = debt_amount - ? WHERE id = ?")->execute([$diff, $customer_id]);
        
        // 4. Update Bank Balance (Handle if bank account changed)
        if ($old['bank_account_id'] == $bank_id) {
            if ($bank_id) $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$diff, $bank_id]);
        } else {
            if ($old['bank_account_id']) $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$old_amount, $old['bank_account_id']]);
            if ($bank_id) $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$new_amount, $bank_id]);
        }
        
        // 5. Update Invoice (Handle if invoice changed or amount changed)
        // This is complex, but let's do a basic adjustment if it's the same invoice
        if ($old['invoice_id'] == $invoice_id && $invoice_id) {
            $pdo->prepare("UPDATE invoices SET paid_amount = paid_amount + ? WHERE id = ?")->execute([$diff, $invoice_id]);
            $pdo->prepare("UPDATE invoices SET status = IF(paid_amount >= total_amount, 'paid', 'partial') WHERE id = ?")->execute([$invoice_id]);
        }
        
        $pdo->commit();
        $success = "Lacagta waa la cusboonaysiiyay!";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Khalad: " . $e->getMessage();
    }
}

// Handle AJAX Edit Receipt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'edit_receipt') {
    header('Content-Type: application/json');
    $receipt_id = (int)$_POST['receipt_id'];
    $new_amount = (float)$_POST['new_amount'];
    
    try {
        $pdo->beginTransaction();
        
        // 1. Get old receipt info
        $stmt = $pdo->prepare("SELECT * FROM receipts WHERE id = ?");
        $stmt->execute([$receipt_id]);
        $receipt = $stmt->fetch();
        
        if (!$receipt) throw new Exception("Receipt not found.");
        
        $old_amount = (float)$receipt['amount'];
        $diff = $new_amount - $old_amount;
        
        if ($diff == 0) {
            echo json_encode(['success' => true, 'message' => 'No changes made.']);
            exit;
        }
        
        // 2. Update Receipt
        $pdo->prepare("UPDATE receipts SET amount = ? WHERE id = ?")->execute([$new_amount, $receipt_id]);
        
        // 3. Update Customer Debt (Difference: if new > old, subtract from debt)
        $pdo->prepare("UPDATE customers SET debt_amount = debt_amount - ? WHERE id = ?")->execute([$diff, $receipt['customer_id']]);
        
        // 4. Update Bank Balance
        if ($receipt['bank_account_id']) {
            $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$diff, $receipt['bank_account_id']]);
        }
        
        // 5. Update Invoice (If linked)
        if ($receipt['invoice_id']) {
            $pdo->prepare("UPDATE invoices SET paid_amount = paid_amount + ? WHERE id = ?")->execute([$diff, $receipt['invoice_id']]);
            $pdo->prepare("UPDATE invoices SET status = IF(paid_amount >= total_amount, 'paid', 'partial') WHERE id = ?")->execute([$receipt['invoice_id']]);
        }
        
        // Note: Accounting journal update is skipped for now as it's complex, 
        // but the balances are corrected in the sub-ledgers.
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Lacagta waa la beddelay!']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
    }
    exit;
}

// Handle Receipt Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'import_receipts') {
    header('Content-Type: application/json');
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
            // Columns: Tenant Name, Customer Name, Invoice Number, Amount, Date, Bank Name
            $tenant_name = trim($data[0] ?? '');
            $customer_name = trim($data[1] ?? '');
            $invoice_number = trim($data[2] ?? '');
            $amount = (float)(str_replace(['$', ','], '', $data[3] ?? 0));
            $date = trim($data[4] ?? date('Y-m-d'));
            $bank_name = trim($data[5] ?? '');
            
            if (empty($tenant_name) || empty($customer_name) || $amount <= 0) continue;
            
            $t_id = $tenants_map[strtolower($tenant_name)] ?? null;
            if (!$t_id) {
                $errors[] = "Line $line: Tenant '$tenant_name' not found.";
                continue;
            }
            
            // Find customer
            $stmt = $pdo->prepare("SELECT id FROM customers WHERE tenant_id = ? AND LOWER(customer_name) = ?");
            $stmt->execute([$t_id, strtolower($customer_name)]);
            $customer_id = $stmt->fetchColumn();
            if (!$customer_id) {
                $stmt = $pdo->prepare("INSERT INTO customers (tenant_id, customer_name, is_active, created_at) VALUES (?, ?, 1, NOW())");
                $stmt->execute([$t_id, $customer_name]);
                $customer_id = $pdo->lastInsertId();
            }
            
            // Find invoice
            $invoice_id = null;
            if (!empty($invoice_number)) {
                $stmt = $pdo->prepare("SELECT id FROM invoices WHERE tenant_id = ? AND invoice_number = ?");
                $stmt->execute([$t_id, $invoice_number]);
                $invoice_id = $stmt->fetchColumn();
            }
            
            // Find bank account
            $bank_id = null;
            if (!empty($bank_name)) {
                $stmt = $pdo->prepare("SELECT id FROM bank_accounts WHERE tenant_id = ? AND LOWER(account_name) = ?");
                $stmt->execute([$t_id, strtolower($bank_name)]);
                $bank_id = $stmt->fetchColumn();
            }
            
            $receipt_no = 'RCP-' . time() . '-' . $line;
            $stmt = $pdo->prepare("INSERT INTO receipts (tenant_id, receipt_number, invoice_id, customer_id, amount, payment_date, bank_account_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$t_id, $receipt_no, $invoice_id, $customer_id, $amount, $date, $bank_id, $_SESSION['user_id']]);
            $receipt_id = $pdo->lastInsertId();
            
            if ($invoice_id) {
                $pdo->prepare("UPDATE invoices SET paid_amount = paid_amount + ?, status = IF(paid_amount >= total_amount, 'paid', 'partial') WHERE id = ?")->execute([$amount, $invoice_id]);
            }
            
            // NOTE: Customer debt is decremented exactly once by the
            // `trigger_update_debt` trigger on receipts INSERT. Do NOT
            // decrement debt_amount here as well — that reintroduces the
            // historical double-decrement defect.
            
            if ($bank_id) {
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$amount, $bank_id]);
            }
            
            $imported++;
        }
        
        $pdo->commit();
        $msg = "Import-ka waa lagu guulaystay! ($imported rasiid).";
        if (count($errors) > 0) $msg .= "<br>Digniin: " . count($errors) . " saf ayaa laga booday.";
        echo json_encode(['success' => true, 'message' => $msg]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
    }
    fclose($handle);
    exit;
}

include_once '../includes/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    .select2-container--default .select2-selection--single { border: 1px solid #ccc; height: 40px; border-radius: 4px; padding: 5px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 38px; }
    .select2-container { width: 100% !important; }
</style>

<div class="container-fluid p-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 style="color:#2D1859; font-weight:800;">Lacag Qabashada (Receipts)</h2>
        <div class="d-flex align-items-center">
            <button class="btn btn-primary" onclick="openNewReceiptModal()" style="background:#2D1859;">New Receipt</button>
            <div class="dropdown ml-2">
                <button class="btn btn-light dropdown-toggle" type="button" data-toggle="dropdown" style="border-radius: 4px; padding: 7px 15px; font-weight: 600; border: 1px solid #ccc;">
                    <i class="fas fa-file-csv"></i> CSV
                </button>
                <div class="dropdown-menu dropdown-menu-right">
                    <a class="dropdown-item" href="?action=export_receipts"><i class="fas fa-download mr-2"></i> Export Receipts</a>
                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#importModal"><i class="fas fa-upload mr-2"></i> Import Receipts</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="?action=download_sample"><i class="fas fa-file-download mr-2"></i> Download Sample</a>
                </div>
            </div>
        </div>
    </div>

    <?php if(isset($success)) echo "<div class='alert alert-success'>$success</div>"; ?>

    <div class="row">
        <div class="col-md-8">
            <div class="glass-card p-4">
                <h5>Recent Customer Receipts</h5>
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Receipt #</th>
                            <th>Customer</th>
                            <th>Invoice #</th>
                            <th>Account</th>
                            <th>Amount</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $where_r = ($tenant_id === 'all') ? "1=1" : "r.tenant_id = ?";
                        $params_r = ($tenant_id === 'all') ? [] : [$tenant_id];
                        $receipts = db_get_all("
                        SELECT r.*, c.customer_name, i.invoice_number, ba.account_name as bank_name
                        FROM receipts r 
                        JOIN customers c ON r.customer_id = c.id 
                        LEFT JOIN invoices i ON r.invoice_id = i.id 
                        LEFT JOIN bank_accounts ba ON r.bank_account_id = ba.id
                        WHERE $where_r 
                        ORDER BY r.created_at DESC 
                        LIMIT 50", $params_r);
                        foreach($receipts as $r):
                        ?>
                        <tr>
                            <td><strong><?= $r['receipt_number'] ?></strong></td>
                            <td><?= $r['customer_name'] ?></td>
                            <td><?= $r['invoice_number'] ?: '<span class="badge badge-info">On Account</span>' ?></td>
                            <td><?= htmlspecialchars($r['bank_name'] ?? 'N/A') ?></td>
                            <td>$<?= number_format($r['amount'], 2) ?></td>
                            <td><?= date('d/m/Y', strtotime($r['payment_date'])) ?></td>
                            <td>
                                <div style="display: flex; gap: 10px;">
                                    <button class="btn btn-sm btn-outline-primary" onclick="window.open('print_receipt.php?id=<?= $r['id'] ?>', '_blank')"><i class="fas fa-print"></i> Print</button>
                                    <button class="btn btn-sm btn-outline-info edit-receipt-btn" data-id="<?= $r['id'] ?>" data-amount="<?= $r['amount'] ?>"><i class="fas fa-edit"></i> Edit</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="glass-card p-4 bg-light text-center">
                <i class="fas fa-hand-holding-usd fa-3x text-primary mb-3"></i>
                <h5>Accounts Receivable</h5>
                <?php
                $where_ar = ($tenant_id === 'all') ? "1=1" : "(tenant_id = ? OR tenant_id IS NULL)";
                $params_ar = ($tenant_id === 'all') ? [] : [$tenant_id];
                $ar = db_get("SELECT SUM(balance) as total_balance FROM chart_of_accounts WHERE account_code = '1100' AND $where_ar", $params_ar);
                ?>
                <h3 class="text-primary font-weight-bold">$<?= number_format($ar['total_balance'] ?? 0, 2) ?></h3>
                <p class="text-muted">Total money customers owe you.</p>
            </div>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 8px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-import"></i> Soo geli Rasiidyo (CSV)</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
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
                    <button type="submit" class="btn btn-primary" style="background: #2D1859; border: none;">Soo geli (Import)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div class="modal fade" id="receiptModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header"><h5 id="modalTitle">Record New Receipt</h5></div>
            <div class="modal-body">
                <input type="hidden" name="save_receipt" id="save_receipt_flag" value="1">
                <input type="hidden" name="update_receipt" id="update_receipt_flag" value="0">
                <input type="hidden" name="receipt_id" id="edit_receipt_id" value="">
                
                <div class="form-group">
                    <label>Select Customer</label>
                    <select name="customer_id" id="customer_select" class="form-control select2" onchange="loadCustomerInvoices(this.value)" required>
                        <option value="">-- Select Customer --</option>
                        <?php
                        $where_c = ($tenant_id === 'all') ? "1=1" : "tenant_id = ?";
                        $params_c = ($tenant_id === 'all') ? [] : [$tenant_id];
                        $customers = db_get_all("SELECT id, customer_name FROM customers WHERE $where_c ORDER BY customer_name ASC", $params_c);
                        foreach($customers as $c) echo "<option value='{$c['id']}'>{$c['customer_name']}</option>";
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Select Invoice (Optional)</label>
                    <select name="invoice_id" id="invoice_select" class="form-control select2" onchange="autoFillAmount(this)">
                        <option value="">-- General Payment (On Account) --</option>
                    </select>
                </div>
                <div class="form-group"><label>Amount Received ($)</label><input type="number" step="0.01" name="amount" id="receipt_amount" class="form-control" required></div>
                <div class="form-group">
                    <label>Deposit To Account</label>
                    <select name="bank_account_id" id="bank_account_select" class="form-control select2" required>
                        <option value="">-- Dooro Account-ka --</option>
                        <?php foreach($bank_accounts as $ba): ?>
                            <option value="<?= $ba['id'] ?>"><?= htmlspecialchars($ba['account_name']) ?> (<?= htmlspecialchars($ba['bank_name']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Payment Date</label><input type="date" name="payment_date" id="payment_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="modalSubmitBtn" style="background:#2D1859;">Save Receipt</button>
            </div>
        </form>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    console.log("Receipt Management JS Loaded");
    $('.select2').select2({
        dropdownParent: $('#receiptModal')
    });
});

function loadCustomerInvoices(customerId, callback = null) {
    if(!customerId) return;
    $.post('invoices.php', {ajax_action: 'get_invoices_by_customer', customer_id: customerId}, function(data) {
        let options = '<option value="">-- General Payment (On Account) --</option>';
        data.invoices.forEach(inv => {
            options += `<option value="${inv.id}" data-due="${inv.due_amount}">${inv.invoice_number} (Due: $${inv.due_amount})</option>`;
        });
        $('#invoice_select').html(options).trigger('change');
        if (callback) callback();
    }, 'json');
}

function autoFillAmount(selectElement) {
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    if (!selectedOption) return;
    const dueAmount = selectedOption.getAttribute('data-due');
    if (dueAmount) {
        $('#receipt_amount').val(dueAmount);
    } else {
        $('#receipt_amount').val('');
    }
}

$(document).on('click', '.edit-receipt-btn', function() {
    const id = $(this).data('id');
    if (!id) {
        alert("ID-ga rasiidka lama helin!");
        return;
    }
    
    // Reset flags
    $('#save_receipt_flag').val('0');
    $('#update_receipt_flag').val('1');
    $('#edit_receipt_id').val(id);
    $('#modalTitle').text('Edit Receipt');
    $('#modalSubmitBtn').text('Update Receipt');

    // Show loading state or modal immediately
    $('#receiptModal').modal('show');
    $('#receipt_amount').val('Raraya...');

    $.post(window.location.href, {ajax_action: 'get_receipt_details', id: id}, function(r) {
        if (!r) {
            alert("Xogta rasiidka lama soo helin!");
            return;
        }
        $('#customer_select').val(r.customer_id).trigger('change');
        $('#receipt_amount').val(r.amount);
        $('#bank_account_select').val(r.bank_account_id).trigger('change');
        $('#payment_date').val(r.payment_date);
        
        // Load invoices and THEN set the selected one
        loadCustomerInvoices(r.customer_id, function() {
            if (r.invoice_id) {
                $('#invoice_select').val(r.invoice_id).trigger('change');
            }
            $('#receipt_amount').val(r.amount);
        });
    }, 'json').fail(function() {
        alert("Cilad ayaa dhacday xilliga soo qabashada xogta!");
    });
});

// Reset modal when opening for new receipt
function openNewReceiptModal() {
    $('#save_receipt_flag').val('1');
    $('#update_receipt_flag').val('0');
    $('#edit_receipt_id').val('');
    $('#modalTitle').text('Record New Receipt');
    $('#modalSubmitBtn').text('Save Receipt');
    
    $('#customer_select').val('').trigger('change');
    $('#receipt_amount').val('');
    $('#invoice_select').html('<option value="">-- General Payment (On Account) --</option>').trigger('change');
    $('#receiptModal').modal('show');
}

$('#importForm').submit(function(e) {
    e.preventDefault();
    let formData = new FormData(this);
    formData.append('ajax_action', 'import_receipts');
    
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
                alert(res.message);
                location.reload();
            } else {
                alert(res.message);
            }
        },
        error: function() {
            alert('Khalad ayaa dhacay intii lagu guda jiray soo gelinta.');
        }
    });
});
</script>


