<?php
// ============================================================================
// driver/profile.php — Driver self-service profile
//
// Safe self-service edits ONLY:
//   * phone
//   * password (must supply the current password to change it)
//   * profile image
//
// The driver MUST NOT be able to change:
//   * role / role_type
//   * tenant_id / branch / driver-ownership id
//   * account status (is_active)
//   * permissions
//
// Read-only fields shown for transparency:
//   * full name, email/username, driver id, license number/expiry,
//     assigned branch/tenant, account status.
// ============================================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/rbac.php';

require_driver();
$tenant_id      = require_tenant_context();
$driver_user_id = (int)$_SESSION['user_id'];

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function postStr(string $k, string $def = ''): string {
    $v = $_POST[$k] ?? $def;
    return is_array($v) ? $def : trim((string)$v);
}

// Fetch the authoritative user + driver + branch rows in one round trip.
$stmt = $pdo->prepare("
    SELECT u.id AS user_id, u.full_name, u.email, u.phone, u.role, u.role_type,
           u.is_active, u.profile_image, u.tenant_id, u.default_branch_id, u.created_at,
           d.id AS driver_id, d.license_number, d.license_expiry, d.employee_id,
           d.hire_date, d.is_active AS driver_active,
           t.name AS tenant_name,
           b.branch_name AS branch_name
    FROM users u
    LEFT JOIN drivers  d ON d.user_id = u.id AND d.tenant_id = u.tenant_id
    LEFT JOIN tenants  t ON t.id = u.tenant_id
    LEFT JOIN branches b ON b.id = u.default_branch_id
    WHERE u.id = ? AND u.tenant_id = ?
    LIMIT 1
");
$stmt->execute([$driver_user_id, $tenant_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo 'Profile not found for the signed-in driver.';
    exit;
}

$notice = null;
$error  = null;

// ----------------------------------------------------------------------------
// Handle self-service updates (server-authoritative allow-list — client cannot
// slip role/tenant/branch/status/ownership into the request).
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = postStr('action');
    try {
        if ($action === 'update_phone') {
            $phone = postStr('phone');
            if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{4,40}$/', $phone)) {
                throw new RuntimeException('Enter a valid phone number.');
            }
            $pdo->prepare("UPDATE users SET phone = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?")
                ->execute([$phone !== '' ? $phone : null, $driver_user_id, $tenant_id]);
            $_SESSION['phone'] = $phone;
            $notice = 'Phone updated.';
        }
        elseif ($action === 'change_password') {
            $current = postStr('current_password');
            $next    = postStr('new_password');
            $confirm = postStr('confirm_password');
            if ($current === '' || $next === '' || $confirm === '') {
                throw new RuntimeException('Fill in current, new, and confirm password fields.');
            }
            if ($next !== $confirm) {
                throw new RuntimeException('New password and confirmation do not match.');
            }
            if (strlen($next) < 8) {
                throw new RuntimeException('New password must be at least 8 characters.');
            }
            // Verify the driver's own current password before allowing the change.
            $st = $pdo->prepare("SELECT password_hash, password FROM users WHERE id = ? LIMIT 1");
            $st->execute([$driver_user_id]);
            $creds = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $stored = !empty($creds['password_hash']) ? $creds['password_hash'] : ($creds['password'] ?? '');
            if ($stored === '' || !password_verify($current, $stored)) {
                throw new RuntimeException('Current password is incorrect.');
            }
            $newHash = password_hash($next, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password_hash = ?, password = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?")
                ->execute([$newHash, $newHash, $driver_user_id, $tenant_id]);
            $notice = 'Password updated.';
        }
        elseif ($action === 'update_photo' && isset($_FILES['profile_image'])) {
            $f = $_FILES['profile_image'];
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Could not read the uploaded image.');
            }
            if (($f['size'] ?? 0) > 2 * 1024 * 1024) {
                throw new RuntimeException('Image must be 2 MB or smaller.');
            }
            $mime = function_exists('mime_content_type') ? mime_content_type($f['tmp_name']) : null;
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime])) {
                throw new RuntimeException('Only JPG, PNG or WEBP images are accepted.');
            }
            $ext    = $allowed[$mime];
            $dir    = __DIR__ . '/../upload/profiles';
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $name   = 'driver_' . $driver_user_id . '_' . time() . '.' . $ext;
            $target = $dir . '/' . $name;
            if (!move_uploaded_file($f['tmp_name'], $target)) {
                throw new RuntimeException('Failed to save the image.');
            }
            $rel = 'upload/profiles/' . $name;
            $pdo->prepare("UPDATE users SET profile_image = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?")
                ->execute([$rel, $driver_user_id, $tenant_id]);
            $_SESSION['profile_image'] = $rel;
            $notice = 'Profile photo updated.';
        }
        else {
            throw new RuntimeException('Unknown action.');
        }
        // Re-read so the page reflects the change.
        $stmt->execute([$driver_user_id, $tenant_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.driver-profile-card{background:#fff;border-radius:14px;padding:22px;box-shadow:0 8px 22px rgba(31,20,65,.08);border:1px solid #eee;margin-bottom:18px}
.driver-profile-card h5{color:#32145f;font-weight:700;margin-bottom:14px}
.profile-readonly dt{color:#6b7280;font-weight:600;font-size:12px;letter-spacing:.4px;text-transform:uppercase;margin-bottom:2px}
.profile-readonly dd{margin-bottom:12px;font-weight:600;color:#1f2937}
.badge-inactive{background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700}
.badge-active{background:#dcfce7;color:#166534;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700}
.avatar-preview{width:96px;height:96px;border-radius:50%;object-fit:cover;border:2px solid #e5e7eb;background:#f3f4f6}
</style>

<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2><i class="fas fa-id-card text-primary"></i> Driver Profile
      <small class="text-muted">— <?= h($row['full_name']) ?></small>
    </h2>
  </div>

  <?php if ($notice): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error):  ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

  <div class="row">
    <div class="col-md-5">
      <div class="driver-profile-card">
        <h5>Account</h5>
        <div class="text-center mb-3">
          <?php
            $photo = !empty($row['profile_image'])
              ? '../' . h($row['profile_image'])
              : '../upload/profiles/default.png';
          ?>
          <img src="<?= $photo ?>" alt="profile" class="avatar-preview" onerror="this.style.display='none'">
        </div>
        <dl class="profile-readonly">
          <dt>Full name</dt><dd><?= h($row['full_name']) ?></dd>
          <dt>Email</dt><dd><?= h($row['email']) ?></dd>
          <dt>Role</dt><dd><?= h(ucwords(str_replace('_',' ', (string)($row['role_type'] ?: $row['role'])))) ?></dd>
          <dt>Driver ID</dt><dd><?= h((string)$row['driver_id'] ?: '—') ?></dd>
          <dt>Employee ID</dt><dd><?= h($row['employee_id'] ?: '—') ?></dd>
          <dt>License number</dt><dd><?= h($row['license_number'] ?: '—') ?></dd>
          <dt>License expiry</dt><dd><?= h($row['license_expiry'] ?: '—') ?></dd>
          <dt>Assigned branch</dt><dd><?= h($row['branch_name'] ?: '—') ?></dd>
          <dt>Tenant</dt><dd><?= h($row['tenant_name'] ?: '—') ?></dd>
          <dt>Account status</dt>
          <dd>
            <?php if ((int)$row['is_active'] === 1 && (int)($row['driver_active'] ?? 1) === 1): ?>
              <span class="badge-active">Active</span>
            <?php else: ?>
              <span class="badge-inactive">Inactive</span>
            <?php endif; ?>
          </dd>
          <dt>Member since</dt><dd><?= h(substr((string)$row['created_at'], 0, 10) ?: '—') ?></dd>
        </dl>
        <p class="small text-muted mb-0">Role, tenant, branch and account status can only be changed by an administrator.</p>
      </div>
    </div>

    <div class="col-md-7">
      <div class="driver-profile-card">
        <h5>Phone number</h5>
        <form method="post" autocomplete="off">
          <input type="hidden" name="action" value="update_phone">
          <div class="form-group">
            <label>Contact phone</label>
            <input type="text" name="phone" class="form-control" value="<?= h($row['phone']) ?>" placeholder="e.g. 6xxxxxxxx">
          </div>
          <button class="btn btn-primary" type="submit">Save phone</button>
        </form>
      </div>

      <div class="driver-profile-card">
        <h5>Change password</h5>
        <form method="post" autocomplete="off">
          <input type="hidden" name="action" value="change_password">
          <div class="form-group">
            <label>Current password</label>
            <input type="password" name="current_password" class="form-control" required>
          </div>
          <div class="form-group">
            <label>New password</label>
            <input type="password" name="new_password" class="form-control" required minlength="8">
          </div>
          <div class="form-group">
            <label>Confirm new password</label>
            <input type="password" name="confirm_password" class="form-control" required minlength="8">
          </div>
          <button class="btn btn-primary" type="submit">Update password</button>
        </form>
      </div>

      <div class="driver-profile-card">
        <h5>Profile photo</h5>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="update_photo">
          <div class="form-group">
            <input type="file" name="profile_image" class="form-control-file" accept="image/jpeg,image/png,image/webp" required>
            <small class="text-muted">JPG, PNG or WEBP, up to 2 MB.</small>
          </div>
          <button class="btn btn-primary" type="submit">Upload photo</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
