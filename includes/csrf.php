<?php
// includes/csrf.php
// Shared CSRF protection helpers for admin mutations.
//
// Also transitively loads includes/sa_scope.php so that AJAX handlers
// which include csrf.php (via require_csrf_token()) have access to the
// Super Admin tenant-scope resolver without needing a second require.
require_once __DIR__ . '/sa_scope.php';
//
// Usage:
//   csrf_token()        -> returns the current per-session token (mints on first call)
//   csrf_field()        -> outputs a hidden input <input name="csrf_token" ...>
//   csrf_meta()         -> outputs <meta name="csrf-token" content="...">
//                          Read by the jQuery ajaxSetup shim in includes/footer.php
//   verify_csrf_token() -> returns bool. Accepts token from:
//                          - $_POST['csrf_token']
//                          - $_SERVER['HTTP_X_CSRF_TOKEN']
//   require_csrf_token()-> emit 403 JSON and exit if verification fails.
//                          Safe to call unconditionally at the top of an
//                          AJAX POST handler.

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
    }
}

if (!function_exists('csrf_meta')) {
    function csrf_meta(): string {
        return '<meta name="csrf-token" content="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token(): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $session = $_SESSION['csrf_token'] ?? '';
        if ($session === '' || !is_string($session)) {
            return false;
        }
        $submitted = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($submitted === '' || !is_string($submitted)) {
            return false;
        }
        return hash_equals($session, $submitted);
    }
}

if (!function_exists('require_csrf_token')) {
    function require_csrf_token(): void {
        if (verify_csrf_token()) {
            return;
        }
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'CSRF token missing or invalid. Reload the page and try again.',
        ]);
        exit;
    }
}
