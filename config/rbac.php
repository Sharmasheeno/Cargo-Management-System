<?php
// ============================================================================
// config/rbac.php
// Centralised, server-side authorization for every workflow entry point.
//
// Direct URL access must NEVER rely on hidden sidebar links: every page calls
// one of the require_*() guards below. Unauthorized access produces a
// controlled redirect to the caller's own dashboard with ?error=access_denied,
// never a blank page or a 500.
//
// Role model (users.role / users.role_type):
//   superadmin            -> superadmin/
//   company_admin         -> tenant_admin/           (Tenant Admin)
//   branch_manager        -> branch_manager/
//   staff + role_type:
//     reception_clerk | warehouse_supervisor | logistics_supervisor
//     finance_manager  | clerk | delivery_agent      -> staff/
//   driver                -> driver/
//   customer              -> customer/
// ============================================================================

if (!function_exists('rbac_session_started')) {
    function rbac_session_started(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
    }
}

if (!function_exists('current_role_type')) {
    function current_role_type(): string {
        return strtolower(trim((string)($_SESSION['role_type'] ?? $_SESSION['role'] ?? '')));
    }
}

if (!function_exists('is_logged_in_user')) {
    function is_logged_in_user(): bool {
        return !empty($_SESSION['user_id']) && !empty($_SESSION['logged_in']);
    }
}

if (!function_exists('access_denied_redirect')) {
    /** Controlled access-denied: bounce to the user's own dashboard, never die(). */
    function access_denied_redirect(string $message = 'You do not have permission to access that page.'): void {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = 'error';
        $rt = current_role_type();
        $map = [
            'superadmin' => '../superadmin/dashboard.php',
            'company_admin' => '../tenant_admin/dashboard.php',
            'tenant_admin' => '../tenant_admin/dashboard.php',
            'branch_manager' => '../branch_manager/dashboard.php',
            'branches_admin' => '../branch_manager/dashboard.php',
            'driver' => '../driver/index.php',
            'customer' => '../customer/dashboard.php',
        ];
        $target = $map[$rt] ?? '../staff/dashboard.php';
        header('Location: ' . $target . '?error=access_denied');
        exit;
    }
}

if (!function_exists('require_login_guard')) {
    function require_login_guard(): void {
        rbac_session_started();
        if (!is_logged_in_user()) {
            header('Location: ../login.php');
            exit;
        }
    }
}

if (!function_exists('require_roles')) {
    /**
     * Allow ONLY the listed role_types (top-level roles or staff sub-roles).
     * Example: require_roles(['warehouse_supervisor','logistics_supervisor']);
     */
    function require_roles(array $role_types): void {
        require_login_guard();
        if (!in_array(current_role_type(), array_map('strtolower', $role_types), true)) {
            access_denied_redirect();
        }
    }
}

if (!function_exists('require_staff_subroles')) {
    /** Staff-area page restricted to specific sub-roles (reception_clerk etc.). */
    function require_staff_subroles(array $subroles): void {
        require_roles(array_merge(['staff'], $subroles));
    }
}

if (!function_exists('require_tenant_context')) {
    /** Tenant isolation guard: resolves and returns the tenant id or dies safely. */
    function require_tenant_context(): int {
        $tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
        if ($tenant_id <= 0) {
            header('Location: ../login.php?error=no_tenant');
            exit;
        }
        return $tenant_id;
    }
}

if (!function_exists('require_branch_context')) {
    /**
     * Branch scoping guard: resolves assigned branch from session, then
     * user_branch_assignments, then users.default_branch_id.
     * $pdo must be available. Returns branch id.
     */
    function require_branch_context(PDO $pdo): int {
        $user_id = (int)$_SESSION['user_id'];
        $branch_id = (int)($_SESSION['assigned_branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
        if ($branch_id <= 0) {
            try {
                $st = $pdo->prepare("SELECT branch_id FROM user_branch_assignments WHERE user_id = ? AND is_primary = 1 LIMIT 1");
                $st->execute([$user_id]);
                $branch_id = (int)$st->fetchColumn();
            } catch (Throwable $e) {}
        }
        if ($branch_id <= 0) {
            try {
                $st = $pdo->prepare("SELECT default_branch_id FROM users WHERE id = ?");
                $st->execute([$user_id]);
                $branch_id = (int)$st->fetchColumn();
            } catch (Throwable $e) {}
        }
        if ($branch_id <= 0) {
            require_once __DIR__ . '/../includes/header.php';
            echo '<div class="container-fluid"><div class="alert alert-danger m-4">You are not assigned to any branch. Please contact your administrator.</div></div>';
            require_once __DIR__ . '/../includes/footer.php';
            exit;
        }
        $_SESSION['assigned_branch_id'] = $branch_id;
        return $branch_id;
    }
}

if (!function_exists('require_driver')) {
    /** Driver portal guard: only authenticated drivers get past this. */
    function require_driver(): void {
        require_roles(['driver']);
    }
}

if (!function_exists('require_customer')) {
    /** Customer portal guard. Customers can never edit operational statuses. */
    function require_customer(): void {
        require_login_guard();
        if (!in_array(current_role_type(), ['customer'], true)) {
            access_denied_redirect();
        }
    }
}
