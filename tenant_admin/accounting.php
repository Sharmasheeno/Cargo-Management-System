<?php
// tenant_admin/accounting.php
// Accounting & Banking Management for Cargo Management System - Tenant Admin

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

require_once __DIR__ . '/../config/db_connect.php';

$db = $pdo;

/**
 * Generate a strong unique reference number.
 * time() alone can duplicate inside the same second, so we use microtime + random bytes.
 */
function generateReferenceNumber($prefix) {
    try {
        return $prefix . '-' . date('YmdHis') . '-' . strtoupper(substr(str_replace('.', '', uniqid('', true)), -6)) . '-' . strtoupper(bin2hex(random_bytes(2)));
    } catch (Exception $e) {
        return $prefix . '-' . date('YmdHis') . '-' . strtoupper(substr(str_replace('.', '', uniqid('', true)), -8));
    }
}

/**
 * Journal entries are saved as multiple debit/credit rows under the same entry_number.
 * Therefore entry_number must NOT be UNIQUE; otherwise the second row of the same journal fails with SQLSTATE 23000 / 1062.
 */
function ensureJournalEntryNumberIsNotUnique(PDO $db) {
    try {
        $stmt = $db->query("SHOW INDEX FROM journal_entries WHERE Column_name = 'entry_number'");
        $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($indexes as $idx) {
            if ((int)$idx['Non_unique'] === 0) {
                $keyName = $idx['Key_name'];
                $db->exec("ALTER TABLE journal_entries DROP INDEX `" . str_replace('`', '``', $keyName) . "`");
            }
        }

        // Add a normal searchable index if it does not exist.
        $stmt = $db->query("SHOW INDEX FROM journal_entries WHERE Key_name = 'idx_journal_entry_number'");
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $db->exec("ALTER TABLE journal_entries ADD INDEX idx_journal_entry_number (entry_number)");
        }
    } catch (PDOException $e) {
        // Do not stop the page if the table does not exist yet or the hosting user lacks ALTER permission.
    }
}

$tenant_id = $session_tenant_id;
$active_tab = $_GET['tab'] ?? 'accounts';
$user_id = $_SESSION['user_id'];

