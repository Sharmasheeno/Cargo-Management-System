<?php
require_once __DIR__ . '/db.php';

$data = get_input();

$phone = trim($data['phone'] ?? $data['phone_number'] ?? '');
$password = trim($data['password'] ?? '');

if ($phone === '' || $password === '') {
    json_response(false, "Phone number iyo password waa qasab");
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            id,
            full_name,
            email,
            phone,
            password_hash,
            role_type,
            tenant_id,
            is_active
        FROM users
        WHERE phone = ?
        LIMIT 1
    ");

    $stmt->execute([$phone]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
json_response(false, "Lambarka taleefanka ama password-ka aad gelisay waa khalad. Fadlan hubi xogtaada oo isku day mar kale.");    }

    if ((int)$user['is_active'] !== 1) {
        json_response(false, "Akoonkaagu ma shaqeynayo. La xiriir admin-ka.");
    }

    if (!password_verify($password, $user['password_hash'])) {
json_response(false, "Lambarka taleefanka ama password-ka aad gelisay waa khalad. Fadlan hubi xogtaada oo isku day mar kale.");    }

    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
        ->execute([$user['id']]);

    unset($user['password_hash']);

    json_response(true, "Login successful", [
        "user" => $user
    ]);

} catch (Exception $e) {
    json_response(false, "Server error", [
        "error" => $e->getMessage()
    ]);
}