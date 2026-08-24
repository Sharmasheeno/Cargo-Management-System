<?php
// tenant_admin/expenses_management.php
// Vendor Bills & Expenses Management for Cargo Management System - Tenant Admin

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is tenant_admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tenant_admin') {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION['role'];
$session_tenant_id = $_SESSION['tenant_id'] ?? 0;

// Security: If no tenant is assigned, redirect
if (!$session_tenant_id) {
    header("Location: ../dashboard.php?error=no_tenant");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Tenant Admin';

require_once __DIR__ . '/../config/db_connect.php';

// Get tenant name
$tenant_name = '';
try {
    $stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
    $stmt->execute([$session_tenant_id]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_name = $tenant['name'] ?? 'My Company';
} catch (PDOException $e) {
    $tenant_name = 'My Company';
}

// ==================== CREATE TABLES IF NOT EXISTS ====================
try {
    // Vendors table
    $pdo->exec("CREATE TABLE IF NOT EXISTS vendors (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT(11) NOT NULL,
        vendor_name VARCHAR(255) NOT NULL,
        contact_person VARCHAR(255),
        phone VARCHAR(50),
        email VARCHAR(100),
        address TEXT,
        tax_number VARCHAR(100),
        payment_terms VARCHAR(50) DEFAULT 'net_30',
        bank_name VARCHAR(100),
        bank_account VARCHAR(100),
        notes TEXT,
        status ENUM('active','inactive') DEFAULT 'active',
        created_by INT(11),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    )");
    
    // Vendor Bills table
    $pdo->exec("CREATE TABLE IF NOT EXISTS vendor_bills (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT(11) NOT NULL,
        vendor_id INT(11) NOT NULL,
        bill_number VARCHAR(100) NOT NULL,
        bill_date DATE NOT NULL,
        due_date DATE,
        subtotal DECIMAL(15,2) DEFAULT 0,
        tax_amount DECIMAL(15,2) DEFAULT 0,
        tax_rate DECIMAL(5,2) DEFAULT 0,
        discount_amount DECIMAL(15,2) DEFAULT 0,
        total_amount DECIMAL(15,2) DEFAULT 0,
        amount_paid DECIMAL(15,2) DEFAULT 0,
        status ENUM('draft','pending','paid','overdue','cancelled') DEFAULT 'pending',
        notes TEXT,
        attachment_path VARCHAR(500),
        created_by INT(11),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
        UNIQUE KEY unique_bill_per_tenant (tenant_id, bill_number)
    )");
    
    // Bill Payments table
    $pdo->exec("CREATE TABLE IF NOT EXISTS bill_payments (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT(11) NOT NULL,
        bill_id INT(11) NOT NULL,
        payment_date DATE NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        payment_method ENUM('cash','bank_transfer','check','credit_card','other') DEFAULT 'cash',
        reference_number VARCHAR(100),
        notes TEXT,
        created_by INT(11),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (bill_id) REFERENCES vendor_bills(id) ON DELETE CASCADE
    )");
    
    // Expense Categories table
    $pdo->exec("CREATE TABLE IF NOT EXISTS expense_categories (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT(11) NOT NULL,
        category_name VARCHAR(100) NOT NULL,
        description TEXT,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    )");
    
    // Expenses table
    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT(11) NOT NULL,
        expense_date DATE NOT NULL,
        category_id INT(11),
        vendor_id INT(11),
        description TEXT,
        amount DECIMAL(15,2) NOT NULL,
        tax_amount DECIMAL(15,2) DEFAULT 0,
        receipt_path VARCHAR(500),
        payment_method ENUM('cash','bank_transfer','check','credit_card','other') DEFAULT 'cash',
        reference_number VARCHAR(100),
        notes TEXT,
        created_by INT(11),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE SET NULL,
        FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL
    )");
    
    // Insert default expense categories for this tenant
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM expense_categories WHERE tenant_id = ?");
    $stmt->execute([$session_tenant_id]);
    if ($stmt->fetchColumn() == 0) {
        $default_categories = ['Fuel', 'Maintenance', 'Salary', 'Rent', 'Utilities', 'Insurance', 'Tax', 'Office Supplies', 'Marketing', 'Other'];
        $stmt = $pdo->prepare("INSERT INTO expense_categories (tenant_id, category_name) VALUES (?, ?)");
        foreach ($default_categories as $cat) {
            $stmt->execute([$session_tenant_id, $cat]);
        }
    }
    
} catch (PDOException $e) {
    // Log error but continue
    error_log("Table creation error: " . $e->getMessage());
}

// Get expense categories for this tenant
$categories = [];
try {
    $stmt = $pdo->prepare("SELECT id, category_name FROM expense_categories WHERE tenant_id = ? ORDER BY category_name");
    $stmt->execute([$session_tenant_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
}

// ==================== AJAX HANDLERS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    
    // ==================== VENDOR MANAGEMENT ====================
    if ($action === 'get_vendors') {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 10;
        $offset = ($page - 1) * $limit;
        $search = $_POST['search'] ?? '';
        
        $where = ["v.tenant_id = ?"];
        $params = [$session_tenant_id];
        
        if (!empty($search)) {
            $where[] = "(v.vendor_name LIKE ? OR v.contact_person LIKE ? OR v.phone LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        $where_clause = "WHERE " . implode(" AND ", $where);
        
        $count_sql = "SELECT COUNT(*) as total FROM vendors v $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total / $limit);
        
        $sql = "SELECT v.*,
                (SELECT COUNT(*) FROM vendor_bills WHERE vendor_id = v.id) as total_bills,
                (SELECT SUM(total_amount - amount_paid) FROM vendor_bills WHERE vendor_id = v.id AND status IN ('pending','overdue')) as outstanding
                FROM vendors v
                $where_clause
                ORDER BY v.created_at DESC
                LIMIT $limit OFFSET $offset";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_start(); ?>
        <table class="table table-hover">
            <thead>
                <tr><th>ID</th><th>Vendor</th><th>Contact</th><th>Phone</th><th>Bills</th><th>Outstanding</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($vendors as $v): ?>
                <tr>
                    <td><?= $v['id'] ?></td>
                    <td><strong><?= htmlspecialchars($v['vendor_name']) ?></strong><br><small><?= htmlspecialchars($v['tax_number'] ?? '-') ?></small></td>
                    <td><?= htmlspecialchars($v['contact_person'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($v['phone'] ?? '-') ?></td>
                    <td><?= $v['total_bills'] ?></td>
                    <td>$<?= number_format($v['outstanding'] ?? 0, 2) ?></td>
                    <td><span class="badge badge-<?= $v['status'] == 'active' ? 'success' : 'danger' ?>"><?= $v['status'] ?></span></td>
                    <td>
                        <button class="btn btn-sm btn-primary edit-vendor" data-id="<?= $v['id'] ?>"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-sm btn-danger delete-vendor" data-id="<?= $v['id'] ?>" data-name="<?= htmlspecialchars($v['vendor_name']) ?>"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php $table_html = ob_get_clean();
        
        echo json_encode(['table_html' => $table_html, 'total_pages' => $total_pages, 'current_page' => $page]);
        exit;
    }
    
    if ($action === 'save_vendor') {
        $id = $_POST['id'] ?? '';
        $tenant_id = $session_tenant_id; // Force tenant admin's tenant
        $vendor_name = trim($_POST['vendor_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $tax_number = trim($_POST['tax_number'] ?? '');
        $payment_terms = $_POST['payment_terms'] ?? 'net_30';
        $bank_name = trim($_POST['bank_name'] ?? '');
        $bank_account = trim($_POST['bank_account'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        if (empty($vendor_name)) {
            echo json_encode(['success' => false, 'message' => 'Vendor name is required']);
            exit;
        }
        
        try {
            if (empty($id)) {
                $stmt = $pdo->prepare("INSERT INTO vendors (tenant_id, vendor_name, contact_person, phone, email, address, tax_number, payment_terms, bank_name, bank_account, notes, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$tenant_id, $vendor_name, $contact_person, $phone, $email, $address, $tax_number, $payment_terms, $bank_name, $bank_account, $notes, $status, $user_id]);
                echo json_encode(['success' => true, 'message' => 'Vendor added successfully']);
            } else {
                // Verify vendor belongs to this tenant
                $check = $pdo->prepare("SELECT id FROM vendors WHERE id = ? AND tenant_id = ?");
                $check->execute([$id, $tenant_id]);
                if (!$check->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Vendor not found or you do not have permission']);
                    exit;
                }
                $stmt = $pdo->prepare("UPDATE vendors SET vendor_name=?, contact_person=?, phone=?, email=?, address=?, tax_number=?, payment_terms=?, bank_name=?, bank_account=?, notes=?, status=? WHERE id=? AND tenant_id=?");
                $stmt->execute([$vendor_name, $contact_person, $phone, $email, $address, $tax_number, $payment_terms, $bank_name, $bank_account, $notes, $status, $id, $tenant_id]);
                echo json_encode(['success' => true, 'message' => 'Vendor updated successfully']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'delete_vendor') {
        $id = $_POST['id'] ?? 0;
        try {
            // Verify vendor belongs to this tenant
            $check = $pdo->prepare("SELECT id FROM vendors WHERE id = ? AND tenant_id = ?");
            $check->execute([$id, $session_tenant_id]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Vendor not found']);
                exit;
            }
            $stmt = $pdo->prepare("DELETE FROM vendors WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $session_tenant_id]);
            echo json_encode(['success' => true, 'message' => 'Vendor deleted']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_vendor') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM vendors WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $session_tenant_id]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit;
    }
    
    // ==================== BILL MANAGEMENT ====================
    if ($action === 'get_bills') {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 10;
        $offset = ($page - 1) * $limit;
        $search = $_POST['search'] ?? '';
        $status_filter = $_POST['status'] ?? '';
        
        $where = ["b.tenant_id = ?"];
        $params = [$session_tenant_id];
        
        if (!empty($search)) {
            $where[] = "(b.bill_number LIKE ? OR v.vendor_name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if (!empty($status_filter)) {
            $where[] = "b.status = ?";
            $params[] = $status_filter;
        }
        
        $where_clause = "WHERE " . implode(" AND ", $where);
        
        $count_sql = "SELECT COUNT(*) as total FROM vendor_bills b LEFT JOIN vendors v ON b.vendor_id = v.id $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total / $limit);
        
        $sql = "SELECT b.*, v.vendor_name,
                DATEDIFF(CURDATE(), b.due_date) as days_overdue
                FROM vendor_bills b
                LEFT JOIN vendors v ON b.vendor_id = v.id
                $where_clause
                ORDER BY b.created_at DESC
                LIMIT $limit OFFSET $offset";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_start(); ?>
        <table class="table table-hover">
            <thead>
                <tr><th>Bill #</th><th>Vendor</th><th>Date</th><th>Due Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($bills as $bill): 
                    $balance = $bill['total_amount'] - $bill['amount_paid'];
                    $status_class = $bill['status'] == 'paid' ? 'success' : ($bill['status'] == 'overdue' ? 'danger' : ($bill['status'] == 'pending' ? 'warning' : 'secondary'));
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($bill['bill_number']) ?></strong></td>
                    <td><?= htmlspecialchars($bill['vendor_name']) ?></td>
                    <td><?= date('d/m/Y', strtotime($bill['bill_date'])) ?></td>
                    <td class="<?= ($bill['days_overdue'] > 0 && $bill['status'] != 'paid') ? 'text-danger' : '' ?>"><?= date('d/m/Y', strtotime($bill['due_date'])) ?></td>
                    <td>$<?= number_format($bill['total_amount'], 2) ?></td>
                    <td>$<?= number_format($bill['amount_paid'], 2) ?></td>
                    <td><strong>$<?= number_format($balance, 2) ?></strong></td>
                    <td><span class="badge badge-<?= $status_class ?>"><?= strtoupper($bill['status']) ?></span></td>
                    <td>
                        <button class="btn btn-sm btn-primary edit-bill" data-id="<?= $bill['id'] ?>"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-sm btn-success add-payment" data-id="<?= $bill['id'] ?>" data-vendor="<?= htmlspecialchars($bill['vendor_name']) ?>" data-bill="<?= htmlspecialchars($bill['bill_number']) ?>" data-balance="<?= $balance ?>"><i class="fas fa-money-bill"></i></button>
                        <button class="btn btn-sm btn-danger delete-bill" data-id="<?= $bill['id'] ?>" data-name="<?= htmlspecialchars($bill['bill_number']) ?>"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php $table_html = ob_get_clean();
        
        echo json_encode(['table_html' => $table_html, 'total_pages' => $total_pages, 'current_page' => $page]);
        exit;
    }
    
    if ($action === 'save_bill') {
        $id = $_POST['id'] ?? '';
        $tenant_id = $session_tenant_id;
        $vendor_id = $_POST['vendor_id'] ?? 0;
        $bill_number = trim($_POST['bill_number'] ?? '');
        $bill_date = $_POST['bill_date'] ?? date('Y-m-d');
        $due_date = $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
        $subtotal = (float)($_POST['subtotal'] ?? 0);
        $tax_rate = (float)($_POST['tax_rate'] ?? 0);
        $tax_amount = $subtotal * ($tax_rate / 100);
        $discount_amount = (float)($_POST['discount_amount'] ?? 0);
        $total_amount = $subtotal + $tax_amount - $discount_amount;
        $notes = trim($_POST['notes'] ?? '');
        
        if (empty($vendor_id) || empty($bill_number)) {
            echo json_encode(['success' => false, 'message' => 'Vendor and Bill Number are required']);
            exit;
        }
        
        // Verify vendor belongs to this tenant
        $check = $pdo->prepare("SELECT id FROM vendors WHERE id = ? AND tenant_id = ?");
        $check->execute([$vendor_id, $tenant_id]);
        if (!$check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Vendor not found or you do not have permission']);
            exit;
        }
        
        try {
            if (empty($id)) {
                $stmt = $pdo->prepare("INSERT INTO vendor_bills (tenant_id, vendor_id, bill_number, bill_date, due_date, subtotal, tax_rate, tax_amount, discount_amount, total_amount, notes, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$tenant_id, $vendor_id, $bill_number, $bill_date, $due_date, $subtotal, $tax_rate, $tax_amount, $discount_amount, $total_amount, $notes, 'pending', $user_id]);
                echo json_encode(['success' => true, 'message' => 'Bill added successfully']);
            } else {
                // Verify bill belongs to this tenant
                $checkBill = $pdo->prepare("SELECT id FROM vendor_bills WHERE id = ? AND tenant_id = ?");
                $checkBill->execute([$id, $tenant_id]);
                if (!$checkBill->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Bill not found or you do not have permission']);
                    exit;
                }
                $stmt = $pdo->prepare("UPDATE vendor_bills SET vendor_id=?, bill_number=?, bill_date=?, due_date=?, subtotal=?, tax_rate=?, tax_amount=?, discount_amount=?, total_amount=?, notes=? WHERE id=? AND tenant_id=?");
                $stmt->execute([$vendor_id, $bill_number, $bill_date, $due_date, $subtotal, $tax_rate, $tax_amount, $discount_amount, $total_amount, $notes, $id, $tenant_id]);
                echo json_encode(['success' => true, 'message' => 'Bill updated successfully']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'delete_bill') {
        $id = $_POST['id'] ?? 0;
        try {
            // Verify bill belongs to this tenant
            $check = $pdo->prepare("SELECT id FROM vendor_bills WHERE id = ? AND tenant_id = ?");
            $check->execute([$id, $session_tenant_id]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Bill not found']);
                exit;
            }
            $stmt = $pdo->prepare("DELETE FROM vendor_bills WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $session_tenant_id]);
            echo json_encode(['success' => true, 'message' => 'Bill deleted']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_bill') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM vendor_bills WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $session_tenant_id]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit;
    }
    
    if ($action === 'add_payment') {
        $bill_id = $_POST['bill_id'] ?? 0;
        $amount = (float)($_POST['amount'] ?? 0);
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $reference_number = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        try {
            $pdo->beginTransaction();
            
            // Get current bill (verify ownership)
            $stmt = $pdo->prepare("SELECT * FROM vendor_bills WHERE id = ? AND tenant_id = ? FOR UPDATE");
            $stmt->execute([$bill_id, $session_tenant_id]);
            $bill = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$bill) {
                throw new Exception('Bill not found');
            }
            
            $new_paid = $bill['amount_paid'] + $amount;
            $new_status = ($new_paid >= $bill['total_amount']) ? 'paid' : 'pending';
            
            // Insert payment record
            $stmt = $pdo->prepare("INSERT INTO bill_payments (tenant_id, bill_id, payment_date, amount, payment_method, reference_number, notes, created_by) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$session_tenant_id, $bill_id, $payment_date, $amount, $payment_method, $reference_number, $notes, $user_id]);
            
            // Update bill
            $stmt = $pdo->prepare("UPDATE vendor_bills SET amount_paid = ?, status = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$new_paid, $new_status, $bill_id, $session_tenant_id]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Payment recorded successfully']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_vendor_options') {
        $stmt = $pdo->prepare("SELECT id, vendor_name FROM vendors WHERE tenant_id = ? AND status = 'active' ORDER BY vendor_name");
        $stmt->execute([$session_tenant_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    
    if ($action === 'get_dashboard_stats') {
        // Total unpaid bills for this tenant
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount - amount_paid), 0) as total FROM vendor_bills WHERE tenant_id = ? AND status IN ('pending','overdue')");
        $stmt->execute([$session_tenant_id]);
        $unpaid = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Recent bills
        $stmt = $pdo->prepare("SELECT b.*, v.vendor_name FROM vendor_bills b LEFT JOIN vendors v ON b.vendor_id = v.id WHERE b.tenant_id = ? ORDER BY b.created_at DESC LIMIT 5");
        $stmt->execute([$session_tenant_id]);
        $recent_bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['unpaid_total' => $unpaid, 'recent_bills' => $recent_bills]);
        exit;
    }
    
    if ($action === 'export_bills') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=vendor_bills_export_'.date('Y-m-d').'.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['ID', 'Vendor Name', 'Bill Number', 'Bill Date', 'Due Date', 'Subtotal', 'Tax Amount', 'Discount', 'Total Amount', 'Paid Amount', 'Balance', 'Status', 'Notes']);
        
        $search = $_GET['search'] ?? '';
        $status_filter = $_GET['status'] ?? '';
        
        $where = ["b.tenant_id = ?"];
        $params = [$session_tenant_id];
        
        if (!empty($search)) {
            $where[] = "(b.bill_number LIKE ? OR v.vendor_name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if (!empty($status_filter)) {
            $where[] = "b.status = ?";
            $params[] = $status_filter;
        }
        
        $where_clause = "WHERE " . implode(" AND ", $where);
        
        $sql = "SELECT b.id, v.vendor_name, b.bill_number, b.bill_date, b.due_date, b.subtotal, b.tax_amount, b.discount_amount, b.total_amount, b.amount_paid, (b.total_amount - b.amount_paid) as balance, b.status, b.notes 
                FROM vendor_bills b 
                LEFT JOIN vendors v ON b.vendor_id = v.id 
                $where_clause 
                ORDER BY b.bill_date DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }
    
    if ($action === 'import_bills') {
        if (!isset($_FILES['excel_file'])) {
            echo json_encode(['success' => false, 'message' => 'No file selected!']);
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
            
            // Pre-fetch vendors for name mapping
            $stmt = $pdo->prepare("SELECT id, vendor_name FROM vendors WHERE tenant_id = ?");
            $stmt->execute([$session_tenant_id]);
            $vendors_map = [];
            while ($v = $stmt->fetch()) {
                $vendors_map[strtolower($v['vendor_name'])] = $v['id'];
            }
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $line++;
                // Columns: Vendor Name, Bill Number, Date (Y-m-d), Due Date (Y-m-d), Subtotal, Tax %, Discount
                $vendor_name = trim($data[0] ?? '');
                $bill_number = trim($data[1] ?? '');
                $bill_date = trim($data[2] ?? date('Y-m-d'));
                $due_date = trim($data[3] ?? date('Y-m-d', strtotime('+30 days')));
                $subtotal = (float)(str_replace(['$', ','], '', $data[4] ?? 0));
                $tax_rate = (float)(str_replace(['%', ','], '', $data[5] ?? 0));
                $discount = (float)(str_replace(['$', ','], '', $data[6] ?? 0));
                $notes = trim($data[7] ?? '');
                
                if (empty($vendor_name) || empty($bill_number)) continue;
                
                $vendor_id = $vendors_map[strtolower($vendor_name)] ?? null;
                if (!$vendor_id) {
                    $errors[] = "Line $line: Vendor '$vendor_name' not found. Please add the vendor first.";
                    continue;
                }
                
                $tax_amount = $subtotal * ($tax_rate / 100);
                $total_amount = $subtotal + $tax_amount - $discount;
                
                // Check for duplicate bill number for this tenant
                $stmt = $pdo->prepare("SELECT id FROM vendor_bills WHERE tenant_id = ? AND bill_number = ?");
                $stmt->execute([$session_tenant_id, $bill_number]);
                if ($stmt->fetch()) {
                    $errors[] = "Line $line: Bill #$bill_number already exists.";
                    continue;
                }
                
                $stmt = $pdo->prepare("INSERT INTO vendor_bills (tenant_id, vendor_id, bill_number, bill_date, due_date, subtotal, tax_rate, tax_amount, discount_amount, total_amount, notes, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$session_tenant_id, $vendor_id, $bill_number, $bill_date, $due_date, $subtotal, $tax_rate, $tax_amount, $discount, $total_amount, $notes, 'pending', $user_id]);
                $imported++;
            }
            
            $pdo->commit();
            $msg = "Import successful! ($imported bills imported)";
            if (count($errors) > 0) $msg .= "<br>Warning: " . count($errors) . " rows skipped.";
            echo json_encode(['success' => true, 'message' => $msg]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        fclose($handle);
        exit;
    }
    
    if ($action === 'download_sample') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=vendor_bills_sample.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, ['Vendor Name', 'Bill Number', 'Bill Date (YYYY-MM-DD)', 'Due Date (YYYY-MM-DD)', 'Subtotal', 'Tax Rate (%)', 'Discount Amount', 'Notes']);
        
        $stmt = $pdo->prepare("SELECT vendor_name FROM vendors WHERE tenant_id = ? LIMIT 1");
        $stmt->execute([$session_tenant_id]);
        $v = $stmt->fetch();
        $v_name = $v ? $v['vendor_name'] : 'Sample Vendor';
        
        fputcsv($output, [$v_name, 'INV-1001', date('Y-m-d'), date('Y-m-d', strtotime('+30 days')), '1500.00', '5', '0', 'Office equipment']);
        fputcsv($output, [$v_name, 'INV-1002', date('Y-m-d'), date('Y-m-d', strtotime('+30 days')), '500.00', '0', '50.00', 'Maintenance service']);
        fclose($output);
        exit;
    }
    
    exit;
}

// Include header
require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Expenses & Bills Management - <?= htmlspecialchars($tenant_name) ?> | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root { --curdun-violet: #2D1859; --curdun-yellow: #F5C410; --curdun-violet-light: #4B2C85; }
        body { background: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .page-header { 
            background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light)); 
            border-radius: 16px; 
            padding: 20px; 
            margin-bottom: 25px; 
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .page-header h1, .page-header p { color: white; margin: 0; }
        .page-header .company-badge {
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
        }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .stat-card h3 { font-size: 32px; margin: 0; color: var(--curdun-violet); }
        
        .tabs-container { display: flex; gap: 10px; margin-bottom: 25px; flex-wrap: wrap; }
        .tab-btn { background: white; border: none; padding: 12px 30px; border-radius: 12px; font-weight: 600; cursor: pointer; transition: 0.3s; }
        .tab-btn.active { background: var(--curdun-violet); color: white; }
        
        .filters-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 25px; display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 150px; }
        
        .btn-primary-custom { background: var(--curdun-yellow); color: var(--curdun-violet); border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.3s; }
        .btn-primary-custom:hover { background: #D4A70C; transform: translateY(-1px); }
        
        .btn-violet { background: var(--curdun-violet); color: white; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer; transition: 0.3s; }
        .btn-violet:hover { background: var(--curdun-violet-light); transform: translateY(-1px); }
        
        .table-container { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .modal-header { background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light)); color: white; }
        .modal-header .close { color: white; opacity: 1; }
        
        .alert-custom { position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: center; }
            .filters-card { flex-direction: column; }
            .filter-group { width: 100%; }
            .tabs-container { flex-direction: column; }
            .tab-btn { width: 100%; text-align: center; }
        }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>
    
    <div class="page-header">
        <div>
            <h1><i class="fas fa-file-invoice-dollar"></i> Vendor Bills & Expenses Management</h1>
            <p>Manage vendors, bills, and track payments for <?= htmlspecialchars($tenant_name) ?></p>
        </div>
        <div class="d-flex gap-3 align-items-center">
            <span class="company-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($tenant_name) ?></span>
            <div>
                <button class="btn btn-light" id="addVendorBtn"><i class="fas fa-plus"></i> Add Vendor</button>
                <button class="btn btn-light" id="addBillBtn"><i class="fas fa-plus"></i> New Bill</button>
                <div class="btn-group ml-2">
                    <button class="btn btn-light dropdown-toggle" data-toggle="dropdown"><i class="fas fa-file-csv"></i> CSV</button>
                    <div class="dropdown-menu dropdown-menu-right">
                        <a class="dropdown-item" href="?ajax_action=export_bills" id="exportBillsBtn"><i class="fas fa-download"></i> Export Bills</a>
                        <a class="dropdown-item" href="#" data-toggle="modal" data-target="#importModal"><i class="fas fa-upload"></i> Import Bills</a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="?ajax_action=download_sample"><i class="fas fa-file-download"></i> Download Sample</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tabs -->
    <div class="tabs-container">
        <button class="tab-btn active" data-tab="dashboard"><i class="fas fa-chart-line"></i> Dashboard</button>
        <button class="tab-btn" data-tab="bills"><i class="fas fa-file-invoice"></i> Vendor Bills</button>
        <button class="tab-btn" data-tab="vendors"><i class="fas fa-truck"></i> Vendors</button>
    </div>
    
    <!-- Dashboard Tab Content -->
    <div id="dashboardTab" class="tab-content" style="display: block;">
        <div class="row">
            <div class="col-md-4">
                <div class="stat-card">
                    <i class="fas fa-dollar-sign fa-2x" style="color: var(--curdun-violet);"></i>
                    <h3 id="unpaidTotal">$0</h3>
                    <p>Total Unpaid Bills</p>
                </div>
            </div>
        </div>
        <div class="table-container mt-3">
            <div class="card-header bg-white border-bottom py-3"><strong><i class="fas fa-clock"></i> Recent Vendor Bills</strong></div>
            <div id="recentBillsTable">
                <table class="table">
                    <thead><tr><th>Vendor</th><th>Bill #</th><th>Amount</th><th>Paid</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody id="recentBillsBody"></tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Filters Panel (for Bills & Vendors) -->
    <div class="filters-card" id="filtersPanel" style="display: none;">
        <div class="filter-group"><label><i class="fas fa-search"></i> Search</label><input type="text" id="searchInput" class="form-control" placeholder="Search..."></div>
        <div class="filter-group" id="statusFilterDiv" style="display: none;"><label><i class="fas fa-filter"></i> Status</label><select id="statusFilter" class="form-control"><option value="">All</option><option value="pending">Pending</option><option value="paid">Paid</option><option value="overdue">Overdue</option></select></div>
        <div class="filter-group"><button class="btn-primary-custom" id="applyFilters"><i class="fas fa-filter"></i> Filter</button></div>
    </div>
    
    <!-- Bills Tab -->
    <div id="billsTab" class="tab-content" style="display: none;">
        <div class="table-container" id="billsTableContainer"></div>
        <div id="billsPagination" class="text-center mt-3"></div>
    </div>
    
    <!-- Vendors Tab -->
    <div id="vendorsTab" class="tab-content" style="display: none;">
        <div class="table-container" id="vendorsTableContainer"></div>
        <div id="vendorsPagination" class="text-center mt-3"></div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header">
                <h5><i class="fas fa-file-import"></i> Import Vendor Bills</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="importForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Fadlan soo geli faylka CSV oo kaliya. 
                        <a href="?ajax_action=download_sample" class="alert-link">Halkan ka soo deji sample-ka</a>.
                    </div>
                    <div class="form-group">
                        <label>Dooro Faylka (CSV)</label>
                        <input type="file" name="excel_file" class="form-control" accept=".csv" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button>
                    <button type="submit" class="btn btn-violet">Soo geli (Import)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Vendor Modal -->
<div class="modal fade" id="vendorModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header" style="border-radius: 16px 16px 0 0;">
                <h5><i class="fas fa-truck"></i> Vendor Details</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="vendorForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="vendorId">
                    <input type="hidden" name="ajax_action" value="save_vendor">
                    <div class="row">
                        <div class="col-md-6"><div class="form-group"><label><i class="fas fa-building"></i> Vendor Name *</label><input type="text" name="vendor_name" id="vendorName" class="form-control" required></div></div>
                        <div class="col-md-6"><div class="form-group"><label><i class="fas fa-user"></i> Contact Person</label><input type="text" name="contact_person" id="contactPerson" class="form-control"></div></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6"><div class="form-group"><label><i class="fas fa-phone"></i> Phone</label><input type="text" name="phone" id="vendorPhone" class="form-control"></div></div>
                        <div class="col-md-6"><div class="form-group"><label><i class="fas fa-envelope"></i> Email</label><input type="email" name="email" id="vendorEmail" class="form-control"></div></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6"><div class="form-group"><label><i class="fas fa-id-card"></i> Tax Number</label><input type="text" name="tax_number" id="taxNumber" class="form-control"></div></div>
                        <div class="col-md-6"><div class="form-group"><label><i class="fas fa-calendar-alt"></i> Payment Terms</label><select name="payment_terms" id="paymentTerms" class="form-control"><option value="net_15">Net 15</option><option value="net_30">Net 30</option><option value="net_45">Net 45</option><option value="cod">COD</option></select></div></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6"><div class="form-group"><label><i class="fas fa-university"></i> Bank Name</label><input type="text" name="bank_name" id="bankName" class="form-control"></div></div>
                        <div class="col-md-6"><div class="form-group"><label><i class="fas fa-credit-card"></i> Bank Account</label><input type="text" name="bank_account" id="bankAccount" class="form-control"></div></div>
                    </div>
                    <div class="form-group"><label><i class="fas fa-map-marker-alt"></i> Address</label><textarea name="address" id="vendorAddress" class="form-control" rows="2"></textarea></div>
                    <div class="form-group"><label><i class="fas fa-sticky-note"></i> Notes</label><textarea name="notes" id="vendorNotes" class="form-control" rows="2"></textarea></div>
                    <div class="form-group"><label><i class="fas fa-toggle-on"></i> Status</label><select name="status" id="vendorStatus" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-violet">Save Vendor</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bill Modal -->
<div class="modal fade" id="billModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header" style="border-radius: 16px 16px 0 0;">
                <h5><i class="fas fa-file-invoice"></i> Bill Details</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="billForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="billId">
                    <input type="hidden" name="ajax_action" value="save_bill">
                    <div class="row">
                        <div class="col-md-6"><div class="form-group"><label><i class="fas fa-truck"></i> Vendor *</label><select name="vendor_id" id="billVendorId" class="form-control" required><option value="">Select Vendor</option></select></div></div>
                        <div class="col-md-6"><div class="form-group"><label><i class="fas fa-hashtag"></i> Bill Number *</label><input type="text" name="bill_number" id="billNumber" class="form-control" required></div></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6"><div class="form-group"><label><i class="fas fa-calendar"></i> Bill Date</label><input type="date" name="bill_date" id="billDate" class="form-control"></div></div>
                        <div class="col-md-6"><div class="form-group"><label><i class="fas fa-calendar-alt"></i> Due Date</label><input type="date" name="due_date" id="dueDate" class="form-control"></div></div>
                    </div>
                    <div class="row">
                        <div class="col-md-4"><div class="form-group"><label><i class="fas fa-dollar-sign"></i> Subtotal</label><input type="number" step="0.01" name="subtotal" id="subtotal" class="form-control" onchange="calculateTotal()"></div></div>
                        <div class="col-md-4"><div class="form-group"><label><i class="fas fa-percent"></i> Tax Rate (%)</label><input type="number" step="0.01" name="tax_rate" id="taxRate" class="form-control" value="0" onchange="calculateTotal()"></div></div>
                        <div class="col-md-4"><div class="form-group"><label><i class="fas fa-tag"></i> Discount</label><input type="number" step="0.01" name="discount_amount" id="discountAmount" class="form-control" value="0" onchange="calculateTotal()"></div></div>
                    </div>
                    <div class="form-group"><label><i class="fas fa-calculator"></i> Total Amount</label><input type="text" id="totalAmountDisplay" class="form-control" readonly style="background:#e9ecef; font-weight:bold;"></div>
                    <div class="form-group"><label><i class="fas fa-sticky-note"></i> Notes</label><textarea name="notes" id="billNotes" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-violet">Save Bill</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #0F7A3A, #20c997); border-radius: 16px 16px 0 0; color: white;">
                <h5><i class="fas fa-money-bill"></i> Record Payment</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form id="paymentForm">
                <div class="modal-body">
                    <input type="hidden" name="bill_id" id="paymentBillId">
                    <input type="hidden" name="ajax_action" value="add_payment">
                    <div class="form-group"><label>Vendor:</label> <strong id="paymentVendorName"></strong></div>
                    <div class="form-group"><label>Bill #:</label> <strong id="paymentBillNumber"></strong></div>
                    <div class="form-group"><label>Balance Due:</label> <strong class="text-danger" id="paymentBalance">$0.00</strong></div>
                    <div class="form-group"><label><i class="fas fa-dollar-sign"></i> Payment Amount *</label><input type="number" step="0.01" name="amount" id="paymentAmount" class="form-control" required></div>
                    <div class="form-group"><label><i class="fas fa-calendar"></i> Payment Date</label><input type="date" name="payment_date" id="paymentDate" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                    <div class="form-group"><label><i class="fas fa-credit-card"></i> Payment Method</label><select name="payment_method" id="paymentMethod" class="form-control"><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option><option value="check">Check</option><option value="credit_card">Credit Card</option></select></div>
                    <div class="form-group"><label><i class="fas fa-hashtag"></i> Reference Number</label><input type="text" name="reference_number" id="referenceNumber" class="form-control" placeholder="Check #, Transaction ID..."></div>
                    <div class="form-group"><label><i class="fas fa-sticky-note"></i> Notes</label><textarea name="notes" id="paymentNotes" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Record Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentTab = 'dashboard';
let currentPage = 1;
let currentModule = 'bills';

function showAlert(type, msg) {
    const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
    const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
    $('#alert-placeholder').html(`<div class="alert alert-custom ${alertClass} alert-dismissible fade show"><i class="fas ${icon} mr-2"></i> ${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
    setTimeout(() => $('.alert-custom').fadeOut(5000, function() { $(this).remove(); }), 5000);
}

function loadDashboard() {
    $.post(window.location.href, {ajax_action: 'get_dashboard_stats'}, function(res) {
        $('#unpaidTotal').text('$' + parseFloat(res.unpaid_total).toLocaleString(undefined, {minimumFractionDigits:2}));
        let html = '';
        res.recent_bills.forEach(bill => {
            let balance = bill.total_amount - bill.amount_paid;
            html += `<tr>
                <td>${escapeHtml(bill.vendor_name)}</td>
                <td><strong>${escapeHtml(bill.bill_number)}</strong></td>
                <td>$${parseFloat(bill.total_amount).toFixed(2)}</td>
                <td>$${parseFloat(bill.amount_paid).toFixed(2)}</td>
                <td><span class="badge badge-${bill.status == 'paid' ? 'success' : 'warning'}">${bill.status}</span></td>
                <td><button class="btn btn-sm btn-outline-primary view-bill" data-id="${bill.id}"><i class="fas fa-eye"></i> View</button></td>
            </tr>`;
        });
        $('#recentBillsBody').html(html);
    }, 'json');
}

function loadBills() {
    let data = {
        ajax_action: 'get_bills',
        page: currentPage,
        search: $('#searchInput').val(),
        status: $('#statusFilter').val()
    };
    $('#billsTableContainer').html('<div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading bills...</p></div>');
    $.post(window.location.href, data, function(res) {
        $('#billsTableContainer').html(res.table_html);
        let pages = '';
        for(let i=1; i<=res.total_pages; i++) {
            pages += `<button class="btn btn-sm ${i==res.current_page ? 'btn-violet' : 'btn-secondary'} mx-1 page-btn" data-page="${i}">${i}</button>`;
        }
        $('#billsPagination').html(pages);
        attachBillEvents();
    }, 'json');
}

function loadVendors() {
    let data = {
        ajax_action: 'get_vendors',
        page: currentPage,
        search: $('#searchInput').val()
    };
    $('#vendorsTableContainer').html('<div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading vendors...</p></div>');
    $.post(window.location.href, data, function(res) {
        $('#vendorsTableContainer').html(res.table_html);
        let pages = '';
        for(let i=1; i<=res.total_pages; i++) {
            pages += `<button class="btn btn-sm ${i==res.current_page ? 'btn-violet' : 'btn-secondary'} mx-1 page-btn" data-page="${i}">${i}</button>`;
        }
        $('#vendorsPagination').html(pages);
        attachVendorEvents();
    }, 'json');
}

function attachBillEvents() {
    $('.edit-bill').click(function() {
        let id = $(this).data('id');
        $.post(window.location.href, {ajax_action: 'get_bill', id: id}, function(res) {
            $('#billId').val(res.id);
            $('#billVendorId').val(res.vendor_id);
            $('#billNumber').val(res.bill_number);
            $('#billDate').val(res.bill_date);
            $('#dueDate').val(res.due_date);
            $('#subtotal').val(res.subtotal);
            $('#taxRate').val(res.tax_rate);
            $('#discountAmount').val(res.discount_amount);
            $('#billNotes').val(res.notes);
            calculateTotal();
            $('#billModal').modal('show');
        }, 'json');
    });
    $('.delete-bill').click(function() {
        if(confirm('Are you sure you want to delete this bill?')) {
            $.post(window.location.href, {ajax_action: 'delete_bill', id: $(this).data('id')}, function(res) {
                showAlert(res.success ? 'success' : 'error', res.message);
                if(res.success) loadBills();
            }, 'json');
        }
    });
    $('.add-payment').click(function() {
        $('#paymentBillId').val($(this).data('id'));
        $('#paymentVendorName').text($(this).data('vendor'));
        $('#paymentBillNumber').text($(this).data('bill'));
        $('#paymentBalance').text('$' + parseFloat($(this).data('balance')).toFixed(2));
        $('#paymentAmount').val('');
        $('#paymentModal').modal('show');
    });
    $('.page-btn').click(function() {
        currentPage = $(this).data('page');
        loadBills();
    });
    $('.view-bill').click(function() {
        let id = $(this).data('id');
        $.post(window.location.href, {ajax_action: 'get_bill', id: id}, function(res) {
            alert(`Bill #: ${res.bill_number}\nVendor ID: ${res.vendor_id}\nAmount: $${parseFloat(res.total_amount).toFixed(2)}\nStatus: ${res.status}`);
        }, 'json');
    });
}

function attachVendorEvents() {
    $('.edit-vendor').click(function() {
        let id = $(this).data('id');
        $.post(window.location.href, {ajax_action: 'get_vendor', id: id}, function(res) {
            $('#vendorId').val(res.id);
            $('#vendorName').val(res.vendor_name);
            $('#contactPerson').val(res.contact_person);
            $('#vendorPhone').val(res.phone);
            $('#vendorEmail').val(res.email);
            $('#vendorAddress').val(res.address);
            $('#taxNumber').val(res.tax_number);
            $('#paymentTerms').val(res.payment_terms);
            $('#bankName').val(res.bank_name);
            $('#bankAccount').val(res.bank_account);
            $('#vendorNotes').val(res.notes);
            $('#vendorStatus').val(res.status);
            $('#vendorModal').modal('show');
        }, 'json');
    });
    $('.delete-vendor').click(function() {
        if(confirm('Are you sure you want to delete this vendor? This will also delete all associated bills.')) {
            $.post(window.location.href, {ajax_action: 'delete_vendor', id: $(this).data('id')}, function(res) {
                showAlert(res.success ? 'success' : 'error', res.message);
                if(res.success) loadVendors();
            }, 'json');
        }
    });
    $('.page-btn').click(function() {
        currentPage = $(this).data('page');
        loadVendors();
    });
}

function calculateTotal() {
    let subtotal = parseFloat($('#subtotal').val()) || 0;
    let taxRate = parseFloat($('#taxRate').val()) || 0;
    let discount = parseFloat($('#discountAmount').val()) || 0;
    let taxAmount = subtotal * (taxRate / 100);
    let total = subtotal + taxAmount - discount;
    $('#totalAmountDisplay').val('$' + total.toFixed(2));
}

function escapeHtml(str) {
    if(!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if(m === '&') return '&amp;';
        if(m === '<') return '&lt;';
        if(m === '>') return '&gt;';
        return m;
    });
}

// Tab switching
$('.tab-btn').click(function() {
    currentTab = $(this).data('tab');
    $('.tab-btn').removeClass('active');
    $(this).addClass('active');
    $('.tab-content').hide();
    $('#' + currentTab + 'Tab').show();
    
    if(currentTab === 'dashboard') {
        $('#filtersPanel').hide();
        loadDashboard();
    } else if(currentTab === 'bills') {
        $('#filtersPanel').show();
        $('#statusFilterDiv').show();
        currentPage = 1;
        loadBills();
        // Update export link with current filters
        let search = $('#searchInput').val();
        let status = $('#statusFilter').val();
        $('#exportBillsBtn').attr('href', `?ajax_action=export_bills&search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}`);
    } else if(currentTab === 'vendors') {
        $('#filtersPanel').show();
        $('#statusFilterDiv').hide();
        currentPage = 1;
        loadVendors();
    }
});

// Form submissions
$('#vendorForm').submit(function(e) {
    e.preventDefault();
    $.post(window.location.href, $(this).serialize(), function(res) {
        showAlert(res.success ? 'success' : 'error', res.message);
        if(res.success) {
            $('#vendorModal').modal('hide');
            loadVendors();
        }
    }, 'json');
});

$('#billForm').submit(function(e) {
    e.preventDefault();
    $.post(window.location.href, $(this).serialize(), function(res) {
        showAlert(res.success ? 'success' : 'error', res.message);
        if(res.success) {
            $('#billModal').modal('hide');
            loadBills();
            loadDashboard();
        }
    }, 'json');
});

$('#paymentForm').submit(function(e) {
    e.preventDefault();
    let amount = parseFloat($('#paymentAmount').val());
    let balance = parseFloat($('#paymentBalance').text().replace('$', ''));
    if(amount > balance) {
        showAlert('error', 'Payment amount cannot exceed the balance due!');
        return;
    }
    $.post(window.location.href, $(this).serialize(), function(res) {
        showAlert(res.success ? 'success' : 'error', res.message);
        if(res.success) {
            $('#paymentModal').modal('hide');
            if(currentTab === 'bills') loadBills();
            loadDashboard();
        }
    }, 'json');
});

$('#importForm').submit(function(e) {
    e.preventDefault();
    let formData = new FormData(this);
    formData.append('ajax_action', 'import_bills');
    
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
            showAlert(res.success ? 'success' : 'error', res.message);
            if(res.success) {
                $('#importModal').modal('hide');
                loadBills();
                loadDashboard();
            }
        },
        error: function() {
            showAlert('error', 'Error occurred during import.');
        }
    });
});

// Add buttons
$('#addVendorBtn').click(() => {
    $('#vendorForm')[0].reset();
    $('#vendorId').val('');
    $('#vendorModal').modal('show');
});

$('#addBillBtn').click(() => {
    $('#billForm')[0].reset();
    $('#billId').val('');
    $('#billDate').val(new Date().toISOString().split('T')[0]);
    let due = new Date();
    due.setDate(due.getDate() + 30);
    $('#dueDate').val(due.toISOString().split('T')[0]);
    $('#subtotal').val(0);
    $('#taxRate').val(0);
    $('#discountAmount').val(0);
    calculateTotal();
    
    // Load vendors dropdown
    $.post(window.location.href, {ajax_action: 'get_vendor_options'}, function(res) {
        let options = '<option value="">Select Vendor</option>';
        res.forEach(v => options += `<option value="${v.id}">${escapeHtml(v.vendor_name)}</option>`);
        $('#billVendorId').html(options);
    }, 'json');
    
    $('#billModal').modal('show');
});

$('#applyFilters').click(() => {
    currentPage = 1;
    if(currentTab === 'bills') {
        loadBills();
        let search = $('#searchInput').val();
        let status = $('#statusFilter').val();
        $('#exportBillsBtn').attr('href', `?ajax_action=export_bills&search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}`);
    } else if(currentTab === 'vendors') {
        loadVendors();
    }
});

// Load dashboard initially
loadDashboard();
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>