// Get tenant name
$tenant_name = '';
try {
    $stmt = $db->prepare("SELECT name FROM tenants WHERE id = ?");
    $stmt->execute([$session_tenant_id]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_name = $tenant['name'] ?? 'My Company';
} catch (PDOException $e) {
    $tenant_name = 'My Company';
}

// ==========================================
// CREATE MISSING TABLES IF THEY DON'T EXIST
// ==========================================
try {
    // Check if chart_of_accounts table exists, create if not
    $db->exec("CREATE TABLE IF NOT EXISTS `chart_of_accounts` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `tenant_id` int(11) DEFAULT NULL,
        `account_code` varchar(20) NOT NULL,
        `account_name` varchar(255) NOT NULL,
        `account_type` enum('asset','liability','equity','revenue','expense') DEFAULT 'asset',
        `balance` decimal(15,2) DEFAULT 0.00,
        `is_active` tinyint(1) DEFAULT 1,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `tenant_id` (`tenant_id`),
        KEY `account_code` (`account_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Insert default chart of accounts if empty (for this tenant only)
    $check = $db->prepare("SELECT COUNT(*) as count FROM chart_of_accounts WHERE tenant_id = ?");
    $check->execute([$tenant_id]);
    $result = $check->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] == 0) {
        $default_accounts = [
            ['1000', 'Cash on Hand', 'asset'],
            ['1010', 'Bank Accounts', 'asset'],
            ['1020', 'Customer Receivables', 'asset'],
            ['2000', 'Supplier Payables', 'liability'],
            ['3000', 'Owner\'s Equity', 'equity'],
            ['4000', 'Service Revenue', 'revenue'],
            ['5000', 'Operating Expenses', 'expense'],
            ['5010', 'Transport Expenses', 'expense'],
            ['5020', 'Warehouse Expenses', 'expense'],
            ['5030', 'Administrative Expenses', 'expense']
        ];
        $stmt = $db->prepare("INSERT INTO chart_of_accounts (tenant_id, account_code, account_name, account_type) VALUES (?, ?, ?, ?)");
        foreach ($default_accounts as $acc) {
            $stmt->execute([$tenant_id, $acc[0], $acc[1], $acc[2]]);
        }
    }
} catch (PDOException $e) {
    // Table might already exist
}

// Fix wrong UNIQUE index on journal_entries.entry_number if it exists.
ensureJournalEntryNumberIsNotUnique($db);


function reverseJournalBalances(PDO $db, int $tenant_id, string $entry_number) {
    $stmt = $db->prepare("SELECT account_code, debit, credit FROM journal_entries WHERE tenant_id = ? AND entry_number = ?");
    $stmt->execute([$tenant_id, $entry_number]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $balance_change = floatval($row['credit']) - floatval($row['debit']);
        $stmtAcc = $db->prepare("UPDATE chart_of_accounts SET balance = balance + ? WHERE account_code = ? AND tenant_id = ?");
        $stmtAcc->execute([$balance_change, $row['account_code'], $tenant_id]);
    }
}

function validateJournalLines($lines) {
    if (!is_array($lines) || count($lines) < 1) {
        return 'Please add at least one line';
    }

    $totalDebit = 0;
    $totalCredit = 0;
    $validLineCount = 0;

    foreach ($lines as $line) {
        $debit = floatval($line['debit'] ?? 0);
        $credit = floatval($line['credit'] ?? 0);
        $accountCode = trim($line['account_code'] ?? '');

        if ($accountCode === '') {
            continue;
        }

        if ($debit > 0 && $credit > 0) {
            return 'One row cannot have both debit and credit';
        }

        if ($debit > 0 || $credit > 0) {
            $validLineCount++;
            $totalDebit += $debit;
            $totalCredit += $credit;
        }
    }

    if ($validLineCount < 1) {
        return 'Please select account and add debit or credit amount';
    }

    if (abs($totalDebit - $totalCredit) > 0.01) {
        return 'Journal entry is not balanced. Debit and credit must be equal.';
    }

    return null;
}

// ==========================================
// HANDLE AJAX ACTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['success' => false, 'message' => 'Invalid action'];

    // 1. CHART OF ACCOUNTS ACTIONS
    if ($action === 'save_account') {
        $id = $_POST['account_id'] ?? null;
        $code = trim($_POST['account_code']);
        $name = trim($_POST['account_name']);
        $type = $_POST['account_type'];
        
        if (empty($code) || empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Account code and name are required']);
            exit();
        }
        
        try {
            if ($id && $id > 0) {
                $stmt = $db->prepare("UPDATE chart_of_accounts SET account_code = ?, account_name = ?, account_type = ? WHERE id = ? AND tenant_id = ?");
                $success = $stmt->execute([$code, $name, $type, $id, $tenant_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO chart_of_accounts (tenant_id, account_code, account_name, account_type) VALUES (?, ?, ?, ?)");
                $success = $stmt->execute([$tenant_id, $code, $name, $type]);
            }
            $accountData = null;
            if ($success) {
                if ($id && $id > 0) {
                    $accountId = (int)$id;
                } else {
                    $accountId = (int)$db->lastInsertId();
                }
                $stmtFetch = $db->prepare("SELECT * FROM chart_of_accounts WHERE id = ? AND tenant_id = ?");
                $stmtFetch->execute([$accountId, $tenant_id]);
                $accountData = $stmtFetch->fetch(PDO::FETCH_ASSOC);
            }
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Account saved successfully' : 'Failed to save account',
                'account' => $accountData
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit();
    }
    
    // 2. Delete account
    if ($action === 'delete_account') {
        $id = $_POST['account_id'] ?? 0;
        try {
            $stmt = $db->prepare("DELETE FROM chart_of_accounts WHERE id = ? AND tenant_id = ?");
            $success = $stmt->execute([$id, $tenant_id]);
            echo json_encode(['success' => $success, 'message' => $success ? 'Account deleted successfully' : 'Failed to delete account']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit();
    }

    // 3. JOURNAL ACTIONS
    if ($action === 'save_journal_entry') {
        $journal_id = intval($_POST['journal_id'] ?? 0);
        $original_entry_number = trim($_POST['original_entry_number'] ?? '');
        $date = $_POST['entry_date'] ?? date('Y-m-d');
        $manualNumber = trim($_POST['entry_number'] ?? '');
        $number = $manualNumber !== '' ? $manualNumber : generateReferenceNumber('JE');
        $description = trim($_POST['description'] ?? '');
        $lines = json_decode($_POST['lines'] ?? '[]', true);

        $lineError = validateJournalLines($lines);
        if ($lineError) {
            echo json_encode(['success' => false, 'message' => $lineError]);
            exit();
        }

        // If this is edit mode, get the old entry number by id if needed.
        if ($journal_id > 0 && $original_entry_number === '') {
            $stmtOld = $db->prepare("SELECT entry_number FROM journal_entries WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmtOld->execute([$journal_id, $tenant_id]);
            $oldRow = $stmtOld->fetch(PDO::FETCH_ASSOC);
            $original_entry_number = $oldRow['entry_number'] ?? '';
        }

        // Prevent duplicate manual/new reference number, except when editing the same journal.
        $checkDuplicate = $db->prepare("SELECT COUNT(*) FROM journal_entries WHERE tenant_id = ? AND entry_number = ? AND entry_number <> ?");
        $checkDuplicate->execute([$tenant_id, $number, $original_entry_number]);
        if ($checkDuplicate->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'This journal entry number already exists. Please use another number.']);
            exit();
        }

        $db->beginTransaction();
        try {
            // Edit mode: reverse old balances and remove old rows first.
            if ($original_entry_number !== '') {
                reverseJournalBalances($db, (int)$tenant_id, $original_entry_number);
                $stmtDeleteOld = $db->prepare("DELETE FROM journal_entries WHERE tenant_id = ? AND entry_number = ?");
                $stmtDeleteOld->execute([$tenant_id, $original_entry_number]);
            }

            foreach ($lines as $line) {
                $account_code = trim($line['account_code'] ?? '');
                $account_name = trim($line['account_name'] ?? '');
                $debit = floatval($line['debit'] ?? 0);
                $credit = floatval($line['credit'] ?? 0);

                if ($account_code === '' || ($debit <= 0 && $credit <= 0)) {
                    continue;
                }

                $stmt = $db->prepare("INSERT INTO journal_entries (tenant_id, entry_number, entry_date, account_name, account_code, debit, credit, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$tenant_id, $number, $date, $account_name, $account_code, $debit, $credit, $description, $user_id]);

                // Update balance in chart_of_accounts
                $balance_change = $debit - $credit;
                $stmtAcc = $db->prepare("UPDATE chart_of_accounts SET balance = balance + ? WHERE account_code = ? AND tenant_id = ?");
                $stmtAcc->execute([$balance_change, $account_code, $tenant_id]);
            }

            $db->commit();
            echo json_encode(['success' => true, 'message' => $original_entry_number !== '' ? 'Journal entry updated successfully' : 'Journal entry saved successfully']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    if ($action === 'get_journal_entry') {
        $entry_number = trim($_POST['entry_number'] ?? '');
        try {
            $stmt = $db->prepare("SELECT * FROM journal_entries WHERE tenant_id = ? AND entry_number = ? ORDER BY id ASC");
            $stmt->execute([$tenant_id, $entry_number]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                echo json_encode(['success' => false, 'message' => 'Journal entry not found']);
                exit();
            }
            echo json_encode(['success' => true, 'entry' => [
                'id' => $rows[0]['id'],
                'entry_number' => $rows[0]['entry_number'],
                'entry_date' => $rows[0]['entry_date'],
                'description' => $rows[0]['description'] ?? '',
                'lines' => $rows
            ]]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    if ($action === 'delete_journal_entry') {
        $entry_number = trim($_POST['entry_number'] ?? '');
        if ($entry_number === '') {
            echo json_encode(['success' => false, 'message' => 'Missing journal entry number']);
            exit();
        }

        $db->beginTransaction();
        try {
            reverseJournalBalances($db, (int)$tenant_id, $entry_number);
            $stmt = $db->prepare("DELETE FROM journal_entries WHERE tenant_id = ? AND entry_number = ?");
            $stmt->execute([$tenant_id, $entry_number]);
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Journal entry deleted successfully']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    // 4. EXPENSE ACTIONS
    if ($action === 'save_expense') {
        $number = generateReferenceNumber('EXP');
        $category = $_POST['category'];
        $amount = floatval($_POST['amount']);
        $date = $_POST['expense_date'];
        $vendor = $_POST['vendor_name'] ?? '';
        $notes = $_POST['notes'] ?? '';
        $bank_acc_id = $_POST['bank_account_id'] ?? null;
        
        if ($amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']);
            exit();
        }
        
        $db->beginTransaction();
        try {
            // 1. Record Expense
            $stmt = $db->prepare("INSERT INTO expenses (tenant_id, expense_number, expense_category, amount, expense_date, vendor_name, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$tenant_id, $number, $category, $amount, $date, $vendor, $notes, $user_id]);
            $expense_id = $db->lastInsertId();
            
            // 2. Bank Transaction
            if ($bank_acc_id) {
                $stmtBank = $db->prepare("INSERT INTO bank_transactions (tenant_id, bank_account_id, transaction_date, transaction_type, amount, description, reference_type, reference_id, created_by, created_at) VALUES (?, ?, ?, 'expense', ?, ?, 'expense', ?, ?, NOW())");
                $stmtBank->execute([$tenant_id, $bank_acc_id, $date, $amount, "Expense: $vendor - $notes", $expense_id, $user_id]);
                
                // Update bank balance
                $db->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ? AND tenant_id = ?")->execute([$amount, $bank_acc_id, $tenant_id]);
            }
            
            // 3. Accounting (Journal Entry)
            $stmt = $db->prepare("INSERT INTO journal_entries (tenant_id, entry_number, entry_date, account_name, account_code, debit, credit, description, created_by, created_at) VALUES (?, ?, ?, ?, '5000', ?, 0, ?, ?, NOW())");
            $stmt->execute([$tenant_id, $number, $date, $category, $amount, "Expense: $vendor - $notes", $user_id]);
            
            // Update expense account balance
            $db->prepare("UPDATE chart_of_accounts SET balance = balance + ? WHERE account_code = '5000' AND tenant_id = ?")->execute([$amount, $tenant_id]);
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Expense recorded successfully']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }
    
    // 5. Get journal entries
    if ($action === 'get_journal_entries') {
        try {
            $stmt = $db->prepare("SELECT * FROM journal_entries WHERE tenant_id = ? ORDER BY entry_date DESC, id DESC LIMIT 100");
            $stmt->execute([$tenant_id]);
            $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($entries);
        } catch (PDOException $e) {
            echo json_encode([]);
        }
        exit();
    }

    // 6. BANKING ACTIONS
    if ($action === 'save_bank') {
        $name = trim($_POST['account_name']);
        $bank = trim($_POST['bank_name']);
        $number = trim($_POST['account_number']);
        $balance = floatval($_POST['opening_balance']) ?: 0;
        
        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Account name is required']);
            exit();
        }
        
        try {
            $stmt = $db->prepare("INSERT INTO bank_accounts (tenant_id, account_name, bank_name, account_number, opening_balance, current_balance, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $success = $stmt->execute([$tenant_id, $name, $bank, $number, $balance, $balance, $user_id]);
            
            if ($success) {
                // Also add to chart of accounts
                $stmt2 = $db->prepare("INSERT INTO chart_of_accounts (tenant_id, account_code, account_name, account_type, balance) VALUES (?, '1010', ?, 'asset', ?)");
                $stmt2->execute([$tenant_id, "$name ($bank)", $balance]);
            }
            
            echo json_encode(['success' => $success, 'message' => $success ? 'Bank account added successfully' : 'Failed to add bank account']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }
    
    // 7. Delete bank account
    if ($action === 'delete_bank') {
        $id = $_POST['bank_id'] ?? 0;
        try {
            $stmt = $db->prepare("DELETE FROM bank_accounts WHERE id = ? AND tenant_id = ?");
            $success = $stmt->execute([$id, $tenant_id]);
            echo json_encode(['success' => $success, 'message' => $success ? 'Bank account deleted successfully' : 'Failed to delete bank account']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }
    
    exit();
}

// Get data for display - using direct queries instead of db_get_all function
try {
    $stmt = $db->prepare("SELECT * FROM chart_of_accounts WHERE tenant_id = ? ORDER BY account_code ASC");
    $stmt->execute([$tenant_id]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $accounts = [];
}

try {
    $stmt = $db->prepare("SELECT * FROM bank_accounts WHERE tenant_id = ? ORDER BY id DESC");
    $stmt->execute([$tenant_id]);
    $banks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $banks = [];
}

try {
    $stmt = $db->prepare("SELECT * FROM expenses WHERE tenant_id = ? ORDER BY expense_date DESC LIMIT 100");
    $stmt->execute([$tenant_id]);
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $expenses = [];
}

try {
    $stmt = $db->prepare("
        SELECT 
            MIN(id) AS id,
            entry_number,
            MAX(entry_date) AS entry_date,
            GROUP_CONCAT(CONCAT(account_code, ' - ', account_name) ORDER BY id SEPARATOR '<br>') AS account_name,
            SUM(debit) AS debit,
            SUM(credit) AS credit,
            MAX(description) AS description
        FROM journal_entries
        WHERE tenant_id = ?
        GROUP BY entry_number
        ORDER BY MAX(entry_date) DESC, MIN(id) DESC
        LIMIT 100
    ");
    $stmt->execute([$tenant_id]);
    $journal_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $journal_entries = [];
}

include_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounting & Banking - <?= htmlspecialchars($tenant_name) ?> | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root {
            --curdun-primary: #2D1859;
            --curdun-secondary: #F5C410;
            --curdun-violet-light: #4B2C85;
            --curdun-bg: #f8f9fc;
            --curdun-border: #e3e6f0;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .accounting-page { padding: 20px; background: var(--curdun-bg); min-height: 100vh; }
        
        .acc-tabs { 
            display: flex; 
            gap: 5px; 
            background: #fff; 
            padding: 10px 15px; 
            border-radius: 12px; 
            border: 1px solid var(--curdun-border); 
            margin-bottom: 25px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            flex-wrap: wrap;
        }
        
        .acc-tab-btn { 
            padding: 10px 20px; 
            border: none; 
            background: transparent; 
            color: #4e73df; 
            font-weight: 600; 
            border-radius: 8px; 
            cursor: pointer; 
            transition: 0.3s; 
        }
        
        .acc-tab-btn:hover { background: #f8f9fc; color: var(--curdun-primary); }
        .acc-tab-btn.active { background: var(--curdun-primary); color: #fff; }
        
        .glass-card { 
            background: #fff; 
            border-radius: 15px; 
            border: 1px solid var(--curdun-border); 
            box-shadow: 0 4px 12px rgba(0,0,0,0.05); 
            padding: 25px; 
            margin-bottom: 25px; 
        }
        
        .table-custom th { 
            background: #f8f9fc; 
            color: #4e73df; 
            font-weight: 700; 
            text-transform: uppercase; 
            font-size: 11px; 
            letter-spacing: 0.5px; 
            border: none; 
        }
        
        .table-custom td { vertical-align: middle; font-size: 13px; color: #5a5c69; }
        
        .btn-curdun { 
            background: var(--curdun-primary); 
            color: #fff; 
            border-radius: 8px; 
            padding: 8px 18px; 
            font-weight: 600; 
            border: none; 
            transition: 0.3s; 
        }
        
        .btn-curdun:hover { background: var(--curdun-violet-light); color: #fff; transform: translateY(-1px); }
        
        .status-pill { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .pill-asset { background: #e3f2fd; color: #1976d2; }
        .pill-revenue { background: #e8f5e9; color: #2e7d32; }
        .pill-expense { background: #ffebee; color: #c62828; }
        .pill-liability { background: #fff3e0; color: #f57c00; }
        .pill-equity { background: #f3e5f5; color: #7b1fa2; }
        
        .alert-custom { 
            position: fixed; 
            top: 20px; 
            right: 20px; 
            z-index: 9999; 
            min-width: 300px; 
            animation: slideIn 0.3s ease; 
        }
        
        .company-badge {
            background: rgba(82,0,102,0.1);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            color: var(--curdun-primary);
        }
        
        @keyframes slideIn { 
            from { transform: translateX(100%); opacity: 0; } 
            to { transform: translateX(0); opacity: 1; } 
        }
        
        @media (max-width: 768px) {
            .acc-tabs { flex-direction: column; }
            .acc-tab-btn { width: 100%; text-align: center; }
            .glass-card { padding: 15px; }
        }
    </style>
</head>
<body>

<div class="accounting-page">
    <div id="alert-placeholder"></div>
    
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h2 class="mb-0" style="font-weight: 800; color: var(--curdun-primary);">
                <i class="fas fa-chart-line"></i> Accounting & Banking
            </h2>
            <p class="text-muted small">Manage finances, accounts, and banking for <?= htmlspecialchars($tenant_name) ?>.</p>
        </div>
        <div class="company-badge">
            <i class="fas fa-building"></i> <?= htmlspecialchars($tenant_name) ?>
        </div>
    </div>

    <div class="acc-tabs">
        <button class="acc-tab-btn <?= $active_tab == 'accounts' ? 'active' : '' ?>" onclick="switchTab('accounts')">
            <i class="fas fa-sitemap mr-2"></i> Chart of Accounts
        </button>
        <button class="acc-tab-btn <?= $active_tab == 'journals' ? 'active' : '' ?>" onclick="switchTab('journals')">
            <i class="fas fa-book mr-2"></i> Journal Entries
        </button>
        <button class="acc-tab-btn <?= $active_tab == 'banking' ? 'active' : '' ?>" onclick="switchTab('banking')">
            <i class="fas fa-university mr-2"></i> Banking
        </button>
        <button class="acc-tab-btn <?= $active_tab == 'expenses' ? 'active' : '' ?>" onclick="switchTab('expenses')">
            <i class="fas fa-receipt mr-2"></i> Expenses
        </button>
    </div>

    <div id="tab-content-area">
        
        <!-- ACCOUNTS TAB -->
        <?php if($active_tab == 'accounts'): ?>
        <div class="glass-card">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                <h5 class="mb-0 font-weight-bold"><i class="fas fa-list"></i> Chart of Accounts</h5>
                <button class="btn btn-curdun btn-sm" onclick="openAccountModal()">
                    <i class="fas fa-plus"></i> New Account
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-custom">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Account Name</th>
                            <th>Type</th>
                            <th class="text-right">Balance</th>
                            <th class="text-center">Actions</th>
                        </thead>
                    <tbody>
                        <?php if (empty($accounts)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4">No accounts found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($accounts as $acc): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($acc['account_code']) ?></code></td>
                                <td><strong><?= htmlspecialchars($acc['account_name']) ?></strong></td>
                                <td>
                                    <span class="status-pill pill-<?= strtolower($acc['account_type']) ?>">
                                        <?php 
                                        $type_map = [
                                            'asset' => 'ASSET',
                                            'liability' => 'LIABILITY',
                                            'equity' => 'EQUITY',
                                            'revenue' => 'REVENUE',
                                            'expense' => 'EXPENSE'
                                        ];
                                        echo $type_map[$acc['account_type']] ?? strtoupper($acc['account_type']);
                                        ?>
                                    </span>
                                </td>
                                <td class="text-right font-weight-bold">$<?= number_format($acc['balance'] ?? 0, 2) ?></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-primary" onclick='editAccount(<?= json_encode($acc) ?>)'>
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteAccount(<?= $acc['id'] ?>, '<?= htmlspecialchars($acc['account_name']) ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- JOURNALS TAB -->
        <?php if($active_tab == 'journals'): ?>
        <div class="glass-card">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                <h5 class="mb-0 font-weight-bold"><i class="fas fa-book-open"></i> Journal Entries</h5>
                <button class="btn btn-curdun btn-sm" onclick="openJournalModal()">
                    <i class="fas fa-plus"></i> New Journal Entry
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-custom">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Entry #</th>
                            <th>Account</th>
                            <th class="text-right">Debit</th>
                            <th class="text-right">Credit</th>
                            <th>Description</th>
                            <th class="text-center">Actions</th>
                        </thead>
                    <tbody>
                        <?php if (empty($journal_entries)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4">No journal entries found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($journal_entries as $entry): ?>
                            <tr>
                                <td><?= date('M d, Y', strtotime($entry['entry_date'])) ?></td>
                                <td><span class="badge badge-secondary">#<?= htmlspecialchars($entry['entry_number']) ?></span></td>
                                <td><?= $entry['account_name'] ?></td>
                                <td class="text-right <?= $entry['debit'] > 0 ? 'text-success font-weight-bold' : '' ?>">
                                    <?= $entry['debit'] > 0 ? '$'.number_format($entry['debit'], 2) : '-' ?>
                                </td>
                                <td class="text-right <?= $entry['credit'] > 0 ? 'text-danger font-weight-bold' : '' ?>">
                                    <?= $entry['credit'] > 0 ? '$'.number_format($entry['credit'], 2) : '-' ?>
                                </td>
                                <td class="text-muted small"><?= htmlspecialchars($entry['description'] ?? '-') ?></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-primary" onclick="editJournalEntry('<?= htmlspecialchars($entry['entry_number'], ENT_QUOTES) ?>')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteJournalEntry('<?= htmlspecialchars($entry['entry_number'], ENT_QUOTES) ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- BANKING TAB -->
        <?php if($active_tab == 'banking'): ?>
        <div class="row">
            <div class="col-md-5">
                <div class="glass-card">
                    <h5 class="mb-3 font-weight-bold"><i class="fas fa-plus-circle"></i> Add Bank Account</h5>
                    <form id="bankForm">
                        <input type="hidden" name="ajax_action" value="save_bank">
                        <div class="form-group">
                            <label>Account Name <span class="text-danger">*</span></label>
                            <input type="text" name="account_name" class="form-control" required placeholder="e.g., Operating Account">
                        </div>
                        <div class="form-group">
                            <label>Bank Name</label>
                            <input type="text" name="bank_name" class="form-control" placeholder="e.g., Premier Bank">
                        </div>
                        <div class="form-group">
                            <label>Account Number</label>
                            <input type="text" name="account_number" class="form-control" placeholder="e.g., 1029...">
                        </div>
                        <div class="form-group">
                            <label>Opening Balance ($)</label>
                            <input type="number" step="0.01" name="opening_balance" class="form-control" value="0.00">
                        </div>
                        <button type="submit" class="btn btn-curdun w-100 mt-2">
                            <i class="fas fa-save"></i> Save Account
                        </button>
                    </form>
                </div>
            </div>
            <div class="col-md-7">
                <div class="glass-card">
                    <h5 class="mb-4 font-weight-bold"><i class="fas fa-university"></i> Bank Accounts</h5>
                    <div class="table-responsive">
                        <table class="table table-custom">
                            <thead>
                                <tr>
                                    <th>Account</th>
                                    <th>Bank</th>
                                    <th>Account #</th>
                                    <th class="text-right">Balance</th>
                                    <th class="text-center">Actions</th>
                                </thead>
                            <tbody>
                                <?php if (empty($banks)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4">No bank accounts registered</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($banks as $b): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($b['account_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($b['bank_name'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($b['account_number'] ?? '-') ?></td>
                                        <td class="text-right font-weight-bold text-primary">$<?= number_format($b['current_balance'], 2) ?></td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteBank(<?= $b['id'] ?>, '<?= htmlspecialchars($b['account_name']) ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- EXPENSES TAB -->
        <?php if($active_tab == 'expenses'): ?>
        <div class="glass-card">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                <h5 class="mb-0 font-weight-bold"><i class="fas fa-receipt"></i> Company Expenses</h5>
                <button class="btn btn-curdun btn-sm" onclick="openExpenseModal()">
                    <i class="fas fa-plus"></i> Record Expense
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-custom">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Reference</th>
                            <th>Category</th>
                            <th>Vendor</th>
                            <th class="text-right">Amount</th>
                            <th>Notes</th>
                        </thead>
                    <tbody>
                        <?php if (empty($expenses)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4">No expenses recorded</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($expenses as $exp): ?>
                            <tr>
                                <td><?= date('M d, Y', strtotime($exp['expense_date'])) ?></td>
                                <td><span class="badge badge-secondary"><?= htmlspecialchars($exp['expense_number']) ?></span></td>
                                <td><span class="status-pill pill-expense"><?= htmlspecialchars($exp['expense_category']) ?></span></td>
                                <td><?= htmlspecialchars($exp['vendor_name'] ?? '-') ?></td>
                                <td class="text-right text-danger font-weight-bold">$<?= number_format($exp['amount'], 2) ?></td>
                                <td class="text-muted small"><?= htmlspecialchars(substr($exp['notes'] ?? '', 0, 50)) ?>...</td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- ACCOUNT MODAL -->
<div class="modal fade" id="accountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: 20px;">
            <div class="modal-header border-0">
                <h5 class="font-weight-bold" id="accountModalTitle">Account Information</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="accountForm">
                <input type="hidden" name="ajax_action" value="save_account">
                <input type="hidden" name="account_id" id="account_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Account Code <span class="text-danger">*</span></label>
                        <input type="text" name="account_code" id="account_code" class="form-control" required placeholder="e.g., 1000">
                    </div>
                    <div class="form-group">
                        <label>Account Name <span class="text-danger">*</span></label>
                        <input type="text" name="account_name" id="account_name" class="form-control" required placeholder="e.g., Cash on Hand">
                    </div>
                    <div class="form-group">
                        <label>Account Type</label>
                        <select name="account_type" id="account_type" class="form-control">
                            <option value="asset">Asset</option>
                            <option value="liability">Liability</option>
                            <option value="equity">Equity</option>
                            <option value="revenue">Revenue</option>
                            <option value="expense">Expense</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="submit" class="btn btn-curdun w-100">Save Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JOURNAL MODAL -->
<div class="modal fade" id="journalModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: 20px;">
            <div class="modal-header border-0">
                <h5 class="font-weight-bold">New Journal Entry</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="journalForm">
                <input type="hidden" id="je_journal_id" value="">
                <input type="hidden" id="je_original_number" value="">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Date</label>
                            <input type="date" id="je_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Reference #</label>
                            <input type="text" id="je_number" class="form-control" placeholder="JE-AUTO">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" id="je_desc" class="form-control" placeholder="Memo / Notes">
                    </div>
                    <hr>
                    <div id="je-rows"></div>
                    <button type="button" class="btn btn-outline-primary btn-sm mt-3" onclick="addJournalRow()">
                        <i class="fas fa-plus"></i> Add Row
                    </button>
                    <div class="mt-4 p-3 bg-light rounded d-flex justify-content-between">
                        <div>Total Debit: <span id="sum-dr" class="font-weight-bold text-success">$0.00</span></div>
                        <div>Total Credit: <span id="sum-cr" class="font-weight-bold text-danger">$0.00</span></div>
                        <div id="balance-warning" class="text-danger small" style="display:none;">
                            <i class="fas fa-exclamation-triangle"></i> Not balanced!
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="submit" id="saveJE" class="btn btn-curdun w-100" disabled>Save Journal Entry</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EXPENSE MODAL -->
<div class="modal fade" id="expenseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: 20px;">
            <div class="modal-header border-0">
                <h5 class="font-weight-bold">Record Expense</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="expenseForm">
                <input type="hidden" name="ajax_action" value="save_expense">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="expense_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Pay From (Bank/Cash)</label>
                        <select name="bank_account_id" class="form-control" required>
                            <option value="">-- Select Bank Account --</option>
                            <?php foreach($banks as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['account_name']) ?> (Balance: $<?= number_format($b['current_balance'], 2) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Expense Category</label>
                        <select name="category" class="form-control" required>
                            <option value="">-- Select Category --</option>
                            <option value="Operating Expenses">Operating Expenses</option>
                            <option value="Transport Expenses">Transport Expenses</option>
                            <option value="Warehouse Expenses">Warehouse Expenses</option>
                            <option value="Administrative Expenses">Administrative Expenses</option>
                            <option value="Other Expenses">Other Expenses</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Vendor / Payee</label>
                        <input type="text" name="vendor_name" class="form-control" placeholder="Person or company name">
                    </div>
                    <div class="form-group">
                        <label>Amount ($) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Memo / Notes</label>
                        <textarea name="notes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="submit" class="btn btn-curdun w-100">Record Expense</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
let coa = <?= json_encode($accounts) ?>;
let accountModalMode = 'normal';
let targetAccountSelect = null;

function showAlert(type, message) {
    const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
    const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
    const html = `<div class="alert alert-custom ${alertClass} alert-dismissible fade show">
        <i class="fas ${icon} mr-2"></i> ${message}
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>`;
    $('#alert-placeholder').html(html);
    setTimeout(() => $('.alert-custom').fadeOut(3000, function() { $(this).remove(); }), 3000);
}

function switchTab(tab) {
    window.location.href = "?tab=" + tab;
}

function buildAccountOptions(selectedId = '') {
    let opts = '<option value="">-- Select Account --</option>';
    coa.forEach(a => {
        const selected = String(a.id) === String(selectedId) ? 'selected' : '';
        opts += `<option value="${a.id}" ${selected}>${a.account_code} - ${a.account_name}</option>`;
    });
    return opts;
}

function refreshAllAccountSelects(selectedId = null) {
    $('.acc-sel').each(function() {
        const oldValue = selectedId ? selectedId : $(this).val();
        $(this).html(buildAccountOptions(oldValue));
    });
}

function upsertAccountInMemory(account) {
    if (!account || !account.id) return;
    const idx = coa.findIndex(a => String(a.id) === String(account.id));
    if (idx >= 0) {
        coa[idx] = account;
    } else {
        coa.push(account);
    }
    coa.sort((a, b) => String(a.account_code).localeCompare(String(b.account_code)));
}

// Account Management
function openAccountModal(mode = 'normal', selectElement = null) {
    accountModalMode = mode;
    targetAccountSelect = selectElement;
    $('#accountModalTitle').text(mode === 'quick' ? 'Add New Account' : 'Account Information');
    $('#account_id').val('');
    $('#account_code').val('');
    $('#account_name').val('');
    $('#account_type').val('asset');
    $('#accountModal').modal('show');
}

function openQuickAccountModal(btn) {
    const selectEl = $(btn).closest('.input-group').find('.acc-sel');
    $('#journalModal').modal('hide');
    setTimeout(() => openAccountModal('quick', selectEl), 250);
}

function editAccount(acc) {
    accountModalMode = 'normal';
    targetAccountSelect = null;
    $('#accountModalTitle').text('Edit Account');
    $('#account_id').val(acc.id);
    $('#account_code').val(acc.account_code);
    $('#account_name').val(acc.account_name);
    $('#account_type').val(acc.account_type);
    $('#accountModal').modal('show');
}

function deleteAccount(id, name) {
    if (confirm(`Are you sure you want to delete account "${name}"?`)) {
        $.post(window.location.href, {
            ajax_action: 'delete_account',
            account_id: id
        }, function(res) {
            if (res.success) {
                showAlert('success', res.message);
                location.reload();
            } else {
                showAlert('error', res.message);
            }
        }, 'json');
    }
}

// Journal Management
function openJournalModal() {
    $('#journalModal .modal-header h5').text('New Journal Entry');
    $('#je-rows').empty();
    addJournalRow();
    addJournalRow();
    $('#je_date').val(new Date().toISOString().slice(0,10));
    $('#je_number').val('');
    $('#je_desc').val('');
    $('#je_journal_id').val('');
    $('#je_original_number').val('');
    $('#journalModal').modal('show');
    calcJE();
}

function addJournalRow(line = null) {
    const selectedId = line ? (coa.find(a => String(a.account_code) === String(line.account_code))?.id || '') : '';
    let row = `<div class="row je-row mb-2 align-items-center">
        <div class="col-md-5">
            <div class="input-group input-group-sm">
                <select class="form-control acc-sel" onchange="calcJE()">${buildAccountOptions(selectedId)}</select>
                <div class="input-group-append">
                    <button type="button" class="btn btn-outline-success" title="Add new account" onclick="openQuickAccountModal(this)">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <input type="number" step="0.01" class="form-control form-control-sm dr-in" value="${line ? parseFloat(line.debit || 0) : 0}" oninput="calcJE()" placeholder="Debit">
        </div>
        <div class="col-md-3">
            <input type="number" step="0.01" class="form-control form-control-sm cr-in" value="${line ? parseFloat(line.credit || 0) : 0}" oninput="calcJE()" placeholder="Credit">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-sm text-danger" onclick="$(this).closest('.row').remove(); calcJE();">&times;</button>
        </div>
    </div>`;
    $('#je-rows').append(row);
    calcJE();
}

function calcJE() {
    let dr = 0, cr = 0;
    $('.dr-in').each(function() { dr += parseFloat($(this).val()) || 0; });
    $('.cr-in').each(function() { cr += parseFloat($(this).val()) || 0; });
    $('#sum-dr').text('$' + dr.toFixed(2));
    $('#sum-cr').text('$' + cr.toFixed(2));

    if (Math.abs(dr - cr) < 0.01 && dr > 0) {
        $('#balance-warning').hide();
        $('#saveJE').prop('disabled', false);
    } else {
        $('#balance-warning').show();
        $('#saveJE').prop('disabled', true);
    }
}

function editJournalEntry(entryNumber) {
    $.post(window.location.href, {
        ajax_action: 'get_journal_entry',
        entry_number: entryNumber
    }, function(res) {
        if (!res.success) {
            showAlert('error', res.message || 'Unable to load journal entry');
            return;
        }

        const entry = res.entry;
        $('#journalModal .modal-header h5').text('Edit Journal Entry');
        $('#je_journal_id').val(entry.id);
        $('#je_original_number').val(entry.entry_number);
        $('#je_date').val(entry.entry_date);
        $('#je_number').val(entry.entry_number);
        $('#je_desc').val(entry.description || '');
        $('#je-rows').empty();

        entry.lines.forEach(line => addJournalRow(line));
        $('#journalModal').modal('show');
        calcJE();
    }, 'json');
}

function deleteJournalEntry(entryNumber) {
    if (!confirm(`Delete journal entry ${entryNumber}? This will also reverse account balances.`)) return;

    $.post(window.location.href, {
        ajax_action: 'delete_journal_entry',
        entry_number: entryNumber
    }, function(res) {
        if (res.success) {
            showAlert('success', res.message);
            location.reload();
        } else {
            showAlert('error', res.message);
        }
    }, 'json');
}

// Bank Management
function deleteBank(id, name) {
    if (confirm(`Are you sure you want to delete bank account "${name}"?`)) {
        $.post(window.location.href, {
            ajax_action: 'delete_bank',
            bank_id: id
        }, function(res) {
            if (res.success) {
                showAlert('success', res.message);
                location.reload();
            } else {
                showAlert('error', res.message);
            }
        }, 'json');
    }
}

function openExpenseModal() {
    $('#expenseModal').modal('show');
}

// Form Submissions
$(document).ready(function() {
    $('#bankForm').submit(function(e) {
        e.preventDefault();
        $.post(window.location.href, $(this).serialize(), function(res) {
            if (res.success) {
                showAlert('success', res.message);
                location.reload();
            } else {
                showAlert('error', res.message);
            }
        }, 'json');
    });

    $('#accountForm').submit(function(e) {
        e.preventDefault();
        $.post(window.location.href, $(this).serialize(), function(res) {
            if (res.success) {
                showAlert('success', res.message);
                if (res.account) {
                    upsertAccountInMemory(res.account);
                    refreshAllAccountSelects(res.account.id);
                }

                $('#accountModal').modal('hide');

                if (accountModalMode === 'quick') {
                    if (targetAccountSelect && res.account) {
                        targetAccountSelect.val(res.account.id);
                    }
                    setTimeout(() => $('#journalModal').modal('show'), 250);
                } else {
                    location.reload();
                }
            } else {
                showAlert('error', res.message);
            }
        }, 'json');
    });

    $('#journalForm').submit(function(e) {
        e.preventDefault();
        let lines = [];
        $('.je-row').each(function() {
            let accId = $(this).find('.acc-sel').val();
            let acc = coa.find(a => String(a.id) === String(accId));
            if (acc) {
                lines.push({
                    account_code: acc.account_code,
                    account_name: acc.account_name,
                    debit: parseFloat($(this).find('.dr-in').val()) || 0,
                    credit: parseFloat($(this).find('.cr-in').val()) || 0
                });
            }
        });

        $.post(window.location.href, {
            ajax_action: 'save_journal_entry',
            journal_id: $('#je_journal_id').val(),
            original_entry_number: $('#je_original_number').val(),
            entry_date: $('#je_date').val(),
            entry_number: $('#je_number').val(),
            description: $('#je_desc').val(),
            lines: JSON.stringify(lines)
        }, function(res) {
            if (res.success) {
                showAlert('success', res.message);
                location.reload();
            } else {
                showAlert('error', res.message);
            }
        }, 'json');
    });

    $('#expenseForm').submit(function(e) {
        e.preventDefault();
        $.post(window.location.href, $(this).serialize(), function(res) {
            if (res.success) {
                showAlert('success', res.message);
                location.reload();
            } else {
                showAlert('error', res.message);
            }
        }, 'json');
    });
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>