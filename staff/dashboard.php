<?php
// staff/dashboard.php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header("Location: ../login.php");
    exit;
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
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
