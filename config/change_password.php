<?php
// change_password.php
// Profile settings page forfaras cargo

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Correct path to db_connect.php (from config folder)
require_once __DIR__ . '/db_connect.php';

// Check if user is logged in
if (empty($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch current user data from users table (FIXED: was isticmaalayaasha)
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

// Update session data (FIXED: column names)
$_SESSION['user_name'] = $user['full_name'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_phone'] = $user['phone'];

$message = '';
$type = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ============================================
    // PROFILE PHOTO UPDATE
    // ============================================
    if (isset($_POST['update_photo'])) {
        if (!empty($_FILES['profile_photo']['name'])) {
            $upload_dir = __DIR__ . '/../uploads/profiles/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

            $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (!in_array($ext, $allowed_ext)) {
                $message = "❌ Nooca faylka qalad ah. Fadlan soo gali sawirka nooca JPG, PNG, ama GIF.";
                $type = "error";
            } elseif ($_FILES['profile_photo']['size'] > 5 * 1024 * 1024) {
                $message = "❌ Cabbirka faylku way weyn tahay. Cabbirka ugu badan waa 5MB.";
                $type = "error";
            } else {
                $new_name = 'user_' . $user_id . '_' . time() . '.' . $ext;
                $photo_path = 'uploads/profiles/' . $new_name;

                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], __DIR__ . '/../' . $photo_path)) {
                    // Delete old photo if exists
                    $old_photo = $user['profile_image'] ?? '';
                    if ($old_photo && $old_photo !== 'uploads/profiles/default.png' && file_exists(__DIR__ . '/../' . $old_photo)) {
                        unlink(__DIR__ . '/../' . $old_photo);
                    }

                    // FIXED: column name sawir_profile -> profile_image
                    $stmt = $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                    if ($stmt->execute([$photo_path, $user_id])) {
                        $_SESSION['user_photo'] = $photo_path;
                        $message = "✅ Sawirka profile-ka si guul leh ayaa loo cusboonaysiiyay!";
                        $type = "success";
                        $user['profile_image'] = $photo_path;
                    } else {
                        $message = "❌ Khalad database ah. Fadlan isku day mar kale.";
                        $type = "error";
                    }
                } else {
                    $message = "❌ Soo gelinta faylku way fashilantay. Fadlan isku day mar kale.";
                    $type = "error";
                }
            }
        } else {
            $message = "⚠️ Fadlan xullo sawir si aad u soo geliso!";
            $type = "error";
        }
    }

    // ============================================
    // PROFILE INFO UPDATE
    // ============================================
    if (isset($_POST['update_info'])) {
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone_number']);

        if ($email === '') {
            $message = "⚠️ Email waa lama huraan!";
            $type = "error";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "❌ Fadlan geli email sax ah!";
            $type = "error";
        } else {
            // Check if email already exists for another user
            $check_stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check_stmt->execute([$email, $user_id]);
            
            if ($check_stmt->fetch()) {
                $message = "❌ Email-kan waxaa isticmaalayaa qof kale!";
                $type = "error";
            } else {
                // Check if phone already exists for another user
                if (!empty($phone)) {
                    $check_phone = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND id != ?");
                    $check_phone->execute([$phone, $user_id]);
                    if ($check_phone->fetch()) {
                        $message = "❌ Lambarka taleefanka waxaa isticmaalayaa qof kale!";
                        $type = "error";
                        $phone = $user['phone'];
                    }
                }
                
                if (empty($message)) {
                    // FIXED: column names taleefan -> phone
                    $stmt = $pdo->prepare("UPDATE users SET email = ?, phone = ? WHERE id = ?");
                    if ($stmt->execute([$email, $phone, $user_id])) {
                        $_SESSION['user_email'] = $email;
                        $_SESSION['user_phone'] = $phone;
                        $user['email'] = $email;
                        $user['phone'] = $phone;
                        $message = "✅ Macluumaadka profile-ka si guul leh ayaa loo cusboonaysiiyay!";
                        $type = "success";
                    } else {
                        $message = "❌ Khalad database ah. Fadlan isku day mar kale.";
                        $type = "error";
                    }
                }
            }
        }
    }

    // ============================================
    // PASSWORD CHANGE
    // ============================================
    if (isset($_POST['change_password'])) {
        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        // Verify current password
        if (!password_verify($old, $user['password_hash'])) {
            $message = "❌ Password-ka hadda aad gashay waa qalad!";
            $type = 'error';
        } elseif ($new !== $confirm) {
            $message = "⚠️ Password-ka cusub isma eka!";
            $type = 'error';
        } elseif (!preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[@$!%*#?&]).{8,}$/', $new)) {
            $message = "⚠️ Password-ku waa inuu ka kooban yahay ugu yaraan 8 xaraf oo ay ku jiraan:<br>
                        - Hal xaraf weyn (A–Z)<br>
                        - Hal xaraf yar (a–z)<br>
                        - Hal lambar (0–9)<br>
                        - Hal calaamad gaar ah (@, $, !, %, &)";
            $type = 'error';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            if ($stmt->execute([$hash, $user_id])) {
                $message = "✅ Password-ka si guul leh ayaa loo beddelay! Fadlan mar kale soo gal.";
                $type = 'success';
                echo '<script>
                    setTimeout(function() {
                        window.location.href = "../logout.php";
                    }, 3000);
                </script>';
            } else {
                $message = "❌ Khalad database ah. Fadlan isku day mar kale.";
                $type = 'error';
            }
        }
    }
}

