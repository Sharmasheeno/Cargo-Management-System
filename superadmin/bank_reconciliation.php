<?php
// superadmin/bank_reconciliation.php
// Bank Reconciliation & Payment Tracking -faras cargo

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
    // Bank Accounts table
    $pdo->exec("CREATE TABLE IF NOT EXISTS bank_accounts (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT(11) NOT NULL,
        account_name VARCHAR(255) NOT NULL,
        bank_name VARCHAR(255) NOT NULL,
        account_number VARCHAR(100) NOT NULL,
        account_type ENUM('checking','savings','cash','mobile_money') DEFAULT 'checking',
        currency VARCHAR(3) DEFAULT 'USD',
        opening_balance DECIMAL(15,2) DEFAULT 0,
        current_balance DECIMAL(15,2) DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        notes TEXT,
        created_by INT(11),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    )");
    
    // Bank Transactions table
    $pdo->exec("CREATE TABLE IF NOT EXISTS bank_transactions (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT(11) NOT NULL,
        bank_account_id INT(11) NOT NULL,
        transaction_date DATE NOT NULL,
        transaction_type ENUM('deposit','withdrawal','transfer','fee','interest') NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        description TEXT,
        reference_number VARCHAR(100),
        category VARCHAR(100),
        related_id INT(11) DEFAULT NULL,
        related_type VARCHAR(50) DEFAULT NULL,
        status ENUM('pending','cleared','reconciled','void') DEFAULT 'pending',
        reconciled_date DATE DEFAULT NULL,
        reconciled_by INT(11) DEFAULT NULL,
        attachment_path VARCHAR(500),
        created_by INT(11),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE CASCADE
    )");
    
    // Bank Reconciliation table
    $pdo->exec("CREATE TABLE IF NOT EXISTS bank_reconciliations (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT(11) NOT NULL,
        bank_account_id INT(11) NOT NULL,
        reconciliation_date DATE NOT NULL,
        statement_ending_balance DECIMAL(15,2) NOT NULL,
        statement_start_date DATE NOT NULL,
        statement_end_date DATE NOT NULL,
        book_balance DECIMAL(15,2) NOT NULL,
        difference_amount DECIMAL(15,2) NOT NULL,
        is_reconciled TINYINT(1) DEFAULT 0,
        notes TEXT,
        created_by INT(11),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE CASCADE
    )");
    
    // Reconciliation Items table (matches cleared during reconciliation)
    $pdo->exec("CREATE TABLE IF NOT EXISTS reconciliation_items (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        reconciliation_id INT(11) NOT NULL,
        transaction_id INT(11) NOT NULL,
        is_matched TINYINT(1) DEFAULT 1,
        notes TEXT,
        FOREIGN KEY (reconciliation_id) REFERENCES bank_reconciliations(id) ON DELETE CASCADE,
        FOREIGN KEY (transaction_id) REFERENCES bank_transactions(id) ON DELETE CASCADE
    )");
    
    // Payment Methods table
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_methods (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT(11) NOT NULL,
        method_name VARCHAR(100) NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    )");
    
    // Insert default payment methods
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payment_methods WHERE tenant_id = 0");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $default_methods = ['Cash', 'Bank Transfer', 'Check', 'Credit Card', 'Mobile Money', 'Crypto'];
        foreach ($default_methods as $method) {
            $pdo->prepare("INSERT INTO payment_methods (tenant_id, method_name) VALUES (0, ?)")->execute([$method]);
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

// Get bank accounts for current user
$bank_accounts = [];
try {
    if ($role === 'company_admin') {
        $stmt = $pdo->prepare("SELECT * FROM bank_accounts WHERE tenant_id = ? ORDER BY account_name");
        $stmt->execute([$session_tenant_id]);
    } else {
        $stmt = $pdo->query("SELECT * FROM bank_accounts ORDER BY account_name");
    }
    $bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $bank_accounts = [];
}

// ==================== AJAX HANDLERS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    
    // ==================== BANK ACCOUNTS ====================
    if ($action === 'get_bank_accounts') {
        $tenant_filter = isset($_POST['tenant']) ? (int)$_POST['tenant'] : 0;
        
        $where = [];
        $params = [];
        
        if ($role === 'company_admin') {
            $where[] = "ba.tenant_id = ?";
            $params[] = $session_tenant_id;
        } elseif ($tenant_filter > 0) {
            $where[] = "ba.tenant_id = ?";
            $params[] = $tenant_filter;
        }
        
        $where_clause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
        
        $sql = "SELECT ba.*, t.name as tenant_name,
                (SELECT COUNT(*) FROM bank_transactions WHERE bank_account_id = ba.id AND status != 'void') as transaction_count,
                (SELECT SUM(CASE WHEN transaction_type IN ('deposit') THEN amount ELSE 0 END) - 
                        SUM(CASE WHEN transaction_type IN ('withdrawal','fee') THEN amount ELSE 0 END)
                 FROM bank_transactions WHERE bank_account_id = ba.id AND status = 'cleared') as calculated_balance
                FROM bank_accounts ba
                LEFT JOIN tenants t ON ba.tenant_id = t.id
                $where_clause
                ORDER BY ba.account_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_start(); ?>
        <div class="row">
            <?php foreach ($accounts as $acc): 
                $balanceColor = $acc['current_balance'] >= 0 ? 'success' : 'danger';
            ?>
            <div class="col-md-6 col-lg-4 mb-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5 class="card-title"><?= htmlspecialchars($acc['account_name']) ?></h5>
                                <h6 class="card-subtitle mb-2 text-muted"><?= htmlspecialchars($acc['bank_name']) ?></h6>
                            </div>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-light" data-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item edit-account" data-id="<?= $acc['id'] ?>" href="#"><i class="fas fa-edit"></i> Edit</a>
                                    <a class="dropdown-item reconcile-account" data-id="<?= $acc['id'] ?>" data-name="<?= htmlspecialchars($acc['account_name']) ?>" href="#"><i class="fas fa-check-double"></i> Reconcile</a>
                                    <a class="dropdown-item view-transactions" data-id="<?= $acc['id'] ?>" data-name="<?= htmlspecialchars($acc['account_name']) ?>" href="#"><i class="fas fa-list"></i> Transactions</a>
                                    <?php if ($role === 'superadmin'): ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger delete-account" data-id="<?= $acc['id'] ?>" data-name="<?= htmlspecialchars($acc['account_name']) ?>" href="#"><i class="fas fa-trash"></i> Delete</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <div class="text-center">
                            <small class="text-muted">Current Balance</small>
                            <h3 class="text-<?= $balanceColor ?>">$<?= number_format($acc['current_balance'], 2) ?></h3>
                            <small>Opening: $<?= number_format($acc['opening_balance'], 2) ?></small><br>
                            <small class="text-muted"><?= $acc['transaction_count'] ?? 0 ?> Transactions</small>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between">
                            <button class="btn btn-sm btn-success add-deposit" data-id="<?= $acc['id'] ?>" data-name="<?= htmlspecialchars($acc['account_name']) ?>"><i class="fas fa-plus-circle"></i> Deposit</button>
                            <button class="btn btn-sm btn-danger add-withdrawal" data-id="<?= $acc['id'] ?>" data-name="<?= htmlspecialchars($acc['account_name']) ?>"><i class="fas fa-minus-circle"></i> Withdraw</button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($accounts)): ?>
            <div class="col-12">
                <div class="alert alert-info text-center">No bank accounts found. Click "Add Bank Account" to create one.</div>
            </div>
            <?php endif; ?>
        </div>
        <?php
        echo json_encode(['html' => ob_get_clean()]);
        exit;
    }
    
    if ($action === 'save_bank_account') {
        $id = $_POST['id'] ?? '';
        $tenant_id = $_POST['tenant_id'] ?? ($role === 'company_admin' ? $session_tenant_id : 0);
        $account_name = trim($_POST['account_name'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $account_type = $_POST['account_type'] ?? 'checking';
        $currency = $_POST['currency'] ?? 'USD';
        $opening_balance = (float)($_POST['opening_balance'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($account_name) || empty($bank_name) || empty($account_number)) {
            echo json_encode(['success' => false, 'message' => 'Account Name, Bank Name, and Account Number are required']);
            exit;
        }
        
        try {
            if (empty($id)) {
                $stmt = $pdo->prepare("INSERT INTO bank_accounts (tenant_id, account_name, bank_name, account_number, account_type, currency, opening_balance, current_balance, notes, is_active, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$tenant_id, $account_name, $bank_name, $account_number, $account_type, $currency, $opening_balance, $opening_balance, $notes, $is_active, $user_id]);
                echo json_encode(['success' => true, 'message' => 'Bank account added successfully']);
            } else {
                $stmt = $pdo->prepare("UPDATE bank_accounts SET account_name=?, bank_name=?, account_number=?, account_type=?, currency=?, opening_balance=?, notes=?, is_active=? WHERE id=?");
                $stmt->execute([$account_name, $bank_name, $account_number, $account_type, $currency, $opening_balance, $notes, $is_active, $id]);
                echo json_encode(['success' => true, 'message' => 'Bank account updated successfully']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'delete_bank_account') {
        $id = $_POST['id'] ?? 0;
        try {
            $stmt = $pdo->prepare("DELETE FROM bank_accounts WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Bank account deleted']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_bank_account') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM bank_accounts WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit;
    }
    
    // ==================== TRANSACTIONS ====================
    if ($action === 'get_transactions') {
        $account_id = (int)$_POST['account_id'];
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;
        $search = $_POST['search'] ?? '';
        $status_filter = $_POST['status'] ?? '';
        
        $where = ["bank_account_id = ?"];
        $params = [$account_id];
        
        if (!empty($search)) {
            $where[] = "(description LIKE ? OR reference_number LIKE ? OR category LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if (!empty($status_filter)) {
            $where[] = "status = ?";
            $params[] = $status_filter;
        }
        
        $where_clause = "WHERE " . implode(" AND ", $where);
        
        $count_sql = "SELECT COUNT(*) as total FROM bank_transactions $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total / $limit);
        
        $sql = "SELECT * FROM bank_transactions $where_clause ORDER BY transaction_date DESC, created_at DESC LIMIT $limit OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_start(); ?>
        <table class="table table-hover">
            <thead>
                <tr><th>Date</th><th>Type</th><th>Description</th><th>Reference</th><th>Amount</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $t): 
                    $typeClass = $t['transaction_type'] == 'deposit' ? 'success' : 'danger';
                    $typeIcon = $t['transaction_type'] == 'deposit' ? 'arrow-up' : 'arrow-down';
                ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($t['transaction_date'])) ?></td>
                    <td><span class="badge badge-<?= $typeClass ?>"><i class="fas fa-<?= $typeIcon ?>"></i> <?= ucfirst($t['transaction_type']) ?></span></td>
                    <td><?= htmlspecialchars($t['description']) ?></td>
                    <td><small><?= htmlspecialchars($t['reference_number'] ?? '-') ?></small></td>
                    <td class="text-<?= $typeClass ?>">$<?= number_format($t['amount'], 2) ?></td>
                    <td>
                        <span class="badge badge-<?= $t['status'] == 'cleared' ? 'success' : ($t['status'] == 'reconciled' ? 'info' : 'warning') ?>">
                            <?= ucfirst($t['status']) ?>
                        </span>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-info edit-transaction" data-id="<?= $t['id'] ?>"><i class="fas fa-edit"></i></button>
                        <?php if ($t['status'] != 'reconciled'): ?>
                        <button class="btn btn-sm btn-danger delete-transaction" data-id="<?= $t['id'] ?>"><i class="fas fa-trash"></i></button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php $html = ob_get_clean();
        
        echo json_encode(['html' => $html, 'total_pages' => $total_pages, 'current_page' => $page, 'total' => $total]);
        exit;
    }
    
    if ($action === 'add_transaction') {
        $bank_account_id = (int)$_POST['bank_account_id'];
        $transaction_date = $_POST['transaction_date'];
        $transaction_type = $_POST['transaction_type'];
        $amount = (float)$_POST['amount'];
        $description = trim($_POST['description']);
        $reference_number = trim($_POST['reference_number'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $status = $_POST['status'] ?? 'cleared';
        
        if (empty($transaction_date) || $amount <= 0 || empty($description)) {
            echo json_encode(['success' => false, 'message' => 'Date, Amount, and Description are required']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Get tenant_id from bank account
            $stmt = $pdo->prepare("SELECT tenant_id FROM bank_accounts WHERE id = ?");
            $stmt->execute([$bank_account_id]);
            $tenant_id = $stmt->fetch(PDO::FETCH_ASSOC)['tenant_id'];
            
            // Insert transaction
            $stmt = $pdo->prepare("INSERT INTO bank_transactions (tenant_id, bank_account_id, transaction_date, transaction_type, amount, description, reference_number, category, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$tenant_id, $bank_account_id, $transaction_date, $transaction_type, $amount, $description, $reference_number, $category, $status, $user_id]);
            
            // Update bank account balance
            if ($transaction_type == 'deposit') {
                $stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?");
            } else {
                $stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?");
            }
            $stmt->execute([$amount, $bank_account_id]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Transaction added successfully']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'delete_transaction') {
        $id = $_POST['id'] ?? 0;
        try {
            $pdo->beginTransaction();
            
            // Get transaction details
            $stmt = $pdo->prepare("SELECT * FROM bank_transactions WHERE id = ?");
            $stmt->execute([$id]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($transaction['status'] == 'reconciled') {
                echo json_encode(['success' => false, 'message' => 'Cannot delete reconciled transaction']);
                exit;
            }
            
            // Reverse balance update
            if ($transaction['transaction_type'] == 'deposit') {
                $stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?");
            } else {
                $stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?");
            }
            $stmt->execute([$transaction['amount'], $transaction['bank_account_id']]);
            
            // Delete transaction
            $stmt = $pdo->prepare("DELETE FROM bank_transactions WHERE id = ?");
            $stmt->execute([$id]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Transaction deleted']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // ==================== RECONCILIATION ====================
    if ($action === 'start_reconciliation') {
        $account_id = (int)$_POST['account_id'];
        $statement_date = $_POST['statement_date'];
        $statement_balance = (float)$_POST['statement_balance'];
        
        try {
            // Get account details
            $stmt = $pdo->prepare("SELECT * FROM bank_accounts WHERE id = ?");
            $stmt->execute([$account_id]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get unreconciled transactions
            $stmt = $pdo->prepare("SELECT * FROM bank_transactions WHERE bank_account_id = ? AND status IN ('pending','cleared') ORDER BY transaction_date");
            $stmt->execute([$account_id]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ob_start(); ?>
            <div class="alert alert-info">
                <strong>Account:</strong> <?= htmlspecialchars($account['account_name']) ?><br>
                <strong>Current Book Balance:</strong> $<?= number_format($account['current_balance'], 2) ?><br>
                <strong>Statement Balance as of <?= date('d/m/Y', strtotime($statement_date)) ?>:</strong> $<?= number_format($statement_balance, 2) ?>
            </div>
            
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr><th><input type="checkbox" id="selectAll"></th><th>Date</th><th>Description</th><th>Reference</th><th>Amount</th><th>Type</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $t): ?>
                        <tr>
                            <td><input type="checkbox" class="transaction-check" data-id="<?= $t['id'] ?>" data-amount="<?= $t['amount'] ?>" data-type="<?= $t['transaction_type'] ?>"></td>
                            <td><?= date('d/m/Y', strtotime($t['transaction_date'])) ?></td>
                            <td><?= htmlspecialchars($t['description']) ?></td>
                            <td><?= htmlspecialchars($t['reference_number'] ?? '-') ?></td>
                            <td class="text-<?= $t['transaction_type'] == 'deposit' ? 'success' : 'danger' ?>">$<?= number_format($t['amount'], 2) ?></td>
                            <td><?= ucfirst($t['transaction_type']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Difference:</label>
                        <input type="text" id="differenceAmount" class="form-control" readonly>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Notes (optional):</label>
                        <textarea id="reconcileNotes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <?php
            echo json_encode(['success' => true, 'html' => ob_get_clean(), 'account' => $account, 'statement_balance' => $statement_balance, 'statement_date' => $statement_date]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'complete_reconciliation') {
        $account_id = (int)$_POST['account_id'];
        $statement_date = $_POST['statement_date'];
        $statement_balance = (float)$_POST['statement_balance'];
        $selected_transactions = json_decode($_POST['selected_transactions'] ?? '[]', true);
        $notes = trim($_POST['notes'] ?? '');
        
        try {
            $pdo->beginTransaction();
            
            // Get account info
            $stmt = $pdo->prepare("SELECT * FROM bank_accounts WHERE id = ?");
            $stmt->execute([$account_id]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Calculate selected deposits and withdrawals
            $selected_deposits = 0;
            $selected_withdrawals = 0;
            
            foreach ($selected_transactions as $trans_id) {
                $stmt = $pdo->prepare("SELECT transaction_type, amount FROM bank_transactions WHERE id = ?");
                $stmt->execute([$trans_id]);
                $t = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($t['transaction_type'] == 'deposit') {
                    $selected_deposits += $t['amount'];
                } else {
                    $selected_withdrawals += $t['amount'];
                }
                
                // Mark as reconciled
                $stmt = $pdo->prepare("UPDATE bank_transactions SET status = 'reconciled', reconciled_date = ?, reconciled_by = ? WHERE id = ?");
                $stmt->execute([$statement_date, $user_id, $trans_id]);
            }
            
            // Calculate book balance of selected transactions
            $selected_balance = $account['opening_balance'] + $selected_deposits - $selected_withdrawals;
            $difference = $statement_balance - $selected_balance;
            
            // Create reconciliation record
            $stmt = $pdo->prepare("INSERT INTO bank_reconciliations (tenant_id, bank_account_id, reconciliation_date, statement_ending_balance, statement_start_date, statement_end_date, book_balance, difference_amount, is_reconciled, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$account['tenant_id'], $account_id, date('Y-m-d'), $statement_balance, date('Y-m-d', strtotime('-30 days')), $statement_date, $selected_balance, $difference, 1, $notes, $user_id]);
            $reconciliation_id = $pdo->lastInsertId();
            
            // Link reconciliation items
            foreach ($selected_transactions as $trans_id) {
                $stmt = $pdo->prepare("INSERT INTO reconciliation_items (reconciliation_id, transaction_id) VALUES (?,?)");
                $stmt->execute([$reconciliation_id, $trans_id]);
            }
            
            // Update account balance to match statement
            $stmt = $pdo->prepare("UPDATE bank_accounts SET current_balance = ? WHERE id = ?");
            $stmt->execute([$statement_balance, $account_id]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Reconciliation completed successfully']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_reconciliation_history') {
        $account_id = (int)$_POST['account_id'];
        
        $stmt = $pdo->prepare("SELECT * FROM bank_reconciliations WHERE bank_account_id = ? ORDER BY reconciliation_date DESC LIMIT 10");
        $stmt->execute([$account_id]);
        $reconciliations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_start(); ?>
        <table class="table table-sm">
            <thead>
                <tr><th>Date</th><th>Statement Balance</th><th>Book Balance</th><th>Difference</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($reconciliations as $r): ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($r['reconciliation_date'])) ?></td>
                    <td>$<?= number_format($r['statement_ending_balance'], 2) ?></td>
                    <td>$<?= number_format($r['book_balance'], 2) ?></td>
                    <td class="<?= abs($r['difference_amount']) > 0.01 ? 'text-danger' : 'text-success' ?>">$<?= number_format($r['difference_amount'], 2) ?></td>
                    <td><span class="badge badge-success">Reconciled</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        echo json_encode(['html' => ob_get_clean()]);
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
    <title>Bank Reconciliation | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root { --curdun-violet: #2D1859; --curdun-yellow: #F5C410; }
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .page-header { background: linear-gradient(135deg, var(--curdun-violet), #4B2C85); border-radius: 16px; padding: 20px; margin-bottom: 25px; color: white; }
        .btn-primary-custom { background: var(--curdun-yellow); color: var(--curdun-violet); border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .modal-header { background: linear-gradient(135deg, var(--curdun-violet), #4B2C85); color: white; }
        .modal-header .close { color: white; }
        .card { transition: transform 0.2s; }
        .card:hover { transform: translateY(-5px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .filters-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 25px; display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 150px; }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>
    
    <div class="page-header d-flex justify-content-between align-items-center">
        <h1><i class="fas fa-university"></i> Bank Reconciliation & Payment Tracking</h1>
        <div>
            <button class="btn btn-light" id="addAccountBtn"><i class="fas fa-plus"></i> Add Bank Account</button>
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
            <button class="btn-primary-custom" id="refreshBtn"><i class="fas fa-sync-alt"></i> Refresh</button>
        </div>
    </div>
    
    <!-- Bank Accounts Container -->
    <div id="accountsContainer"></div>
    
    <!-- Transaction Modal -->
    <div class="modal fade" id="transactionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="transactionModalTitle">Add Transaction</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <form id="transactionForm">
                    <div class="modal-body">
                        <input type="hidden" name="bank_account_id" id="transAccountId">
                        <input type="hidden" name="ajax_action" value="add_transaction">
                        <div class="form-group">
                            <label>Transaction Type *</label>
                            <select name="transaction_type" id="transType" class="form-control" required>
                                <option value="deposit">Deposit (Money In)</option>
                                <option value="withdrawal">Withdrawal (Money Out)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Date *</label>
                            <input type="date" name="transaction_date" id="transDate" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Amount *</label>
                            <input type="number" step="0.01" name="amount" id="transAmount" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Description *</label>
                            <input type="text" name="description" id="transDescription" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Reference Number</label>
                            <input type="text" name="reference_number" id="transReference" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Category</label>
                            <input type="text" name="category" id="transCategory" class="form-control" placeholder="e.g., Rent, Salary, Sales">
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="transStatus" class="form-control">
                                <option value="cleared">Cleared</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn" style="background:var(--curdun-violet);color:white;">Save Transaction</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Bank Account Modal -->
    <div class="modal fade" id="bankAccountModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Bank Account Details</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <form id="bankAccountForm">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="accountId">
                        <input type="hidden" name="ajax_action" value="save_bank_account">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Account Name *</label>
                                    <input type="text" name="account_name" id="accountName" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Bank Name *</label>
                                    <input type="text" name="bank_name" id="bankName" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Account Number *</label>
                                    <input type="text" name="account_number" id="accountNumber" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Account Type</label>
                                    <select name="account_type" id="accountType" class="form-control">
                                        <option value="checking">Checking</option>
                                        <option value="savings">Savings</option>
                                        <option value="cash">Cash</option>
                                        <option value="mobile_money">Mobile Money</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Currency</label>
                                    <select name="currency" id="currency" class="form-control">
                                        <option value="USD">USD</option>
                                        <option value="SOS">SOS</option>
                                        <option value="EUR">EUR</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Opening Balance</label>
                                    <input type="number" step="0.01" name="opening_balance" id="openingBalance" class="form-control" value="0">
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea name="notes" id="accountNotes" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="form-group">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="isActive" name="is_active" checked>
                                <label class="custom-control-label" for="isActive">Active</label>
                            </div>
                        </div>
                        <?php if ($role === 'superadmin'): ?>
                        <div class="form-group">
                            <label>Tenant</label>
                            <select name="tenant_id" id="accountTenantId" class="form-control">
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
                        <button type="submit" class="btn" style="background:var(--curdun-violet);color:white;">Save Account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Transactions Modal -->
    <div class="modal fade" id="transactionsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="transactionsModalTitle">Transactions</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <input type="text" id="transSearch" class="form-control" placeholder="Search transactions...">
                        </div>
                        <div class="col-md-3">
                            <select id="transStatusFilter" class="form-control">
                                <option value="">All Status</option>
                                <option value="pending">Pending</option>
                                <option value="cleared">Cleared</option>
                                <option value="reconciled">Reconciled</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary" id="applyTransFilter">Filter</button>
                        </div>
                    </div>
                    <div id="transactionsTableContainer"></div>
                    <div id="transactionsPagination" class="mt-3 text-center"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Reconciliation Modal -->
    <div class="modal fade" id="reconciliationModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Bank Reconciliation</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body" id="reconciliationBody">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Statement Date *</label>
                                <input type="date" id="stmtDate" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Statement Ending Balance *</label>
                                <input type="number" step="0.01" id="stmtBalance" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    <div id="reconciliationTransactions"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="startReconcileBtn" style="background:var(--curdun-violet);">Start Reconciliation</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentAccountId = null;
let currentAccountName = null;
let currentReconcileAccountId = null;
let currentReconcileAccountName = null;
let currentReconcileStatementDate = null;
let currentReconcileStatementBalance = null;
let transCurrentPage = 1;

function showAlert(type, msg) {
    $('#alert-placeholder').html(`<div class="alert alert-${type} alert-dismissible fade show">${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
    setTimeout(() => $('.alert').fadeOut(), 5000);
}

function loadBankAccounts() {
    let tenant = $('#tenantFilter').val();
    $.post(window.location.href, {ajax_action: 'get_bank_accounts', tenant: tenant}, function(res) {
        $('#accountsContainer').html(res.html);
        attachAccountEvents();
    }, 'json');
}

function attachAccountEvents() {
    $('.edit-account').click(function(e) {
        e.preventDefault();
        let id = $(this).data('id');
        $.post(window.location.href, {ajax_action: 'get_bank_account', id: id}, function(res) {
            $('#accountId').val(res.id);
            $('#accountName').val(res.account_name);
            $('#bankName').val(res.bank_name);
            $('#accountNumber').val(res.account_number);
            $('#accountType').val(res.account_type);
            $('#currency').val(res.currency);
            $('#openingBalance').val(res.opening_balance);
            $('#accountNotes').val(res.notes);
            $('#isActive').prop('checked', res.is_active == 1);
            if(res.tenant_id) $('#accountTenantId').val(res.tenant_id);
            $('#bankAccountModal').modal('show');
        }, 'json');
    });
    
    $('.delete-account').click(function(e) {
        e.preventDefault();
        if(confirm('Delete this bank account? All transactions will be deleted as well.')) {
            $.post(window.location.href, {ajax_action: 'delete_bank_account', id: $(this).data('id')}, function(res) {
                showAlert(res.success ? 'success' : 'error', res.message);
                if(res.success) loadBankAccounts();
            }, 'json');
        }
    });
    
    $('.add-deposit').click(function() {
        currentAccountId = $(this).data('id');
        currentAccountName = $(this).data('name');
        $('#transAccountId').val(currentAccountId);
        $('#transType').val('deposit');
        $('#transDate').val(new Date().toISOString().split('T')[0]);
        $('#transAmount').val('');
        $('#transDescription').val('');
        $('#transReference').val('');
        $('#transCategory').val('');
        $('#transactionModalTitle').text('Add Deposit - ' + currentAccountName);
        $('#transactionModal').modal('show');
    });
    
    $('.add-withdrawal').click(function() {
        currentAccountId = $(this).data('id');
        currentAccountName = $(this).data('name');
        $('#transAccountId').val(currentAccountId);
        $('#transType').val('withdrawal');
        $('#transDate').val(new Date().toISOString().split('T')[0]);
        $('#transAmount').val('');
        $('#transDescription').val('');
        $('#transReference').val('');
        $('#transCategory').val('');
        $('#transactionModalTitle').text('Add Withdrawal - ' + currentAccountName);
        $('#transactionModal').modal('show');
    });
    
    $('.reconcile-account').click(function(e) {
        e.preventDefault();
        currentReconcileAccountId = $(this).data('id');
        currentReconcileAccountName = $(this).data('name');
        $('#stmtDate').val(new Date().toISOString().split('T')[0]);
        $('#stmtBalance').val('');
        $('#reconciliationTransactions').html('');
        $('#reconciliationModal').modal('show');
    });
    
    $('.view-transactions').click(function(e) {
        e.preventDefault();
        currentAccountId = $(this).data('id');
        currentAccountName = $(this).data('name');
        $('#transactionsModalTitle').text('Transactions - ' + currentAccountName);
        transCurrentPage = 1;
        loadTransactions();
        $('#transactionsModal').modal('show');
    });
}

function loadTransactions() {
    let search = $('#transSearch').val();
    let status = $('#transStatusFilter').val();
    $.post(window.location.href, {
        ajax_action: 'get_transactions',
        account_id: currentAccountId,
        page: transCurrentPage,
        search: search,
        status: status
    }, function(res) {
        $('#transactionsTableContainer').html(res.html);
        let pages = '';
        for(let i = 1; i <= res.total_pages; i++) {
            pages += `<button class="btn btn-sm ${i == res.current_page ? 'btn-primary' : 'btn-secondary'} mx-1 trans-page-btn" data-page="${i}">${i}</button>`;
        }
        $('#transactionsPagination').html(pages);
        attachTransactionEvents();
    }, 'json');
}

function attachTransactionEvents() {
    $('.trans-page-btn').click(function() {
        transCurrentPage = $(this).data('page');
        loadTransactions();
    });
    
    $('.delete-transaction').click(function() {
        if(confirm('Delete this transaction?')) {
            $.post(window.location.href, {ajax_action: 'delete_transaction', id: $(this).data('id')}, function(res) {
                showAlert(res.success ? 'success' : 'error', res.message);
                if(res.success) loadTransactions();
            }, 'json');
        }
    });
    
    $('.edit-transaction').click(function() {
        showAlert('info', 'Edit feature coming soon');
    });
}

// Start reconciliation
$('#startReconcileBtn').click(function() {
    let stmtDate = $('#stmtDate').val();
    let stmtBalance = $('#stmtBalance').val();
    
    if(!stmtDate || !stmtBalance) {
        showAlert('error', 'Please enter statement date and balance');
        return;
    }
    
    $.post(window.location.href, {
        ajax_action: 'start_reconciliation',
        account_id: currentReconcileAccountId,
        statement_date: stmtDate,
        statement_balance: stmtBalance
    }, function(res) {
        if(res.success) {
            $('#reconciliationTransactions').html(res.html);
            $('#startReconcileBtn').hide();
            $('#reconciliationBody').append(`
                <button class="btn btn-success" id="completeReconcileBtn">Complete Reconciliation</button>
            `);
            
            $('#selectAll').click(function() {
                $('.transaction-check').prop('checked', $(this).prop('checked'));
                calculateDifference();
            });
            
            $('.transaction-check').click(function() {
                calculateDifference();
            });
            
            function calculateDifference() {
                let totalCheckedDeposits = 0;
                let totalCheckedWithdrawals = 0;
                $('.transaction-check:checked').each(function() {
                    let amount = parseFloat($(this).data('amount'));
                    let type = $(this).data('type');
                    if(type == 'deposit') {
                        totalCheckedDeposits += amount;
                    } else {
                        totalCheckedWithdrawals += amount;
                    }
                });
                let bookBalance = res.account.opening_balance + totalCheckedDeposits - totalCheckedWithdrawals;
                let difference = res.statement_balance - bookBalance;
                $('#differenceAmount').val('$' + difference.toFixed(2) + ' (Book: $' + bookBalance.toFixed(2) + ')');
                if(Math.abs(difference) > 0.01) {
                    $('#differenceAmount').css('color', 'red');
                } else {
                    $('#differenceAmount').css('color', 'green');
                }
            }
            
            $('#completeReconcileBtn').click(function() {
                let selected = [];
                $('.transaction-check:checked').each(function() {
                    selected.push($(this).data('id'));
                });
                
                $.post(window.location.href, {
                    ajax_action: 'complete_reconciliation',
                    account_id: currentReconcileAccountId,
                    statement_date: stmtDate,
                    statement_balance: stmtBalance,
                    selected_transactions: JSON.stringify(selected),
                    notes: $('#reconcileNotes').val()
                }, function(res) {
                    showAlert(res.success ? 'success' : 'error', res.message);
                    if(res.success) {
                        $('#reconciliationModal').modal('hide');
                        loadBankAccounts();
                    }
                }, 'json');
            });
        } else {
            showAlert('error', res.message);
        }
    }, 'json');
});

// Form submissions
$('#bankAccountForm').submit(function(e) {
    e.preventDefault();
    $.post(window.location.href, $(this).serialize(), function(res) {
        showAlert(res.success ? 'success' : 'error', res.message);
        if(res.success) {
            $('#bankAccountModal').modal('hide');
            loadBankAccounts();
        }
    }, 'json');
});

$('#transactionForm').submit(function(e) {
    e.preventDefault();
    $.post(window.location.href, $(this).serialize(), function(res) {
        showAlert(res.success ? 'success' : 'error', res.message);
        if(res.success) {
            $('#transactionModal').modal('hide');
            loadBankAccounts();
            if(currentAccountId) loadTransactions();
        }
    }, 'json');
});

$('#addAccountBtn').click(function() {
    $('#bankAccountForm')[0].reset();
    $('#accountId').val('');
    $('#isActive').prop('checked', true);
    $('#openingBalance').val(0);
    $('#bankAccountModal').modal('show');
});

$('#refreshBtn').click(function() {
    loadBankAccounts();
});

$('#applyTransFilter').click(function() {
    transCurrentPage = 1;
    loadTransactions();
});

$('#transSearch').keypress(function(e) {
    if(e.which === 13) {
        transCurrentPage = 1;
        loadTransactions();
    }
});

// Initialize
loadBankAccounts();

<?php if ($role === 'company_admin'): ?>
$('#tenantFilter').prop('disabled', true);
<?php endif; ?>
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>