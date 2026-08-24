<?php
ob_start();
date_default_timezone_set('Africa/Mogadishu');

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/secrets.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_FROM_EMAIL', SMTP_USERNAME);
define('SMTP_FROM_NAME', 'Cargo Management System');

$error = '';
$success = '';
$step = 'request';
$email = '';
$token = '';

function valid_reset_token($pdo, $token) {
    $stmt = $pdo->prepare("
        SELECT user_id, expires_at, is_used
        FROM password_resets
        WHERE token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) return false;
    if ((int)$row['is_used'] === 1) return false;
    if (strtotime($row['expires_at']) < time()) return false;

    return $row;
}

if (!empty($_GET['token'])) {
    $token = trim($_GET['token']);
    $resetData = valid_reset_token($pdo, $token);

    if ($resetData) {
        $step = 'reset';
    } else {
        $error = "❌ Token-gan waa dhacay ama lama isticmaali karo! Fadlan codso reset cusub.";
        $step = 'request';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request') {
    $email = trim($_POST['email'] ?? '');

    if ($email === '') {
        $error = "⚠️ Fadlan geli emailkaaga!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "⚠️ Email sax ah geli!";
    } else {
        $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', time() + 1800);

            $pdo->prepare("DELETE FROM password_resets WHERE user_id = ? AND is_used = 0")
                ->execute([$user['id']]);

            $pdo->prepare("
                INSERT INTO password_resets (user_id, token, expires_at, created_at, is_used)
                VALUES (?, ?, ?, NOW(), 0)
            ")->execute([$user['id'], $token, $expires_at]);

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            $resetLink = $protocol . "://" . $_SERVER['HTTP_HOST'] . $basePath . "/forgot_password.php?token=" . urlencode($token);

            $mail = new PHPMailer(true);

            try {
                $mail->isSMTP();
                $mail->Host = SMTP_HOST;
                $mail->SMTPAuth = true;
                $mail->Username = SMTP_USERNAME;
                $mail->Password = SMTP_PASSWORD;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = SMTP_PORT;

                $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
                $mail->addAddress($user['email'], $user['full_name']);
                $mail->isHTML(true);
                $mail->Subject = 'Reset Password - Cargo Management System';

                $safeName = htmlspecialchars($user['full_name']);
                $safeLink = htmlspecialchars($resetLink);

                $mail->Body = "
                    <div style='font-family:Arial;max-width:600px;margin:auto;background:#fff'>
                        <div style='background:#2D1859;padding:30px;text-align:center'>
                            <h1 style='color:#F5C410'>Cargo Management System</h1>
                        </div>
                        <div style='padding:35px;text-align:center'>
                            <h2 style='color:#2D1859'>Reset Your Password</h2>
                            <p>Salaam {$safeName},</p>
                            <p>Hoos ka dhagsii si aad password cusub u samaysato.</p>
                            <a href='{$safeLink}' style='background:#2D1859;color:#fff;padding:13px 28px;border-radius:25px;text-decoration:none;font-weight:bold'>
                                Reset Password
                            </a>
                            <p style='color:#ff9800'>Link-gan wuxuu shaqeynayaa 30 daqiiqo.</p>
                            <p style='word-break:break-all;font-size:12px'>{$safeLink}</p>
                        </div>
                    </div>
                ";

                $mail->AltBody = "Reset Password Link: " . $resetLink;
                $mail->send();

                $success = "✅ Reset link emailkaaga waa loo diray. Hubi Inbox ama Spam.";
                $email = '';
            } catch (Exception $e) {
                $error = "❌ Email lama diri karo: " . $mail->ErrorInfo;
            }
        } else {
            $success = "✅ Haddii emailkaagu diiwaan gashan yahay, reset link waa laguu diray.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset') {
    $token = trim($_POST['token'] ?? '');
    $password = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $resetData = valid_reset_token($pdo, $token);

    if (!$resetData) {
        $error = "❌ Token-gan waa dhacay ama lama isticmaali karo! Codso reset cusub.";
        $step = 'request';
    } elseif ($password === '' || $confirm === '') {
        $error = "⚠️ Geli password cusub iyo confirm password.";
        $step = 'reset';
    } elseif (strlen($password) < 6) {
        $error = "⚠️ Password-ku ugu yaraan 6 xaraf ha noqdo.";
        $step = 'reset';
    } elseif ($password !== $confirm) {
        $error = "⚠️ Password-ka iyo confirm-ka isma eka.";
        $step = 'reset';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([$hash, $resetData['user_id']]);

        $pdo->prepare("UPDATE password_resets SET is_used = 1 WHERE token = ?")
            ->execute([$token]);

        $success = "✅ Password-ka waa la beddelay. 3 seconds kadib login page ayaa lagu geynayaa.";
        echo "<meta http-equiv='refresh' content='3;url=index.php'>";
        $step = 'request';
    }
}
?>
<!DOCTYPE html>
<html lang="so">
<head>
<meta charset="UTF-8">
<title>Forgot Password | Cargo Management System</title>
<link rel="icon" type="image/png" href="assets/images/curdun-favicon.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
body{margin:0;min-height:100vh;background:#2D1859;font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;padding:20px}
.box{width:100%;max-width:500px;background:#fff;border-radius:25px;padding:35px;border-top:6px solid #F5C410;box-shadow:0 20px 45px rgba(0,0,0,.25)}
.logo{width:90px;height:90px;margin:0 auto 20px;background:#2D1859;color:#F5C410;border-radius:20px;display:flex;align-items:center;justify-content:center;font-size:45px}
h2{text-align:center;color:#2D1859;margin:0 0 10px;font-size:30px}
p{text-align:center;color:#69647A}
input{width:100%;padding:15px;border:2px solid #ddd;border-radius:14px;margin-bottom:15px;font-size:15px;box-sizing:border-box}
input:focus{outline:none;border-color:#2D1859}
button{width:100%;padding:15px;background:#F5C410;color:#1B1233;border:0;border-radius:14px;font-size:16px;font-weight:bold;cursor:pointer}
button:hover{background:#D4A70C;color:#1B1233}
.alert{padding:14px;border-radius:12px;margin-bottom:18px;text-align:center;font-size:14px}
.error{background:#FEF0EE;color:#B42318}
.success{background:#EEFBF3;color:#0F7A3A}
.info{background:#F4F5F9;padding:14px;border-radius:12px;margin-bottom:18px;color:#69647A;text-align:center}
.back{text-align:center;margin-top:20px}
.back a{color:#2D1859;text-decoration:none;font-weight:bold}
.password-wrapper{position:relative}
.password-wrapper input{padding-right:45px}
.toggle-password{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:#69647A;cursor:pointer;padding:4px;display:flex;align-items:center}
.toggle-password:hover{color:#2D1859}
</style>
</head>
<body>
<div class="box">
    <div class="logo">🔑</div>

    <h2><?= $step === 'reset' ? 'Create New Password' : 'Forgot Password?' ?></h2>
    <p><?= $step === 'reset' ? 'Geli password cusub iyo confirm password' : 'Geli emailkaaga si aad u hesho reset link' ?></p>

    <?php if ($error): ?>
        <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($step === 'request'): ?>
        <div class="info">Reset link ayaa emailkaaga laguugu soo diri doonaa.</div>
        <form method="POST">
            <input type="hidden" name="action" value="request">
            <input type="email" name="email" placeholder="Email Address" value="<?= htmlspecialchars($email) ?>" required>
            <button type="submit">Soo dir Reset Link</button>
        </form>
    <?php else: ?>
        <form method="POST">
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <div class="password-wrapper">
                <input type="password" name="new_password" id="new_password" placeholder="New Password" required>
                <button type="button" class="toggle-password" data-target="new_password" aria-label="Show password" tabindex="-1"><i class="fas fa-eye"></i></button>
            </div>
            <div class="password-wrapper">
                <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm Password" required>
                <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Show password" tabindex="-1"><i class="fas fa-eye"></i></button>
            </div>
            <button type="submit">Save New Password</button>
        </form>
    <?php endif; ?>

    <div class="back">
        <a href="index.php">← Ku noqo Login page-ka</a>
    </div>
</div>
<script>
document.querySelectorAll('.toggle-password').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var input = document.getElementById(btn.getAttribute('data-target'));
        if (!input) return;
        var isHidden = input.getAttribute('type') === 'password';
        input.setAttribute('type', isHidden ? 'text' : 'password');
        var icon = btn.querySelector('i');
        if (icon) { icon.classList.toggle('fa-eye'); icon.classList.toggle('fa-eye-slash'); }
        btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    });
});
</script>
</body>
</html>