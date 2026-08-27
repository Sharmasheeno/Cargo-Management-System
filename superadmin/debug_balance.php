<?php
// superadmin/debug_balance.php
// OBSOLETE developer-only balance probe. Not a production feature.
// Historical implementation referenced a hardcoded local path from a
// different developer machine and produced a raw Fatal error when opened
// in the browser. It is not linked from any sidebar or dashboard.
//
// Access policy: not a browser-accessible route. Return 404 for any HTTP
// request. CLI runs are permitted so the historical debug workflow can
// still be re-implemented against the real config path if it is ever
// needed again.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// CLI-only historical placeholder: intentionally does nothing.
echo "debug_balance.php: obsolete developer probe. Use production reports instead.\n";
