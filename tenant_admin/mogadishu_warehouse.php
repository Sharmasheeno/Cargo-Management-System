<?php
// tenant_admin/mogadishu_warehouse.php
// Warehouse Management System for Mogadishu Port - Tenant Admin
// Fixed: short WhatsApp messages, reusable WhatsApp config/helper, customer search instead of select, import/export preserved, HY093-safe import queries.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has tenant_admin or company_admin role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['tenant_admin', 'company_admin', 'superadmin', 'warehouse_supervisor', 'staff'])) {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION['role'];
// Normalize company_admin to tenant_admin for this page
if ($role === 'company_admin') {
    $role = 'tenant_admin';
    $_SESSION['role'] = 'tenant_admin';
}

// Ensure tenant_id is set for tenant_admin
$session_tenant_id = $_SESSION['tenant_id'] ?? 0;
if ($role !== 'superadmin' && $session_tenant_id == 0) {
    // Fallback: get tenant_id from user record
    require_once __DIR__ . '/../config/db_connect.php';
    $stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userTenant = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($userTenant && $userTenant['tenant_id']) {
        $session_tenant_id = $userTenant['tenant_id'];
        $_SESSION['tenant_id'] = $session_tenant_id;
    }
}

require_once __DIR__ . '/../config/db_connect.php';

$current_user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'User';

/**
 * Safe schema helper: avoids SQL errors when an optional column is missing.
 */
function tableColumnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function normalizeTelefoonDigits($phone): string {
    return preg_replace('/\D/', '', (string)$phone);
}

function customerBalanceExpression(PDO $pdo): string {
    if (tableColumnExists($pdo, 'customers', 'debt_amount')) return 'COALESCE(debt_amount, 0)';
    if (tableColumnExists($pdo, 'customers', 'balance')) return 'COALESCE(balance, 0)';
    if (tableColumnExists($pdo, 'customers', 'current_balance')) return 'COALESCE(current_balance, 0)';
    return '0';
}

function customerLoyaltyExpression(PDO $pdo): string {
    if (tableColumnExists($pdo, 'customers', 'loyalty_points')) return 'COALESCE(loyalty_points, 0)';
    if (tableColumnExists($pdo, 'customers', 'points')) return 'COALESCE(points, 0)';
    return '0';
}


/**
 * Rule: Mogadishu Warehouse wuxuu soo bandhigayaa/soo dhaweynayaa keliya alaabta
 * ku xiran kontaynar xaaladdiisu tahay delivered (La gaarsiiyay).
 */
function deliveredContainerExistsSql(string $warehouseAlias = 'ws'): string {
    return "EXISTS (
        SELECT 1
        FROM cargo_manifest_items cmi_rule
        JOIN containers cnt_rule
          ON cnt_rule.id = cmi_rule.container_id
         AND cnt_rule.tenant_id = cmi_rule.tenant_id
        WHERE cmi_rule.warehouse_stock_id = {$warehouseAlias}.id
          AND cmi_rule.tenant_id = {$warehouseAlias}.tenant_id
          AND cnt_rule.status = 'delivered'
    )";
}

