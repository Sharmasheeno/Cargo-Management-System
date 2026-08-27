<?php
// superadmin/accounting.php
// Accounting & Banking Management forfaras cargo - Super Admin

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

$db = $pdo;
$tenant_id = $_SESSION['tenant_id'] ?? null;
$active_tab = $_GET['tab'] ?? 'accounts';
$user_id = $_SESSION['user_id'];

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
    
    // Insert default chart of accounts if empty
    $check = $db->query("SELECT COUNT(*) as count FROM chart_of_accounts WHERE tenant_id IS NULL OR tenant_id = 0")->fetch(PDO::FETCH_ASSOC);
    if ($check['count'] == 0) {
        $default_accounts = [
            ['1000', 'Lacagta Gacanta', 'asset'],
            ['1010', 'Bangiyada', 'asset'],
            ['1020', 'Deeumaha Macaamiisha', 'asset'],
            ['2000', 'Deeumaha Alaab-qeybiyeyaasha', 'liability'],
            ['3000', 'Raasumaalka Milkiilaha', 'equity'],
            ['4000', 'Dakhliga Adeegga', 'revenue'],
            ['5000', 'Kharashyada Hawlaha', 'expense'],
            ['5010', 'Kharashyada Gaadiidka', 'expense'],
            ['5020', 'Kharashyada Bakhaarka', 'expense'],
            ['5030', 'Kharashyada Maamulka', 'expense']
        ];
        $stmt = $db->prepare("INSERT INTO chart_of_accounts (tenant_id, account_code, account_name, account_type) VALUES (?, ?, ?, ?)");
        foreach ($default_accounts as $acc) {
            $stmt->execute([null, $acc[0], $acc[1], $acc[2]]);
        }
    }
} catch (PDOException $e) {
    // Table might already exist
}

