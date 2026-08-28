<?php
// includes/sa_scope.php
//
// Shared authoritative tenant-scope resolver for Super Admin pages.
//
// The Super Admin top-bar selector sets $_SESSION['selected_tenant_id']
// (via includes/set_tenant.php). Pages use their own on-page dropdown /
// AJAX parameter `tenant` for explicit per-request scope. This helper
// gives every SA page the same default: when no explicit scope is
// provided, fall back to the top-bar selection.
//
// Contract:
//   sa_selected_tenant_id_int() -> int
//     - returns the numeric tenant id when the top-bar is set to a specific
//       tenant, or 0 for 'all' / not-set / non-numeric.
//     - safe to call for any role — returns 0 for non-superadmin sessions,
//       leaving existing per-role fallbacks (e.g. $session_tenant_id) intact.
//
//   sa_resolve_tenant_scope(?int $explicit) -> int
//     - returns $explicit when it is a positive int; otherwise defers to
//       sa_selected_tenant_id_int(). Use this at the top of every SA
//       tenant-sensitive query path.
//
// Non-goals:
//   - No changes to authorization: this file does not gate access.
//   - No cross-tenant privilege escalation: the helper is read-only.

if (!function_exists('sa_selected_tenant_id_int')) {
    function sa_selected_tenant_id_int(): int {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        // Only Super Admin has a top-bar tenant selector. Other roles have
        // their own session-scoped tenant_id which page code already reads.
        $role = $_SESSION['role'] ?? $_SESSION['role_type'] ?? '';
        if ($role !== 'superadmin') {
            return 0;
        }
        $sel = $_SESSION['selected_tenant_id'] ?? null;
        if ($sel === null || $sel === 'all') {
            return 0;
        }
        if (is_int($sel) && $sel > 0) {
            return $sel;
        }
        if (is_string($sel) && ctype_digit($sel) && (int)$sel > 0) {
            return (int)$sel;
        }
        return 0;
    }
}

if (!function_exists('sa_resolve_tenant_scope')) {
    function sa_resolve_tenant_scope($explicit): int {
        if (is_numeric($explicit) && (int)$explicit > 0) {
            return (int)$explicit;
        }
        return sa_selected_tenant_id_int();
    }
}
