<?php
// includes/tenant_chart_of_accounts.php
//
// Provisioning of the required control accounts on a per-tenant chart of
// accounts. Existing tenants (created before this file existed) can be
// backfilled by calling provisionTenantChartOfAccounts($pdo, $tenant_id)
// once; new tenants get it automatically at creation time via the
// save_tenant handler in superadmin/tenants.php.
//
// Design notes:
//   - Idempotent: only INSERTs rows the tenant is missing. Never mutates
//     an existing tenant-scoped row's balance, account_name, or
//     account_type. Running it twice is a no-op the second time.
//   - Source of truth for the default set: seven control accounts every
//     tenant needs to run the double-entry ledger this application
//     writes. See CONTROL_ACCOUNTS below. Balance always seeded to 0.00
//     because balance is a derived cache maintained by
//     AccountingService::postToLedger; a fresh account has no journal
//     history and therefore a $0 balance.
//   - Never touches the tenant-null "template" rows (ids 1-10 in the
//     current schema). Those exist as documentation of the standard
//     numbering; this function makes each tenant self-sufficient.
//
// Returns an int — number of rows INSERTed for this call.

if (!function_exists('provisionTenantChartOfAccounts')) {
    /**
     * Ensure the tenant has the required control-account rows. Idempotent.
     *
     * @param PDO $pdo
     * @param int $tenant_id  Positive tenant id.
     * @return int  Number of rows inserted (0 if already provisioned).
     */
    function provisionTenantChartOfAccounts(PDO $pdo, int $tenant_id): int {
        if ($tenant_id <= 0) return 0;

        // The seven control accounts every tenant needs. Codes follow the
        // rest of this codebase's conventions (Cash/AR/AP/Tax/Equity/Rev/
        // Expense) and match what AccountingService posts against.
        $CONTROL_ACCOUNTS = [
            ['code' => '1010', 'name' => 'Bank Accounts',       'type' => 'asset'],
            ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => 'asset'],
            ['code' => '2000', 'name' => 'Supplier Payables',   'type' => 'liability'],
            ['code' => '2100', 'name' => 'Sales Tax Payable',   'type' => 'liability'],
            ['code' => '3000', 'name' => 'Owner Equity',        'type' => 'equity'],
            ['code' => '4000', 'name' => 'Service Revenue',     'type' => 'revenue'],
            ['code' => '5000', 'name' => 'Operating Expenses',  'type' => 'expense'],
        ];

        // Read the set of codes that already exist for this tenant in
        // one round-trip so we can INSERT only what's missing.
        $sel = $pdo->prepare("SELECT account_code FROM chart_of_accounts WHERE tenant_id = ?");
        $sel->execute([$tenant_id]);
        $existing = [];
        foreach ($sel->fetchAll(PDO::FETCH_COLUMN, 0) as $c) {
            $existing[(string)$c] = true;
        }

        $ins = $pdo->prepare(
            "INSERT INTO chart_of_accounts (tenant_id, account_code, account_name, account_type, balance, is_active) "
          . "VALUES (?, ?, ?, ?, 0.00, 1)"
        );

        $inserted = 0;
        foreach ($CONTROL_ACCOUNTS as $acc) {
            if (isset($existing[$acc['code']])) continue;
            $ins->execute([$tenant_id, $acc['code'], $acc['name'], $acc['type']]);
            $inserted++;
        }
        return $inserted;
    }
}
