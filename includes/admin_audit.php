<?php
// includes/admin_audit.php
// Centralized administrative audit trail for Tenant Admin mutations.
// Uses the existing `audit_logs` table so no schema migration is required.
//
// Contract:
//   record_admin_audit(PDO $pdo, string $action, string $table_name,
//                      ?int $record_id, ?array $old, ?array $new,
//                      ?int $tenant_id = null, ?int $user_id = null): void
//
// The helper is intentionally non-fatal: audit failure never blocks the
// primary mutation. When called inside a transaction, the audit insert
// participates in that transaction and commits or rolls back with it.

if (!function_exists('admin_audit_mask_sensitive')) {
    function admin_audit_mask_sensitive(?array $row): ?array {
        if ($row === null) return null;
        // Never persist password material, tokens, or provider secrets in the
        // audit trail. Redact by key name — case-insensitive.
        $sensitive = [
            'password', 'password_hash', 'passwd', 'pwd',
            'api_key', 'api_secret', 'secret', 'client_secret',
            'smtp_password', 'smtp_pass', 'mail_password',
            'whatsapp_token', 'access_token', 'refresh_token', 'auth_token',
            'private_key', 'session_key',
        ];
        $masked = [];
        foreach ($row as $k => $v) {
            $lower = strtolower((string)$k);
            $isSensitive = false;
            foreach ($sensitive as $needle) {
                if ($lower === $needle || strpos($lower, $needle) !== false) {
                    $isSensitive = true;
                    break;
                }
            }
            $masked[$k] = $isSensitive ? '***' : $v;
        }
        return $masked;
    }
}

if (!function_exists('record_admin_audit')) {
    function record_admin_audit(
        PDO $pdo,
        string $action,
        string $table_name,
        ?int $record_id,
        ?array $old,
        ?array $new,
        ?int $tenant_id = null,
        ?int $user_id = null
    ): void {
        try {
            $tenant_id = $tenant_id ?? (int)($_SESSION['tenant_id'] ?? 0) ?: null;
            $user_id   = $user_id   ?? (int)($_SESSION['user_id']   ?? 0) ?: null;
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $old_json = $old === null ? null : json_encode(admin_audit_mask_sensitive($old), JSON_UNESCAPED_UNICODE);
            $new_json = $new === null ? null : json_encode(admin_audit_mask_sensitive($new), JSON_UNESCAPED_UNICODE);

            $stmt = $pdo->prepare("
                INSERT INTO audit_logs
                    (tenant_id, user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $tenant_id, $user_id, $action, $table_name, $record_id,
                $old_json, $new_json, $ip, $ua
            ]);
        } catch (Throwable $e) {
            error_log('record_admin_audit failed: ' . $e->getMessage());
        }
    }
}