// Refresh user data after updates
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Profile photo path - FIXED: column name sawir_profile -> profile_image
$photo = !empty($user['profile_image']) && file_exists(__DIR__ . '/../' . $user['profile_image'])
    ? "../" . htmlspecialchars($user['profile_image'], ENT_QUOTES, 'UTF-8')
    : "../uploads/profiles/default.png";
?>

<!DOCTYPE html>
<html lang="so">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dejinta Profile-ka | Cargo Management System</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --curdun-violet: #2D1859;
    --curdun-yellow: #F5C410;
    --curdun-violet-light: #4B2C85;
    --curdun-yellow-dark: #D4A70C;
    --curdun-white: #FFFFFF;
    --curdun-bg: #F8F6F9;
    --curdun-red: #B42318;
    --curdun-amber: #FFB400;
    --curdun-dark-gray: #2D2D2D;
    --curdun-gray-light: #E8E4EC;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: var(--curdun-bg);
    margin: 0;
    padding: 40px 20px;
    color: var(--curdun-dark-gray);
    line-height: 1.6;
    min-height: 100vh;
}

.main-wrapper {
    display: flex;
    flex-direction: column;
    gap: 30px;
    align-items: center;
    justify-content: center;
    width: 100%;
    max-width: 1200px;
    margin: 0 auto;
}

.row-top {
    display: flex;
    justify-content: center;
    align-items: stretch;
    gap: 30px;
    flex-wrap: wrap;
    width: 100%;
}

.row-bottom {
    display: flex;
    justify-content: center;
    width: 100%;
}

.card {
    background: var(--curdun-white);
    padding: 30px;
    border-radius: 16px;
    box-shadow: 0 10px 25px rgba(82, 0, 102, 0.08);
    transition: all 0.3s ease;
    flex: 1 1 420px;
    max-width: 500px;
    border: 1px solid var(--curdun-gray-light);
    position: relative;
}

.card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--curdun-violet), var(--curdun-yellow));
    border-radius: 16px 16px 0 0;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 20px 40px rgba(82, 0, 102, 0.15);
}

