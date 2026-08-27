<?php
// includes/header.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// Require functions.php
require_once(__DIR__ . '/functions.php');

// Redirect if not logged in
if (empty($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// User Info
$user_id = $_SESSION['user_id'];
$role = strtolower($_SESSION['role'] ?? 'guest');
$role_type = $_SESSION['role_type'] ?? '';

// Convert company_admin to tenant_admin for backward compatibility
if ($role === 'company_admin') {
    $role = 'tenant_admin';
    $_SESSION['role'] = 'tenant_admin';
}

// Get user details from database
$user_full_name = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'User';
$user_email = $_SESSION['email'] ?? 'N/A';
$user_phone = $_SESSION['phone'] ?? 'N/A';
$user_profile_image = $_SESSION['profile_image'] ?? null;

// Fetch fresh user data from database
try {
    $stmt = $pdo->prepare("
        SELECT u.*, t.name as tenant_name 
        FROM users u 
        LEFT JOIN tenants t ON u.tenant_id = t.id 
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userData) {
        $user_full_name = !empty($userData['full_name']) ? $userData['full_name'] : ($userData['username'] ?? 'User');
        $user_email = $userData['email'] ?? 'N/A';
        $user_phone = $userData['phone'] ?? 'N/A';
        $user_profile_image = $userData['profile_image'] ?? null;
        $role_type = $userData['role_type'] ?? $role_type;
        
        // Update session with latest data
        $_SESSION['user_name'] = $user_full_name;
        $_SESSION['email'] = $user_email;
        $_SESSION['profile_image'] = $user_profile_image;
        $_SESSION['role_type'] = $role_type;
    }
} catch (PDOException $e) {
    // Fallback to session data
}

// Get first name for display
$first_name = explode(' ', trim($user_full_name))[0];
$display_name = $first_name ?: $user_full_name;

$email = $user_email;
$phone = $user_phone;

// ==============================================
// PROFILE PHOTO PATH HANDLING
// ==============================================
$profile_image_path = null;

// Check if profile image exists in session
if (!empty($user_profile_image)) {
    $clean_path = ltrim($user_profile_image, './');
    $possible_paths = [
        '../' . $clean_path,
        '../uploads/profiles/' . basename($clean_path),
        '../uploads/profiles/default.png'
    ];
    
    if (!strpos($clean_path, '/')) {
        $possible_paths[] = '../uploads/profiles/' . $clean_path;
    }
    
    $found = false;
    foreach ($possible_paths as $path) {
        if (file_exists(__DIR__ . '/' . $path) || file_exists($path)) {
            $profile_image_path = $path;
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        $profile_image_path = '../uploads/profiles/default.png';
    }
} else {
    $profile_image_path = '../uploads/profiles/default.png';
}

$photo = $profile_image_path;

// Try to find user-specific image
if ($photo == '../uploads/profiles/default.png') {
    $uploads_dir = __DIR__ . '/../uploads/profiles/';
    if (is_dir($uploads_dir)) {
        $files = glob($uploads_dir . 'user_' . $user_id . '.*');
        if (!empty($files) && file_exists($files[0])) {
            $photo = '../uploads/profiles/' . basename($files[0]);
        }
    }
}

$currentPage = basename($_SERVER['PHP_SELF']);

// ==============================================
// FETCH SYSTEM NAME - SHOW COMPANY NAME FOR LOGGED IN USER
// ==============================================
$system_name = 'Cargo Management System';
$current_tenant_id = $_SESSION['tenant_id'] ?? null;

// For superadmin when a tenant is selected
if ($role === 'superadmin' && isset($_SESSION['selected_tenant_id']) && $_SESSION['selected_tenant_id'] !== 'all') {
    $current_tenant_id = $_SESSION['selected_tenant_id'];
}

// PRIORITY 1: If user has a tenant_id, show that tenant's name
if ($current_tenant_id) {
    try {
        // First try to get custom system name from settings
        $stmt_name = $pdo->prepare("SELECT setting_value FROM system_settings WHERE tenant_id = ? AND setting_key = 'system_name' LIMIT 1");
        $stmt_name->execute([$current_tenant_id]);
        $custom_name = $stmt_name->fetchColumn();
        if ($custom_name) {
            $system_name = $custom_name;
        } else {
            // Fallback to tenant name
            $stmt_t = $pdo->prepare("SELECT name FROM tenants WHERE id = ? LIMIT 1");
            $stmt_t->execute([$current_tenant_id]);
            $t_name = $stmt_t->fetchColumn();
            if ($t_name) {
                $system_name = $t_name;
            }
        }
    } catch (PDOException $e) {
        // If error, try to get tenant name directly
        try {
            $stmt_t = $pdo->prepare("SELECT name FROM tenants WHERE id = ? LIMIT 1");
            $stmt_t->execute([$current_tenant_id]);
            $t_name = $stmt_t->fetchColumn();
            if ($t_name) $system_name = $t_name;
        } catch (PDOException $e2) {}
    }
} else {
    // PRIORITY 2: For users without tenant_id (like superadmin viewing 'all')
    // Try to get user's associated tenant name from users table
    try {
        $stmt_user_tenant = $pdo->prepare("
            SELECT t.name 
            FROM users u 
            LEFT JOIN tenants t ON u.tenant_id = t.id 
            WHERE u.id = ?
        ");
        $stmt_user_tenant->execute([$user_id]);
        $user_tenant_name = $stmt_user_tenant->fetchColumn();
        if ($user_tenant_name) {
            $system_name = $user_tenant_name;
        }
    } catch (PDOException $e) {}
}

// Fetch tenants for superadmin dropdown
$all_tenants = [];
if ($role === 'superadmin') {
    try {
        $tenants_stmt = $pdo->query("SELECT id, name FROM tenants WHERE is_active = 1 ORDER BY name ASC");
        $all_tenants = $tenants_stmt->fetchAll();
    } catch (PDOException $e) {}
    $selected_tenant = $_SESSION['selected_tenant_id'] ?? 'all';
}

// Role display name
$role_display = ucfirst(str_replace('_', ' ', $role));
if ($role === 'superadmin') {
    $role_display = 'Super Administrator';
} elseif ($role === 'tenant_admin') {
    $role_display = 'Tenant Administrator';
} elseif ($role === 'staff') {
    $role_display = 'Staff Member';
} elseif ($role === 'customer') {
    $role_display = 'Customer';
} elseif ($role === 'branch_manager') {
    $role_display = 'Branch Manager';
} elseif ($role === 'branches_admin') {
    $role_display = 'Branches Administrator';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<?php require_once __DIR__ . '/csrf.php'; echo csrf_meta(); ?>
<title><?= htmlspecialchars($system_name) ?> | <?= ucfirst(str_replace('.php', '', $currentPage)) ?></title>

<link rel="icon" type="image/png" href="../assets/images/curdun-favicon.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="../assets/css/premium_ui.css">
<link rel="stylesheet" href="../assets/css/curdun-theme.css">

<style>
/* ==============================
   BASE STYLES
   ============================== */
:root {
  --curdun-purple: #2D1859;
  --curdun-gold: #F5C410;
  --curdun-purple-light: #4B2C85;
  --curdun-text: #1B1233;
  --curdun-background: #F4F5F9;
  --curdun-danger: #B42318;
  --curdun-gold-dark: #D4A70C;
  --curdun-white: #FFFFFF;
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  font-family: 'Poppins', sans-serif;
}

body {
  background: var(--curdun-background);
  color: var(--curdun-text);
  overflow-x: hidden;
  min-height: 100vh;
}

a {
  text-decoration: none;
  color: inherit;
}

/* ==============================
   HEADER STYLES
   ============================== */
.hu-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--curdun-purple);
  padding: 10px 20px;
  color: white;
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  height: 65px;
  z-index: 1000;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
}

.hu-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.menu-btn {
  background: none;
  border: none;
  color: var(--curdun-gold);
  font-size: 20px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  border-radius: 6px;
  transition: background 0.2s;
}

.menu-btn:hover {
  background: rgba(255, 255, 255, 0.1);
}

.logo-circle {
  background: white;
  border-radius: 10px;
  padding: 6px 10px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.hu-logo {
  height: 38px;
  width: auto;
  object-fit: contain;
}

.hu-title {
  font-weight: 600;
  font-size: 16px;
  color: var(--curdun-gold);
  padding-left: 12px;
  margin-left: 4px;
  border-left: 1px solid rgba(255, 255, 255, 0.2);
}

/* No duplicate company-name needed */

.hu-right {
  display: flex;
  align-items: center;
  gap: 15px;
}

.user-info {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  padding: 5px 8px;
  border-radius: 6px;
  transition: background 0.2s;
}

.user-info:hover {
  background: rgba(255, 255, 255, 0.1);
}

.user-photo {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  border: 2px solid var(--curdun-gold);
  object-fit: cover;
}

.user-details {
  text-align: right;
}

.user-name {
  font-weight: 600;
  color: var(--curdun-gold);
  font-size: 14px;
}

.user-role {
  font-size: 11px;
  color: #ddd;
}

.tenant-selector select {
  background: rgba(255, 255, 255, 0.1);
  color: white;
  border: 1px solid var(--curdun-gold);
  border-radius: 4px;
  padding: 5px 10px;
  font-size: 13px;
  cursor: pointer;
}

.tenant-selector select option {
  background: var(--curdun-purple);
  color: white;
}

/* ==============================
   SIDEBAR STYLES
   ============================== */
.sidebar {
  position: fixed;
  top: 65px;
  left: 0;
  width: 240px;
  height: calc(100% - 65px);
  background: var(--curdun-purple);
  color: white;
  overflow-y: auto;
  transition: all 0.3s ease;
  padding: 12px 0;
  z-index: 999;
}

.sidebar ul {
  list-style: none;
  padding-left: 0;
}

.sidebar li {
  border-top: 1px solid rgba(255, 255, 255, 0.1);
}

.sidebar a {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  color: white;
  font-weight: 500;
  border-left: 4px solid transparent;
  transition: all 0.2s;
  text-decoration: none;
}

.sidebar a i {
  width: 18px;
  text-align: center;
  font-size: 15px;
  color: var(--curdun-gold);
}

.sidebar a:hover,
.sidebar a.active {
  background: rgba(255, 255, 255, 0.1);
  color: var(--curdun-gold);
  border-left: 4px solid var(--curdun-gold);
  text-decoration: none;
}

.sidebar .submenu {
  display: none;
  background: rgba(0, 0, 0, 0.2);
}

.sidebar .submenu a {
  padding-left: 40px;
  font-size: 13px;
}

.sidebar .menu-toggle {
  cursor: pointer;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 16px;
  font-weight: 500;
}

.sidebar .menu-toggle i:first-child {
  color: var(--curdun-gold);
  width: 18px;
  text-align: center;
  margin-right: 10px;
}

.sidebar .menu-toggle:hover {
  background: rgba(255, 255, 255, 0.1);
  color: var(--curdun-gold);
}

.sidebar .menu-toggle i {
  transition: 0.2s;
}

.sidebar .open i.fa-chevron-right {
  transform: rotate(90deg);
}

/* Collapsed State */
.sidebar.collapsed {
  width: 60px;
}

.sidebar.collapsed a span,
.sidebar.collapsed .menu-toggle span,
.sidebar.collapsed .menu-toggle .fa-chevron-right {
  display: none;
}

.sidebar.collapsed .submenu {
  display: none !important;
}

.sidebar.collapsed a,
.sidebar.collapsed .menu-toggle {
  justify-content: center;
  padding: 12px 0;
}

/* ==============================
   MAIN CONTENT AREA
   ============================== */
.main-content {
  margin-top: 65px;
  margin-left: 240px;
  margin-bottom: 50px;
  padding: 20px;
  transition: margin-left 0.3s ease;
  min-height: calc(100vh - 115px);
}

body.sidebar-collapsed .main-content {
  margin-left: 60px;
}

/* ==============================
   PROFILE MODAL
   ============================== */
.profile-modal {
  display: none;
  justify-content: center;
  align-items: center;
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  z-index: 2000;
}

.profile-content {
  background: white;
  border-radius: 8px;
  padding: 20px;
  text-align: center;
  width: 320px;
  max-width: 90%;
  box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
  position: relative;
}

.profile-avatar {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  border: 3px solid var(--curdun-purple);
  object-fit: cover;
  margin-bottom: 10px;
}

.close-profile {
  position: absolute;
  top: 8px;
  right: 12px;
  font-size: 20px;
  color: var(--curdun-purple);
  cursor: pointer;
}

.profile-info {
  margin: 10px 0;
}

.profile-info h3 {
  font-size: 18px;
  margin-bottom: 5px;
  color: #333;
}

.profile-info p {
  font-size: 12px;
  color: #666;
  margin: 3px 0;
}

.profile-links {
  margin-top: 16px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.profile-link {
  background: var(--curdun-purple);
  color: white;
  border: none;
  padding: 10px;
  border-radius: 5px;
  cursor: pointer;
  transition: background 0.2s;
  text-decoration: none;
  display: block;
}

.profile-link:hover {
  background: var(--curdun-purple-light);
  color: white;
}

.profile-link.logout-link {
  background: var(--curdun-danger);
}

.profile-link.logout-link:hover {
  background: #b71c1c;
}

.no-photo-message {
  background: #fff3cd;
  color: #856404;
  padding: 8px;
  border-radius: 5px;
  margin: 8px 0;
  font-size: 12px;
  border: 1px solid #ffeaa7;
}

/* Overlay Styles */
.overlay {
  display: none;
  justify-content: center;
  align-items: center;
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  z-index: 3000;
}

.overlay-content {
  background: white;
  border-radius: 6px;
  width: 90%;
  height: 90%;
  position: relative;
}

.close-overlay {
  position: absolute;
  top: 8px;
  right: 16px;
  font-size: 22px;
  color: var(--curdun-purple);
  cursor: pointer;
  z-index: 10;
}

.overlay-frame {
  width: 100%;
  height: 100%;
  border: none;
  border-radius: 6px;
}

/* Flash Message */
.flash-message {
  position: fixed;
  top: 80px;
  right: 20px;
  z-index: 2500;
  padding: 12px 20px;
  border-radius: 8px;
  background: white;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  animation: slideIn 0.3s ease;
}

.flash-message.success {
  border-left: 4px solid #28a745;
}

.flash-message.error {
  border-left: 4px solid #dc3545;
}

.flash-message.warning {
  border-left: 4px solid #ffc107;
}

@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateX(100px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}

/* ==============================
   RESPONSIVE STYLES
   ============================== */
@media (max-width: 768px) {
  .hu-title { 
    font-size: 14px;
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .user-details { display: none; }
  
  .sidebar {
    left: -240px;
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
  }
  .sidebar.mobile-open { left: 0; }
  .main-content {
    margin-left: 0 !important;
  }
  
  .tenant-selector select {
    max-width: 120px;
    font-size: 11px;
    padding: 4px 6px;
  }
  
  .flash-message {
    top: 70px;
    right: 10px;
    left: 10px;
    font-size: 12px;
  }
}

@media (max-width: 480px) {
  .hu-header {
    padding: 8px 12px;
  }
  
  .user-photo {
    width: 32px;
    height: 32px;
  }
  
  .tenant-selector select {
    max-width: 100px;
  }
  
  .hu-title {
    font-size: 12px;
    max-width: 120px;
  }
}
</style>
</head>
<body>

<header class="hu-header">
  <div class="hu-left">
    <button id="sidebarToggle" class="menu-btn" aria-label="Toggle Sidebar">
      <i class="fa-solid fa-bars" id="toggleIcon"></i>
    </button>
    <div class="logo-circle">
      <img src="../assets/images/curdun-logo1.png" alt="CURDUN ICT" class="hu-logo">
    </div>
    <span class="hu-title">
      <?= htmlspecialchars($system_name) ?>
    </span>
  </div>
  <div class="hu-right">
    <?php if ($role === 'superadmin' && !empty($all_tenants)): ?>
      <div class="tenant-selector mr-3">
        <select id="globalTenantSelect" class="form-control form-control-sm" style="min-width: 150px;">
          <option value="all" <?= ($selected_tenant ?? 'all') == 'all' ? 'selected' : '' ?>>Dhammaan Shirkadaha</option>
          <?php foreach ($all_tenants as $t): ?>
            <option value="<?= $t['id'] ?>" <?= ($selected_tenant ?? 'all') == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="user-info" id="profileOpen">
      <img src="<?= $photo ?>?t=<?= time() ?>" alt="Profile" class="user-photo" id="userProfilePhoto">
      <div class="user-details">
        <div class="user-name"><?= htmlspecialchars($display_name) ?></div>
        <div class="user-role"><?= $role_display ?></div>
      </div>
    </div>
  </div>
</header>

<!-- SIDEBAR NAVIGATION - ALL ROLES (SAME AS BEFORE) -->
<nav class="sidebar" id="sidebar">
  <ul>
    
    <?php if ($role === 'superadmin'): ?>
      <!-- ==================== SUPERADMIN SIDEBAR ==================== -->
      <li><a href="../superadmin/dashboard.php" class="<?= $currentPage == 'dashboard.php' ? 'active' : '' ?>"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
      
      <li>
        <div class="menu-toggle" data-target="opsMenu"><span><i class="fa-solid fa-box-open"></i> Operations</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="opsMenu">
          <li><a href="../superadmin/receptions.php"><i class="fa-solid fa-clipboard-check"></i> Receptions</a></li>
          <li><a href="../superadmin/mogadishu_warehouse.php"><i class="fa-solid fa-warehouse"></i> Bakhaarka Xamar</a></li>
          <li><a href="../superadmin/warehouse_stock.php"><i class="fa-solid fa-warehouse"></i> Warehouse Stock</a></li>
          <li><a href="../superadmin/containers.php"><i class="fa-solid fa-truck-loading"></i> Containers</a></li>
        </ul>
      </li>

      <li>
        <div class="menu-toggle" data-target="logisticsMenu"><span><i class="fa-solid fa-route"></i> Logistics</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="logisticsMenu">
          <li><a href="../superadmin/trucking.php"><i class="fa-solid fa-truck"></i> Trucking Fleet</a></li>
          <li><a href="../superadmin/tracking.php"><i class="fa-solid fa-map-location-dot"></i> Live Tracking</a></li>
          <li><a href="../superadmin/drivers.php"><i class="fa-solid fa-id-card"></i> Drivers</a></li>
          <li><a href="../superadmin/loaders.php"><i class="fa-solid fa-person-walking-arrow-right"></i> Loaders</a></li>
        </ul>
      </li>

      <li>
        <div class="menu-toggle" data-target="financeMenu"><span><i class="fa-solid fa-wallet"></i> Finance</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="financeMenu">
          <li><a href="../superadmin/accounting.php"><i class="fa-solid fa-university"></i> Accounting</a></li>
          <li><a href="../superadmin/receipt_management.php"><i class="fa-solid fa-hand-holding-dollar"></i> Lacag Qabashada</a></li>
          <li><a href="../superadmin/expenses_management.php"><i class="fa-solid fa-receipt"></i> Expenses & Bills</a></li>
          <li><a href="../superadmin/bank_reconciliation.php"><i class="fa-solid fa-balance-scale"></i> Bank Reconciliation</a></li>
          <li><a href="../superadmin/tax_management.php"><i class="fa-solid fa-percent"></i> Tax Management</a></li>
          <li><a href="../superadmin/invoices.php"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices</a></li>
          <li><a href="../superadmin/customers.php"><i class="fa-solid fa-users"></i> Customers</a></li>
          <li><a href="../superadmin/payments.php"><i class="fa-solid fa-credit-card"></i> Payments</a></li>
          <li><a href="../superadmin/loyalty_points.php"><i class="fa-solid fa-users"></i> Loyalty Points</a></li>

        </ul>
      </li>

      <li>
        <div class="menu-toggle" data-target="adminMenu"><span><i class="fa-solid fa-users-gear"></i> Administration</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="adminMenu">
          <li><a href="../superadmin/tenants.php"><i class="fa-solid fa-building"></i> Tenants</a></li>
          <li><a href="../superadmin/users.php"><i class="fa-solid fa-user-shield"></i> System Users</a></li>
          <li><a href="../superadmin/message_templates.php"><i class="fa-solid fa-envelope-open-text"></i> Message Templates</a></li>
          <li><a href="../superadmin/branches.php"><i class="fas fa-code-branch"></i> Branches</a></li>
          <li><a href="../superadmin/branch_assignments.php"><i class="fas fa-user-check"></i> Branch Assignments</a></li>
          <li><a href="../superadmin/roles.php"><i class="fa-solid fa-user-tag"></i> Roles</a></li>
        </ul>
      </li>
      
      <li><a href="../superadmin/reports.php"><i class="fa-solid fa-chart-pie"></i><span>Reports</span></a></li>
      <li><a href="../superadmin/settings.php"><i class="fa-solid fa-gear"></i><span>Settings</span></a></li>

    <?php elseif ($role === 'tenant_admin'): ?>
      <!-- ==================== TENANT ADMIN SIDEBAR ==================== -->
      <li><a href="../tenant_admin/dashboard.php" class="<?= $currentPage == 'dashboard.php' ? 'active' : '' ?>"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
      
      <li>
        <div class="menu-toggle" data-target="opsMenu"><span><i class="fa-solid fa-box-open"></i> Operations</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="opsMenu">
          <li><a href="../tenant_admin/receptions.php"><i class="fa-solid fa-clipboard-check"></i> Receptions</a></li>
          <li><a href="../tenant_admin/mogadishu_warehouse.php"><i class="fa-solid fa-warehouse"></i> Bakhaarka Xamar</a></li>
          <li><a href="../tenant_admin/warehouse_stock.php"><i class="fa-solid fa-warehouse"></i> Warehouse Stock</a></li>
          <li><a href="../tenant_admin/containers.php"><i class="fa-solid fa-truck-loading"></i> Containers</a></li>
<li>
    <a href="../tenant_admin/template_message.php">
        <i class="fa-solid fa-envelope-open-text"></i> Template Messages
    </a>
</li>
        </ul>
      </li>

      <li>
        <div class="menu-toggle" data-target="logisticsMenu"><span><i class="fa-solid fa-route"></i> Logistics</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="logisticsMenu">
          <li><a href="../tenant_admin/trucking.php"><i class="fa-solid fa-truck"></i> Trucking Fleet</a></li>
          <li><a href="../tenant_admin/tracking.php"><i class="fa-solid fa-map-location-dot"></i> Live Tracking</a></li>
          <li><a href="../tenant_admin/drivers.php"><i class="fa-solid fa-id-card"></i> Drivers</a></li>
          <li><a href="../tenant_admin/loaders.php"><i class="fa-solid fa-person-walking-arrow-right"></i> Loaders</a></li>
        </ul>
      </li>

      <li>
        <div class="menu-toggle" data-target="financeMenu"><span><i class="fa-solid fa-wallet"></i> Finance</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="financeMenu">
          <li><a href="../tenant_admin/accounting.php"><i class="fa-solid fa-university"></i> Accounting</a></li>
          <li><a href="../tenant_admin/receipt_management.php"><i class="fa-solid fa-hand-holding-dollar"></i> Lacag Qabashada</a></li>
          <li><a href="../tenant_admin/expenses_management.php"><i class="fa-solid fa-receipt"></i> Expenses & Bills</a></li>
          <li><a href="../tenant_admin/bank_reconciliation.php"><i class="fa-solid fa-balance-scale"></i> Bank Reconciliation</a></li>
          <li><a href="../tenant_admin/tax_management.php"><i class="fa-solid fa-percent"></i> Tax Management</a></li>
          <li><a href="../tenant_admin/invoices.php"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices</a></li>
          <li><a href="../tenant_admin/customers.php"><i class="fa-solid fa-users"></i> Customers</a></li>
          <li><a href="../tenant_admin/payments.php"><i class="fa-solid fa-credit-card"></i> Payments</a></li>
          <li>
    <a href="../tenant_admin/debts.php">
        <i class="fa-solid fa-coins"></i> Debts
    </a>
</li>
          <li><a href="../tenant_admin/loyalty_points.php"><i class="fa-solid fa-gift"></i> Loyalty Points</a>
</li>
        </ul>
      </li>

      <li>
        <div class="menu-toggle" data-target="adminMenu"><span><i class="fa-solid fa-users-gear"></i> Administration</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="adminMenu">
          <li><a href="../tenant_admin/users.php"><i class="fa-solid fa-user-shield"></i> Users</a></li>
          <li><a href="../tenant_admin/branches.php"><i class="fas fa-code-branch"></i> Branches</a></li>
          <li><a href="../tenant_admin/branch_assignments.php"><i class="fas fa-user-check"></i> Branch Assignments</a></li>
        </ul>
      </li>
      
      <li><a href="../tenant_admin/reports.php"><i class="fa-solid fa-chart-pie"></i><span>Reports</span></a></li>
      <li><a href="../tenant_admin/settings.php"><i class="fa-solid fa-gear"></i><span>Settings</span></a></li>
      <li><a href="../tenant_admin/ticket.php"><i class="fa-solid fa-ticket"></i><span>ticket</span></a></li>

    <?php elseif ($role === 'branch_manager'): ?>
      <!-- ==================== BRANCH MANAGER SIDEBAR ==================== -->
      <li><a href="../branch_manager/dashboard.php" class="<?= $currentPage == 'dashboard.php' ? 'active' : '' ?>"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
      
      <li>
        <div class="menu-toggle" data-target="opsMenu"><span><i class="fa-solid fa-box-open"></i> Branch Operations</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="opsMenu">
          <li><a href="../branch_manager/receptions.php"><i class="fa-solid fa-clipboard-check"></i> Receptions</a></li>
          <li><a href="../branch_manager/warehouse_stock.php"><i class="fa-solid fa-warehouse"></i> Warehouse Stock</a></li>
          <li><a href="../branch_manager/branch_transfers.php"><i class="fa-solid fa-exchange-alt"></i> Branch Transfers</a></li>
        </ul>
      </li>

      <li>
        <div class="menu-toggle" data-target="logisticsMenu"><span><i class="fa-solid fa-route"></i> Logistics</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="logisticsMenu">
<li>
    <a href="../branch_manager/containers.php">
        <i class="fa-solid fa-boxes-stacked"></i> Containers
    </a>
</li>          <li><a href="../branch_manager/tracking.php"><i class="fa-solid fa-map-location-dot"></i> Live Tracking</a></li>
          <li><a href="../branch_manager/trips.php"><i class="fa-solid fa-road"></i> Trips</a></li>
        </ul>
      </li>

      <li>
        <div class="menu-toggle" data-target="financeMenu"><span><i class="fa-solid fa-wallet"></i> Finance</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="financeMenu">
          <li><a href="../branch_manager/expenses.php"><i class="fa-solid fa-receipt"></i> Expenses</a></li>
          <li><a href="../branch_manager/invoices.php"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices</a></li>
          <li><a href="../branch_manager/receipts.php"><i class="fa-solid fa-hand-holding-dollar"></i> Receipts</a></li>
        </ul>
      </li>

      <li><a href="../branch_manager/branch_report.php"><i class="fa-solid fa-chart-simple"></i><span>Branch Reports</span></a></li>
      <li><a href="../branch_manager/branch_settings.php"><i class="fa-solid fa-gear"></i><span>Branch Settings</span></a></li>

    <?php elseif ($role === 'branches_admin'): ?>
      <!-- ==================== BRANCHES ADMIN SIDEBAR ==================== -->
      <li><a href="../branches_admin/dashboard.php" class="<?= $currentPage == 'dashboard.php' ? 'active' : '' ?>"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
      
      <li>
        <div class="menu-toggle" data-target="branchMgmt"><span><i class="fas fa-code-branch"></i> Branch Management</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="branchMgmt">
          <li><a href="../branches_admin/branches.php"><i class="fas fa-code-branch"></i> All Branches</a></li>
          <li><a href="../branches_admin/branch_stock.php"><i class="fa-solid fa-boxes-stacked"></i> Branch Stock</a></li>
          <li><a href="../branches_admin/branch_transfers.php"><i class="fa-solid fa-exchange-alt"></i> Branch Transfers</a></li>
          <li><a href="../branches_admin/branch_users.php"><i class="fa-solid fa-users"></i> Branch Users</a></li>
          <li><a href="../branches_admin/branch_assignments.php"><i class="fas fa-user-check"></i> User Assignments</a></li>
        </ul>
      </li>

      <li>
        <div class="menu-toggle" data-target="opsMenu"><span><i class="fa-solid fa-box-open"></i> Operations</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="opsMenu">
          <li><a href="../branches_admin/receptions.php"><i class="fa-solid fa-clipboard-check"></i> Receptions</a></li>
          <li><a href="../branches_admin/warehouse_stock.php"><i class="fa-solid fa-warehouse"></i> Warehouse Stock</a></li>
          <li><a href="../branches_admin/containers.php"><i class="fa-solid fa-truck-loading"></i> Containers</a></li>
        </ul>
      </li>

      <li>
        <div class="menu-toggle" data-target="reportsMenu"><span><i class="fa-solid fa-chart-pie"></i> Reports</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="reportsMenu">
          <li><a href="../branches_admin/branch_reports.php"><i class="fa-solid fa-chart-simple"></i> Branch Reports</a></li>
          <li><a href="../branches_admin/stock_reports.php"><i class="fa-solid fa-chart-line"></i> Stock Reports</a></li>
          <li><a href="../branches_admin/transfer_reports.php"><i class="fa-solid fa-truck-ramp-box"></i> Transfer Reports</a></li>
        </ul>
      </li>

    <?php elseif (in_array($role, ['staff','reception_clerk','warehouse_supervisor','logistics_supervisor','finance_manager','clerk'], true)): ?>
      <!-- ==================== STAFF SIDEBAR ==================== -->
      <li><a href="../staff/dashboard.php" class="<?= $currentPage == 'dashboard.php' ? 'active' : '' ?>"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
      <li><a href="../staff/shipments.php"><i class="fa-solid fa-boxes-stacked"></i><span>Shipments</span></a></li>
      <?php if ($role_type === 'reception_clerk' || $role_type === 'clerk'): ?>
        <!-- Reception intake belongs to reception clerks. -->
        <li><a href="../staff/receptions.php"><i class="fa-solid fa-clipboard-check"></i><span>Receptions</span></a></li>
      <?php endif; ?>
      <?php if ($role_type === 'warehouse_supervisor'): ?>
        <!-- Destination warehouse receiving — server-gated to warehouse_supervisor only. -->
        <li><a href="../staff/incoming_trips.php"><i class="fa-solid fa-truck-ramp-box"></i><span>Incoming Trips</span></a></li>
      <?php endif; ?>
      <?php if ($role_type === 'warehouse_supervisor'): ?>
        <li><a href="../staff/warehouse_stock.php"><i class="fa-solid fa-warehouse"></i><span>Warehouse Stock</span></a></li>
      <?php endif; ?>
      <li><a href="../staff/stock_movements.php"><i class="fa-solid fa-right-left"></i><span>Stock Movements</span></a></li>

      <?php if ($role_type === 'logistics_supervisor'): ?>
        <!-- Trip / Container management belongs to logistics, not destination warehouse. -->
        <li><a href="../staff/trips.php"><i class="fa-solid fa-road"></i><span>Trips</span></a></li>
        <li><a href="../staff/containers.php"><i class="fa-solid fa-truck-loading"></i><span>Containers</span></a></li>
      <?php endif; ?>
      
      <?php if ($role_type === 'finance_manager' || $role_type === 'clerk'): ?>
        <li><a href="../staff/invoices.php"><i class="fa-solid fa-file-invoice-dollar"></i><span>Invoices</span></a></li>
        <li><a href="../staff/receipts.php"><i class="fa-solid fa-hand-holding-dollar"></i><span>Receipts</span></a></li>
      <?php endif; ?>

    <?php elseif ($role === 'driver' || $role === 'delivery_agent'): ?>
      <!-- ==================== DRIVER / COURIER SIDEBAR ==================== -->
      <li><a href="../driver/index.php" class="<?= $currentPage == 'index.php' ? 'active' : '' ?>"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
      <?php if ($role === 'driver'): ?>
        <li><a href="../driver/my_trips.php" class="<?= $currentPage == 'my_trips.php' ? 'active' : '' ?>"><i class="fa-solid fa-road"></i><span>My Trips</span></a></li>
        <li><a href="../driver/profile.php" class="<?= $currentPage == 'profile.php' ? 'active' : '' ?>"><i class="fa-solid fa-user"></i><span>Profile</span></a></li>
      <?php endif; ?>
      <?php if ($role === 'delivery_agent'): ?>
        <li><a href="../driver/deliveries.php" class="<?= $currentPage == 'deliveries.php' ? 'active' : '' ?>"><i class="fa-solid fa-motorcycle"></i><span>My Deliveries</span></a></li>
      <?php endif; ?>

    <?php elseif ($role === 'customer'): ?>

      <!-- ==================== CUSTOMER SIDEBAR ==================== -->
      <li><a href="../customer/dashboard.php" class="<?= $currentPage == 'dashboard.php' ? 'active' : '' ?>"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
      <li><a href="../customer/tracking.php"><i class="fa-solid fa-map-location-dot"></i><span>My Shipments</span></a></li>
      <li><a href="../customer/invoices.php"><i class="fa-solid fa-file-invoice"></i><span>My Invoices</span></a></li>
      <li><a href="../customer/payments.php"><i class="fa-solid fa-credit-card"></i><span>Payments</span></a></li>
     <li><a href="../customer/loyalty_points.php"><i class="fa-solid fa-users"></i> Loyalty Points</a></li>
      <li><a href="../customer/support.php"><i class="fa-solid fa-headset"></i><span>Support</span></a></li>

    <?php else: ?>
      <!-- ==================== DEFAULT / UNKNOWN ROLE ==================== -->
      <li><a href="../dashboard.php"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
      <li><a href="#"><i class="fa-solid fa-ban"></i><span>Access Restricted</span></a></li>
    <?php endif; ?>
    
  </ul>
</nav>

<!-- PROFILE MODAL (SAME AS BEFORE) -->
<div class="profile-modal" id="profileModal">
  <div class="profile-content">
    <span class="close-profile" id="closeProfile">&times;</span>
    <img src="<?= $photo ?>?t=<?= time() ?>" alt="Profile" class="profile-avatar" id="modalProfilePhoto">
    <div class="profile-info">
      <h3><?= htmlspecialchars($user_full_name) ?></h3>
      <p><i class="fas fa-envelope"></i> <?= htmlspecialchars($email) ?></p>
      <p><i class="fas fa-phone"></i> <?= htmlspecialchars($phone) ?></p>
      <p><i class="fas fa-user-tag"></i> <?= $role_display ?></p>
    </div>
    <?php if ($photo == '../uploads/profiles/default.png') : ?>
      <div class="no-photo-message"><i class="fa-solid fa-exclamation-triangle"></i> No profile photo uploaded.</div>
    <?php endif; ?>
    <div class="profile-links">
      <button class="profile-link" id="viewProfileBtn"><i class="fa-solid fa-user"></i> View Profile</button>
      <button class="profile-link" id="changePasswordBtn"><i class="fa-solid fa-lock"></i> Change Password</button>
      <a href="../logout.php" class="profile-link logout-link"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
  </div>
</div>

<!-- Overlay for View Profile -->
<div class="overlay" id="overlayView">
  <div class="overlay-content">
    <span class="close-overlay" data-close="overlayView">&times;</span>
    <iframe src="../config/view_profile.php" class="overlay-frame"></iframe>
  </div>
</div>

<!-- Overlay for Change Password -->
<div class="overlay" id="overlayPassword">
  <div class="overlay-content">
    <span class="close-overlay" data-close="overlayPassword">&times;</span>
    <iframe src="../config/change_password.php" class="overlay-frame"></iframe>
  </div>
</div>

<!-- Flash Message Display -->
<?php
$flash = getFlashMessage();
if ($flash):
?>
<div class="flash-message <?= $flash['type'] ?>" id="flashMessage">
  <i class="fas <?= $flash['type'] === 'success' ? 'fa-check-circle' : ($flash['type'] === 'error' ? 'fa-exclamation-triangle' : 'fa-info-circle') ?>"></i>
  <?= htmlspecialchars($flash['message']) ?>
</div>
<script>
  setTimeout(function() {
    var flash = document.getElementById('flashMessage');
    if (flash) flash.style.display = 'none';
  }, 5000);
</script>
<?php endif; ?>

<!-- Main Content wrapper starts here -->
<div class="main-content">

<script>
// Tenant Selector AJAX
document.addEventListener('DOMContentLoaded', function() {
    const tenantSelect = document.getElementById('globalTenantSelect');
    if (tenantSelect) {
        tenantSelect.addEventListener('change', function() {
            const tenantId = this.value;
            fetch('../includes/set_tenant.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'tenant_id=' + tenantId
            }).then(() => {
                window.location.reload();
            }).catch(err => console.log('Error:', err));
        });
    }
});

// Sidebar and Mobile Navigation
let isMobile = window.innerWidth <= 768;
const sidebar = document.getElementById("sidebar");
const toggleBtn = document.getElementById("sidebarToggle");
const toggleIcon = document.getElementById("toggleIcon");
const body = document.body;
let sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';

function initializeSidebar() {
    if (isMobile) {
        sidebar.classList.remove('collapsed', 'mobile-open');
        body.classList.remove('sidebar-collapsed');
        if (toggleIcon) {
            toggleIcon.classList.remove('fa-xmark');
            toggleIcon.classList.add('fa-bars');
        }
    } else {
        if (sidebarCollapsed) {
            sidebar.classList.add('collapsed');
            body.classList.add('sidebar-collapsed');
            if (toggleIcon) {
                toggleIcon.classList.remove('fa-bars');
                toggleIcon.classList.add('fa-xmark');
            }
        } else {
            sidebar.classList.remove('collapsed');
            body.classList.remove('sidebar-collapsed');
            if (toggleIcon) {
                toggleIcon.classList.remove('fa-xmark');
                toggleIcon.classList.add('fa-bars');
            }
        }
    }
}

function checkMobile() {
    isMobile = window.innerWidth <= 768;
    initializeSidebar();
}

if (toggleBtn) {
    toggleBtn.addEventListener('click', function() {
        if (isMobile) {
            sidebar.classList.toggle('mobile-open');
            if (toggleIcon) {
                toggleIcon.classList.toggle('fa-bars');
                toggleIcon.classList.toggle('fa-xmark');
            }
        } else {
            sidebarCollapsed = !sidebarCollapsed;
            if (sidebarCollapsed) {
                sidebar.classList.add('collapsed');
                body.classList.add('sidebar-collapsed');
                if (toggleIcon) {
                    toggleIcon.classList.remove('fa-bars');
                    toggleIcon.classList.add('fa-xmark');
                }
            } else {
                sidebar.classList.remove('collapsed');
                body.classList.remove('sidebar-collapsed');
                if (toggleIcon) {
                    toggleIcon.classList.remove('fa-xmark');
                    toggleIcon.classList.add('fa-bars');
                }
            }
            localStorage.setItem('sidebarCollapsed', sidebarCollapsed);
        }
    });
}

// Close mobile sidebar when clicking outside
document.addEventListener('click', function(event) {
    if (isMobile && sidebar && sidebar.classList.contains('mobile-open')) {
        if (!sidebar.contains(event.target) && toggleBtn && !toggleBtn.contains(event.target)) {
            sidebar.classList.remove('mobile-open');
            if (toggleIcon) {
                toggleIcon.classList.remove('fa-xmark');
                toggleIcon.classList.add('fa-bars');
            }
        }
    }
});

// Close sidebar on mobile when clicking a link
document.querySelectorAll('.sidebar a').forEach(link => {
    link.addEventListener('click', () => {
        if (isMobile && sidebar) {
            sidebar.classList.remove('mobile-open');
            if (toggleIcon) {
                toggleIcon.classList.remove('fa-xmark');
                toggleIcon.classList.add('fa-bars');
            }
        }
    });
});

// Submenu toggle
document.querySelectorAll(".menu-toggle").forEach(toggle => {
    toggle.addEventListener('click', function(e) {
        if (isMobile) e.stopPropagation();
        const submenuId = this.getAttribute('data-target');
        if (submenuId) {
            const submenu = document.getElementById(submenuId);
            if (submenu) {
                if (submenu.style.display === "block") {
                    submenu.style.display = "none";
                    this.classList.remove("open");
                } else {
                    submenu.style.display = "block";
                    this.classList.add("open");
                }
            }
        }
    });
});

// Profile modal
const profileModal = document.getElementById("profileModal");
const profileOpen = document.getElementById("profileOpen");
const closeProfile = document.getElementById("closeProfile");

if (profileOpen) {
    profileOpen.addEventListener('click', () => {
        if (profileModal) {
            profileModal.style.display = "flex";
            document.body.style.overflow = "hidden";
        }
    });
}

if (closeProfile) {
    closeProfile.addEventListener('click', () => {
        if (profileModal) {
            profileModal.style.display = "none";
            document.body.style.overflow = "auto";
        }
    });
}

// Overlay close buttons
document.querySelectorAll(".close-overlay").forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.getAttribute("data-close");
        const overlay = document.getElementById(id);
        if (overlay) {
            overlay.style.display = "none";
            document.body.style.overflow = "auto";
        }
    });
});

// View profile button
const viewProfileBtn = document.getElementById("viewProfileBtn");
if (viewProfileBtn) {
    viewProfileBtn.addEventListener('click', () => {
        if (profileModal) profileModal.style.display = "none";
        const overlayView = document.getElementById("overlayView");
        if (overlayView) {
            overlayView.style.display = "flex";
            document.body.style.overflow = "hidden";
        }
    });
}

// Change password button
const changePasswordBtn = document.getElementById("changePasswordBtn");
if (changePasswordBtn) {
    changePasswordBtn.addEventListener('click', () => {
        if (profileModal) profileModal.style.display = "none";
        const overlayPassword = document.getElementById("overlayPassword");
        if (overlayPassword) {
            overlayPassword.style.display = "flex";
            document.body.style.overflow = "hidden";
        }
    });
}

// Close modals when clicking outside
window.addEventListener('click', function(event) {
    if (profileModal && event.target === profileModal) {
        profileModal.style.display = "none";
        document.body.style.overflow = "auto";
    }
    
    document.querySelectorAll('.overlay').forEach(overlay => {
        if (event.target === overlay) {
            overlay.style.display = "none";
            document.body.style.overflow = "auto";
        }
    });
});

// Initialize on load
checkMobile();
window.addEventListener('resize', function() {
    checkMobile();
});
</script>