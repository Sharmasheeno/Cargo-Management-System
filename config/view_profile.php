<?php
// config/view_profile.php
// View profile page forfaras cargo

if (session_status() === PHP_SESSION_NONE) session_start();

// Load functions first
require_once(__DIR__ . '/functions.php');
require_once(__DIR__ . '/db_connect.php');

// Check if user is logged in
requireLogin();

$user_id = $_SESSION['user_id'];
$role = strtolower($_SESSION['role'] ?? 'guest');

// Convert company_admin to tenant_admin
if ($role === 'company_admin') {
    $role = 'tenant_admin';
    $_SESSION['role'] = 'tenant_admin';
}

// Fetch user data from users table
$stmt = $pdo->prepare("
    SELECT u.*, t.name as tenant_name, t.code as tenant_code
    FROM users u
    LEFT JOIN tenants t ON u.tenant_id = t.id
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    echo "<div style='text-align:center; padding:50px;'>User not found. <a href='../login.php'>Go to Login</a></div>";
    exit;
}

// Get user's role and staff assignment
$stmt2 = $pdo->prepare("
    SELECT 
        r.name as role_name,
        r.display_name as role_display,
        r.level as role_level,
        sa.status as assignment_status,
        sa.start_date,
        sa.salary
    FROM staff_assignments sa
    JOIN roles r ON sa.role_id = r.id
    WHERE sa.user_id = ? AND sa.status = 'active'
    ORDER BY sa.start_date DESC
    LIMIT 1
");
$stmt2->execute([$user_id]);
$role_data = $stmt2->fetch(PDO::FETCH_ASSOC);

// Get user statistics
$stats = [];

// Total assignments for this user
$stmt3 = $pdo->prepare("
    SELECT COUNT(*) as count FROM assignments WHERE assigned_to_driver_id = ? OR assigned_to_loader_id = ?
");
$stmt3->execute([$user_id, $user_id]);
$stats['total_assignments'] = $stmt3->fetch(PDO::FETCH_ASSOC)['count'];

// Completed assignments
$stmt4 = $pdo->prepare("
    SELECT COUNT(*) as count FROM assignments 
    WHERE (assigned_to_driver_id = ? OR assigned_to_loader_id = ?) 
    AND status = 'completed'
");
$stmt4->execute([$user_id, $user_id]);
$stats['completed_assignments'] = $stmt4->fetch(PDO::FETCH_ASSOC)['count'];

$stats['pending_assignments'] = $stats['total_assignments'] - $stats['completed_assignments'];

// Total trips
try {
    $stmt5 = $pdo->prepare("
        SELECT COUNT(*) as count FROM trucking_trips 
        WHERE driver_id = ? OR loader_id = ?
    ");
    $stmt5->execute([$user_id, $user_id]);
    $stats['total_trips'] = $stmt5->fetch(PDO::FETCH_ASSOC)['count'];
} catch(PDOException $e) {
    $stats['total_trips'] = 0;
}

// Total containers
try {
    $stmt6 = $pdo->prepare("
        SELECT COUNT(*) as count FROM containers WHERE tenant_id = ?
    ");
    $stmt6->execute([$profile['tenant_id'] ?? 0]);
    $stats['total_containers'] = $stmt6->fetch(PDO::FETCH_ASSOC)['count'];
} catch(PDOException $e) {
    $stats['total_containers'] = 0;
}

// Total customers
try {
    $stmt7 = $pdo->prepare("
        SELECT COUNT(*) as count FROM customers WHERE tenant_id = ?
    ");
    $stmt7->execute([$profile['tenant_id'] ?? 0]);
    $stats['total_customers'] = $stmt7->fetch(PDO::FETCH_ASSOC)['count'];
} catch(PDOException $e) {
    $stats['total_customers'] = 0;
}

// ==============================================
// PROFILE PHOTO - READ FROM uploads/profiles DIRECTORY
// ==============================================

$profile_image_path = null;
$profiles_dir = __DIR__ . '/../uploads/profiles/';

// Check if profile image exists in session first
$user_profile_image = $profile['profile_image'] ?? null;

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
        $full_path = __DIR__ . '/../' . $path;
        if (file_exists($full_path)) {
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

// If still default, try to find user-specific image
if ($profile_image_path == '../uploads/profiles/default.png') {
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    // METHOD 1: user_{id}.extension
    foreach ($allowed_extensions as $ext) {
        $user_image = $profiles_dir . 'user_' . $user_id . '.' . $ext;
        if (file_exists($user_image)) {
            $profile_image_path = '../uploads/profiles/user_' . $user_id . '.' . $ext;
            break;
        }
    }
    
    // METHOD 2: Any image containing user_id
    if ($profile_image_path == '../uploads/profiles/default.png') {
        $files = glob($profiles_dir . '*' . $user_id . '*.*');
        if (!empty($files)) {
            $profile_image_path = '../uploads/profiles/' . basename($files[0]);
        }
    }
    
    // METHOD 3: Email prefix image
    if ($profile_image_path == '../uploads/profiles/default.png' && !empty($profile['email'])) {
        $email_prefix = explode('@', $profile['email'])[0];
        foreach ($allowed_extensions as $ext) {
            $email_image = $profiles_dir . $email_prefix . '.' . $ext;
            if (file_exists($email_image)) {
                $profile_image_path = '../uploads/profiles/' . $email_prefix . '.' . $ext;
                break;
            }
        }
    }
    
    // METHOD 4: Username image
    if ($profile_image_path == '../uploads/profiles/default.png' && !empty($profile['username'])) {
        foreach ($allowed_extensions as $ext) {
            $username_image = $profiles_dir . $profile['username'] . '.' . $ext;
            if (file_exists($username_image)) {
                $profile_image_path = '../uploads/profiles/' . $profile['username'] . '.' . $ext;
                break;
            }
        }
    }
}

$photo = $profile_image_path;

// Last login
$last_login = $profile['last_login'] ?? $profile['created_at'] ?? null;

// Role display name
$role_display = ucfirst(str_replace('_', ' ', $role));
if ($role === 'superadmin') {
    $role_display = 'Super Administrator';
} elseif ($role === 'tenant_admin') {
    $role_display = 'Tenant Administrator';
} elseif ($role === 'staff') {
    $role_display = $role_data['role_display'] ?? 'Staff Member';
} elseif ($role === 'customer') {
    $role_display = 'Customer';
}

$full_name = $profile['full_name'] ?? $profile['username'] ?? 'User';
$first_name = explode(' ', trim($full_name))[0];
?>

<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --curdun-violet: #2D1859;
            --curdun-yellow: #F5C410;
            --curdun-violet-light: #4B2C85;
            --curdun-white: #ffffff;
            --curdun-bg: #F8F6F9;
            --curdun-dark-gray: #2D2D2D;
            --curdun-medium-gray: #4a5568;
            --curdun-light-gray: #E8E4EC;
            --curdun-red: #e74c3c;
            --curdun-green: #27ae60;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--curdun-bg) 0%, #e9ecef 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .profile-container {
            background: var(--curdun-white);
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(82, 0, 102, 0.15);
            overflow: hidden;
            width: 100%;
            max-width: 1100px;
            display: grid;
            grid-template-columns: 350px 1fr;
            min-height: 600px;
        }
        .profile-sidebar {
            background: linear-gradient(135deg, var(--curdun-violet) 0%, var(--curdun-violet-light) 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .profile-image {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 5px solid var(--curdun-yellow);
            object-fit: cover;
            margin-bottom: 20px;
            position: relative;
            z-index: 2;
            background: white;
        }
        .user-name { font-size: 24px; font-weight: 700; margin-bottom: 5px; z-index: 2; position: relative; }
        .user-role {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 20px;
            background: rgba(255,255,255,0.2);
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            z-index: 2;
            position: relative;
        }
        .user-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 30px;
            width: 100%;
            z-index: 2;
            position: relative;
        }
        .stat-item {
            background: rgba(255,255,255,0.15);
            padding: 12px;
            border-radius: 12px;
            backdrop-filter: blur(10px);
        }
        .stat-value { font-size: 22px; font-weight: 700; display: block; }
        .stat-label { font-size: 11px; opacity: 0.8; display: block; margin-top: 5px; }
        .profile-content { padding: 40px; overflow-y: auto; max-height: 650px; }
        .content-section { margin-bottom: 30px; }
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--curdun-violet);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--curdun-light-gray);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-title i { color: var(--curdun-yellow); }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
            padding: 12px;
            background: var(--curdun-bg);
            border-radius: 12px;
        }
        .info-label { font-size: 11px; font-weight: 600; color: var(--curdun-medium-gray); text-transform: uppercase; }
        .info-value { font-size: 15px; color: var(--curdun-dark-gray); font-weight: 500; }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-active { background: rgba(39,174,96,0.1); color: var(--curdun-green); }
        .status-inactive { background: rgba(231,76,60,0.1); color: var(--curdun-red); }
        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
        }
        .btn-primary { background: var(--curdun-violet); color: white; }
        .btn-primary:hover { background: var(--curdun-violet-light); }
        .btn-secondary { background: var(--curdun-yellow); color: var(--curdun-violet); }
        .btn-outline { background: transparent; border: 2px solid var(--curdun-violet); color: var(--curdun-violet); }
        .btn-outline:hover { background: var(--curdun-violet); color: white; }
        .company-card {
            background: linear-gradient(135deg, var(--curdun-yellow) 0%, #e6c800 100%);
            padding: 20px;
            border-radius: 16px;
            margin-top: 20px;
            color: var(--curdun-violet);
        }
        .company-card h3 { margin-bottom: 15px; display: flex; align-items: center; gap: 10px; }
        .company-detail {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid rgba(82,0,102,0.1);
        }
        .company-detail:last-child { border-bottom: none; }
        .no-photo-message {
            background: rgba(255,255,255,0.9);
            color: var(--curdun-violet);
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 11px;
            margin-top: 10px;
        }
        @media (max-width: 768px) {
            .profile-container { grid-template-columns: 1fr; }
            .profile-sidebar { padding: 30px 20px; }
            .profile-content { padding: 30px 20px; }
            .info-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="profile-container">
    <div class="profile-sidebar">
        <img src="<?= htmlspecialchars($photo) ?>?t=<?= time() ?>" alt="Profile" class="profile-image" onerror="this.src='../uploads/profiles/default.png'">
        <h1 class="user-name"><?= htmlspecialchars($first_name) ?></h1>
        <p class="user-role"><i class="fas fa-briefcase"></i> <?= htmlspecialchars($role_display) ?></p>
        
        <div class="user-stats">
            <div class="stat-item"><span class="stat-value"><?= $stats['total_assignments'] ?></span><span class="stat-label">Total Tasks</span></div>
            <div class="stat-item"><span class="stat-value"><?= $stats['completed_assignments'] ?></span><span class="stat-label">Completed</span></div>
            <div class="stat-item"><span class="stat-value"><?= $stats['pending_assignments'] ?></span><span class="stat-label">Pending</span></div>
            <div class="stat-item"><span class="stat-value"><?= $stats['total_trips'] ?></span><span class="stat-label">Total Trips</span></div>
        </div>
        
        <?php if ($photo == '../uploads/profiles/default.png') : ?>
            <div class="no-photo-message"><i class="fas fa-camera"></i> No profile photo</div>
        <?php endif; ?>
    </div>

    <div class="profile-content">
        <div class="content-section">
            <h2 class="section-title"><i class="fas fa-user-circle"></i> Personal Information</h2>
            <div class="info-grid">
                <div class="info-item"><span class="info-label">Full Name</span><span class="info-value"><?= htmlspecialchars($full_name) ?></span></div>
                <div class="info-item"><span class="info-label">Email</span><span class="info-value"><?= htmlspecialchars($profile['email'] ?? '-') ?></span></div>
                <div class="info-item"><span class="info-label">Phone</span><span class="info-value"><?= htmlspecialchars($profile['phone'] ?? '-') ?></span></div>
                <div class="info-item"><span class="info-label">Username</span><span class="info-value"><?= htmlspecialchars($profile['username'] ?? '-') ?></span></div>
                <div class="info-item"><span class="info-label">Role</span><span class="info-value"><?= htmlspecialchars($role_display) ?></span></div>
                <div class="info-item"><span class="info-label">Role Level</span><span class="info-value"><?= htmlspecialchars($role_data['role_level'] ?? 'N/A') ?></span></div>
            </div>
        </div>

        <div class="content-section">
            <h2 class="section-title"><i class="fas fa-shield-alt"></i> Account Information</h2>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Account Status</span>
                    <span class="info-value">
                        <?php if($profile['is_active'] == 1): ?>
                            <span class="status-badge status-active"><i class="fas fa-check-circle"></i> Active</span>
                        <?php else: ?>
                            <span class="status-badge status-inactive"><i class="fas fa-times-circle"></i> Inactive</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="info-item"><span class="info-label">Last Login</span><span class="info-value"><?= formatDateTime($last_login) ?></span></div>
                <div class="info-item"><span class="info-label">Member Since</span><span class="info-value"><?= formatDateTime($profile['created_at'] ?? '') ?></span></div>
                <div class="info-item"><span class="info-label">Last Updated</span><span class="info-value"><?= formatDateTime($profile['updated_at'] ?? '') ?></span></div>
            </div>
        </div>

        <?php if($profile['tenant_name']): ?>
        <div class="company-card">
            <h3><i class="fas fa-building"></i> Company Information</h3>
            <div class="company-detail"><span>Company:</span><strong><?= htmlspecialchars($profile['tenant_name']) ?></strong></div>
            <div class="company-detail"><span>Code:</span><strong><?= htmlspecialchars($profile['tenant_code'] ?? 'N/A') ?></strong></div>
            <?php if($role_data): ?>
            <div class="company-detail"><span>Position:</span><strong><?= htmlspecialchars($role_data['role_display'] ?? $role_data['role_name'] ?? 'N/A') ?></strong></div>
            <?php if($role_data['salary'] > 0): ?>
            <div class="company-detail"><span>Salary:</span><strong><?= number_format($role_data['salary'], 2) ?> USD</strong></div>
            <?php endif; ?>
            <?php endif; ?>
            <div class="company-detail"><span>Total Containers:</span><strong><?= $stats['total_containers'] ?></strong></div>
            <div class="company-detail"><span>Total Customers:</span><strong><?= $stats['total_customers'] ?></strong></div>
        </div>
        <?php endif; ?>

        <div class="action-buttons">
            <a href="javascript:void(0)" onclick="closeOverlay()" class="btn btn-outline"><i class="fas fa-times"></i> Close</a>
        </div>
    </div>
</div>

<script>
function closeOverlay() {
    if (window.parent && window.parent.document.getElementById('overlayView')) {
        window.parent.document.getElementById('overlayView').style.display = 'none';
        window.parent.document.body.style.overflow = 'auto';
    }
}
document.querySelectorAll('.btn').forEach(btn => {
    btn.addEventListener('mouseenter', function() { this.style.transform = 'translateY(-2px)'; });
    btn.addEventListener('mouseleave', function() { this.style.transform = 'translateY(0)'; });
});
</script>
</body>
</html>