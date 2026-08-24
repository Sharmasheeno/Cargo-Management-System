<?php
/*******************************************************************************************
 * faras cargo - UNIVERSAL LOGIN SYSTEM
 * Database: curdun_smart_cargo
 * Table: users (columns: id, full_name, email, phone, password_hash, role_type, is_active)
 * Roles: superadmin, company_admin, branch_manager, staff, customer, branches_admin
 *******************************************************************************************/
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/config/db_connect.php';

$error = '';
$success = '';

// Redirect if already logged in
if (!empty($_SESSION['user_id']) && !empty($_SESSION['logged_in'])) {
    $redirect = getDashboardRedirect($_SESSION['role_type'] ?? $_SESSION['role'] ?? '');
    if ($redirect) {
        header("Location: " . $redirect);
        exit;
    }
}

// Check if any super admin exists (using correct column name 'role_type')
$adminCount = 0;
try {
    $stmtCheck = $pdo->query("SELECT COUNT(*) AS total FROM users WHERE role_type = 'superadmin' AND is_active = 1");
    $adminCount = (int)$stmtCheck->fetch(PDO::FETCH_ASSOC)['total'];
} catch (Exception $e) {
    $error = "Database connection error: " . $e->getMessage();
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($email) && !empty($password)) {
        try {
            // First check if user exists (including inactive)
            $checkStmt = $pdo->prepare("SELECT id, is_active, role_type FROM users WHERE email = ? LIMIT 1");
            $checkStmt->execute([$email]);
            $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            // If user exists but is inactive
            if ($existingUser && $existingUser['is_active'] == 0) {
                $error = "Your account is INACTIVE! Please contact the administrator to activate your account.";
            } else {
                // Get active user with all details
                $stmt = $pdo->prepare("
                    SELECT 
                        u.*,
                        t.name as tenant_name,
                        t.id as tenant_id,
                        t.code as tenant_code
                    FROM users u
                    LEFT JOIN tenants t ON u.tenant_id = t.id AND t.is_active = 1
                    WHERE u.email = ? AND u.is_active = 1 
                    LIMIT 1
                ");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    // Check password (supports both password and password_hash columns)
                    $passwordValid = false;
                    if (!empty($user['password_hash'])) {
                        $passwordValid = password_verify($password, $user['password_hash']);
                    } elseif (!empty($user['password'])) {
                        $passwordValid = password_verify($password, $user['password']);
                    }
                    
                    if ($passwordValid) {
                        // Update last login time
                        $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                        $updateStmt->execute([$user['id']]);
                        
                        // Regenerate session ID for security
                        session_regenerate_id(true);
                        
                        // Clear any existing session data
                        $_SESSION = array();
                        
                        // Set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_name'] = $user['full_name'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['phone'] = $user['phone'] ?? '';
                        $_SESSION['role_type'] = $user['role_type'];
                        $_SESSION['role'] = $user['role_type']; // For backward compatibility
                        $_SESSION['staff_level'] = $user['staff_level'] ?? null;
                        $_SESSION['profile_image'] = $user['profile_image'] ?? null;
                        $_SESSION['is_active'] = $user['is_active'];
                        $_SESSION['tenant_id'] = $user['tenant_id'] ?? null;
                        $_SESSION['tenant_name'] = $user['tenant_name'] ?? null;
                        $_SESSION['default_branch_id'] = $user['default_branch_id'] ?? null;
                        $_SESSION['logged_in'] = true;
                        $_SESSION['login_time'] = time();
                        
                        // Get user's branch assignments if any
                        if ($user['tenant_id']) {
                            try {
                                $branchStmt = $pdo->prepare("
                                    SELECT branch_id, is_primary, can_manage_branch 
                                    FROM user_branch_assignments 
                                    WHERE user_id = ? AND is_primary = 1
                                    LIMIT 1
                                ");
                                $branchStmt->execute([$user['id']]);
                                $primaryBranch = $branchStmt->fetch(PDO::FETCH_ASSOC);
                                if ($primaryBranch) {
                                    $_SESSION['assigned_branch_id'] = $primaryBranch['branch_id'];
                                    $_SESSION['can_manage_branch'] = $primaryBranch['can_manage_branch'];
                                }
                            } catch (Exception $e) {
                                // Branch assignments table might not exist or have no data
                            }
                        }
                        
                        // Role-based redirection
                        $redirectUrl = getDashboardRedirect($user['role_type']);
                        header("Location: " . $redirectUrl);
                        exit;
                        
                    } else {
                        $error = "Incorrect password! Please try again.";
                    }
                } else {
                    if (!$existingUser) {
                        $error = "No account found with this email address!";
                    } else {
                        $error = "Invalid login credentials!";
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $error = "System error occurred. Please try again later.";
        }
    } else {
        $error = "Please enter both email and password!";
    }
}

/**
 * Get dashboard redirect URL based on user role
 */
function getDashboardRedirect($role) {
    $role = strtolower(trim($role));
    
    $redirects = [
        'superadmin' => 'superadmin/dashboard.php',
        'company_admin' => 'tenant_admin/dashboard.php',
        'tenant_admin' => 'tenant_admin/dashboard.php',
        'branch_manager' => 'branch_manager/dashboard.php',
        'branches_admin' => 'branches_admin/dashboard.php',
        'staff' => 'staff/dashboard.php',
        'customer' => 'customer/dashboard.php',
    ];
    
    // Special handling for staff role types
    if ($role === 'staff') {
        return 'staff/dashboard.php';
    }
    
    return $redirects[$role] ?? 'staff/dashboard.php';
}

// Get system name for login page
$login_system_name = 'Cargo Management System';
$t_code = $_GET['t'] ?? null;
if ($t_code) {
    try {
        $stmt_t = $pdo->prepare("SELECT name FROM tenants WHERE code = ? AND is_active = 1 LIMIT 1");
        $stmt_t->execute([$t_code]);
        $t_name = $stmt_t->fetchColumn();
        if ($t_name) $login_system_name = $t_name;
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="so">
<head>
<meta charset="UTF-8">
<title>Login | <?= htmlspecialchars($login_system_name) ?></title>
<link rel="icon" type="image/png" href="assets/images/curdun-favicon.png">
<link rel="stylesheet" href="assets/css/curdun-theme.css">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --primary-purple: #2D1859;
  --primary-yellow: #F5C410;
  --primary-purple-light: #4B2C85;
  --white: #FFFFFF;
  --bg-light: #F4F5F9;
  --dark: #1B1233;
  --gray: #69647A;
  --danger: #B42318;
  --success: #0F7A3A;
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  background: var(--primary-purple);
  font-family: 'Poppins', sans-serif;
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 100vh;
  margin: 0;
  position: relative;
}

/* Animated Background */
body::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" opacity="0.05"><path fill="white" d="M20,20 L80,20 L80,80 L20,80 Z"/><circle cx="50" cy="50" r="15" fill="white"/></svg>');
  background-size: 30px;
  animation: moveBg 20s linear infinite;
  pointer-events: none;
}

@keyframes moveBg {
  0% { background-position: 0 0; }
  100% { background-position: 100px 100px; }
}

.container {
  width: 100%;
  max-width: 450px;
  padding: 20px;
  position: relative;
  z-index: 1;
  animation: fadeInUp 0.6s ease;
}

@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(30px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.login-box {
  background: var(--white);
  border-radius: 28px;
  box-shadow: 0 25px 45px rgba(0, 0, 0, 0.2);
  padding: 45px 35px;
  text-align: center;
  border-top: 6px solid var(--primary-yellow);
  transition: all 0.3s ease;
}

.login-box:hover {
  transform: translateY(-5px);
  box-shadow: 0 35px 55px rgba(0, 0, 0, 0.25);
}

.logo {
  width: fit-content;
  max-width: 100%;
  padding: 14px 22px;
  background: var(--white);
  border: 1px solid #E9E7F1;
  border-radius: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 20px;
  box-shadow: 0 10px 20px rgba(45, 24, 89, 0.12);
}

.logo img {
  height: 56px;
  width: auto;
  max-width: 260px;
  object-fit: contain;
}

.built-by {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--gray);
  margin-bottom: 6px;
}

.built-by strong {
  color: var(--primary-purple);
}

h2 {
  color: var(--primary-purple);
  font-size: 28px;
  font-weight: 800;
  margin-bottom: 8px;
}

h2 span {
  color: var(--primary-yellow);
}

.subtitle {
  font-size: 13px;
  color: var(--gray);
  margin-bottom: 30px;
}

.input-group {
  position: relative;
  margin-bottom: 20px;
}

.input-group > i {
  position: absolute;
  left: 15px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--gray);
  font-size: 16px;
}

.input-group input {
  width: 100%;
  padding: 14px 16px 14px 45px;
  border-radius: 14px;
  border: 2px solid #e0e0e0;
  font-size: 14px;
  outline: none;
  transition: all 0.3s ease;
  font-family: 'Poppins', sans-serif;
}

.input-group input:focus {
  border-color: var(--primary-purple);
  box-shadow: 0 0 0 3px rgba(45, 24, 89, 0.1);
}

.toggle-password {
  position: absolute;
  right: 15px;
  left: auto;
  top: 50%;
  transform: translateY(-50%);
  color: var(--gray);
  font-size: 16px;
  cursor: pointer;
  background: none;
  border: none;
  padding: 4px;
  display: flex;
  align-items: center;
}
#togglePasswordIcon{
    margin-left:300px;
}
.toggle-password:hover {
  color: var(--primary-purple);
}

.input-group input[name="password"] {
  padding-right: 45px;
}

button {
  width: 100%;
  padding: 14px;
  border-radius: 14px;
  border: none;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.3s ease;
  font-family: 'Poppins', sans-serif;
  font-size: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
}

.btn-primary {
  background: var(--primary-yellow);
  color: var(--dark);
}

.btn-primary:hover,
.btn-primary:focus {
  background: #D4A70C;
  color: var(--dark);
  transform: translateY(-2px);
}

.forgot-password {
  text-align: right;
  margin-bottom: 20px;
  font-size: 13px;
}

.forgot-password a {
  color: var(--primary-purple);
  text-decoration: none;
  font-weight: 500;
}

.forgot-password a:hover {
  text-decoration: underline;
}

.alert {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background: var(--white);
  border-left: 6px solid var(--danger);
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
  padding: 18px 25px;
  border-radius: 16px;
  text-align: center;
  z-index: 1000;
  width: 90%;
  max-width: 380px;
  font-size: 14px;
  color: var(--danger);
  animation: popIn 0.4s ease forwards;
  display: flex;
  align-items: center;
  gap: 12px;
}

.alert.alert-success {
  border-left-color: var(--success);
  color: var(--success);
}

@keyframes popIn {
  from {
    opacity: 0;
    transform: translate(-50%, -60%) scale(0.8);
  }
  to {
    opacity: 1;
    transform: translate(-50%, -50%) scale(1);
  }
}

.divider {
  margin: 20px 0;
  text-align: center;
  color: var(--gray);
  font-size: 13px;
  position: relative;
}

.divider span {
  background: var(--white);
  padding: 0 10px;
  position: relative;
  z-index: 1;
}

.divider::before {
  content: '';
  position: absolute;
  left: 0;
  top: 50%;
  width: 100%;
  height: 1px;
  background: #ddd;
}

.register-text {
  margin-top: 20px;
  font-size: 13px;
  color: var(--gray);
}

.register-text a {
  color: var(--primary-purple);
  text-decoration: none;
  font-weight: 600;
}

@media (max-width: 480px) {
  .login-box {
    padding: 35px 25px;
  }
  h2 {
    font-size: 24px;
  }
}
</style>
</head>
<body>
<div class="container">
  <div class="login-box">
    <div class="logo">
      <img src="assets/images/curdun-logo1.png" alt="CURDUN ICT">
    </div>
    <p class="built-by">Built by <strong>CURDUN ICT</strong></p>
    <h2><?= htmlspecialchars($login_system_name) ?></h2>
    <p class="subtitle">Welcome to Cargo Management System</p>

    <form method="POST" action="">
      <div class="input-group">
        <i class="fas fa-envelope"></i>
        <input type="email" name="email" placeholder="Email Address" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      <div class="input-group">
        <i class="fas fa-lock"></i>
        <input type="password" name="password" id="password" placeholder="Password" required>
        <button type="button" class="toggle-password" id="togglePassword" aria-label="Show password" tabindex="-1">
          <i class="fas fa-eye" id="togglePasswordIcon"></i>
        </button>
      </div>
      
      <div class="forgot-password">
        <a href="forgot_password.php"><i class="fas fa-key"></i> Forgot Password?</a>
      </div>
      
      <button class="btn-primary" type="submit">
        <i class="fas fa-sign-in-alt"></i> Login
      </button>
    </form>

    <?php if ($adminCount == 0): ?>
      <div class="divider"><span>OR</span></div>
      <div class="register-text">
        <p>No admin account? <a href="register_admin.php">Create Admin Account</a></p>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($error)): ?>
<div class="alert" id="alertBox">
  <i class="fas fa-exclamation-circle"></i>
  <?= htmlspecialchars($error) ?>
</div>
<script>
  setTimeout(function() {
    var alert = document.getElementById('alertBox');
    if(alert) {
      alert.style.transition = 'opacity 0.6s ease';
      alert.style.opacity = '0';
      setTimeout(function() { alert.remove(); }, 600);
    }
  }, 5000);
</script>
<?php endif; ?>

<script>
// Prevent form resubmission on page refresh
if (window.history.replaceState) {
  window.history.replaceState(null, null, window.location.href);
}
</script>

<script>
// Show/hide password toggle
var togglePassword = document.getElementById('togglePassword');
var passwordInput = document.getElementById('password');
var togglePasswordIcon = document.getElementById('togglePasswordIcon');
if (togglePassword && passwordInput && togglePasswordIcon) {
  togglePassword.addEventListener('click', function() {
    var isHidden = passwordInput.getAttribute('type') === 'password';
    passwordInput.setAttribute('type', isHidden ? 'text' : 'password');
    togglePasswordIcon.classList.toggle('fa-eye');
    togglePasswordIcon.classList.toggle('fa-eye-slash');
    togglePassword.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
  });
}
</script>
</body>
</html>