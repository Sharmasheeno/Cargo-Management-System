<?php
// staff/dashboard.php
if (session_status() === PHP_SESSION_NONE) session_start();
// NOTE: login.php stores the sub-role (role_type) into $_SESSION['role'] as an alias
// ("For backward compatibility"), so a plain === 'staff' check only matches the generic
// staff account and incorrectly locks out every staff sub-role (warehouse_supervisor,
// logistics_supervisor, finance_manager, clerk). Check against the known staff role_types
// instead, using role_type first and falling back to role for older sessions.
$staff_role_types = ['staff', 'warehouse_supervisor', 'logistics_supervisor', 'finance_manager', 'clerk'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_type'] ?? $_SESSION['role'] ?? '', $staff_role_types, true)) {
    header("Location: ../login.php");
    exit;
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <?php if (($_GET['error'] ?? '') === 'access_denied'): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-ban"></i> You do not have permission to access that page.
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    <?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-users-cog text-info"></i> Staff Dashboard</h2>
    </div>
    <div class="row">
        <div class="col-md-12">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center p-5">
                    <i class="fas fa-clipboard-check fa-4x text-muted mb-3"></i>
                    <h4>Welcome, <?= htmlspecialchars($_SESSION['user_name']) ?>!</h4>
                    <p class="text-muted">You are logged in as Staff. Here you can process receptions and manage warehouse stock.</p>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
