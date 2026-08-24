<?php
require_once __DIR__ . '/db.php';

$data = get_input();

$user_id = $data['user_id'] ?? null;
$subject = trim($data['subject'] ?? 'Support Request');
$message = trim($data['message'] ?? '');
$method = trim($data['method'] ?? 'whatsapp'); // whatsapp ama email

if (!$user_id || $message === '') {
    json_response(false, "user_id iyo message waa qasab");
}

try {
    $user = db_get("
        SELECT id, full_name, email, phone
        FROM users
        WHERE id = ?
        LIMIT 1
    ", [$user_id]);

    if (!$user) {
        json_response(false, "User lama helin");
    }

    $support_whatsapp = "252619533034";
    $support_email = "cabdirahmanjmohamed@gmail.com";

    $full_message =
        "CURDUN SYSTEM SUPPORT\n\n" .
        "Magac: " . ($user['full_name'] ?? '-') . "\n" .
        "Phone: " . ($user['phone'] ?? '-') . "\n" .
        "Email: " . ($user['email'] ?? '-') . "\n\n" .
        "Subject: " . $subject . "\n" .
        "Message: " . $message;

    $whatsapp_url = "https://wa.me/" . $support_whatsapp . "?text=" . urlencode($full_message);

    $email_url = "mailto:" . $support_email .
        "?subject=" . urlencode($subject) .
        "&body=" . urlencode($full_message);

    json_response(true, "Support link generated", [
        "method" => $method,
        "whatsapp" => [
            "number" => "+252619533034",
            "url" => $whatsapp_url
        ],
        "email" => [
            "address" => $support_email,
            "url" => $email_url
        ],
        "message" => $full_message
    ]);

} catch (Exception $e) {
    json_response(false, "Server error", [
        "error" => $e->getMessage()
    ]);
}
?>