function warehouseItemHasDeliveredContainer(PDO $pdo, int $warehouseStockId, int $tenantId): bool {
    $sql = "
        SELECT 1
        FROM warehouse_stock ws
        WHERE ws.id = ?
          AND ws.tenant_id = ?
          AND " . deliveredContainerExistsSql('ws') . "
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$warehouseStockId, $tenantId]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function getContainerStatusForWarehouseItem(PDO $pdo, int $warehouseStockId, int $tenantId): ?array {
    $stmt = $pdo->prepare("
        SELECT cnt.id, cnt.container_number, cnt.status
        FROM cargo_manifest_items cmi
        JOIN containers cnt ON cnt.id = cmi.container_id AND cnt.tenant_id = cmi.tenant_id
        WHERE cmi.warehouse_stock_id = ?
          AND cmi.tenant_id = ?
        ORDER BY cnt.id DESC
        LIMIT 1
    ");
    $stmt->execute([$warehouseStockId, $tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}


// Get tenant info for display
$tenant_info = [];
if ($session_tenant_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmt->execute([$session_tenant_id]);
    $tenant_info = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ── Ensure warehouse tables have required columns ───────────────────────────────────────────
try {
    // warehouse_stock table
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS mogadishu_status ENUM('not_arrived','in_warehouse','taken','delivered') NOT NULL DEFAULT 'not_arrived'");
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS mogadishu_received_date DATETIME DEFAULT NULL");
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS mogadishu_taken_date DATETIME DEFAULT NULL");
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS storage_fee DECIMAL(15,2) DEFAULT 0.00");
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS location VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS bin_location VARCHAR(100) DEFAULT NULL");
    $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS zone VARCHAR(50) DEFAULT NULL");
    
    // cargo_manifest_items table
    $pdo->exec("ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS mogadishu_status ENUM('not_arrived','in_warehouse','taken','delivered') NOT NULL DEFAULT 'not_arrived'");
    $pdo->exec("ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS mogadishu_received_date DATETIME DEFAULT NULL");
    $pdo->exec("ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS mogadishu_taken_date DATETIME DEFAULT NULL");
    $pdo->exec("ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS storage_fee DECIMAL(15,2) DEFAULT 0.00");
    
    // containers table
    $pdo->exec("ALTER TABLE containers ADD COLUMN IF NOT EXISTS customs_status ENUM('pending','cleared','held','inspected') DEFAULT 'pending'");
    $pdo->exec("ALTER TABLE containers ADD COLUMN IF NOT EXISTS eta_port DATE DEFAULT NULL");
    $pdo->exec("ALTER TABLE containers ADD COLUMN IF NOT EXISTS etd_port DATE DEFAULT NULL");
    $pdo->exec("ALTER TABLE containers ADD COLUMN IF NOT EXISTS current_branch_id INT(11) DEFAULT NULL");
} catch (PDOException $e) {
    // Ignore errors if columns already exist
}

// Customer selection uses AJAX search input, not a normal <select>.
$customers = [];

// Get containers for this tenant
$containers = [];
if ($session_tenant_id > 0) {
    $stmt = $pdo->prepare("SELECT id, container_number, container_type FROM containers WHERE tenant_id = ? ORDER BY id DESC");
    $stmt->execute([$session_tenant_id]);
    $containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Status definitions
$mogadishu_statuses = [
    'not_arrived' => 'Aan Imaanin 🚫',
    'in_warehouse' => 'Bakhaarka 📦',
    'taken' => 'La Qaaday ✅',
    'delivered' => 'La Gaarsiiyay 🎯'
];

$status_colors = [
    'not_arrived' => '#EF4444',
    'in_warehouse' => '#F59E0B',
    'taken' => '#10B981',
    'delivered' => '#06B6D4'
];

$customs_statuses = [
    'pending' => 'Sugaya ⏳',
    'cleared' => 'La Fasaxay ✅',
    'held' => 'La Qabtay ❌',
    'inspected' => 'La Kormeeray 🔍'
];

$customs_colors = [
    'pending' => '#F59E0B',
    'cleared' => '#10B981',
    'held' => '#EF4444',
    'inspected' => '#3B82F6'
];


// ==============================================
// WHATSAPP AUTOMATIC MESSAGES - MOGADISHU WAREHOUSE
// ==============================================
// Reusable WhatsApp API files. Haddii files-kaani jiraan, halkan ayaa laga soo call-gareynayaa.
$greenConfig = __DIR__ . '/../config/greenapi_config.php';
$waHelper = __DIR__ . '/../includes/whatsapp_helper.php';
if (file_exists($greenConfig)) {
    require_once $greenConfig;
}
if (file_exists($waHelper)) {
    require_once $waHelper;
}

// Fallback values haddii config files-ka aysan constants/variables bixin.
// Production: xogtan geli config/greenapi_config.php ama ENV, ha ku adkeynin page kasta.
$GREEN_API_ID = defined('GREEN_API_ID') ? GREEN_API_ID : (getenv('GREEN_API_ID') ?: '');
$GREEN_API_TOKEN = defined('GREEN_API_TOKEN') ? GREEN_API_TOKEN : (getenv('GREEN_API_TOKEN') ?: '');
$GREEN_API_URL = defined('GREEN_API_URL') ? GREEN_API_URL : (getenv('GREEN_API_URL') ?: '');

function ensureMogadishuWhatsAppLogTable(PDO $pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `whatsapp_warehouse_logs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `tenant_id` int(11) NOT NULL,
            `warehouse_stock_id` int(11) DEFAULT NULL,
            `customer_id` int(11) DEFAULT NULL,
            `action` varchar(50) NOT NULL,
            `phone` varchar(50) NOT NULL,
            `message` text NOT NULL,
            `send_status` varchar(20) DEFAULT 'pending',
            `api_response` text DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_whatsapp_warehouse_unique_check` (`tenant_id`, `warehouse_stock_id`, `action`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        error_log('WhatsApp warehouse log table error: ' . $e->getMessage());
    }
}

function alreadySentWarehouseWhatsApp(PDO $pdo, $tenantId, $warehouseStockId, $action) {
    ensureMogadishuWhatsAppLogTable($pdo);
    $stmt = $pdo->prepare("SELECT id FROM whatsapp_warehouse_logs WHERE tenant_id = ? AND warehouse_stock_id = ? AND action = ? AND send_status = 'sent' LIMIT 1");
    $stmt->execute([$tenantId, $warehouseStockId, $action]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function logWarehouseWhatsApp(PDO $pdo, $tenantId, $warehouseStockId, $customerId, $action, $phone, $message, array $result) {
    ensureMogadishuWhatsAppLogTable($pdo);
    $status = !empty($result['success']) ? 'sent' : 'failed';
    $stmt = $pdo->prepare("INSERT INTO whatsapp_warehouse_logs
        (tenant_id, warehouse_stock_id, customer_id, action, phone, message, send_status, api_response, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([
        $tenantId,
        $warehouseStockId,
        $customerId,
        $action,
        $phone,
        $message,
        $status,
        json_encode($result, JSON_UNESCAPED_UNICODE)
    ]);
}

function formatSomaliTelefoonForMogadishuWarehouse($phone) {
    $phone = preg_replace('/\D/', '', (string)$phone);
    if ($phone === '') return '';
    if (strlen($phone) === 9 && in_array($phone[0], ['6', '7'], true)) return '252' . $phone;
    if (strlen($phone) === 10 && $phone[0] === '0') return '252' . substr($phone, 1);
    if (strlen($phone) === 12 && substr($phone, 0, 3) === '252') return $phone;
    return '252' . ltrim($phone, '0');
}

function sendMogadishuWarehouseWhatsApp($phone, $message, $idInstance, $apiToken, $apiUrl) {
    $formattedTelefoon = formatSomaliTelefoonForMogadishuWarehouse($phone);
    if ($formattedTelefoon === '') {
        return ['success' => false, 'message' => 'Telefoon sax ah lama helin'];
    }

    if (trim((string)$message) === '') {
        return ['success' => false, 'message' => 'Fariin madhan lama diri karo'];
    }

    if (!function_exists('curl_init')) {
        return ['success' => false, 'message' => 'PHP cURL extension lama shidin. XAMPP/php.ini ka fur extension=curl'];
    }

    $payload = [
        'chatId' => $formattedTelefoon . '@c.us',
        'message' => $message
    ];

    $baseUrl = rtrim((string)$apiUrl, '/');
    $idInstance = trim((string)$idInstance);
    $apiToken = trim((string)$apiToken);

    if ($baseUrl === '' || $idInstance === '' || $apiToken === '') {
        return ['success' => false, 'message' => 'GreenAPI config lama helin: apiUrl/idInstance/apiToken hubi'];
    }

    // GreenAPI endpoint sax ah waa sendMessage. SendMessage fallback ahaan ayaa loo dayay.
    $endpoints = ['sendMessage', 'SendMessage'];
    $last = null;

    foreach ($endpoints as $endpoint) {
        $url = $baseUrl . "/waInstance{$idInstance}/{$endpoint}/{$apiToken}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 35
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $decoded = json_decode((string)$response, true);

        if ($httpCode >= 200 && $httpCode < 300 && is_array($decoded) && !empty($decoded['idMessage'])) {
            return [
                'success' => true,
                'message' => 'WhatsApp waa la diray',
                'message_id' => $decoded['idMessage'],
                'endpoint' => $endpoint,
                'chatId' => $payload['chatId'],
                'http_code' => $httpCode,
                'api_response' => $decoded
            ];
        }

        $last = [
            'success' => false,
            'message' => $curlError ?: ($decoded['message'] ?? $decoded['error'] ?? $response ?? 'WhatsApp API error'),
            'http_code' => $httpCode,
            'endpoint' => $endpoint,
            'chatId' => $payload['chatId'],
            'api_response' => $decoded ?: $response
        ];
    }

    error_log('Mogadishu WhatsApp failed: ' . json_encode($last, JSON_UNESCAPED_UNICODE));
    return $last ?: ['success' => false, 'message' => 'WhatsApp lama dirin'];
}

function getMogadishuTenantName(PDO $pdo, $tenantId) {
    $stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    return $tenant['name'] ?? 'Shirkadda';
}

function getWarehouseItemWhatsAppInfo(PDO $pdo, $warehouseStockId, $tenantId) {
    $stmt = $pdo->prepare("
        SELECT
            ws.id,
            ws.tenant_id,
            ws.customer_id,
            ws.stock_name,
            ws.quantity,
            ws.volume_cbm,
            ws.unit_price,
            ws.location,
            ws.bin_location,
            ws.zone,
            ws.mogadishu_received_date,
            ws.storage_fee,
            c.customer_name,
            c.phone AS customer_phone,
            cnt.container_number,
            cnt.tracking_number,
            cnt.bl_number
        FROM warehouse_stock ws
        LEFT JOIN customers c ON ws.customer_id = c.id AND c.tenant_id = ws.tenant_id
        LEFT JOIN cargo_manifest_items cmi ON cmi.warehouse_stock_id = ws.id AND cmi.tenant_id = ws.tenant_id
        LEFT JOIN containers cnt ON cnt.id = cmi.container_id AND cnt.tenant_id = ws.tenant_id
        WHERE ws.id = ? AND ws.tenant_id = ?
        GROUP BY ws.id
        LIMIT 1
    ");
    $stmt->execute([$warehouseStockId, $tenantId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function buildWarehouseLocationText(array $item) {
    $parts = [];
    if (!empty($item['location'])) $parts[] = 'Goobta: ' . $item['location'];
    if (!empty($item['bin_location'])) $parts[] = 'Bin: ' . $item['bin_location'];
    if (!empty($item['zone'])) $parts[] = 'Zone: ' . $item['zone'];
    return $parts ? implode(' | ', $parts) : 'Goobta bakhaarka wali lama cayimin';
}

function buildMogadishuArrivalMessage(array $item, $companyName) {
    $customerName = $item['customer_name'] ?: 'Macaamiil';
    $itemName = $item['stock_name'] ?? '-';
    $qty = max(1, (int)($item['quantity'] ?? 1));
    $unitCbm = (float)($item['volume_cbm'] ?? 0);
    $totalCbm = $unitCbm * $qty;
    $locationText = buildWarehouseLocationText($item);
    $receivedDate = !empty($item['mogadishu_received_date'])
        ? date('d/m/Y H:i', strtotime($item['mogadishu_received_date']))
        : date('d/m/Y H:i');

    $message  = "📦 Alaab timid\n";
    $message .= "Macmiil: {$customerName}\n";
    $message .= "Alaab: {$itemName}\n";
    $message .= "Qty: {$qty}\n";
    $message .= "CBM: " . number_format($totalCbm, 4) . "\n";

    if (!empty($item['container_number'])) {
        $message .= "Container: {$item['container_number']}\n";
    }

    $message .= "Goob: {$locationText}\n";
    $message .= "Date: {$receivedDate}\n";
    $message .= $companyName;

    return $message;
}

function buildSevenDayStorageFeeMessage(array $item, $companyName) {
    $customerName = $item['customer_name'] ?: 'Macaamiil';
    $itemName = $item['stock_name'] ?? '-';
    $qty = max(1, (int)($item['quantity'] ?? 1));
    $unitCbm = (float)($item['volume_cbm'] ?? 0);
    $totalCbm = $unitCbm * $qty;
    $days = !empty($item['mogadishu_received_date'])
        ? max(7, (int)floor((time() - strtotime($item['mogadishu_received_date'])) / 86400))
        : 7;

    $message  = "⚠️ Xusuusin kaydin\n";
    $message .= "Macmiil: {$customerName}\n";
    $message .= "Alaab: {$itemName}\n";
    $message .= "Qty: {$qty}\n";
    $message .= "CBM: " . number_format($totalCbm, 4) . "\n";
    $message .= "Maalmo: {$days}\n";

    if (!empty($item['container_number'])) {
        $message .= "Container: {$item['container_number']}\n";
    }

    $message .= "Fadlan soo qaado alaabta.\n";
    $message .= $companyName;

    return $message;
}

function sendWarehouseArrivalWhatsApp(PDO $pdo, $warehouseStockId, $tenantId) {
    global $GREEN_API_ID, $GREEN_API_TOKEN, $GREEN_API_URL;
    $item = getWarehouseItemWhatsAppInfo($pdo, $warehouseStockId, $tenantId);
    if (!$item || empty($item['customer_phone'])) {
        return ['success' => false, 'message' => 'Telefoonka macaamiilka lama helin'];
    }
    if (alreadySentWarehouseWhatsApp($pdo, $tenantId, $warehouseStockId, 'arrival_in_warehouse')) {
        return ['success' => true, 'message' => 'Fariinta imaanshaha horay ayaa loo diray'];
    }

    $companyName = getMogadishuTenantName($pdo, $tenantId);
    $message = buildMogadishuArrivalMessage($item, $companyName);
    $result = sendMogadishuWarehouseWhatsApp($item['customer_phone'], $message, $GREEN_API_ID, $GREEN_API_TOKEN, $GREEN_API_URL);
    logWarehouseWhatsApp($pdo, $tenantId, $warehouseStockId, $item['customer_id'] ?? null, 'arrival_in_warehouse', $item['customer_phone'], $message, $result);
    return $result;
}

function sendWarehouseSevenDayFeeReminders(PDO $pdo, $tenantId, $limit = 20) {
    global $GREEN_API_ID, $GREEN_API_TOKEN, $GREEN_API_URL;
    ensureMogadishuWhatsAppLogTable($pdo);
    $stmt = $pdo->prepare("
        SELECT ws.id
        FROM warehouse_stock ws
        LEFT JOIN whatsapp_warehouse_logs wl
            ON wl.tenant_id = ws.tenant_id
           AND wl.warehouse_stock_id = ws.id
           AND wl.action = 'fee_after_7_days'
           AND wl.send_status = 'sent'
        WHERE ws.tenant_id = ?
          AND ws.mogadishu_status = 'in_warehouse'
          AND ws.mogadishu_received_date IS NOT NULL
          AND DATEDIFF(NOW(), ws.mogadishu_received_date) >= 7
          AND wl.id IS NULL
        ORDER BY ws.mogadishu_received_date ASC
        LIMIT " . (int)$limit
    );
    $stmt->execute([$tenantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sent = 0;
    $failed = 0;
    $companyName = getMogadishuTenantName($pdo, $tenantId);

    foreach ($rows as $row) {
        $item = getWarehouseItemWhatsAppInfo($pdo, $row['id'], $tenantId);
        if (!$item || empty($item['customer_phone'])) {
            $failed++;
            continue;
        }
        $message = buildSevenDayStorageFeeMessage($item, $companyName);
        $result = sendMogadishuWarehouseWhatsApp($item['customer_phone'], $message, $GREEN_API_ID, $GREEN_API_TOKEN, $GREEN_API_URL);
        logWarehouseWhatsApp($pdo, $tenantId, $item['id'], $item['customer_id'] ?? null, 'fee_after_7_days', $item['customer_phone'], $message, $result);
        if (!empty($result['success'])) $sent++; else $failed++;
    }

    return ['success' => true, 'sent' => $sent, 'failed' => $failed];
}


// Handle Export/Template Actions (GET)
if (isset($_GET['action'])) {
    $get_action = $_GET['action'];

    if ($get_action === 'export_mogadishu_warehouse') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=mogadishu_warehouse_export_' . date('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, [
            'id', 'customer_name', 'customer_phone', 'container_number', 'stock_name',
            'quantity', 'length_cm', 'width_cm', 'height_cm', 'volume_cbm', 'unit_price',
            'total_amount', 'mogadishu_status', 'mogadishu_received_date', 'mogadishu_taken_date',
            'storage_fee', 'location', 'bin_location', 'zone', 'origin'
        ]);

        $where_conditions = ['ws.tenant_id = ?', deliveredContainerExistsSql('ws')];
        $params = [$session_tenant_id];
        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');

        if ($search !== '') {
            $where_conditions[] = "(ws.stock_name LIKE ? OR c.customer_name LIKE ? OR c.phone LIKE ? OR ws.location LIKE ? OR ws.bin_location LIKE ? OR cnt.container_number LIKE ?)";
            $like = "%{$search}%";
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if ($status !== '') {
            $where_conditions[] = 'ws.mogadishu_status = ?';
            $params[] = $status;
        }

        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        $sql = "
            SELECT
                ws.id,
                c.customer_name,
                c.phone AS customer_phone,
                cnt.container_number,
                ws.stock_name,
                ws.quantity,
                ws.length_cm,
                ws.width_cm,
                ws.height_cm,
                ws.volume_cbm,
                ws.unit_price,
                (COALESCE(ws.volume_cbm,0) * GREATEST(COALESCE(ws.quantity,0),1) * COALESCE(ws.unit_price,0)) AS total_amount,
                ws.mogadishu_status,
                ws.mogadishu_received_date,
                ws.mogadishu_taken_date,
                ws.storage_fee,
                ws.location,
                ws.bin_location,
                ws.zone,
                ws.origin
            FROM warehouse_stock ws
            LEFT JOIN customers c ON ws.customer_id = c.id AND c.tenant_id = ws.tenant_id
            LEFT JOIN cargo_manifest_items cmi ON cmi.warehouse_stock_id = ws.id AND cmi.tenant_id = ws.tenant_id
            LEFT JOIN containers cnt ON cmi.container_id = cnt.id AND cnt.tenant_id = ws.tenant_id
            $where_clause
            GROUP BY ws.id
            ORDER BY ws.id DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    if ($get_action === 'download_mogadishu_import_template') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=mogadishu_warehouse_import_template.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($output, [
            'id', 'customer_name', 'customer_phone', 'container_number', 'stock_name',
            'quantity', 'length_cm', 'width_cm', 'height_cm', 'volume_cbm', 'unit_price',
            'mogadishu_status', 'mogadishu_received_date', 'mogadishu_taken_date',
            'storage_fee', 'location', 'bin_location', 'zone', 'origin'
        ]);
        fputcsv($output, [
            '', 'Ahmed Ali', '25261XXXXXXX', 'CONT-001', 'BAGAASH',
            '1', '50', '40', '30', '', '15.00',
            'in_warehouse', date('Y-m-d H:i:s'), '',
            '0.00', 'Shelf A-1', 'BIN-001', 'Zone A', 'local'
        ]);
        fclose($output);
        exit;
    }
}

function normalizeWarehouseImportDate($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    $time = strtotime($value);
    return $time ? date('Y-m-d H:i:s', $time) : null;
}

function findOrCreateWarehouseCustomer($pdo, $tenant_id, $customer_name, $customer_phone) {
    $customer_name = trim((string)$customer_name);
    $customer_phone = trim((string)$customer_phone);
    if ($customer_phone === '' && $customer_name === '') return null;

    if ($customer_phone !== '') {
        $stmt = $pdo->prepare("SELECT id FROM customers WHERE tenant_id = ? AND phone = ? LIMIT 1");
        $stmt->execute([$tenant_id, $customer_phone]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($found) return (int)$found['id'];

        $normalized = preg_replace('/\D/', '', $customer_phone);
        $stmt = $pdo->prepare("SELECT id FROM customers WHERE tenant_id = ? AND REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', '') = ? LIMIT 1");
        $stmt->execute([$tenant_id, $normalized]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($found) return (int)$found['id'];
    }

    if ($customer_name === '') $customer_name = 'Macaamiil';
    if ($customer_phone === '') $customer_phone = 'N/A-' . time() . rand(100,999);

    $ins = $pdo->prepare("INSERT INTO customers (tenant_id, customer_name, phone, is_active, created_at) VALUES (?, ?, ?, 1, NOW())");
    $ins->execute([$tenant_id, $customer_name, $customer_phone]);
    return (int)$pdo->lastInsertId();
}

function findWarehouseContainerId($pdo, $tenant_id, $container_number) {
    $container_number = trim((string)$container_number);
    if ($container_number === '') return null;
    $stmt = $pdo->prepare("SELECT id FROM containers WHERE tenant_id = ? AND container_number = ? LIMIT 1");
    $stmt->execute([$tenant_id, $container_number]);
    $found = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($found) return (int)$found['id'];

    $ins = $pdo->prepare("INSERT INTO containers (tenant_id, container_number, container_type, origin, status, created_at) VALUES (?, ?, '20ft', 'local', 'received', NOW())");
    $ins->execute([$tenant_id, $container_number]);
    return (int)$pdo->lastInsertId();
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];

    // Test WhatsApp direct from this page
    if ($action === 'test_whatsapp') {
        $phone = trim($_POST['phone'] ?? '');
        $message = trim($_POST['message'] ?? 'Test WhatsApp from Mogadishu Warehouse');
        if ($phone === '') {
            echo json_encode(['success' => false, 'message' => 'Fadlan geli telefoon']);
            exit;
        }
        $result = sendMogadishuWarehouseWhatsApp($phone, $message, $GREEN_API_ID, $GREEN_API_TOKEN, $GREEN_API_URL);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Customer search by name/phone - no customer <select>
    if ($action === 'search_customers') {
        $q = trim($_POST['q'] ?? '');
        $where = "tenant_id = ? AND is_active = 1";
        $params = [$session_tenant_id];

        if ($q !== '') {
            $where .= " AND (customer_name LIKE ? OR phone LIKE ?)";
            $like = "%{$q}%";
            $params[] = $like;
            $params[] = $like;
        }

        $balanceExpr = customerBalanceExpression($pdo);
        $loyaltyExpr = customerLoyaltyExpression($pdo);

        $stmt = $pdo->prepare("
            SELECT id, customer_name, phone,
                   {$balanceExpr} AS balance,
                   {$loyaltyExpr} AS loyalty_points
            FROM customers
            WHERE {$where}
            ORDER BY customer_name ASC
            LIMIT 20
        ");
        $stmt->execute($params);
        echo json_encode(['success' => true, 'customers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'quick_add_customer') {
        $name = trim($_POST['customer_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Magaca macmiilka waa qasab']);
            exit;
        }

        if ($phone !== '') {
            $phoneKey = normalizeTelefoonDigits($phone);
            $chk = $pdo->prepare("
                SELECT id, customer_name, phone
                FROM customers
                WHERE tenant_id = ?
                  AND REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '_', '') = ?
                LIMIT 1
            ");
            $chk->execute([$session_tenant_id, $phoneKey]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Macmiilkan horay ayuu u jiray, waana la doortay.',
                    'customer' => [
                        'id' => (int)$existing['id'],
                        'customer_name' => $existing['customer_name'],
                        'phone' => $existing['phone'],
                        'balance' => 0,
                        'loyalty_points' => 0
                    ]
                ]);
                exit;
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO customers (tenant_id, customer_name, phone, email, address, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$session_tenant_id, $name, $phone, $email, $address]);
        $newId = (int)$pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'customer' => [
                'id' => $newId,
                'customer_name' => $name,
                'phone' => $phone,
                'balance' => 0,
                'loyalty_points' => 0
            ]
        ]);
        exit;
    }

    // Import warehouse CSV
    if ($action === 'import_mogadishu_warehouse') {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Fadlan dooro CSV file sax ah.']);
            exit;
        }

        $handle = fopen($_FILES['import_file']['tmp_name'], 'r');
        if (!$handle) {
            echo json_encode(['success' => false, 'message' => 'File-ka lama furi karin.']);
            exit;
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            echo json_encode(['success' => false, 'message' => 'CSV file-ku waa madhan yahay.']);
            exit;
        }

        $header = array_map(function($h) {
            $h = preg_replace('/^\xEF\xBB\xBF/', '', (string)$h);
            return strtolower(trim($h));
        }, $header);

        $allowed_statuses = ['not_arrived', 'in_warehouse', 'taken', 'delivered'];
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $rowNumber = 1;

        try {
            $pdo->beginTransaction();

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue;

                $data = [];
                foreach ($header as $i => $key) {
                    $data[$key] = trim($row[$i] ?? '');
                }

                $stock_id = !empty($data['id']) ? (int)$data['id'] : 0;
                $stock_name = trim($data['stock_name'] ?? '');
                if ($stock_name === '') {
                    $skipped++;
                    $errors[] = "Row {$rowNumber}: stock_name waa madhan yahay.";
                    continue;
                }

                $customer_id = findOrCreateWarehouseCustomer($pdo, $session_tenant_id, $data['customer_name'] ?? '', $data['customer_phone'] ?? '');
                $container_id = findWarehouseContainerId($pdo, $session_tenant_id, $data['container_number'] ?? '');
                $container_is_delivered = false;
                if ($container_id) {
                    $containerStatusStmt = $pdo->prepare("SELECT status FROM containers WHERE id = ? AND tenant_id = ? LIMIT 1");
                    $containerStatusStmt->execute([$container_id, $session_tenant_id]);
                    $containerStatusRow = $containerStatusStmt->fetch(PDO::FETCH_ASSOC);
                    $container_is_delivered = (($containerStatusRow['status'] ?? '') === 'delivered');
                }

                $quantity = isset($data['quantity']) && $data['quantity'] !== '' ? (int)$data['quantity'] : 0;
                $length_cm = isset($data['length_cm']) && $data['length_cm'] !== '' ? (float)$data['length_cm'] : 0;
                $width_cm  = isset($data['width_cm']) && $data['width_cm'] !== '' ? (float)$data['width_cm'] : 0;
                $height_cm = isset($data['height_cm']) && $data['height_cm'] !== '' ? (float)$data['height_cm'] : 0;
                $volume_cbm = isset($data['volume_cbm']) && $data['volume_cbm'] !== '' ? (float)$data['volume_cbm'] : 0;
                if ($volume_cbm <= 0 && $length_cm > 0 && $width_cm > 0 && $height_cm > 0) {
                    $volume_cbm = ($length_cm * $width_cm * $height_cm) / 1000000;
                }

                $unit_price = isset($data['unit_price']) && $data['unit_price'] !== '' ? (float)$data['unit_price'] : 0;
                $storage_fee = isset($data['storage_fee']) && $data['storage_fee'] !== '' ? (float)$data['storage_fee'] : 0;
                $status = $data['mogadishu_status'] ?? 'not_arrived';
                if (!in_array($status, $allowed_statuses, true)) $status = 'not_arrived';
                if (!$container_is_delivered && $status !== 'not_arrived') {
                    $skipped++;
                    $errors[] = "Row {$rowNumber}: Kontaynar-ka lama gaarsiin weli; alaabtan Bakhaarka Muqdisho lama gelin karo.";
                    continue;
                }

                $received_date = normalizeWarehouseImportDate($data['mogadishu_received_date'] ?? '');
                $taken_date = normalizeWarehouseImportDate($data['mogadishu_taken_date'] ?? '');
                if ($status === 'in_warehouse' && !$received_date) $received_date = date('Y-m-d H:i:s');
                if (($status === 'taken' || $status === 'delivered') && !$taken_date) $taken_date = date('Y-m-d H:i:s');

                $location = trim($data['location'] ?? '');
                $bin_location = trim($data['bin_location'] ?? '');
                $zone = trim($data['zone'] ?? '');
                $origin = trim($data['origin'] ?? 'local');
                if (!in_array($origin, ['china_yiwu','china_guangzhou','dubai','local'], true)) $origin = 'local';

                if ($stock_id > 0) {
                    $check = $pdo->prepare("SELECT id FROM warehouse_stock WHERE id = ? AND tenant_id = ? LIMIT 1");
                    $check->execute([$stock_id, $session_tenant_id]);
                    if ($check->fetch(PDO::FETCH_ASSOC)) {
                        $upd = $pdo->prepare("UPDATE warehouse_stock
                            SET customer_id = :customer_id,
                                stock_name = :stock_name,
                                quantity = :quantity,
                                length_cm = :length_cm,
                                width_cm = :width_cm,
                                height_cm = :height_cm,
                                volume_cbm = :volume_cbm,
                                unit_price = :unit_price,
                                mogadishu_status = :mogadishu_status,
                                mogadishu_received_date = :mogadishu_received_date,
                                mogadishu_taken_date = :mogadishu_taken_date,
                                storage_fee = :storage_fee,
                                location = :location,
                                bin_location = :bin_location,
                                zone = :zone,
                                origin = :origin,
                                updated_by = :updated_by,
                                last_updated = NOW()
                            WHERE id = :id AND tenant_id = :tenant_id");
                        $upd->execute([
                            ':customer_id' => $customer_id,
                            ':stock_name' => $stock_name,
                            ':quantity' => $quantity,
                            ':length_cm' => $length_cm,
                            ':width_cm' => $width_cm,
                            ':height_cm' => $height_cm,
                            ':volume_cbm' => $volume_cbm,
                            ':unit_price' => $unit_price,
                            ':mogadishu_status' => $status,
                            ':mogadishu_received_date' => $received_date,
                            ':mogadishu_taken_date' => $taken_date,
                            ':storage_fee' => $storage_fee,
                            ':location' => $location,
                            ':bin_location' => $bin_location,
                            ':zone' => $zone,
                            ':origin' => $origin,
                            ':updated_by' => $current_user_id,
                            ':id' => $stock_id,
                            ':tenant_id' => $session_tenant_id
                        ]);
                        $warehouse_stock_id = $stock_id;
                        $updated++;
                    } else {
                        $stock_id = 0;
                    }
                }

                if ($stock_id <= 0) {
                    $ins = $pdo->prepare("INSERT INTO warehouse_stock
                        (tenant_id, customer_id, stock_name, quantity, length_cm, width_cm, height_cm,
                         volume_cbm, unit_price, mogadishu_status, mogadishu_received_date, mogadishu_taken_date,
                         storage_fee, location, bin_location, zone, origin, updated_by, last_updated, created_at)
                        VALUES (:tenant_id, :customer_id, :stock_name, :quantity, :length_cm, :width_cm, :height_cm,
                                :volume_cbm, :unit_price, :mogadishu_status, :mogadishu_received_date, :mogadishu_taken_date,
                                :storage_fee, :location, :bin_location, :zone, :origin, :updated_by, NOW(), NOW())");
                    $ins->execute([
                        ':tenant_id' => $session_tenant_id,
                        ':customer_id' => $customer_id,
                        ':stock_name' => $stock_name,
                        ':quantity' => $quantity,
                        ':length_cm' => $length_cm,
                        ':width_cm' => $width_cm,
                        ':height_cm' => $height_cm,
                        ':volume_cbm' => $volume_cbm,
                        ':unit_price' => $unit_price,
                        ':mogadishu_status' => $status,
                        ':mogadishu_received_date' => $received_date,
                        ':mogadishu_taken_date' => $taken_date,
                        ':storage_fee' => $storage_fee,
                        ':location' => $location,
                        ':bin_location' => $bin_location,
                        ':zone' => $zone,
                        ':origin' => $origin,
                        ':updated_by' => $current_user_id
                    ]);
                    $warehouse_stock_id = (int)$pdo->lastInsertId();
                    $inserted++;
                }

                if ($container_id) {
                    $manifestCheck = $pdo->prepare("SELECT id FROM cargo_manifest_items WHERE tenant_id = ? AND warehouse_stock_id = ? AND container_id = ? LIMIT 1");
                    $manifestCheck->execute([$session_tenant_id, $warehouse_stock_id, $container_id]);
                    $manifest = $manifestCheck->fetch(PDO::FETCH_ASSOC);
                    if ($manifest) {
                        $mupd = $pdo->prepare("UPDATE cargo_manifest_items
                            SET stock_name = ?, quantity = ?, cbm_used = ?, unit_price = ?, storage_fee = ?,
                                mogadishu_status = ?, mogadishu_received_date = ?, mogadishu_taken_date = ?
                            WHERE id = ? AND tenant_id = ?");
                        $mupd->execute([$stock_name, $quantity, $volume_cbm * max(1, $quantity), $unit_price, $storage_fee, $status, $received_date, $taken_date, $manifest['id'], $session_tenant_id]);
                    } else {
                        $mins = $pdo->prepare("INSERT INTO cargo_manifest_items
                            (tenant_id, container_id, warehouse_stock_id, stock_name, quantity, cbm_used, unit_price, storage_fee,
                             mogadishu_status, mogadishu_received_date, mogadishu_taken_date, added_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $mins->execute([$session_tenant_id, $container_id, $warehouse_stock_id, $stock_name, $quantity, $volume_cbm * max(1, $quantity), $unit_price, $storage_fee, $status, $received_date, $taken_date]);
                    }
                }
            }

            fclose($handle);
            $pdo->commit();
            echo json_encode([
                'success' => true,
                'message' => "Import waa dhammaaday: {$inserted} waa la geliyay, {$updated} waa la cusboonaysiiyay, {$skipped} waa la dhaafay.",
                'inserted' => $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => array_slice($errors, 0, 10)
            ]);
        } catch (Exception $e) {
            if (is_resource($handle)) fclose($handle);
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Import khalad ayuu galay: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Get warehouse statistics
    if ($action === 'get_stats') {
        try {
            // Automatic 7-day storage-fee reminder. Log table prevents duplicate messages.
            $feeReminderResult = sendWarehouseSevenDayFeeReminders($pdo, $session_tenant_id, 20);

            // Total items in warehouse for this tenant
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM warehouse_stock ws WHERE ws.tenant_id = ? AND ws.mogadishu_status = 'in_warehouse' AND " . deliveredContainerExistsSql('ws') . "");
            $stmt->execute([$session_tenant_id]);
            $total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Total quantity
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(ws.quantity), 0) as total_qty FROM warehouse_stock ws WHERE ws.tenant_id = ? AND ws.mogadishu_status = 'in_warehouse' AND " . deliveredContainerExistsSql('ws') . "");
            $stmt->execute([$session_tenant_id]);
            $total_qty = $stmt->fetch(PDO::FETCH_ASSOC)['total_qty'];
            
            // Total storage fee
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(ws.storage_fee), 0) as total_fee FROM warehouse_stock ws WHERE ws.tenant_id = ? AND ws.mogadishu_status = 'in_warehouse' AND " . deliveredContainerExistsSql('ws') . "");
            $stmt->execute([$session_tenant_id]);
            $total_fee = $stmt->fetch(PDO::FETCH_ASSOC)['total_fee'];
            
            // Containers at port (pending customs)
            $stmt = $pdo->prepare("SELECT COUNT(*) as containers FROM containers WHERE tenant_id = ? AND customs_status = 'pending'");
            $stmt->execute([$session_tenant_id]);
            $pending_containers = $stmt->fetch(PDO::FETCH_ASSOC)['containers'];
            
            // Items waiting to arrive
            $stmt = $pdo->prepare("SELECT COUNT(*) as waiting FROM warehouse_stock ws WHERE ws.tenant_id = ? AND ws.mogadishu_status = 'not_arrived' AND " . deliveredContainerExistsSql('ws') . "");
            $stmt->execute([$session_tenant_id]);
            $waiting_items = $stmt->fetch(PDO::FETCH_ASSOC)['waiting'];
            
            // Items taken this month
            $stmt = $pdo->prepare("SELECT COUNT(*) as taken FROM warehouse_stock ws WHERE ws.tenant_id = ? AND ws.mogadishu_status = 'taken' AND MONTH(ws.mogadishu_taken_date) = MONTH(CURDATE()) AND " . deliveredContainerExistsSql('ws') . "");
            $stmt->execute([$session_tenant_id]);
            $taken_items = $stmt->fetch(PDO::FETCH_ASSOC)['taken'];
            
            echo json_encode([
                'total_items' => $total_items,
                'total_quantity' => $total_qty,
                'total_storage_fee' => $total_fee,
                'pending_containers' => $pending_containers,
                'waiting_items' => $waiting_items,
                'taken_items' => $taken_items,
                'fee_reminders' => $feeReminderResult ?? ['sent' => 0, 'failed' => 0]
            ]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
    
    // Get warehouse items
    elseif ($action === 'get_warehouse_items') {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        $search = $_POST['search'] ?? '';
        $status_filter = $_POST['status'] ?? '';
        
        $where_conditions = ["ws.tenant_id = ?", deliveredContainerExistsSql('ws')];
        $params = [$session_tenant_id];
        
        if (!empty($search)) {
            $where_conditions[] = "(ws.stock_name LIKE ? OR c.customer_name LIKE ? OR ws.location LIKE ? OR ws.bin_location LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if (!empty($status_filter)) {
            $where_conditions[] = "ws.mogadishu_status = ?";
            $params[] = $status_filter;
        }
        
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
        
        $count_sql = "SELECT COUNT(*) as total FROM warehouse_stock ws
                      LEFT JOIN customers c ON ws.customer_id = c.id
                      $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_items / $limit);
        
        $sql = "
            SELECT ws.*, 
                   c.customer_name, c.phone as customer_phone,
                   DATEDIFF(NOW(), ws.mogadishu_received_date) as days_in_storage,
                   (DATEDIFF(NOW(), ws.mogadishu_received_date) * 0.50) as calculated_storage_fee
            FROM warehouse_stock ws
            LEFT JOIN customers c ON ws.customer_id = c.id
            $where_clause
            ORDER BY 
                CASE ws.mogadishu_status 
                    WHEN 'in_warehouse' THEN 1 
                    WHEN 'not_arrived' THEN 2 
                    ELSE 3 
                END,
                ws.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate table HTML
        ob_start(); ?>
        <div style="overflow-x: auto; width: 100%;">
            <table class="warehouse-table" style="min-width: 1200px; width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f6f9;">
                        <th style="padding: 12px;">ID</th>
                        <th style="padding: 12px;">Magaca Alaabta</th>
                        <th style="padding: 12px;">Macmiil</th>
                        <th style="padding: 12px;">Tiro & CBM</th>
                        <th style="padding: 12px;">Goobta Bakhaarka</th>
                        <th style="padding: 12px;">Xaaladda</th>
                        <th style="padding: 12px;">Maalinta Bakhaarka</th>
                        <th style="padding: 12px;">Kharashka Kaydinta</th>
                        <th style="padding: 12px;">Hawlaha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($items) > 0): ?>
                        <?php foreach ($items as $item):
                            $statusColor = $status_colors[$item['mogadishu_status']] ?? '#6c757d';
                            $statusName = $mogadishu_statuses[$item['mogadishu_status']] ?? ucfirst($item['mogadishu_status']);
                            $daysInStorage = $item['days_in_storage'] ?? 0;
                            $storageFee = $item['storage_fee'] > 0 ? $item['storage_fee'] : ($item['calculated_storage_fee'] ?? 0);
                        ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px;"><?= $item['id'] ?> </td>
                                <td style="padding: 12px;">
                                    <strong><?= htmlspecialchars($item['stock_name']) ?></strong>
                                    <div style="font-size: 11px; color: #6c757d;">
                                        <?= htmlspecialchars($item['origin'] ?? 'N/A') ?>
                                    </div>
                                </td>
                                <td style="padding: 12px;">
                                    <?= htmlspecialchars($item['customer_name'] ?? '-') ?>
                                    <div style="font-size: 11px; color: #6c757d;">
                                        <?= htmlspecialchars($item['customer_phone'] ?? '') ?>
                                    </div>
                                </td>
                                <td style="padding: 12px;">
                                    <div>Tiro: <strong><?= number_format($item['quantity']) ?></strong></div>
                                    <div style="font-size: 11px;">CBM: <?= number_format($item['volume_cbm'], 4) ?></div>
                                    <div style="font-size: 11px;">Qiimo: $<?= number_format($item['volume_cbm'] * max(1, (int)$item['quantity']) * $item['unit_price'], 2) ?></div>
                                </td>
                                <td style="padding: 12px;">
                                    <?php if ($item['location']): ?>
                                        <div><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($item['location']) ?></div>
                                        <div style="font-size: 11px;">
                                            <?php if ($item['zone']): ?>Zona: <?= htmlspecialchars($item['zone']) ?><?php endif; ?>
                                            <?php if ($item['bin_location']): ?> | Bin: <?= htmlspecialchars($item['bin_location']) ?><?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">Lama qorin</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px;">
                                    <span class="status-badge" style="background: <?= $statusColor ?>20; color: <?= $statusColor ?>; padding: 4px 10px; border-radius: 20px; font-size: 11px;">
                                        <?= $statusName ?>
                                    </span>
                                </td>
                                <td style="padding: 12px;">
                                    <?php if ($item['mogadishu_received_date']): ?>
                                        <div><?= date('d/m/Y', strtotime($item['mogadishu_received_date'])) ?></div>
                                        <div style="font-size: 11px; color: <?= $daysInStorage > 30 ? '#EF4444' : '#6c757d' ?>">
                                            <?= $daysInStorage ?> maalmood
                                        </div>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px;">
                                    <div>$<?= number_format($storageFee, 2) ?></div>
                                    <?php if ($daysInStorage > 0 && $item['mogadishu_status'] == 'in_warehouse'): ?>
                                        <div style="font-size: 10px; color: #6c757d;">$0.50/maalin</div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px;">
                                    <div class="action-buttons">
                                        <button class="action-btn btn-view view-item" data-id="<?= $item['id'] ?>" title="Faahfaahin"><i class="fas fa-eye"></i></button>
                                        <?php if ($item['mogadishu_status'] == 'not_arrived'): ?>
                                            <button class="action-btn btn-receive receive-item" data-id="<?= $item['id'] ?>" data-name="<?= htmlspecialchars($item['stock_name']) ?>" title="Soo Dhawo Bakhaarka"><i class="fas fa-arrow-down"></i></button>
                                        <?php endif; ?>
                                        <?php if ($item['mogadishu_status'] == 'in_warehouse'): ?>
                                            <button class="action-btn btn-release release-item" data-id="<?= $item['id'] ?>" data-name="<?= htmlspecialchars($item['stock_name']) ?>" title="Siidayn"><i class="fas fa-arrow-up"></i></button>
                                        <?php endif; ?>
                                        <button class="action-btn btn-edit edit-item" data-id="<?= $item['id'] ?>" title="Wax Ka Beddel"><i class="fas fa-edit"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 50px;">
                                <div class="empty-state">
                                    <i class="fas fa-warehouse" style="font-size: 48px; opacity: 0.5;"></i>
                                    <p>Ma jiraan wax alaab ah bakhaarka</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
             </table>
        </div>
        <?php
        $table_html = ob_get_clean();
        
        ob_start();
        if ($total_pages > 1): ?>
            <div class="pagination" style="display: flex; justify-content: center; gap: 8px; margin-top: 25px;">
                <?php if ($page > 1): ?>
                    <a data-page="<?= $page-1 ?>" class="pagination-link"><i class="fas fa-chevron-left"></i> Hore</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active-page"><?= $i ?></span>
                    <?php else: ?>
                        <a data-page="<?= $i ?>" class="pagination-link"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a data-page="<?= $page+1 ?>" class="pagination-link">Danbe <i class="fas fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        <?php endif;
        $pagination_html = ob_get_clean();
        
        echo json_encode([
            'table_html' => $table_html,
            'pagination_html' => $pagination_html
        ]);
        exit;
    }
    
    // Get single item details
    elseif ($action === 'get_item') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("
            SELECT ws.*, c.customer_name, c.phone as customer_phone
            FROM warehouse_stock ws
            LEFT JOIN customers c ON ws.customer_id = c.id
            WHERE ws.id = ? AND ws.tenant_id = ?
        ");
        $stmt->execute([$id, $session_tenant_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get movement history
        $stmt2 = $pdo->prepare("
            SELECT * FROM stock_movements 
            WHERE warehouse_stock_id = ? AND tenant_id = ?
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $stmt2->execute([$id, $session_tenant_id]);
        $movements = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['item' => $item, 'movements' => $movements]);
        exit;
    }
    
    // Receive item to warehouse
    elseif ($action === 'receive_item') {
        $id = (int)($_POST['id'] ?? 0);
        $location = trim($_POST['location'] ?? '');
        $bin_location = trim($_POST['bin_location'] ?? '');
        $zone = trim($_POST['zone'] ?? '');
        
        try {
            if (!warehouseItemHasDeliveredContainer($pdo, $id, (int)$session_tenant_id)) {
                $containerInfo = getContainerStatusForWarehouseItem($pdo, $id, (int)$session_tenant_id);
                $containerText = $containerInfo ? (" Kontaynar: " . ($containerInfo['container_number'] ?? '-') . ", xaalad: " . ($containerInfo['status'] ?? '-')) : '';
                echo json_encode([
                    'success' => false,
                    'message' => 'Alaabtan lama soo dhaweyn karo. Keliya alaabta kontaynarkeeda xaaladdiisu tahay La gaarsiiyay ayaa geli karta Bakhaarka Muqdisho.' . $containerText
                ]);
                exit;
            }
            $sql = "UPDATE warehouse_stock 
                    SET mogadishu_status = 'in_warehouse',
                        mogadishu_received_date = NOW(),
                        location = ?,
                        bin_location = ?,
                        zone = ?
                    WHERE id = ? AND tenant_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$location, $bin_location, $zone, $id, $session_tenant_id]);
            
            // Also update related cargo manifest items
            $stmt2 = $pdo->prepare("
                UPDATE cargo_manifest_items 
                SET mogadishu_status = 'in_warehouse',
                    mogadishu_received_date = NOW()
                WHERE warehouse_stock_id = ? AND tenant_id = ?
            ");
            $stmt2->execute([$id, $session_tenant_id]);

            $whatsapp_result = sendWarehouseArrivalWhatsApp($pdo, $id, $session_tenant_id);
            $whatsapp_text = !empty($whatsapp_result['success'])
                ? ' WhatsApp automatic ah waa la diray.'
                : ' Laakiin WhatsApp lama dirin: ' . ($whatsapp_result['error'] ?? $whatsapp_result['message'] ?? 'khalad aan la garanayn');
            
            echo json_encode(['success' => true, 'message' => 'Alaabta waa la soo dhaweeyay bakhaarka!' . $whatsapp_text, 'whatsapp' => $whatsapp_result]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Release item from warehouse (taken by customer)
    elseif ($action === 'release_item') {
        $id = (int)($_POST['id'] ?? 0);
        $storage_fee = (float)($_POST['storage_fee'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        try {
            $sql = "UPDATE warehouse_stock 
                    SET mogadishu_status = 'taken',
                        mogadishu_taken_date = NOW(),
                        storage_fee = ?
                    WHERE id = ? AND tenant_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$storage_fee, $id, $session_tenant_id]);
            
            // Update cargo manifest items
            $stmt2 = $pdo->prepare("
                UPDATE cargo_manifest_items 
                SET mogadishu_status = 'taken',
                    mogadishu_taken_date = NOW(),
                    storage_fee = ?
                WHERE warehouse_stock_id = ? AND tenant_id = ?
            ");
            $stmt2->execute([$storage_fee, $id, $session_tenant_id]);
            
            echo json_encode(['success' => true, 'message' => 'Alaabta waa la sii daayay macmiilka!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Update item details
    elseif ($action === 'update_item') {
        $id = (int)($_POST['id'] ?? 0);
        $stock_name = trim($_POST['stock_name'] ?? '');
        $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $quantity = (int)($_POST['quantity'] ?? 0);
        $length_cm = (float)($_POST['length_cm'] ?? 0);
        $width_cm = (float)($_POST['width_cm'] ?? 0);
        $height_cm = (float)($_POST['height_cm'] ?? 0);
        $volume_cbm = (float)($_POST['volume_cbm'] ?? 0);
        $unit_price = (float)($_POST['unit_price'] ?? 0);
        $location = trim($_POST['location'] ?? '');
        $bin_location = trim($_POST['bin_location'] ?? '');
        $zone = trim($_POST['zone'] ?? '');
        $origin = $_POST['origin'] ?? 'china_yiwu';
        
        if (empty($stock_name)) {
            echo json_encode(['success' => false, 'message' => 'Fadlan geli magaca alaabta']);
            exit;
        }
        
        try {
            $sql = "UPDATE warehouse_stock 
                    SET stock_name = ?, customer_id = ?, quantity = ?,
                        length_cm = ?, width_cm = ?, height_cm = ?,
                        volume_cbm = ?, unit_price = ?, location = ?,
                        bin_location = ?, zone = ?, origin = ?,
                        updated_by = ?, last_updated = NOW()
                    WHERE id = ? AND tenant_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$stock_name, $customer_id, $quantity, $length_cm, $width_cm, $height_cm,
                           $volume_cbm, $unit_price, $location, $bin_location, $zone, $origin,
                           $current_user_id, $id, $session_tenant_id]);
            
            echo json_encode(['success' => true, 'message' => 'Alaabta waa la cusboonaysiiyay!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Get containers list
    elseif ($action === 'get_containers') {
        $sql = "SELECT c.* FROM containers c WHERE c.tenant_id = ? ORDER BY c.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$session_tenant_id]);
        $containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['containers' => $containers]);
        exit;
    }
    
    // Update container customs status
    elseif ($action === 'update_container_customs') {
        $id = (int)($_POST['id'] ?? 0);
        $customs_status = $_POST['customs_status'] ?? 'pending';
        
        try {
            $sql = "UPDATE containers SET customs_status = ? WHERE id = ? AND tenant_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$customs_status, $id, $session_tenant_id]);
            
            echo json_encode(['success' => true, 'message' => 'Xaaladda Kastamka waa la cusboonaysiiyay!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Get pending shipments (items waiting to arrive)
    elseif ($action === 'get_pending_shipments') {
        $sql = "
            SELECT ws.*, c.customer_name, cnt.container_number
            FROM warehouse_stock ws
            LEFT JOIN customers c ON ws.customer_id = c.id
            LEFT JOIN cargo_manifest_items cmi ON ws.id = cmi.warehouse_stock_id
            LEFT JOIN containers cnt ON cmi.container_id = cnt.id AND cnt.tenant_id = cmi.tenant_id
            WHERE ws.mogadishu_status = 'not_arrived'
              AND ws.tenant_id = ?
              AND cnt.status = 'delivered'
            ORDER BY ws.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$session_tenant_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_start(); ?>
        <div style="overflow-x: auto;">
            <table class="pending-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f6f9;">
                        <th style="padding: 12px;">Alaabta</th>
                        <th style="padding: 12px;">Macmiil</th>
                        <th style="padding: 12px;">Container</th>
                        <th style="padding: 12px;">Tiro</th>
                        <th style="padding: 12px;">Hawlaha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($items) > 0): ?>
                        <?php foreach ($items as $item): ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px;"><strong><?= htmlspecialchars($item['stock_name']) ?></strong></td>
                                <td style="padding: 12px;"><?= htmlspecialchars($item['customer_name'] ?? '-') ?></td>
                                <td style="padding: 12px;"><?= htmlspecialchars($item['container_number'] ?? '-') ?></td>
                                <td style="padding: 12px;"><?= number_format($item['quantity']) ?></td>
                                <td style="padding: 12px;">
                                    <button class="btn btn-sm btn-success receive-pending-btn" data-id="<?= $item['id'] ?>" data-name="<?= htmlspecialchars($item['stock_name']) ?>">
                                        <i class="fas fa-arrow-down"></i> Soo Dhawo
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align: center; padding: 30px;">Ma jiraan wax alaab ah oo sugaya</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        $html = ob_get_clean();
        echo json_encode(['html' => $html]);
        exit;
    }
    
    exit;
}

// Include header
require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maareynta Bakhaarka Muqdisho | <?= htmlspecialchars($tenant_info['name'] ?? 'Cargo Management System') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root {
            --primary: #2D1859;
            --primary-light: #4B2C85;
            --secondary: #F5C410;
            --secondary-dark: #D4A70C;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: var(--gray-50); font-family: 'Inter', sans-serif; }
        
        .page-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border-radius: 20px;
            padding: 20px 30px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 10px 25px -5px rgba(82, 0, 102, 0.15);
        }
        .page-header h1 { color: white; font-size: 24px; margin: 0; font-weight: 600; }
        .page-header h1 i { margin-right: 10px; color: var(--secondary); }
        .tenant-badge-header {
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 13px;
        }
        .tenant-badge-header i { margin-right: 6px; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid var(--gray-200);
            transition: all 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); }
        .stat-info h4 { font-size: 11px; color: var(--gray-500); margin: 0 0 5px 0; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-info .stat-number { font-size: 22px; font-weight: 700; color: var(--primary); }
        .stat-icon { width: 45px; height: 45px; background: rgba(82,0,102,0.08); border-radius: 12px; display: flex; align-items: center; justify-content: center; }
        .stat-icon i { font-size: 20px; color: var(--primary); }
        
        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 768px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        
        .filters-card {
            background: white;
            border-radius: 16px;
            padding: 15px 20px;
            margin-bottom: 25px;
            border: 1px solid var(--gray-200);
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        .filter-group { flex: 1; min-width: 180px; }
        .filter-group label { display: block; font-size: 12px; font-weight: 600; color: var(--gray-600); margin-bottom: 5px; }
        .filter-group input, .filter-group select {
            width: 100%; padding: 8px 12px; border: 1px solid var(--gray-300); border-radius: 10px;
            font-size: 13px; transition: all 0.2s;
        }
        .btn-filter, .btn-reset { padding: 8px 20px; border-radius: 10px; font-weight: 500; font-size: 13px; cursor: pointer; border: none; }
        .btn-filter { background: var(--primary); color: white; }
        .btn-reset { background: var(--gray-100); color: var(--gray-700); border: 1px solid var(--gray-200); }
        
        .warehouse-table-container {
            background: white;
            border-radius: 16px;
            border: 1px solid var(--gray-200);
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
        .action-btn { width: 30px; height: 30px; border-radius: 8px; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; }
        .btn-view { background: #eef2ff; color: #4f46e5; }
        .btn-view:hover { background: #4f46e5; color: white; }
        .btn-edit { background: #fff7ed; color: #ea580c; }
        .btn-edit:hover { background: #ea580c; color: white; }
        .btn-receive { background: #d1fae5; color: #10b981; }
        .btn-receive:hover { background: #10b981; color: white; }
        .btn-release { background: #fef3c7; color: #d97706; }
        .btn-release:hover { background: #d97706; color: white; }
        
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 25px; }
        .pagination-link, .active-page {
            padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 500;
            background: white; border: 1px solid var(--gray-200); cursor: pointer;
        }
        .active-page { background: var(--primary); color: white; border-color: var(--primary); }
        
        .modal-header { background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: white; border-bottom: none; }
        .modal-header .close { color: white; opacity: 0.8; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { font-size: 12px; font-weight: 600; color: var(--gray-700); margin-bottom: 5px; display: block; }
        .form-control { border-radius: 10px; border: 1px solid var(--gray-300); padding: 8px 12px; font-size: 13px; }
        
        .nav-tabs .nav-link { color: var(--gray-700); border: none; padding: 10px 20px; }
        .nav-tabs .nav-link.active { color: var(--primary); border-bottom: 2px solid var(--primary); background: transparent; }
        
        .alert { position: fixed; top: 85px; right: 20px; z-index: 9999; min-width: 320px; border-radius: 12px; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
        .loading-spinner { text-align: center; padding: 50px; }
        .loading-spinner i { font-size: 40px; color: var(--primary); animation: spin 1s linear infinite; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        
        .empty-state { text-align: center; padding: 60px; color: var(--gray-500); }
        .empty-state i { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }
        
        .text-success { color: var(--success); }
        .text-warning { color: var(--warning); }
        .text-danger { color: var(--danger); }
        
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
        .info-item { padding: 10px; background: var(--gray-50); border-radius: 10px; }
        .info-item label { font-size: 11px; color: var(--gray-500); display: block; margin-bottom: 3px; }
        .info-item .value { font-size: 14px; font-weight: 600; }
        
        .btn-primary-custom {
            background: var(--secondary);
            color: var(--primary);
            border: none;
            padding: 8px 18px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-primary-custom:hover {
            background: var(--secondary-dark);
        }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-warehouse"></i> Maareynta Bakhaarka Muqdisho</h1>
        <div class="tenant-badge-header">
            <i class="fas fa-building"></i> <?= htmlspecialchars($tenant_info['name'] ?? 'My Company') ?>
        </div>
        <div>
            <button type="button" class="btn-primary-custom" id="refreshBtn" style="background: rgba(255,255,255,0.2); color: white; margin-right: 10px;">
                <i class="fas fa-sync-alt"></i> Cusboonaysii
            </button>
            <a class="btn-primary-custom" id="exportWarehouseBtn" href="?action=export_mogadishu_warehouse" style="text-decoration:none; margin-right: 10px;">
                <i class="fas fa-file-export"></i> Export
            </a>
            <a class="btn-primary-custom" href="?action=download_mogadishu_import_template" style="text-decoration:none; margin-right: 10px;">
                <i class="fas fa-download"></i> Template
            </a>
            <button type="button" class="btn-primary-custom" id="importWarehouseBtn" style="margin-right: 10px;">
                <i class="fas fa-file-import"></i> Import
            </button>
            <button type="button" class="btn-primary-custom" id="viewContainersBtn">
                <i class="fas fa-ship"></i> Kontaynerada
            </button>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-info"><h4>Alaabta Bakhaarka</h4><div class="stat-number" id="stat-total-items">0</div></div><div class="stat-icon"><i class="fas fa-boxes"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Wadarta Tirada</h4><div class="stat-number" id="stat-total-qty">0</div></div><div class="stat-icon"><i class="fas fa-cubes"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Kharashka Kaydinta</h4><div class="stat-number" id="stat-total-fee">$0</div></div><div class="stat-icon"><i class="fas fa-dollar-sign"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Kontayner Sugaya</h4><div class="stat-number" id="stat-pending-containers">0</div></div><div class="stat-icon"><i class="fas fa-clock"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Alaabta Sugaya</h4><div class="stat-number" id="stat-waiting">0</div></div><div class="stat-icon"><i class="fas fa-hourglass-half"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>La Qaaday Bishan</h4><div class="stat-number" id="stat-taken">0</div></div><div class="stat-icon"><i class="fas fa-check-circle"></i></div></div>
    </div>

    <!-- Filters -->
    <div class="filters-card">
        <div class="filter-group"><label><i class="fas fa-search"></i> Raadin</label><input type="text" id="searchInput" placeholder="Raadi alaabta, macmiilka..."></div>
        <div class="filter-group"><label><i class="fas fa-filter"></i> Xaaladda</label><select id="statusFilter"><option value="">Dhammaan</option><option value="not_arrived">Aan Imaanin</option><option value="in_warehouse">Bakhaarka</option><option value="taken">La Qaaday</option><option value="delivered">La Gaarsiiyay</option></select></div>
        <div><button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Shaandheey</button></div>
        <div><button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Nadiifi</button></div>
    </div>

    <div id="warehouse-table-container"><div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i><p>Loading warehouse data...</p></div></div>
    <div id="pagination-container"></div>
</div>

<!-- View Item Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-eye"></i> Faahfaahinta Alaabta</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Xir</button>
            </div>
        </div>
    </div>
</div>

<!-- Receive Item Modal -->
<div class="modal fade" id="receiveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-arrow-down"></i> Soo Dhawo Alaabta</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="receiveForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="receiveId">
                    <p><strong>Alaabta:</strong> <span id="receiveItemName"></span></p>
                    <div class="form-group">
                        <label>Goobta Bakhaarka (Location)</label>
                        <input type="text" name="location" id="receiveLocation" class="form-control" placeholder="Tusaale: Shelf A-1">
                    </div>
                    <div class="form-group">
                        <label>Bin Location</label>
                        <input type="text" name="bin_location" id="receiveBinLocation" class="form-control" placeholder="Tusaale: BIN-001">
                    </div>
                    <div class="form-group">
                        <label>Zone</label>
                        <select name="zone" id="receiveZone" class="form-control">
                            <option value="">-- Dooro Zone --</option>
                            <option value="Zone A">Zone A (Electronics)</option>
                            <option value="Zone B">Zone B (Clothing)</option>
                            <option value="Zone C">Zone C (Food)</option>
                            <option value="Zone D">Zone D (Furniture)</option>
                            <option value="Zone E">Zone E (General)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button>
                    <button type="submit" class="btn btn-success">Soo Dhawo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Release Item Modal -->
<div class="modal fade" id="releaseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-arrow-up"></i> Siidayn Alaabta</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="releaseForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="releaseId">
                    <p><strong>Alaabta:</strong> <span id="releaseItemName"></span></p>
                    <div class="form-group">
                        <label>Kharashka Kaydinta (Storage Fee)</label>
                        <input type="number" step="0.01" name="storage_fee" id="releaseStorageFee" class="form-control" placeholder="0.00">
                        <small class="text-muted">Kharashka kaydinta maalin walba $0.50</small>
                    </div>
                    <div class="form-group">
                        <label>Qoraal (Notes)</label>
                        <textarea name="notes" id="releaseNotes" class="form-control" rows="2" placeholder="Tusaale: Macmiilka ayaa qaaday..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button>
                    <button type="submit" class="btn btn-warning">Siidayn</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Item Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Wax Ka Beddel Alaabta</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="editForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="editId">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Magaca Alaabta <span class="text-danger">*</span></label>
                                <input type="text" name="stock_name" id="editStockName" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Macmiilka</label>
                                <input type="hidden" name="customer_id" id="editCustomerId">
                                <div class="customer-search-wrap" style="position:relative;">
                                    <input type="text" id="editCustomerSearch" class="form-control" placeholder="Raadi magaca macmiilka ama telefoonka...">
                                    <div id="editCustomerResults" class="customer-results" style="display:none;position:absolute;z-index:9999;background:#fff;border:1px solid #ddd;border-radius:10px;width:100%;max-height:220px;overflow:auto;box-shadow:0 10px 25px rgba(0,0,0,.08);"></div>
                                    <small id="selectedCustomerText" class="text-muted d-block mt-1">Macmiil lama dooran</small>
                                    <button type="button" id="addCustomerInlineBtn" class="btn btn-sm btn-outline-primary mt-2">
                                        <i class="fas fa-user-plus"></i> Ku dar Macmiil
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Asalka (Origin)</label>
                                <select name="origin" id="editOrigin" class="form-control">
                                    <option value="china_yiwu">China Yiwu</option>
                                    <option value="china_guangzhou">China Guangzhou</option>
                                    <option value="dubai">Dubai</option>
                                    <option value="local">Local</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tirada (Quantity)</label>
                                <input type="number" name="quantity" id="editQuantity" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Length (cm)</label>
                                <input type="number" step="0.01" name="length_cm" id="editLength" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Width (cm)</label>
                                <input type="number" step="0.01" name="width_cm" id="editWidth" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Height (cm)</label>
                                <input type="number" step="0.01" name="height_cm" id="editHeight" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Volume (CBM)</label>
                                <input type="number" step="0.0001" name="volume_cbm" id="editVolume" class="form-control" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Qiimaha Cutubka (Unit Price)</label>
                                <input type="number" step="0.01" name="unit_price" id="editUnitPrice" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Location</label>
                                <input type="text" name="location" id="editLocation" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Bin Location</label>
                                <input type="text" name="bin_location" id="editBinLocation" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Zone</label>
                                <input type="text" name="zone" id="editZone" class="form-control">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button>
                    <button type="submit" class="btn btn-primary-custom">Kaydi Isbeddellada</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Containers Modal -->
<div class="modal fade" id="containersModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-ship"></i> Maareynta Kontaynerada</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" id="containerTabs" role="tablist">
                    <li class="nav-item"><a class="nav-link active" id="all-containers-tab" data-toggle="tab" href="#allContainers">Dhammaan Kontaynerada</a></li>
                    <li class="nav-item"><a class="nav-link" id="pending-customs-tab" data-toggle="tab" href="#pendingCustoms">Kastamka Sugaya</a></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="allContainers">
                        <div id="containersList" class="mt-3"><div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>
                    </div>
                    <div class="tab-pane fade" id="pendingCustoms">
                        <div id="pendingShipmentsList" class="mt-3"><div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Xir</button>
            </div>
        </div>
    </div>
</div>

<!-- Update Customs Modal -->
<div class="modal fade" id="customsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-passport"></i> Cusboonaysii Xaaladda Kastamka</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="customsForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="customsId">
                    <p><strong>Kontayner:</strong> <span id="customsContainerNumber"></span></p>
                    <div class="form-group">
                        <label>Xaaladda Kastamka</label>
                        <select name="customs_status" id="customsStatus" class="form-control">
                            <option value="pending">Sugaya ⏳</option>
                            <option value="cleared">La Fasaxay ✅</option>
                            <option value="held">La Qabtay ❌</option>
                            <option value="inspected">La Kormeeray 🔍</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button>
                    <button type="submit" class="btn btn-primary-custom">Cusboonaysii</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- Import Warehouse CSV Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-import"></i> Import Warehouse CSV</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="importForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="alert alert-info" style="position:static; min-width:auto;">
                        CSV-ga isticmaal template-ka. Fields muhiim ah: <strong>stock_name, quantity, volume_cbm/unit_price, customer_phone, container_number, mogadishu_status</strong>.
                    </div>
                    <div class="form-group">
                        <label>Dooro CSV File</label>
                        <input type="file" name="import_file" id="importFile" class="form-control" accept=".csv,text/csv" required>
                    </div>
                    <a href="?action=download_mogadishu_import_template" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-download"></i> Soo dejiso template
                    </a>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button>
                    <button type="submit" class="btn btn-primary-custom">Import Garee</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- Quick Ku dar Macmiil Modal -->
<div class="modal fade" id="quickCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" id="quickCustomerForm">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus"></i> Ku dar Macmiil</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Magaca Macmiilka *</label>
                    <input type="text" name="customer_name" id="quickCustomerName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Telefoon</label>
                    <input type="text" name="phone" id="quickCustomerTelefoon" class="form-control">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" id="quickCustomerEmail" class="form-control">
                </div>
                <div class="form-group">
                    <label>Cinwaan</label>
                    <textarea name="address" id="quickCustomerCinwaan" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Xidh</button>
                <button type="submit" class="btn-primary-custom"><i class="fas fa-save"></i> Save Customer</button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    let customerSearchTimer = null;

    function selectWarehouseCustomer(customer) {
        $('#editCustomerId').val(customer.id || '');
        const name = customer.customer_name || '';
        const phone = customer.phone ? ' (' + customer.phone + ')' : '';
        $('#editCustomerSearch').val(name + phone);
        const balance = customer.balance !== undefined ? parseFloat(customer.balance || 0).toFixed(2) : '0.00';
        const points = customer.loyalty_points !== undefined ? parseInt(customer.loyalty_points || 0, 10) : 0;
        $('#selectedCustomerText').text(customer.id ? ('La doortay: ' + name + phone + ' | Balance: $' + balance + ' | Points: ' + points) : 'Macmiil lama dooran');
        $('#editCustomerResults').hide().empty();
    }

    function renderCustomerResults(customers) {
        const box = $('#editCustomerResults');
        box.empty();

        if (!customers || customers.length === 0) {
            box.append(`
                <div class="p-2 text-muted">
                    Macmiil lama helin.
                    <button type="button" class="btn btn-sm btn-primary ml-2" id="addCustomerFromResultBtn">
                        Ku dar Macmiil
                    </button>
                </div>
            `);
            box.show();
            return;
        }

        customers.forEach(function(c) {
            box.append(`
                <div class="customer-result-item" data-id="${c.id}" data-name="${escapeHtml(c.customer_name || '')}" data-phone="${escapeHtml(c.phone || '')}" data-balance="${c.balance || 0}" data-points="${c.loyalty_points || 0}" style="padding:9px 12px;cursor:pointer;border-bottom:1px solid #eee;">
                    <strong>${escapeHtml(c.customer_name || '-')}</strong>
                    <div style="font-size:12px;color:#6b7280;">${escapeHtml(c.phone || '')} | Balance: $${parseFloat(c.balance || 0).toFixed(2)}</div>
                </div>
            `);
        });
        box.show();
    }

    function searchWarehouseCustomers(q) {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            dataType: 'json',
            data: {ajax_action: 'search_customers', q: q || ''},
            success: function(res) {
                if (res.success) renderCustomerResults(res.customers || []);
            }
        });
    }

    $('#editCustomerSearch').on('input focus', function() {
        clearTimeout(customerSearchTimer);
        const q = $(this).val();
        customerSearchTimer = setTimeout(function() {
            searchWarehouseCustomers(q);
        }, 250);
    });

    $(document).on('click', '.customer-result-item', function() {
        selectWarehouseCustomer({
            id: $(this).data('id'),
            customer_name: $(this).data('name'),
            phone: $(this).data('phone'),
            balance: $(this).data('balance'), loyalty_points: $(this).data('points')
        });
    });

    $('#addCustomerInlineBtn').on('click', function() {
        $('#quickCustomerForm')[0].reset();
        $('#quickCustomerName').val($('#editCustomerSearch').val());
        $('#quickCustomerModal').modal('show');
    });

    $(document).on('click', '#addCustomerFromResultBtn', function() {
        $('#quickCustomerForm')[0].reset();
        $('#quickCustomerName').val($('#editCustomerSearch').val());
        $('#quickCustomerModal').modal('show');
    });

    $('#quickCustomerForm').on('submit', function(e) {
        e.preventDefault();
        $.ajax({
            url: window.location.href,
            type: 'POST',
            dataType: 'json',
            data: $(this).serialize() + '&ajax_action=quick_add_customer',
            success: function(res) {
                if (res.success) {
                    $('#quickCustomerModal').modal('hide');
                    selectWarehouseCustomer(res.customer);
                    showAlert('success', 'Macmiilka waa la kaydiyay');
                } else {
                    showAlert('error', res.message || 'Macmiilka lama kaydin');
                }
            }
        });
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('.customer-search-wrap, #quickCustomerModal').length) {
            $('#editCustomerResults').hide();
        }
    });


    let currentPage = 1;

    function refreshExportLink() {
        const params = new URLSearchParams();
        params.set('action', 'export_mogadishu_warehouse');
        if ($('#searchInput').val()) params.set('search', $('#searchInput').val());
        if ($('#statusFilter').val()) params.set('status', $('#statusFilter').val());
        $('#exportWarehouseBtn').attr('href', '?' + params.toString());
    }
    
    function loadWarehouseItems() {
        let data = {
            ajax_action: 'get_warehouse_items',
            page: currentPage,
            search: $('#searchInput').val(),
            status: $('#statusFilter').val()
        };
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(res) {
                $('#warehouse-table-container').html(res.table_html);
                $('#pagination-container').html(res.pagination_html);
                attachTableEvents();
            },
            error: function() {
                $('#warehouse-table-container').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Khalad ayaa dhacay</p></div>');
            }
        });
    }
    
    function loadStats() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_stats' },
            dataType: 'json',
            success: function(s) {
                $('#stat-total-items').text(parseInt(s.total_items || 0, 10));
                $('#stat-total-qty').text(parseInt(s.total_quantity || 0, 10));
                $('#stat-total-fee').text('$' + parseFloat(s.total_storage_fee || 0).toFixed(2));
                $('#stat-pending-containers').text(parseInt(s.pending_containers || 0, 10));
                $('#stat-waiting').text(parseInt(s.waiting_items || 0, 10));
                $('#stat-taken').text(parseInt(s.taken_items || 0, 10));
            }
        });
    }
    
    function loadContainers() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_containers' },
            dataType: 'json',
            success: function(res) {
                if (res.containers && res.containers.length > 0) {
                    let html = '<table class="table table-bordered"><thead><tr><th>ID</th><th>Container Number</th><th>Nooca</th><th>Xaaladda Kastamka</th><th>Hawlaha</th></tr></thead><tbody>';
                    for (let c of res.containers) {
                        let customsStatus = c.customs_status === 'pending' ? 'Sugaya' : (c.customs_status === 'cleared' ? 'La Fasaxay' : (c.customs_status === 'inspected' ? 'La Kormeeray' : 'La Qabtay'));
                        let customsClass = c.customs_status === 'pending' ? 'text-warning' : (c.customs_status === 'cleared' ? 'text-success' : 'text-danger');
                        html += `<tr>
                            <td>${c.id}</td>
                            <td><strong>${escapeHtml(c.container_number)}</strong></td>
                            <td>${c.container_type || '-'}</td>
                            <td class="${customsClass}">${customsStatus}</td>
                            <td><button class="btn btn-sm btn-primary update-customs" data-id="${c.id}" data-number="${escapeHtml(c.container_number)}" data-status="${c.customs_status}"><i class="fas fa-edit"></i> Cusboonaysii</button></td>
                        </tr>`;
                    }
                    html += '</tbody></table>';
                    $('#containersList').html(html);
                    
                    $('.update-customs').off('click').on('click', function() {
                        $('#customsId').val($(this).data('id'));
                        $('#customsContainerNumber').text($(this).data('number'));
                        $('#customsStatus').val($(this).data('status') || 'pending');
                        $('#customsModal').modal('show');
                    });
                } else {
                    $('#containersList').html('<div class="empty-state"><i class="fas fa-box"></i><p>Ma jiraan wax kontayner ah</p></div>');
                }
            }
        });
    }
    
    function loadPendingShipments() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_pending_shipments' },
            dataType: 'json',
            success: function(res) {
                $('#pendingShipmentsList').html(res.html);
                $('.receive-pending-btn').off('click').on('click', function() {
                    let id = $(this).data('id');
                    let name = $(this).data('name');
                    $('#receiveId').val(id);
                    $('#receiveItemName').text(name);
                    $('#receiveLocation').val('');
                    $('#receiveBinLocation').val('');
                    $('#receiveZone').val('');
                    $('#receiveModal').modal('show');
                });
            }
        });
    }
    
    function attachTableEvents() {
        $('.view-item').off('click').on('click', function() {
            let id = $(this).data('id');
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_item', id: id },
                dataType: 'json',
                success: function(res) {
                    let item = res.item;
                    let statusName = item.mogadishu_status === 'not_arrived' ? 'Aan Imaanin' : (item.mogadishu_status === 'in_warehouse' ? 'Bakhaarka' : (item.mogadishu_status === 'taken' ? 'La Qaaday' : 'La Gaarsiiyay'));
                    let movementsHtml = '';
                    if (res.movements && res.movements.length > 0) {
                        movementsHtml = '<div class="info-grid mt-3"><div class="info-item"><label>Dhaqdhaqaaqa Bakhaarka</label><div style="max-height: 200px; overflow-y: auto;">';
                        for (let m of res.movements) {
                            let type = m.movement_type === 'in' ? 'Soo Galitaan' : (m.movement_type === 'out' ? 'Bixitaan' : (m.movement_type === 'move' ? 'Wareeji' : 'Hagaajin'));
                            movementsHtml += `<div style="padding: 5px 0; border-bottom: 1px solid #eee;">
                                <span class="badge badge-info">${type}</span> ${m.quantity_change} qayb - ${new Date(m.created_at).toLocaleString()}
                            </div>`;
                        }
                        movementsHtml += '</div></div></div>';
                    }
                    $('#viewModalBody').html(`
                        <div class="info-grid">
                            <div class="info-item"><label>Magaca Alaabta</label><div class="value">${escapeHtml(item.stock_name)}</div></div>
                            <div class="info-item"><label>Macmiilka</label><div class="value">${escapeHtml(item.customer_name || '-')} (${escapeHtml(item.customer_phone || '-')})</div></div>
                            <div class="info-item"><label>Tirada</label><div class="value">${item.quantity || 0}</div></div>
                            <div class="info-item"><label>Volume (CBM)</label><div class="value">${parseFloat(item.volume_cbm || 0).toFixed(4)}</div></div>
                            <div class="info-item"><label>Dimensions</label><div class="value">${item.length_cm || 0} x ${item.width_cm || 0} x ${item.height_cm || 0} cm</div></div>
                            <div class="info-item"><label>Qiimaha Unit-ka</label><div class="value">$${parseFloat(item.unit_price || 0).toFixed(2)}</div></div>
                            <div class="info-item"><label>Goobta Bakhaarka</label><div class="value">${escapeHtml(item.location || '-')} | Bin: ${escapeHtml(item.bin_location || '-')} | Zone: ${escapeHtml(item.zone || '-')}</div></div>
                            <div class="info-item"><label>Xaaladda</label><div class="value">${statusName}</div></div>
                            <div class="info-item"><label>Maalinta la Helay</label><div class="value">${item.mogadishu_received_date ? new Date(item.mogadishu_received_date).toLocaleString() : '-'}</div></div>
                            <div class="info-item"><label>Maalinta la Qaaday</label><div class="value">${item.mogadishu_taken_date ? new Date(item.mogadishu_taken_date).toLocaleString() : '-'}</div></div>
                            <div class="info-item"><label>Kharashka Kaydinta</label><div class="value">$${parseFloat(item.storage_fee || 0).toFixed(2)}</div></div>
                        </div>
                        ${movementsHtml}
                    `);
                    $('#viewModal').modal('show');
                }
            });
        });
        
        $('.receive-item').off('click').on('click', function() {
            let id = $(this).data('id');
            let name = $(this).data('name');
            $('#receiveId').val(id);
            $('#receiveItemName').text(name);
            $('#receiveLocation').val('');
            $('#receiveBinLocation').val('');
            $('#receiveZone').val('');
            $('#receiveModal').modal('show');
        });
        
        $('.release-item').off('click').on('click', function() {
            let id = $(this).data('id');
            let name = $(this).data('name');
            $('#releaseId').val(id);
            $('#releaseItemName').text(name);
            $('#releaseStorageFee').val('');
            $('#releaseNotes').val('');
            $('#releaseModal').modal('show');
        });
        
        $('.edit-item').off('click').on('click', function() {
            let id = $(this).data('id');
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_item', id: id },
                dataType: 'json',
                success: function(res) {
                    let item = res.item;
                    $('#editId').val(item.id);
                    $('#editStockName').val(item.stock_name);
                    selectWarehouseCustomer({
                        id: item.customer_id || '',
                        customer_name: item.customer_name || '',
                        phone: item.customer_phone || '',
                        balance: 0, loyalty_points: 0
                    });
                    $('#editOrigin').val(item.origin || 'china_yiwu');
                    $('#editQuantity').val(item.quantity);
                    $('#editLength').val(item.length_cm);
                    $('#editWidth').val(item.width_cm);
                    $('#editHeight').val(item.height_cm);
                    $('#editVolume').val(item.volume_cbm);
                    $('#editUnitPrice').val(item.unit_price);
                    $('#editLocation').val(item.location);
                    $('#editBinLocation').val(item.bin_location);
                    $('#editZone').val(item.zone);
                    $('#editModal').modal('show');
                }
            });
        });
        
        $('.pagination a').off('click').on('click', function(e) {
            e.preventDefault();
            if ($(this).data('page')) {
                currentPage = $(this).data('page');
                loadWarehouseItems();
            }
        });
    }
    
    $('#receiveForm').submit(function(e) {
        e.preventDefault();
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $(this).serialize() + '&ajax_action=receive_item',
            dataType: 'json',
            success: function(r) {
                $('#receiveModal').modal('hide');
                if (r.success) {
                    showAlert('success', r.message);
                    loadWarehouseItems();
                    loadStats();
                } else {
                    showAlert('error', r.message);
                }
            }
        });
    });
    
    $('#releaseForm').submit(function(e) {
        e.preventDefault();
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $(this).serialize() + '&ajax_action=release_item',
            dataType: 'json',
            success: function(r) {
                $('#releaseModal').modal('hide');
                if (r.success) {
                    showAlert('success', r.message);
                    loadWarehouseItems();
                    loadStats();
                } else {
                    showAlert('error', r.message);
                }
            }
        });
    });
    
    $('#editForm').submit(function(e) {
        e.preventDefault();
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $(this).serialize() + '&ajax_action=update_item',
            dataType: 'json',
            success: function(r) {
                $('#editModal').modal('hide');
                if (r.success) {
                    showAlert('success', r.message);
                    loadWarehouseItems();
                    loadStats();
                } else {
                    showAlert('error', r.message);
                }
            }
        });
    });
    
    $('#customsForm').submit(function(e) {
        e.preventDefault();
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $(this).serialize() + '&ajax_action=update_container_customs',
            dataType: 'json',
            success: function(r) {
                $('#customsModal').modal('hide');
                if (r.success) {
                    showAlert('success', r.message);
                    loadContainers();
                    loadStats();
                } else {
                    showAlert('error', r.message);
                }
            }
        });
    });
    
    // Auto-calculate volume
    function calculateVolume() {
        let length = parseFloat($('#editLength').val()) || 0;
        let width = parseFloat($('#editWidth').val()) || 0;
        let height = parseFloat($('#editHeight').val()) || 0;
        let volume = (length * width * height) / 1000000;
        $('#editVolume').val(volume.toFixed(6));
    }
    
    $('#editLength, #editWidth, #editHeight').on('input', calculateVolume);
    
    $('#viewContainersBtn').click(function() {
        $('#containersModal').modal('show');
        loadContainers();
        loadPendingShipments();
    });
    
    $('#refreshBtn').click(function() {
        loadWarehouseItems();
        loadStats();
        refreshExportLink();
        showAlert('info', 'Xogta waa la cusboonaysiiyay!');
    });

    $('#importWarehouseBtn').click(function() {
        $('#importForm')[0].reset();
        $('#importModal').modal('show');
    });

    $('#importForm').submit(function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('ajax_action', 'import_mogadishu_warehouse');
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            dataType: 'json',
            processData: false,
            contentType: false,
            success: function(r) {
                if (r.success) {
                    $('#importModal').modal('hide');
                    showAlert('success', r.message);
                    loadWarehouseItems();
                    loadStats();
                } else {
                    showAlert('error', r.message || 'Import wuu fashilmay.');
                }
            },
            error: function() {
                showAlert('error', 'Import khalad ayuu galay. Hubi CSV-ga iyo server-ka.');
            }
        });
    });
    
    $('#applyFilters').click(function() { currentPage = 1; refreshExportLink(); loadWarehouseItems(); loadStats(); });
    $('#resetFilters').click(function() {
        $('#searchInput').val('');
        $('#statusFilter').val('');
        currentPage = 1;
        refreshExportLink();
        loadWarehouseItems();
        loadStats();
    });
    
    function showAlert(t, m) {
        const type = (t === 'error') ? 'danger' : t;
        const icon = type === 'success' ? 'fa-check-circle' : (type === 'info' ? 'fa-info-circle' : 'fa-exclamation-circle');
        $('#alert-placeholder').html(`<div class="alert alert-${type} alert-dismissible fade show"><i class="fas ${icon}"></i> ${escapeHtml(m || '')}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
        setTimeout(() => $('.alert').fadeOut(3000, function() { $(this).remove(); }), 5000);
    }
    
    function escapeHtml(t) {
        if (!t) return '';
        return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    
    refreshExportLink();
    loadWarehouseItems();
    loadStats();
});
</script>
</body>
</html>