.card h2 {
    text-align: center;
    color: var(--curdun-dark-gray);
    margin-bottom: 25px;
    font-size: 22px;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.card h2 i {
    color: var(--curdun-violet);
    font-size: 24px;
}

.photo-container {
    text-align: center;
    margin-bottom: 25px;
    position: relative;
}

.profile-photo {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 4px solid var(--curdun-violet);
    object-fit: cover;
    display: block;
    margin: 0 auto 15px auto;
    transition: all 0.3s ease;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    background: var(--curdun-bg);
}

.profile-photo:hover {
    transform: scale(1.05);
    border-color: var(--curdun-yellow);
}

.file-input-wrapper {
    position: relative;
    display: inline-block;
    width: 100%;
    margin-bottom: 10px;
}

.file-input-wrapper input[type="file"] {
    position: absolute;
    left: 0;
    top: 0;
    opacity: 0;
    width: 100%;
    height: 100%;
    cursor: pointer;
}

.file-input-label {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 20px;
    background: var(--curdun-violet);
    color: var(--curdun-white);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
    font-weight: 600;
    font-size: 14px;
    width: 100%;
}

.file-input-label:hover {
    background: var(--curdun-yellow);
    color: var(--curdun-violet);
    transform: translateY(-2px);
}

.file-input-label i {
    font-size: 16px;
}

form {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.input-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.input-label {
    font-size: 14px;
    font-weight: 600;
    color: #666666;
    display: flex;
    align-items: center;
    gap: 6px;
}

.input-label i {
    color: var(--curdun-violet);
    font-size: 14px;
    width: 16px;
}

input {
    padding: 12px 16px;
    border: 2px solid var(--curdun-gray-light);
    border-radius: 8px;
    font-size: 15px;
    transition: all 0.3s ease;
    width: 100%;
    background: var(--curdun-white);
    font-family: inherit;
}

input:focus {
    border-color: var(--curdun-violet);
    box-shadow: 0 0 0 3px rgba(82, 0, 102, 0.1);
    outline: none;
}

input[readonly] {
    background: var(--curdun-bg);
    cursor: not-allowed;
    color: #666666;
    border-color: var(--curdun-gray-light);
}

button {
    background: var(--curdun-violet);
    color: var(--curdun-white);
    border: none;
    padding: 14px 0;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    font-size: 16px;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 10px;
    font-family: inherit;
}

button:hover {
    background: var(--curdun-yellow);
    color: var(--curdun-violet);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(82, 0, 102, 0.2);
}

button:active {
    transform: translateY(0);
}

.password-strength {
    margin-top: 5px;
}

.strength-meter {
    height: 6px;
    border-radius: 3px;
    background: var(--curdun-gray-light);
    overflow: hidden;
    margin-bottom: 8px;
}

.strength-meter-fill {
    height: 100%;
    border-radius: 3px;
    transition: all 0.3s ease;
    width: 0%;
}

.strength-text {
    font-size: 13px;
    font-weight: 600;
    margin-top: 2px;
}

.match-text {
    font-size: 13px;
    font-weight: 600;
    margin-top: 5px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.alert {
    position: fixed;
    top: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--curdun-white);
    padding: 16px 24px;
    border-radius: 12px;
    font-size: 14px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
    display: none;
    z-index: 10000;
    animation: slideInDown 0.5s ease;
    border-left: 4px solid;
    max-width: 500px;
    width: 90%;
}

.alert.success {
    border-left-color: var(--curdun-violet);
    color: var(--curdun-violet);
}

.alert.error {
    border-left-color: var(--curdun-red);
    color: var(--curdun-red);
}

.alert.warning {
    border-left-color: var(--curdun-amber);
    color: var(--curdun-amber);
}

.alert i {
    margin-right: 8px;
    font-size: 16px;
}

@keyframes slideInDown {
    from {
        opacity: 0;
        transform: translateX(-50%) translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }
}

@media (max-width: 768px) {
    body { padding: 20px 15px; }
    .main-wrapper { gap: 20px; }
    .row-top { gap: 20px; }
    .card { padding: 25px 20px; flex: 1 1 100%; }
    .profile-photo { width: 100px; height: 100px; }
}

.password-requirements {
    background: var(--curdun-bg);
    padding: 15px;
    border-radius: 12px;
    margin: 15px 0;
    border-left: 3px solid var(--curdun-violet);
}

.password-requirements h4 {
    margin-bottom: 10px;
    color: var(--curdun-dark-gray);
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.password-requirements h4 i {
    color: var(--curdun-violet);
}

.password-requirements ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.password-requirements li {
    font-size: 12px;
    color: #666666;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.password-requirements li.valid {
    color: var(--curdun-violet);
}

.password-requirements li.invalid {
    color: #666666;
}

.password-requirements li i {
    font-size: 10px;
    width: 12px;
}

.file-info {
    font-size: 12px;
    color: #666666;
    text-align: center;
    margin-top: 8px;
}

.user-info-display {
    background: linear-gradient(135deg, var(--curdun-bg) 0%, #fff 100%);
    padding: 15px;
    border-radius: 12px;
    margin-bottom: 20px;
    border-left: 4px solid var(--curdun-violet);
}

.info-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 14px;
}

.info-label {
    font-weight: 600;
    color: var(--curdun-dark-gray);
}

.info-value {
    color: #666666;
}
</style>
</head>

<body>

<div class="main-wrapper">

    <!-- User Information Display -->
    <div class="card" style="max-width: 800px; flex: 1 1 100%;">
        <h2><i class="fas fa-user-circle"></i> Macluumaadka Isticmaalaha</h2>
        <div class="user-info-display">
            <div class="info-row">
                <span class="info-label">Magaca:</span>
                <span class="info-value"><?= htmlspecialchars($user['full_name'] ?? '') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Email:</span>
                <span class="info-value"><?= htmlspecialchars($user['email'] ?? '') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Taleefan:</span>
                <span class="info-value"><?= htmlspecialchars($user['phone'] ?? 'Lama helin') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Door:</span>
                <span class="info-value"><?= htmlspecialchars($user['role_type'] ?? 'Lama cayimin') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Heerka Shaqada:</span>
                <span class="info-value"><?= htmlspecialchars($user['staff_level'] ?? 'Lama cayimin') ?></span>
            </div>
        </div>
    </div>

    <!-- ROW 1: Update Photo + Edit Info -->
    <div class="row-top">
        <div class="card">
            <h2><i class="fas fa-camera"></i> Cusboonaysii Sawirka</h2>
            <form method="POST" enctype="multipart/form-data" id="photoForm">
                <input type="hidden" name="update_photo" value="1">
                <div class="photo-container">
                    <img src="<?= $photo ?>" class="profile-photo" id="preview" alt="Sawirka Profile-ka" 
                         onerror="this.src='../uploads/profiles/default.png'">
                    <div class="file-input-wrapper">
                        <input type="file" name="profile_photo" accept="image/*" onchange="previewImage(event)" id="photoInput">
                        <label for="photoInput" class="file-input-label">
                            <i class="fas fa-upload"></i> Xullo Sawir
                        </label>
                    </div>
                    <div class="file-info">
                        <small>Cabbirka ugu badan: 5MB • Noocyada: JPG, PNG, GIF</small>
                    </div>
                </div>
                <button type="submit" name="update_photo_btn">
                    <i class="fas fa-save"></i> Keydi Sawirka
                </button>
            </form>
        </div>

        <div class="card">
            <h2><i class="fas fa-user-edit"></i> Cusboonaysii Macluumaadka</h2>
            <form method="POST" id="infoForm">
                <input type="hidden" name="update_info" value="1">
                <div class="input-group">
                    <label class="input-label"><i class="fas fa-user"></i> Magaca</label>
                    <input type="text" name="username" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" placeholder="Magaca" readonly>
                </div>
                
                <div class="input-group">
                    <label class="input-label"><i class="fas fa-envelope"></i> Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="Email" required>
                </div>
                
                <div class="input-group">
                    <label class="input-label"><i class="fas fa-phone"></i> Lambarka Taleefanka</label>
                    <input type="text" name="phone_number" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="Lambarka Taleefanka">
                </div>
                
                <button type="submit" name="update_info_btn">
                    <i class="fas fa-sync-alt"></i> Cusboonaysii Macluumaadka
                </button>
            </form>
        </div>
    </div>

    <!-- ROW 2: Change Password -->
    <div class="row-bottom">
        <div class="card" style="max-width:650px;">
            <h2><i class="fas fa-lock"></i> Bedel Password-ka</h2>
            
            <div class="password-requirements">
                <h4><i class="fas fa-info-circle"></i> Shuruudaha Password-ka</h4>
                <ul>
                    <li id="req-length" class="invalid"><i class="fas fa-circle"></i> Ugu yaraan 8 xaraf</li>
                    <li id="req-upper" class="invalid"><i class="fas fa-circle"></i> Hal xaraf weyn (A-Z)</li>
                    <li id="req-lower" class="invalid"><i class="fas fa-circle"></i> Hal xaraf yar (a-z)</li>
                    <li id="req-number" class="invalid"><i class="fas fa-circle"></i> Hal lambar (0-9)</li>
                    <li id="req-special" class="invalid"><i class="fas fa-circle"></i> Hal calaamad gaar ah</li>
                </ul>
            </div>
            
            <form method="POST" id="passwordForm">
                <input type="hidden" name="change_password" value="1">
                <div class="input-group">
                    <label class="input-label"><i class="fas fa-key"></i> Password-ka Hadda</label>
                    <input type="password" id="old_password" name="old_password" placeholder="Geli password-ka hadda" required>
                </div>
                
                <div class="input-group">
                    <label class="input-label"><i class="fas fa-lock"></i> Password-ka Cusub</label>
                    <input type="password" id="new_password" name="new_password" placeholder="Geli password-ka cusub" required 
                           oninput="checkPasswordRequirements(this.value); checkMatch();">
                    <div class="password-strength">
                        <div class="strength-meter">
                            <div class="strength-meter-fill" id="strengthMeter"></div>
                        </div>
                        <div class="strength-text" id="strengthText">Xoogga password-ka: Midna</div>
                    </div>
                </div>
                
                <div class="input-group">
                    <label class="input-label"><i class="fas fa-lock"></i> Xaqiiji Password-ka</label>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Ku celi password-ka cusub" required 
                           oninput="checkMatch()">
                    <div class="match-text" id="matchText"></div>
                </div>
                
                <button type="submit" name="change_password_btn">
                    <i class="fas fa-shield-alt"></i> Cusboonaysii Password-ka
                </button>
            </form>
        </div>
    </div>

</div>

<?php if($message): ?>
<div class="alert <?= $type ?>" id="alertBox">
    <i class="fas fa-<?= $type === 'success' ? 'check-circle' : ($type === 'error' ? 'exclamation-circle' : 'exclamation-triangle') ?>"></i>
    <?= $message ?>
</div>
<script>
window.scrollTo({ top: 0, behavior: 'smooth' });
const alertBox = document.getElementById('alertBox');
alertBox.style.display = 'block';
setTimeout(() => {
    alertBox.style.display = 'none';
    <?php if($type === 'success' && strpos($message, 'Password') === false): ?>
    setTimeout(() => { window.location.reload(); }, 1000);
    <?php endif; ?>
}, 5000);
</script>
<?php endif; ?>

<script>
function previewImage(event) {
    const file = event.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('preview').src = e.target.result;
        }
        reader.readAsDataURL(file);
        
        const label = document.querySelector('.file-input-label');
        label.innerHTML = `<i class="fas fa-check"></i> Sawir La Xullay`;
        label.style.background = 'var(--curdun-yellow)';
        label.style.color = 'var(--curdun-violet)';
    }
}

function checkPasswordRequirements(password) {
    const requirements = {
        length: password.length >= 8,
        upper: /[A-Z]/.test(password),
        lower: /[a-z]/.test(password),
        number: /\d/.test(password),
        special: /[@$!%*#?&]/.test(password)
    };

    Object.keys(requirements).forEach(req => {
        const element = document.getElementById(`req-${req}`);
        if (requirements[req]) {
            element.classList.remove('invalid');
            element.classList.add('valid');
            element.innerHTML = `<i class="fas fa-check-circle"></i> ${element.textContent.replace('•', '').trim()}`;
        } else {
            element.classList.remove('valid');
            element.classList.add('invalid');
            element.innerHTML = `<i class="fas fa-circle"></i> ${element.textContent.replace('•', '').trim()}`;
        }
    });

    const strength = Object.values(requirements).filter(Boolean).length;
    const meter = document.getElementById('strengthMeter');
    const text = document.getElementById('strengthText');
    
    let color, label, width;
    switch (strength) {
        case 0: case 1: color = '#B42318'; width = '20%'; label = 'Aad u Daciif'; break;
        case 2: color = '#FF5722'; width = '40%'; label = 'Daciif'; break;
        case 3: color = '#F5C410'; width = '60%'; label = 'Dhexdhexaad'; break;
        case 4: color = '#4B2C85'; width = '80%'; label = 'Xoog Badan'; break;
        case 5: color = '#2D1859'; width = '100%'; label = 'Aad u Xoog Badan'; break;
    }
    
    meter.style.width = width;
    meter.style.background = color;
    text.textContent = `Xoogga password-ka: ${label}`;
    text.style.color = color;
}

function checkMatch() {
    const newPass = document.getElementById("new_password").value;
    const confirmPass = document.getElementById("confirm_password").value;
    const matchText = document.getElementById("matchText");
    
    if (confirmPass.length === 0) {
        matchText.textContent = "";
        matchText.innerHTML = "";
        return;
    }
    
    if (newPass === confirmPass) {
        matchText.innerHTML = `<i class="fas fa-check-circle"></i> Password-yada way isku egyihiin!`;
        matchText.style.color = "#2D1859";
    } else {
        matchText.innerHTML = `<i class="fas fa-times-circle"></i> Password-yada isma eka!`;
        matchText.style.color = "#B42318";
    }
}

// Form submission handlers
document.getElementById('photoForm').addEventListener('submit', function(e) {
    const fileInput = document.getElementById('photoInput');
    if (!fileInput.files[0]) {
        e.preventDefault();
        alert('Fadlan xullo sawir si aad u soo geliso!');
        return;
    }
});

document.getElementById('infoForm').addEventListener('submit', function(e) {
    const email = document.querySelector('input[name="email"]').value;
    if (!email) {
        e.preventDefault();
        alert('Email waa lama huraan!');
        return;
    }
});

document.getElementById('passwordForm').addEventListener('submit', function(e) {
    const newPass = document.getElementById("new_password").value;
    const confirmPass = document.getElementById("confirm_password").value;
    
    if (newPass !== confirmPass) {
        e.preventDefault();
        alert('Password-yada isma eka!');
        return;
    }
});

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    checkMatch();
    document.getElementById('preview').addEventListener('error', function() {
        this.src = '../uploads/profiles/default.png';
    });
    document.getElementById('photoInput').value = '';
});

if (window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
}
</script>

</body>
</html>