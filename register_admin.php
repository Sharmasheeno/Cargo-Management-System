<?php
/**
 * register_superadmin.php
 *faras cargo - Diiwaangelinta Super Admin (First User)
 * 
 * ✅ Creates Super Admin user with company_id = NULL (system-wide admin)
 * ✅ After successful registration, redirects directly to superadmin dashboard
 * ✅ Super Admin can see and manage ALL companies
 */

session_start();
require_once __DIR__ . '/config/db_connect.php';

$message = '';
$success = false;

// Check if any super admin exists (using correct column 'role_type')
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_type = 'superadmin'");
    $stmt->execute();
    $hasSuperAdmin = $stmt->fetchColumn() > 0;
} catch (PDOException $e) {
    $message = "❌ Khalad ku yimid xidhiidhka database-ka: " . $e->getMessage();
    $hasSuperAdmin = true;
}

// Handle registration - ONLY if no super admin exists
if (!$hasSuperAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $photoPath = null;

    // Password validation: at least 6 characters
    $passwordValid = strlen($password) >= 6;
    
    if (empty($full_name)) {
        $message = "❌ Fadlan geli magaca koowaad iyo magaca dambe.";
    } elseif (strlen($full_name) < 3) {
        $message = "❌ Magaca waa inuu ka kooban yahay ugu yaraan 3 xaraf.";
    } elseif (empty($email)) {
        $message = "❌ Fadlan geli email address.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "❌ Fadlan geli email sax ah.";
    } elseif (empty($phone)) {
        $message = "❌ Fadlan geli lambarka taleefanka.";
    } elseif (empty($password)) {
        $message = "❌ Fadlan geli password.";
    } elseif (!$passwordValid) {
        $message = "❌ Password-ku waa inuu ka kooban yahay ugu yaraan 6 xaraf.";
    } else {
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) {
            $message = "❌ Email-kan waxaa isticmaalayaa qof kale!";
        } else {
            // Check if phone already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE phone = ?");
            $stmt->execute([$phone]);
            if ($stmt->fetchColumn() > 0) {
                $message = "❌ Lambarka taleefankan waxaa isticmaalayaa qof kale!";
            } else {
                // Upload Profile Photo
                if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/uploads/profiles/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    $maxSize = 5 * 1024 * 1024; // 5MB
                    
                    if ($_FILES['profile_photo']['size'] > $maxSize) {
                        $message = "❌ Sawirka waa inuusan ka weynayn 5MB!";
                    } elseif (in_array($ext, $allowed)) {
                        $photoName = 'superadmin_' . time() . '_' . uniqid() . '.' . $ext;
                        $targetPath = $uploadDir . $photoName;
                        
                        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $targetPath)) {
                            $photoPath = 'uploads/profiles/' . $photoName;
                        }
                    } else {
                        $message = "❌ Nooca sawirka lama aqbali karo! Aqbal: JPG, PNG, GIF, WEBP";
                    }
                }
                
                // Only proceed if no photo error message was set
                if (empty($message)) {
                    // Hash password
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    
                    try {
                        // Insert Super Admin into users table with tenant_id = NULL
                        // Super Admin is system-wide, not tied to any specific tenant
                        $sql = "INSERT INTO users (
                                    tenant_id, email, password_hash, full_name, phone, profile_image,
                                    role_type, is_active, created_at, last_login
                                ) VALUES (
                                    NULL, ?, ?, ?, ?, ?,
                                    'superadmin', 1, NOW(), NULL
                                )";
                        $stmt = $pdo->prepare($sql);
                        
                        if ($stmt->execute([$email, $hashedPassword, $full_name, $phone, $photoPath])) {
                            $userId = $pdo->lastInsertId();
                            
                            // Set session for immediate login
                            $_SESSION['user_id'] = $userId;
                            $_SESSION['full_name'] = $full_name;
                            $_SESSION['email'] = $email;
                            $_SESSION['phone'] = $phone;
                            $_SESSION['role'] = 'superadmin';
                            $_SESSION['tenant_id'] = null;
                            $_SESSION['staff_level'] = null;
                            $_SESSION['profile_image'] = $photoPath;
                            $_SESSION['is_active'] = 1;
                            
                            $success = true;
                            $message = "✅ Super Admin si guul leh ayaa loo diiwaan geliyay! Hadda waxaa laguu diri doonaa dashboard-ka.";
                        } else {
                            $message = "❌ Khalad ku yimid diiwaangelinta. Fadlan isku day mar kale.";
                        }
                    } catch (PDOException $e) {
                        $message = "❌ Khalad database ah: " . $e->getMessage();
                        error_log("Super Admin Registration Error: " . $e->getMessage());
                    }
                }
            }
        }
    }
}

