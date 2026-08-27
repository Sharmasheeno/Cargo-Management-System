<?php
// branch_manager/container.php
// Branch Manager Containers Management - Faras Cargo / CMS
// Converted from tenant_admin/containers.php to branch_manager/container.php
// Security: every query is limited by tenant_id + assigned branch current_branch_id.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db_connect.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    die("Database connection failed: \$pdo lama helin. Hubi config/db_connect.php");
}

$active_role = $_SESSION['role_type'] ?? $_SESSION['role'] ?? '';
if (!isset($_SESSION['user_id']) || $active_role !== 'branch_manager') {
    header("Location: ../login.php");
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = $_SESSION['user_name'] ?? ($_SESSION['full_name'] ?? 'Branch Manager');
$session_tenant_id = (int)($_SESSION['tenant_id'] ?? 0);

if (!$session_tenant_id) {
    header("Location: ../dashboard.php?error=no_tenant");
    exit;
}

/* ==========================================================
   HELPERS
========================================================== */
function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function jsonResponse(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetch(PDO::FETCH_NUM);
    } catch (Throwable $e) {
        return false;
    }
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void {
    try {
        if (!columnExists($pdo, $table, $column)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    } catch (Throwable $e) {}
}

function postString(string $key, string $default = ''): string {
    $value = $_POST[$key] ?? $default;
    return is_array($value) ? $default : trim((string)$value);
}

function postInt(string $key, int $default = 0): int {
    $value = $_POST[$key] ?? $default;
    return is_numeric($value) ? (int)$value : $default;
}

function postFloat(string $key, float $default = 0.0): float {
    $value = $_POST[$key] ?? $default;
    if (is_array($value)) return $default;
    $value = str_replace(',', '.', trim((string)$value));
    return is_numeric($value) ? (float)$value : $default;
}

function nullableDate($value): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    $time = strtotime($value);
    return $time ? date('Y-m-d', $time) : null;
}

/* ==========================================================
   BRANCH ASSIGNMENT
========================================================== */
$assigned_branch_id = (int)($_SESSION['assigned_branch_id'] ?? 0);

if (!$assigned_branch_id && tableExists($pdo, 'user_branch_assignments')) {
    try {
        $stmt = $pdo->prepare("
            SELECT branch_id, can_manage_branch
            FROM user_branch_assignments
            WHERE user_id = ? AND is_primary = 1
            LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $assigned_branch_id = (int)$row['branch_id'];
            $_SESSION['assigned_branch_id'] = $assigned_branch_id;
            $_SESSION['can_manage_branch'] = $row['can_manage_branch'] ?? 1;
        }
    } catch (Throwable $e) {}
}

if (!$assigned_branch_id && columnExists($pdo, 'users', 'default_branch_id')) {
    try {
        $stmt = $pdo->prepare("SELECT default_branch_id FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['default_branch_id'])) {
            $assigned_branch_id = (int)$row['default_branch_id'];
            $_SESSION['assigned_branch_id'] = $assigned_branch_id;
        }
    } catch (Throwable $e) {}
}

if (!$assigned_branch_id) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container mt-4"><div class="alert alert-danger">Branch assignment lama helin. Fadlan admin-ka la xiriir.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$branch_name = 'My Branch';
try {
    $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1");
    $stmt->execute([$assigned_branch_id, $session_tenant_id]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) $branch_name = $branch['branch_name'];
} catch (Throwable $e) {}

/* ==========================================================
   SCHEMA PATCHES
========================================================== */
try {
    if (!tableExists($pdo, 'containers')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS containers (
                id INT(11) NOT NULL AUTO_INCREMENT,
                tenant_id INT(11) NOT NULL,
                container_number VARCHAR(100) NOT NULL,
                container_type ENUM('20ft','40ft','40hc','lcl') DEFAULT '20ft',
                size_cbm DECIMAL(15,2) DEFAULT 0.00,
                size_used_cbm DECIMAL(15,2) DEFAULT 0.00,
                weight_kg DECIMAL(15,2) DEFAULT 0.00,
                status ENUM('received','loading','loaded','shipped','dispatched','at_port','ready','delivered') DEFAULT 'received',
                current_branch_id INT(11) DEFAULT NULL,
                current_location VARCHAR(255) DEFAULT NULL,
                arrival_date DATE DEFAULT NULL,
                departure_date DATE DEFAULT NULL,
                estimated_arrival DATE DEFAULT NULL,
                tracking_number VARCHAR(100) DEFAULT NULL,
                seal_number VARCHAR(100) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                shipping_line VARCHAR(255) DEFAULT NULL,
                bl_number VARCHAR(100) DEFAULT NULL,
                vessel_name VARCHAR(255) DEFAULT NULL,
                port_of_loading VARCHAR(255) DEFAULT NULL,
                port_of_discharge VARCHAR(255) DEFAULT NULL,
                eta_port DATE DEFAULT NULL,
                etd_port DATE DEFAULT NULL,
                customs_status ENUM('pending','cleared','held') DEFAULT 'pending',
                created_by INT(11) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_tenant_branch (tenant_id, current_branch_id),
                UNIQUE KEY uk_container_tenant_number (tenant_id, container_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        try {
            $pdo->exec("ALTER TABLE containers MODIFY COLUMN status ENUM('received','loading','loaded','shipped','dispatched','at_port','ready','delivered') DEFAULT 'received'");
        } catch (Throwable $e) {}
        addColumnIfMissing($pdo, 'containers', 'container_type', "ENUM('20ft','40ft','40hc','lcl') DEFAULT '20ft'");
        addColumnIfMissing($pdo, 'containers', 'size_cbm', "DECIMAL(15,2) DEFAULT 0.00");
        addColumnIfMissing($pdo, 'containers', 'size_used_cbm', "DECIMAL(15,2) DEFAULT 0.00");
        addColumnIfMissing($pdo, 'containers', 'weight_kg', "DECIMAL(15,2) DEFAULT 0.00");
        addColumnIfMissing($pdo, 'containers', 'current_branch_id', "INT(11) DEFAULT NULL");
        addColumnIfMissing($pdo, 'containers', 'current_location', "VARCHAR(255) DEFAULT NULL");
        addColumnIfMissing($pdo, 'containers', 'arrival_date', "DATE DEFAULT NULL");
        addColumnIfMissing($pdo, 'containers', 'departure_date', "DATE DEFAULT NULL");
        addColumnIfMissing($pdo, 'containers', 'estimated_arrival', "DATE DEFAULT NULL");
        addColumnIfMissing($pdo, 'containers', 'tracking_number', "VARCHAR(100) DEFAULT NULL");
        addColumnIfMissing($pdo, 'containers', 'seal_number', "VARCHAR(100) DEFAULT NULL");
        addColumnIfMissing($pdo, 'containers', 'notes', "TEXT DEFAULT NULL");
        addColumnIfMissing($pdo, 'containers', 'shipping_line', "VARCHAR(255) DEFAULT NULL");
        addColumnIfMissing($pdo, 'containers', 'bl_number', "VARCHAR(100) DEFAULT NULL");
        addColumnIfMissing($pdo, 'containers', 'vessel_name', "VARCHAR(255) DEFAULT NULL");
        addColumnIfMissing($pdo, 'containers', 'port_of_loading', "VARCHAR(255) DEFAULT NULL");
        addColumnIfMissing($pdo, 'containers', 'port_of_discharge', "VARCHAR(255) DEFAULT NULL");
        addColumnIfMissing($pdo, 'containers', 'eta_port', "DATE DEFAULT NULL");
        addColumnIfMissing($pdo, 'containers', 'etd_port', "DATE DEFAULT NULL");
        addColumnIfMissing($pdo, 'containers', 'customs_status', "ENUM('pending','cleared','held') DEFAULT 'pending'");
        addColumnIfMissing($pdo, 'containers', 'created_by', "INT(11) DEFAULT NULL");
        addColumnIfMissing($pdo, 'containers', 'created_at', "DATETIME DEFAULT CURRENT_TIMESTAMP");
        addColumnIfMissing($pdo, 'containers', 'updated_at', "DATETIME DEFAULT NULL");
    }

    if (tableExists($pdo, 'trucking_trips')) {
        addColumnIfMissing($pdo, 'trucking_trips', 'branch_id', "INT(11) DEFAULT NULL");
        addColumnIfMissing($pdo, 'trucking_trips', 'loaded_at', "DATETIME DEFAULT NULL");
        addColumnIfMissing($pdo, 'trucking_trips', 'departed_at', "DATETIME DEFAULT NULL");
        addColumnIfMissing($pdo, 'trucking_trips', 'delivered_at', "DATETIME DEFAULT NULL");
    }

    if (tableExists($pdo, 'warehouse_stock')) {
        try { $pdo->exec("ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS mogadishu_status ENUM('not_arrived','in_warehouse','taken','delivered') NOT NULL DEFAULT 'not_arrived'"); } catch (Throwable $e) {}
        addColumnIfMissing($pdo, 'warehouse_stock', 'mogadishu_received_date', "DATETIME DEFAULT NULL");
        addColumnIfMissing($pdo, 'warehouse_stock', 'mogadishu_taken_date', "DATETIME DEFAULT NULL");
        addColumnIfMissing($pdo, 'warehouse_stock', 'storage_fee', "DECIMAL(15,2) DEFAULT 0.00");
    }

    if (tableExists($pdo, 'cargo_manifest_items')) {
        try { $pdo->exec("ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS mogadishu_status ENUM('not_arrived','in_warehouse','taken','delivered') NOT NULL DEFAULT 'not_arrived'"); } catch (Throwable $e) {}
        addColumnIfMissing($pdo, 'cargo_manifest_items', 'mogadishu_received_date', "DATETIME DEFAULT NULL");
        addColumnIfMissing($pdo, 'cargo_manifest_items', 'mogadishu_taken_date', "DATETIME DEFAULT NULL");
        addColumnIfMissing($pdo, 'cargo_manifest_items', 'storage_fee', "DECIMAL(15,2) DEFAULT 0.00");
        addColumnIfMissing($pdo, 'cargo_manifest_items', 'weight_kg', "DECIMAL(15,2) DEFAULT 0.00");
        addColumnIfMissing($pdo, 'cargo_manifest_items', 'unit_price', "DECIMAL(15,2) DEFAULT 0.00");
    }
} catch (Throwable $e) {}

/* ==========================================================
   CONSTANTS + STATUS RULES
========================================================== */
$container_cbm_map = [
    '20ft' => 33.2,
    '40ft' => 67.6,
    '40hc' => 76.3,
    'lcl' => 0
];

$status_names = [
    'received' => 'La helay',
    'loading' => 'Waa la rarayaa',
    'loaded' => 'Waa la raray',
    'shipped' => 'Wuu dhoofay',
    'dispatched' => 'Waa la diray',
    'at_port' => 'Dekedda ayuu joogaa',
    'ready' => 'Diyaar',
    'delivered' => 'La gaarsiiyay'
];

$status_colors = [
    'received' => '#17a2b8',
    'loading' => '#ffc107',
    'loaded' => '#28a745',
    'shipped' => '#6f42c1',
    'dispatched' => '#fd7e14',
    'at_port' => '#6f42c1',
    'ready' => '#28a745',
    'delivered' => '#20c997'
];

$customs_status_names = [
    'pending' => 'Sugaya',
    'cleared' => 'La fasaxay',
    'held' => 'La qabtay'
];

function containerStatusRank($status): int {
    $order = [
        'received' => 1,
        'loading' => 2,
        'loaded' => 3,
        'shipped' => 4,
        'dispatched' => 5,
        'at_port' => 6,
        'ready' => 7,
        'delivered' => 8
    ];
    return $order[$status] ?? 0;
}

function isContainerManifestLocked($status): bool {
    return containerStatusRank($status) >= containerStatusRank('shipped');
}

function isContainerFinalLocked($status): bool {
    return $status === 'delivered';
}

function canMoveContainerStatusForward($currentStatus, $newStatus): bool {
    $currentRank = containerStatusRank($currentStatus);
    $newRank = containerStatusRank($newStatus);
    return $currentRank > 0 && $newRank > 0 && $newRank > $currentRank;
}

function somaliContainerXaaladText($status): string {
    $map = [
        'received' => 'Waa la helay',
        'loading' => 'Waa la rarayaa',
        'loaded' => 'Waa la raray',
        'shipped' => 'Wuu dhoofay',
        'dispatched' => 'Waa la diray',
        'at_port' => 'Wuxuu joogaa dekedda',
        'ready' => 'Alaabtu waa diyaar',
        'delivered' => 'Waa la gaarsiiyay'
    ];
    return $map[$status] ?? (string)$status;
}

function ensureWhatsAppContainerLogsSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_container_logs (
        id INT(11) NOT NULL AUTO_INCREMENT,
        tenant_id INT(11) NOT NULL,
        container_id INT(11) NOT NULL,
        customer_id INT(11) DEFAULT NULL,
        phone VARCHAR(30) NOT NULL,
        status VARCHAR(50) DEFAULT NULL,
        message TEXT NOT NULL,
        send_status VARCHAR(20) DEFAULT 'pending',
        api_response TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$greenConfig = __DIR__ . '/../config/greenapi_connect.php';
$waHelper = __DIR__ . '/../includes/whatsapp_helper.php';
if (file_exists($greenConfig)) require_once $greenConfig;
if (file_exists($waHelper)) require_once $waHelper;

$GREEN_API_ID = defined('GREEN_API_ID') ? GREEN_API_ID : (getenv('GREEN_API_ID') ?: '');
$GREEN_API_TOKEN = defined('GREEN_API_TOKEN') ? GREEN_API_TOKEN : (getenv('GREEN_API_TOKEN') ?: '');
$GREEN_API_URL = defined('GREEN_API_URL') ? GREEN_API_URL : (getenv('GREEN_API_URL') ?: 'https://7107.api.greenapi.com');

function formatSomaliPhoneForContainer($phone): string {
    $phone = preg_replace('/\D/', '', (string)$phone);
    if ($phone === '') return '';
    if (strlen($phone) === 9 && in_array($phone[0], ['6', '7'], true)) return '252' . $phone;
    if (strlen($phone) === 10 && $phone[0] === '0') return '252' . substr($phone, 1);
    if (strlen($phone) === 12 && substr($phone, 0, 3) === '252') return $phone;
    return '252' . ltrim($phone, '0');
}

function sendWhatsAppGreenAPIForContainer($phone, $message, $idInstance, $apiToken, $apiUrl): array {
    $formatted = formatSomaliPhoneForContainer($phone);
    if ($formatted === '') return ['success' => false, 'error' => 'Telefoon sax ah lama helin'];

    if (function_exists('sendWhatsAppMessage')) return sendWhatsAppMessage($formatted, $message);
    if (function_exists('sendWhatsApp')) return sendWhatsApp($formatted, $message);

    if ($idInstance === '' || $apiToken === '') {
        return ['success' => false, 'error' => 'GREEN_API_ID ama GREEN_API_TOKEN lama config-gareyn'];
    }
    if (!function_exists('curl_init')) return ['success' => false, 'error' => 'PHP cURL extension lama shidin'];

    $payload = ['chatId' => $formatted . '@c.us', 'message' => $message];
    $lastError = null;

    foreach (['sendMessage', 'SendMessage'] as $endpoint) {
        $url = rtrim($apiUrl, '/') . "/waInstance{$idInstance}/{$endpoint}/{$apiToken}";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30
        ]);
        $response = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $decoded = json_decode((string)$response, true);
        if ($http === 200 && is_array($decoded) && isset($decoded['idMessage'])) {
            return ['success' => true, 'message_id' => $decoded['idMessage']];
        }
        $lastError = ['success' => false, 'error' => $err ?: ($decoded['message'] ?? $response ?? 'WhatsApp failed'), 'http_code' => $http];
    }

    return $lastError ?: ['success' => false, 'error' => 'WhatsApp lama dirin'];
}

function buildContainerSomaliMessage($customerName, $companyName, array $container, $itemsList = ''): string {
    $customerName = trim((string)$customerName) !== '' ? trim((string)$customerName) : 'Macaamiil';
    $message  = "Macmiil: {$customerName}\n";
    $message .= "Container update\n";
    $message .= "Container: " . ($container['container_number'] ?? '-') . "\n";
    if (!empty($container['tracking_number'])) $message .= "Code: {$container['tracking_number']}\n";
    if (!empty($container['bl_number'])) $message .= "BL: {$container['bl_number']}\n";
    if (!empty($container['current_location'])) $message .= "Goob: {$container['current_location']}\n";
    $message .= "Xaalad: " . somaliContainerXaaladText($container['status'] ?? '') . "\n";
    if (!empty($container['estimated_arrival']) && $container['estimated_arrival'] !== '0000-00-00') {
        $message .= "ETA: " . date('d/m/Y', strtotime($container['estimated_arrival'])) . "\n";
    }
    if (!empty($container['arrival_date']) && $container['arrival_date'] !== '0000-00-00') {
        $message .= "Arrival: " . date('d/m/Y', strtotime($container['arrival_date'])) . "\n";
    }
    if (trim((string)$itemsList) !== '') $message .= "Alaab: {$itemsList}\n";
    $message .= "Date: " . date('d/m/Y H:i') . "\n";
    $message .= $companyName;
    return $message;
}

function sendContainerXaaladWhatsAppToMacmiils(PDO $pdo, int $containerId, int $tenantId, int $branchId, ?string $status = null): array {
    global $GREEN_API_ID, $GREEN_API_TOKEN, $GREEN_API_URL;

    try {
        $tenantStmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
        $tenantStmt->execute([$tenantId]);
        $companyName = $tenantStmt->fetchColumn() ?: 'Shirkadda';

        $containerStmt = $pdo->prepare("
            SELECT id, container_number, status, current_location, arrival_date, departure_date,
                   estimated_arrival, tracking_number, bl_number, vessel_name, port_of_loading, port_of_discharge
            FROM containers
            WHERE id = ? AND tenant_id = ? AND current_branch_id = ?
            LIMIT 1
        ");
        $containerStmt->execute([$containerId, $tenantId, $branchId]);
        $container = $containerStmt->fetch(PDO::FETCH_ASSOC);

        if (!$container) {
            return ['success' => false, 'sent' => 0, 'failed' => 0, 'message' => 'Kontaynerka lama helin'];
        }
        if ($status !== null) $container['status'] = $status;

        if (!tableExists($pdo, 'cargo_manifest_items') || !tableExists($pdo, 'warehouse_stock') || !tableExists($pdo, 'customers')) {
            return ['success' => true, 'sent' => 0, 'failed' => 0, 'message' => 'Manifest/customers tables lama helin'];
        }

        $customersStmt = $pdo->prepare("
            SELECT
                cust.id AS customer_id,
                cust.customer_name,
                cust.phone,
                GROUP_CONCAT(DISTINCT cmi.stock_name SEPARATOR ', ') AS items_list
            FROM cargo_manifest_items cmi
            JOIN warehouse_stock ws ON cmi.warehouse_stock_id = ws.id
            JOIN customers cust ON ws.customer_id = cust.id
            WHERE cmi.container_id = ?
              AND cmi.tenant_id = ?
              AND cust.phone IS NOT NULL
              AND cust.phone <> ''
            GROUP BY cust.id, cust.customer_name, cust.phone
        ");
        $customersStmt->execute([$containerId, $tenantId]);
        $customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$customers) {
            return ['success' => true, 'sent' => 0, 'failed' => 0, 'message' => 'Macaamiil telefoon leh lagama helin kontaynerkan'];
        }

        ensureWhatsAppContainerLogsSchema($pdo);

        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($customers as $customer) {
            $message = buildContainerSomaliMessage($customer['customer_name'], $companyName, $container, $customer['items_list'] ?? '');
            $result = sendWhatsAppGreenAPIForContainer($customer['phone'], $message, $GREEN_API_ID, $GREEN_API_TOKEN, $GREEN_API_URL);
            $sendStatus = !empty($result['success']) ? 'sent' : 'failed';

            if ($sendStatus === 'sent') $sent++;
            else {
                $failed++;
                $errors[] = ($customer['customer_name'] ?? 'Macmiil') . ': ' . ($result['error'] ?? 'Khalad');
            }

            $log = $pdo->prepare("
                INSERT INTO whatsapp_container_logs
                (tenant_id, container_id, customer_id, phone, status, message, send_status, api_response, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $log->execute([
                $tenantId,
                $containerId,
                $customer['customer_id'] ?? null,
                $customer['phone'],
                $container['status'],
                $message,
                $sendStatus,
                json_encode($result, JSON_UNESCAPED_UNICODE)
            ]);
        }

        return [
            'success' => $failed === 0,
            'sent' => $sent,
            'failed' => $failed,
            'message' => "WhatsApp: {$sent} fariin waa la diray, {$failed} way fashilmeen.",
            'errors' => $errors
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'sent' => 0, 'failed' => 0, 'message' => $e->getMessage()];
    }
}

/* ==========================================================
   TENANT NAME
========================================================== */
$tenant_name = 'Shirkaddayda';
try {
    $stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$session_tenant_id]);
    $tenant_name = $stmt->fetchColumn() ?: 'Shirkaddayda';
} catch (Throwable $e) {}

/* ==========================================================
   GET EXPORTS
========================================================== */
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'export_containers') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=branch_containers_export_' . date('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, [
            'ID', 'Lambarka Kontaynarka', 'Nooca', 'CBM', 'Miisaanka KG', 'Xaalad',
            'Laanta', 'Goobta Hadda', 'Arrival', 'Departure', 'Tracking', 'Seal', 'BL',
            'Vessel', 'Created'
        ]);

        $where = ["c.tenant_id = ?", "c.current_branch_id = ?"];
        $params = [$session_tenant_id, $assigned_branch_id];

        $search = trim($_GET['search'] ?? '');
        if ($search !== '') {
            $where[] = "(c.container_number LIKE ? OR c.tracking_number LIKE ? OR c.bl_number LIKE ?)";
            $like = "%$search%";
            array_push($params, $like, $like, $like);
        }

        $status = trim($_GET['status'] ?? '');
        if ($status !== '') {
            $where[] = "c.status = ?";
            $params[] = $status;
        }

        $sql = "
            SELECT c.id, c.container_number, c.container_type, c.size_cbm, c.weight_kg, c.status,
                   b.branch_name, c.current_location, c.arrival_date, c.departure_date,
                   c.tracking_number, c.seal_number, c.bl_number, c.vessel_name, c.created_at
            FROM containers c
            LEFT JOIN branches b ON c.current_branch_id = b.id AND b.tenant_id = c.tenant_id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY c.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    if ($action === 'export_manifest') {
        $container_id = (int)($_GET['id'] ?? 0);

        $stmt = $pdo->prepare("SELECT container_number FROM containers WHERE id = ? AND tenant_id = ? AND current_branch_id = ?");
        $stmt->execute([$container_id, $session_tenant_id, $assigned_branch_id]);
        $container = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$container) die("Kontaynar lama helin ama branch-kan kuma jiro");

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=manifest_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $container['container_number']) . '_' . date('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, ['Macmiil', 'Telefoon', 'Xirmooyin', 'CBM', 'Weight KG', 'Rate', 'Total', 'Storage', 'Alaab', 'Xaaladda Muqdisho']);

        if (tableExists($pdo, 'cargo_manifest_items')) {
            $sql = "
                SELECT COALESCE(cust.customer_name, '-') AS customer_name,
                       COALESCE(cust.phone, '-') AS phone,
                       cmi.quantity AS total_packages,
                       cmi.cbm_used AS total_cbm,
                       cmi.weight_kg,
                       COALESCE(cmi.unit_price, ws.unit_price, 0) AS unit_price,
                       (cmi.cbm_used * COALESCE(cmi.unit_price, ws.unit_price, 0)) AS total_price,
                       cmi.storage_fee,
                       cmi.stock_name AS items_list,
                       cmi.mogadishu_status
                FROM cargo_manifest_items cmi
                LEFT JOIN warehouse_stock ws ON cmi.warehouse_stock_id = ws.id
                LEFT JOIN customers cust ON ws.customer_id = cust.id AND cust.tenant_id = ws.tenant_id
                WHERE cmi.container_id = ? AND cmi.tenant_id = ?
                ORDER BY cust.customer_name, cmi.stock_name
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$container_id, $session_tenant_id]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
        }

        fclose($output);
        exit;
    }

    if ($action === 'download_import_template') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=branch_containers_import_template.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($output, [
            'container_number','container_type','size_cbm','weight_kg','status','current_location',
            'arrival_date','departure_date','estimated_arrival','tracking_number','seal_number','notes',
            'shipping_line','bl_number','vessel_name','port_of_loading','port_of_discharge',
            'eta_port','etd_port','customs_status'
        ]);
        fputcsv($output, [
            'MSKU1234567','20ft','33.2','0','received',$branch_name,
            '','','2026-06-20','TRK-20260530-1001','SEAL123','Sample note',
            'MSC','BL123456','MSC SOMALIA','Yiwu','Mogadishu',
            '2026-06-20','','pending'
        ]);
        fclose($output);
        exit;
    }
}

