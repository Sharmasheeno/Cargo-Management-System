<?php
require_once __DIR__ . '/db.php';

$data = get_input();

/*
|--------------------------------------------------------------------------
| INPUTS
|--------------------------------------------------------------------------
*/

$user_id = $data['user_id'] ?? null;

// PROFILE UPDATE
$email = trim($data['email'] ?? '');
$phone = trim($data['phone'] ?? '');

// PASSWORD UPDATE
$old_password = trim($data['old_password'] ?? '');
$new_password = trim($data['new_password'] ?? '');
$confirm_password = trim($data['confirm_password'] ?? '');

if (!$user_id) {
    json_response(false, "user_id waa qasab");
}

try {

    /*
    |--------------------------------------------------------------------------
    | CHECK USER
    |--------------------------------------------------------------------------
    */

    $user = db_get("
        SELECT 
            id,
            email,
            phone,
            password_hash
        FROM users
        WHERE id = ?
        LIMIT 1
    ", [$user_id]);

    if (!$user) {
        json_response(false, "User lama helin");
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE PROFILE
    |--------------------------------------------------------------------------
    */

    if ($email !== '' || $phone !== '') {

        // CHECK EMAIL
        if ($email !== '') {

            $checkEmail = db_get("
                SELECT id
                FROM users
                WHERE email = ?
                AND id != ?
                LIMIT 1
            ", [$email, $user_id]);

            if ($checkEmail) {
                json_response(false, "Email-kan qof kale ayaa isticmaalaya");
            }
        }

        // CHECK PHONE
        if ($phone !== '') {

            $checkPhone = db_get("
                SELECT id
                FROM users
                WHERE phone = ?
                AND id != ?
                LIMIT 1
            ", [$phone, $user_id]);

            if ($checkPhone) {
                json_response(false, "Phone-kan qof kale ayaa isticmaalaya");
            }
        }

        // UPDATE USERS
        db_query("
            UPDATE users
            SET
                email = COALESCE(NULLIF(?, ''), email),
                phone = COALESCE(NULLIF(?, ''), phone)
            WHERE id = ?
        ", [$email, $phone, $user_id]);

        // UPDATE CUSTOMERS
        db_query("
            UPDATE customers
            SET
                email = COALESCE(NULLIF(?, ''), email),
                phone = COALESCE(NULLIF(?, ''), phone)
            WHERE user_id = ?
        ", [$email, $phone, $user_id]);
    }

    /*
    |--------------------------------------------------------------------------
    | CHANGE PASSWORD
    |--------------------------------------------------------------------------
    */

    if (
        $old_password !== '' ||
        $new_password !== '' ||
        $confirm_password !== ''
    ) {

        if (
            $old_password === '' ||
            $new_password === '' ||
            $confirm_password === ''
        ) {
            json_response(false, "Dhamaan password fields waa qasab");
        }

        // VERIFY OLD PASSWORD
        if (!password_verify($old_password, $user['password_hash'])) {
            json_response(false, "Password-ka hadda waa qalad");
        }

        // CHECK MATCH
        if ($new_password !== $confirm_password) {
            json_response(false, "Password-yada cusub isma eka");
        }

        // PASSWORD LENGTH
        if (strlen($new_password) < 6) {
            json_response(false, "Password-ka cusub ugu yaraan 6 xaraf ha noqdo");
        }

        // HASH PASSWORD
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);

        // UPDATE PASSWORD
        db_query("
            UPDATE users
            SET password_hash = ?
            WHERE id = ?
        ", [$new_hash, $user_id]);
    }

    /*
    |--------------------------------------------------------------------------
    | RETURN UPDATED USER
    |--------------------------------------------------------------------------
    */

    $updatedUser = db_get("
        SELECT
            id,
            full_name,
            email,
            phone,
            role_type,
            tenant_id
        FROM users
        WHERE id = ?
        LIMIT 1
    ", [$user_id]);

    json_response(true, "Profile iyo password si guul leh ayaa loo cusboonaysiiyay", [
        "user" => $updatedUser
    ]);

} catch (Exception $e) {

    json_response(false, "Server error", [
        "error" => $e->getMessage()
    ]);
}
?>