// ==========================================
// HANDLE AJAX ACTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['success' => false, 'message' => 'Waxqabad khaldan'];

    // 1. CHART OF ACCOUNTS ACTIONS
    if ($action === 'save_account') {
        $id = $_POST['account_id'] ?? null;
        $code = trim($_POST['account_code']);
        $name = trim($_POST['account_name']);
        $type = $_POST['account_type'];
        
        if (empty($code) || empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Koodhka iyo Magaca waa lagama maarmaan']);
            exit();
        }
        
        try {
            if ($id && $id > 0) {
                $stmt = $db->prepare("UPDATE chart_of_accounts SET account_code = ?, account_name = ?, account_type = ? WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)");
                $success = $stmt->execute([$code, $name, $type, $id, $tenant_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO chart_of_accounts (tenant_id, account_code, account_name, account_type) VALUES (?, ?, ?, ?)");
                $success = $stmt->execute([$tenant_id, $code, $name, $type]);
            }
            echo json_encode(['success' => $success, 'message' => $success ? 'Xisaabta waa la keydiyay' : 'Wuu ku guuldareystay keydinta xisaabta']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit();
    }
    
    // 2. Delete account
    if ($action === 'delete_account') {
        $id = $_POST['account_id'] ?? 0;
        try {
            $stmt = $db->prepare("DELETE FROM chart_of_accounts WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)");
            $success = $stmt->execute([$id, $tenant_id]);
            echo json_encode(['success' => $success, 'message' => $success ? 'Xisaabta waa la tirtiray' : 'Waa lagu guuldareystay']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit();
    }

    // 3. JOURNAL ACTIONS
    if ($action === 'save_journal_entry') {
        $date = $_POST['entry_date'];
        $number = !empty($_POST['entry_number']) ? $_POST['entry_number'] : 'JE-' . date('Ymd') . '-' . time();
        $description = $_POST['description'];
        $lines = json_decode($_POST['lines'], true);
        
        if (empty($lines)) {
            echo json_encode(['success' => false, 'message' => 'Fadlan ku dar ugu yaraan hal saf']);
            exit();
        }
        
        $db->beginTransaction();
        try {
            foreach ($lines as $line) {
                $stmt = $db->prepare("INSERT INTO journal_entries (tenant_id, entry_number, entry_date, account_name, account_code, debit, credit, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$tenant_id, $number, $date, $line['account_name'], $line['account_code'], $line['debit'], $line['credit'], $description, $user_id]);
                
                // Update balance in chart_of_accounts
                $balance_change = $line['debit'] - $line['credit'];
                $stmtAcc = $db->prepare("UPDATE chart_of_accounts SET balance = balance + ? WHERE account_code = ? AND (tenant_id = ? OR tenant_id IS NULL)");
                $stmtAcc->execute([$balance_change, $line['account_code'], $tenant_id]);
            }
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Joornaalka waa la soo saaray si guul leh']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    // 4. EXPENSE ACTIONS
    if ($action === 'save_expense') {
        $number = 'EXP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
        $category = $_POST['category'];
        $amount = floatval($_POST['amount']);
        $date = $_POST['expense_date'];
        $vendor = $_POST['vendor_name'] ?? '';
        $notes = $_POST['notes'] ?? '';
        $bank_acc_id = $_POST['bank_account_id'] ?? null;
        
        if ($amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Lacagtu waa inay ka weyn tahay 0']);
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
                $stmtBank->execute([$tenant_id, $bank_acc_id, $date, $amount, "Kharash: $vendor - $notes", $expense_id, $user_id]);
                
                // Update bank balance
                $db->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$amount, $bank_acc_id]);
            }
            
            // 3. Accounting (Journal Entry)
            $stmt = $db->prepare("INSERT INTO journal_entries (tenant_id, entry_number, entry_date, account_name, account_code, debit, credit, description, created_by, created_at) VALUES (?, ?, ?, ?, '5000', ?, 0, ?, ?, NOW())");
            $stmt->execute([$tenant_id, $number, $date, $category, $amount, "Kharash: $vendor - $notes", $user_id]);
            
            // Update expense account balance
            $db->prepare("UPDATE chart_of_accounts SET balance = balance + ? WHERE account_code = '5000' AND (tenant_id = ? OR tenant_id IS NULL)")->execute([$amount, $tenant_id]);
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Kharashka waa la diiwaangeliyay']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }
    
    // 5. Get journal entries
    if ($action === 'get_journal_entries') {
        $entries = db_get_all("SELECT * FROM journal_entries WHERE (tenant_id = ? OR tenant_id IS NULL) ORDER BY entry_date DESC, id DESC LIMIT 100", [$tenant_id]);
        echo json_encode($entries);
        exit();
    }

    // 6. BANKING ACTIONS
    if ($action === 'save_bank') {
        $name = trim($_POST['account_name']);
        $bank = trim($_POST['bank_name']);
        $number = trim($_POST['account_number']);
        $balance = floatval($_POST['opening_balance']) ?: 0;
        
        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Magaca akoonka waa lagama maarmaan']);
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
            
            echo json_encode(['success' => $success, 'message' => $success ? 'Akoonka bangiga waa lagu daray' : 'Wuu ku guuldareystay']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }
    
    // 7. Delete bank account
    if ($action === 'delete_bank') {
        $id = $_POST['bank_id'] ?? 0;
        try {
            $stmt = $db->prepare("DELETE FROM bank_accounts WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)");
            $success = $stmt->execute([$id, $tenant_id]);
            echo json_encode(['success' => $success, 'message' => $success ? 'Bangiga waa la tirtiray' : 'Waa lagu guuldareystay']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }
    
    exit();
}

// Get data for display using existing db_get_all function
$accounts = db_get_all("SELECT * FROM chart_of_accounts WHERE (tenant_id = ? OR tenant_id IS NULL) ORDER BY account_code ASC", [$tenant_id]);
$banks = db_get_all("SELECT * FROM bank_accounts WHERE (tenant_id = ? OR tenant_id IS NULL) ORDER BY id DESC", [$tenant_id]);
$expenses = db_get_all("SELECT * FROM expenses WHERE (tenant_id = ? OR tenant_id IS NULL) ORDER BY expense_date DESC LIMIT 100", [$tenant_id]);
$journal_entries = db_get_all("SELECT * FROM journal_entries WHERE (tenant_id = ? OR tenant_id IS NULL) ORDER BY entry_date DESC, id DESC LIMIT 100", [$tenant_id]);

include_once '../includes/header.php';
?>

<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Xisaabaadka & Bangiyada - Super Admin | Cargo Management System</title>
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
        .pill-revenue { background: #EEFBF3; color: #0F7A3A; }
        .pill-expense { background: #FEF0EE; color: #B42318; }
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
                <i class="fas fa-chart-line"></i> Xisaabaadka & Bangiyada
            </h2>
            <p class="text-muted small">Maareynta maaliyadda iyo xisaabaadka Cargo Management System.</p>
        </div>
    </div>

    <div class="acc-tabs">
        <button class="acc-tab-btn <?= $active_tab == 'accounts' ? 'active' : '' ?>" onclick="switchTab('accounts')">
            <i class="fas fa-sitemap mr-2"></i> Xisaabaadka
        </button>
        <button class="acc-tab-btn <?= $active_tab == 'journals' ? 'active' : '' ?>" onclick="switchTab('journals')">
            <i class="fas fa-book mr-2"></i> Joornaalka
        </button>
        <button class="acc-tab-btn <?= $active_tab == 'banking' ? 'active' : '' ?>" onclick="switchTab('banking')">
            <i class="fas fa-university mr-2"></i> Bangiyada
        </button>
        <button class="acc-tab-btn <?= $active_tab == 'expenses' ? 'active' : '' ?>" onclick="switchTab('expenses')">
            <i class="fas fa-receipt mr-2"></i> Kharashyada
        </button>
    </div>

    <div id="tab-content-area">
        
        <!-- ACCOUNTS TAB -->
        <?php if($active_tab == 'accounts'): ?>
        <div class="glass-card">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                <h5 class="mb-0 font-weight-bold"><i class="fas fa-list"></i> Shaxda Xisaabaadka (COA)</h5>
                <button class="btn btn-curdun btn-sm" onclick="openAccountModal()">
                    <i class="fas fa-plus"></i> Xisaab Cusub
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-custom">
                    <thead>
                        <tr>
                            <th>Koodhka</th>
                            <th>Magaca Xisaabta</th>
                            <th>Nooca</th>
                            <th class="text-right">Haraaga</th>
                            <th class="text-center">Waxqabad</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($accounts)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4">Ma jiraan xisaabo</td>
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
                                            'asset' => 'HANTI',
                                            'liability' => 'DEYN',
                                            'equity' => 'SAAMI',
                                            'revenue' => 'DAKHLI',
                                            'expense' => 'KHARASH'
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
                <h5 class="mb-0 font-weight-bold"><i class="fas fa-book-open"></i> Diiwaanka Joornaalka</h5>
                <button class="btn btn-curdun btn-sm" onclick="openJournalModal()">
                    <i class="fas fa-plus"></i> Joornaal Cusub
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-custom">
                    <thead>
                        <tr>
                            <th>Taariikhda</th>
                            <th>Lr Joornaal</th>
                            <th>Xisaabta</th>
                            <th class="text-right">Debid (Dr)</th>
                            <th class="text-right">Karidit (Cr)</th>
                            <th>Faahfaahin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($journal_entries)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4">Ma jiraan wax qoraal ah</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($journal_entries as $entry): ?>
                            <tr>
                                <td><?= date('M d, Y', strtotime($entry['entry_date'])) ?></td>
                                <td><span class="badge badge-secondary">#<?= htmlspecialchars($entry['entry_number']) ?></span></td>
                                <td><?= htmlspecialchars($entry['account_name']) ?></td>
                                <td class="text-right <?= $entry['debit'] > 0 ? 'text-success font-weight-bold' : '' ?>">
                                    <?= $entry['debit'] > 0 ? '$'.number_format($entry['debit'], 2) : '-' ?>
                                </td>
                                <td class="text-right <?= $entry['credit'] > 0 ? 'text-danger font-weight-bold' : '' ?>">
                                    <?= $entry['credit'] > 0 ? '$'.number_format($entry['credit'], 2) : '-' ?>
                                </td>
                                <td class="text-muted small"><?= htmlspecialchars($entry['description'] ?? '-') ?></td>
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
                    <h5 class="mb-3 font-weight-bold"><i class="fas fa-plus-circle"></i> Ku dar Bangi</h5>
                    <form id="bankForm">
                        <input type="hidden" name="ajax_action" value="save_bank">
                        <div class="form-group">
                            <label>Naaneysta Akoonka <span class="text-danger">*</span></label>
                            <input type="text" name="account_name" class="form-control" required placeholder="t.h. Hawlgallada Guud">
                        </div>
                        <div class="form-group">
                            <label>Magaca Bangiga</label>
                            <input type="text" name="bank_name" class="form-control" placeholder="t.h. Premier Bank">
                        </div>
                        <div class="form-group">
                            <label>Lambarka Akoonka</label>
                            <input type="text" name="account_number" class="form-control" placeholder="1029...">
                        </div>
                        <div class="form-group">
                            <label>Haraaga Furitaanka ($)</label>
                            <input type="number" step="0.01" name="opening_balance" class="form-control" value="0.00">
                        </div>
                        <button type="submit" class="btn btn-curdun w-100 mt-2">
                            <i class="fas fa-save"></i> Keydi Akoonka
                        </button>
                    </form>
                </div>
            </div>
            <div class="col-md-7">
                <div class="glass-card">
                    <h5 class="mb-4 font-weight-bold"><i class="fas fa-university"></i> Bangiyada & Lacagaha</h5>
                    <div class="table-responsive">
                        <table class="table table-custom">
                            <thead>
                                <tr>
                                    <th>Akoonka</th>
                                    <th>Bangiga</th>
                                    <th>Lr Akoonka</th>
                                    <th class="text-right">Haraaga</th>
                                    <th class="text-center">Waxqabad</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($banks)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4">Ma jiraan bangiyo la diiwaangeliyay</td>
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
                <h5 class="mb-0 font-weight-bold"><i class="fas fa-receipt"></i> Kharashyada Shirkadda</h5>
                <button class="btn btn-curdun btn-sm" onclick="openExpenseModal()">
                    <i class="fas fa-plus"></i> Diiwaangeli Kharash
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-custom">
                    <thead>
                        <tr>
                            <th>Taariikhda</th>
                            <th>Lambarka</th>
                            <th>Nooca</th>
                            <th>Cidda la siiyay</th>
                            <th class="text-right">Lacagta</th>
                            <th>Ogeysiis</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($expenses)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4">Ma jiraan kharashyo la diiwaangeliyay</td>
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
                <h5 class="font-weight-bold" id="accountModalTitle">Macluumaadka Xisaabta</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="accountForm">
                <input type="hidden" name="ajax_action" value="save_account">
                <input type="hidden" name="account_id" id="account_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Koodhka Xisaabta <span class="text-danger">*</span></label>
                        <input type="text" name="account_code" id="account_code" class="form-control" required placeholder="t.h. 1000">
                    </div>
                    <div class="form-group">
                        <label>Magaca Xisaabta <span class="text-danger">*</span></label>
                        <input type="text" name="account_name" id="account_name" class="form-control" required placeholder="t.h. Lacagta Gacanta">
                    </div>
                    <div class="form-group">
                        <label>Nooca Xisaabta</label>
                        <select name="account_type" id="account_type" class="form-control">
                            <option value="asset">Hanti (Asset)</option>
                            <option value="liability">Deyn (Liability)</option>
                            <option value="equity">Saami (Equity)</option>
                            <option value="revenue">Dakhli (Revenue)</option>
                            <option value="expense">Kharash (Expense)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="submit" class="btn btn-curdun w-100">Keydi Xisaabta</button>
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
                <h5 class="font-weight-bold">Joornaal Cusub</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="journalForm">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Taariikhda</label>
                            <input type="date" id="je_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Lr Tixraaca</label>
                            <input type="text" id="je_number" class="form-control" placeholder="JI-AUTO">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Faahfaahin</label>
                        <input type="text" id="je_desc" class="form-control" placeholder="Memo/Ogeysiis">
                    </div>
                    <hr>
                    <div id="je-rows"></div>
                    <button type="button" class="btn btn-outline-primary btn-sm mt-3" onclick="addJournalRow()">
                        <i class="fas fa-plus"></i> Ku dar Saf
                    </button>
                    <div class="mt-4 p-3 bg-light rounded d-flex justify-content-between">
                        <div>Wadarta Dr: <span id="sum-dr" class="font-weight-bold text-success">$0.00</span></div>
                        <div>Wadarta Cr: <span id="sum-cr" class="font-weight-bold text-danger">$0.00</span></div>
                        <div id="balance-warning" class="text-danger small" style="display:none;">
                            <i class="fas fa-exclamation-triangle"></i> Ma is-le'eka!
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="submit" id="saveJE" class="btn btn-curdun w-100" disabled>Soo saar Joornaalka</button>
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
                <h5 class="font-weight-bold">Diiwaangeli Kharash</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="expenseForm">
                <input type="hidden" name="ajax_action" value="save_expense">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Taariikhda</label>
                        <input type="date" name="expense_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Ka bixi (Bangi/Kaash)</label>
                        <select name="bank_account_id" class="form-control" required>
                            <option value="">-- Dooro Bangi --</option>
                            <?php foreach($banks as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['account_name']) ?> (Bal: $<?= number_format($b['current_balance'], 2) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nooca Kharashka</label>
                        <select name="category" class="form-control" required>
                            <option value="">-- Dooro Nooca --</option>
                            <option value="Kharashyada Hawlaha">Kharashyada Hawlaha</option>
                            <option value="Kharashyada Gaadiidka">Kharashyada Gaadiidka</option>
                            <option value="Kharashyada Bakhaarka">Kharashyada Bakhaarka</option>
                            <option value="Kharashyada Maamulka">Kharashyada Maamulka</option>
                            <option value="Kharashyo Kale">Kharashyo Kale</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Cidda la siiyay</label>
                        <input type="text" name="vendor_name" class="form-control" placeholder="Magaca qofka/shirkadda">
                    </div>
                    <div class="form-group">
                        <label>Lacagta ($) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Memo/Qoraal</label>
                        <textarea name="notes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="submit" class="btn btn-curdun w-100">Keydi Kharashka</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
let coa = <?= json_encode($accounts) ?>;

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

// Account Management
function openAccountModal() {
    $('#account_id').val('');
    $('#account_code').val('');
    $('#account_name').val('');
    $('#account_type').val('asset');
    $('#accountModal').modal('show');
}

function editAccount(acc) {
    $('#account_id').val(acc.id);
    $('#account_code').val(acc.account_code);
    $('#account_name').val(acc.account_name);
    $('#account_type').val(acc.account_type);
    $('#accountModal').modal('show');
}

function deleteAccount(id, name) {
    if (confirm(`Ma hubtaa inaad tirtirto xisaabta "${name}"?`)) {
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
    $('#je-rows').empty();
    addJournalRow();
    addJournalRow();
    $('#je_date').val(new Date().toISOString().slice(0,10));
    $('#je_number').val('');
    $('#je_desc').val('');
    $('#journalModal').modal('show');
}

function addJournalRow() {
    let opts = '<option value="">-- Dooro Xisaab --</option>';
    opts += coa.map(a => `<option value="${a.id}">${a.account_code} - ${a.account_name}</option>`).join('');
    let row = `<div class="row je-row mb-2">
        <div class="col-md-5">
            <select class="form-control form-control-sm acc-sel">${opts}</select>
        </div>
        <div class="col-md-3">
            <input type="number" step="0.01" class="form-control form-control-sm dr-in" value="0" oninput="calcJE()" placeholder="Debid">
        </div>
        <div class="col-md-3">
            <input type="number" step="0.01" class="form-control form-control-sm cr-in" value="0" oninput="calcJE()" placeholder="Karidit">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-sm text-danger" onclick="$(this).closest('.row').remove(); calcJE();">&times;</button>
        </div>
    </div>`;
    $('#je-rows').append(row);
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

// Bank Management
function deleteBank(id, name) {
    if (confirm(`Ma hubtaa inaad tirtirto bangiga "${name}"?`)) {
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
                location.reload();
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
            let acc = coa.find(a => a.id == accId);
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

<?php include_once '../includes/footer.php'; ?>
</body>
</html>