<?php
// superadmin/tax_management.php
// Tax Management & Tax Reporting -faras cargo

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
    // Tax Rates table
    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_rates (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT(11) NOT NULL,
        tax_name VARCHAR(100) NOT NULL,
        tax_rate DECIMAL(5,2) NOT NULL,
        tax_type ENUM('VAT','Sales Tax','Income Tax','Withholding','Customs','Other') DEFAULT 'VAT',
        tax_number VARCHAR(100),
        is_default TINYINT(1) DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        effective_from DATE,
        effective_to DATE,
        notes TEXT,
        created_by INT(11),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    )");
    
    // Tax Returns / Filings table
    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_returns (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT(11) NOT NULL,
        tax_rate_id INT(11) NOT NULL,
        return_period VARCHAR(20) NOT NULL,
        return_year INT(4) NOT NULL,
        return_month INT(2) DEFAULT NULL,
        return_quarter INT(1) DEFAULT NULL,
        filing_date DATE,
        due_date DATE,
        taxable_amount DECIMAL(15,2) DEFAULT 0,
        tax_amount DECIMAL(15,2) DEFAULT 0,
        penalties DECIMAL(15,2) DEFAULT 0,
        interest DECIMAL(15,2) DEFAULT 0,
        total_due DECIMAL(15,2) DEFAULT 0,
        amount_paid DECIMAL(15,2) DEFAULT 0,
        status ENUM('draft','filed','paid','overdue','amended') DEFAULT 'draft',
        payment_reference VARCHAR(100),
        payment_date DATE,
        notes TEXT,
        filed_by INT(11),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (tax_rate_id) REFERENCES tax_rates(id) ON DELETE CASCADE
    )");
    
    // Tax Transactions table (links tax to invoices, expenses, etc.)
    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_transactions (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT(11) NOT NULL,
        tax_rate_id INT(11) NOT NULL,
        transaction_type ENUM('invoice','expense','purchase','sale','credit_note','debit_note') NOT NULL,
        transaction_id INT(11) NOT NULL,
        taxable_amount DECIMAL(15,2) NOT NULL,
        tax_amount DECIMAL(15,2) NOT NULL,
        tax_date DATE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (tax_rate_id) REFERENCES tax_rates(id) ON DELETE CASCADE,
        INDEX idx_transaction (transaction_type, transaction_id)
    )");
    
    // Tax Settings per tenant
    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_settings (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT(11) NOT NULL,
        default_tax_rate_id INT(11) DEFAULT NULL,
        tax_calculation_method ENUM('exclusive','inclusive') DEFAULT 'exclusive',
        enable_tax_invoicing TINYINT(1) DEFAULT 1,
        tax_period ENUM('monthly','quarterly','annually') DEFAULT 'monthly',
        tax_authority_name VARCHAR(255),
        tax_authority_email VARCHAR(100),
        tax_authority_phone VARCHAR(50),
        tax_office_address TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (default_tax_rate_id) REFERENCES tax_rates(id) ON DELETE SET NULL
    )");
    
    // Insert default tax rates for each tenant when they are created (handled by trigger or manually)
    // For now, insert for tenant 0 (system default)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tax_rates WHERE tenant_id = 0");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $default_taxes = [
            ['VAT 15%', 15, 'VAT', 'Default VAT Rate', 1],
            ['Income Tax', 25, 'Income Tax', 'Corporate Income Tax', 0],
            ['Withholding Tax', 5, 'Withholding', 'WHT on services', 0],
            ['Customs Duty', 10, 'Customs', 'Import duties', 0],
            ['Sales Tax', 8, 'Sales Tax', 'General Sales Tax', 0]
        ];
        foreach ($default_taxes as $tax) {
            $stmt = $pdo->prepare("INSERT INTO tax_rates (tenant_id, tax_name, tax_rate, tax_type, notes, is_default) VALUES (0, ?, ?, ?, ?, ?)");
            $stmt->execute([$tax[0], $tax[1], $tax[2], $tax[3], $tax[4]]);
        }
    }
    
    // Initialize tax settings for tenants
    $stmt = $pdo->prepare("SELECT id FROM tenants");
    $stmt->execute();
    $all_tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all_tenants as $tenant) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tax_settings WHERE tenant_id = ?");
        $stmt->execute([$tenant['id']]);
        if ($stmt->fetchColumn() == 0) {
            $default_tax = $pdo->query("SELECT id FROM tax_rates WHERE tenant_id = 0 AND tax_type = 'VAT' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $default_tax_id = $default_tax ? $default_tax['id'] : null;
            $stmt = $pdo->prepare("INSERT INTO tax_settings (tenant_id, default_tax_rate_id, tax_calculation_method, tax_period) VALUES (?, ?, 'exclusive', 'monthly')");
            $stmt->execute([$tenant['id'], $default_tax_id]);
        }
    }
    
} catch (PDOException $e) {
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

// Get tax rates for current tenant view
$tax_rates = [];
try {
    if ($role === 'company_admin') {
        $stmt = $pdo->prepare("SELECT tr.*, t.name as tenant_name FROM tax_rates tr LEFT JOIN tenants t ON tr.tenant_id = t.id WHERE tr.tenant_id IN (0, ?) ORDER BY tr.tax_type, tr.tax_name");
        $stmt->execute([$session_tenant_id]);
    } else {
        $stmt = $pdo->prepare("SELECT tr.*, t.name as tenant_name FROM tax_rates tr LEFT JOIN tenants t ON tr.tenant_id = t.id WHERE tr.tenant_id IN (0, ?) OR tr.tenant_id = ? ORDER BY tr.tax_type, tr.tax_name");
        $stmt->execute([$session_tenant_id, $session_tenant_id]);
    }
    $tax_rates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tax_rates = [];
}

// Get tax settings
$tax_settings = [];
if ($role === 'company_admin') {
    $stmt = $pdo->prepare("SELECT * FROM tax_settings WHERE tenant_id = ?");
    $stmt->execute([$session_tenant_id]);
    $tax_settings = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ==================== AJAX HANDLERS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    
    // ==================== TAX RATES ====================
    if ($action === 'get_tax_rates') {
        $tenant_filter = isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0;
        $search = $_POST['search'] ?? '';
        
        $where = [];
        $params = [];
        
        if ($role === 'company_admin') {
            $where[] = "(tr.tenant_id = 0 OR tr.tenant_id = ?)";
            $params[] = $session_tenant_id;
        } elseif ($tenant_filter > 0) {
            $where[] = "(tr.tenant_id = 0 OR tr.tenant_id = ?)";
            $params[] = $tenant_filter;
        }
        
        if (!empty($search)) {
            $where[] = "(tr.tax_name LIKE ? OR tr.tax_type LIKE ? OR tr.tax_number LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        $where_clause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
        
        $sql = "SELECT tr.*, t.name as tenant_name,
                (SELECT COUNT(*) FROM tax_returns WHERE tax_rate_id = tr.id) as return_count
                FROM tax_rates tr
                LEFT JOIN tenants t ON tr.tenant_id = t.id
                $where_clause
                ORDER BY tr.is_default DESC, tr.tax_type, tr.tax_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_start(); ?>
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Tax Name</th>
                    <th>Rate</th>
                    <th>Type</th>
                    <th>Tax Number</th>
                    <th>Status</th>
                    <th>Default</th>
                    <th>Returns</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rates as $rate): ?>
                <tr>
                    <td><?= $rate['id'] ?></td>
                    <td>
                        <strong><?= htmlspecialchars($rate['tax_name']) ?></strong>
                        <?php if ($rate['tenant_id'] == 0): ?>
                            <span class="badge badge-info">System Default</span>
                        <?php endif; ?>
                        <?php if ($rate['tenant_id'] > 0): ?>
                            <br><small class="text-muted"><?= htmlspecialchars($rate['tenant_name'] ?? '') ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-primary"><?= $rate['tax_rate'] ?>%</span></td>
                    <td><?= htmlspecialchars($rate['tax_type']) ?></td>
                    <td><?= htmlspecialchars($rate['tax_number'] ?? '-') ?></td>
                    <td>
                        <span class="badge badge-<?= $rate['is_active'] ? 'success' : 'danger' ?>">
                            <?= $rate['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($rate['is_default']): ?>
                            <i class="fas fa-check-circle text-success"></i> Default
                        <?php else: ?>
                            <button class="btn btn-sm btn-outline-primary set-default-tax" data-id="<?= $rate['id'] ?>">Set Default</button>
                        <?php endif; ?>
                    </td>
                    <td><?= $rate['return_count'] ?? 0 ?></td>
                    <td>
                        <button class="btn btn-sm btn-primary edit-tax" data-id="<?= $rate['id'] ?>"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-sm btn-danger delete-tax" data-id="<?= $rate['id'] ?>" data-name="<?= htmlspecialchars($rate['tax_name']) ?>"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        echo json_encode(['html' => ob_get_clean()]);
        exit;
    }
    
    if ($action === 'save_tax_rate') {
        $id = $_POST['id'] ?? '';
        $tenant_id = $_POST['tenant_id'] ?? ($role === 'company_admin' ? $session_tenant_id : 0);
        $tax_name = trim($_POST['tax_name'] ?? '');
        $tax_rate = (float)($_POST['tax_rate'] ?? 0);
        $tax_type = $_POST['tax_type'] ?? 'VAT';
        $tax_number = trim($_POST['tax_number'] ?? '');
        $is_default = isset($_POST['is_default']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $effective_from = !empty($_POST['effective_from']) ? $_POST['effective_from'] : null;
        $effective_to = !empty($_POST['effective_to']) ? $_POST['effective_to'] : null;
        $notes = trim($_POST['notes'] ?? '');
        
        if (empty($tax_name) || $tax_rate <= 0) {
            echo json_encode(['success' => false, 'message' => 'Tax name and rate are required']);
            exit;
        }
        
        try {
            if ($is_default) {
                $stmt = $pdo->prepare("UPDATE tax_rates SET is_default = 0 WHERE tenant_id = ?");
                $stmt->execute([$tenant_id]);
            }
            
            if (empty($id)) {
                $stmt = $pdo->prepare("INSERT INTO tax_rates (tenant_id, tax_name, tax_rate, tax_type, tax_number, is_default, is_active, effective_from, effective_to, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$tenant_id, $tax_name, $tax_rate, $tax_type, $tax_number, $is_default, $is_active, $effective_from, $effective_to, $notes, $user_id]);
                echo json_encode(['success' => true, 'message' => 'Tax rate added successfully']);
            } else {
                $stmt = $pdo->prepare("UPDATE tax_rates SET tax_name=?, tax_rate=?, tax_type=?, tax_number=?, is_default=?, is_active=?, effective_from=?, effective_to=?, notes=? WHERE id=?");
                $stmt->execute([$tax_name, $tax_rate, $tax_type, $tax_number, $is_default, $is_active, $effective_from, $effective_to, $notes, $id]);
                echo json_encode(['success' => true, 'message' => 'Tax rate updated successfully']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'delete_tax_rate') {
        $id = $_POST['id'] ?? 0;
        try {
            $stmt = $pdo->prepare("DELETE FROM tax_rates WHERE id = ? AND tenant_id != 0");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Tax rate deleted']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_tax_rate') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM tax_rates WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit;
    }
    
    if ($action === 'set_default_tax') {
        $id = $_POST['id'] ?? 0;
        $tenant_id = $role === 'company_admin' ? $session_tenant_id : ($_POST['tenant_id'] ?? 0);
        
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE tax_rates SET is_default = 0 WHERE tenant_id = ?");
            $stmt->execute([$tenant_id]);
            $stmt = $pdo->prepare("UPDATE tax_rates SET is_default = 1 WHERE id = ?");
            $stmt->execute([$id]);
            
            // Update tax settings
            $stmt = $pdo->prepare("UPDATE tax_settings SET default_tax_rate_id = ? WHERE tenant_id = ?");
            $stmt->execute([$id, $tenant_id]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Default tax rate updated']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // ==================== TAX RETURNS ====================
    if ($action === 'get_tax_returns') {
        $tenant_filter = isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0;
        $search = $_POST['search'] ?? '';
        $status_filter = $_POST['status'] ?? '';
        
        $where = [];
        $params = [];
        
        if ($role === 'company_admin') {
            $where[] = "tr.tenant_id = ?";
            $params[] = $session_tenant_id;
        } elseif ($tenant_filter > 0) {
            $where[] = "tr.tenant_id = ?";
            $params[] = $tenant_filter;
        }
        
        if (!empty($search)) {
            $where[] = "(tax_rate.tax_name LIKE ? OR tr.return_period LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if (!empty($status_filter)) {
            $where[] = "tr.status = ?";
            $params[] = $status_filter;
        }
        
        $where_clause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
        
        $sql = "SELECT tr.*, tax_rate.tax_name, tax_rate.tax_rate, tax_rate.tax_type, t.name as tenant_name
                FROM tax_returns tr
                LEFT JOIN tax_rates tax_rate ON tr.tax_rate_id = tax_rate.id
                LEFT JOIN tenants t ON tr.tenant_id = t.id
                $where_clause
                ORDER BY tr.return_year DESC, tr.return_period DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_start(); ?>
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Tax Type</th>
                    <th>Taxable Amount</th>
                    <th>Tax Due</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($returns as $return): 
                    $balance = $return['total_due'] - $return['amount_paid'];
                    $statusClass = $return['status'] == 'paid' ? 'success' : ($return['status'] == 'overdue' ? 'danger' : 'warning');
                ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($return['return_period']) ?> <?= $return['return_year'] ?></strong>
                        <br><small class="text-muted"><?= date('d/m/Y', strtotime($return['filing_date'] ?? $return['created_at'])) ?></small>
                    </td>
                    <td><?= htmlspecialchars($return['tax_name']) ?><br><small><?= $return['tax_rate'] ?>%</small></td>
                    <td>$<?= number_format($return['taxable_amount'], 2) ?></td>
                    <td>$<?= number_format($return['tax_amount'], 2) ?></td>
                    <td>$<?= number_format($return['amount_paid'], 2) ?></td>
                    <td class="<?= $balance > 0 ? 'text-danger' : 'text-success' ?>">
                        <strong>$<?= number_format($balance, 2) ?></strong>
                    </td>
                    <td class="<?= (strtotime($return['due_date']) < time() && $return['status'] != 'paid') ? 'text-danger' : '' ?>">
                        <?= date('d/m/Y', strtotime($return['due_date'])) ?>
                    </td>
                    <td><span class="badge badge-<?= $statusClass ?>"><?= strtoupper($return['status']) ?></span></td>
                    <td>
                        <button class="btn btn-sm btn-primary edit-return" data-id="<?= $return['id'] ?>"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-sm btn-success add-return-payment" data-id="<?= $return['id'] ?>" data-name="<?= htmlspecialchars($return['tax_name']) ?>" data-period="<?= $return['return_period'] ?> <?= $return['return_year'] ?>" data-balance="<?= $balance ?>"><i class="fas fa-money-bill"></i></button>
                        <button class="btn btn-sm btn-danger delete-return" data-id="<?= $return['id'] ?>"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        echo json_encode(['html' => ob_get_clean()]);
        exit;
    }
    
    if ($action === 'save_tax_return') {
        $id = $_POST['id'] ?? '';
        $tenant_id = $_POST['tenant_id'] ?? ($role === 'company_admin' ? $session_tenant_id : 0);
        $tax_rate_id = $_POST['tax_rate_id'] ?? 0;
        $return_period = $_POST['return_period'] ?? '';
        $return_year = (int)($_POST['return_year'] ?? date('Y'));
        $return_month = !empty($_POST['return_month']) ? (int)$_POST['return_month'] : null;
        $return_quarter = !empty($_POST['return_quarter']) ? (int)$_POST['return_quarter'] : null;
        $filing_date = !empty($_POST['filing_date']) ? $_POST['filing_date'] : date('Y-m-d');
        $due_date = $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
        $taxable_amount = (float)($_POST['taxable_amount'] ?? 0);
        $tax_amount = (float)($_POST['tax_amount'] ?? 0);
        $penalties = (float)($_POST['penalties'] ?? 0);
        $interest = (float)($_POST['interest'] ?? 0);
        $total_due = $tax_amount + $penalties + $interest;
        $notes = trim($_POST['notes'] ?? '');
        $status = $_POST['status'] ?? 'draft';
        
        if (empty($tax_rate_id) || empty($return_period)) {
            echo json_encode(['success' => false, 'message' => 'Tax rate and period are required']);
            exit;
        }
        
        try {
            if (empty($id)) {
                $stmt = $pdo->prepare("INSERT INTO tax_returns (tenant_id, tax_rate_id, return_period, return_year, return_month, return_quarter, filing_date, due_date, taxable_amount, tax_amount, penalties, interest, total_due, notes, status, filed_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$tenant_id, $tax_rate_id, $return_period, $return_year, $return_month, $return_quarter, $filing_date, $due_date, $taxable_amount, $tax_amount, $penalties, $interest, $total_due, $notes, $status, $user_id]);
                echo json_encode(['success' => true, 'message' => 'Tax return created successfully']);
            } else {
                $stmt = $pdo->prepare("UPDATE tax_returns SET tax_rate_id=?, return_period=?, return_year=?, return_month=?, return_quarter=?, filing_date=?, due_date=?, taxable_amount=?, tax_amount=?, penalties=?, interest=?, total_due=?, notes=?, status=? WHERE id=?");
                $stmt->execute([$tax_rate_id, $return_period, $return_year, $return_month, $return_quarter, $filing_date, $due_date, $taxable_amount, $tax_amount, $penalties, $interest, $total_due, $notes, $status, $id]);
                echo json_encode(['success' => true, 'message' => 'Tax return updated successfully']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'delete_tax_return') {
        $id = $_POST['id'] ?? 0;
        try {
            $stmt = $pdo->prepare("DELETE FROM tax_returns WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Tax return deleted']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_tax_return') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM tax_returns WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit;
    }
    
    if ($action === 'record_tax_payment') {
        $return_id = $_POST['return_id'] ?? 0;
        $amount = (float)($_POST['amount'] ?? 0);
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $payment_reference = trim($_POST['payment_reference'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT * FROM tax_returns WHERE id = ? FOR UPDATE");
            $stmt->execute([$return_id]);
            $return = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$return) {
                throw new Exception('Tax return not found');
            }
            
            $new_paid = $return['amount_paid'] + $amount;
            $new_status = ($new_paid >= $return['total_due']) ? 'paid' : 'filed';
            
            $stmt = $pdo->prepare("UPDATE tax_returns SET amount_paid = ?, payment_reference = ?, payment_date = ?, status = ? WHERE id = ?");
            $stmt->execute([$new_paid, $payment_reference, $payment_date, $new_status, $return_id]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Payment recorded successfully']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // ==================== TAX REPORTS ====================
    if ($action === 'get_tax_report') {
        $tenant_filter = isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0;
        $year = (int)($_POST['year'] ?? date('Y'));
        $tax_type = $_POST['tax_type'] ?? '';
        
        $where = [];
        $params = [$year];
        
        if ($role === 'company_admin') {
            $where[] = "tr.tenant_id = ?";
            $params[] = $session_tenant_id;
        } elseif ($tenant_filter > 0) {
            $where[] = "tr.tenant_id = ?";
            $params[] = $tenant_filter;
        }
        
        if (!empty($tax_type)) {
            $where[] = "tax_rate.tax_type = ?";
            $params[] = $tax_type;
        }
        
        $where_clause = empty($where) ? "WHERE tr.return_year = ?" : "WHERE tr.return_year = ? AND " . implode(" AND ", $where);
        
        $sql = "SELECT tr.*, tax_rate.tax_name, tax_rate.tax_type, tax_rate.tax_rate
                FROM tax_returns tr
                LEFT JOIN tax_rates tax_rate ON tr.tax_rate_id = tax_rate.id
                $where_clause
                ORDER BY tr.return_period";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $total_taxable = 0;
        $total_tax = 0;
        $total_paid = 0;
        
        ob_start(); ?>
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h6>Total Taxable Amount</h6>
                        <h3>$<?= number_format(array_sum(array_column($reports, 'taxable_amount')), 2) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <h6>Total Tax Due</h6>
                        <h3>$<?= number_format(array_sum(array_column($reports, 'tax_amount')), 2) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h6>Total Paid</h6>
                        <h3>$<?= number_format(array_sum(array_column($reports, 'amount_paid')), 2) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-danger text-white">
                    <div class="card-body">
                        <h6>Outstanding Balance</h6>
                        <h3>$<?= number_format(array_sum(array_column($reports, 'total_due')) - array_sum(array_column($reports, 'amount_paid')), 2) ?></h3>
                    </div>
                </div>
            </div>
        </div>
        
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Tax Type</th>
                    <th>Rate</th>
                    <th>Taxable Amount</th>
                    <th>Tax Due</th>
                    <th>Penalties</th>
                    <th>Total Due</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reports as $report): 
                    $balance = $report['total_due'] - $report['amount_paid'];
                ?>
                <tr>
                    <td><?= $report['return_period'] ?> <?= $report['return_year'] ?></td>
                    <td><?= htmlspecialchars($report['tax_name']) ?></td>
                    <td><?= $report['tax_rate'] ?>%</td>
                    <td>$<?= number_format($report['taxable_amount'], 2) ?></td>
                    <td>$<?= number_format($report['tax_amount'], 2) ?></td>
                    <td>$<?= number_format($report['penalties'], 2) ?></td>
                    <td><strong>$<?= number_format($report['total_due'], 2) ?></strong></td>
                    <td>$<?= number_format($report['amount_paid'], 2) ?></td>
                    <td class="<?= $balance > 0 ? 'text-danger' : 'text-success' ?>">$<?= number_format($balance, 2) ?></td>
                    <td><span class="badge badge-<?= $report['status'] == 'paid' ? 'success' : 'warning' ?>"><?= $report['status'] ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        echo json_encode(['html' => ob_get_clean()]);
        exit;
    }
    
    // ==================== TAX SETTINGS ====================
    if ($action === 'get_tax_settings') {
        if ($role === 'company_admin') {
            $stmt = $pdo->prepare("SELECT * FROM tax_settings WHERE tenant_id = ?");
            $stmt->execute([$session_tenant_id]);
        } else {
            $tenant_id = $_POST['tenant_id'] ?? 0;
            $stmt = $pdo->prepare("SELECT * FROM tax_settings WHERE tenant_id = ?");
            $stmt->execute([$tenant_id]);
        }
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get available tax rates
        $stmt = $pdo->prepare("SELECT id, tax_name, tax_rate FROM tax_rates WHERE tenant_id IN (0, ?) AND is_active = 1");
        $stmt->execute([$settings['tenant_id'] ?? 0]);
        $tax_rates_options = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['settings' => $settings, 'tax_rates' => $tax_rates_options]);
        exit;
    }
    
    if ($action === 'save_tax_settings') {
        $tenant_id = $_POST['tenant_id'] ?? ($role === 'company_admin' ? $session_tenant_id : 0);
        $default_tax_rate_id = $_POST['default_tax_rate_id'] ?? null;
        $tax_calculation_method = $_POST['tax_calculation_method'] ?? 'exclusive';
        $enable_tax_invoicing = isset($_POST['enable_tax_invoicing']) ? 1 : 0;
        $tax_period = $_POST['tax_period'] ?? 'monthly';
        $tax_authority_name = trim($_POST['tax_authority_name'] ?? '');
        $tax_authority_email = trim($_POST['tax_authority_email'] ?? '');
        $tax_authority_phone = trim($_POST['tax_authority_phone'] ?? '');
        $tax_office_address = trim($_POST['tax_office_address'] ?? '');
        
        try {
            $stmt = $pdo->prepare("UPDATE tax_settings SET default_tax_rate_id=?, tax_calculation_method=?, enable_tax_invoicing=?, tax_period=?, tax_authority_name=?, tax_authority_email=?, tax_authority_phone=?, tax_office_address=? WHERE tenant_id=?");
            $stmt->execute([$default_tax_rate_id, $tax_calculation_method, $enable_tax_invoicing, $tax_period, $tax_authority_name, $tax_authority_email, $tax_authority_phone, $tax_office_address, $tenant_id]);
            echo json_encode(['success' => true, 'message' => 'Tax settings updated']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
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
    <title>Tax Management | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root { --curdun-violet: #2D1859; --curdun-yellow: #F5C410; }
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .page-header { background: linear-gradient(135deg, var(--curdun-violet), #4B2C85); border-radius: 16px; padding: 20px; margin-bottom: 25px; color: white; }
        .btn-primary-custom { background: var(--curdun-yellow); color: var(--curdun-violet); border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .modal-header { background: linear-gradient(135deg, var(--curdun-violet), #4B2C85); color: white; }
        .modal-header .close { color: white; }
        .filters-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 25px; display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 150px; }
        .nav-tabs .nav-link { color: var(--curdun-violet); font-weight: 600; }
        .nav-tabs .nav-link.active { background: var(--curdun-violet); color: white; border-color: var(--curdun-violet); }
        .card-stat { text-align: center; padding: 15px; border-radius: 12px; }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>
    
    <div class="page-header d-flex justify-content-between align-items-center">
        <h1><i class="fas fa-file-invoice-dollar"></i> Tax Management & Compliance</h1>
        <div>
            <button class="btn btn-light" id="addTaxRateBtn"><i class="fas fa-plus"></i> Add Tax Rate</button>
            <button class="btn btn-light" id="addTaxReturnBtn"><i class="fas fa-plus"></i> File Return</button>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="filters-card">
        <div class="filter-group">
            <label><i class="fas fa-building"></i> Tenant</label>
            <select id="tenantFilter" class="form-control">
                <option value="0">All Tenants</option>
                <?php foreach ($tenants as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= ($role === 'company_admin' && $t['id'] == $session_tenant_id) ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-search"></i> Search</label>
            <input type="text" id="searchInput" class="form-control" placeholder="Search...">
        </div>
        <div class="filter-group" id="statusFilterDiv">
            <label><i class="fas fa-filter"></i> Status</label>
            <select id="statusFilter" class="form-control">
                <option value="">All</option>
                <option value="draft">Draft</option>
                <option value="filed">Filed</option>
                <option value="paid">Paid</option>
                <option value="overdue">Overdue</option>
            </select>
        </div>
        <div class="filter-group">
            <label>&nbsp;</label>
            <button class="btn-primary-custom" id="applyFilters"><i class="fas fa-filter"></i> Filter</button>
        </div>
    </div>
    
    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link active" data-tab="rates" href="#"><i class="fas fa-percent"></i> Tax Rates</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-tab="returns" href="#"><i class="fas fa-file-upload"></i> Tax Returns</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-tab="reports" href="#"><i class="fas fa-chart-bar"></i> Tax Reports</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-tab="settings" href="#"><i class="fas fa-cog"></i> Settings</a>
        </li>
    </ul>
    
    <!-- Tax Rates Tab -->
    <div id="ratesTab" class="tab-content">
        <div class="table-container bg-white rounded p-3" id="taxRatesContainer"></div>
    </div>
    
    <!-- Tax Returns Tab -->
    <div id="returnsTab" class="tab-content" style="display: none;">
        <div class="table-container bg-white rounded p-3" id="taxReturnsContainer"></div>
    </div>
    
    <!-- Tax Reports Tab -->
    <div id="reportsTab" class="tab-content" style="display: none;">
        <div class="row mb-3">
            <div class="col-md-3">
                <label>Year</label>
                <select id="reportYear" class="form-control">
                    <?php for ($y = date('Y')-3; $y <= date('Y')+1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label>Tax Type</label>
                <select id="reportTaxType" class="form-control">
                    <option value="">All Types</option>
                    <option value="VAT">VAT</option>
                    <option value="Income Tax">Income Tax</option>
                    <option value="Withholding">Withholding Tax</option>
                    <option value="Customs">Customs Duty</option>
                </select>
            </div>
            <div class="col-md-2">
                <label>&nbsp;</label>
                <button class="btn-primary-custom" id="generateReport"><i class="fas fa-chart-line"></i> Generate</button>
            </div>
        </div>
        <div id="taxReportContainer" class="bg-white rounded p-3"></div>
    </div>
    
    <!-- Tax Settings Tab -->
    <div id="settingsTab" class="tab-content" style="display: none;">
        <div class="card">
            <div class="card-header bg-white">
                <h5><i class="fas fa-cog"></i> Tax Configuration</h5>
            </div>
            <div class="card-body">
                <form id="taxSettingsForm">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Default Tax Rate</label>
                                <select id="settingsDefaultTax" name="default_tax_rate_id" class="form-control">
                                    <option value="">Select Default Tax</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tax Calculation Method</label>
                                <select id="settingsCalcMethod" name="tax_calculation_method" class="form-control">
                                    <option value="exclusive">Exclusive (Tax added to price)</option>
                                    <option value="inclusive">Inclusive (Tax included in price)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tax Period</label>
                                <select id="settingsPeriod" name="tax_period" class="form-control">
                                    <option value="monthly">Monthly</option>
                                    <option value="quarterly">Quarterly</option>
                                    <option value="annually">Annually</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <div class="custom-control custom-switch mt-4">
                                    <input type="checkbox" class="custom-control-input" id="enableTaxInvoicing" name="enable_tax_invoicing">
                                    <label class="custom-control-label" for="enableTaxInvoicing">Enable Tax Invoicing</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <h6>Tax Authority Information</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Authority Name</label>
                                <input type="text" id="settingsAuthName" name="tax_authority_name" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Authority Email</label>
                                <input type="email" id="settingsAuthEmail" name="tax_authority_email" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Authority Phone</label>
                                <input type="text" id="settingsAuthPhone" name="tax_authority_phone" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Office Address</label>
                        <textarea id="settingsAuthAddress" name="tax_office_address" class="form-control" rows="2"></textarea>
                    </div>
                    <input type="hidden" name="ajax_action" value="save_tax_settings">
                    <input type="hidden" name="tenant_id" id="settingsTenantId" value="<?= $session_tenant_id ?>">
                    <button type="submit" class="btn" style="background:var(--curdun-violet);color:white;"><i class="fas fa-save"></i> Save Settings</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Tax Rate Modal -->
<div class="modal fade" id="taxRateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tax Rate Details</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="taxRateForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="taxRateId">
                    <input type="hidden" name="ajax_action" value="save_tax_rate">
                    
                    <div class="form-group">
                        <label>Tax Name *</label>
                        <input type="text" name="tax_name" id="taxName" class="form-control" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tax Rate (%) *</label>
                                <input type="number" step="0.01" name="tax_rate" id="taxRate" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tax Type</label>
                                <select name="tax_type" id="taxType" class="form-control">
                                    <option value="VAT">VAT</option>
                                    <option value="Sales Tax">Sales Tax</option>
                                    <option value="Income Tax">Income Tax</option>
                                    <option value="Withholding">Withholding Tax</option>
                                    <option value="Customs">Customs Duty</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Tax Number / Registration</label>
                        <input type="text" name="tax_number" id="taxNumber" class="form-control">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Effective From</label>
                                <input type="date" name="effective_from" id="effectiveFrom" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Effective To</label>
                                <input type="date" name="effective_to" id="effectiveTo" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" id="taxNotes" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="isDefault" name="is_default">
                                <label class="custom-control-label" for="isDefault">Set as Default</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="isActive" name="is_active" checked>
                                <label class="custom-control-label" for="isActive">Active</label>
                            </div>
                        </div>
                    </div>
                    <?php if ($role === 'superadmin'): ?>
                    <div class="form-group mt-2">
                        <label>Tenant (Leave 0 for system default)</label>
                        <select name="tenant_id" id="taxTenantId" class="form-control">
                            <option value="0">System Default (All Tenants)</option>
                            <?php foreach ($tenants as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background:var(--curdun-violet);color:white;">Save Tax Rate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Tax Return Modal -->
<div class="modal fade" id="taxReturnModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tax Return Filing</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="taxReturnForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="returnId">
                    <input type="hidden" name="ajax_action" value="save_tax_return">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tax Rate *</label>
                                <select name="tax_rate_id" id="returnTaxRateId" class="form-control" required>
                                    <option value="">Select Tax Rate</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Return Period *</label>
                                <select name="return_period" id="returnPeriod" class="form-control" required>
                                    <option value="January">January</option>
                                    <option value="February">February</option>
                                    <option value="March">March</option>
                                    <option value="April">April</option>
                                    <option value="May">May</option>
                                    <option value="June">June</option>
                                    <option value="July">July</option>
                                    <option value="August">August</option>
                                    <option value="September">September</option>
                                    <option value="October">October</option>
                                    <option value="November">November</option>
                                    <option value="December">December</option>
                                    <option value="Q1">Quarter 1</option>
                                    <option value="Q2">Quarter 2</option>
                                    <option value="Q3">Quarter 3</option>
                                    <option value="Q4">Quarter 4</option>
                                    <option value="Annual">Annual</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Year *</label>
                                <input type="number" name="return_year" id="returnYear" class="form-control" value="<?= date('Y') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Filing Date</label>
                                <input type="date" name="filing_date" id="filingDate" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Due Date</label>
                                <input type="date" name="due_date" id="dueDate" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Taxable Amount</label>
                                <input type="number" step="0.01" name="taxable_amount" id="taxableAmount" class="form-control" onchange="calculateReturnTax()">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Tax Amount</label>
                                <input type="number" step="0.01" name="tax_amount" id="taxAmount" class="form-control" onchange="calculateTotalDue()">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" id="returnStatus" class="form-control">
                                    <option value="draft">Draft</option>
                                    <option value="filed">Filed</option>
                                    <option value="paid">Paid</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Penalties</label>
                                <input type="number" step="0.01" name="penalties" id="penalties" class="form-control" value="0" onchange="calculateTotalDue()">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Interest</label>
                                <input type="number" step="0.01" name="interest" id="interest" class="form-control" value="0" onchange="calculateTotalDue()">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Total Due</label>
                        <input type="text" id="totalDueDisplay" class="form-control" readonly style="background:#e9ecef; font-weight:bold;">
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" id="returnNotes" class="form-control" rows="2"></textarea>
                    </div>
                    <?php if ($role === 'superadmin'): ?>
                    <div class="form-group">
                        <label>Tenant</label>
                        <select name="tenant_id" id="returnTenantId" class="form-control">
                            <option value="">Select Tenant</option>
                            <?php foreach ($tenants as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background:var(--curdun-violet);color:white;">Save Return</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="taxPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Record Tax Payment</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="taxPaymentForm">
                <div class="modal-body">
                    <input type="hidden" name="return_id" id="paymentReturnId">
                    <input type="hidden" name="ajax_action" value="record_tax_payment">
                    
                    <p><strong>Tax Return:</strong> <span id="paymentReturnName"></span></p>
                    <p><strong>Balance Due:</strong> $<span id="paymentBalance"></span></p>
                    
                    <div class="form-group">
                        <label>Payment Amount *</label>
                        <input type="number" step="0.01" name="amount" id="paymentAmount" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Payment Date</label>
                        <input type="date" name="payment_date" id="paymentDate" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label>Reference Number</label>
                        <input type="text" name="payment_reference" id="paymentReference" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" id="paymentNotes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background:var(--curdun-violet);color:white;">Record Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentTab = 'rates';

function showAlert(type, msg) {
    $('#alert-placeholder').html(`<div class="alert alert-${type} alert-dismissible fade show">${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
    setTimeout(() => $('.alert').fadeOut(), 5000);
}

function loadTaxRates() {
    let data = {
        ajax_action: 'get_tax_rates',
        search: $('#searchInput').val(),
        tenant: $('#tenantFilter').val()
    };
    $.post(window.location.href, data, function(res) {
        $('#taxRatesContainer').html(res.html);
        attachTaxRateEvents();
    }, 'json');
}

function loadTaxReturns() {
    let data = {
        ajax_action: 'get_tax_returns',
        search: $('#searchInput').val(),
        status: $('#statusFilter').val(),
        tenant: $('#tenantFilter').val()
    };
    $.post(window.location.href, data, function(res) {
        $('#taxReturnsContainer').html(res.html);
        attachTaxReturnEvents();
    }, 'json');
}

function loadTaxSettings() {
    let tenant = $('#tenantFilter').val();
    $.post(window.location.href, {ajax_action: 'get_tax_settings', tenant_id: tenant}, function(res) {
        if(res.settings) {
            $('#settingsDefaultTax').val(res.settings.default_tax_rate_id);
            $('#settingsCalcMethod').val(res.settings.tax_calculation_method);
            $('#settingsPeriod').val(res.settings.tax_period);
            $('#enableTaxInvoicing').prop('checked', res.settings.enable_tax_invoicing == 1);
            $('#settingsAuthName').val(res.settings.tax_authority_name || '');
            $('#settingsAuthEmail').val(res.settings.tax_authority_email || '');
            $('#settingsAuthPhone').val(res.settings.tax_authority_phone || '');
            $('#settingsAuthAddress').val(res.settings.tax_office_address || '');
        }
        
        let options = '<option value="">Select Default Tax</option>';
        res.tax_rates.forEach(rate => {
            options += `<option value="${rate.id}">${rate.tax_name} (${rate.tax_rate}%)</option>`;
        });
        $('#settingsDefaultTax').html(options);
    }, 'json');
}

function loadTaxReport() {
    let data = {
        ajax_action: 'get_tax_report',
        year: $('#reportYear').val(),
        tax_type: $('#reportTaxType').val(),
        tenant: $('#tenantFilter').val()
    };
    $.post(window.location.href, data, function(res) {
        $('#taxReportContainer').html(res.html);
    }, 'json');
}

function attachTaxRateEvents() {
    $('.edit-tax').click(function() {
        let id = $(this).data('id');
        $.post(window.location.href, {ajax_action: 'get_tax_rate', id: id}, function(res) {
            $('#taxRateId').val(res.id);
            $('#taxName').val(res.tax_name);
            $('#taxRate').val(res.tax_rate);
            $('#taxType').val(res.tax_type);
            $('#taxNumber').val(res.tax_number);
            $('#effectiveFrom').val(res.effective_from);
            $('#effectiveTo').val(res.effective_to);
            $('#taxNotes').val(res.notes);
            $('#isDefault').prop('checked', res.is_default == 1);
            $('#isActive').prop('checked', res.is_active == 1);
            if(res.tenant_id) $('#taxTenantId').val(res.tenant_id);
            $('#taxRateModal').modal('show');
        }, 'json');
    });
    
    $('.delete-tax').click(function() {
        if(confirm('Delete this tax rate?')) {
            $.post(window.location.href, {ajax_action: 'delete_tax_rate', id: $(this).data('id')}, function(res) {
                showAlert(res.success ? 'success' : 'error', res.message);
                if(res.success) loadTaxRates();
            }, 'json');
        }
    });
    
    $('.set-default-tax').click(function() {
        $.post(window.location.href, {ajax_action: 'set_default_tax', id: $(this).data('id')}, function(res) {
            showAlert(res.success ? 'success' : 'error', res.message);
            if(res.success) loadTaxRates();
        }, 'json');
    });
}

function attachTaxReturnEvents() {
    $('.edit-return').click(function() {
        let id = $(this).data('id');
        $.post(window.location.href, {ajax_action: 'get_tax_return', id: id}, function(res) {
            $('#returnId').val(res.id);
            $('#returnTaxRateId').val(res.tax_rate_id);
            $('#returnPeriod').val(res.return_period);
            $('#returnYear').val(res.return_year);
            $('#filingDate').val(res.filing_date);
            $('#dueDate').val(res.due_date);
            $('#taxableAmount').val(res.taxable_amount);
            $('#taxAmount').val(res.tax_amount);
            $('#penalties').val(res.penalties);
            $('#interest').val(res.interest);
            $('#returnStatus').val(res.status);
            $('#returnNotes').val(res.notes);
            if(res.tenant_id) $('#returnTenantId').val(res.tenant_id);
            calculateTotalDue();
            $('#taxReturnModal').modal('show');
        }, 'json');
    });
    
    $('.delete-return').click(function() {
        if(confirm('Delete this tax return?')) {
            $.post(window.location.href, {ajax_action: 'delete_tax_return', id: $(this).data('id')}, function(res) {
                showAlert(res.success ? 'success' : 'error', res.message);
                if(res.success) loadTaxReturns();
            }, 'json');
        }
    });
    
    $('.add-return-payment').click(function() {
        $('#paymentReturnId').val($(this).data('id'));
        $('#paymentReturnName').text($(this).data('name') + ' - ' + $(this).data('period'));
        $('#paymentBalance').text($(this).data('balance').toFixed(2));
        $('#paymentAmount').val('');
        $('#paymentReference').val('');
        $('#taxPaymentModal').modal('show');
    });
}

function calculateReturnTax() {
    let taxable = parseFloat($('#taxableAmount').val()) || 0;
    let taxRate = 0;
    
    // Get selected tax rate
    let taxRateId = $('#returnTaxRateId').val();
    if(taxRateId) {
        // We need to get the rate from the selected option
        let selectedOption = $('#returnTaxRateId option:selected').text();
        let match = selectedOption.match(/(\d+(?:\.\d+)?)%/);
        if(match) taxRate = parseFloat(match[1]);
    }
    
    let taxAmount = taxable * (taxRate / 100);
    $('#taxAmount').val(taxAmount.toFixed(2));
    calculateTotalDue();
}

function calculateTotalDue() {
    let taxAmount = parseFloat($('#taxAmount').val()) || 0;
    let penalties = parseFloat($('#penalties').val()) || 0;
    let interest = parseFloat($('#interest').val()) || 0;
    let total = taxAmount + penalties + interest;
    $('#totalDueDisplay').val('$' + total.toFixed(2));
}

// Populate tax rates dropdown for return form
function populateTaxRatesDropdown() {
    let tenant = $('#tenantFilter').val();
    $.post(window.location.href, {ajax_action: 'get_tax_rates', tenant: tenant}, function(res) {
        let options = '<option value="">Select Tax Rate</option>';
        $(res.html).find('tbody tr').each(function() {
            let id = $(this).find('td:first').text();
            let name = $(this).find('td:eq(1) strong').text();
            let rate = $(this).find('td:eq(2) .badge').text().replace('%', '');
            options += `<option value="${id}">${name} (${rate}%)</option>`;
        });
        $('#returnTaxRateId').html(options);
    });
}

// Tab switching
$('.nav-link').click(function(e) {
    e.preventDefault();
    currentTab = $(this).data('tab');
    $('.nav-link').removeClass('active');
    $(this).addClass('active');
    $('.tab-content').hide();
    
    if(currentTab === 'rates') {
        $('#ratesTab').show();
        loadTaxRates();
        $('#statusFilterDiv').hide();
    } else if(currentTab === 'returns') {
        $('#returnsTab').show();
        loadTaxReturns();
        $('#statusFilterDiv').show();
    } else if(currentTab === 'reports') {
        $('#reportsTab').show();
        loadTaxReport();
        $('#statusFilterDiv').hide();
    } else if(currentTab === 'settings') {
        $('#settingsTab').show();
        loadTaxSettings();
        $('#statusFilterDiv').hide();
    }
});

// Form submissions
$('#taxRateForm').submit(function(e) {
    e.preventDefault();
    $.post(window.location.href, $(this).serialize(), function(res) {
        showAlert(res.success ? 'success' : 'error', res.message);
        if(res.success) {
            $('#taxRateModal').modal('hide');
            loadTaxRates();
        }
    }, 'json');
});

$('#taxReturnForm').submit(function(e) {
    e.preventDefault();
    $.post(window.location.href, $(this).serialize(), function(res) {
        showAlert(res.success ? 'success' : 'error', res.message);
        if(res.success) {
            $('#taxReturnModal').modal('hide');
            loadTaxReturns();
        }
    }, 'json');
});

$('#taxPaymentForm').submit(function(e) {
    e.preventDefault();
    $.post(window.location.href, $(this).serialize(), function(res) {
        showAlert(res.success ? 'success' : 'error', res.message);
        if(res.success) {
            $('#taxPaymentModal').modal('hide');
            loadTaxReturns();
        }
    }, 'json');
});

$('#taxSettingsForm').submit(function(e) {
    e.preventDefault();
    $.post(window.location.href, $(this).serialize(), function(res) {
        showAlert(res.success ? 'success' : 'error', res.message);
    }, 'json');
});

// Add buttons
$('#addTaxRateBtn').click(function() {
    $('#taxRateForm')[0].reset();
    $('#taxRateId').val('');
    $('#isActive').prop('checked', true);
    $('#isDefault').prop('checked', false);
    $('#taxRateModal').modal('show');
});

$('#addTaxReturnBtn').click(function() {
    $('#taxReturnForm')[0].reset();
    $('#returnId').val('');
    $('#returnYear').val(new Date().getFullYear());
    $('#filingDate').val(new Date().toISOString().split('T')[0]);
    let due = new Date();
    due.setMonth(due.getMonth() + 1);
    $('#dueDate').val(due.toISOString().split('T')[0]);
    $('#totalDueDisplay').val('$0.00');
    $('#returnStatus').val('draft');
    populateTaxRatesDropdown();
    $('#taxReturnModal').modal('show');
});

$('#applyFilters').click(function() {
    if(currentTab === 'rates') loadTaxRates();
    else if(currentTab === 'returns') loadTaxReturns();
});

$('#generateReport').click(function() {
    loadTaxReport();
});

$('#returnTaxRateId').change(function() {
    calculateReturnTax();
});

// Initialize
loadTaxRates();

<?php if ($role === 'company_admin'): ?>
$('#tenantFilter').prop('disabled', true);
<?php endif; ?>
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>