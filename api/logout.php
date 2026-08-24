<?php
require_once __DIR__ . '/db.php';

$data = get_input();

$user_id = $data['user_id'] ?? null;

try {
    if ($user_id) {
        // Audit log haddii function-ku jiro
        $auditPath = __DIR__ . '/../includes/audit_helper.php';

        if (file_exists($auditPath)) {
            require_once $auditPath;

            if (function_exists('add_audit_log')) {
                add_audit_log($pdo, $user_id, 'logout', 'User logged out from mobile app');
            }
        }
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    session_unset();
    session_destroy();

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    json_response(true, "Si guul leh ayaad uga baxday system-ka");

} catch (Exception $e) {
    json_response(false, "Server error", [
        "error" => $e->getMessage()
    ]);
}
?>