// If super admin already exists and not in success state, redirect to login
if ($hasSuperAdmin && !$success && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diiwaangelinta Super Admin | Cargo Management System</title>
    <link rel="icon" type="image/png" href="assets/images/curdun-favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/curdun-theme.css">
    <style>
        :root {
            --curdun-violet: #2D1859;
            --curdun-yellow: #F5C410;
            --curdun-violet-light: #4B2C85;
            --curdun-yellow-dark: #D4A70C;
            --curdun-white: #FFFFFF;
            --curdun-dark: #1B1233;
            --curdun-gray: #69647A;
            --curdun-danger: #B42318;
            --curdun-success: #0F7A3A;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: var(--curdun-violet);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }
        
        .container {
            display: flex;
            width: 1000px;
            max-width: 100%;
            background: var(--curdun-white);
            border-radius: 24px;
            box-shadow: 0 25px 45px rgba(0, 0, 0, 0.2);
            overflow: hidden;
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
        
        .left-panel {
            background: var(--curdun-violet);
            color: var(--curdun-white);
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 50px 30px;
            text-align: center;
        }
        
        .logo-icon {
            width: fit-content;
            max-width: 100%;
            padding: 14px 22px;
            background: white;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }

        .logo-img {
            height: 52px;
            width: auto;
            max-width: 230px;
            object-fit: contain;
        }

        .built-by {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 8px;
        }

        .built-by strong {
            color: var(--curdun-yellow);
        }

        .left-panel h1 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .left-panel h1 span {
            color: var(--curdun-yellow);
        }
        
        .left-panel p {
            font-size: 13px;
            opacity: 0.85;
            line-height: 1.6;
        }
        
        .right-panel {
            flex: 1.2;
            padding: 45px 40px;
            background: var(--curdun-white);
        }
        
        .right-panel h2 {
            font-size: 28px;
            font-weight: 700;
            color: var(--curdun-violet);
            margin-bottom: 8px;
        }
        
        .right-panel h3 {
            font-size: 14px;
            color: var(--curdun-gray);
            margin-bottom: 25px;
            font-weight: 400;
        }
        
        .error-msg {
            background: #FEF0EE;
            color: var(--curdun-danger);
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 500;
            font-size: 13px;
            border-left: 4px solid var(--curdun-danger);
        }
        
        .success-msg {
            background: #EEFBF3;
            color: var(--curdun-success);
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 500;
            font-size: 13px;
            border-left: 4px solid var(--curdun-success);
        }
        
        form {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        input[type="text"],
        input[type="email"],
        input[type="tel"],
        input[type="password"],
        input[type="file"] {
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
            width: 100%;
        }
        
        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            padding-right: 45px;
        }

        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--curdun-gray);
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
        }

        .toggle-password:hover {
            color: var(--curdun-violet);
        }

        input:focus {
            border-color: var(--curdun-violet);
            outline: none;
            box-shadow: 0 0 0 3px rgba(45, 24, 89, 0.12);
        }
        
        .password-meter {
            margin-top: -5px;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .password-strength {
            height: 6px;
            flex: 1;
            border-radius: 4px;
            background: #e0e0e0;
            overflow: hidden;
        }
        
        .password-strength div {
            height: 100%;
            transition: width 0.3s ease;
        }
        
        #strengthLabel {
            font-size: 12px;
            font-weight: 600;
            min-width: 60px;
        }
        
        .upload-section {
            margin: 5px 0;
        }
        
        .upload-section label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--curdun-dark);
            margin-bottom: 8px;
        }
        
        .upload-section label i {
            color: var(--curdun-violet);
            margin-right: 8px;
        }
        
        .upload-section small {
            display: block;
            font-size: 11px;
            color: var(--curdun-gray);
            margin-top: 5px;
        }
        
        button {
            background: var(--curdun-yellow);
            color: var(--curdun-dark);
            border: none;
            padding: 14px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 5px;
        }

        button:hover,
        button:focus {
            background: var(--curdun-yellow-dark);
            color: var(--curdun-dark);
            transform: translateY(-2px);
        }
        
        /* Overlay & Success Modal */
        .overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 999;
        }
        
        .alert-center {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 35px;
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            text-align: center;
            width: 340px;
            z-index: 1000;
            animation: popIn 0.4s ease;
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
        
        .circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 4px solid var(--curdun-success);
            margin: 0 auto 20px;
            position: relative;
        }
        
        .checkmark {
            width: 25px;
            height: 50px;
            border-right: 4px solid var(--curdun-success);
            border-bottom: 4px solid var(--curdun-success);
            position: absolute;
            left: 28px;
            top: 10px;
            transform: rotate(45deg) scale(0);
            transition: transform 0.6s ease;
        }
        
        .circle.animate .checkmark {
            transform: rotate(45deg) scale(1);
        }
        
        .alert-center p {
            font-size: 14px;
            color: var(--curdun-dark);
        }
        
        .redirect-text {
            font-size: 12px;
            color: var(--curdun-gray);
            margin-top: 10px;
        }
        
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
                margin: 20px;
            }
            
            .left-panel {
                padding: 30px 20px;
            }
            
            .right-panel {
                padding: 30px 25px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .logo-icon {
                width: 80px;
                height: 80px;
            }
            
            .logo-icon i {
                font-size: 40px;
            }
            
            .right-panel h2 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="left-panel">
        <div class="logo-icon">
            <img src="assets/images/curdun-logo1.png" alt="CURDUN ICT" class="logo-img">
        </div>
        <p class="built-by">Built by <strong>CURDUN ICT</strong></p>
        <h1>Cargo <span>Management System</span></h1>
        <p>Nidaamka Maareynta Saadka<br><strong>Diiwaangelinta Super Admin</strong></p>
    </div>

    <div class="right-panel">
        <h2>Diiwaan Gelinta</h2>
        <h3>Samee Akoonka Maamulaha Koowaad</h3>

        <?php if (!empty($message) && !$success): ?>
            <div class="error-msg">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success-msg">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if (!$hasSuperAdmin && !$success): ?>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-grid">
                    <input type="text" name="full_name" placeholder="Magaca koowaad & Magaca dambe" required 
                           value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                    <input type="email" name="email" placeholder="Email Address" required 
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    <input type="tel" name="phone" placeholder="Lambarka Taleefanka (e.g., 0612345678)" required 
                           value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                    <div class="password-wrapper">
                        <input type="password" name="password" id="password" placeholder="Password (6+ characters)" required>
                        <button type="button" class="toggle-password" id="togglePassword" aria-label="Show password" tabindex="-1">
                            <i class="fas fa-eye" id="togglePasswordIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="password-meter">
                    <div class="password-strength"><div id="strengthBar"></div></div>
                    <span id="strengthLabel">Daciif</span>
                </div>

                <div class="upload-section">
                    <label><i class="fas fa-camera"></i> Sawirka Profile-ka</label>
                    <input type="file" name="profile_photo" id="profile_photo" accept="image/jpeg,image/png,image/gif,image/webp">
                    <small>Noocyada la aqbali karo: JPG, PNG, GIF, WEBP (5MB ugu badnaan)</small>
                </div>

                <button type="submit" name="submit">
                    <i class="fas fa-user-plus"></i> Diiwaan Gelinta
                </button>
            </form>
        <?php endif; ?>
        
        <?php if ($hasSuperAdmin && !$success && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
            <div class="error-msg">
                <i class="fas fa-info-circle"></i> Nidaamka waxaa ugu horayn loo diiwaan geliyay. Fadlan u gudub <a href="login.php" style="color: var(--curdun-violet); font-weight: 600;">bogga galitaanka</a>.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($success): ?>
<div class="overlay"></div>
<div class="alert-center success">
    <div class="circle"><div class="checkmark"></div></div>
    <p><i class="fas fa-check-circle" style="color: var(--curdun-success);"></i> Diiwaangelintu waa guul!</p>
    <p style="margin-top: 10px;">Hadda waxaa laguu diri doonaa Dashboard-ka...</p>
    <p class="redirect-text"><i class="fas fa-spinner fa-spin"></i> 3 seconds...</p>
</div>
<script>
    setTimeout(() => {
        document.querySelector('.circle').classList.add('animate');
    }, 100);
    setTimeout(() => {
        window.location.href = "superadmin/dashboard.php";
    }, 3000);
</script>
<?php endif; ?>

<script>
const password = document.getElementById('password');
const bar = document.getElementById('strengthBar');
const label = document.getElementById('strengthLabel');

const togglePasswordBtn = document.getElementById('togglePassword');
const togglePasswordIcon = document.getElementById('togglePasswordIcon');
if (togglePasswordBtn && password && togglePasswordIcon) {
    togglePasswordBtn.addEventListener('click', () => {
        const isHidden = password.getAttribute('type') === 'password';
        password.setAttribute('type', isHidden ? 'text' : 'password');
        togglePasswordIcon.classList.toggle('fa-eye');
        togglePasswordIcon.classList.toggle('fa-eye-slash');
        togglePasswordBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    });
}

if (password && bar && label) {
    password.addEventListener('input', () => {
        const val = password.value;
        let strength = 0;
        if (val.length >= 6) strength++;
        if (val.length >= 8) strength++;
        if (/[A-Z]/.test(val)) strength++;
        if (/[0-9]/.test(val)) strength++;
        if (/[@$!%*#?&]/.test(val)) strength++;
        
        const width = Math.min((strength / 5) * 100, 100);
        bar.style.width = width + '%';
        
        if (width <= 30) {
            bar.style.background = '#B42318';
            label.textContent = 'Daciif';
            label.style.color = '#B42318';
        } else if (width <= 60) {
            bar.style.background = '#FFB400';
            label.textContent = 'Dhexdhexaad';
            label.style.color = '#FFB400';
        } else {
            bar.style.background = '#0F7A3A';
            label.textContent = 'Xoog Badan';
            label.style.color = '#0F7A3A';
        }
    });
}
</script>

</body>
</html>