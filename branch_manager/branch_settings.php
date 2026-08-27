<?php
// branch_manager/branch_settings.php
// Lets the branch manager view/edit their OWN branch row only.
// Editable: branch_name, address, phone, email, manager_name, manager_phone,
//           opening_time, closing_time, max_capacity_cbm.
// Read-only (administrative, not exposed for edit): branch_code, branch_type,
//           tenant_id, status, current_used_cbm, id.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db_connect.php';

// CSRF: every POST to this handler must carry a valid session token.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
}


if (!isset($pdo) || !$pdo instanceof PDO) {
    die('Database connection failed: $pdo not found. Check config/db_connect.php');
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role_type'] ?? $_SESSION['role'] ?? '') !== 'branch_manager') {
    header("Location: ../login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
$user_name = $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'Branch Manager';

if ($tenant_id <= 0) {
    header("Location: ../login.php?error=no_tenant");
    exit;
}

$assigned_branch_id = $_SESSION['assigned_branch_id'] ?? null;
$can_manage_branch = $_SESSION['can_manage_branch'] ?? 0;

if (!$assigned_branch_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT branch_id, is_primary, can_manage_branch
            FROM user_branch_assignments
            WHERE user_id = ? AND is_primary = 1
            LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $branchAssign = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($branchAssign) {
            $assigned_branch_id = $branchAssign['branch_id'];
            $can_manage_branch = $branchAssign['can_manage_branch'];
            $_SESSION['assigned_branch_id'] = $assigned_branch_id;
            $_SESSION['can_manage_branch'] = $can_manage_branch;
        }
    } catch (PDOException $e) {}
}

if (!$assigned_branch_id) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container mt-4"><div class="alert alert-danger">You are not assigned to any branch. Please contact administrator.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}
$assigned_branch_id = (int)$assigned_branch_id;

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Fields the branch manager is allowed to edit
$editable_fields = ['branch_name', 'address', 'phone', 'email', 'manager_name', 'manager_phone', 'opening_time', 'closing_time', 'max_capacity_cbm'];

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_branch_settings'])) {
    // Re-verify this branch belongs to this tenant and this user manages it before writing
    $check = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1");
    $check->execute([$assigned_branch_id, $tenant_id]);

    if (!$check->fetch(PDO::FETCH_ASSOC)) {
        $message = 'Branch not found for your tenant.';
        $message_type = 'danger';
    } else {
        $branch_name = trim($_POST['branch_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $manager_name = trim($_POST['manager_name'] ?? '');
        $manager_phone = trim($_POST['manager_phone'] ?? '');
        $opening_time = trim($_POST['opening_time'] ?? '');
        $closing_time = trim($_POST['closing_time'] ?? '');
        $max_capacity_cbm = (float)str_replace(',', '.', trim($_POST['max_capacity_cbm'] ?? '0'));

        if ($branch_name === '') {
            $message = 'Branch name is required.';
            $message_type = 'danger';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE branches
                    SET branch_name = ?, address = ?, phone = ?, email = ?, manager_name = ?, manager_phone = ?,
                        opening_time = ?, closing_time = ?, max_capacity_cbm = ?, updated_at = NOW()
                    WHERE id = ? AND tenant_id = ?
                ");
                $stmt->execute([
                    $branch_name, $address ?: null, $phone ?: null, $email ?: null, $manager_name ?: null, $manager_phone ?: null,
                    $opening_time ?: null, $closing_time ?: null, $max_capacity_cbm,
                    $assigned_branch_id, $tenant_id
                ]);
                $message = 'Branch settings updated successfully.';
                $message_type = 'success';
            } catch (Throwable $e) {
                $message = 'Error updating branch: ' . $e->getMessage();
                $message_type = 'danger';
            }
        }
    }
}

$stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1");
$stmt->execute([$assigned_branch_id, $tenant_id]);
$branch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$branch) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container mt-4"><div class="alert alert-danger">Assigned branch was not found for this tenant.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Settings - <?= h($branch['branch_name']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { background:#f4f6f9; }
        .page-wrap { padding: 20px; }
        .hero { background: linear-gradient(135deg,#2D1859,#4B2C85); color:#fff; border-radius:18px; padding:22px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
        .hero h3 { margin:0; font-weight:700; }
        .hero small { opacity:.9; }
        .panel { background:#fff; border-radius:16px; padding:24px; box-shadow:0 6px 18px rgba(0,0,0,.06); border:1px solid #eee; }
        .btn-main { background:#2D1859; color:#fff; border:0; }
        .btn-main:hover { background:#1F0F3D; color:#fff; }
        .readonly-field { background:#f4f4f4; }
        .section-title { font-weight:700; color:#2D1859; margin: 20px 0 12px; padding-bottom:6px; border-bottom: 2px solid #2D1859; }
        .badge-status { padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
    </style>
</head>
<body>
<div class="page-wrap">
    <div class="hero">
        <div>
            <h3><i class="fas fa-gear"></i> Branch Settings</h3>
            <small>Manage your own branch's information</small>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= h($message_type) ?> alert-dismissible fade show">
            <?= h($message) ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    <?php endif; ?>

    <div class="panel">
        <div class="section-title"><i class="fas fa-lock"></i> Administrative Info (Read-Only)</div>
        <div class="row">
            <div class="col-md-3 form-group">
                <label>Branch Code</label>
                <input type="text" class="form-control readonly-field" value="<?= h($branch['branch_code']) ?>" readonly>
            </div>
            <div class="col-md-3 form-group">
                <label>Branch Type</label>
                <input type="text" class="form-control readonly-field text-capitalize" value="<?= h($branch['branch_type']) ?>" readonly>
            </div>
            <div class="col-md-3 form-group">
                <label>Status</label>
                <div><span class="badge-status badge-<?= $branch['status'] === 'active' ? 'success' : 'danger' ?>" style="background:<?= $branch['status'] === 'active' ? '#EEFBF3' : '#FEF0EE' ?>;color:<?= $branch['status'] === 'active' ? '#0F7A3A' : '#B42318' ?>"><?= h(ucfirst(str_replace('_', ' ', $branch['status']))) ?></span></div>
            </div>
            <div class="col-md-3 form-group">
                <label>Current Used CBM</label>
                <input type="text" class="form-control readonly-field" value="<?= h(number_format((float)$branch['current_used_cbm'], 2)) ?>" readonly>
            </div>
        </div>
        <small class="text-muted">These fields are managed by your tenant administrator and cannot be changed here.</small>

        <form method="post">
            <div class="section-title"><i class="fas fa-edit"></i> Branch Information</div>
            <div class="row">
                <div class="col-md-6 form-group">
                    <label>Branch Name <span class="text-danger">*</span></label>
                    <input type="text" name="branch_name" class="form-control" value="<?= h($branch['branch_name']) ?>" required>
                </div>
                <div class="col-md-6 form-group">
                    <label>Max Capacity (CBM)</label>
                    <input type="number" step="0.01" min="0" name="max_capacity_cbm" class="form-control" value="<?= h($branch['max_capacity_cbm']) ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Address</label>
                <textarea name="address" class="form-control" rows="2"><?= h($branch['address']) ?></textarea>
            </div>
            <div class="row">
                <div class="col-md-6 form-group">
                    <label>Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?= h($branch['phone']) ?>">
                </div>
                <div class="col-md-6 form-group">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control" value="<?= h($branch['email']) ?>">
                </div>
            </div>

            <div class="section-title"><i class="fas fa-user-tie"></i> Manager Contact</div>
            <div class="row">
                <div class="col-md-6 form-group">
                    <label>Manager Name</label>
                    <input type="text" name="manager_name" class="form-control" value="<?= h($branch['manager_name']) ?>">
                </div>
                <div class="col-md-6 form-group">
                    <label>Manager Phone</label>
                    <input type="text" name="manager_phone" class="form-control" value="<?= h($branch['manager_phone']) ?>">
                </div>
            </div>

            <div class="section-title"><i class="fas fa-clock"></i> Operating Hours</div>
            <div class="row">
                <div class="col-md-6 form-group">
                    <label>Opening Time</label>
                    <input type="time" name="opening_time" class="form-control" value="<?= h($branch['opening_time']) ?>">
                </div>
                <div class="col-md-6 form-group">
                    <label>Closing Time</label>
                    <input type="time" name="closing_time" class="form-control" value="<?= h($branch['closing_time']) ?>">
                </div>
            </div>

            <button type="submit" name="save_branch_settings" value="1" class="btn btn-main mt-3"><i class="fas fa-save"></i> Save Changes</button>
        </form>
    </div>
</div>
</body>
</html>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
