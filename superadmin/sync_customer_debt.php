<?php
// superadmin/sync_customer_debt.php
// OBSOLETE data-repair utility. Not a production feature.
// Historical implementation referenced a hardcoded local path from a
// different developer machine and produced a raw Fatal error when opened
// in the browser. It is not linked from any sidebar or dashboard, and its
// business logic (recompute customers.debt_amount from invoices minus
// receipts) is already handled by the finance module's day-to-day flows.
//
// Access policy: not a browser-accessible route. Return 404 for any HTTP
// request. CLI runs are permitted so the historical maintenance workflow
// can still be re-implemented against the real config path if it is ever
// needed again.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// CLI-only historical placeholder: intentionally does nothing.
echo "sync_customer_debt.php: obsolete maintenance utility. Debt is derived from live invoices/receipts.\n";
