<?php
// superadmin/expenses_management.php
// Maareynta Kharashaadka & Biilasha Vendors -faras cargo

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
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Admin';

require_once __DIR__ . '/../config/db_connect.php';

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
    
    // Insert default expense categories
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM expense_categories WHERE tenant_id = 0");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $default_categories = ['Fuel', 'Maintenance', 'Salary', 'Rent', 'Utilities', 'Insurance', 'Tax', 'Office Supplies', 'Marketing', 'Other'];
        foreach ($default_categories as $cat) {
            $pdo->prepare("INSERT INTO expense_categories (tenant_id, category_name) VALUES (0, ?)")->execute([$cat]);
        }
    }
    
} catch (PDOException $e) {
    // Log error but continue
    error_log("Table creation error: " . $e->getMessage());
}

// Get all tenants for filter
$tenants = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM tenants ORDER BY name");
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tenants = [];
}

// Get expense categories
$categories = [];
try {
    $cat_filter = ($role === 'company_admin') ? "WHERE tenant_id IN (0, ?)" : "WHERE tenant_id IN (0, ?) OR tenant_id = ?";
    $stmt = $pdo->prepare("SELECT id, category_name FROM expense_categories WHERE tenant_id = 0 OR tenant_id = ? ORDER BY category_name");
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
        $tenant_filter = isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0;
        
        $where = [];
        $params = [];
        
        if (!empty($search)) {
            $where[] = "(v.vendor_name LIKE ? OR v.contact_person LIKE ? OR v.phone LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($role === 'company_admin') {
            $where[] = "v.tenant_id = ?";
            $params[] = $session_tenant_id;
        } elseif ($tenant_filter > 0) {
            $where[] = "v.tenant_id = ?";
            $params[] = $tenant_filter;
        }
        
        $where_clause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
        
        $count_sql = "SELECT COUNT(*) as total FROM vendors v $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total / $limit);
        
        $sql = "SELECT v.*, t.name as tenant_name,
                (SELECT COUNT(*) FROM vendor_bills WHERE vendor_id = v.id) as total_bills,
                (SELECT SUM(total_amount - amount_paid) FROM vendor_bills WHERE vendor_id = v.id AND status IN ('pending','overdue')) as outstanding
                FROM vendors v
                LEFT JOIN tenants t ON v.tenant_id = t.id
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
        $tenant_id = $_POST['tenant_id'] ?? ($role === 'company_admin' ? $session_tenant_id : 0);
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
                $stmt = $pdo->prepare("UPDATE vendors SET vendor_name=?, contact_person=?, phone=?, email=?, address=?, tax_number=?, payment_terms=?, bank_name=?, bank_account=?, notes=?, status=? WHERE id=?");
                $stmt->execute([$vendor_name, $contact_person, $phone, $email, $address, $tax_number, $payment_terms, $bank_name, $bank_account, $notes, $status, $id]);
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
            $stmt = $pdo->prepare("DELETE FROM vendors WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Vendor deleted']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_vendor') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM vendors WHERE id = ?");
        $stmt->execute([$id]);
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
        $tenant_filter = isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0;
        
        $where = [];
        $params = [];
        
        if (!empty($search)) {
            $where[] = "(b.bill_number LIKE ? OR v.vendor_name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if (!empty($status_filter)) {
            $where[] = "b.status = ?";
            $params[] = $status_filter;
        }
        
        if ($role === 'company_admin') {
            $where[] = "b.tenant_id = ?";
            $params[] = $session_tenant_id;
        } elseif ($tenant_filter > 0) {
            $where[] = "b.tenant_id = ?";
            $params[] = $tenant_filter;
        }
        
        $where_clause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
        
        $count_sql = "SELECT COUNT(*) as total FROM vendor_bills b LEFT JOIN vendors v ON b.vendor_id = v.id $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total / $limit);
        
        $sql = "SELECT b.*, v.vendor_name, t.name as tenant_name,
                DATEDIFF(CURDATE(), b.due_date) as days_overdue
                FROM vendor_bills b
                LEFT JOIN vendors v ON b.vendor_id = v.id
                LEFT JOIN tenants t ON b.tenant_id = t.id
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
        $tenant_id = $_POST['tenant_id'] ?? ($role === 'company_admin' ? $session_tenant_id : 0);
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
        
        try {
            if (empty($id)) {
                $stmt = $pdo->prepare("INSERT INTO vendor_bills (tenant_id, vendor_id, bill_number, bill_date, due_date, subtotal, tax_rate, tax_amount, discount_amount, total_amount, notes, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$tenant_id, $vendor_id, $bill_number, $bill_date, $due_date, $subtotal, $tax_rate, $tax_amount, $discount_amount, $total_amount, $notes, 'pending', $user_id]);
                echo json_encode(['success' => true, 'message' => 'Bill added successfully']);
            } else {
                $stmt = $pdo->prepare("UPDATE vendor_bills SET vendor_id=?, bill_number=?, bill_date=?, due_date=?, subtotal=?, tax_rate=?, tax_amount=?, discount_amount=?, total_amount=?, notes=? WHERE id=?");
                $stmt->execute([$vendor_id, $bill_number, $bill_date, $due_date, $subtotal, $tax_rate, $tax_amount, $discount_amount, $total_amount, $notes, $id]);
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
            $stmt = $pdo->prepare("DELETE FROM vendor_bills WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Bill deleted']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_bill') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM vendor_bills WHERE id = ?");
        $stmt->execute([$id]);
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
            
            // Get current bill
            $stmt = $pdo->prepare("SELECT * FROM vendor_bills WHERE id = ? FOR UPDATE");
            $stmt->execute([$bill_id]);
            $bill = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$bill) {
                throw new Exception('Bill not found');
            }
            
            $new_paid = $bill['amount_paid'] + $amount;
            $new_status = ($new_paid >= $bill['total_amount']) ? 'paid' : 'pending';
            
            // Insert payment record
            $stmt = $pdo->prepare("INSERT INTO bill_payments (tenant_id, bill_id, payment_date, amount, payment_method, reference_number, notes, created_by) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$bill['tenant_id'], $bill_id, $payment_date, $amount, $payment_method, $reference_number, $notes, $user_id]);
            
            // Update bill
            $stmt = $pdo->prepare("UPDATE vendor_bills SET amount_paid = ?, status = ? WHERE id = ?");
            $stmt->execute([$new_paid, $new_status, $bill_id]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Payment recorded successfully']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_vendor_options') {
        $tenant_filter = isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0;
        $where = "status = 'active'";
        $params = [];
        
        if ($role === 'company_admin') {
            $where .= " AND tenant_id = ?";
            $params[] = $session_tenant_id;
        } elseif ($tenant_filter > 0) {
            $where .= " AND tenant_id = ?";
            $params[] = $tenant_filter;
        }
        
        $stmt = $pdo->prepare("SELECT id, vendor_name FROM vendors WHERE $where ORDER BY vendor_name");
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    
    if ($action === 'get_dashboard_stats') {
        $tenant_filter = isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0;
        $where = "";
        $params = [];
        
        if ($role === 'company_admin') {
            $where = "WHERE tenant_id = ?";
            $params[] = $session_tenant_id;
        } elseif ($tenant_filter > 0) {
            $where = "WHERE tenant_id = ?";
            $params[] = $tenant_filter;
        }
        
        // Total unpaid bills
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount - amount_paid), 0) as total FROM vendor_bills $where AND status IN ('pending','overdue')");
        $stmt->execute($params);
        $unpaid = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Recent bills
        $stmt = $pdo->prepare("SELECT b.*, v.vendor_name FROM vendor_bills b LEFT JOIN vendors v ON b.vendor_id = v.id $where ORDER BY b.created_at DESC LIMIT 5");
        $stmt->execute($params);
        $recent_bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['unpaid_total' => $unpaid, 'recent_bills' => $recent_bills]);
        if ($action === 'export_bills') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=superadmin_vendor_bills_'.date('Y-m-d').'.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['ID', 'Tenant Name', 'Vendor Name', 'Bill Number', 'Bill Date', 'Due Date', 'Total Amount', 'Paid Amount', 'Balance', 'Status', 'Notes']);
        
        $search = $_GET['search'] ?? '';
        $status_filter = $_GET['status'] ?? '';
        $tenant_filter = isset($_GET['tenant']) ? (int)$_GET['tenant'] : 0;
        
        $where = [];
        $params = [];
        
        if (!empty($search)) {
            $where[] = "(b.bill_number LIKE ? OR v.vendor_name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if (!empty($status_filter)) {
            $where[] = "b.status = ?";
            $params[] = $status_filter;
        }
        
        if ($role === 'company_admin') {
            $where[] = "b.tenant_id = ?";
            $params[] = $session_tenant_id;
        } elseif ($tenant_filter > 0) {
            $where[] = "b.tenant_id = ?";
            $params[] = $tenant_filter;
        }
        
        $where_clause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
        
        $sql = "SELECT b.id, t.name as tenant_name, v.vendor_name, b.bill_number, b.bill_date, b.due_date, b.total_amount, b.amount_paid, (b.total_amount - b.amount_paid) as balance, b.status, b.notes 
                FROM vendor_bills b 
                LEFT JOIN vendors v ON b.vendor_id = v.id 
                LEFT JOIN tenants t ON b.tenant_id = t.id
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
            
            // Pre-fetch tenants
            $tenants_map = [];
            $stmt = $pdo->query("SELECT id, name FROM tenants");
            while ($t = $stmt->fetch()) {
                $tenants_map[strtolower($t['name'])] = $t['id'];
            }
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $line++;
                // Columns: Tenant Name, Vendor Name, Bill Number, Date (Y-m-d), Due Date (Y-m-d), Subtotal, Tax %, Discount, Notes
                $tenant_name = trim($data[0] ?? '');
                $vendor_name = trim($data[1] ?? '');
                $bill_number = trim($data[2] ?? '');
                $bill_date = trim($data[3] ?? date('Y-m-d'));
                $due_date = trim($data[4] ?? date('Y-m-d', strtotime('+30 days')));
                $subtotal = (float)(str_replace(['$', ','], '', $data[5] ?? 0));
                $tax_rate = (float)(str_replace(['%', ','], '', $data[6] ?? 0));
                $discount = (float)(str_replace(['$', ','], '', $data[7] ?? 0));
                $notes = trim($data[8] ?? '');
                
                if (empty($tenant_name) || empty($vendor_name) || empty($bill_number)) continue;
                
                $t_id = $tenants_map[strtolower($tenant_name)] ?? null;
                if (!$t_id) {
                    $errors[] = "Line $line: Tenant '$tenant_name' not found.";
                    continue;
                }
                
                // Find or create vendor for this tenant
                $stmt = $pdo->prepare("SELECT id FROM vendors WHERE tenant_id = ? AND LOWER(vendor_name) = ?");
                $stmt->execute([$t_id, strtolower($vendor_name)]);
                $vendor_id = $stmt->fetchColumn();
                
                if (!$vendor_id) {
                    $stmt = $pdo->prepare("INSERT INTO vendors (tenant_id, vendor_name, status, created_by) VALUES (?, ?, 'active', ?)");
                    $stmt->execute([$t_id, $vendor_name, $user_id]);
                    $vendor_id = $pdo->lastInsertId();
                }
                
                $tax_amount = $subtotal * ($tax_rate / 100);
                $total_amount = $subtotal + $tax_amount - $discount;
                
                // Check for duplicate
                $stmt = $pdo->prepare("SELECT id FROM vendor_bills WHERE tenant_id = ? AND bill_number = ?");
                $stmt->execute([$t_id, $bill_number]);
                if ($stmt->fetch()) {
                    $errors[] = "Line $line: Bill #$bill_number already exists for tenant '$tenant_name'.";
                    continue;
                }
                
                $stmt = $pdo->prepare("INSERT INTO vendor_bills (tenant_id, vendor_id, bill_number, bill_date, due_date, subtotal, tax_rate, tax_amount, discount_amount, total_amount, notes, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$t_id, $vendor_id, $bill_number, $bill_date, $due_date, $subtotal, $tax_rate, $tax_amount, $discount, $total_amount, $notes, 'pending', $user_id]);
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
        header('Content-Disposition: attachment; filename=superadmin_bills_sample.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, ['Tenant Name', 'Vendor Name', 'Bill Number', 'Bill Date (YYYY-MM-DD)', 'Due Date (YYYY-MM-DD)', 'Subtotal', 'Tax Rate (%)', 'Discount Amount', 'Notes']);
        
        $t_name = count($tenants) > 0 ? $tenants[0]['name'] : 'Sample Tenant';
        fputcsv($output, [$t_name, 'Sample Vendor', 'INV-1001', date('Y-m-d'), date('Y-m-d', strtotime('+30 days')), '1500.00', '5', '0', 'Initial bill']);
        fclose($output);
        exit;
    }
    
    exit;
    }
}

// Include header
require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Expenses & Bills Management | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root { --curdun-violet: #2D1859; --curdun-yellow: #F5C410; }
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .page-header { background: linear-gradient(135deg, var(--curdun-violet), #4B2C85); border-radius: 16px; padding: 20px; margin-bottom: 25px; color: white; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .stat-card h3 { font-size: 32px; margin: 0; color: var(--curdun-violet); }
        .tabs-container { display: flex; gap: 10px; margin-bottom: 25px; }
        .tab-btn { background: white; border: none; padding: 12px 30px; border-radius: 12px; font-weight: 600; cursor: pointer; }
        .tab-btn.active { background: var(--curdun-violet); color: white; }
        .filters-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 25px; display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 150px; }
        .btn-primary-custom { background: var(--curdun-yellow); color: var(--curdun-violet); border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .table-container { background: white; border-radius: 12px; overflow: hidden; }
        .modal-header { background: linear-gradient(135deg, var(--curdun-violet), #4B2C85); color: white; }
        .modal-header .close { color: white; }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>
    
    <div class="page-header">
        <h1><i class="fas fa-file-invoice-dollar"></i> Vendor Bills & Expenses Management</h1>
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
            <div class="card-header"><strong>Recent Vendor Bills</strong></div>
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
        <div class="filter-group"><label>Search</label><input type="text" id="searchInput" class="form-control" placeholder="Search..."></div>
        <div class="filter-group"><label>Tenant</label><select id="tenantFilter" class="form-control"><option value="0">All Tenants</option><?php foreach($tenants as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="filter-group" id="statusFilterDiv" style="display: none;"><label>Status</label><select id="statusFilter" class="form-control"><option value="">All</option><option value="pending">Pending</option><option value="paid">Paid</option><option value="overdue">Overdue</option></select></div>
        <div class="filter-group"><button class="btn-primary-custom" id="applyFilters"><i class="fas fa-filter"></i> Filter</button></div>
    </div>
    
    <!-- Bills Tab -->
    <div id="billsTab" class="tab-content" style="display: none;">
        <div class="table-container" id="billsTableContainer"></div>
        <div id="billsPagination"></div>
    </div>
    
    <!-- Vendors Tab -->
    <div id="vendorsTab" class="tab-content" style="display: none;">
        <div class="table-container" id="vendorsTableContainer"></div>
        <div id="vendorsPagination"></div>
    </div>
</div>

<!-- Vendor Modal -->
<div class="modal fade" id="vendorModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h5><i class="fas fa-truck"></i> Vendor Details</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
            <form id="vendorForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="vendorId">
                    <input type="hidden" name="ajax_action" value="save_vendor">
                    <div class="row">
                        <div class="col-md-6"><div class="form-group"><label>Vendor Name *</label><input type="text" name="vendor_name" id="vendorName" class="form-control" required></div></div>
                        <div class="col-md-6"><div class="form-group"><label>Contact Person</label><input type="text" name="contact_person" id="contactPerson" class="form-control"></div></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6"><div class="form-group"><label>Phone</label><input type="text" name="phone" id="vendorPhone" class="form-control"></div></div>
                        <div class="col-md-6"><div class="form-group"><label>Email</label><input type="email" name="email" id="vendorEmail" class="form-control"></div></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6"><div class="form-group"><label>Tax Number</label><input type="text" name="tax_number" id="taxNumber" class="form-control"></div></div>
                        <div class="col-md-6"><div class="form-group"><label>Payment Terms</label><select name="payment_terms" id="paymentTerms" class="form-control"><option value="net_15">Net 15</option><option value="net_30">Net 30</option><option value="net_45">Net 45</option><option value="cod">COD</option></select></div></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6"><div class="form-group"><label>Bank Name</label><input type="text" name="bank_name" id="bankName" class="form-control"></div></div>
                        <div class="col-md-6"><div class="form-group"><label>Bank Account</label><input type="text" name="bank_account" id="bankAccount" class="form-control"></div></div>
                    </div>
                    <div class="form-group"><label>Address</label><textarea name="address" id="vendorAddress" class="form-control" rows="2"></textarea></div>
                    <div class="form-group"><label>Notes</label><textarea name="notes" id="vendorNotes" class="form-control" rows="2"></textarea></div>
                    <div class="form-group"><label>Status</label><select name="status" id="vendorStatus" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                    <?php if ($role === 'superadmin'): ?>
                    <div class="form-group"><label>Tenant</label><select name="tenant_id" id="vendorTenantId" class="form-control"><option value="">Select Tenant</option><?php foreach($tenants as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?></select></div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn" style="background:var(--curdun-violet);color:white;">Save</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Bill Modal -->
<div class="modal fade" id="billModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h5><i class="fas fa-file-invoice"></i> Bill Details</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
            <form id="billForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="billId">
                    <input type="hidden" name="ajax_action" value="save_bill">
                    <div class="row">
                        <div class="col-md-6"><div class="form-group"><label>Vendor *</label><select name="vendor_id" id="billVendorId" class="form-control" required><option value="">Select Vendor</option></select></div></div>
                        <div class="col-md-6"><div class="form-group"><label>Bill Number *</label><input type="text" name="bill_number" id="billNumber" class="form-control" required></div></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6"><div class="form-group"><label>Bill Date</label><input type="date" name="bill_date" id="billDate" class="form-control"></div></div>
                        <div class="col-md-6"><div class="form-group"><label>Due Date</label><input type="date" name="due_date" id="dueDate" class="form-control"></div></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6"><div class="form-group"><label>Subtotal</label><input type="number" step="0.01" name="subtotal" id="subtotal" class="form-control" onchange="calculateTotal()"></div></div>
                        <div class="col-md-6"><div class="form-group"><label>Tax Rate (%)</label><input type="number" step="0.01" name="tax_rate" id="taxRate" class="form-control" value="0" onchange="calculateTotal()"></div></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6"><div class="form-group"><label>Discount</label><input type="number" step="0.01" name="discount_amount" id="discountAmount" class="form-control" value="0" onchange="calculateTotal()"></div></div>
                        <div class="col-md-6"><div class="form-group"><label>Total Amount</label><input type="text" id="totalAmountDisplay" class="form-control" readonly style="background:#e9ecef; font-weight:bold;"></div></div>
                    </div>
                    <div class="form-group"><label>Notes</label><textarea name="notes" id="billNotes" class="form-control" rows="2"></textarea></div>
                    <?php if ($role === 'superadmin'): ?>
                    <div class="form-group"><label>Tenant</label><select name="tenant_id" id="billTenantId" class="form-control"><option value="">Select Tenant</option><?php foreach($tenants as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?></select></div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn" style="background:var(--curdun-violet);color:white;">Save</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5><i class="fas fa-money-bill"></i> Add Payment</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
            <form id="paymentForm">
                <div class="modal-body">
                    <input type="hidden" name="bill_id" id="paymentBillId">
                    <input type="hidden" name="ajax_action" value="add_payment">
                    <p><strong>Vendor:</strong> <span id="paymentVendorName"></span></p>
                    <p><strong>Bill #:</strong> <span id="paymentBillNumber"></span></p>
                    <p><strong>Balance Due:</strong> $<span id="paymentBalance"></span></p>
                    <div class="form-group"><label>Payment Amount *</label><input type="number" step="0.01" name="amount" id="paymentAmount" class="form-control" required></div>
                    <div class="form-group"><label>Payment Date</label><input type="date" name="payment_date" id="paymentDate" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                    <div class="form-group"><label>Payment Method</label><select name="payment_method" id="paymentMethod" class="form-control"><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option><option value="check">Check</option><option value="credit_card">Credit Card</option></select></div>
                    <div class="form-group"><label>Reference Number</label><input type="text" name="reference_number" id="referenceNumber" class="form-control"></div>
                    <div class="form-group"><label>Notes</label><textarea name="notes" id="paymentNotes" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn" style="background:var(--curdun-violet);color:white;">Record Payment</button></div>
            </form>
        </div>
    </div>
</div>

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
                        <i class="fas fa-info-circle"></i> Soo geli CSV file. Hubi in Magacyada Tenant-yada ay sax yihiin.
                        <a href="?ajax_action=download_sample" class="alert-link">Download Sample</a>.
                    </div>
                    <div class="form-group">
                        <label>Select CSV File</label>
                        <input type="file" name="excel_file" class="form-control" accept=".csv" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background:var(--curdun-violet);color:white;">Import</button>
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
    $('#alert-placeholder').html(`<div class="alert alert-${type} alert-dismissible fade show">${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
    setTimeout(() => $('.alert').fadeOut(), 5000);
}

function loadDashboard() {
    let tenant = $('#tenantFilter').val() || 0;
    $.post(window.location.href, {ajax_action: 'get_dashboard_stats', tenant: tenant}, function(res) {
        $('#unpaidTotal').text('$' + parseFloat(res.unpaid_total).toLocaleString(undefined, {minimumFractionDigits:2}));
        let html = '';
        res.recent_bills.forEach(bill => {
            let balance = bill.total_amount - bill.amount_paid;
            html += `<tr><td>${escapeHtml(bill.vendor_name)}</td><td>${escapeHtml(bill.bill_number)}</td><td>$${parseFloat(bill.total_amount).toFixed(2)}</td><td>$${parseFloat(bill.amount_paid).toFixed(2)}</td><td><span class="badge badge-${bill.status == 'paid' ? 'success' : 'warning'}">${bill.status}</span></td><td><button class="btn btn-sm btn-primary view-bill" data-id="${bill.id}">View</button></td></tr>`;
        });
        $('#recentBillsBody').html(html);
    }, 'json');
}

function loadBills() {
    let data = {
        ajax_action: 'get_bills',
        page: currentPage,
        search: $('#searchInput').val(),
        status: $('#statusFilter').val(),
        tenant: $('#tenantFilter').val()
    };
    $('#billsTableContainer').html('<div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-2x"></i></div>');
    $.post(window.location.href, data, function(res) {
        $('#billsTableContainer').html(res.table_html);
        let pages = '';
        for(let i=1; i<=res.total_pages; i++) {
            pages += `<button class="btn btn-sm ${i==res.current_page ? 'btn-primary' : 'btn-secondary'} mx-1 page-btn" data-page="${i}">${i}</button>`;
        }
        $('#billsPagination').html(pages);
        attachBillEvents();
    }, 'json');
}

function loadVendors() {
    let data = {
        ajax_action: 'get_vendors',
        page: currentPage,
        search: $('#searchInput').val(),
        tenant: $('#tenantFilter').val()
    };
    $('#vendorsTableContainer').html('<div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-2x"></i></div>');
    $.post(window.location.href, data, function(res) {
        $('#vendorsTableContainer').html(res.table_html);
        let pages = '';
        for(let i=1; i<=res.total_pages; i++) {
            pages += `<button class="btn btn-sm ${i==res.current_page ? 'btn-primary' : 'btn-secondary'} mx-1 page-btn" data-page="${i}">${i}</button>`;
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
            if(res.tenant_id) $('#billTenantId').val(res.tenant_id);
            calculateTotal();
            $('#billModal').modal('show');
        }, 'json');
    });
    $('.delete-bill').click(function() {
        if(confirm('Delete this bill?')) {
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
        $('#paymentBalance').text($(this).data('balance').toFixed(2));
        $('#paymentAmount').val('');
        $('#paymentModal').modal('show');
    });
    $('.page-btn').click(function() {
        currentPage = $(this).data('page');
        loadBills();
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
            if(res.tenant_id) $('#vendorTenantId').val(res.tenant_id);
            $('#vendorModal').modal('show');
        }, 'json');
    });
    $('.delete-vendor').click(function() {
        if(confirm('Delete this vendor?')) {
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
        }
    }, 'json');
});

$('#paymentForm').submit(function(e) {
    e.preventDefault();
    $.post(window.location.href, $(this).serialize(), function(res) {
        showAlert(res.success ? 'success' : 'error', res.message);
        if(res.success) {
            $('#paymentModal').modal('hide');
            if(currentTab === 'bills') loadBills();
            else loadDashboard();
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
                if(currentTab === 'bills') loadBills();
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
    calculateTotal();
    
    // Load vendors dropdown
    let tenant = $('#tenantFilter').val() || 0;
    $.post(window.location.href, {ajax_action: 'get_vendor_options', tenant: tenant}, function(res) {
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
        let tenant = $('#tenantFilter').val();
        $('#exportBillsBtn').attr('href', `?ajax_action=export_bills&search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}&tenant=${tenant}`);
    } else if(currentTab === 'vendors') {
        loadVendors();
    }
});

// Load dashboard initially
loadDashboard();

// Load vendor options for bill modal when needed
<?php if ($role === 'company_admin'): ?>
$('#tenantFilter').prop('disabled', true);
<?php endif; ?>
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>