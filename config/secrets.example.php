<?php
// config/secrets.php.example
//
// Copy this file to config/secrets.php and fill in real values.
// config/secrets.php is gitignored and must NEVER be committed.

// Gmail SMTP (used by forgot_password.php to send password-reset emails)
// Use a Gmail App Password, not your real account password:
// https://myaccount.google.com/apppasswords
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-16-character-app-password');

// GreenAPI (used for WhatsApp notifications - container status, receipts, etc.)
// Get these from https://green-api.com after creating an instance.
define('GREEN_API_ID', '');
define('GREEN_API_TOKEN', '');
define('GREEN_API_URL', '');