/* ==========================================================
   AJAX ACTIONS
========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    $action = postString('ajax_action');

    if ($action === 'get_containers') {
        $page = max(1, postInt('page', 1));
        $limit = 15;
        $offset = ($page - 1) * $limit;

        $search = postString('search');
        $status_filter = postString('status');

        $where = ["c.tenant_id = ?", "c.current_branch_id = ?"];
        $params = [$session_tenant_id, $assigned_branch_id];

        if ($search !== '') {
            $where[] = "(c.container_number LIKE ? OR c.tracking_number LIKE ? OR c.bl_number LIKE ? OR c.vessel_name LIKE ?)";
            $like = "%$search%";
            array_push($params, $like, $like, $like, $like);
        }

        if ($status_filter !== '') {
            $where[] = "c.status = ?";
            $params[] = $status_filter;
        }

        $where_sql = "WHERE " . implode(" AND ", $where);

        try {
            $count_sql = "SELECT COUNT(*) AS total FROM containers c $where_sql";
            $stmt = $pdo->prepare($count_sql);
            $stmt->execute($params);
            $total_containers = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
            $total_pages = max(1, (int)ceil($total_containers / $limit));

            $sql = "
                SELECT c.*, b.branch_name AS branch_name
                FROM containers c
                LEFT JOIN branches b ON c.current_branch_id = b.id AND b.tenant_id = c.tenant_id
                $where_sql
                ORDER BY c.created_at DESC, c.id DESC
                LIMIT $limit OFFSET $offset
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $containers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            global $status_names, $status_colors, $customs_status_names;

            ob_start(); ?>
            <div style="overflow-x:auto;width:100%;">
                <table class="containers-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Lambarka Kontaynarka</th>
                            <th>Nooca</th>
                            <th>CBM</th>
                            <th>Xaalad</th>
                            <th>Laanta</th>
                            <th>Safarkii Ugu Dambeeyay</th>
                            <th>BL / Tracking</th>
                            <th>Hawlo</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($containers): ?>
                        <?php foreach ($containers as $container):
                            $statusColor = $status_colors[$container['status']] ?? '#6c757d';
                            $statusName = $status_names[$container['status']] ?? ucfirst((string)$container['status']);
                            $isManifestLocked = isContainerManifestLocked($container['status']);
                            $isFinalLocked = isContainerFinalLocked($container['status']);

                            $lastTrip = null;
                            if (tableExists($pdo, 'trucking_trips')) {
                                $tripStmt = $pdo->prepare("
                                    SELECT trip_number, status
                                    FROM trucking_trips
                                    WHERE container_id = ? AND tenant_id = ?
                                    ORDER BY created_at DESC, id DESC
                                    LIMIT 1
                                ");
                                $tripStmt->execute([$container['id'], $GLOBALS['session_tenant_id']]);
                                $lastTrip = $tripStmt->fetch(PDO::FETCH_ASSOC);
                            }
                        ?>
                            <tr>
                                <td><?= (int)$container['id'] ?></td>
                                <td>
                                    <strong><?= h($container['container_number']) ?></strong>
                                    <div class="tiny"><i class="fas fa-calendar-alt"></i> <?= h(date('d/m/Y', strtotime($container['created_at'] ?? 'now'))) ?></div>
                                </td>
                                <td><?= h($container['container_type'] ?? '20ft') ?></td>
                                <td>
                                    <?= number_format((float)($container['size_used_cbm'] ?? 0), 2) ?> /
                                    <?= number_format((float)($container['size_cbm'] ?? 0), 2) ?> CBM
                                </td>
                                <td>
                                    <span class="status-badge" style="background:<?= h($statusColor) ?>20;color:<?= h($statusColor) ?>;">
                                        <?= h($statusName) ?>
                                    </span>
                                    <?php if (($container['customs_status'] ?? '') === 'cleared'): ?>
                                        <div class="tiny text-info">🛃 Kastamku wuu fasaxay</div>
                                    <?php endif; ?>
                                    <?php if ($isManifestLocked): ?>
                                        <div class="tiny text-danger"><i class="fas fa-lock"></i> Manifest locked</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <i class="fas fa-store"></i> <?= h($container['branch_name'] ?? $GLOBALS['branch_name']) ?>
                                    <?php if (!empty($container['current_location'])): ?>
                                        <div class="tiny"><?= h($container['current_location']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($lastTrip): ?>
                                        <strong><?= h($lastTrip['trip_number']) ?></strong>
                                        <div class="tiny"><?= h($status_names[$lastTrip['status']] ?? $lastTrip['status']) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">Lama xirin</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($container['bl_number'])): ?>
                                        <code><?= h($container['bl_number']) ?></code><br>
                                    <?php endif; ?>
                                    <small><?= h($container['tracking_number'] ?? '-') ?></small>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="action-btn btn-view view-container" data-id="<?= (int)$container['id'] ?>" title="Faahfaahin"><i class="fas fa-eye"></i></button>
                                        <button class="action-btn btn-tracking tracking-container" data-id="<?= (int)$container['id'] ?>" title="Tracking"><i class="fas fa-map-marker-alt"></i></button>
                                        <button class="action-btn btn-whatsapp whatsapp-all" data-id="<?= (int)$container['id'] ?>" title="WhatsApp"><i class="fab fa-whatsapp"></i></button>

                                        <?php if (!$isManifestLocked): ?>
                                            <button class="action-btn btn-edit edit-container" data-id="<?= (int)$container['id'] ?>" title="Edit"><i class="fas fa-edit"></i></button>
                                            <button class="action-btn btn-delete delete-container" data-id="<?= (int)$container['id'] ?>" data-name="<?= h($container['container_number']) ?>" title="Delete"><i class="fas fa-trash"></i></button>
                                        <?php else: ?>
                                            <button class="action-btn btn-lock" disabled title="Manifest locked"><i class="fas fa-lock"></i></button>
                                        <?php endif; ?>

                                        <?php if (!$isFinalLocked): ?>
                                            <button class="action-btn btn-status update-status" data-id="<?= (int)$container['id'] ?>" data-status="<?= h($container['status']) ?>" title="Update Status"><i class="fas fa-exchange-alt"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="empty-cell">
                                <i class="fas fa-box fa-3x"></i>
                                <p>Kontaynarro lama helin branch-kan.</p>
                                <button class="btn-primary-custom" id="addContainerBtnEmpty"><i class="fas fa-plus-circle"></i> Ku dar Kontaynar</button>
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
                <div class="pagination">
                    <?php if ($page > 1): ?><a data-page="<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i> Hore</a><?php endif; ?>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i === $page): ?><span class="active"><?= $i ?></span><?php else: ?><a data-page="<?= $i ?>"><?= $i ?></a><?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?><a data-page="<?= $page + 1 ?>">Xiga <i class="fas fa-chevron-right"></i></a><?php endif; ?>
                </div>
            <?php endif;
            $pagination_html = ob_get_clean();

            jsonResponse(['success' => true, 'table_html' => $table_html, 'pagination_html' => $pagination_html, 'total' => $total_containers]);
        } catch (Throwable $e) {
            jsonResponse(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
    }

    if ($action === 'get_container') {
        $id = postInt('id');

        try {
            $stmt = $pdo->prepare("
                SELECT c.*, b.branch_name AS branch_name
                FROM containers c
                LEFT JOIN branches b ON c.current_branch_id = b.id AND b.tenant_id = c.tenant_id
                WHERE c.id = ? AND c.tenant_id = ? AND c.current_branch_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $session_tenant_id, $assigned_branch_id]);
            $container = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$container) {
                jsonResponse(['success' => false, 'message' => 'Kontaynar lama helin ama branch-kan kuma jiro']);
            }

            $manifest = [];
            if (tableExists($pdo, 'cargo_manifest_items')) {
                $manifestStmt = $pdo->prepare("
                    SELECT cmi.id, cust.customer_name, cust.phone,
                           cmi.quantity AS total_packages,
                           cmi.cbm_used AS total_cbm,
                           COALESCE(cmi.unit_price, ws.unit_price, 0) AS cbm_price,
                           (cmi.cbm_used * COALESCE(cmi.unit_price, ws.unit_price, 0)) AS total_price,
                           cmi.stock_name AS items_list,
                           cmi.added_at,
                           cmi.weight_kg,
                           cmi.storage_fee,
                           cmi.mogadishu_status,
                           ws.id AS stock_id
                    FROM cargo_manifest_items cmi
                    LEFT JOIN warehouse_stock ws ON cmi.warehouse_stock_id = ws.id
                    LEFT JOIN customers cust ON ws.customer_id = cust.id AND cust.tenant_id = ws.tenant_id
                    WHERE cmi.container_id = ? AND cmi.tenant_id = ?
                    ORDER BY cmi.added_at DESC
                ");
                $manifestStmt->execute([$id, $session_tenant_id]);
                $manifest = $manifestStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            jsonResponse(['success' => true, 'container' => $container, 'manifest' => $manifest]);
        } catch (Throwable $e) {
            jsonResponse(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
    }

    if ($action === 'save_container') {
        $id = postString('container_id');
        $container_number = postString('container_number');
        $container_type = postString('container_type', '20ft');
        $weight_kg = postFloat('weight_kg', 0);
        $status = postString('status', 'received');
        $current_location = postString('current_location', $branch_name);
        $arrival_date = nullableDate($_POST['arrival_date'] ?? '');
        $departure_date = nullableDate($_POST['departure_date'] ?? '');
        $estimated_arrival = nullableDate($_POST['estimated_arrival'] ?? '');
        $tracking_number = postString('tracking_number');
        $seal_number = postString('seal_number');
        $notes = postString('notes');
        $shipping_line = postString('shipping_line');
        $bl_number = postString('bl_number');
        $vessel_name = postString('vessel_name');
        $port_of_loading = postString('port_of_loading');
        $port_of_discharge = postString('port_of_discharge');
        $eta_port = nullableDate($_POST['eta_port'] ?? '');
        $etd_port = nullableDate($_POST['etd_port'] ?? '');
        $customs_status = postString('customs_status', 'pending');

        global $container_cbm_map;
        $allowed_statuses = ['received','loading','loaded','shipped','dispatched','at_port','ready','delivered'];
        $allowed_types = ['20ft','40ft','40hc','lcl'];
        $allowed_customs = ['pending','cleared','held'];

        if (!in_array($container_type, $allowed_types, true)) $container_type = '20ft';
        if (!in_array($status, $allowed_statuses, true)) $status = 'received';
        if (!in_array($customs_status, $allowed_customs, true)) $customs_status = 'pending';

        $size_cbm = postFloat('size_cbm', $container_cbm_map[$container_type] ?? 0);

        if ($container_number === '') {
            jsonResponse(['success' => false, 'message' => 'Fadlan geli lambarka kontaynarka']);
        }

        try {
            if ($id === '') {
                $check = $pdo->prepare("SELECT id FROM containers WHERE container_number = ? AND tenant_id = ? LIMIT 1");
                $check->execute([$container_number, $session_tenant_id]);
                if ($check->fetch(PDO::FETCH_ASSOC)) {
                    jsonResponse(['success' => false, 'message' => "Container number '$container_number' horay ayuu uga jiraa shirkaddaada"]);
                }

                if ($tracking_number === '') {
                    $tracking_number = 'TRK-' . date('Ymd') . '-' . random_int(1000, 9999);
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO containers (
                        tenant_id, container_number, container_type, size_cbm, weight_kg, status,
                        current_location, current_branch_id, arrival_date, departure_date, estimated_arrival,
                        tracking_number, seal_number, notes, shipping_line, bl_number, vessel_name,
                        port_of_loading, port_of_discharge, eta_port, etd_port, customs_status, created_by, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                    )
                ");
                $stmt->execute([
                    $session_tenant_id, $container_number, $container_type, $size_cbm, $weight_kg, $status,
                    $current_location, $assigned_branch_id, $arrival_date, $departure_date, $estimated_arrival,
                    $tracking_number, $seal_number, $notes, $shipping_line, $bl_number, $vessel_name,
                    $port_of_loading, $port_of_discharge, $eta_port, $etd_port, $customs_status, $user_id
                ]);

                $container_id = (int)$pdo->lastInsertId();

                if (tableExists($pdo, 'trucking_trips')) {
                    $trip_number = 'TRP-' . date('ymd') . '-' . str_pad((string)$container_id, 3, '0', STR_PAD_LEFT);
                    $tripStmt = $pdo->prepare("
                        INSERT INTO trucking_trips (tenant_id, branch_id, container_id, trip_number, status, created_at)
                        VALUES (?, ?, ?, ?, 'pending', NOW())
                    ");
                    $tripStmt->execute([$session_tenant_id, $assigned_branch_id, $container_id, $trip_number]);
                }

                $pdo->commit();

                jsonResponse(['success' => true, 'message' => "Container '$container_number' waa la kaydiyay!", 'id' => $container_id]);
            }

            $container_id = (int)$id;
            $checkLock = $pdo->prepare("SELECT status FROM containers WHERE id = ? AND tenant_id = ? AND current_branch_id = ? LIMIT 1");
            $checkLock->execute([$container_id, $session_tenant_id, $assigned_branch_id]);
            $currentContainer = $checkLock->fetch(PDO::FETCH_ASSOC);

            if (!$currentContainer) {
                jsonResponse(['success' => false, 'message' => 'Kontaynar lama helin ama branch-kan kuma jiro']);
            }

            if (isContainerManifestLocked($currentContainer['status'])) {
                jsonResponse(['success' => false, 'message' => 'Kontaynarkan lama beddeli karo sababtoo ah wuu dhoofay ama waa la gaarsiiyay.']);
            }

            $stmt = $pdo->prepare("
                UPDATE containers
                SET container_number = ?, container_type = ?, size_cbm = ?, weight_kg = ?, status = ?,
                    current_location = ?, current_branch_id = ?, arrival_date = ?, departure_date = ?,
                    estimated_arrival = ?, tracking_number = ?, seal_number = ?, notes = ?, shipping_line = ?,
                    bl_number = ?, vessel_name = ?, port_of_loading = ?, port_of_discharge = ?,
                    eta_port = ?, etd_port = ?, customs_status = ?, updated_at = NOW()
                WHERE id = ? AND tenant_id = ? AND current_branch_id = ?
            ");
            $stmt->execute([
                $container_number, $container_type, $size_cbm, $weight_kg, $status,
                $current_location, $assigned_branch_id, $arrival_date, $departure_date,
                $estimated_arrival, $tracking_number, $seal_number, $notes, $shipping_line,
                $bl_number, $vessel_name, $port_of_loading, $port_of_discharge,
                $eta_port, $etd_port, $customs_status,
                $container_id, $session_tenant_id, $assigned_branch_id
            ]);

            jsonResponse(['success' => true, 'message' => "Container '$container_number' waa la cusboonaysiiyay!"]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
    }

    if ($action === 'delete_container') {
        $id = postInt('id');

        try {
            $check = $pdo->prepare("SELECT status, container_number FROM containers WHERE id = ? AND tenant_id = ? AND current_branch_id = ? LIMIT 1");
            $check->execute([$id, $session_tenant_id, $assigned_branch_id]);
            $container = $check->fetch(PDO::FETCH_ASSOC);

            if (!$container) jsonResponse(['success' => false, 'message' => 'Kontaynar lama helin']);
            if (isContainerManifestLocked($container['status'])) {
                jsonResponse(['success' => false, 'message' => "Container '{$container['container_number']}' lama tirtiri karo sababtoo ah wuu dhoofay ama waa la gaarsiiyay."]);
            }

            if (tableExists($pdo, 'trucking_trips')) {
                $tripCheck = $pdo->prepare("SELECT COUNT(*) FROM trucking_trips WHERE container_id = ? AND tenant_id = ?");
                $tripCheck->execute([$id, $session_tenant_id]);
                $tripCount = (int)$tripCheck->fetchColumn();
                if ($tripCount > 0) {
                    jsonResponse(['success' => false, 'message' => "Kontaynarkan wuxuu leeyahay $tripCount safar oo ku xiran. Marka hore tirtir safarrada."]);
                }
            }

            $stmt = $pdo->prepare("DELETE FROM containers WHERE id = ? AND tenant_id = ? AND current_branch_id = ?");
            $stmt->execute([$id, $session_tenant_id, $assigned_branch_id]);

            jsonResponse(['success' => true, 'message' => "Container '{$container['container_number']}' waa la tirtiray!"]);
        } catch (Throwable $e) {
            jsonResponse(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
    }

    if ($action === 'update_status') {
        $id = postInt('id');
        $status = postString('status');

        $allowed = ['received','loading','loaded','shipped','dispatched','at_port','ready','delivered'];
        if (!in_array($status, $allowed, true)) {
            jsonResponse(['success' => false, 'message' => 'Xaalad aan sax ahayn']);
        }

        try {
            $pdo->beginTransaction();

            $check = $pdo->prepare("SELECT status FROM containers WHERE id = ? AND tenant_id = ? AND current_branch_id = ? FOR UPDATE");
            $check->execute([$id, $session_tenant_id, $assigned_branch_id]);
            $current = $check->fetch(PDO::FETCH_ASSOC);

            if (!$current) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'message' => 'Kontaynar lama helin ama branch-kan kuma jiro']);
            }

            if (isContainerFinalLocked($current['status'])) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'message' => 'Kontaynarkan waa la gaarsiiyay; xaaladdiisa lama beddeli karo.']);
            }

            if (!canMoveContainerStatusForward($current['status'], $status)) {
                $pdo->rollBack();
                jsonResponse([
                    'success' => false,
                    'message' => 'Xaalad hore dib looguma celin karo. Xaaladda hadda: ' . somaliContainerXaaladText($current['status']) . '. Door xaalad ka dambeysa oo keliya.'
                ]);
            }

            $stmt = $pdo->prepare("UPDATE containers SET status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ? AND current_branch_id = ?");
            $stmt->execute([$status, $id, $session_tenant_id, $assigned_branch_id]);

            if (tableExists($pdo, 'trucking_trips')) {
                if ($status === 'loaded') {
                    $pdo->prepare("UPDATE trucking_trips SET status = 'loaded', loaded_at = NOW() WHERE container_id = ? AND tenant_id = ?")->execute([$id, $session_tenant_id]);
                } elseif ($status === 'shipped' || $status === 'dispatched') {
                    $pdo->prepare("UPDATE trucking_trips SET status = 'in_transit', departed_at = NOW() WHERE container_id = ? AND tenant_id = ?")->execute([$id, $session_tenant_id]);
                } elseif ($status === 'delivered') {
                    $pdo->prepare("UPDATE trucking_trips SET status = 'completed', delivered_at = NOW() WHERE container_id = ? AND tenant_id = ?")->execute([$id, $session_tenant_id]);
                }
            }

            $pushed = 0;
            if ($status === 'ready' && tableExists($pdo, 'cargo_manifest_items')) {
                $push = $pdo->prepare("
                    UPDATE cargo_manifest_items
                    SET mogadishu_status = 'in_warehouse',
                        mogadishu_received_date = NOW()
                    WHERE container_id = ? AND tenant_id = ?
                ");
                $push->execute([$id, $session_tenant_id]);
                $pushed = $push->rowCount();

                if (tableExists($pdo, 'warehouse_stock')) {
                    $pdo->prepare("
                        UPDATE warehouse_stock ws
                        JOIN cargo_manifest_items cmi ON cmi.warehouse_stock_id = ws.id
                        SET ws.mogadishu_status = 'in_warehouse',
                            ws.mogadishu_received_date = NOW()
                        WHERE cmi.container_id = ? AND ws.tenant_id = ?
                    ")->execute([$id, $session_tenant_id]);
                }
            } elseif (!isContainerManifestLocked($status) && tableExists($pdo, 'cargo_manifest_items')) {
                $pdo->prepare("
                    UPDATE cargo_manifest_items
                    SET mogadishu_status = 'not_arrived',
                        mogadishu_received_date = NULL
                    WHERE container_id = ? AND tenant_id = ? AND mogadishu_status != 'taken'
                ")->execute([$id, $session_tenant_id]);
            }

            $pdo->commit();

            $wa = sendContainerXaaladWhatsAppToMacmiils($pdo, $id, $session_tenant_id, $assigned_branch_id, $status);
            $waMsg = isset($wa['message']) ? ' | ' . $wa['message'] : '';

            $msg = 'Xaaladda kontaynarka waa la cusboonaysiiyay!';
            if ($status === 'ready') $msg .= " $pushed alaab ayaa loo diray Bakhaarka.";

            jsonResponse(['success' => true, 'message' => $msg . $waMsg, 'pushed' => $pushed, 'whatsapp' => $wa]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
    }

    if ($action === 'send_whatsapp_all') {
        $id = postInt('id');
        $result = sendContainerXaaladWhatsAppToMacmiils($pdo, $id, $session_tenant_id, $assigned_branch_id);
        jsonResponse($result);
    }

    if ($action === 'get_tracking_history') {
        $id = postInt('id');

        try {
            $stmt = $pdo->prepare("
                SELECT c.*, b.branch_name
                FROM containers c
                LEFT JOIN branches b ON c.current_branch_id = b.id AND b.tenant_id = c.tenant_id
                WHERE c.id = ? AND c.tenant_id = ? AND c.current_branch_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $session_tenant_id, $assigned_branch_id]);
            $container = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$container) {
                jsonResponse(['success' => false, 'message' => 'Kontaynerka lama helin ama branch-kan kuma jiro.']);
            }

            $currentRank = containerStatusRank($container['status'] ?? 'received');
            $flow = [
                'received' => ['rank' => 1, 'title' => 'La helay', 'icon' => 'fa-box-open'],
                'loading' => ['rank' => 2, 'title' => 'Waa la rarayaa', 'icon' => 'fa-truck-loading'],
                'loaded' => ['rank' => 3, 'title' => 'Waa la raray', 'icon' => 'fa-boxes-stacked'],
                'shipped' => ['rank' => 4, 'title' => 'Wuu dhoofay', 'icon' => 'fa-ship'],
                'dispatched' => ['rank' => 5, 'title' => 'Waa la diray', 'icon' => 'fa-truck-fast'],
                'at_port' => ['rank' => 6, 'title' => 'Dekedda ayuu joogaa', 'icon' => 'fa-anchor'],
                'ready' => ['rank' => 7, 'title' => 'Diyaar', 'icon' => 'fa-circle-check'],
                'delivered' => ['rank' => 8, 'title' => 'La gaarsiiyay', 'icon' => 'fa-handshake']
            ];

            ob_start(); ?>
            <div class="tracking-card">
                <h5><?= h($container['container_number']) ?></h5>
                <p class="text-muted mb-3"><?= h($container['current_location'] ?? $container['branch_name'] ?? '-') ?></p>
                <div class="timeline">
                    <?php foreach ($flow as $key => $item):
                        $done = $item['rank'] <= $currentRank;
                        $current = $key === ($container['status'] ?? '');
                    ?>
                        <div class="timeline-item <?= $done ? 'done' : '' ?> <?= $current ? 'current' : '' ?>">
                            <div class="timeline-icon"><i class="fas <?= h($item['icon']) ?>"></i></div>
                            <div>
                                <strong><?= h($item['title']) ?></strong>
                                <div class="tiny"><?= $done ? 'Dhacay/La gaaray' : 'Sugaya' ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
            jsonResponse(['success' => true, 'html' => ob_get_clean(), 'container' => $container]);
        } catch (Throwable $e) {
            jsonResponse(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
    }

    if ($action === 'remove_manifest_item' || $action === 'delete_manifest_item') {
        $id = postInt('id');

        try {
            if (!tableExists($pdo, 'cargo_manifest_items')) {
                jsonResponse(['success' => false, 'message' => 'Manifest table lama helin']);
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT cmi.warehouse_stock_id, cmi.quantity, cmi.container_id, c.status
                FROM cargo_manifest_items cmi
                JOIN containers c ON cmi.container_id = c.id AND c.tenant_id = cmi.tenant_id
                WHERE cmi.id = ? AND cmi.tenant_id = ? AND c.current_branch_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $session_tenant_id, $assigned_branch_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'message' => 'Alaabta lama helin ama branch-kan kuma jirto']);
            }

            if (isContainerManifestLocked($item['status'])) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'message' => 'Kontaynarkan wuu dhoofay/full ayuu noqday; manifest-ka lama beddeli karo.']);
            }

            if ($action === 'remove_manifest_item' && tableExists($pdo, 'warehouse_stock')) {
                $pdo->prepare("UPDATE warehouse_stock SET quantity = quantity + ? WHERE id = ? AND tenant_id = ?")
                    ->execute([(int)$item['quantity'], (int)$item['warehouse_stock_id'], $session_tenant_id]);
            }

            $pdo->prepare("DELETE FROM cargo_manifest_items WHERE id = ? AND tenant_id = ?")
                ->execute([$id, $session_tenant_id]);

            $cbmStmt = $pdo->prepare("SELECT COALESCE(SUM(cbm_used), 0) FROM cargo_manifest_items WHERE container_id = ? AND tenant_id = ?");
            $cbmStmt->execute([(int)$item['container_id'], $session_tenant_id]);
            $totalCbm = (float)$cbmStmt->fetchColumn();

            $pdo->prepare("UPDATE containers SET size_used_cbm = ? WHERE id = ? AND tenant_id = ? AND current_branch_id = ?")
                ->execute([$totalCbm, (int)$item['container_id'], $session_tenant_id, $assigned_branch_id]);

            $pdo->commit();

            $msg = $action === 'remove_manifest_item'
                ? 'Alaabta waa laga saaray kontaynar-ka, waxaana lagu celiyay bakhaarka.'
                : 'Alaabta si joogto ah ayaa looga tirtiray kontaynar-ka.';

            jsonResponse(['success' => true, 'message' => $msg]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
    }

    if ($action === 'set_container_full') {
        $_POST['status'] = 'ready';
        $_POST['ajax_action'] = 'update_status';
        // Direct recursive include is unsafe; implement inline.
        jsonResponse(['success' => false, 'message' => 'Isticmaal badhanka Update Status oo dooro Diyaar.']);
    }

    if ($action === 'set_container_open') {
        jsonResponse(['success' => false, 'message' => 'Branch Manager ma celin karo container status hore.']);
    }

    if ($action === 'import_containers') {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['success' => false, 'message' => 'Fadlan dooro CSV file sax ah.']);
        }

        $handle = fopen($_FILES['import_file']['tmp_name'], 'r');
        if (!$handle) jsonResponse(['success' => false, 'message' => 'File-ka lama furi karin.']);

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            jsonResponse(['success' => false, 'message' => 'CSV file-ku waa madhan yahay.']);
        }

        $header = array_map(function($h) {
            $h = preg_replace('/^\xEF\xBB\xBF/', '', (string)$h);
            return strtolower(trim($h));
        }, $header);

        $inserted = 0;
        $skipped = 0;
        $errors = [];
        $rowNumber = 1;

        $allowed_statuses = ['received','loading','loaded','shipped','dispatched','at_port','ready','delivered'];
        $allowed_types = ['20ft','40ft','40hc','lcl'];
        $allowed_customs = ['pending','cleared','held'];
        global $container_cbm_map;

        try {
            $pdo->beginTransaction();

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue;

                $data = [];
                foreach ($header as $i => $key) $data[$key] = trim($row[$i] ?? '');

                $container_number = $data['container_number'] ?? '';
                if ($container_number === '') {
                    $skipped++;
                    $errors[] = "Row {$rowNumber}: container_number waa madhan yahay.";
                    continue;
                }

                $check = $pdo->prepare("SELECT id FROM containers WHERE container_number = ? AND tenant_id = ? LIMIT 1");
                $check->execute([$container_number, $session_tenant_id]);
                if ($check->fetch(PDO::FETCH_ASSOC)) {
                    $skipped++;
                    $errors[] = "Row {$rowNumber}: kontaynerkan horay ayuu u jiray ({$container_number}).";
                    continue;
                }

                $type = $data['container_type'] ?? '20ft';
                if (!in_array($type, $allowed_types, true)) $type = '20ft';

                $status = $data['status'] ?? 'received';
                if (!in_array($status, $allowed_statuses, true)) $status = 'received';

                $customs = $data['customs_status'] ?? 'pending';
                if (!in_array($customs, $allowed_customs, true)) $customs = 'pending';

                $size = isset($data['size_cbm']) && $data['size_cbm'] !== '' ? (float)$data['size_cbm'] : ($container_cbm_map[$type] ?? 0);
                $weight = isset($data['weight_kg']) && $data['weight_kg'] !== '' ? (float)$data['weight_kg'] : 0;
                $tracking = $data['tracking_number'] ?? '';
                if ($tracking === '') $tracking = 'TRK-' . date('Ymd') . '-' . random_int(1000, 9999);

                $dates = [];
                foreach (['arrival_date','departure_date','estimated_arrival','eta_port','etd_port'] as $field) {
                    $dates[$field] = !empty($data[$field]) && strtotime($data[$field]) ? date('Y-m-d', strtotime($data[$field])) : null;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO containers (
                        tenant_id, container_number, container_type, size_cbm, weight_kg, status,
                        current_location, current_branch_id, arrival_date, departure_date, estimated_arrival,
                        tracking_number, seal_number, notes, shipping_line, bl_number, vessel_name,
                        port_of_loading, port_of_discharge, eta_port, etd_port, customs_status, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $session_tenant_id, $container_number, $type, $size, $weight, $status,
                    $data['current_location'] ?? $branch_name, $assigned_branch_id,
                    $dates['arrival_date'], $dates['departure_date'], $dates['estimated_arrival'],
                    $tracking, $data['seal_number'] ?? '', $data['notes'] ?? '',
                    $data['shipping_line'] ?? '', $data['bl_number'] ?? '', $data['vessel_name'] ?? '',
                    $data['port_of_loading'] ?? '', $data['port_of_discharge'] ?? '',
                    $dates['eta_port'], $dates['etd_port'], $customs, $user_id
                ]);

                $container_id = (int)$pdo->lastInsertId();

                if (tableExists($pdo, 'trucking_trips')) {
                    $trip_number = 'TRP-' . date('ymd') . '-' . str_pad((string)$container_id, 3, '0', STR_PAD_LEFT);
                    $pdo->prepare("INSERT INTO trucking_trips (tenant_id, branch_id, container_id, trip_number, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())")
                        ->execute([$session_tenant_id, $assigned_branch_id, $container_id, $trip_number]);
                }

                $inserted++;
            }

            fclose($handle);
            $pdo->commit();

            jsonResponse([
                'success' => true,
                'message' => "Soo geli waa dhammaaday: {$inserted} waa la geliyay, {$skipped} waa la dhaafay.",
                'inserted' => $inserted,
                'skipped' => $skipped,
                'errors' => array_slice($errors, 0, 10)
            ]);
        } catch (Throwable $e) {
            if (is_resource($handle)) fclose($handle);
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Soo geli khalad ayuu galay: ' . $e->getMessage()]);
        }
    }

    jsonResponse(['success' => false, 'message' => 'Action lama yaqaan']);
}

/* ==========================================================
   PAGE UI
========================================================== */
require_once __DIR__ . '/../includes/header.php';
?>
<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <title>Kontaynarada - <?= h($branch_name) ?> | <?= h($tenant_name) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        :root {
            --primary: #2D1859;
            --primary2: #4B2C85;
            --yellow: #F5C410;
            --bg: #f4f6f9;
            --white: #ffffff;
            --muted: #6c757d;
            --danger: #dc3545;
            --success: #0F7A3A;
            --info: #17a2b8;
        }

        body {
            background: var(--bg);
            font-family: "Segoe UI", Tahoma, sans-serif;
        }

        .page-wrap {
            padding: 22px;
        }

        .page-header-custom {
            background: linear-gradient(135deg, var(--primary), var(--primary2));
            color: #fff;
            border-radius: 18px;
            padding: 24px;
            margin-bottom: 22px;
            box-shadow: 0 14px 35px rgba(82,0,102,.18);
            display:flex;
            justify-content:space-between;
            align-items:center;
            flex-wrap:wrap;
            gap:15px;
        }

        .page-header-custom h1 {
            margin:0;
            font-size:26px;
            font-weight:800;
        }

        .page-header-custom p {
            margin:5px 0 0;
            opacity:.9;
        }

        .header-actions {
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }

        .btn-primary-custom,
        .btn-outline-custom {
            border:none;
            border-radius:12px;
            padding:10px 15px;
            cursor:pointer;
            font-weight:700;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            gap:7px;
        }

        .btn-primary-custom {
            background: var(--yellow);
            color: var(--primary);
        }

        .btn-outline-custom {
            background: rgba(255,255,255,.14);
            color:#fff;
            border:1px solid rgba(255,255,255,.35);
        }

        .panel {
            background:#fff;
            border-radius:18px;
            padding:18px;
            box-shadow:0 8px 22px rgba(0,0,0,.06);
        }

        .toolbar {
            display:flex;
            justify-content:space-between;
            align-items:center;
            flex-wrap:wrap;
            gap:12px;
            margin-bottom:16px;
        }

        .toolbar-left {
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }

        .search-input,
        .filter-select {
            height:42px;
            border:1px solid #ddd;
            border-radius:12px;
            padding:0 12px;
            min-width:220px;
            outline:none;
        }

        .containers-table {
            width:100%;
            min-width:1200px;
            border-collapse:collapse;
        }

        .containers-table th {
            background:#f8f6f9;
            color:#333;
            padding:13px;
            font-size:13px;
            border-bottom:1px solid #e9e9e9;
            white-space:nowrap;
        }

        .containers-table td {
            padding:13px;
            border-bottom:1px solid #eee;
            vertical-align:middle;
            font-size:13px;
        }

        .containers-table tr:hover {
            background:#fafafa;
        }

        .tiny {
            font-size:11px;
            color:var(--muted);
            margin-top:3px;
        }

        .status-badge {
            padding:5px 11px;
            border-radius:999px;
            font-size:11px;
            font-weight:700;
            white-space:nowrap;
            display:inline-block;
        }

        .action-buttons {
            display:flex;
            gap:5px;
            flex-wrap:wrap;
        }

        .action-btn {
            width:32px;
            height:32px;
            border:none;
            border-radius:8px;
            color:#fff;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }

        .btn-view { background:#17a2b8; }
        .btn-edit { background:#ffc107; color:#222; }
        .btn-delete { background:#dc3545; }
        .btn-status { background:#6f42c1; }
        .btn-tracking { background:#fd7e14; }
        .btn-whatsapp { background:#25d366; }
        .btn-lock { background:#6c757d; }

        .pagination {
            display:flex;
            justify-content:center;
            gap:8px;
            margin-top:22px;
            flex-wrap:wrap;
        }

        .pagination a,
        .pagination span {
            padding:8px 13px;
            border-radius:9px;
            border:1px solid #ddd;
            background:#fff;
            cursor:pointer;
            text-decoration:none;
            color:#333;
        }

        .pagination .active {
            background:var(--primary);
            color:#fff;
            border-color:var(--primary);
        }

        .empty-cell {
            text-align:center;
            padding:55px!important;
            color:var(--muted);
        }

        .modal {
            display:none;
            position:fixed;
            z-index:5000;
            left:0; top:0;
            width:100%; height:100%;
            background:rgba(0,0,0,.5);
            overflow:auto;
        }

        .modal-content-custom {
            background:#fff;
            margin:40px auto;
            border-radius:18px;
            max-width:920px;
            box-shadow:0 20px 55px rgba(0,0,0,.25);
            overflow:hidden;
        }

        .modal-header-custom {
            background:linear-gradient(135deg,var(--primary),var(--primary2));
            color:#fff;
            padding:18px 22px;
            display:flex;
            justify-content:space-between;
            align-items:center;
        }

        .modal-header-custom h3 {
            margin:0;
            font-size:20px;
            font-weight:800;
        }

        .modal-close {
            background:none;
            border:none;
            color:#fff;
            font-size:26px;
            cursor:pointer;
        }

        .modal-body-custom {
            padding:22px;
        }

        .form-grid {
            display:grid;
            grid-template-columns:repeat(3, minmax(180px, 1fr));
            gap:15px;
        }

        .form-group-custom {
            display:flex;
            flex-direction:column;
            gap:6px;
        }

        .form-group-custom label {
            font-weight:700;
            font-size:13px;
            color:#333;
        }

        .form-group-custom input,
        .form-group-custom select,
        .form-group-custom textarea {
            border:1px solid #ddd;
            border-radius:11px;
            padding:10px 11px;
            outline:none;
            font-size:14px;
        }

        .form-group-custom textarea {
            min-height:90px;
            resize:vertical;
        }

        .span-2 { grid-column:span 2; }
        .span-3 { grid-column:span 3; }

        .modal-footer-custom {
            padding:16px 22px;
            border-top:1px solid #eee;
            display:flex;
            justify-content:flex-end;
            gap:10px;
            flex-wrap:wrap;
        }

        .btn-save {
            background:var(--primary);
            color:#fff;
            border:none;
            border-radius:12px;
            padding:10px 18px;
            font-weight:800;
            cursor:pointer;
        }

        .btn-cancel {
            background:#f1f1f1;
            color:#333;
            border:none;
            border-radius:12px;
            padding:10px 18px;
            font-weight:700;
            cursor:pointer;
        }

        .alert {
            padding:12px 14px;
            border-radius:12px;
            margin:10px 0;
            font-size:14px;
        }

        .alert-success { background:#EEFBF3; color:#155724; }
        .alert-danger { background:#f8d7da; color:#721c24; }
        .alert-info { background:#d1ecf1; color:#0c5460; }

        .manifest-table {
            width:100%;
            border-collapse:collapse;
            margin-top:12px;
        }
        .manifest-table th,
        .manifest-table td {
            padding:9px;
            border-bottom:1px solid #eee;
            font-size:13px;
        }

        .timeline {
            border-left:3px solid #ddd;
            margin-left:15px;
            padding-left:18px;
        }
        .timeline-item {
            display:flex;
            gap:12px;
            margin-bottom:16px;
            opacity:.55;
        }
        .timeline-item.done {
            opacity:1;
        }
        .timeline-item.current strong {
            color:var(--primary);
        }
        .timeline-icon {
            width:34px;
            height:34px;
            border-radius:50%;
            background:#ddd;
            color:#fff;
            display:flex;
            justify-content:center;
            align-items:center;
            margin-left:-36px;
        }
        .timeline-item.done .timeline-icon {
            background:var(--primary);
        }

        @media (max-width:768px) {
            .form-grid {
                grid-template-columns:1fr;
            }
            .span-2, .span-3 {
                grid-column:span 1;
            }
            .search-input, .filter-select {
                width:100%;
                min-width:unset;
            }
        }
    </style>
</head>
<body>

<div class="page-wrap">
    <div class="page-header-custom">
        <div>
            <h1><i class="fas fa-boxes-stacked"></i> Maareynta Kontaynarada</h1>
            <p>Branch: <?= h($branch_name) ?> | <?= h($tenant_name) ?></p>
        </div>
        <div class="header-actions">
            <a class="btn-outline-custom" href="?action=export_containers"><i class="fas fa-file-export"></i> Export</a>
            <a class="btn-outline-custom" href="?action=download_import_template"><i class="fas fa-file-download"></i> Template</a>
            <button class="btn-outline-custom" id="importBtn"><i class="fas fa-file-import"></i> Import</button>
            <button class="btn-primary-custom" id="addContainerBtn"><i class="fas fa-plus-circle"></i> Ku dar Kontaynar</button>
        </div>
    </div>

    <div class="panel">
        <div class="toolbar">
            <div class="toolbar-left">
                <input type="text" id="searchInput" class="search-input" placeholder="Search container, tracking, BL...">
                <select id="statusFilter" class="filter-select">
                    <option value="">Dhammaan Xaaladaha</option>
                    <?php foreach ($status_names as $key => $name): ?>
                        <option value="<?= h($key) ?>"><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn-save" id="refreshBtn"><i class="fas fa-sync"></i> Refresh</button>
        </div>

        <div id="tableArea">
            <div style="text-align:center;padding:50px;">
                <i class="fas fa-spinner fa-spin fa-2x"></i>
                <p>Loading...</p>
            </div>
        </div>
        <div id="paginationArea"></div>
    </div>
</div>

<!-- Container Modal -->
<div class="modal" id="containerModal">
    <div class="modal-content-custom">
        <div class="modal-header-custom">
            <h3><i class="fas fa-box"></i> Container Form</h3>
            <button class="modal-close" data-close="containerModal">&times;</button>
        </div>
        <form id="containerForm">
            <div class="modal-body-custom">
                <input type="hidden" name="ajax_action" value="save_container">
                <input type="hidden" name="container_id" id="container_id">

                <div class="form-grid">
                    <div class="form-group-custom">
                        <label>Lambarka Kontaynarka *</label>
                        <input type="text" name="container_number" id="container_number" required>
                    </div>

                    <div class="form-group-custom">
                        <label>Nooca</label>
                        <select name="container_type" id="container_type">
                            <option value="20ft">20ft</option>
                            <option value="40ft">40ft</option>
                            <option value="40hc">40HC</option>
                            <option value="lcl">LCL</option>
                        </select>
                    </div>

                    <div class="form-group-custom">
                        <label>CBM Capacity</label>
                        <input type="number" step="0.01" name="size_cbm" id="size_cbm">
                    </div>

                    <div class="form-group-custom">
                        <label>Weight KG</label>
                        <input type="number" step="0.01" name="weight_kg" id="weight_kg">
                    </div>

                    <div class="form-group-custom">
                        <label>Xaalad</label>
                        <select name="status" id="status">
                            <?php foreach ($status_names as $key => $name): ?>
                                <option value="<?= h($key) ?>"><?= h($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group-custom">
                        <label>Customs Status</label>
                        <select name="customs_status" id="customs_status">
                            <?php foreach ($customs_status_names as $key => $name): ?>
                                <option value="<?= h($key) ?>"><?= h($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group-custom">
                        <label>Current Location</label>
                        <input type="text" name="current_location" id="current_location" value="<?= h($branch_name) ?>">
                    </div>

                    <div class="form-group-custom">
                        <label>Arrival Date</label>
                        <input type="date" name="arrival_date" id="arrival_date">
                    </div>

                    <div class="form-group-custom">
                        <label>Departure Date</label>
                        <input type="date" name="departure_date" id="departure_date">
                    </div>

                    <div class="form-group-custom">
                        <label>Estimated Arrival</label>
                        <input type="date" name="estimated_arrival" id="estimated_arrival">
                    </div>

                    <div class="form-group-custom">
                        <label>Tracking Number</label>
                        <input type="text" name="tracking_number" id="tracking_number">
                    </div>

                    <div class="form-group-custom">
                        <label>Seal Number</label>
                        <input type="text" name="seal_number" id="seal_number">
                    </div>

                    <div class="form-group-custom">
                        <label>Shipping Line</label>
                        <input type="text" name="shipping_line" id="shipping_line">
                    </div>

                    <div class="form-group-custom">
                        <label>BL Number</label>
                        <input type="text" name="bl_number" id="bl_number">
                    </div>

                    <div class="form-group-custom">
                        <label>Vessel Name</label>
                        <input type="text" name="vessel_name" id="vessel_name">
                    </div>

                    <div class="form-group-custom">
                        <label>Port of Loading</label>
                        <input type="text" name="port_of_loading" id="port_of_loading">
                    </div>

                    <div class="form-group-custom">
                        <label>Port of Discharge</label>
                        <input type="text" name="port_of_discharge" id="port_of_discharge">
                    </div>

                    <div class="form-group-custom">
                        <label>ETA Port</label>
                        <input type="date" name="eta_port" id="eta_port">
                    </div>

                    <div class="form-group-custom">
                        <label>ETD Port</label>
                        <input type="date" name="etd_port" id="etd_port">
                    </div>

                    <div class="form-group-custom span-3">
                        <label>Notes</label>
                        <textarea name="notes" id="notes"></textarea>
                    </div>
                </div>

                <div id="formMsg"></div>
            </div>
            <div class="modal-footer-custom">
                <button type="button" class="btn-cancel" data-close="containerModal">Close</button>
                <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Container</button>
            </div>
        </form>
    </div>
</div>

<!-- Status Modal -->
<div class="modal" id="statusModal">
    <div class="modal-content-custom" style="max-width:520px;">
        <div class="modal-header-custom">
            <h3><i class="fas fa-exchange-alt"></i> Beddel Xaaladda</h3>
            <button class="modal-close" data-close="statusModal">&times;</button>
        </div>
        <form id="statusForm">
            <div class="modal-body-custom">
                <input type="hidden" name="ajax_action" value="update_status">
                <input type="hidden" name="id" id="status_container_id">
                <div class="form-group-custom">
                    <label>Xaalad Cusub</label>
                    <select name="status" id="status_update">
                        <?php foreach ($status_names as $key => $name): ?>
                            <option value="<?= h($key) ?>"><?= h($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="statusMsg"></div>
            </div>
            <div class="modal-footer-custom">
                <button type="button" class="btn-cancel" data-close="statusModal">Close</button>
                <button type="submit" class="btn-save">Update</button>
            </div>
        </form>
    </div>
</div>

<!-- View Modal -->
<div class="modal" id="viewModal">
    <div class="modal-content-custom">
        <div class="modal-header-custom">
            <h3><i class="fas fa-eye"></i> Faahfaahin</h3>
            <button class="modal-close" data-close="viewModal">&times;</button>
        </div>
        <div class="modal-body-custom" id="viewBody"></div>
        <div class="modal-footer-custom">
            <button type="button" class="btn-cancel" data-close="viewModal">Close</button>
        </div>
    </div>
</div>

<!-- Tracking Modal -->
<div class="modal" id="trackingModal">
    <div class="modal-content-custom" style="max-width:650px;">
        <div class="modal-header-custom">
            <h3><i class="fas fa-map-marker-alt"></i> Tracking History</h3>
            <button class="modal-close" data-close="trackingModal">&times;</button>
        </div>
        <div class="modal-body-custom" id="trackingBody"></div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal" id="importModal">
    <div class="modal-content-custom" style="max-width:560px;">
        <div class="modal-header-custom">
            <h3><i class="fas fa-file-import"></i> Import Containers CSV</h3>
            <button class="modal-close" data-close="importModal">&times;</button>
        </div>
        <form id="importForm" enctype="multipart/form-data">
            <div class="modal-body-custom">
                <input type="hidden" name="ajax_action" value="import_containers">
                <div class="form-group-custom">
                    <label>CSV File</label>
                    <input type="file" name="import_file" accept=".csv" required>
                </div>
                <div id="importMsg"></div>
            </div>
            <div class="modal-footer-custom">
                <button type="button" class="btn-cancel" data-close="importModal">Close</button>
                <button type="submit" class="btn-save">Import</button>
            </div>
        </form>
    </div>
</div>

<script>
const cbmMap = { '20ft': 33.2, '40ft': 67.6, '40hc': 76.3, 'lcl': 0 };
let currentPage = 1;
let searchTimer = null;

function alertHtml(type, msg) {
    return `<div class="alert alert-${type}">${msg}</div>`;
}

function openModal(id) {
    document.getElementById(id).style.display = 'block';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

document.addEventListener('click', function(e) {
    const closeId = e.target.getAttribute('data-close');
    if (closeId) closeModal(closeId);
    if (e.target.classList.contains('modal')) e.target.style.display = 'none';
});

function postAjax(data, success, fail) {
    // Native fetch() bypasses the shared jQuery ajaxSetup CSRF shim in
    // includes/footer.php, so attach the token explicitly from the
    // <meta name="csrf-token"> tag rendered by includes/header.php.
    var _csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var _csrfTok = _csrfMeta ? _csrfMeta.getAttribute('content') : '';
    var body;
    if (data instanceof FormData) {
        body = data;
        if (_csrfTok && !body.has('csrf_token')) body.append('csrf_token', _csrfTok);
    } else {
        body = new URLSearchParams(data);
        if (_csrfTok && !body.has('csrf_token')) body.append('csrf_token', _csrfTok);
    }
    fetch('', {
        method: 'POST',
        headers: { 'X-CSRF-Token': _csrfTok },
        body: body
    })
    .then(r => r.json())
    .then(success)
    .catch(() => {
        if (fail) fail();
        else alert('Server error');
    });
}

function loadContainers(page = 1) {
    currentPage = page;
    document.getElementById('tableArea').innerHTML = `<div style="text-align:center;padding:50px;"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>`;

    postAjax({
        ajax_action: 'get_containers',
        page: page,
        search: document.getElementById('searchInput').value,
        status: document.getElementById('statusFilter').value
    }, function(res) {
        if (res.success) {
            document.getElementById('tableArea').innerHTML = res.table_html;
            document.getElementById('paginationArea').innerHTML = res.pagination_html;
        } else {
            document.getElementById('tableArea').innerHTML = alertHtml('danger', res.message || 'Error');
        }
    }, function() {
        document.getElementById('tableArea').innerHTML = alertHtml('danger', 'Server error');
    });
}

function resetContainerForm() {
    document.getElementById('containerForm').reset();
    document.getElementById('container_id').value = '';
    document.getElementById('container_type').value = '20ft';
    document.getElementById('size_cbm').value = cbmMap['20ft'];
    document.getElementById('status').value = 'received';
    document.getElementById('customs_status').value = 'pending';
    document.getElementById('current_location').value = <?= json_encode($branch_name) ?>;
    document.getElementById('formMsg').innerHTML = '';
}

function fillContainerForm(c) {
    document.getElementById('container_id').value = c.id || '';
    document.getElementById('container_number').value = c.container_number || '';
    document.getElementById('container_type').value = c.container_type || '20ft';
    document.getElementById('size_cbm').value = c.size_cbm || '';
    document.getElementById('weight_kg').value = c.weight_kg || '';
    document.getElementById('status').value = c.status || 'received';
    document.getElementById('customs_status').value = c.customs_status || 'pending';
    document.getElementById('current_location').value = c.current_location || '';
    document.getElementById('arrival_date').value = c.arrival_date || '';
    document.getElementById('departure_date').value = c.departure_date || '';
    document.getElementById('estimated_arrival').value = c.estimated_arrival || '';
    document.getElementById('tracking_number').value = c.tracking_number || '';
    document.getElementById('seal_number').value = c.seal_number || '';
    document.getElementById('shipping_line').value = c.shipping_line || '';
    document.getElementById('bl_number').value = c.bl_number || '';
    document.getElementById('vessel_name').value = c.vessel_name || '';
    document.getElementById('port_of_loading').value = c.port_of_loading || '';
    document.getElementById('port_of_discharge').value = c.port_of_discharge || '';
    document.getElementById('eta_port').value = c.eta_port || '';
    document.getElementById('etd_port').value = c.etd_port || '';
    document.getElementById('notes').value = c.notes || '';
}

document.getElementById('container_type').addEventListener('change', function() {
    document.getElementById('size_cbm').value = cbmMap[this.value] ?? 0;
});

document.getElementById('addContainerBtn').addEventListener('click', function() {
    resetContainerForm();
    openModal('containerModal');
});

document.getElementById('refreshBtn').addEventListener('click', function() {
    loadContainers(currentPage);
});

document.getElementById('importBtn').addEventListener('click', function() {
    document.getElementById('importForm').reset();
    document.getElementById('importMsg').innerHTML = '';
    openModal('importModal');
});

document.getElementById('searchInput').addEventListener('keyup', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadContainers(1), 350);
});

document.getElementById('statusFilter').addEventListener('change', function() {
    loadContainers(1);
});

document.addEventListener('click', function(e) {
    const pageBtn = e.target.closest('.pagination a');
    if (pageBtn && pageBtn.dataset.page) {
        loadContainers(pageBtn.dataset.page);
    }

    if (e.target.closest('#addContainerBtnEmpty')) {
        resetContainerForm();
        openModal('containerModal');
    }

    const editBtn = e.target.closest('.edit-container');
    if (editBtn) {
        postAjax({ajax_action:'get_container', id: editBtn.dataset.id}, function(res) {
            if (res.success) {
                resetContainerForm();
                fillContainerForm(res.container);
                openModal('containerModal');
            } else {
                alert(res.message || 'Container lama helin');
            }
        });
    }

    const viewBtn = e.target.closest('.view-container');
    if (viewBtn) {
        postAjax({ajax_action:'get_container', id: viewBtn.dataset.id}, function(res) {
            if (!res.success) {
                alert(res.message || 'Container lama helin');
                return;
            }

            const c = res.container;
            let manifestHtml = '';
            if (res.manifest && res.manifest.length) {
                manifestHtml = `
                    <h4 style="margin-top:18px;">Manifest</h4>
                    <div style="overflow-x:auto;">
                    <table class="manifest-table">
                        <thead>
                            <tr>
                                <th>Macmiil</th><th>Alaab</th><th>Qty</th><th>CBM</th><th>Rate</th><th>Total</th><th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${res.manifest.map(m => `
                                <tr>
                                    <td>${m.customer_name || '-'}</td>
                                    <td>${m.items_list || '-'}</td>
                                    <td>${m.total_packages || 0}</td>
                                    <td>${Number(m.total_cbm || 0).toFixed(3)}</td>
                                    <td>$${Number(m.cbm_price || 0).toFixed(2)}</td>
                                    <td>$${Number(m.total_price || 0).toFixed(2)}</td>
                                    <td>${m.mogadishu_status || '-'}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                    </div>
                `;
            } else {
                manifestHtml = `<div class="alert alert-info">Manifest alaab laguma hayo.</div>`;
            }

            document.getElementById('viewBody').innerHTML = `
                <div class="form-grid">
                    <div><strong>Container:</strong><br>${c.container_number || '-'}</div>
                    <div><strong>Nooca:</strong><br>${c.container_type || '-'}</div>
                    <div><strong>Status:</strong><br>${c.status || '-'}</div>
                    <div><strong>Branch:</strong><br>${c.branch_name || '-'}</div>
                    <div><strong>Location:</strong><br>${c.current_location || '-'}</div>
                    <div><strong>CBM:</strong><br>${Number(c.size_used_cbm || 0).toFixed(2)} / ${Number(c.size_cbm || 0).toFixed(2)}</div>
                    <div><strong>Tracking:</strong><br>${c.tracking_number || '-'}</div>
                    <div><strong>BL:</strong><br>${c.bl_number || '-'}</div>
                    <div><strong>Vessel:</strong><br>${c.vessel_name || '-'}</div>
                    <div class="span-3"><strong>Notes:</strong><br>${c.notes || '-'}</div>
                </div>
                ${manifestHtml}
                <div style="margin-top:16px;">
                    <a class="btn-save" href="?action=export_manifest&id=${c.id}" style="text-decoration:none;display:inline-block;">
                        <i class="fas fa-file-export"></i> Export Manifest
                    </a>
                </div>
            `;
            openModal('viewModal');
        });
    }

    const deleteBtn = e.target.closest('.delete-container');
    if (deleteBtn) {
        if (!confirm(`Ma hubtaa inaad tirtirayso container: ${deleteBtn.dataset.name}?`)) return;
        postAjax({ajax_action:'delete_container', id: deleteBtn.dataset.id}, function(res) {
            alert(res.message || '');
            if (res.success) loadContainers(currentPage);
        });
    }

    const statusBtn = e.target.closest('.update-status');
    if (statusBtn) {
        document.getElementById('status_container_id').value = statusBtn.dataset.id;
        document.getElementById('status_update').value = statusBtn.dataset.status || 'received';
        document.getElementById('statusMsg').innerHTML = '';
        openModal('statusModal');
    }

    const trackingBtn = e.target.closest('.tracking-container');
    if (trackingBtn) {
        document.getElementById('trackingBody').innerHTML = `<div style="text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>`;
        openModal('trackingModal');

        postAjax({ajax_action:'get_tracking_history', id: trackingBtn.dataset.id}, function(res) {
            document.getElementById('trackingBody').innerHTML = res.success ? res.html : alertHtml('danger', res.message || 'Tracking lama helin');
        });
    }

    const waBtn = e.target.closest('.whatsapp-all');
    if (waBtn) {
        if (!confirm('WhatsApp dhammaan macaamiisha container-kan ma loo dirayaa?')) return;
        postAjax({ajax_action:'send_whatsapp_all', id: waBtn.dataset.id}, function(res) {
            alert(res.message || (res.success ? 'WhatsApp waa la diray' : 'WhatsApp failed'));
        });
    }
});

document.getElementById('containerForm').addEventListener('submit', function(e) {
    e.preventDefault();
    document.getElementById('formMsg').innerHTML = '';

    postAjax(new FormData(this), function(res) {
        if (res.success) {
            document.getElementById('formMsg').innerHTML = alertHtml('success', res.message);
            loadContainers(currentPage);
            setTimeout(() => closeModal('containerModal'), 800);
        } else {
            document.getElementById('formMsg').innerHTML = alertHtml('danger', res.message || 'Error');
        }
    }, function() {
        document.getElementById('formMsg').innerHTML = alertHtml('danger', 'Server error');
    });
});

document.getElementById('statusForm').addEventListener('submit', function(e) {
    e.preventDefault();
    document.getElementById('statusMsg').innerHTML = '';

    postAjax(new FormData(this), function(res) {
        if (res.success) {
            document.getElementById('statusMsg').innerHTML = alertHtml('success', res.message);
            loadContainers(currentPage);
            setTimeout(() => closeModal('statusModal'), 900);
        } else {
            document.getElementById('statusMsg').innerHTML = alertHtml('danger', res.message || 'Error');
        }
    }, function() {
        document.getElementById('statusMsg').innerHTML = alertHtml('danger', 'Server error');
    });
});

document.getElementById('importForm').addEventListener('submit', function(e) {
    e.preventDefault();
    document.getElementById('importMsg').innerHTML = '';

    postAjax(new FormData(this), function(res) {
        if (res.success) {
            document.getElementById('importMsg').innerHTML = alertHtml('success', res.message);
            loadContainers(1);
        } else {
            let extra = '';
            if (res.errors && res.errors.length) {
                extra = '<br>' + res.errors.map(x => '- ' + x).join('<br>');
            }
            document.getElementById('importMsg').innerHTML = alertHtml('danger', (res.message || 'Import failed') + extra);
        }
    }, function() {
        document.getElementById('importMsg').innerHTML = alertHtml('danger', 'Server error');
    });
});

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('size_cbm').value = cbmMap['20ft'];
    loadContainers(1);
});
</script>

</body>
</html>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
