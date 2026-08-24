<?php
require_once __DIR__ . '/config/db_connect.php';

$student = null;
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['reg_no'])) {
  $reg_no = trim($_GET['reg_no']);
  if ($reg_no !== '') {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE reg_no = ? LIMIT 1");
    $stmt->execute([$reg_no]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) {
      $message = '<p style="color:red;">❌ No student found with that registration number.</p>';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>🎓 Verify Student | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
:root {
  --hu-green: #00843D;
  --hu-blue: #0072CE;
  --hu-light-green: #00A651;
  --hu-dark-gray: #333333;
  --hu-light-gray: #F5F9F7;
  --hu-danger: #C62828;
  --hu-white: #FFFFFF;
}

/* ✅ FULL SCREEN CENTER LAYOUT */
html, body {
  height: 100%;
  margin: 0;
  font-family: 'Segoe UI', sans-serif;
  background: linear-gradient(135deg, var(--hu-light-gray), #E8F5E9);
  display: flex;
  justify-content: center;
  align-items: center;
  color: var(--hu-dark-gray);
}

/* ✅ POPUP BOX */
.popup {
  background: var(--hu-white);
  border-radius: 18px;
  box-shadow: 0 8px 25px rgba(0,0,0,0.25);
  width: 95%;
  max-width: 350px;
  padding: 25px 20px 30px;
  border-top: 6px solid var(--hu-green);
  text-align: center;
  animation: fadeIn 0.4s ease forwards;
  transform: scale(0.9);
  opacity: 0;
}
@keyframes fadeIn {
  to { transform: scale(1); opacity: 1; }
}

/* ✅ TITLE */
h2 {
  color: var(--hu-green);
  margin: 8px 0 15px;
  font-size: 20px;
  font-weight: 700;
}

/* ✅ INPUTS */
input {
  width: 90%;
  padding: 10px;
  margin: 6px 0;
  border-radius: 8px;
  border: 1px solid #ccc;
  font-size: 14px;
  outline: none;
  transition: border-color 0.25s, box-shadow 0.25s;
}
input:focus {
  border-color: var(--hu-blue);
  box-shadow: 0 0 5px rgba(0,114,206,0.3);
}

/* ✅ BUTTON */
button {
  background: var(--hu-blue);
  color: var(--hu-white);
  border: none;
  padding: 11px 15px;
  border-radius: 8px;
  cursor: pointer;
  font-weight: 600;
  width: 90%;
  margin-top: 8px;
  transition: background 0.3s, transform 0.2s;
}
button:hover { background: var(--hu-light-green); transform: scale(1.03); }

/* ✅ STUDENT PHOTO */
img {
  width: 110px;
  height: 110px;
  border-radius: 50%;
  border: 3px solid var(--hu-green);
  object-fit: cover;
  margin-top: 10px;
  box-shadow: 0 4px 10px rgba(0,0,0,0.15);
}

/* ✅ TEXTS */
.name {
  font-weight: 600;
  color: var(--hu-dark-gray);
  font-size: 17px;
  margin-top: 10px;
  text-transform: capitalize;
}
.message {
  color: var(--hu-danger);
  margin-top: 10px;
  font-weight: 500;
  font-size: 14px;
}

/* ✅ RESPONSIVE */
@media(max-width:480px){
  .popup { padding: 22px 15px; max-width: 300px; }
  h2 { font-size: 18px; }
  .name { font-size: 16px; }
}
</style>
</head>

<body>
<div class="popup">
  <?php if ($student): ?>
    <img src="<?= htmlspecialchars($student['photo_path'] ?: 'assets/img/default_avatar.png') ?>" alt="Photo">
    <p class="name"><?= htmlspecialchars($student['full_name']) ?></p>

    <form method="POST" action="verify_user.php" id="verifyForm">
      <input type="hidden" name="reg_no" value="<?= htmlspecialchars($student['reg_no']) ?>">
      <input type="password" name="password" placeholder="Enter password" required><br>
      <button type="submit">Verify</button>
    </form>

  <?php else: ?>
    <h2>🎓 Verify Student</h2>
    <?php if ($message): ?>
      <div class="message"><?= $message ?></div>
    <?php endif; ?>
    <form method="GET">
      <input type="text" name="reg_no" placeholder="Enter Registration No" required><br>
      <button type="submit">Search</button>
    </form>
  <?php endif; ?>
</div>

<script>
/* ✅ AJAX Submit for verify_user.php (no reload) */
const verifyForm = document.getElementById('verifyForm');
if (verifyForm) {
  verifyForm.onsubmit = async (e) => {
    e.preventDefault();
    const form = e.target;
    const data = new FormData(form);
    const popup = document.querySelector('.popup');
    popup.innerHTML = "<p style='color:#0072CE;font-weight:600;'>⏳ Verifying...</p>";

    try {
      const res = await fetch('verify_user.php', { method: 'POST', body: data });
      const html = await res.text();
      popup.innerHTML = html;
    } catch (err) {
      popup.innerHTML = "<p style='color:#C62828;font-weight:600;'>❌ Verification error!</p>";
    }
  };
}
</script>
</body>
</html>
